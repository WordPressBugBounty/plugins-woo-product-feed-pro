<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Helpers
 */

namespace AdTribes\PFP\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Field-mapping helpers shared across editor-augmentation and feed-generation paths.
 *
 * @since 13.5.5
 */
class Field_Mapping_Helper {

    /**
     * Return the feed's saved field mapping, or `null` if it's missing/empty.
     *
     * When `$feed` is a real `Product_Feed` object, reads `$feed->attributes`.
     * When it is `null` (the new-feed path of the filters/rules REST endpoint),
     * falls back to the in-progress temp option so the rules builder reflects
     * whatever the user has just configured in the Field Mapping tab before
     * saving.
     *
     * Implementation note: `Product_Feed` exposes `attributes` through `__get`
     * but defines no `__isset`. In PHP 8, `empty( $feed->attributes )` on that
     * path consults `__isset` first and returns `true` regardless of contents.
     * Reading the property into a local variable before checking sidesteps
     * that — keep the workaround in one place so it can't drift.
     *
     * @since 13.5.5
     * @access public
     *
     * @param mixed $feed The feed object, or `null` for the new-feed flow.
     * @return array|null The field mapping array, or null when unavailable.
     */
    public static function get_feed_field_mapping( $feed ) {
        if ( is_object( $feed ) ) {
            $field_mapping = $feed->attributes;
            if ( ! is_array( $field_mapping ) || empty( $field_mapping ) ) {
                return null;
            }
            return $field_mapping;
        }

        if ( null === $feed && defined( 'ADT_OPTION_TEMP_PRODUCT_FEED' ) ) {
            $temp_feed_data = get_option( ADT_OPTION_TEMP_PRODUCT_FEED, array() );
            if ( ! is_array( $temp_feed_data ) ) {
                return null;
            }
            $field_mapping = $temp_feed_data['attributes'] ?? array();
            if ( is_array( $field_mapping ) && ! empty( $field_mapping ) ) {
                return $field_mapping;
            }
        }

        return null;
    }
}
