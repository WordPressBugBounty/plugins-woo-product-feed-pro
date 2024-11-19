<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Factories\Product_Feed_Query;
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Helpers\Product_Feed_Helper;
use AdTribes\PFP\Traits\Singleton_Trait;
/**
 * Product Feed Cron class.
 *
 * @since 13.3.5
 */
class Cron extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Get the amount of products in the feed file.
     *
     * @param string       $file        The file path.
     * @param string       $file_format The file format.
     * @param Product_Feed $feed        The feed data object.
     *
     * @return int The amount of products in the feed file.
     */
    private function get_product_counts_from_file( $file, $file_format, $feed ) {
        $products_count = 0;

        // Check if file exists.
        if ( ! file_exists( $file ) ) {
            return $products_count;
        }

        switch ( $file_format ) {
            case 'xml':
                $xml          = simplexml_load_file( $file, 'SimpleXMLElement', LIBXML_NOCDATA );
                $feed_channel = $feed->get_channel();

                if ( 'Yandex' === $feed_channel['name'] ) {
                    $products_count = isset( $xml->offers->offer ) && is_countable( $xml->offers->offer ) ? count( $xml->offers->offer ) : 0;
                } elseif ( 'none' === $feed_channel['taxonomy'] ) {
                    $products_count = isset( $xml->product ) && is_countable( $xml->product ) ? count( $xml->product ) : 0;
                } else {
                    $products_count = isset( $xml->channel->item ) && is_countable( $xml->channel->item ) ? count( $xml->channel->item ) : 0;
                }

                break;
            case 'csv':
            case 'txt':
            case 'tsv':
                $products_count = count( file( $file ) ) - 1; // -1 for the header.
                break;
        }

        /**
         * Filter the amount of history products in the system report.
         *
         * @since 13.3.5
         *
         * @param int          $products_count The amount of products in the feed file.
         * @param string       $file           The file path.
         * @param string       $file_format    The file format.
         * @param Product_Feed $feed           The feed data object.
         */
        return apply_filters( 'adt_product_feed_history_count', $products_count, $file, $file_format, $feed );
    }

    /**
     * Update product feed.
     *
     * This method is used to update the product feed after generating the products from the legacy code base.
     *
     * @since 13.3.5
     * @access public
     *
     * @param int $feed_id     Feed ID.
     * @param int $batch_size  Offset step size.
     */
    public function update_product_feed( $feed_id, $batch_size ) {
        $feed = Product_Feed_Helper::get_product_feed( $feed_id );
        if ( ! Product_Feed_Helper::is_a_product_feed( $feed ) && ! $feed->id ) {
            return false;
        }

        // User would like to see a preview of their feed, retrieve only 5 products by default.
        $preview_count = $feed->create_preview ? apply_filters( 'adt_product_feed_preview_products', 5, $feed ) : null;

        // Get total of published products to process.
        $published_products = $preview_count ? $preview_count : Product_Feed_Helper::get_total_published_products( $feed->include_product_variations );

        /**
         * Filter the total number of products to process.
         *
         * @since 13.3.5
         *
         * @param int $published_products Total number of published products to process.
         * @param \AdTribes\PFP\Factories\Product_Feed $feed The product feed instance.
         */
        $published_products = apply_filters( 'adt_product_feed_total_published_products', $published_products, $feed );

        // Update the feed with the total number of products.
        $feed->products_count           = intval( $published_products );
        $feed->total_products_processed = min( $feed->total_products_processed + $batch_size, $feed->products_count );

        /**
         * Batch processing.
         *
         * If the batch size is less than the total number of published products, then we need to create a batch.
         * The batching logic is from the legacy code base as it's has the batch size.
         * We need to refactor this logic so it's not stupid.
         */
        if ( $feed->total_products_processed >= $published_products || $batch_size >= $published_products ) { // End of processing.
            $upload_dir = wp_upload_dir();
            $base       = $upload_dir['basedir'];
            $path       = $base . '/woo-product-feed-pro/' . $feed->file_format;
            $tmp_file   = $path . '/' . sanitize_file_name( $feed->file_name ) . '_tmp.' . $feed->file_format;
            $new_file   = $path . '/' . sanitize_file_name( $feed->file_name ) . '.' . $feed->file_format;

            // Move the temporary file to the final file.
            if ( copy( $tmp_file, $new_file ) ) {
                wp_delete_file( $tmp_file );
            }

            // Set status to ready.
            $feed->status = 'ready';

            // Set counters back to 0.
            $feed->total_products_processed = 0;

            // Set last updated date and time.
            $feed->last_updated = gmdate( 'd M Y H:i:s' );
        }

        // Save feed changes.
        $feed->save();

        if ( 'ready' === $feed->status ) {
            // Check the amount of products in the feed and update the history count.
            as_schedule_single_action( time() + 1, ADT_PFP_AS_PRODUCT_FEED_UPDATE_STATS, array( 'feed_id' => $feed->id ) );
        } else {
            // Set the next scheduled event.
            $feed->run_batch_event();
        }
    }

    /**
     * Get total published products.
     *
     * @param Product_Feed $feed The product feed instance.
     *
     * @return int
     */
    private function _get_total_published_products( $feed ) {
        // Get total of published products to process.
        if ( $feed->create_preview ) {
            // User would like to see a preview of their feed, retrieve only 5 products by default.
            $published_products = apply_filters( 'adt_product_feed_preview_products', 5, $feed );
        } elseif ( $feed->include_product_variations ) {
            $published_products = Product_Feed_Helper::get_total_published_products( true );
        } else {
            $published_products = Product_Feed_Helper::get_total_published_products();
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

    /***************************************************************************
     * Action Scheduler
     * **************************************************************************
     */

    /**
     * Generate product feed callback.
     *
     * @since 13.3.9
     * @access public
     *
     * @param int $feed_id The feed ID.
     */
    public function as_generate_product_feed_callback( $feed_id ) {
        $feed = Product_Feed_Helper::get_product_feed( $feed_id );
        if ( ! $feed->id ) {
            return;
        }

        Product_Feed_Helper::disable_cache();

        $feed->run_batch_event();
    }

    /**
     * Process product feed in batch.
     *
     * @since 13.3.9
     * @access public
     *
     * @param int $feed_id The feed ID.
     */
    public function as_generate_product_feed_batch_callback( $feed_id ) {
        $feed = Product_Feed_Helper::get_product_feed( $feed_id );
        if ( $feed->id ) {
            /**
             * Check if the feed is stopped.
             *
             * If in the middle of processing a feed and the feed is stopped by the user.
             * This is to avoid the feed from continuing to process when the user has stopped it.
             */
            if ( 'stopped' === $feed->status ) {
                return;
            }

            $feed->status      = 'processing';
            $get_product_class = new \WooSEA_Get_Products();
            $get_product_class->woosea_get_products( $feed );
        }
    }

    /**
     * Set project history: amount of products in the feed.
     *
     * @since 13.3.5
     * @access public
     *
     * @param int $feed_id The Feed ID.
     **/
    public function as_product_feed_update_stats( $feed_id ) {
        $feed = Product_Feed_Helper::get_product_feed( $feed_id );
        if ( ! $feed->id ) {
            return;
        }

        // Filter the amount of history products in the system report.
        $max_history_products = apply_filters( 'adt_product_feed_max_history_products', 10 );

        $products_count = 0;
        $file           = $feed->get_file_path();
        $file_format    = $feed->file_format;
        $products_count = file_exists( $file ) ? $this->get_product_counts_from_file( $file, $file_format, $feed ) : 0;

        $feed->add_history_product( $products_count );
        $feed->save();
    }


    /**
     * Run the class
     *
     * @codeCoverageIgnore
     * @since 13.3.5
     */
    public function run() {
        add_action( 'adt_after_product_feed_generation', array( $this, 'update_product_feed' ), 10, 2 );

        // Action Scheduler.
        add_action( ADT_PFP_AS_GENERATE_PRODUCT_FEED, array( $this, 'as_generate_product_feed_callback' ), 1, 1 );
        add_action( ADT_PFP_AS_GENERATE_PRODUCT_FEED_BATCH, array( $this, 'as_generate_product_feed_batch_callback' ), 1, 1 );
        add_action( ADT_PFP_AS_PRODUCT_FEED_UPDATE_STATS, array( $this, 'as_product_feed_update_stats' ), 1, 1 );
    }
}
