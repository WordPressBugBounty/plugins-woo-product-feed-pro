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
     * Whether the last feed batch query was paged by the keyset cursor.
     *
     * The decision is made per query by self::apply_batch_cursor(), and can only be
     * made once the query's final ORDER BY is known - which is after the producer has
     * built its arguments. The producer resets this before the query and reads it
     * back afterwards, so both sides agree on which pagination mechanism ran.
     *
     * @since 13.5.8
     * @access private
     *
     * @var bool
     */
    private static $cursor_applied = false;

    /**
     * Forget which pagination mechanism the previous query used.
     *
     * Called by the producer immediately before each batch query.
     *
     * @since 13.5.8
     * @access public
     */
    public static function reset_cursor_state() {
        self::$cursor_applied = false;
    }

    /**
     * Whether the batch query that just ran was paged by the keyset cursor.
     *
     * @since 13.5.8
     * @access public
     *
     * @return bool
     */
    public static function cursor_was_applied() {
        return self::$cursor_applied;
    }

    /**
     * Whether a query is still sorted in the order the keyset cursor is built on.
     *
     * Compared against the ORDER BY the query will actually run with, not against
     * the `orderby` query argument. Those two are not the same thing: any plugin
     * may re-sort a query through `posts_orderby` or `posts_clauses` without ever
     * touching the argument, and a keyset clause applied to rows in another order
     * skips rows and repeats others.
     *
     * The match is deliberately exact (whitespace and case aside). Anything else
     * falls back to LIMIT/OFFSET paging, which is correct in any sort order.
     *
     * @since 13.5.8
     * @access private
     *
     * @param string $orderby The ORDER BY clause, without the ORDER BY keyword.
     * @return bool
     */
    private static function is_keyset_orderby( $orderby ) {
        global $wpdb;

        $expected = sprintf( '%1$s.post_date DESC, %1$s.ID DESC', $wpdb->posts );
        $actual   = preg_replace( '/\s+/', ' ', trim( (string) $orderby ) );

        return 0 === strcasecmp( $actual, $expected );
    }

    /**
     * The same LIMIT clause with its offset removed, or null if it cannot be read.
     *
     * The cursor and LIMIT/OFFSET are two ways of expressing the same resume point,
     * so exactly one of them may be in play: a query carrying both skips the offset
     * twice. The producer always asks for the offset, and this drops it from the
     * clause on the queries where the cursor takes over.
     *
     * Returning null means the clause is one this cannot reason about, because another
     * plugin rebuilt it, and the caller then leaves the query on LIMIT/OFFSET rather
     * than guessing. An unlimited query carries no offset to remove, so it is returned
     * as it is rather than refused.
     *
     * @since 13.5.8
     * @access private
     *
     * @param string $limits The LIMIT clause.
     * @return string|null
     */
    private static function limits_without_offset( $limits ) {
        $limits = trim( (string) $limits );

        if ( '' === $limits ) {
            return $limits;
        }

        if ( preg_match( '/^LIMIT\s+\d+\s*,\s*(\d+)$/i', $limits, $matches ) ) {
            return 'LIMIT 0, ' . (int) $matches[1];
        }

        if ( preg_match( '/^LIMIT\s+\d+$/i', $limits ) ) {
            return $limits;
        }

        return null;
    }

    /**
     * The same ORDER BY clause with a deterministic tie-break appended.
     *
     * A batch that cannot use the cursor pages by LIMIT/OFFSET instead, and that is
     * only stable while every batch of the run sees rows in the same order. An order
     * with ties - `menu_order ASC`, where most rows hold the same value - does not
     * guarantee that: MySQL may return a tie group in one order for one batch and
     * another order for the next, so a row lands in both batches or in neither.
     *
     * Appending the post ID makes the order total without changing the sort the
     * caller asked for: it only settles rows that were already tied, and only on the
     * feed's own batch query.
     *
     * @since 13.5.8
     * @access private
     *
     * @param string $orderby The ORDER BY clause, without the ORDER BY keyword.
     * @return string
     */
    private static function with_id_tiebreak( $orderby ) {
        global $wpdb;

        $orderby  = trim( (string) $orderby );
        $tiebreak = "{$wpdb->posts}.ID DESC";

        // Already broken by ID, or ordered by nothing at all - in which case the ID
        // is the whole order, since paging an unordered query is the same hazard.
        if ( '' === $orderby ) {
            return $tiebreak;
        }

        if ( preg_match( '/\bID\b/i', $orderby ) ) {
            return $orderby;
        }

        return $orderby . ', ' . $tiebreak;
    }

    /**
     * Resume a batched feed query after the last row of the previous batch.
     *
     * Feed generation walks the catalogue in (post_date DESC, ID DESC) order. Instead of
     * paging with LIMIT/OFFSET - which is not stable when rows share a post_date, as
     * bulk-created variations do - each batch carries the sort key of the last row it
     * walked and this clause skips everything up to and including that row.
     *
     * Hooked on `posts_clauses_request`, the last filter WP_Query runs before it
     * assembles the SQL, because that is the first point at which the ORDER BY the
     * query will really use is known. Deciding earlier - from the `orderby` query
     * argument - reads only what the feed asked for, and any plugin that re-sorts
     * the query through `posts_orderby` or `posts_clauses` leaves that argument
     * untouched. The keyset clause then ran against rows in a different order and
     * batches silently skipped rows.
     *
     * When the order is not the one the cursor is built on, this instead makes that
     * order total - see self::with_id_tiebreak() - so the LIMIT/OFFSET paging the run
     * falls back to is stable across its batches.
     *
     * @since 13.5.7
     * @since 13.5.8 Moved to `posts_clauses_request` and gated on the query's real
     *               ORDER BY, so a re-sorted query keeps LIMIT/OFFSET paging - with a
     *               tie-break added so that paging is stable.
     * @access public
     *
     * @param array     $clauses The query clauses.
     * @param \WP_Query $query   The query object.
     * @return array
     */
    public function apply_batch_cursor( $clauses, $query ) {
        global $wpdb;

        // Only apply this filter for our specific query.
        if ( 'adt_published_products_and_variations' !== $query->get( 'custom_query' ) ) {
            return $clauses;
        }

        $orderby = isset( $clauses['orderby'] ) ? $clauses['orderby'] : '';

        // Something re-ordered the query, so the cursor cannot be used and every batch
        // of this run pages by LIMIT/OFFSET instead. That needs a total order to be
        // stable across batches, which the new order is not guaranteed to be.
        if ( ! self::is_keyset_orderby( $orderby ) ) {
            $clauses['orderby'] = self::with_id_tiebreak( $orderby );

            return $clauses;
        }

        $cursor = $query->get( self::BATCH_CURSOR_QUERY_ARG );

        if ( ! self::is_valid_cursor( $cursor ) ) {
            return $clauses;
        }

        $limits = self::limits_without_offset( isset( $clauses['limits'] ) ? $clauses['limits'] : '' );

        if ( null === $limits ) {
            return $clauses;
        }

        $clauses['where'] .= $wpdb->prepare(
            " AND (
                {$wpdb->posts}.post_date < %s OR
                ({$wpdb->posts}.post_date = %s AND {$wpdb->posts}.ID < %d)
            )",
            $cursor['date'],
            $cursor['date'],
            $cursor['id']
        );

        $clauses['limits'] = $limits;

        self::$cursor_applied = true;

        return $clauses;
    }

    /**
     * Run the class
     *
     * @codeCoverageIgnore
     * @since 13.3.9
     */
    public function run() {
        add_filter( 'posts_where', array( $this, 'only_include_published_parent_variations' ), 10, 2 );
        add_filter( 'posts_clauses_request', array( $this, 'apply_batch_cursor' ), PHP_INT_MAX, 2 );
    }
}
