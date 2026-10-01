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
 * Feed_Attributes_Migrator class.
 *
 * Transforms legacy `attributes` rows into the normalized `feed_attributes`
 * schema (and back), following the same migration pattern used for
 * `filters` → `feed_filters` and `rules` → `feed_rules`.
 *
 * @since 13.5.8
 */
class Feed_Attributes_Migrator {

    /**
     * Data version stamped into `data_version['feed_attributes']` when a feed
     * is migrated to the `feed_attributes` schema.
     *
     * Matches the `'13.4.6'` pattern used for `feed_filters`/`feed_rules`.
     *
     * DO NOT BUMP without a re-seed path. The legacy `attributes` meta is never
     * cleared after migration, so a bumped version re-enters
     * `maybe_run_migration()`, re-transforms the STALE legacy rows, and
     * overwrites every edit the user made through the new format. A bump must
     * first teach the migration to re-seed an existing `feed_attributes` value
     * in place instead of rebuilding it from `get_raw_attributes()`.
     *
     * @since 13.5.8
     */
    const VERSION = '13.5.8';

    /**
     * Valid `source` values for a top-level `feed_attributes` row.
     *
     * This class produces them, `Edit_Feed_Page::clean_feed_attributes_data()`
     * validates against them, and the `AttributeSource` union in
     * `src/apps/field-mapping-builder/types/index.ts` mirrors them. Keep the three
     * in step: a value added on one side only makes the save path discard the row
     * without an error.
     *
     * @since 13.5.8
     */
    const SOURCES = array( 'field', 'static', 'page_url', 'post_url' );

    /**
     * Valid `source` values for a sub-attribute.
     *
     * Mirrored by the `SubAttributeSource` union in
     * `src/apps/field-mapping-builder/types/index.ts`. `Sub_Attribute_Registry` and
     * `Sub_Attribute_Resolver` (#1036) must read these rather than restate them.
     *
     * `page_url` and `post_url` are the same two sources SOURCES offers, so the
     * field mapping UI can present one identical source picker for a top-level
     * row and for a sub-attribute. Their `source_value` is a page or post ID,
     * and `Sub_Attribute_Resolver` turns it into a permalink. Every value here
     * must have a branch there, or the row saves and then resolves to nothing.
     *
     * `zone` is not user-selectable: those rows come from the registry defaults
     * and their `source_value` is a literal `Shipping_Data` row key.
     *
     * @since 13.5.8
     */
    const SUB_SOURCES = array( 'zone', 'field', 'static', 'empty', 'page_url', 'post_url' );

    /**
     * The stale top-level tag that `migrate_loyalty_points_rows()` retires.
     *
     * Google has no top-level `loyalty_points` attribute — it is a sub-attribute of
     * `loyalty_program` — so a feed that maps this tag ships an element Merchant
     * Center does not recognise. See #1130.
     *
     * @since 13.5.8
     */
    const LOYALTY_POINTS_TAG = 'g:loyalty_points';

    /**
     * The nested parent that a retired `g:loyalty_points` mapping moves into.
     *
     * @since 13.5.8
     */
    const LOYALTY_PROGRAM_TAG = 'g:loyalty_program';

    /**
     * Clamp a registry-supplied sub-attribute `source` to `SUB_SOURCES`.
     *
     * The registry is filterable, so a third party can hand back any string. An
     * unknown source seeds a row that `Edit_Feed_Page::clean_feed_attributes_data()`
     * silently drops, so both consumers of the registry — the seeder below and the
     * REST reshaper in `Field_Mappings::get_sub_attribute_registry()` — clamp
     * through here. Keep the fallback in one place: a seeder and a payload that
     * disagree would hand the app a default it can never save.
     *
     * @since 13.5.8
     *
     * @param mixed $source The registry-supplied source value.
     * @return string A valid sub-attribute source, falling back to `'empty'`.
     */
    public static function normalize_sub_source( $source ): string {
        $source = is_string( $source ) ? $source : '';

        return in_array( $source, self::SUB_SOURCES, true ) ? $source : 'empty';
    }

