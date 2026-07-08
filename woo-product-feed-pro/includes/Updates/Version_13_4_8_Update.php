<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Updates
 */

namespace AdTribes\PFP\Updates;

use AdTribes\PFP\Abstracts\Abstract_Update;

/**
 * Class Version_13_4_8_Update
 *
 * Gating/idempotency live in Abstract_Update; update() only writes a prefixed
 * option when it does not already exist, so a one-time re-run is a no-op.
 *
 * @since 13.4.8
 */
class Version_13_4_8_Update extends Abstract_Update {

    /**
     * Per-blog option name flagging this migration as complete.
     *
     * @since 13.5.6
     *
     * @var string
     */
    const MIGRATION_FLAG = 'adt_pfp_update_13_4_8_done';

    /**
     * Migrate unprefixed options to prefixed versions.
     *
     * @since 13.4.8
     */
    public function update() {
        // Define the options that need to be migrated from unprefixed to prefixed versions.
        //
        // Autoload notes:
        // - `true` is reserved for options read on every front-end request (tracking pixels,
        // remarketing tags, etc.). Their values are small flags/IDs.
        // - All other options are admin-only or feed/cron-only and use `false` to keep the
        // autoloaded options blob small.
        //
        // NOTE: For installs that already ran this migration before issue #919, the autoload
        // flag values below have no retroactive effect — the migration only writes the new
        // option when `false === get_option( $new_option )`. Existing rows whose autoload
        // needs flipping are handled by `Version_13_5_5_Update`.
        $options_to_migrate = array(
            'add_mother_image'               => array(
                'value'    => 'adt_use_parent_variable_product_image',
                'autoload' => false,
            ),
            'add_all_shipping'               => array(
                'value'    => 'adt_add_all_shipping',
                'autoload' => false,
            ),
            'free_shipping'                  => array(
                'value'    => 'adt_remove_other_shipping_classes_on_free_shipping',
                'autoload' => false,
            ),
            'remove_free_shipping'           => array(
                'value'    => 'adt_remove_free_shipping',
                'autoload' => false,
            ),
            'remove_local_pickup'            => array(
                'value'    => 'adt_remove_local_pickup_shipping',
                'autoload' => false,
            ),
            'add_woosea_basic'               => array(
                'value'    => 'adt_show_only_basis_attributes',
                'autoload' => false,
            ),
            'add_woosea_logging'             => array(
                'value'    => 'adt_enable_logging',
                'autoload' => false,
            ),
            'add_facebook_pixel'             => array(
                'value'    => 'adt_add_facebook_pixel',
                'autoload' => true,
            ),
            'facebook_pixel_id'              => array(
                'value'    => 'adt_facebook_pixel_id',
                'autoload' => true,
            ),
            'add_facebook_pixel_content_ids' => array(
                'value'    => 'adt_facebook_pixel_content_ids',
                'autoload' => true,
            ),
            'add_remarketing'                => array(
                'value'    => 'adt_add_remarketing',
                'autoload' => true,
            ),
            'adwords_conversion_id'          => array(
                'value'    => 'adt_adwords_conversion_id',
                'autoload' => true,
            ),
            'add_batch'                      => array(
                'value'    => 'adt_enable_batch',
                'autoload' => false,
            ),
            'woosea_batch_size'              => array(
                'value'    => 'adt_batch_size',
                'autoload' => false,
            ),
            'last_order_id'                  => array(
                'value'    => 'adt_last_order_id',
                'autoload' => false,
            ),
            'cron_projects'                  => array(
                'value'    => 'adt_cron_projects',
                'autoload' => false,
            ),
            'product_changes'                => array(
                'value'    => 'adt_product_changes',
                'autoload' => false,
            ),
        );

        foreach ( $options_to_migrate as $old_option => $new_option_config ) {
            $old_value = get_option( $old_option );

            // Handle both array and string formats for backward compatibility.
            $new_option = is_array( $new_option_config ) ? $new_option_config['value'] : $new_option_config;
            $autoload   = is_array( $new_option_config ) ? $new_option_config['autoload'] : true;

            // If the old option exists and the new one doesn't, migrate the value.
            if ( false !== $old_value && false === get_option( $new_option ) ) {
                update_option( $new_option, $old_value, $autoload );
            }
        }
    }
}
