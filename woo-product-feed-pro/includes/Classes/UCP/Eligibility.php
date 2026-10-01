<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Classes\UCP
 */

namespace AdTribes\PFP\Classes\UCP;

use AdTribes\PFP\Traits\Singleton_Trait;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * UCP product eligibility resolution.
 *
 * Decides whether a product may be sold through agentic checkout. The
 * same resolution runs at feed time (whether `native_commerce` is
 * emitted) and at checkout time (agents may hold stale feed data).
 *
 * Resolution order:
 *  1. Hard spec exclusions — categories UCP cannot sell (subscriptions,
 *     pre-orders, external products, …), each with a KB link explaining why.
 *  2. Purchasability — unpublished, non-purchasable or hidden products.
 *  3. Per-product merchant override (include/exclude meta).
 *  4. Default policy — conservative: products are NOT eligible unless
 *     opted in (rule-based bulk selection ships with the admin UI).
 *
 * @since 13.6.0
 */
class Eligibility {

    use Singleton_Trait;

    /**
     * Per-product override meta key. Values: 'include', 'exclude', or absent.
     *
     * @since 13.6.0
     * @var string
     */
    const META_KEY = '_adt_ucp_eligible';

    /**
     * Memoized hard-exclusion rules, null until first resolved.
     *
     * @since 13.6.0
     * @access private
     * @var array|null
     */
    private $hard_exclusions = null;

    /**
     * Resolve UCP eligibility for a product.
     *
     * @since 13.6.0
     * @access public
     *
     * @param WC_Product|int $product Product object or ID (variations resolve their own ID).
     * @return array {
     *     @type bool   $eligible Whether the product may be sold via UCP.
     *     @type string $reason   Machine-readable reason code.
     *     @type string $kb_url   KB article explaining the reason (hard exclusions only).
     * }
     */
    public function is_eligible( $product ) {

        if ( ! $product instanceof WC_Product ) {
            $product = wc_get_product( $product );
        }

        if ( ! $product instanceof WC_Product ) {
            return $this->result( false, 'not_found' );
        }

        // 1. Hard spec exclusions.
        $exclusion = $this->get_hard_exclusion( $product );
        if ( null !== $exclusion ) {
            return $this->result( false, $exclusion['reason'], $exclusion['kb_url'] );
        }

        // 2. Purchasability.
        if ( 'publish' !== $product->get_status() || ! $product->is_purchasable() ) {
            return $this->result( false, 'not_purchasable' );
        }

        // A variation's own post can stay `publish` while its parent is
        // draft/trash/hidden, so an agent passing a variation ID directly
        // could otherwise bypass the parent's state. Visibility is resolved
        // on the listable product (the parent for a variation). A `hidden`
        // product is purchasable but intentionally unlisted and must not
        // become agent-buyable via rules or an include override.
        $listable = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;

        if ( ! $listable instanceof WC_Product || 'publish' !== $listable->get_status() || 'hidden' === $listable->get_catalog_visibility() ) {
            return $this->result( false, 'not_purchasable' );
        }

        // 3. Per-product merchant override (variations inherit the parent override).
        $override = $this->get_override( $product );
        if ( 'exclude' === $override ) {
            return $this->result( false, 'merchant_excluded' );
        }
        if ( 'include' === $override ) {
            return $this->result( true, 'merchant_included' );
        }

        // 4. Merchant eligibility rules (match-all conditions).
        $conditions = $this->get_rules()['conditions'];
        if ( ! empty( $conditions ) ) {
            return $this->matches_rules( $product, $conditions )
                ? $this->result( true, 'rules_matched' )
                : $this->result( false, 'rules_not_matched' );
        }

        // 5. Conservative default: not eligible unless opted in.
        return $this->result( false, 'not_opted_in' );
    }

    /**
     * Get the merchant eligibility rules.
     *
     * Rules mark products eligible without per-product opt-in: when
     * conditions exist, a product (without an explicit override) is
     * eligible iff ALL conditions match.
     *
     * @since 13.6.0
     * @access public
     *
     * @return array {match: 'all', conditions: array{field, operator, value}[]}.
     */
    public function get_rules() {

        $rules = Settings::instance()->get( 'eligibility_rules' );

        return wp_parse_args(
            is_array( $rules ) ? $rules : array(),
            array(
                'match'      => 'all',
                'conditions' => array(),
            )
        );
    }

