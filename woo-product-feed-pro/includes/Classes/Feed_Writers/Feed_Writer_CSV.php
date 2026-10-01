<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes\Feed_Writers
 */

namespace AdTribes\PFP\Classes\Feed_Writers;

defined( 'ABSPATH' ) || exit;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Traits\Singleton_Trait;
use AdTribes\PFP\Classes\Sub_Attribute_Resolver;
use AdTribes\PFP\Factories\Product_Feed;

/**
 * CSV Feed Writer class.
 *
 * Serializes a migrated (`feed_attributes`) feed's configured sub-attribute
 * groups into the single CSV/TSV cell their parent attribute owns, in the format
 * the legacy path already emits: groups joined by `||`, values joined by `:`.
 *
 * Reached only through the dispatch fork in the CSV row builder. Unmigrated feeds
 * and every non-Google channel keep the legacy path untouched.
 *
 * SCOPE. This writer refines the cells of nested parents on a row the legacy
 * builder has already assembled. It does not re-assemble the row:
 *
 * - The column order is the legacy order by construction, because the writer only
 *   ever replaces a cell in place. That order is the Facebook DRM contract - Elite
 *   reads `$pieces_row` by position.
 * - Every non-nested cell is still built by the legacy branch chain, which reads
 *   the legacy-shaped rows the compat accessor resolves for a migrated feed. There
 *   is nothing to port: the same code produces the same bytes for both schemas.
 * - `adt_product_feed_csv_header` and `adt_product_feed_csv_row_data` keep their
 *   existing call sites, which already hand out the legacy shape. See the class
 *   docs of `write_row()`.
 *
 * @since 13.5.8
 */
