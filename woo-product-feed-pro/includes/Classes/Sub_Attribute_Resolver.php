<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

defined( 'ABSPATH' ) || exit;

use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Helpers\Product_Feed_Helper;

/**
 * Sub_Attribute_Resolver class.
 *
 * Resolves a parent attribute's configured sub-attribute template groups into
 * concrete tag/value pairs at feed generation time — expanding `zones_auto`
 * parents (e.g. `g:shipping`) once per WooCommerce shipping zone and resolving
 * zone/field/static sources per row.
 *
 * @since 13.5.8
 */
class Sub_Attribute_Resolver {

    /**
     * The `product_data` key that holds the `Shipping_Data` zone rows.
     *
     * @since 13.5.8
     */
    const ZONE_ROWS_KEY = 'shipping';

    /**
     * Payload shape: ordered `array( 'tag' => …, 'value' => … )` rows per group.
     *
     * `Feed_Writer_XML::write_nested()` consumes this. It needs the order and the
     * namespace prefix that the tag carries.
     *
     * @since 13.5.8
     */
    const SHAPE_ROWS = 'rows';

    /**
     * Payload shape: one flat object per group, keyed by the unprefixed tag.
     *
     * `Feed_Writer_JSONL` encodes this, so it must be the object the spec shows:
     * `{"shipping":[{"country":"US","service":"Standard","price":"5.99 USD"}]}`.
     * The rows shape would encode as an array of tag/value metadata instead.
     *
     * @since 13.5.8
     */
    const SHAPE_OBJECT = 'object';

    /**
     * Parent tags whose affixes are concatenated raw, not sanitized per affix.
     *
     * The legacy marker builder has two arms with different affix rules, so one
     * global rule cannot reproduce both:
     *
     * - `g:shipping` → `g:price` sanitizes each affix on its own
     *   (`class-get-products.php`, the `price` arm). `sanitize_text_field()` collapses
     *   and trims whitespace, so a `" USD"` suffix emits as `5.99USD`.
     * - `free_shipping_threshold` → `g:price_threshold` concatenates the affixes raw
     *   and the writer trims the whole value, so the same suffix emits as `50 USD`.
     *
     * A parent listed here therefore takes the raw concatenation, trimmed once, which
     * is what its legacy arm emits byte for byte. Legacy trims only `g:price_threshold`
     * at write time, not that arm's `g:country`, but `g:country` carries no affix on
     * the legacy path, so the trim cannot change a migrated value.
     *
     * `g:loyalty_program` (#1130) is listed for a different reason: it has no legacy
     * arm at all, so there is no byte parity to preserve, and its `g:price` sub-attribute
     * carries a member price that Google requires as `10.00 USD`. The sanitized rule
     * would collapse a `" USD"` suffix to `10.00USD`, which Merchant Center rejects, so
     * the only spec-valid rule for a new parent is the raw one. Do not extend this list
     * to a parent that HAS a legacy arm without first checking that arm's affix rule.
     *
     * This list covers the XML arms only. The legacy CSV row builder concatenates the
     * affixes raw on EVERY arm, so `Feed_Writer_CSV` passes `$raw_affixes` to
     * `resolve()` instead of being listed here - the affix rule belongs to the output
     * path, and one parent needs both rules. See #1039.
     *
     * @since 13.5.8
     */
    const RAW_AFFIX_PARENTS = array( 'free_shipping_threshold', 'g:loyalty_program' );

