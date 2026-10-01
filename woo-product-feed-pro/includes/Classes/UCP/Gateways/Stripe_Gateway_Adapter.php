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
 * Base class for Stripe gateway adapters.
 *
 * UCP settlement rides the merchant's own Stripe gateway: the agent
 * delivers a Stripe PaymentMethod (`pm_…`) credential and the adapter
 * completes payment through the plugin-specific deferred-intent path.
 * AdTribes never touches funds.
 *
 * @since 13.6.0
 */
abstract class Stripe_Gateway_Adapter {

    /**
     * Payment completed successfully.
     *
     * @since 13.6.0
     * @var string
     */
    const OUTCOME_SUCCESS = 'success';

    /**
     * Payment needs buyer action (e.g. 3DS) — escalate with a continue URL.
     *
     * @since 13.6.0
     * @var string
     */
    const OUTCOME_REQUIRES_ACTION = 'requires_action';

    /**
     * Payment failed.
     *
     * @since 13.6.0
     * @var string
     */
    const OUTCOME_FAILURE = 'failure';

    /**
     * Adapter slug.
     *
     * @since 13.6.0
     * @access public
     *
     * @return string Slug.
     */
    abstract public function get_id();

    /**
     * The WooCommerce payment gateway ID this adapter drives.
     *
     * @since 13.6.0
     * @access public
     *
     * @return string Gateway ID.
     */
    abstract public function get_gateway_id();

    /**
     * Whether the underlying gateway plugin is active and enabled.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool True when available.
     */
    abstract public function is_available();

    /**
     * The Stripe publishable key for the active mode.
     *
     * @since 13.6.0
     * @access public
     *
     * @return string Publishable key, empty string when unavailable.
     */
    abstract public function get_publishable_key();

    /**
     * Complete payment for an order with an agent-supplied credential.
     *
     * @since 13.6.0
     * @access public
     *
     * @param WC_Order $order            Pending order.
     * @param string   $credential_token Stripe PaymentMethod token (`pm_…`).
     * @param array    $instrument       Full UCP payment instrument object.
     * @return array {
     *     @type string $outcome      One of the OUTCOME_* constants.
     *     @type string $redirect_url Buyer-facing URL (requires_action only).
     *     @type string $error        Failure detail (failure only).
     * }
     */
    abstract public function complete_payment( WC_Order $order, $credential_token, array $instrument );

    /**
     * Whether this adapter can actually complete an agentic payment.
     *
     * Detection-only adapters (gateway recognized, completion path not built
     * yet) must return false so the store never advertises a payment handler
     * it cannot honor, and pre-flight reports the gateway as not ready.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool True when complete_payment() is implemented.
     */
    public function supports_completion() {

        return true;
    }

    /**
     * Resolve the WooCommerce gateway object this adapter drives.
     *
     * @since 13.6.0
     * @access protected
     *
     * @return \WC_Payment_Gateway|null Gateway, or null.
     */
    protected function get_gateway() {

        if ( ! function_exists( 'WC' ) || null === WC()->payment_gateways() ) {
            return null;
        }

        $gateways = WC()->payment_gateways()->payment_gateways();

        return $gateways[ $this->get_gateway_id() ] ?? null;
    }

    /**
     * Interpret the order state after a process_payment() call.
     *
     * @since 13.6.0
     * @access protected
     *
     * @param WC_Order $order    Order (will be re-read).
     * @param array    $result   The gateway's process_payment() result.
     * @return array Outcome array (see complete_payment()).
     */
    protected function interpret_result( WC_Order $order, array $result ) {

        if ( 'success' !== ( $result['result'] ?? '' ) ) {
            return array(
                'outcome' => self::OUTCOME_FAILURE,
                'error'   => __( 'The payment gateway declined the payment.', 'woo-product-feed-pro' ),
            );
        }

        // Re-read: the gateway mutates the order inside process_payment().
        $order = wc_get_order( $order->get_id() );

        if ( $order && ! $order->needs_payment() ) {
            return array( 'outcome' => self::OUTCOME_SUCCESS );
        }

        // Successful result but still unpaid: buyer confirmation required (3DS).
        return array(
            'outcome'      => self::OUTCOME_REQUIRES_ACTION,
            'redirect_url' => ! empty( $result['redirect'] ) ? (string) $result['redirect'] : ( $order ? $order->get_checkout_payment_url() : '' ),
        );
    }
}
