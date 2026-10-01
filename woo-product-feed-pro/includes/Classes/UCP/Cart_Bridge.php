<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Classes\UCP
 */

namespace AdTribes\PFP\Classes\UCP;

use AdTribes\PFP\Traits\Singleton_Trait;
use WC_Cart;
use WC_Customer;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * UCP checkout-session ↔ WooCommerce cart bridge.
 *
 * Sessions are stateless on our side: the checkout-session ID embeds a
 * cart token (`ucp_{32-hex}`) which keys a headless WooCommerce session
 * (see Session). Each request re-hydrates WC()->session / customer /
 * cart from that token, mutates the real Woo cart, and snapshots it back
 * into the UCP checkout shape (pinned v2026-04-08 schemas under
 * tests/fixtures/ucp/spec/). All money leaves this class in ISO 4217
 * minor units, computed by Woo's own totals engine — never re-derived.
 *
 * @since 13.6.0
 */
class Cart_Bridge {

    use Singleton_Trait;

    /**
     * Checkout-session ID prefix.
     *
     * @since 13.6.0
     * @var string
     */
    const ID_PREFIX = 'ucp_';

    /**
     * Session key for the buyer object.
     *
     * @since 13.6.0
     * @var string
     */
    const SESSION_BUYER = 'adt_ucp_buyer';

    /**
     * Session key for the shipping destination.
     *
     * @since 13.6.0
     * @var string
     */
    const SESSION_DESTINATION = 'adt_ucp_destination';

    /**
     * Session key marking a session as UCP-created.
     *
     * @since 13.6.0
     * @var string
     */
    const SESSION_MARKER = 'adt_ucp';

    /**
     * Session key for a terminal status (completed / canceled).
     *
     * @since 13.6.0
     * @var string
     */
    const SESSION_TERMINAL = 'adt_ucp_terminal';

    /**
     * Session key for a payment escalation (3DS etc.).
     *
     * @since 13.6.0
     * @var string
     */
    const SESSION_ESCALATION = 'adt_ucp_escalation';

    /**
     * The loaded cart token.
     *
     * @since 13.6.0
     * @access protected
     * @var string
     */
    protected $token = '';

    /**
     * Messages collected during mutations (UCP message objects).
     *
     * @since 13.6.0
     * @access protected
     * @var array
     */
    protected $messages = array();

    /**
     * Create a new checkout session.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $environment Authenticating key environment, recorded so
     *                            only that environment can later drive the session.
     * @return string|false The new cart token, or false on failure.
     */
    public function create( $environment = '' ) {

        $token = bin2hex( random_bytes( 16 ) );

        if ( ! $this->load( $token, true ) ) {
            return false;
        }

        WC()->session->set(
            self::SESSION_MARKER,
            array(
                'created_at' => time(),
                'env'        => (string) $environment,
            )
        );
        WC()->session->save_data();

        return $token;
    }

    /**
     * Get the key environment that created the loaded session.
     *
     * The session ID is otherwise a pure bearer token; recording the owning
     * environment lets callers reject a staging key driving a session a
     * production key created (and vice versa).
     *
     * @since 13.6.0
     * @access public
     *
     * @return string Owning environment, empty when unknown (legacy sessions).
     */
    public function get_owner_environment() {

        $marker = WC()->session->get( self::SESSION_MARKER );

        return is_array( $marker ) ? (string) ( $marker['env'] ?? '' ) : '';
    }

