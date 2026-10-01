<?php

namespace AdTribes\PFP\REST;

defined( 'ABSPATH' ) || exit;

use AdTribes\PFP\Abstracts\Abstract_REST;
use AdTribes\PFP\Classes\Field_Mapping_Preview_Data;
use AdTribes\PFP\Classes\Feed_Writers\Feed_Writer_XML;
use AdTribes\PFP\Classes\Sub_Attribute_Resolver;
use AdTribes\PFP\Classes\Repeatable_Attributes;
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Helpers\Product_Feed_Helper;
use AdTribes\PFP\Traits\Singleton_Trait;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class Field_Mapping_Preview
 *
 * Resolves an in-progress field mapping against one sample product and returns
 * the output it would produce, for the field mapping builder's preview pane.
 *
 * WHY THIS IS A POST ROUTE. The epic's save-path invariant says the field
 * mapping saves through the hidden JSON field plus `admin-post.php`, and that no
 * POST route may be added. That invariant governs the SAVE path. This route
 * saves nothing: it never calls `save()`, never writes post meta, and never
 * touches an option. It is a pure computation endpoint, and it has to accept a
 * body because a 133-row mapping does not fit in a query string and the preview
 * is worthless if it can only show the last saved state. The capability check
 * and the REST nonce are the same ones the read route carries.
 *
 * Anything that persists state still belongs on the form POST. Do not add
 * writes here.
 *
 * @since 13.5.8
 */
class Field_Mapping_Preview extends Abstract_REST {

    use Singleton_Trait;

    /**
     * How many products the picker's search returns.
     *
     * @since 13.5.8
     */
    const PRODUCT_SEARCH_LIMIT = 20;

    /**
     * How many variations of one parent the picker lists.
     *
     * A bound is required: each entry costs a product load, and stores exist with
     * hundreds of variations on a single product.
     *
     * @since 13.5.8
     */
    const VARIATION_LIMIT = 100;

