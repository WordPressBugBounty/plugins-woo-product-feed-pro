<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Traits\Singleton_Trait;
use AdTribes\PFP\Helpers\Helper;

/**
 * Handles Google Ads (Adwords) Remarketing client-side tracking.
 *
 * Outputs the Google global site tag (gtag.js) snippet on the frontend and
 * fires `view_item`, `purchase`, and `add_to_cart` events for product, order
 * received, and cart pages respectively.
 *
 * @since 13.5.5
 */
class Google_Remarketing extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Google Ads conversion ID (numeric portion, without the AW- prefix).
     *
     * @since 13.5.5
     * @access protected
     *
     * @var string|int
     */
    protected $conversion_id;

    /**
     * Output the Google Remarketing gtag.js snippet on the frontend.
     *
     * @since 13.5.5
     * @access public
     *
     * @param \WC_Product|null $product Optional product object. Falls back to global $product.
     * @return void
     */
    public function add_remarketing_tags( $product = null ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        if ( 'yes' !== get_option( 'adt_add_remarketing' ) ) {
            return;
        }

        $this->conversion_id = get_option( 'adt_adwords_conversion_id' );

        if ( ! is_numeric( $this->conversion_id ) || $this->conversion_id <= 0 ) {
            return;
        }

        $event = $this->resolve_page_event( $product );

        $this->output_gtag_snippet( $event );
    }

    /**
     * Detect the current page type and return structured event data.
     *
     * @since 13.5.5
     * @access protected
     *
     * @param \WC_Product|null $product Optional product object.
     * @return array|null Event array with keys: event_name, event_data. Null if no event applies.
     */
    protected function resolve_page_event( $product ) {
        $pagetype = Helper::get_wc_page_type();

        if ( 'product' === $pagetype ) {
            if ( ! is_object( $product ) ) {
                /**
                 * Filter the post ID used to resolve the product for the Google
                 * Remarketing view_item event. Useful when the global post context
                 * differs from the product context (e.g. on AJAX-rendered
                 * single-product modals).
                 *
                 * @since 13.5.5
                 *
                 * @param int $post_id The current post ID from get_the_ID().
                 */
                $post_id = apply_filters( 'adt_google_remarketing_post_id', get_the_ID() );
                $product = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;
            }

            if ( $product instanceof \WC_Product ) {
                return $this->get_product_page_event( $product );
            }
        } elseif ( 'cart' === $pagetype ) {
            return $this->get_cart_page_event();
        }

        return null;
    }

    /**
     * Build view_item event data for a product page.
     *
     * @since 13.5.5
     * @access protected
     *
     * @param \WC_Product $product The current product object.
     * @return array|null Structured event array, or null if the product has no price.
     */
    protected function get_product_page_event( \WC_Product $product ) {
        if ( '' === $product->get_price() ) {
            return null;
        }

        $product_id = $product->get_id();

        if ( empty( $product_id ) ) {
            return null;
        }

        if ( $product->is_type( 'variable' ) ) {
            return $this->get_variable_product_event( $product, $product_id );
        }

        $price = $this->get_numeric_price( $product->get_price() );

        return $this->build_event( 'view_item', $product_id, $price );
    }

    /**
     * Build view_item event data for a variable product page.
     *
     * When the URL contains variation attributes and a matching variation is
     * found, the variation ID and price are used. Otherwise the parent product
     * ID is used with the lowest variation price.
     *
     * @since 13.5.5
     * @access protected
     *
     * @param \WC_Product $product    The variable product object.
     * @param int         $product_id Parent product ID.
     * @return array Structured event array.
     */
    protected function get_variable_product_event( \WC_Product $product, $product_id ) {
        // woosea_find_matching_product_variation() only returns a non-zero ID when
        // attribute_* params in the URL match a variation, so a positive result
        // already implies the relevant query params are present.
        $variation_id = woosea_find_matching_product_variation( $product, $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( $variation_id > 0 ) {
            $variation = wc_get_product( $variation_id );

            if ( $variation instanceof \WC_Product ) {
                $price = $this->get_numeric_price( $variation->get_price() );
            } else {
                $price = $this->get_variable_price_range( $product );
            }

            return $this->build_event( 'view_item', $variation_id, $price );
        }

        // No variation params in the URL — use the parent product with the lowest variation price.
        $price = $this->get_variable_price_range( $product );

        return $this->build_event( 'view_item', $product_id, $price );
    }

    /**
     * Build purchase or add_to_cart event data for cart-related pages.
     *
     * @since 13.5.5
     * @access protected
     *
     * @return array|null Structured event array, or null if no event applies.
     */
    protected function get_cart_page_event() {
        // Order received / thank-you page — fire purchase event.
        if ( isset( $_GET['key'] ) && is_wc_endpoint_url( 'order-received' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $order_key = sanitize_text_field( wp_unslash( $_GET['key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

            if ( empty( $order_key ) ) {
                return null;
            }

            $order_id = wc_get_order_id_by_order_key( $order_key );
            $order    = wc_get_order( $order_id );

            if ( ! $order instanceof \WC_Order ) {
                return null;
            }

            // Legacy behaviour preserved: only the last order item's ID is reported.
            // Google Ads gtag 'purchase' events in this minimal schema carry a single item.
            $product_id = 0;
            foreach ( $order->get_items() as $order_item ) {
                $prod_id      = $order_item->get_product_id();
                $variation_id = $order_item->get_variation_id();

                if ( $variation_id > 0 ) {
                    $prod_id = $variation_id;
                }

                $product_id = $prod_id;
            }

            $value = $this->get_numeric_price( $order->get_total() );

            return $this->build_event( 'purchase', $product_id, $value );
        }

        // Cart page — fire add_to_cart event.
        if ( ! WC()->cart ) {
            return null;
        }

        $cart_items = WC()->cart->get_cart();

        if ( empty( $cart_items ) ) {
            return null;
        }

        $first_item = reset( $cart_items );
        $product_id = isset( $first_item['product_id'] ) ? $first_item['product_id'] : 0;

        $cart_total = WC()->cart->get_cart_contents_total() + WC()->cart->tax_total;
        $value      = $this->get_numeric_price( $cart_total );

        return $this->build_event( 'add_to_cart', $product_id, $value );
    }

    /**
     * Build a structured Google Ads event array.
     *
     * @since 13.5.5
     * @access protected
     *
     * @param string $event_name The gtag event name (e.g. view_item, purchase, add_to_cart).
     * @param int    $product_id The product or variation ID to include in items[0].id.
     * @param string $value      The event value as a decimal string (e.g. "46.20"). See get_numeric_price().
     * @return array Structured event array with keys: event_name, event_data.
     */
    protected function build_event( $event_name, $product_id, $value ) {
        return array(
            'event_name' => $event_name,
            'event_data' => array(
                'send_to' => 'AW-' . $this->conversion_id,
                'value'   => $value,
                'items'   => array(
                    array(
                        'id'                       => (string) $product_id,
                        'google_business_vertical' => 'retail',
                    ),
                ),
            ),
        );
    }

    /**
     * Output the Google Remarketing gtag.js HTML/JS snippet.
     *
     * Always renders the global site tag (gtag.js loader + config) and
     * appends the resolved event call when one applies to the current page.
     *
     * @since 13.5.5
     * @access protected
     *
     * @param array|null $event Structured event array from resolve_page_event(), or null.
     * @return void
     */
    protected function output_gtag_snippet( $event ) {
        $config_id = 'AW-' . $this->conversion_id;
        ?>
        <!-- Global site tag (gtag.js) - Google Ads: <?php echo (int) $this->conversion_id; ?> - Added by the Product Feed Pro plugin from AdTribes.io -->
        <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Third-party gtag.js loader is correctly inlined in wp_footer; cannot be wp_enqueue_script'd because it is a vendor snippet, not a managed asset. ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $config_id ); ?>"></script>
        <script>
            window.dataLayer = window.dataLayer || [];

            function gtag() {
                dataLayer.push(arguments);
            }
            gtag('js', new Date());

            gtag('config', <?php echo wp_json_encode( $config_id ); ?>);
            <?php
            if ( $event ) {
                echo $this->build_gtag_call( $event['event_name'], $event['event_data'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            ?>
        </script>
        <?php
    }

    /**
     * Build a gtag('event', ...) JS call string.
     *
     * @since 13.5.5
     * @access protected
     *
     * @param string $event_name The gtag event name.
     * @param array  $event_data The event data payload.
     * @return string The gtag() JS call.
     */
    protected function build_gtag_call( $event_name, array $event_data ) {
        return 'gtag("event",' . wp_json_encode( $event_name ) . ',' . wp_json_encode( $event_data ) . ');';
    }

    /**
     * Convert a price value to a locale-safe decimal string at currency precision.
     *
     * Returns a string rather than a float so wp_json_encode() emits the
     * value verbatim as "46.20" instead of the IEEE-754/serialize_precision-
     * dependent long form ("46.200000000000003") that floats produce on
     * environments with `serialize_precision = 17` for sums like
     * get_cart_contents_total() + tax_total. Google Ads gtag accepts the
     * `value` field as either a number or a string and treats them
     * equivalently for conversion reporting.
     *
     * @since 13.5.5
     * @access protected
     *
     * @param mixed $price Raw price value.
     * @return string Decimal string formatted to wc_get_price_decimals() places.
     */
    protected function get_numeric_price( $price ) {
        return wc_format_decimal( $price, wc_get_price_decimals() );
    }

    /**
     * Get the lowest variation price for a variable product.
     *
     * @since 13.5.5
     * @access protected
     *
     * @param \WC_Product $product The variable product object.
     * @return string Decimal string formatted to wc_get_price_decimals() places.
     */
    protected function get_variable_price_range( \WC_Product $product ) {
        $prices = $product->get_variation_prices();

        if ( empty( $prices['price'] ) ) {
            return $this->get_numeric_price( 0 );
        }

        $lowest = reset( $prices['price'] );

        return $this->get_numeric_price( $lowest );
    }

    /**
     * Run the class.
     *
     * @since 13.5.5
     */
    public function run() {
        add_action( 'wp_footer', array( $this, 'add_remarketing_tags' ) );
    }
}
