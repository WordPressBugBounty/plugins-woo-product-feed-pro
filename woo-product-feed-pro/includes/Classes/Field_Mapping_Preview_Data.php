<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Factories\Product_Feed;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Build the raw product data array that backs the field mapping preview.
 *
 * SCOPE, AND WHY IT IS NARROW. The generation path assembles `$product_data`
 * inline inside `WooSEA_Get_Products::woosea_get_products()`
 * (`classes/class-get-products.php:3061`) - a method that also truncates and
 * rewrites the live feed file. Calling it for a preview would overwrite the
 * user's feed, and extracting the builder would break the epic invariant that
 * caps `class-get-products.php` at two edits. So the preview resolves its own
 * product data.
 *
 * The consequence is a deliberate contract: this class resolves the MAPPING
 * layer faithfully - the source key of each row, its prefix/suffix, static
 * values, page/post URLs, and the shipping zone rows that drive nested
 * `g:shipping` - and nothing else. Filters, Rules and the tax transformations
 * live in the monolith and do NOT run here.
 *
 * Multi-currency DOES run, despite living in the monolith for generation: the
 * addons convert prices from a callback on `adt_get_product_data`, which this
 * class fires below, so `Curcy` and `WCML` overwrite price/regular_price/
 * sale_price here exactly as they do during generation. Do not describe the
 * preview as currency-free.
 *
 * Variation values are NOT inherited from the parent, except for images and
 * taxonomies (see `resolve_images()` and `resolve_core()`): a variation with an
 * empty description previews as empty, which is what the mapping layer would
 * resolve. Whether the preview should fall back to the parent for description
 * is an open product question, not an oversight.
 *
 * `adt_get_product_data` still fires, so Elite and the addons see the payload -
 * though it is a preview-shaped subset, not the generation loop's full key set.
 *
 * @since 13.5.8
 */
class Field_Mapping_Preview_Data {

    /**
     * Build the product data array for one product, keyed by internal attribute key.
     *
     * @since 13.5.8
     *
     * @param WC_Product   $product The product to resolve against.
     * @param Product_Feed $feed    The product feed instance.
     * @return array The product data, keyed by internal attribute key.
     */
    public static function build( WC_Product $product, Product_Feed $feed ) {
        $product_data = self::resolve_core( $product );

        /*
         * Shipping zone rows. `Sub_Attribute_Resolver` reads them from
         * `$product_data['shipping']` (its ZONE_ROWS_KEY) to expand one template
         * group per zone, so a nested `g:shipping` previews with the same rows
         * generation would use. This is the same public call the generation loop
         * makes at `class-get-products.php:4445`.
         */
        try {
            $product_data['shipping'] = Shipping_Data::instance()->get_shipping_data( $product, $feed );
        } catch ( \Throwable $e ) {
            // A shipping-zone failure must not take the whole preview down.
            $product_data['shipping'] = array();
        }

        /*
         * The filter is contained here, unlike in the generation loop.
         *
         * 20 callbacks across Elite, the multicurrency addon, the translation
         * addon and WWPP hook it, and every one was written against the
         * generation loop's full key set. This payload is a preview-shaped
         * subset, so a single consumer's assumption about an absent key would
         * turn the preview pane into a 500. During generation that failure costs
         * one scheduled action; here it is in front of the user.
         *
         * The fallback is the unfiltered payload - still a faithful mapping-layer
         * result, only missing the addon's contribution. Swallowed silently, to
         * match the shipping-zone catch above.
         */
        /** This filter is documented in classes/class-get-products.php */
        try {
            $filtered = apply_filters( 'adt_get_product_data', $product_data, $feed, $product );
        } catch ( \Throwable $e ) {
            return $product_data;
        }

        return is_array( $filtered ) ? $filtered : $product_data;
    }

