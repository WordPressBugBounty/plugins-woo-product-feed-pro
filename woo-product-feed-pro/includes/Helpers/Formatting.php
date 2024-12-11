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
     * @param float $price          The price.
     * @param array $args           Optional. Arguments to localize the price. Default empty array.
     * @param bool  $strip_currency Optional. Whether to strip currency symbol. Default true.
     * @return string
     */
    public static function localize_price( $price, $args = array(), $strip_currency = true ) {
        if ( ! is_numeric( $price ) ) {
            return $price;
        }

        if ( $strip_currency ) {
            $args['currency'] = 'ZZZ'; // Dummy currency to strip currency symbol.
        }

        return html_entity_decode( wc_clean( wc_price( $price, array_filter( $args ) ) ) );
    }
}