    /**
     * Load a checkout session by token or checkout-session ID.
     *
     * Swaps the global WooCommerce session/customer/cart to the headless
     * token-keyed equivalents.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $token    Cart token or `ucp_{token}` session ID.
     * @param bool   $creating Allow a token with no stored session yet.
     * @return bool True when the session is loaded.
     */
    public function load( $token, $creating = false ) {

        $token = self::parse_token( $token );

        if ( '' === $token ) {
            return false;
        }

        if ( ! $creating && ! $this->exists( $token ) ) {
            return false;
        }

        $this->ensure_woocommerce();

        $session = new Session( $token );
        $session->init();

        WC()->session = $session;

        // The customer swap must also migrate WC core's shutdown save hook:
        // WooCommerce::initialize_cart() binds `shutdown -> save()` to the
        // ORIGINAL customer object, so after the swap the stale instance
        // still saves its default (empty) data at shutdown — which
        // WC_Customer_Data_Store_Session::save_to_session() collapses to
        // `customer => null`, wiping the destination this bridge persisted.
        if ( WC()->customer instanceof WC_Customer ) {
            remove_action( 'shutdown', array( WC()->customer, 'save' ), 10 );
        }

        WC()->customer = new WC_Customer( 0, true );
        add_action( 'shutdown', array( WC()->customer, 'save' ), 10 );

        // Reuse the one global cart instance: every WC_Cart constructs a
        // WC_Cart_Session that registers persistent hooks (set_session on
        // woocommerce_after_calculate_totals, destroy on cart-emptied), so a
        // second instance would leave a stale, empty-cart hook behind that
        // wipes session keys (e.g. chosen_shipping_methods) on every
        // recalculation. One instance, re-hydrated per load, avoids that.
        if ( ! WC()->cart instanceof WC_Cart ) {
            WC()->cart = new WC_Cart();
        }

        // Force re-hydration from the swapped session. WC_Cart::get_cart()
        // only hydrates once per request (did_action guard) and
        // WC_Cart::$session has no public accessor.
        $cart = WC()->cart;
        ( function () {
            $this->session->get_cart_from_session();
        } )->call( $cart );

        $this->token    = $token;
        $this->messages = array();

        return true;
    }

    /**
     * Whether a stored session exists for a token.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $token Cart token or `ucp_{token}` session ID.
     * @return bool True when session data exists and carries the UCP marker.
     */
    public function exists( $token ) {

        $token = self::parse_token( $token );

        if ( '' === $token ) {
            return false;
        }

        $data = ( new Session( $token ) )->get_session( $token, false );

        return is_array( $data ) && array_key_exists( self::SESSION_MARKER, $data );
    }

    /**
     * Get the loaded cart token.
     *
     * @since 13.6.0
     * @access public
     *
     * @return string Token, empty string when nothing is loaded.
     */
    public function get_token() {

        return $this->token;
    }

    /**
     * Get the public checkout-session ID for the loaded session.
     *
     * @since 13.6.0
     * @access public
     *
     * @return string Checkout-session ID.
     */
    public function get_session_id() {

        return self::ID_PREFIX . $this->token;
    }

    /**
     * Extract the cart token from a checkout-session ID or bare token.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $value Session ID or token.
     * @return string Bare token, empty string when invalid.
     */
    public static function parse_token( $value ) {

        $value = (string) $value;

        if ( 0 === strpos( $value, self::ID_PREFIX ) ) {
            $value = substr( $value, strlen( self::ID_PREFIX ) );
        }

        return preg_match( '/^[a-f0-9]{32}$/', $value ) ? $value : '';
    }

    /**
     * Replace the cart contents with the requested line items.
     *
     * Enforces eligibility and stock. Quantities above available stock
     * are adjusted down with a `quantity_adjusted` warning rather than
     * rejected, per UCP business-error semantics.
     *
     * @since 13.6.0
     * @access public
     *
     * @param array $line_items UCP line items: array{item: array{id: string}, quantity: int}[].
     */
    public function set_line_items( array $line_items ) {

        WC()->cart->empty_cart( false );

        foreach ( array_values( $line_items ) as $index => $line_item ) {
            $item_id  = (string) ( $line_item['item']['id'] ?? '' );
            $quantity = max( 1, (int) ( $line_item['quantity'] ?? 1 ) );
            $path     = '$.line_items[' . $index . ']';

            $product = wc_get_product( absint( $item_id ) );

            if ( ! $product instanceof WC_Product ) {
                $this->add_message( 'error', 'not_found', sprintf( 'Item %s was not found.', $item_id ), 'recoverable', $path );
                continue;
            }

            $resolution = Eligibility::instance()->is_eligible( $product );
            if ( empty( $resolution['eligible'] ) ) {
                $this->add_message( 'error', 'item_unavailable', sprintf( 'Item %s is not available for agentic checkout.', $item_id ), 'recoverable', $path );
                continue;
            }

            if ( ! $product->is_in_stock() ) {
                $this->add_message( 'error', 'out_of_stock', sprintf( 'Item %s is out of stock.', $item_id ), 'recoverable', $path );
                continue;
            }

            $available = $product->managing_stock() && ! $product->backorders_allowed()
                ? (int) $product->get_stock_quantity()
                : null;

            if ( null !== $available && $available < $quantity ) {
                $this->add_message( 'warning', 'quantity_adjusted', sprintf( 'Quantity for item %1$s was adjusted from %2$d to the %3$d available.', $item_id, $quantity, $available ), '', $path );
                $quantity = $available;
            }

            if ( $product->is_sold_individually() && $quantity > 1 ) {
                $this->add_message( 'warning', 'quantity_adjusted', sprintf( 'Item %s is limited to one per order.', $item_id ), '', $path );
                $quantity = 1;
            }

            $this->add_product_to_cart( $product, $quantity, $path );
        }

        $this->persist();
    }

