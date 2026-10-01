<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Classes\UCP\Gateways
 */

namespace AdTribes\PFP\Classes\UCP\Gateways;

use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for the official WooCommerce Stripe Gateway.
 *
 * Drives the documented deferred-intent path (the same one Shopify's
 * ucp-proxy uses against WooCommerce): `payment_method=stripe`,
 * `wc-stripe-payment-method={pm_…}`, `wc-stripe-is-deferred-intent=true`
 * posted through the gateway's own process_payment().
 *
 * @since 13.6.0
 */
class WC_Stripe_Adapter extends Stripe_Gateway_Adapter {

    /**
     * Adapter slug.
     *
     * @since 13.6.0
     * @access public
     *
     * @return string Slug.
     */
    public function get_id() {

        return 'wc_stripe';
    }

    /**
     * WooCommerce gateway ID.
     *
     * @since 13.6.0
     * @access public
     *
     * @return string Gateway ID.
     */
    public function get_gateway_id() {

        return 'stripe';
    }

    /**
     * Whether WooCommerce Stripe Gateway is active and enabled.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool True when available.
     */
    public function is_available() {

        if ( ! defined( 'WC_STRIPE_VERSION' ) && ! class_exists( 'WC_Stripe' ) ) {
            return false;
        }

        $gateway = $this->get_gateway();

        return null !== $gateway && 'yes' === $gateway->enabled;
    }

    /**
     * The Stripe publishable key for the active mode.
     *
     * @since 13.6.0
     * @access public
     *
     * @return string Publishable key.
     */
    public function get_publishable_key() {

        $settings = (array) get_option( 'woocommerce_stripe_settings', array() );
        $testmode = 'yes' === ( $settings['testmode'] ?? 'no' );

        return (string) ( $testmode ? ( $settings['test_publishable_key'] ?? '' ) : ( $settings['publishable_key'] ?? '' ) );
    }

    /**
     * Complete payment via the deferred-intent path.
     *
     * @since 13.6.0
     * @access public
     *
     * @param WC_Order $order            Pending order.
     * @param string   $credential_token Stripe PaymentMethod token (`pm_…`).
     * @param array    $instrument       Full UCP payment instrument object.
     * @return array Outcome array.
     */
    public function complete_payment( WC_Order $order, $credential_token, array $instrument ) {

        $gateway = $this->get_gateway();

        if ( null === $gateway ) {
            return array(
                'outcome' => self::OUTCOME_FAILURE,
                'error'   => __( 'The Stripe gateway is not available.', 'woo-product-feed-pro' ),
            );
        }

        $order->set_payment_method( $gateway );
        $order->save();

        // The documented ucp-proxy request params for the deferred-intent path.
        $previous_post = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        $_POST['payment_method']               = $this->get_gateway_id();
        $_POST['wc-stripe-payment-method']     = wc_clean( $credential_token );
        $_POST['wc-stripe-is-deferred-intent'] = 'true';

        try {
            $result = $gateway->process_payment( $order->get_id() );
        } catch ( \Exception $e ) {
            return array(
                'outcome' => self::OUTCOME_FAILURE,
                'error'   => $e->getMessage(),
            );
        } finally {
            $_POST = $previous_post;
        }

        return $this->interpret_result( $order, is_array( $result ) ? $result : array() );
    }
}
