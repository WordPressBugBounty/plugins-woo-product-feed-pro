<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes\Admin_Pages
 */

namespace AdTribes\PFP\Classes\Admin_Pages;

use AdTribes\PFP\Abstracts\Admin_Page;
use AdTribes\PFP\Factories\Vite_App;
use AdTribes\PFP\Traits\Singleton_Trait;
use AdTribes\PFP\Helpers\Helper;
use AdTribes\PFP\Helpers\Product_Feed_Helper;
use AdTribes\PFP\Classes\Google_Product_Taxonomy_Fetcher;
use AdTribes\PFP\Classes\Admin_Pages\Manage_Feeds_Page;

/**
 * Edit_Feed_Page class.
 *
 * @since 13.4.4
 */
class Edit_Feed_Page extends Admin_Page {

    use Singleton_Trait;

    const MENU_SLUG = 'adt-edit-feed';

    /**
     * Holds the class instance object.
     *
     * @since 13.4.4
     * @var Edit_Feed_Page $instance
     */
    protected static $instance;

    /**
     * Holds the tabs.
     *
     * @since 13.4.4
     * @var array
     */
    protected $tabs;

    /**
     * Initialize the class.
     *
     * @since 13.4.4
     */
    public function init() {
        $this->parent_slug = 'woo-product-feed';
        $this->page_title  = __( 'Feed configuration', 'woo-product-feed-pro' );
        $this->menu_title  = __( 'Create feed', 'woo-product-feed-pro' );
        $this->capability  = apply_filters( 'adt_pfp_admin_capability', 'manage_options' );
        $this->menu_slug   = self::MENU_SLUG;
        $this->template    = 'edit-feed/edit-feed.php';
        $this->position    = 20;
        $this->tabs        = $this->get_tabs();
    }

    /**
     * Get the admin menu priority.
     *
     * @since 13.4.4
     * @return int
     */
    protected function get_priority() {
        return 20;
    }

    /**
     * Enqueue scripts.
     *
     * @since 13.4.4
     */
    public function enqueue_scripts() {
        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        // Load Google Taxonomy JS.
        switch ( $tab ) {
            case 'category_mapping':
                $google_taxonomy_fetcher = Google_Product_Taxonomy_Fetcher::instance();

                // Typeahead JS.
                wp_enqueue_script( 'pfp-typeahead-js', ADT_PFP_JS_URL . 'lib/typeahead.bundle.js', '', ADT_PFP_OPTION_INSTALLED_VERSION, true );

                wp_enqueue_script( 'pfp-google-taxonomy-js', ADT_PFP_JS_URL . 'pfp-google-taxonomy.js', array( 'jquery' ), ADT_PFP_OPTION_INSTALLED_VERSION, true );
                wp_localize_script(
                    'pfp-google-taxonomy-js',
                    'pfp_google_taxonomy',
                    array(
                        'file_name' => $google_taxonomy_fetcher::GOOGLE_PRODUCT_TAXONOMY_FILE_NAME,
                        'file_path' => $google_taxonomy_fetcher::GOOGLE_PRODUCT_TAXONOMY_FILE_PATH,
                        'file_url'  => $google_taxonomy_fetcher::GOOGLE_PRODUCT_TAXONOMY_FILE_URL,
                    )
                );
                break;
        }

        $l10n = Helper::vite_app_common_l10n(
            array(
                'adtNonce' => wp_create_nonce( 'adt_nonce' ),
            )
        );

        $app = new Vite_App(
            'adt-edit-feed-script',
            'src/vanilla/edit-feed/index.ts',
            array( 'jquery', 'wp-i18n', 'select2' ),
            $l10n,
            'adtObj',
            array()
        );
        $app->enqueue();
    }

