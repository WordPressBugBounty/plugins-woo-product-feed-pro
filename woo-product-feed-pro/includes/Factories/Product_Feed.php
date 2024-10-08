<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Factories
 */

namespace AdTribes\PFP\Factories;

use AdTribes\PFP\Helpers\Product_Feed_Helper;

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
     * Stores product data.
     *
     * @var array
     */
    protected $data = array(
        'status'                                 => '',
        'country'                                => '',
        'channel_hash'                           => '',
        'channel'                                => array(),
        'file_name'                              => '',
        'file_format'                            => 'xml',
        'file_url'                               => '',
        'delimiter'                              => '',
        'refresh_interval'                       => 'daily',
        'refresh_only_when_product_changed'      => false,
        'create_preview'                         => false,
        'include_product_variations'             => false,
        'only_include_default_product_variation' => false,
        'only_include_lowest_product_variation'  => false,
        'products_count'                         => 0,
        'total_products_processed'               => 0,
        'utm_enabled'                            => true,
        'utm_source'                             => '',
        'utm_medium'                             => '',
        'utm_campaign'                           => '',
        'utm_term'                               => '',
        'utm_content'                            => '',
        'utm_total_product_orders_lookback'      => 0,
        'attributes'                             => array(),
        'mappings'                               => array(),
        'rules'                                  => array(),
        'filters'                                => array(),
        'history_products'                       => array(),
        'ship_suffix'                            => false,
        'last_updated'                           => '',
        'legacy_project_hash'                    => '', // Backward compatibility.
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
            $this->id = $feed;
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
        } elseif ( in_array( $key, array( 'title', 'post_status' ), true ) ) {
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
        } elseif ( in_array( $prop, array( 'title', 'post_status' ), true ) ) {
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
        // Exclude data from saving.
        $meta_keys = array_diff( array_keys( $this->data ), array( 'channel', 'file_url' ) );

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
                $value = $this->_filter_feed_filters_mapping_meta_value( $value );
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
     * Get product feed file format.
     *
     * @since 13.3.5
     * @access public
     *
     * @return string
     */
    public function get_file_url() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['baseurl'] . '/' . self::UPLOAD_SUB_DIR . '/' . $this->file_format . '/' . $this->file_name . '.' . $this->file_format;
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
        $upload_dir = wp_upload_dir();
        $asd        = $upload_dir['basedir'] . '/' . self::UPLOAD_SUB_DIR . '/' . $this->file_format . '/' . $this->file_name . '.' . $this->file_format;
        return $asd;
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
        $legacy_countries = include WOOCOMMERCESEA_PATH . 'includes/I18n/legacy_countries.php';
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
     * Execute product feed batch event.
     *
     * @since 13.3.5.4
     * @access public
     */
    public function run_batch_event() {
        // Set the next scheduled event.
        if ( ! wp_next_scheduled( 'woosea_create_batch_event', array( $this->id ) ) ) {
            if ( wp_schedule_single_event( time(), 'woosea_create_batch_event', array( $this->id ) ) ) {
                spawn_cron( time() );
            } else {
                // Something went wrong with scheduling the cron, try again in case it was an intermittent failure.
                wp_schedule_single_event( time(), 'woosea_create_batch_event', array( $this->id ) );
                spawn_cron( time() );
            }
        }
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
        $cron_projects = get_option( 'cron_projects', array() );
        $feed_data     = array();
        $data          = array();

        $data['projectname']                   = $this->title;
        $data['active']                        = 'publish' === $this->post_status ? 'true' : 'false';
        $data['running']                       = $this->data['status'] ?? '';
        $data['countries']                     = Product_Feed_Helper::get_legacy_country_from_code( $this->country );
        $data['channel_hash']                  = $this->data['channel_hash'] ?? '';
        $data['filename']                      = $this->data['file_name'] ?? '';
        $data['fileformat']                    = $this->data['file_format'] ?? '';
        $data['delimiter']                     = $this->data['delimiter'] ?? '';
        $data['cron']                          = $this->data['refresh_interval'] ?? '';
        $data['product_variations']            = $this->data['include_product_variations'] ? 'on' : '';
        $data['default_variations']            = $this->data['only_include_default_product_variation'] ? 'on' : '';
        $data['lowest_price_variations']       = $this->data['only_include_lowest_product_variation'] ? 'on' : '';
        $data['preview_feed']                  = $this->data['create_preview'] ? 'on' : '';
        $data['products_changed']              = $this->data['refresh_only_when_product_changed'] ? 'on' : '';
        $data['attributes']                    = $this->data['attributes'] ?? array();
        $data['mappings']                      = $this->data['mappings'] ?? array();
        $data['rules']                         = $this->data['filters'] ?? array();
        $data['rules2']                        = $this->data['rules'] ?? array();
        $data['nr_products']                   = $this->data['products_count'] ?? 0;
        $data['nr_products_processed']         = $this->data['total_products_processed'] ?? 0;
        $data['utm_on']                        = $this->data['utm_enabled'] ? 'on' : '';
        $data['utm_source']                    = $this->data['utm_source'] ?? '';
        $data['utm_medium']                    = $this->data['utm_medium'] ?? '';
        $data['utm_campaign']                  = $this->data['utm_campaign'] ?? '';
        $data['utm_term']                      = $this->data['utm_term'] ?? '';
        $data['utm_content']                   = $this->data['utm_content'] ?? '';
        $data['total_product_orders_lookback'] = $this->data['utm_total_product_orders_lookback'] ?? '';
        $data['project_hash']                  = $this->data['legacy_project_hash'] ?? '';
        $data['history_products']              = $this->data['history_products'] ?? array();
        $data['last_updated']                  = $this->data['last_updated'] ?? '';
        $data['external_file']                 = $this->get_file_url();

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

        update_option( 'cron_projects', $cron_projects, false );
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
        $feed_data = get_option( 'cron_projects', array() );

        if ( ! empty( $feed_data ) ) {
            $feed_data = array_filter(
                $feed_data,
                function ( $feed ) {
                    return $feed['project_hash'] !== $this->data['legacy_project_hash'];
                }
            );

            update_option( 'cron_projects', $feed_data );
        }

        // Delete the 'batch_project_' option.
        delete_option( 'batch_project_' . $this->data['legacy_project_hash'] );
    }
}