    /**
     * Register the routes.
     *
     * @since 13.5.8
     */
    public function register_routes() {
        $this->rest_base = 'field-mappings';

        register_rest_route(
            $this->namespace,
            "/$this->rest_base/(?P<feed_id>[\w-]+)/preview",
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'get_preview' ),
                'permission_callback' => array( $this, 'get_item_permissions_check' ),
                'args'                => array(
                    'product_id'      => array(
                        'type'              => 'integer',
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                    ),

                    /*
                     * The rows are validated per row in `build_payload()`, which
                     * has to tolerate a partially-filled mapping the user is
                     * still editing. The schema only asserts the outer shape.
                     */
                    'feed_attributes' => array(
                        'type'     => 'array',
                        'required' => false,
                    ),
                ),
            )
        );

        /*
         * Deliberately NOT nested under `/field-mappings/`. The read controller
         * owns `/field-mappings/(?P<feed_id>[\w-]+)`, and `products` matches that
         * pattern, so a nested route would be shadowed and answer 404.
         */
        register_rest_route(
            $this->namespace,
            '/field-mapping-preview/products',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'search_products' ),
                'permission_callback' => array( $this, 'get_item_permissions_check' ),
                'args'                => array(
                    'search' => array(
                        'type'              => 'string',
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'parent' => array(
                        'type'              => 'integer',
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                    ),
                ),
            )
        );
    }

    /**
     * Search published products for the preview's product picker.
     *
     * Title search, plus a direct ID lookup when the term is numeric, so pasting
     * a product ID finds it. Variations are excluded: the preview resolves a feed
     * row, and the feed's rows are configured against parent products.
     *
     * @since 13.5.8
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function search_products( $request ) {
        $parent_id = absint( $request->get_param( 'parent' ) );

        // Variation listing for one parent, which is how the picker offers a
        // variable product's children.
        if ( $parent_id ) {
            $variations = $this->get_variations( $parent_id );
            $parent     = wc_get_product( $parent_id );

            return new WP_REST_Response(
                array(
                    'products'  => $variations,
                    // The picker must not present a truncated list as complete.
                    'truncated' => $parent && count( (array) $parent->get_children() ) > self::VARIATION_LIMIT,
                ),
                200
            );
        }

        $search  = (string) $request->get_param( 'search' );
        $results = array();

        // A numeric term is very likely a product ID, so try that first.
        if ( '' !== $search && ctype_digit( $search ) ) {
            $by_id = wc_get_product( absint( $search ) );
            if ( $by_id && 'publish' === get_post_status( $by_id->get_id() ) ) {
                $results[ $by_id->get_id() ] = $this->to_product_option( $by_id );
            }
        }

        global $wpdb;

        /*
         * Title-only search, on purpose. WP_Query's `s` also LIKEs post_content,
         * and a product catalogue's descriptions are long: on a large store that
         * turns a picker keystroke into a scan of megabytes of text for no gain,
         * because nobody looks up a product by a phrase in its description.
         * `no_found_rows` has no equivalent here - there is simply no COUNT.
         */
        if ( '' !== $search ) {
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_type = 'product' AND post_status = 'publish' AND post_title LIKE %s
                     ORDER BY post_date DESC
                     LIMIT %d",
                    '%' . $wpdb->esc_like( $search ) . '%',
                    self::PRODUCT_SEARCH_LIMIT
                )
            );
        } else {
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_type = 'product' AND post_status = 'publish'
                     ORDER BY post_date DESC
                     LIMIT %d",
                    self::PRODUCT_SEARCH_LIMIT
                )
            );
        }

        foreach ( (array) $ids as $id ) {
            $id = (int) $id;
            if ( isset( $results[ $id ] ) ) {
                continue;
            }

            $product = wc_get_product( $id );
            if ( $product ) {
                $results[ $id ] = $this->to_product_option( $product );
            }
        }

        return new WP_REST_Response( array( 'products' => array_values( $results ) ), 200 );
    }

    /**
     * List a variable product's variations for the picker.
     *
     * Variations are their own feed items - separate price, SKU, image and
     * attribute values - so previewing one is often the only way to see what a
     * mapping really produces.
     *
     * @since 13.5.8
     *
     * @param int $parent_id The parent product ID.
     * @return array Variation options, or an empty list when the product has none.
     */
    private function get_variations( $parent_id ) {
        $parent = wc_get_product( $parent_id );

        if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
            return array();
        }

        $options = array();

        foreach ( array_slice( $parent->get_children(), 0, self::VARIATION_LIMIT ) as $variation_id ) {
            $variation = wc_get_product( $variation_id );

            if ( ! $variation || 'publish' !== get_post_status( $variation_id ) ) {
                continue;
            }

            $summary = $variation->get_attribute_summary();

            $options[] = array(
                'id'     => $variation->get_id(),
                // The attribute summary is what distinguishes one variation from
                // another; the bare name is the parent's for every one of them.
                'name'   => '' !== $summary ? wp_specialchars_decode( $summary, ENT_QUOTES ) : $this->decode_product_name( $variation ),
                'sku'    => $variation->get_sku(),
                'parent' => $parent_id,
            );
        }

        return $options;
    }

    /**
     * Shape one product for the picker.
     *
     * @since 13.5.8
     *
     * @param \WC_Product $product The product.
     * @return array
     */
    private function to_product_option( $product ) {
        return array(
            'id'             => $product->get_id(),
            'name'           => $this->decode_product_name( $product ),
            'sku'            => $product->get_sku(),
            // Lets the picker offer a variation step without a second lookup.
            'has_variations' => $product->is_type( 'variable' ) && ! empty( $product->get_children() ),
        );
    }

    /**
     * A product name fit for a JSON payload.
     *
     * `get_name()` returns the stored post title, which holds HTML entities for
     * characters like `&`. The client escapes on render, so an entity would show
     * up literally as `&amp;`. Decode once here and let the client do the
     * escaping.
     *
     * @since 13.5.8
     *
     * @param \WC_Product $product The product.
     * @return string The decoded product name.
     */
    private function decode_product_name( $product ) {
        return wp_specialchars_decode( (string) $product->get_name(), ENT_QUOTES );
    }

    /**
     * Checks if a given request has access to compute a preview.
     *
     * SECURITY: overrides the always-true default in Abstract_REST. The preview
     * resolves a feed's mapping against real product data, so it is gated behind
     * `manage_woocommerce`, exactly like the field mapping read route. Do not
     * relax it.
     *
     * @since 13.5.8
     *
     * @param \WP_REST_Request $request Full data about the request.
     * @return bool True if the request has access, false otherwise.
     */
    public function get_item_permissions_check( $request ) {
        return current_user_can( 'manage_woocommerce' );
    }

    /**
     * Resolve the posted mapping against one sample product.
     *
     * @since 13.5.8
     *
     * @param \WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_preview( $request ) {
        $feed_id = $request->get_param( 'feed_id' );

        if ( ! $feed_id || 'new' === $feed_id ) {
            // A new feed has no post yet, so there is no feed object to resolve
            // against. The app hides the preview pane until the feed is saved.
            return new WP_REST_Response(
                array( 'error' => __( 'Save the feed before previewing its output.', 'woo-product-feed-pro' ) ),
                400
            );
        }

        $feed = Product_Feed_Helper::get_product_feed( $feed_id );

        /*
         * SECURITY: `Product_Feed::load()` does not check the post type, so any
         * existing post ID returns a truthy feed. Verify the type before this
         * route resolves product data against it.
         */
        if ( ! $feed || Product_Feed::POST_TYPE !== get_post_type( $feed->id ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Feed not found', 'woo-product-feed-pro' ) ), 404 );
        }

        /*
         * The preview must not show output the generated feed cannot contain.
         *
         * Generation forks on exactly this predicate: only a migrated feed on
         * the Google chain runs through `Feed_Writer_XML`, and every other
         * channel keeps the legacy path (epic invariant §4). `render_xml()`
         * below is Google-shaped - it hardcodes the `g:` namespace and calls the
         * new writer - so on a Fruugo, Zbozi, Mall, Yandex, Pinterest or
         * Skroutz feed it would render nested elements that generation never
         * emits, and the user would trust it.
         *
         * Reusing the single dispatch predicate keeps the preview and the
         * generator from ever disagreeing about which feeds are covered.
         */
        if ( ! Feed_Writer_XML::uses_new_writer( $feed, $feed->get_channel() ) ) {
            return new WP_REST_Response(
                array(
                    // `supported`, not `previewable`: the per-line flag in
                    // build_lines() already owns that word for a single
                    // attribute. This one is about the whole feed.
                    'supported' => false,
                    'reason'    => __(
                        'The output preview covers migrated Google Shopping feeds only. This feed generates through the legacy writer, so its output cannot be previewed here.',
                        'woo-product-feed-pro'
                    ),
                    'xml'       => '',
                    'lines'     => array(),
                    'product'   => null,
                ),
                200
            );
        }

        $product = $this->resolve_sample_product( $request->get_param( 'product_id' ) );

        if ( ! $product ) {
            return new WP_REST_Response(
                array( 'error' => __( 'No published product is available to preview against.', 'woo-product-feed-pro' ) ),
                404
            );
        }

        $mapping = $request->get_param( 'feed_attributes' );
        $mapping = is_array( $mapping ) ? $mapping : array();

        /*
         * The in-progress mapping replaces the saved one on the IN-MEMORY feed
         * object only, so `Sub_Attribute_Resolver::apply_to_payload()` - which
         * reads `$feed->feed_attributes` - resolves what the user is editing.
         * `save()` is never called, so nothing persists.
         */
        $feed->set_props( array( 'feed_attributes' => $mapping ) );

        $product_data  = Field_Mapping_Preview_Data::build( $product, $feed );
        $repeat_limits = Repeatable_Attributes::get_limits( (string) $feed->channel_hash );

        /*
         * Expand repeats into their own rows before the payload is built, which is
         * exactly what `Feed_Attributes_Migrator::to_legacy()` does for generation.
         * Building the payload from the expanded list is what keeps the pane's
         * element ORDER equal to the feed's: each repeat sits at its own row
         * position rather than being appended afterwards.
         */
        $payload = $this->build_payload( Repeatable_Attributes::expand_rows( $mapping, $repeat_limits ), $product_data );
        $payload = Sub_Attribute_Resolver::apply_to_payload( $payload, $product_data, $feed );

        return new WP_REST_Response(
            array(
                'xml'     => $this->render_xml( $payload, $feed ),
                'lines'   => $this->build_lines( $payload, $repeat_limits ),
                'product' => array(
                    'id'        => $product->get_id(),
                    'name'      => $this->decode_product_name( $product ),
                    'sku'       => $product->get_sku(),
                    'image'     => $this->product_thumbnail( $product ),
                    'parent'    => $product->get_parent_id(),
                    'variation' => $product->is_type( 'variation' )
                        ? wp_specialchars_decode( (string) $product->get_attribute_summary(), ENT_QUOTES )
                        : '',
                ),
            ),
            200
        );
    }

    /**
     * Thumbnail URL for a product, falling back to its parent for a variation.
     *
     * @since 13.5.8
     *
     * @param \WC_Product $product The product.
     * @return string The thumbnail URL, or an empty string.
     */
    private function product_thumbnail( $product ) {
        $image_id = $product->get_image_id();

        if ( ! $image_id && $product->get_parent_id() ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent ) {
                $image_id = $parent->get_image_id();
            }
        }

        return $image_id ? (string) wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
    }

    /**
     * Resolve the product to preview against.
     *
     * An explicit ID wins. Without one the newest published product is used,
     * which is arbitrary but stable - the picker in the preview pane is how a
     * user chooses a meaningful product.
     *
     * @since 13.5.8
     *
     * @param mixed $product_id Optional. An explicit product ID.
     * @return \WC_Product|null The product, or null when none is available.
     */
    private function resolve_sample_product( $product_id ) {
        if ( $product_id ) {
            $product = wc_get_product( absint( $product_id ) );

            /*
             * Variations are accepted here as well as parent products. A
             * variation post is `publish` like any other, so the same status
             * check covers both.
             */
            if ( $product && 'publish' === get_post_status( $product->get_id() ) ) {
                return $product;
            }
        }

        $ids = wc_get_products(
            array(
                'status'  => 'publish',
                'limit'   => 1,
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'ids',
            )
        );

        if ( empty( $ids ) ) {
            return null;
        }

        $product = wc_get_product( $ids[0] );

        return $product ? $product : null;
    }

    /**
     * Build the output payload from the mapping, keyed by output tag.
     *
     * Mirrors the mapping semantics of the save path: `static` takes the literal
     * `source_value`, `page_url`/`post_url` resolve the permalink of the stored
     * ID, and `field` reads the internal attribute key from the product data.
     * Prefix and suffix wrap whatever the source produced.
     *
     * @since 13.5.8
     *
     * @param array $mapping      The in-progress `feed_attributes` rows, repeats already expanded.
     * @param array $product_data The resolved product data, keyed by internal attribute key.
     * @return array The payload, keyed by output tag.
     */
    private function build_payload( $mapping, $product_data ) {
        $payload = array();

        // Occurrences seen so far, per output tag. Carried rather than recounted:
        // the route accepts a mapping of any length from the client, and rescanning
        // the payload per duplicate makes the build quadratic in the rows sharing a
        // tag.
        $seen = array();

        foreach ( $mapping as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $feed_name = isset( $row['feed_name'] ) ? (string) $row['feed_name'] : '';
            if ( '' === $feed_name ) {
                continue;
            }

            /*
             * The name becomes an XML element name in `write_plain_child()`, and
             * it arrives straight from the request body - the mapping being
             * previewed is not saved yet, so no server-side sanitiser has seen
             * it. `addChild()` neither validates nor escapes an element NAME.
             *
             * This is the same guard `Feed_Writer_XML::write_nested()` applies at
             * generation time, so it is also a correctness fix: a name the writer
             * would drop must not preview as though it would be written.
             */
            if ( ! preg_match( Feed_Writer_XML::XML_NAME_PATTERN, $feed_name ) ) {
                continue;
            }

            $source       = isset( $row['source'] ) ? (string) $row['source'] : 'field';
            $source_value = isset( $row['source_value'] ) ? (string) $row['source_value'] : '';
            $value        = '';

            switch ( $source ) {
                case 'static':
                    $value = $source_value;
                    break;
                case 'page_url':
                case 'post_url':
                    $value = $source_value ? (string) get_permalink( absint( $source_value ) ) : '';
                    break;
                case 'field':
                default:
                    if ( '' === $source_value ) {
                        // An unmapped row contributes nothing, exactly as it does
                        // during generation.
                        continue 2;
                    }
                    if ( ! array_key_exists( $source_value, $product_data ) ) {
                        // The preview cannot resolve this source key. Report it so
                        // the UI labels the cell instead of showing a wrong value.
                        $payload[ self::next_payload_key( $seen, $feed_name ) ] = null;
                        continue 2;
                    }
                    $value = is_scalar( $product_data[ $source_value ] ) ? (string) $product_data[ $source_value ] : '';
                    break;
            }

            $prefix = isset( $row['prefix'] ) ? (string) $row['prefix'] : '';
            $suffix = isset( $row['suffix'] ) ? (string) $row['suffix'] : '';

            $payload[ self::next_payload_key( $seen, $feed_name ) ] = $prefix . $value . $suffix;
        }

        return $payload;
    }

    /**
     * The payload key a repeated output tag should take.
     *
     * A repeat (#1133) arrives as a second row carrying the same output tag, so the
     * payload needs a distinct key per occurrence or the last one silently wins and
     * the pane shows a single value for an attribute the feed emits several times.
     * The `_N` shape matches the key generation builds for a duplicated tag, and
     * `Repeatable_Attributes::strip_repeat_suffix()` collapses it again for both the
     * rendered XML and the line list, so the suffix never reaches the reader.
     *
     * @since 13.5.8
     *
     * @param array  $seen      Occurrences already taken, keyed by output tag. Updated by reference.
     * @param string $feed_name The output tag.
     * @return string A key that is free in the payload.
     */
    private static function next_payload_key( array &$seen, string $feed_name ): string {
        $occurrence = isset( $seen[ $feed_name ] ) ? (int) $seen[ $feed_name ] + 1 : 1;

        $seen[ $feed_name ] = $occurrence;

        return 1 === $occurrence ? $feed_name : $feed_name . '_' . $occurrence;
    }

    /**
     * Flatten the payload into per-attribute preview lines.
     *
     * @since 13.5.8
     *
     * @param array $payload       The resolved payload, keyed by output tag.
     * @param array $repeat_limits Repeat limits keyed by output tag, from `Repeatable_Attributes::get_limits()`.
     * @return array One entry per output tag.
     */
    private function build_lines( $payload, $repeat_limits ) {
        $lines = array();

        foreach ( $payload as $feed_name => $value ) {
            // A repeat is written under a `_N` key, but it emits the base tag, so
            // that is the name the pane must show — one line per emitted element.
            $feed_name = Repeatable_Attributes::strip_repeat_suffix( (string) $feed_name, $repeat_limits );

            if ( null === $value ) {
                $lines[] = array(
                    'feed_name'   => (string) $feed_name,
                    'value'       => '',
                    'nested'      => false,
                    'previewable' => false,
                );
                continue;
            }

            $lines[] = array(
                'feed_name'   => (string) $feed_name,
                'value'       => is_array( $value ) ? '' : (string) $value,
                'nested'      => is_array( $value ),
                'groups'      => is_array( $value ) ? count( $value ) : 0,
                'previewable' => true,
            );
        }

        return $lines;
    }

    /**
     * Render the payload through the real XML writer.
     *
     * Uses `Feed_Writer_XML` itself, so nested groups, entity escaping and the
     * `_N` duplicate-tag handling behave exactly as they do during generation.
     * The `$legacy_writer` callback stands in for the monolith's per-attribute
     * branch, which is private to the generation class: it writes the escaped
     * child, which is what that branch does for a plain scalar value. Attributes
     * whose legacy branch does more than that (the `adt_product_feed_xml_attribute_handling`
     * consumers) are therefore approximate in the preview only.
     *
     * @since 13.5.8
     *
     * @param array        $payload The resolved payload, keyed by output tag.
     * @param Product_Feed $feed    The product feed instance.
     * @return string The rendered `<item>` XML.
     */
    private function render_xml( $payload, $feed ) {
        $namespaces = array( 'g' => 'http://base.google.com/ns/1.0' );

        $item = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?><item xmlns:g="http://base.google.com/ns/1.0"></item>'
        );

        // Drop the keys the preview could not resolve before the writer runs, so
        // a null never reaches it.
        $payload = array_filter(
            $payload,
            function ( $value ) {
                return null !== $value;
            }
        );

        Feed_Writer_XML::instance()->write_product(
            $item,
            $payload,
            $feed,
            $namespaces,
            function ( $key, $value ) use ( $item, $namespaces ) {
                $this->write_plain_child( $item, (string) $key, $value, $namespaces['g'] );
            }
        );

        $xml = $item->asXML();

        // Strip the XML declaration; the pane renders the element only.
        return trim( (string) preg_replace( '/^<\?xml.*?\?>/', '', (string) $xml ) );
    }

    /**
     * Write one escaped child element, standing in for the legacy per-attribute branch.
     *
     * @since 13.5.8
     *
     * @param \SimpleXMLElement $parent_el    The element to write into.
     * @param string            $key       The output tag.
     * @param mixed             $value     The value to write.
     * @param string            $namespace_uri The Google namespace URI.
     * @return void
     */
    private function write_plain_child( \SimpleXMLElement $parent_el, $key, $value, $namespace_uri ) {
        if ( is_array( $value ) ) {
            return;
        }

        $escaped = htmlspecialchars( (string) $value, ENT_XML1, 'UTF-8' );

        if ( 0 === strpos( $key, 'g:' ) ) {
            $parent_el->addChild( $key, $escaped, $namespace_uri );
            return;
        }

        /*
         * Passing an empty namespace to addChild() emits `xmlns=""` on the
         * element. A tag with no prefix belongs to the document's default
         * namespace, so omit the argument entirely.
         */
        $parent_el->addChild( $key, $escaped );
    }
}
