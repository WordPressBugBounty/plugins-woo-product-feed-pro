<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Classes\UCP
 */

namespace AdTribes\PFP\Classes\UCP;

use AdTribes\PFP\Traits\Singleton_Trait;

defined( 'ABSPATH' ) || exit;

/**
 * UCP connector settings storage.
 *
 * Site-level configuration for the UCP connector, stored as a single
 * WordPress option. This class is intentionally not registered as a
 * bootstrap object — it has no hooks and is consumed by the other UCP
 * classes directly.
 *
 * @since 13.6.0
 */
class Settings {

    use Singleton_Trait;

    /**
     * The option name that holds all UCP connector settings.
     *
     * @since 13.6.0
     * @var string
     */
    const OPTION_NAME = 'adt_ucp_settings';

    /**
     * Get the default settings.
     *
     * @since 13.6.0
     * @access public
     *
     * @return array Default settings.
     */
    public static function get_defaults() {

        return array(
            'enabled'                => 'no', // Merchant opt-in toggle (feature on/off, tracked separately from pre-flight).
            'staging_mode'           => 'no', // Serve the UCP surface for Google's validate/test flows before going live.
            'preflight_passed'       => 'no', // Gate: last pre-flight run passed all checks.
            'account_connected'      => 'no', // Gate: AdTribes account connected via OAuth.
            'google_approved'        => 'no', // Gate: merchant acknowledged Google UCP approval.
            'merchant_center_linked' => 'no', // Manual confirmation: store claimed in Merchant Center.
            'google_partner_id'      => '',   // Google webhook partner ID.
            'google_webhook_key'     => '',   // Google webhook API key.
            'eligibility_rules'      => array(), // Merchant eligibility rules ({match, conditions}).
        );
    }

    /**
     * Get all settings merged with defaults.
     *
     * @since 13.6.0
     * @access public
     *
     * @return array Settings.
     */
    public function get_all() {

        $settings = get_option( self::OPTION_NAME, array() );

        return wp_parse_args( is_array( $settings ) ? $settings : array(), self::get_defaults() );
    }

    /**
     * Get a single setting value.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $key           Setting key.
     * @param mixed  $default_value Value to return when the key is unknown.
     * @return mixed Setting value.
     */
    public function get( $key, $default_value = null ) {

        $settings = $this->get_all();

        return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default_value;
    }

    /**
     * Set a single setting value.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $key   Setting key.
     * @param mixed  $value Setting value.
     * @return bool True if the option was updated.
     */
    public function set( $key, $value ) {

        // Persist only explicit overrides — defaults are applied at read time
        // by get_all(), so never-customized keys stay out of the stored option.
        $settings = get_option( self::OPTION_NAME, array() );
        $settings = is_array( $settings ) ? $settings : array();

        $settings[ $key ] = $value;

        return update_option( self::OPTION_NAME, $settings );
    }

    /**
     * Delete all UCP settings.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool True if the option was deleted.
     */
    public function delete_all() {

        return delete_option( self::OPTION_NAME );
    }
}
