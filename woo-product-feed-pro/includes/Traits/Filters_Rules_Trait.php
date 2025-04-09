<?php
/**
 * Trait to handle templates for filters and rules
 *
 * @package AdTribes\PFP\Traits
 * @since 1.0.0
 */

namespace AdTribes\PFP\Traits;

defined( 'ABSPATH' ) || exit;

/**
 * Filters_Rules_Trait trait.
 */
trait Filters_Rules_Trait {

    /**
     * Get template for filter row.
     *
     * @param int   $row_count     Row count for the filter.
     * @param array $attributes    Array of available attributes.
     * @param array $filter_data   Optional. Filter data for existing filter.
     * @return string HTML markup for filter row.
     */
    public function get_filter_template( $row_count, $attributes, $filter_data = array() ) {
        // Extract needed variables for the template.
        $criteria           = isset( $filter_data['criteria'] ) ? $filter_data['criteria'] : '';
        $condition          = isset( $filter_data['condition'] ) ? $filter_data['condition'] : '';
        $than               = isset( $filter_data['than'] ) ? $filter_data['than'] : '';
        $is_case_sensitive  = isset( $filter_data['cs'] ) ? $filter_data['cs'] : false;
        $selected_attribute = isset( $filter_data['attribute'] ) ? $filter_data['attribute'] : '';

        // Get the template path.
        $template_path = WOOCOMMERCESEA_VIEWS_ROOT_PATH . 'filters-rules/view-filter-row.php';

        // Only process if the file exists.
        if ( ! file_exists( $template_path ) ) {
            return '';
        }

        // Use output buffering with an include to ensure the PHP is processed
        // and variables are available.
        ob_start();
        require $template_path;
        return ob_get_clean();
    }

    /**
     * Get template for rule row.
     *
     * @param int   $row_count     Row count for the rule.
     * @param array $attributes    Array of available attributes.
     * @param array $rule_data     Optional. Rule data for existing rule.
     * @return string HTML markup for rule row.
     */
    public function get_rule_template( $row_count, $attributes, $rule_data = array() ) {
        // Extract needed variables for the template.
        $criteria           = isset( $rule_data['criteria'] ) ? $rule_data['criteria'] : '';
        $condition          = isset( $rule_data['condition'] ) ? $rule_data['condition'] : '';
        $new_value          = isset( $rule_data['newvalue'] ) ? $rule_data['newvalue'] : '';
        $is_case_sensitive  = isset( $rule_data['cs'] ) ? $rule_data['cs'] : false;
        $selected_attribute = isset( $rule_data['attribute'] ) ? $rule_data['attribute'] : '';
        $than_attribute     = isset( $rule_data['than_attribute'] ) ? $rule_data['than_attribute'] : '';

        // Get the template path.
        $template_path = WOOCOMMERCESEA_VIEWS_ROOT_PATH . 'filters-rules/view-rule-row.php';

        // Only process if the file exists.
        if ( ! file_exists( $template_path ) ) {
            return '';
        }

        // Use output buffering with an include to ensure the PHP is processed
        // and variables are available.
        ob_start();
        require $template_path;
        return ob_get_clean();
    }

    /**
     * Get condition options HTML.
     *
     * @param string $selected Selected condition.
     * @param string $type     Filter or rule type.
     * @return string HTML for condition options.
     */
    public static function get_condition_options( $selected = '', $type = 'filter' ) {
        $conditions = self::get_condition_list( $type );
        $html       = '';

        foreach ( $conditions as $value => $label ) {
            $html .= '<option value="' . esc_attr( $value ) . '"' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }

        return $html;
    }

    /**
     * Get list of available conditions.
     *
     * @param string $type Filter or rule type.
     * @return array Array of conditions.
     */
    public static function get_condition_list( $type = 'filter' ) {
        $conditions = array(
            'contains'    => __( 'contains', 'woo-product-feed-pro' ),
            'containsnot' => __( 'doesn\'t contain', 'woo-product-feed-pro' ),
            '='           => __( 'is equal to', 'woo-product-feed-pro' ),
            '!='          => __( 'is not equal to', 'woo-product-feed-pro' ),
            '>'           => __( 'is greater than', 'woo-product-feed-pro' ),
            '>='          => __( 'is greater or equal to', 'woo-product-feed-pro' ),
            '<'           => __( 'is less than', 'woo-product-feed-pro' ),
            '=<'          => __( 'is less or equal to', 'woo-product-feed-pro' ),
            'empty'       => __( 'is empty', 'woo-product-feed-pro' ),
            'notempty'    => __( 'is not empty', 'woo-product-feed-pro' ),
        );

        // Add additional conditions for rules.
        if ( 'rule' === $type ) {
            $rule_conditions = array(
                'multiply'    => __( 'multiply', 'woo-product-feed-pro' ),
                'divide'      => __( 'divide', 'woo-product-feed-pro' ),
                'plus'        => __( 'plus', 'woo-product-feed-pro' ),
                'minus'       => __( 'minus', 'woo-product-feed-pro' ),
                'findreplace' => __( 'find and replace', 'woo-product-feed-pro' ),
            );

            $conditions = array_merge( $conditions, $rule_conditions );
        }

        return $conditions;
    }

    /**
     * Get action options HTML.
     *
     * @param string $selected Selected action.
     * @return string HTML for action options.
     */
    public static function get_action_options( $selected = '' ) {
        $actions = array(
            'exclude'      => __( 'Exclude', 'woo-product-feed-pro' ),
            'include_only' => __( 'Include only', 'woo-product-feed-pro' ),
        );

        $html = '<optgroup label="' . esc_attr__( 'Action', 'woo-product-feed-pro' ) . '">';

        foreach ( $actions as $value => $label ) {
            $html .= '<option value="' . esc_attr( $value ) . '"' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }

        $html .= '</optgroup>';

        return $html;
    }
}
