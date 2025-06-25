<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Traits\Singleton_Trait;
use AdTribes\PFP\Factories\Product_Feed;

/**
 * Orders class.
 *
 * @since 13.4.5
 */
class Orders extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Get orders for given time period used in filters.
     *
     * @since 13.4.5
     * @access public
     *
     * @param Product_Feed $feed The product feed object.
     * @return array
     */
    public static function get_orders( $feed ) {
        global $wpdb;

        $allowed_products              = array();
        $total_product_orders_lookback = $feed->utm_total_product_orders_lookback ?? 0;
        if ( $total_product_orders_lookback > 0 ) {
            /**
             * Filter the today date.
             *
             * @since 13.4.5
             * @access public
             *
             * @param string $today The today date.
             * @param Product_Feed $feed The product feed object.
             */
            $today = apply_filters( 'adt_total_product_orders_lookback_today', gmdate( 'Y-m-d' ), $feed );

            /**
             * Filter the today limit date.
             *
             * @since 13.4.5
             * @access public
             *
             * @param string $today_limit The today limit date.
             * @param Product_Feed $feed The product feed object.
             */
            $today_limit = apply_filters( 'adt_total_product_orders_lookback_today_limit', gmdate( 'Y-m-d', strtotime( '-' . $total_product_orders_lookback . ' days', strtotime( $today ) ) ), $feed );

            // Check if HPOS is enabled.
            $is_hpos_enabled = false;
            if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
                $is_hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
            }

            if ( $is_hpos_enabled ) {
                // HPOS (High-Performance Order Storage) - use orders table.
                $query = $wpdb->prepare(
                    "SELECT DISTINCT oim.meta_value as product_id
                    FROM {$wpdb->prefix}woocommerce_order_items oi
                    INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
                    INNER JOIN {$wpdb->prefix}wc_orders o ON oi.order_id = o.id
                    WHERE o.status NOT IN ('wc-trash', 'wc-draft')
                    AND o.date_created_gmt >= %s
                    AND oim.meta_key IN ('_product_id', '_variation_id')
                    AND oim.meta_value > 0",
                    $today_limit
                );
            } else {
                // Traditional storage - use posts table.
                $query = $wpdb->prepare(
                    "SELECT DISTINCT oim.meta_value as product_id
                    FROM {$wpdb->prefix}woocommerce_order_items oi
                    INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
                    INNER JOIN {$wpdb->prefix}posts p ON oi.order_id = p.ID
                    WHERE p.post_type = 'shop_order'
                    AND p.post_status NOT IN ('trash', 'draft', 'auto-draft')
                    AND p.post_date >= %s
                    AND oim.meta_key IN ('_product_id', '_variation_id')
                    AND oim.meta_value > 0",
                    $today_limit
                );
            }

            // Execute query with error handling.
            $results = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB

            if ( $wpdb->last_error ) {
                // Log the error for debugging.
                $logging = get_option( 'add_woosea_logging', 'no' );
                if ( 'yes' === $logging ) {
                    // Fallback to WooCommerce API if database query fails.
                    $logger = new \WC_Logger();
                    $logger->add( 'Product Feed Pro by AdTribes.io', 'Database query failed, falling back to API method. Error: ' . $wpdb->last_error, 'error' );
                }
            }

            if ( ! empty( $results ) ) {
                $allowed_products = array_map( 'intval', $results );
            }
        }

        /**
         * Filter the allowed products from orders.
         *
         * @since 13.4.5
         * @access public
         *
         * @param array $allowed_products The allowed products.
         * @param Product_Feed $feed The product feed object.
         */
        return apply_filters( 'adt_total_product_orders_lookback_allowed_products', $allowed_products, $feed );
    }

    /**
     * Run the class.
     *
     * @since 13.3.4
     */
    public function run() {}
}
