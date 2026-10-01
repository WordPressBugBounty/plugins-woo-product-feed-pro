<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes\Feed_Writers
 */

namespace AdTribes\PFP\Classes\Feed_Writers;

defined( 'ABSPATH' ) || exit;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Traits\Singleton_Trait;
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Classes\Repeatable_Attributes;

/**
 * XML Feed Writer class.
 *
 * Writes a single product's attributes to the feed XML for migrated
 * (`feed_attributes`) feeds, including generic nested sub-attribute structures
 * (e.g. `g:shipping` → `g:country`/`g:service`/`g:price`). Reached only via
 * the dispatch fork in the feed generation loop — unmigrated feeds keep using
 * the legacy write path untouched.
 *
 * @since 13.5.8
 */
class Feed_Writer_XML extends Abstract_Class {

    use Singleton_Trait;

    /**
     * The channel taxonomy whose per-attribute chain this writer reproduces.
     *
     * @since 13.5.8
     */
    const GOOGLE_CHAIN_TAXONOMY = 'google_shopping';

    /**
     * A valid XML element name, with an optional namespace prefix.
     *
     * A sub-attribute tag is user configuration, and it reaches `addChild()` as an
     * element NAME. `SimpleXMLElement` neither escapes nor rejects an invalid name,
     * so a space or an angle bracket in one tag makes the whole document
     * unparseable - on every product, because the tag comes from the feed's config
     * rather than from product data.
     *
     * The `D` modifier is required. Without it `$` also matches before a single
     * trailing newline, so a tag saved as "g:country\n" would pass and reach
     * `addChild()` as an element name with an embedded newline.
     *
     * @since 13.5.8
     */
    const XML_NAME_PATTERN = '/^(?:[A-Za-z_][\w.-]*:)?[A-Za-z_][\w.-]*$/D';

    /**
     * Whether a feed writes through this writer instead of the legacy chain.
     *
     * Both conditions are required. The feed must be migrated to the
     * `feed_attributes` schema, and it must run the Google Shopping per-attribute
     * chain. Version 1 ports that chain only — every other channel keeps the
     * legacy path, byte for byte.
     *
     * This is the single dispatch predicate. The generation loop uses it for the
     * writer fork, and for the nested payload placement, so the two cannot drift.
     *
     * @since 13.5.8
     *
     * @param Product_Feed $feed        The product feed instance.
     * @param array        $feed_config The feed's channel configuration.
     * @return bool True when the feed writes through this writer.
     */
    public static function uses_new_writer( Product_Feed $feed, $feed_config ): bool {
        if ( ! is_array( $feed_config ) || ! isset( $feed_config['taxonomy'] ) || self::GOOGLE_CHAIN_TAXONOMY !== $feed_config['taxonomy'] ) {
            return false;
        }

        $data_version = $feed->data_version;

        return is_array( $data_version ) && ! empty( $data_version['feed_attributes'] );
    }

