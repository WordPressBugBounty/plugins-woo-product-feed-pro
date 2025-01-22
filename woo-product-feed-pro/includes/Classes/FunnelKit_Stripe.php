<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Helpers\Helper;
use AdTribes\PFP\Traits\Singleton_Trait;

/**
 * Heartbeat class.
 *
 * @since 13.4.1
 */
class FunnelKit_Stripe extends Abstract_Class {

    use Singleton_Trait;

    const SHOW_FUNNELKIT_STRIPE_PROMOTE_POINTER = 'adt_pfp_show_funnelkit_stripe_promote_pointer';

    /**
     * The FunnelKit Stripe plugin basename.
     *
     * @since 13.4.1
     * @access private
     *
     * @var string
     */
    private $_basename = 'funnelkit-stripe-woo-payment-gateway/funnelkit-stripe-woo-payment-gateway.php';

    /**
     * Check if the FunnelKit Stripe plugin is installed.
     *
     * @since 13.4.1
     * @access private
     *
     * @return bool
     */
    private function is_installed() {
        if ( Helper::is_plugin_installed( $this->_basename ) ) {
            return true;
        }

        return false;
    }

    /**
     * Add an admin bar menu item.
     *
     * @since 13.4.1
     * @access public
     *
     * @param WP_Admin_Bar $wp_admin_bar The WP_Admin_Bar instance.
     */
    public function admin_bar_menu( $wp_admin_bar ) {
        // Skip if not in admin.
        if ( ! is_admin() ) {
            return;
        }

        // Show in plugin page and admin dashboard.
        $current_screen = get_current_screen();
        if ( ! Helper::is_plugin_page() && 'dashboard' !== $current_screen->id ) {
            return;
        }

        // Skip if the FunnelKit Stripe promote pointer is dismissed or not set.
        if ( get_option( self::SHOW_FUNNELKIT_STRIPE_PROMOTE_POINTER, '' ) !== 'yes' ) {
            return;
        }

        // Only show the pointer if the user can install or activate plugins and the FunnelKit Stripe plugin is not installed.
        if ( $this->is_installed() || ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) ) {
            return;
        }

