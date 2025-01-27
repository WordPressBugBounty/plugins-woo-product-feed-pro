<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Traits\Singleton_Trait;

/**
 * Filters class.
 *
 * @since 13.3.4.1
 */
class Filters extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Filter data
     *
     * @since 13.4.1
     * @access public
     *
     * @param array  $data The data to filter.
     * @param object $feed The feed object.
     * @return array
     */
    public function filter( $data, $feed ) {
        if ( empty( $data ) ) {
            return $data;
        }

        $filters = $feed->filters;

        if ( empty( $filters ) ) {
            return $data;
        }

        $passed = true;

        foreach ( $filters as $filter ) {
            // If a previous filter is not passed, skip processing the remaining filters.
            if ( ! $passed ) {
                break;
            }

            // Skip if any required filter parameters are missing.
            // Required parameters are: attribute, condition, criteria, than.
            if ( ! isset( $filter['attribute'] ) || ! isset( $filter['condition'] ) || ! isset( $filter['criteria'] ) || ! isset( $filter['than'] ) ) {
                continue;
            }

            // Skip if the attribute is not set in the data array.
            // This prevents PHP notices and warnings when trying to access non-existent array keys.
            if ( ! isset( $data[ $filter['attribute'] ] ) ) {
                continue;
            }

            $passed = true;
            $value  = $data[ $filter['attribute'] ];

            // if the attribute is an array then we need to loop through the array and check if the values.
            if ( is_array( $value ) ) {
                // If empty array then add a value to the array.
                // This is to keep the filter to check the value against the filter criteria.
                if ( empty( $value ) ) {
                    $value[] = '';
                }

                foreach ( $value as $v ) {
                    $passed = $this->filter_data( $passed, $v, $filter, $feed );
                    if ( ! $passed ) {
                        break;
                    }
                }
            } else {
                $passed = $this->filter_data( $passed, $value, $filter, $feed );
            }
        }

        /**
         * Filter the data.
         *
         * @since 13.4.1
         *
         * @param bool   $passed The passed value.
         * @param array  $filters The filter criteria.
         * @param object $feed   The feed object.
         * @return bool
         */
        $passed = apply_filters( 'adt_pfp_filter_product_feed_data', $passed, $filters, $feed );

        if ( ! $passed ) {
            $data = array();
        }

        return $data;
    }

    /**
     * Filter data
     *
     * @since 13.4.1
     * @access private
     *
     * @param bool   $passed The passed value.
     * @param string $value The value to filter.
     * @param array  $filter The filter criteria.
     * @param object $feed The feed object.
     * @return bool
     */
    private function filter_data( $passed, $value, $filter, $feed ) {
        $condition    = $filter['condition'] ?? '';
        $filter_value = $filter['criteria'] ?? '';
        $then         = $filter['than'] ?? '';

        // If not case sensitive then convert the value to lower case. so we can compare.
        if ( ! isset( $filter['cs'] ) || 'on' !== $filter['cs'] ) {
            $value        = strtolower( $value );
            $filter_value = strtolower( $filter_value );
        }

        switch ( $condition ) {
            case 'contains':
                if ( 'exclude' === $then && preg_match( '/' . preg_quote( $filter_value, '/' ) . '/', $value ) ) {
                    $passed = false;
                } elseif ( 'include_only' === $then && ! preg_match( '/' . preg_quote( $filter_value, '/' ) . '/', $value ) ) {
                    $passed = false;
                }
                break;
            case 'containsnot':
                if ( 'exclude' === $then && ! preg_match( '/' . preg_quote( $filter_value, '/' ) . '/', $value ) ) {
                    $passed = false;
                } elseif ( 'include_only' === $then && preg_match( '/' . preg_quote( $filter_value, '/' ) . '/', $value ) ) {
                    $passed = false;
                }
                break;
            case '=':
                if ( 'exclude' === $then && strcmp( $value, $filter_value ) === 0 ) {
                    $passed = false;
                } elseif ( 'include_only' === $then && strcmp( $value, $filter_value ) !== 0 ) {
                    $passed = false;
                }
                break;
            case '!=':
                if ( 'exclude' === $then && strcmp( $value, $filter_value ) !== 0 ) {
                    $passed = false;
                } elseif ( 'include_only' === $then && strcmp( $value, $filter_value ) === 0 ) {
                    $passed = false;
                }
                break;
            case '>':
                if ( 'exclude' === $then && $value > $filter_value ) {
                    $passed = false;
                } elseif ( 'include_only' === $then && $value <= $filter_value ) {
                    $passed = false;
                }
                break;
            case '>=':
                if ( 'exclude' === $then && $value >= $filter_value ) {
                    $passed = false;
                } elseif ( 'include_only' === $then && $value < $filter_value ) {
                    $passed = false;
                }
                break;
            case '<':
                if ( 'exclude' === $then && $value < $filter_value ) {
                    $passed = false;
                } elseif ( 'include_only' === $then && $value >= $filter_value ) {
                    $passed = false;
                }
                break;
            case '<=':
                if ( 'exclude' === $then && $value <= $filter_value ) {
                    $passed = false;
                } elseif ( 'include_only' === $then && $value > $filter_value ) {
                    $passed = false;
                }
                break;
            case 'empty':
                if ( 'exclude' === $then && ! empty( $value ) ) {
                    $passed = false;
                } elseif ( 'include_only' === $then && empty( $value ) ) {
                    $passed = false;
                }
                break;
            case 'notempty':
                if ( 'exclude' === $then && empty( $value ) ) {
                    $passed = false;
                } elseif ( 'include_only' === $then && ! empty( $value ) ) {
                    $passed = false;
                }
                break;
            default:
                break;
        }

        return $passed;
    }

    /**
     * Run the class
     *
     * @codeCoverageIgnore
     * @since 13.4.1
     */
    public function run() {
    }
}
