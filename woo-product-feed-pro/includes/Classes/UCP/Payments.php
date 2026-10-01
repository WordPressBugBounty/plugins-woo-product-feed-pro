<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Classes\UCP
 */

namespace AdTribes\PFP\Classes\UCP;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Classes\UCP\Gateways\FunnelKit_Stripe_Adapter;
use AdTribes\PFP\Classes\UCP\Gateways\Stripe_Gateway_Adapter;
use AdTribes\PFP\Classes\UCP\Gateways\WC_Stripe_Adapter;
use AdTribes\PFP\Traits\Singleton_Trait;
use WC_Order;
use WC_Product;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * UCP payment orchestration.
 *
 * Owns the complete-checkout flow: AP2 mandate verification (backend
 * call to the AdTribes server — fail closed), real-time stock re-check,
 * native order creation, hand-off to the merchant's own Stripe gateway
 * via the adapter layer, and 3DS escalation mapping. Also declares the
 * Google Pay payment handler on the profile and response envelopes when
 * a supported gateway is active.
 *
 * @since 13.6.0
 */
class Payments extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Google Pay UCP payment handler version (per the Google Pay handler doc).
     *
     * @since 13.6.0
     * @var string
     */
    const GOOGLE_PAY_HANDLER_VERSION = '2026-01-23';

    /**
     * Handler instance ID declared in profile and echoed by agents.
     *
     * @since 13.6.0
     * @var string
     */
    const GOOGLE_PAY_HANDLER_ID = 'gpay_stripe_1';

    /**
     * Get the registered gateway adapters, in preference order.
     *
     * @since 13.6.0
     * @access public
     *
     * @return Stripe_Gateway_Adapter[] Adapters.
     */
    public function get_adapters() {

        /**
         * Filter the registered UCP gateway adapters.
         *
         * @since 13.6.0
         *
         * @param Stripe_Gateway_Adapter[] $adapters Adapters in preference order.
         */
        return (array) apply_filters(
            'adt_ucp_gateway_adapters',
            array(
                new WC_Stripe_Adapter(),
                new FunnelKit_Stripe_Adapter(),
            )
        );
    }

    /**
     * Get the first available gateway adapter.
     *
     * @since 13.6.0
     * @access public
     *
     * @return Stripe_Gateway_Adapter|null Adapter, or null.
     */
    public function get_active_adapter() {

        foreach ( $this->get_adapters() as $adapter ) {
            if ( $adapter instanceof Stripe_Gateway_Adapter && $adapter->is_available() ) {
                return $adapter;
            }
        }

        return null;
    }

    /**
     * Get the first available adapter that can actually complete a payment.
     *
     * Detection-only adapters (e.g. FunnelKit pending its spike) are skipped
     * so payment handlers are only advertised when completion works.
     *
     * @since 13.6.0
     * @access public
     *
     * @return Stripe_Gateway_Adapter|null Adapter, or null.
     */
    public function get_completion_adapter() {

        foreach ( $this->get_adapters() as $adapter ) {
            if ( $adapter instanceof Stripe_Gateway_Adapter && $adapter->is_available() && $adapter->supports_completion() ) {
                return $adapter;
            }
        }

        return null;
    }

    /**
     * Complete a checkout session: verify mandate, re-check stock, create
     * the order, and settle through the merchant's gateway.
     *
     * Business failures are appended to the bridge as messages; the
     * returned snapshot always reflects the resulting state.
     *
     * @since 13.6.0
     * @access public
     *
     * @param Cart_Bridge     $bridge  Loaded bridge.
     * @param array           $params  Complete request body.
     * @param WP_REST_Request $request Request.
     * @return array Checkout snapshot.
     */
    public function complete( Cart_Bridge $bridge, array $params, WP_REST_Request $request ) {

        $snapshot = $bridge->get_snapshot();

        // Already finalized or awaiting a 3DS handoff: never re-create the
        // order or re-charge. A second complete (agent retry after 3DS, or a
        // lost response) returns the recorded state instead of a duplicate
        // order — this makes the escalation path idempotent.
        if ( in_array( $snapshot['status'], array( 'completed', 'canceled', 'requires_escalation' ), true ) ) {
            return $snapshot;
        }

        if ( 'ready_for_complete' !== $snapshot['status'] ) {
            return $snapshot; // Blocking `missing` messages are already included.
        }

        // Serialize completion for this session. Without this, two concurrent
        // complete requests (or a keyless timeout-retry) could both pass the
        // "no order yet" check below and each create an order and charge the
        // card. The lock is connection-scoped, so it holds across requests.
        $session_id = $bridge->get_session_id();

        if ( ! $this->acquire_lock( $session_id ) ) {
            $bridge->add_message( 'error', 'checkout_in_progress', 'A completion for this checkout is already in progress. Retry shortly.', 'recoverable' );
            return $bridge->get_snapshot();
        }

        try {
            // Inside the lock: if the winning request already created an order
            // for this session, reuse it rather than creating a second one.
            $existing = Orders::instance()->get_order_for_session( $session_id );
            if ( $existing instanceof WC_Order ) {
                return $this->finalize_from_order( $bridge, $existing );
            }

            // 1. AP2 mandate verification — fail closed.
            $verification = $this->verify_mandate( $bridge, $params, $snapshot );
            if ( false === $verification ) {
                return $bridge->get_snapshot();
            }

            // 2. Payment credential.
            $instrument = $this->extract_instrument( $params );
            $token      = (string) ( $instrument['credential']['token'] ?? '' );

            if ( '' === $token ) {
                $bridge->add_message( 'error', 'payment_failed', 'No payment credential was provided.', 'recoverable', '$.payment.instruments' );
                return $bridge->get_snapshot();
            }

            // 3. Gateway adapter — must be one that can complete, not just be detected.
            $adapter = $this->get_completion_adapter();
            if ( null === $adapter ) {
                $bridge->add_message( 'error', 'payment_failed', 'No payment gateway on this store supports agentic checkout completion.', 'unrecoverable' );
                return $bridge->get_snapshot();
            }

            // 4. Real-time stock re-check — agents may hold stale data, and a
            // wrong-total or oversold order is a merchant-facing money bug.
            foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
                $product = $cart_item['data'] ?? null;

                // A product trashed or deleted after the cart was hydrated
                // leaves a non-object here — fataling in the charge path.
                if ( ! $product instanceof WC_Product ) {
                    $bridge->add_message( 'error', 'out_of_stock', sprintf( 'Item %s is no longer available.', (string) $cart_item_key ), 'recoverable' );
                    return $bridge->get_snapshot();
                }

                if ( ! $product->is_in_stock() || ! $product->has_enough_stock( $cart_item['quantity'] ) ) {
                    $bridge->add_message( 'error', 'out_of_stock', sprintf( 'Item %s is no longer available in the requested quantity.', $product->get_id() ), 'recoverable' );
                    return $bridge->get_snapshot();
                }
            }

            // 5. Create the native WooCommerce order (pending payment).
            $order = Orders::instance()->create_from_bridge( $bridge, $request, $adapter->get_gateway_id() );
            if ( is_wp_error( $order ) ) {
                $bridge->add_message( 'error', 'payment_failed', 'The order could not be created.', 'recoverable' );
                return $bridge->get_snapshot();
            }

            // Persist the server's signed verification receipt and the fee it
            // debited — the audit trail for the charge and the anchor for
            // refund fee-credit symmetry.
            if ( ! empty( $verification['receipt'] ) ) {
                $order->update_meta_data( Orders::META_VERIFY_RECEIPT, (string) $verification['receipt'] );
            }
            if ( ! empty( $verification['fee'] ) && is_array( $verification['fee'] ) ) {
                $order->update_meta_data( Orders::META_VERIFY_FEE, wp_json_encode( $verification['fee'] ) );
            }
            if ( ! empty( $verification['receipt'] ) || ! empty( $verification['fee'] ) ) {
                $order->save();
            }

            // 6. Settle through the merchant's gateway.
            $result       = $adapter->complete_payment( $order, $token, $instrument );
            $confirmation = Orders::confirmation( $order );

            switch ( $result['outcome'] ?? Stripe_Gateway_Adapter::OUTCOME_FAILURE ) {
                case Stripe_Gateway_Adapter::OUTCOME_SUCCESS:
                    $bridge->set_terminal_status( 'completed', $confirmation );
                    break;

                case Stripe_Gateway_Adapter::OUTCOME_REQUIRES_ACTION:
                    $continue_url = ! empty( $result['redirect_url'] ) ? (string) $result['redirect_url'] : $order->get_checkout_payment_url();
                    $bridge->set_escalation( $continue_url, $confirmation );
                    break;

                default:
                    $order->update_status( 'failed', 'UCP agentic payment failed: ' . (string) ( $result['error'] ?? '' ) );
                    $bridge->add_message( 'error', 'payment_failed', (string) ( $result['error'] ?? 'The payment could not be completed.' ), 'recoverable', '$.payment' );
                    break;
            }

            return $bridge->get_snapshot();
        } finally {
            $this->release_lock( $session_id );
        }
    }

    /**
     * Resolve the checkout state from an order that already exists for the
     * session, without re-creating it or re-charging the card.
     *
     * @since 13.6.0
     * @access private
     *
     * @param Cart_Bridge $bridge Loaded bridge.
     * @param WC_Order    $order  Existing order for the session.
     * @return array Checkout snapshot.
     */
    private function finalize_from_order( Cart_Bridge $bridge, WC_Order $order ) {

        $confirmation = Orders::confirmation( $order );

        if ( $order->is_paid() || $order->has_status( array( 'processing', 'completed', 'on-hold' ) ) ) {
            $bridge->set_terminal_status( 'completed', $confirmation );
        } else {
            // Still awaiting payment (e.g. 3DS in progress): resume the same
            // order rather than starting a new checkout.
            $bridge->set_escalation( $order->get_checkout_payment_url(), $confirmation );
        }

        return $bridge->get_snapshot();
    }

    /**
     * Acquire a connection-scoped lock for a checkout session.
     *
     * @since 13.6.0
     * @access private
     *
     * @param string $session_id Checkout-session ID.
     * @return bool True when the lock was acquired.
     */
    private function acquire_lock( $session_id ) {

        global $wpdb;

        $got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->lock_name( $session_id ), 5 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        return '1' === (string) $got;
    }

    /**
     * Release the session lock.
     *
     * @since 13.6.0
     * @access private
     *
     * @param string $session_id Checkout-session ID.
     */
    private function release_lock( $session_id ) {

        global $wpdb;

        $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name( $session_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Build the MySQL named-lock string for a session.
     *
     * MySQL lock names are server-global and capped at 64 characters, so the
     * session ID is namespaced and hashed to a fixed length.
     *
     * @since 13.6.0
     * @access private
     *
     * @param string $session_id Checkout-session ID.
     * @return string Lock name.
     */
    private function lock_name( $session_id ) {

        return 'adt_ucp_' . substr( hash( 'sha256', (string) $session_id ), 0, 40 );
    }

    /**
     * Verify the AP2 checkout mandate, appending messages on failure.
     *
     * Resolution order: the `adt_ucp_verify_checkout_mandate` filter
     * (testing/mocks), then the AdTribes server verify API. The server
     * meters the completed order first-hand at this call (Req. 14a).
     * Fail closed: no verifiable mandate, no completion.
     *
     * @since 13.6.0
     * @access private
     *
     * @param Cart_Bridge $bridge   Loaded bridge.
     * @param array       $params   Complete request body.
     * @param array       $snapshot Checkout snapshot already computed by the caller.
     * @return array|false Verification response ({verified, receipt, fee, …}; empty
     *                     array when a testing filter short-circuited), false on failure.
     */
    private function verify_mandate( Cart_Bridge $bridge, array $params, array $snapshot ) {

        $mandate = (string) ( $params['ap2']['checkout_mandate'] ?? '' );

        if ( '' === $mandate ) {
            $bridge->add_message( 'error', 'mandate_required', 'An ap2.checkout_mandate is required to complete this checkout.', 'recoverable', '$.ap2.checkout_mandate' );
            return false;
        }

        /**
         * Filter the mandate verification verdict before the server call.
         *
         * Return true/false to short-circuit (used by tests and staging);
         * null defers to the AdTribes server verify API.
         *
         * @since 13.6.0
         *
         * @param bool|null   $verdict Verification verdict, null to defer.
         * @param string      $mandate The ap2.checkout_mandate credential.
         * @param array       $params  Complete request body.
         * @param Cart_Bridge $bridge  Loaded bridge.
         */
        $verdict = apply_filters( 'adt_ucp_verify_checkout_mandate', null, $mandate, $params, $bridge );

        // The short-circuit verdict is a testing/staging affordance only. On a
        // live store a stray filter (a leftover test double, a mis-scoped
        // mu-plugin) must never be able to disable mandate verification, so a
        // non-null verdict is honored only on a designated testing site.
        if ( null !== $verdict && ! ( defined( 'ADT_PFP_TESTING_SITE' ) && ADT_PFP_TESTING_SITE ) ) {
            $verdict = null;
        }

        if ( null !== $verdict ) {
            // Filter short-circuit (testing site only): a true verdict carries
            // no server response — no receipt or fee to persist.
            if ( true !== $verdict ) {
                $bridge->add_message( 'error', 'mandate_verification_failed', 'The checkout mandate could not be verified.', 'recoverable', '$.ap2.checkout_mandate' );
                return false;
            }

            return array();
        }

        $verification = Account_Client::instance()->verify_checkout_mandate(
            $mandate,
            $params,
            $bridge->get_session_id(),
            $this->authorization_context( $snapshot )
        );

        if ( is_wp_error( $verification ) ) {
            $bridge->add_message( 'error', 'mandate_verification_failed', $verification->get_error_message(), 'recoverable', '$.ap2.checkout_mandate' );
            return false;
        }

        return $verification;
    }

    /**
     * Build the amount/currency/line-item context the mandate must authorize.
     *
     * The AP2 mandate binds the buyer's authorization to a specific amount
     * and items. Sending that context lets the verification server assert the
     * mandate authorizes exactly this cart rather than trusting the agent to
     * have kept it in sync — the amount binding is the security-critical half
     * of mandate verification.
     *
     * @since 13.6.0
     * @access private
     *
     * @param array $snapshot Checkout snapshot already computed by the caller.
     * @return array Authorization context {amount_cents, currency, line_items} per contract 1.0.0-rc.2.
     */
    private function authorization_context( array $snapshot ) {

        $line_items = array_map(
            function ( $item ) {
                return array(
                    'id'       => (string) ( $item['item']['id'] ?? '' ),
                    'quantity' => (int) ( $item['quantity'] ?? 0 ),
                    'price'    => (int) ( $item['item']['price'] ?? 0 ),
                );
            },
            (array) ( $snapshot['line_items'] ?? array() )
        );

        // The snapshot totals carry exactly one `total` entry, already in
        // minor units — that amount is what the mandate must authorize.
        $amount_cents = 0;
        foreach ( (array) ( $snapshot['totals'] ?? array() ) as $total ) {
            if ( 'total' === ( $total['type'] ?? '' ) ) {
                $amount_cents = (int) ( $total['amount'] ?? 0 );
                break;
            }
        }

        return array(
            'amount_cents' => $amount_cents,
            'currency'     => (string) ( $snapshot['currency'] ?? get_woocommerce_currency() ),
            'line_items'   => $line_items,
        );
    }

    /**
     * Pick the selected payment instrument from the request.
     *
     * @since 13.6.0
     * @access private
     *
     * @param array $params Complete request body.
     * @return array Instrument, possibly empty.
     */
    private function extract_instrument( array $params ) {

        $instruments = isset( $params['payment']['instruments'] ) && is_array( $params['payment']['instruments'] )
            ? $params['payment']['instruments']
            : array();

        foreach ( $instruments as $instrument ) {
            if ( ! empty( $instrument['selected'] ) ) {
                return (array) $instrument;
            }
        }

        return isset( $instruments[0] ) ? (array) $instruments[0] : array();
    }

    /**
     * Declare the Google Pay payment handler on the UCP profile.
     *
     * Declaration only — UCP payment handlers are not callable endpoints;
     * the agent acquires the instrument itself and posts it to complete.
     * Exact config member names to be ratified against the Google Pay
     * handler doc during Merchant Center onboarding.
     *
     * @since 13.6.0
     *
     * @param array $profile Profile document.
     * @return array Profile document.
     */
    public function declare_profile_payment_handlers( $profile ) {

        $adapter = $this->get_completion_adapter();

        if ( null === $adapter ) {
            return $profile;
        }

        $publishable_key = $adapter->get_publishable_key();
        if ( '' === $publishable_key ) {
            return $profile;
        }

        $profile['payment_handlers'] = array(
            'com.google.pay' => array(
                array(
                    'id'      => self::GOOGLE_PAY_HANDLER_ID,
                    'version' => self::GOOGLE_PAY_HANDLER_VERSION,
                    'config'  => array(
                        'allowed_payment_methods' => array(
                            array(
                                'type'       => 'CARD',
                                'parameters' => array(
                                    'allowed_auth_methods' => array( 'PAN_ONLY', 'CRYPTOGRAM_3DS' ),
                                    'allowed_card_networks' => array( 'AMEX', 'DISCOVER', 'MASTERCARD', 'VISA' ),
                                ),
                                'tokenization_specification' => array(
                                    'type'       => 'PAYMENT_GATEWAY',
                                    'parameters' => array(
                                        'gateway'        => 'stripe',
                                        'stripe:publishableKey' => $publishable_key,
                                        'stripe:version' => '2024-06-20',
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );

        return $profile;
    }

    /**
     * Declare the Google Pay handler instance on response envelopes.
     *
     * @since 13.6.0
     *
     * @param array $handlers Handler name => instances.
     * @return array Handlers.
     */
    public function declare_response_payment_handlers( $handlers ) {

        if ( null === $this->get_completion_adapter() ) {
            return $handlers;
        }

        $handlers['com.google.pay'] = array(
            array(
                'id'      => self::GOOGLE_PAY_HANDLER_ID,
                'version' => self::GOOGLE_PAY_HANDLER_VERSION,
            ),
        );

        return $handlers;
    }

    /**
     * Run the class.
     *
     * @since 13.6.0
     * @access public
     */
    public function run() {

        add_filter( 'adt_ucp_profile_data', array( $this, 'declare_profile_payment_handlers' ) );
        add_filter( 'adt_ucp_response_payment_handlers', array( $this, 'declare_response_payment_handlers' ) );
    }
}
