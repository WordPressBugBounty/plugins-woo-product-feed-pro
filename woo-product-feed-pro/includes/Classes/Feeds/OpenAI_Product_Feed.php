<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes\Feeds
 */

namespace AdTribes\PFP\Classes\Feeds;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Traits\Singleton_Trait;
use AdTribes\PFP\Factories\Product_Feed;

/**
 * Google Product Review class.
 *
 * @since 13.4.9
 */
class OpenAI_Product_Feed extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Feed type.
     *
     * @since 13.4.9
     *
     * @var string
     */
    protected $feed_type = 'openai_product_feed';

    /**
     * Handle the XML attribute.
     *
     * @since 13.4.9
     *
     * @param bool   $handled If returned true, skip all default processing for this key.
     * @param object $xml_product The XML product element object.
     * @param string $attribute The attribute key/name.
     * @param string $value The attribute value.
     * @param array  $feed_config The feed configuration array.
     * @param array  $channel_attributes The channel attributes array.
     * @param object $feed               The feed object.
     * @return bool If returned true, skip all default processing for this key.
     */
    public function handle_xml_attribute( $handled, $xml_product, $attribute, $value, $feed_config, $channel_attributes, $feed ) {
        if ( ! isset( $feed_config['fields'] ) || 'openai' !== $feed_config['fields'] ) {
            return $handled;
        }

        if ( 'shipping' === $attribute ) {
            $this->write_shipping_attribute( $xml_product, $value );
            $handled = true;
        }

        return $handled;
    }

    /**
     * Write the shipping attribute.
     * Format: country:region:service_class:price
     * Multiple entries separated by semicolons (;).
     *
     * @since 13.4.9
     *
     * @param object $xml_product The XML element object.
     * @param string $value The attribute value.
     */
    private function write_shipping_attribute( $xml_product, $value ) {
        if ( empty( $value ) ) {
            return;
        }

        /**
         * Example input value:
         * "WOOSEA_COUNTRY##VN:WOOSEA_SERVICE##Vietnam Shipping Test:WOOSEA_PRICE##AUD 12.60||WOOSEA_COUNTRY##US:WOOSEA_REGION##CA:WOOSEA_SERVICE##Overnight:WOOSEA_PRICE##USD 16.00"
         *
         * Expected output format per OpenAI spec:
         * "VN::Vietnam Shipping Test:AUD 12.60;US:CA:Overnight:USD 16.00"
         */

        $shipping_entries = array();
        $shipping_array   = explode( '||', $value );

        foreach ( $shipping_array as $shipping ) {
            $country = '';
            $region  = '';
            $service = '';
            $price   = '';

            // Parse each component from the internal format.
            $shipping_pieces = explode( ':', $shipping );

            foreach ( $shipping_pieces as $piece ) {
                if ( strpos( $piece, 'WOOSEA_COUNTRY##' ) !== false ) {
                    $country = str_replace( 'WOOSEA_COUNTRY##', '', $piece );
                } elseif ( strpos( $piece, 'WOOSEA_REGION##' ) !== false ) {
                    $region = str_replace( 'WOOSEA_REGION##', '', $piece );
                } elseif ( strpos( $piece, 'WOOSEA_SERVICE##' ) !== false ) {
                    $service = str_replace( 'WOOSEA_SERVICE##', '', $piece );
                } elseif ( strpos( $piece, 'WOOSEA_PRICE##' ) !== false ) {
                    $price = str_replace( 'WOOSEA_PRICE##', '', $piece );
                }
            }

            // Build the OpenAI format: country:region:service_class:price.
            // Note: region is optional, so we include it even if empty.
            $formatted_entry = sprintf(
                '%s:%s:%s:%s',
                $country,
                $region,
                $service,
                $price
            );

            $shipping_entries[] = $formatted_entry;
        }

        // Join multiple entries with semicolons as per OpenAI spec.
        $shipping_value = implode( ';', $shipping_entries );

        // Add as a simple text child element, not nested XML.
        $xml_product->addChild( 'shipping', htmlspecialchars( $shipping_value, ENT_XML1, 'UTF-8' ) );
    }

    /**
     * Format the availability.
     *
     * @since 13.4.9
     *
     * @param string $availability The availability value.
     * @param object $product The product object.
     * @param array  $feed_channel The feed channel array.
     * @return string The availability value.
     */
    public function format_availability( $availability, $product, $feed_channel ) {
        if ( 'openai' !== $feed_channel['fields'] ) {
            return $availability;
        }

        $wc_to_openai_availability_format = array(
            \Automattic\WooCommerce\Enums\ProductStockStatus::IN_STOCK     => 'in_stock',
            \Automattic\WooCommerce\Enums\ProductStockStatus::OUT_OF_STOCK => 'out_of_stock',
            \Automattic\WooCommerce\Enums\ProductStockStatus::ON_BACKORDER => 'preorder',
        );

        return $wc_to_openai_availability_format[ $product->get_stock_status() ] ?? $availability;
    }

    /**
     * Run the class.
     *
     * @since 13.4.9
     */
    public function run() {
        add_filter( 'adt_product_feed_xml_attribute_handling', array( $this, 'handle_xml_attribute' ), 10, 7 );
        add_filter( 'adt_product_data_availability_format', array( $this, 'format_availability' ), 10, 3 );
    }
}
