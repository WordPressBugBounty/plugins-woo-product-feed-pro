<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Factories
 */

namespace AdTribes\PFP\Factories;

use AdTribes\PFP\Helpers\Product_Feed_Helper;
use AdTribes\PFP\Classes\Cron;

/**
 * Class Product_Feed.
 *
 * @since 13.3.5
 */
class Product_Feed {

    /**
     * Custom post type.
     */
    const POST_TYPE = 'adt_product_feed';

    /**
     * Upload sub directory.
     */
    const UPLOAD_SUB_DIR = 'woo-product-feed-pro';

    /**
     * Meta prefix.
     */
    const META_PREFIX = 'adt_';

    /**
     * Adaptive batch sizing tuning constants.
     *
     * The batch size self-tunes toward a fraction of a host-derived time budget,
     * growing gently (capped per step) and shrinking hard under time/memory pressure.
     *
     * @since 13.5.7
     */
    const ADAPTIVE_HARD_CAP_SECONDS                = 60;    // A single batch should never target more than this.
    const ADAPTIVE_MIN_SECONDS                     = 10;    // Floor for the per-batch time budget.
    const ADAPTIVE_TARGET_RATIO                    = 0.6;   // Aim each batch at this fraction of the budget.
    const ADAPTIVE_MAX_GROWTH                      = 2.0;   // Never grow the batch by more than this factor per step.
    const ADAPTIVE_MIN_BATCH                       = 50;    // Lower clamp for the adaptive batch size.
    const ADAPTIVE_MAX_BATCH                       = 25000; // Upper clamp for the adaptive batch size.
    const ADAPTIVE_MEMORY_RATIO                    = 0.8;   // Shrink hard if peak memory exceeds this fraction of the limit.
    const ADAPTIVE_UNLIMITED_MEMORY_FALLBACK_BYTES = 1073741824; // 1 GB ceiling used when memory_limit is -1 (unlimited).
    const ADAPTIVE_COLD_START_MAX                  = 500;   // First-run probe cap: a cold start never begins above this size.
    const ADAPTIVE_CEILING_RECOVERY                = 1.25;  // A crash-learned ceiling grows by this factor per completed run.
    const ADAPTIVE_MAX_CRASHES                     = 3;     // Park scheduled generation after this many consecutive crashed runs.

    /**
     * Option that lists feeds parked after repeated crashed runs, keyed by feed
     * ID. Read by the admin notice; entries are cleared when a run completes.
     */
    const ADAPTIVE_BLOCKED_OPTION = 'adt_pfp_adaptive_blocked_feeds';

    /**
     * ID for this object.
     *
     * @var int
     */
    protected $id = 0;

    /**
     * Title for this object.
     *
     * @var string
     */
    protected $title = '';

    /**
     * Post status for this object.
     *
     * @var string
     */
    protected $post_status = 'publish';

    /**
     * ID for this object.
     *
     * @var int
     */
    protected $post_type = self::POST_TYPE;

    /**
     * The context for the query.
     *
     * @since 13.3.5
     * @var string
     */
    protected $context = 'view';

    /**
     * Flag to track if shutdown handler is registered.
     *
     * @since 13.4.6
     * @var bool
     */
    private static $shutdown_handler_registered = false;

    /**
     * The feed ID and batch size currently being processed in this request,
     * or null when no batch is in flight. Static because Action Scheduler can
     * chain several batch actions - possibly for different feeds - through one
     * request, while the shutdown handler is only registered once with the
     * first feed instance. Lets the handler attribute a fatal (OOM/timeout)
     * to the batch that actually crashed.
     *
     * @since 13.5.7
     * @var array|null
     */
    private static $current_run = null;

    /**
     * Per-instance memo for is_adaptive_batch_enabled(). The answer cannot change
     * within a single request, and the method is called several times across a
     * generate()/run_batch_event() cycle. Null until first resolved.
     *
     * @since 13.5.7
     * @var bool|null
     */
    private $adaptive_batch_enabled_memo = null;

    /**
     * Per-batch memo for is_first_write_of_run(). The writers ask more than once
     * while assembling a single batch - the XML writer alone asks three times -
     * and every call within a batch must give the same answer, because the first
     * one claims the temp file for the run. Deliberately per-instance rather than
     * static: Action Scheduler can chain several batches through one request, and
     * each of those gets its own feed instance, so only the first batch of a run
     * may answer yes. Null until first resolved.
     *
     * @since 13.5.7
     * @var bool|null
     */
    private $first_write_of_run = null;

    /**
     * Stores product data.
     *
     * @var array
     */
    protected $data = array(
        'status'                                 => '',
        'products_count'                         => 0,
        'total_products_processed'               => 0,
        'batch_size'                             => 0,
        // Resume point of the run in progress: the ( post_date, ID ) sort key of the last
        // row the previous batch walked. Empty means "start at the top of the catalogue".
        'batch_cursor'                           => array(),
        'adaptive_last_batch_size'               => 0,
        'adaptive_batch_ceiling'                 => 0,
        'adaptive_batch_attempt'                 => 0,
        'adaptive_crash_count'                   => 0,
        // Identifies the generation run in flight. Every batch action carries the
        // run ID it was queued for, so work left over from a superseded run can be
        // recognised and discarded instead of corrupting the run that replaced it.
        'batch_run_id'                           => '',
        // Unix timestamp of the last batch activity in the run, which is what tells
        // a healthy in-flight run apart from one that has genuinely stalled.
        'batch_last_active'                      => 0,
        'executed_from'                          => '',
        'country'                                => '',
        'channel_hash'                           => '',
        'channel'                                => array(),
        'file_name'                              => '',
        'file_format'                            => 'xml',
        'file_url'                               => '',
        'delimiter'                              => '',
        'refresh_interval'                       => '',
        'refresh_only_when_product_changed'      => false,
        'create_preview'                         => false,
        'include_product_variations'             => false,
        'only_include_default_product_variation' => false,
        'only_include_lowest_product_variation'  => false,
        'include_all_shipping_countries'         => false,
        'utm_enabled'                            => true,
        'utm_source'                             => '',
        'utm_medium'                             => '',
        'utm_campaign'                           => '',
        'utm_term'                               => '',
        'utm_content'                            => '',
        'utm_total_product_orders_lookback'      => '',
        'attributes'                             => array(),
        'mappings'                               => array(),
        'rules'                                  => array(),
        'filters'                                => array(),
        'feed_filters'                           => array(),
        'feed_rules'                             => array(),
        'history_products'                       => array(),
        'ship_suffix'                            => false,
        'last_updated'                           => '',
        'legacy_project_hash'                    => '', // Backward compatibility.
        'data_version'                           => array(),
    );

    /**
     * Constructor.
     *
     * @param int|string|WP_Post $feed Feed ID, project hash (legacy) or WP_Post object.
     * @param string             $context Either 'view' or 'edit'.
     */
    public function __construct( $feed = 0, $context = 'view' ) {
        $this->context = $context;

        if ( is_numeric( $feed ) && $feed > 0 ) {
            $this->id = absint( $feed );
        } elseif ( is_string( $feed ) && ! empty( $feed ) ) {
            $this->id = self::get_feed_id_by_project_hash( $feed );
        } elseif ( $feed instanceof \WP_Post ) {
            $this->id = absint( $feed->ID );
        } elseif ( $feed instanceof self || ! empty( $feed->id ) ) {
            $this->id = absint( $feed->id );
        }

        // Set default data and merge with extra data.
        $this->data = array_merge(
            $this->data,
            $this->extra_data(),
            apply_filters( 'adt_product_feed_data', array() ) // Third party integration.
        );

        // Load feed data if ID is set.
        if ( $this->id > 0 ) {
            $this->load();
        }
    }

    /**
     * Get class property.
     *
     * @since 13.3.5
     * @access public
     *
     * @param string $key Property name.
     * @throws \Exception If property does not exist.
     * @return null|mixed
     */
    public function __get( $key ) {
        if ( array_key_exists( $key, $this->data ) ) {
            return $this->data[ $key ];
        } elseif ( property_exists( $this, $key ) ) { // Check if property exists in the class.
            return $this->$key;
        } else {
            throw new \Exception( 'Trying to access unknown property ' . esc_html( $key ) . ' on Product_Feed instance.' );
        }
    }

    /**
     * Set class property.
     *
     * @since 13.3.5
     * @access public
     *
     * @param string $key Property name.
     * @param mixed  $value Property value.
     * @throws \Exception If property does not exist.
     */
    public function __set( $key, $value ) {
        if ( array_key_exists( $key, $this->data ) ) {
            $this->data[ $key ] = $value;
        } elseif ( in_array( $key, array( 'id', 'title', 'post_status' ), true ) ) {
            $this->$key = $value;
        } else {
            // Handle the case where the property does not exist.
            // For example, you can throw an exception or ignore it.
            throw new \Exception( 'Property ' . esc_html( $key ) . ' does not exist on ' . esc_html( get_class( $this ) ) );
        }
    }

    /**
     * Set a collection of props in one go, collect any errors, and return the result.
     * Only sets using public methods.
     *
     * @since 13.3.5
     * @access public
     *
     * @param array $props Key value pairs to set. Key is the prop and should map to a setter function name.
     */
    public function set_props( $props ) {
        foreach ( $props as $prop => $value ) {
            // Checks if the value is not null.
            if ( is_null( $value ) ) {
                continue;
            }
            $this->set_prop( $prop, $value );
        }
    }

    /**
     * Sets prop for the product feed object.
     *
     * @since 13.3.5
     * @access public
     *
     * @param string $prop Name of prop to set.
     * @param mixed  $value Value of the prop.
     * @throws \Exception If property does not exist.
     */
    public function set_prop( $prop, $value ) {
        if ( array_key_exists( $prop, $this->data ) ) {
            if ( is_bool( $this->data[ $prop ] ) ) {
                if ( is_string( $value ) ) {
                    $value = ( 'true' === strtolower( $value ) || 'yes' === strtolower( $value ) ) ? true : false;
                } else {
                    $value = (bool) $value;
                }
            } elseif ( is_int( $this->data[ $prop ] ) ) {
                $value = absint( $value );
            }
            $this->data[ $prop ] = $value;
        } elseif ( in_array( $prop, array( 'id', 'title', 'post_status' ), true ) ) {
            $this->$prop = $value;
        } else {
            throw new \Exception( 'Trying to set unknown property ' . esc_html( $prop ) . ' on Product_Feed instance.' );
        }
    }

