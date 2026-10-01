<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Classes\UCP
 */

namespace AdTribes\PFP\Classes\UCP;

use AdTribes\PFP\Traits\Singleton_Trait;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Client for the AdTribes AP2 & account server.
 *
 * Owns the OAuth 2.0 authorization-code link (the admin "Connect"
 * button), encrypted credential storage, the backend verify call for AP2
 * checkout mandates, refund reporting, and the billing/usage endpoints
 * consumed by the balance-aware admin UI. Completed orders are metered
 * server-side, first-hand, at mandate verification — the plugin reports
 * nothing for billing except refunds (fee credit symmetry).
 *
 * Implements plugin↔server contract 1.0.0-rc.2: dynamic client
 * registration (RFC 7591) before the first connect, PKCE S256 on the
 * authorize URL, form-encoded token exchanges authenticated with
 * client_secret_post, and the `/v1/ap2/*` verify/refund endpoints. The
 * base URL honors the `ADT_UCP_SERVER_URL` testing constant so local
 * environments can point at a mock.
 *
 * @since 13.6.0
 */
class Account_Client {

    use Singleton_Trait;

    /**
     * The option name that holds the (encrypted) OAuth credentials.
     *
     * @since 13.6.0
     * @var string
     */
    const OPTION_NAME = 'adt_ucp_account';

    /**
     * Transient caching the last /v1/account/status payload.
     *
     * @since 13.6.0
     * @var string
     */
    const STATUS_TRANSIENT = 'adt_ucp_account_status';

    /**
     * Default production server base URL.
     *
     * The server is mounted on a zone path route (`adtribes.io/ap2/*`),
     * not a subdomain — every other path on that host falls through to
     * the WordPress origin.
     *
     * @since 13.6.0
     * @var string
     */
    const DEFAULT_SERVER_URL = 'https://adtribes.io/ap2';

    /**
     * OAuth scopes requested on connect (contract 1.0.0-rc.2).
     *
     * @since 13.6.0
     * @var string
     */
    const SCOPES = 'ap2:verify account:read billing:manage';

    /**
     * Get the server base URL.
     *
     * @since 13.6.0
     * @access public
     *
     * @return string Base URL without trailing slash.
     */
    public function get_server_url() {

        $url = defined( 'ADT_UCP_SERVER_URL' ) ? ADT_UCP_SERVER_URL : self::DEFAULT_SERVER_URL;

        /**
         * Filter the AdTribes account server base URL.
         *
         * @since 13.6.0
         *
         * @param string $url Base URL.
         */
        return untrailingslashit( (string) apply_filters( 'adt_ucp_server_url', $url ) );
    }

    /**
     * Whether the store is connected to an AdTribes account.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool True when a refresh token is stored.
     */
    public function is_connected() {

        $credentials = $this->get_credentials();

        return ! empty( $credentials['refresh_token'] );
    }

    /**
     * Whether the store holds a registered OAuth client.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool True when a client_id and client_secret are stored.
     */
    public function is_registered() {

        $credentials = $this->get_credentials();

        return ! empty( $credentials['client_id'] ) && ! empty( $credentials['client_secret'] );
    }

