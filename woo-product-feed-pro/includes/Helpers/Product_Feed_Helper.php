<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Helpers
 */

namespace AdTribes\PFP\Helpers;

use AdTribes\PFP\Factories\Product_Feed;

/**
 * Helper methods class.
 *
 * @since 13.3.5
 */
class Product_Feed_Helper {

    /**
     * Check if object is a Product_Feed.
     *
     * This method is used to check if the object is a product feed.
     *
     * @since 13.3.5
     * @access public
     *
     * @param mixed $feed The feed object.
     * @return bool
     */
    public static function is_a_product_feed( $feed ) {
        return ( is_a( $feed, 'AdTribes\PFP\Factories\Product_Feed' ) || is_a( $feed, 'AdTribes\PFE\Factories\Product_Feed' ) );
    }

    /**
     * Product feed instance.
     *
     * @since 13.3.6
     * @access public
     *
     * @param int|string|WP_Post $feed    Feed ID, project hash (legacy) or WP_Post object.
     * @param string             $context The context of the product feed.
     * @return Product_Feed
     */
    public static function get_product_feed( $feed = 0, $context = 'view' ) {
        if ( class_exists( 'AdTribes\PFE\Factories\Product_Feed' ) ) {
            return new \AdTribes\PFE\Factories\Product_Feed( $feed, $context );
        } else {
            return new Product_Feed( $feed, $context );
        }
    }

    /**
     * Get country code from legacy country name.
     *
     * This method is used to get the country code from the legacy country name.
     * We used to store the country name in the codebase, but now use the country code available in WooCommerce.
     *
     * @since 13.3.5
     * @access public
     *
     * @param string $country_name The name of the country.
     * @return string
     */
    public static function get_code_from_legacy_country_name( $country_name ) {
        $legacy_countries = include WOOCOMMERCESEA_PATH . 'includes/I18n/legacy_countries.php';
        return array_search( $country_name, $legacy_countries ); // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict
    }

    /**
     * Get legacy country name from country code.
     *
     * This method is used to get the legacy country name from the country code.
     * We used to store the country name in the codebase, but now use the country code available in WooCommerce.
     *
     * @since 13.3.5
     * @access public
     *
     * @param string $country_code The code of the country.
     * @return string
     */
    public static function get_legacy_country_from_code( $country_code ) {
        $legacy_countries = include WOOCOMMERCESEA_PATH . 'includes/I18n/legacy_countries.php';
        return $legacy_countries[ $country_code ] ?? '';
    }

    /**
     * Get channel data from legacy channel hash.
     *
     * This method is used to get the channel data from the legacy channel hash.
     *
     * @since 13.3.5
     * @access public
     *
     * @param string $channel_hash The hash of the channel.
     * @return array|null
     */
    public static function get_channel_from_legacy_channel_hash( $channel_hash ) {
        $legacy_channel_statics = include WOOCOMMERCESEA_PATH . 'includes/I18n/legacy_channel_statics.php';

        // Search for the channel hash in the legacy channel statics.
        foreach ( $legacy_channel_statics as $country ) {
            foreach ( $country as $channel ) {
                if ( $channel['channel_hash'] === $channel_hash ) {
                    return $channel;
                }
            }
        }
        return null;
    }

    /**
     * Generate legacy project hash.
     *
     * Copied from legacy code. This method is used to generate the legacy project hash.
     * We keep this method to maintain backward compatibility.
     *
     * @since 13.3.5
     * @access public
     *
     * @return string
     */
    public static function generate_legacy_project_hash() {
        // New code to create the project hash so dependency on openSSL is removed.
        $keyspace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $pieces   = array();
        $length   = 32;
        $max      = mb_strlen( $keyspace, '8bit' ) - 1;

        for ( $i = 0; $i < $length; ++$i ) {
            $pieces [] = $keyspace[ random_int( 0, $max ) ];
        }

        return implode( '', $pieces );
    }

    /**
     * Count total product feed projects.
     *
     * @since 13.3.5
     * @access public
     *
     * @return int
     */
    public static function get_total_product_feed() {
        $count_post = wp_count_posts( Product_Feed::POST_TYPE );
        return $count_post->publish + $count_post->draft;
    }

    /**
     * Count total published product including variations.
     *
     * @since 13.3.5
     * @access public
     *
     * @param bool $incl_variation Include variations.
     * @return int
     */
    public static function get_total_published_products( $incl_variation = false ) {
        $count_product = wp_count_posts( 'product' );
        if ( ! $incl_variation ) {
            return $count_product->publish;
        }

        $count_product_variation = wp_count_posts( 'product_variation' );
        return $count_product->publish + $count_product_variation->publish;
    }

    /**
     * Remove cache.
     *
     * The method is used to remove the cache for the feed processing.
     * This is to ensure that the feed is not cached by the caching plugins.
     * This is the legacy code base logic.
     *
     * @since 13.3.5
     * @access public
     */
    public static function disable_cache() {
        // Force garbage collection dump.
        gc_enable();
        gc_collect_cycles();

        // Make sure feeds are not being cached.
        $no_caching = new \WooSEA_Caching();

        // LiteSpeed Caching.
        if ( class_exists( 'LiteSpeed\Core' ) || defined( 'LSCWP_DIR' ) ) {
            $no_caching->litespeed_cache();
        }

        // WP Fastest Caching.
        if ( class_exists( 'WpFastestCache' ) ) {
            $no_caching->wp_fastest_cache();
        }

        // WP Super Caching.
        if ( function_exists( 'wpsc_init' ) ) {
            $no_caching->wp_super_cache();
        }

        // Breeze Caching.
        if ( class_exists( 'Breeze_Admin' ) ) {
            $no_caching->breeze_cache();
        }

        // WP Optimize Caching.
        if ( class_exists( 'WP_Optimize' ) ) {
            $no_caching->wp_optimize_cache();
        }

        // Cache Enabler.
        if ( class_exists( 'Cache_Enabler' ) ) {
            $no_caching->cache_enabler_cache();
        }

        // Swift Performance Lite.
        if ( class_exists( 'Swift_Performance_Lite' ) ) {
            $no_caching->swift_performance_cache();
        }

        // Comet Cache.
        if ( is_plugin_active( 'comet-cache/comet-cache.php' ) ) {
            $no_caching->comet_cache();
        }

        // HyperCache.
        if ( class_exists( 'HyperCache' ) ) {
            $no_caching->hyper_cache();
        }
    }

    /**
     * Check if the user is allowed to manage product feed.
     *
     * @since 13.3.5
     * @access private
     *
     * @return bool
     */
    public static function is_current_user_allowed() {
        $user          = wp_get_current_user();
        $allowed_roles = apply_filters( 'adt_manage_product_feed_allowed_roles', array( 'administrator' ) );

        if ( array_intersect( $allowed_roles, $user->roles ) ) {
            return true;
        }

        return false;
    }
}
