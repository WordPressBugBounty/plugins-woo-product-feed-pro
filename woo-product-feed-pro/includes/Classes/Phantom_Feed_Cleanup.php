<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Traits\Singleton_Trait;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes phantom product feed posts.
 *
 * A phantom is an `adt_product_feed` post that a caller created by accident: it
 * holds the factory defaults and nothing else, so it has no channel, no file name,
 * has never generated, and does nothing but slow the feed admin list and inflate
 * the usage figures. Sites accumulated them at two to twenty-five a day (issue #1189).
 *
 * The insert routes are closed (`Cron::get_existing_feed()` and
 * `Product_Feed::has_feed_identity()`), so this class only removes what was
 * already written.
 *
 * Deletion runs through Action Scheduler, one bounded batch per action, because
 * the worst site seen held over eleven thousand of them - far more than one
 * request can delete. Each batch queues the next while any remain.
 *
 * @since 13.6.0
 */
class Phantom_Feed_Cleanup extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Number of phantom feeds deleted per batch.
     *
     * @since 13.6.0
     *
     * @var int
     */
    const BATCH_SIZE = 100;

    /**
     * Minimum age, in hours, before a feed post may be deleted.
     *
     * A feed is written in two steps - `wp_insert_post()`, then the meta - so
     * between them a real feed briefly looks exactly like a phantom. The window is
     * microseconds wide within one request, but this cleanup runs unattended on
     * merchant sites, and a feed whose meta write was lost to a fatal would be
     * deleted with no way to tell. Nothing younger than this is touched, which
     * costs the cleanup nothing: phantoms accumulate over days.
     *
     * @since 13.6.0
     *
     * @var int
     */
    const MIN_AGE_HOURS = 1;

    /**
     * Find phantom feed post IDs.
     *
     * What this deletes is a strict subset of what `Product_Feed::has_feed_identity()`
     * rejects - that guard now decides whether a feed may be inserted at all.
     * Anything the guard would admit must survive this query, or a feed the plugin
     * itself considers real is deleted unattended. The two are deliberately not
     * symmetric: this predicate adds the file name, the run history, the post
     * status and the age window on top, and it tests emptiness in SQL where the
     * guard trims in PHP. Narrowing this predicate further is always safe;
     * widening it past the guard is not. So a post is a phantom only when it
     * carries none of the three identity values -
     *
     * - `post_title`
     * - `adt_channel_hash` - the channel selects the attribute set, the writer and
     *   the output columns, so a feed without one cannot generate.
     * - `adt_legacy_project_hash`
     *
     * - and, on top of that, no sign it was ever run:
     *
     * - `adt_file_name` - no file was ever named for it. Every create path assigns
     *   one before the feed is ever run.
     * - `adt_last_updated` - no run ever completed. It is stamped when a run
     *   reaches `ready`.
     *
     * The title and the project hash matter in particular for the 13.3.5 legacy
     * migration, which carries a `projectname` and a project hash across but takes
     * the channel and the file name from the legacy row, where either may be
     * missing. Such a feed is a real one a merchant can finish configuring.
     *
     * Two further limits keep an unattended job away from anything in flight: only
     * `publish` and `draft` are considered, so an `auto-draft`, a trashed post or a
     * revision is never touched, and nothing younger than self::MIN_AGE_HOURS is
     * matched at all.
     *
     * `adt_history_products` is deliberately not part of the predicate. It records
     * attempts rather than results, and the phantoms written by
     * `Cron::as_product_feed_update_stats()` carry one row holding a count of
     * zero - so testing it for emptiness misses exactly the posts this removes.
     *
     * Read off the meta table directly rather than through `WP_Query`, because a
     * meta query for "key absent or empty" over thousands of posts costs far more
     * than these three left joins, and because loading the posts would prime the
     * meta cache for every one of them.
     *
     * @since 13.6.0
     * @access public
     *
     * @param int $limit Maximum number of IDs to return.
     * @return int[] Phantom feed post IDs.
     */
    public static function find_ids( $limit = self::BATCH_SIZE ) {
        global $wpdb;

        $limit     = max( 1, absint( $limit ) );
        $predicate = self::predicate_sql();

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT p.ID ' . $predicate['sql'] . ' ORDER BY p.ID ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                array_merge( $predicate['args'], array( $limit ) )
            )
        );

        return array_map( 'absint', (array) $ids );
    }

    /**
     * The phantom predicate: its SQL fragment and the arguments that prepare it.
     *
     * Single-sourced so `count()` and `find_ids()` cannot describe different sets.
     * When the two drift, `count()` reports a total the delete loop never reaches,
     * the CLI warns about feeds that were never candidates, and the Action
     * Scheduler chain decides whether to re-queue from a set the next batch will
     * not act on.
     *
     * The age window compares `post_date` rather than `post_date_gmt` for a post
     * that carries the zero date. WordPress leaves `post_date_gmt` as
     * `0000-00-00 00:00:00` for every `date_floating` status, and `draft` is one -
     * so comparing the GMT column alone is unconditionally true for a draft, and
     * the window protected nothing on half the statuses this predicate admits.
     * `draft` is what the WP-CLI `create` command, both create abilities, the
     * clone/duplicate handlers and the 13.3.5 legacy migration all write, and the
     * insert-then-write-meta gap the window exists to cover is exactly where such
     * a feed is indistinguishable from a phantom.
     *
     * @since 13.6.0
     * @access private
     *
     * @return array {
     *     @type string $sql  The FROM and WHERE fragment, with placeholders.
     *     @type array  $args The arguments that prepare it, in order.
     * }
     */
    private static function predicate_sql() {
        global $wpdb;

        $cutoff = time() - ( self::MIN_AGE_HOURS * HOUR_IN_SECONDS );

        return array(
            'sql'  => "FROM {$wpdb->posts} p
                   LEFT JOIN {$wpdb->postmeta} ch ON ch.post_id = p.ID AND ch.meta_key = %s
                   LEFT JOIN {$wpdb->postmeta} lph ON lph.post_id = p.ID AND lph.meta_key = %s
                   LEFT JOIN {$wpdb->postmeta} fn ON fn.post_id = p.ID AND fn.meta_key = %s
                   LEFT JOIN {$wpdb->postmeta} lu ON lu.post_id = p.ID AND lu.meta_key = %s
                  WHERE p.post_type = %s
                    AND p.post_status IN ( 'publish', 'draft' )
                    AND p.post_title = ''
                    AND ( CASE WHEN p.post_date_gmt = '0000-00-00 00:00:00'
                               THEN p.post_date < %s
                               ELSE p.post_date_gmt < %s END )
                    AND COALESCE( ch.meta_value, '' ) = ''
                    AND COALESCE( lph.meta_value, '' ) = ''
                    AND COALESCE( fn.meta_value, '' ) = ''
                    AND COALESCE( lu.meta_value, '' ) = ''",
            'args' => array(
                Product_Feed::META_PREFIX . 'channel_hash',
                Product_Feed::META_PREFIX . 'legacy_project_hash',
                Product_Feed::META_PREFIX . 'file_name',
                Product_Feed::META_PREFIX . 'last_updated',
                Product_Feed::POST_TYPE,
                wp_date( 'Y-m-d H:i:s', $cutoff ),
                gmdate( 'Y-m-d H:i:s', $cutoff ),
            ),
        );
    }

    /**
     * Count the phantom feeds on this site.
     *
     * @since 13.6.0
     * @access public
     *
     * @return int Number of phantom feeds.
     */
    public static function count() {
        global $wpdb;

        $predicate = self::predicate_sql();

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) ' . $predicate['sql'], // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                $predicate['args']
            )
        );
    }

    /**
     * Delete one batch of phantom feeds.
     *
     * `wp_delete_post()` with force is used rather than a direct row delete, so
     * the post meta goes with the post and anything hooked to feed deletion still
     * runs.
     *
     * @since 13.6.0
     * @access public
     *
     * @param int $limit Maximum number of feeds to delete.
     * @return int Number of feeds deleted.
     */
    public static function delete_batch( $limit = self::BATCH_SIZE ) {
        $deleted = 0;

        foreach ( self::find_ids( $limit ) as $id ) {
            if ( wp_delete_post( $id, true ) ) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * Queue the cleanup when this site has phantom feeds to remove.
     *
     * Does nothing when an action is already queued, so a second caller cannot
     * start a second chain.
     *
     * `as_has_scheduled_action()` is not usable for that guard here, because it
     * matches in-progress actions as well as pending ones, and Action Scheduler
     * flips an action to in-progress before it fires the hook. The batch callback
     * re-queues its successor from inside the running action, so the guard matched
     * the very action asking the question - same hook, same empty args, same group
     * - and the chain stopped after its first batch, permanently. The statuses are
     * therefore named explicitly, the same way `Cron::query_active_batch_actions()`
     * already does it.
     *
     * @since 13.6.0
     * @access public
     *
     * @param bool     $ignore_running   Whether an in-progress action should not count
     *                                   as already queued. True only from the batch
     *                                   callback, which runs inside the action it is
     *                                   asking about.
     * @param int|null $known_remaining  How many phantoms the caller already counted.
     *                                   The batch callback counts them to log the
     *                                   figure, so it passes it here rather than let
     *                                   this method scan the same rows again. A caller
     *                                   without a count passes null and this method
     *                                   counts for itself.
     * @return bool True when a cleanup is queued or already running.
     */
    public static function schedule( $ignore_running = false, $known_remaining = null ) {
        if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
            return false;
        }

        $statuses = array( \ActionScheduler_Store::STATUS_PENDING );

        if ( ! $ignore_running ) {
            $statuses[] = \ActionScheduler_Store::STATUS_RUNNING;
        }

        $existing = as_get_scheduled_actions(
            array(
                'hook'     => ADT_PFP_AS_CLEANUP_PHANTOM_FEEDS,
                'status'   => $statuses,
                'group'    => ADT_PFP_AS_CLEANUP_PHANTOM_FEEDS_GROUP,
                'per_page' => 1,
            ),
            'ids'
        );

        if ( ! empty( $existing ) ) {
            return true;
        }

        $remaining = null !== $known_remaining ? absint( $known_remaining ) : self::count();

        if ( $remaining < 1 ) {
            return false;
        }

        as_schedule_single_action(
            time() + 60,
            ADT_PFP_AS_CLEANUP_PHANTOM_FEEDS,
            array(),
            ADT_PFP_AS_CLEANUP_PHANTOM_FEEDS_GROUP
        );

        return true;
    }

    /**
     * Action Scheduler callback: delete one batch, then queue the next if needed.
     *
     * @since 13.6.0
     * @access public
     */
    public function as_cleanup_phantom_feeds_callback() {
        $deleted = self::delete_batch();

        // Stop when the batch removed nothing: either none are left, or every
        // candidate refused to delete and retrying would loop forever.
        if ( $deleted < 1 ) {
            return;
        }

        $remaining = self::count();

        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->info(
                'Deleted phantom product feeds',
                array(
                    'source'    => 'woo-product-feed-pro',
                    'deleted'   => $deleted,
                    'remaining' => $remaining,
                )
            );
        }

        // Queue the next batch through schedule(), which refuses to add a second
        // action while one is pending. Action Scheduler re-runs an action whose
        // claim expired - a batch that fatalled, or one a second queue runner
        // picked up - and an unguarded re-queue would fork the chain in two.
        // This call runs inside the action it would otherwise match, so the
        // in-progress status is excluded from that guard here, and only here.
        if ( $remaining > 0 ) {
            self::schedule( true, $remaining );
        }
    }

    /**
     * Run the class.
     *
     * @codeCoverageIgnore
     * @since 13.6.0
     * @access public
     */
    public function run() {
        add_action( ADT_PFP_AS_CLEANUP_PHANTOM_FEEDS, array( $this, 'as_cleanup_phantom_feeds_callback' ) );
    }
}
