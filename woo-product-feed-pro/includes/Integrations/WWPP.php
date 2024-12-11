<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Integrations
 */

namespace AdTribes\PFP\Integrations;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Helpers\Helper;
use AdTribes\PFP\Helpers\Product_Feed_Helper;

/**
 * WWPP class.
 *
 * @since 13.3.4
 */
class WWPP extends Abstract_Class {

    /**
     * Check if WWP plugin is active.
     *
     * @since 13.3.4
     * @return bool
     */
    public function is_active() {
        return Helper::is_plugin_active( 'woocommerce-wholesale-prices-premium/woocommerce-wholesale-prices-premium.bootstrap.php' );
    }

    /**
     * Query wholesale exclusive products.
     *
     * @since 13.3.4
     * @access public
     *
     * @param object $feed The product feed.
     */
    public function query_wholesale_exclusive_products( $feed ) {
        // Check the Only Show Wholesale Products To Wholesale Customers is enabled.
        // If enabled, exclude wholesale products from the feed.
        if ( get_option( 'wwpp_settings_only_show_wholesale_products_to_wholesale_users', false ) === 'yes'
            && ! get_transient( 'adt_pfp_wwpp_wholesale_exclusive_products_' . $feed->id )
        ) {
            global $wpdb;

            $wholesale_exclusive_products = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT 
                        post_id
                    FROM
                        $wpdb->postmeta
                    WHERE 
                        (meta_key LIKE %s AND meta_value != '')
                        OR (meta_key LIKE %s AND meta_value != '')
                    ",
                    '%_wholesale_price',
                    '%_have_wholesale_price',
                )
            );

            // Map the product ids to absolute integers.
            $wholesale_exclusive_products = array_map( 'absint', $wholesale_exclusive_products );

            // Set result ids to transients.
            set_transient( 'adt_pfp_wwpp_wholesale_exclusive_products_' . $feed->id, $wholesale_exclusive_products );
        }
    }

    /**
     * Clear WWPP transient.
     *
     * @since 13.3.4
     * @access public
     *
     * @param int $feed_id The product feed ID.
     */
    public function clear_wwpp_transient( $feed_id ) {
        $feed = Product_Feed_Helper::get_product_feed( $feed_id );
        if ( ! Product_Feed_Helper::is_a_product_feed( $feed ) && ! $feed->id ) {
            return false;
        }

        if ( 'ready' === $feed->status ) {
            delete_transient( 'adt_pfp_wwpp_wholesale_exclusive_products_' . $feed_id );
        }
    }

    /**
     * Exclude wholesale products from product feeds.
     *
     * @since 13.3.4
     * @access public
     *
     * @param array  $product_data The product data.
     * @param object $feed         The product feed.
     * @param object $product      The product data.
     * @return array
     */
    public function exclude_wholesale_products( $product_data, $feed, $product ) {
        // Check the Only Show Wholesale Products To Wholesale Customers is enabled.
        // If enabled, exclude wholesale products from the feed.
        if ( ! empty( $product_data )
            && get_option( 'wwpp_settings_only_show_wholesale_products_to_wholesale_users', false ) === 'yes'
        ) {
            $wholesale_exclusive_products = get_transient( 'adt_pfp_wwpp_wholesale_exclusive_products_' . $feed->id );
            if ( ! empty( $wholesale_exclusive_products ) && in_array( $product->get_id(), $wholesale_exclusive_products, true ) ) {
                $product_data = array();
            }
        }

        // Check if product is restricted to wholesale customers.
        if ( ! empty( $product_data ) ) {
            $wwpp_product_wholesale_visibility_filter = $product->get_meta( 'wwpp_product_wholesale_visibility_filter', false );
            if ( is_array( $wwpp_product_wholesale_visibility_filter ) && ! empty( $wwpp_product_wholesale_visibility_filter ) ) {
                $wwpp_product_wholesale_visibility_filter = wp_list_pluck( $wwpp_product_wholesale_visibility_filter, 'value' );
                if ( ! in_array( 'all', $wwpp_product_wholesale_visibility_filter, true ) ) {
                    $product_data = array();
                }
            }
        }

        // Check if product is restricted in category.
        if ( ! empty( $product_data ) && class_exists( 'WWPP_Helper_Functions' ) ) {
            $product_is_restricted_in_category = \WWPP_Helper_Functions::is_product_restricted_in_category( $product->get_id(), array() );
            if ( $product_is_restricted_in_category ) {
                $product_data = array();
            }
        }

        return $product_data;
    }

    /**
     * Run WWP integration hooks.
     *
     * @since 13.3.4
     */
    public function run() {
        if ( ! $this->is_active() ) {
            return;
        }

        add_action( 'woosea_before_get_products', array( $this, 'query_wholesale_exclusive_products' ) );
        add_action( 'adt_after_product_feed_generation', array( $this, 'clear_wwpp_transient' ), 20, 1 );

        add_filter( 'adt_get_product_data', array( $this, 'exclude_wholesale_products' ), 10, 3 );
    }
}
