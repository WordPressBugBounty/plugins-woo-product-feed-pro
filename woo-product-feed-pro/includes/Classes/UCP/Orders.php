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
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * UCP order creation and attribution.
 *
 * Completed agentic checkouts become native WooCommerce orders — guest
 * orders keyed by the buyer's email — tagged with UCP meta for
 * attribution, the orders-list badge, and webhook routing. Meta is
 * written through WC CRUD for HPOS safety.
 *
 * @since 13.6.0
 */
class Orders extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Order meta: marks an order as UCP-originated.
     *
     * @since 13.6.0
     * @var string
     */
    const META_UCP_ORDER = '_adt_ucp_order';

    /**
     * Order meta: originating checkout-session ID.
     *
     * @since 13.6.0
     * @var string
     */
    const META_SESSION_ID = '_adt_ucp_session_id';

    /**
     * Order meta: the agent (UCP-Agent header) that placed the order.
     *
     * Attribution only — `UCP-Agent` is client-asserted and non-secret, so
     * it must never be used to authorize access to the order.
     *
     * @since 13.6.0
     * @var string
     */
    const META_AGENT = '_adt_ucp_agent';

    /**
     * Order meta: the server's signed (JWS) mandate-verification receipt.
     *
     * @since 13.6.0
     * @var string
     */
    const META_VERIFY_RECEIPT = '_adt_ucp_verify_receipt';

    /**
     * Order meta: the fee the server debited at verification (JSON).
     *
     * @since 13.6.0
     * @var string
     */
    const META_VERIFY_FEE = '_adt_ucp_verify_fee';

    /**
     * Order meta: the API-key environment the order was placed with.
     *
     * Server-derived from the presented key (never client-asserted), so it
     * can scope reads the way checkout sessions are scoped to their owner
     * environment.
     *
     * @since 13.6.0
     * @var string
     */
    const META_ENVIRONMENT = '_adt_ucp_environment';

    /**
     * Create a native order from the loaded cart bridge.
     *
     * Reuses WooCommerce's own checkout order builder so line items,
     * coupons, shipping, fees and taxes transfer through the same code
     * path a storefront checkout uses.
     *
     * @since 13.6.0
     * @access public
     *
     * @param Cart_Bridge     $bridge         Loaded bridge.
     * @param WP_REST_Request $request        Request (agent attribution).
     * @param string          $payment_method WooCommerce gateway ID.
     * @return WC_Order|WP_Error Order, or error.
     */
    public function create_from_bridge( Cart_Bridge $bridge, WP_REST_Request $request, $payment_method ) {

        $customer = WC()->customer;
        $buyer    = $bridge->get_buyer();

        $data = array(
            'payment_method'      => $payment_method,
            'billing_first_name'  => $customer->get_billing_first_name(),
            'billing_last_name'   => $customer->get_billing_last_name(),
            'billing_email'       => ! empty( $buyer['email'] ) ? $buyer['email'] : $customer->get_billing_email(),
            'billing_phone'       => $customer->get_billing_phone(),
            'billing_country'     => $customer->get_billing_country(),
            'billing_state'       => $customer->get_billing_state(),
            'billing_postcode'    => $customer->get_billing_postcode(),
            'billing_city'        => $customer->get_billing_city(),
            'billing_address_1'   => $customer->get_billing_address_1(),
            'billing_address_2'   => $customer->get_billing_address_2(),
            'shipping_first_name' => $customer->get_shipping_first_name(),
            'shipping_last_name'  => $customer->get_shipping_last_name(),
            'shipping_country'    => $customer->get_shipping_country(),
            'shipping_state'      => $customer->get_shipping_state(),
            'shipping_postcode'   => $customer->get_shipping_postcode(),
            'shipping_city'       => $customer->get_shipping_city(),
            'shipping_address_1'  => $customer->get_shipping_address_1(),
            'shipping_address_2'  => $customer->get_shipping_address_2(),
            'order_comments'      => '',
        );

        $order_id = WC()->checkout()->create_order( $data );

        if ( is_wp_error( $order_id ) ) {
            return $order_id;
        }

        $order = wc_get_order( $order_id );

        if ( ! $order instanceof WC_Order ) {
            return new WP_Error( 'adt_ucp_order_failed', __( 'Order could not be loaded after creation.', 'woo-product-feed-pro' ) );
        }

        $order->update_meta_data( self::META_UCP_ORDER, 'yes' );
        $order->update_meta_data( self::META_SESSION_ID, $bridge->get_session_id() );
        $order->update_meta_data( self::META_AGENT, (string) $request->get_header( 'ucp_agent' ) );
        $order->update_meta_data( self::META_ENVIRONMENT, Auth::instance()->get_request_environment( $request ) );
        $order->set_created_via( 'ucp' );

        // UCP authenticates with an API key, so an agentic order is always a
        // guest order. Pin it explicitly: WC_Checkout::create_order() falls
        // back to get_current_user_id(), which would silently attribute the
        // order to whoever happens to be logged in (admin browser testing, a
        // future combined-auth path).
        $order->set_customer_id( 0 );

        // WooCommerce order attribution, so the orders list "Origin"
        // column reads "Referral: UCP (AdTribes)" for agentic orders.
        $order->update_meta_data( '_wc_order_attribution_source_type', 'referral' );
        $order->update_meta_data( '_wc_order_attribution_utm_source', 'UCP (AdTribes)' );

        $order->save();

        return $order;
    }

    /**
     * Find the existing (non-failed) UCP order for a checkout session.
     *
     * Order creation is idempotent per session: a retried or concurrent
     * `complete` must reuse the order the first call created rather than
     * minting a second order and charging the buyer again.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $session_id Checkout-session ID.
     * @return WC_Order|null The order, or null when none exists yet.
     */
    public function get_order_for_session( $session_id ) {

        if ( '' === (string) $session_id ) {
            return null;
        }

        $orders = wc_get_orders(
            array(
                'limit'      => 1,
                'orderby'    => 'ID',
                'order'      => 'DESC',
                'status'     => array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-failed' ) ),
                'meta_key'   => self::META_SESSION_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_value' => $session_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            )
        );

        $order = ! empty( $orders ) ? $orders[0] : null;

        return $order instanceof WC_Order ? $order : null;
    }

    /**
     * Build the UCP order_confirmation object for an order.
     *
     * @since 13.6.0
     * @access public
     *
     * @param WC_Order $order Order.
     * @return array order_confirmation per the pinned schema.
     */
    public static function confirmation( WC_Order $order ) {

        return array(
            'id'            => (string) $order->get_id(),
            'label'         => '#' . $order->get_order_number(),
            'permalink_url' => $order->get_checkout_order_received_url(),
        );
    }

    /**
     * Whether an order is UCP-originated.
     *
     * @since 13.6.0
     * @access public
     *
     * @param WC_Order $order Order.
     * @return bool True when UCP-originated.
     */
    public static function is_ucp_order( WC_Order $order ) {

        return 'yes' === $order->get_meta( self::META_UCP_ORDER );
    }

    /**
     * URL of the WooCommerce orders list filtered to UCP orders.
     *
     * There is no separate UCP orders screen — agentic orders are native
     * WooCommerce orders, so the admin app links here instead.
     *
     * @since 13.6.0
     * @access public
     *
     * @return string Admin URL (HPOS or legacy orders screen).
     */
    public static function get_orders_list_url() {

        if (
            class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
        ) {
            return admin_url( 'admin.php?page=wc-orders&adt_ucp_orders=1' );
        }

        return admin_url( 'edit.php?post_type=shop_order&adt_ucp_orders=1' );
    }

    /**
     * Whether the current request asks for the UCP-only orders view.
     *
     * @since 13.6.0
     * @access private
     *
     * @return bool True when the adt_ucp_orders query flag is set.
     */
    private function is_ucp_orders_view() {

        return ! empty( $_GET['adt_ucp_orders'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
    }

    /**
     * Filter the HPOS orders list to UCP orders when requested.
     *
     * @since 13.6.0
     *
     * @param array $args wc_get_orders() args for the list table.
     * @return array Args.
     */
    public function filter_orders_list_query_args( $args ) {

        if ( $this->is_ucp_orders_view() ) {
            $args['created_via'] = 'ucp';
        }

        return $args;
    }

    /**
     * Filter the legacy (posts) orders list to UCP orders when requested.
     *
     * @since 13.6.0
     *
     * @param \WP_Query $query Main query.
     */
    public function filter_legacy_orders_list_query( $query ) {

        if (
            ! is_admin()
            || ! $query->is_main_query()
            || 'shop_order' !== $query->get( 'post_type' )
            || ! $this->is_ucp_orders_view()
        ) {
            return;
        }

        $meta_query   = (array) $query->get( 'meta_query' );
        $meta_query[] = array(
            'key'   => '_created_via',
            'value' => 'ucp',
        );

        $query->set( 'meta_query', $meta_query );
    }

    /**
     * Add the UCP column to the orders list (legacy and HPOS screens).
     *
     * @since 13.6.0
     *
     * @param array $columns Columns.
     * @return array Columns.
     */
    public function add_orders_list_column( $columns ) {

        $columns['adt_ucp'] = __( 'UCP', 'woo-product-feed-pro' );

        return $columns;
    }

    /**
     * Render the UCP column for the HPOS orders screen.
     *
     * @since 13.6.0
     *
     * @param string          $column Column key.
     * @param WC_Order|object $order  Order object.
     */
    public function render_orders_list_column( $column, $order ) {

        if ( 'adt_ucp' !== $column ) {
            return;
        }

        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );

        if ( $order && self::is_ucp_order( $order ) ) {
            echo '<span class="adt-ucp-order-badge" title="' . esc_attr__( 'Placed via agentic checkout (UCP)', 'woo-product-feed-pro' ) . '">UCP</span>';
        }
    }

    /**
     * Render the UCP column for the legacy orders screen.
     *
     * @since 13.6.0
     *
     * @param string $column  Column key.
     * @param int    $post_id Order post ID.
     */
    public function render_legacy_orders_list_column( $column, $post_id ) {

        $this->render_orders_list_column( $column, wc_get_order( $post_id ) );
    }

    /**
     * Run the class.
     *
     * @since 13.6.0
     * @access public
     */
    public function run() {

        // HPOS orders screen.
        add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_orders_list_column' ) );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_orders_list_column' ), 10, 2 );
        add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'filter_orders_list_query_args' ) );

        // Legacy posts orders screen.
        add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_orders_list_column' ) );
        add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy_orders_list_column' ), 10, 2 );
        add_action( 'pre_get_posts', array( $this, 'filter_legacy_orders_list_query' ) );
    }
}
