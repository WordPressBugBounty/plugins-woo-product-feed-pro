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
     * Get total published products.
     *
     * @since 13.3.5
     * @access private
     *
     * @param Product_Feed $feed The product feed instance.
     * @return int
     */
    public static function get_feed_total_published_products( $feed ) {
        // Get total of published products to process.
        if ( $feed->create_preview ) {
            // User would like to see a preview of their feed, retrieve only 5 products by default.
            $published_products = apply_filters( 'adt_product_feed_preview_products', 5, $feed );
        } else {
            $published_products = self::get_total_published_products( $feed->include_product_variations );
        }

        /**
         * Filter the total number of products to process.
         *
         * @since 13.3.5
         *
         * @param int $published_products Total number of published products to process.
         * @param \AdTribes\PFP\Factories\Product_Feed $feed The product feed instance.
         */
        return apply_filters( 'adt_product_feed_total_published_products', intval( $published_products ), $feed );
    }

    /**
     * Get batch size.
     *
     * @since 13.4.1
     * @access public
     *
     * @param Product_Feed $feed The product feed instance.
     * @param int          $published_products The total number of published products.
     * @return int
     */
    public static function get_batch_size( $feed, $published_products = null ) {
        $published_products = $published_products ?? self::get_feed_total_published_products( $feed );

        // By default process a 750 products per batch.
        // If the number of products is greater than 50000, process a 2500 products per batch.
        $batch_size = $published_products > 50000 ? 2500 : 750;

        /**
         * User set his own batch size
         */
        $batch_option      = get_option( 'add_batch', 'no' );
        $batch_size_option = get_option( 'woosea_batch_size', '' );
        if ( 'yes' === $batch_option && ! empty( $batch_size_option ) && is_numeric( $batch_size_option ) ) {
            $batch_size = intval( $batch_size_option );
        }

        return $batch_size;
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
     * Get refresh interval label.
     *
     * This method is used to get the refresh interval label.
     *
     * @since 13.3.5
     * @access public
     *
     * @param string $key The key of the refresh interval.
     * @return string
     */
    public static function get_refresh_interval_label( $key ) {
        $refresh_intervals = array(
            'hourly'     => __( 'Hourly', 'woo-product-feed-pro' ),
            'twicedaily' => __( 'Twice Daily', 'woo-product-feed-pro' ),
            'daily'      => __( 'Daily', 'woo-product-feed-pro' ),
        );

        return $refresh_intervals[ $key ] ?? __( 'No Refresh', 'woo-product-feed-pro' );
    }

    /**
     * Get hierarchical categories mapping.
     *
     * @since 13.4.0
     * @access public
     *
     * @param object $feed The feed object.
     * @return array
     */
    public static function get_hierarchical_categories_mapping( $feed = null ) {
        $feed_mappings       = array();
        $mapped_category_ids = array();

        /**
         * Filters the arguments for hierarchical categories mapping.
         *
         * @since 13.4.0
         * @param array $args The arguments for hierarchical categories mapping.
         * @return array
         */
        $parent_terms_args = apply_filters(
            'adt_product_feed_hierarchical_categories_mapping_args',
            array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'parent'     => 0, // Get only parent terms.
                'orderby'    => 'name',
                'order'      => 'ASC',
            )
        );

        /**
         * Filters the categories for hierarchical categories mapping.
         *
         * @since 13.4.0
         * @param array $parent_terms The parent terms.
         * @param array $args         The arguments for hierarchical categories mapping.
         * @param int   $feed_ud      The feed id.
         * @return array
         */
        $parent_terms = apply_filters(
            'adt_product_feed_hierarchical_categories_mapping',
            get_terms( $parent_terms_args ),
            $parent_terms_args,
            $feed->id ?? 0
        );

        // Get already mapped categories.
        if ( null !== $feed ) {
            $feed_mappings = $feed->mappings ?? array();

            // Get category IDs that are already mapped.
            if ( ! empty( $feed_mappings ) ) {
                $mapped_category_ids = array_map(
                    function ( $mapping ) {
                        return $mapping['map_to_category'] ?? '';
                    },
                    $feed_mappings
                );
            }
        }

        ob_start();
        foreach ( $parent_terms as $category ) {
            self::print_hierarchical_categories_mapping_view( $category, $mapped_category_ids );
        }
        $html = ob_get_clean();

        return $html;
    }

    /**
     * Hierarchical categories mapping view.
     *
     * @since 13.4.0
     * @access private
     *
     * @param object $category            The category object.
     * @param array  $mapped_category_ids The mapped category IDs.
     * @param int    $child_number        The child number, to print the dash character.
     * @return void
     */
    public static function print_hierarchical_categories_mapping_view( $category, $mapped_category_ids = array(), $child_number = 0 ) {
        // Check if this category is already mapped.
        $mapped_category = $mapped_category_ids[ $category->term_id ] ?? '';

        // Get the children of the current category.
        $childrens = get_terms(
            array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'parent'     => $category->term_id,
                'orderby'    => 'name',
                'order'      => 'ASC',
            )
        );

        // Include the view for the current category.
        include WOOCOMMERCESEA_VIEWS_ROOT_PATH . 'manage-feed/view-google-shopping-category-mapping.php';

        // Process each child category recursively.
        if ( ! empty( $childrens ) ) {
            foreach ( $childrens as $children ) {
                self::print_hierarchical_categories_mapping_view( $children, $mapped_category_ids, $child_number + 1 );
            }
        }
    }

    /**
     * Get the price including tax.
     * This method is used to get the price including tax by feed settings.
     *
     * @since 13.4.0
     * @access public
     *
     * @param float  $price     The price of the product.
     * @param array  $tax_rates The tax rates.
     * @param object $feed      The feed object.
     * @param object $product   The product object.
     * @return float
     */
    public static function get_price_including_tax( $price, $tax_rates = array(), $feed = null, $product = null ) {
        $tax_class    = $product ? $product->get_tax_class() : '';
        $country      = $feed ? $feed->country : '';
        $price        = (float) $price;
        $return_price = $price;

        // Get the tax rates for the given country.
        $tax_rates = empty( $tax_rates ) ? self::find_tax_rates(
            array(
                'country'   => $country,
                'state'     => '',
                'postcode'  => '',
                'city'      => '',
                'tax_class' => $tax_class,
            ),
            $feed,
            $product
        ) : $tax_rates;

        if ( $product->is_taxable() ) {
            if ( ! wc_prices_include_tax() ) {
                // Calculate the tax with WC_Tax::calc_tax.
                $taxes = \WC_Tax::calc_tax( $price, $tax_rates, wc_prices_include_tax() );

                // Get the tax amount.
                $tax_amount = array_sum( $taxes );

                // Add the tax amount to the price.
                $return_price = $price + $tax_amount;
            } else {
                $unfiltered_tax_rates = $product ? $product->get_tax_class( 'unfiltered' ) : '';
                $base_tax_rates       = \WC_Tax::get_base_tax_rates( $unfiltered_tax_rates );

                if ( $tax_rates !== $base_tax_rates && apply_filters( 'woocommerce_adjust_non_base_location_prices', true ) ) {
                    $base_taxes   = \WC_Tax::calc_tax( $price, $base_tax_rates, true );
                    $modded_taxes = \WC_Tax::calc_tax( $price - array_sum( $base_taxes ), $tax_rates, false );

                    if ( 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ) ) {
                        $base_taxes_total   = array_sum( $base_taxes );
                        $modded_taxes_total = array_sum( $modded_taxes );
                    } else {
                        $base_taxes_total   = array_sum( array_map( 'wc_round_tax_total', $base_taxes ) );
                        $modded_taxes_total = array_sum( array_map( 'wc_round_tax_total', $modded_taxes ) );
                    }

                    $return_price = $price - $base_taxes_total + $modded_taxes_total;
                }
            }
        }

        return $return_price;
    }

    /**
     * Get the price excluding tax.
     * This method is used to get the price excluding tax by feed settings.
     *
     * @since 13.4.0
     * @access public
     *
     * @param float  $price     The price of the product.
     * @param array  $tax_rates The tax rates.
     * @param object $feed      The feed object.
     * @param object $product   The product object.
     * @return float
     */
    public static function get_price_excluding_tax( $price, $tax_rates = array(), $feed = null, $product = null ) {
        $tax_class    = $product ? $product->get_tax_class() : '';
        $country      = $feed ? $feed->country : '';
        $price        = (float) $price;
        $return_price = $price;

        // Get the tax rates for the given country.
        $tax_rates = empty( $tax_rates ) ? self::find_tax_rates(
            array(
                'country'   => $country,
                'state'     => '',
                'postcode'  => '',
                'city'      => '',
                'tax_class' => $tax_class,
            ),
            $feed,
            $product
        ) : $tax_rates;

        if ( $product->is_taxable() && wc_prices_include_tax() ) {
            if ( apply_filters( 'woocommerce_adjust_non_base_location_prices', true ) ) {
                $unfiltered_tax_rates = $product ? $product->get_tax_class( 'unfiltered' ) : '';
                $tax_rates            = \WC_Tax::get_base_tax_rates( $unfiltered_tax_rates );
            }
            $remove_taxes = \WC_Tax::calc_tax( $price, $tax_rates, true );
            $return_price = $price - array_sum( $remove_taxes ); // Unrounded since we're dealing with tax inclusive prices. Matches logic in cart-totals class. @see adjust_non_base_location_price.
        }

        return $return_price;
    }

    /**
     * Find tax rates.
     *
     * This method is used to find the tax rates for the given arguments.
     *
     * @since 13.4.0
     * @access public
     *
     * @param array  $args    The arguments for finding tax rates.
     * @param object $feed    The feed object.
     * @param object $product The product object.
     * @return array
     */
    public static function find_tax_rates( $args, $feed = null, $product = null ) {
        return \WC_Tax::find_rates(
            /**
             * Filters the arguments for finding tax rates.
             *
             * @since 13.4.0
             *
             * @param array  $args    The arguments for finding tax rates.
             * @param object $feed    The feed object.
             * @param object $product The product object.
             * @return array
             */
            apply_filters(
                'adt_product_feed_find_tax_rates_args',
                wp_parse_args(
                    $args,
                    array(
                        'country'   => '',
                        'state'     => '',
                        'postcode'  => '',
                        'city'      => '',
                        'tax_class' => '',
                    )
                ),
                $feed,
                $product
            )
        );
    }
}