    /**
     * Get the tabs.
     *
     * @since 13.4.4
     * @param object|array|null $feed The feed object.
     * @return array
     */
    public function get_tabs( $feed = null ) {
        $tabs = apply_filters(
            'adt_edit_feed_tabs',
            array(
                'general'              => __( 'General', 'woo-product-feed-pro' ),
                'field_mapping'        => __( 'Field Mapping', 'woo-product-feed-pro' ),
                'category_mapping'     => __( 'Category Mapping', 'woo-product-feed-pro' ),
                'filters_rules'        => __( 'Filters & Rules', 'woo-product-feed-pro' ),
                'conversion_analytics' => __( 'Conversion & Google Analytics', 'woo-product-feed-pro' ),
            )
        );

        // By default, include the category mapping tab.
        $show_category_mapping = false;

        $feed_id = $feed->id ?? 0;
        if ( $feed_id > 0 ) {
            $channel               = $feed->get_channel();
            $show_category_mapping = isset( $channel['taxonomy'] ) && 'none' !== $channel['taxonomy'];
        } elseif ( is_array( $feed ) && isset( $feed['channel_hash'] ) ) { // Check if channel has taxonomy.
            $channel               = Product_Feed_Helper::get_channel_from_legacy_channel_hash( $feed['channel_hash'] );
            $show_category_mapping = isset( $channel['taxonomy'] ) && 'none' !== $channel['taxonomy'];
        }

        // Remove category mapping if not applicable.
        if ( ! $show_category_mapping ) {
            unset( $tabs['category_mapping'] );
        }

        /**
         * Filter the tabs.
         *
         * @since 13.4.4
         * @param array $tabs The tabs.
         * @param object $feed The feed object.
         * @return array
         */
        return apply_filters( 'adt_edit_feed_get_tabs', $tabs, $feed );
    }

