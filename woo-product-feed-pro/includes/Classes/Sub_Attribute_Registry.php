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
 * Sub_Attribute_Registry class.
 *
 * Holds the built-in default sub-attribute definitions per channel and parent
 * tag (e.g. Google Shopping `g:shipping` → country/service/price/…), exposed
 * for third-party extension via the `adt_sub_attribute_registry` filter.
 *
 * @since 13.5.8
 */
class Sub_Attribute_Registry {

    /**
     * The only parent tag whose sub-attributes expand once per shipping zone.
     *
     * @since 13.5.8
     */
    const ZONE_PARENT_TAG = 'g:shipping';

    /**
     * Resolved channel key per channel hash, for the life of the request.
     *
     * `get_defaults()` runs once per nested parent tag per feed read, and each
     * miss walks the 237-entry legacy channel list.
     *
     * @since 13.5.8
     *
     * @var array
     */
    private static $channel_key_cache = array();

    /**
     * The filtered registry, for the life of the request.
     *
     * `get_built_in_registry()` allocates every definition row and runs a `__()`
     * lookup per label, and `get_registry()` fires `adt_sub_attribute_registry` on
     * top. Once `REST\Field_Mappings` (#1035) reads the defaults per nested parent
     * tag, a feed with several nested parents would rebuild the whole registry once
     * per parent.
     *
     * A callback added to `adt_sub_attribute_registry` after the first call in the
     * same request therefore does not apply. Register the filter on plugin load, as
     * the other registry filters in this plugin are registered.
     *
     * @since 13.5.8
     *
     * @var array|null
     */
    private static $registry_cache = null;

    /**
     * Get the default sub-attribute definitions for a channel and parent tag.
     *
     * @since 13.5.8
     *
     * @param string $channel_hash The feed's channel hash.
     * @param string $parent_tag   The parent attribute output tag (e.g. `g:shipping`).
     * @return array Default sub-attribute definitions (tag, label, default_source), or an empty array if none.
     */
    public static function get_defaults( string $channel_hash, string $parent_tag ): array {
        $registry    = self::get_registry();
        $channel_key = self::get_channel_key( $channel_hash );

        if ( '' === $channel_key || ! isset( $registry[ $channel_key ][ $parent_tag ] ) ) {
            return array();
        }

        return (array) $registry[ $channel_key ][ $parent_tag ];
    }

    /**
     * Get the whole registry, keyed by channel key and then by parent tag.
     *
     * `REST\Field_Mappings` (#1035) ships this in the initial field-mappings
     * payload, so the field mapping UI needs no per-change request to look up
     * the defaults of a nested parent tag.
     *
     * @since 13.5.8
     *
     * @return array The filtered registry.
     */
    public static function get_registry(): array {
        if ( null !== self::$registry_cache ) {
            return self::$registry_cache;
        }

        /**
         * Filter the sub-attribute registry.
         *
         * The registry is keyed by channel key, then by parent output tag. The
         * channel key is the channel's `fields` value in
         * `includes/I18n/legacy_channel_statics.php` — the same value that names
         * the channel class, e.g. `google_shopping`, `facebook_drm`.
         *
         * Each entry is a list of definitions shaped as:
         * `array( 'tag' => string, 'label' => string, 'default_source' => string, 'zone_key' => string )`.
         * `zone_key` is only present when `default_source` is `zone`, and it holds
         * the literal `Shipping_Data` row key. Never derive it from the tag name.
         *
         * `default_source` takes one of four values:
         * - `zone`   Resolved from the `Shipping_Data` row key named by `zone_key`.
         * - `static` The literal `source_value` configured on the sub-attribute.
         * - `field`  The `$product_data` entry named by `source_value`.
         * - `empty`  Resolves to an empty string, and the writer omits the child.
         *   Any other value resolves the same way as `empty`.
         *
         * @since 13.5.8
         *
         * @param array $registry The built-in registry.
         * @return array The registry.
         */
        self::$registry_cache = (array) apply_filters( 'adt_sub_attribute_registry', self::get_built_in_registry() );

        return self::$registry_cache;
    }

    /**
     * Whether a parent tag's sub-attributes auto-resolve from WooCommerce shipping zones.
     *
     * @since 13.5.8
     *
     * @param string $parent_tag The parent attribute output tag.
     * @return bool True if the parent tag uses shipping-zone auto-resolution.
     */
    public static function uses_zones( string $parent_tag ): bool {
        return self::ZONE_PARENT_TAG === $parent_tag;
    }

