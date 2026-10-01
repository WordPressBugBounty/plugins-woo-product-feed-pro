<?php
/**
 * Settings for Bol.com product feeds
 */

// phpcs:disable PEAR.NamingConventions.ValidClassName.Invalid -- legacy class name 'WooSEA_bol' is resolved dynamically as 'WooSEA_' . $channel_name in classes/class-get-products.php; renaming would break feed generation.
/**
 * Bol.com channel definition.
 */
class WooSEA_bol {
    /**
     * Bol.com product feed settings
     *
     * @var array
     */
    public $bol;

    /**
     * Get the channel attributes
     *
     * @return array
     */
    public static function get_channel_attributes() {
        $sitename = get_option( 'blogname' );

        $bol = array(
            'Feed fields' => array(
                'Reference'         => array(
                    'name'        => 'Reference',
                    'feed_name'   => 'Reference',
                    'format'      => 'required',
                    'woo_suggest' => 'id',
                ),
                'EAN'               => array(
                    'name'      => 'EAN',
                    'feed_name' => 'EAN',
                    'format'    => 'required',
                ),
                'Condition'         => array(
                    'name'        => 'Condition',
                    'feed_name'   => 'Condition',
                    'format'      => 'required',
                    'woo_suggest' => 'condition',
                ),
                'Stock'             => array(
                    'name'        => 'Stock',
                    'feed_name'   => 'Stock',
                    'format'      => 'required',
                    'woo_suggest' => 'quantity',
                ),
                'Price'             => array(
                    'name'        => 'Price',
                    'feed_name'   => 'Price',
                    'format'      => 'required',
                    'woo_suggest' => 'price',
                ),
                'Fullfillment by'   => array(
                    'name'      => 'Fullfillment by',
                    'feed_name' => 'Fullfillment by',
                    'format'    => 'required',
                ),

                /*
                 * Deliverycode is one of the six columns Bol.com requires in its own
                 * offer template, next to EAN, Condition, Stock, Price and Fulfilment by
                 * (spelled "Fullfillment by" in this file).
                 * It carries the delivery time of the offer, expressed as one of Bol.com's
                 * own codes (for example "24uurs-23" or "3-5d"), so there is no WooCommerce
                 * field to suggest for it - merchants map it to a static value or to a
                 * custom attribute that holds the code.
                 */
                'Deliverycode'      => array(
                    'name'      => 'Deliverycode',
                    'feed_name' => 'Deliverycode',
                    'format'    => 'required',
                ),
                'Offer description' => array(
                    'name'        => 'Offer description',
                    'feed_name'   => 'Offer description',
                    'format'      => 'required',
                    'woo_suggest' => 'description',
                ),
                'For sale'          => array(
                    'name'      => 'For sale',
                    'feed_name' => 'For sale',
                    'format'    => 'required',
                ),
                'Title'             => array(
                    'name'        => 'Title',
                    'feed_name'   => 'Title',
                    'format'      => 'required',
                    'woo_suggest' => 'title',
                ),
            ),
        );
        return $bol;
    }
}
// phpcs:enable PEAR.NamingConventions.ValidClassName.Invalid