    /**
     * Save the merchant eligibility rules, sanitizing to the supported
     * condition vocabulary.
     *
     * @since 13.6.0
     * @access public
     *
     * @param array $rules Raw rules ({match, conditions}).
     * @return array The sanitized, saved rules.
     */
    public function save_rules( array $rules ) {

        $allowed = array(
            'stock_status' => array( 'is', 'is_not' ),
            'price'        => array( 'greater_than', 'less_than', 'at_least', 'at_most' ),
            'category'     => array( 'in', 'not_in' ),
        );

        $conditions = array();

        foreach ( (array) ( $rules['conditions'] ?? array() ) as $condition ) {
            $field    = sanitize_key( (string) ( $condition['field'] ?? '' ) );
            $operator = sanitize_key( (string) ( $condition['operator'] ?? '' ) );

            if ( ! isset( $allowed[ $field ] ) || ! in_array( $operator, $allowed[ $field ], true ) ) {
                continue;
            }

            if ( 'category' === $field ) {
                $value = array_values( array_filter( array_map( 'absint', (array) ( $condition['value'] ?? array() ) ) ) );
            } elseif ( 'price' === $field ) {
                $value = (string) (float) ( $condition['value'] ?? 0 );
            } else {
                $value = sanitize_key( (string) ( $condition['value'] ?? 'instock' ) );
            }

            $conditions[] = array(
                'field'    => $field,
                'operator' => $operator,
                'value'    => $value,
            );
        }

        $sanitized = array(
            'match'      => 'all',
            'conditions' => $conditions,
        );

        Settings::instance()->set( 'eligibility_rules', $sanitized );

        return $sanitized;
    }

