<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Traits\Singleton_Trait;

/**
 * Shipping_Data class.
 *
 * @since 13.3.4
 */
class Google_Product_Taxonomy_Fetcher extends Abstract_Class {

    use Singleton_Trait;

    const GOOGLE_PRODUCT_TAXONOMY_FILE_NAME = 'taxonomy-with-ids.en-US.txt';

    const GOOGLE_PRODUCT_TAXONOMY_SERVER_URL = 'https://adtribes.io/' . self::GOOGLE_PRODUCT_TAXONOMY_FILE_NAME;

    const GOOGLE_PRODUCT_TAXONOMY_FILE_PATH = WP_CONTENT_DIR . '/uploads/woo-product-feed-pro/' . self::GOOGLE_PRODUCT_TAXONOMY_FILE_NAME;

    const GOOGLE_PRODUCT_TAXONOMY_FILE_URL = WP_CONTENT_URL . '/uploads/woo-product-feed-pro/' . self::GOOGLE_PRODUCT_TAXONOMY_FILE_NAME;

    const GOOGLE_PRODUCT_TAXONOMY_AJAX_ACTION = 'adt_pfp_get_google_product_taxonomy';

    const GOOGLE_PRODUCT_TAXONOMY_TRANSIENT = 'adt_pfp_google_product_taxonomy';

    /**
     * Register the action.
     *
     * @since 13.3.4
     */
    public function register_action() {
        $this->unregister_action();

        // Start at random time today at midnight in range of 1-3 hours.
        $timestamp = strtotime( gmdate( 'Y-m-d 00:00:00', strtotime( '+1 day' ) ) ) + wp_rand( 1 * HOUR_IN_SECONDS, 3 * HOUR_IN_SECONDS );

        // Schedule the Action Scheduler event.
        as_schedule_recurring_action(
            $timestamp,
            WEEK_IN_SECONDS,
            ADT_PFP_AS_FETCH_GOOGLE_PRODUCT_TAXONOMY,
            array(),
            '',
            true
        );
    }

    /**
     * Unregister the action.
     *
     * @since 13.3.4
     */
    public function unregister_action() {
        as_unschedule_all_actions( ADT_PFP_AS_FETCH_GOOGLE_PRODUCT_TAXONOMY );
    }

    /**
     * Fetch Google Product Taxonomy.
     *
     * @since 13.3.4
     */
    public function as_fetch_google_product_taxonomy() {
        $body = $this->fetch_remote_taxonomy();
        if ( empty( $body ) ) {
            return;
        }

        global $wp_filesystem;

        // Initialize the WordPress filesystem.
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // Initialize the WordPress filesystem, return if failed.
        if ( ! WP_Filesystem() ) {
            return;
        }

        // Define the file path and ensure directory exists.
        $upload_dir = WP_CONTENT_DIR . '/uploads/woo-product-feed-pro/';
        $file_path  = $upload_dir . self::GOOGLE_PRODUCT_TAXONOMY_FILE_NAME;

        // Create directory if it doesn't exist.
        if ( ! $wp_filesystem->is_dir( $upload_dir ) ) {
            $wp_filesystem->mkdir( $upload_dir, FS_CHMOD_DIR );
        }

        // Create and write to the file using WP_Filesystem.
        $wp_filesystem->put_contents( $file_path, $body, FS_CHMOD_FILE );
    }

    /**
     * Fetch the taxonomy list from the AdTribes server.
     *
     * @since 13.5.8
     *
     * @param int $timeout Seconds to wait for the response.
     * @return string The taxonomy list, or an empty string if the request failed.
     */
    public function fetch_remote_taxonomy( $timeout = 30 ) {
        $response = wp_remote_get(
            self::GOOGLE_PRODUCT_TAXONOMY_SERVER_URL,
            array(
                'timeout' => $timeout,
            )
        );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        // An error page answers with a body, so the status decides. Two callers
        // read this: the scheduled action writes the body to the cache file, and
        // the ajax handler returns it to the browser. Neither must get an error
        // page in place of the list.
        if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return '';
        }

