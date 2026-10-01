<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes\Admin_Pages
 */

namespace AdTribes\PFP\Classes\Admin_Pages;

use AdTribes\PFP\Abstracts\Admin_Page;
use AdTribes\PFP\Traits\Singleton_Trait;

/**
 * Feed_Validator_Page class.
 *
 * Product-education page for the Feed Validator tool, which lives in the
 * AdTribes account area and ships with the Plus and Business plans.
 *
 * @since 13.5.8
 */
class Feed_Validator_Page extends Admin_Page {

    use Singleton_Trait;

    const MENU_SLUG = 'pfp-feed-validator';

    /**
     * Holds the class instance object.
     *
     * @since 13.5.8
     * @access protected
     *
     * @var Singleton_Trait $instance object
     */
    protected static $instance;

    /**
     * Initialize the class.
     *
     * @since 13.5.8
     */
    public function init() {
        $this->parent_slug = 'woo-product-feed';
        $this->page_title  = __( 'Feed Validator', 'woo-product-feed-pro' );
        $this->menu_title  = __( 'Feed Validator', 'woo-product-feed-pro' );
        $this->capability  = apply_filters( 'adt_pfp_admin_capability', 'manage_options' );
        $this->menu_slug   = self::MENU_SLUG;
        $this->template    = 'feed-validator-page.php';
        $this->position    = 45;
    }

    /**
     * Get the admin menu priority.
     *
     * @since 13.5.8
     * @return int
     */
    protected function get_priority() {
        return 45;
    }
}
