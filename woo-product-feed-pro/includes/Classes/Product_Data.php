<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Traits\Singleton_Trait;
use AdTribes\PFP\Helpers\Formatting;
use AdTribes\PFP\Classes\Product_Feed_Attributes;

/**
 * Product_Data class.
 *
 * @since 13.3.9
 */
class Product_Data extends Abstract_Class {

    use Singleton_Trait;

    /**
     * WP_Query argument carrying the batch resume point.
     *
     * Written by WooSEA_Get_Products::woosea_get_products() and read by
     * self::apply_batch_cursor(), so producer and consumer share one spelling and
     * cannot silently drift apart.
     *
     * @since 13.5.7
     * @access public
     *
     * @var string
     */
    const BATCH_CURSOR_QUERY_ARG = 'adt_batch_cursor';

    /**
     * The price fields.
     *
     * @since 13.4.1
     * @access public
     *
     * @var array
     */
    private $prices_attributes = array();

    /**
     * Constructor.
     *
     * @since 13.4.1
     * @access public
     */
    public function __construct() {
        $this->prices_attributes = Product_Feed_Attributes::instance()->prices_attributes;
    }

    /**
     * Localize prices.
     * This method is used to localize the prices in the product data.
     *
     * @since 13.4.1
     * @access public
     *
     * @param array  $data The product data.
     * @param object $feed The feed object.
     * @return array
     */
    public function localize_prices( $data, $feed ) {
        foreach ( $this->prices_attributes as $price_key ) {
            if ( array_key_exists( $price_key, $data ) && is_numeric( $data[ $price_key ] ) ) {
                $data[ $price_key ] = Formatting::localize_price( $data[ $price_key ], array(), true, $feed );
            }
        }

        return apply_filters( 'adt_pfp_localize_prices_data', $data, $feed );
    }

    /**
     * Only include published parent variations.
     *
     * This method is used to exclude parent variations that is not published from the product feed with the custom query.
     *
     * @since 13.3.9
     * @access public
     *
     * @param string    $where The where clause.
     * @param \WP_Query $query The query object.
     * @return string
     */
    public function only_include_published_parent_variations( $where, $query ) {
        global $wpdb;

        // Only apply this filter for our specific query.
        if ( $query->get( 'custom_query' ) === 'adt_published_products_and_variations' ) {
            $where .= " AND (
                {$wpdb->posts}.post_type = 'product' OR 
                ({$wpdb->posts}.post_type = 'product_variation' AND {$wpdb->posts}.post_parent IN (
                    SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'
                ))
            )";
        }
        return $where;
    }

    /**
     * Whether a batch cursor carries a usable resume point.
     *
     * The producer (WooSEA_Get_Products::woosea_get_products()) and the consumer
     * (self::apply_batch_cursor()) both gate on this, so the { date, id } contract is
     * stated once and the two sites cannot drift apart if it ever grows a field.
     *
     * @since 13.5.7
     * @access public
     *
     * @param mixed $cursor The value to check.
     * @return bool
     */
    public static function is_valid_cursor( $cursor ) {
        return is_array( $cursor ) && ! empty( $cursor['date'] ) && ! empty( $cursor['id'] );
    }

    /**
     * Resume a batched feed query after the last row of the previous batch.
     *
     * Feed generation walks the catalogue in (post_date DESC, ID DESC) order. Instead of
     * paging with LIMIT/OFFSET - which is not stable when rows share a post_date, as
     * bulk-created variations do - each batch carries the sort key of the last row it
     * walked and this clause skips everything up to and including that row.
     *
     * @since 13.5.7
     * @access public
     *
     * @param string    $where The where clause.
     * @param \WP_Query $query The query object.
     * @return string
     */
    public function apply_batch_cursor( $where, $query ) {
        global $wpdb;

        // Only apply this filter for our specific query.
        if ( 'adt_published_products_and_variations' !== $query->get( 'custom_query' ) ) {
            return $where;
        }

        $cursor = $query->get( self::BATCH_CURSOR_QUERY_ARG );

        if ( ! self::is_valid_cursor( $cursor ) ) {
            return $where;
        }

        $where .= $wpdb->prepare(
            " AND (
                {$wpdb->posts}.post_date < %s OR
                ({$wpdb->posts}.post_date = %s AND {$wpdb->posts}.ID < %d)
            )",
            $cursor['date'],
            $cursor['date'],
            $cursor['id']
        );

        return $where;
    }

    /**
     * Run the class
     *
     * @codeCoverageIgnore
     * @since 13.3.9
     */
    public function run() {
        add_filter( 'posts_where', array( $this, 'only_include_published_parent_variations' ), 10, 2 );
        add_filter( 'posts_where', array( $this, 'apply_batch_cursor' ), 10, 2 );
    }
}
