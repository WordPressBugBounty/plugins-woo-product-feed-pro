<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Integrations
 */

namespace AdTribes\PFP\Integrations;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Classes\Shipping_Data;
use AdTribes\PFP\Helpers\Helper;

/**
 * WC_Vendors class.
 *
 * On a multi-vendor marketplace each vendor configures their own shipping, but
 * WC Vendors Pro registers that as a standalone `WC_Shipping_Method`
 * (`wcv_pro_vendor_shipping`) instead of adding it to a WooCommerce shipping
 * zone. Shipping_Data only walks `WC_Shipping_Zones`, so vendor rates are
 * invisible to it and the feed advertises the marketplace's own zone rates —
 * which is not what the shopper is charged, and gets products disapproved in
 * Google Merchant Center.
 *
 * This integration prices a vendor-owned product with the vendor's own method
 * and mirrors what happens at checkout: WC Vendors hides `flat_rate` rates once
 * a vendor rate is present, so the store's flat rates are dropped from the feed
 * too, while free shipping and local pickup are left alone.
 *
 * Rates are produced by handing the feed's package to WC Vendors'
 * `calculate_shipping()`, so their flat / country table / tiered logic,
 * min and max charges, free-shipping thresholds, quantity overrides and
 * handling fees all apply without being re-implemented here.
 *
 * @since 13.6.0
 */
class WC_Vendors extends Abstract_Class {

    /**
     * Cache key for the memoized vendor rows, `<product id>:<feed id>`.
     *
     * @since 13.6.0
     * @access private
     *
     * @var string
     */
    private $_rows_cache_key = '';

    /**
     * Memoized vendor shipping rows for $_rows_cache_key.
     *
     * Both filters this class registers need the same rows for the same
     * product, and building them prices the product against every destination
     * country, so the result is kept for the product currently being exported.
     *
     * @since 13.6.0
     * @access private
     *
     * @var array
     */
    private $_rows_cache = array();

    /**
     * Check if WC Vendors is active.
     *
     * The vendor shipping method ships with WC Vendors Pro; the free plugin
     * only provides the vendor lookup, so both are required. Checked by plugin
     * file rather than by class, because hooks are registered before WC Vendors
     * has loaded its classes.
     *
     * @since 13.6.0
     * @return bool
     */
    public function is_active() {
        return Helper::is_plugin_active( 'wc-vendors/class-wc-vendors.php' )
            && Helper::is_plugin_active( 'wc-vendors-pro/wcvendors-pro.php' );
    }

    /**
     * Drop the marketplace's own flat rates from a vendor-owned product.
     *
     * Mirrors WC Vendors' `hide_flat_rate_if_package_has_vendor_shipping()`,
     * which removes `flat_rate` rates at checkout whenever the vendor method
     * returns one. Free shipping and local pickup are left in place, matching
     * checkout. If the vendor yields no rates at all the store rate is kept, so
     * a product never ends up with no shipping information.
     *
     * @since 13.6.0
     * @access public
     *
     * @uses adt_wc_vendors_drop_store_flat_rate Return false from this filter to
     *       keep the store rate and advertise it alongside the vendor rate.
     *
     * @param array             $shipping The shipping data row.
     * @param \WC_Shipping_Rate $rate     The shipping rate object.
     * @param object            $feed     The feed object.
     * @param array             $package  The package data containing product information.
     * @return array The row, or an empty array to drop it from the feed.
     */
    public function drop_store_flat_rate_for_vendor_products( $shipping, $rate, $feed, $package ) {
        if ( ! is_a( $rate, 'WC_Shipping_Rate' ) || 'flat_rate' !== $rate->get_method_id() ) {
            return $shipping;
        }

        $product = $package['contents'][0]['data'] ?? null;
        if ( ! is_a( $product, 'WC_Product' ) ) {
            return $shipping;
        }

        /**
         * Filter whether the marketplace's own flat rates are dropped from
         * vendor-owned products.
         *
         * Defaults to true to match checkout, where WC Vendors hides flat rates
         * once the vendor method returns a rate. Return false to advertise both
         * the store rate and the vendor rate.
         *
         * @since 13.6.0
         *
         * @param bool        $drop    Whether to drop the store flat rate. Default true.
         * @param \WC_Product $product The product object.
         * @param object      $feed    The feed object.
         * @return bool
         */
        if ( ! apply_filters( 'adt_wc_vendors_drop_store_flat_rate', true, $product, $feed ) ) {
            return $shipping;
        }

        // Only drop the store rate when the vendor actually replaces it.
        return empty( $this->_get_vendor_shipping_rows( $product, $feed, $package ) ) ? $shipping : array();
    }

