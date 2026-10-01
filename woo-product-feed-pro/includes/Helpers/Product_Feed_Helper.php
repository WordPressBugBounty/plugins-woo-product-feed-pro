<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Helpers
 */

namespace AdTribes\PFP\Helpers;

use AdTribes\PFP\Integrations\Caching;
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Factories\Product_Feed_Query;

/**
 * Helper methods class.
 *
 * @since 13.3.5
 */
class Product_Feed_Helper {

    /**
     * PHP extensions the XML feed writer needs in order to run.
     *
     * `xmlwriter` became a hard runtime dependency when the feed formatter moved from DOM
     * string output to XMLWriter. `dom` and `simplexml` are used by the same write path
     * (SimpleXMLElement, dom_import_simplexml, DOMDocument), so a missing one of those
     * fails identically. Non-XML formats (csv, txt, tsv, jsonl) do not need any of them.
     *
     * @since 13.5.8
     * @var string[]
     */
    const XML_REQUIRED_PHP_EXTENSIONS = array( 'xmlwriter', 'dom', 'simplexml' );

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
     * @return Product_Feed|false Returns the product feed object if valid, otherwise false.
     */
    public static function get_product_feed( $feed = 0, $context = 'view' ) {
        if ( class_exists( 'AdTribes\PFE\Factories\Product_Feed' ) ) {
            $feed_object = new \AdTribes\PFE\Factories\Product_Feed( $feed, $context );
        } else {
            $feed_object = new Product_Feed( $feed, $context );
        }

        // If the feed is 0 or null, it means we are creating a new feed object.
        if ( 0 === $feed || null === $feed ) {
            return $feed_object;
        }

        return $feed_object->id > 0 ? $feed_object : false;
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
        $legacy_countries = include ADT_PFP_PLUGIN_DIR_PATH . 'includes/I18n/legacy_countries.php';
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
        $legacy_countries = include ADT_PFP_PLUGIN_DIR_PATH . 'includes/I18n/legacy_countries.php';
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
        /*
         * Memoized per request: the lookup re-`include`s a ~237-entry static file
         * and scans it linearly, and a single field-mapping read resolves the same
         * hash from three call sites (the REST payload, the migrator, and the
         * sub-attribute registry). The source file is static, so the result cannot
         * change within a request.
         */
        static $resolved = array();

        $cache_key = (string) $channel_hash;

        if ( array_key_exists( $cache_key, $resolved ) ) {
            return $resolved[ $cache_key ];
        }

        $legacy_channel_statics = include ADT_PFP_PLUGIN_DIR_PATH . 'includes/I18n/legacy_channel_statics.php';

        $resolved[ $cache_key ] = null;

        // Search for the channel hash in the legacy channel statics.
        foreach ( $legacy_channel_statics as $country ) {
            foreach ( $country as $channel ) {
                if ( $channel['channel_hash'] === $channel_hash ) {
                    $resolved[ $cache_key ] = $channel;
                    break 2;
                }
            }
        }

        return $resolved[ $cache_key ];
    }

    /**
     * Load a channel class and return its attribute catalog.
     *
     * Single owner of the dynamic `WooSEA_{fields}` class loading sequence and of
     * the path-traversal guard that protects it. `Field_Mappings` (the REST
     * payload) and `Feed_Attributes_Migrator` (the `is_custom` classifier) both
     * read the same catalog, so keep the guard here rather than restating it at a
     * call site — a future hardening must apply everywhere at once.
     *
     * SECURITY: `$channel_fields` reaches the filesystem path, so it is clamped to
     * an alphanumeric/underscore allowlist before `file_exists()` and the include.
     * Do not relax that check.
     *
     * The catalog is memoized per request, because building it twice in one
     * request (once for the migration, once for the payload) is pure repeat work.
     *
     * @since 13.5.8
     * @access public
     *
     * @param string $channel_fields The channel `fields` value.
     * @return array The channel attribute catalog, or an empty array when the channel cannot be loaded.
     */
    public static function get_channel_attribute_catalog( $channel_fields ) {
        static $catalogs = array();

        $channel_fields = (string) $channel_fields;

        if ( isset( $catalogs[ $channel_fields ] ) ) {
            return $catalogs[ $channel_fields ];
        }

        $catalogs[ $channel_fields ] = array();

        if ( '' === $channel_fields ) {
            return $catalogs[ $channel_fields ];
        }

        $channel_class = 'WooSEA_' . $channel_fields;

        if ( ! class_exists( $channel_class ) ) {
            // Only allow alphanumeric characters and underscores, to prevent path traversal.
            if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $channel_fields ) ) {
                return $catalogs[ $channel_fields ];
            }

