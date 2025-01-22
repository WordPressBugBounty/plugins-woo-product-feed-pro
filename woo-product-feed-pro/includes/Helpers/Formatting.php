<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Helpers
 */

namespace AdTribes\PFP\Helpers;

/**
 * Helper methods class.
 *
 * @since 13.3.4
 */
class Formatting {

    /**
     * Localize price.
     *
     * @since 13.3.4
     * @access private
     *
     * @param float       $price          The price.
     * @param array       $args           Optional. Arguments to localize the price. Default empty array.
     * @param bool        $strip_currency Optional. Whether to strip currency symbol. Default true.
     * @param object|null $feed         The feed object.
     * @return string
     */
    public static function localize_price( $price, $args = array(), $strip_currency = true, $feed = null ) {
        if ( ! is_numeric( $price ) ) {
            return $price;
        }

        /**
         * Filter the arguments to localize the price.
         *
         * @since 13.4.1
         * @param array $args The arguments to localize the price.
         * @param object $feed The feed object.
         * @return array
         */
        $args          = apply_filters( 'adt_product_feed_localize_price_args', $args, $feed );
        $iso4217_feeds = apply_filters(
            'adt_pfp_localize_price_iso4217_feeds',
            array(
                'bing_shopping',
                'bing_shopping_promotions',
                'facebook_drm',
                'google_shopping',
                'google_drm',
                'google_dsa',
                'google_local',
                'google_local_products',
                'google_product_review',
                'google_shopping_promotions',
            )
        );

        // Skip if not in the ISO4217 feeds.
        if ( null !== $feed && in_array( $feed->get_channel( 'fields' ), $iso4217_feeds, true ) ) {
            $price = self::price_iso4217( $price );
        } else {
            if ( $strip_currency ) {
                $args['currency'] = 'ZZZ'; // Dummy currency to strip currency symbol.
            }

            $price = html_entity_decode( wc_clean( wc_price( $price, array_filter( $args ) ) ) );
        }

        return $price;
    }

    /**
     * Format price to ISO4217.
     *
     * @since 13.4.1
     * @access public
     *
     * @param float $price The price to format.
     * @return string
     */
    public static function price_iso4217( $price ) {
        if ( ! is_numeric( $price ) ) {
            return $price;
        }

        return number_format( $price, 2, '.', '' );
    }

    /**
     * Format date.
     * This method is used to format date based on general settings.
     *
     * @since 13.3.4
     * @access private
     *
     * @param string|WC_DateTime $date The date to format.
     * @param object|null        $feed The feed object. Default null.
     * @return string
     */
    public static function format_date( $date, $feed = null ) {
        if ( is_string( $date ) ) {
            $date = new \DateTime( $date, new \DateTimeZone( 'UTC' ) );
        }

        if ( ! is_a( $date, 'WC_DateTime' ) ) {
            return '';
        }

        $formatted_date = $date->date_i18n( wc_date_format() . ' ' . wc_time_format() );

        // Format date to ISO8601 for specific feeds.
        if ( null !== $feed ) {
            $iso8601_feeds = apply_filters(
                'adt_pfp_date_iso8601_format_feeds',
                array(
                    'bing_shopping',
                    'bing_shopping_promotions',
                    'facebook_drm',
                    'google_shopping',
                    'google_drm',
                    'google_dsa',
                    'google_local',
                    'google_local_products',
                    'google_product_review',
                    'google_shopping_promotions',
                )
            );

            if ( in_array( $feed->get_channel( 'fields' ), $iso8601_feeds, true ) ) {
                $formatted_date = self::date_iso8601( $date );
            }
        }

        return apply_filters( 'adt_pfp_format_date', $formatted_date, $date, $feed );
    }

    /**
     * Format date to ISO8601.
     *
     * @since 13.3.4
     * @access private
     *
     * @param string|WC_DateTime $date The date to format.
     * @return string
     */
    public static function date_iso8601( $date ) {
        if ( is_string( $date ) ) {
            $date = new \DateTime( $date, new \DateTimeZone( 'UTC' ) );
        }

        if ( ! is_a( $date, 'WC_DateTime' ) ) {
            return '';
        }

        // Set local timezone or offset.
        if ( get_option( 'timezone_string' ) ) {
            $date->setTimezone( new \DateTimeZone( wc_timezone_string() ) );
        } else {
            $date->set_utc_offset( wc_timezone_offset() );
        }

        return $date->__toString();
    }
}
