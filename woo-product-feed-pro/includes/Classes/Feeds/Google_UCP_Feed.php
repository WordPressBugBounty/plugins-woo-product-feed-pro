<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes\Feeds
 */

namespace AdTribes\PFP\Classes\Feeds;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Classes\UCP\Eligibility;
use AdTribes\PFP\Classes\UCP\UCP_Feature;
use AdTribes\PFP\Traits\Singleton_Trait;

defined( 'ABSPATH' ) || exit;

/**
 * Google UCP feed attributes.
 *
 * Injects the agentic-checkout attributes into Google Shopping XML
 * feeds: `g:native_commerce > g:checkout_eligibility` (what makes the
 * Buy button appear in Google AI Mode / Gemini) and
 * `g:merchant_item_id` (the identifier the UCP checkout endpoints
 * resolve back to a product/variation). Attributes are only emitted
 * while the store's UCP surface is being served, and only for products
 * that pass eligibility resolution.
 *
 * `g:consumer_notice` is already a mappable Google Shopping attribute
 * with its own XML writer — it is not auto-injected here.
 *
 * @since 13.6.0
 */
class Google_UCP_Feed extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Memoized serving verdict, null until first resolved.
     *
     * @since 13.6.0
     * @access private
     * @var bool|null
     */
    private $is_serving = null;

    /**
     * Whether the UCP surface is being served, resolved once per request.
     *
     * `is_serving()` walks three settings reads and four filters; the feed
     * writer asks per product row and the answer cannot change mid-run.
     *
     * @since 13.6.0
     * @access private
     *
     * @return bool True when the UCP surface is being served.
     */
    private function is_serving() {

        if ( null === $this->is_serving ) {
            $this->is_serving = UCP_Feature::instance()->is_serving();
        }

        return $this->is_serving;
    }

    /**
     * Inject UCP attributes into a Google Shopping XML product.
     *
     * @since 13.6.0
     *
     * @param array       $xml_product  The product data key/value array.
     * @param array       $feed_channel The active channel configuration.
     * @param object      $feed         The feed object.
     * @param \WC_Product $product      The WooCommerce product.
     * @return array The product data key/value array.
     */
    public function inject_xml_attributes( $xml_product, $feed_channel, $feed, $product ) {

        if ( ! is_array( $xml_product ) || 'google_shopping' !== ( $feed_channel['fields'] ?? '' ) ) {
            return $xml_product;
        }

        if ( ! $product instanceof \WC_Product || ! $this->is_serving() ) {
            return $xml_product;
        }

        $resolution = Eligibility::instance()->is_eligible( $product );

        if ( empty( $resolution['eligible'] ) ) {
            return $xml_product;
        }

        $xml_product['g:native_commerce']  = 'TRUE';
        $xml_product['g:merchant_item_id'] = (string) $product->get_id();

        return $xml_product;
    }

    /**
     * Write the nested `g:native_commerce` XML structure.
     *
     * The value carried through the pipeline is the checkout-eligibility
     * flag; it must be emitted as a nested attribute:
     * `<g:native_commerce><g:checkout_eligibility>TRUE</g:checkout_eligibility></g:native_commerce>`.
     *
     * @since 13.6.0
     *
     * @param bool   $handled            If returned true, skip all default processing for this key.
     * @param object $xml_product        The XML product element object.
     * @param string $attribute          The attribute key/name.
     * @param string $value              The attribute value.
     * @param array  $feed_config        The feed configuration array.
     * @param array  $channel_attributes The channel attributes array.
     * @param object $feed               The feed object.
     * @return bool If returned true, skip all default processing for this key.
     */
    public function handle_xml_attribute( $handled, $xml_product, $attribute, $value, $feed_config, $channel_attributes, $feed ) {

        if ( 'g:native_commerce' !== $attribute ) {
            return $handled;
        }

        if ( ! empty( $value ) ) {
            $namespace       = 'http://base.google.com/ns/1.0';
            $native_commerce = $xml_product->addChild( 'g:native_commerce', '', $namespace );

            // addChild() does not escape, and this handler fires for any
            // product carrying a `g:native_commerce` key — including one a
            // merchant mapped a custom field to, not just the constant this
            // class injects. Escape as the rest of the writer does.
            $native_commerce->addChild( 'g:checkout_eligibility', htmlspecialchars( (string) $value, ENT_XML1, 'UTF-8' ), $namespace );
        }

        return true;
    }

    /**
     * Run the class.
     *
     * @since 13.6.0
     * @access public
     */
    public function run() {

        add_filter( 'adt_product_feed_xml_product', array( $this, 'inject_xml_attributes' ), 10, 4 );
        add_filter( 'adt_product_feed_xml_attribute_handling', array( $this, 'handle_xml_attribute' ), 10, 7 );
    }
}
