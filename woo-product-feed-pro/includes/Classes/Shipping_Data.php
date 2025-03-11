<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Traits\Singleton_Trait;
use AdTribes\PFP\Helpers\Formatting;

/**
 * Shipping_Data class.
 *
 * @since 13.4.0
 */
class Shipping_Data extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Only include published parent variations.
     *
     * This method is used to exclude parent variations that is not published from the product feed with the custom query.
     *
     * @since 13.4.0
     * @access public
     *
     * @param WC_Product $product The product object.
     * @param object     $feed    The feed object.
     * @return array
     */
    public function get_shipping_data( $product, $feed ) {
        $shipping_data = array();
        $feed_channel  = $feed->get_channel();
        if ( empty( $feed_channel ) ) {
            return $shipping_data;
        }

        if ( ! function_exists( 'wc_get_cart_item_data_hash' ) ) {
            include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
        }

        $shipping_zones    = \WC_Shipping_Zones::get_zones();
        $shipping_currency = apply_filters( 'adt_product_feed_shipping_cost_currency', get_woocommerce_currency(), $feed );
        $country_code      = $feed->country;

        // Get shipping options.
        $options = array(
            'add_all_shipping'             => get_option( 'add_all_shipping', 'no' ),
            'only_free_shipping'           => get_option( 'free_shipping', 'no' ), // Only include free shipping if free shipping is met.
            'remove_free_shipping'         => get_option( 'remove_free_shipping', 'no' ),  // Remove free shipping method.
            'remove_local_pickup_shipping' => get_option( 'local_pickup_shipping', 'no' ), // Remove local pickup shipping method.
        );

        /**
         * Filter the product price.
         *
         * @since 13.4.0
         *
         * @param float $product_price The product price.
         * @param object $product The product object.
         * @param array $shipping_zones The shipping zones.
         * @param object $feed The feed object.
         * @return float
         */
        $product_price = apply_filters( 'adt_product_feed_shipping_product_price', (float) $product->get_price( 'edit' ), $product, $shipping_zones, $feed );

        // Build the package data for the product.
        $package = array(
            'contents'        => array(
                array(
                    'key'               => md5( uniqid( wp_rand(), true ) ),
                    'product_id'        => $product->get_id(),
                    'variation_id'      => $product->is_type( 'variation' ) ? $product->get_id() : 0,
                    'variation'         => $product->is_type( 'variation' ) ? $this->format_variation_attributes( $product->get_attributes() ) : array(),
                    'quantity'          => 1,
                    'data_hash'         => function_exists( 'wc_get_cart_item_data_hash' ) ? wc_get_cart_item_data_hash( $product ) : '',
                    'line_tax_data'     => array(
                        'subtotal' => array(),
                        'total'    => array(),
                    ),
                    'line_subtotal'     => $product_price,
                    'line_subtotal_tax' => 0,
                    'line_total'        => $product_price,
                    'line_tax'          => 0,
                    'data'              => $product,
                ),
            ),
            'contents_cost'   => $product_price,
            'applied_coupons' => array(),
            'user'            => array(
                'ID' => get_current_user_id(),
            ),
            'destination'     => array(
                'country'   => '',
                'state'     => '',
                'postcode'  => '',
                'city'      => '',
                'address'   => '',
                'address_2' => '',
            ),
            'cart_subtotal'   => $product_price,
        );

        if ( ! empty( $shipping_zones ) ) {
            foreach ( $shipping_zones as $shipping_zone ) {
                $shipping_zones_data = $this->_get_shipping_zones_data( $shipping_zone, $package, $options, $country_code, $shipping_currency, $feed );

                if ( ! empty( $shipping_zones_data ) ) {
                    $shipping_data = array_merge( $shipping_data, $shipping_zones_data );
                }
            }
        }

        /**
         * Filter the shipping data.
         *
         * @since 13.4.0
         *
         * @param array  $shipping The shipping data.
         * @param object $product  The product object.
         * @param object $feed     The feed object.
         * @return array
         */
        return apply_filters( 'adt_product_feed_shipping_data', $shipping_data, $product, $feed );
    }

    /**
     * Get the shipping zones data.
     *
     * @since 13.4.0
     * @since 13.4.2 Fixed the issue with multiple countries in the same zone & states by country.
     * @access private
     *
     * @param array  $shipping_zone    The shipping zone data.
     * @param array  $package          The package data.
     * @param array  $options          The shipping options.
     * @param string $country_code     The feed country code.
     * @param string $shipping_currency The shipping currency.
     * @param object $feed             The feed object.
     * @return array
     */
    private function _get_shipping_zones_data( $shipping_zone, $package, $options, $country_code, $shipping_currency, $feed ) {
        $shipping_zones_data = array();

        $zone_locations = $shipping_zone['zone_locations'] ?? array();

        // Collect all countries and states in this zone.
        $countries_in_zone = array();
        $states_by_country = array();

        foreach ( $zone_locations as $zone_location ) {
            if ( 'country' === $zone_location->type || 'code' === $zone_location->type ) {
                $countries_in_zone[] = $zone_location->code;
            } elseif ( 'state' === $zone_location->type ) {
                $zone_expl = explode( ':', $zone_location->code );
                if ( ! empty( $zone_expl[0] ) && ! empty( $zone_expl[1] ) ) {
                    // Group states by country.
                    if ( ! isset( $states_by_country[ $zone_expl[0] ] ) ) {
                        $states_by_country[ $zone_expl[0] ] = array();
                    }
                    $states_by_country[ $zone_expl[0] ][] = $zone_expl[1];

                    // Also track the country.
                    if ( ! in_array( $zone_expl[0], $countries_in_zone, true ) ) {
                        $countries_in_zone[] = $zone_expl[0];
                    }
                }
            }
        }

        // Skip this zone if it's not for the feed country and Add all shipping is not enabled.
        if ( ! in_array( $country_code, $countries_in_zone, true ) && 'yes' !== $options['add_all_shipping'] ) {
            return $shipping_zones_data;
        }

        // Get and filter shipping methods.
        $methods = $this->_get_filtered_shipping_methods( $shipping_zone, $options );

        // If the feed country is in the zone.
        if ( in_array( $country_code, $countries_in_zone, true ) ) {
            // Check if there are specific states for this country.
            if ( isset( $states_by_country[ $country_code ] ) && ! empty( $states_by_country[ $country_code ] ) ) {
                // Process each state for this country.
                foreach ( $states_by_country[ $country_code ] as $state_code ) {
                    $zone_data = $this->_setup_zone_and_package( $country_code, $state_code, $package );

                    // Process shipping methods for this state.
                    $shipping_zones_data = $this->_process_shipping_methods(
                        $methods,
                        $shipping_zone,
                        $zone_data['package'],
                        $zone_data['zone'],
                        $options,
                        $zone_data['has_free_shipping'],
                        $shipping_currency,
                        $feed,
                        $shipping_zones_data
                    );
                }
            } else {
                // No specific states, process the country as a whole.
                $zone_data = $this->_setup_zone_and_package( $country_code, '', $package );

                // Process shipping methods for the whole country.
                $shipping_zones_data = $this->_process_shipping_methods(
                    $methods,
                    $shipping_zone,
                    $zone_data['package'],
                    $zone_data['zone'],
                    $options,
                    $zone_data['has_free_shipping'],
                    $shipping_currency,
                    $feed,
                    $shipping_zones_data
                );
            }
        } elseif ( 'yes' === $options['add_all_shipping'] ) {
            // Feed country is not in the zone, but add all shipping is enabled.
            // Use the first country in the zone.
            $first_country = $countries_in_zone[0] ?? '';

            if ( ! empty( $first_country ) ) {
                $first_state = '';
                if ( isset( $states_by_country[ $first_country ] ) && ! empty( $states_by_country[ $first_country ] ) ) {
                    // Use the first state for this country.
                    $first_state = $states_by_country[ $first_country ][0];
                }

                $zone_data = $this->_setup_zone_and_package( $first_country, $first_state, $package );

                // Process shipping methods.
                $shipping_zones_data = $this->_process_shipping_methods(
                    $methods,
                    $shipping_zone,
                    $zone_data['package'],
                    $zone_data['zone'],
                    $options,
                    $zone_data['has_free_shipping'],
                    $shipping_currency,
                    $feed,
                    $shipping_zones_data
                );
            }
        }

        return $shipping_zones_data;
    }

    /**
     * Get and filter shipping methods for a zone.
     *
     * @since 13.4.2
     * @access private
     *
     * @param array $shipping_zone The shipping zone data.
     * @param array $options       The shipping options.
     * @return array Filtered shipping methods.
     */
    private function _get_filtered_shipping_methods( $shipping_zone, $options ) {
        $wc_shipping_zone = new \WC_Shipping_Zone( $shipping_zone['id'] );
        $methods          = $wc_shipping_zone->get_shipping_methods( true );

        // Remove local pickup shipping method.
        if ( 'yes' === $options['remove_local_pickup_shipping'] ) {
            $methods = array_filter(
                $methods,
                function ( $method ) {
                    return 'local_pickup' !== $method->id;
                }
            );
        }

        // Remove free shipping method.
        if ( 'yes' === $options['remove_free_shipping'] ) {
            $methods = array_filter(
                $methods,
                function ( $method ) {
                    return 'free_shipping' !== $method->id;
                }
            );
        }

        $has_free_shipping = $this->_is_has_free_shipping( $methods, $options );
        if ( $has_free_shipping ) {
            $methods = $this->_sort_free_shipping_method( $methods );
        }

        return $methods;
    }

    /**
     * Setup zone and package data.
     *
     * @since 13.4.2
     * @access private
     *
     * @param string $country_code The country code.
     * @param string $state_code   The state code.
     * @param array  $package      The package data.
     * @return array Zone and package data.
     */
    private function _setup_zone_and_package( $country_code, $state_code, $package ) {
        // Create zone data.
        $zone = array(
            'country'  => $country_code,
            'region'   => $state_code,
            'postcode' => '',
        );

        // Set package destination.
        $package['destination']['country']  = $country_code;
        $package['destination']['state']    = $state_code;
        $package['destination']['postcode'] = '';

        return array(
            'zone'              => $zone,
            'package'           => $package,
            'has_free_shipping' => false, // This will be set by the caller.
        );
    }

    /**
     * Process shipping methods for a zone and package.
     *
     * @since 13.4.0
     * @access private
     *
     * @param array  $methods            The shipping methods.
     * @param array  $shipping_zone      The shipping zone data.
     * @param array  $package            The package data.
     * @param array  $zone               The zone data.
     * @param array  $options            The shipping options.
     * @param bool   $has_free_shipping  Whether the zone has free shipping.
     * @param string $shipping_currency  The shipping currency.
     * @param object $feed               The feed object.
     * @param array  $shipping_zones_data The shipping zones data.
     * @return array
     */
    private function _process_shipping_methods( $methods, $shipping_zone, $package, $zone, $options, $has_free_shipping, $shipping_currency, $feed, $shipping_zones_data ) {
        $free_shipping_met = false;

        foreach ( $methods as $method ) {
            if ( $this->_is_shipping_available( $method, $package ) ) {
                // Skip all other shipping methods if free shipping is met.
                if ( 'yes' === $options['only_free_shipping'] && $has_free_shipping ) {
                    if ( $free_shipping_met && 'free_shipping' !== $method->id ) {
                        continue;
                    } elseif ( 'free_shipping' === $method->id ) {
                        $free_shipping_met = true;
                    }
                }

                $shipping_method_data = $this->_get_shipping_method_data( $method, $shipping_zone, $package, $zone, $shipping_currency, $feed );
                if ( ! empty( $shipping_method_data ) ) {
                    $shipping_zones_data = array_merge( $shipping_zones_data, $shipping_method_data );
                }
            }
        }

        return $shipping_zones_data;
    }

    /**
     * Get the shipping method data.
     *
     * @since 13.4.0
     * @access private
     *
     * @param object $method            The shipping method object.
     * @param array  $shipping_zone     The shipping zone data.
     * @param array  $package           The package data.
     * @param array  $zone              The zone data.
     * @param string $shipping_currency The shipping currency.
     * @param object $feed              The feed object.
     * @return array
     */
    public function _get_shipping_method_data( $method, $shipping_zone, $package, $zone, $shipping_currency, $feed ) {
        $shipping_method_data = array();
        $feed_channel         = $feed->get_channel();

        /**
         * Filter the package data before calculating shipping rates.
         *
         * @since 13.5.0
         * @param array  $package       The package data.
         * @param object $method        The shipping method object.
         * @param array  $shipping_zone The shipping zone data.
         * @param object $feed          The feed object.
         * @return array
         */
        $package = apply_filters( 'adt_product_feed_shipping_package', $package, $method, $shipping_zone, $feed );
        $method->calculate_shipping( $package );

        foreach ( $method->rates as $rate ) {
            $shipping = array(
                'country'     => '',
                'region'      => '',
                'postal_code' => '',
                'service'     => '',
                'price'       => '',
            );

            $shipping['country'] = $zone['country'];

            // Add the region if it's not empty.
            if ( ! empty( $zone['region'] ) ) {
                $shipping['region'] = $zone['region'];
            }

            // Add the region if it's not empty.
            if ( ! empty( $zone['postcode'] ) ) {
                $shipping['postal_code'] = $zone['postcode'];
            }

            $shipping['service']  = $shipping_zone['zone_name'] . ' ' . $rate->get_label();
            $shipping['service'] .= ! empty( $zone['country'] ) ? ' ' . $zone['country'] : '';

            // Get the shipping cost.
            $shipping_cost = (float) $rate->get_cost();

            /**
             * Filter the shipping tax should be applied.
             *
             * @since 13.4.1
             * @param bool   $apply_shipping_tax Whether the shipping tax should be applied. Default true.
             * @param object $rate              The shipping rate object.
             * @param object $feed              The feed object.
             * @return bool
             */
            if ( apply_filters( 'adt_apply_shipping_tax', true, $rate, $feed ) ) {
                $shipping_cost = $shipping_cost + $rate->get_shipping_tax();
            }

            /**
             * Filter the shipping cost.
             * This filter is used to modify the shipping cost before it is added to the feed.
             *
             * @since 13.4.0
             * @param float|bool $shipping_cost   The shipping cost.
             * @param object     $feed            The feed object.
             * @param object     $shipping_method The shipping method object.
             * @return float|bool
             */
            $shipping_cost = apply_filters( 'adt_product_feed_convert_shipping_cost', $shipping_cost, $rate, $feed );

            /**
             * Filter the localized price.
             *
             * @since 13.4.0
             *
             * @param array      $args          Arguments to localize the price. Default empty array.
             * @param float|bool $shipping_cost The shipping cost.
             * @param object     $feed          The feed object.
             * @param object     $shipping_method The shipping method object.
             * @return string
             */
            $shipping_cost = Formatting::localize_price( $shipping_cost, apply_filters( 'adt_product_feed_shipping_cost_localize_price_args', array(), $shipping_cost, $rate, $feed ), true, $feed );

            // Heureka: remove the currency from the price.
            $shipping['price'] = $feed->ship_suffix || 'heureka' === $feed_channel['fields']
                ? $shipping_cost
                : $shipping_currency . ' ' . $shipping_cost;

            /**
             * Filter the shipping array.
             * This filter is used to modify the shipping data before it is added to the main shipping data array.
             *
             * @since 13.3.9.
             *
             * @param array  $shipping The shipping data.
             * @param object $shipping The shipping data.
             * @param object $feed     The feed object.
             * @return array
             */
            $shipping_method_data[] = apply_filters( 'adt_product_feed_shipping_array', array_filter( $shipping ), $rate, $feed );
        }

        return $shipping_method_data;
    }

    /**
     * Check if the shipping method has free shipping.
     *
     * If the only free shipping option is enabled, we will sort the free shipping method to the top.
     * This is to ensure that the free shipping method is always the first option.
     * This is useful for feeds that only want to include free shipping methods.
     * So, that we can skip the other shipping methods if the free shipping method is met.
     *
     * @since 13.4.0
     * @access private
     *
     * @param array $methods The shipping methods.
     * @param array $options The shipping options.
     * @return bool
     */
    private function _is_has_free_shipping( $methods, $options ) {
        $has_free_shipping = false;
        if ( 'yes' === $options['only_free_shipping'] ) {
            // Check if methods has free shipping method.
            $has_free_shipping = ! empty(
                array_filter(
                    $methods,
                    function ( $method ) {
                        return 'free_shipping' === $method->id;
                    }
                )
            );
        }

        return $has_free_shipping;
    }

    /**
     * Sort the free shipping method to the top.
     *
     * @since 13.4.0
     * @access private
     *
     * @param array $methods The shipping methods.
     */
    private function _sort_free_shipping_method( $methods ) {
        usort(
            $methods,
            function ( $a ) {
                if ( 'free_shipping' === $a->id ) {
                    return -1;
                }
                return 1;
            }
        );

        return $methods;
    }

    /**
     * Check if the shipping method is available.
     *
     * The reason why we don't use the is_available method for free shipping, is because it expects a the cart session to be set.
     * So, we have to check if the free shipping requirements are met manually.
     *
     * @since 13.4.0
     * @access private
     *
     * @param object $method  The shipping method object.
     * @param array  $package The package data.
     * @return bool
     */
    private function _is_shipping_available( $method, $package ) {
        $is_available = false;
        if ( 'free_shipping' === $method->id ) {

            if ( in_array( $method->requires, array( 'min_amount', 'either', 'both' ), true ) ) {
                $total = $package['contents_cost'];
                $total = \Automattic\WooCommerce\Utilities\NumberUtil::round( $total, wc_get_price_decimals() );

                if ( $total >= $method->min_amount ) {
                    $is_available = true;
                }
            }
        } else {
            $is_available = $method->is_available( $package );
        }
        return $is_available;
    }

    /**
     * Format variation attributes to match WooCommerce cart format
     *
     * @param array $attributes The product attributes.
     * @return array Formatted attributes with 'attribute_' prefix
     */
    private function format_variation_attributes( $attributes ) {
        $formatted_attributes = array();

        if ( ! empty( $attributes ) ) {
            foreach ( $attributes as $key => $value ) {
                $formatted_attributes[ 'attribute_' . $key ] = $value;
            }
        }

        return $formatted_attributes;
    }

    /**
     * Run the class
     *
     * @codeCoverageIgnore
     * @since 13.4.0
     */
    public function run() {}
}