    /**
     * Resolve the core WooCommerce and WordPress values.
     *
     * @since 13.5.8
     *
     * @param WC_Product $product The product to resolve against.
     * @return array The resolved values, keyed by internal attribute key.
     */
    private static function resolve_core( WC_Product $product ) {
        /*
         * A variation inherits most of its content from its parent: description,
         * images, categories and tags are all empty on the variation itself. The
         * generation path reads the inherited values, so the preview has to as
         * well, or a variation previews as a nearly empty product.
         */
        $parent = self::get_inheritance_parent( $product );

        $data = array(
            'id'                => (string) $product->get_id(),
            'sku'               => (string) $product->get_sku(),
            'title'             => (string) $product->get_name(),
            'description'       => (string) $product->get_description(),
            'short_description' => (string) $product->get_short_description(),
            'link'              => (string) $product->get_permalink(),
            'slug'              => (string) $product->get_slug(),
            'price'             => (string) wc_get_price_to_display( $product ),
            'regular_price'     => (string) $product->get_regular_price(),
            'sale_price'        => (string) $product->get_sale_price(),
            'stock_status'      => (string) $product->get_stock_status(),
            'availability'      => $product->is_in_stock() ? 'in stock' : 'out of stock',
            'quantity'          => (string) $product->get_stock_quantity(),
            'weight'            => (string) $product->get_weight(),
            'width'             => (string) $product->get_width(),
            'height'            => (string) $product->get_height(),
            'length'            => (string) $product->get_length(),
            'virtual'           => $product->is_virtual() ? 'yes' : 'no',
            'downloadable'      => $product->is_downloadable() ? 'yes' : 'no',
            'menu_order'        => (string) $product->get_menu_order(),
            'rating_average'    => (string) $product->get_average_rating(),
            'rating_total'      => (string) $product->get_rating_count(),
            'amount_sales'      => (string) $product->get_total_sales(),
            'item_group_id'     => (string) ( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ),
            'site_title'        => (string) get_bloginfo( 'name' ),
            'site_url'          => (string) get_bloginfo( 'url' ),
            'boolean_true'      => 'true',
            'boolean_false'     => 'false',
            'base_country'      => (string) WC()->countries->get_base_country(),
        );

        $shop_page_id                    = wc_get_page_id( 'shop' );
        $data['shop_url']                = $shop_page_id > 0 ? (string) get_permalink( $shop_page_id ) : '';
        $terms_page_id                   = wc_get_page_id( 'terms' );
        $data['terms_condtion_page_url'] = $terms_page_id > 0 ? (string) get_permalink( $terms_page_id ) : '';
        $privacy_page_id                 = (int) get_option( 'wp_page_for_privacy_policy' );
        $data['privacy_policy_page_url'] = $privacy_page_id > 0 ? (string) get_permalink( $privacy_page_id ) : '';

        $data = array_merge( $data, self::resolve_images( $product, $parent ) );
        $data = array_merge( $data, self::resolve_taxonomies( $parent ? $parent : $product ) );

        return $data;
    }

    /**
     * The product a variation inherits its shared content from.
     *
     * @since 13.5.8
     *
     * @param WC_Product $product The product being previewed.
     * @return WC_Product|null The parent product, or null when there is none.
     */
    private static function get_inheritance_parent( WC_Product $product ) {
        if ( ! $product->get_parent_id() ) {
            return null;
        }

        $parent = wc_get_product( $product->get_parent_id() );

        return $parent ? $parent : null;
    }

    /**
     * Resolve the image keys.
     *
     * @since 13.5.8
     *
     * @param WC_Product      $product The product to resolve against.
     * @param WC_Product|null $parent_product Optional. The parent to inherit from for a variation.
     * @return array The resolved image values.
     */
    private static function resolve_images( WC_Product $product, $parent_product = null ) {
        // A variation without its own image shows the parent's, exactly as the
        // storefront and the feed do.
        $image_id = $product->get_image_id();
        if ( ! $image_id && $parent_product ) {
            $image_id = $parent_product->get_image_id();
        }

        $main = $image_id ? (string) wp_get_attachment_url( $image_id ) : '';

        $images = array(
            'image'         => $main,
            'feature_image' => $main,
        );

        $gallery = $product->get_gallery_image_ids();
        if ( empty( $gallery ) && $parent_product ) {
            $gallery = $parent_product->get_gallery_image_ids();
        }

        $urls = array();

        foreach ( array_values( (array) $gallery ) as $index => $attachment_id ) {
            $url = (string) wp_get_attachment_url( $attachment_id );
            if ( '' === $url ) {
                continue;
            }
            $urls[] = $url;
            if ( $index < 10 ) {
                $images[ 'image_' . ( $index + 1 ) ] = $url;
            }
        }

        $images['all_gallery_images'] = implode( ',', $urls );
        $images['all_images']         = implode( ',', array_filter( array_merge( array( $main ), $urls ) ) );

        return $images;
    }

    /**
     * Resolve the taxonomy keys.
     *
     * @since 13.5.8
     *
     * @param WC_Product $product The product to resolve against.
     * @return array The resolved taxonomy values.
     */
    private static function resolve_taxonomies( WC_Product $product ) {
        $category_names = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
        $category_names = is_wp_error( $category_names ) ? array() : $category_names;

        $tag_names = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );
        $tag_names = is_wp_error( $tag_names ) ? array() : $tag_names;

        return array(
            'categories'                 => implode( ' > ', $category_names ),
            'categories_comma_separated' => implode( ',', $category_names ),
            'category_path'              => implode( ' > ', $category_names ),
            'product_tag'                => implode( ',', $tag_names ),
            'product_tag_space'          => implode( ', ', $tag_names ),
        );
    }
}
