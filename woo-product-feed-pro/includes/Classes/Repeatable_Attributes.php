<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

defined( 'ABSPATH' ) || exit;

use AdTribes\PFP\Helpers\Product_Feed_Helper;

/**
 * Repeatable_Attributes class.
 *
 * A channel attribute has two independent properties: a SHAPE, scalar or nested,
 * and a CARDINALITY, single or repeatable. The plugin already models nested and
 * repeatable through `Sub_Attribute_Resolver` template groups, and scalar and
 * single through a plain `feed_attributes` row. This class supplies the missing
 * fourth cell: scalar and repeatable — one attribute that emits several sibling
 * elements holding plain values, such as `g:additional_image_link` and
 * `g:included_destination`.
 *
 * Cardinality is declared once, in the channel class:
 *
 *     'Additional image URL' => array(
 *         'name'       => 'additional_image_link',
 *         'feed_name'  => 'g:additional_image_link',
 *         'format'     => 'optional',
 *         'repeatable' => 10,
 *     ),
 *
 * Nothing else needs a code change to make a further attribute repeatable, on
 * this or on any other channel. The declaration drives the limit the field
 * mapping UI enforces and the suffix stripping in `Feed_Writer_XML`.
 *
 * STORAGE. The first value stays where every other attribute keeps its value:
 * the row's own `source`/`source_value`. Values two and up are held as
 * `sub_attributes` groups of one row whose `tag` is empty, meaning "the parent
 * element's own text" rather than a named child. Keeping value one in the row
 * is what makes an existing single-valued feed need no migration at all, and it
 * keeps the mapping table's Source column meaning the same thing on every row.
 *
 * OUTPUT. A repeat IS a duplicated mapping row — that is exactly how a store
 * expressed one before this existed — so `expand_rows()` turns it back into one,
 * and every format then travels the duplicate-row path the generation chain has
 * always had. Repeats must NOT instead be written into the assembled payload:
 * that payload reaches the XML and JSONL writers only, and a product's CSV/TXT
 * row is built by its own earlier loop that never reads it.
 *
 * @since 13.5.8
 */
class Repeatable_Attributes {

    /**
     * Sanity ceiling on a channel's declared `repeatable` limit.
     *
     * Mirrors `MAX_REPEATS` in `fieldMappingStore.ts`. The two must agree: the UI
     * clamps to it, so without the same clamp here a channel declaring a larger
     * limit would expand more stored repeats than the UI can ever show or delete.
     * It is not a specification limit — a channel declares its own real one, and
     * none currently exceeds 10.
     *
     * @since 13.5.8
     */
    const MAX_REPEATS = 50;

    /**
     * The `repeatable` limits of a channel, keyed by channel key then output tag.
     *
     * `get_limits()` runs per product per feed, and a miss walks the channel's
     * whole attribute catalog (194 entries on the biggest channel).
     *
     * @since 13.5.8
     *
     * @var array
     */
    private static $limits_cache = array();

    /**
     * Get the repeat limit of every repeatable attribute of a channel.
     *
     * @since 13.5.8
     *
     * @param string $channel_hash The feed's channel hash.
     * @return array Repeat limits keyed by output tag. Empty when the channel declares none.
     */
    public static function get_limits( string $channel_hash ): array {
        if ( isset( self::$limits_cache[ $channel_hash ] ) ) {
            return self::$limits_cache[ $channel_hash ];
        }

        $channel        = Product_Feed_Helper::get_channel_from_legacy_channel_hash( $channel_hash );
        $channel_fields = is_array( $channel ) && isset( $channel['fields'] ) ? (string) $channel['fields'] : '';
        $catalog        = '' !== $channel_fields ? Product_Feed_Helper::get_channel_attribute_catalog( $channel_fields ) : array();

        $limits = array();

        foreach ( (array) $catalog as $definitions ) {
            foreach ( (array) $definitions as $definition ) {
                if ( ! is_array( $definition ) || empty( $definition['feed_name'] ) || empty( $definition['repeatable'] ) ) {
                    continue;
                }

                $limit = min( (int) $definition['repeatable'], self::MAX_REPEATS );
                if ( $limit > 1 ) {
                    $limits[ (string) $definition['feed_name'] ] = $limit;
                }
            }
        }

        self::$limits_cache[ $channel_hash ] = $limits;

        return $limits;
    }