    /**
     * Save product feed.
     *
     * @since 13.3.5
     * @access public
     *
     * @throws \Exception If error saving product feed.
     * @return int|WP_Error
     */
    public function save() {
        $post_id = 0;

        if ( $this->id > 0 ) {
            $post_id = wp_update_post(
                array(
                    'ID'          => $this->id,
                    'post_title'  => $this->title,
                    'post_status' => $this->post_status,
                )
            );
        } else {
            $post_id = wp_insert_post(
                array(
                    'post_title'  => $this->title,
                    'post_status' => $this->post_status,
                    'post_type'   => self::POST_TYPE,
                )
            );
        }

        if ( is_wp_error( $post_id ) ) {
            throw new \Exception( esc_html( 'Error saving product feed: ' . $post_id->get_error_message() ) );
        }

        $this->id = absint( $post_id );

        // Update meta data.
        $this->save_meta_data();

        // Save legacy options.
        $this->save_legacy_options();

        return $this->id;
    }

    /**
     * Save product feed meta data.
     *
     * @since 13.3.5
     * @access public
     */
    public function save_meta_data() {
        /**
         * Exclude data from saving.
         *
         * `channel` and `file_url` are derived rather than stored. The two run
         * fields are excluded because this method rewrites every key from whatever
         * the instance happens to hold, and a batch worker holds the snapshot it
         * loaded when its batch began: were they included, a worker whose run has
         * since been replaced would hand ownership of the feed back to its own
         * dead run on save. They are written directly by the code that owns the
         * run instead - see start_run() and touch_run_activity().
         */
        $meta_keys = array_diff( array_keys( $this->data ), array( 'channel', 'file_url', 'batch_run_id', 'batch_last_active' ) );

        foreach ( $meta_keys as $key ) {
            if ( isset( $this->data[ $key ] ) ) {
                $value = $this->data[ $key ];
                if ( is_bool( $value ) ) {
                    $value = $value ? 'yes' : 'no';
                }

                // Filter meta value.
                $value = $this->_filter_meta_value( $value, $key );

                update_post_meta( $this->id, self::META_PREFIX . $key, $value );
            }
        }
    }

    /**
     * Filter meta value.
     *
     * @since 13.3.5
     * @access private
     *
     * @param mixed  $value Meta value.
     * @param string $key Meta key.
     *
     * @return mixed
     */
    private function _filter_meta_value( $value, $key ) {
        switch ( $key ) {
            case 'filters':
            case 'rules':
                $value = $this->_filter_feed_filters_mapping_meta_value( $value );
                break;
        }
        return $value;
    }

    /**
     * Filter feed filter mapping meta value.
     *
     * @since 13.3.5
     * @access private
     *
     * @param array $value Rules meta value.
     *
     * @return array
     */
    private function _filter_feed_filters_mapping_meta_value( $value ) {
        if ( ! is_array( $value ) || empty( $value ) ) {
            return $value;
        }

        foreach ( $value as $i => $rule ) {
            // Use array map to filter the rule values for 'condition' key!
            $value[ $i ]['condition'] = html_entity_decode( $rule['condition'] );
        }

        return $value;
    }

    /**
     * Load product feed data.
     *
     * @since 13.3.5
     * @access public
     */
    public function load() {
        $post = get_post( $this->id );
        if ( ! $post instanceof \WP_Post ) {
            $this->id = 0;
            return false;
        }

        $this->title       = $post->post_title;
        $this->post_status = $post->post_status;

        // Load meta data.
        $this->load_meta_data();

        // Set channel data.
        if ( '' !== $this->channel_hash ) {
            $this->data['channel'] = Product_Feed_Helper::get_channel_from_legacy_channel_hash( $this->channel_hash );
        }

        // Set file URL.
        if ( '' !== $this->file_name ) {
            $this->data['file_url'] = $this->get_file_url();
        }

        // Set default delimiter.
        if ( '' === $this->delimiter ) {
            $this->set_default_delimiter();
        }

        return true;
    }

    /**
     * Return extra data.
     *
     * @since 13.3.5
     * @access protected
     *
     * @return array Extra default data.
     */
    protected function extra_data() {
        return array();
    }

    /**
     * Load product feed meta data.
     *
     * @since 13.3.5
     * @access public
     */
    public function load_meta_data() {
        $post_meta_values = get_post_meta( $this->id );

        // Exclude data from loading.
        $meta_keys = array_diff( array_keys( $this->data ), array( 'channel', 'file_url' ) );

        foreach ( $meta_keys as $key ) {
            $meta_key = self::META_PREFIX . $key;
            if ( isset( $post_meta_values[ $meta_key ] ) ) {
                $meta_value = $post_meta_values[ $meta_key ][0] ? maybe_unserialize( $post_meta_values[ $meta_key ][0] ) : null;
                $this->set_prop( $key, $meta_value );
            }
        }
    }

    /**
     * Delete product feed.
     *
     * @since 13.3.5
     * @access public
     */
    public function delete() {
        $this->remove_file();
        $this->delete_legacy_options();
        $this->unregister_action();

        wp_delete_post( $this->id, true );
    }

    /**
     * Generate file.
     *
     * @since 13.3.5
     * @access public
     */
    public function remove_file() {
        $file_path = $this->get_file_path();

        if ( file_exists( $file_path ) ) {
            wp_delete_file( $file_path );
        }
    }

    /**
     * Set category mapping.
     *
     * @since 13.3.5
     * @access public
     *
     * @param array $mapping Category mapping.
     * @param int   $row     Row number.
     */
    public function set_mappings( $mapping, $row = null ) {
        if ( null !== $row ) {
            $this->data['mappings'][ $row ] = $mapping;
        } else {
            $this->data['mappings'] = $mapping;
        }
    }

    /**
     * Get product feed channel data.
     *
     * @since 13.3.5
     * @access public
     *
     * @param string $key Channel data key.
     *
     * @return array|string|null
     */
    public function get_channel( $key = null ) {
        // Get channel data by key.
        if ( null !== $key ) {
            return ! empty( $this->data['channel'] ) && isset( $this->data['channel'][ $key ] ) ? $this->data['channel'][ $key ] : '';
        }
        return $this->data['channel'];
    }

    /**
     * Get the base file format, stripping any .gz suffix.
     *
     * For compressed formats like 'jsonl.gz' or 'csv.gz', returns the underlying
     * format ('jsonl' or 'csv') used for directory naming and temp-file extensions.
     * Declared static so it can be reused across classes without duplicating
     * the stripping logic (e.g. Product_Feed::get_base_file_format( $feed->file_format )).
     *
     * @since 13.5.2
     * @access public
     *
     * @param string $format The file format string to evaluate.
     * @return string
     */
    public static function get_base_file_format( $format ) {
        if ( substr( $format, -3 ) === '.gz' ) {
            return substr( $format, 0, -3 );
        }
        return $format;
    }

    /**
     * Get product feed file format.
     *
     * @since 13.3.5
     * @access public
     *
     * @return string
     */
    public function get_file_url() {
        $upload_dir  = wp_upload_dir();
        $base_url    = set_url_scheme( $upload_dir['baseurl'], is_ssl() ? 'https' : 'http' );
        $base_format = self::get_base_file_format( $this->file_format );
        return $base_url . '/' . self::UPLOAD_SUB_DIR . '/' . $base_format . '/' . $this->file_name . '.' . $this->file_format;
    }

    /**
     * Get file path.
     *
     * @since 13.3.5
     * @access public
     *
     * @return string
     */
    public function get_file_path() {
        $upload_dir  = wp_upload_dir();
        $base_format = self::get_base_file_format( $this->file_format );
        return $upload_dir['basedir'] . '/' . self::UPLOAD_SUB_DIR . '/' . $base_format . '/' . $this->file_name . '.' . $this->file_format;
    }

    /**
     * Get temporary file path.
     *
     * The temp file always uses the base format extension (e.g. .jsonl not .jsonl.gz)
     * because compression happens after writing.
     *
     * Note: `file_name` is intentionally used raw (no `sanitize_file_name()`) — it is
     * generated from a controlled keyspace by `Product_Feed_Helper::generate_legacy_project_hash()`
     * and must match the value used by `get_file_path()` / `get_file_url()` byte-for-byte.
     * Any external source of `file_name` (import, migration) must sanitize at the boundary.
     *
     * @since 13.5.4
     * @access public
     *
     * @return string
     */
    public function get_temp_file_path() {
        $upload_dir  = wp_upload_dir();
        $base_format = self::get_base_file_format( $this->file_format );
        return $upload_dir['basedir'] . '/' . self::UPLOAD_SUB_DIR . '/' . $base_format . '/' . $this->file_name . '_tmp.' . $base_format;
    }

    /**
     * Get the feed file directory path.
     *
     * @since 13.5.4
     * @access public
     *
     * @return string
     */
    public function get_file_dir_path() {
        $upload_dir  = wp_upload_dir();
        $base_format = self::get_base_file_format( $this->file_format );
        return $upload_dir['basedir'] . '/' . self::UPLOAD_SUB_DIR . '/' . $base_format;
    }

    /**
     * Set default delimiter.
     *
     * @since 13.3.5.3
     * @access protected
     *
     * @return string
     */
    protected function set_default_delimiter() {
        $default_delimiter = '';
        switch ( $this->file_format ) {
            case 'tsv':
                return "\t";
            default:
                return ',';
        }

        $this->data['delimiter'] = $default_delimiter;
    }

    /**
     * Get product feed running process percentage.
     *
     * @since 13.3.5
     * @access public
     *
     * @return string
     */
    public function get_processing_percentage() {
        return 'processing' === $this->data['status'] && 0 < $this->data['products_count']
            ? round( ( $this->data['total_products_processed'] / $this->data['products_count'] ) * 100 )
            : 0;
    }