    /**
     * Resolve an attribute's sub-attribute template groups into concrete groups.
     *
     * @since 13.5.8
     *
     * @param array        $attribute    The `feed_attributes` row for the parent attribute
     *                                   (feed_name, source, source_value, zones_auto, sub_attributes, …).
     * @param array        $product_data The assembled product data for the current product.
     * @param Product_Feed $feed         The product feed instance.
     * @param bool         $raw_affixes  Optional. Force the raw affix rule for every parent, whatever
     *                                   RAW_AFFIX_PARENTS says. The affix rule belongs to the output
     *                                   path, not to the parent: the legacy CSV row builder concatenates
     *                                   the affixes raw on every arm, while the legacy XML marker builder
     *                                   sanitizes each affix on the `g:shipping` price arm. `Feed_Writer_CSV`
     *                                   therefore passes true, so a ` AUD` suffix keeps its space and a
     *                                   migrated CSV price still reads `5.99 AUD`. The default is false,
     *                                   which keeps the XML and JSONL output byte for byte as it was.
     *                                   See #1039.
     * @return array Resolved groups; each group is a list of `array( 'tag' => string, 'value' => string )` rows.
     */
    public static function resolve( array $attribute, array $product_data, Product_Feed $feed, bool $raw_affixes = false ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        $template_groups = isset( $attribute['sub_attributes'] ) && is_array( $attribute['sub_attributes'] )
            ? $attribute['sub_attributes']
            : array();

        if ( empty( $template_groups ) ) {
            return array();
        }

        $resolved_groups = array();
        $parent_name     = isset( $attribute['feed_name'] ) ? (string) $attribute['feed_name'] : '';

        if ( ! empty( $attribute['zones_auto'] ) ) {
            // One resolved group per zone/rate row from Shipping_Data. The single
            // template group is the row template that every zone reuses.
            $template = isset( $template_groups[0] ) && is_array( $template_groups[0] ) ? $template_groups[0] : array();
            if ( empty( $template ) ) {
                return array();
            }

            $zone_rows = isset( $product_data[ self::ZONE_ROWS_KEY ] ) ? (array) $product_data[ self::ZONE_ROWS_KEY ] : array();

            foreach ( $zone_rows as $zone_row ) {
                if ( ! is_array( $zone_row ) ) {
                    continue;
                }
                $resolved_groups[] = self::resolve_group( $template, $product_data, $zone_row, $parent_name, $raw_affixes );
            }

            return $resolved_groups;
        }

        foreach ( $template_groups as $template ) {
            if ( ! is_array( $template ) ) {
                continue;
            }
            $resolved_groups[] = self::resolve_group( $template, $product_data, array(), $parent_name, $raw_affixes );
        }

        return $resolved_groups;
    }