    /**
     * Collapse `_N` repeat keys into one JSON array under the base tag.
     *
     * The XML writer normalises a repeat away key by key, because it writes one
     * element per occurrence and several elements may share a name. A JSON object
     * cannot do that — its keys are unique — so the occurrences have to become one
     * array under the base tag, which is also the shape a JSON feed spec asks for.
     *
     * Only a tag the channel DECLARES repeatable is collapsed, exactly as in
     * `strip_repeat_suffix()`. A duplicated tag the channel calls single-valued
     * keeps the `_N` keys it has always had, so no existing feed's shape changes
     * unless its channel opted the tag in.
     *
     * A single value stays a scalar. Repetition is what makes an array, so a feed
     * that maps one additional image is unchanged.
     *
     * Order is the payload's own, which is the order the mapping rows were expanded
     * in, so value one stays first.
     *
     * @since 13.5.8
     *
     * @param array $payload The assembled product payload, keyed by output tag.
     * @param array $limits  Repeat limits keyed by output tag, from `get_limits()`.
     * @return array The payload, with each repeated tag holding an array of its values.
     */
    public static function collapse_repeats_to_array( array $payload, array $limits ): array {
        if ( empty( $limits ) ) {
            return $payload;
        }

        $collapsed = array();

        foreach ( $payload as $key => $value ) {
            $base = self::strip_repeat_suffix( (string) $key, $limits );

            if ( $base === (string) $key ) {
                $collapsed[ $key ] = $value;
                continue;
            }

            // A repeat whose first value resolved to nothing still belongs to the
            // tag, so it seeds the array rather than being dropped on the floor.
            $collapsed[ $base ] = array_merge(
                array_values( (array) ( $collapsed[ $base ] ?? array() ) ),
                array( $value )
            );
        }

        return $collapsed;
    }

    /**
     * Whether a group is a scalar repeat rather than a nested sub-attribute group.
     *
     * A scalar repeat is one row carrying no tag: there is no child element to
     * name, because the value belongs to the parent element itself.
     *
     * @since 13.5.8
     *
     * @param mixed $group A `sub_attributes` group.
     * @return bool True when the group holds exactly one untagged row.
     */
    private static function is_scalar_repeat_group( $group ): bool {
        if ( ! is_array( $group ) || 1 !== count( $group ) ) {
            return false;
        }

        $row = reset( $group );

        return is_array( $row ) && '' === ( isset( $row['tag'] ) ? trim( (string) $row['tag'] ) : '' );
    }

    /**
     * The scalar repeat groups of an attribute row, or an empty array.
     *
     * An attribute is read as scalar-repeating only when EVERY group is a scalar
     * repeat. A mixed configuration is a nested parent that happens to hold a
     * malformed group, and it stays with `Sub_Attribute_Resolver`, which drops
     * the untagged row exactly as it does today.
     *
     * @since 13.5.8
     *
     * @param array $attribute A `feed_attributes` row.
     * @return array The scalar repeat groups, or an empty array when the row is not scalar-repeating.
     */
    public static function get_repeat_groups( array $attribute ): array {
        $groups = isset( $attribute['sub_attributes'] ) && is_array( $attribute['sub_attributes'] )
            ? $attribute['sub_attributes']
            : array();

        if ( empty( $groups ) ) {
            return array();
        }

        foreach ( $groups as $group ) {
            if ( ! self::is_scalar_repeat_group( $group ) ) {
                return array();
            }
        }

        return $groups;
    }

