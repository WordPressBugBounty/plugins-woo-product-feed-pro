<?php
/**
 * Class WooSEA_Caching
 *
 * Handles cache exclusions for various caching plugins.
 */
class WooSEA_Caching {

    /**
     * Exclude Feed URL from being cached by LiteSpeed
     *
     * @return false
     */
    public function litespeed_cache() {
        if ( ! class_exists( 'LiteSpeed\Core' ) || ! defined( 'LSCWP_DIR' ) ) {
            return false;
        }
        $litespeed_ex_paths = maybe_unserialize( get_option( 'litespeed.conf.cdn-exc' ) );
        if ( $litespeed_ex_paths && is_array( $litespeed_ex_paths ) && ! in_array( '/wp-content/uploads/woo-product-feed-pro', $litespeed_ex_paths, true ) ) {
                $litespeed_ex_paths = array_merge(
                    $litespeed_ex_paths,
                    array( '/wp-content/uploads/woo-product-feed-pro' )
                );
                update_option( 'litespeed.conf.cdn-exc', $litespeed_ex_paths, false );
        }
        return false;
    }

    /**
     * Exclude Feed URL from being cached by WP Fastest
     *
     * @return false
     */
    public function wp_fastest_cache() {

        if ( ! class_exists( 'WpFastestCache' ) ) {
            return false;
        }

        $wp_fastest_cache_ex_paths = json_decode( get_option( 'WpFastestCacheExclude' ), false );
        if ( $wp_fastest_cache_ex_paths && is_array( $wp_fastest_cache_ex_paths ) ) {
            $feed_path_exist = false;

            foreach ( $wp_fastest_cache_ex_paths as $path ) {
                if ( 'woo-product-feed-pro' === $path->content ) {
                    $feed_path_exist = true;
                    break;
                }
            }

            if ( ! $feed_path_exist ) {
                $new_rule          = new stdClass();
                $new_rule->prefix  = 'contain';
                $new_rule->content = 'woo-product-feed-pro';
                $new_rule->type    = 'page';

                $wp_fastest_cache_ex_paths = array_merge(
                    $wp_fastest_cache_ex_paths,
                    array( $new_rule )
                );

                update_option( 'WpFastestCacheExclude', wp_json_encode( $wp_fastest_cache_ex_paths ), false );
            }
        } elseif ( empty( $wp_fastest_cache_ex_paths ) ) {
            $wp_fastest_cache_ex_paths = array();
            $new_rule                  = new stdClass();
            $new_rule->prefix          = 'contain';
            $new_rule->content         = 'woo-product-feed-pro';
            $new_rule->type            = 'page';

            $wp_fastest_cache_ex_paths = array_merge(
                $wp_fastest_cache_ex_paths,
                array( $new_rule )
            );

            update_option( 'WpFastestCacheExclude', wp_json_encode( $wp_fastest_cache_ex_paths ), false );
        }
        return false;
    }

