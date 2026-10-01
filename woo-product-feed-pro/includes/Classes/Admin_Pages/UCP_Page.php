<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes\Admin_Pages
 */

namespace AdTribes\PFP\Classes\Admin_Pages;

use AdTribes\PFP\Abstracts\Admin_Page;
use AdTribes\PFP\Classes\UCP\Orders;
use AdTribes\PFP\Factories\Vite_App;
use AdTribes\PFP\Traits\Singleton_Trait;

defined( 'ABSPATH' ) || exit;

/**
 * UCP_Page class.
 *
 * The "AI Discovery" admin page (UCP connector — no "Google" in
 * product-facing labels): status hero, pre-flight checklist with fix-it
 * guidance, Google integration panel (profile URL, API keys, webhook
 * partner ID), payments panel and eligibility management. Rendered by
 * the Vue app in src/apps/ucp/.
 *
 * @since 13.6.0
 */
class UCP_Page extends Admin_Page {

    use Singleton_Trait;

    const MENU_SLUG = 'pfp-ucp-page';

    /**
     * Holds the class instance object
     *
     * @since 13.6.0
     * @access protected
     *
     * @var Singleton_Trait $instance object
     */
    protected static $instance;

    /**
     * Initialize the class.
     *
     * @since 13.6.0
     */
    public function init() {
        $this->parent_slug = 'woo-product-feed';
        $this->page_title  = __( 'AI Discovery', 'woo-product-feed-pro' );
        $this->menu_title  = __( 'AI Discovery', 'woo-product-feed-pro' )
            . ' <span class="adt-menu-new-tag">' . __( 'NEW', 'woo-product-feed-pro' ) . '</span>';
        $this->capability  = apply_filters( 'adt_pfp_admin_capability', 'manage_options' );
        $this->menu_slug   = self::MENU_SLUG;
        $this->template    = 'ucp-page.php';
        $this->position    = 25;
    }

    /**
     * Get the admin menu priority.
     *
     * @since 13.6.0
     * @return int
     */
    protected function get_priority() {
        return 25;
    }

    /**
     * Enqueue the UCP admin app.
     *
     * @since 13.6.0
     * @return void
     */
    protected function enqueue_scripts() {

        $app = new Vite_App(
            'adt-ucp-app',
            'src/apps/ucp/index.ts',
            array( 'wp-i18n' ),
            array(
                'rest_url'    => esc_url_raw( untrailingslashit( rest_url( 'adtribes/v1/ucp' ) ) ),
                'nonce'       => wp_create_nonce( 'wp_rest' ),
                'profile_url' => esc_url_raw( home_url( '/.well-known/ucp' ) ),
                'orders_url'  => esc_url_raw( Orders::get_orders_list_url() ),
            ),
            'adtObjUcp'
        );

        $app->enqueue();
    }
}