    /**
     * Write a single product's attributes to the feed XML.
     *
     * Ports the Google-chain hardcoded attribute branches from the legacy
     * generation loop. The legacy `else` branch hook contract
     * (`adt_product_feed_xml_attribute_handling`, 7 args) is preserved.
     *
     * A payload value that is an array is a resolved sub-attribute group set from
     * `Sub_Attribute_Resolver`, and it is written through `write_nested()`. Every
     * other value keeps the legacy marker-string handling, so a migrated feed with
     * no sub-attribute configuration produces the same bytes as the legacy path.
     *
     * @since 13.5.8
     *
     * @param \SimpleXMLElement $product_xml   The product XML element to write into.
     * @param array             $product_data  The assembled product data for the current product.
     * @param Product_Feed      $feed          The product feed instance.
     * @param array             $namespaces    XML namespace map keyed by tag prefix.
     * @param callable|null     $legacy_writer Optional. Receives ( $key, $value ) for any attribute that
     *                                         falls through to the legacy per-attribute writer, which still
     *                                         owns the `adt_product_feed_xml_attribute_handling` contract and
     *                                         the branches this writer has not ported yet. Without it, a
     *                                         fall-through attribute is not written at all, so the generation
     *                                         loop always passes one.
     * @return void
     */
    public function write_product( \SimpleXMLElement $product_xml, array $product_data, Product_Feed $feed, array $namespaces, ?callable $legacy_writer = null ): void {
        $google_namespace = isset( $namespaces['g'] ) ? $namespaces['g'] : '';

        if ( ! is_callable( $legacy_writer ) ) {
            /*
             * Fail loudly. Without the callback every attribute that reaches the
             * else branch - id, title, link, price, description - is dropped, and
             * the product would ship as a near-empty <item> with no warning.
             */
            _doing_it_wrong(
                __METHOD__,
                esc_html__( 'A $legacy_writer callable is required. Without it every non-nested attribute is dropped from the product.', 'woo-product-feed-pro' ),
                '13.5.8'
            );
        }

        /*
         * Repeat suffixes (#1133) are normalised away before the branch chain, so
         * every repeat of a declared-repeatable tag reaches the same branch as its
         * first value. The eight tags that already carry a hand-written `_N`-tolerant
         * regex below are unaffected — they matched a suffixed key already. This is
         * what lets a NINTH attribute be made repeatable by a one-line channel
         * declaration, with no ninth branch here.
         */
        $repeat_limits = Repeatable_Attributes::get_limits( (string) $feed->channel_hash );

        foreach ( $product_data as $k => $v ) {
            // Resolved sub-attribute groups (#1036) arrive as arrays. Everything
            // below this point is the ported legacy marker-string handling.
            if ( is_array( $v ) ) {
                $this->write_nested( $product_xml, $this->resolve_nested_tag( (string) $k ), $v, $namespaces );
                continue;
            }

            $k = Repeatable_Attributes::strip_repeat_suffix( (string) $k, $repeat_limits );

            if ( 'g:shipping' === $k ) {
                $ship = explode( '||', $v );
                foreach ( $ship as $vv ) {
                    $shipping   = $product_xml->addChild( $k, '', htmlspecialchars( $google_namespace ) );
                    $ship_split = explode( ':', $vv );

                    foreach ( $ship_split as $ship_piece ) {
                        $piece_value = explode( '##', $ship_piece );
                        if ( preg_match( '/WOOSEA_COUNTRY/', $ship_piece ) ) {
                            $this->add_escaped_child( $shipping, 'g:country', $piece_value[1], $google_namespace );
                        } elseif ( preg_match( '/WOOSEA_REGION/', $ship_piece ) ) {
                            $this->add_escaped_child( $shipping, 'g:region', $piece_value[1], $google_namespace );
                        } elseif ( preg_match( '/WOOSEA_POSTAL_CODE/', $ship_piece ) ) {
                            $this->add_escaped_child( $shipping, 'g:postal_code', $piece_value[1], $google_namespace );
                        } elseif ( preg_match( '/WOOSEA_SERVICE/', $ship_piece ) ) {
                            $this->add_escaped_child( $shipping, 'g:service', $piece_value[1], $google_namespace );
                        } elseif ( preg_match( '/WOOSEA_PRICE/', $ship_piece ) ) {
                            $this->add_escaped_child( $shipping, 'g:price', trim( $piece_value[1] ), $google_namespace );
                        } elseif ( preg_match( '/WOOSEA_MIN_TRANSIT_TIME/', $ship_piece ) ) {
                            $this->add_escaped_child( $shipping, 'g:min_transit_time', trim( $piece_value[1] ), $google_namespace );
                        } elseif ( preg_match( '/WOOSEA_MAX_TRANSIT_TIME/', $ship_piece ) ) {
                            $this->add_escaped_child( $shipping, 'g:max_transit_time', trim( $piece_value[1] ), $google_namespace );
                        } elseif ( preg_match( '/WOOSEA_MIN_HANDLING_TIME/', $ship_piece ) ) {
                            $this->add_escaped_child( $shipping, 'g:min_handling_time', trim( $piece_value[1] ), $google_namespace );
                        } elseif ( preg_match( '/WOOSEA_MAX_HANDLING_TIME/', $ship_piece ) ) {
                            $this->add_escaped_child( $shipping, 'g:max_handling_time', trim( $piece_value[1] ), $google_namespace );
                        } else {
                            // DO NOT ADD ANYTHING.
                            continue;
                        }
                    }
                }
            } elseif ( 'free_shipping_threshold' === $k || 'g:free_shipping_threshold' === $k ) {
                if ( ! empty( $v ) ) {
                    $thresholds = explode( '||', $v );
                    foreach ( $thresholds as $threshold_data ) {
                        $threshold_element = $product_xml->addChild( 'g:free_shipping_threshold', '', $google_namespace );
                        $threshold_split   = explode( ':', $threshold_data );

                        foreach ( $threshold_split as $threshold_piece ) {
                            $piece_value = explode( '##', $threshold_piece );

                            // Skip if the piece value is not set.
                            if ( ! isset( $piece_value[1] ) ) {
                                continue;
                            }

                            if ( preg_match( '/WOOSEA_COUNTRY/', $threshold_piece ) ) {
                                $this->add_escaped_child( $threshold_element, 'g:country', $piece_value[1], $google_namespace );
                            } elseif ( preg_match( '/WOOSEA_PRICE_THRESHOLD/', $threshold_piece ) ) {
                                $this->add_escaped_child( $threshold_element, 'g:price_threshold', trim( $piece_value[1] ), $google_namespace );
                            }
                        }
                    }
                }
            } elseif ( preg_match( '/g:additional_image_link/i', $k ) ) {
                // First replace spaces from additional image URL.
                $v = str_replace( ' ', '', $v );
                // Only add the element if the value is not empty.
                if ( ! empty( $v ) ) {
                    $this->add_escaped_child( $product_xml, 'g:additional_image_link', $v, $google_namespace );
                }
            } elseif ( preg_match( '/g:product_highlight/i', $k ) ) {
                $this->add_escaped_child( $product_xml, 'g:product_highlight', $v, $google_namespace );
            } elseif ( preg_match( '/g:included_destination/i', $k ) ) {
                $this->add_escaped_child( $product_xml, 'g:included_destination', $v, $google_namespace );
            } elseif ( preg_match( '/g:excluded_destination/i', $k ) ) {
                $this->add_escaped_child( $product_xml, 'g:excluded_destination', $v, $google_namespace );
            } elseif ( preg_match( '/g:shopping_ads_excluded_country/i', $k ) ) {
                $this->add_escaped_child( $product_xml, 'g:shopping_ads_excluded_country', $v, $google_namespace );
            } elseif ( preg_match( '/g:promotion_id/i', $k ) ) {
                $this->add_escaped_child( $product_xml, 'g:promotion_id', $v, $google_namespace );
            } elseif ( preg_match( '/g:product_detail/i', $k ) ) {
                $this->write_product_detail( $product_xml, $v, $google_namespace );
            } elseif ( preg_match( '/g:consumer_notice/i', $k ) ) {
                $this->write_consumer_notice( $product_xml, $v, $google_namespace );
            } elseif ( 'g:installment' === $k ) {
                if ( ! empty( $v ) ) {
                    $installment_split = explode( ':', $v );
                    $installment       = $product_xml->addChild( $k, '', $google_namespace );
                    $this->add_escaped_child( $installment, 'g:months', $installment_split[0], $google_namespace );
                    $this->add_escaped_child( $installment, 'g:amount', $installment_split[1], $google_namespace );
                }
            } elseif ( 'g:subscription_cost' === $k ) {
                if ( ! empty( $v ) ) {
                    // Google's subscription_cost is a nested attribute. The value
                    // arrives as a colon-delimited "period:period_length:amount"
                    // string (Google's own text format, e.g. "month:3:50.00 USD");
                    // split it into the nested period / period_length / amount
                    // children. Limit 3 so a stray ':' in the amount is preserved.
                    $subscription_split = explode( ':', $v, 3 );
                    if ( count( $subscription_split ) === 3 && '' !== trim( $subscription_split[2] ) ) {
                        $subscription_cost = $product_xml->addChild( $k, '', $google_namespace );
                        $this->add_escaped_child( $subscription_cost, 'g:period', trim( $subscription_split[0] ), $google_namespace );
                        $this->add_escaped_child( $subscription_cost, 'g:period_length', trim( $subscription_split[1] ), $google_namespace );
                        $this->add_escaped_child( $subscription_cost, 'g:amount', trim( $subscription_split[2] ), $google_namespace );
                    }
                }
            } elseif ( 'g:color' === $k || 'g:size' === $k || 'g:material' === $k ) {
                if ( ! empty( $v ) ) {
                    $attr_split = explode( ',', $v );
                    $nr_attr    = count( $attr_split ) - 1;
                    $attr_value = '';

                    for ( $x = 0; $x <= $nr_attr; $x++ ) {
                        $attr_value .= trim( $attr_split[ $x ] ) . '/';
                    }
                    $attr_value      = rtrim( $attr_value, '/' );
                    $product_xml->$k = rawurldecode( $attr_value );
                }
            } else {
                /**
                 * Filter the product attribute value before adding it to the XML feed.
                 *
                 * @since 13.4.6
                 *
                 * @param mixed  $v       The attribute value.
                 * @param string $k       The attribute key/name.
                 * @param object $product The XML product element.
                 * @param array  $value   The complete product data array.
                 * @param object $feed    The product feed object.
                 * @return mixed
                 */
                $v = apply_filters( 'adt_product_feed_xml_attribute_value', $v, $k, $product_xml, $product_data, $feed );
                $v = $this->recursive_trim( $v );
                $k = trim( $k );

                if ( is_callable( $legacy_writer ) ) {
                    $legacy_writer( $k, $v );
                }
            }
        }
    }

