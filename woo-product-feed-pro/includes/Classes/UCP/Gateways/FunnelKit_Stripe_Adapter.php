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
 * Adapter for FunnelKit Stripe (fkwcs).
 *
 * Detection and publishable-key resolution are implemented; the
 * externally-supplied-PaymentMethod completion path is pending the
 * build-time spike flagged in the project brief — until then
 * complete_payment() reports failure and pre-flight steers merchants
 * with only FunnelKit toward completing the spike or activating the
 * WooCommerce Stripe Gateway.
 *
 * @since 13.6.0
 */
class FunnelKit_Stripe_Adapter extends Stripe_Gateway_Adapter {

    /**
     * Adapter slug.
     *
     * @since 13.6.0
     * @access public
     *
     * @return string Slug.
     */
    public function get_id() {

        return 'funnelkit_stripe';
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

        return 'fkwcs_stripe';
    }

    /**
     * Whether FunnelKit Stripe is active and enabled.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool True when available.
     */
    public function is_available() {

        if ( ! defined( 'FKWCS_VERSION' ) && ! class_exists( 'FKWCS_Gateway_Stripe' ) ) {
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

        $gateway = $this->get_gateway();

        if ( null === $gateway || ! method_exists( $gateway, 'get_option' ) ) {
            return '';
        }

        $testmode = 'test' === $gateway->get_option( 'mode' ) || 'yes' === $gateway->get_option( 'testmode' );

        $key = $testmode ? $gateway->get_option( 'test_public_key' ) : $gateway->get_option( 'public_key' );

        return (string) $key;
    }

    /**
     * Completion is pending the FunnelKit deferred-intent spike, so this
     * adapter must not be advertised as a working payment handler.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool Always false until the spike lands.
     */
    public function supports_completion() {

        return false;
    }

    /**
     * Complete payment — pending the FunnelKit deferred-intent spike.
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

        return array(
            'outcome' => self::OUTCOME_FAILURE,
            'error'   => __( 'FunnelKit Stripe checkout completion is not yet supported for agentic orders.', 'woo-product-feed-pro' ),
        );
    }
}
