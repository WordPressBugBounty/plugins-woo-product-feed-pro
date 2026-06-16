<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Updates
 */

namespace AdTribes\PFP\Updates;

use AdTribes\PFP\Abstracts\Abstract_Class;

/**
 * Class Version_13_5_5_Update
 *
 * Flips `autoload` to `'no'` on plugin-owned options that are admin-only,
 * cron-only or one-shot. WordPress's `update_option()` only sets the
 * autoload flag the first time an option is created, so existing installs
 * that seeded these options as autoloaded need this one-shot migration.
 *
 * @since 13.5.5
 */
class Version_13_5_5_Update extends Abstract_Class {

    /**
     * Holds the version number.
     *
     * @since 13.5.5
     * @access protected
     *
     * @var string
     */
    protected $version = '13.5.5';

    /**
     * Whether to force update the options.
     *
     * @since 13.5.5
     * @access protected
     *
     * @var bool
     */
    protected $force_update = false;

    /**
     * Constructor.
     *
     * @since 13.5.5
     * @access public
     *
     * @param bool $force_update Whether to force update the options.
     */
    public function __construct( $force_update = false ) {
        $this->force_update = $force_update;
    }

    /**
     * Plugin-owned options that should NOT be autoloaded.
     *
     * Includes:
     * - Options whose `update_option()` call sites previously omitted the
     *   `$autoload` argument and may have been seeded as autoloaded.
     * - Options already passed as no-autoload at their call sites but which
     *   may have been seeded as autoloaded on older installs (before the
     *   `false` argument was added).
     *
     * @since 13.5.5
     * @access private
     *
     * @return array List of option names to flip to autoload=no.
     */
    private function get_options_to_disable_autoload() {
        // NOTE: `adt_pfp_activation_code_triggered` is intentionally NOT in this list — it is
        // read by `App::initialize()` on every `init` hook, so it must remain autoloaded.
        return array(
            // Previously omitted autoload arg (audited in issue #919).
            'woosea_allow_update',
            ADT_PFP_USAGE_CRON_CONFIG,
            ADT_PFP_USAGE_LAST_CHECKIN,
            ADT_PFP_USAGE_ALLOW,
            'adt_notification_meta',
            'adt_cron_projects',

            // Admin-only / cron-only / feed-only settings migrated by
            // Version_13_4_8_Update which were previously marked autoload=true.
            'adt_use_parent_variable_product_image',
            'adt_add_all_shipping',
            'adt_remove_other_shipping_classes_on_free_shipping',
            'adt_remove_free_shipping',
            'adt_remove_local_pickup_shipping',
            'adt_show_only_basis_attributes',
            'adt_enable_logging',
            'adt_enable_batch',
            'adt_batch_size',
            'adt_last_order_id',
            'adt_product_changes',

            // Other admin-only / cron-only options.
            'woosea_gs_analysis_results',
            ADT_OPTION_TEMP_PRODUCT_FEED,
            'woosea_first_activation',
            'woosea_count_activation',
            'woosea_getelite_notification',
            'adt_use_legacy_filters_and_rules',
            'adt_disable_http_feed_generation',
            'adt_pfp_enable_image_size_validation',
            'adt_clean_up_plugin_data',

            // Per-notice flags registered in `Notices::_get_cron_notices()`. These are written
            // via the dynamic `$notice['option']` path which now passes autoload=no, but older
            // installs may have seeded them as autoloaded.
            'adt_pfp_show_review_request_notice',
            'adt_show_store_agent_recommendation_notice',
            'adt_show_saveto_wishlist_recommendation_notice',
        );
    }

    /**
     * Flip `autoload` to `'no'` for plugin-owned admin/cron-only options.
     *
     * Uses `wp_set_options_autoload()` when available (WP 6.4+); falls back
     * to a direct `UPDATE` on `wp_options` and an `alloptions` cache flush
     * for older WP (plugin min is 5.9).
     *
     * @since 13.5.5
     */
    public function update() {
        $option_names = $this->get_options_to_disable_autoload();

        // Include any dynamic rows present in this site:
        // - `batch_project_*` — per-feed batch projects.
        // - `pfp_*_marketing_page_closed` — Marketing page closed flag per plugin key
        // (e.g. `pfp_elite_marketing_page_closed`); names are built at runtime in
        // `Marketing::close_marketing_page()` so we can't hardcode them all.
        global $wpdb;
        $dynamic_rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options}
                 WHERE ( option_name LIKE %s ESCAPE '\\\\' OR option_name LIKE %s ESCAPE '\\\\' )
                 AND autoload IN ( 'yes', 'on', 'auto-on' )",
                $wpdb->esc_like( 'batch_project_' ) . '%',
                $wpdb->esc_like( 'pfp_' ) . '%' . $wpdb->esc_like( '_marketing_page_closed' )
            )
        );
        if ( $dynamic_rows ) {
            $option_names = array_merge( $option_names, $dynamic_rows );
        }

        if ( function_exists( 'wp_set_options_autoload' ) ) {
            wp_set_options_autoload( $option_names, false );
            return;
        }

        // Fallback for WP < 6.4.
        $placeholders = implode( ',', array_fill( 0, count( $option_names ), '%s' ) );
        $wpdb->query(
            $wpdb->prepare(
                // The IN() placeholders are built dynamically from a hardcoded `%s` repeated
                // once per element of `$option_names`; PHPCS can't see them inside the
                // interpolated `{$placeholders}`.
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                "UPDATE {$wpdb->options} SET autoload = 'no' WHERE option_name IN ({$placeholders}) AND autoload IN ('yes', 'on', 'auto-on')",
                $option_names
            )
        );

        // Bust the alloptions cache so subsequent reads do not contain the flipped rows.
        wp_cache_delete( 'alloptions', 'options' );
    }

    /**
     * Run the migration.
     *
     * Runs against the CURRENT blog only. `Activation::run()` already loops
     * `$blog_ids` and calls `_activate_plugin()` (which invokes this method)
     * per blog, so this method must not loop blogs itself.
     *
     * @since 13.5.5
     */
    public function run() {
        if (
            (
                version_compare( get_site_option( ADT_PFP_OPTION_INSTALLED_VERSION ), $this->version, '<' ) ||
                ! get_site_option( ADT_PFP_OPTION_INSTALLED_VERSION )
            ) || $this->force_update
        ) {
            $this->update();
        }
    }
}