    /**
     * Get feed ID by project hash.
     *
     * @since 13.3.5
     * @access public
     *
     * @param string $project_hash Project hash.
     * @return int|bool
     */
    public function get_feed_id_by_project_hash( $project_hash ) {
        global $wpdb;

        $query = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} AS p
                LEFT JOIN {$wpdb->postmeta} AS pm
                    ON p.ID = pm.post_id
                WHERE p.post_type = %s 
                    AND pm.meta_key = %s
                    AND pm.meta_value = %s",
            self::POST_TYPE,
            'adt_legacy_project_hash',
            $project_hash
        );

        $result = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ( $result ) {
            return absint( $result );
        }

        return false;
    }

    /**
     * Get legacy country name.
     *
     * This method is used to get the legacy country name.
     * We used to store the country name in the codebase, but now use the country code available in WooCommerce.
     *
     * @since 13.3.5
     * @access public
     *
     * @return string
     */
    public function get_legacy_country() {
        $legacy_countries = include ADT_PFP_PLUGIN_DIR_PATH . 'includes/I18n/legacy_countries.php';
        return $legacy_countries[ $this->country ] ?? '';
    }

    /**
     * Add history product.
     *
     * @since 13.3.5
     * @access public
     *
     * @param int $products_count Products count.
     */
    public function add_history_product( $products_count ) {
        // Filter the amount of history products in the system report.
        $max_history_products = apply_filters( 'adt_product_feed_max_history_products', 10 );

        $count_timestamp = gmdate( 'd M Y H:i:s' );

        $this->data['history_products'][][ $count_timestamp ] = $products_count;

        // Remove old history products.
        if ( count( $this->data['history_products'] ) > $max_history_products ) {
            // trim the array to the max history products but preserve the last updated key.
            $this->data['history_products'] = array_slice( $this->data['history_products'], - $max_history_products, null, true );
        }
    }

    /**
     * Set the run-scoped fields that every start and end of a generation run touches.
     *
     * These four move as a set - how far the run got, the size it is working in, its
     * keyset resume point, and what started it - and are assigned at six sites (run
     * start, completion, caught error, the admin and WP-CLI cancel paths, and the
     * race-condition harness). Keeping the list here means a field added to the run
     * state is added once instead of in six places, one of which is easy to miss.
     *
     * Called with no arguments this clears the state, which is what a run that has
     * ended wants; generate() passes the size and context the new run starts with.
     * Status, last_updated and the adaptive counters differ per caller and stay with
     * the caller, as does the save() - callers set other fields alongside and persist
     * them in one write.
     *
     * @since 13.5.7
     * @access public
     *
     * @param int    $batch_size The batch size the run starts with, or 0 when clearing.
     * @param string $context    The context that started the run, or '' when clearing.
     * @return void
     */
    public function set_run_state( $batch_size = 0, $context = '' ) {
        $this->total_products_processed = 0;
        $this->batch_size               = (int) $batch_size;
        $this->batch_cursor             = array(); // A run never inherits another run's resume point.
        $this->executed_from            = $context;
    }

    /**
     * Put the feed into the state a user-cancelled run leaves it in.
     *
     * The admin AJAX handler and the WP-CLI command both cancel a run, and both have
     * to land on the same fields; keeping that set here means a field added to
     * "cancelled" is added once rather than in two files. Unscheduling the batches,
     * the surrounding do_action() hooks and the stats re-count stay with the callers,
     * as does the save() - each persists this alongside its own writes.
     *
     * @since 13.5.7
     * @access public
     *
     * @return void
     */
    public function cancel_run() {
        $this->set_run_state();
        $this->adaptive_batch_attempt = 0; // A user cancel is not a crash - clear the write-ahead marker.
        $this->status                 = 'stopped';
        $this->last_updated           = gmdate( 'd M Y H:i:s' );
    }

    /**
     * Generate product feed.
     *
     * @since 13.4.1
     * @access public
     *
     * @param string $context The context of the generation. 'schedule' or 'manual'.
     */
    public function generate( $context = 'schedule' ) {
        // Guard: skip if feed is already being processed to prevent concurrent generation.
        if ( 'processing' === $this->status ) {
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->warning(
                    'Skipping feed generation: feed is already processing',
                    array(
                        'source'  => 'woo-product-feed-pro',
                        'feed_id' => $this->id,
                        'context' => $context,
                    )
                );
            }
            return false;
        }

        if ( $this->is_adaptive_batch_enabled() ) {
            // The write-ahead marker is still set from the previous run: that
            // batch never survived, and the process died in a way no shutdown
            // handler could see (e.g. SIGKILL from a host process killer).
            // Learn from it before sizing this run.
            if ( $this->adaptive_batch_attempt > 0 ) {
                $this->learn_from_unclean_crash();
            }

            // After repeated crashed runs, stop burning the host with scheduled
            // retries: park the feed and surface an admin notice. A manual
            // refresh still runs, and a completed run resets the counter and
            // un-parks the feed.
            if ( 'schedule' === $context && $this->adaptive_crash_count >= self::ADAPTIVE_MAX_CRASHES ) {
                $this->save(); // Persist any crash-learning from above.
                self::flag_blocked_feed( $this->id, $this->title, (int) $this->adaptive_crash_count );

                if ( function_exists( 'wc_get_logger' ) ) {
                    wc_get_logger()->warning(
                        'Skipping scheduled feed generation: parked after repeated crashed runs',
                        array(
                            'source'      => 'woo-product-feed-pro',
                            'feed_id'     => $this->id,
                            'crash_count' => (int) $this->adaptive_crash_count,
                        )
                    );
                }
                return false;
            }
        }

        // Log when feed generation starts.
        $logging = get_option( 'adt_enable_logging', 'no' );
        if ( 'yes' === $logging ) {
            $start_info  = array(
                'feed_id'        => $this->id,
                'feed_title'     => $this->title,
                'execution_date' => current_time( 'Y-m-d H:i:s' ),
                'context'        => $context,
                'channel'        => $this->channel,
                'file_format'    => $this->file_format,
                'action'         => 'Feed generation started',
            );
            $log_message = 'Product Feed Generation Started: ' . print_r( $start_info, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

            $logger = new \WC_Logger();
            $logger->add( 'Product Feed Pro by AdTribes.io', $log_message, 'info' );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( $log_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }

        // Get the total number of products.
        $published_products = Product_Feed_Helper::get_feed_total_published_products( $this );
        $batch_size         = Product_Feed_Helper::get_batch_size( $this, $published_products );

        if ( $this->is_adaptive_batch_enabled() ) {
            if ( $this->adaptive_last_batch_size > 0 ) {
                // Warm-start from the size the previous run sustained, so a
                // repeat run doesn't re-learn the host's capacity each time.
                $batch_size = (int) $this->adaptive_last_batch_size;
            } else {
                // Cold start: probe with a conservative size. The controller
                // ramps up within a few batches, while a too-large first guess
                // on a weak host would fatal before it ever gets a sample.
                $batch_size = min( $batch_size, self::ADAPTIVE_COLD_START_MAX );
            }

            // Never start above a ceiling learned from a previous fatal (OOM /
            // execution timeout).
            if ( $this->adaptive_batch_ceiling > 0 ) {
                $batch_size = min( $batch_size, (int) $this->adaptive_batch_ceiling );
            }

            $batch_size = max( self::ADAPTIVE_MIN_BATCH, min( self::ADAPTIVE_MAX_BATCH, $batch_size ) );
        }

        // Set feed status to processing.
        $this->status = 'processing';

        // Stamp this run so its batches can be told apart from any earlier run's.
        $this->start_run();

        // Update the feed with the total number of products.
        $this->products_count = intval( $published_products );
        $this->set_run_state( $batch_size, $context );
        $this->save();

        return Cron::schedule_next_batch( $this->id, 0, $batch_size, $this->batch_run_id );
    }

    /**
     * Run batch event.
     *
     * @since 13.4.1
     * @access public
     *
     * @param int    $offset     The offset of the batch.
     * @param int    $batch_size The batch size.
     * @param string $context The context of the generation. 'ajax' or 'cron'.
     * @param string $run_id     The generation run this batch belongs to. Empty falls
     *                           back to the feed's current run, for callers that
     *                           continue a run rather than carrying its ID (AJAX).
     */
    public function run_batch_event( $offset = 0, $batch_size = 0, $context = '', $run_id = '' ) {
        // Register shutdown handler only once per request to avoid duplicate registrations.
        if ( ! self::$shutdown_handler_registered ) {
            register_shutdown_function( array( $this, 'handle_fatal_error' ), $context );
            self::$shutdown_handler_registered = true;
        }

        $run_id = '' !== (string) $run_id ? (string) $run_id : (string) $this->batch_run_id;

        // Nothing about a superseded run should reach the file: its rows would land
        // in the current run's output and its offsets are measured against a run
        // that no longer exists. Checked again after the batch, in case the takeover
        // lands while this one is working.
        if ( $this->is_run_superseded( $run_id ) ) {
            $this->log_superseded_batch( $offset, $run_id );
            return;
        }

        // Mark the run as alive before the work starts, so a batch that takes a
        // while is not mistaken for a stalled run by a scheduled run firing
        // alongside it.
        $this->touch_run_activity();

        try {
            // Log memory usage at the start of batch processing.
            $this->log_memory_usage( 'Batch start', $offset, $batch_size );

            // Check memory availability (logs warning if low, but doesn't prevent execution).
            $this->check_memory_availability();

            /**
             * Before product feed batch processing action.
             *
             * @since 13.5.1
             *
             * @param int    $feed_id    Feed ID.
             * @param int    $offset     Batch offset.
             * @param int    $batch_size Batch size.
             */
            do_action( 'adt_before_product_feed_batch_processing', $this->id, $offset, $batch_size );

            // Create the product class instance.
            $get_product_class = new \WooSEA_Get_Products();

            // Record the batch in flight so a fatal (OOM/timeout) can be
            // attributed to it by the shutdown handler. Carry the crashed feed's
            // identity too, so the fatal-error log names it rather than the first
            // feed instance the handler happened to be registered with.
            self::$current_run = array(
                'feed_id'         => $this->id,
                'batch_size'      => (int) $batch_size,
                'title'           => $this->title,
                'channel'         => $this->channel,
                'file_format'     => $this->file_format,
                'products_count'  => $this->products_count,
                'processed_count' => $this->total_products_processed,
            );

            // Write-ahead marker: persist the size being attempted BEFORE the
            // batch runs. A surviving batch clears it below; if the process
            // dies in a way no shutdown handler can see (e.g. SIGKILL from a
            // host process killer), the next generate() finds it still set and
            // treats it as a crash at this size.
            if ( $this->is_adaptive_batch_enabled() ) {
                $this->adaptive_batch_attempt = (int) $batch_size;
                update_post_meta( $this->id, self::META_PREFIX . 'adaptive_batch_attempt', (int) $batch_size );
            }

            // Reset the peak tracker where available (PHP 8.2+) so the reading
            // after the batch reflects THIS batch, not an earlier one in the
            // same request. On older PHP the baseline below serves instead.
            if ( function_exists( 'memory_reset_peak_usage' ) ) {
                memory_reset_peak_usage();
            }
            $peak_before = memory_get_peak_usage( true );

            // Time the batch so the size can adapt to what this host actually handled.
            $batch_started_at = microtime( true );

            // This is where errors might occur.
            $get_product_class->woosea_get_products( $this, $offset, $batch_size );

            $batch_elapsed = microtime( true ) - $batch_started_at;

            // The batch survived - a fatal from here on is not a batch-size
            // problem. Clear the stash and the write-ahead marker (the marker
            // is persisted by the save() below).
            self::$current_run            = null;
            $this->adaptive_batch_attempt = 0;

            // Stop if the run this batch belongs to has been superseded while the
            // batch was working. Both the progress counter this worker is about to
            // write and the next batch it would chain describe a run that no longer
            // exists, so saving either would overwrite the current run's state.
            if ( $this->is_run_superseded( $run_id ) ) {
                $this->log_superseded_batch( $offset, $run_id );

                // Clear the write-ahead marker this batch set, so the run that
                // replaced it is not charged with a crash at this batch size.
                if ( $this->is_adaptive_batch_enabled() ) {
                    update_post_meta( $this->id, self::META_PREFIX . 'adaptive_batch_attempt', 0 );
                }

                return;
            }

            // Log memory usage after processing.
            $this->log_memory_usage( 'Batch end', $offset, $batch_size );

            // Update the total number of products processed. This uses the size that
            // was just processed ($batch_size), not the next one.
            $offset_before                  = $this->total_products_processed;
            $this->total_products_processed = min( $offset_before + $batch_size, $this->products_count );
            $processed_this_batch           = $this->total_products_processed - $offset_before;

            // Decide the size for the NEXT batch. The completion/offset math above
            // intentionally still uses the size just processed.
            $next_batch_size = $batch_size;
            if ( $this->is_adaptive_batch_enabled() ) {
                $memory_pressed  = false;
                $next_batch_size = $this->calculate_next_batch_size( $batch_size, $batch_elapsed, $peak_before, $memory_pressed );

                // Warm-start only from a full batch that stayed within the time budget
                // AND did not hit memory pressure, so a repeat run never *starts* larger
                // than a size this host has actually sustained comfortably. A partial
                // final batch finishes fast and would otherwise over-project the
                // warm-start size; a memory-pressured batch was just halved for the next
                // step, so persisting its (pressured) size would warm-start into the same
                // pressure every run on a memory-constrained host.
                if ( $processed_this_batch >= $batch_size && $batch_elapsed <= $this->get_batch_time_budget() && ! $memory_pressed ) {
                    $this->adaptive_last_batch_size = $batch_size;
                }

                $this->log_adaptive_batch( $batch_size, $next_batch_size, $batch_elapsed );
            }

            /**
             * Batch processing.
             *
             * If the batch size is less than the total number of published products, then we need to create a batch.
             * The batching logic is from the legacy code base as it's has the batch size.
             * We need to refactor this logic so it's not stupid.
             */
            if ( $this->total_products_processed >= $this->products_count || $batch_size >= $this->products_count ) { // End of processing.
                // Set status to ready.
                $this->status = 'ready';

                // Set counters back to 0.
                $this->set_run_state();

                // A completed run is evidence the host handles the current
                // sizes: let a crash-learned ceiling recover gradually instead
                // of pinning the feed forever (AIMD across runs). Fully lifted
                // once it reaches the absolute maximum.
                if ( $this->is_adaptive_batch_enabled() && $this->adaptive_batch_ceiling > 0 ) {
                    $ceiling                      = (int) ceil( $this->adaptive_batch_ceiling * self::ADAPTIVE_CEILING_RECOVERY );
                    $this->adaptive_batch_ceiling = $ceiling >= self::ADAPTIVE_MAX_BATCH ? 0 : $ceiling;
                }

                // A completed run also clears crash tracking and un-parks the
                // feed from the repeated-crash admin notice.
                $this->adaptive_crash_count   = 0;
                $this->adaptive_batch_attempt = 0;
                self::unflag_blocked_feed( $this->id );

                // Set last updated date and time.
                $this->last_updated = gmdate( 'd M Y H:i:s' );
            }

            // Save feed changes.
            $this->save();

            if ( 'ready' === $this->status ) {
                // Log when feed generation ends.
                $logging = get_option( 'adt_enable_logging', 'no' );
                if ( 'yes' === $logging ) {
                    $end_info    = array(
                        'feed_id'         => $this->id,
                        'feed_title'      => $this->title,
                        'execution_date'  => current_time( 'Y-m-d H:i:s' ),
                        'context'         => $context,
                        'products_count'  => $this->products_count,
                        'processed_count' => $this->products_count, // All products processed when status is ready.
                        'channel'         => $this->channel,
                        'file_format'     => $this->file_format,
                        'file_url'        => $this->file_url,
                        'action'          => 'Feed generation completed',
                    );
                    $log_message = 'Product Feed Generation Completed: ' . print_r( $end_info, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

                    $logger = new \WC_Logger();
                    $logger->add( 'Product Feed Pro by AdTribes.io', $log_message, 'info' );

                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( $log_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    }
                }

                $this->move_feed_file_to_final();

                // Check the amount of products in the feed and update the history count.
                as_schedule_single_action( time() + 1, ADT_PFP_AS_PRODUCT_FEED_UPDATE_STATS, array( 'feed_id' => $this->id ) );

                /**
                 * After feed generation action.
                 */
                do_action( 'adt_after_product_feed_generation', $this->id, $offset, $batch_size );

                if ( 'ajax' === $context && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
                    wp_send_json_success(
                        array(
                            'feed_id'    => $this->id,
                            'offset'     => $this->total_products_processed,
                            'batch_size' => $batch_size,
                            'status'     => $this->status,
                        )
                    );
                }

                return;
            }

            // Run next batch event via AJAX or cron, using the adaptively-sized next batch.
            if ( 'ajax' === $context && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
                wp_send_json_success(
                    array(
                        'feed_id'    => $this->id,
                        'offset'     => $this->total_products_processed,
                        'batch_size' => $next_batch_size,
                        'status'     => $this->status,
                    )
                );
            } else {
                Cron::schedule_next_batch( $this->id, $this->total_products_processed, $next_batch_size, $run_id );
            }
        } catch ( \Throwable $e ) {

            // Log the error for debugging.
            $logging = get_option( 'adt_enable_logging', 'no' );
            if ( 'yes' === $logging ) {
                // Build comprehensive error information.
                $error_info  = array(
                    'feed_id'         => $this->id,
                    'feed_title'      => $this->title,
                    'execution_date'  => gmdate( 'Y-m-d H:i:s' ),
                    'context'         => $context,
                    'offset'          => $offset,
                    'batch_size'      => $batch_size,
                    'error_message'   => $e->getMessage(),
                    'error_code'      => $e->getCode(),
                    'error_file'      => $e->getFile(),
                    'error_line'      => $e->getLine(),
                    'products_count'  => $this->products_count,
                    'processed_count' => $this->total_products_processed,
                    'channel'         => $this->channel,
                    'file_format'     => $this->file_format,
                );
                $log_message = 'Product Feed Error: ' . print_r( $error_info, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

                $logger = new \WC_Logger();
                $logger->add( 'Product Feed Pro by AdTribes.io', $log_message, 'error' );

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( $log_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                }
            }

            // Set status to error.
            $this->status = 'error';

            // Set counters back to 0.
            $this->set_run_state();

            // A caught exception is a logic/data problem, not resource
            // exhaustion - clear the write-ahead marker so it is not counted
            // as a batch-size crash by the next run. Also clear the in-flight
            // stash (mirroring the success path) so a later unrelated fatal in
            // the same request is not misattributed to this batch by the
            // shutdown handler and used to halve its learned size.
            self::$current_run            = null;
            $this->adaptive_batch_attempt = 0;

            // Save feed changes.
            $this->save();

            // If this is an AJAX request, send back the error.
            if ( 'ajax' === $context && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
                wp_send_json_error(
                    array(
                        'feed_id' => $this->id,
                        'message' => $e->getMessage(),
                        'status'  => $this->status,
                    )
                );
            }
        }
    }

    /**
     * Take ownership of the feed for a new generation run.
     *
     * Written straight to meta rather than left to save(), because the blanket
     * meta save deliberately skips these fields - see save_meta_data().
     *
     * @since 13.5.7
     * @access private
     */
    private function start_run() {
        $this->data['batch_run_id'] = wp_generate_uuid4();
        update_post_meta( $this->id, self::META_PREFIX . 'batch_run_id', $this->data['batch_run_id'] );

        $this->touch_run_activity();
    }

    /**
     * Log a batch that was dropped because its run no longer owns the feed.
     *
     * @since 13.5.7
     * @access private
     *
     * @param int    $offset The offset of the discarded batch.
     * @param string $run_id The run ID the batch belonged to.
     */
    private function log_superseded_batch( $offset, $run_id ) {
        if ( ! function_exists( 'wc_get_logger' ) ) {
            return;
        }

        wc_get_logger()->warning(
            'Discarding feed batch: the generation run it belongs to has been superseded',
            array(
                'source'  => 'woo-product-feed-pro',
                'feed_id' => $this->id,
                'offset'  => $offset,
                'run_id'  => $run_id,
            )
        );
    }

    /**
     * Record that the generation run in flight is still making progress.
     *
     * Written straight to meta as well as to the in-memory data, because the
     * scheduled-run guard reads it from another request while this batch is still
     * executing - a value that only reaches the database when the batch finishes
     * would leave a long batch looking stalled for its whole duration.
     *
     * @since 13.5.7
     * @access private
     */
    private function touch_run_activity() {
        $now = time();

        $this->data['batch_last_active'] = $now;
        update_post_meta( $this->id, self::META_PREFIX . 'batch_last_active', $now );
    }

    /**
     * Whether the generation run a batch belongs to has been superseded.
     *
     * Reads the feed's current run ID straight from the database, bypassing the
     * object cache: the run may have been replaced by another request after this
     * one loaded the feed, which is precisely the case worth detecting.
     *
     * Busting the cache means dropping the feed post's whole meta cache, since
     * WordPress has no per-key invalidation - that is deliberate, not an oversight.
     * It costs one extra meta query per call, which at a couple of calls per batch
     * and roughly a batch a minute is not worth trading a correct read for.
     *
     * @since 13.5.7
     * @access private
     *
     * @param string $run_id The run ID the batch belongs to.
     * @return bool
     */
    private function is_run_superseded( $run_id ) {
        // A batch queued before run IDs existed cannot be placed in a run, so it
        // is given the benefit of the doubt rather than dropped.
        if ( '' === (string) $run_id ) {
            return false;
        }

        wp_cache_delete( $this->id, 'post_meta' );
        $current_run_id = (string) get_post_meta( $this->id, self::META_PREFIX . 'batch_run_id', true );

        return '' !== $current_run_id && $current_run_id !== (string) $run_id;
    }

    /**
     * Whether this batch is the first of its run to write the feed file.
     *
     * The temp file is appended to across the batches of a run, so exactly one
     * batch may start it: the first to write truncates any leftover file and lays
     * down the header or XML root, and the rest append. That used to be inferred
     * from `total_products_processed == 0`, which describes the feed's progress
     * counter rather than the file - so a run that started over while the counter
     * was non-zero appended to the previous run's rows instead of replacing them,
     * duplicating every product the earlier run had already written. The file now
     * records the run that owns it, and this returns true exactly when that
     * ownership is about to change.
     *
     * The owning run is kept in its own meta key rather than on the feed's data,
     * because it describes the file on disk and not the feed's configuration, and
     * must not be rewritten by an unrelated save().
     *
     * Note that this is side-effecting: the call that answers yes claims the file
     * for the run. It must therefore be called once per batch before any writer
     * runs - see woosea_get_products() - because most of the call sites ask behind
     * a file_exists() check and would skip the claim on the batch that creates the
     * file, leaving the next batch to truncate it.
     *
     * @since 13.5.7
     * @access public
     *
     * @return bool
     */
    public function is_first_write_of_run() {
        if ( null !== $this->first_write_of_run ) {
            return $this->first_write_of_run;
        }

        $run_id = (string) $this->batch_run_id;

        // A run started before this release has no ID to key the file on: fall
        // back to the progress counter, which is what gated truncation until now.
        // Memoised like the run-ID branch, so that the counter advancing as the
        // batch processes products cannot change the answer partway through it.
        if ( '' === $run_id ) {
            $this->first_write_of_run = 0 === (int) $this->total_products_processed;

            return $this->first_write_of_run;
        }

        $owner_run_id             = (string) get_post_meta( $this->id, self::META_PREFIX . 'temp_file_run_id', true );
        $this->first_write_of_run = $owner_run_id !== $run_id;

        if ( $this->first_write_of_run ) {
            // Claim the file for this run, so the batches that follow append to it.
            update_post_meta( $this->id, self::META_PREFIX . 'temp_file_run_id', $run_id );
        }

        return $this->first_write_of_run;
    }

    /**
     * Handle fatal errors during batch processing.
     *
     * @since 13.5.1
     * @access public
     *
     * @param string $context The context of the generation. 'ajax' or 'cron'.
     */
    public function handle_fatal_error( $context = '' ) {
        $error = error_get_last();

        // Check if this is a fatal error.
        if ( null === $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
            return;
        }

        // Attribute the fatal to the feed whose batch was actually in flight.
        // Action Scheduler can chain batches for different feeds through one
        // request, while this handler is only registered with the first
        // instance that processed a batch. Fall back to $this when no batch is
        // stashed (e.g. a fatal outside batch processing).
        $run     = is_array( self::$current_run ) ? self::$current_run : array();
        $feed_id = ! empty( $run['feed_id'] ) ? (int) $run['feed_id'] : $this->id;

        // Log the fatal error.
        $logging = get_option( 'adt_enable_logging', 'no' );
        if ( 'yes' === $logging ) {
            // Source the feed identity from the in-flight stash so the whole log
            // block describes the crashed feed, not the handler's registered instance.
            $error_info  = array(
                'feed_id'         => $feed_id,
                'feed_title'      => isset( $run['title'] ) ? $run['title'] : $this->title,
                'execution_date'  => gmdate( 'Y-m-d H:i:s' ),
                'context'         => $context,
                'error_type'      => $this->get_error_type_name( $error['type'] ),
                'error_message'   => isset( $error['message'] ) ? sanitize_text_field( $error['message'] ) : '',
                'error_file'      => isset( $error['file'] ) ? esc_html( $error['file'] ) : '',
                'error_line'      => isset( $error['line'] ) ? absint( $error['line'] ) : 0,
                'memory_usage'    => size_format( memory_get_usage( true ) ),
                'memory_limit'    => ini_get( 'memory_limit' ),
                'products_count'  => isset( $run['products_count'] ) ? $run['products_count'] : $this->products_count,
                'processed_count' => isset( $run['processed_count'] ) ? $run['processed_count'] : $this->total_products_processed,
                'channel'         => isset( $run['channel'] ) ? $run['channel'] : $this->channel,
                'file_format'     => isset( $run['file_format'] ) ? $run['file_format'] : $this->file_format,
            );
            $log_message = 'Product Feed Fatal Error: ' . print_r( $error_info, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

            // Use error_log directly as WC_Logger might fail in shutdown handler.
            error_log( $log_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }

        // Set status to error, then reset the run state. The batch cursor belongs to the
        // same teardown set as the counters: the batch that crashed never finished, so its
        // resume point is meaningless, and no resume path may inherit a stale mid-catalogue
        // cursor. (Today every restart goes through generate(), which clears it anyway -
        // that is the only reason a stale cursor here would be harmless rather than a bug.)
        //
        // These are direct database writes to avoid allocating in a shutdown handler that
        // may be running after an OOM.
        self::update_feed_meta_direct( $feed_id, 'status', 'error' );
        self::update_feed_meta_direct( $feed_id, 'total_products_processed', '0' );
        self::update_feed_meta_direct( $feed_id, 'batch_size', '0' );
        self::update_feed_meta_direct( $feed_id, 'executed_from', '' );
        self::update_feed_meta_direct( $feed_id, 'batch_cursor', maybe_serialize( array() ) );

        // Crash-learning: a fatal that lands here with a batch in flight is
        // resource exhaustion (OOM or execution timeout - anything catchable is
        // handled by the try/catch in run_batch_event, which clears the stash
        // once the batch survives). Halve the crashed size and persist it as
        // both the next warm-start and a ceiling the controller may not grow
        // past, so the next run converges below the crash point instead of
        // ramping straight back into it.
        if ( is_array( self::$current_run ) && ! empty( self::$current_run['batch_size'] ) ) {
            $safe_size = max( self::ADAPTIVE_MIN_BATCH, (int) floor( self::$current_run['batch_size'] / 2 ) );

            foreach ( array( 'adaptive_last_batch_size', 'adaptive_batch_ceiling' ) as $meta_key ) {
                self::update_feed_meta_direct( $feed_id, $meta_key, (string) $safe_size );
            }
        }

        // WP caches a post's meta as one array per post ID, so a single delete covers
        // every write above. Done once, at the end, rather than per write: under a
        // persistent object cache each delete is a network round-trip, and only the last
        // one would have mattered anyway - nothing repopulates the entry in between.
        wp_cache_delete( $feed_id, 'post_meta' );
    }

    /**
     * Write a single feed meta value straight to the database.
     *
     * For use from the shutdown handler only: update_post_meta() and this class' own
     * property setters both allocate, which is not safe in a handler that may be running
     * because the request ran out of memory.
     *
     * Unlike update_post_meta() a raw $wpdb->update() leaves the post-meta cache holding
     * the pre-crash value, so under a persistent object cache (Redis/Memcached) the next
     * request would read the state this teardown just cleared - for the batch cursor that
     * means resuming mid-catalogue and reintroducing the duplicated/missing rows of #1081.
     * Invalidating that cache is the caller's job: handle_fatal_error() writes several
     * keys in a row and drops the (single, per-post) cache entry once they are all done.
     *
     * @since 13.5.7
     * @access private
     *
     * @param int    $feed_id  The feed post ID.
     * @param string $meta_key The feed meta key, without the class' meta prefix.
     * @param string $value    The value to store, already serialized if it is not a scalar.
     */
    private static function update_feed_meta_direct( $feed_id, $meta_key, $value ) {
        global $wpdb;

        $wpdb->update(
            $wpdb->postmeta,
            array( 'meta_value' => $value ),
            array(
                'post_id'  => $feed_id,
                'meta_key' => self::META_PREFIX . $meta_key,
            ),
            array( '%s' ),
            array( '%d', '%s' )
        );
    }

    /**
     * Get error type name from error code.
     *
     * @since 13.5.1
     * @access private
     *
     * @param int $type Error type code.
     * @return string
     */
    private function get_error_type_name( $type ) {
        $error_types = array(
            E_ERROR             => 'E_ERROR',
            E_WARNING           => 'E_WARNING',
            E_PARSE             => 'E_PARSE',
            E_NOTICE            => 'E_NOTICE',
            E_CORE_ERROR        => 'E_CORE_ERROR',
            E_CORE_WARNING      => 'E_CORE_WARNING',
            E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
            E_USER_ERROR        => 'E_USER_ERROR',
            E_USER_WARNING      => 'E_USER_WARNING',
            E_USER_NOTICE       => 'E_USER_NOTICE',
            E_STRICT            => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED        => 'E_DEPRECATED',
            E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
        );

        return isset( $error_types[ $type ] ) ? $error_types[ $type ] : 'UNKNOWN';
    }

    /**
     * Log memory availability warnings for batch processing (non-blocking).
     *
     * Checks available memory and logs a warning if below threshold when logging is enabled.
     * Does not prevent feed generation from proceeding.
     *
     * @since 13.5.1
     * @access private
     */
    private function check_memory_availability() {
        $memory_limit = ini_get( 'memory_limit' );

        // If memory limit is -1, it's unlimited.
        if ( '-1' === $memory_limit ) {
            return;
        }

        // Convert memory limit to bytes.
        $memory_limit_bytes = $this->convert_to_bytes( $memory_limit );
        $memory_used        = memory_get_usage( true );
        $memory_available   = $memory_limit_bytes - $memory_used;

        // Log memory status for debugging purposes only - don't throw exceptions.
        $logging = get_option( 'adt_enable_logging', 'no' );
        if ( 'yes' === $logging ) {
            $threshold = 128 * 1024 * 1024; // 128MB in bytes.

            /**
             * Filter the memory threshold for warnings.
             *
             * @since 13.5.1
             *
             * @param int $threshold Memory threshold in bytes (default: 128MB).
             * @param int $feed_id Feed ID.
             */
            $threshold = apply_filters( 'adt_product_feed_memory_warning_threshold', $threshold, $this->id );

            if ( $memory_available < $threshold ) {
                $logger = wc_get_logger();
                $logger->warning(
                    'Low memory detected before batch processing',
                    array(
                        'source'           => 'woo-product-feed-pro',
                        'feed_id'          => $this->id,
                        'memory_available' => size_format( $memory_available ),
                        'memory_limit'     => size_format( $memory_limit_bytes ),
                        'memory_used'      => size_format( $memory_used ),
                        'threshold'        => size_format( $threshold ),
                    )
                );
            }
        }

        // Don't throw exceptions - let the shutdown handler catch actual memory exhaustion.
    }

    /**
     * Convert PHP memory limit notation to bytes.
     *
     * @since 13.5.1
     * @access private
     *
     * @param string $value Memory limit string (e.g., '512M', '2G').
     * @return int Memory in bytes.
     */
    private function convert_to_bytes( $value ) {
        $value = trim( $value );

        // Handle empty values.
        if ( empty( $value ) ) {
            return 0;
        }

        // Handle numeric-only values (assume bytes).
        if ( is_numeric( $value ) ) {
            return (int) $value;
        }

        // Extract the last character and convert to lowercase.
        $last  = strtolower( substr( $value, -1 ) );
        $value = (int) $value;

        switch ( $last ) {
            case 'g':
                $value *= 1024;
                // Fall through.
            case 'm':
                $value *= 1024;
                // Fall through.
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Log memory usage during batch processing.
     *
     * @since 13.5.1
     * @access private
     *
     * @param string $label      Label for the log entry.
     * @param int    $offset     Current offset.
     * @param int    $batch_size Batch size.
     */
    private function log_memory_usage( $label, $offset, $batch_size ) {
        $logging = get_option( 'adt_enable_logging', 'no' );
        if ( 'yes' !== $logging ) {
            return;
        }

        $memory_info = array(
            'feed_id'        => $this->id,
            'label'          => $label,
            'offset'         => $offset,
            'batch_size'     => $batch_size,
            'memory_current' => size_format( memory_get_usage() ),
            'memory_real'    => size_format( memory_get_usage( true ) ),
            'memory_peak'    => size_format( memory_get_peak_usage( true ) ),
            'memory_limit'   => ini_get( 'memory_limit' ),
        );

        $log_message = 'Product Feed Memory Usage: ' . print_r( $memory_info, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

        if ( function_exists( 'wc_get_logger' ) ) {
            $logger = wc_get_logger();
            $logger->debug( $log_message, array( 'source' => 'woo-product-feed-pro' ) );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( $log_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    /**
     * Whether adaptive batch sizing is enabled for this run.
     *
     * A user-pinned batch size (the adt_enable_batch / adt_batch_size options)
     * always wins and disables adaptation.
     *
     * @since 13.5.7
     * @access private
     *
     * @return bool
     */
    private function is_adaptive_batch_enabled() {
        if ( null !== $this->adaptive_batch_enabled_memo ) {
            return $this->adaptive_batch_enabled_memo;
        }

        $enabled = true;
        if ( 'yes' === get_option( 'adt_enable_batch', 'no' ) ) {
            $manual = get_option( 'adt_batch_size', '' );
            if ( ! empty( $manual ) && is_numeric( $manual ) ) {
                $enabled = false;
            }
        }

        if ( $enabled ) {
            /**
             * Filter whether adaptive batch sizing is enabled.
             *
             * @since 13.5.7
             *
             * @param bool $enabled Whether adaptive batch sizing is enabled. Default true.
             * @param int  $feed_id Feed ID.
             */
            $enabled = (bool) apply_filters( 'adt_product_feed_adaptive_batch_enabled', true, $this->id );
        }

        $this->adaptive_batch_enabled_memo = $enabled;

        return $enabled;
    }

    /**
     * Learn from a run that died without a trace.
     *
     * Called when generate() finds the write-ahead marker still set: the batch
     * it recorded never survived, and no shutdown handler ran (e.g. SIGKILL
     * from a host process killer). Applies the same halving as the shutdown
     * handler and merges with anything the handler may already have learned
     * (a PHP-visible fatal writes the same values, making this idempotent).
     *
     * The caller is responsible for persisting via save().
     *
     * @since 13.5.7
     * @access private
     */
    private function learn_from_unclean_crash() {
        $safe_size = max( self::ADAPTIVE_MIN_BATCH, (int) floor( $this->adaptive_batch_attempt / 2 ) );

        $this->adaptive_last_batch_size = $this->adaptive_last_batch_size > 0
            ? min( (int) $this->adaptive_last_batch_size, $safe_size )
            : $safe_size;
        $this->adaptive_batch_ceiling   = $this->adaptive_batch_ceiling > 0
            ? min( (int) $this->adaptive_batch_ceiling, $safe_size )
            : $safe_size;
        $this->adaptive_crash_count     = (int) $this->adaptive_crash_count + 1;
        $this->adaptive_batch_attempt   = 0;

        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->warning(
                'Previous feed generation run died unexpectedly; batch size reduced',
                array(
                    'source'      => 'woo-product-feed-pro',
                    'feed_id'     => $this->id,
                    'safe_size'   => $safe_size,
                    'crash_count' => (int) $this->adaptive_crash_count,
                )
            );
        }
    }

    /**
     * Record a feed as parked after repeated crashed runs, for the admin notice.
     *
     * @since 13.5.7
     * @access private
     *
     * @param int    $feed_id Feed ID.
     * @param string $title   Feed title.
     * @param int    $count   Consecutive crash count.
     */
    private static function flag_blocked_feed( $feed_id, $title, $count ) {
        $blocked = get_option( self::ADAPTIVE_BLOCKED_OPTION, array() );
        if ( ! is_array( $blocked ) ) {
            $blocked = array();
        }

        $blocked[ $feed_id ] = array(
            'title' => $title,
            'count' => $count,
        );

        update_option( self::ADAPTIVE_BLOCKED_OPTION, $blocked, false );
    }

    /**
     * Remove a feed from the parked-feeds record.
     *
     * @since 13.5.7
     * @access private
     *
     * @param int $feed_id Feed ID.
     */
    private static function unflag_blocked_feed( $feed_id ) {
        $blocked = get_option( self::ADAPTIVE_BLOCKED_OPTION, array() );
        if ( ! is_array( $blocked ) || ! isset( $blocked[ $feed_id ] ) ) {
            return;
        }

        unset( $blocked[ $feed_id ] );
        update_option( self::ADAPTIVE_BLOCKED_OPTION, $blocked, false );
    }

    /**
     * Host-derived per-batch time budget, in seconds.
     *
     * Ported from the pattern in StoreAgent's Data_Upload::get_upload_time_budget():
     * stay under the PHP execution ceiling and Action Scheduler's failure window,
     * capped and floored so every host makes forward progress.
     *
     * @since 13.5.7
     * @access private
     *
     * @return int The per-batch time budget in seconds.
     */
    private function get_batch_time_budget() {
        // PHP execution ceiling. 0 / negative means unlimited (common on CLI/cron).
        $max_execution = (int) ini_get( 'max_execution_time' );
        $php_budget    = $max_execution > 0 ? (int) floor( $max_execution * 0.7 ) : self::ADAPTIVE_HARD_CAP_SECONDS;

        // Action Scheduler marks an action failed once it runs past this window and
        // re-runs it, which would duplicate work. Stay well under it.
        $as_failure = (int) apply_filters( 'action_scheduler_failure_period', 5 * MINUTE_IN_SECONDS );
        $as_budget  = $as_failure > 0 ? (int) floor( $as_failure * 0.8 ) : self::ADAPTIVE_HARD_CAP_SECONDS;

        $budget = min( $php_budget, $as_budget, self::ADAPTIVE_HARD_CAP_SECONDS );

        // Floor for forward progress, but never above the PHP-derived ceiling on a
        // host with a very low max_execution_time.
        $floor  = $max_execution > 0 ? min( self::ADAPTIVE_MIN_SECONDS, $php_budget ) : self::ADAPTIVE_MIN_SECONDS;
        $budget = max( $floor, $budget );

        /**
         * Filter the per-batch time budget, in seconds, for feed generation.
         *
         * @since 13.5.7
         *
         * @param int $budget  The per-batch time budget in seconds.
         * @param int $feed_id Feed ID.
         */
        return max( 1, (int) apply_filters( 'adt_product_feed_batch_time_budget', $budget, $this->id ) );
    }

    /**
     * Compute the next batch size from the last batch's wall-time and memory use.
     *
     * Proportional controller: aim each batch at a target fraction of the time
     * budget, grow gently (capped per step to avoid overshoot), and shrink hard
     * under time or memory pressure.
     *
     * @since 13.5.7
     * @access private
     *
     * @param int   $current        The batch size that was just processed.
     * @param float $elapsed        Seconds the last batch took.
     * @param int   $peak_before    Peak memory in bytes recorded before the batch ran.
     * @param bool  $memory_pressed Set by reference to whether this batch hit the memory
     *                              guard, so the caller can avoid warm-starting from a
     *                              pressured size. Reflects the measured reading, not the
     *                              filtered next-size result.
     * @return int The next batch size.
     */
    private function calculate_next_batch_size( $current, $elapsed, $peak_before = 0, &$memory_pressed = false ) {
        $current = max( 1, (int) $current );
        $budget  = $this->get_batch_time_budget();
        $target  = max( 1, $budget * self::ADAPTIVE_TARGET_RATIO );

        // Memory guard: if this batch's peak approached the limit, shrink hard.
        $memory_pressed = false;
        $memory_limit   = $this->convert_to_bytes( ini_get( 'memory_limit' ) );

        // A memory_limit of -1 (unlimited) still runs on finite physical RAM, so fall
        // back to a sane absolute ceiling. Otherwise nothing but the time budget bounds
        // batch growth and a large batch can exhaust the host's real memory.
        if ( $memory_limit <= 0 ) {
            $memory_limit = self::ADAPTIVE_UNLIMITED_MEMORY_FALLBACK_BYTES;
        }

        /**
         * Filter the memory limit, in bytes, used by the adaptive memory guard.
         *
         * Return 0 to disable the guard entirely.
         *
         * @since 13.5.7
         *
         * @param int $memory_limit Memory limit in bytes.
         * @param int $feed_id      Feed ID.
         */
        $memory_limit = (int) apply_filters( 'adt_product_feed_memory_limit_bytes', $memory_limit, $this->id );

        // Only count pressure when THIS batch raised the peak: the tracker is
        // monotonic per process, and Action Scheduler can chain several batches
        // through one request - without this, one big early batch would look
        // like permanent pressure and shrink every batch after it. On PHP 8.2+
        // the tracker is also reset before each batch, making the reading
        // genuinely per-batch.
        $peak_now = memory_get_peak_usage( true );
        if ( $memory_limit > 0 && $peak_now >= $memory_limit * self::ADAPTIVE_MEMORY_RATIO && $peak_now > $peak_before ) {
            $memory_pressed = true;
        }

        if ( $memory_pressed ) {
            $next = (int) floor( $current / 2 );
        } elseif ( $elapsed <= 0 ) {
            // Too fast to measure — grow by the per-step cap.
            $next = (int) floor( $current * self::ADAPTIVE_MAX_GROWTH );
        } else {
            $next     = (int) round( $current * ( $target / $elapsed ) );
            $max_step = (int) floor( $current * self::ADAPTIVE_MAX_GROWTH );
            if ( $next > $max_step ) {
                $next = $max_step; // Cap growth per step to avoid overshoot.
            }
        }

        // Clamp to any crash-learned ceiling, then to sane bounds.
        if ( $this->adaptive_batch_ceiling > 0 ) {
            $next = min( $next, (int) $this->adaptive_batch_ceiling );
        }
        $next = max( self::ADAPTIVE_MIN_BATCH, min( self::ADAPTIVE_MAX_BATCH, $next ) );

        /**
         * Filter the adaptively-computed next batch size.
         *
         * @since 13.5.7
         *
         * @param int   $next    The next batch size.
         * @param int   $current The batch size just processed.
         * @param float $elapsed Seconds the last batch took.
         * @param int   $feed_id Feed ID.
         */
        return (int) apply_filters( 'adt_product_feed_adaptive_next_batch_size', $next, $current, $elapsed, $this->id );
    }

    /**
     * Log an adaptive batch-sizing decision (only when logging is enabled).
     *
     * @since 13.5.7
     * @access private
     *
     * @param int   $current The batch size just processed.
     * @param int   $next    The next batch size.
     * @param float $elapsed Seconds the last batch took.
     */
    private function log_adaptive_batch( $current, $next, $elapsed ) {
        if ( 'yes' !== get_option( 'adt_enable_logging', 'no' ) ) {
            return;
        }

        $info = array(
            'feed_id'         => $this->id,
            'batch_processed' => $current,
            'elapsed_seconds' => round( $elapsed, 3 ),
            'next_batch_size' => $next,
            'batch_ceiling'   => (int) $this->adaptive_batch_ceiling,
            'time_budget'     => $this->get_batch_time_budget(),
            'memory_peak'     => size_format( memory_get_peak_usage( true ) ),
            'memory_limit'    => ini_get( 'memory_limit' ),
        );

        $log_message = 'Product Feed Adaptive Batch: ' . print_r( $info, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->debug( $log_message, array( 'source' => 'woo-product-feed-pro' ) );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( $log_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    /**
     * Move the feed file to the final file.
     *
     * @since 13.4.1
     * @access public
     */
    public function move_feed_file_to_final() {
        $base_format = self::get_base_file_format( $this->file_format );
        $is_gz       = $base_format !== $this->file_format;

        $tmp_file = $this->get_temp_file_path();
        $new_file = $this->get_file_path();

        // Check if temporary file exists before attempting to copy.
        if ( ! file_exists( $tmp_file ) ) {
            if ( function_exists( 'wc_get_logger' ) ) {
                $logger = wc_get_logger();
                $logger->warning(
                    'Temporary feed file does not exist',
                    array(
                        'source'      => 'woo-product-feed-pro',
                        'feed_id'     => $this->id,
                        'feed_title'  => $this->title,
                        'tmp_file'    => $tmp_file,
                        'file_format' => $this->file_format,
                    )
                );
            }
            return;
        }

        // Validate the tmp file is not corrupt before overwriting the live feed.
        // Note: we only block on parse errors (-1). A count of 0 is allowed because:
        // - feeds may legitimately have 0 products after filtering, and
        // - some XML channel structures are not recognized by count_products_in_tmp_file().
        if ( 'xml' === $this->file_format ) {
            $tmp_product_count = $this->count_products_in_tmp_file( $tmp_file );
            if ( -1 === $tmp_product_count ) {
                if ( function_exists( 'wc_get_logger' ) ) {
                    wc_get_logger()->error(
                        'Refusing to overwrite live feed: tmp file is corrupt (parse error)',
                        array(
                            'source'         => 'woo-product-feed-pro',
                            'feed_id'        => $this->id,
                            'feed_title'     => $this->title,
                            'products_count' => $this->products_count,
                        )
                    );
                }
                // Delete the corrupt tmp file but preserve the existing live feed.
                wp_delete_file( $tmp_file );
                return;
            }
        }

        if ( $is_gz ) {
            // Compress the plain tmp file into a gzip-compressed final file.
            $gz_handle    = gzopen( $new_file, 'wb9' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            $plain_handle = fopen( $tmp_file, 'rb' );   // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

            if ( false === $gz_handle || false === $plain_handle ) {
                if ( $gz_handle ) {
                    gzclose( $gz_handle );
                }
                if ( $plain_handle ) {
                    fclose( $plain_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                }
                if ( function_exists( 'wc_get_logger' ) ) {
                    $logger = wc_get_logger();
                    $logger->error(
                        'Failed to open files for gzip compression',
                        array(
                            'source'      => 'woo-product-feed-pro',
                            'feed_id'     => $this->id,
                            'feed_title'  => $this->title,
                            'tmp_file'    => $tmp_file,
                            'new_file'    => $new_file,
                            'file_format' => $this->file_format,
                        )
                    );
                }
                return;
            }

            $write_error = false;
            while ( ! feof( $plain_handle ) ) {
                $data = fread( $plain_handle, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                if ( false === $data ) {
                    $write_error = true;
                    break;
                }
                $bytes_written = gzwrite( $gz_handle, $data );
                if ( false === $bytes_written || ( 0 === $bytes_written && strlen( $data ) > 0 ) ) {
                    $write_error = true;
                    break;
                }
            }

            fclose( $plain_handle );  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            gzclose( $gz_handle );

            if ( $write_error ) {
                wp_delete_file( $new_file );
                if ( function_exists( 'wc_get_logger' ) ) {
                    $logger = wc_get_logger();
                    $logger->error(
                        'Gzip compression failed during feed file write',
                        array(
                            'source'      => 'woo-product-feed-pro',
                            'feed_id'     => $this->id,
                            'feed_title'  => $this->title,
                            'tmp_file'    => $tmp_file,
                            'new_file'    => $new_file,
                            'file_format' => $this->file_format,
                        )
                    );
                }
                return;
            }

            wp_delete_file( $tmp_file );
            return;
        }

        // Format XML file with proper indentation before moving (for large feeds).
        if ( 'xml' === $this->file_format ) {
            $get_products = new \WooSEA_Get_Products();
            if ( ! $get_products->woosea_format_xml_file( $tmp_file ) ) {
                // Log warning but continue - unformatted XML is still valid.
                if ( function_exists( 'wc_get_logger' ) ) {
                    $logger = wc_get_logger();
                    $logger->warning(
                        'XML formatting failed, proceeding with unformatted file',
                        array(
                            'source'   => 'woo-product-feed-pro',
                            'feed_id'  => $this->id,
                            'tmp_file' => $tmp_file,
                        )
                    );
                }
            }
        }

        // Move the temporary file to the final file.
        if ( copy( $tmp_file, $new_file ) ) {
            wp_delete_file( $tmp_file );
        } elseif ( function_exists( 'wc_get_logger' ) ) {
            $logger = wc_get_logger();
            $logger->error(
                'Failed to copy temporary feed file to final location',
                array(
                    'source'      => 'woo-product-feed-pro',
                    'feed_id'     => $this->id,
                    'feed_title'  => $this->title,
                    'tmp_file'    => $tmp_file,
                    'new_file'    => $new_file,
                    'file_format' => $this->file_format,
                )
            );
        }
    }

    /**
     * Count products in a temporary XML feed file.
     *
     * @since 13.5.4
     * @access private
     *
     * @param string $file The temporary XML file path.
     * @return int The number of products found, or -1 on parse error.
     */
    private function count_products_in_tmp_file( $file ) {
        if ( ! file_exists( $file ) ) {
            return 0;
        }

        $xml = @simplexml_load_file( $file, 'SimpleXMLElement', LIBXML_NOCDATA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( false === $xml ) {
            return -1;
        }

        $feed_channel = $this->get_channel();
        $channel_name = ! empty( $feed_channel ) ? ( $feed_channel['name'] ?? '' ) : '';

        if ( 'Yandex' === $channel_name ) {
            return isset( $xml->offers->offer ) && is_countable( $xml->offers->offer ) ? count( $xml->offers->offer ) : 0;
        }

        // Google Shopping / Facebook / standard RSS feeds.
        if ( isset( $xml->channel->item ) && is_countable( $xml->channel->item ) ) {
            return count( $xml->channel->item );
        }

        // Generic product feeds (non-Google taxonomy).
        if ( isset( $xml->product ) && is_countable( $xml->product ) ) {
            return count( $xml->product );
        }

        // Feeds with nested <products><product> structure (Bestprice, Skroutz, Shopflix).
        if ( isset( $xml->products->product ) && is_countable( $xml->products->product ) ) {
            return count( $xml->products->product );
        }

        // Heureka/Zbozi/Glami SHOPITEM.
        if ( isset( $xml->SHOPITEM ) && is_countable( $xml->SHOPITEM ) ) {
            return count( $xml->SHOPITEM );
        }

        // Yandex offers (nested path).
        if ( isset( $xml->shop->offers->offer ) && is_countable( $xml->shop->offers->offer ) ) {
            return count( $xml->shop->offers->offer );
        }

        return 0;
    }

    /**
     * Register the product feed action.
     *
     * @since 13.3.9
     * @access public
     */
    public function register_action() {
        // Unschedule the Action Scheduler event if it exists.
        $this->unregister_action();

        $interval = $this->refresh_interval ?? '';

        // Return if the interval is empty, to prevent scheduling recurring the event.
        if ( empty( $interval ) ) {
            return;
        }

        $interval_in_seconds = 0;
        $timestamp           = 0;
        switch ( $interval ) {
            case 'twicedaily':
                // Time is set to the next 12 hours, get the current hour and decide if it is the first or second 12 hours.
                $timestamp           = strtotime( ( gmdate( 'H' ) < 12 ? 'today 12:00:00' : 'tomorrow 00:00:00' ) );
                $interval_in_seconds = DAY_IN_SECONDS / 2;
                break;
            case 'hourly':
                // Time is set to the next hour.
                $timestamp           = strtotime( '+1 hour' );
                $timestamp           = strtotime( gmdate( 'Y-m-d H:00:00', $timestamp ) );
                $interval_in_seconds = HOUR_IN_SECONDS;
                break;
            case 'daily':
                // Time is set to the next day.
                $timestamp           = strtotime( 'tomorrow 00:00:00' );
                $interval_in_seconds = DAY_IN_SECONDS;
                break;
        }

        /*
         * Schedule the Action Scheduler event.
         *
         * Do not pass $unique = true here: Action Scheduler's uniqueness check
         * matches on hook + group only and ignores args, so passing true would
         * silently reject every feed after the first (all feeds share the same
         * hook/group, only the feed_id arg differs). Duplicate prevention for
         * this specific feed is already handled by unregister_action() above.
         */
        as_schedule_recurring_action(
            $timestamp,
            $interval_in_seconds,
            ADT_PFP_AS_GENERATE_PRODUCT_FEED,
            array( 'feed_id' => $this->id ),
            ADT_PFP_AS_GENERATE_PRODUCT_FEED_GROUP
        );
    }

    /**
     * Unregister the product feed action.
     *
     * @since 13.3.9
     * @access public
     */
    public function unregister_action() {
        as_unschedule_all_actions( ADT_PFP_AS_GENERATE_PRODUCT_FEED, array( 'feed_id' => $this->id ), ADT_PFP_AS_GENERATE_PRODUCT_FEED_GROUP );
    }

    /**
     * Set the data version for the product feed.
     *
     * @since 13.4.6
     * @access public
     *
     * @param string $key The key of the data version.
     * @param string $data_version The data version.
     */
    public function set_data_version( $key, $data_version ) {
        $this->data['data_version'][ $key ] = $data_version;
    }

    /***************************************************************************
     * Legacy methods.
     * **************************************************************************
     *
     * For backwards compatibility, we have to keep saving the product feed configuration in the database.
     * This is because if the user decided to use previous versions of the plugin, the configuration will still be available.
     */

    /**
     * Save legacy options.
     *
     * @since 13.3.5.1
     * @access public
     */
    public function save_legacy_options() {
        $cron_projects = get_option( 'adt_cron_projects', array() );
        $feed_data     = array();
        $data          = array();

        $data['projectname']                    = $this->title;
        $data['active']                         = 'publish' === $this->post_status ? 'true' : 'false';
        $data['running']                        = $this->data['status'] ?? '';
        $data['countries']                      = Product_Feed_Helper::get_legacy_country_from_code( $this->country );
        $data['channel_hash']                   = $this->data['channel_hash'] ?? '';
        $data['filename']                       = $this->data['file_name'] ?? '';
        $data['fileformat']                     = $this->data['file_format'] ?? '';
        $data['delimiter']                      = $this->data['delimiter'] ?? '';
        $data['cron']                           = $this->data['refresh_interval'] ?? '';
        $data['product_variations']             = $this->data['include_product_variations'] ? 'on' : '';
        $data['default_variations']             = $this->data['only_include_default_product_variation'] ? 'on' : '';
        $data['lowest_price_variations']        = $this->data['only_include_lowest_product_variation'] ? 'on' : '';
        $data['include_all_shipping_countries'] = $this->data['include_all_shipping_countries'] ? 'on' : '';
        $data['preview_feed']                   = $this->data['create_preview'] ? 'on' : '';
        $data['products_changed']               = $this->data['refresh_only_when_product_changed'] ? 'on' : '';
        $data['attributes']                     = $this->data['attributes'] ?? array();
        $data['mappings']                       = $this->data['mappings'] ?? array();
        $data['rules']                          = $this->data['filters'] ?? array();
        $data['rules2']                         = $this->data['rules'] ?? array();
        $data['nr_products']                    = $this->data['products_count'] ?? 0;
        $data['nr_products_processed']          = $this->data['total_products_processed'] ?? 0;
        $data['utm_on']                         = $this->data['utm_enabled'] ? 'on' : '';
        $data['utm_source']                     = $this->data['utm_source'] ?? '';
        $data['utm_medium']                     = $this->data['utm_medium'] ?? '';
        $data['utm_campaign']                   = $this->data['utm_campaign'] ?? '';
        $data['utm_term']                       = $this->data['utm_term'] ?? '';
        $data['utm_content']                    = $this->data['utm_content'] ?? '';
        $data['total_product_orders_lookback']  = $this->data['utm_total_product_orders_lookback'] ?? '';
        $data['project_hash']                   = $this->data['legacy_project_hash'] ?? '';
        $data['history_products']               = $this->data['history_products'] ?? array();
        $data['last_updated']                   = $this->data['last_updated'] ?? '';
        $data['external_file']                  = $this->get_file_url();

        // Get the channel data from the legacy channel hash.
        if ( $data['channel_hash'] ) {
            $channel_data = Product_Feed_Helper::get_channel_from_legacy_channel_hash( $data['channel_hash'] );

            if ( ! empty( $channel_data ) ) {
                $data['name']       = $channel_data['name'] ?? '';
                $data['fields']     = $channel_data['fields'] ?? '';
                $data['taxonomy']   = $channel_data['taxonomy'] ?? '';
                $data['utm_source'] = empty( $data['utm_source'] ) ? $channel_data['utm_source'] : $data['utm_source'];
            }
        }

        $data = $this->add_legacy_option_extra_data( $data );

        // Revert the deleted 'batch_project_' options.
        if ( ! empty( $data['project_hash'] ) ) {
            update_option( 'batch_project_' . $data['project_hash'], $data, false );
        }

        $feed_data[] = $data;

        // Check if the feed data already exists.
        $feed_exists = array_filter(
            $cron_projects,
            function ( $cron_project ) use ( $data ) {
                return $cron_project['project_hash'] === $data['project_hash'];
            }
        );

        // Save the feed data.
        // If the feed data does not exist, add it to the cron projects.
        // If the feed data exists, update the existing cron project.
        if ( empty( $feed_exists ) ) {
            $cron_projects = array_merge( $cron_projects, $feed_data );
        } else {
            $cron_projects = array_map(
                function ( $cron_project ) use ( $data ) {
                    return $cron_project['project_hash'] === $data['project_hash'] ? array_merge( $cron_project, $data ) : $cron_project;
                },
                $cron_projects
            );
        }

        update_option( 'adt_cron_projects', $cron_projects, false );
    }

    /**
     * Add extra data to the legacy options.
     *
     * @since 13.3.7
     * @access protected
     *
     * @param array $data Legacy options.
     * @return array
     */
    protected function add_legacy_option_extra_data( $data ) {
        return $data;
    }

    /**
     * Delete legacy options.
     *
     * @since 13.3.5.1
     * @access public
     */
    public function delete_legacy_options() {
        $feed_data = get_option( 'adt_cron_projects', array() );

        if ( ! empty( $feed_data ) ) {
            $feed_data = array_filter(
                $feed_data,
                function ( $feed ) {
                    return $feed['project_hash'] !== $this->data['legacy_project_hash'];
                }
            );

            update_option( 'adt_cron_projects', $feed_data, false );
        }

        // Delete the 'batch_project_' option.
        delete_option( 'batch_project_' . $this->data['legacy_project_hash'] );
    }
}