            $channel_file = ADT_PFP_CHANNEL_CLASS_ROOT_PATH . 'class-' . $channel_fields . '.php';
            if ( ! file_exists( $channel_file ) ) {
                return $catalogs[ $channel_fields ];
            }

            require_once $channel_file;

            if ( ! class_exists( $channel_class ) ) {
                return $catalogs[ $channel_fields ];
            }
        }

        if ( ! method_exists( $channel_class, 'get_channel_attributes' ) ) {
            return $catalogs[ $channel_fields ];
        }

        $catalogs[ $channel_fields ] = (array) $channel_class::get_channel_attributes();

        return $catalogs[ $channel_fields ];
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
        $keyspace = apply_filters( 'adt_product_feed_legacy_project_hash_keyspace', '0123456789abcdefghijklmnopqrstuvwxyz' );
        $pieces   = array();
        $length   = 32;
        $max      = mb_strlen( $keyspace, '8bit' ) - 1;

        for ( $i = 0; $i < $length; ++$i ) {
            $pieces [] = $keyspace[ random_int( 0, $max ) ];
        }

        return implode( '', $pieces );
    }

    /**
     * Determine whether a legacy cron_projects value holds at least one real feed.
     *
     * A "real" feed is any entry carrying a non-empty `project_hash`. Blank,
     * hashless placeholder entries (which some migration paths create) do not
     * count. Used to decide whether the prefixed `adt_cron_projects` option can be
     * trusted, or whether the unprefixed `cron_projects` should be used instead.
     *
     * @since 13.5.7
     * @access public
     *
     * @param mixed $projects The (maybe-unserialized) cron_projects value.
     * @return bool True if at least one entry has a non-empty project_hash.
     */
    public static function has_valid_projects( $projects ) {
        if ( ! is_array( $projects ) ) {
            return false;
        }

        foreach ( $projects as $project_data ) {
            if ( is_array( $project_data ) && ! empty( $project_data['project_hash'] ) ) {
                return true;
            }
        }

        return false;
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
     * The variation half of this count must agree with what the feed query is able to
     * return, because it seeds `products_count` - the figure the progress bar and the
     * generation log report. The query only accepts a variation whose parent product is
     * itself published (@see \AdTribes\PFP\Classes\Product_Data::only_include_published_parent_variations()),
     * so a plain `wp_count_posts( 'product_variation' )` over-counts by every published
     * variation left behind a draft, pending or private parent. That gap is invisible:
     * the run then reports products it never had any way of writing.
     *
     * @since 13.3.5
     * @since 13.5.8 The variation half applies the query's parent-status constraint.
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

        return $count_product->publish + self::get_total_feed_eligible_variations();
    }

    /**
     * Count the published variations a feed query can actually return.
     *
     * Mirrors the parent-status constraint the feed query applies in
     * \AdTribes\PFP\Classes\Product_Data::only_include_published_parent_variations(): a
     * published variation counts only while its parent product is published too.
     *
     * @since 13.5.8
     * @access public
     *
     * @return int
     */
    public static function get_total_feed_eligible_variations() {
        $counts = self::get_published_variation_counts();
        return $counts['eligible'];
    }

    /**
     * Count the published variations no feed can reach.
     *
     * A variation stays published when its parent product is set to draft, pending or
     * private - WooCommerce has no separate status control for one, and only cascades
     * to the variations when the parent is trashed - so the catalogue can hold published
     * variations that every feed drops. They are left out of products_count, so no count
     * derived from it can report them either, which is why a merchant sees a feed shrink
     * with no explanation. The generation log names the figure, and the get_feed_stats
     * and get_excluded_products abilities carry it for a site that leaves logging off.
     *
     * The count covers the whole catalogue, not one feed. Feed filters and category
     * restrictions are applied per-product downstream, so no caller can narrow it.
     *
     * @since 13.5.8
     * @access public
     *
     * @return int
     */
    public static function get_total_variations_with_unpublished_parent() {
        $counts = self::get_published_variation_counts();
        return $counts['orphaned'];
    }

    /**
     * Split the published variations by whether a feed query can reach them.
     *
     * One query answers both halves, so the rule "a variation counts only while its
     * parent product is published" lives in exactly one place. Two hand-written joins
     * would drift apart the next time that rule grows a clause, and products_count would
     * disagree with the feed query again - the very gap this count exists to close.
     *
     * `COUNT( parents.ID )` counts only the rows the LEFT JOIN matched, which is the
     * eligible half; the remaining rows are the orphans. The join is on the parent's
     * primary key, so it adds at most one row per variation and the total stays a
     * variation count.
     *
     * Read straight from the database rather than through `wp_count_posts()`, which
     * answers from a cache WordPress clears only on a status transition: after a bulk
     * delete of variations it would still describe the catalogue as it was.
     *
     * The result is held for as long as the posts cache stays unchanged. A bulk refresh
     * generates several feeds in one request and every one of them sizes itself from
     * this, so without the memo the same catalogue-wide join runs once or twice per feed.
     * WordPress bumps `last_changed` whenever a post is added, updated or deleted, so the
     * memo cannot outlive the catalogue it describes. A failed query is never memoized.
     *
     * @since 13.5.8
     * @access private
     *
     * @return array {
     *     @type int $total    Every published variation.
     *     @type int $eligible The ones a feed query can return.
     *     @type int $orphaned The ones left behind an unpublished parent.
     * }
     */
    private static function get_published_variation_counts() {
        global $wpdb;

        static $cached_key = '';
        static $cached     = null;

        $last_changed = wp_cache_get_last_changed( 'posts' );
        if ( null !== $cached && $last_changed === $cached_key ) {
            return $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            "SELECT COUNT( variations.ID ) AS total, COUNT( parents.ID ) AS eligible
            FROM {$wpdb->posts} AS variations
            LEFT JOIN {$wpdb->posts} AS parents
                ON variations.post_parent = parents.ID
                AND parents.post_type = 'product'
                AND parents.post_status = 'publish'
            WHERE variations.post_type = 'product_variation'
            AND variations.post_status = 'publish'",
            ARRAY_A
        );

        // A failed query returns null, and taking the eligible half for a zero would end
        // the run as soon as the products were written and drop every variation from the
        // file - the same silent truncation this count exists to prevent. Fall back to
        // the total instead: over-counting only costs a trailing batch that writes
        // nothing. No orphan is reported, because none was measured.
        if ( null === $row ) {
            $count_product_variation = wp_count_posts( 'product_variation' );
            $published               = (int) $count_product_variation->publish;

            return array(
                'total'    => $published,
                'eligible' => $published,
                'orphaned' => 0,
            );
        }

        $total    = (int) $row['total'];
        $eligible = (int) $row['eligible'];

        $cached_key = $last_changed;
        $cached     = array(
            'total'    => $total,
            'eligible' => $eligible,
            'orphaned' => $total - $eligible,
        );

        return $cached;
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

        // Tiered batch sizes for better reliability and efficiency.
        // Smaller batches = more reliable, better progress tracking, less memory usage.
        if ( $published_products > 50000 ) {
            // Very large feeds: 1500 products per batch.
            $batch_size = 1500;
        } elseif ( $published_products > 10000 ) {
            // Large feeds: 1000 products per batch.
            $batch_size = 1000;
        } elseif ( $published_products > 5000 ) {
            // Medium-large feeds: 500 products per batch.
            $batch_size = 500;
        } elseif ( $published_products > 1000 ) {
            // Medium feeds: 300 products per batch.
            $batch_size = 300;
        } else {
            // Small feeds: 200 products per batch.
            $batch_size = 200;
        }

        /**
         * User set his own batch size.
         */
        $batch_option      = get_option( 'adt_enable_batch', 'no' );
        $batch_size_option = get_option( 'adt_batch_size', '' );
        if ( 'yes' === $batch_option && ! empty( $batch_size_option ) && is_numeric( $batch_size_option ) ) {
            $batch_size = intval( $batch_size_option );
        }

        /**
         * Filter the batch size for feed generation.
         *
         * @since 13.5.7
         *
         * @param int          $batch_size         The batch size.
         * @param Product_Feed $feed               The product feed instance.
         * @param int          $published_products The total number of published products.
         */
        return (int) apply_filters( 'adt_product_feed_batch_size', $batch_size, $feed, $published_products );
    }

    /**
     * Remove cache.
     *
     * The method is used to remove the cache for the feed processing.
     * Two things happen before every feed run: a garbage-collection pass, because feed
     * generation is the memory-heavy path in this plugin, and the caching-plugin
     * exclusions that keep the generated files out of page caches. The old name,
     * disable_cache(), described only the second and hid the first from its callers.
     *
     * @since 13.3.5
     * @access public
     */
    public static function prepare_for_generation() {
        // Force garbage collection dump.
        gc_enable();
        gc_collect_cycles();

        // Make sure feeds are not being cached. Each integration guards on its own
        // caching plugin being present, so Caching::run() is safe to call unconditionally.
        ( new Caching() )->run();
    }

    /**
     * Remove cache.
     *
     * Kept because the method was public from 13.3.5 and custom code may call it. Nothing
     * in this plugin or its addons does any more.
     *
     * @since 13.3.5
     * @deprecated 13.5.8 Use Product_Feed_Helper::prepare_for_generation() instead.
     * @access public
     */
    public static function disable_cache() {
        _deprecated_function( __METHOD__, '13.5.8', __CLASS__ . '::prepare_for_generation' );

        self::prepare_for_generation();
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

        /**
         * Filters the refresh interval labels.
         *
         * @since 13.4.6
         * @param array $refresh_intervals The refresh interval options.
         * @return array
         */
        $refresh_intervals = apply_filters( 'adt_product_feed_refresh_interval_labels', $refresh_intervals );

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
            // Get the mappings from the feed.
            if ( $feed instanceof Product_Feed ) {
                $feed_mappings = $feed->mappings ?? array();
            } else { // Get the mappings from the feed array if a new feed is created.
                $feed_mappings = $feed['mappings'] ?? array();
            }

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
            self::print_hierarchical_categories_mapping_view( $category, $mapped_category_ids, 0, $feed );
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
     * @param object $feed                The feed object.
     * @return void
     */
    public static function print_hierarchical_categories_mapping_view( $category, $mapped_category_ids = array(), $child_number = 0, $feed = null ) {
        // Check if this category is already mapped.
        $mapped_category = $mapped_category_ids[ $category->term_id ] ?? '';

        /**
         * Get the children of the current category.
         * Filters the arguments for fetching children categories in hierarchical categories mapping.
         *
         * @since 13.4.4
         * @param array  $children_args The arguments for fetching children categories.
         * @param object $category      The parent category object.
         * @param int    $child_number  The child number, indicating the depth level.
         * @return array
         */
        $children_args = apply_filters(
            'adt_product_feed_hierarchical_categories_children_args',
            array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'parent'     => $category->term_id,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ),
            $category,
            $child_number
        );

        /**
         * Filters the children categories in hierarchical categories mapping.
         *
         * @since 13.4.4
         * @param array  $childrens    The children categories.
         * @param array  $children_args The arguments used for fetching children categories.
         * @param int    $feed_id       The feed id.
         * @param object $category      The parent category object.
         * @param int    $child_number  The child number, indicating the depth level.
         * @return array
         */
        $childrens = apply_filters(
            'adt_product_feed_hierarchical_categories_children_mapping',
            get_terms( $children_args ),
            $children_args,
            $feed->id ?? 0,
            $category,
            $child_number
        );

        // Include the view for the current category.
        Helper::locate_admin_template(
            'components/google-shopping-category-mapping.php',
            true,
            false,
            array(
                'category'        => $category,
                'mapped_category' => $mapped_category,
                'childrens'       => $childrens,
                'child_number'    => $child_number,
            )
        );

        // Process each child category recursively.
        if ( ! empty( $childrens ) ) {
            foreach ( $childrens as $children ) {
                self::print_hierarchical_categories_mapping_view( $children, $mapped_category_ids, $child_number + 1, $feed );
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
     * @since 13.4.5 Added support for base tax rates based on the WooCommerce "Calculate tax based on" settings.
     * @access public
     *
     * @param array  $args    The arguments for finding tax rates.
     * @param object $feed    The feed object.
     * @param object $product The product object.
     * @return array
     */
    public static function find_tax_rates( $args, $feed = null, $product = null ) {
        if ( 'base' === get_option( 'woocommerce_tax_based_on' ) ) {
            $args = array(
                'country'   => WC()->countries->get_base_country(),
                'state'     => WC()->countries->get_base_state(),
                'postcode'  => WC()->countries->get_base_postcode(),
                'city'      => WC()->countries->get_base_city(),
                'tax_class' => $args['tax_class'] ?? '',
            );
        }

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

    /**
     * Group channels by their type and print channel options.
     *
     * This method is used to organize channels into groups based on their type
     * and generate HTML for select options.
     *
     * @since 13.4.2
     * @access public
     * @param array  $channels        Array of channels to be grouped.
     * @param string $default_channel Default channel to be selected.
     * @return string HTML content for select options.
     */
    public static function print_channel_options( $channels, $default_channel = 'Google Shopping' ) {
        // Start output buffering.
        ob_start();

        // Group channels by their type.
        $grouped_channels = array();

        foreach ( $channels as $key => $val ) {
            if ( ! isset( $val['type'] ) ) {
                continue;
            }

            $type = $val['type'];

            if ( ! isset( $grouped_channels[ $type ] ) ) {
                $grouped_channels[ $type ] = array();
            }

            $grouped_channels[ $type ][ $key ] = $val;
        }

        // Generate the select options grouped by type.
        foreach ( $grouped_channels as $type => $type_channels ) {
            echo '<optgroup label="' . esc_attr( $type ) . '">';

            foreach ( $type_channels as $key => $val ) {
                $selected = $default_channel === $val['name'] ? ' selected' : '';
                echo '<option value="' . esc_attr( $val['channel_hash'] ) . '"' . esc_attr( $selected ) . '>' . esc_html( $val['name'] ) . '</option>';
            }

            echo '</optgroup>';
        }

        // Get the buffered content and return it.
        return ob_get_clean();
    }

    /**
     * Get total count of product feeds.
     *
     * @since 13.4.4
     * @access public
     *
     * @return int Total number of feeds.
     */
    public static function get_total_feeds_count() {
        global $wpdb;

        // Direct SQL count query for better performance.
        $total_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} 
                WHERE post_type = %s 
                AND post_status IN ('publish', 'draft')",
                'adt_product_feed'
            )
        );

        return $total_count;
    }

    /**
     * Generate HTML for the feed URL column.
     *
     * This method generates the appropriate HTML for the feed URL column based on
     * the feed's status and whether the feed file exists.
     *
     * @since 13.4.5
     * @access public
     *
     * @param object $feed The feed object.
     * @return string HTML for the feed URL column.
     */
    public static function get_feed_url_html( $feed ) {
        ob_start();

        // Load the template with the feed variable.
        Helper::locate_admin_template(
            'components/feed-url.php',
            true,
            false,
            array(
                'feed' => $feed,
            )
        );

        return ob_get_clean();
    }

    /**
     * Check if the channel is all feeds channel.
     *
     * @since 13.4.6
     * @access public
     *
     * @param string $feed_channel The Feed channel.
     * @return bool
     */
    public static function is_all_feeds_channel( $feed_channel ) {
        $legacy_channel_statics = include ADT_PFP_PLUGIN_DIR_PATH . 'includes/I18n/legacy_channel_statics.php';

        $all_countries_feeds_channel = $legacy_channel_statics['All countries'];
        $custom_feeds_channel        = $legacy_channel_statics['Custom Feed'];

        $all_feeds_channel = array_merge( $all_countries_feeds_channel, $custom_feeds_channel );

        foreach ( $all_feeds_channel as $channel ) {
            if ( $channel['fields'] === $feed_channel ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the per-product currency code used in feed output.
     *
     * Single source for the `adt_product_data_currency` filter result so callers
     * (the product-data builder and channel feed classes) don't duplicate the
     * expression and drift.
     *
     * @since 13.5.6
     * @access public
     *
     * @return string The currency code (ISO 4217).
     */
    public static function get_product_data_currency() {
        return (string) apply_filters( 'adt_product_data_currency', get_woocommerce_currency() );
    }

    /**
     * Get the XML feed writer's required PHP extensions that this server is missing.
     *
     * @since 13.5.8
     * @access public
     *
     * @return array List of missing extension names. Empty when the server has them all.
     */
    public static function get_missing_xml_php_extensions() {
        $missing = array();

        foreach ( self::XML_REQUIRED_PHP_EXTENSIONS as $extension ) {
            if ( ! extension_loaded( $extension ) ) {
                $missing[] = $extension;
            }
        }

        return $missing;
    }

    /**
     * Check whether a feed writes XML and so depends on the XML PHP extensions.
     *
     * Keyed on the `xml` base format because that is the only base format the writer
     * emits XML for. A new XML-producing format added under a different slug must be
     * listed here too, or it silently loses the missing-extension guard.
     *
     * @since 13.5.8
     * @access public
     *
     * @param Product_Feed $feed The product feed object.
     * @return bool
     */
    public static function feed_needs_xml_php_extensions( $feed ) {
        return 'xml' === Product_Feed::get_base_file_format( (string) $feed->file_format );
    }

    /**
     * Load a temporary XML feed file during batch processing, capturing libxml parse detail.
     *
     * Wraps simplexml_load_file() with libxml internal-error capture so a parse failure can
     * report the actual libxml error plus a context snippet (via get_libxml_error_detail())
     * instead of just the filename. The libxml internal-error state is saved and restored on
     * both the success and failure paths so other XML operations are unaffected. CDATA is
     * preserved (LIBXML_NOCDATA is intentionally not passed).
     *
     * @since 13.5.6
     * @access public
     *
     * @param string $file Absolute path to the temporary XML feed file.
     * @return \SimpleXMLElement The parsed XML.
     * @throws \Exception When the file cannot be parsed, with the libxml detail appended.
     */
    public static function load_xml_feed_file( $file ) {
        $libxml_previous = libxml_use_internal_errors( true );
        libxml_clear_errors();

        $xml = simplexml_load_file( $file );
        if ( false === $xml ) {
            $parse_detail = self::get_libxml_error_detail( $file );
            libxml_clear_errors();
            libxml_use_internal_errors( $libxml_previous );
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is logged as plain text (WC logger / feed error_message), not HTML output; escaping would corrupt the diagnostic XML snippet. Output is escaped at display time.
            throw new \Exception( 'Failed to parse temporary XML feed file: ' . basename( $file ) . $parse_detail );
        }

        libxml_clear_errors();
        libxml_use_internal_errors( $libxml_previous );

        return $xml;
    }

    /**
     * Build a human-readable detail string from the most recent libxml parse errors.
     *
     * The caller must enable libxml internal errors (and clear the buffer) before the failing
     * parse. Reads the first captured error and appends a short snippet of the offending file
     * content around the failure column, so XML feed parse failures are diagnosable straight
     * from the feed error log without external tools.
     *
     * @since 13.5.6
     * @access private
     *
     * @param string $file Absolute path to the XML file that failed to parse.
     * @return string Detail string beginning with ' — ', or '' when no libxml error is available.
     */
    private static function get_libxml_error_detail( $file ) {
        $xml_errors = libxml_get_errors();
        if ( empty( $xml_errors ) ) {
            return '';
        }

        $first        = reset( $xml_errors );
        $parse_detail = sprintf( ' — %s (line %d, col %d)', trim( $first->message ), $first->line, $first->column );

        // Append a short context snippet around the failure so the bad markup is visible.
        // Read only a bounded ~120-byte window around the error column rather than the whole
        // offending line: generated feed XML is frequently minified onto a single line, so
        // reading "the line" would otherwise pull the entire (multi-MB) file into memory.
        // Seek to the line, take its byte offset, then byte-seek to the window. ($first->line
        // is 1-based; SplFileObject::seek() is 0-based.) Best-effort, cold-path only.
        if ( $first->line > 0 ) {
            try {
                $reader = new \SplFileObject( $file, 'r' );
                $reader->seek( $first->line - 1 );              // Position at the offending line.
                $line_start = (int) $reader->ftell();           // Absolute byte offset of that line.
                $reader->fseek( $line_start + max( 0, $first->column - 60 ) );
                $snippet = trim( (string) $reader->fread( 120 ) );
                $reader  = null; // Release the file handle.

                if ( '' !== $snippet ) {
                    $parse_detail .= ' — context: "...' . $snippet . '..."';
                }
            } catch ( \Exception $e ) {
                // Snippet is best-effort: keep the message-only detail if the file can no
                // longer be opened for reading. Never let the snippet read mask the real
                // "failed to parse" exception the caller is about to throw.
                unset( $e );
            }
        }

        return $parse_detail;
    }

    /**
     * Format a postal code for the feed shipping block's postal_code sub-attribute.
     *
     * Google Merchant Center (and Facebook, which mirrors Google's shipping format)
     * rejects the full alphanumeric postal code for GB and CA in the shipping block —
     * it only accepts the outward/area portion. Sending the full postcode causes an
     * "Invalid ZIP code [postal_code]" error that limits the row's visibility.
     *
     * - GB: outward code — the part before the space (e.g. "LE7 2HA" -> "LE7"). When the
     *       space is missing, a full postcode (5-7 chars) has the 3-char inward code
     *       stripped (e.g. "LE72HA" -> "LE7"); an already-outward code (2-4 chars, e.g.
     *       "SW1A") is returned unchanged so a merchant's manual mitigation is preserved.
     * - CA: Forward Sortation Area — the first three characters (e.g. "K1A 0B1" -> "K1A").
     * - Any other country is returned unchanged.
     *
     * @since 13.5.7
     * @access public
     *
     * @param string $postcode The raw postal code.
     * @param string $country  The ISO 3166-1 alpha-2 country code for the location.
     * @return string The postal code formatted for the shipping block.
     */
    public static function format_shipping_postal_code( $postcode, $country ) {
        $postcode = trim( (string) $postcode );
        if ( '' === $postcode ) {
            return $postcode;
        }

        $country = strtoupper( trim( (string) $country ) );

        if ( 'GB' === $country ) {
            $normalized = strtoupper( $postcode );
            $space_pos  = strpos( $normalized, ' ' );
            if ( false !== $space_pos ) {
                return trim( substr( $normalized, 0, $space_pos ) );
            }
            $compact = str_replace( ' ', '', $normalized );
            return strlen( $compact ) > 4 ? substr( $compact, 0, -3 ) : $compact;
        }

        if ( 'CA' === $country ) {
            $compact = strtoupper( str_replace( ' ', '', $postcode ) );
            return substr( $compact, 0, 3 );
        }

        return $postcode;
    }

    /**
     * Strip a field-mapping prefix from a mapped attribute name.
     *
     * The feed generator rewrites two mapped attribute keys while it assembles a
     * product: `custom_attributes_attribute_<name>` and `product_attributes_<name>`
     * both become `<name>` (see Get_Products). Any consumer that has to predict the
     * assembled key from the mapping alone - Feed_Writer_Parquet resolves its column
     * list from the mapping before the first product exists - must apply the same
     * rewrite. It lives here so the two sites cannot drift.
     *
     * @since 13.5.8
     * @access public
     *
     * @param string $key A mapped attribute name, or an assembled product-array key.
     * @return string The key with a known mapping prefix removed. Unchanged when it carries none.
     */
    public static function strip_mapped_attribute_prefix( $key ) {
        $key = (string) $key;

        foreach ( array( 'custom_attributes_attribute_', 'product_attributes_' ) as $prefix ) {
            if ( false !== strpos( $key, $prefix ) ) {
                $pieces = explode( $prefix, $key );

                return $pieces[1];
            }
        }

        return $key;
    }
}