    /**
     * Add the vendor's own shipping rates to a vendor-owned product.
     *
     * @since 13.6.0
     * @access public
     *
     * @param array       $shipping_data The shipping data.
     * @param \WC_Product $product      The product object.
     * @param object      $feed          The feed object.
     * @param array       $package       The package data built for this product.
     * @return array
     */
    public function add_vendor_shipping_data( $shipping_data, $product, $feed, $package = array() ) {
        if ( ! is_a( $product, 'WC_Product' ) || empty( $package ) ) {
            return $shipping_data;
        }

        $vendor_rows = $this->_get_vendor_shipping_rows( $product, $feed, $package );

        // The rows are only needed while this product is being exported. This is
        // a memory-retention courtesy, not a correctness guard: the key check in
        // `_get_vendor_shipping_rows()` already stops one product from reading
        // another's rows, so do not remove that check on the strength of this
        // clear (which is skipped if this callback is short-circuited).
        $this->_rows_cache_key = '';
        $this->_rows_cache     = array();

        return empty( $vendor_rows ) ? $shipping_data : array_merge( $shipping_data, $vendor_rows );
    }

    /**
     * Get the vendor shipping rows for a product, building them once per product.
     *
     * The key is per product, and deliberately so: the rows are NOT shared
     * between the variations of one parent. WC Vendors prices each exported
     * product against its own price and its own `_wcv_shipping_details` meta, so
     * two variations of the same parent can legitimately get different rates —
     * a percentage handling fee scales with the variation price, and a
     * free-shipping-over-X threshold is met by one variation and not another.
     * Keying on the parent ID to make siblings share a build would report the
     * wrong rate for every sibling but the first one.
     *
     * @since 13.6.0
     * @access private
     *
     * @param \WC_Product $product The product object.
     * @param object      $feed    The feed object.
     * @param array       $package The package data built for this product.
     * @return array
     */
    private function _get_vendor_shipping_rows( $product, $feed, $package ) {
        $cache_key = $product->get_id() . ':' . ( $feed->id ?? 0 );
        if ( $cache_key !== $this->_rows_cache_key ) {
            // Assigned only once the rows are built, so the cache never holds
            // another product's rows under this key.
            $rows                  = $this->_build_vendor_shipping_rows( $product, $feed, $package );
            $this->_rows_cache_key = $cache_key;
            $this->_rows_cache     = $rows;
        }

        return $this->_rows_cache;
    }

    /**
     * Build the vendor shipping rows for a product.
     *
     * @since 13.6.0
     * @access private
     *
     * @param \WC_Product $product The product object.
     * @param object      $feed    The feed object.
     * @param array       $package The package data built for this product.
     * @return array
     */
    private function _build_vendor_shipping_rows( $product, $feed, $package ) {
        $vendor_id = $this->_get_vendor_id( $product );
        if ( ! $vendor_id ) {
            return array();
        }

        $method = $this->_get_vendor_shipping_method();
        if ( ! $method ) {
            return array();
        }

        /** This filter is documented in includes/Classes/Shipping_Data.php */
        $shipping_currency = apply_filters( 'adt_product_feed_shipping_cost_currency', get_woocommerce_currency(), $feed );

        // WC Vendors resolves the vendor from the package contents. The zone
        // name is left empty because WC Vendors already names the vendor in its
        // own rate label, which Shipping_Data appends the zone name to.
        $package['vendor_id'] = $vendor_id;
        $shipping_zone        = array( 'zone_name' => '' );
        $shipping_data        = Shipping_Data::instance();
        $rows                 = array();

        foreach ( $this->_get_destination_countries( $feed ) as $country_code ) {
            $package['destination']['country']  = $country_code;
            $package['destination']['state']    = '';
            $package['destination']['postcode'] = '';

            $zone = array(
                'country'  => $country_code,
                'region'   => '',
                'postcode' => '',
            );

            // The method is reused across destinations, so clear the rates it
            // collected for the previous country before pricing the next one.
            $method->rates = array();

            // `_get_shipping_method_data()` is public by contract: it is the one
            // seam that prices a shipping method that lives outside the zone
            // walk. Keep it callable from here if it is ever refactored.
            foreach ( $shipping_data->_get_shipping_method_data( $method, $shipping_zone, $package, $zone, $shipping_currency, $feed ) as $row ) {
                // Drop the leading space left by the empty zone name.
                if ( isset( $row['service'] ) ) {
                    $row['service'] = trim( $row['service'] );
                }

                $rows[] = $row;
            }
        }

        /**
         * Filter the vendor shipping rows added to a product.
         *
         * @since 13.6.0
         *
         * @param array       $rows      The vendor shipping rows.
         * @param \WC_Product $product   The product object.
         * @param int         $vendor_id The vendor user ID.
         * @param object      $feed      The feed object.
         * @return array
         */
        return apply_filters( 'adt_wc_vendors_shipping_rows', $rows, $product, $vendor_id, $feed );
    }

