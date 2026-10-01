<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Traits\Singleton_Trait;
use AdTribes\PFP\Helpers\Helper;
use AdTribes\PFP\Helpers\Product_Feed_Helper;
use AdTribes\PFP\Factories\Admin_Notice;
use AdTribes\PFP\Factories\Product_Feed;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Usage tracking class.
 *
 * @since 13.3.9
 */
class Usage extends Abstract_Class {

    use Singleton_Trait;

    /**
     * The minimum gap between two check-ins.
     *
     * @since 13.5.9
     * @var int
     */
    const CHECKIN_WINDOW = 6 * DAY_IN_SECONDS;

    /**
     * Property that houses all admin notices data.
     *
     * @since 13.3.9
     * @access private
     * @var array
     */
    private $_notices = array();

    /**
     * Property that houses all product feed data.
     *
     * @since 13.3.9
     * @access private
     * @var array
     */
    private $_product_feed_data = array();

    /**
     * Post status of each feed in $_product_feed_data, keyed by feed ID.
     *
     * Kept beside the meta rather than inside them, because it is a post column
     * and not feed meta. Reported per feed so the warehouse can tell an active
     * feed from a paused one instead of the plugin deciding which of the two
     * counts - see _prepare_product_feed_data().
     *
     * @since 13.6.0
     * @access private
     *
     * @var array
     */
    private $_product_feed_status = array();

    /**
     * Gather the tracking data together
     *
     * @since 13.3.9
     * @access public
     */
    private function _get_data() {
        $data = array();

        $this->_prepare_product_feed_data();

        // Plugin data.
        $this->_append_plugins_data( $data );

        // Settings data.
        $this->_append_settings_data( $data );

        // Server environment data.
        $this->_append_environment_data( $data );

        // Product feed data. Runs before the effectiveness block, which sums the
        // per-feed counts this fills in.
        $this->_append_product_feed_data( $data );

        // Effectiveness data.
        $this->_append_effectiveness_data( $data );

        // Channel attributes data.
        $this->_append_channel_attributes_data( $data );

        return $data;
    }

    /**
     * Append versions and license data for all AdTribes related plugins.
     *
     * @since 13.3.9
     * @access private
     *
     * @param array $data Usage data.
     */
    private function _append_plugins_data( &$data ) {

        $data = wp_parse_args(
            $data,
            array(
                'pfp_version'       => Helper::get_plugin_version(),
                'pfe_version'       => '',
                'pfe'               => (int) Helper::is_plugin_active( 'woo-product-feed-elite/woocommerce-sea.php' ),
                'pfe_license_email' => '',
                'pfe_license_key'   => '',
            )
        );

        // PFE data.
        if ( 1 === $data['pfe'] ) {
            $data['pfe_version']       = defined( 'WOOCOMMERCESEA_ELITE_PLUGIN_VERSION' ) ? WOOCOMMERCESEA_ELITE_PLUGIN_VERSION : '';
            $data['pfe_license_email'] = get_option( ADT_PFE_LICENSE_KEY );
            $data['pfe_license_key']   = get_option( ADT_PFE_ACTIVATION_EMAIL );
        }
    }

