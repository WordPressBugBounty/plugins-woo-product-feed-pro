<?php
/**
 * Settings for Google Shopping product feeds
 */
class WooSEA_google_shopping { // phpcs:ignore

    /**
     * The destination values Google documents for included_destination and excluded_destination.
     *
     * Both attributes accept the same list, and both are repeatable, so the list
     * doubles as the `repeatable` limit: a destination is either in or out, so
     * naming one more than once is meaningless.
     *
     * @link https://support.google.com/merchants/answer/7501026
     *
     * @var array
     */
    const DESTINATIONS = array(
        'Shopping_ads',
        'Display_ads',
        'Local_inventory_ads',
        'Free_listings',
        'Free_local_listings',
        'Cloud_retail',
        'Local_cloud_retail',
    );

    /**
     * Google attributes
     *
     * @var array
     */
    public $google_attributes;

    /**
     * Get the channel attributes
     */
    public static function get_channel_attributes() {
        $google_attributes = array(
            'Basic product data'           => array(
                'Product ID'             => array(
                    'name'        => 'id',
                    'feed_name'   => 'g:id',
                    'format'      => 'required',
                    'woo_suggest' => 'id',
                ),
                'Product title'          => array(
                    'name'        => 'title',
                    'feed_name'   => 'g:title',
                    'format'      => 'required',
                    'woo_suggest' => 'title',
                ),
                'Short title'            => array(
                    'name'        => 'short_title',
                    'feed_name'   => 'g:short_title',
                    'format'      => 'optional',
                    'woo_suggest' => 'title',
                ),
                'Product description'    => array(
                    'name'        => 'description',
                    'feed_name'   => 'g:description',
                    'format'      => 'required',
                    'woo_suggest' => 'description',
                ),
                'Product URL'            => array(
                    'name'        => 'link',
                    'feed_name'   => 'g:link',
                    'format'      => 'required',
                    'woo_suggest' => 'link',
                ),
                'Main image URL'         => array(
                    'name'        => 'image_link',
                    'feed_name'   => 'g:image_link',
                    'format'      => 'required',
                    'woo_suggest' => 'image',
                ),
                'Additional image URL'   => array(
                    'name'       => 'additional_image_link',
                    'feed_name'  => 'g:additional_image_link',
                    'format'     => 'optional',
                    // "Submit up to 10 additional product images by including this
                    // attribute multiple times." https://support.google.com/merchants/answer/7052112
                    'repeatable' => 10,
                ),
                'Canonical product URL'  => array(
                    'name'      => 'canonical_link',
                    'feed_name' => 'g:canonical_link',
                    'format'    => 'optional',
                ),
                'Product URL mobile'     => array(
                    'name'      => 'mobile_link',
                    'feed_name' => 'g:mobile_link',
                    'format'    => 'optional',
                ),
                'Checkout link template' => array(
                    'name'        => 'checkout_link_template',
                    'feed_name'   => 'g:checkout_link_template',
                    'format'      => 'required',
                    'woo_suggest' => 'cart_link',
                ),
            ),
            'Price & availability'         => array(
                'Stock status'              => array(
                    'name'        => 'availability',
                    'feed_name'   => 'g:availability',
                    'format'      => 'required',
                    'woo_suggest' => 'availability',
                ),

                /*
                 * Google asks for an availability date only in one availability
                 * state: https://support.google.com/merchants/answer/7052112 —
                 * "Required if product availability is set to preorder".
                 *
                 * `required_when` names the gate: the output tag to watch, the
                 * static value that arms the rule, and the notice to show while
                 * it is armed. The gate is a STATIC value on purpose. When
                 * availability comes from a product field, the value is only
                 * known per product at generation time, so a mapped field can
                 * neither arm nor disarm a rule the editor has to resolve now.
                 *
                 * `format` stays `optional`, so the rule is additive: a consumer
                 * that does not read `required_when` keeps today's behaviour,
                 * and no store that never sends `preorder` gains a required
                 * field.
                 */
                'Availability date'         => array(
                    'name'          => 'availability_date',
                    'feed_name'     => 'g:availability_date',
                    'format'        => 'optional',
                    'required_when' => array(
                        'feed_name' => 'g:availability',
                        'static'    => 'preorder',
                        'notice'    => __( 'Google requires an availability date for preorder items. WooCommerce has no preorder stock status, so this date usually comes from a static value or a custom field.', 'woo-product-feed-pro' ),
                    ),
                ),
                'Expiration date'           => array(
                    'name'      => 'expiration_date',
                    'feed_name' => 'g:expiration_date',
                    'format'    => 'optional',
                ),
                'Price'                     => array(
                    'name'        => 'Price',
                    'feed_name'   => 'g:price',
                    'format'      => 'required',
                    'woo_suggest' => 'price',
                    'suffix'      => ' {{CURRENCY}}',
                ),
                'Sale price'                => array(
                    'name'        => 'sale_price',
                    'feed_name'   => 'g:sale_price',
                    'format'      => 'optional',
                    'woo_suggest' => 'sale_price',
                ),
                'Sale price effective date' => array(
                    'name'        => 'sale_price_effective_date',
                    'feed_name'   => 'g:sale_price_effective_date',
                    'format'      => 'optional',
                    'woo_suggest' => 'sale_price_effective_date',
                ),
                'Maximum retail price'      => array(
                    'name'      => 'maximum_retail_price',
                    'feed_name' => 'g:maximum_retail_price',
                    'format'    => 'optional',
                    'suffix'    => ' {{CURRENCY}}',
                ),
                'Unit pricing measure'      => array(
                    'name'      => 'unit_pricing_measure',
                    'feed_name' => 'g:unit_pricing_measure',
                    'format'    => 'optional',
                ),
                'Unit pricing base measure' => array(
                    'name'      => 'unit_pricing_base_measure',
                    'feed_name' => 'g:unit_pricing_base_measure',
                    'format'    => 'optional',
                ),
                'Installment'               => array(
                    'name'      => 'installment',
                    'feed_name' => 'g:installment',
                    'format'    => 'optional',
                ),
                'Subscription cost'         => array(
                    'name'      => 'subscription_cost',
                    'feed_name' => 'g:subscription_cost',
                    'format'    => 'optional',
                ),
                'Loyalty program'           => array(
                    'name'      => 'loyalty_program',
                    'feed_name' => 'g:loyalty_program',
                    'format'    => 'optional',
                ),
                'Auto pricing min price'    => array(
                    'name'      => 'auto_pricing_min_price',
                    'feed_name' => 'g:auto_pricing_min_price',
                    'format'    => 'optional',
                ),
            ),
            'Product category'             => array(
                'Google product category' => array(
                    'name'        => 'google_product_category',
                    'feed_name'   => 'g:google_product_category',
                    'format'      => 'required',
                    'woo_suggest' => 'categories',
                ),
                'Product type'            => array(
                    'name'        => 'product_type',
                    'feed_name'   => 'g:product_type',
                    'format'      => 'required',
                    'woo_suggest' => 'category_path',
                ),
            ),
            'Product identifiers'          => array(
                'Brand'             => array(
                    'name'      => 'brand',
                    'feed_name' => 'g:brand',
                    'format'    => 'required',
                ),

                /*
                 * Google's product identifier rules are conditional, not absolute:
                 * https://support.google.com/merchants/answer/6324461
                 *
                 * `g:gtin` is strongly recommended, never unconditionally required —
                 * plenty of products (custom-made goods, own-brand items) have no
                 * manufacturer-assigned GTIN at all.
                 *
                 * `g:mpn` is required only while no GTIN is supplied. `required_unless`
                 * names the output tag that relaxes it: map `g:gtin` and MPN drops to
                 * recommended.
                 */
                'Gtin'              => array(
                    'name'      => 'gtin',
                    'feed_name' => 'g:gtin',
                    'format'    => 'recommended',
                ),
                'MPN'               => array(
                    'name'            => 'mpn',
                    'feed_name'       => 'g:mpn',
                    'format'          => 'required',
                    'required_unless' => 'g:gtin',
                ),
                'Identifier exists' => array(
                    'name'        => 'identifier_exists',
                    'feed_name'   => 'g:identifier_exists',
                    'woo_suggest' => 'calculated',
                    'format'      => 'required',
                ),
            ),
            'Detailed product description' => array(
                'Condition'                       => array(
                    'name'           => 'condition',
                    'feed_name'      => 'g:condition',
                    'format'         => 'required',
                    'woo_suggest'    => 'condition',
                    'allowed_values' => array( 'new', 'refurbished', 'used' ),
                ),
                'Adult'                           => array(
                    'name'           => 'adult',
                    'feed_name'      => 'g:adult',
                    'format'         => 'optional',
                    'allowed_values' => array( 'yes', 'no' ),
                ),
                'Multipack'                       => array(
                    'name'      => 'multipack',
                    'feed_name' => 'g:multipack',
                    'format'    => 'optional',
                ),
                'Is bundle'                       => array(
                    'name'           => 'is_bundle',
                    'feed_name'      => 'g:is_bundle',
                    'format'         => 'optional',
                    'allowed_values' => array( 'yes', 'no' ),
                ),
                'Energy efficiency class'         => array(
                    'name'           => 'energy_efficiency_class',
                    'feed_name'      => 'g:energy_efficiency_class',
                    'format'         => 'optional',
                    'allowed_values' => array( 'A+++', 'A++', 'A+', 'A', 'B', 'C', 'D', 'E', 'F', 'G' ),
                ),
                'Minimum energy efficiency class' => array(
                    'name'           => 'min_energy_efficiency_class',
                    'feed_name'      => 'g:min_energy_efficiency_class',
                    'format'         => 'optional',
                    'allowed_values' => array( 'A+++', 'A++', 'A+', 'A', 'B', 'C', 'D', 'E', 'F', 'G' ),
                ),
                'Maximum energy efficiency class' => array(
                    'name'           => 'max_energy_efficiency_class',
                    'feed_name'      => 'g:max_energy_efficiency_class',
                    'format'         => 'optional',
                    'allowed_values' => array( 'A+++', 'A++', 'A+', 'A', 'B', 'C', 'D', 'E', 'F', 'G' ),
                ),
                'Age group'                       => array(
                    'name'           => 'age_group',
                    'feed_name'      => 'g:age_group',
                    'format'         => 'optional',
                    'allowed_values' => array( 'newborn', 'infant', 'toddler', 'kids', 'adult' ),
                ),
                'Color'                           => array(
                    'name'      => 'color',
                    'feed_name' => 'g:color',
                    'format'    => 'optional',
                ),
                'Gender'                          => array(
                    'name'           => 'gender',
                    'feed_name'      => 'g:gender',
                    'format'         => 'optional',
                    'allowed_values' => array( 'male', 'female', 'unisex' ),
                ),
                'Material'                        => array(
                    'name'      => 'material',
                    'feed_name' => 'g:material',
                    'format'    => 'optional',
                ),
                'Pattern'                         => array(
                    'name'      => 'pattern',
                    'feed_name' => 'g:pattern',
                    'format'    => 'optional',
                ),
                'Size'                            => array(
                    'name'      => 'size',
                    'feed_name' => 'g:size',
                    'format'    => 'optional',
                ),
                'Size type'                       => array(
                    'name'           => 'size_type',
                    'feed_name'      => 'g:size_type',
                    'format'         => 'optional',
                    'allowed_values' => array( 'regular', 'petite', 'plus', 'tall', 'big', 'maternity' ),
                ),
                'Size system'                     => array(
                    'name'           => 'size_system',
                    'feed_name'      => 'g:size_system',
                    'format'         => 'optional',
                    'allowed_values' => array( 'US', 'UK', 'EU', 'DE', 'FR', 'JP', 'CN', 'IT', 'BR', 'MEX', 'AU' ),
                ),
                'Item group ID'                   => array(
                    'name'        => 'item_group_id',
                    'feed_name'   => 'g:item_group_id',
                    'format'      => 'required',
                    'woo_suggest' => 'item_group_id',
                ),
            ),
            'Shopping campaigns'           => array(
                'Ads redirect (new)'   => array(
                    'name'      => 'ads_redirect',
                    'feed_name' => 'g:ads_redirect',
                    'format'    => 'optional',
                ),
                'Excluded destination' => array(
                    'name'       => 'excluded_destination',
                    'feed_name'  => 'g:excluded_destination',
                    'format'     => 'optional',
                    'repeatable' => 7,
                    'choices'    => self::DESTINATIONS,
                ),
                'Custom label 0'       => array(
                    'name'      => 'custom_label_0',
                    'feed_name' => 'g:custom_label_0',
                    'format'    => 'optional',
                ),
                'Custom label 1'       => array(
                    'name'      => 'custom_label_1',
                    'feed_name' => 'g:custom_label_1',
                    'format'    => 'optional',
                ),
                'Custom label 2'       => array(
                    'name'      => 'custom_label_2',
                    'feed_name' => 'g:custom_label_2',
                    'format'    => 'optional',
                ),
                'Custom label 3'       => array(
                    'name'      => 'custom_label_3',
                    'feed_name' => 'g:custom_label_3',
                    'format'    => 'optional',
                ),
                'Custom label 4'       => array(
                    'name'      => 'custom_label_4',
                    'feed_name' => 'g:custom_label_4',
                    'format'    => 'optional',
                ),
                'Promotion ID'         => array(
                    'name'      => 'promotion_id',
                    'feed_name' => 'g:promotion_id',
                    'format'    => 'optional',
                ),
                'Included destination' => array(
                    'name'       => 'included_destination',
                    'feed_name'  => 'g:included_destination',
                    'format'     => 'optional',
                    'repeatable' => 7,
                    'choices'    => self::DESTINATIONS,
                ),
                'Ads grouping'         => array(
                    'name'      => 'ads_grouping',
                    'feed_name' => 'g:ads_grouping',
                    'format'    => 'optional',
                ),
                'Ads labels'           => array(
                    'name'      => 'ads_labels',
                    'feed_name' => 'g:ads_labels',
                    'format'    => 'optional',
                ),
                'Feed label'           => array(
                    'name'      => 'feed_label',
                    'feed_name' => 'g:feed_label',
                    'format'    => 'optional',
                ),
            ),
            'Shipping'                     => array(

                /*
                 * `g:shipping` is recommended, not optional.
                 *
                 * Most countries enforce shipping information for Shopping ads, and
                 * missing shipping is a common cause of item disapproval, so leaving
                 * the field silent in the mapping UI helps nobody.
                 *
                 * It stops short of `required` on purpose. The rule is
                 * country-dependent, and shipping can also be set at Merchant Center
                 * account level instead of in the feed, so a red Required badge would
                 * be wrong for a store that legitimately needs neither - and it would
                 * raise the unmapped-required count on every Google feed.
                 */
                'Shipping'                => array(
                    'name'      => 'shipping',
                    'feed_name' => 'g:shipping',
                    'format'    => 'recommended',
                ),
                'Shipping label'          => array(
                    'name'      => 'shipping_label',
                    'feed_name' => 'g:shipping_label',
                    'format'    => 'optional',
                ),
                'Shipping weight'         => array(
                    'name'      => 'shipping_weight',
                    'feed_name' => 'g:shipping_weight',
                    'format'    => 'optional',
                ),
                'Shipping length'         => array(
                    'name'      => 'shipping_length',
                    'feed_name' => 'g:shipping_length',
                    'format'    => 'optional',
                ),
                'Shipping width'          => array(
                    'name'      => 'shipping_width',
                    'feed_name' => 'g:shipping_width',
                    'format'    => 'optional',
                ),
                'Shipping height'         => array(
                    'name'      => 'shipping_height',
                    'feed_name' => 'g:shipping_height',
                    'format'    => 'optional',
                ),
                'Free shipping threshold' => array(
                    'name'      => 'free_shipping_threshold',
                    'feed_name' => 'g:free_shipping_threshold',
                    'format'    => 'optional',
                ),
                'Minimum handling time'   => array(
                    'name'      => 'min_handling_time',
                    'feed_name' => 'g:min_handling_time',
                    'format'    => 'optional',
                ),
                'Maximum handling time'   => array(
                    'name'      => 'max_handling_time',
                    'feed_name' => 'g:max_handling_time',
                    'format'    => 'optional',
                ),
                'Minimum Transit Time'    => array(
                    'name'      => 'min_transit_time',
                    'feed_name' => 'g:min_transit_time',
                    'format'    => 'optional',
                ),
                'Maximum Transit Time'    => array(
                    'name'      => 'max_transit_time',
                    'feed_name' => 'g:max_transit_time',
                    'format'    => 'optional',
                ),
                'Ships from country'      => array(
                    'name'      => 'ships_from_country',
                    'feed_name' => 'g:ships_from_country',
                    'format'    => 'optional',
                ),
                'Region Id'               => array(
                    'name'      => 'region_id',
                    'feed_name' => 'g:region_id',
                    'format'    => 'optional',
                ),
            ),
            'Tax'                          => array(
                'Tax'          => array(
                    'name'      => 'tax',
                    'feed_name' => 'g:tax',
                    'format'    => 'optional',
                ),
                'Tax category' => array(
                    'name'      => 'tax_category',
                    'feed_name' => 'g:tax_category',
                    'format'    => 'optional',
                ),
            ),
            'Shopping Actions'             => array(
                'Product fee'                          => array(
                    'name'      => 'product_fee',
                    'feed_name' => 'g:product_fee',
                    'format'    => 'optional',
                ),
                'Purchase quantity limit'              => array(
                    'name'      => 'purchase_quantity_limit',
                    'feed_name' => 'g:purchase_quantity_limit',
                    'format'    => 'optional',
                ),
                'Sell on google quantity'              => array(
                    'name'      => 'sell_on_google_quantity',
                    'feed_name' => 'g:sell_on_google_quantity',
                    'format'    => 'optional',
                ),
                'Consumer datasheet'                   => array(
                    'name'      => 'consumer_datasheet',
                    'feed_name' => 'g:consumer_datasheet',
                    'format'    => 'optional',
                ),
                'Consumer notice 1'                    => array(
                    'name'      => 'consumer_notice_1',
                    'feed_name' => 'g:consumer_notice',
                    'format'    => 'optional',
                ),
                'Consumer notice 2'                    => array(
                    'name'      => 'consumer_notice_2',
                    'feed_name' => 'g:consumer_notice',
                    'format'    => 'optional',
                ),
                'Consumer notice 3'                    => array(
                    'name'      => 'consumer_notice_3',
                    'feed_name' => 'g:consumer_notice',
                    'format'    => 'optional',
                ),
                'Energy label image link'              => array(
                    'name'      => 'energy_label_image_link',
                    'feed_name' => 'g:energy_label_image_link',
                    'format'    => 'optional',
                ),
                'Excluding countries for Shopping ads' => array(
                    'name'      => 'shopping_ads_excluded_country',
                    'feed_name' => 'g:shopping_ads_excluded_country',
                    'format'    => 'optional',
                ),
                'Product detail'                       => array(
                    'name'      => 'product_detail',
                    'feed_name' => 'g:product_detail',
                    'format'    => 'optional',
                ),
                'Product highlight'                    => array(
                    'name'      => 'product_highlight',
                    'feed_name' => 'g:product_highlight',
                    'format'    => 'optional',
                ),
                'Return address label'                 => array(
                    'name'      => 'return_address_label',
                    'feed_name' => 'g:return_address_label',
                    'format'    => 'optional',
                ),
                'Return policy label'                  => array(
                    'name'      => 'return_policy_label',
                    'feed_name' => 'g:return_policy_label',
                    'format'    => 'optional',
                ),
                'Google funded promotion eligibility'  => array(
                    'name'      => 'google_funded_promotion_eligibility',
                    'feed_name' => 'g:google_funded_promotion_eligibility',
                    'format'    => 'optional',
                ),
                'Pickup method'                        => array(
                    'name'      => 'pickup_method',
                    'feed_name' => 'g:pickup_method',
                    'format'    => 'optional',
                ),
                'Pickup SLA'                           => array(
                    'name'      => 'pickup_SLA',
                    'feed_name' => 'g:pickup_SLA',
                    'format'    => 'optional',
                ),
                'Pickup link template'                 => array(
                    'name'      => 'pickup_link_template',
                    'feed_name' => 'g:pickup_link_template',
                    'format'    => 'optional',
                ),
                'Store code'                           => array(
                    'name'      => 'store_code',
                    'feed_name' => 'g:store_code',
                    'format'    => 'optional',
                ),
                'Mobile pickup link template'          => array(
                    'name'      => 'mobile_pickup_link_template',
                    'feed_name' => 'g:mobile_pickup_link_template',
                    'format'    => 'optional',
                ),
                'Link template'                        => array(
                    'name'      => 'link_template',
                    'feed_name' => 'g:link_template',
                    'format'    => 'optional',
                ),
                'Seller name'                          => array(
                    'name'      => 'seller_name',
                    'feed_name' => 'g:seller_name',
                    'format'    => 'optional',
                ),
                'Count'                                => array(
                    'name'      => 'count',
                    'feed_name' => 'g:count',
                    'format'    => 'optional',
                ),
                'Disclosure date'                      => array(
                    'name'      => 'disclosure_date',
                    'feed_name' => 'g:disclosure_date',
                    'format'    => 'optional',
                ),
                'Feature description'                  => array(
                    'name'      => 'feature_description',
                    'feed_name' => 'g:feature_description',
                    'format'    => 'optional',
                ),
                'Flavor'                               => array(
                    'name'      => 'flavor',
                    'feed_name' => 'g:flavor',
                    'format'    => 'optional',
                ),
                'Scent'                                => array(
                    'name'      => 'scent',
                    'feed_name' => 'g:scent',
                    'format'    => 'optional',
                ),
                'Format'                               => array(
                    'name'      => 'format',
                    'feed_name' => 'g:format',
                    'format'    => 'optional',
                ),
                'Release date'                         => array(
                    'name'      => 'release_date',
                    'feed_name' => 'g:release_date',
                    'format'    => 'optional',
                ),
                'Rich product content'                 => array(
                    'name'      => 'rich_product_content',
                    'feed_name' => 'g:rich_product_content',
                    'format'    => 'optional',
                ),
                'Product line'                         => array(
                    'name'      => 'product_line',
                    'feed_name' => 'g:product_line',
                    'format'    => 'optional',
                ),
                'Product name'                         => array(
                    'name'      => 'product_name',
                    'feed_name' => 'g:product_name',
                    'format'    => 'optional',
                ),
                'Product page url'                     => array(
                    'name'      => 'product_page_url',
                    'feed_name' => 'g:product_page_url',
                    'format'    => 'optional',
                ),
                'Product length'                       => array(
                    'name'      => 'product_length',
                    'feed_name' => 'g:product_length',
                    'format'    => 'optional',
                ),
                'Product width'                        => array(
                    'name'      => 'product_width',
                    'feed_name' => 'g:product_width',
                    'format'    => 'optional',
                ),
                'Product height'                       => array(
                    'name'      => 'product_height',
                    'feed_name' => 'g:product_height',
                    'format'    => 'optional',
                ),
                'Product weight'                       => array(
                    'name'      => 'product_weight',
                    'feed_name' => 'g:product_weight',
                    'format'    => 'optional',
                ),
                'Size system'                          => array(
                    'name'           => 'size_system',
                    'feed_name'      => 'g:size_system',
                    'format'         => 'optional',
                    'allowed_values' => array( 'US', 'UK', 'EU', 'DE', 'FR', 'JP', 'CN', 'IT', 'BR', 'MEX', 'AU' ),
                ),
                'Size type'                            => array(
                    'name'           => 'size_type',
                    'feed_name'      => 'g:size_type',
                    'format'         => 'optional',
                    'allowed_values' => array( 'regular', 'petite', 'plus', 'tall', 'big', 'maternity' ),
                ),
                'Capacity'                             => array(
                    'name'      => 'capacity',
                    'feed_name' => 'g:capacity',
                    'format'    => 'optional',
                ),
                'Suggested retail price'               => array(
                    'name'      => 'suggested_retail_price',
                    'feed_name' => 'g:suggested_retail_price',
                    'format'    => 'optional',
                ),
                'Theme'                                => array(
                    'name'      => 'theme',
                    'feed_name' => 'g:theme',
                    'format'    => 'optional',
                ),
                'Video link'                           => array(
                    'name'      => 'video_link',
                    'feed_name' => 'g:video_link',
                    'format'    => 'optional',
                ),
                'Display ads ID'                       => array(
                    'name'      => 'display_ads_id',
                    'feed_name' => 'g:display_ads_id',
                    'format'    => 'optional',
                ),
                'Display ads Similar ID'               => array(
                    'name'      => 'display_ads_similar_id',
                    'feed_name' => 'g:display_ads_similar_id',
                    'format'    => 'optional',
                ),
                'Display ads Title'                    => array(
                    'name'      => 'display_ads_title',
                    'feed_name' => 'g:display_ads_title',
                    'format'    => 'optional',
                ),
                'Display ads Link'                     => array(
                    'name'      => 'display_ads_link',
                    'feed_name' => 'g:display_ads_link',
                    'format'    => 'optional',
                ),
                'Display ads Value'                    => array(
                    'name'      => 'display_ads_value',
                    'feed_name' => 'g:display_ads_value',
                    'format'    => 'optional',
                ),
                '3D model link'                        => array(
                    'name'      => 'virtual_model_link',
                    'feed_name' => 'g:virtual_model_link',
                    'format'    => 'optional',
                ),
                'Certification'                        => array(
                    'name'      => 'certification',
                    'feed_name' => 'g:certification',
                    'format'    => 'optional',
                ),
                'Lifestyle image link'                 => array(
                    'name'      => 'lifestyle_image_link',
                    'feed_name' => 'g:lifestyle_image_link',
                    'format'    => 'optional',
                ),
                'External seller ID'                   => array(
                    'name'      => 'external_seller_id',
                    'feed_name' => 'g:external_seller_id',
                    'format'    => 'optional',
                ),
                'Pause'                                => array(
                    'name'      => 'pause',
                    'feed_name' => 'g:pause',
                    'format'    => 'optional',
                ),
                'Cost of goods​ sold (cogs)'           => array(
                    'name'      => 'cost_of_goods_sold',
                    'feed_name' => 'g:cost_of_goods_sold',
                    'format'    => 'optional',
                ),
            ),
        );
        return $google_attributes;
    }
}
