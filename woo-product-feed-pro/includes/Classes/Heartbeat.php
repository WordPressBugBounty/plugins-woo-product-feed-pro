<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Helpers\Helper;
use AdTribes\PFP\Helpers\Product_Feed_Helper;
use AdTribes\PFP\Traits\Singleton_Trait;

/**
 * Heartbeat class.
 *
 * @since 13.3.5
 */
class Heartbeat extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Get product feed processing status.
     *
     * @since 13.3.5
     * @access public
     *
     * @return void
     */
    public function ajax_get_product_feed_processing_status() {
        if ( ! wp_verify_nonce( $_REQUEST['security'], 'woosea_ajax_nonce' ) ) {
            wp_send_json_error( __( 'Invalid security token', 'woo-product-feed-pro' ) );
        }

        if ( ! Helper::is_current_user_allowed() ) {
            wp_send_json_error( __( 'You do not have permission to manage product feed.', 'woo-product-feed-pro' ) );
        }

        if ( ! isset( $_POST['project_hashes'] ) || ! is_array( $_POST['project_hashes'] ) ) {
            wp_send_json_error( __( 'Invalid request.', 'woo-product-feed-pro' ) );
        }

        $project_hashes = array_map( 'sanitize_text_field', $_POST['project_hashes'] );
        $response       = array();

        foreach ( $project_hashes as $project_hash ) {
            $feed = Product_Feed_Helper::get_product_feed( $project_hash );

            if ( ! $feed->id ) {
                continue;
            }

            $proc_perc = $feed->get_processing_percentage();

            $response[] = array(
                'feed_id'       => $feed->id,
                'hash'          => $project_hash,
                'status'        => $feed->status,
                'executed_from' => $feed->executed_from,
                'offset'        => $feed->total_products_processed,
                'batch_size'    => $feed->batch_size,
                'proc_perc'     => $proc_perc,
            );
        }

        if ( empty( $response ) ) {
            wp_send_json_error( __( 'Product feed(s) not found.', 'woo-product-feed-pro' ) );
        }

        wp_send_json_success( apply_filters( 'adt_product_feed_processing_status_response', $response, $feed ) );
    }

    /**
     * Generate product feed via AJAX.
     *
     * @since 13.4.1
     * @access public
     */
    public function ajax_generate_product_feed() {
        if ( ! wp_verify_nonce( $_REQUEST['security'], 'woosea_ajax_nonce' ) ) {
            wp_send_json_error( __( 'Invalid security token', 'woo-product-feed-pro' ) );
        }

        $feed_id    = sanitize_text_field( $_POST['feed_id'] );
        $offset     = sanitize_text_field( $_POST['offset'] );
        $batch_size = sanitize_text_field( $_POST['batch_size'] );

        $feed = Product_Feed_Helper::get_product_feed( $feed_id );
        if ( ! $feed->id ) {
            wp_send_json_error( __( 'Product feed not found.', 'woo-product-feed-pro' ) );
        }

        /**
         * Check if the feed is stopped.
         *
         * If in the middle of processing a feed and the feed is stopped by the user.
         * This is to avoid the feed from continuing to process when the user has stopped it.
         */
        if ( 'stopped' === $feed->status ) {
            wp_send_json_success(
                array(
                    'feed_id'    => $feed->id,
                    'offset'     => $offset,
                    'batch_size' => $batch_size,
                    'status'     => $feed->status,
                )
            );
        }

        $feed->run_batch_event( $offset, $batch_size, 'ajax' );
    }

    /**
     * Run the class
     *
     * @codeCoverageIgnore
     * @since 13.3.5
     */
    public function run() {
        add_action( 'wp_ajax_woosea_project_processing_status', array( $this, 'ajax_get_product_feed_processing_status' ) );

        add_action( 'wp_ajax_adt_pfp_generate_product_feed', array( $this, 'ajax_generate_product_feed' ) );
    }
}