    /**
     * Transform legacy attribute rows into the normalized `feed_attributes` schema.
     *
     * Legacy rows are shaped as rowCount/attribute/mapfrom/static_value/value/prefix/suffix.
     * New rows are shaped as feed_name/source/source_value/prefix/suffix/is_custom,
     * plus optional `zones_auto` and `sub_attributes` keys.
     *
     * The legacy schema overloads `mapfrom`: it holds the source field key normally,
     * but it holds the literal static text when `static_value` is `'true'`. The
     * `value` key only ever holds a page or post ID.
     *
     * `$channel_hash` is optional so the frozen one-argument call stays valid. It
     * decides `is_custom`, which needs the channel's own `feed_name` list, and it
     * gates `migrate_loyalty_points_rows()` and `seed_sub_attribute_defaults()`,
     * which both need the channel's registry entries. Without it every row keeps
     * `is_custom = false`, because the row cannot be classified, no sub-attributes
     * are seeded, and a stale `g:loyalty_points` row is left alone. Only a channel
     * that resolves to no attribute list at all marks every row custom, which is
     * what the legacy tab does at
     * `templates/edit-feed/tabs/field-mapping-tab.php:233-241`.
     *
     * A row with an empty `attribute` is kept, not dropped, so the reverse transform
     * reproduces the legacy array exactly. The save path drops such rows instead -
     * see `Edit_Feed_Page::clean_feed_attributes_data()`.
     *
     * @since 13.5.8
     *
     * @param array  $attributes   Legacy attribute rows.
     * @param string $channel_hash Optional. The feed's channel hash, used for `is_custom` detection.
     * @return array Normalized `feed_attributes` rows.
     */
    public static function transform_old_attributes_to_new( array $attributes, string $channel_hash = '' ): array {
        $channel_feed_names = '' !== $channel_hash ? self::get_channel_feed_names( $channel_hash ) : null;

        // Flip once: the biggest channel list holds 194 entries, and this runs per row.
        $channel_feed_name_keys = is_array( $channel_feed_names ) ? array_flip( $channel_feed_names ) : null;

        $feed_attributes = array();

        foreach ( $attributes as $attribute ) {
            if ( ! is_array( $attribute ) ) {
                continue;
            }

            $feed_name = isset( $attribute['attribute'] ) ? (string) $attribute['attribute'] : '';
            $mapfrom   = isset( $attribute['mapfrom'] ) ? (string) $attribute['mapfrom'] : '';

            if ( isset( $attribute['static_value'] ) && 'true' === $attribute['static_value'] ) {
                // Static text lives in `mapfrom`, never in `value`.
                $source       = 'static';
                $source_value = $mapfrom;
            } elseif ( 'page_url' === $mapfrom || 'post_url' === $mapfrom ) {
                // Only these two sources use `value`, and it holds a page or post ID.
                $source       = $mapfrom;
                $source_value = isset( $attribute['value'] ) ? (string) $attribute['value'] : '';
            } else {
                $source       = 'field';
                $source_value = $mapfrom;
            }

            $feed_attributes[] = array(
                'feed_name'    => $feed_name,
                'source'       => $source,
                'source_value' => $source_value,
                'prefix'       => isset( $attribute['prefix'] ) ? (string) $attribute['prefix'] : '',
                'suffix'       => isset( $attribute['suffix'] ) ? (string) $attribute['suffix'] : '',
                'is_custom'    => is_array( $channel_feed_name_keys ) && ! isset( $channel_feed_name_keys[ $feed_name ] ),
            );
        }

        if ( '' !== $channel_hash ) {
            $feed_attributes = self::migrate_loyalty_points_rows( $feed_attributes, $channel_hash, $channel_feed_names );
            $feed_attributes = self::seed_sub_attribute_defaults( $feed_attributes, $channel_hash );
        }

        return $feed_attributes;
    }

