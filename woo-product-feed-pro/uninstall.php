<?php
/**
 * Uninstall script for AdTribes Product Feed Plugin Pro.
 *
 * @package AdTribes\PFP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Function that houses the code that cleans up the plugin on un-installation.
 *
 * @since 13.3.4
 */
function adt_pfp_plugin_cleanup() {
    unregister_custom_capabilities();
}

/**
 * Unregister custom capabilities.
 *
 * @since 13.3.4
 *
 * @param bool $sitewide Whether to unregister custom capabilities for super admin.
 */
function unregister_custom_capabilities( $sitewide = false ) {
    if ( $sitewide ) {
        // Remove custom capabilities for super admin.
        $super_admins = get_super_admins();
        foreach ( $super_admins as $super_admin ) {
            $user = new \WP_User( $super_admin );
            $user->remove_cap( 'manage_adtribes_product_feeds' );
        }
    } else {
        // Get all roles.
        $roles = wp_roles()->roles;

        // Loop through each role and remove the custom capability.
        foreach ( $roles as $role_name => $role_info ) {
            $role = get_role( $role_name );
            if ( $role ) {
                $role->remove_cap( 'manage_adtribes_product_feeds' );
            }
        }
    }
}

if ( function_exists( 'is_multisite' ) && is_multisite() ) {
    // Get all blog ids.
    global $wpdb;
    $blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );

    foreach ( $blog_ids as $blogid ) {
        switch_to_blog( $blogid );
        adt_pfp_plugin_cleanup();
    }
    restore_current_blog();

    unregister_custom_capabilities( true );
} else {
    adt_pfp_plugin_cleanup();
}
