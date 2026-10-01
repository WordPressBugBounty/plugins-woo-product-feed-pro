<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Integrations
 */

namespace AdTribes\PFP\Integrations;

use AdTribes\PFP\Abstracts\Abstract_Class;

defined( 'ABSPATH' ) || exit;

/**
 * Caching class.
 *
 * Keeps the generated feed files out of the page caches of the third-party caching
 * plugins the feed can sit behind. Each method targets one plugin, writes that
 * plugin's real page-cache exclusion setting through the plugin's own save path, and
 * returns without writing when the exclusion is already present.
 *
 * Every method must stay idempotent: run() is reached on every feed refresh through
 * Product_Feed_Helper::prepare_for_generation(), from cron, the admin, and WP-CLI.
 *
 * @since 13.5.8
 */
class Caching extends Abstract_Class {

    /**
     * The upload path the generated feed files are written to.
     *
     * @since 13.5.8
     * @var string
     */
    const FEED_PATH = '/wp-content/uploads/woo-product-feed-pro';

    /**
     * The feed directory name.
     *
     * Used where a caching plugin matches on a path fragment rather than a full path.
     *
     * @since 13.5.8
     * @var string
     */
    const FEED_SLUG = 'woo-product-feed-pro';

    /**
     * Apply every caching-plugin exclusion.
     *
     * Each method guards on its own plugin being present, so they are safe to call
     * unconditionally.
     *
     * @since 13.5.8
     * @access public
     */
    public function run() {
        $this->litespeed_cache();
        $this->wp_fastest_cache();
        $this->wp_super_cache();
        $this->breeze_cache();
        $this->wp_optimize_cache();
        $this->cache_enabler_cache();
        $this->swift_performance_cache();
        $this->comet_cache();
        $this->hyper_cache();
    }

    /**
     * Exclude Feed URL from being cached by LiteSpeed
     *
     * NOTE: cdn-exc (Base::O_CDN_EXC) belongs to LiteSpeed's CDN module and only decides which
     * URLs skip CDN rewriting. It is not a page-cache exclusion list. The page-cache list is
     * cache-exc (Base::O_CACHE_EXC). The old code also read the option directly, which returns
     * an empty array on a default install, so its truthiness guard failed and nothing was ever
     * written. Read and write through Conf instead: it is LiteSpeed's documented save path and
     * it hides where each setting lives (7.x keeps one option per key, litespeed.conf.cache-exc).
     *
     * Conf::update() is deliberate over Conf::update_confs(): cache-exc is read live at request
     * time (control.cls.php), so the file and data sync update_confs() adds — rewriting
     * wp-config.php, .htaccess and object-cache.php, plus CDN sync — buys nothing here and is
     * unwanted from a cron or WP-CLI feed run. update() still purges the URLs that changed,
     * because that comparison lives inside update() itself. See issue #1070.
     */
    public function litespeed_cache() {
        if ( ! class_exists( 'LiteSpeed\Core' ) || ! defined( 'LSCWP_DIR' ) ) {
            return;
        }

        if ( ! class_exists( 'LiteSpeed\Conf' ) || ! class_exists( 'LiteSpeed\Base' ) ) {
            return;
        }

        $conf = \LiteSpeed\Conf::cls();

        if ( ! is_object( $conf ) || ! method_exists( $conf, 'conf' ) || ! method_exists( $conf, 'update' ) ) {
            return;
        }

        $feed_path = self::FEED_PATH;
        $cache_exc = $conf->conf( \LiteSpeed\Base::O_CACHE_EXC );
        $cache_exc = is_array( $cache_exc ) ? $cache_exc : array();

        if ( in_array( $feed_path, $cache_exc, true ) ) {
            return;
        }

        $cache_exc[] = $feed_path;

        $conf->update( \LiteSpeed\Base::O_CACHE_EXC, $cache_exc );
    }