    /**
     * Get the enabled WC Vendors shipping method.
     *
     * Taken from WooCommerce's own registry rather than instantiated directly,
     * because that is what loads the WC Vendors method class and applies its
     * saved settings.
     *
     * @since 13.6.0
     * @access private
     *
     * @return \WC_Shipping_Method|null The method, or null when it is unavailable or disabled.
     */
    private function _get_vendor_shipping_method() {
        $methods = WC()->shipping()->get_shipping_methods();
        $method  = $methods['wcv_pro_vendor_shipping'] ?? null;

        return $method && $method->is_enabled() ? $method : null;
    }

    /**
     * Get the vendor that owns a product.
     *
     * `WCV_Vendors::get_vendor_from_product()` resolves variations to their
     * parent and returns the post author, which is the marketplace admin for
     * products the store owns, so the result is confirmed to be a vendor.
     *
     * @since 13.6.0
     * @access private
     *
     * @param \WC_Product $product The product object.
     * @return int The vendor user ID, or 0 when the product is not owned by a vendor.
     */
    private function _get_vendor_id( $product ) {
        if ( ! class_exists( 'WCV_Vendors' ) ) {
            return 0;
        }

        $vendor_id = (int) \WCV_Vendors::get_vendor_from_product( $product->get_id() );

        return \WCV_Vendors::is_vendor( $vendor_id ) ? $vendor_id : 0;
    }

    /**
     * Get the destination countries to price the vendor's shipping against.
     *
     * Follows the same rules Shipping_Data applies to zone-based methods: the
     * feed country, or every shipping country when the feed asks for all of
     * them. Vendor rates are national/international relative to the vendor's
     * store, so a destination is always needed — when the feed has no country
     * the store's base country is used rather than emitting a country-less row.
     *
     * Keep in sync with `Shipping_Data::get_shipping_data()`, which reads the
     * same `include_all_shipping_countries` flag and `adt_add_all_shipping`
     * option to decide the zone countries it walks.
     *
     * @since 13.6.0
     * @access private
     *
     * @param object $feed The feed object.
     * @return array
     */
    private function _get_destination_countries( $feed ) {
        $feed_country = $feed->country ?? '';

        $include_all_shipping_countries = true === $feed->include_all_shipping_countries || 'yes' === $feed->include_all_shipping_countries;
        $all_countries                  = 'yes' === get_option( 'adt_add_all_shipping', 'no' )
            || ( empty( $feed_country ) && $include_all_shipping_countries );

        if ( $all_countries ) {
            /** This filter is documented in includes/Classes/Shipping_Data.php */
            $shipping_countries = apply_filters( 'adt_product_feed_shipping_countries', WC()->countries->get_shipping_countries(), $feed );

            return array_keys( (array) $shipping_countries );
        }

        return array( ! empty( $feed_country ) ? $feed_country : WC()->countries->get_base_country() );
    }

    /**
     * Run WC Vendors integration hooks.
     *
     * @codeCoverageIgnore
     * @since 13.6.0
     */
    public function run() {
        if ( ! $this->is_active() ) {
            return;
        }

        // Both priorities are deliberate, relative to Elite's callbacks on the
        // same two filters. Elite registers `add_shipping_times_to_array` and
        // `maybe_keep_cheapest_shipping_per_destination` at priority 10 (see
        // woo-product-feed-elite/includes/Classes/Shipping_Data.php), so these
        // two must straddle it.
        //
        // Dropping runs last so it wins: Elite's `add_shipping_times_to_array`
        // also targets flat rates, and would otherwise repopulate a row this
        // integration had emptied, leaving a row of transit times and no price.
        //
        // Adding runs first so the vendor rates are already present when
        // Elite's `maybe_keep_cheapest_shipping_per_destination` picks the
        // cheapest rate per destination.
        add_filter( 'adt_product_feed_shipping_array', array( $this, 'drop_store_flat_rate_for_vendor_products' ), 20, 4 );
        add_filter( 'adt_product_feed_shipping_data', array( $this, 'add_vendor_shipping_data' ), 9, 4 );
    }
}