    /**
     * Strip the `_N` repeat suffix from a payload key.
     *
     * Only a tag the channel DECLARES repeatable is stripped, and only when the
     * suffix is entirely digits. Both limits matter: a blanket `<tag>_` match
     * would truncate real Google attributes that merely start with another tag's
     * name — `g:shipping_label`, `g:shipping_weight` and `g:tax_category` all do.
     *
     * The eight tags that already carry a hand-written `_N`-tolerant branch in
     * `Feed_Writer_XML::write_product()` are unaffected by this: their branch
     * matches a suffixed key already and writes the base tag either way. This
     * exists so that declaring a NINTH attribute repeatable needs no ninth
     * branch.
     *
     * @since 13.5.8
     *
     * @param string $key    The payload key.
     * @param array  $limits Repeat limits keyed by output tag, from `get_limits()`.
     * @return string The base output tag, or the key unchanged.
     */
    public static function strip_repeat_suffix( string $key, array $limits ): string {
        // Runs on every payload key of every product, and almost none can match.
        // The last-character test costs nothing next to a regular expression.
        if ( empty( $limits ) || '' === $key || ! ctype_digit( $key[ strlen( $key ) - 1 ] ) ) {
            return $key;
        }

        if ( ! preg_match( '/^(.+)_(\d+)$/', $key, $matches ) ) {
            return $key;
        }

        return isset( $limits[ $matches[1] ] ) ? $matches[1] : $key;
    }

    /**
     * Expand every configured repeat into its own flat attribute row.
     *
     * This is the ONE place repeats leave their stored shape. Every consumer takes
     * the expanded list and then treats a repeat exactly as the generation chain has
     * always treated a duplicated mapping row — which is what it is.
     *
     * Expanding here rather than at the payload is deliberate, and the earlier design
     * got it wrong. A product's CSV/TXT row is built by its own loop over the
     * legacy-shaped attribute list, well before the XML/JSONL payload is assembled,
     * and it never reads that payload. A repeat written into the payload therefore
     * reached XML and JSONL only, and every CSV and TXT feed silently kept the first
     * value alone. Expanding the attribute list instead reaches all four formats
     * through code that already existed, keeps each repeat at its own row position
     * (so element and column order do not move), and needs no writer to cooperate.
     *
     * Only an attribute the channel DECLARES repeatable expands. An untagged group on
     * any other attribute is left to `Sub_Attribute_Resolver`, which drops it exactly
     * as it did before repeats existed — a blank sub-attribute tag must not silently
     * turn `g:shipping` into a duplicated scalar row.
     *
     * @since 13.5.8
     *
     * @param array $feed_attributes The `feed_attributes` rows.
     * @param array $limits          Repeat limits keyed by output tag, from `get_limits()`.
     * @return array The rows, with each repeat following the row it repeats.
     */
    public static function expand_rows( array $feed_attributes, array $limits ): array {
        if ( empty( $limits ) ) {
            return $feed_attributes;
        }

        $expanded = array();

        foreach ( $feed_attributes as $attribute ) {
            if ( ! is_array( $attribute ) ) {
                continue;
            }

            $expanded[] = $attribute;

            $feed_name = isset( $attribute['feed_name'] ) ? (string) $attribute['feed_name'] : '';
            if ( '' === $feed_name || ! isset( $limits[ $feed_name ] ) ) {
                continue;
            }

            $groups = self::get_repeat_groups( $attribute );
            if ( empty( $groups ) ) {
                continue;
            }

            // The row itself holds value one, so the channel's limit leaves room for
            // one fewer repeat. A stored configuration past the limit is truncated
            // rather than trusted: the limit is the channel's specification.
            $groups = array_slice( $groups, 0, max( 0, $limits[ $feed_name ] - 1 ) );

            foreach ( $groups as $group ) {
                $row = reset( $group );

                $expanded[] = array(
                    'feed_name'    => $feed_name,
                    'source'       => isset( $row['source'] ) ? (string) $row['source'] : 'field',
                    'source_value' => isset( $row['source_value'] ) ? (string) $row['source_value'] : '',
                    'prefix'       => isset( $row['prefix'] ) ? (string) $row['prefix'] : '',
                    'suffix'       => isset( $row['suffix'] ) ? (string) $row['suffix'] : '',
                    'is_custom'    => ! empty( $attribute['is_custom'] ),
                );
            }
        }

        return $expanded;
    }
}
