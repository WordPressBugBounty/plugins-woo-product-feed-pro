<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Classes\Feed_Writers\Feed_Writer_Parquet;
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
     * How long a run left in `processing` may go without batch activity before a
     * scheduled run may treat it as stuck and start over, in seconds.
     *
     * Generously above what a healthy run needs: batches aim to finish well
     * inside a minute, and Action Scheduler gives up on an action it never saw
     * finish after five. The threshold only has to outlast a slow host, because
     * every extra minute is a merchant waiting on a feed that really is stuck.
     *
     * @since 13.5.7
     */
    const STALE_RUN_THRESHOLD = 900;

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
            case 'jsonl':
                $products_count = $this->count_non_empty_lines( $file, false );
                break;
            case 'jsonl.gz':
                $products_count = $this->count_non_empty_lines( $file, true );
                break;
            case 'csv.gz':
            case 'tsv.gz':
                $line_count     = $this->count_non_empty_lines( $file, true );
                $products_count = $line_count > 0 ? $line_count - 1 : 0; // -1 for the header.
                break;
            case 'parquet':
                // Parquet keeps its row counts in the file footer, so this reads the
                // footer only and never walks the rows.
                $parquet_rows = Feed_Writer_Parquet::count_rows( $file );

                // The history wants a count, so a negative sentinel needs a substitute.
                // ROWS_UNKNOWN means the count could not be taken at all - an unloadable
                // library, or the support filter flipping off - which says nothing about
                // the file: reporting 0 there would record an empty history entry for a
                // feed that is fine, so fall back to the run's own product count. A
                // corrupt file really does hold no readable rows, so that stays 0.
                $products_count = Feed_Writer_Parquet::ROWS_UNKNOWN === $parquet_rows
                    ? (int) $feed->products_count
                    : max( 0, $parquet_rows );
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
     * Count non-empty lines in a file, streaming plain or gzip-compressed input.
     *
     * Streams line-by-line so large feed files do not need to be loaded
     * entirely into memory.
     *
     * @since 13.5.4
     *
     * @param string $file     The file path.
     * @param bool   $is_gzip  Whether the file is gzip-compressed.
     * @return int
     */
    private function count_non_empty_lines( $file, $is_gzip ) {
        $count  = 0;
        $handle = $is_gzip ? gzopen( $file, 'rb' ) : fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

        if ( false === $handle ) {
            return 0;
        }

        while ( ! ( $is_gzip ? gzeof( $handle ) : feof( $handle ) ) ) {
            $line = $is_gzip ? gzgets( $handle ) : fgets( $handle );
            if ( false === $line ) {
                break;
            }
            if ( '' !== trim( $line ) ) {
                ++$count;
            }
        }

        if ( $is_gzip ) {
            gzclose( $handle );
        } else {
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        }

        return $count;
    }

    /**
     * Get the Action Scheduler group that holds a feed's batch actions.
     *
     * @since 13.5.7
     * @access public
     *
     * @param int $feed_id The feed ID.
     * @return string
     */
    public static function get_batch_action_group( $feed_id ) {
        return 'adt_pfp_as_generate_product_feed_batch_' . $feed_id;
    }

    /**
     * Schedule the next batch.
     *
     * @since 13.4.1
     * @access public
     *
     * @param int    $feed_id    The feed ID.
     * @param int    $offset     The offset of the batch.
     * @param int    $batch_size The batch size.
     * @param string $run_id     The ID of the generation run this batch belongs to.
     * @return int The action ID.
     */
    public static function schedule_next_batch( $feed_id, $offset, $batch_size, $run_id = '' ) {
        // Set the next scheduled event.
        $action_id = as_schedule_single_action(
            time() + 1,
            ADT_PFP_AS_GENERATE_PRODUCT_FEED_BATCH,
            array(
                'feed_id'    => $feed_id,
                'offset'     => $offset,
                'batch_size' => $batch_size,
                'run_id'     => $run_id,
            ),
            self::get_batch_action_group( $feed_id ),
            false,
            5
        );

        return $action_id;
    }

    /***************************************************************************
     * Action Scheduler
     * **************************************************************************
     */

    /**
     * Load an existing feed for an Action Scheduler callback.
     *
     * `Product_Feed_Helper::get_product_feed()` answers two different questions
     * with one return value: it hands back a loaded feed for a real ID, `false`
     * for an ID that no longer resolves, and - for the integer `0` or `null` - a
     * brand new, unsaved feed object, because that is how the create paths ask
     * for a blank one. A callback that only tests `if ( ! $feed )` accepts that
     * blank object, and the first `save()` on it inserts a new `adt_product_feed`
     * post carrying nothing but the factory defaults. Repeated on a schedule that
     * is what fills a site with phantom feeds (issue #1189).
     *
     * A scheduled action always names an existing feed, so anything that does not
     * resolve to a saved feed is discarded here. A legacy project hash still
     * resolves, for a caller that schedules one.
     *
     * @since 13.6.0
     * @access private
     *
     * @param mixed $feed_id The feed ID carried by the scheduled action.
     * @return \AdTribes\PFP\Factories\Product_Feed|false The feed, or false when the ID names no feed.
     */
    private function get_existing_feed( $feed_id ) {
        if ( is_numeric( $feed_id ) ) {
            $feed_id = absint( $feed_id );
        }

        if ( empty( $feed_id ) ) {
            return false;
        }

        $feed = Product_Feed_Helper::get_product_feed( $feed_id );

        return $feed && $feed->id > 0 ? $feed : false;
    }

    /**
     * Generate product feed callback.
     *
     * @since 13.3.9
     * @access public
     *
     * @param int $feed_id The feed ID.
     */
    public function as_generate_product_feed_callback( $feed_id ) {
        $feed = $this->get_existing_feed( $feed_id );
        if ( ! $feed ) {
            return;
        }

        // Guard: a previous run is still in flight — skip unless it has genuinely stalled.
        if ( 'processing' === $feed->status ) {
            if ( ! $this->is_feed_run_stale( $feed ) ) {
                if ( function_exists( 'wc_get_logger' ) ) {
                    wc_get_logger()->warning(
                        'Skipping scheduled feed generation: previous run is still processing',
                        array(
                            'source'  => 'woo-product-feed-pro',
                            'feed_id' => $feed_id,
                        )
                    );
                }
                return;
            }

            // The run made no progress for longer than the threshold and has no
            // batch action left to carry it forward: it is stuck, so start over.
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->info(
                    'Feed appears stuck (processing with no batch activity), allowing reset',
                    array(
                        'source'      => 'woo-product-feed-pro',
                        'feed_id'     => $feed_id,
                        'last_active' => (int) $feed->batch_last_active,
                    )
                );
            }

            // Reset status so generate() won't be blocked by the guard.
            $feed->status = 'ready';
            $feed->save();
        }

        Product_Feed_Helper::prepare_for_generation();

        $feed->generate( 'schedule' );
    }

    /**
     * Process product feed in batch.
     *
     * @since 13.3.9
     * @access public
     *
     * @param int    $feed_id    The feed ID.
     * @param int    $offset     The offset of the batch.
     * @param int    $batch_size The batch size.
     * @param string $run_id     The generation run this batch was queued for.
     */
    public function as_generate_product_feed_batch_callback( $feed_id, $offset = 0, $batch_size = 0, $run_id = '' ) {
        $feed = $this->get_existing_feed( $feed_id );
        if ( ! $feed ) {
            return;
        }

        /**
         * Check if the feed is still in processing status.
         *
         * Skip if the feed was stopped by the user, or if it's no longer processing
         * (e.g., a stale batch from a previous run arriving after a new run completed).
         */
        if ( 'processing' !== $feed->status ) {
            return;
        }

        /**
         * Discard a batch belonging to a superseded run.
         *
         * The feed is processing again, but not necessarily the run that queued
         * this batch. Running it would append rows the current run's file already
         * holds and then save an offset measured against a different run.
         *
         * Only enforced when the action carries a run ID: batches queued before
         * this release do not, and are still allowed to finish their chain.
         */
        if ( '' !== (string) $run_id && (string) $run_id !== (string) $feed->batch_run_id ) {
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->warning(
                    'Skipping feed batch: it belongs to a superseded generation run',
                    array(
                        'source'         => 'woo-product-feed-pro',
                        'feed_id'        => $feed_id,
                        'offset'         => $offset,
                        'run_id'         => $run_id,
                        'current_run_id' => (string) $feed->batch_run_id,
                    )
                );
            }
            return;
        }

        $feed->run_batch_event( $offset, $batch_size, 'cron', $run_id );
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
        $feed = $this->get_existing_feed( $feed_id );
        if ( ! $feed ) {
            return;
        }

        $products_count = 0;
        $file           = $feed->get_file_path();
        $file_format    = $feed->file_format;
        $products_count = file_exists( $file ) ? $this->get_product_counts_from_file( $file, $file_format, $feed ) : 0;

        $feed->add_history_product( $products_count );
        $feed->save();

        // The only count taken off the finished file, and so the only one that answers
        // what the feed actually carries. The generation log reports products_count -
        // the products the run started from, before a single filter or rule ran - so a
        // merchant reading that log alone cannot tell how much of the catalogue reached
        // the file. Recorded in the same log, right after the figure is known.
        if ( 'yes' === get_option( 'adt_enable_logging', 'no' ) ) {
            $file_info   = array(
                'feed_id'          => $feed->id,
                'feed_title'       => $feed->title,
                'execution_date'   => current_time( 'Y-m-d H:i:s' ),
                'products_in_feed' => $products_count,
                'products_count'   => $feed->products_count,
                'file_format'      => $file_format,
                'action'           => 'Feed file counted',
            );
            $log_message = 'Product Feed File Counted: ' . print_r( $file_info, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

            $logger = new \WC_Logger();
            $logger->add( 'Product Feed Pro by AdTribes.io', $log_message, 'info' );
        }
    }


    /**
     * Whether a feed left in `processing` has genuinely stalled.
     *
     * A run counts as alive while Action Scheduler still holds a batch action for
     * the feed that is pending or running. Batches are chained one at a time, and
     * the action for the batch currently executing has already been flipped to
     * `in-progress` before it runs, so a running action is the only trace a run
     * mid-batch leaves behind - looking for pending actions alone reads a healthy
     * run as stuck and starts a second chain over the same file.
     *
     * When no such action exists the chain is broken, but that on its own is not
     * proof of a stall either: a batch has a brief gap between saving its progress
     * and queueing its successor. Recovery therefore also requires the run to have
     * gone quiet for longer than the staleness threshold.
     *
     * @since 13.5.7
     * @access private
     *
     * @param Product_Feed $feed The feed to check.
     * @return bool
     */
    private function is_feed_run_stale( $feed ) {
        if ( ! empty( $this->query_active_batch_actions( $feed->id ) ) ) {
            return false;
        }

        $last_active = (int) $feed->batch_last_active;

        // A run that never recorded any activity (it started before this release,
        // or died before its first batch) has nothing to wait for.
        if ( $last_active <= 0 ) {
            return true;
        }

        /**
         * Filter how long a run may go without batch activity before a scheduled
         * run may treat it as stuck and start over, in seconds.
         *
         * @since 13.5.7
         *
         * @param int          $threshold The staleness threshold in seconds.
         * @param Product_Feed $feed      The feed being checked.
         */
        $threshold = (int) apply_filters( 'adt_product_feed_stale_run_threshold', self::STALE_RUN_THRESHOLD, $feed );

        return ( time() - $last_active ) >= $threshold;
    }

    /**
     * Query the batch actions of a feed that are still pending or running.
     *
     * Matched by Action Scheduler group rather than by args. A batch action carries
     * `offset`, `batch_size` and `run_id` alongside `feed_id`, and an args query
     * compares the serialised args array in full unless partial matching is asked
     * for - so filtering on `feed_id` alone matched no action at all, healthy or
     * otherwise. The group is already per-feed, and is indexed.
     *
     * @since 13.5.4
     * @access private
     *
     * @param int $feed_id Feed ID.
     * @return array
     */
    private function query_active_batch_actions( $feed_id ) {
        return as_get_scheduled_actions(
            array(
                'hook'     => ADT_PFP_AS_GENERATE_PRODUCT_FEED_BATCH,
                'status'   => array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ),
                'group'    => self::get_batch_action_group( $feed_id ),
                'per_page' => 1,
            ),
            'ids'
        );
    }

    /**
     * Run the class
     *
     * @codeCoverageIgnore
     * @since 13.3.5
     */
    public function run() {
        // Action Scheduler.
        add_action( ADT_PFP_AS_GENERATE_PRODUCT_FEED, array( $this, 'as_generate_product_feed_callback' ), 1, 1 );
        add_action( ADT_PFP_AS_GENERATE_PRODUCT_FEED_BATCH, array( $this, 'as_generate_product_feed_batch_callback' ), 1, 4 );
        add_action( ADT_PFP_AS_PRODUCT_FEED_UPDATE_STATS, array( $this, 'as_product_feed_update_stats' ), 1, 1 );
    }
}