    /**
     * Reverse transform: feed_attributes rows → legacy attribute rows.
     *
     * Lossless for legacy-representable configs, so
     * `to_legacy( transform_old_attributes_to_new( $rows ) ) == $rows`. One row breaks
     * that identity on purpose: `migrate_loyalty_points_rows()` renames or drops a
     * `g:loyalty_points` row, because Google has no such top-level attribute (#1130).
     *
     * Two keys are dropped on purpose. `is_custom` is derived from the channel, and
     * the legacy tab recomputes it. NESTED `sub_attributes` have no legacy
     * representation: legacy consumers only read top-level rows, and `g:shipping` is
     * driven by `Shipping_Data` on both paths.
     *
     * A SCALAR REPEAT does have one, and it is emitted. A repeat is a duplicated
     * mapping row (#1133) — that is how a store expressed one before the repeat UI
     * existed — so `Repeatable_Attributes::expand_rows()` turns each one back into a
     * row here. This is the single point where repeats reach the generation chain,
     * and it is why every file format sees them: the CSV/TXT row builder, the
     * XML/JSONL payload builder and the CSV header all read this list.
     *
     * Each format then writes the repeat in its own shape, and only the writers know
     * how. CSV/TXT get one column per value, read positionally. XML gets sibling
     * elements sharing one name, through `Repeatable_Attributes::strip_repeat_suffix()`.
     * JSON has unique keys and so cannot do either: `collapse_repeats_to_array()`
     * turns the occurrences into one array under the base tag.
     *
     * The output is canonical, not a byte copy of what was stored. It emits exactly
     * the seven legacy keys listed below, and it re-indexes `rowCount` from zero. A
     * stored legacy row that historically carried an extra key, or a non-contiguous
     * `rowCount`, is therefore not reproduced key for key. No core consumer reads a
     * dropped key, so generation is unaffected. Third-party code that reads a custom
     * key off `$feed->attributes` must move that read to `feed_attributes`.
     *
     * @since 13.5.8
     *
     * @param array  $feed_attributes New-format rows.
     * @param string $channel_hash    Optional. The feed's channel hash. Without it no repeat is
     *                                expanded, because the channel is what declares an attribute
     *                                repeatable — pass it wherever generation reads.
     * @return array Legacy-shaped rows (rowCount, attribute, prefix, mapfrom, static_value, value, suffix).
     */
    public static function to_legacy( array $feed_attributes, string $channel_hash = '' ): array {
        if ( '' !== $channel_hash ) {
            $feed_attributes = Repeatable_Attributes::expand_rows(
                $feed_attributes,
                Repeatable_Attributes::get_limits( $channel_hash )
            );
        }

        $legacy = array();

        foreach ( $feed_attributes as $attribute ) {
            if ( ! is_array( $attribute ) ) {
                continue;
            }

            $source       = isset( $attribute['source'] ) ? (string) $attribute['source'] : 'field';
            $source_value = isset( $attribute['source_value'] ) ? (string) $attribute['source_value'] : '';

            // Key order and value types mirror the legacy form POST: every legacy
            // row reached `$data['attributes']` through `$_POST`, so `rowCount` is a
            // numeric string, and the keys run rowCount, attribute, prefix, mapfrom,
            // [static_value], [value], suffix.
            $row = array(
                'rowCount'  => (string) count( $legacy ),
                'attribute' => isset( $attribute['feed_name'] ) ? (string) $attribute['feed_name'] : '',
                'prefix'    => isset( $attribute['prefix'] ) ? (string) $attribute['prefix'] : '',
            );

            if ( 'static' === $source ) {
                $row['mapfrom']      = $source_value;
                $row['static_value'] = 'true';
            } elseif ( 'page_url' === $source || 'post_url' === $source ) {
                $row['mapfrom'] = $source;
                $row['value']   = $source_value;
            } else {
                $row['mapfrom'] = $source_value;
            }

            $row['suffix'] = isset( $attribute['suffix'] ) ? (string) $attribute['suffix'] : '';

            $legacy[] = $row;
        }

        return $legacy;
    }

    /**
     * Migrate a feed's legacy `attributes` to `feed_attributes` if it hasn't been migrated yet.
     *
     * Runs the transform, sets the `feed_attributes` data key, and stamps
     * `data_version['feed_attributes']` with self::VERSION. Does not persist —
     * saving is the caller's responsibility.
     *
     * DO NOT DRIVE THE SAVE OFF THE RETURN VALUE. It answers "was legacy data
     * converted", and it is `false` for two states with opposite save obligations:
     * a feed that was already migrated (nothing to persist) and a feed that was just
     * stamped but had no legacy rows to convert (the stamp is in memory only).
     * `if ( $migrated ) { $feed->save(); }` therefore loses the stamp for every feed
     * with no legacy rows, and the migration re-runs on every read.
     *
     * Use `$needs_save`, or just save unconditionally:
     *
     *     Feed_Attributes_Migrator::maybe_run_migration( $feed, $needs_save );
     *     if ( $needs_save ) {
     *         $feed->save();
     *     }
     *
     * Note this is the opposite of the sibling it mirrors,
     * `REST\Filters_Rules::migrate_filters()`, which saves the feed itself. The read
     * trigger in `REST\Field_Mappings` (#1035) owns the save here.
     *
     * @since 13.5.8
     *
     * @param Product_Feed $feed       The product feed instance.
     * @param bool         $needs_save Output. Set to true when the caller must persist the feed.
     * @return bool True if legacy data was converted, false if already migrated or nothing to convert.
     */
    public static function maybe_run_migration( Product_Feed $feed, bool &$needs_save = false ): bool {
        $needs_save = false;

        $data_version = $feed->data_version;
        if ( ! is_array( $data_version ) ) {
            $data_version = array();
        }

        if ( ! empty( $data_version['feed_attributes'] )
            && version_compare( $data_version['feed_attributes'], self::VERSION, '>=' ) ) {
            return false;
        }

        // Stamp first. A guard clause is not enough to make this idempotent.
        $feed->set_data_version( 'feed_attributes', self::VERSION );
        $needs_save = true;

        // Read the raw legacy rows. The `attributes` accessor resolves migrated
        // feeds through to_legacy(), so it must not be used here.
        $legacy_attributes = $feed->get_raw_attributes();
        if ( empty( $legacy_attributes ) || ! is_array( $legacy_attributes ) ) {
            return false;
        }

        $feed->feed_attributes = self::transform_old_attributes_to_new( $legacy_attributes, (string) $feed->channel_hash );

        return true;
    }

