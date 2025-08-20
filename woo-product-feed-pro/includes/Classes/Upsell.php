<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Traits\Singleton_Trait;
use AdTribes\PFP\Helpers\Helper;
use AdTribes\PFP\Factories\Vite_App;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Usage tracking class.
 *
 * @since 13.4.7
 */
class Upsell extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Get the upsell l10n.
     *
     * @since 13.4.7
     * @return array
     */
    public function upsell_l10n() {
        $popup_upsells = array(
            'default'                   => array(
                'title'   => __( 'Upgrade to Product Feed Elite for WooCommerce', 'woo-product-feed-pro' ),
                'message' => __( 'In Product Feed Pro Elite for WooCommerce you get access to advanced features like extra fields (GTIN, MPN, EAN, etc.), advanced filters and rules, product data manupulation, and more. Perfect for scaling your e-commerce business across multiple platforms.', 'woo-product-feed-pro' ),
                'link'    => Helper::get_utm_url( 'pricing', 'pfp', 'upsell', 'modal' ),
            ),
            'rule_action_set_attribute' => array(
                'title'   => __( 'Upgrade to Set Attributes', 'woo-product-feed-pro' ),
                'message' => __( 'In Product Feed ELITE for WooCommerce you can dynamically set and modify product attributes on-the-fly. This is perfect for customizing product data for different channels, adding custom labels, and optimizing feed data without touching your product catalog.', 'woo-product-feed-pro' ),
                'link'    => Helper::get_utm_url( 'pricing', 'pfp', 'upsell', 'setattrrules' ),
            ),
        );

        /**
         * Filter the upsell l10n.
         *
         * @since 13.4.7
         * @param array $l10n The upsell l10n.
         * @return array The filtered upsell l10n.
         */
        $popup_upsells = apply_filters( 'adt_pfp_upsell_l10n', $popup_upsells );

        $upsell_l10n = array();
        foreach ( $popup_upsells as $key => $args ) {
            $upsell_l10n[ $key ]['title']   = $args['title'];
            $upsell_l10n[ $key ]['content'] = $this->_generate_upsell_popup_content( $args );
        }

        return $upsell_l10n;
    }

        /**
         * Generate upsell popup content html markup.
         *
         * @since 4.5.1
         * @access private
         *
         * @param array $args Content arguments.
         * @return string Content markup.
         */
    private function _generate_upsell_popup_content( $args ) {

        $args = wp_parse_args(
            $args,
            array(
                'title'   => '',
                'message' => '',
                'link'    => '',
            )
        );

        // Extracted variables are defined above.
        extract( $args ); // phpcs:ignore

        $html  = sprintf( '<img src="%1$s" alt="%2$s" />', ADT_PFP_IMAGES_URL . '/logo.png', __( 'Product Feed Pro for WooCommerce', 'woo-product-feed-pro' ) );
        $html .= sprintf( '<h3>%s</h3>', $title );
        $html .= sprintf( '<p>%s</p>', $message );
        if ( ! empty( $link ) ) {
            $html .= sprintf( '<a href="%s" target="_blank">%s</a>', $link, __( 'See all features & pricing →', 'woo-product-feed-pro' ) );
        }

        return wp_kses_post( $html );
    }

    /**
     * Enqueue admin styles and scripts.
     *
     * @since 13.4.6
     * @access public
     */
    public function admin_enqueue_scripts() {
        // Enqueue scripts and styles only on the plugin pages.
        if ( ! Helper::is_plugin_page() ) {
            return;
        }

        // Vex Modal.
        wp_enqueue_style( 'adt-vex', ADT_PFP_JS_URL . 'lib/vex/vex.css', array(), WOOCOMMERCESEA_PLUGIN_VERSION );
        wp_enqueue_style( 'adt-vex-theme-plain', ADT_PFP_JS_URL . 'lib/vex/vex-theme-plain.css', array(), WOOCOMMERCESEA_PLUGIN_VERSION );
        wp_enqueue_script( 'adt-vex', ADT_PFP_JS_URL . 'lib/vex/vex.combined.min.js', array( 'jquery' ), WOOCOMMERCESEA_PLUGIN_VERSION, true );
        wp_add_inline_script( 'adt-vex', 'vex.defaultOptions.className = "vex-theme-plain"', 'after' );

        // Vite app - Upsell.
        $app = new Vite_App(
            'adt-upsell-script',
            'src/vanilla/upsell/index.ts',
            array( 'jquery' ),
            array(),
            'adtObj',
            array()
        );
        $app->enqueue();
    }

    /**
     * Execute Notices class.
     *
     * @since 13.3.9
     * @access public
     */
    public function run() {
        // Enqueue admin styles and scripts.
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
    }
}
