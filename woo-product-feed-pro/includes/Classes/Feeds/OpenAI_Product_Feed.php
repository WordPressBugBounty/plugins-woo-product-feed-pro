<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes\Feeds
 */

namespace AdTribes\PFP\Classes\Feeds;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Helpers\Product_Feed_Helper;
use AdTribes\PFP\Traits\Singleton_Trait;

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
     * Required OpenAI feed fields and their safe empty defaults.
     *
     * These fields must be present in every product object even when the mapped
     * WooCommerce value is empty or the user has not yet configured a static value.
     * The defaults ensure the field key exists in the JSONL output so OpenAI does
     * not reject the feed for a missing required attribute.
     *
     * @since 13.5.2.2
     *
     * @var array<string,mixed>
     */
    protected $required_field_defaults = array(
        'weight'        => '',
        'seller_tos'    => '',
        'return_policy' => '',
    );

    /**
     * OpenAI feed fields whose default WooCommerce mapping (boolean_true / boolean_false)
     * yields the literal strings "true" / "false". For JSONL output these must serialise
     * as real JSON booleans to match the spec's Boolean field type.
     *
     * @since 13.5.6
     *
     * @var string[]
     */
    protected $boolean_fields = array(
        'is_eligible_search',
        'is_eligible_checkout',
        'is_eligible_ads',
    );


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
                if ( str_contains( $piece, 'WOOSEA_COUNTRY##' ) ) {
                    $country = str_replace( 'WOOSEA_COUNTRY##', '', $piece );
                } elseif ( str_contains( $piece, 'WOOSEA_REGION##' ) ) {
                    $region = str_replace( 'WOOSEA_REGION##', '', $piece );
                } elseif ( str_contains( $piece, 'WOOSEA_SERVICE##' ) ) {
                    $service = str_replace( 'WOOSEA_SERVICE##', '', $piece );
                } elseif ( str_contains( $piece, 'WOOSEA_PRICE##' ) ) {
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
            \Automattic\WooCommerce\Enums\ProductStockStatus::ON_BACKORDER => 'backorder',
        );

        // Fall back to the spec-valid "unknown" enum for any non-standard stock status
        // (custom statuses registered by third-party plugins) rather than the spaced
        // value from the default switch ("in stock"), which OpenAI would reject.
        return $wc_to_openai_availability_format[ $product->get_stock_status() ] ?? 'unknown';
    }

    /**
     * Register OpenAI as a platform that requires pure plain text.
     *
     * This makes Sanitization::sanitize_html_content() route title, description,
     * and similar fields through convert_to_pure_plain_text() — which strips HTML
     * tags and decodes HTML entities — instead of convert_to_plain_text() which
     * re-encodes entities with htmlentities() for XML compatibility.
     *
     * @since 13.5.2.2
     *
     * @param array $platforms Platform slugs requiring pure plain text.
     * @return array
     */
    public function register_pure_plain_text_platform( $platforms ) {
        $platforms[] = 'openai';
        return $platforms;
    }

    /**
     * Transform an OpenAI JSONL product array before it is written to the feed file.
     *
     * Handles six concerns specific to the JSONL path:
     * 1. Shipping — converts internal WOOSEA_COUNTRY##/… marker strings into
     *    an array of structured shipping objects.
     * 2. HTML entities — decodes any remaining entities (e.g. &gt; in
     *    product_category which is built outside sanitize_html_content()).
     *    Fields like title and description are already clean plain text at this
     *    point because OpenAI is registered as a pure-plain-text platform.
     * 3. Boolean flags — casts the eligibility flags from the "true"/"false"
     *    strings produced by the boolean_true/boolean_false mapping into real
     *    JSON booleans, matching the spec's Boolean field type.
     * 4. List fields — casts target_countries (spec type: List) from its flat
     *    comma-separated string into a JSON array.
     * 5. Sale price currency — appends the currency to sale_price (its
     *    ` {{CURRENCY}}` channel suffix is dead config for recommended fields),
     *    mirroring the required `price` field's "<number> <CURRENCY>" form.
     * 6. Required field defaults — ensures every required field is present in the
     *    output even when the product has no mapped value.
     *
     * @since 13.5.2.2
     *
     * @param array  $product_data The product key/value array being built for JSONL.
     * @param array  $feed_channel The active channel configuration array.
     * @param object $feed         The feed object.
     * @return array
     */
    public function transform_jsonl_product( $product_data, $feed_channel, $feed ) {
        if ( ! isset( $feed_channel['fields'] ) || 'openai' !== $feed_channel['fields'] ) {
            return $product_data;
        }

        // 1. Transform shipping field from internal marker format to array of objects.
        if ( ! empty( $product_data['shipping'] ) ) {
            $product_data['shipping'] = $this->parse_shipping_for_jsonl( $product_data['shipping'] );
        }

        // 2. Decode any remaining HTML entities in string values.
        // (title/description are already plain text via convert_to_pure_plain_text();
        // this covers fields like product_category that are built outside sanitize_html_content().)
        foreach ( $product_data as $key => $value ) {
            if ( is_string( $value ) ) {
                $product_data[ $key ] = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            }
        }

        // 3. Cast eligibility flags from the boolean_true/false string mapping ("true"/"false")
        // to real JSON booleans so the JSONL output matches OpenAI's Boolean field type.
        // Only exact "true"/"false" strings are converted, leaving any custom mapping intact.
        foreach ( $this->boolean_fields as $field ) {
            if ( isset( $product_data[ $field ] ) && is_string( $product_data[ $field ] ) ) {
                $normalized = strtolower( trim( $product_data[ $field ] ) );
                if ( 'true' === $normalized ) {
                    $product_data[ $field ] = true;
                } elseif ( 'false' === $normalized ) {
                    $product_data[ $field ] = false;
                }
            }
        }

        // 4. Cast target_countries (spec type: List) from its flat comma-separated
        // string into a JSON array for the JSONL output. store_country stays a scalar
        // string (spec type: String). CSV/TSV keep the flat string, which is correct
        // for those formats.
        if ( isset( $product_data['target_countries'] ) && is_string( $product_data['target_countries'] ) ) {
            $countries                        = array_filter( array_map( 'trim', explode( ',', $product_data['target_countries'] ) ) );
            $product_data['target_countries'] = array_values( $countries );
        }

        // 5. Append the currency to sale_price so it matches the required `price`
        // field's "<number> <CURRENCY>" form (the channel's ` {{CURRENCY}}` suffix is
        // never substituted for recommended fields). Runs after rules execution.
        // get_feed_currency() is called with no argument here because this JSONL array
        // holds the renamed output keys and carries no 'currency' key to reuse (unlike
        // the CSV path, which passes the full $product_data); it resolves via the filter.
        if ( ! empty( $product_data['sale_price'] ) && is_string( $product_data['sale_price'] ) ) {
            $product_data['sale_price'] = $this->append_price_currency( $product_data['sale_price'], $this->get_feed_currency() );
        }

        // 6. Ensure every required field is present; use the registered default when absent.
        foreach ( $this->required_field_defaults as $field => $default ) {
            if ( ! array_key_exists( $field, $product_data ) ) {
                $product_data[ $field ] = $default;
            }
        }

        return $product_data;
    }

    /**
     * Decode HTML entities and append the sale_price currency in OpenAI CSV/TSV row data.
     *
     * For CSV.GZ / TSV.GZ formats, fields like product_category carry HTML entities
     * (e.g. &gt; as the category separator) that are not decoded by the
     * sanitize_html_content() pipeline; this filter decodes all entity-encoded
     * values in the row so the output is clean plain text. It also appends the
     * currency to the sale_price cell so it matches the required `price` field
     * (the channel's ` {{CURRENCY}}` suffix is never substituted for the
     * recommended sale_price field).
     *
     * @since 13.5.2.2
     *
     * @param array  $pieces_row            The indexed array of CSV cell values for this row.
     * @param array  $old_attributes_config The feed attribute mapping configuration.
     * @param array  $product_data          The full product data array.
     * @param object $feed                  The feed object.
     * @return array
     */
    public function handle_csv_row_data( $pieces_row, $old_attributes_config, $product_data, $feed ) {
        if ( 'openai' !== $feed->get_channel( 'fields' ) ) {
            return $pieces_row;
        }

        // Decode any remaining HTML entities in the row values.
        $pieces_row = array_map(
            function ( $value ) {
                return is_string( $value ) ? html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : $value;
            },
            $pieces_row
        );

        // Append the currency to sale_price for parity with the required `price` field
        // (the channel's ` {{CURRENCY}}` suffix is never substituted for recommended
        // fields). $pieces_row is positionally parallel to $old_attributes_config.
        if ( is_array( $old_attributes_config ) && ! empty( $product_data['sale_price'] ) ) {
            $currency   = $this->get_feed_currency( $product_data );
            $sale_price = (string) $product_data['sale_price'];
            $column     = 0;
            foreach ( $old_attributes_config as $attribute_config ) {
                if ( is_array( $attribute_config ) && isset( $attribute_config['attribute'] ) && 'sale_price' === $attribute_config['attribute'] ) {
                    // Guard against column drift: the CSV row builder can skip a cell when a
                    // page_url/post_url attribute resolves to an empty id, shifting every
                    // column after it. Only append when the resolved cell actually contains
                    // the sale_price value rather than trusting the position blindly.
                    if ( isset( $pieces_row[ $column ] ) && false !== strpos( (string) $pieces_row[ $column ], $sale_price ) ) {
                        $pieces_row[ $column ] = $this->append_price_currency( $pieces_row[ $column ], $currency );
                    }
                    break;
                }
                ++$column;
            }
        }

        return $pieces_row;
    }

    /**
     * Resolve the currency code to append to OpenAI price values.
     *
     * Prefers the per-product currency already resolved into $product_data
     * (set via Product_Feed_Helper::get_product_data_currency() during generation);
     * falls back to the same shared helper when that key is absent (e.g. the JSONL
     * path, which receives the output array rather than the full product data).
     *
     * @since 13.5.6
     *
     * @param array $product_data Optional product data carrying a resolved 'currency'.
     * @return string The currency code, or empty string if unavailable.
     */
    private function get_feed_currency( $product_data = array() ) {
        if ( is_array( $product_data ) && ! empty( $product_data['currency'] ) ) {
            return (string) $product_data['currency'];
        }

        return Product_Feed_Helper::get_product_data_currency();
    }

    /**
     * Append a currency code to a price value (e.g. "150.00" -> "150.00 AUD").
     *
     * Used to format sale_price for OpenAI at output time — after rules run,
     * mirroring how the required `price` field is formatted. No-op when the value
     * is empty, the currency is unknown, or the currency is already present. A
     * literal ` {{CURRENCY}}` placeholder (which a user can type manually on the
     * recommended sale_price field, since the UI only auto-substitutes it for
     * required fields) is resolved rather than left in place.
     *
     * Note: the already-present check is a substring test on the feed currency, so a
     * manually-entered *different* currency token (e.g. " EUR" while the feed currency
     * is USD) is not detected and would be appended alongside — an accepted edge that
     * requires deliberately misconfiguring a recommended field.
     *
     * @since 13.5.6
     *
     * @param string $value    The price value to format.
     * @param string $currency The ISO currency code to append.
     * @return string
     */
    private function append_price_currency( $value, $currency ) {
        $value = (string) $value;

        if ( '' === trim( $value ) || '' === $currency ) {
            return $value;
        }

        // Resolve a manually-entered ` {{CURRENCY}}` placeholder instead of appending a
        // second currency token (e.g. "79.99 {{CURRENCY}}" -> "79.99 USD").
        if ( false !== strpos( $value, '{{CURRENCY}}' ) ) {
            return str_replace( '{{CURRENCY}}', $currency, $value );
        }

        if ( false === strpos( $value, $currency ) ) {
            $value .= ' ' . $currency;
        }

        return $value;
    }

    /**
     * Parse a raw internal shipping string into an array of structured shipping objects.
     *
     * Reuses the same token parsing as write_shipping_attribute() but returns an
     * array of associative arrays rather than a semicolon-delimited string, which
     * is correct for JSON serialisation.
     *
     * @since 13.5.2.2
     *
     * @param string $value Raw shipping value in WOOSEA_COUNTRY##…:WOOSEA_SERVICE##…:… format,
     *                      with multiple entries separated by '||'.
     * @return array Array of shipping entry objects, each with 'country', 'service', 'price'
     *               and optionally 'region' keys.
     */
    private function parse_shipping_for_jsonl( $value ) {
        $shipping_entries = array();
        $shipping_array   = explode( '||', $value );

        foreach ( $shipping_array as $shipping ) {
            $country = '';
            $region  = '';
            $service = '';
            $price   = '';

            $shipping_pieces = explode( ':', $shipping );

            foreach ( $shipping_pieces as $piece ) {
                if ( str_contains( $piece, 'WOOSEA_COUNTRY##' ) ) {
                    $country = str_replace( 'WOOSEA_COUNTRY##', '', $piece );
                } elseif ( str_contains( $piece, 'WOOSEA_REGION##' ) ) {
                    $region = str_replace( 'WOOSEA_REGION##', '', $piece );
                } elseif ( str_contains( $piece, 'WOOSEA_SERVICE##' ) ) {
                    $service = str_replace( 'WOOSEA_SERVICE##', '', $piece );
                } elseif ( str_contains( $piece, 'WOOSEA_PRICE##' ) ) {
                    $price = str_replace( 'WOOSEA_PRICE##', '', $piece );
                }
            }

            $entry = array( 'country' => $country );
            if ( ! empty( $region ) ) {
                $entry['region'] = $region;
            }
            $entry['service'] = $service;
            $entry['price']   = $price;

            $shipping_entries[] = $entry;
        }

        return $shipping_entries;
    }

    /**
     * Run the class.
     *
     * @since 13.4.9
     */
    public function run() {
        add_filter( 'adt_product_feed_xml_attribute_handling', array( $this, 'handle_xml_attribute' ), 10, 7 );
        add_filter( 'adt_product_data_availability_format', array( $this, 'format_availability' ), 10, 3 );
        add_filter( 'adt_product_feed_jsonl_product', array( $this, 'transform_jsonl_product' ), 10, 3 );
        add_filter( 'adt_product_feed_platform_requires_pure_plain_text_fields', array( $this, 'register_pure_plain_text_platform' ), 10, 1 );
        add_filter( 'adt_product_feed_csv_row_data', array( $this, 'handle_csv_row_data' ), 10, 4 );
    }
}