    /**
     * Get every `feed_name` the given channel defines.
     *
     * Mirrors how the legacy field mapping tab builds its channel attribute list:
     * resolve the channel from its hash, then read the list from the channel class.
     * The class loading and its path-traversal guard live in
     * `Product_Feed_Helper::get_channel_attribute_catalog()`, shared with the REST
     * payload so the guard has one owner.
     *
     * @since 13.5.8
     *
     * @param string $channel_hash The feed's channel hash.
     * @return array|null The channel's feed names, or null when the channel cannot be resolved.
     */
    private static function get_channel_feed_names( string $channel_hash ): ?array {
        $channel = Product_Feed_Helper::get_channel_from_legacy_channel_hash( $channel_hash );
        if ( ! is_array( $channel ) ) {
            return null;
        }

        $fields = isset( $channel['fields'] ) ? (string) $channel['fields'] : '';

        /*
         * Do not special-case `customfeed`. It has a real channel class with 44
         * attributes, and it is the `fields` value for 135 of the 237 channels in
         * `includes/I18n/legacy_channel_statics.php`. Treating it as "no list" would
         * mark every migrated row custom on the majority of channels. Only a channel
         * with no resolvable `fields` value has no attribute list.
         */
        if ( '' === $fields ) {
            return array();
        }

        $feed_names = array();
        foreach ( Product_Feed_Helper::get_channel_attribute_catalog( $fields ) as $group ) {
            if ( ! is_array( $group ) ) {
                continue;
            }
            foreach ( $group as $channel_attribute ) {
                if ( isset( $channel_attribute['feed_name'] ) ) {
                    $feed_names[] = (string) $channel_attribute['feed_name'];
                }
            }
        }

        return $feed_names;
    }

