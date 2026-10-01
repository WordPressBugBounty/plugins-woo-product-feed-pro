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
 * UCP order-lifecycle webhooks to Google.
 *
 * Status changes on UCP-originated orders are pushed to Google's
 * shopping-data-integration webhook endpoint via Action Scheduler with
 * retry/backoff. Requests carry Standard-Webhooks headers, signed with
 * the store's UCP signing key. Payload members are filterable.
 *
 * KNOWN DISCREPANCY — the signature is not Standard-Webhooks conformant.
 * `Webhook-Signature` is emitted as `v1a,<compact detached JWS>`, which
 * borrows the Standard-Webhooks asymmetric marker but does not match it
 * on either count:
 *
 * 1. `Keys::sign()` signs `base64url(header).base64url(payload)`, not the
 *    raw `{id}.{timestamp}.{body}` bytes a Standard-Webhooks receiver
 *    recomputes over.
 * 2. Standard Webhooks specifies Ed25519 for `v1a`; this store signs with
 *    EC P-256 / ES256.
 *
 * A strictly conformant receiver would therefore reject every signed
 * delivery. This is left as-is deliberately: the wire format still has to
 * be ratified against Google's UCP orders guide during onboarding, and
 * agentic checkout (the only thing that emits these) ships behind
 * "coming soon". Resolve it before checkout goes live — either sign the
 * raw `{id}.{timestamp}.{body}` bytes to match Standard Webhooks, or keep
 * the detached JWS and stop reusing the `v1a,` marker for it.
 *
 * @since 13.6.0
 */