        $wp_admin_bar->add_menu(
            array(
                'id'    => 'adt-pfp-funnelkit-stripe',
                'title' => '<img class="menu-icon" src="' . WOOCOMMERCESEA_IMAGES_URL . '/stripe-logo.png"/><span class="menu-title">' . __( 'Stripe', 'woo-product-feed-pro' ) . '</span>',
                'href'  => '#',
            )
        );
    }

    /**
     * Enqueue scripts.
     *
     * @since 13.4.1
     * @access public
     */
    public function enqueue_scripts() {
        // Show in plugin page and admin dashboard.
        global $current_screen;
        if ( ! Helper::is_plugin_page() && 'dashboard' !== $current_screen->id ) {
            return;
        }

        // Skip if the FunnelKit Stripe promote pointer is dismissed or not set.
        if ( get_option( self::SHOW_FUNNELKIT_STRIPE_PROMOTE_POINTER, '' ) !== 'yes' ) {
            return;
        }

        // Only show the pointer if the user can install or activate plugins and the FunnelKit Stripe plugin is not installed.
        if ( $this->is_installed() || ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) ) {
            return;
        }

        $heading  = __( 'Setup FunnelKit Stripe (Recommended)', 'woo-product-feed-pro' );
        $content  = '<p>' . __( 'Use FunnelKit\'s Stripe for Maximum Compatibility & Trustworthy Support', 'woo-product-feed-pro' ) . '</p>';
        $content .= '<ul>';
        $content .= '<li>' . __( 'Better Express Payment with Apple & Google Pay', 'woo-product-feed-pro' ) . '</li>';
        $content .= '<li>' . __( 'Supports One Click Upsells with Express Pay Options', 'woo-product-feed-pro' ) . '</li>';
        $content .= '<li>' . __( 'Personalised Support for any payments related issues', 'woo-product-feed-pro' ) . '</li>';
        $content .= '<li>' . __( 'Increase Revenue with Buy Now Pay Later services such as Affirm, Klarna and AfterPay', 'woo-product-feed-pro' ) . '</li>';
        $content .= '</ul>';

        // Change the heading if the WooCommerce Gateway Stripe plugin is active.
        if ( Helper::is_plugin_active( 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php' ) ) {
            $heading = __( 'Swap To FunnelKit Stripe (Recommended)', 'woo-product-feed-pro' );
        }

        wp_enqueue_script( 'wp-pointer' );
        wp_enqueue_style( 'wp-pointer' );

        wp_enqueue_style(
            'adt-pfp-funnelkit-stripe',
            WOOCOMMERCESEA_PLUGIN_URL . '/css/funnelkit-stripe.css',
            array( 'wp-pointer' ),
            WOOCOMMERCESEA_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'adt-pfp-funnelkit-stripe',
            WOOCOMMERCESEA_PLUGIN_URL . '/js/funnelkit-stripe.js',
            array( 'jquery', 'wp-pointer' ),
            WOOCOMMERCESEA_PLUGIN_VERSION,
            true
        );
        wp_localize_script(
            'adt-pfp-funnelkit-stripe',
            'adt_pfp_funnelkit_stripe',
            array(
                'nonce'         => wp_create_nonce( 'adt_pfp_funnelkit_stripe_nonce' ),
                'install_nonce' => wp_create_nonce( 'adt_install_plugin' ),
                'i18n'          => array(
                    'heading'                   => $heading,
                    'content'                   => $content,
                    'install_button'            => __( 'Install', 'woo-product-feed-pro' ),
                    'install_button_installing' => __( 'Installing...', 'woo-product-feed-pro' ),
                    'install_button_settings'   => __( 'Go to Settings', 'woo-product-feed-pro' ),
                    'install_button_connect'    => __( 'Connect', 'woo-product-feed-pro' ),
                    'dismiss_button'            => __( 'Dismiss Forever', 'woo-product-feed-pro' ),
                ),
            )
        );
    }

    /**
     * Update funnelkit partner key after install and activate plugin.
     *
     * @since 13.4.1
     * @access public
     *
     * @param string         $plugin_slug Filtered plugin slug.
     * @param bool|\WP_Error $result Filtered result install activate plugin.
     */
    public function update_funnelkit_stripe_promote_after_install_activate_plugin( $plugin_slug, $result ) {
        // If plugin is not funnel kit stripe, then return.
        if ( 'funnelkit-stripe-woo-payment-gateway' !== $plugin_slug ) {
            return;
        }

        // Set partner key if the result is true.
        if ( ! is_wp_error( $result ) ) {
            update_option( 'fkwcs_wp_stripe', '5411cecc0ff56e5657e46cb9b11b94bf', false );
            update_option( self::SHOW_FUNNELKIT_STRIPE_PROMOTE_POINTER, 'dismissed', false );
        }
    }

    /**
     * Schedule funnelkit stripe promote cron notice.
     *
     * @since 13.4.1
     * @access private
     */
    public function schedule_funnelkit_stripe_promote() {
        // Skip if the funnelkit stripe promote is already scheduled or funnelkit stripe is already installed.
        if ( \WC()->queue()->get_next( self::SHOW_FUNNELKIT_STRIPE_PROMOTE_POINTER, array(), self::SHOW_FUNNELKIT_STRIPE_PROMOTE_POINTER ) instanceof \WC_DateTime
            || $this->is_installed() ) {
            return;
        }

        // Schedule the funnelkit stripe prmote for 21 days.
        \WC()->queue()->schedule_single(
            time() + ( 21 * DAY_IN_SECONDS ),
            self::SHOW_FUNNELKIT_STRIPE_PROMOTE_POINTER,
            array(),
            self::SHOW_FUNNELKIT_STRIPE_PROMOTE_POINTER
        );
    }

    /**
     * Show funnelkit stripe promote notice.
     *
     * @since 13.4.1
     * @access public
     */
    public function show_funnelkit_stripe_promote_notice() {
        if ( get_option( self::SHOW_FUNNELKIT_STRIPE_PROMOTE_POINTER ) === 'dismissed' ) {
            return;
        }

        update_option( self::SHOW_FUNNELKIT_STRIPE_PROMOTE_POINTER, 'yes', false );
    }

    /***************************************************************************
     * AJAX ACTIONS
     * **************************************************************************
     */

    /**
     * Dismiss the FunnelKit Stripe pointer.
     *
     * @since 13.4.1
     * @access public
     */
    public function ajax_dismiss_pointer() {
        check_ajax_referer( 'adt_pfp_funnelkit_stripe_nonce', 'nonce' );

        update_option( self::SHOW_FUNNELKIT_STRIPE_PROMOTE_POINTER, 'dismissed', false );
        wp_send_json_success();
    }

    /**
     * Get the FunnelKit Stripe connect link.
     *
     * @since 13.4.1
     * @access public
     */
    public function ajax_get_funnelkit_stripe_connect_link() {
        check_ajax_referer( 'adt_pfp_funnelkit_stripe_nonce', 'nonce' );

        if ( ! class_exists( '\FKWCS\Gateway\Stripe\Admin' ) ) {
            wp_send_json_error( array( 'message' => __( 'Error: FunnelKit Stripe Admin class not found.', 'woo-product-feed-pro' ) ) );
        }

        if ( \FKWCS\Gateway\Stripe\Admin::get_instance()->is_stripe_connected() ) {
            wp_send_json_success(
                array(
                    'connected' => true,
                    'url'       => 'admin.php?page=wc-settings&tab=fkwcs_api_settings',
                )
            );
        } else {
            wp_send_json_success(
                array(
                    'connected' => false,
                    'url'       => \FKWCS\Gateway\Stripe\Admin::get_instance()->get_connect_url(),
                )
            );
        }
    }

    /**
     * Run the class
     *
     * @codeCoverageIgnore
     * @since 13.4.1
     */
    public function run() {
        add_action( self::SHOW_FUNNELKIT_STRIPE_PROMOTE_POINTER, array( $this, 'show_funnelkit_stripe_promote_notice' ) );

        add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 9999 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'adt_after_install_activate_plugin', array( $this, 'update_funnelkit_stripe_promote_after_install_activate_plugin' ), 10, 2 );

        // Ajax actions.
        add_action( 'wp_ajax_adt_pfp_dismiss_funnelkit_stripe_pointer', array( $this, 'ajax_dismiss_pointer' ) );
        add_action( 'wp_ajax_adt_pfp_get_funnelkit_stripe_connect_link', array( $this, 'ajax_get_funnelkit_stripe_connect_link' ) );
    }
}
