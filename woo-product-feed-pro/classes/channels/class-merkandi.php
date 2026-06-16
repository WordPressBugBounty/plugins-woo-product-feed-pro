<?php
/**
 * Settings for Merkandi product feeds.
 *
 * Generates a Merkandi-compliant XML feed (root <products>, one <product> per
 * offer) for import via the Merkandi portal (https://merkandi.com/import-xml).
 *
 * Several fields (category_id, country_id, ware_id, unit_id, price_unit_id) are
 * Merkandi-specific integer IDs that have no WooCommerce equivalent. Merchants
 * map these to static values taken from Merkandi's reference CSV lists
 * (categories, countries, measure units, product grades). The <name> and
 * <description> values are wrapped in CDATA, <photos> renders one <photoUrl>
 * child per image (capped at 7), and tiered <pricing> can be supplied through
 * the `adt_merkandi_pricing_tiers` filter (e.g. by a wholesale pricing add-on).
 *
 * @since 13.6.0
 * @package AdTribes\PFP
 */
class WooSEA_merkandi { // phpcs:ignore

    /**
     * Get the channel attributes.
     *
     * @since 13.6.0
     * @return array
     */
    public static function get_channel_attributes() {

        $merkandi = array(
            'Feed fields' => array(
                // Required fields.
                'SKU'                   => array(
                    'name'        => 'SKU',
                    'feed_name'   => 'sku',
                    'format'      => 'required',
                    'woo_suggest' => 'sku',
                ),
                'Category ID'           => array(
                    'name'      => 'Category ID',
                    'feed_name' => 'category_id',
                    'format'    => 'required',
                ),
                'Locale'                => array(
                    'name'      => 'Locale',
                    'feed_name' => 'locale',
                    'format'    => 'required',
                ),
                'Name'                  => array(
                    'name'        => 'Name',
                    'feed_name'   => 'name',
                    'format'      => 'required',
                    'woo_suggest' => 'title',
                ),
                'Description'           => array(
                    'name'        => 'Description',
                    'feed_name'   => 'description',
                    'format'      => 'required',
                    'woo_suggest' => 'description',
                ),
                'Minimum order type'    => array(
                    'name'      => 'Minimum order type',
                    'feed_name' => 'min_order_type',
                    'format'    => 'required',
                ),
                'Minimum order'         => array(
                    'name'      => 'Minimum order',
                    'feed_name' => 'min_order',
                    'format'    => 'required',
                ),
                'Photos'                => array(
                    'name'        => 'Photos',
                    'feed_name'   => 'photos',
                    'format'      => 'required',
                    'woo_suggest' => 'all_images',
                ),
                'Country ID'            => array(
                    'name'      => 'Country ID',
                    'feed_name' => 'country_id',
                    'format'    => 'required',
                ),
                'Grade (ware ID)'       => array(
                    'name'      => 'Grade (ware ID)',
                    'feed_name' => 'ware_id',
                    'format'    => 'required',
                ),

                // Optional fields.
                'Merkandi ID'           => array(
                    'name'      => 'Merkandi ID',
                    'feed_name' => 'id',
                    'format'    => 'optional',
                ),
                'Price'                 => array(
                    'name'        => 'Price',
                    'feed_name'   => 'price',
                    'format'      => 'optional',
                    'woo_suggest' => 'price',
                ),
                'Retail price'          => array(
                    'name'      => 'Retail price',
                    'feed_name' => 'price_retail',
                    'format'    => 'optional',
                ),
                'Currency'              => array(
                    'name'      => 'Currency',
                    'feed_name' => 'currency',
                    'format'    => 'optional',
                ),
                'Measure unit'          => array(
                    'name'      => 'Measure unit',
                    'feed_name' => 'unit_id',
                    'format'    => 'optional',
                ),
                'Price measure unit'    => array(
                    'name'      => 'Price measure unit',
                    'feed_name' => 'price_unit_id',
                    'format'    => 'optional',
                ),
                'Quantity'              => array(
                    'name'      => 'Quantity',
                    'feed_name' => 'qty',
                    'format'    => 'optional',
                ),
                'Shipping days'         => array(
                    'name'      => 'Shipping days',
                    'feed_name' => 'shipping_days',
                    'format'    => 'optional',
                ),
                'TARIC code'            => array(
                    'name'      => 'TARIC code',
                    'feed_name' => 'taric',
                    'format'    => 'optional',
                ),
                'EAN'                   => array(
                    'name'      => 'EAN',
                    'feed_name' => 'ean',
                    'format'    => 'optional',
                ),
                'Video URL'             => array(
                    'name'      => 'Video URL',
                    'feed_name' => 'video',
                    'format'    => 'optional',
                ),
                'For negotiation'       => array(
                    'name'      => 'For negotiation',
                    'feed_name' => 'for_negotiation',
                    'format'    => 'optional',
                ),
                'Maximum discount'      => array(
                    'name'      => 'Maximum discount',
                    'feed_name' => 'max_discount',
                    'format'    => 'optional',
                ),
                'Promotional price'     => array(
                    'name'      => 'Promotional price',
                    'feed_name' => 'promo_price',
                    'format'    => 'optional',
                ),
                'Minimum price'         => array(
                    'name'      => 'Minimum price',
                    'feed_name' => 'min_price',
                    'format'    => 'optional',
                ),
                'Special products zone' => array(
                    'name'      => 'Special products zone',
                    'feed_name' => 'special_products_zone',
                    'format'    => 'optional',
                ),
                'Auto refresh'          => array(
                    'name'      => 'Auto refresh',
                    'feed_name' => 'auto_refresh_enable',
                    'format'    => 'optional',
                ),
            ),
        );
        return $merkandi;
    }
}
