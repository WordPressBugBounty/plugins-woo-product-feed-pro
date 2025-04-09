<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Helpers\Helper;
use AdTribes\PFP\Helpers\Product_Feed_Helper;
use AdTribes\PFP\Traits\Singleton_Trait;

/**
 * Product Feed Admin class.
 *
 * @since 13.3.5
 */
class Product_Feed_Admin extends Abstract_Class {

    use Singleton_Trait;

    /***************************************************************************
     * Actions
     * **************************************************************************
     */

    /**
     * Create product feed.
     *
     * This method is used to create the product feed after generating the products from the legacy code base.
     *
     * @since 13.3.5
     * @access public
     *
     * @param array $project_data Project data from the legacy code base.
     */
    public function create_product_feed( $project_data ) {
        if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'woosea_ajax_nonce' ) ) {
            wp_send_json_error( __( 'Invalid security token', 'woo-product-feed-pro' ) );
        }

        if ( ! Helper::is_current_user_allowed() ) {
            wp_send_json_error( __( 'You do not have permission to manage product feed.', 'woo-product-feed-pro' ) );
        }

        // Get the total amount of products in the feed.
        if ( isset( $project_data['product_variations'] ) && 'on' === $project_data['product_variations'] ) {
            $project_data['nr_products'] = Product_Feed_Helper::get_total_published_products( true );
        } else {
            $project_data['nr_products'] = Product_Feed_Helper::get_total_published_products();
        }

        $product_feed = Product_Feed_Helper::get_product_feed();
        $country_code = isset( $project_data['countries'] ) ? Product_Feed_Helper::get_code_from_legacy_country_name( $project_data['countries'] ) : '';

        /**
         * Filter the product feed properties.
         *
         * @since 13.3.7
         * @param array        $props        The product feed properties.
         * @param Product_Feed $product_feed The product feed instance.
         * @param array        $project_data The project data from form submission.
         * @return array
         */
        $product_feed->set_props(
            apply_filters(
                'adt_create_product_feed_props',
                array(
                    'title'                             => $project_data['projectname'] ?? '',
                    'status'                            => 'processing',
                    'country'                           => $country_code,
                    'channel_hash'                      => $project_data['channel_hash'] ?? '',
                    'file_name'                         => $project_data['project_hash'] ?? '',
                    'file_format'                       => $project_data['fileformat'] ?? '',
                    'delimiter'                         => $project_data['delimiter'] ?? '',
                    'refresh_interval'                  => $project_data['cron'] ?? '',
                    'include_product_variations'        => isset( $project_data['product_variations'] ) && 'on' === $project_data['product_variations'] ? 'yes' : 'no',
                    'only_include_default_product_variation' => isset( $project_data['default_variations'] ) && 'on' === $project_data['default_variations'] ? 'yes' : 'no',
                    'only_include_lowest_product_variation' => isset( $project_data['lowest_price_variations'] ) && 'on' === $project_data['lowest_price_variations'] ? 'yes' : 'no',
                    'create_preview'                    => isset( $project_data['preview_feed'] ) && 'on' === $project_data['preview_feed'] ? 'yes' : 'no',
                    'refresh_only_when_product_changed' => isset( $project_data['products_changed'] ) && 'on' === $project_data['products_changed'] ? 'yes' : 'no',
                    'attributes'                        => $project_data['attributes'] ?? array(),
                    'mappings'                          => $project_data['mappings'] ?? array(),
                    'filters'                           => $project_data['rules'] ?? array(),
                    'rules'                             => $project_data['rules2'] ?? array(),
                    'products_count'                    => $project_data['nr_products'] ?? 0,
                    'total_products_processed'          => $project_data['nr_products_processed'] ?? 0,
                    'utm_enabled'                       => isset( $project_data['utm_on'] ) && 'on' === $project_data['utm_on'] ? 'yes' : 'no',
                    'utm_source'                        => $project_data['utm_source'] ?? '',
                    'utm_medium'                        => $project_data['utm_medium'] ?? '',
                    'utm_campaign'                      => $project_data['utm_campaign'] ?? '',
                    'utm_term'                          => $project_data['utm_term'] ?? '',
                    'utm_content'                       => $project_data['utm_content'] ?? '',
                    'utm_total_product_orders_lookback' => $project_data['total_product_orders_lookback'] ?? '',
                    'legacy_project_hash'               => $project_data['project_hash'] ?? '',
                ),
                $product_feed,
                $project_data
            )
        );

        /**
         * Action before saving the product feed.
         *
         * @since 13.3.7
         *
         * @param Product_Feed_Factory $product_feed The new product feed.
         * @param array                $project_data The project data from form submission.
         */
        do_action( 'adt_create_product_feed_before_save', $product_feed, $project_data );

        $product_feed->save();

        // Register the product feed action scheduler.
        $product_feed->register_action();

        /**
         * Run the product feed batch processing.
         * This is the legacy code base processing logic.
         */
        $product_feed->generate( 'cron' );
    }

    /**
     * Edit product feed.
     *
     * This method is used to edit the product feed after generating the products from the legacy code base.
     *
     * @since 13.3.5
     * @access public
     *
     * @param array $post_data Post data from the legacy code base.
     */
    public function edit_product_feed( $post_data ) {
        if ( ! wp_verify_nonce( $post_data['_wpnonce'], 'woosea_ajax_nonce' ) ) {
            wp_send_json_error( __( 'Invalid security token', 'woo-product-feed-pro' ) );
        }

        if ( ! Helper::is_current_user_allowed() ) {
            wp_send_json_error( __( 'You do not have permission to manage product feed.', 'woo-product-feed-pro' ) );
        }

        $product_feed = Product_Feed_Helper::get_product_feed( $post_data['project_hash'] );
        if ( $product_feed->id ) {
            // Get the current refresh interval.
            $refresh_interval_before = $product_feed->refresh_interval ?? '';

            $props_to_update = array();
            $step            = isset( $_GET['step'] ) ? sanitize_text_field( $_GET['step'] ) : 0;
            switch ( $step ) {
                case 0: // General settings.
                    $props_to_update = array(
                        'title'                      => $post_data['projectname'] ?? '',
                        'file_format'                => $post_data['fileformat'] ?? '',
                        'delimiter'                  => $post_data['delimiter'] ?? '',
                        'refresh_interval'           => $post_data['cron'] ?? '',
                        'include_product_variations' => isset( $post_data['product_variations'] ) && 'on' === $post_data['product_variations'] ? 'yes' : 'no',
                        'only_include_default_product_variation' => isset( $post_data['default_variations'] ) && 'on' === $post_data['default_variations'] ? 'yes' : 'no',
                        'only_include_lowest_product_variation' => isset( $post_data['lowest_price_variations'] ) && 'on' === $post_data['lowest_price_variations'] ? 'yes' : 'no',
                        'create_preview'             => isset( $post_data['preview_feed'] ) && 'on' === $post_data['preview_feed'] ? 'yes' : 'no',
                        'refresh_only_when_product_changed' => isset( $post_data['products_changed'] ) && 'on' === $post_data['products_changed'] ? 'yes' : 'no',
                        'utm_total_product_orders_lookback' => $post_data['total_product_orders_lookback'] ?? '',
                    );
                    break;
                case 1: // Categories mapping. (Google only).
                    $props_to_update = array(
                        'mappings' => $post_data['mappings'] ?? array(),
                    );
                    break;
                case 4: // Filters and rules.
                    $props_to_update = array(
                        'filters' => $post_data['rules'] ?? array(),
                        'rules'   => $post_data['rules2'] ?? array(),
                    );
                    break;
                case 5: // Conversion & Google Analytics settings.
                    $props_to_update = array(
                        'utm_enabled'  => isset( $post_data['utm_on'] ) && 'on' === $post_data['utm_on'] ? true : false,
                        'utm_source'   => $post_data['utm_source'] ?? '',
                        'utm_medium'   => $post_data['utm_medium'] ?? '',
                        'utm_campaign' => $post_data['utm_campaign'] ?? '',
                        'utm_term'     => $post_data['utm_term'] ?? '',
                        'utm_content'  => $post_data['utm_content'] ?? '',
                    );
                    break;
                case 7: // Field mapping.
                    $props_to_update = array(
                        'attributes' => $post_data['attributes'] ?? array(),
                    );
                    break;
            }
            /**
             * Filter the product feed properties to update.
             *
             * @since 13.3.6
             *
             * @param array        $props_to_update The product feed properties to update.
             * @param Product_Feed $product_feed    The product feed instance.
             * @param array        $post_data       The post data from the legacy code base.
             * @param int          $step            The current step.
             */
            $props_to_update = apply_filters( 'adt_edit_product_feed_props', $props_to_update, $product_feed, $post_data, $step );
            $product_feed->set_props( $props_to_update );

            $product_feed->save();

            // Re-register the product feed action scheduler if the refresh interval has changed.
            if ( '' !== $product_feed->refresh_interval && $refresh_interval_before !== $product_feed->refresh_interval ) {
                $product_feed->register_action();
            } elseif ( '' === $product_feed->refresh_interval ) {
                $product_feed->unregister_action();
            }
        }
    }

    /***************************************************************************
     * AJAX Actions
     * **************************************************************************
     */

    /**
     * Update product feed status.
     *
     * This method is used to update the product feed status after generating the products from the legacy code base.
     *
     * @since 13.3.5
     * @access public
     */
    public function ajax_update_product_feed_status() {
        if ( ! wp_verify_nonce( $_REQUEST['security'], 'woosea_ajax_nonce' ) ) {
            wp_send_json_error( __( 'Invalid security token', 'woo-product-feed-pro' ) );
        }

        if ( ! Helper::is_current_user_allowed() ) {
            wp_send_json_error( __( 'You do not have permission to manage product feed.', 'woo-product-feed-pro' ) );
        }

        $project_hash = sanitize_text_field( $_POST['project_hash'] );
        $is_publish   = sanitize_text_field( $_POST['active'] );

        $feed = Product_Feed_Helper::get_product_feed( $project_hash );
        if ( ! $feed->id ) {
            wp_send_json_error( __( 'Product feed not found.', 'woo-product-feed-pro' ) );
        }

        // Remove file if set to draft.
        if ( 'true' !== $is_publish ) {
            $feed->remove_file();
            $feed->unregister_action();
        } else {
            // Remove cache.
            Product_Feed_Helper::disable_cache();

            $feed->register_action();

            /**
             * Run the product feed batch processing.
             */
            $feed->generate( 'cron' );
        }

        $feed->post_status = 'true' === $is_publish ? 'publish' : 'draft';
        $feed->save();

        $response = array(
            'project_hash' => $project_hash,
            'status'       => $feed->post_status,
        );

        wp_send_json_success( apply_filters( 'adt_product_feed_status_response', $response, $feed ) );
    }

    /**
     * Clone product feed.
     *
     * @since 13.3.5
     * @access public
     *
     * @return void
     */
    public function ajax_clone_product_feed() {
        if ( ! wp_verify_nonce( $_REQUEST['security'], 'woosea_ajax_nonce' ) ) {
            wp_send_json_error( __( 'Invalid security token', 'woo-product-feed-pro' ) );
        }

        if ( ! Helper::is_current_user_allowed() ) {
            wp_send_json_error( __( 'You do not have permission to manage product feed.', 'woo-product-feed-pro' ) );
        }

        $original_feed = Product_Feed_Helper::get_product_feed( sanitize_text_field( $_POST['id'] ) );
        if ( ! $original_feed->id ) {
            wp_send_json_error( __( 'Product feed not found.', 'woo-product-feed-pro' ) );
        }

        // Generate a new project hash for the cloned feed.
        $project_hash = Product_Feed_Helper::generate_legacy_project_hash();

        $feed = Product_Feed_Helper::get_product_feed();
        $feed->set_props(
            apply_filters(
                'adt_clone_product_feed_props',
                array(
                    'title'                             => 'Copy ' . $original_feed->title,
                    'status'                            => 'not run yet',
                    'country'                           => $original_feed->country,
                    'channel_hash'                      => $original_feed->channel_hash,
                    'file_format'                       => $original_feed->file_format,
                    'delimiter'                         => $original_feed->delimiter,
                    'refresh_interval'                  => $original_feed->refresh_interval,
                    'refresh_only_when_product_changed' => $original_feed->refresh_only_when_product_changed,
                    'create_preview'                    => $original_feed->create_preview,
                    'include_product_variations'        => $original_feed->include_product_variations,
                    'only_include_default_product_variation' => $original_feed->only_include_default_product_variation,
                    'only_include_lowest_product_variation' => $original_feed->only_include_lowest_product_variation,
                    'products_count'                    => $original_feed->products_count,
                    'total_products_processed'          => $original_feed->total_products_processed,
                    'utm_enabled'                       => $original_feed->utm_enabled,
                    'utm_source'                        => $original_feed->utm_source,
                    'utm_medium'                        => $original_feed->utm_medium,
                    'utm_campaign'                      => $original_feed->utm_campaign,
                    'utm_term'                          => $original_feed->utm_term,
                    'utm_content'                       => $original_feed->utm_content,
                    'utm_total_product_orders_lookback' => $original_feed->utm_total_product_orders_lookback,
                    'attributes'                        => $original_feed->attributes,
                    'mappings'                          => $original_feed->mappings,
                    'rules'                             => $original_feed->rules,
                    'filters'                           => $original_feed->filters,
                    'legacy_project_hash'               => $project_hash, // Backward compatibility.
                    'file_name'                         => $project_hash, // Backward compatibility.
                ),
                $feed,
                $original_feed
            )
        );

        /**
         * Filter the cloned product feed.
         *
         * @since 13.3.5
         *
         * @param Product_Feed_Factory $feed           The cloned product feed.
         * @param Product_Feed_Factory $original_feed  The original product feed.
         */
        do_action( 'adt_clone_product_feed_before_save', $feed, $original_feed );

        $feed->save();
        $feed->register_action();

        $response = array(
            'project_hash'  => $feed->legacy_project_hash,
            'channel'       => $feed->channel_hash,
            'projectname'   => $feed->title,
            'fileformat'    => $feed->file_format,
            'interval'      => $feed->refresh_interval,
            'external_file' => $feed->get_file_url(),
            'copy_status'   => true,  // Do not start processing, user wants to make changes to the copied project.
        );

        wp_send_json_success( apply_filters( 'adt_clone_product_feed_response', $response, $feed ) );
    }

    /**
     * Cancel product feed.
     *
     * @since 13.3.5
     * @access public
     *
     * @return void
     */
    public function ajax_cancel_product_feed() {
        if ( ! wp_verify_nonce( $_REQUEST['security'], 'woosea_ajax_nonce' ) ) {
            wp_send_json_error( __( 'Invalid security token', 'woo-product-feed-pro' ) );
        }

        if ( ! Helper::is_current_user_allowed() ) {
            wp_send_json_error( __( 'You do not have permission to manage product feed.', 'woo-product-feed-pro' ) );
        }

        $feed_id = sanitize_text_field( $_POST['id'] );

        $feed = Product_Feed_Helper::get_product_feed( $feed_id );
        if ( ! $feed->id ) {
            wp_send_json_error( __( 'Product feed not found.', 'woo-product-feed-pro' ) );
        }

        do_action( 'adt_before_cancel_product_feed', $feed );

        // Remove the scheduled event.
        as_unschedule_all_actions( '', array(), 'adt_pfp_as_generate_product_feed_batch_' . $feed->id );

        $feed->total_products_processed = 0;
        $feed->batch_size               = 0;
        $feed->executed_from            = '';
        $feed->status                   = 'stopped';
        $feed->last_updated             = gmdate( 'd M Y H:i:s' );
        $feed->save();

        /**
         * Check the amount of products in the feed and update the history count.
         */
        as_schedule_single_action( time() + 1, 'adt_pfp_as_product_feed_update_stats', array( 'feed_id' => $feed->id ) );

        do_action( 'adt_after_cancel_product_feed', $feed );

        wp_send_json_success( __( 'Product feed process has been cancelled.', 'woo-product-feed-pro' ) );
    }

    /**
     * Refresh product feed.
     *
     * @since 13.3.5
     * @access public
     */
    public function ajax_refresh_product_feed() {
        if ( ! wp_verify_nonce( $_REQUEST['security'], 'woosea_ajax_nonce' ) ) {
            wp_send_json_error( __( 'Invalid security token', 'woo-product-feed-pro' ) );
        }

        if ( ! Helper::is_current_user_allowed() ) {
            wp_send_json_error( __( 'You do not have permission to manage product feed.', 'woo-product-feed-pro' ) );
        }

        $feed_id = sanitize_text_field( $_POST['id'] );

        $feed = Product_Feed_Helper::get_product_feed( $feed_id );
        if ( ! $feed->id ) {
            wp_send_json_error( __( 'Product feed not found.', 'woo-product-feed-pro' ) );
        }

        // Remove cache.
        Product_Feed_Helper::disable_cache();

        // Determine if the feed is executed from AJAX or cron.
        // For debugging purposes, ajax is easier to debug.
        $executed_from = defined( 'ADT_PFP_MANUAL_REFRESH_FEED_EXECUTION_METHOD' ) ? ADT_PFP_MANUAL_REFRESH_FEED_EXECUTION_METHOD : 'cron';

        /**
         * Run the product feed batch processing.
         */
        $response = $feed->generate( $executed_from );

        wp_send_json_success( $response );
    }

    /**
     * Delete product feed.
     *
     * @since 13.3.5
     * @access public
     *
     * @return void
     */
    public function ajax_delete_product_feed() {
        if ( ! wp_verify_nonce( $_REQUEST['security'], 'woosea_ajax_nonce' ) ) {
            wp_send_json_error( __( 'Invalid security token', 'woo-product-feed-pro' ) );
        }

        if ( ! Helper::is_current_user_allowed() ) {
            wp_send_json_error( __( 'You do not have permission to manage product feed.', 'woo-product-feed-pro' ) );
        }

        $feed_id = sanitize_text_field( $_POST['id'] );

        $feed = Product_Feed_Helper::get_product_feed( $feed_id );
        if ( ! $feed->id ) {
            wp_send_json_error( __( 'Product feed not found.', 'woo-product-feed-pro' ) );
        }

        do_action( 'adt_before_delete_product_feed', $feed );

        $feed->delete();

        do_action( 'adt_after_delete_product_feed', $feed );

        wp_send_json_success( __( 'Product feed has been deleted.', 'woo-product-feed-pro' ) );
    }

    /**
     * Print channel options for the channel dropdown.
     *
     * @since 13.4.2
     * @access public
     */
    public function ajax_print_channels() {
        if ( ! wp_verify_nonce( $_REQUEST['security'], 'woosea_ajax_nonce' ) ) {
            wp_send_json_error( __( 'Nonce verification failed', 'woo-product-feed-pro' ) );
        }

        $country  = sanitize_text_field( $_POST['country'] );
        $channels = Product_Feed_Attributes::get_channels( $country );
        $data     = Product_Feed_Helper::print_channel_options( $channels );
        wp_send_json_success( $data );
    }

    /**
     * Generate view pages.
     * This method is used to generate the view pages after generating the products from the legacy code base.
     *
     * @since 13.3.6
     * @access public
     */
    public function generate_pages() {
        // phpcs:disable WordPress.Security.NonceVerification
        $form_data     = Helper::stripslashes_recursive( $_REQUEST );
        $channel_hash  = isset( $_REQUEST['channel_hash'] ) ? sanitize_text_field( $_REQUEST['channel_hash'] ) : '';
        $generate_step = isset( $_REQUEST['step'] ) ? sanitize_text_field( $_REQUEST['step'] ) : 0;

        do_action( 'adt_before_generate_pages', $form_data, $channel_hash, $generate_step );

        // Switch to determing what template to use during feed configuration.
        switch ( $generate_step ) {
            case 0:
                load_template( WOOCOMMERCESEA_VIEWS_ROOT_PATH . 'manage-feed/step/woosea-generate-feed-step-0.php' );
                break;
            case 1:
                load_template( WOOCOMMERCESEA_VIEWS_ROOT_PATH . 'manage-feed/step/woosea-generate-feed-step-1.php' );
                break;
            case 3:
                load_template( WOOCOMMERCESEA_VIEWS_ROOT_PATH . 'manage-feed/step/woosea-generate-feed-step-3.php' );
                break;
            case 4:
                load_template( WOOCOMMERCESEA_VIEWS_ROOT_PATH . 'manage-feed/step/woosea-generate-feed-step-4.php' );
                break;
            case 5:
                load_template( WOOCOMMERCESEA_VIEWS_ROOT_PATH . 'manage-feed/step/woosea-generate-feed-step-5.php' );
                break;
            case 6:
                load_template( WOOCOMMERCESEA_VIEWS_ROOT_PATH . 'manage-feed/step/woosea-generate-feed-step-6.php' );
                break;
            case 7:
            case 99: // Determing if we need to do field mapping or attribute picking after step 0.
                load_template( WOOCOMMERCESEA_VIEWS_ROOT_PATH . 'manage-feed/step/woosea-generate-feed-step-7.php' );
                break;
            case 100:
                // Update product feed configuration.
                $this->edit_product_feed( $form_data );

                wp_safe_redirect( admin_url( 'admin.php?page=woo-product-feed' ) );
                exit;
            case 101:
                // Update temp product feed configuration.
                $project_temp = $this->update_temp_product_feed( $form_data, true );

                // Create product feed.
                $this->create_product_feed( $project_temp );

                wp_safe_redirect( admin_url( 'admin.php?page=woo-product-feed' ) );
                exit;
            default:
                load_template(
                    apply_filters(
                        'adt_load_step_template',
                        WOOCOMMERCESEA_VIEWS_ROOT_PATH . 'manage-feed/view-manage-feed.php',
                        $form_data,
                        $channel_hash,
                        $generate_step
                    )
                );
                break;
        }
        // phpcs:enable WordPress.Security.NonceVerification

        do_action( 'adt_after_generate_pages', $form_data, $channel_hash, $generate_step );
    }

    /**
     * Update project configuration.
     *
     * @since 13.3.6
     * @access private
     *
     * @param array $form_data The form data.
     * @param bool  $clear     Clear the temp product feed.
     *
     * @return array
     */
    public static function update_temp_product_feed( $form_data, $clear = false ) {
        // Sanitize the form data.
        $form_data = Helper::array_walk_recursive_with_callback( $form_data, array( Helper::class, 'custom_product_feeds_data_sanitize_text_field' ) );

        $project_temp     = get_option( ADT_OPTION_TEMP_PRODUCT_FEED, array() );
        $new_project_hash = empty( $form_data['project_hash'] ) ? Product_Feed_Helper::generate_legacy_project_hash() : '';

        // If the project hash is empty, then we need to generate a new one.
        if ( empty( $form_data['project_hash'] ) ) {
            $form_data['project_hash'] = $new_project_hash;
        }

        // Merge the form data with the project temp values.
        $project_temp = array_merge( $project_temp, $form_data );

        // Clear the temp product feed.
        if ( $clear ) {
            delete_option( ADT_OPTION_TEMP_PRODUCT_FEED );
        } else {
            update_option( ADT_OPTION_TEMP_PRODUCT_FEED, $project_temp, false );
        }

        // Update the project temp.

        return apply_filters( 'adt_update_temp_product_feed', $project_temp, $form_data, $new_project_hash );
    }

    /**
     * Get product feed settings for the product feed table.
     *
     * @since 13.3.5
     * @access public
     *
     * @param object $product_feed The product feed object.
     */
    public static function get_product_feed_settings( $product_feed ) {
        if ( ! Product_Feed_Helper::is_a_product_feed( $product_feed ) ) {
            return '';
        }

        $settings = array(
            array(
                'title'    => __( 'General feed settings', 'woo-product-feed-pro' ),
                'step'     => 0,
                'url'      => self::get_product_feed_setting_url( $product_feed->legacy_project_hash, $product_feed->channel_hash, 0 ),
                'position' => 10,
            ),
            array(
                'title'    => __( 'Field mapping', 'woo-product-feed-pro' ),
                'step'     => 7,
                'url'      => self::get_product_feed_setting_url( $product_feed->legacy_project_hash, $product_feed->channel_hash, 7 ),
                'position' => 20,
            ),
            array(
                'title'    => __( 'Feed filters and rules', 'woo-product-feed-pro' ),
                'step'     => 4,
                'url'      => self::get_product_feed_setting_url( $product_feed->legacy_project_hash, $product_feed->channel_hash, 4 ),
                'position' => 30,
            ),
            array(
                'title'    => __( 'Conversion & Google Analytics settings', 'woo-product-feed-pro' ),
                'step'     => 5,
                'url'      => self::get_product_feed_setting_url( $product_feed->legacy_project_hash, $product_feed->channel_hash, 5 ),
                'position' => 40,
            ),
        );

        // Add category mapping setting if taxonomy is not set to none.
        if ( $product_feed->get_channel( 'taxonomy' ) !== 'none' ) {
            // Insert after field mapping.
            $settings[] = array(
                'title'    => __( 'Category mapping', 'woo-product-feed-pro' ),
                'step'     => 1,
                'url'      => self::get_product_feed_setting_url( $product_feed->legacy_project_hash, $product_feed->channel_hash, 1 ),
                'position' => 25,
            );
        }

        /**
         * Filter the product feed settings.
         *
         * @since 13.3.5
         *
         * @param array  $settings The product feed settings.
         * @param object $product_feed The product feed object.
         */
        $settings = apply_filters( 'adt_product_feed_settings', $settings, $product_feed );

        // Sort the actions by position.
        usort(
            $settings,
            function ( $a, $b ) {
                return $a['position'] <=> $b['position'];
            }
        );

        return $settings;
    }

    /**
     * Get product feed setting URL.
     *
     * @since 13.3.5
     * @access public
     *
     * @param string $legacy_project_hash The legacy project hash.
     * @param string $channel_hash        The channel hash.
     * @param int    $step                The step number.
     *
     * @return string
     */
    public static function get_product_feed_setting_url( $legacy_project_hash, $channel_hash, $step = 0 ) {
        $args = array(
            'page'         => 'pfp-edit-feed',
            'action'       => 'edit_project',
            'project_hash' => $legacy_project_hash,
            'channel_hash' => $channel_hash,
            'step'         => $step,
        );

        return esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) );
    }

    /**
     * Get product feed actions for the product feed table.
     *
     * @since 13.3.7
     * @access public
     *
     * @param object $product_feed The product feed object.
     */
    public static function get_product_feed_actions( $product_feed ) {
        if ( ! Product_Feed_Helper::is_a_product_feed( $product_feed ) ) {
            return '';
        }

        $actions = array(
            array(
                'title'    => __( 'Feed settings', 'woo-product-feed-pro' ),
                'icon'     => 'dashicons-admin-generic',
                'id'       => 'gear',
                'position' => 10,
            ),
        );

        // Add refresh action if the product feed is published and not processing.
        if ( 'publish' === $product_feed->post_status && 'processing' !== $product_feed->status ) {
            $actions[] = array(
                'title'    => __( 'Start product feed processing', 'woo-product-feed-pro' ),
                'icon'     => 'dashicons-update',
                'id'       => 'refresh',
                'position' => 30,
            );
        }

        // Add Cancel action if the product feed is processing.
        // Add Refresh & Delete action if the product feed is not processing.
        if ( 'processing' === $product_feed->status ) {
            $actions[] = array(
                'title'    => __( 'Cancel product feed processing', 'woo-product-feed-pro' ),
                'icon'     => 'dashicons-dismiss',
                'id'       => 'cancel',
                'position' => 90,
            );
        } else {
            $actions[] = array(
                'title'    => __( 'Copy product feed', 'woo-product-feed-pro' ),
                'icon'     => 'dashicons-admin-page',
                'id'       => 'copy',
                'position' => 20,
            );
            $actions[] = array(
                'title'    => __( 'Delete product feed', 'woo-product-feed-pro' ),
                'icon'     => 'dashicons-trash',
                'id'       => 'trash',
                'position' => 99,
            );
        }

        // Add Download action if the product feed is ever being run & not processing.
        if ( 'processing' !== $product_feed->status && 'not run yet' !== $product_feed->status ) {
            $actions[] = array(
                'title'    => __( 'Download product feed', 'woo-product-feed-pro' ),
                'icon'     => 'dashicons-download',
                'id'       => 'download',
                'url'      => $product_feed->get_file_url(),
                'position' => 40,
            );
        }

        /**
         * Filter the product feed actions.
         *
         * @since 13.3.7
         *
         * @param array  $actions The product feed actions.
         * @param object $product_feed The product feed object.
         */
        $actions = apply_filters( 'adt_product_feed_actions', $actions, $product_feed );

        // Sort the actions by position.
        usort(
            $actions,
            function ( $a, $b ) {
                return $a['position'] <=> $b['position'];
            }
        );

        return $actions;
    }

    /**
     * Run the class
     *
     * @codeCoverageIgnore
     * @since 13.3.3
     */
    public function run() {
        // AJAX actions.
        add_action( 'wp_ajax_woosea_project_status', array( $this, 'ajax_update_product_feed_status' ) );
        add_action( 'wp_ajax_woosea_project_copy', array( $this, 'ajax_clone_product_feed' ) );
        add_action( 'wp_ajax_woosea_project_cancel', array( $this, 'ajax_cancel_product_feed' ) );
        add_action( 'wp_ajax_woosea_project_refresh', array( $this, 'ajax_refresh_product_feed' ) );
        add_action( 'wp_ajax_woosea_project_delete', array( $this, 'ajax_delete_product_feed' ) );
        add_action( 'wp_ajax_woosea_print_channels', array( $this, 'ajax_print_channels' ) );

        // Views.
        add_action( 'adt_view_generate_pages', array( $this, 'generate_pages' ) );
    }
}