    /**
     * Write resolved sub-attribute groups as nested XML elements.
     *
     * Each group produces one parent element (e.g. `<g:shipping>`) whose
     * children are the group's tag/value rows. Rows with an empty value are
     * omitted from the output.
     *
     * @since 13.5.8
     *
     * @param \SimpleXMLElement $product_xml The product XML element to write into.
     * @param string            $tag         The parent output tag (e.g. `g:shipping`).
     * @param array             $groups      Resolved groups from Sub_Attribute_Resolver.
     * @param array             $namespaces  XML namespace map keyed by tag prefix.
     * @return void
     */
    public function write_nested( \SimpleXMLElement $product_xml, string $tag, array $groups, array $namespaces ): void {
        if ( ! preg_match( self::XML_NAME_PATTERN, $tag ) ) {
            return;
        }

        foreach ( $groups as $group ) {
            if ( ! is_array( $group ) ) {
                continue;
            }

            // Collect the writable rows first. A parent whose every value resolved
            // empty must not leave a bare <g:shipping/> behind, which Google reads
            // as a malformed override rather than as an absent one.
            $rows = array();
            foreach ( $group as $sub ) {
                if ( ! is_array( $sub ) || empty( $sub['tag'] ) || ! isset( $sub['value'] ) || '' === (string) $sub['value'] ) {
                    continue;
                }
                if ( ! preg_match( self::XML_NAME_PATTERN, (string) $sub['tag'] ) ) {
                    continue;
                }
                $rows[] = $sub;
            }

            if ( empty( $rows ) ) {
                continue;
            }

            $parent_el = $product_xml->addChild( $tag, '', $this->resolve_namespace( $tag, $namespaces ) );

            foreach ( $rows as $sub ) {
                // ENT_XML1 escaping — addChild() does not escape '&'. The legacy chain
                // escapes the same way through add_xml_child_with_escaped_entities().
                $this->add_escaped_child(
                    $parent_el,
                    (string) $sub['tag'],
                    (string) $sub['value'],
                    $this->resolve_namespace( (string) $sub['tag'], $namespaces )
                );
            }
        }
    }