    /**
     * Exclude Feed URL from being cached by WP Fastest
     */
    public function wp_fastest_cache() {

        if ( ! class_exists( 'WpFastestCache' ) ) {
            return;
        }

        $rules = json_decode( get_option( 'WpFastestCacheExclude' ), false );
        $rules = is_array( $rules ) ? $rules : array();

        foreach ( $rules as $rule ) {
            // Entries are stdClass rules, but the option is user-editable, so do not assume.
            if ( is_object( $rule ) && isset( $rule->content ) && self::FEED_SLUG === $rule->content ) {
                return;
            }
        }

        $new_rule          = new \stdClass();
        $new_rule->prefix  = 'contain';
        $new_rule->content = self::FEED_SLUG;
        $new_rule->type    = 'page';

        $rules[] = $new_rule;

        update_option( 'WpFastestCacheExclude', wp_json_encode( array_values( $rules ) ), false );
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
     */
    public function wp_super_cache() {

        if ( ! function_exists( 'wpsc_init' ) ) {
            return;
        }

        global $cache_rejected_uri, $wp_cache_config_file;

        // Both are provided by WP Super Cache once its config is loaded; bail if the
        // config-file write helper or the config path is unavailable.
        if ( ! function_exists( 'wp_cache_replace_line' ) || empty( $wp_cache_config_file ) ) {
            return;
        }

        $feed_path         = self::FEED_PATH;
        $wp_super_ex_paths = is_array( $cache_rejected_uri ) ? $cache_rejected_uri : array();

        if ( in_array( $feed_path, $wp_super_ex_paths, true ) ) {
            return;
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
    }

    /**
     * Exclude Feed URL from being cached by BREEZE
     *
     * NOTE: breeze_cdn_integration's cdn-exclude-content is Breeze's CDN URL-rewrite list
     * (see inc/cdn-integration/breeze-cdn-rewrite.php). It has no effect on whether a URL is
     * cached or served. Breeze rejects a URL from its page cache through breeze-exclude-urls
     * inside the breeze_advanced_settings option, which Breeze_ConfigCache compiles into the
     * on-disk config that inc/cache/execute-cache.php reads as $config['exclude_url']. The
     * option write alone is not enough — the on-disk config must be rebuilt too. See issue #1069.
     */
    public function breeze_cache() {

        if ( ! class_exists( 'Breeze_Admin' ) || ! class_exists( 'Breeze_ConfigCache' ) ) {
            return;
        }

        if ( ! function_exists( 'breeze_get_option' ) || ! function_exists( 'breeze_update_option' ) ) {
            return;
        }

        // Trailing (.*) matters. Breeze's own save runs esc_url( '/' . $item ) then
        // ltrim( $item, '/' ) (inc/breeze-configuration.php), which strips the leading
        // slash. Without it the entry drops out of the path-regex branch of
        // is_excluded_page() into a whole-URL equality compare that never matches a feed
        // file, so the exclusion would die the first time the owner saves the Advanced tab.
        $feed_path = self::FEED_PATH . '/(.*)';
        $advanced  = breeze_get_option( 'advanced_settings', true );

        if ( ! is_array( $advanced ) ) {
            return;
        }

        $exclude_urls = isset( $advanced['breeze-exclude-urls'] ) && is_array( $advanced['breeze-exclude-urls'] )
            ? $advanced['breeze-exclude-urls']
            : array();

        if ( in_array( $feed_path, $exclude_urls, true ) ) {
            return;
        }

        $exclude_urls[]                  = $feed_path;
        $advanced['breeze-exclude-urls'] = array_values( array_unique( $exclude_urls ) );

        breeze_update_option( 'advanced_settings', $advanced, true );

        // Rebuild the on-disk config, exactly as Breeze does after it saves this setting
        // itself (see save_advanced_settings() in inc/breeze-configuration.php).
        if ( method_exists( 'Breeze_ConfigCache', 'factory' ) ) {
            $config_cache = \Breeze_ConfigCache::factory();

            if ( method_exists( $config_cache, 'write' ) ) {
                $config_cache->write();
            }

            if ( method_exists( $config_cache, 'write_config_cache' ) ) {
                $config_cache->write_config_cache();
            }
        }

        // Drop anything already cached, or a copy stored before the exclusion existed keeps
        // being served. Breeze fires this itself at the end of the same save.
        do_action( 'breeze_clear_all_cache' );
    }

    /**
     * Exclude Feed URL from being cached by WP Optimize
     *
     * NOTE: wpo_cache_config's cache_exception_urls is the right setting, but a bare
     * update_option() does not reach the cache engine. WP-Optimize serves pages from an
     * advanced-cache.php drop-in that reads its config from a JSON file on disk, never from
     * the option (see cache/class-wpo-page-cache.php). Only WPO_Cache_Config::update()
     * rewrites that file. See issue #1071.
     */
    public function wp_optimize_cache() {

        if ( ! class_exists( 'WP_Optimize' ) || ! class_exists( 'WPO_Cache_Config' ) ) {
            return;
        }

        $cache_config = \WPO_Cache_Config::instance();

        if ( ! is_object( $cache_config ) || ! method_exists( $cache_config, 'get' ) || ! method_exists( $cache_config, 'update' ) ) {
            return;
        }

        $config = $cache_config->get();

        // Only act while page caching is on.
        if ( ! is_array( $config ) || empty( $config['enable_page_caching'] ) ) {
            return;
        }

        $feed_path      = self::FEED_PATH;
        $exception_urls = isset( $config['cache_exception_urls'] ) && is_array( $config['cache_exception_urls'] )
            ? $config['cache_exception_urls']
            : array();

        if ( in_array( $feed_path, $exception_urls, true ) ) {
            return;
        }

        $exception_urls[]               = $feed_path;
        $config['cache_exception_urls'] = $exception_urls;

        // The second argument keeps update() from creating the on-disk config when the cache
        // has never been switched on, which is what WP-Optimize itself passes when saving.
        $result = $cache_config->update( $config, true );

        // update() returns WP_Error when the on-disk config cannot be written. Without the
        // rewrite the outcome is the bug #1071 describes, so it must not fail silently.
        if ( is_wp_error( $result ) && function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->warning(
                'WP-Optimize cache config could not be written; the feed is not excluded from its page cache',
                array(
                    'source' => 'woo-product-feed-pro',
                    'error'  => $result->get_error_message(),
                )
            );
        }
    }

    /**
     * Exclude Feed URL from being cached by Cache Enabler
     *
     * NOTE: Cache Enabler passes excluded_page_paths straight into preg_match() and adds no
     * delimiters of its own (see inc/cache_enabler_engine.class.php), so the stored string
     * must already be a complete, valid pattern. The plain path '/wp-content/uploads/...'
     * read as delimiter + pattern + modifier flags, which made preg_match() warn and return
     * false on every request that ran the check. See issue #1072.
     */
    public function cache_enabler_cache() {

        if ( ! class_exists( 'Cache_Enabler' ) ) {
            return;
        }

        $cache_enabler_ex_paths = get_option( 'cache_enabler' );

        if ( ! is_array( $cache_enabler_ex_paths ) || ! isset( $cache_enabler_ex_paths['excluded_page_paths'] ) ) {
            return;
        }

        $feed_pattern = preg_quote( self::FEED_PATH . '/', '/' );
        $existing     = (string) $cache_enabler_ex_paths['excluded_page_paths'];

        if ( false !== strpos( $existing, $feed_pattern ) ) {
            return;
        }

        // Cache Enabler stores one pattern, so merge with the value the site owner already
        // set instead of writing only while the field is empty. validate_regex() rejects a
        // value that does not end in a delimiter, so a stored pattern carries no modifiers
        // and its inner part is safe to alternate with ours. See issue #1074.
        if ( '' !== $existing ) {
            $existing_pattern = preg_replace( '/^\/(.*)\/$/s', '$1', $existing );

            // A stored value that does not compile can only have come from the invalid
            // pattern this method wrote before #1072 — Cache Enabler's own sanitiser
            // discards anything invalid before it reaches the option. Replace it rather
            // than merge it forward, otherwise the merged result is invalid too and the
            // broken pattern survives on exactly the sites #1072 describes.
            if ( false === @preg_match( '/' . $existing_pattern . '/', '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an invalid stored pattern is the expected upgrade case; the warning is the signal, not an error to surface.
                $existing_pattern = '';
            }

            if ( '' !== $existing_pattern ) {
                $feed_pattern = '(?:' . $existing_pattern . ')|(?:' . $feed_pattern . ')';
            }
        }

        $excluded_page_paths = '/' . $feed_pattern . '/';

        // Cache Enabler discards an invalid pattern instead of storing it. Run the value
        // through the same check so a bad pattern never reaches the option.
        if ( method_exists( 'Cache_Enabler', 'validate_regex' ) ) {
            $excluded_page_paths = \Cache_Enabler::validate_regex( $excluded_page_paths );

            if ( '' === $excluded_page_paths ) {
                return;
            }
        }

        $cache_enabler_ex_paths['excluded_page_paths'] = $excluded_page_paths;

        // Cache Enabler hooks update_option and rebuilds its on-disk settings file from
        // this value (Cache_Enabler::on_update_option -> Cache_Enabler_Disk::create_settings_file).
        update_option( 'cache_enabler', $cache_enabler_ex_paths, false );
    }

    /**
     * Exclude Feed URL from being cached by Swift Performance
     *
     * NOTE: the old else-branch assigned exclude-strings a fresh array holding only the feed
     * path. It ran whenever the feed path was already present — the normal state from the
     * second feed run onward — so it wiped every exclusion the site owner had configured.
     * exclude-strings is read live at request time (modules/cache/cache.php), where a #...#
     * entry is a regex and anything else is a substring match, so the destroyed entries were
     * load-bearing. Append when absent, return when present, and never assign the list
     * wholesale. See issue #1124.
     */
    public function swift_performance_cache() {

        // Swift Performance Lite 2.x defines Swift_Performance, not Swift_Performance_Lite —
        // that name appears nowhere in the plugin, so the old guard never passed and this
        // integration never ran. Both names are accepted in case an older build used the
        // other one. See issue #1124.
        if ( ! class_exists( 'Swift_Performance' ) && ! class_exists( 'Swift_Performance_Lite' ) ) {
            return;
        }

        $swift_perform_ex_paths = get_option( 'swift_performance_options' );
        $swift_perform_ex_paths = is_array( $swift_perform_ex_paths ) ? $swift_perform_ex_paths : array();

        $exclude_strings = isset( $swift_perform_ex_paths['exclude-strings'] ) && is_array( $swift_perform_ex_paths['exclude-strings'] )
            ? $swift_perform_ex_paths['exclude-strings']
            : array();

        if ( in_array( self::FEED_PATH, $exclude_strings, true ) ) {
            return;
        }

        $exclude_strings[] = self::FEED_PATH;

        $swift_perform_ex_paths['exclude-strings'] = array_values( $exclude_strings );

        update_option( 'swift_performance_options', $swift_perform_ex_paths, false );
    }

    /**
     * Exclude Feed URL from being cached by Comet Cache
     *
     * NOTE: exclude_uris is the right setting and the newline-delimited format is right, but
     * the option write does not reach the cache engine. Comet Cache compiles exclude_uris
     * into a regex and bakes it into wp-content/advanced-cache.php when that file is built
     * (see addAdvancedCache() in src/includes/traits/Plugin/InstallUtils.php). The drop-in
     * never reads the option at request time. Comet Cache also keeps its options in a site
     * option, so update_option() was the wrong write on multisite. See issue #1075.
     */
    public function comet_cache() {
        if ( ! is_plugin_active( 'comet-cache/comet-cache.php' ) ) {
            return;
        }

        $comet_cache = isset( $GLOBALS['comet_cache'] ) && is_object( $GLOBALS['comet_cache'] ) ? $GLOBALS['comet_cache'] : null;

        if ( null === $comet_cache
            || ! method_exists( $comet_cache, 'getOptions' )
            || ! method_exists( $comet_cache, 'updateOptions' )
            || ! method_exists( $comet_cache, 'addAdvancedCache' ) ) {
            return;
        }

        $comet_cache_settings = $comet_cache->getOptions();

        if ( ! is_array( $comet_cache_settings ) || ! isset( $comet_cache_settings['exclude_uris'] ) ) {
            return;
        }

        $feed_path    = self::FEED_PATH;
        $exclude_uris = (string) $comet_cache_settings['exclude_uris'];

        if ( str_contains( $exclude_uris, $feed_path ) ) {
            return;
        }

        $exclude_uris = '' === trim( $exclude_uris ) ? $feed_path : $exclude_uris . "\n" . $feed_path;

        $comet_cache->updateOptions( array( 'exclude_uris' => $exclude_uris ) );

        // Only rebuild while caching is switched on. addAdvancedCache() blanks the drop-in
        // before it rewrites it, and Comet Cache's own save calls it inside the same guard —
        // so running it with caching off re-creates a file the plugin deliberately emptied.
        if ( empty( $comet_cache_settings['enable'] ) ) {
            return;
        }

        // Rebuild wp-content/advanced-cache.php so the compiled regex picks the feed up,
        // exactly as Comet Cache does after it saves its own settings (see Actions.php).
        // A failure leaves the drop-in blank and page caching off, so do not discard it.
        if ( ! $comet_cache->addAdvancedCache() && function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->warning(
                'Comet Cache advanced-cache.php could not be rebuilt; the feed is not excluded from its page cache',
                array( 'source' => 'woo-product-feed-pro' )
            );
        }

        // Drop anything already cached, as Comet Cache does on its own save.
        if ( method_exists( $comet_cache, 'clearCache' ) ) {
            $comet_cache->clearCache();
        }
    }

    /**
     * Exclude Feed URL from being cached by Hyper Caching
     *
     * NOTE: array_merge_recursive() turns a key into an array of both values when each side
     * holds a scalar under that key, so reject_uris_enabled could become array( 0, 1 ) on any
     * install where something had already stored it as a scalar. Direct assignment removes
     * that class of corruption. See issue #1076.
     *
     * Two claims in #1076 did not hold when checked against Hyper Cache 3.4.4, so do not rely
     * on them: the plugin does not call checked() anywhere (the checkbox renders through
     * `if ( ! empty( $value ) )`, which an array satisfies), and its own settings screen cannot
     * produce the scalar-0 precondition, because an unchecked HTML checkbox posts nothing and
     * the key is unset instead. reject_uris is also read only on a cache miss, at
     * template_redirect (plugin.php); the advanced-cache.php drop-in never consults it.
     */
    public function hyper_cache() {

        if ( ! class_exists( 'HyperCache' ) ) {
            return;
        }

        $hyper_cache_settings = get_option( 'hyper-cache' );

        if ( ! is_array( $hyper_cache_settings ) || ! isset( $hyper_cache_settings['reject_uris'] ) ) {
            return;
        }

        $feed_path   = self::FEED_PATH;
        $reject_uris = is_array( $hyper_cache_settings['reject_uris'] ) ? $hyper_cache_settings['reject_uris'] : array();

        if ( in_array( $feed_path, $reject_uris, true ) ) {
            return;
        }

        $reject_uris[] = $feed_path;

        // Assign both keys directly so each one keeps its expected shape.
        $hyper_cache_settings['reject_uris']         = array_values( $reject_uris );
        $hyper_cache_settings['reject_uris_enabled'] = 1;

        update_option( 'hyper-cache', $hyper_cache_settings, false );
    }
}