    /**
     * Seed registry-default sub-attributes onto nested parent attributes.
     *
     * Only zone-driven parents (`Sub_Attribute_Registry::uses_zones()`, i.e.
     * `g:shipping`) are seeded. Their template group resolves against the same
     * `Shipping_Data` rows the legacy marker string is built from, so the seeded
     * output is byte-identical to the legacy output. The marker-driven registry
     * parents (`g:installment`, `g:product_detail`, `g:consumer_notice`,
     * `g:subscription_cost`, `free_shipping_threshold`) build their legacy output
     * from per-product data that a seeded template cannot reproduce — and
     * `Sub_Attribute_Resolver::apply_to_payload()` REPLACES the marker payload
     * whenever `sub_attributes` is non-empty, so seeding them would silently drop
     * that output. They migrate without `sub_attributes` and keep the
     * marker-string path; the UI offers their registry defaults on demand
     * instead, from the `sub_attribute_registry` key of the field-mappings
     * payload. `g:tax` and `g:certification` have no legacy hardcoded branch at
     * all, so migration has nothing to reproduce for them either — the UI seeds
     * both on demand.
     *
     * The parent row's `prefix`/`suffix` are copied onto the `price` zone row,
     * because the legacy shipping arm applies them to the price piece of the
     * marker string and the resolver only reads affixes off the sub-attribute.
     *
     * A row that already has `sub_attributes` is left alone, so re-running the
     * transform (e.g. a legacy-form save on a migrated feed) cannot double-seed.
     *
     * The empty-`$channel_hash` guard below is unreachable from the only live
     * caller (`transform_old_attributes_to_new()` already gates on it). It is kept
     * on purpose: this method is public static, and without a channel there is no
     * registry to read, so a future direct caller must get a no-op rather than a
     * lookup on an empty hash.
     *
     * @since 13.5.8
     *
     * @param array  $feed_attributes Normalized `feed_attributes` rows.
     * @param string $channel_hash    The feed's channel hash.
     * @return array The `feed_attributes` rows with defaults seeded.
     */
    public static function seed_sub_attribute_defaults( array $feed_attributes, string $channel_hash ): array {
        if ( '' === $channel_hash ) {
            return $feed_attributes;
        }

        foreach ( $feed_attributes as &$attribute ) {
            if ( ! is_array( $attribute ) ) {
                continue;
            }

            $feed_name = isset( $attribute['feed_name'] ) ? (string) $attribute['feed_name'] : '';

            if ( '' === $feed_name || ! Sub_Attribute_Registry::uses_zones( $feed_name ) ) {
                continue;
            }

            if ( ! empty( $attribute['sub_attributes'] ) ) {
                continue;
            }

            $defaults = Sub_Attribute_Registry::get_defaults( $channel_hash, $feed_name );
            if ( empty( $defaults ) ) {
                continue;
            }

            $group = array();
            foreach ( $defaults as $definition ) {
                if ( ! is_array( $definition ) || empty( $definition['tag'] ) ) {
                    continue;
                }

                $source = self::normalize_sub_source( isset( $definition['default_source'] ) ? $definition['default_source'] : '' );

                $zone_key = 'zone' === $source && isset( $definition['zone_key'] ) ? (string) $definition['zone_key'] : '';

                // Legacy applies the parent row's affixes to the price piece only.
                $is_price_row = 'price' === $zone_key;

                $group[] = array(
                    'tag'          => (string) $definition['tag'],
                    'source'       => $source,
                    'source_value' => $zone_key,
                    'prefix'       => $is_price_row && isset( $attribute['prefix'] ) ? (string) $attribute['prefix'] : '',
                    'suffix'       => $is_price_row && isset( $attribute['suffix'] ) ? (string) $attribute['suffix'] : '',
                );
            }

            if ( empty( $group ) ) {
                continue;
            }

            $attribute['zones_auto']     = true;
            $attribute['sub_attributes'] = array( $group );
        }
        unset( $attribute );

        return $feed_attributes;
    }

    /**
     * Retire a top-level `g:loyalty_points` mapping (#1130).
     *
     * Google has no top-level `loyalty_points` attribute. It is a sub-attribute of
     * `loyalty_program`, so every feed that mapped the old tag shipped an element
     * Merchant Center does not recognise, and the points it meant to send reached no
     * program. Removing the attribute from a channel class is not enough on its own:
     * a stored row keys on its output tag and generation writes every stored row, so
     * the row would simply become a custom attribute and keep emitting the bad tag.
     *
     * A channel that has `g:loyalty_program` defaults in `Sub_Attribute_Registry`
     * keeps the mapping: the row is renamed to the nested parent, and the old
     * source, prefix and suffix move onto its `g:loyalty_points` sub-attribute. The
     * parent's own mapping is cleared, because with `sub_attributes` present the
     * groups ARE the value — this is the shape the `g:certification` example in the
     * sub-attributes brief uses.
     *
     * The migrated row carries the channel's whole `g:loyalty_program` template, not
     * just the one mapped tag, so the required but unmappable `g:program_label` is
     * visible and empty in the Field Mapping tab — see
     * `build_migrated_loyalty_group()`. The seeded tags change no output.
     *
     * A channel with no such defaults has nowhere to put the mapping. Pinterest and
     * TikTok list neither `loyalty_program` nor `loyalty_points` in their catalog
     * specifications, so their row is dropped rather than renamed to an attribute
     * their channel does not define.
     *
     * The registry, not the channel catalog, is the test. Nesting is what makes the
     * migrated row valid, and a third party that adds a `g:loyalty_program` slice
     * for its own channel through `adt_sub_attribute_registry` therefore gets the
     * migration too.
     *
     * Idempotent: a migrated row is named `g:loyalty_program`, so a second pass
     * finds no `g:loyalty_points` row to act on.
     *
     * @since 13.5.8
     *
     * @param array      $feed_attributes    Normalized `feed_attributes` rows.
     * @param string     $channel_hash       The feed's channel hash.
     * @param array|null $channel_feed_names The channel's feed names, already resolved by the
     *                                       caller, or null when the hash resolves to no channel.
     * @return array The rows, with any `g:loyalty_points` row migrated or dropped.
     */
    private static function migrate_loyalty_points_rows( array $feed_attributes, string $channel_hash, ?array $channel_feed_names ): array {
        // A hash that resolves to no channel cannot be classified, so neither
        // outcome below is safe. Leave every row exactly as it is.
        if ( ! is_array( $channel_feed_names ) ) {
            return $feed_attributes;
        }

        $defaults = Sub_Attribute_Registry::get_defaults( $channel_hash, self::LOYALTY_PROGRAM_TAG );

        // Classify the renamed row the same way the transform loop classifies every
        // other row. A channel that gets its defaults through the registry filter but
        // does not list the parent in its own attribute list keeps `is_custom = true`.
        $is_custom = ! in_array( self::LOYALTY_PROGRAM_TAG, $channel_feed_names, true );

        $migrated = array();

        foreach ( $feed_attributes as $attribute ) {
            if ( ! is_array( $attribute )
                || ! isset( $attribute['feed_name'] )
                || self::LOYALTY_POINTS_TAG !== (string) $attribute['feed_name'] ) {
                $migrated[] = $attribute;
                continue;
            }

            if ( empty( $defaults ) ) {
                continue;
            }

            $migrated[] = array(
                'feed_name'      => self::LOYALTY_PROGRAM_TAG,
                'source'         => 'static',
                'source_value'   => '',
                'prefix'         => '',
                'suffix'         => '',
                'is_custom'      => $is_custom,
                'sub_attributes' => array( self::build_migrated_loyalty_group( $defaults, $attribute ) ),
            );
        }

        return $migrated;
    }