    /**
     * Replace the marker-string payload of every configured nested parent with resolved groups.
     *
     * Runs once per product, in the generation loop, where the raw `$product_data`
     * is still in scope. `Feed_Writer_XML` then writes each array value as nested
     * elements, and `Feed_Writer_JSONL` encodes it as a nested object. Both writers
     * therefore read one payload, built once.
     *
     * The two shapes key the parent differently. SHAPE_ROWS keeps the output tag, because
     * the XML writer needs the prefix for the element name. SHAPE_OBJECT stores the group
     * under the tag with its namespace prefix stripped, so the JSONL parent key matches
     * its unprefixed child keys: `{"shipping":[{"country":"US"}]}`, not `{"g:shipping":…}`.
     *
     * A parent that repeats through a legacy `_N` key keeps the marker-string path.
     * The new schema expresses repetition as several template groups on one row, so
     * only the first (unsuffixed) occurrence of a `feed_name` is resolved.
     *
     * A configured parent is written whether or not the payload already held its key.
     * This is deliberate: with `sub_attributes` present the groups ARE the value, so
     * a parent whose own mapping resolved to nothing still emits. The schema relies
     * on it - the `g:certification` example in the brief maps `source_value` to an
     * empty string and carries its whole value in static sub-attributes.
     *
     * @since 13.5.8
     *
     * @param array        $payload      The assembled product payload, keyed by output tag.
     * @param array        $product_data The raw product data, keyed by internal attribute key.
     * @param Product_Feed $feed         The product feed instance.
     * @param string       $shape        Optional. self::SHAPE_ROWS for the XML writer, or
     *                                   self::SHAPE_OBJECT for the JSONL payload.
     * @return array The payload, with configured nested parents replaced by resolved groups.
     */
    public static function apply_to_payload( array $payload, array $product_data, Product_Feed $feed, string $shape = self::SHAPE_ROWS ): array {
        $feed_attributes = $feed->feed_attributes;
        if ( ! is_array( $feed_attributes ) || empty( $feed_attributes ) ) {
            return $payload;
        }

        $resolved_tags = array();
        $repeat_limits = Repeatable_Attributes::get_limits( (string) $feed->channel_hash );

        foreach ( $feed_attributes as $attribute ) {
            if ( ! is_array( $attribute ) || empty( $attribute['sub_attributes'] ) ) {
                continue;
            }

            $feed_name = isset( $attribute['feed_name'] ) ? (string) $attribute['feed_name'] : '';
            if ( '' === $feed_name ) {
                continue;
            }

            /*
             * Scalar repeats (#1133) reuse the same `sub_attributes` key but carry no
             * child tag, so they are not nested groups and must not be resolved here.
             * Each one is already its own attribute row by the time this runs, so the
             * value is in the payload: `Feed_Attributes_Migrator::to_legacy()` expands
             * them on the generation path, and `Field_Mapping_Preview::build_payload()`
             * calls `Repeatable_Attributes::expand_rows()` directly on the preview path.
             *
             * Skipping matters twice over: resolve() would drop every untagged row and
             * hand back empty groups, and the empty-groups arm below would then UNSET
             * the parent key, deleting the value those expanded rows wrote.
             *
             * The channel's declaration decides, not the shape alone. An untagged group
             * on an attribute the channel never declared repeatable is a half-configured
             * nested parent, and it must keep falling through to resolve(), which drops
             * it — exactly as it did before repeats existed.
             */
            if ( isset( $repeat_limits[ $feed_name ] ) && Repeatable_Attributes::get_repeat_groups( $attribute ) ) {
                continue;
            }

            // The JSONL payload is encoded as it stands, and a JSON key carries no
            // namespace, so the parent key drops its prefix exactly as the child keys
            // do in to_objects(). The XML writer writes elements, so it keeps the
            // prefix. `free_shipping_threshold` carries no prefix and is unchanged.
            $output_key = self::SHAPE_OBJECT === $shape ? self::strip_namespace_prefix( $feed_name ) : $feed_name;

            // Dedupe on the key the payload is written under, not on `feed_name`. Two
            // parents whose names differ only by prefix (`g:shipping` and a filter-added
            // `x:shipping`) collapse to one JSONL key, and keying on `feed_name` would
            // let the second silently overwrite the first. First occurrence wins, which
            // is how a repeated `feed_name` already behaves.
            if ( isset( $resolved_tags[ $output_key ] ) ) {
                continue;
            }

            $resolved_tags[ $output_key ] = true;

            $groups = self::resolve( $attribute, $product_data, $feed );

            if ( self::SHAPE_OBJECT === $shape ) {
                $groups = self::to_objects( $groups );
            }

            if ( empty( $groups ) ) {
                // A configured parent that resolves to nothing emits nothing. Drop
                // the legacy marker string too, so the two paths cannot both write.
                unset( $payload[ $feed_name ] );
                continue;
            }

            if ( $output_key !== $feed_name ) {
                // Drop the marker string that the parent's own mapping left under the
                // prefixed key, so one parent cannot ship in both shapes.
                unset( $payload[ $feed_name ] );
            }

            $payload[ $output_key ] = $groups;
        }

        return $payload;
    }

    /**
     * Convert resolved rows into one flat object per group.
     *
     * `Feed_Writer_JSONL` encodes the payload as-is, so a nested parent must reach it
     * as the object the spec shows rather than as tag/value metadata:
     *
     *     {"shipping":[{"country":"US","service":"Standard","price":"5.99 USD"}]}
     *
     * Each key is the sub-attribute tag without its namespace prefix, because a JSON
     * key carries no namespace. `apply_to_payload()` strips the prefix from the parent
     * key the same way, so the parent and its children agree. A row with an empty value
     * is dropped, and a group left with no rows is dropped with it, matching what the
     * XML writer omits.
     *
     * @since 13.5.8
     *
     * @param array $groups Resolved groups in the rows shape.
     * @return array Resolved groups in the object shape.
     */
    private static function to_objects( array $groups ): array {
        $objects = array();

        foreach ( $groups as $group ) {
            if ( ! is_array( $group ) ) {
                continue;
            }

            $object = array();
            foreach ( $group as $row ) {
                if ( ! is_array( $row ) || empty( $row['tag'] ) || ! isset( $row['value'] ) || '' === (string) $row['value'] ) {
                    continue;
                }

                $object[ self::strip_namespace_prefix( (string) $row['tag'] ) ] = (string) $row['value'];
            }

            if ( ! empty( $object ) ) {
                $objects[] = $object;
            }
        }

        return $objects;
    }

