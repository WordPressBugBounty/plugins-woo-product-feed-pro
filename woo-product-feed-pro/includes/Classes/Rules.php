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
 * Rules class.
 *
 * @since 13.3.4.1
 */
class Rules extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Rules data.
     *
     * @since 13.4.1
     * @access public
     *
     * @param array  $data The data to filter.
     * @param object $feed The feed object.
     * @return array
     */
    public function rules( $data, $feed ) {
        if ( empty( $data ) ) {
            return $data;
        }

        $rules = $feed->rules;

        if ( empty( $rules ) ) {
            return $data;
        }

        // For some reason, the calculation rules conditions doesn't show the than_attribute in the frontend.
        // So instead of altering the then_attribute, it will alter the attribute.
        $calculation_rules = array(
            'multiply',
            'divide',
            'plus',
            'minus',
        );

        foreach ( $rules as $rule ) {
            // Skip if any required rule parameters are missing.
            // Required parameters are: attribute, condition, criteria.
            if ( ! isset( $rule['attribute'] ) || ! isset( $rule['condition'] ) || ! isset( $rule['criteria'] ) ) {
                continue;
            }

            // Skip if the attribute is not set in the data array.
            // This prevents PHP notices and warnings when trying to access non-existent array keys.
            if ( ! isset( $data[ $rule['attribute'] ] ) ) {
                continue;
            }

            $value = $data[ $rule['attribute'] ];

            // if the attribute is an array then we need to loop through the array and check if the values.
            if ( is_array( $value ) ) {
                foreach ( $value as $key => $v ) {
                    $new_value = $this->rules_data( $v, $rule, $feed );

                    if ( in_array( $rule['condition'], $calculation_rules, true ) ) {
                        $data[ $rule['attribute'] ][ $key ] = $new_value;
                    } else {
                        $data[ $rule['than_attribute'] ][ $key ] = $new_value;
                    }
                }
            } else {
                $new_value = $this->rules_data( $value, $rule, $feed );

                if ( in_array( $rule['condition'], $calculation_rules, true ) ) {
                    $data[ $rule['attribute'] ] = $new_value;
                } else {
                    $data[ $rule['than_attribute'] ] = $new_value;
                }
            }
        }

        return $data;
    }

    /**
     * Rules data.
     *
     * @since 13.4.1
     * @access private
     *
     * @param string $value The value to filter.
     * @param array  $rule  The rule criteria.
     * @param object $feed  The feed object.
     * @return bool
     */
    private function rules_data( $value, $rule, $feed ) {
        $condition  = $rule['condition'] ?? '';
        $rule_value = $rule['criteria'] ?? '';
        $attribute  = $rule['attribute'] ?? '';
        $then       = $rule['than_attribute'] ?? '';
        $new_value  = $rule['newvalue'] ?? '';

        switch ( $condition ) {
            case 'contains':
                if ( preg_match( '/' . preg_quote( $rule_value, '/' ) . '/', $value ) ) {
                    $return_value = $new_value;
                }
                break;
            case 'containsnot':
                if ( ! preg_match( '/' . preg_quote( $rule_value, '/' ) . '/', $value ) ) {
                    $return_value = $new_value;
                }
                break;
            case '=':
                if ( strcmp( $value, $rule_value ) === 0 ) {
                    $return_value = $new_value;
                }
                break;
            case '!=':
                if ( strcmp( $value, $rule_value ) !== 0 ) {
                    $return_value = $new_value;
                }
                break;
            case '>':
                if ( $value > $rule_value ) {
                    $return_value = $new_value;
                }
                break;
            case '>=':
                if ( $value >= $rule_value ) {
                    $return_value = $new_value;
                }
                break;
            case '<':
                if ( $value < $rule_value ) {
                    $return_value = $new_value;
                }
                break;
            case '<=':
                if ( $value <= $rule_value ) {
                    $return_value = $new_value;
                }
                break;
            case 'empty':
                if ( empty( $value ) ) {
                    $return_value = $new_value;
                }
                break;
            case 'notempty':
                if ( ! empty( $value ) ) {
                    $return_value = $new_value;
                }
                break;
            case 'multiply':
                $return_value = $this->calculate( $value, $rule_value, 'multiply' );
                break;
            case 'divide':
                $return_value = $this->calculate( $value, $rule_value, 'divide' );
                break;
            case 'plus':
                $return_value = $this->calculate( $value, $rule_value, 'plus' );
                break;
            case 'minus':
                $return_value = $this->calculate( $value, $rule_value, 'minus' );
                break;
            case 'findreplace':
                // Find and replace only work on same attribute field, otherwise create a contains rule.
                if ( $attribute === $then && is_string( $value ) && strpos( $value, $rule_value ) !== false ) {
                    $return_value = str_replace( $rule_value, $new_value, $value );
                }
                break;
            default:
                $return_value = $value;
                break;
        }

        /**
         * Filter the return value.
         *
         * @since 13.4.1
         *
         * @param string $return_value The return value.
         * @param string $value        The value.
         * @param array  $rule         The rule.
         * @param object $feed         The feed object.
         * @return string
         */
        return isset( $return_value ) ? apply_filters( 'adt_pfp_rules_return_value', $return_value, $value, $rule, $feed ) : $value;
    }

    /**
     * Calculate the value.
     *
     * @since 13.4.1
     * @access private
     *
     * @param float  $value The value to calculate.
     * @param float  $rule_value The rule value to calculate.
     * @param string $operator The operator to use.
     * @return float
     */
    private function calculate( $value, $rule_value, $operator ) {
        // Check if both values are numeric.
        if ( ! is_numeric( $value ) || ! is_numeric( $rule_value ) ) {
            return $value;
        }

        switch ( $operator ) {
            case 'multiply':
                return $value * $rule_value;
            case 'divide':
                return $value / $rule_value;
            case 'plus':
                return $value + $rule_value;
            case 'minus':
                return $value - $rule_value;
        }

        return $value;
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