    /**
     * Get the tab URL.
     *
     * @since 13.4.4
     * @param string $tab The tab.
     * @return string
     */
    public static function get_tab_url( $tab ) {
        $args = array(
            'page' => self::MENU_SLUG,
            'tab'  => $tab,
        );

        // Preserve id if it exists in the URL.
        if ( isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $args['id'] = intval( $_GET['id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        return esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) );
    }

    /**
     * Get the tab content.
     *
     * @since 13.4.4
     * @param string $tab The tab.
     * @return string
     */
    public function get_tab_content( $tab ) {
        ob_start();

        $tab = '' === $tab ? 'general' : $tab;

        switch ( $tab ) {
            case 'general':
                Helper::locate_admin_template( 'edit-feed/tabs/general-tab.php', true );
                break;
            case 'field_mapping':
                Helper::locate_admin_template( 'edit-feed/tabs/field-mapping-tab.php', true );
                break;
            case 'category_mapping':
                Helper::locate_admin_template( 'edit-feed/tabs/category-mapping-tab.php', true );
                break;
            case 'filters_rules':
                Helper::locate_admin_template( 'edit-feed/tabs/filters-rules-tab.php', true );
                break;
            case 'conversion_analytics':
                Helper::locate_admin_template( 'edit-feed/tabs/conversion-analytics-tab.php', true );
                break;
        }

        do_action( 'adt_edit_feed_tab_content', $tab );

        return ob_get_clean();
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
    public function update_temp_product_feed( $form_data, $clear = false ) {
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
     * Change default footer text, asking to review our plugin.
     *
     * @param string $default_text Default footer text.
     * @return string Footer text asking to review our plugin.
     **/
    public function edit_feed_footer_text( $default_text ) {
        $screen = get_current_screen();

        // Only show on edit feed page.
        if ( 'product-feed-pro_page_adt-edit-feed' !== $screen->id ) {
            return $default_text;
        }

        return sprintf(
            /* translators: %s: WooCommerce Product Feed PRO plugin rating link */
            esc_html__(
                'If you like our %1$s plugin please leave us a %2$s rating. Thanks in advance!',
                'woo-product-feed-pro'
            ),
            '<strong>WooCommerce Product Feed PRO</strong>',
            '<a href="https://wordpress.org/support/plugin/woo-product-feed-pro/reviews?rate=5#new-post" target="_blank" class="woo-product-feed-pro-ratingRequest">&#9733;&#9733;&#9733;&#9733;&#9733;</a>'
        );
    }

    /**
     * Process form submissions from the edit feed page.
     *
     * @since 13.4.4
     * @return void
     */
    public function process_form_submission() {
        // Verify nonce for security.
        check_ajax_referer( 'woosea_ajax_nonce', 'security' );

        // Check user capabilities.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to edit feeds', 'woo-product-feed-pro' ) );
        }

        // Get the active tab to determine which form we're processing.
        $active_tab = isset( $_POST['active_tab'] ) ? sanitize_text_field( wp_unslash( $_POST['active_tab'] ) ) : 'general';
        $feed_id    = isset( $_POST['feed_id'] ) ? intval( $_POST['feed_id'] ) : 0;

        if ( 0 !== $feed_id ) {
            // Existing feed - process normally.
            $feed = Product_Feed_Helper::get_product_feed( $feed_id );
            if ( ! $feed ) {
                wp_die( esc_html__( 'Feed not found', 'woo-product-feed-pro' ) );
            }

            // Process the form based on the active tab.
            $this->process_tab_form( $active_tab, $feed );

            // Redirect back to the same tab to prevent form resubmission.
            wp_safe_redirect(
                add_query_arg(
                    array(
                        'page'    => self::MENU_SLUG,
                        'id'      => $feed_id,
                        'tab'     => $active_tab,
                        'updated' => '1',
                    ),
                    admin_url( 'admin.php' )
                )
            );
        } else {
            // New feed - process temp data.
            $feed = $this->update_temp_product_feed( $_POST ?? array() ); // phpcs:ignore WordPress.Security.NonceVerification
            $tabs = array_keys( $this->get_tabs( $feed ) );

            // Find the current tab's position and determine the next tab.
            $current_tab_position = array_search( $active_tab, $tabs, true );
            $next_tab             = ( false !== $current_tab_position && isset( $tabs[ $current_tab_position + 1 ] ) )
                ? $tabs[ $current_tab_position + 1 ]
                : ''; // Empty indicates we're at the last tab.

            // Check if channel has taxonomy (only for field_mapping tab).
            if ( 'field_mapping' === $active_tab && isset( $feed['channel_hash'] ) ) {
                $channel = Product_Feed_Helper::get_channel_from_legacy_channel_hash( $feed['channel_hash'] );
                if ( isset( $channel['taxonomy'] ) && 'none' === $channel['taxonomy'] ) {
                    // Skip to filters_rules if no taxonomy by finding its position.
                    $filters_position = array_search( 'filters_rules', $tabs, true );
                    $next_tab         = false !== $filters_position ? 'filters_rules' : $next_tab;
                }
            }

            if ( '' !== $next_tab ) {
                wp_safe_redirect(
                    add_query_arg(
                        array(
                            'page'    => self::MENU_SLUG,
                            'tab'     => $next_tab,
                            'updated' => '1',
                        ),
                        admin_url( 'admin.php' )
                    )
                );
            } else {
                // Create the product feed.
                $this->create_product_feed( $feed );

                wp_safe_redirect(
                    add_query_arg(
                        array(
                            'page' => Manage_Feeds_Page::MENU_SLUG,
                        ),
                        admin_url( 'admin.php' )
                    )
                );
            }
            exit;
        }
    }

    /**
     * Process form submission based on the active tab.
     *
     * @since 13.4.4
     * @param string $active_tab The active tab.
     * @param object $feed The feed object.
     * @return void
     */
    private function process_tab_form( $active_tab, $feed ) {
        switch ( $active_tab ) {
            case 'general':
                $this->process_general_tab_form( $feed );
                break;
            case 'field_mapping':
                $this->process_field_mapping_tab_form( $feed );
                break;
            case 'category_mapping':
                $this->process_category_mapping_tab_form( $feed );
                break;
            case 'filters_rules':
                $this->process_filters_rules_tab_form( $feed );
                break;
            case 'conversion_analytics':
                $this->process_conversion_analytics_tab_form( $feed );
                break;
            default:
                do_action( 'adt_process_tab_form', $active_tab, $feed );
                break;
        }
    }

    /**
     * Process general tab form submission.
     *
     * @since 13.4.4
     * @param object $feed The feed object.
     * @return void
     */
    private function process_general_tab_form( $feed ) {
        // phpcs:disable WordPress.Security.NonceVerification

        // Get the current refresh interval.
        $refresh_interval_before = $feed->refresh_interval ?? '';

        // Process form data.
        $props_to_update = array(
            'title'                                  => isset( $_POST['projectname'] ) ? sanitize_text_field( wp_unslash( $_POST['projectname'] ) ) : '',
            'file_format'                            => isset( $_POST['fileformat'] ) ? sanitize_text_field( wp_unslash( $_POST['fileformat'] ) ) : '',
            'delimiter'                              => isset( $_POST['delimiter'] ) ? sanitize_text_field( wp_unslash( $_POST['delimiter'] ) ) : '',
            'refresh_interval'                       => isset( $_POST['cron'] ) ? sanitize_text_field( wp_unslash( $_POST['cron'] ) ) : '',
            'include_product_variations'             => isset( $_POST['product_variations'] ) ? 'yes' : 'no',
            'only_include_default_product_variation' => isset( $_POST['default_variations'] ) ? 'yes' : 'no',
            'only_include_lowest_product_variation'  => isset( $_POST['lowest_price_variations'] ) ? 'yes' : 'no',
            'create_preview'                         => isset( $_POST['preview_feed'] ) ? 'yes' : 'no',
            'refresh_only_when_product_changed'      => isset( $_POST['products_changed'] ) ? 'yes' : 'no',
            'utm_total_product_orders_lookback'      => isset( $_POST['total_product_orders_lookback'] ) ? intval( $_POST['total_product_orders_lookback'] ) : '',
        );

        /**
         * Filter the product feed properties to update for the general tab.
         *
         * @since 13.4.4
         * @param array  $props_to_update The product feed properties to update.
         * @param object $feed The product feed object.
         */
        $props_to_update = apply_filters( 'adt_edit_feed_general_tab_props', $props_to_update, $feed );

        // Update feed properties.
        $feed->set_props( $props_to_update );
        $feed->save();

        // Re-register the product feed action scheduler if the refresh interval has changed.
        if ( '' !== $feed->refresh_interval && $refresh_interval_before !== $feed->refresh_interval ) {
            $feed->register_action();
        } elseif ( '' === $feed->refresh_interval ) {
            $feed->unregister_action();
        }

        /**
         * Action after processing the general tab form.
         *
         * @since 13.4.4
         * @param object $feed The product feed object.
         * @param array  $props_to_update The updated properties.
         */
        do_action( 'adt_after_process_general_tab_form', $feed, $props_to_update );
        // phpcs:enable WordPress.Security.NonceVerification
    }

    /**
     * Process field mapping tab form submission.
     *
     * @since 13.4.4
     * @param object $feed The feed object.
     * @return void
     */
    private function process_field_mapping_tab_form( $feed ) {
        // phpcs:disable WordPress.Security.NonceVerification

        // Process field mapping data.
        $attributes = isset( $_POST['attributes'] ) ? Helper::sanitize_array( $_POST['attributes'] ) : array(); // phpcs:ignore

        $props_to_update = array(
            'attributes' => $attributes,
        );

        /**
         * Filter the product feed properties to update for the field mapping tab.
         *
         * @since 13.4.4
         * @param array  $props_to_update The product feed properties to update.
         * @param object $feed The product feed object.
         */
        $props_to_update = apply_filters( 'adt_edit_feed_field_mapping_tab_props', $props_to_update, $feed );

        // Update feed properties.
        $feed->set_props( $props_to_update );
        $feed->save();

        /**
         * Action after processing the field mapping tab form.
         *
         * @since 13.4.4
         * @param object $feed The product feed object.
         * @param array  $props_to_update The updated properties.
         */
        do_action( 'adt_after_process_field_mapping_tab_form', $feed, $props_to_update );
        // phpcs:enable WordPress.Security.NonceVerification
    }

    /**
     * Process category mapping tab form submission.
     *
     * @since 13.4.4
     * @param object $feed The feed object.
     * @return void
     */
    private function process_category_mapping_tab_form( $feed ) {
        // phpcs:disable WordPress.Security.NonceVerification

        // Process category mapping data.
        $mappings = isset( $_POST['mappings'] ) ? Helper::sanitize_array( $_POST['mappings'] ) : array(); // phpcs:ignore

        $props_to_update = array(
            'mappings' => $mappings,
        );

        /**
         * Filter the product feed properties to update for the category mapping tab.
         *
         * @since 13.4.4
         * @param array  $props_to_update The product feed properties to update.
         * @param object $feed The product feed object.
         */
        $props_to_update = apply_filters( 'adt_edit_feed_category_mapping_tab_props', $props_to_update, $feed );

        // Update feed properties.
        $feed->set_props( $props_to_update );
        $feed->save();

        /**
         * Action after processing the category mapping tab form.
         *
         * @since 13.4.4
         * @param object $feed The product feed object.
         * @param array  $props_to_update The updated properties.
         */
        do_action( 'adt_after_process_category_mapping_tab_form', $feed, $props_to_update );
        // phpcs:enable WordPress.Security.NonceVerification
    }

    /**
     * Process filters and rules tab form submission.
     *
     * @since 13.4.4
     * @param object $feed The feed object.
     * @return void
     */
    private function process_filters_rules_tab_form( $feed ) {
        // phpcs:disable WordPress.Security.NonceVerification

        // Process filters and rules data.
        $filters = isset( $_POST['rules'] ) ? Helper::sanitize_array( $_POST['rules'] ) : array(); // phpcs:ignore
        $rules   = isset( $_POST['rules2'] ) ? Helper::sanitize_array( $_POST['rules2'] ) : array(); // phpcs:ignore

        $props_to_update = array(
            'filters' => $filters,
            'rules'   => $rules,
        );

        /**
         * Filter the product feed properties to update for the filters and rules tab.
         *
         * @since 13.4.4
         * @param array  $props_to_update The product feed properties to update.
         * @param object $feed The product feed object.
         */
        $props_to_update = apply_filters( 'adt_edit_feed_filters_rules_tab_props', $props_to_update, $feed );

        // Update feed properties.
        $feed->set_props( $props_to_update );
        $feed->save();

        /**
         * Action after processing the filters and rules tab form.
         *
         * @since 13.4.4
         * @param object $feed The product feed object.
         * @param array  $props_to_update The updated properties.
         */
        do_action( 'adt_after_process_filters_rules_tab_form', $feed, $props_to_update );
        // phpcs:enable WordPress.Security.NonceVerification
    }

    /**
     * Process conversion and analytics tab form submission.
     *
     * @since 13.4.4
     * @param object $feed The feed object.
     * @return void
     */
    private function process_conversion_analytics_tab_form( $feed ) {
        // phpcs:disable WordPress.Security.NonceVerification

        // Process conversion and analytics data.
        $props_to_update = array(
            'utm_enabled'  => isset( $_POST['utm_on'] ) ? true : false,
            'utm_source'   => isset( $_POST['utm_source'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_source'] ) ) : '',
            'utm_medium'   => isset( $_POST['utm_medium'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_medium'] ) ) : '',
            'utm_campaign' => isset( $_POST['utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_campaign'] ) ) : '',
            'utm_term'     => isset( $_POST['utm_term'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_term'] ) ) : '',
            'utm_content'  => isset( $_POST['utm_content'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_content'] ) ) : '',
        );

        /**
         * Filter the product feed properties to update for the conversion and analytics tab.
         *
         * @since 13.4.4
         * @param array  $props_to_update The product feed properties to update.
         * @param object $feed The product feed object.
         */
        $props_to_update = apply_filters( 'adt_edit_feed_conversion_analytics_tab_props', $props_to_update, $feed );

        // Update feed properties.
        $feed->set_props( $props_to_update );
        $feed->save();

        /**
         * Action after processing the conversion and analytics tab form.
         *
         * @since 13.4.4
         * @param object $feed The product feed object.
         * @param array  $props_to_update The updated properties.
         */
        do_action( 'adt_after_process_conversion_analytics_tab_form', $feed, $props_to_update );
        // phpcs:enable WordPress.Security.NonceVerification
    }

    /**
     * Create product feed.
     *
     * This method is used to create the product feed after generating the products from the legacy code base.
     *
     * @since 13.3.5
     * @access private
     *
     * @param array $feed_data Project data from the legacy code base.
     */
    private function create_product_feed( $feed_data ) {
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'woosea_ajax_nonce' ) ) {
            wp_send_json_error( __( 'Invalid security token', 'woo-product-feed-pro' ) );
        }

        if ( ! Helper::is_current_user_allowed() ) {
            wp_send_json_error( __( 'You do not have permission to manage product feed.', 'woo-product-feed-pro' ) );
        }

        // Get the total amount of products in the feed.
        if ( isset( $feed_data['product_variations'] ) && 'on' === $feed_data['product_variations'] ) {
            $feed_data['nr_products'] = Product_Feed_Helper::get_total_published_products( true );
        } else {
            $feed_data['nr_products'] = Product_Feed_Helper::get_total_published_products();
        }

        $product_feed = Product_Feed_Helper::get_product_feed();
        $country_code = isset( $feed_data['countries'] ) ? Product_Feed_Helper::get_code_from_legacy_country_name( $feed_data['countries'] ) : '';

        /**
         * Filter the product feed properties.
         *
         * @since 13.3.7
         * @param array        $props        The product feed properties.
         * @param Product_Feed $product_feed The product feed instance.
         * @param array        $feed_data The project data from form submission.
         * @return array
         */
        $product_feed->set_props(
            apply_filters(
                'adt_create_product_feed_props',
                array(
                    'title'                             => $feed_data['projectname'] ?? '',
                    'status'                            => 'processing',
                    'country'                           => $country_code,
                    'channel_hash'                      => $feed_data['channel_hash'] ?? '',
                    'file_name'                         => $feed_data['project_hash'] ?? '',
                    'file_format'                       => $feed_data['fileformat'] ?? '',
                    'delimiter'                         => $feed_data['delimiter'] ?? '',
                    'refresh_interval'                  => $feed_data['cron'] ?? '',
                    'include_product_variations'        => isset( $feed_data['product_variations'] ) && 'on' === $feed_data['product_variations'] ? 'yes' : 'no',
                    'only_include_default_product_variation' => isset( $feed_data['default_variations'] ) && 'on' === $feed_data['default_variations'] ? 'yes' : 'no',
                    'only_include_lowest_product_variation' => isset( $feed_data['lowest_price_variations'] ) && 'on' === $feed_data['lowest_price_variations'] ? 'yes' : 'no',
                    'create_preview'                    => isset( $feed_data['preview_feed'] ) && 'on' === $feed_data['preview_feed'] ? 'yes' : 'no',
                    'refresh_only_when_product_changed' => isset( $feed_data['products_changed'] ) && 'on' === $feed_data['products_changed'] ? 'yes' : 'no',
                    'attributes'                        => $feed_data['attributes'] ?? array(),
                    'mappings'                          => $feed_data['mappings'] ?? array(),
                    'filters'                           => $feed_data['rules'] ?? array(),
                    'rules'                             => $feed_data['rules2'] ?? array(),
                    'products_count'                    => $feed_data['nr_products'] ?? 0,
                    'total_products_processed'          => $feed_data['nr_products_processed'] ?? 0,
                    'utm_enabled'                       => isset( $feed_data['utm_on'] ) && 'on' === $feed_data['utm_on'] ? 'yes' : 'no',
                    'utm_source'                        => $feed_data['utm_source'] ?? '',
                    'utm_medium'                        => $feed_data['utm_medium'] ?? '',
                    'utm_campaign'                      => $feed_data['utm_campaign'] ?? '',
                    'utm_term'                          => $feed_data['utm_term'] ?? '',
                    'utm_content'                       => $feed_data['utm_content'] ?? '',
                    'utm_total_product_orders_lookback' => $feed_data['total_product_orders_lookback'] ?? '',
                    'legacy_project_hash'               => $feed_data['project_hash'] ?? '',
                ),
                $product_feed,
                $feed_data
            )
        );

        /**
         * Action before saving the product feed.
         *
         * @since 13.3.7
         *
         * @param Product_Feed_Factory $product_feed The new product feed.
         * @param array                $feed_data The project data from form submission.
         */
        do_action( 'adt_create_product_feed_before_save', $product_feed, $feed_data );

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
     * Clear the temporary product feed data when a user first loads the page for creating a new feed.
     *
     * @since 13.4.4
     * @return void
     */
    public function maybe_clear_temp_product_feed() {
        // Get current screen to ensure we're on the edit feed page.
        $screen = get_current_screen();

        // Only proceed if we're on the edit feed page and it's the first visit to create a new feed.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ( 'product-feed-pro_page_adt-edit-feed' === $screen->id || 'product-feed-elite_page_adt-edit-feed' === $screen->id ) && ! isset( $_GET['id'] ) && ! isset( $_GET['tab'] ) ) {
            // Clear the temporary product feed data.
            delete_option( ADT_OPTION_TEMP_PRODUCT_FEED );
        }
    }

    /**
     * AJAX handler to check if required fields are filled in the temporary feed data.
     *
     * @since 13.4.4
     * @return void
     */
    public function ajax_check_temp_feed_required_fields() {
        // Verify the nonce.
        check_ajax_referer( 'adt_nonce', 'nonce' );

        // Check user capabilities.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'You do not have permission to perform this action.', 'woo-product-feed-pro' ),
                    'errors'  => array( __( 'Permission denied.', 'woo-product-feed-pro' ) ),
                )
            );
        }

        // Get the temporary feed data.
        $temp_feed_data = get_option( ADT_OPTION_TEMP_PRODUCT_FEED, array() );
        $errors         = array();

        // Check project name (should be already checked client-side, but double-check).
        if ( empty( $temp_feed_data['projectname'] ) ) {
            $errors[] = __( 'Feed title is required.', 'woo-product-feed-pro' );
        }

        // Check channel selection.
        if ( empty( $temp_feed_data['channel_hash'] ) ) {
            $errors[] = __( 'Channel field is required.', 'woo-product-feed-pro' );
        }

        // Check if there are any attributes set in the field mapping tab.
        if ( empty( $temp_feed_data['attributes'] ) || ! is_array( $temp_feed_data['attributes'] ) ) {
            $errors[] = __( 'Field mapping is empty or you haven\'t saved the field mapping. Go to the Field Mapping tab and save the field mapping.', 'woo-product-feed-pro' );
        }

        // If we have errors, send them back.
        if ( ! empty( $errors ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Required feed configuration fields are missing.', 'woo-product-feed-pro' ),
                    'errors'  => $errors,
                )
            );
        }

        // Everything looks good.
        wp_send_json_success(
            array(
                'message' => __( 'All required fields are filled.', 'woo-product-feed-pro' ),
            )
        );
    }

    /**
     * Run the admin page.
     *
     * @since 13.4.4
     */
    public function run() {
        parent::run();

        // Add hook to clear temporary product feed data when on the edit feed page.
        add_action( 'current_screen', array( $this, 'maybe_clear_temp_product_feed' ) );

        add_filter( 'admin_footer_text', array( $this, 'edit_feed_footer_text' ) );

        // Register admin-post.php hooks for form processing.
        add_action( 'admin_post_edit_feed_form_process', array( $this, 'process_form_submission' ) );

        // Register AJAX endpoint to check required fields in temporary feed data.
        add_action( 'wp_ajax_check_temp_feed_required_fields', array( $this, 'ajax_check_temp_feed_required_fields' ) );
    }
}