    /**
     * Strip a tag's namespace prefix.
     *
     * A JSON key carries no namespace, so the JSONL shape uses the bare tag for both
     * the parent key and every child key. A tag with no prefix is returned unchanged.
     *
     * @since 13.5.8
     *
     * @param string $tag The output tag (e.g. `g:shipping`).
     * @return string The tag without its namespace prefix (e.g. `shipping`).
     */
    private static function strip_namespace_prefix( string $tag ): string {
        $separator = strpos( $tag, ':' );

        return false === $separator ? $tag : substr( $tag, $separator + 1 );
    }

    /**
     * Resolve one sub-attribute row's source to its raw, un-affixed value.
     *
     * The one resolver for `SUB_SOURCES`: `resolve_group()` calls it for every row
     * of a nested group, so a source added to the enum is understood by the whole
     * class at once. A scalar repeat never reaches it — a repeat is expanded into
     * its own legacy attribute row long before this class runs, so the generation
     * chain resolves it as an ordinary row.
     *
     * @since 13.5.8
     *
     * @param mixed $row          A sub-attribute row (source, source_value, …).
     * @param array $product_data The raw product data, keyed by internal attribute key.
     * @param array $zone_row     Optional. A single `Shipping_Data` row, for `zone` sources.
     * @return string The resolved value, or an empty string.
     */
    private static function resolve_row_value( $row, array $product_data, array $zone_row = array() ): string {
        if ( ! is_array( $row ) ) {
            return '';
        }

        $source       = isset( $row['source'] ) ? (string) $row['source'] : 'empty';
        $source_value = isset( $row['source_value'] ) ? (string) $row['source_value'] : '';

        switch ( $source ) {
            case 'zone':
                // `source_value` is the explicit Shipping_Data row key. Never derive
                // it from the tag name — `ltrim( $tag, 'g:' )` corrupts tags such as
                // `g:gtin`. Rows are array_filter()-ed, so absent keys are normal.
                return self::resolve_zone_value( $source_value, $zone_row );
            case 'field':
                return isset( $product_data[ $source_value ] ) && is_scalar( $product_data[ $source_value ] )
                    ? (string) $product_data[ $source_value ]
                    : '';
            case 'static':
                return $source_value;
            case 'page_url':
            case 'post_url':
                /*
                 * `source_value` holds a page or post ID, matching the top-level
                 * row. The legacy chain resolves it the same way
                 * (`classes/class-get-products.php`, the page_url/post_url arm).
                 * get_permalink() returns false for a missing or unpublished
                 * object, which resolves to '' and the writer then omits the child.
                 */
                $permalink = '' !== $source_value ? get_permalink( (int) $source_value ) : false;
                return is_string( $permalink ) ? $permalink : '';
            default:
                // 'empty' and unknown sources resolve to '' and the writer omits them.
                return '';
        }
    }