    /**
     * Set the buyer.
     *
     * @since 13.6.0
     * @access public
     *
     * @param array $buyer UCP buyer object (first_name, last_name, email, phone_number).
     */
    public function set_buyer( array $buyer ) {

        WC()->session->set( self::SESSION_BUYER, array_intersect_key( $buyer, array_flip( array( 'first_name', 'last_name', 'email', 'phone_number' ) ) ) );

        $customer = WC()->customer;

        if ( ! empty( $buyer['email'] ) && is_email( $buyer['email'] ) ) {
            $customer->set_billing_email( sanitize_email( $buyer['email'] ) );
        }
        if ( isset( $buyer['first_name'] ) ) {
            $customer->set_first_name( wc_clean( $buyer['first_name'] ) );
            $customer->set_billing_first_name( wc_clean( $buyer['first_name'] ) );
        }
        if ( isset( $buyer['last_name'] ) ) {
            $customer->set_last_name( wc_clean( $buyer['last_name'] ) );
            $customer->set_billing_last_name( wc_clean( $buyer['last_name'] ) );
        }
        if ( isset( $buyer['phone_number'] ) ) {
            $customer->set_billing_phone( wc_clean( $buyer['phone_number'] ) );
        }

        $this->persist();
    }

    /**
     * Set the shipping destination.
     *
     * The agent supplies a single UCP `shipping_destination`
     * (postal_address + id); it becomes the customer's shipping AND
     * billing address so tax resolves the same way a storefront guest
     * checkout would.
     *
     * @since 13.6.0
     * @access public
     *
     * @param array $destination UCP shipping_destination object.
     */
    public function set_destination( array $destination ) {

        if ( empty( $destination['id'] ) ) {
            $destination['id'] = 'destination_1';
        }

        WC()->session->set( self::SESSION_DESTINATION, $destination );

        $map = array(
            'address_country'  => 'country',
            'address_region'   => 'state',
            'postal_code'      => 'postcode',
            'address_locality' => 'city',
            'street_address'   => 'address_1',
            'extended_address' => 'address_2',
            'first_name'       => 'first_name',
            'last_name'        => 'last_name',
        );

        $customer = WC()->customer;

        foreach ( $map as $ucp_key => $wc_key ) {
            if ( ! isset( $destination[ $ucp_key ] ) ) {
                continue;
            }

            $value = wc_clean( $destination[ $ucp_key ] );

            $shipping_setter = "set_shipping_{$wc_key}";
            $billing_setter  = "set_billing_{$wc_key}";

            if ( is_callable( array( $customer, $shipping_setter ) ) ) {
                $customer->{$shipping_setter}( $value );
            }
            if ( is_callable( array( $customer, $billing_setter ) ) ) {
                $customer->{$billing_setter}( $value );
            }
        }

        // New destination invalidates previously chosen shipping options.
        WC()->session->set( 'chosen_shipping_methods', array() );

        $this->persist();
    }