    /**
     * Port of the legacy `g:product_detail` branch.
     *
     * KEEP IN SYNC: the attribute_name clean-up below is duplicated in the
     * legacy XML branch of WooSEA_Get_Products::woosea_create_xml_feed() and in
     * WooSEA_Get_Products::get_product_detail_attribute_name() (the CSV path).
     * See #1039.
     *
     * @since 13.5.8
     *
     * @param \SimpleXMLElement $product_xml      The product XML element.
     * @param string            $v                The marker-string value.
     * @param string            $google_namespace The Google namespace URI.
     * @return void
     */
    private function write_product_detail( \SimpleXMLElement $product_xml, $v, string $google_namespace ): void {
        if ( empty( $v ) ) {
            return;
        }

        $product_detail_split = explode( '#', $v );
        $detail_complete      = count( $product_detail_split );

        if ( 2 !== $detail_complete || empty( $product_detail_split[1] ) ) {
            return;
        }

        $product_detail     = $product_xml->addChild( 'g:product_detail', '', $google_namespace );
        $name               = str_replace( '_', ' ', $product_detail_split[0] );
        $section_name       = explode( ':', $name );
        $section_name_start = ucfirst( $section_name[0] );

        if ( preg_match( '/||/i', $product_detail_split[0] ) ) {
            $product_detail_value_exp = explode( '||', $product_detail_split[0] );
            $product_detail_name      = $product_detail_value_exp[0];
            $product_detail_value     = $product_detail_split[1];
            $section_name_start       = str_replace( $product_detail_value_exp[0], '', $section_name_start );
            $section_name_start       = trim( str_replace( '||', '', $section_name_start ) );
        } else {
            $product_detail_name  = 'General';
            $product_detail_value = $product_detail_split[0];
        }

        $this->add_escaped_child( $product_detail, 'g:section_name', $product_detail_name, $google_namespace );
        $section_name_start = str_replace( 'Pa ', '', $section_name_start );
        $section_name_start = str_replace( 'pa ', '', $section_name_start );
        $section_name_start = str_replace( '-', ' ', $section_name_start );
        // Strip the leading "Custom attributes" group-label prefix case-insensitively.
        // It arrives in two casings: "Custom attributes ..." (capital C, plain path)
        // or "custom attributes ..." (lowercase, the section/|| path — ucfirst() only
        // capitalised the leading section token, which has since been removed). A
        // case-sensitive str_replace missed the lowercase form, leaking the prefix
        // into g:attribute_name. Anchor to the start so a real attribute name that
        // merely contains the phrase is never altered.
        $section_name_start = preg_replace( '/^custom attributes\s+/i', '', $section_name_start );
        $this->add_escaped_child( $product_detail, 'g:attribute_name', ucfirst( $section_name_start ), $google_namespace );
        $this->add_escaped_child( $product_detail, 'g:attribute_value', $product_detail_value, $google_namespace );
    }