    /**
     * Build the sub-attribute group a migrated `g:loyalty_points` row carries.
     *
     * The group is the channel's whole `g:loyalty_program` template, not just the
     * one tag the store had mapped. Google makes `program_label` required — it is
     * the key that ties the block to a program configured in Merchant Center — and
     * a migrated row that holds only `g:loyalty_points` gives the merchant no place
     * in the Field Mapping tab to supply it. Seeding the template puts every
     * required tag in front of them, empty and ready to fill.
     *
     * The generated feed does not change. `Sub_Attribute_Resolver::to_objects()`
     * drops any child whose resolved value is empty, and the writer omits a parent
     * whose children all resolve empty, so the seeded tags stay invisible until the
     * merchant maps them.
     *
     * The store's own source, prefix and suffix land on the `g:loyalty_points` tag.
     * Every other tag is seeded from its registry `default_source` with no value.
     * A registry slice supplied through `adt_sub_attribute_registry` that omits
     * `g:loyalty_points` still keeps the mapping: the tag is appended, so the
     * migration never drops what the store had configured.
     *
     * @since 13.5.8
     *
     * @param array $defaults  The channel's `g:loyalty_program` registry definitions.
     * @param array $attribute The legacy `g:loyalty_points` row being migrated.
     * @return array The sub-attribute rows for the migrated parent.
     */
    private static function build_migrated_loyalty_group( array $defaults, array $attribute ): array {
        $points = array(
            'tag'          => self::LOYALTY_POINTS_TAG,
            'source'       => isset( $attribute['source'] ) ? (string) $attribute['source'] : 'field',
            'source_value' => isset( $attribute['source_value'] ) ? (string) $attribute['source_value'] : '',
            'prefix'       => isset( $attribute['prefix'] ) ? (string) $attribute['prefix'] : '',
            'suffix'       => isset( $attribute['suffix'] ) ? (string) $attribute['suffix'] : '',
        );

        $group        = array();
        $points_added = false;

        foreach ( $defaults as $definition ) {
            if ( ! is_array( $definition ) || empty( $definition['tag'] ) ) {
                continue;
            }

            $tag = (string) $definition['tag'];

            if ( self::LOYALTY_POINTS_TAG === $tag ) {
                $group[]      = $points;
                $points_added = true;
                continue;
            }

            $group[] = array(
                'tag'          => $tag,
                'source'       => self::normalize_sub_source( isset( $definition['default_source'] ) ? $definition['default_source'] : '' ),
                'source_value' => '',
                'prefix'       => '',
                'suffix'       => '',
            );
        }

        if ( ! $points_added ) {
            $group[] = $points;
        }

        return $group;
    }
}