    /**
     * Exclude Feed URL from being cached by WP Super
     *
     * NOTE: Unlike the other 7 integrations in this class, WP Super Cache does NOT
     * store its page-cache exclusion list in a WordPress option. Its real page-cache
     * rejection list is the $cache_rejected_uri array, which lives in the file
     * $wp_cache_config_file points at (typically wp-content/wp-cache-config.php) and is
     * persisted through WP Super Cache's own wp_cache_replace_line() config-file helper
     * (see wp-cache.php). The
     * ossdl_off_exclude option belongs to the legacy OSSDL CDN off-loading module and
     * has NO effect on whether a URL is cached/served, so this must not be "fixed"
     * back to update_option( 'ossdl_off_exclude', ... ). See issue #1067.
     *
     * @return false
     */
    public function wp_super_cache() {

        if ( ! function_exists( 'wpsc_init' ) ) {
            return false;
        }

        global $cache_rejected_uri, $wp_cache_config_file;

        // Both are provided by WP Super Cache once its config is loaded; bail if the
        // config-file write helper or the config path is unavailable.
        if ( ! function_exists( 'wp_cache_replace_line' ) || empty( $wp_cache_config_file ) ) {
            return false;
        }

        $feed_path         = '/wp-content/uploads/woo-product-feed-pro';
        $wp_super_ex_paths = is_array( $cache_rejected_uri ) ? $cache_rejected_uri : array();

        if ( in_array( $feed_path, $wp_super_ex_paths, true ) ) {
            return false;
        }

        $wp_super_ex_paths[] = $feed_path;
        $cache_rejected_uri  = $wp_super_ex_paths;

        // Persist to wp-cache-config.php exactly as WP Super Cache does when it saves
        // its own cache_rejected_uri setting (see wp_cache_sanitize_value() +
        // wp_cache_update_rejected_strings() in wp-cache.php): a single-line
        // var_export() with whitespace collapsed. The single line matters — wp_cache_replace_line()
        // only rewrites the one line matching ^ *\$cache_rejected_uri, so a multi-line
        // value would be corrupted the next time WP Super Cache saves the setting itself.
        $rejected_value = preg_replace( '/[\s]+/', ' ', var_export( $wp_super_ex_paths, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- var_export() is required to serialise the array into the config file, matching WP Super Cache's own format.
        wp_cache_replace_line(
            '^ *\$cache_rejected_uri',
            '$cache_rejected_uri = ' . $rejected_value . ';',
            $wp_cache_config_file
        );

        return false;
    }

    /**
     * Exclude Feed URL from being cached by BREEZE
     *
     * @return false
     */
    public function breeze_cache() {

        if ( ! class_exists( 'Breeze_Admin' ) ) {
            return false;
        }

        $breeze_settings = maybe_unserialize( get_option( 'breeze_cdn_integration' ) );
        if ( is_array( $breeze_settings ) ) {
            $woo_product_feed_pro_files             = array( '.xml', '.csv', '.tsv', '.txt', '.xls' );
            $woo_product_feed_pro_files             = array_unique( array_merge( $woo_product_feed_pro_files, $breeze_settings['cdn-exclude-content'] ) );
            $breeze_settings['cdn-exclude-content'] = $woo_product_feed_pro_files;
            update_option( 'breeze_cdn_integration', $breeze_settings, false );
        }

        return false;
    }

    /**
     * Exclude Feed URL from being cached by WP Optimize
     *
     * @return false
     */
    public function wp_optimize_cache() {

        if ( ! class_exists( 'WP_Optimize' ) ) {
            return false;
        }

        $wp_optimize_ex_paths = maybe_unserialize( get_option( 'wpo_cache_config' ) );
        // If page caching is enabled.
        if ( isset( $wp_optimize_ex_paths['enable_page_caching'] ) && $wp_optimize_ex_paths['enable_page_caching'] && is_array( $wp_optimize_ex_paths ) && ! in_array( '/wp-content/uploads/woo-product-feed-pro', $wp_optimize_ex_paths['cache_exception_urls'], true ) ) {
            $woo_feed_ex_path['cache_exception_urls'] = array( '/wp-content/uploads/woo-product-feed-pro' );
            $wp_optimize_ex_paths                     = array_merge_recursive(
                $wp_optimize_ex_paths,
                $woo_feed_ex_path
            );
            update_option( 'wpo_cache_config', $wp_optimize_ex_paths, false );
        }

        return false;
    }

    /**
     * Exclude Feed URL from being cached by Cache Enabler
     *
     * @return false
     */
    public function cache_enabler_cache() {

        if ( ! class_exists( 'Cache_Enabler' ) ) {
            return false;
        }

        $cache_enabler_ex_paths = maybe_unserialize( get_option( 'cache_enabler' ) );
        if ( isset( $cache_enabler_ex_paths['excluded_page_paths'] ) && empty( $cache_enabler_ex_paths['excluded_page_paths'] ) ) {
            $cache_enabler_ex_paths['excluded_page_paths'] = '/wp-content/uploads/woo-product-feed-pro/';
            update_option( 'cache_enabler', $cache_enabler_ex_paths, false );
        }

        return false;
    }

    /**
     * Exclude Feed URL from being cached by Swift Performance
     *
     * @return false
     */
    public function swift_performance_cache() {

        if ( ! class_exists( 'Swift_Performance_Lite' ) ) {
                return false;
        }

        $swift_perform_ex_paths = maybe_unserialize( get_option( 'swift_performance_options' ) );

        if ( $swift_perform_ex_paths && isset( $swift_perform_ex_paths['exclude-strings'] ) ) {
            $exclude_strings = $swift_perform_ex_paths['exclude-strings'];
            if ( is_array( $exclude_strings ) && ! in_array( '/wp-content/uploads/woo-product-feed-pro', $exclude_strings, true ) ) {
                $woo_feed_ex_path['exclude-strings'] = array( '/wp-content/uploads/woo-product-feed-pro' );
                $swift_perform_ex_paths              = array_merge_recursive(
                    $swift_perform_ex_paths,
                    $woo_feed_ex_path
                );
            } else {
                $swift_perform_ex_paths['exclude-strings'] = array( '/wp-content/uploads/woo-product-feed-pro' );
            }
            update_option( 'swift_performance_options', $swift_perform_ex_paths, false );
        } elseif ( empty( $swift_perform_ex_paths ) ) {
            $swift_perform_ex_paths['exclude-strings'] = array( '/wp-content/uploads/woo-product-feed-pro' );
            update_option( 'swift_performance_options', $swift_perform_ex_paths, false );
        }

        return false;
    }

    /**
     * Exclude Feed URL from being cached by Comet Cache
     *
     * @return false
     */
    public function comet_cache() {
        if ( ! is_plugin_active( 'comet-cache/comet-cache.php' ) ) {
            return false;
        }

        $comet_cache_settings = maybe_unserialize( get_option( 'comet_cache_options' ) );

        if ( $comet_cache_settings && isset( $comet_cache_settings['exclude_uris'] ) ) {
            $exclude_uris = $comet_cache_settings['exclude_uris'];
            if ( ! str_contains( $exclude_uris, '/wp-content/uploads/woo-product-feed-pro' ) ) {
                $exclude_uris                        .= "\n/wp-content/uploads/woo-product-feed-pro";
                $comet_cache_settings['exclude_uris'] = $exclude_uris;
                update_option( 'comet_cache_options', $comet_cache_settings, false );
            }
        }

        return false;
    }

    /**
     * Exclude Feed URL from being cached by Hyper Caching
     *
     * @return false
     */
    public function hyper_cache() {

        if ( ! class_exists( 'HyperCache' ) ) {
            return false;
        }

        $hyper_cache_settings = maybe_unserialize( get_option( 'hyper-cache' ) );
        if ( $hyper_cache_settings && isset( $hyper_cache_settings['reject_uris'] ) ) {
            $exclude_strings = $hyper_cache_settings['reject_uris'];
            if ( is_array( $exclude_strings ) && ! in_array( '/wp-content/uploads/woo-product-feed-pro', $exclude_strings, true ) ) {
                $woo_feed_ex_path['reject_uris']         = array( '/wp-content/uploads/woo-product-feed-pro' );
                $woo_feed_ex_path['reject_uris_enabled'] = 1;
                $hyper_cache_settings                    = array_merge_recursive(
                    $hyper_cache_settings,
                    $woo_feed_ex_path
                );
            }
            update_option( 'hyper-cache', $hyper_cache_settings, false );
        }

        return false;
    }
}
