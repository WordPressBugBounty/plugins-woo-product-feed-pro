<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Actions
 */

namespace AdTribes\PFP\Actions;

use AdTribes\PFP\Abstracts\Abstract_Class;

/**
 * Deactivation class.
 *
 * @since 13.3.3
 */
class Deactivation extends Abstract_Class {

    /**
     * Holds boolean value whether the plugin is being activated network wide.
     *
     * @since 13.3.3
     * @access protected
     *
     * @var bool
     */
    protected $network_wide;

    /**
     * Constructor.
     *
     * @since 13.3.3
     * @access public
     *
     * @param bool $network_wide Whether the plugin is being activated network wide.
     */
    public function __construct( $network_wide ) {

        $this->network_wide = $network_wide;
    }

    /**
     * Plugin deactivation.
     *
     * @since 13.3.5
     * @access public
     *
     * @param int $blog_id Blog ID.
     */
    private function _deactivate_plugin( $blog_id ) {
        delete_option( 'adt_pfp_activation_code_triggered' );
        delete_site_option( WOOCOMMERCESEA_OPTION_INSTALLED_VERSION );

        $this->cleanup_cron();
        $this->cleanup_options();
    }

    /**
     * Cleanup cron.
     *
     * @since 13.3.3
     * @access public
     */
    protected function cleanup_cron() {
        wp_clear_scheduled_hook( 'woosea_cron_hook' );
    }

    /**
     * Cleanup options.
     *
     * @since 13.3.3
     * @access public
     */
    protected function cleanup_options() {
        delete_option( 'woosea_getelite_notification' );
        delete_option( 'woosea_license_notification_closed' );
    }

    /**
     * Run plugin deactivation actions.
     *
     * @since 13.3.3
     * @access public
     */
    public function run() {
        // Delete the flag that determines if plugin activation code is triggered.
        global $wpdb;

        // check if it is a multisite network.
        if ( is_multisite() ) {

            // check if the plugin has been activated on the network or on a single site.
            if ( $this->network_wide ) {
                // get ids of all sites.
                $blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );

                foreach ( $blog_ids as $blog_id ) {
                    switch_to_blog( $blog_id );
                    $this->_deactivate_plugin( $blog_id );
                }

                restore_current_blog();
            } else {
                // activated on a single site, in a multi-site.
                $this->_deactivate_plugin( $wpdb->blogid );
            }
        } else {
            // activated on a single site.
            $this->_deactivate_plugin( $wpdb->blogid );
        }
    }
}