    /**
     * Append settings data.
     *
     * @since 13.3.9
     * @access private
     *
     * @param array $data Usage data.
     */
    private function _append_settings_data( &$data ) {
        global $wpdb;

        $option_keys = array(
            'adt_use_parent_variable_product_image',
            'adt_add_all_shipping',
            'adt_remove_other_shipping_classes_on_free_shipping',
            'adt_remove_free_shipping',
            'adt_remove_local_pickup_shipping',
            'adt_show_only_basis_attributes',
            'adt_enable_logging',
            'adt_add_facebook_pixel',
            'adt_facebook_pixel_content_ids',
            'adt_add_remarketing',
            'adt_enable_batch',
            'adt_batch_size',
        );

        $data['settings'] = array();

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options}
                WHERE option_name IN (" . implode( ', ', array_fill( 0, count( $option_keys ), '%s' ) ) . ')',
                $option_keys
            )
        );

        foreach ( $results as $row ) {
            $data['settings'][ $row->option_name ] = $row->option_value;
        }
    }

    /**
     * Append server environment data.
     *
     * @since 13.3.9
     * @access private
     *
     * @param array $data Usage data.
     */
    private function _append_environment_data( &$data ) {
        // Get current theme info.
        $theme_data = wp_get_theme();

        // Get multisite data.
        $count_blogs = 1;
        if ( is_multisite() ) {
            if ( function_exists( 'get_blog_count' ) ) {
                $count_blogs = get_blog_count();
            } else {
                $count_blogs = 'Not Set';
            }
        }

        $data['url']               = home_url();
        $data['php_version']       = phpversion();
        $data['wp_version']        = get_bloginfo( 'version' );
        $data['wc_version']        = \WC()->version;
        $data['server']            = isset( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : ''; // phpcs:ignore
        $data['multisite']         = is_multisite();
        $data['sites']             = $count_blogs;
        $data['usercount']         = function_exists( 'count_users' ) ? count_users() : 'Not Set';
        $data['themename']         = $theme_data->Name;
        $data['themeversion']      = $theme_data->Version;
        $data['admin_email']       = get_bloginfo( 'admin_email' );
        $data['usagetracking']     = get_option( ADT_PFP_USAGE_CRON_CONFIG, false );
        $data['timezoneoffset']    = wp_timezone_string();
        $data['locale']            = get_locale();
        $data['active_plugins']    = $this->_get_active_plugins_data();
        $data['is_hpos_enabled']   = 'yes' === get_option( 'woocommerce_feature_custom_order_tables_enabled' );
        $data['is_cart_block']     = has_block( 'woocommerce/cart', wc_get_page_id( 'cart' ) );
        $data['is_checkout_block'] = has_block( 'woocommerce/checkout', wc_get_page_id( 'checkout' ) );
    }

    /**
     * Get site's list of active plugins.
     *
     * @since 13.3.9
     * @access private
     *
     * @return array List of active plugins.
     */
    private function _get_active_plugins_data() {
        $active_plugins         = get_option( 'active_plugins', array() );
        $network_active_plugins = array_keys( get_site_option( 'active_sitewide_plugins', array() ) );

        return array_unique( array_merge( $active_plugins, $network_active_plugins ) );
    }

    /**
     * Prepare product feed IDs for the current week.
     *
     * Both queries used to enumerate every `adt_product_feed` post of any status,
     * which reported trashed feeds and the phantom posts of issue #1189 as real
     * feeds - 342k reported against roughly 14k real ones, which made
     * `total_product_feed` and every feeds-per-site figure derived from it
     * unusable. Two conditions fix that:
     *
     * - The feed must carry a channel. The channel is what makes a feed a feed: it
     *   selects the attribute set, the writer and the output columns, and a post
     *   without one has never generated a file and never can. This alone removes
     *   every phantom, which is why it does the work here.
     * - The post must be `publish` or `draft`, which drops trashed feeds,
     *   auto-drafts and revisions.
     *
     * A paused feed is deliberately still counted. Turning a feed off in Manage
     * Feeds sets its status to `draft`, and so does creating one through the CLI or
     * the abilities, yet it keeps its channel and its generation history. Dropping
     * those would trade the known over-count for a quieter under-count, and would
     * make `total_products_in_feeds` fall whenever a merchant pauses a feed - churn
     * that did not happen. The status travels with each feed row instead, so the
     * warehouse decides.
     *
     * @since 13.3.9
     * @since 13.6.0 Counts only feeds that have a channel, and drops trashed feeds.
     * @access private
     */
    private function _prepare_product_feed_data() {
        global $wpdb;

        $product_feeds = array();
        $feed_statuses = array();

        /**
         * If the database does not support JSON_OBJECTAGG, we need to use a different query.
         * This is because the JSON_OBJECTAGG function is only available in MySQL 5.7.22 and MariaDB 10.2.3.
         */
        if ( Helper::is_db_supports_json() ) {
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.ID, p.post_status,
                        JSON_OBJECTAGG(pm.meta_key, pm.meta_value) AS post_meta
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                     INNER JOIN {$wpdb->postmeta} ch ON ch.post_id = p.ID AND ch.meta_key = %s AND ch.meta_value <> ''
                     WHERE p.post_type = %s
                        AND p.post_status IN ( 'publish', 'draft' )
                        AND pm.meta_key LIKE %s
                    GROUP BY p.ID, p.post_status",
                    Product_Feed::META_PREFIX . 'channel_hash',
                    Product_Feed::POST_TYPE,
                    'adt_%'
                )
            );

            if ( ! empty( $results ) ) {
                foreach ( $results as $row ) {
                    $post_meta = json_decode( $row->post_meta, true );
                    $meta_data = array();

                    if ( ! empty( $post_meta ) ) {
                        foreach ( $post_meta as $key => $value ) {
                            $meta_data[ $key ] = maybe_unserialize( $value );
                        }
                    }

                    $product_feeds[ $row->ID ] = $meta_data;
                    $feed_statuses[ $row->ID ] = (string) $row->post_status;
                }
            }
        } else {
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.ID, p.post_status, pm.meta_key, pm.meta_value
                        FROM {$wpdb->posts} p
                        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                        INNER JOIN {$wpdb->postmeta} ch ON ch.post_id = p.ID AND ch.meta_key = %s AND ch.meta_value <> ''
                        WHERE p.post_type = %s
                            AND p.post_status IN ( 'publish', 'draft' )
                            AND pm.meta_key LIKE %s",
                    Product_Feed::META_PREFIX . 'channel_hash',
                    Product_Feed::POST_TYPE,
                    'adt_%'
                )
            );

            if ( ! empty( $results ) ) {
                foreach ( $results as $row ) {
                    $product_feeds[ $row->ID ][ $row->meta_key ] = maybe_unserialize( $row->meta_value );
                    $feed_statuses[ $row->ID ]                   = (string) $row->post_status;
                }
            }
        }

        $this->_product_feed_data   = $product_feeds ?? array();
        $this->_product_feed_status = $feed_statuses;
    }

    /**
     * Append effectiveness data.
     *
     * Runs after _append_product_feed_data(): total_products_in_feeds sums the
     * per-feed rows that appender builds. Keep that order.
     *
     * @since 13.3.9
     * @access private
     *
     * @param array $data Usage data.
     */
    private function _append_effectiveness_data( &$data ) {
        global $wpdb;

        // Retrieve the total number of published products.
        $total_products_query_results = $wpdb->get_row(
            "SELECT 
                COUNT(*) AS total_product,
                SUM(CASE WHEN t.slug = 'simple' THEN 1 ELSE 0 END) AS total_simple_product,
                SUM(CASE WHEN t.slug = 'variable' THEN 1 ELSE 0 END) AS total_variable_product,
                (
                    SELECT COUNT(*) AS total_variation
                        FROM {$wpdb->posts} pv
                    WHERE pv.post_status = 'publish' 
                        AND pv.post_type = 'product_variation'
                ) as total_variation_product
            FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_type'
                INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
            WHERE p.post_status = 'publish' 
                AND p.post_type = 'product'
            "
        );

        $data['effectiveness'] = array(
            'total_product_feed'      => ! empty( $this->_product_feed_data ) ? count( $this->_product_feed_data ) : 0,
            'total_products_in_feeds' => $this->_get_total_products_in_feeds( $data['product_feeds'] ?? array() ),
            'total_product'           => $total_products_query_results->total_product ?? 0,
            'total_simple_product'    => $total_products_query_results->total_simple_product ?? 0,
            'total_variable_product'  => $total_products_query_results->total_variable_product ?? 0,
            'total_variation_product' => $total_products_query_results->total_variation_product ?? 0,
            'date'                    => gmdate( 'Y-m-d H:i:s', strtotime( 'midnight', strtotime( 'sunday last week' ) ) + WEEK_IN_SECONDS - 1 ),
        );
        return $data;
    }

    /**
     * Sum the products every feed on this site wrote on its last generation.
     *
     * This is the site's contribution to "products live in channel feeds". It is not
     * the catalogue size: a feed that filters by category contributes the filtered
     * count, which is the whole reason the number is worth sending.
     *
     * The feeds with no recorded count are skipped rather than added as zero, so the
     * total is always the sum of the last_products_in_feed values in the same payload.
     * A total of zero therefore means either that every feed wrote nothing or that no
     * feed has a recorded count; read it against the per-feed rows, not on its own.
     *
     * @since 13.6.0
     * @access private
     *
     * @param array $product_feeds The per-feed rows this check-in is sending.
     * @return int Total products written across all feeds on their last generation.
     */
    private function _get_total_products_in_feeds( $product_feeds ) {
        $total = 0;

        foreach ( $product_feeds as $feed_data ) {
            // _append_product_feed_data() sets this key on every row, which is why
            // _get_data() runs it first. A missing key means the appenders were
            // reordered, not that the feed is empty.
            if ( isset( $feed_data['last_products_in_feed'] ) ) {
                $total += $feed_data['last_products_in_feed'];
            }
        }

        return $total;
    }

    /**
     * Append product feeds data.
     *
     * @since 13.3.9
     * @access private
     *
     * @param array $data Usage data.
     */
    private function _append_product_feed_data( &$data ) {
        if ( empty( $this->_product_feed_data ) ) {
            return $data;
        }

        $data['product_feeds'] = array();
        $product_feeds         = $this->_product_feed_data;

        // Default data to collect.
        $default_data = array(
            'country'                                => '',
            'channel_hash'                           => '',
            'include_product_variations'             => '',
            'only_include_default_product_variation' => '',
            'only_include_lowest_product_variation'  => '',
            'file_format'                            => '',
            'refresh_interval'                       => '',
            'refresh_only_when_product_changed'      => '',
            'create_preview'                         => '',
            // There is no adt_last_products_in_feed meta, so the generic loop below
            // leaves this null and the products history fills it in.
            'last_products_in_feed'                  => null,
            'last_updated'                           => '',
        );

        if ( ! empty( $product_feeds ) ) {
            foreach ( $product_feeds as $id => $meta_data ) {
                $feed_data = array();

                // Collect the data from meta data and merge with default data.
                foreach ( $default_data as $key => $value ) {
                    $feed_data[ $key ] = $meta_data[ 'adt_' . $key ] ?? $value;
                }

                // Get channel key from the legacy channel hash.
                if ( isset( $feed_data['channel_hash'] ) ) {
                    $channel_data         = Product_Feed_Helper::get_channel_from_legacy_channel_hash( $feed_data['channel_hash'] );
                    $feed_data['channel'] = ! empty( $channel_data['name'] ) ? sanitize_key( $channel_data['name'] ) : '';

                    unset( $feed_data['channel_hash'] );
                }

                // Products written to the feed file on the last generation. Stays null when
                // the history is empty, because an empty history does not prove the feed
                // never generated - see Product_Feed::parse_latest_products_in_feed().
                $feed_data['last_products_in_feed'] = Product_Feed::parse_latest_products_in_feed(
                    $meta_data['adt_history_products'] ?? array()
                )['count'];

                // A post column rather than feed meta, so it is not in $default_data.
                $feed_data['post_status'] = $this->_product_feed_status[ $id ] ?? '';

                $data['product_feeds'][ $id ] = $feed_data;
            }
        }

        return $data;
    }

    /**
     * Append channel attributes data.
     *
     * @since 13.3.9
     * @access private
     *
     * @param array $data Usage data.
     */
    private function _append_channel_attributes_data( &$data ) {
        if ( empty( $this->_product_feed_data ) ) {
            return $data;
        }

        $data['channel_attributes'] = array();
        $product_feed_data          = $this->_product_feed_data;

        if ( ! empty( $product_feed_data ) ) {
            foreach ( $product_feed_data as $meta_data ) {
                $channel_key = '';

                // Get channel key from the legacy channel hash.
                if ( isset( $meta_data['adt_channel_hash'] ) ) {
                    $channel_data = Product_Feed_Helper::get_channel_from_legacy_channel_hash( $meta_data['adt_channel_hash'] );
                    $channel_key  = ! empty( $channel_data['name'] ) ? sanitize_key( $channel_data['name'] ) : '';
                }

                $attributes = isset( $meta_data['adt_attributes'] ) ? maybe_unserialize( $meta_data['adt_attributes'] ) : array();
                if ( ! empty( $attributes ) && ! empty( $channel_key ) ) {
                    foreach ( $attributes as $attribute ) {
                        // Strip g: prefix from attribute name.
                        $attribute['attribute'] = str_replace( 'g:', '', $attribute['attribute'] );

                        if ( ! isset( $data['channel_attributes'][ $channel_key ][ $attribute['attribute'] ] ) ) {
                            $data['channel_attributes'][ $channel_key ][ $attribute['attribute'] ] = 1;
                        } else {
                            ++$data['channel_attributes'][ $channel_key ][ $attribute['attribute'] ];
                        }
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Get product feeds meta data.
     *
     * @since 13.3.9
     * @access private
     */
    private function _get_product_feeds_meta_data() {
        global $wpdb;

        $product_feeds = array();

        /**
         * If the database does not support JSON_OBJECTAGG, we need to use a different query.
         * This is because the JSON_OBJECTAGG function is only available in MySQL 5.7.22 and MariaDB 10.2.3.
         */
        if ( ! Helper::is_db_supports_json() ) {
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.ID,
                        JSON_OBJECTAGG(pm.meta_key, pm.meta_value) AS post_meta
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                     WHERE p.ID IN (" . implode( ', ', array_fill( 0, count( $this->_product_feed_ids ), '%d' ) ) . ')
                        AND pm.meta_key LIKE %s
                        GROUP BY p.ID',
                    array_merge(
                        $this->_product_feed_ids,
                        array( 'adt_%' )
                    )
                )
            );

            if ( ! empty( $results ) ) {
                foreach ( $results as $row ) {
                    $post_meta = json_decode( $row->post_meta, true );
                    $meta_data = array();

                    if ( ! empty( $post_meta ) ) {
                        foreach ( $post_meta as $key => $value ) {
                            $meta_data[ $key ] = maybe_unserialize( $value );
                        }
                    }

                    $product_feeds[ $row->ID ] = $meta_data;
                }
            }
        } else {
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.ID, pm.meta_key, pm.meta_value
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                     WHERE p.ID IN (" . implode( ', ', array_fill( 0, count( $this->_product_feed_ids ), '%d' ) ) . ')
                     AND pm.meta_key LIKE %s',
                    array_merge(
                        $this->_product_feed_ids,
                        array( 'adt_%' )
                    )
                )
            );

            if ( ! empty( $results ) ) {
                foreach ( $results as $row ) {
                    $product_feeds[ $row->ID ][ $row->meta_key ] = maybe_unserialize( $row->meta_value );
                }
            }
        }

        return $product_feeds;
    }


    /***************************************************************************
     * Cron Schedule
     * **************************************************************************
     */

    /**
     * Schedule when we should send tracking data
     *
     * @since 13.3.9
     * @access public
     */
    public function schedule_send() {
        $main_usage_tracking_scheduled = wp_next_scheduled( ADT_PFP_USAGE_CRON_ACTION );

        // Return if both schedules are already set.
        if ( $main_usage_tracking_scheduled ) {
            return;
        }

        $tracking = array();
        // phpcs:disable
        $tracking['day']      = rand( 0, 6 );
        $tracking['hour']     = rand( 0, 23 );
        $tracking['minute']   = rand( 0, 59 );
        $tracking['second']   = rand( 0, 59 );
        // phpcs:enable
        $tracking['offset']   = ( $tracking['day'] * DAY_IN_SECONDS ) +
            ( $tracking['hour'] * HOUR_IN_SECONDS ) +
            ( $tracking['minute'] * MINUTE_IN_SECONDS ) +
            $tracking['second'];
        $tracking['initsend'] = strtotime( 'next sunday' ) + $tracking['offset'];

        if ( ! $main_usage_tracking_scheduled ) {
            // Schedule the main usage tracking event.
            wp_schedule_event( $tracking['initsend'], 'weekly', ADT_PFP_USAGE_CRON_ACTION );
            update_option( ADT_PFP_USAGE_CRON_CONFIG, $tracking, false );
        } else {
            // Use the existing scheduled time.
            $tracking['initsend'] = $main_usage_tracking_scheduled;
        }
    }

    /**
     * Add the cron schedule
     *
     * @since 13.3.9
     * @access public
     * @param array $schedules The schedules array from the filter.
     */
    public function add_schedules( $schedules = array() ) {
        // Adds once weekly to the existing schedules.
        $schedules['weekly'] = array(
            'interval' => 604800,
            'display'  => __( 'Once Weekly', 'woo-product-feed-pro' ),
        );
        return $schedules;
    }

    /**
     * Send the checkin.
     *
     * @since 13.3.9
     * @access public
     * @param bool $override            Flag to override if tracking is allowed or not.
     * @param bool $ignore_last_checkin Flag to ignore that last checkin time check.
     * @return bool Whether the checkin was sent successfully.
     */
    public function send_checkin( $override = false, $ignore_last_checkin = false ) {

        // Don't track anything from our domains.
        $home_url = trailingslashit( home_url() );
        if (
            str_contains( $home_url, 'wholesalesuiteplugin.com' )
            || str_contains( $home_url, 'advancedcouponsplugin.com' )
            || str_contains( $home_url, 'adtribes.io' )
            || str_contains( $home_url, 'wcvendors.com' )
            || str_contains( $home_url, 'visser.com.au' )
        ) {
            return false;
        }

        // Check if tracking is allowed on this site.
        if ( ! $this->_is_tracking_allowed() && ! $override ) {
            return false;
        }

        // Bail if the site is a dev site or testing site.
        if ( Helper::is_dev_url( network_site_url( '/' ) ) ) {
            return;
        }

        $last_send = get_option( ADT_PFP_USAGE_LAST_CHECKIN );
        // The cron runs weekly but WP-Cron's variable delay lands many gaps just under 7 days (#1191).
        // The 6-day window is deliberate: it gives 24h of slack against the weekly grid — the observed
        // skips all fell within a few hours of 7 days — while still suppressing a double-fired run.
        if ( is_numeric( $last_send ) && $last_send > time() - self::CHECKIN_WINDOW && ! ( $ignore_last_checkin || defined( 'ADT_PFP_TESTING_SITE' ) ) ) {
            return false;
        }

        $checkin_url = 'https://usg.rymeraplugins.com/v1/pfp-checkin/';
        if (
            defined( 'WP_ENVIRONMENT_TYPE' )
            && 'local' === WP_ENVIRONMENT_TYPE
            && defined( 'RYMERA_LOCAL_USAGE_TRACKING_URL' )
            && wc_is_valid_url( RYMERA_LOCAL_USAGE_TRACKING_URL )
        ) {
            $checkin_url = RYMERA_LOCAL_USAGE_TRACKING_URL;
        }

        $response = wp_remote_post(
            $checkin_url,
            array(
                'method'      => 'POST',
                'timeout'     => 5,
                'redirection' => 5,
                'httpversion' => '1.1',
                'blocking'    => false,
                'body'        => $this->_get_data(),
                'user-agent'  => 'PFP/' . WOOCOMMERCESEA_PLUGIN_VERSION . '; ' . get_bloginfo( 'url' ),
            )
        );

        // Record the send time. The guard above reads it to enforce the 6 day floor.
        update_option( ADT_PFP_USAGE_LAST_CHECKIN, time(), false );
        return true;
    }

    /**
     * Check if tracking is allowed.
     *
     * @since 13.3.9
     * @access private
     *
     * @return bool True if allowed, false otherwise.
     */
    private function _is_tracking_allowed() {
        $allow_usage = get_option( ADT_PFP_USAGE_ALLOW, 'no' );
        return ( 'yes' === $allow_usage ) || Helper::has_paid_plugin_active();
    }

    /***************************************************************************
     * Settings
     * **************************************************************************
     */

    /**
     * Register allow usage tracking field.
     *
     * @since 13.3.9
     * @access public
     *
     * @param array $settings Setting fields.
     * @return array Filtered setting fields.
     */
    public function register_allow_usage_tracking_field( $settings ) {

        $settings[] = array(
            'title'      => __( 'Allow usage tracking', 'woo-product-feed-pro' ),
            'type'       => 'checkbox',
            'desc'       => sprintf(
                /* Translators: %1$s: Line break, %2$s: Link to usage tracking documentation, %3$s: Closing anchor tag. */
                __( 'By allowing us to track usage data we can better help you because we know with which WordPress configurations, themes and plugins we should test.%1$sComplete documentation on usage tracking is available %2$shere%3$s.', 'woo-product-feed-pro' ),
                '<br/>',
                '<a href="' . Helper::get_utm_url( 'knowledge-base/usage-tracking', 'pfp', 'kb', 'allowusagesetting' ) . '" target="_blank">',
                '</a>'
            ),
            'id'         => ADT_PFP_USAGE_ALLOW,
            'show_title' => true,
        );

        return $settings;
    }

    /***************************************************************************
     * Notices
     * **************************************************************************
     */

    /**
     * Register allow usage tracking notice.
     *
     * @since 13.3.9
     * @access public
     */
    public function register_allow_usage_tracking_notice() {
        // Bail if the site is a dev site or testing site.
        if ( Helper::is_dev_url( network_site_url( '/' ) ) ) {
            return;
        }

        // Check if the current user is allowed to view the notice.
        // And if the current page is a plugin page or a WooCommerce screen.
        if ( Helper::is_current_user_allowed()
            && (
                Helper::is_plugin_page() ||
                Helper::is_wc_screen()
            )
        ) {
            // Initialize the allow usage tracking notice.
            $notice = new Admin_Notice(
                sprintf(
                    /* translators: %1$s = opening <p> tag; %2$s = closing </p> tag; %3$s = opening <a> tag; %4$s = closing </a> tag */
                    esc_html__(
                        '%1$sAllow Product Feed Pro for WooCommerce to track plugin usage? Opt-in to let us track usage data so we know with which WordPress configurations, themes and plugins we should test with.%2$s
                        %1$sComplete documentation on usage tracking is available %3$shere%4$s.%2$s',
                        'woo-product-feed-pro'
                    ),
                    '<p>',
                    '</p>',
                    '<a href="' . Helper::get_utm_url( 'knowledge-base/usage-tracking', 'pfp', 'kb', 'allowusagenotice' ) . '" target="_blank">',
                    '</a>',
                ),
                'allow_tracking',
            );
            $notice->run();
        }
    }

    /**
     * Register allow usage tracking notice scripts.
     *
     * @since 13.3.9
     * @access public
     */
    public function register_allow_usage_tracking_notice_scripts() {
        // Check if the current user is allowed to view the notice.
        // And if the current page is a plugin page or a WooCommerce screen.
        if ( Helper::is_current_user_allowed()
            && (
                Helper::is_plugin_page() ||
                Helper::is_wc_screen()
            )
        ) {
            // Enqueue the notice scripts.
            wp_enqueue_script( 'adt-pfp-allow-usage-tracking-notice', ADT_PFP_JS_URL . 'usage-tracking-notice.js', array( 'jquery' ), WOOCOMMERCESEA_PLUGIN_VERSION, true );
            wp_localize_script(
                'adt-pfp-allow-usage-tracking-notice',
                'adt_pfp_allow_tracking_notice',
                array(
                    'nonce' => wp_create_nonce( 'adt_pfp_allow_tracking_nonce' ),
                )
            );
        }
    }

    /**
     * Set allow notice setting to 'yes' when response clicked in notice is "allow".
     *
     * @since 13.3.9
     * @access public
     *
     * @param string $notice_key Notice key.
     * @param string $response   Notice response.
     */
    public function update_allow_usage_setting_on_notice_dismiss( $notice_key, $response ) {
        if ( 'allow_usage' === $notice_key && 'allow_usage' === $response ) {
            update_option( ADT_PFP_USAGE_ALLOW, 'yes', false );
        }
    }

    /***************************************************************************
     * Ajax
     * **************************************************************************
     */

    /**
     * Enable usage tracking via AJAX.
     *
     * @since 13.3.9
     * @access public
     */
    public function ajax_adt_pfp_anonymous_data() {
        if ( ! Helper::is_current_user_allowed() ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'woo-product-feed-pro' ) ) );
        }

        if ( ! isset( $_REQUEST['security'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['security'] ) ), 'woosea_ajax_nonce' ) ) {
            wp_send_json_error( __( 'Invalid security token', 'woo-product-feed-pro' ) );
        }

        $value = isset( $_REQUEST['value'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['value'] ) ) : '';
        $value = 'true' === $value ? 'yes' : 'no';

        // Update the option.
        if ( update_option( ADT_PFP_USAGE_ALLOW, $value, false ) ) {
            if ( 'yes' === $value ) {
                wp_send_json_success( array( 'message' => __( 'Usage tracking has been enabled.', 'woo-product-feed-pro' ) ) );
            } else {
                wp_send_json_success( array( 'message' => __( 'Usage tracking has been disabled.', 'woo-product-feed-pro' ) ) );
            }
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to enable usage tracking.', 'woo-product-feed-pro' ) ) );
        }
    }

    /**
     * Allow tracking notice action via notice.
     *
     * @since 13.3.9
     * @access public
     */
    public function ajax_adt_pfp_allow_tracking_notice_action() {
        if ( ! Helper::is_current_user_allowed() ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'woo-product-feed-pro' ) ) );
        }

        if ( ! isset( $_REQUEST['security'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['security'] ) ), 'adt_pfp_allow_tracking_nonce' ) ) {
            wp_send_json_error( __( 'Invalid security token', 'woo-product-feed-pro' ) );
        }

        $value = isset( $_REQUEST['value'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['value'] ) ) : '';
        $value = '1' === $value ? 'yes' : 'no';

        // Update the option.
        if ( update_option( ADT_PFP_USAGE_ALLOW, $value, false ) ) {
            if ( 'yes' === $value ) {
                wp_send_json_success( array( 'message' => __( 'Usage tracking has been enabled.', 'woo-product-feed-pro' ) ) );
            } else {
                wp_send_json_success( array( 'message' => __( 'Usage tracking has been disabled.', 'woo-product-feed-pro' ) ) );
            }
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to enable usage tracking.', 'woo-product-feed-pro' ) ) );
        }
    }

    /**
     * Execute Notices class.
     *
     * @since 13.3.9
     * @access public
     */
    public function run() {
        if ( ! Helper::has_paid_plugin_active() ) {
            $is_allowed = get_option( ADT_PFP_USAGE_ALLOW, '' );
            add_filter( 'adt_settings_other_settings_args', array( $this, 'register_allow_usage_tracking_field' ) );

            if ( '' === $is_allowed ) {
                add_action( 'admin_notices', array( $this, 'register_allow_usage_tracking_notice' ) );
                add_action( 'admin_enqueue_scripts', array( $this, 'register_allow_usage_tracking_notice_scripts' ) );
            }
        }

        // Cron.
        add_filter( 'init', array( $this, 'schedule_send' ) );
        add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );
        add_action( ADT_PFP_USAGE_CRON_ACTION, array( $this, 'send_checkin' ) );

        // Ajax actions.
        add_filter( 'wp_ajax_adt_pfp_anonymous_data', array( $this, 'ajax_adt_pfp_anonymous_data' ) );
        add_filter( 'wp_ajax_adt_pfp_allow_tracking_notice_action', array( $this, 'ajax_adt_pfp_allow_tracking_notice_action' ) );
    }
}
