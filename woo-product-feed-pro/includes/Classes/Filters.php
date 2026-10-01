<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Filters_Rules;
use AdTribes\PFP\Traits\Singleton_Trait;
use AdTribes\PFP\Traits\Filters_Rules_Trait;
use AdTribes\PFP\Classes\Legacy\Filters_Legacy;

/**
 * Filters class with backwards compatibility.
 *
 * @since 13.3.4.1
 */
class Filters extends Abstract_Filters_Rules {

    use Singleton_Trait;
    use Filters_Rules_Trait;

    /**
     * Option that records feeds whose saved filters could not be read.
     *
     * Keyed by feed ID, each entry holds the feed title, which the admin notice
     * names, and the list of problems found, which tells a later run that these
     * problems are already logged.
     *
     * @since 13.5.8
     * @var string
     */
    const INVALID_FILTERS_OPTION = 'adt_pfp_invalid_feed_filters';

    /**
     * Structure check results, keyed by feed ID, for this request.
     *
     * Feed generation calls the filter engine once per product. The structure of
     * a feed's saved filters does not change between those calls, so it is
     * checked, logged and flagged one time only.
     *
     * @since 13.5.8
     * @var array
     */
    private $filters_structure_cache = array();

    /**
     * Filter data - main entry point
     *
     * @since 13.4.6
     * @access public
     *
     * @param array  $data The data to filter.
     * @param object $feed The feed object.
     * @return array
     */
    public function filter( $data, $feed ) {
        // Determine which filtering logic to use.
        if ( $this->should_use_legacy_filters( $feed ) ) {
            return $this->legacy_filter( $data, $feed );
        }

        return $this->validate_filters( $data, $feed );
    }

    /**
     * Determine if we should use legacy filters.
     * We're using the data version to determine if we should use the legacy filters.
     * If the data version is 13.4.6 or higher, we're using the new filters.
     * If the data version is lower than 13.4.6, we're using the legacy filters.
     *
     * The reason why we're not just checking if the feed_filters is empty and the filters legacy is present,
     * is because both the new and legacy filters can be present at the same time.
     *
     * @since 13.4.6
     * @access private
     *
     * @param object $feed The feed object.
     * @return bool
     */
    private function should_use_legacy_filters( $feed ) {
        if ( 'yes' === get_option( 'adt_use_legacy_filters_and_rules', 'no' ) ) {
            return true;
        }

        // Validate feed object.
        if ( ! $feed ) {
            return false;
        }

        $data_version = $feed->data_version;
        if ( ! empty( $data_version['feed_filters'] ) && version_compare( $data_version['feed_filters'], '13.4.6', '>=' ) ) {
            return false;
        }

        return true;
    }

    /**
     * Process using legacy filters
     *
     * @since 13.4.6
     * @access private
     *
     * @param array  $data The data to filter.
     * @param object $feed The feed object.
     * @return array
     */
    private function legacy_filter( $data, $feed ) {
        $legacy_filters = new Filters_Legacy( $this->feed_type );
        return $legacy_filters->filter( $data, $feed );
    }