        return (string) wp_remote_retrieve_body( $response );
    }

    /**
     * Get the taxonomy list.
     *
     * Reads the cache file. If the cache is missing or empty - for example when
     * the fetch on activation failed - this reads the list from the AdTribes
     * server and holds it in a transient. It does not write the cache file: the
     * scheduled action owns the cache, and a bad response here would otherwise
     * keep the site on a broken cache until that action runs again.
     *
     * @since 13.5.8
     *
     * @return string The taxonomy list, or an empty string if it is unavailable.
     */
    public function get_taxonomy_contents() {
        if ( $this->is_file_exists() ) {
            $contents = file_get_contents( self::GOOGLE_PRODUCT_TAXONOMY_FILE_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            if ( ! empty( $contents ) ) {
                return (string) $contents;
            }
        }

        /*
         * Hold the remote list in a transient. The cache file is missing in this
         * branch, so without the transient every view of the Category Mapping
         * tab would hold a PHP worker on an outbound request. The empty result
         * is held too, for a shorter time, so an AdTribes server that is down
         * cannot stall each view.
         */
        $cached = get_transient( self::GOOGLE_PRODUCT_TAXONOMY_TRANSIENT );
        if ( false !== $cached ) {
            return (string) $cached;
        }

        // A person waits for this request, so it gets a shorter timeout than the
        // scheduled action.
        $body = $this->fetch_remote_taxonomy( 10 );

        set_transient(
            self::GOOGLE_PRODUCT_TAXONOMY_TRANSIENT,
            $body,
            '' === $body ? 5 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS
        );

        return $body;
    }

    /**
     * AJAX handler that returns the taxonomy list to the Category Mapping tab.
     *
     * The browser cannot always read the cache file directly. Some hosts block
     * HTTP requests to .txt files below /uploads/, so that request fails before
     * WordPress runs. This endpoint gives the tab a transport that always
     * reaches WordPress.
     *
     * @since 13.5.8
     * @return void
     */
    public function ajax_get_google_product_taxonomy() {
        check_ajax_referer( 'adt_nonce', 'nonce' );

        // Use the same capability that gates the edit feed screen.
        $capability = apply_filters( 'adt_pfp_admin_capability', 'manage_options' );
        if ( ! current_user_can( $capability ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'You do not have permission to perform this action.', 'woo-product-feed-pro' ),
                )
            );
        }

        $contents = $this->get_taxonomy_contents();
        if ( '' === $contents ) {
            wp_send_json_error(
                array(
                    'message' => __( 'The Google product taxonomy is not available yet. Please try again later.', 'woo-product-feed-pro' ),
                )
            );
        }

        wp_send_json_success(
            array(
                'taxonomy' => $contents,
            )
        );
    }

    /**
     * Check if the file exists.
     *
     * @since 13.3.4
     *
     * @return bool
     */
    public function is_file_exists() {
        return file_exists( self::GOOGLE_PRODUCT_TAXONOMY_FILE_PATH );
    }

    /**
     * Check if the action is in progress.
     *
     * In case the fetch action is in progress or pending, and the user already in the category mapping page,
     * we should show a message to the user that the action is in progress.
     *
     * @since 13.3.4
     *
     * @return bool
     */
    public function is_fetching() {
        // Check if the file exists.
        if ( $this->is_file_exists() ) {
            return false;
        }

        // Check if the action is in progress.
        $action = as_get_scheduled_actions(
            array(
                'hook'   => ADT_PFP_AS_FETCH_GOOGLE_PRODUCT_TAXONOMY,
                'status' => array( \ActionScheduler_Store::STATUS_RUNNING ),
            )
        );
        if ( ! empty( $action ) ) {
            return true;
        }

        return false;
    }

    /**
     * Run the class
     *
     * @codeCoverageIgnore
     * @since 13.3.4
     */
    public function run() {
        // Action Scheduler.
        add_action( ADT_PFP_AS_FETCH_GOOGLE_PRODUCT_TAXONOMY, array( $this, 'as_fetch_google_product_taxonomy' ) );

        // AJAX endpoint that serves the taxonomy list to the Category Mapping tab.
        add_action( 'wp_ajax_' . self::GOOGLE_PRODUCT_TAXONOMY_AJAX_ACTION, array( $this, 'ajax_get_google_product_taxonomy' ) );
    }
}