    /**
     * Replace the applied coupon codes.
     *
     * @since 13.6.0
     * @access public
     *
     * @param array $codes Coupon codes.
     */
    public function apply_coupons( array $codes ) {

        $cart = WC()->cart;

        foreach ( $cart->get_applied_coupons() as $applied ) {
            $cart->remove_coupon( $applied );
        }

        foreach ( array_values( array_unique( array_map( 'wc_format_coupon_code', $codes ) ) ) as $index => $code ) {
            wc_clear_notices();

            if ( ! $cart->apply_coupon( $code ) ) {
                $this->add_message( 'error', 'discount_code_invalid', $this->consume_error_notices( sprintf( 'Discount code "%s" cannot be applied.', $code ) ), 'recoverable', '$.discounts.codes[' . $index . ']' );
            }
        }

        wc_clear_notices();

        $this->persist();
    }

    /**
     * Select a fulfillment option for a fulfillment group.
     *
     * Group IDs map to Woo shipping package indexes; option IDs are Woo
     * shipping rate IDs.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $group_id  Fulfillment group ID (`group_{index}`).
     * @param string $option_id Fulfillment option (shipping rate) ID.
     */
    public function select_fulfillment_option( $group_id, $option_id ) {

        // Reject malformed group IDs instead of silently coercing them to
        // package index 0, which would select the wrong fulfillment group.
        if ( ! preg_match( '/^group_(\d+)$/', (string) $group_id, $matches ) ) {
            $this->add_message( 'error', 'item_unavailable', sprintf( 'Unknown fulfillment group "%s".', $group_id ), 'recoverable', '$.fulfillment.selected_options' );
            return;
        }

        $index = (int) $matches[1];

        $chosen           = (array) WC()->session->get( 'chosen_shipping_methods', array() );
        $chosen[ $index ] = wc_clean( (string) $option_id );

        WC()->session->set( 'chosen_shipping_methods', $chosen );

        $this->persist();
    }

    /**
     * Build the UCP checkout-session snapshot for the loaded cart.
     *
     * The `ucp` envelope member (version/capabilities/payment_handlers)
     * is added by the REST layer, which owns handler declarations.
     *
     * @since 13.6.0
     * @access public
     *
     * @return array Checkout object per the pinned v2026-04-08 schema.
     */
    public function get_snapshot() {

        $cart = WC()->cart;

        $cart->calculate_totals();

        $line_items = $this->build_line_items( $cart );

        $checkout = array(
            'id'         => $this->get_session_id(),
            'status'     => 'incomplete', // Finalized below.
            'currency'   => get_woocommerce_currency(),
            'line_items' => $line_items,
            'totals'     => $this->build_totals( $cart ),
            'messages'   => $this->messages,
            'links'      => $this->build_links(),
            'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', WC()->session instanceof Session ? WC()->session->get_expiration() : time() + Session::DEFAULT_TTL ),
        );

        $buyer = WC()->session->get( self::SESSION_BUYER );
        if ( ! empty( $buyer ) ) {
            $checkout['buyer'] = $buyer;
        }

        if ( $cart->needs_shipping() ) {
            $checkout['fulfillment'] = $this->build_fulfillment( $cart, $line_items );
        }

        $applied_coupons = $cart->get_applied_coupons();
        if ( ! empty( $applied_coupons ) ) {
            $applied = array();
            foreach ( $applied_coupons as $code ) {
                $applied[] = array(
                    'code'   => $code,
                    'title'  => $code,
                    'amount' => self::to_minor( (float) $cart->get_coupon_discount_amount( $code ) ),
                );
            }
            $checkout['discounts'] = array(
                'codes'   => array_values( $applied_coupons ),
                'applied' => $applied,
            );
        }

        // Proactively report what still blocks completion (per the spec's
        // create-response example: e.g. `missing $.buyer.email`).
        $checkout['messages'] = array_merge( $checkout['messages'], $this->compute_missing_messages( $checkout ) );

        $checkout['status'] = $this->resolve_status( $checkout );

        // Terminal status and escalation survive across requests and override
        // the computed pre-payment status.
        $terminal = WC()->session->get( self::SESSION_TERMINAL );
        if ( is_array( $terminal ) && ! empty( $terminal['status'] ) ) {
            $checkout['status'] = $terminal['status'];
            if ( ! empty( $terminal['order'] ) ) {
                $checkout['order'] = $terminal['order'];
            }
        } else {
            $escalation = WC()->session->get( self::SESSION_ESCALATION );
            if ( is_array( $escalation ) && ! empty( $escalation['continue_url'] ) ) {
                $checkout['status']       = 'requires_escalation';
                $checkout['continue_url'] = $escalation['continue_url'];
                if ( ! empty( $escalation['order'] ) ) {
                    $checkout['order'] = $escalation['order'];
                }
            }
        }

