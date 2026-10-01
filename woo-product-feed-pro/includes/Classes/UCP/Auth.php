<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Classes\UCP
 */

namespace AdTribes\PFP\Classes\UCP;

use AdTribes\PFP\Traits\Singleton_Trait;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Merchant-issued API-key authentication for the UCP endpoints.
 *
 * Google's integration UI takes two merchant-issued keys — staging and
 * production. Keys are shown to the merchant exactly once at generation;
 * only SHA-256 hashes are stored. A staging key is honored only while
 * staging mode is on, and a production key only while the store is live,
 * so a leaked staging key can never touch a live store.
 *
 * @since 13.6.0
 */
class Auth {

    use Singleton_Trait;

    /**
     * The option name that holds the API key hashes.
     *
     * @since 13.6.0
     * @var string
     */
    const OPTION_NAME = 'adt_ucp_api_keys';

    /**
     * Production key environment.
     *
     * @since 13.6.0
     * @var string
     */
    const ENV_PRODUCTION = 'production';

    /**
     * Staging key environment.
     *
     * @since 13.6.0
     * @var string
     */
    const ENV_STAGING = 'staging';

    /**
     * Generate (or replace) the API key for an environment.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $environment One of the ENV_* constants.
     * @return string|WP_Error The plaintext key — shown once, never stored — or an error.
     */
    public function generate_key( $environment ) {

        if ( ! in_array( $environment, array( self::ENV_PRODUCTION, self::ENV_STAGING ), true ) ) {
            return new WP_Error( 'adt_ucp_invalid_environment', __( 'Invalid API key environment.', 'woo-product-feed-pro' ) );
        }

        $prefix    = self::ENV_PRODUCTION === $environment ? 'adtucp_live_' : 'adtucp_test_';
        $plaintext = $prefix . bin2hex( random_bytes( 24 ) );

        $keys = $this->get_stored_keys();

        $keys[ $environment ] = array(
            'hash'       => hash( 'sha256', $plaintext ),
            'hint'       => substr( $plaintext, 0, strlen( $prefix ) + 4 ) . '…',
            'created_at' => time(),
        );

        $this->save_keys( $keys );

        return $plaintext;
    }

    /**
     * Revoke the API key for an environment.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $environment One of the ENV_* constants.
     * @return bool True if a key was removed.
     */
    public function revoke_key( $environment ) {

        $keys = $this->get_stored_keys();

        if ( ! isset( $keys[ $environment ] ) ) {
            return false;
        }

        unset( $keys[ $environment ] );

        return $this->save_keys( $keys );
    }

    /**
     * Get non-secret metadata about a stored key for the admin UI.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $environment One of the ENV_* constants.
     * @return array Empty array when no key exists, else `hint` and `created_at`.
     */
    public function get_key_meta( $environment ) {

        $keys = $this->get_stored_keys();

        if ( empty( $keys[ $environment ] ) ) {
            return array();
        }

        return array(
            'hint'       => $keys[ $environment ]['hint'] ?? '',
            'created_at' => $keys[ $environment ]['created_at'] ?? 0,
        );
    }

    /**
     * Validate a presented key against the stored hashes.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $plaintext Presented API key.
     * @return string|false The matching environment, or false.
     */
    public function validate_key( $plaintext ) {

        if ( ! is_string( $plaintext ) || '' === $plaintext ) {
            return false;
        }

        $presented_hash = hash( 'sha256', $plaintext );
        $environment    = false;

        // Compare against every stored hash (no early return) to keep timing uniform.
        foreach ( $this->get_stored_keys() as $env => $key ) {
            if ( ! empty( $key['hash'] ) && hash_equals( $key['hash'], $presented_hash ) ) {
                $environment = $env;
            }
        }

        return $environment;
    }

    /**
     * Authenticate a UCP REST request.
     *
     * Suitable as the body of a `permission_callback`. Enforces the
     * environment gate: staging keys require staging mode, production
     * keys require the store to be live.
     *
     * @since 13.6.0
     * @access public
     *
     * @param WP_REST_Request $request Request.
     * @return true|WP_Error True when authenticated.
     */
    public function authenticate_request( WP_REST_Request $request ) {

        $environment = $this->validate_key( self::extract_key( $request ) );

        if ( false === $environment ) {
            return new WP_Error(
                'adt_ucp_unauthorized',
                __( 'Missing or invalid API key.', 'woo-product-feed-pro' ),
                array( 'status' => 401 )
            );
        }

        $feature = UCP_Feature::instance();

        $allowed = self::ENV_PRODUCTION === $environment
            ? $feature->is_live()
            : ( $feature->is_enabled() && 'yes' === Settings::instance()->get( 'staging_mode' ) );

        /**
         * Filter whether an authenticated environment may access the UCP endpoints.
         *
         * @since 13.6.0
         *
         * @param bool            $allowed     Whether access is allowed.
         * @param string          $environment Authenticated key environment.
         * @param WP_REST_Request $request     Request.
         */
        $allowed = (bool) apply_filters( 'adt_ucp_environment_allowed', $allowed, $environment, $request );

        if ( ! $allowed ) {
            return new WP_Error(
                'adt_ucp_forbidden',
                __( 'This API key is not valid for the store\'s current UCP state.', 'woo-product-feed-pro' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * Resolve the environment of the API key on a request.
     *
     * Stateless companion to authenticate_request() for callers that need
     * to scope data (e.g. idempotency keys) to the authenticated key
     * environment so a staging key can never replay a production response.
     *
     * @since 13.6.0
     * @access public
     *
     * @param WP_REST_Request $request Request.
     * @return string Environment (production/staging), empty string when invalid.
     */
    public function get_request_environment( WP_REST_Request $request ) {

        $environment = $this->validate_key( self::extract_key( $request ) );

        return false === $environment ? '' : (string) $environment;
    }

    /**
     * Extract the presented API key from a request.
     *
     * Accepts `Authorization: Bearer <key>` and the `X-API-Key` header.
     *
     * @since 13.6.0
     * @access public
     *
     * @param WP_REST_Request $request Request.
     * @return string Presented key, empty string when absent.
     */
    public static function extract_key( WP_REST_Request $request ) {

        $authorization = (string) $request->get_header( 'authorization' );
        if ( 0 === stripos( $authorization, 'Bearer ' ) ) {
            return trim( substr( $authorization, 7 ) );
        }

        return trim( (string) $request->get_header( 'x_api_key' ) );
    }

    /**
     * Get the stored key records.
     *
     * @since 13.6.0
     * @access private
     *
     * @return array Environment => key record.
     */
    private function get_stored_keys() {

        $keys = get_option( self::OPTION_NAME );

        return is_array( $keys ) ? $keys : array();
    }

    /**
     * Save the key records without autoloading the option.
     *
     * @since 13.6.0
     * @access private
     *
     * @param array $keys Environment => key record.
     * @return bool True on success.
     */
    private function save_keys( array $keys ) {

        if ( false === get_option( self::OPTION_NAME ) ) {
            return add_option( self::OPTION_NAME, $keys, '', 'no' );
        }

        return update_option( self::OPTION_NAME, $keys );
    }
}
