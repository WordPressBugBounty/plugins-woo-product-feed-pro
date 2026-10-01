<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\REST\UCP
 */

namespace AdTribes\PFP\REST\UCP;

use AdTribes\PFP\Abstracts\Abstract_REST;
use AdTribes\PFP\Classes\UCP\Auth;
use AdTribes\PFP\Classes\UCP\Cart_Bridge;
use AdTribes\PFP\Classes\UCP\Envelope;
use AdTribes\PFP\Classes\UCP\Orders as UCP_Orders;
use AdTribes\PFP\Classes\UCP\Profile;
use AdTribes\PFP\Classes\UCP\UCP_Feature;
use AdTribes\PFP\Classes\UCP\Version_Negotiator;
use AdTribes\PFP\Classes\UCP\Webhooks;
use AdTribes\PFP\Traits\Singleton_Trait;
use WP_Error;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * UCP order pull-fallback endpoint (GET /orders/{id}).
 *
 * Webhooks are the primary order-lifecycle channel; this endpoint lets
 * agents re-fetch order state when a webhook is missed. Only
 * UCP-originated orders are retrievable. Payload members to be ratified
 * against the pinned order schema during onboarding — filterable.
 *
 * @since 13.6.0
 */
class Orders extends Abstract_REST {

    use Singleton_Trait;

    /**
     * Register plugin routes with the UCP namespace.
     *
     * @since 13.6.0
     * @return void
     */
    public function register_plugin_routes() {

        $this->namespace = Profile::REST_NAMESPACE;
        $this->rest_base = 'orders';

        parent::register_plugin_routes();
    }

    /**
     * Register the order routes.
     *
     * @since 13.6.0
     * @return void
     */
    public function register_routes() {

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[0-9]+)',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'get_item' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                ),
            )
        );
    }

    /**
     * Permission check: serving gate + API-key auth.
     *
     * @since 13.6.0
     *
     * @param \WP_REST_Request $request Request.
     * @return true|WP_Error True when authorized.
     */
    public function permissions_check( $request ) {

        if ( ! UCP_Feature::instance()->is_serving() ) {
            return new WP_Error( 'not_found', __( 'Not found.', 'woo-product-feed-pro' ), array( 'status' => 404 ) );
        }

        return Auth::instance()->authenticate_request( $request );
    }

    /**
     * GET /orders/{id} — fetch a UCP order.
     *
     * @since 13.6.0
     *
     * @param \WP_REST_Request $request Request.
     * @return WP_REST_Response Response.
     */
    public function get_item( $request ) {

        $version_check = Version_Negotiator::validate_request( $request );
        if ( is_wp_error( $version_check ) ) {
            $data = (array) $version_check->get_error_data();

            return new WP_REST_Response(
                array(
                    'code'    => $version_check->get_error_code(),
                    'content' => $version_check->get_error_message(),
                ),
                isset( $data['status'] ) ? (int) $data['status'] : 400
            );
        }

        $order = wc_get_order( absint( $request['id'] ) );

        if ( ! $order instanceof \WC_Order || ! UCP_Orders::is_ucp_order( $order ) ) {
            return new WP_REST_Response(
                array(
                    'code'    => 'not_found',
                    'content' => __( 'Order not found.', 'woo-product-feed-pro' ),
                ),
                404
            );
        }

        // Bind the order to the API-key environment that placed it, exactly
        // as checkout sessions are bound to their owner environment. Order
        // IDs are sequential, so a read must be scoped by something the
        // caller cannot assert — never the client-supplied `UCP-Agent`
        // header, which any key holder can spoof to enumerate `/orders/{n}`.
        // Fail closed on an order with no recorded environment: UCP orders
        // have carried this meta since the feature shipped, so an absent
        // value means the order did not come from an authenticated UCP
        // request. Respond 404 (not 403) so a mismatch does not confirm the
        // ID exists.
        $order_environment   = (string) $order->get_meta( UCP_Orders::META_ENVIRONMENT );
        $request_environment = Auth::instance()->get_request_environment( $request );
        if ( '' === $order_environment || $order_environment !== $request_environment ) {
            return new WP_REST_Response(
                array(
                    'code'    => 'not_found',
                    'content' => __( 'Order not found.', 'woo-product-feed-pro' ),
                ),
                404
            );
        }

        $line_items = array();
        foreach ( $order->get_items() as $item_id => $item ) {
            $line_items[] = array(
                'id'       => (string) $item_id,
                'item'     => array(
                    'id'    => (string) ( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() ),
                    'title' => $item->get_name(),
                    'price' => Cart_Bridge::to_minor( (float) $order->get_item_subtotal( $item, false ), $order->get_currency() ),
                ),
                'quantity' => (int) $item->get_quantity(),
            );
        }

        // Note: the order-received URL (get_checkout_order_received_url())
        // embeds the order key and grants full PII access to anyone holding
        // it, so it is deliberately NOT returned from this machine endpoint.
        $body = array(
            'ucp'         => Envelope::build( 'success', Version_Negotiator::get_negotiated_version( $request ) ),
            'id'          => (string) $order->get_id(),
            'checkout_id' => (string) $order->get_meta( UCP_Orders::META_SESSION_ID ),
            'status'      => Webhooks::map_status( $order->get_status() ),
            'currency'    => $order->get_currency(),
            'total'       => Cart_Bridge::to_minor( (float) $order->get_total(), $order->get_currency() ),
            'line_items'  => $line_items,
        );

        /**
         * Filter the UCP order payload.
         *
         * @since 13.6.0
         *
         * @param array     $body  Payload.
         * @param \WC_Order $order Order.
         */
        $body = (array) apply_filters( 'adt_ucp_order_response', $body, $order );

        return new WP_REST_Response( $body, 200 );
    }
}
