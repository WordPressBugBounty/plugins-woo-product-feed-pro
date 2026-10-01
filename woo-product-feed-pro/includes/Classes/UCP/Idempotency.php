<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Classes\UCP
 */

namespace AdTribes\PFP\Classes\UCP;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Traits\Singleton_Trait;

defined( 'ABSPATH' ) || exit;

/**
 * Idempotency-Key store for the UCP checkout endpoints.
 *
 * Mutating UCP requests carry an `Idempotency-Key` header. A replay with
 * the same key and identical body must receive the stored response; the
 * same key with a different body must be rejected with HTTP 409. Keys are
 * retained for at least 24 hours (purged after 48), which requires keyed
 * TTL lookups a wp_options row cannot provide — hence the plugin's first
 * custom table.
 *
 * Keys and bodies are stored as SHA-256 hashes, so arbitrarily long
 * client-supplied keys never hit column limits.
 *
 * @since 13.6.0
 */
class Idempotency extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Unprefixed table name.
     *
     * @since 13.6.0
     * @var string
     */
    const TABLE = 'adt_ucp_idempotency';

    /**
     * Action Scheduler hook for purging expired keys.
     *
     * @since 13.6.0
     * @var string
     */
    const PURGE_HOOK = 'adt_ucp_purge_idempotency';

    /**
     * Retention period in hours. The spec requires at least 24.
     *
     * @since 13.6.0
     * @var int
     */
    const RETENTION_HOURS = 48;

    /**
     * Check state: no record for this key.
     *
     * @since 13.6.0
     * @var string
     */
    const STATE_MISS = 'miss';

    /**
     * Check state: record found with an identical body — replay it.
     *
     * @since 13.6.0
     * @var string
     */
    const STATE_REPLAY = 'replay';

    /**
     * Check state: record found with a different body — respond 409.
     *
     * @since 13.6.0
     * @var string
     */
    const STATE_MISMATCH = 'mismatch';

    /**
     * Check state: another request holds the claim and is still running.
     *
     * @since 13.6.0
     * @var string
     */
    const STATE_IN_PROGRESS = 'in_progress';

    /**
     * Seconds after which an unfinished claim is treated as abandoned.
     *
     * A request that fataled between claiming a key and storing its
     * response would otherwise wedge that key until the purge runs.
     *
     * @since 13.6.0
     * @var int
     */
    const CLAIM_TIMEOUT = 60;

    /**
     * Get the prefixed table name.
     *
     * @since 13.6.0
     * @access public
     *
     * @return string Table name.
     */
    public static function get_table_name() {

        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Create (or migrate) the idempotency table.
     *
     * Safe to run repeatedly — dbDelta only applies differences.
     *
     * `key_hash` (hex) and `endpoint` (ASCII) are pinned to the `ascii`
     * charset so the composite primary key measures 64 + 191 = 255 bytes.
     * Under the connection's default utf8mb4 the same key would be 1020
     * bytes, over the 767-byte InnoDB limit on MySQL 5.6 / 5.7 without
     * large-prefix support — where dbDelta() would swallow the failure and
     * leave the store with no idempotency protection at all.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool True when the table exists afterwards.
     */
    public static function create_table() {

        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            key_hash char(64) CHARACTER SET ascii NOT NULL,
            endpoint varchar(191) CHARACTER SET ascii NOT NULL,
            body_hash char(64) NOT NULL,
            status smallint unsigned NOT NULL DEFAULT 200,
            response longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (key_hash,endpoint),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta( $sql );

        return self::table_exists();
    }

    /**
     * Whether the idempotency table exists.
     *
     * Nothing useful comes back from dbDelta() when `CREATE TABLE` fails, so
     * the migration verifies the result rather than assuming it — otherwise a
     * failed create is flagged done and never retried.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool True when the table exists.
     */
    public static function table_exists() {

        global $wpdb;

        $table = self::get_table_name();

        return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Check an idempotency key against the store.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $idempotency_key Client-supplied Idempotency-Key header value.
     * @param string $endpoint        Endpoint identifier the key is scoped to (e.g. `POST /checkout-sessions`).
     * @param string $raw_body        Raw request body.
     * @return array {
     *     @type string $state    One of the STATE_* constants.
     *     @type int    $status   Stored HTTP status (replay only).
     *     @type string $response Stored response body JSON (replay only).
     * }
     */
    public function check( $idempotency_key, $endpoint, $raw_body ) {

        global $wpdb;

        $table = self::get_table_name();

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT body_hash, status, response FROM {$table} WHERE key_hash = %s AND endpoint = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                hash( 'sha256', $idempotency_key ),
                $endpoint
            ),
            ARRAY_A
        );

        if ( null === $row ) {
            return array( 'state' => self::STATE_MISS );
        }

        if ( ! hash_equals( (string) $row['body_hash'], hash( 'sha256', $raw_body ) ) ) {
            return array( 'state' => self::STATE_MISMATCH );
        }

        return array(
            'state'    => self::STATE_REPLAY,
            'status'   => (int) $row['status'],
            'response' => $row['response'],
        );
    }

    /**
     * Atomically claim an idempotency key before running the operation.
     *
     * `check()` alone is a read, so two concurrent requests carrying the
     * same key (an ordinary client retry) both see STATE_MISS and both run
     * the operation — duplicate order, double charge. Claiming inserts the
     * row on the `(key_hash,endpoint)` primary key first: exactly one
     * request can win, and the loser learns whether the winner is still
     * running (STATE_IN_PROGRESS) or has already stored its response
     * (STATE_REPLAY).
     *
     * The caller owns the claim on STATE_MISS and MUST finish it with
     * either store() or release().
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $idempotency_key Client-supplied Idempotency-Key header value.
     * @param string $endpoint        Endpoint identifier the key is scoped to.
     * @param string $raw_body        Raw request body.
     * @return array Same shape as check(), plus STATE_IN_PROGRESS.
     */
    public function claim( $idempotency_key, $endpoint, $raw_body ) {

        global $wpdb;

        $table = self::get_table_name();

        // A duplicate primary key makes this fail rather than warn, which is
        // the signal we want — suppress the error output around it.
        $suppress = $wpdb->suppress_errors( true );
        $inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "INSERT INTO {$table} (key_hash, endpoint, body_hash, status, response, created_at) VALUES (%s, %s, %s, 0, '', %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                hash( 'sha256', $idempotency_key ),
                $endpoint,
                hash( 'sha256', $raw_body ),
                gmdate( 'Y-m-d H:i:s' )
            )
        );
        $wpdb->suppress_errors( $suppress );

        if ( 1 === (int) $inserted ) {
            return array( 'state' => self::STATE_MISS );
        }

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT body_hash, status, response, created_at FROM {$table} WHERE key_hash = %s AND endpoint = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                hash( 'sha256', $idempotency_key ),
                $endpoint
            ),
            ARRAY_A
        );

        // No row and no insert means the write failed for a reason other than
        // a duplicate key — most likely the table is missing because its
        // migration could not run. Degrade to unprotected rather than
        // refusing every mutating request.
        if ( null === $row ) {
            return array( 'state' => self::STATE_MISS );
        }

        if ( ! hash_equals( (string) $row['body_hash'], hash( 'sha256', $raw_body ) ) ) {
            return array( 'state' => self::STATE_MISMATCH );
        }

        if ( (int) $row['status'] > 0 ) {
            return array(
                'state'    => self::STATE_REPLAY,
                'status'   => (int) $row['status'],
                'response' => $row['response'],
            );
        }

        // An unfinished claim older than the timeout belongs to a request
        // that died. Take it over with a compare-and-swap on created_at so
        // only one of several waiters can win.
        if ( strtotime( (string) $row['created_at'] . ' UTC' ) < time() - self::CLAIM_TIMEOUT ) {
            $taken = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->prepare(
                    "UPDATE {$table} SET created_at = %s WHERE key_hash = %s AND endpoint = %s AND status = 0 AND created_at = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    gmdate( 'Y-m-d H:i:s' ),
                    hash( 'sha256', $idempotency_key ),
                    $endpoint,
                    (string) $row['created_at']
                )
            );

            if ( 1 === (int) $taken ) {
                return array( 'state' => self::STATE_MISS );
            }
        }

        return array( 'state' => self::STATE_IN_PROGRESS );
    }

    /**
     * Drop an unfinished claim so the key can be retried.
     *
     * Only ever removes a claim with no stored response, so a completed
     * record is never destroyed.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $idempotency_key Client-supplied Idempotency-Key header value.
     * @param string $endpoint        Endpoint identifier the key is scoped to.
     * @return bool True when a claim was removed.
     */
    public function release( $idempotency_key, $endpoint ) {

        global $wpdb;

        $table = self::get_table_name();

        $deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE key_hash = %s AND endpoint = %s AND status = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                hash( 'sha256', $idempotency_key ),
                $endpoint
            )
        );

        return (int) $deleted > 0;
    }

    /**
     * Store the response for an idempotency key.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $idempotency_key Client-supplied Idempotency-Key header value.
     * @param string $endpoint        Endpoint identifier the key is scoped to.
     * @param string $raw_body        Raw request body.
     * @param int    $status          HTTP status code of the response.
     * @param string $response        Response body JSON.
     * @return bool True on success.
     */
    public function store( $idempotency_key, $endpoint, $raw_body, $status, $response ) {

        global $wpdb;

        $result = $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            self::get_table_name(),
            array(
                'key_hash'   => hash( 'sha256', $idempotency_key ),
                'endpoint'   => $endpoint,
                'body_hash'  => hash( 'sha256', $raw_body ),
                'status'     => (int) $status,
                'response'   => $response,
                'created_at' => gmdate( 'Y-m-d H:i:s' ),
            ),
            array( '%s', '%s', '%s', '%d', '%s', '%s' )
        );

        return false !== $result;
    }

    /**
     * Delete records older than the retention period.
     *
     * @since 13.6.0
     * @access public
     *
     * @return int Number of rows deleted.
     */
    public function purge() {

        global $wpdb;

        $table  = self::get_table_name();
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_HOURS * HOUR_IN_SECONDS );

        $deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );

        return (int) $deleted;
    }

    /**
     * Schedule the daily purge task while the feature is enabled.
     *
     * @since 13.6.0
     * @access public
     */
    public function maybe_schedule_purge() {

        if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
            return;
        }

        if ( ! UCP_Feature::instance()->is_enabled() ) {
            return;
        }

        if ( ! as_has_scheduled_action( self::PURGE_HOOK ) ) {
            as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::PURGE_HOOK, array(), 'adt_ucp' );
        }
    }

    /**
     * Run the class.
     *
     * @since 13.6.0
     * @access public
     */
    public function run() {

        add_action( 'init', array( $this, 'maybe_schedule_purge' ) );
        add_action( self::PURGE_HOOK, array( $this, 'purge' ) );
    }
}