        /**
         * Filter the UCP checkout-session snapshot.
         *
         * @since 13.6.0
         *
         * @param array       $checkout Checkout object.
         * @param Cart_Bridge $bridge   The bridge instance.
         */
        return (array) apply_filters( 'adt_ucp_checkout_session_data', $checkout, $this );
    }

    /**
     * Get the buyer stored on the session.
     *
     * @since 13.6.0
     * @access public
     *
     * @return array Buyer object, possibly empty.
     */
    public function get_buyer() {

        return (array) WC()->session->get( self::SESSION_BUYER, array() );
    }

    /**
     * Get the messages collected since the session was loaded.
     *
     * @since 13.6.0
     * @access public
     *
     * @return array UCP message objects.
     */
    public function get_messages() {

        return $this->messages;
    }

    /**
     * Add a UCP message.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $type     'error', 'warning' or 'info'.
     * @param string $code     Message code.
     * @param string $content  Human-readable content.
     * @param string $severity Error severity (errors only).
     * @param string $path     RFC 9535 JSONPath to the related component.
     */
    public function add_message( $type, $code, $content, $severity = '', $path = '' ) {

        $message = array(
            'type'    => $type,
            'code'    => $code,
            'content' => $content,
        );

        if ( 'error' === $type ) {
            $message['severity'] = '' !== $severity ? $severity : 'recoverable';
        }
        if ( '' !== $path ) {
            $message['path'] = $path;
        }

        $this->messages[] = $message;
    }

    /**
     * Destroy the loaded session and its cart.
     *
     * @since 13.6.0
     * @access public
     */
    public function destroy() {

        WC()->cart->empty_cart( false );

        if ( WC()->session instanceof Session ) {
            WC()->session->forget_session();
        }

        $this->token = '';
    }

    /**
     * Ensure WooCommerce frontend includes, session and cart are bootable.
     *
     * @since 13.6.0
     * @access private
     */
    private function ensure_woocommerce() {

        static $loaded = false;

        if ( $loaded ) {
            return;
        }

        if ( function_exists( 'wc_load_cart' ) ) {
            wc_load_cart();
        } else {
            WC()->frontend_includes();
            WC()->initialize_session();
            WC()->initialize_cart();
        }

        $loaded = true;
    }

    /**
     * Add a resolved product to the cart, mapping failures to messages.
     *
     * @since 13.6.0
     * @access private
     *
     * @param WC_Product $product  Product or variation.
     * @param int        $quantity Quantity.
     * @param string     $path     JSONPath for messages.
     */
    private function add_product_to_cart( WC_Product $product, $quantity, $path ) {

        wc_clear_notices();

        try {
            if ( 'variation' === $product->get_type() ) {
                $result = WC()->cart->add_to_cart(
                    $product->get_parent_id(),
                    $quantity,
                    $product->get_id(),
                    $product->get_variation_attributes()
                );
            } else {
                $result = WC()->cart->add_to_cart( $product->get_id(), $quantity );
            }
        } catch ( \Exception $e ) {
            $result = false;

            // Usually a third-party `woocommerce_add_to_cart_validation`
            // filter throwing — surface it for debugging instead of only the
            // generic message below.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( '[AdTribes UCP] add_to_cart threw for product %d: %s', (int) $product->get_id(), $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }

        if ( false === $result ) {
            $this->add_message( 'error', 'item_unavailable', $this->consume_error_notices( sprintf( 'Item %s could not be added.', $product->get_id() ) ), 'recoverable', $path );
        }

        wc_clear_notices();
    }

    /**
     * Build UCP line items from the cart contents.
     *
     * @since 13.6.0
     * @access private
     *
     * @param WC_Cart $cart Cart.
     * @return array UCP line_item objects.
     */
    private function build_line_items( WC_Cart $cart ) {

        $line_items = array();

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            /**
             * The product object for this cart line.
             *
             * @var WC_Product $product
             */
            $product  = $cart_item['data'];
            $quantity = (int) $cart_item['quantity'];

            $subtotal = (float) $cart_item['line_subtotal'];
            $total    = (float) $cart_item['line_total'];
            $tax      = (float) $cart_item['line_tax'];
            $discount = $subtotal - $total;

            $item = array(
                'id'    => (string) ( $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'] ),
                'title' => $product->get_name(),
                'price' => $quantity > 0 ? self::to_minor( $subtotal / $quantity ) : 0,
            );

            $image_url = wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' );
            if ( $image_url ) {
                $item['image_url'] = $image_url;
            }

            $totals = array(
                array(
                    'type'   => 'subtotal',
                    'amount' => self::to_minor( $subtotal ),
                ),
            );

            if ( $discount > 0 ) {
                $totals[] = array(
                    'type'   => 'discount',
                    'amount' => -self::to_minor( $discount ),
                );
            }

            $totals[] = array(
                'type'   => 'tax',
                'amount' => self::to_minor( $tax ),
            );
            $totals[] = array(
                'type'   => 'total',
                'amount' => self::to_minor( $total + $tax ),
            );

            $line_items[] = array(
                'id'       => $cart_item_key,
                'item'     => $item,
                'quantity' => $quantity,
                'totals'   => $totals,
            );
        }

        return $line_items;
    }

    /**
     * Build the UCP totals array from the cart.
     *
     * Exactly one `subtotal` and one `total` entry, per the schema.
     *
     * @since 13.6.0
     * @access private
     *
     * @param WC_Cart $cart Cart.
     * @return array UCP totals.
     */
    private function build_totals( WC_Cart $cart ) {

        $totals = array(
            array(
                'type'   => 'subtotal',
                'amount' => self::to_minor( (float) $cart->get_subtotal() ),
            ),
        );

        $discount = (float) $cart->get_discount_total();
        if ( $discount > 0 ) {
            $totals[] = array(
                'type'         => 'discount',
                'display_text' => __( 'Discount', 'woo-product-feed-pro' ),
                'amount'       => -self::to_minor( $discount ),
            );
        }

        if ( $cart->needs_shipping() ) {
            $totals[] = array(
                'type'         => 'fulfillment',
                'display_text' => __( 'Shipping', 'woo-product-feed-pro' ),
                'amount'       => self::to_minor( (float) $cart->get_shipping_total() ),
            );
        }

        $totals[] = array(
            'type'   => 'tax',
            'amount' => self::to_minor( (float) $cart->get_total_tax() ),
        );

        $totals[] = array(
            'type'   => 'total',
            'amount' => self::to_minor( (float) $cart->get_total( 'edit' ) ),
        );

        return $totals;
    }

    /**
     * Build the UCP fulfillment object: one shipping method holding the
     * agent-supplied destination and one group per Woo shipping package.
     *
     * @since 13.6.0
     * @access private
     *
     * @param WC_Cart $cart       Cart.
     * @param array   $line_items Built UCP line items.
     * @return array UCP fulfillment object.
     */
    private function build_fulfillment( WC_Cart $cart, array $line_items ) {

        $all_line_ids = wp_list_pluck( $line_items, 'id' );
        $destination  = WC()->session->get( self::SESSION_DESTINATION );
        $chosen       = (array) WC()->session->get( 'chosen_shipping_methods', array() );

        $method = array(
            'id'                      => 'shipping_1',
            'type'                    => 'shipping',
            'line_item_ids'           => $all_line_ids,
            'destinations'            => ! empty( $destination ) ? array( $destination ) : array(),
            'selected_destination_id' => ! empty( $destination['id'] ) ? $destination['id'] : null,
            'groups'                  => array(),
        );

        if ( ! empty( $destination ) ) {
            $cart->calculate_shipping();

            foreach ( WC()->shipping()->get_packages() as $package_index => $package ) {
                $group = array(
                    'id'                 => 'group_' . $package_index,
                    'line_item_ids'      => array_values( array_intersect( array_keys( (array) ( $package['contents'] ?? array() ) ), $all_line_ids ) ),
                    'options'            => array(),
                    'selected_option_id' => isset( $chosen[ $package_index ] ) && '' !== $chosen[ $package_index ] ? $chosen[ $package_index ] : null,
                );

                foreach ( (array) ( $package['rates'] ?? array() ) as $rate ) {
                    /**
                     * The shipping rate.
                     *
                     * @var \WC_Shipping_Rate $rate
                     */
                    $group['options'][] = array(
                        'id'     => $rate->get_id(),
                        'title'  => $rate->get_label(),
                        'totals' => array(
                            array(
                                'type'   => 'fulfillment',
                                'amount' => self::to_minor( (float) $rate->get_cost() ),
                            ),
                            array(
                                'type'   => 'tax',
                                'amount' => self::to_minor( (float) array_sum( $rate->get_taxes() ) ),
                            ),
                        ),
                    );
                }

                $method['groups'][] = $group;
            }
        }

        return array( 'methods' => array( $method ) );
    }

    /**
     * Build the legally required links (privacy policy, TOS, refunds).
     *
     * @since 13.6.0
     * @access private
     *
     * @return array UCP link objects.
     */
    private function build_links() {

        $links = array();

        $privacy_url = get_privacy_policy_url();
        if ( $privacy_url ) {
            $links[] = array(
                'type' => 'privacy_policy',
                'url'  => $privacy_url,
            );
        }

        $pages = array(
            'terms_of_service' => wc_terms_and_conditions_page_id(),
            'refund_policy'    => wc_get_page_id( 'refund_returns' ),
        );

        foreach ( $pages as $type => $page_id ) {
            if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
                $links[] = array(
                    'type' => $type,
                    'url'  => (string) get_permalink( $page_id ),
                );
            }
        }

        /**
         * Filter the UCP checkout links.
         *
         * @since 13.6.0
         *
         * @param array $links UCP link objects.
         */
        return (array) apply_filters( 'adt_ucp_checkout_links', $links );
    }

    /**
     * Resolve the checkout status from the snapshot state.
     *
     * Blocking conditions are already materialized as error messages
     * (item failures and compute_missing_messages()), so the pre-payment
     * status reduces to: items present and no errors. Escalation and
     * terminal states are applied by the snapshot from session state.
     *
     * @since 13.6.0
     * @access public
     *
     * @param array $checkout Checkout snapshot (pre-status, messages merged).
     * @return string `ready_for_complete` or `incomplete`.
     */
    public function resolve_status( array $checkout ) {

        if ( empty( $checkout['line_items'] ) ) {
            return 'incomplete';
        }

        foreach ( $checkout['messages'] as $message ) {
            if ( 'error' === ( $message['type'] ?? '' ) ) {
                return 'incomplete';
            }
        }

        return 'ready_for_complete';
    }

    /**
     * Build `missing` / blocking error messages for data completion still
     * requires.
     *
     * @since 13.6.0
     * @access private
     *
     * @param array $checkout Checkout snapshot so far (line_items, buyer, fulfillment).
     * @return array UCP message objects.
     */
    private function compute_missing_messages( array $checkout ) {

        $missing = array();

        if ( empty( $checkout['line_items'] ) ) {
            return $missing;
        }

        if ( empty( $checkout['buyer']['email'] ) ) {
            $missing[] = array(
                'type'     => 'error',
                'code'     => 'missing',
                'path'     => '$.buyer.email',
                'content'  => 'Buyer email is required.',
                'severity' => 'recoverable',
            );
        }

        if ( isset( $checkout['fulfillment'] ) ) {
            $method = $checkout['fulfillment']['methods'][0];

            if ( empty( $method['selected_destination_id'] ) ) {
                $missing[] = array(
                    'type'     => 'error',
                    'code'     => 'missing',
                    'path'     => '$.fulfillment.methods[0].destinations',
                    'content'  => 'A shipping destination is required.',
                    'severity' => 'recoverable',
                );
            } elseif ( empty( $method['groups'] ) ) {
                $missing[] = array(
                    'type'     => 'error',
                    'code'     => 'address_undeliverable',
                    'path'     => '$.fulfillment.methods[0]',
                    'content'  => 'No fulfillment is available for the destination.',
                    'severity' => 'recoverable',
                );
            } else {
                foreach ( $method['groups'] as $index => $group ) {
                    if ( empty( $group['options'] ) ) {
                        $missing[] = array(
                            'type'     => 'error',
                            'code'     => 'address_undeliverable',
                            'path'     => sprintf( '$.fulfillment.methods[0].groups[%d]', $index ),
                            'content'  => 'No shipping options are available for the destination.',
                            'severity' => 'recoverable',
                        );
                    } elseif ( empty( $group['selected_option_id'] ) ) {
                        $missing[] = array(
                            'type'     => 'error',
                            'code'     => 'missing',
                            'path'     => sprintf( '$.fulfillment.methods[0].groups[%d].selected_option_id', $index ),
                            'content'  => 'A fulfillment option must be selected.',
                            'severity' => 'recoverable',
                        );
                    }
                }
            }
        }

        return $missing;
    }

    /**
     * Record a terminal status for the session (completed / canceled).
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $status             Terminal status.
     * @param array  $order_confirmation Optional UCP order_confirmation object.
     */
    public function set_terminal_status( $status, array $order_confirmation = array() ) {

        WC()->session->set(
            self::SESSION_TERMINAL,
            array(
                'status' => $status,
                'order'  => $order_confirmation,
            )
        );
        WC()->session->set( self::SESSION_ESCALATION, null );
        WC()->session->save_data();
    }

    /**
     * Get the recorded terminal status, if any.
     *
     * @since 13.6.0
     * @access public
     *
     * @return string Terminal status, empty string when none.
     */
    public function get_terminal_status() {

        $terminal = WC()->session->get( self::SESSION_TERMINAL );

        return is_array( $terminal ) ? (string) ( $terminal['status'] ?? '' ) : '';
    }

    /**
     * Record a payment escalation (e.g. 3DS) for the session.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $continue_url       URL for checkout handoff.
     * @param array  $order_confirmation Optional UCP order_confirmation object.
     */
    public function set_escalation( $continue_url, array $order_confirmation = array() ) {

        WC()->session->set(
            self::SESSION_ESCALATION,
            array(
                'continue_url' => $continue_url,
                'order'        => $order_confirmation,
            )
        );
        WC()->session->save_data();
    }

    /**
     * Persist cart, customer and session after a mutation.
     *
     * @since 13.6.0
     * @access private
     */
    private function persist() {

        // Recalculation also persists the cart to the session via
        // WC_Cart_Session::set_session() (hooked on woocommerce_after_calculate_totals).
        WC()->cart->calculate_totals();
        WC()->customer->save();
        WC()->session->save_data();
    }

    /**
     * Collect queued WooCommerce error notices into a single string.
     *
     * @since 13.6.0
     * @access private
     *
     * @param string $fallback Fallback text when no notices are queued.
     * @return string Notice text.
     */
    private function consume_error_notices( $fallback ) {

        $notices = wc_get_notices( 'error' );
        wc_clear_notices();

        $texts = array();
        foreach ( $notices as $notice ) {
            $texts[] = wp_strip_all_tags( (string) ( $notice['notice'] ?? '' ) );
        }

        $texts = array_filter( $texts );

        return $texts ? implode( ' ', $texts ) : $fallback;
    }

    /**
     * Convert a major-unit amount to ISO 4217 minor units.
     *
     * @since 13.6.0
     * @access public
     *
     * @param float  $amount   Major-unit amount.
     * @param string $currency Currency code; defaults to the store currency.
     * @return int Minor units.
     */
    public static function to_minor( $amount, $currency = '' ) {

        $currency = '' !== $currency ? strtoupper( $currency ) : get_woocommerce_currency();

        $zero_decimal  = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
        $three_decimal = array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' );

        if ( in_array( $currency, $zero_decimal, true ) ) {
            $exponent = 0;
        } elseif ( in_array( $currency, $three_decimal, true ) ) {
            $exponent = 3;
        } else {
            $exponent = 2;
        }

        /**
         * Filter the ISO 4217 exponent used for minor-unit conversion.
         *
         * @since 13.6.0
         *
         * @param int    $exponent Exponent.
         * @param string $currency Currency code.
         */
        $exponent = (int) apply_filters( 'adt_ucp_currency_exponent', $exponent, $currency );

        return (int) round( (float) $amount * pow( 10, $exponent ) );
    }
}