    /**
     * Validate filters structure before processing
     *
     * @since 13.4.6
     * @since 13.5.8 Runs the structure check, which repairs the shapes it can and
     *               reports the ones it cannot. See #1140.
     * @access private
     *
     * @param array  $data The data to filter.
     * @param object $feed The feed object.
     * @return array
     */
    private function validate_filters( $data, $feed ) {
        $filters = $feed->feed_filters ?? array();

        if ( empty( $filters ) ) {
            return $data; // No filters applied.
        }

        try {
            $filters = $this->check_filters_structure( $feed );

            // The saved payload cannot be read at all. The merchant is told about
            // it by the log entry and the admin notice raised in the check above,
            // so let the products through rather than empty a live feed.
            if ( is_wp_error( $filters ) ) {
                return $data;
            }

            return $this->process_filters( $data, $filters, $feed );
        } catch ( \Exception $e ) {
            // Log error and fall back to legacy if possible.
            error_log( 'PFP Filters Error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

            if ( $this->should_use_legacy_filters( $feed ) ) {
                return $this->legacy_filter( $data, $feed );
            }

            return $data;
        }
    }

    /**
     * Normalise and structurally check a feed's saved filters.
     *
     * Feeds written by older versions, by an import, or by a partial save can hold
     * a shape the parser does not expect. Before #1140 the parser read such a shape
     * as "no filter" and let every product through without a word. This method
     * repairs what it can, records what it cannot, and reports the problems once
     * per feed through the WooCommerce log and the admin notice.
     *
     * @since 13.5.8
     * @access public
     *
     * @param object $feed         The feed object.
     * @param array  $extra_issues Problems found by the caller, added to the report.
     * @return array|\WP_Error The usable filters, or a WP_Error if the payload cannot be read.
     */
    public function check_filters_structure( $feed, $extra_issues = array() ) {
        $raw     = $feed->feed_filters ?? array();
        $feed_id = (int) ( $feed->id ?? 0 );

        if ( empty( $extra_issues )
            && isset( $this->filters_structure_cache[ $feed_id ] )
            && $this->filters_structure_cache[ $feed_id ]['raw'] === $raw ) {
            return $this->filters_structure_cache[ $feed_id ]['result'];
        }

        $issues = $extra_issues;

        // A feed with no filters saved is a normal state, not an unreadable
        // payload. validate_filters() stops before this point on an empty value;
        // migrate_filters() does not, so the same rule is applied here.
        if ( empty( $raw ) ) {
            $result = array(
                'include' => array(),
                'exclude' => array(),
            );
        } else {
            $result = $this->normalize_filters( $raw, $issues );

            if ( is_wp_error( $result ) ) {
                $issues[] = $result->get_error_message();
            }
        }

        if ( empty( $issues ) ) {
            self::unflag_invalid_filters( $feed_id );
        } else {
            $this->report_filters_issues( $feed, $issues );
        }

        $this->filters_structure_cache[ $feed_id ] = array(
            'raw'    => $raw,
            'result' => $result,
        );

        return $result;
    }

    /**
     * Normalise a saved filters payload into the shape the parser expects.
     *
     * @since 13.5.8
     * @access private
     *
     * @param mixed $filters The saved filters payload.
     * @param array $issues  Collects a message for every entry repaired or dropped.
     * @return array|\WP_Error The normalised filters, or a WP_Error if the payload cannot be read.
     */
    private function normalize_filters( $filters, &$issues ) {
        if ( is_string( $filters ) ) {
            $decoded = json_decode( $filters, true );

            if ( ! is_array( $decoded ) ) {
                return new \WP_Error(
                    'adt_pfp_filters_invalid_json',
                    'The saved filters could not be read: the stored value is not valid JSON.'
                );
            }

            $filters = $decoded;
        }

        if ( ! is_array( $filters ) ) {
            return new \WP_Error(
                'adt_pfp_filters_invalid_structure',
                'The saved filters could not be read: the stored value is not a list of filter groups.'
            );
        }

        if ( ! isset( $filters['include'] ) && ! isset( $filters['exclude'] ) ) {
            return new \WP_Error(
                'adt_pfp_filters_unknown_structure',
                'The saved filters could not be read: the stored value has no include or exclude section.'
            );
        }

        $normalized = array();

        foreach ( array( 'include', 'exclude' ) as $section ) {
            $normalized[ $section ] = $this->normalize_filter_groups( $filters[ $section ] ?? array(), $section, $issues );
        }

        return $normalized;
    }

    /**
     * Normalise one section (include or exclude) of a saved filters payload.
     *
     * @since 13.5.8
     * @access private
     *
     * @param mixed  $groups  The section's groups.
     * @param string $section The section name, 'include' or 'exclude'.
     * @param array  $issues  Collects a message for every entry repaired or dropped.
     * @return array The normalised groups.
     */
    private function normalize_filter_groups( $groups, $section, &$issues ) {
        if ( ! is_array( $groups ) ) {
            if ( ! empty( $groups ) ) {
                $issues[] = sprintf( 'The %s filters are not a list of groups and were ignored.', $section );
            }

            return array();
        }

        $normalized = array();

        foreach ( $groups as $group ) {
            if ( ! is_array( $group ) ) {
                continue;
            }

            $type = $group['type'] ?? '';

            // A missing type is what makes a group invisible to the parser. Read it
            // from the shape instead: a 'fields' list is a group, an 'and'/'or'
            // value is a logic operator between groups.
            if ( '' === $type ) {
                if ( isset( $group['fields'] ) ) {
                    $type = 'group';
                } elseif ( isset( $group['value'] ) && in_array( $group['value'], array( 'and', 'or' ), true ) ) {
                    $type = 'group_logic';
                } else {
                    $issues[] = sprintf( 'An entry in the %s filters could not be read and was ignored.', $section );
                    continue;
                }

                $group['type'] = $type;
                $issues[]      = sprintf( 'A group in the %1$s filters had no type and was repaired as "%2$s".', $section, $type );
            }

            if ( 'group_logic' === $type ) {
                $normalized[] = $group;
                continue;
            }

            if ( 'group' !== $type ) {
                continue;
            }

            // A group without a usable field states no condition. Drop it: treating
            // it as an automatic pass is what let every product through.
            if ( ! $this->group_has_field( $group ) ) {
                $issues[] = sprintf( 'A group in the %s filters has no filter field and was ignored.', $section );
                continue;
            }

            $normalized[] = $group;
        }

        return $normalized;
    }

    /**
     * Check that a group holds at least one usable filter field.
     *
     * @since 13.5.8
     * @access private
     *
     * @param array $group The group to check.
     * @return bool
     */
    private function group_has_field( $group ) {
        $fields = $group['fields'] ?? array();

        if ( ! is_array( $fields ) ) {
            return false;
        }

        foreach ( $fields as $field ) {
            if ( is_array( $field )
                && isset( $field['type'], $field['data'] )
                && 'field' === $field['type']
                && is_array( $field['data'] ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record a feed whose saved filters could not be read, and log the problems.
     *
     * The memo in check_filters_structure() covers one request, but feed
     * generation runs one request for each batch. Comparing against what is
     * already recorded keeps a long run from writing the same warning hundreds
     * of times.
     *
     * @since 13.5.8
     * @access private
     *
     * @param object $feed   The feed object.
     * @param array  $issues The problems found.
     */
    private function report_filters_issues( $feed, $issues ) {
        $feed_id = (int) ( $feed->id ?? 0 );
        $issues  = array_values( array_unique( $issues ) );

        $invalid = get_option( self::INVALID_FILTERS_OPTION, array() );
        if ( ! is_array( $invalid ) ) {
            $invalid = array();
        }

        if ( isset( $invalid[ $feed_id ]['issues'] ) && $invalid[ $feed_id ]['issues'] === $issues ) {
            return; // These problems are already recorded and logged.
        }

        $invalid[ $feed_id ] = array(
            'title'  => $feed->title ?? '',
            'issues' => $issues,
        );

        update_option( self::INVALID_FILTERS_OPTION, $invalid, false );

        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->warning(
                'Feed filters could not be read as saved: ' . implode( ' ', $issues ),
                array(
                    'source'  => 'woo-product-feed-pro',
                    'feed_id' => $feed_id,
                )
            );
        }
    }

    /**
     * Remove a feed from the unreadable-filters record.
     *
     * @since 13.5.8
     * @access private
     *
     * @param int $feed_id Feed ID.
     */
    private static function unflag_invalid_filters( $feed_id ) {
        $invalid = get_option( self::INVALID_FILTERS_OPTION, array() );
        if ( ! is_array( $invalid ) || ! isset( $invalid[ $feed_id ] ) ) {
            return;
        }

        unset( $invalid[ $feed_id ] );
        update_option( self::INVALID_FILTERS_OPTION, $invalid, false );
    }

    /**
     * Process filters structure
     *
     * @since 13.4.6
     * @access private
     *
     * @param array  $data The data to filter.
     * @param array  $filters The filters structure.
     * @param object $feed The feed object.
     * @return array
     */
    private function process_filters( $data, $filters, $feed ) {
        // Special handling for Google Product Review feeds.
        if ( 'google_product_review' === $feed->get_channel( 'fields' ) ) {
            $google_product_review = \AdTribes\PFP\Classes\Feeds\Google_Product_Review::instance();
            return $google_product_review->process_google_review_filters( $data, $filters, $feed );
        }

        $include_result = $this->process_include_groups( $data, $filters['include'] ?? array(), $feed );
        $exclude_result = $this->process_exclude_groups( $data, $filters['exclude'] ?? array(), $feed );

        // Product passes if it passes include filters AND doesn't match exclude filters.
        $passed = $include_result && ! $exclude_result;

        if ( ! $passed ) {
            $data = array();
        }

        /**
         * Filter the product feed data after filtering.
         *
         * @since 13.4.5
         * @since 13.5.8 $filters is now the normalised structure the engine used,
         *               not the raw saved value: both sections are always present,
         *               a group saved without a type carries the repaired one, and
         *               a group that states no condition is absent.
         *
         * @param array  $data The product feed data.
         * @param array  $filters The filters.
         * @param object $feed The feed.
         */
        return apply_filters( 'adt_pfp_filter_product_feed_data', $data, $filters, $feed );
    }

    /**
     * Process include groups - OR logic between groups
     *
     * @since 13.4.6
     * @access private
     *
     * @param array  $data The data to filter.
     * @param array  $groups The include groups.
     * @param object $feed The feed object.
     * @return bool
     */
    public function process_include_groups( $data, $groups, $feed ) {
        if ( empty( $groups ) ) {
            return true; // No include filters = include all.
        }

        // OR logic between groups - any group passing means include.
        foreach ( $groups as $group ) {
            if ( 'group' === ( $group['type'] ?? '' ) && $this->process_group( $data, $group, $feed ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Process exclude groups - supports both AND and OR logic between groups
     *
     * @since 13.4.6
     * @access private
     *
     * @param array  $data The data to filter.
     * @param array  $groups The exclude groups.
     * @param object $feed The feed object.
     * @return bool
     */
    public function process_exclude_groups( $data, $groups, $feed ) {
        if ( empty( $groups ) ) {
            return false; // No exclude filters = exclude none.
        }

        $group_results = array();
        $current_logic = 'and'; // Default logic between groups.

        foreach ( $groups as $group ) {
            $group_type = $group['type'] ?? '';

            if ( 'group' === $group_type ) {
                $group_results[] = array(
                    'result' => $this->process_group( $data, $group, $feed ),
                    'logic'  => $current_logic,
                );
            } elseif ( 'group_logic' === $group_type ) {
                $logic_value = $group['value'] ?? '';
                if ( in_array( $logic_value, array( 'and', 'or' ), true ) ) {
                    $current_logic = $logic_value;
                }
            }
        }

        return $this->evaluate_exclude_group_results( $group_results );
    }

    /**
     * Evaluate exclude group results with logic operators
     *
     * @since 13.4.6
     * @access private
     *
     * @param array $results The results array.
     * @return bool
     */
    private function evaluate_exclude_group_results( $results ) {
        if ( empty( $results ) ) {
            return false;
        }

        $final_result = $results[0]['result'];
        $result_count = count( $results );

        for ( $i = 1; $i < $result_count; $i++ ) {
            $current = $results[ $i ];
            if ( 'and' === $current['logic'] ) {
                $final_result = $final_result && $current['result'];
            } else { // 'or'
                $final_result = $final_result || $current['result'];
            }
        }

        return $final_result;
    }

    /**
     * Process a single group with its fields and logic
     *
     * @since 13.4.6
     * @access private
     *
     * @param array  $data The data to filter.
     * @param array  $group The group to process.
     * @param object $feed The feed object.
     * @return bool
     */
    private function process_group( $data, $group, $feed ) {
        $fields        = $group['fields'] ?? array();
        $results       = array();
        $current_logic = 'and'; // Default.

        // Validate group structure.
        if ( empty( $fields ) || ! is_array( $fields ) ) {
            return true; // Empty group passes.
        }

        foreach ( $fields as $field ) {
            // Validate field structure.
            if ( ! is_array( $field ) || ! isset( $field['type'] ) ) {
                continue; // Skip invalid fields.
            }

            if ( 'field' === $field['type'] ) {
                // Validate field data.
                if ( ! isset( $field['data'] ) || ! is_array( $field['data'] ) ) {
                    continue; // Skip invalid field data.
                }

                $results[] = array(
                    'result' => $this->process_field( $data, $field['data'], $feed ),
                    'logic'  => $current_logic,
                );
            } elseif ( 'logic' === $field['type'] ) {
                $logic_value = $field['data'] ?? '';
                if ( in_array( $logic_value, array( 'and', 'or' ), true ) ) {
                    $current_logic = $logic_value;
                }
            }
        }

        return $this->evaluate_group_results( $results );
    }

    /**
     * Evaluate group results with logic operators
     *
     * @since 13.4.6
     * @access private
     *
     * @param array $results The results array.
     * @return bool
     */
    private function evaluate_group_results( $results ) {
        if ( empty( $results ) ) {
            return true;
        }

        $final_result = $results[0]['result'];
        $result_count = count( $results );

        for ( $i = 1; $i < $result_count; $i++ ) {
            $current = $results[ $i ];
            if ( 'and' === $current['logic'] ) {
                $final_result = $final_result && $current['result'];
            } else { // 'or'
                $final_result = $final_result || $current['result'];
            }
        }

        return $final_result;
    }

    /**
     * Process a single field
     *
     * @since 13.4.6
     * @access private
     *
     * @param array  $data The data to filter.
     * @param array  $field_data The field data.
     * @param object $feed The feed object.
     * @return bool
     */
    private function process_field( $data, $field_data, $feed ) {
        $attribute      = $field_data['attribute'] ?? '';
        $condition      = $field_data['condition'] ?? '';
        $value          = $field_data['value'] ?? '';
        $case_sensitive = $field_data['case_sensitive'] ?? false;

        $product_value = $data[ $attribute ] ?? '';

        // Apply category hierarchy logic if needed.
        $product_value = $this->maybe_get_category_hierarchy( $product_value, $attribute, $data );

        // Handle array values similar to legacy system.
        if ( ! is_array( $product_value ) ) {
            return $this->evaluate_field_condition( $product_value, $condition, $value, $case_sensitive );
        }

        // Handle array values.
        if ( empty( $product_value ) ) {
            $product_value[] = ''; // Add empty value to ensure filter is applied.
        }

        // Determine if we need ANY match or ALL matches for arrays.
        $requires_any_match = $this->requires_any_match_for_condition( $condition );

        return $this->process_array_field_value( $product_value, $condition, $value, $case_sensitive, $requires_any_match );
    }

    /**
     * Determine if condition requires any match for arrays
     *
     * @since 13.4.6
     * @access private
     *
     * @param string $condition The condition.
     * @return bool
     */
    private function requires_any_match_for_condition( $condition ) {
        $any_match_conditions = array(
            'contains',
            'equals',
            'greater_than_or_equal',
            'greater_than',
            'less_than_or_equal',
            'less_than',
            'is_not_empty',
        );
        return in_array( $condition, $any_match_conditions, true );
    }

    /**
     * Process array field values
     *
     * @since 13.4.6
     * @access private
     *
     * @param array  $values The array of values.
     * @param string $condition The condition.
     * @param mixed  $filter_value The filter value.
     * @param bool   $case_sensitive Whether to be case sensitive.
     * @param bool   $requires_any_match Whether any match is sufficient.
     * @return bool
     */
    private function process_array_field_value( $values, $condition, $filter_value, $case_sensitive, $requires_any_match ) {
        if ( empty( $values ) ) {
            return false;
        }

        if ( $requires_any_match ) {
            // ANY match should pass.
            foreach ( $values as $value ) {
                if ( is_array( $value ) ) {
                    if ( $this->process_array_field_value( $value, $condition, $filter_value, $case_sensitive, $requires_any_match ) ) {
                        return true;
                    }
                } elseif ( $this->evaluate_field_condition( $value, $condition, $filter_value, $case_sensitive ) ) {
                    return true;
                }
            }
            return false;
        } else {
            // ALL must pass.
            foreach ( $values as $value ) {
                if ( is_array( $value ) ) {
                    if ( ! $this->process_array_field_value( $value, $condition, $filter_value, $case_sensitive, $requires_any_match ) ) {
                        return false;
                    }
                } elseif ( ! $this->evaluate_field_condition( $value, $condition, $filter_value, $case_sensitive ) ) {
                    return false;
                }
            }
            return true;
        }
    }

    /**
     * Evaluate field condition using legacy logic
     *
     * @since 13.4.6
     * @access private
     *
     * @param mixed  $product_value The product value.
     * @param string $condition The condition.
     * @param mixed  $filter_value The filter value.
     * @param bool   $case_sensitive Whether to be case sensitive.
     * @return bool
     */
    private function evaluate_field_condition( $product_value, $condition, $filter_value, $case_sensitive ) {
        // Handle case sensitivity.
        if ( ! $case_sensitive ) {
            $product_value = strtolower( $product_value );
            $filter_value  = strtolower( $filter_value );
        }

        switch ( $condition ) {
            case 'contains':
                return (bool) preg_match( '/' . preg_quote( $filter_value, '/' ) . '/', $product_value );

            case 'not_contains':
                return ! (bool) preg_match( '/' . preg_quote( $filter_value, '/' ) . '/', $product_value );

            case 'equals':
                return strcmp( $product_value, $filter_value ) === 0;

            case 'not_equals':
                return strcmp( $product_value, $filter_value ) !== 0;

            case 'greater_than':
                return $product_value > $filter_value;

            case 'greater_than_or_equal':
                return $product_value >= $filter_value;

            case 'less_than':
                return $product_value < $filter_value;

            case 'less_than_or_equal':
                return $product_value <= $filter_value;

            case 'is_empty':
                return empty( $product_value );

            case 'is_not_empty':
                return ! empty( $product_value );

            default:
                /**
                 * Filter the evaluation result of a condition the core switch does not handle.
                 *
                 * Lets add-ons evaluate custom condition types (e.g. character count) that they
                 * register via the `adt_pfp_get_filters_rules_conditions` filter. Return a boolean
                 * to resolve the condition; return the passed-through `null` to fall back to the
                 * core default (unhandled filter conditions pass).
                 *
                 * @since 13.5.6
                 *
                 * @param bool|null $result         Null when the core switch did not handle the condition.
                 * @param string    $condition      The condition operator.
                 * @param mixed     $product_value  The product field value (case-normalised).
                 * @param mixed     $filter_value   The filter's comparison value.
                 * @param bool      $case_sensitive Whether the comparison is case-sensitive.
                 */
                $result = apply_filters( 'adt_pfp_evaluate_filters_rules_condition', null, $condition, $product_value, $filter_value, $case_sensitive );

                return is_bool( $result ) ? $result : true; // Default to passing if condition is unknown.
        }
    }

    /**
     * Run the class
     *
     * @codeCoverageIgnore
     * @since 13.4.6
     */
    public function run() {}
}