    /**
     * Port of the legacy `g:consumer_notice` branch.
     *
     * @since 13.5.8
     *
     * @param \SimpleXMLElement $product_xml      The product XML element.
     * @param string            $v                The mapped notice value.
     * @param string            $google_namespace The Google namespace URI.
     * @return void
     */
    private function write_consumer_notice( \SimpleXMLElement $product_xml, $v, string $google_namespace ): void {
        if ( empty( $v ) ) {
            return;
        }

        $notice = $product_xml->addChild( 'consumer_notice', '', $google_namespace );

        /*
         * Google's Universal Commerce Protocol requires underscore enum
         * values for notice_type ( prop_65 / safety_warning / legal_disclaimer ).
         * Recognise both the space and underscore form of each type
         * ( case-insensitively ), emit the UCP value, and strip the matched
         * token from the notice message.
         */
        $notice_types = array(
            'prop_65'          => array( 'prop_65', 'prop 65' ),
            'safety_warning'   => array( 'safety_warning', 'safety warning' ),
            'legal_disclaimer' => array( 'legal_disclaimer', 'legal disclaimer' ),
        );

        // Default per UCP when no recognisable type token is present. The
        // type is a leading prefix on the mapped value, so match only at the
        // start ( case-insensitively ) and strip just that prefix — anchoring
        // the same way the g:attribute_name handling above does — so a message
        // that merely contains the phrase is never altered.
        $notice_type_value = 'safety_warning';
        $trimmed_notice    = ltrim( $v );
        foreach ( $notice_types as $ucp_value => $tokens ) {
            foreach ( $tokens as $token ) {
                if ( stripos( $trimmed_notice, $token ) === 0 ) {
                    $notice_type_value = $ucp_value;
                    $v                 = trim( substr( $trimmed_notice, strlen( $token ) ) );
                    break 2;
                }
            }
        }

        $notice->addChild( 'g:notice_type', $notice_type_value, $google_namespace );
        $this->add_escaped_child( $notice, 'g:notice_message', $v, $google_namespace );
    }