class Feed_Writer_CSV extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Delimiter between two serialized sub-attribute groups.
     *
     * Matches the legacy CSV shipping flatten, which joins zone rows with `||`
     * (`classes/class-get-products.php`, the array arm of the CSV row builder).
     *
     * @since 13.5.8
     */
    const GROUP_DELIMITER = '||';

    /**
     * Delimiter between two values inside one serialized group.
     *
     * @since 13.5.8
     */
    const VALUE_DELIMITER = ':';

    /**
     * The feed instance the memoized nested parent rows were derived from.
     *
     * Held as the object, not as an id. Keeping the reference makes the identity
     * test below exact: the object cannot be collected while this property points
     * at it, so its identity can never be reused by a later feed.
     *
     * @since 13.5.8
     * @var Product_Feed|null
     */
    private $nested_attributes_feed = null;

    /**
     * Nested parent rows for the feed instance in `$nested_attributes_feed`.
     *
     * @since 13.5.8
     * @var array
     */
    private $nested_attributes_cache = array();

    /**
     * Replace the cell of every configured nested parent with its serialized groups.
     *
     * `$attributes` is the legacy-shaped config the row was built from - the
     * `adt_feed_get_attributes` output, one entry per column, in column order.
     * It is not the raw `feed_attributes` value: the writer needs the same list
     * the legacy builder walked, or a column index would not name the same
     * attribute. The `feed_attributes` rows are read off `$feed`.
     *
     * The two CSV hooks stay where they are, in the monolith. Both already receive
     * the legacy shape for a migrated feed, because the header composition and the
     * row builder both read `$feed->attributes` through the compat accessor, and
     * `adt_product_feed_csv_row_data` still fires once, on the row this writer
     * returns. Firing them from here instead would fork a second call site for each
     * one and would drop the `adt_feed_get_attributes` filter that the monolith
     * applies to the config it passes.
     *
     * A parent whose `feed_name` repeats takes its groups on the FIRST matching
     * column only. `Sub_Attribute_Resolver::apply_to_payload()` resolves a repeated
     * name the same way, so the CSV and the XML paths agree on which occurrence
     * carries the nested value.
     *
     * @since 13.5.8
     *
     * @param array        $row          The row the legacy builder assembled, indexed by column position.
     * @param array        $attributes   The legacy-shaped attribute config, one entry per column, in column order.
     * @param array        $product_data The assembled product data for the current product.
     * @param Product_Feed $feed         The product feed instance.
     * @return array The row, with each configured nested parent's cell replaced. Column order is unchanged.
     */
    public function write_row( array $row, array $attributes, array $product_data, Product_Feed $feed ): array {
        $nested = $this->get_nested_attributes( $feed );
        if ( empty( $nested ) ) {
            return $row;
        }

        $columns = array_values( $attributes );

        /*
         * The legacy builder assembles the row as one quoted string and splits it
         * on "','", so a cell whose own value holds that sequence splits into two
         * columns and every later column shifts. A shifted row is already broken
         * output, and writing into a computed index would then overwrite a
         * different attribute's cell, so leave the row exactly as legacy built it.
         */
        if ( count( $row ) !== count( $columns ) ) {
            return $row;
        }

        $written = array();

        foreach ( $columns as $index => $column ) {
            $feed_name = is_array( $column ) && isset( $column['attribute'] ) ? (string) $column['attribute'] : '';

            if ( '' === $feed_name || ! isset( $nested[ $feed_name ] ) || isset( $written[ $feed_name ] ) ) {
                continue;
            }

            /*
             * The row is a contiguous list, because it comes straight out of
             * explode(). Assigning a key it does not hold would append the cell to
             * the end of the row instead of replacing a column, so skip rather than
             * move an attribute's value to the wrong position.
             */
            if ( ! array_key_exists( $index, $row ) ) {
                continue;
            }

            $written[ $feed_name ] = true;

            $attribute = $nested[ $feed_name ];

            /*
             * Raw affixes. The legacy CSV row builder concatenates a row's Prefix and
             * Suffix raw on every arm, while the legacy XML marker builder sanitizes
             * each affix on the `g:shipping` price arm - and sanitize_text_field()
             * trims, so a ' AUD' suffix loses its space there. Passing true keeps a
             * migrated CSV price reading '5.99 AUD', which is what legacy CSV emits
             * and what Google's shipping price format wants.
             */
            $groups = Sub_Attribute_Resolver::resolve( $attribute, $product_data, $feed, true );

            $row[ $index ] = $this->serialize_groups( $groups, $attribute, $feed );
        }

        return $row;
    }

    /**
     * Collect the feed's nested parent attributes, keyed by `feed_name`.
     *
     * Only a row that actually carries `sub_attributes` is collected. A migrated
     * parent with none - `g:product_detail` and the other marker-driven parents,
     * which the migrator deliberately leaves unseeded - keeps the legacy cell its
     * own branch builds.
     *
     * Memoized against the `$feed` OBJECT, because the result is feed
     * configuration and `write_row()` asks for it once per product. It is not
     * memoized against the feed id: this class is a singleton, so a memo keyed by
     * id outlives the batch that filled it, and `Cron` loads a fresh `Product_Feed`
     * from the database on every batch tick. An admin who saves a field-mapping
     * change while a large catalog is still generating would then keep the old
     * mapping for every remaining batch in that queue-runner request. Every product
     * in one tick shares one `$feed` object, so the object test keeps the whole
     * per-tick saving and re-derives as soon as the next tick loads its own feed.
     *
     * @since 13.5.8
     *
     * @param Product_Feed $feed The product feed instance.
     * @return array The nested parent rows, keyed by `feed_name`. First occurrence wins.
     */
    private function get_nested_attributes( Product_Feed $feed ): array {
        if ( $this->nested_attributes_feed === $feed ) {
            return $this->nested_attributes_cache;
        }

        $feed_attributes = $feed->feed_attributes;
        if ( ! is_array( $feed_attributes ) ) {
            return array();
        }

        $nested = array();

        foreach ( $feed_attributes as $attribute ) {
            if ( ! is_array( $attribute ) || empty( $attribute['sub_attributes'] ) ) {
                continue;
            }

            $feed_name = isset( $attribute['feed_name'] ) ? (string) $attribute['feed_name'] : '';

            if ( '' === $feed_name || isset( $nested[ $feed_name ] ) ) {
                continue;
            }

            $nested[ $feed_name ] = $attribute;
        }

        $this->nested_attributes_feed  = $feed;
        $this->nested_attributes_cache = $nested;

        return $nested;
    }

    /**
     * Serialize resolved sub-attribute groups into one cell.
     *
     * Two rules decide what an empty value does, and they are not the same rule:
     *
     * - A zone-driven parent (`zones_auto`, i.e. `g:shipping`) DROPS an empty
     *   value. `Shipping_Data` runs its rows through `array_filter()`, so the
     *   legacy cell already emits no position for a zone with no region -
     *   `US:10001:Standard:5.99`, not `US::10001:Standard:5.99`. The arity of the
     *   cell varies per zone on both paths.
     * - Every other parent KEEPS an empty position. Google reads a bare column
     *   header as the attribute's full positional order, so a `product_detail`
     *   group with no section name has to emit the leading placeholder -
     *   `:Warranty:1 year`, not `Warranty:1 year`. Collapsing it produces output
     *   that generates no error and only surfaces later as a Merchant Center
     *   diagnostic. See the `product_detail` note on #1039.
     *
     * A group whose every value resolved empty writes nothing at all, rather than a
     * cell of bare delimiters. `Feed_Writer_XML::write_nested()` omits the same
     * group for the same reason.
     *
     * The value is NOT escaped here. `woosea_create_csvtxt_feed()` owns the CSV
     * quoting for both paths.
     *
     * @since 13.5.8
     *
     * @param array        $groups    Resolved groups from `Sub_Attribute_Resolver::resolve()`.
     * @param array        $attribute The parent's `feed_attributes` row.
     * @param Product_Feed $feed      The product feed instance.
     * @return string The serialized cell.
     */
    private function serialize_groups( array $groups, array $attribute, Product_Feed $feed ): string {
        $drop_empty_positions = ! empty( $attribute['zones_auto'] );
        $serialized_groups    = array();

        foreach ( $groups as $group ) {
            if ( ! is_array( $group ) ) {
                continue;
            }

            $values = array();
            foreach ( $group as $sub ) {
                if ( ! is_array( $sub ) || empty( $sub['tag'] ) ) {
                    continue;
                }

                $value = isset( $sub['value'] ) ? (string) $sub['value'] : '';

                if ( '' === $value && $drop_empty_positions ) {
                    continue;
                }

                $values[] = $value;
            }

            // Every position resolved empty - write nothing rather than delimiters.
            if ( '' === implode( '', $values ) ) {
                continue;
            }

            $serialized_groups[] = implode( self::VALUE_DELIMITER, $values );
        }

        // The legacy row builder trims every cell (`array_map( 'trim', $pieces_row )`),
        // so trim here too: a configured prefix of "USD " on the first value would
        // otherwise survive where the legacy cell lost it.
        $cell = trim( implode( self::GROUP_DELIMITER, $serialized_groups ) );

        /**
         * Filter the serialized sub-attribute cell of one CSV/TSV column.
         *
         * Version 1 emits the default serialization only - groups joined by `||`,
         * values joined by `:` - which is the format the legacy CSV path already
         * produces. A channel whose CSV specification wants another shape overrides
         * it here.
         *
         * @since 13.5.8
         *
         * @param string       $cell      The serialized cell.
         * @param array        $groups    The resolved groups, each a list of `array( 'tag' => …, 'value' => … )` rows.
         * @param array        $attribute The parent's `feed_attributes` row, including its `sub_attributes` config.
         * @param Product_Feed $feed      The product feed instance.
         * @return string The cell to write.
         */
        return (string) apply_filters( 'adt_csv_sub_attribute_format', $cell, $groups, $attribute, $feed );
    }

    /**
     * Whether a feed's CSV/TSV rows are written through this writer.
     *
     * Delegates to `Feed_Writer_XML::uses_new_writer()` on purpose. That method is
     * the epic's single dispatch predicate - a migrated feed on the Google Shopping
     * chain - and the XML fork, the nested JSONL payload and this fork all have to
     * answer it the same way. A second copy here would let the formats drift.
     *
     * @since 13.5.8
     *
     * @param Product_Feed $feed        The product feed instance.
     * @param array        $feed_config The feed's channel configuration.
     * @return bool True when the feed writes through this writer.
     */
    public static function uses_new_writer( Product_Feed $feed, $feed_config ): bool {
        return Feed_Writer_XML::uses_new_writer( $feed, $feed_config );
    }

    /**
     * Run the class.
     *
     * @since 13.5.8
     */
    public function run() {
        // No hooks needed for this writer class.
        // It's called directly from the feed generation dispatch fork.
    }
}
