<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Classes\UCP
 */

namespace AdTribes\PFP\Classes\UCP;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Classes\Admin_Pages\UCP_Page;
use AdTribes\PFP\Traits\Singleton_Trait;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-side OAuth connect flow for the AdTribes account.
 *
 * Owns the two browser legs of the authorization-code link: the connect
 * action (register the OAuth client when needed, then send the merchant
 * to the server's consent screen) and the callback leg (exchange the
 * returned code for tokens). The consent screen handles inline signup,
 * and the checkout channel only activates after the first credit topup —
 * the connect copy sets that expectation.
 *
 * @since 13.6.0
 */
class Account_Connect extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Query flag that starts the connect flow.
     *
     * @since 13.6.0
     * @var string
     */
    const CONNECT_ARG = 'adt_ucp_connect';

    /**
     * Query flag carrying the connect outcome back to the page.
     *
     * @since 13.6.0
     * @var string
     */
    const RESULT_ARG = 'adt_ucp_connect_result';

    /**
     * Transient holding the last connect error detail, per user.
     *
     * @since 13.6.0
     * @var string
     */
    const ERROR_TRANSIENT_PREFIX = 'adt_ucp_connect_error_';

    /**
     * URL of the admin action that starts the connect flow.
     *
     * @since 13.6.0
     * @access public
     *
     * @return string Nonce-protected admin URL.
     */
    public static function get_connect_action_url() {

        // Not wp_nonce_url(): that HTML-escapes the ampersands, and this URL
        // travels through JSON into a Vue :href binding (a DOM property, no
        // entity decoding), where `&amp;` would corrupt the query args.
        return add_query_arg(
            array(
                self::CONNECT_ARG => '1',
                '_wpnonce'        => wp_create_nonce( 'adt_ucp_connect' ),
            ),
            self::get_page_url()
        );
    }

    /**
     * Bare AI Discovery page URL — the OAuth redirect URI.
     *
     * Must stay byte-identical across register, authorize and the token
     * exchange, so every leg derives it from this single helper.
     *
     * @since 13.6.0
     * @access public
     *
     * @return string Admin page URL.
     */
    public static function get_page_url() {

        return admin_url( 'admin.php?page=' . UCP_Page::MENU_SLUG );
    }

    /**
     * Whether the current request targets the AI Discovery admin page.
     *
     * @since 13.6.0
     * @access private
     *
     * @return bool True on the AI Discovery page.
     */
    private function is_ucp_page_request() {

        return isset( $_GET['page'] ) && UCP_Page::MENU_SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing check only; each handler verifies its own nonce/state.
    }

    /**
     * Route the connect and callback legs on the AI Discovery page.
     *
     * @since 13.6.0
     * @access public
     */
    public function handle_connect_flow() {

        if ( ! $this->is_ucp_page_request() ) {
            return;
        }

        /** This filter is documented in includes/REST/UCP/Admin.php */
        if ( ! current_user_can( apply_filters( 'adt_pfp_admin_capability', 'manage_options' ) ) ) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Each branch verifies its own nonce (connect) or OAuth state nonce (callback).
        if ( isset( $_GET[ self::CONNECT_ARG ] ) ) {
            $this->start_connect();
            return;
        }

        if ( isset( $_GET['code'], $_GET['state'] ) ) {
            $this->finish_connect(
                sanitize_text_field( wp_unslash( $_GET['code'] ) ),
                sanitize_text_field( wp_unslash( $_GET['state'] ) )
            );
            return;
        }

        if ( isset( $_GET['error'], $_GET['state'] ) ) {
            $this->redirect_with_result( 'denied' );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Connect leg: register the client when needed, then send the
     * merchant to the server's consent screen.
     *
     * @since 13.6.0
     * @access private
     */
    private function start_connect() {

        check_admin_referer( 'adt_ucp_connect' );

        $client = Account_Client::instance();

        if ( ! $client->is_registered() ) {
            $registered = $client->register( self::get_page_url() );

            if ( is_wp_error( $registered ) ) {
                $this->redirect_with_result( 'error', $registered->get_error_message() );
                return;
            }
        }

        $authorize_url = $client->get_connect_url( self::get_page_url() );

        if ( is_wp_error( $authorize_url ) ) {
            $this->redirect_with_result( 'error', $authorize_url->get_error_message() );
            return;
        }

        wp_redirect( esc_url_raw( $authorize_url ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External OAuth consent screen; host is the configured account server.
        exit;
    }

    /**
     * Callback leg: exchange the authorization code for tokens.
     *
     * @since 13.6.0
     * @access private
     *
     * @param string $code  Authorization code.
     * @param string $state Returned state value.
     */
    private function finish_connect( $code, $state ) {

        $result = Account_Client::instance()->handle_callback( $code, $state, self::get_page_url() );

        if ( is_wp_error( $result ) ) {
            $this->redirect_with_result( 'error', $result->get_error_message() );
            return;
        }

        $this->redirect_with_result( 'connected' );
    }

    /**
     * Redirect back to a clean page URL carrying the outcome flag.
     *
     * @since 13.6.0
     * @access private
     *
     * @param string $result Outcome: connected | denied | error.
     * @param string $detail Error detail shown in the admin notice.
     */
    private function redirect_with_result( $result, $detail = '' ) {

        if ( '' !== $detail ) {
            set_transient( self::ERROR_TRANSIENT_PREFIX . get_current_user_id(), $detail, 5 * MINUTE_IN_SECONDS );
        }

        wp_safe_redirect( add_query_arg( self::RESULT_ARG, rawurlencode( $result ), self::get_page_url() ) );
        exit;
    }

    /**
     * Show the connect outcome as an admin notice on the page.
     *
     * @since 13.6.0
     * @access public
     */
    public function render_connect_notice() {

        if ( ! $this->is_ucp_page_request() || ! isset( $_GET[ self::RESULT_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only outcome flag set by our own redirect.
            return;
        }

        $result = sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only outcome flag.

        if ( 'connected' === $result ) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html__( 'Your AdTribes account is connected. The agentic checkout channel activates after your first credit top-up.', 'woo-product-feed-pro' )
            );
            return;
        }

        if ( 'denied' === $result ) {
            printf(
                '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                esc_html__( 'The connection request was declined on the AdTribes consent screen. No changes were made.', 'woo-product-feed-pro' )
            );
            return;
        }

        $detail = get_transient( self::ERROR_TRANSIENT_PREFIX . get_current_user_id() );
        delete_transient( self::ERROR_TRANSIENT_PREFIX . get_current_user_id() );

        printf(
            '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
            esc_html(
                is_string( $detail ) && '' !== $detail
                    ? sprintf(
                        /* translators: %s: error detail from the account server. */
                        __( 'Connecting your AdTribes account failed: %s', 'woo-product-feed-pro' ),
                        $detail
                    )
                    : __( 'Connecting your AdTribes account failed. Please try again.', 'woo-product-feed-pro' )
            )
        );
    }

    /**
     * Run the class.
     *
     * @since 13.6.0
     * @access public
     */
    public function run() {

        add_action( 'admin_init', array( $this, 'handle_connect_flow' ) );
        add_action( 'admin_notices', array( $this, 'render_connect_notice' ) );
    }
}
