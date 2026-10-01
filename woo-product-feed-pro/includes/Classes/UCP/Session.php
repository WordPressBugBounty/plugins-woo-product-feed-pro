<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Classes\UCP
 */

namespace AdTribes\PFP\Classes\UCP;

defined( 'ABSPATH' ) || exit;

// The parent class is lazily loaded by WooCommerce's autoloader; this file is
// itself only loaded on demand by the plugin autoloader, after WooCommerce boots.
if ( ! class_exists( '\WC_Session_Handler' ) ) {
    return;
}

/**
 * Headless, token-keyed WooCommerce session.
 *
 * UCP checkout requests arrive with no browser cookies; the checkout
 * session ID embeds a cart token which is used directly as the
 * WooCommerce session key. This handler loads/persists session data for
 * that key via the standard wp_woocommerce_sessions table (so Woo's own
 * session GC applies) while neutralizing all cookie behavior.
 *
 * @since 13.6.0
 */
class Session extends \WC_Session_Handler {

    /**
     * Default checkout-session TTL in seconds (before the
     * `adt_ucp_checkout_session_ttl` filter).
     *
     * @since 13.6.0
     * @var int
     */
    const DEFAULT_TTL = 6 * HOUR_IN_SECONDS;

    /**
     * Constructor.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $token Cart token used as the session key.
     */
    public function __construct( $token ) {

        parent::__construct();
        $this->_customer_id = $token;
    }

    /**
     * Initialize the session from the token — no cookies involved.
     *
     * @since 13.6.0
     * @access public
     */
    public function init() {

        $this->set_session_expiration();
        $data        = $this->get_session( $this->_customer_id, array() );
        $this->_data = is_array( $data ) ? $data : array();

        // Safety net; explicit save_data() calls happen after each mutation.
        add_action( 'shutdown', array( $this, 'save_data' ), 20 );
    }

    /**
     * Set the session expiration to the UCP checkout-session TTL.
     *
     * The spec's default checkout-session TTL is 6 hours.
     *
     * @since 13.6.0
     * @access public
     */
    public function set_session_expiration() {

        /**
         * Filter the UCP checkout-session TTL in seconds.
         *
         * @since 13.6.0
         *
         * @param int $ttl TTL in seconds.
         */
        $ttl = (int) apply_filters( 'adt_ucp_checkout_session_ttl', self::DEFAULT_TTL );

        $this->_session_expiring   = time() + max( 0, $ttl - HOUR_IN_SECONDS );
        $this->_session_expiration = time() + $ttl;
    }

    /**
     * Get the session expiration timestamp.
     *
     * @since 13.6.0
     * @access public
     *
     * @return int Unix timestamp.
     */
    public function get_expiration() {

        return (int) $this->_session_expiration;
    }

    /**
     * Cookie initialization is a no-op for headless sessions.
     *
     * @since 13.6.0
     * @access public
     */
    public function init_session_cookie() {}

    /**
     * Cookie writing is a no-op for headless sessions.
     *
     * @since 13.6.0
     * @access public
     *
     * @param bool $set Ignored.
     */
    public function set_customer_session_cookie( $set ) {}

    /**
     * A token-keyed session always "has" a session.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool Always true.
     */
    public function has_session() {

        return true;
    }

    /**
     * Destroy the stored session without touching cookies.
     *
     * @since 13.6.0
     * @access public
     */
    public function forget_session() {

        $this->delete_session( $this->_customer_id );

        $this->_data  = array();
        $this->_dirty = false;
    }
}
