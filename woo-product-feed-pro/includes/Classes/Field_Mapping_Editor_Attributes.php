<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Helpers\Field_Mapping_Helper;
use AdTribes\PFP\Helpers\Helper;
use AdTribes\PFP\Traits\Singleton_Trait;

defined( 'ABSPATH' ) || exit;

/**
 * Inject the feed's saved field-mapping output fields as a selectable group in
 * the filters/rules builder attribute dropdowns.
 *
 * The group renders for everyone (so Pro users see the upsell), but actually
 * processing rules that target these fields is an Elite capability — Elite
 * removes the `(Elite)` suffix from the group label and provides the
 * execution-side handlers. Pro's Vue dropdown reads
 * `eliteGatedAttrGroups` from the REST response to gate clicks and trigger
 * the upsell modal.
 *
 * Shared between the IF and THEN sides of Rules — the inject decision is
 * gated on `$type` so the same callback can light up other surfaces
 * (e.g. Filters) when they grow support.
 *
 * @since 13.5.5
 */
class Field_Mapping_Editor_Attributes extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Types eligible for field-mapping injection.
     *
     * `rules` for the IF side, `rules_then` for the THEN side. Filters
     * evaluates against the source product data array so its dropdown
     * stays as-is.
     *
     * @since 13.5.5
     */
    private const INJECTABLE_TYPES = array( 'rules', 'rules_then' );

    /**
     * Group label injected into the rules attribute dropdowns.
     *
     * The `(Elite)` suffix follows the existing convention for
     * Elite-gated controls (e.g. `Set Attribute (Elite)`). Kept as a
     * plain string (not wrapped in `__()`) because the group label
     * doubles as a stable identifier the Elite plugin's label-strip
     * filter rewrites at runtime. Translating it here would either
     * break Elite's lookup or force Elite to mirror the translation.
     *
     * Public so the Elite plugin can reference it by symbol
     * (`\AdTribes\PFP\Classes\Field_Mapping_Editor_Attributes::GROUP_LABEL`)
     * rather than duplicating the literal — a rename here then propagates to
     * Elite at compile time instead of silently breaking its label-strip.
     *
     * @since 13.5.5
     */
    public const GROUP_LABEL = 'Feed output fields (Elite)';

    /**
     * Upsell modal key registered in `Upsell::upsell_l10n()`.
     *
     * Travels with the group label in the gated-groups response so the
     * frontend doesn't need to maintain its own label-to-modal mapping.
     *
     * @since 13.5.5
     */
    private const UPSELL_MODAL_KEY = 'rules_feed_output_fields';

    /**
     * Inject the feed's field-mapping output fields into the attribute dropdown.
     *
     * Without this, output fields a user added in Field Mapping (e.g.
     * `g:custom_label_0`, custom fields) cannot be targeted by a rule, even
     * though Field Mapping accepts them.
     *
     * @since 13.5.5
     * @access public
     *
     * @param array             $attributes    Grouped attribute list keyed by group label.
     * @param string|null       $feed_channel  The feed's channel `fields` value.
     * @param string            $type          'filters', 'rules', or 'rules_then'.
     * @param Product_Feed|null $feed          The feed object, or null for new feeds.
     * @return array
     */
    public function inject_field_mapping_attributes( $attributes, $feed_channel = null, $type = '', $feed = null ) {
        if ( ! in_array( $type, self::INJECTABLE_TYPES, true ) ) {
            return $attributes;
        }

        $field_mapping = Field_Mapping_Helper::get_feed_field_mapping( $feed );
        if ( null === $field_mapping ) {
            return $attributes;
        }

        $output_fields = array();
        foreach ( $field_mapping as $field ) {
            // Defensive: a corrupted option could yield scalar/object items.
            // `$field['attribute']` on a non-array scalar warns;
            // on an object without ArrayAccess it fatals in PHP 8+.
            if ( ! is_array( $field ) ) {
                continue;
            }

            $attribute_name = $field['attribute'] ?? '';
            if ( '' === $attribute_name ) {
                continue;
            }
            // Dropdown is keyed by attribute key with the visible label as the value.
            $output_fields[ $attribute_name ] = $attribute_name;
        }

        if ( empty( $output_fields ) ) {
            return $attributes;
        }

        $attributes[ self::GROUP_LABEL ] = $output_fields;

        return $attributes;
    }

    /**
     * Advertise this group as Elite-gated so the Vue dropdown can intercept
     * clicks on its options and trigger the upsell modal.
     *
     * Only registered when Elite is NOT active — on Elite-licensed sites the
     * map stays empty, the dropdown does not gate, and Elite's own filter
     * rewrites the group label to drop the `(Elite)` suffix.
     *
     * @since 13.5.5
     * @access public
     *
     * @param array             $gated_groups Map of group label => upsell modal key.
     * @param string|null       $feed_channel The feed's channel `fields` value.
     * @param string            $type         'filters', 'rules', or 'rules_then'.
     * @param Product_Feed|null $feed         The feed object, or null for new feeds.
     * @return array
     */
    public function add_elite_gated_group( $gated_groups, $feed_channel = null, $type = '', $feed = null ) {
        // $feed_channel / $feed are unused here — kept for filter-signature compatibility.
        if ( ! in_array( $type, self::INJECTABLE_TYPES, true ) ) {
            return $gated_groups;
        }

        $gated_groups[ self::GROUP_LABEL ] = self::UPSELL_MODAL_KEY;
        return $gated_groups;
    }

    /**
     * Run the class.
     *
     * @codeCoverageIgnore
     * @since 13.5.5
     */
    public function run() {
        add_filter( 'adt_pfp_get_filters_rules_attributes', array( $this, 'inject_field_mapping_attributes' ), 10, 4 );

        // Gate clicks for Pro-only sites; Elite installs skip this so the dropdown stays interactive.
        if ( ! Helper::has_paid_plugin_active() ) {
            add_filter( 'adt_pfp_elite_gated_attr_groups', array( $this, 'add_elite_gated_group' ), 10, 4 );
        }
    }
}