    /**
     * Resolve a channel hash to the key the registry uses.
     *
     * The registry is keyed by the channel's `fields` value, not by its hash and
     * not by its `taxonomy`. Version 1 therefore ships defaults for the six
     * channels whose `fields` value is `google_shopping` (Google Shopping, Bing
     * Shopping, Twitter and the three Prisjakt channels). Every other channel —
     * including the Google-chain channels `facebook_drm`, `pinterest`,
     * `snapchat` and `tiktok` — gets its entries through the
     * `adt_sub_attribute_registry` filter.
     *
     * @since 13.5.8
     *
     * @param string $channel_hash The feed's channel hash.
     * @return string The channel key, or an empty string when the hash resolves to no channel.
     */
    private static function get_channel_key( string $channel_hash ): string {
        if ( isset( self::$channel_key_cache[ $channel_hash ] ) ) {
            return self::$channel_key_cache[ $channel_hash ];
        }

        $channel = Product_Feed_Helper::get_channel_from_legacy_channel_hash( $channel_hash );

        self::$channel_key_cache[ $channel_hash ] = is_array( $channel ) && isset( $channel['fields'] )
            ? (string) $channel['fields']
            : '';

        return self::$channel_key_cache[ $channel_hash ];
    }

    /**
     * The built-in sub-attribute definitions.
     *
     * Every tag below is a Google Merchant nested sub-attribute. `free_shipping_threshold`
     * keeps its unprefixed parent key, because that is the key the channel class
     * and the legacy writer use.
     *
     * The four `g:shipping` transit and handling tags are `zone`-sourced (#1038).
     * The legacy marker builder sources them from the shipping-zone row
     * (`classes/class-get-products.php`, the shipping arm), and `Shipping_Data`
     * populates `min_transit_time`/`max_transit_time` on its rows from the
     * flat-rate settings or the feed's static configuration. A `static` default
     * with no value would drop those tags from a migrated `g:shipping`, so the
     * zone source is what keeps the migrated output identical to the legacy
     * output. A key absent from a zone row resolves to an empty string and the
     * writer omits the child, which is also what the legacy path does.
     *
     * `min_handling_time`/`max_handling_time` are `zone`-sourced for the same
     * reason, even though `Shipping_Data` writes neither key today, so both tags
     * currently resolve to empty and are omitted. This mirrors legacy exactly: the
     * legacy marker builder reads the same two row keys and also emits nothing
     * when they are absent. Both keys are reachable through
     * `adt_product_feed_shipping_array` and `adt_product_feed_shipping_data`, so a
     * site that fills them keeps identical output on both paths — making these two
     * `static` would break that parity. Do not "fix" them to `static` without
     * first checking those filters.
     *
     * `g:loyalty_program` (#1130) splits its sources by what the value describes.
     * `program_label`, `tier_label`, `member_price_effective_date` and
     * `shipping_label` describe the program, so they take one configured value per
     * feed and default to `static`. `price`, `cashback_for_future_use` and
     * `loyalty_points` describe the product, so they default to `field`. The tag
     * list and the nesting come from the Google loyalty program specification:
     * https://support.google.com/merchants/answer/12922446
     *
     * @since 13.5.8
     *
     * @return array The built-in registry.
     */
    private static function get_built_in_registry(): array {
        return array(
            'google_shopping' => array(
                'g:shipping'              => array(
                    array(
                        'tag'            => 'g:country',
                        'label'          => __( 'Country', 'woo-product-feed-pro' ),
                        'default_source' => 'zone',
                        'zone_key'       => 'country',
                    ),
                    array(
                        'tag'            => 'g:region',
                        'label'          => __( 'Region', 'woo-product-feed-pro' ),
                        'default_source' => 'zone',
                        'zone_key'       => 'region',
                    ),
                    array(
                        'tag'            => 'g:postal_code',
                        'label'          => __( 'Postal Code', 'woo-product-feed-pro' ),
                        'default_source' => 'zone',
                        'zone_key'       => 'postal_code',
                    ),
                    array(
                        'tag'            => 'g:service',
                        'label'          => __( 'Service', 'woo-product-feed-pro' ),
                        'default_source' => 'zone',
                        'zone_key'       => 'service',
                    ),
                    array(
                        'tag'            => 'g:price',
                        'label'          => __( 'Price', 'woo-product-feed-pro' ),
                        'default_source' => 'zone',
                        'zone_key'       => 'price',
                    ),
                    array(
                        'tag'            => 'g:min_transit_time',
                        'label'          => __( 'Min Transit Time', 'woo-product-feed-pro' ),
                        'default_source' => 'zone',
                        'zone_key'       => 'min_transit_time',
                    ),
                    array(
                        'tag'            => 'g:max_transit_time',
                        'label'          => __( 'Max Transit Time', 'woo-product-feed-pro' ),
                        'default_source' => 'zone',
                        'zone_key'       => 'max_transit_time',
                    ),
                    array(
                        'tag'            => 'g:min_handling_time',
                        'label'          => __( 'Min Handling Time', 'woo-product-feed-pro' ),
                        'default_source' => 'zone',
                        'zone_key'       => 'min_handling_time',
                    ),
                    array(
                        'tag'            => 'g:max_handling_time',
                        'label'          => __( 'Max Handling Time', 'woo-product-feed-pro' ),
                        'default_source' => 'zone',
                        'zone_key'       => 'max_handling_time',
                    ),
                ),
                'g:tax'                   => array(
                    array(
                        'tag'            => 'g:country',
                        'label'          => __( 'Country', 'woo-product-feed-pro' ),
                        'default_source' => 'static',
                    ),
                    array(
                        'tag'            => 'g:region',
                        'label'          => __( 'Region', 'woo-product-feed-pro' ),
                        'default_source' => 'static',
                    ),
                    array(
                        'tag'            => 'g:rate',
                        'label'          => __( 'Rate', 'woo-product-feed-pro' ),
                        'default_source' => 'static',
                    ),
                    array(
                        'tag'            => 'g:tax_ship',
                        'label'          => __( 'Tax Ship', 'woo-product-feed-pro' ),
                        'default_source' => 'static',
                    ),
                ),
                'g:installment'           => array(
                    array(
                        'tag'            => 'g:months',
                        'label'          => __( 'Months', 'woo-product-feed-pro' ),
                        'default_source' => 'static',
                    ),
                    array(
                        'tag'            => 'g:amount',
                        'label'          => __( 'Amount', 'woo-product-feed-pro' ),
                        'default_source' => 'static',
                    ),
                ),
                'g:subscription_cost'     => array(
                    array(
                        'tag'            => 'g:period',
                        'label'          => __( 'Period', 'woo-product-feed-pro' ),
                        'default_source' => 'static',
                    ),
                    array(
                        'tag'            => 'g:period_length',
                        'label'          => __( 'Period Length', 'woo-product-feed-pro' ),
                        'default_source' => 'static',
                    ),
                    array(
                        'tag'            => 'g:amount',
                        'label'          => __( 'Amount', 'woo-product-feed-pro' ),
                        'default_source' => 'static',
                    ),
                ),
                'g:loyalty_program'       => array(
                    array(
                        'tag'            => 'g:program_label',
                        'label'          => __( 'Program Label', 'woo-product-feed-pro' ),
                        'default_source' => 'static',
                    ),
                    array(
                        'tag'            => 'g:tier_label',
                        'label'          => __( 'Tier Label', 'woo-product-feed-pro' ),
                        'default_source' => 'static',
                    ),
                    array(
                        'tag'            => 'g:price',
                        'label'          => __( 'Member Price', 'woo-product-feed-pro' ),
                        'default_source' => 'field',
                    ),
                    array(
                        'tag'            => 'g:cashback_for_future_use',
                        'label'          => __( 'Cashback For Future Use', 'woo-product-feed-pro' ),
                        'default_source' => 'field',
                    ),
                    array(
                        'tag'            => 'g:loyalty_points',
                        'label'          => __( 'Loyalty Points', 'woo-product-feed-pro' ),
                        'default_source' => 'field',
                    ),
                    array(
                        'tag'            => 'g:member_price_effective_date',
                        'label'          => __( 'Member Price Effective Date', 'woo-product-feed-pro' ),
                        'default_source' => 'static',
                    ),
                    array(
                        'tag'            => 'g:shipping_label',
                        'label'          => __( 'Shipping Label', 'woo-product-feed-pro' ),
                        'default_source' => 'static',
                    ),
                ),
                'g:product_detail'        => array(
                    array(
                        'tag'            => 'g:section_name',
                        'label'          => __( 'Section Name', 'woo-product-feed-pro' ),
                        'default_source' => 'static',
                    ),
                    array(
                        'tag'            => 'g:attribute_name',
                        'label'          => __( 'Attribute Name', 'woo-product-feed-pro' ),
                        'default_source' => 'field',
                    ),
                    array(
                        'tag'            => 'g:attribute_value',
                        'label'          => __( 'Attribute Value', 'woo-product-feed-pro' ),
                        'default_source' => 'field',
                    ),
                ),
                'g:certification'         => array(
                    array(
                        'tag'            => 'g:certification_authority',
                        'label'          => __( 'Authority', 'woo-product-feed-pro' ),
                        'default_source' => 'static',
                    ),
                    array(
                        'tag'            => 'g:certification_name',
                        'label'          => __( 'Name', 'woo-product-feed-pro' ),
                        'default_source' => 'static',
                    ),
                    array(
                        'tag'            => 'g:certification_code',
                        'label'          => __( 'Code', 'woo-product-feed-pro' ),
                        'default_source' => 'static',
                    ),
                ),
                'free_shipping_threshold' => array(
                    array(
                        'tag'            => 'g:country',
                        'label'          => __( 'Country', 'woo-product-feed-pro' ),
                        'default_source' => 'static',
                    ),
                    array(
                        'tag'            => 'g:price_threshold',
                        'label'          => __( 'Price Threshold', 'woo-product-feed-pro' ),
                        'default_source' => 'static',
                    ),
                ),
                'g:consumer_notice'       => array(
                    array(
                        'tag'            => 'g:notice_type',
                        'label'          => __( 'Notice Type', 'woo-product-feed-pro' ),
                        'default_source' => 'static',
                    ),
                    array(
                        'tag'            => 'g:notice_message',
                        'label'          => __( 'Notice Message', 'woo-product-feed-pro' ),
                        'default_source' => 'field',
                    ),
                ),
            ),
        );
    }
}
