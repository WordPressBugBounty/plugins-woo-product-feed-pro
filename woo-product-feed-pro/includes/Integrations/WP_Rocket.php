<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Integrations
 */

namespace AdTribes\PFP\Integrations;

use AdTribes\PFP\Abstracts\Abstract_Class;

/**
 * WP_Rocket class.
 *
 * @since 13.3.5.2
 */
class WP_Rocket extends Abstract_Class {

    /**
     * Check if WP Rocket plugin is active.
     *
     * @since 13.3.5.2
     * @return bool
     */
    public function is_active() {
        return defined( 'WP_ROCKET_FILE' ) || class_exists( 'WP_Rocket' );
    }

    /**
     * Exclude Product Feed urls from WP Rocket preload.
     *
     * @since 13.3.5.2
     * @acceess public
     *
     * @param array $regexes Array of regexes to exclude from WP Rocket preload.
     * @return array
     */
    public function preload_exclude_urls( $regexes ) {
        $regexes[] = '/wp-content/uploads/woo-product-feed-pro/(.*)';
        $regexes[] = '/\.well-known/ucp(.*)';
        return $regexes;
    }

    /**
     * Exclude UCP protocol URLs from the WP Rocket page cache.
     *
     * The UCP profile controls its own Cache-Control headers and the
     * checkout endpoints are dynamic — a cached copy would serve stale
     * signatures and totals to Google's agents.
     *
     * @since 13.6.0
     *
     * @param array $uris URIs excluded from caching.
     * @return array
     */
    public function cache_reject_uri( $uris ) {
        $uris[] = '/\.well-known/ucp';
        return $uris;
    }

    /**
     * Run WP Rocket integration hooks.
     *
     * @since 13.3.5.2
     */
    public function run() {
        if ( ! $this->is_active() ) {
            return;
        }

        add_filter( 'rocket_preload_exclude_urls', array( $this, 'preload_exclude_urls' ) );
        add_filter( 'rocket_cache_reject_uri', array( $this, 'cache_reject_uri' ) );
    }
}
