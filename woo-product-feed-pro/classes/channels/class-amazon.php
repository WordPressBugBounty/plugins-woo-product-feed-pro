<?php
/**
 * Settings for Amazon product feeds.
 *
 * Generates a generic Amazon flat-file inventory loader template (tab-separated)
 * for manual upload to Amazon Seller Central. Category-specific templates
 * (BMVD, Clothing, Home, etc.) are out of scope; sellers should verify the
 * output against the exact template required by their product category.
 *
 * @since 13.6.0
 * @package AdTribes\PFP
 */
class WooSEA_amazon { // phpcs:ignore

    /**
     * Get the channel attributes.
     *
     * @since 13.6.0
     * @return array
     */
    public static function get_channel_attributes() {

        $amazon = array(
            'Feed fields' => array(
                'SKU'                          => array(
                    'name'        => 'SKU',
                    'feed_name'   => 'sku',
                    'format'      => 'required',
                    'woo_suggest' => 'sku',
                ),
                'Product ID'                   => array(
                    'name'        => 'Product ID',
                    'feed_name'   => 'product-id',
                    'format'      => 'required',
                    'woo_suggest' => 'id',
                ),
                'Product ID Type'              => array(
                    'name'      => 'Product ID Type',
                    'feed_name' => 'product-id-type',
                    'format'    => 'required',
                ),
                'Item Name'                    => array(
                    'name'        => 'Item Name',
                    'feed_name'   => 'item-name',
                    'format'      => 'required',
                    'woo_suggest' => 'title',
                ),
                'Brand Name'                   => array(
                    'name'      => 'Brand Name',
                    'feed_name' => 'brand-name',
                    'format'    => 'required',
                ),
                'Manufacturer'                 => array(
                    'name'      => 'Manufacturer',
                    'feed_name' => 'manufacturer',
                    'format'    => 'optional',
                ),
                'Product Description'          => array(
                    'name'        => 'Product Description',
                    'feed_name'   => 'product-description',
                    'format'      => 'required',
                    'woo_suggest' => 'description',
                ),
                'Bullet Point 1'               => array(
                    'name'      => 'Bullet Point 1',
                    'feed_name' => 'bullet-point1',
                    'format'    => 'optional',
                ),
                'Bullet Point 2'               => array(
                    'name'      => 'Bullet Point 2',
                    'feed_name' => 'bullet-point2',
                    'format'    => 'optional',
                ),
                'Bullet Point 3'               => array(
                    'name'      => 'Bullet Point 3',
                    'feed_name' => 'bullet-point3',
                    'format'    => 'optional',
                ),
                'Bullet Point 4'               => array(
                    'name'      => 'Bullet Point 4',
                    'feed_name' => 'bullet-point4',
                    'format'    => 'optional',
                ),
                'Bullet Point 5'               => array(
                    'name'      => 'Bullet Point 5',
                    'feed_name' => 'bullet-point5',
                    'format'    => 'optional',
                ),
                'Standard Price'               => array(
                    'name'        => 'Standard Price',
                    'feed_name'   => 'standard-price',
                    'format'      => 'required',
                    'woo_suggest' => 'price',
                ),
                'Sale Price'                   => array(
                    'name'        => 'Sale Price',
                    'feed_name'   => 'sale-price',
                    'format'      => 'optional',
                    'woo_suggest' => 'sale_price',
                ),
                'Sale From Date'               => array(
                    'name'      => 'Sale From Date',
                    'feed_name' => 'sale-from-date',
                    'format'    => 'optional',
                ),
                'Sale End Date'                => array(
                    'name'      => 'Sale End Date',
                    'feed_name' => 'sale-end-date',
                    'format'    => 'optional',
                ),
                'Currency'                     => array(
                    'name'      => 'Currency',
                    'feed_name' => 'currency',
                    'format'    => 'optional',
                ),
                'Quantity'                     => array(
                    'name'        => 'Quantity',
                    'feed_name'   => 'quantity',
                    'format'      => 'required',
                    'woo_suggest' => 'quantity',
                ),
                'Item Condition'               => array(
                    'name'        => 'Item Condition',
                    'feed_name'   => 'item-condition',
                    'format'      => 'required',
                    'woo_suggest' => 'condition',
                ),
                'Condition Note'               => array(
                    'name'      => 'Condition Note',
                    'feed_name' => 'condition-note',
                    'format'    => 'optional',
                ),
                'Main Image URL'               => array(
                    'name'        => 'Main Image URL',
                    'feed_name'   => 'main-image-url',
                    'format'      => 'required',
                    'woo_suggest' => 'image',
                ),
                'Other Image URL 1'            => array(
                    'name'      => 'Other Image URL 1',
                    'feed_name' => 'other-image-url1',
                    'format'    => 'optional',
                ),
                'Other Image URL 2'            => array(
                    'name'      => 'Other Image URL 2',
                    'feed_name' => 'other-image-url2',
                    'format'    => 'optional',
                ),
                'Other Image URL 3'            => array(
                    'name'      => 'Other Image URL 3',
                    'feed_name' => 'other-image-url3',
                    'format'    => 'optional',
                ),
                'Other Image URL 4'            => array(
                    'name'      => 'Other Image URL 4',
                    'feed_name' => 'other-image-url4',
                    'format'    => 'optional',
                ),
                'Other Image URL 5'            => array(
                    'name'      => 'Other Image URL 5',
                    'feed_name' => 'other-image-url5',
                    'format'    => 'optional',
                ),
                'Other Image URL 6'            => array(
                    'name'      => 'Other Image URL 6',
                    'feed_name' => 'other-image-url6',
                    'format'    => 'optional',
                ),
                'Other Image URL 7'            => array(
                    'name'      => 'Other Image URL 7',
                    'feed_name' => 'other-image-url7',
                    'format'    => 'optional',
                ),
                'Other Image URL 8'            => array(
                    'name'      => 'Other Image URL 8',
                    'feed_name' => 'other-image-url8',
                    'format'    => 'optional',
                ),
                'Merchant Shipping Group Name' => array(
                    'name'      => 'Merchant Shipping Group Name',
                    'feed_name' => 'merchant-shipping-group-name',
                    'format'    => 'optional',
                ),
                'Recommended Browse Nodes'     => array(
                    'name'        => 'Recommended Browse Nodes',
                    'feed_name'   => 'recommended-browse-nodes',
                    'format'      => 'optional',
                    'woo_suggest' => 'categories',
                ),
            ),
        );
        return $amazon;
    }
}