    /**
     * Whether a product matches ALL rule conditions.
     *
     * @since 13.6.0
     * @access private
     *
     * @param WC_Product $product    Product.
     * @param array      $conditions Sanitized conditions.
     * @return bool True when every condition matches.
     */
    private function matches_rules( WC_Product $product, array $conditions ) {

        foreach ( $conditions as $condition ) {
            $field    = $condition['field'] ?? '';
            $operator = $condition['operator'] ?? '';
            $value    = $condition['value'] ?? '';

            if ( 'stock_status' === $field ) {
                $matches = $product->get_stock_status() === $value;
                if ( ( 'is' === $operator ) !== $matches ) {
                    return false;
                }
            } elseif ( 'price' === $field ) {
                $price  = (float) $product->get_price();
                $target = (float) $value;

                $matches = false;
                if ( 'greater_than' === $operator ) {
                    $matches = $price > $target;
                } elseif ( 'less_than' === $operator ) {
                    $matches = $price < $target;
                } elseif ( 'at_least' === $operator ) {
                    $matches = $price >= $target;
                } elseif ( 'at_most' === $operator ) {
                    $matches = $price <= $target;
                }

                if ( ! $matches ) {
                    return false;
                }
            } elseif ( 'category' === $field ) {
                // Variations carry no category terms; use the parent.
                $term_product_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
                $term_ids        = wc_get_product_term_ids( $term_product_id, 'product_cat' );
                $intersects      = (bool) array_intersect( array_map( 'absint', (array) $value ), $term_ids );

                if ( ( 'in' === $operator ) !== $intersects ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Set the per-product override.
     *
     * @since 13.6.0
     * @access public
     *
     * @param int    $product_id Product ID.
     * @param string $override   'include', 'exclude', or '' to clear.
     */
    public function set_override( $product_id, $override ) {

        if ( in_array( $override, array( 'include', 'exclude' ), true ) ) {
            update_post_meta( $product_id, self::META_KEY, $override );
        } else {
            delete_post_meta( $product_id, self::META_KEY );
        }
    }

    /**
     * Get the merchant override for a product, falling back to the parent
     * for variations without their own override.
     *
     * @since 13.6.0
     * @access public
     *
     * @param WC_Product $product Product.
     * @return string 'include', 'exclude', or ''.
     */
    public function get_override( WC_Product $product ) {

        $override = (string) get_post_meta( $product->get_id(), self::META_KEY, true );

        if ( '' === $override && $product->get_parent_id() ) {
            $override = (string) get_post_meta( $product->get_parent_id(), self::META_KEY, true );
        }

        return in_array( $override, array( 'include', 'exclude' ), true ) ? $override : '';
    }

    /**
     * Get the hard-exclusion rules.
     *
     * Each rule: reason code => array{types: string[], kb_url: string,
     * label: string}. A product whose type matches (substring match, so
     * `subscription` catches `variable-subscription`) is excluded.
     *
     * @since 13.6.0
     * @access public
     *
     * @return array Exclusion rules.
     */
    public function get_hard_exclusions() {

        // Resolved once per request: is_eligible() consults this for every
        // product in a feed run, and neither the translated labels nor the
        // filter output change mid-run.
        if ( null !== $this->hard_exclusions ) {
            return $this->hard_exclusions;
        }

        $exclusions = array(
            'external_product' => array(
                'types'       => array( 'external', 'grouped' ),
                'label'       => __( 'External and grouped products', 'woo-product-feed-pro' ),
                'description' => __( 'Externally sold and grouped products can’t be represented in the agent cart.', 'woo-product-feed-pro' ),
                'kb_url'      => 'https://adtribes.io/kb/ucp-ineligible-external-products/',
            ),
            'subscription'     => array(
                'types'       => array( 'subscription' ),
                'label'       => __( 'Subscriptions & recurring products', 'woo-product-feed-pro' ),
                'description' => __( 'Recurring billing is out of scope for UCP native checkout v1.', 'woo-product-feed-pro' ),
                'kb_url'      => 'https://adtribes.io/kb/ucp-ineligible-subscriptions/',
            ),
            'personalized'     => array(
                'types'       => array(),
                'label'       => __( 'Personalized / made-to-order', 'woo-product-feed-pro' ),
                'description' => __( 'Per-buyer customization can’t be represented in the agent cart.', 'woo-product-feed-pro' ),
                'kb_url'      => 'https://adtribes.io/kb/ucp-ineligible-personalized/',
            ),
            'booking'          => array(
                'types'       => array( 'booking', 'appointment' ),
                'label'       => __( 'Bookings and appointments', 'woo-product-feed-pro' ),
                'description' => __( 'Date and slot selection can’t be performed by agents in v1.', 'woo-product-feed-pro' ),
                'kb_url'      => 'https://adtribes.io/kb/ucp-ineligible-bookings/',
            ),
            'pre_order'        => array(
                'types'       => array( 'pre-order', 'preorder' ),
                'label'       => __( 'Pre-order & backorder items', 'woo-product-feed-pro' ),
                'description' => __( 'No confirmed availability at checkout time.', 'woo-product-feed-pro' ),
                'kb_url'      => 'https://adtribes.io/kb/ucp-ineligible-preorders/',
            ),
            'gift_card'        => array(
                'types'       => array( 'gift-card', 'pw-gift-card' ),
                'label'       => __( 'Gift cards & virtual goods', 'woo-product-feed-pro' ),
                'description' => __( 'Excluded by Merchant Center native-commerce policy.', 'woo-product-feed-pro' ),
                'kb_url'      => 'https://adtribes.io/kb/ucp-ineligible-gift-cards/',
            ),
            'name_your_price'  => array(
                'types'       => array(),
                'label'       => __( 'Name-your-price products', 'woo-product-feed-pro' ),
                'description' => __( 'Buyer-set pricing can’t be represented in the agent cart.', 'woo-product-feed-pro' ),
                'kb_url'      => 'https://adtribes.io/kb/ucp-ineligible-name-your-price/',
            ),
        );

        /**
         * Filter the UCP hard-exclusion rules.
         *
         * @since 13.6.0
         *
         * @param array $exclusions Reason code => rule.
         */
        $this->hard_exclusions = (array) apply_filters( 'adt_ucp_hard_exclusions', $exclusions );

        return $this->hard_exclusions;
    }

    /**
     * Match a product against the hard-exclusion rules.
     *
     * @since 13.6.0
     * @access private
     *
     * @param WC_Product $product Product.
     * @return array|null Matching rule with `reason` added, or null.
     */
    private function get_hard_exclusion( WC_Product $product ) {

        $type       = $product->get_type();
        $exclusions = $this->get_hard_exclusions();

        foreach ( $exclusions as $reason => $rule ) {
            foreach ( (array) ( $rule['types'] ?? array() ) as $excluded_type ) {
                if ( false !== strpos( $type, $excluded_type ) ) {
                    return array(
                        'reason' => $reason,
                        'kb_url' => $rule['kb_url'] ?? '',
                    );
                }
            }
        }

        // Name-your-price products have no dedicated type — detect by meta.
        if ( 'yes' === get_post_meta( $product->get_id(), '_nyp', true ) ) {
            return array(
                'reason' => 'name_your_price',
                'kb_url' => $exclusions['name_your_price']['kb_url'] ?? '',
            );
        }

        return null;
    }

    /**
     * Build a resolution result.
     *
     * @since 13.6.0
     * @access private
     *
     * @param bool   $eligible Whether the product is eligible.
     * @param string $reason   Reason code.
     * @param string $kb_url   KB article URL.
     * @return array Result.
     */
    private function result( $eligible, $reason, $kb_url = '' ) {

        $result = array(
            'eligible' => $eligible,
            'reason'   => $reason,
            'kb_url'   => $kb_url,
        );

        /**
         * Filter a UCP eligibility resolution.
         *
         * @since 13.6.0
         *
         * @param array $result Resolution result.
         */
        return (array) apply_filters( 'adt_ucp_is_product_eligible', $result );
    }
}
