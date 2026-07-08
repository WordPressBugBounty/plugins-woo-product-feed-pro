<?php
/**
 * Settings for Google Shopping Promotions feeds
 */
class WooSEA_google_shopping_promotions { // phpcs:ignore

    /**
     * Google Shopping Promotions feed attributes.
     *
     * @since 13.5.6
     * @var array
     */
    public $google_attributes_promotions;

    /**
     * Get the channel attributes.
     *
     * @since 13.5.6
     * @return array
     */
    public static function get_channel_attributes() {

        $google_attributes_promotions = array(
            'Feed fields'                => array(
                'promotion_id'              => array(
                    'name'      => 'promotion_id',
                    'feed_name' => 'promotion_id',
                    'format'    => 'required',
                ),
                'product_applicability'     => array(
                    'name'      => 'product_applicability',
                    'feed_name' => 'product_applicability',
                    'format'    => 'required',
                ),
                'offer_type'                => array(
                    'name'      => 'offer_type',
                    'feed_name' => 'offer_type',
                    'format'    => 'required',
                ),
                'long_title'                => array(
                    'name'      => 'long_title',
                    'feed_name' => 'long_title',
                    'format'    => 'required',
                ),
                'promotion_effective_dates' => array(
                    'name'      => 'promotion_effective_dates',
                    'feed_name' => 'promotion_effective_dates',
                    'format'    => 'required',
                ),
                'redemption_channel'        => array(
                    'name'      => 'redemption_channel',
                    'feed_name' => 'redemption_channel',
                    'format'    => 'required',
                ),
                'promotion_destination'     => array(
                    'name'      => 'promotion_destination',
                    'feed_name' => 'promotion_destination',
                    'format'    => 'required',
                ),
                'promotional_display_dates' => array(
                    'name'      => 'promotional_display_dates',
                    'feed_name' => 'promotional_display_dates',
                    'format'    => 'optional',
                ),
                'minimum_purchase_amount'   => array(
                    'name'      => 'minimum_purchase_amount',
                    'feed_name' => 'minimum_purchase_amount',
                    'format'    => 'optional',
                ),
                'generic_redemption_code'   => array(
                    'name'      => 'generic_redemption_code',
                    'feed_name' => 'generic_redemption_code',
                    'format'    => 'optional',
                ),
            ),
            'Product filters'            => array(
                'item_id'        => array(
                    'name'      => 'item_id',
                    'feed_name' => 'item_id',
                    'format'    => 'optional',
                ),
                'item_group_id'  => array(
                    'name'      => 'item_group_id',
                    'feed_name' => 'item_group_id',
                    'format'    => 'optional',
                ),
                'product_type'   => array(
                    'name'      => 'product_type',
                    'feed_name' => 'product_type',
                    'format'    => 'optional',
                ),
                'brand'          => array(
                    'name'      => 'brand',
                    'feed_name' => 'brand',
                    'format'    => 'optional',
                ),
                'custom_label_0' => array(
                    'name'      => 'custom_label_0',
                    'feed_name' => 'custom_label_0',
                    'format'    => 'optional',
                ),
                'custom_label_1' => array(
                    'name'      => 'custom_label_1',
                    'feed_name' => 'custom_label_1',
                    'format'    => 'optional',
                ),
                'custom_label_2' => array(
                    'name'      => 'custom_label_2',
                    'feed_name' => 'custom_label_2',
                    'format'    => 'optional',
                ),
                'custom_label_3' => array(
                    'name'      => 'custom_label_3',
                    'feed_name' => 'custom_label_3',
                    'format'    => 'optional',
                ),
                'custom_label_4' => array(
                    'name'      => 'custom_label_4',
                    'feed_name' => 'custom_label_4',
                    'format'    => 'optional',
                ),
            ),
            'Structured data attributes' => array(
                'percent_off'                  => array(
                    'name'      => 'percent_off',
                    'feed_name' => 'percent_off',
                    'format'    => 'optional',
                ),
                'money_off_amount'             => array(
                    'name'      => 'money_off_amount',
                    'feed_name' => 'money_off_amount',
                    'format'    => 'optional',
                ),
                'buy_this_quantity'            => array(
                    'name'      => 'buy_this_quantity',
                    'feed_name' => 'buy_this_quantity',
                    'format'    => 'optional',
                ),
                'get_this_quantity_discounted' => array(
                    'name'      => 'get_this_quantity_discounted',
                    'feed_name' => 'get_this_quantity_discounted',
                    'format'    => 'optional',
                ),
                'free_shipping'                => array(
                    'name'      => 'free_shipping',
                    'feed_name' => 'free_shipping',
                    'format'    => 'optional',
                ),
                'free_gift_value'              => array(
                    'name'      => 'free_gift_value',
                    'feed_name' => 'free_gift_value',
                    'format'    => 'optional',
                ),
                'free_gift_description'        => array(
                    'name'      => 'free_gift_description',
                    'feed_name' => 'free_gift_description',
                    'format'    => 'optional',
                ),
                'free_gift_item_id'            => array(
                    'name'      => 'free_gift_item_id',
                    'feed_name' => 'free_gift_item_id',
                    'format'    => 'optional',
                ),
            ),
        );
        return $google_attributes_promotions;
    }
}