class Webhooks extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Action Scheduler hook for dispatching a webhook.
     *
     * @since 13.6.0
     * @var string
     */
    const DISPATCH_HOOK = 'adt_ucp_dispatch_order_webhook';

    /**
     * Maximum delivery attempts.
     *
     * @since 13.6.0
     * @var int
     */
    const MAX_ATTEMPTS = 5;

    /**
     * Map WooCommerce order statuses to UCP order statuses.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $wc_status WooCommerce status (without `wc-` prefix).
     * @return string UCP order status.
     */
    public static function map_status( $wc_status ) {

        $map = array(
            'pending'    => 'pending',
            'on-hold'    => 'pending',
            'processing' => 'confirmed',
            'completed'  => 'completed',
            'cancelled'  => 'canceled',
            'refunded'   => 'refunded',
            'failed'     => 'canceled',
        );

        /**
         * Filter the WooCommerce → UCP order status map.
         *
         * @since 13.6.0
         *
         * @param array $map Status map.
         */
        $map = (array) apply_filters( 'adt_ucp_order_status_map', $map );

        return $map[ $wc_status ] ?? $wc_status;
    }

    /**
     * Get the configured Google webhook endpoint URL.
     *
     * @since 13.6.0
     * @access public
     *
     * @return string URL, empty string when not configured.
     */
    public function get_endpoint_url() {

        $settings   = Settings::instance();
        $partner_id = (string) $settings->get( 'google_partner_id', '' );
        $api_key    = (string) $settings->get( 'google_webhook_key', '' );

        if ( '' === $partner_id || '' === $api_key ) {
            return '';
        }

        $url = sprintf(
            'https://shoppingdataintegration.googleapis.com/v1/webhooks/partners/%s/events/order?key=%s',
            rawurlencode( $partner_id ),
            rawurlencode( $api_key )
        );

        /**
         * Filter the Google order-webhook endpoint URL.
         *
         * @since 13.6.0
         *
         * @param string $url Endpoint URL.
         */
        return (string) apply_filters( 'adt_ucp_webhook_endpoint', $url );
    }

    /**
     * Queue a webhook when a UCP order changes status.
     *
     * @since 13.6.0
     *
     * @param int    $order_id Order ID.
     * @param string $from     Previous status.
     * @param string $to       New status.
     */
    public function on_order_status_changed( $order_id, $from, $to ) {

        if ( ! function_exists( 'as_enqueue_async_action' ) || ! UCP_Feature::instance()->is_serving() ) {
            return;
        }

        $order = wc_get_order( $order_id );

        if ( ! $order instanceof WC_Order || ! Orders::is_ucp_order( $order ) ) {
            return;
        }

        if ( '' === $this->get_endpoint_url() ) {
            return;
        }

        // Mint the Webhook-Id once, at enqueue time, and carry it through every
        // retry. Standard-Webhooks receivers dedupe on Webhook-Id, so a fresh
        // ID per attempt would make Google process a retried delivery as a
        // distinct event.
        $webhook_id = 'whk_' . bin2hex( random_bytes( 12 ) );

        as_enqueue_async_action( self::DISPATCH_HOOK, array( $order_id, $to, 1, $webhook_id ), 'adt_ucp' );
    }

    /**
     * Dispatch a queued webhook, rescheduling with backoff on failure.
     *
     * @since 13.6.0
     *
     * @param int    $order_id   Order ID.
     * @param string $status     The WooCommerce status that triggered the event.
     * @param int    $attempt    Delivery attempt (1-based).
     * @param string $webhook_id Stable Webhook-Id, carried across retries.
     */
    public function dispatch( $order_id, $status, $attempt = 1, $webhook_id = '' ) {

        $endpoint = $this->get_endpoint_url();
        $order    = wc_get_order( $order_id );

        if ( '' === $endpoint || ! $order instanceof WC_Order ) {
            return;
        }

        // Fall back to a fresh ID only for actions enqueued before the stable
        // ID was threaded through (in-flight upgrades).
        if ( '' === $webhook_id ) {
            $webhook_id = 'whk_' . bin2hex( random_bytes( 12 ) );
        }

        $payload = array(
            'type'  => 'order.updated',
            'order' => array(
                'id'            => (string) $order->get_id(),
                'checkout_id'   => (string) $order->get_meta( Orders::META_SESSION_ID ),
                'status'        => self::map_status( $status ),
                'currency'      => $order->get_currency(),
                'total'         => Cart_Bridge::to_minor( (float) $order->get_total(), $order->get_currency() ),
                'permalink_url' => $order->get_checkout_order_received_url(),
                'updated_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
            ),
        );

        /**
         * Filter the order webhook payload.
         *
         * @since 13.6.0
         *
         * @param array    $payload Payload.
         * @param WC_Order $order   Order.
         * @param string   $status  Triggering WooCommerce status.
         */
        $payload = (array) apply_filters( 'adt_ucp_order_webhook_payload', $payload, $order, $status );

        $body      = (string) wp_json_encode( $payload );
        $timestamp = (string) time();

        $headers = array(
            'Content-Type'      => 'application/json',
            'Webhook-Id'        => $webhook_id,
            'Webhook-Timestamp' => $timestamp,
        );

        try {
            $keys = Keys::instance();
            if ( $keys->has_keys() ) {
                // Detached JWS over `{id}.{timestamp}.{payload}` behind the
                // Standard-Webhooks asymmetric marker. The two do not agree on
                // the signed bytes or the algorithm — see the class docblock,
                // which records why that is deliberate for now and what has to
                // change before agentic checkout goes live.
                $headers['Webhook-Signature'] = 'v1a,' . $keys->sign( $webhook_id . '.' . $timestamp . '.' . $body );
            }
        } catch ( \Exception $e ) {
            // Unsigned delivery is better than none, but a persistent signing
            // fault (missing/corrupt key) must be observable rather than
            // silently shipping every event unsigned.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( '[AdTribes UCP] Webhook signing failed for order %d: %s', (int) $order_id, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }

        $response = wp_remote_post(
            $endpoint,
            array(
                'timeout' => 10,
                'headers' => $headers,
                'body'    => $body,
            )
        );

        $code   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
        $failed = $code < 200 || $code >= 300;

        // A webhook that exhausts its retries is dropped for good — leave a
        // trace so "Google never got my order update" is researchable.
        if ( $failed && ( $attempt >= self::MAX_ATTEMPTS || ! function_exists( 'as_schedule_single_action' ) ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $detail = is_wp_error( $response ) ? $response->get_error_message() : sprintf( 'HTTP %d', $code );
            error_log( sprintf( '[AdTribes UCP] Webhook %s for order %d permanently failed after attempt %d: %s', $webhook_id, (int) $order_id, $attempt, $detail ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }

        if ( $failed && $attempt < self::MAX_ATTEMPTS && function_exists( 'as_schedule_single_action' ) ) {
            // Exponential backoff: 1, 4, 9, 16 minutes.
            as_schedule_single_action(
                time() + $attempt * $attempt * MINUTE_IN_SECONDS,
                self::DISPATCH_HOOK,
                array( $order_id, $status, $attempt + 1, $webhook_id ),
                'adt_ucp'
            );
        }
    }

    /**
     * Run the class.
     *
     * @since 13.6.0
     * @access public
     */
    public function run() {

        add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 3 );
        add_action( self::DISPATCH_HOOK, array( $this, 'dispatch' ), 10, 4 );
    }
}