    /**
     * Register this store as an OAuth client (RFC 7591).
     *
     * One-time step before the first connect. The returned client_id and
     * client_secret are stored encrypted alongside the tokens. Re-running
     * replaces the stored client (e.g. after a server-side purge).
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $redirect_uri The wp-admin connect callback URL.
     * @return true|WP_Error True on success.
     */
    public function register( $redirect_uri ) {

        $response = $this->request(
            'POST',
            '/oauth/register',
            array(
                'redirect_uris'              => array( $redirect_uri ),
                'client_name'                => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
                'token_endpoint_auth_method' => 'client_secret_post',
            ),
            false
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( empty( $response['client_id'] ) || empty( $response['client_secret'] ) ) {
            return new WP_Error( 'adt_ucp_register_failed', __( 'The account server did not return client credentials.', 'woo-product-feed-pro' ) );
        }

        $this->save_credentials(
            array(
                'client_id'     => (string) $response['client_id'],
                'client_secret' => (string) $response['client_secret'],
            )
        );

        return true;
    }

    /**
     * Build the authorization URL for the admin "Connect" click.
     *
     * Requires a registered client (see register()). Generates a fresh
     * PKCE S256 verifier per call and stores it for the token exchange —
     * each connect click supersedes the previous pending attempt.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $redirect_uri Admin callback URL.
     * @return string|WP_Error Authorization URL (state nonce included).
     */
    public function get_connect_url( $redirect_uri ) {

        $credentials = $this->get_credentials();

        if ( empty( $credentials['client_id'] ) ) {
            return new WP_Error( 'adt_ucp_not_registered', __( 'The store is not registered with the account server yet.', 'woo-product-feed-pro' ) );
        }

        // PKCE S256: 64 random bytes → 86-char base64url verifier (RFC 7636
        // allows 43–128), challenge = base64url(SHA-256(verifier)).
        $verifier  = self::base64url_encode( random_bytes( 64 ) );
        $challenge = self::base64url_encode( hash( 'sha256', $verifier, true ) );

        $this->save_credentials( array( 'code_verifier' => $verifier ) );

        // The server treats `state` as opaque and returns it verbatim; a WP
        // nonce ties the callback to this admin user's session.
        $state = wp_create_nonce( 'adt_ucp_oauth_state' );

        return add_query_arg(
            array(
                'response_type'         => 'code',
                'client_id'             => rawurlencode( (string) $credentials['client_id'] ),
                'redirect_uri'          => rawurlencode( $redirect_uri ),
                'scope'                 => rawurlencode( self::SCOPES ),
                'state'                 => $state,
                'code_challenge'        => $challenge,
                'code_challenge_method' => 'S256',
            ),
            $this->get_server_url() . '/oauth/authorize'
        );
    }

    /**
     * Exchange an authorization code for tokens and store them.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $code         Authorization code.
     * @param string $state        Returned state value.
     * @param string $redirect_uri The redirect URI used for the authorize step.
     * @return true|WP_Error True on success.
     */
    public function handle_callback( $code, $state, $redirect_uri ) {

        if ( ! wp_verify_nonce( $state, 'adt_ucp_oauth_state' ) ) {
            return new WP_Error( 'adt_ucp_oauth_state', __( 'Invalid OAuth state.', 'woo-product-feed-pro' ) );
        }

        $credentials = $this->get_credentials();

        if ( empty( $credentials['code_verifier'] ) ) {
            return new WP_Error( 'adt_ucp_oauth_verifier', __( 'No pending connect attempt was found. Start the connection again.', 'woo-product-feed-pro' ) );
        }

        $response = $this->token_request(
            array(
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $redirect_uri,
                'code_verifier' => (string) $credentials['code_verifier'],
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( empty( $response['access_token'] ) ) {
            return new WP_Error( 'adt_ucp_oauth_failed', __( 'The account server did not return an access token.', 'woo-product-feed-pro' ) );
        }

        // The verifier is single-use; clear it with the same write that
        // stores the tokens.
        $this->store_tokens( $response, array( 'code_verifier' => '' ) );

        Settings::instance()->set( 'account_connected', 'yes' );

        delete_transient( self::STATUS_TRANSIENT );

        return true;
    }

    /**
     * Disconnect the account.
     *
     * Asks the server to revoke the store link and its grants first, then
     * deletes the local credentials regardless of the revocation outcome —
     * a server outage must not trap a merchant in a connection they are
     * trying to sever. The client registration is deleted too; the next
     * connect re-registers.
     *
     * @since 13.6.0
     * @access public
     */
    public function disconnect() {

        if ( $this->is_connected() ) {
            $response = $this->request( 'POST', '/v1/account/disconnect' );

            if ( is_wp_error( $response ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( '[AdTribes UCP] Server-side disconnect failed (local credentials deleted anyway): %s', $response->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }

        delete_option( self::OPTION_NAME );
        delete_transient( self::STATUS_TRANSIENT );

        Settings::instance()->set( 'account_connected', 'no' );
    }

    /**
     * Verify an AP2 checkout mandate with the server.
     *
     * This call is also the server's first-hand metering point for
     * order-based billing (requirements Req. 14a): the verdict and the fee
     * debit are atomic server-side, and the response carries a signed
     * receipt the caller should persist on the order. The store identity
     * is token-bound — the body carries no store URL.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $mandate       The ap2.checkout_mandate credential (SD-JWT+kb).
     * @param array  $params        The full complete request body (mandate bundle context).
     * @param string $session_id    Checkout-session ID.
     * @param array  $authorization Amount/currency/line-item context the mandate must authorize.
     * @return array|WP_Error Verification response ({verified, receipt, fee, …}) when verified.
     */
    public function verify_checkout_mandate( $mandate, array $params, $session_id, array $authorization = array() ) {

        if ( ! $this->is_connected() ) {
            return new WP_Error( 'adt_ucp_not_connected', __( 'The store is not connected to an AdTribes account.', 'woo-product-feed-pro' ) );
        }

        $response = $this->request(
            'POST',
            '/v1/ap2/verify',
            array(
                'checkout_mandate' => $mandate,
                'session_id'       => $session_id,
                'payment'          => $params['payment'] ?? array(),
                'authorization'    => $authorization,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( empty( $response['verified'] ) ) {
            return new WP_Error(
                'adt_ucp_mandate_rejected',
                ! empty( $response['reason'] ) ? (string) $response['reason'] : __( 'The mandate was rejected by the verification service.', 'woo-product-feed-pro' )
            );
        }

        return $response;
    }

    /**
     * Report a WooCommerce refund of a verified agent order (rc.2).
     *
     * Credits the order's fee back to the account balance — a full refund
     * credits the remaining fee, a partial refund pro-rates the percentage
     * component. Idempotent per (session_id, refund_id), so retries are
     * safe.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $session_id          UCP checkout-session ID of the order.
     * @param string $refund_id           WooCommerce refund ID.
     * @param bool   $full_refund         Whether the order was refunded in full.
     * @param int    $refund_amount_cents Refunded order amount in minor units (ignored when full).
     * @return array|WP_Error Credit result ({credited_cents, balance_cents}).
     */
    public function report_refund( $session_id, $refund_id, $full_refund, $refund_amount_cents = 0 ) {

        if ( ! $this->is_connected() ) {
            return new WP_Error( 'adt_ucp_not_connected', __( 'The store is not connected to an AdTribes account.', 'woo-product-feed-pro' ) );
        }

        $body = array(
            'session_id' => (string) $session_id,
            'refund_id'  => (string) $refund_id,
        );

        if ( $full_refund ) {
            $body['full_refund'] = true;
        } else {
            $body['refund_amount_cents'] = (int) $refund_amount_cents;
        }

        return $this->request( 'POST', '/v1/ap2/refund', $body );
    }

    /**
     * Get the account status (connection, plan, balance, channel health).
     *
     * The live payload also feeds the low-balance admin notice, so every
     * successful fetch refreshes the cached copy (see get_cached_status()).
     *
     * @since 13.6.0
     * @access public
     *
     * @return array|WP_Error Status payload.
     */
    public function get_account_status() {

        if ( ! $this->is_connected() ) {
            return new WP_Error( 'adt_ucp_not_connected', __( 'The store is not connected to an AdTribes account.', 'woo-product-feed-pro' ) );
        }

        $response = $this->request( 'GET', '/v1/account/status' );

        if ( ! is_wp_error( $response ) ) {
            set_transient( self::STATUS_TRANSIENT, $response, 6 * HOUR_IN_SECONDS );
        }

        return $response;
    }

    /**
     * Get the cached account status without hitting the server.
     *
     * Used by the low-balance admin notice, which runs on every admin
     * request and must never block on a remote call. The cache refreshes
     * whenever get_account_status() succeeds (admin page loads).
     *
     * @since 13.6.0
     * @access public
     *
     * @return array Status payload, empty array when nothing is cached.
     */
    public function get_cached_status() {

        $cached = get_transient( self::STATUS_TRANSIENT );

        return is_array( $cached ) ? $cached : array();
    }

    /**
     * Get the current-period usage summary for the admin panel.
     *
     * @since 13.6.0
     * @access public
     *
     * @return array|WP_Error Summary payload (orders, fees, balance, plan).
     */
    public function get_usage_summary() {

        if ( ! $this->is_connected() ) {
            return new WP_Error( 'adt_ucp_not_connected', __( 'The store is not connected to an AdTribes account.', 'woo-product-feed-pro' ) );
        }

        return $this->request( 'GET', '/v1/usage/summary' );
    }

    /**
     * Get the storefront top-up link (contract 1.0.0-rc.4).
     *
     * Credits are sold on the adtribes.io WooCommerce store. The server
     * returns the store's add-to-cart URL carrying a signed account token;
     * the browser is sent there and the balance updates asynchronously once
     * the order completes.
     *
     * @since 13.6.0
     * @access public
     *
     * @return array|WP_Error Storefront link ({url, account_token}).
     */
    public function create_topup_link() {

        if ( ! $this->is_connected() ) {
            return new WP_Error( 'adt_ucp_not_connected', __( 'The store is not connected to an AdTribes account.', 'woo-product-feed-pro' ) );
        }

        return $this->request( 'POST', '/v1/billing/topup-link', array() );
    }

    /**
     * Create a Stripe-hosted session for a topup purchase or auto-topup setup.
     *
     * Retired by contract 1.0.0-rc.4 (a store-mode server responds 409
     * `use_topup_link`) — kept for older servers only.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $mode         Session mode: 'topup' or 'setup_auto_topup'.
     * @param int    $amount_cents Topup amount in minor units (topup mode).
     * @param string $success_url  Return URL after a completed session.
     * @param string $cancel_url   Return URL after an abandoned session.
     * @return array|WP_Error Hosted session ({url}).
     */
    public function create_topup_session( $mode, $amount_cents, $success_url, $cancel_url ) {

        if ( ! $this->is_connected() ) {
            return new WP_Error( 'adt_ucp_not_connected', __( 'The store is not connected to an AdTribes account.', 'woo-product-feed-pro' ) );
        }

        $body = array(
            'mode'        => (string) $mode,
            'success_url' => (string) $success_url,
            'cancel_url'  => (string) $cancel_url,
        );

        if ( (int) $amount_cents > 0 ) {
            $body['amount_cents'] = (int) $amount_cents;
        }

        return $this->request( 'POST', '/v1/billing/topup-session', $body );
    }

    /**
     * Configure auto-topup (rc.2).
     *
     * @since 13.6.0
     * @access public
     *
     * @param bool $enabled         Whether auto-topup is enabled.
     * @param int  $threshold_cents Balance threshold that triggers a recharge.
     * @param int  $amount_cents    Recharge amount in minor units.
     * @return array|WP_Error Stored configuration ({auto_topup}).
     */
    public function set_auto_topup( $enabled, $threshold_cents = 0, $amount_cents = 0 ) {

        if ( ! $this->is_connected() ) {
            return new WP_Error( 'adt_ucp_not_connected', __( 'The store is not connected to an AdTribes account.', 'woo-product-feed-pro' ) );
        }

        $body = array( 'enabled' => (bool) $enabled );

        if ( $enabled ) {
            $body['threshold_cents'] = (int) $threshold_cents;
            $body['amount_cents']    = (int) $amount_cents;
        }

        $response = $this->request( 'POST', '/v1/billing/auto-topup', $body );

        if ( ! is_wp_error( $response ) ) {
            delete_transient( self::STATUS_TRANSIENT );
        }

        return $response;
    }

    /**
     * Perform an authenticated JSON request against the account server.
     *
     * @since 13.6.0
     * @access private
     *
     * @param string $method        HTTP method.
     * @param string $path          Path relative to the base URL.
     * @param array  $body          JSON body (POST) or query args (GET).
     * @param bool   $authenticated Whether to send the bearer token.
     * @return array|WP_Error Decoded JSON response.
     */
    private function request( $method, $path, array $body = array(), $authenticated = true ) {

        $url  = $this->get_server_url() . $path;
        $args = array(
            'method'  => $method,
            'timeout' => 10,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
        );

        if ( $authenticated ) {
            $token = $this->get_access_token();
            if ( is_wp_error( $token ) ) {
                return $token;
            }
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }

        if ( 'GET' === $method ) {
            if ( ! empty( $body ) ) {
                $url = add_query_arg( array_map( 'rawurlencode', $body ), $url );
            }
        } else {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status  = (int) wp_remote_retrieve_response_code( $response );
        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status < 200 || $status >= 300 ) {
            return new WP_Error(
                'adt_ucp_server_error',
                sprintf( 'Account server responded with HTTP %d.', $status ),
                array(
                    'status' => $status,
                    'body'   => $decoded,
                )
            );
        }

        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Perform a form-encoded request against the OAuth token endpoint.
     *
     * The token endpoint speaks application/x-www-form-urlencoded (RFC
     * 6749) and authenticates with client_secret_post — the stored client
     * credentials are merged into every grant.
     *
     * @since 13.6.0
     * @access private
     *
     * @param array $fields Grant fields (grant_type, code, …).
     * @return array|WP_Error Decoded token response.
     */
    private function token_request( array $fields ) {

        $credentials = $this->get_credentials();

        if ( empty( $credentials['client_id'] ) || empty( $credentials['client_secret'] ) ) {
            return new WP_Error( 'adt_ucp_not_registered', __( 'The store is not registered with the account server yet.', 'woo-product-feed-pro' ) );
        }

        $fields['client_id']     = (string) $credentials['client_id'];
        $fields['client_secret'] = (string) $credentials['client_secret'];

        $response = wp_remote_post(
            $this->get_server_url() . '/oauth/token',
            array(
                'timeout' => 10,
                'headers' => array(
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ),
                'body'    => $fields,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status  = (int) wp_remote_retrieve_response_code( $response );
        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        $decoded = is_array( $decoded ) ? $decoded : array();

        if ( $status < 200 || $status >= 300 ) {
            // RFC 6749 §5.2 error body: surface error_description when present.
            $detail = ! empty( $decoded['error_description'] )
                ? (string) $decoded['error_description']
                : sprintf( 'Account server responded with HTTP %d.', $status );

            return new WP_Error(
                'adt_ucp_oauth_error',
                $detail,
                array(
                    'status' => $status,
                    'body'   => $decoded,
                )
            );
        }

        return $decoded;
    }

    /**
     * Get a valid access token, refreshing when expired.
     *
     * @since 13.6.0
     * @access private
     *
     * @return string|WP_Error Access token.
     */
    private function get_access_token() {

        $credentials = $this->get_credentials();

        if ( empty( $credentials['access_token'] ) ) {
            return new WP_Error( 'adt_ucp_not_connected', __( 'The store is not connected to an AdTribes account.', 'woo-product-feed-pro' ) );
        }

        if ( ! empty( $credentials['expires_at'] ) && time() >= (int) $credentials['expires_at'] - 60 ) {
            $refreshed = $this->refresh_tokens( $credentials );
            if ( is_wp_error( $refreshed ) ) {
                return $refreshed;
            }
            $credentials = $refreshed;
        }

        return (string) $credentials['access_token'];
    }

    /**
     * Refresh the access token.
     *
     * The server rotates the refresh token on every refresh and keeps the
     * previously-used one valid until the new one is first used, so
     * retrying with the last-persisted token after a failed write is safe.
     *
     * @since 13.6.0
     * @access private
     *
     * @param array $credentials Current credential set.
     * @return array|WP_Error New credential set.
     */
    private function refresh_tokens( array $credentials ) {

        if ( empty( $credentials['refresh_token'] ) ) {
            return new WP_Error( 'adt_ucp_not_connected', __( 'No refresh token is stored.', 'woo-product-feed-pro' ) );
        }

        $response = $this->token_request(
            array(
                'grant_type'    => 'refresh_token',
                'refresh_token' => $credentials['refresh_token'],
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( empty( $response['access_token'] ) ) {
            return new WP_Error( 'adt_ucp_refresh_failed', __( 'Token refresh failed.', 'woo-product-feed-pro' ) );
        }

        // The contract says the server always returns a rotated refresh
        // token; preserving the old one when a response omits it is a safe
        // belt-and-braces fallback either way.
        if ( empty( $response['refresh_token'] ) ) {
            $response['refresh_token'] = $credentials['refresh_token'];
        }

        return $this->store_tokens( $response );
    }

    /**
     * Store a token response, preserving the client registration.
     *
     * @since 13.6.0
     * @access private
     *
     * @param array $response Token endpoint response.
     * @param array $extra    Additional credential fields to merge in.
     * @return array Stored credential set (plaintext form).
     */
    private function store_tokens( array $response, array $extra = array() ) {

        return $this->save_credentials(
            array_merge(
                array(
                    'access_token'  => (string) ( $response['access_token'] ?? '' ),
                    'refresh_token' => (string) ( $response['refresh_token'] ?? '' ),
                    'expires_at'    => time() + (int) ( $response['expires_in'] ?? HOUR_IN_SECONDS ),
                ),
                $extra
            )
        );
    }

    /**
     * Merge fields into the encrypted credential set and persist it.
     *
     * @since 13.6.0
     * @access private
     *
     * @param array $updates Credential fields to merge.
     * @return array Stored credential set (plaintext form).
     */
    private function save_credentials( array $updates ) {

        $credentials = array_merge( $this->get_credentials(), $updates );

        $blob = Crypto::encrypt( (string) wp_json_encode( $credentials ), 'adt_ucp_account' );

        if ( false === get_option( self::OPTION_NAME ) ) {
            add_option( self::OPTION_NAME, $blob, '', 'no' );
        } else {
            update_option( self::OPTION_NAME, $blob );
        }

        return $credentials;
    }

    /**
     * Get the decrypted credential set.
     *
     * @since 13.6.0
     * @access private
     *
     * @return array Credential set, empty array when nothing is stored.
     */
    private function get_credentials() {

        $blob = get_option( self::OPTION_NAME );

        if ( ! is_string( $blob ) || '' === $blob ) {
            return array();
        }

        $decoded = json_decode( Crypto::decrypt( $blob, 'adt_ucp_account' ), true );

        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Base64url-encode raw bytes (RFC 4648 §5, unpadded).
     *
     * @since 13.6.0
     * @access private
     *
     * @param string $bytes Raw bytes.
     * @return string Base64url string.
     */
    private static function base64url_encode( $bytes ) {

        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }
}