    /**
     * Resolve a resolved-group payload key to its output tag.
     *
     * Only one key needs normalising: the channel names the free-shipping parent
     * `free_shipping_threshold`, and the legacy chain writes it as
     * `g:free_shipping_threshold`.
     *
     * This does NOT strip a `_N` duplicate suffix, and must not. A duplicate output
     * tag never carries resolved groups, because `Sub_Attribute_Resolver::apply_to_payload()`
     * keys the payload by the configured `feed_name` alone. Matching a `<tag>_`
     * prefix here would instead truncate real Google attributes that merely start
     * with a nested parent's name — `g:shipping_label`, `g:shipping_weight`,
     * `g:shipping_length`, `g:shipping_width`, `g:shipping_height` and
     * `g:tax_category` all do — and emit them as `<g:shipping>` or `<g:tax>`, which
     * Google would ingest as a shipping or tax override.
     *
     * The `_N` suffixes of the marker-string path are handled where the legacy chain
     * handles them: the ported branches in `write_product()` keep legacy's own
     * substring regular expressions, which match a suffixed key already.
     *
     * @since 13.5.8
     *
     * @param string $key The payload key.
     * @return string The output tag.
     */
    private function resolve_nested_tag( string $key ): string {
        return 'free_shipping_threshold' === $key ? 'g:free_shipping_threshold' : $key;
    }

    /**
     * Resolve the namespace URI for an output tag.
     *
     * @since 13.5.8
     *
     * @param string $tag        The output tag.
     * @param array  $namespaces XML namespace map keyed by tag prefix.
     * @return string The namespace URI, or an empty string when the tag carries no known prefix.
     */
    private function resolve_namespace( string $tag, array $namespaces ): string {
        $separator = strpos( $tag, ':' );
        if ( false === $separator ) {
            return '';
        }

        $prefix = substr( $tag, 0, $separator );

        return isset( $namespaces[ $prefix ] ) ? (string) $namespaces[ $prefix ] : '';
    }

    /**
     * Add a child element with XML entities escaped.
     *
     * Mirrors `add_xml_child_with_escaped_entities()` in the legacy writer:
     * `SimpleXMLElement::addChild()` does not escape `&`, so the value is escaped
     * first. `woosea_save_xml_with_xmlwriter()` writes the final file and escapes
     * text nodes again, which both paths rely on.
     *
     * @since 13.5.8
     *
     * @param \SimpleXMLElement $parent_el     The parent element.
     * @param string            $name          The child element name.
     * @param string            $value         The value to escape and add.
     * @param string            $namespace_uri Optional. The XML namespace URI.
     * @return \SimpleXMLElement|null The created child element.
     */
    private function add_escaped_child( \SimpleXMLElement $parent_el, string $name, $value, string $namespace_uri = '' ): ?\SimpleXMLElement {
        $escaped = htmlspecialchars( (string) $value, ENT_XML1, 'UTF-8' );

        if ( '' !== $namespace_uri ) {
            return $parent_el->addChild( $name, $escaped, $namespace_uri );
        }

        return $parent_el->addChild( $name, $escaped );
    }

    /**
     * Trim a value, recursing into arrays.
     *
     * Mirrors `woosea_recursive_trim()` in the legacy writer, which the else branch
     * applies before handing the value to the per-attribute writer.
     *
     * @since 13.5.8
     *
     * @param mixed $value The value to trim.
     * @return mixed The trimmed value.
     */
    private function recursive_trim( $value ) {
        if ( is_array( $value ) ) {
            return array_map( array( $this, 'recursive_trim' ), $value );
        }

        return trim( $value );
    }

    /**
     * Run the class.
     *
     * @since 13.5.8
     */
    public function run() {
        // No hooks needed for this writer class.
        // It's called directly from the feed generation dispatch fork.
    }
}
