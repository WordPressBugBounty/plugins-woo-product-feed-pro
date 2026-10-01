<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Classes\UCP
 */

namespace AdTribes\PFP\Classes\UCP;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Traits\Singleton_Trait;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Refund fee-credit reporting to the AdTribes account server (rc.2).
 *
 * When a verified agent order is refunded in WooCommerce, the server is
 * told so it credits the order's fee back to the prepaid balance — a
 * full refund credits the remaining fee, a partial refund pro-rates the
 * percentage component. The server call is idempotent per
 * (session_id, refund_id), so delivery retries can never double-credit.
 *
 * Reports are dispatched through Action Scheduler with retry/backoff so
 * a server blip during the admin refund action doesn't lose the credit;
 * without Action Scheduler the call runs inline as a best effort.
 *
 * @since 13.6.0
 */
class Refunds extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Action Scheduler hook for reporting a refund.
     *
     * @since 13.6.0
     * @var string
     */
    const REPORT_HOOK = 'adt_ucp_report_refund';

    /**
     * Maximum delivery attempts.
     *
     * @since 13.6.0
     * @var int
     */
    const MAX_ATTEMPTS = 5;

    /**
     * Queue a fee-credit report when a UCP order is refunded.
     *
     * @since 13.6.0
     *
     * @param int $order_id  Order ID.
     * @param int $refund_id Refund ID.
     */
    public function on_order_refunded( $order_id, $refund_id ) {

        $order = wc_get_order( $order_id );

        if ( ! $order instanceof WC_Order || ! Orders::is_ucp_order( $order ) ) {
            return;
        }

        if ( '' === (string) $order->get_meta( Orders::META_SESSION_ID ) ) {
            return;
        }

        if ( ! Account_Client::instance()->is_connected() ) {
            // Nothing to report against — but a fee that was debited and now
            // can't be credited back deserves a trace.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( '[AdTribes UCP] Refund %d on UCP order %d not reported: the store is not connected to an AdTribes account.', (int) $refund_id, (int) $order_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
            return;
        }

        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( self::REPORT_HOOK, array( (int) $order_id, (int) $refund_id, 1 ), 'adt_ucp' );
        } else {
            $this->report( (int) $order_id, (int) $refund_id, self::MAX_ATTEMPTS );
        }
    }

    /**
     * Report a refund to the server, rescheduling with backoff on failure.
     *
     * @since 13.6.0
     *
     * @param int $order_id  Order ID.
     * @param int $refund_id Refund ID.
     * @param int $attempt   Delivery attempt (1-based).
     */
    public function report( $order_id, $refund_id, $attempt = 1 ) {

        $order  = wc_get_order( $order_id );
        $refund = wc_get_order( $refund_id );

        if ( ! $order instanceof WC_Order || ! $refund instanceof \WC_Order_Refund ) {
            return;
        }

        $session_id = (string) $order->get_meta( Orders::META_SESSION_ID );

        if ( '' === $session_id ) {
            return;
        }

        // Full when the order's cumulative refunds cover its total — the
        // trigger refund may be the last of several partials.
        $full_refund = (float) $order->get_total_refunded() >= (float) $order->get_total() - 0.001;

        $result = Account_Client::instance()->report_refund(
            $session_id,
            (string) $refund_id,
            $full_refund,
            Cart_Bridge::to_minor( (float) $refund->get_amount(), $order->get_currency() )
        );

        if ( ! is_wp_error( $result ) ) {
            return;
        }

        $status = (int) ( $result->get_error_data()['status'] ?? 0 );

        // 4xx (except 429) is definitive — e.g. 404 means no verified order
        // exists for this session (a staging/mocked order); retrying cannot
        // succeed. A disconnected store is terminal too: no credentials will
        // appear between attempts. Transport errors, 429 and 5xx are worth
        // retrying.
        $retryable = ( 0 === $status || 429 === $status || $status >= 500 )
            && 'adt_ucp_not_connected' !== $result->get_error_code();

        if ( $retryable && $attempt < self::MAX_ATTEMPTS && function_exists( 'as_schedule_single_action' ) ) {
            // Exponential backoff: 1, 4, 9, 16 minutes.
            as_schedule_single_action(
                time() + $attempt * $attempt * MINUTE_IN_SECONDS,
                self::REPORT_HOOK,
                array( (int) $order_id, (int) $refund_id, $attempt + 1 ),
                'adt_ucp'
            );
            return;
        }

        // Dropped for good — leave a trace so "my fee was never credited
        // back" is researchable.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( '[AdTribes UCP] Refund %d on order %d permanently failed to report after attempt %d: %s', (int) $refund_id, (int) $order_id, (int) $attempt, $result->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    /**
     * Run the class.
     *
     * @since 13.6.0
     * @access public
     */
    public function run() {

        add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 10, 2 );
        add_action( self::REPORT_HOOK, array( $this, 'report' ), 10, 3 );
    }
}
