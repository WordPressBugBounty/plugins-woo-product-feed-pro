<?php
/**
 * Uninstall script for AdTribes Product Feed Plugin Pro.
 *
 * @package AdTribes\PFP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'woosea_cron_hook' );