    /**
     * Resolve one template group against the product data and an optional zone row.
     *
     * @since 13.5.8
     *
     * @param array  $template     The sub-attribute template rows (tag, source, source_value, prefix, suffix).
     * @param array  $product_data The raw product data, keyed by internal attribute key.
     * @param array  $zone_row     A single `Shipping_Data` row, or an empty array when the parent has no zones.
     * @param string $parent_name  The parent attribute's `feed_name`, which selects the affix rule.
     * @param bool   $raw_affixes  Whether the caller forces the raw affix rule for every parent.
     * @return array A list of `array( 'tag' => string, 'value' => string )` rows.
     */
    private static function resolve_group( array $template, array $product_data, array $zone_row, string $parent_name = '', bool $raw_affixes = false ): array {
        $resolved  = array();
        $raw_affix = $raw_affixes || in_array( $parent_name, self::RAW_AFFIX_PARENTS, true );

        foreach ( $template as $sub ) {
            if ( ! is_array( $sub ) || empty( $sub['tag'] ) ) {
                continue;
            }

            $raw_value = self::resolve_row_value( $sub, $product_data, $zone_row );

            $prefix = isset( $sub['prefix'] ) ? (string) $sub['prefix'] : '';
            $suffix = isset( $sub['suffix'] ) ? (string) $sub['suffix'] : '';

            $resolved[] = array(
                'tag'   => (string) $sub['tag'],
                // Prefix and suffix only apply to a value that exists, so an absent
                // zone key cannot produce a child holding just the prefix.
                //
                // The affix rule follows the parent's own legacy arm - see
                // RAW_AFFIX_PARENTS. A sanitized affix loses its inner whitespace,
                // because sanitize_text_field() trims, so " USD" emits as "USD" on the
                // `g:shipping` price arm and as " USD" on the free-shipping-threshold
                // arm. Each arm therefore keeps the bytes it had before migration.
                //
                // The base value is sanitized upstream only when the source is 'zone'
                // (resolve_zone_value()). 'field' and 'static' pass their value through
                // as configured, matching legacy, and every value is escaped at write
                // time (ENT_XML1 in Feed_Writer_XML::add_escaped_child()).
                'value' => self::apply_affixes( $raw_value, $prefix, $suffix, $raw_affix ),
            );
        }

        return $resolved;
    }

    /**
     * Wrap a resolved value in its prefix and suffix, following the parent's legacy arm.
     *
     * An empty value takes no affix, so an absent zone key cannot produce a child that
     * holds only the prefix.
     *
     * @since 13.5.8
     *
     * @param string $value     The resolved base value.
     * @param string $prefix    The configured prefix.
     * @param string $suffix    The configured suffix.
     * @param bool   $raw_affix Whether the affixes concatenate raw (see RAW_AFFIX_PARENTS).
     * @return string The value with its affixes applied.
     */
    private static function apply_affixes( string $value, string $prefix, string $suffix, bool $raw_affix ): string {
        if ( '' === $value ) {
            return '';
        }

        if ( $raw_affix ) {
            // Raw concatenation, trimmed once - what the legacy free-shipping-threshold
            // arm emits, because it builds "$prefix$v$suffix" and the writer trims it.
            return trim( $prefix . $value . $suffix );
        }

        return sanitize_text_field( $prefix ) . $value . sanitize_text_field( $suffix );
    }

    /**
     * Read one value out of a `Shipping_Data` row.
     *
     * Applies the same per-key preparation the legacy marker-string builder applies
     * in `class-get-products.php`, so a migrated `g:shipping` matches the legacy
     * output for the same zones: every value goes through `sanitize_text_field()`,
     * and a postal code is formatted for its row's country first.
     *
     * @since 13.5.8
     *
     * @param string $zone_key The explicit `Shipping_Data` row key.
     * @param array  $zone_row The zone row.
     * @return string The prepared value, or an empty string when the key is absent.
     */
    private static function resolve_zone_value( string $zone_key, array $zone_row ): string {
        if ( '' === $zone_key || ! isset( $zone_row[ $zone_key ] ) || ! is_scalar( $zone_row[ $zone_key ] ) ) {
            return '';
        }

        $value = (string) $zone_row[ $zone_key ];

        if ( 'postal_code' === $zone_key ) {
            $value = Product_Feed_Helper::format_shipping_postal_code(
                $value,
                isset( $zone_row['country'] ) ? (string) $zone_row['country'] : ''
            );
        }

        return sanitize_text_field( $value );
    }
}
