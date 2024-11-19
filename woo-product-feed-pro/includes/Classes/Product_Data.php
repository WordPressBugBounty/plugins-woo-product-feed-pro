<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Traits\Singleton_Trait;

/**
 * Product_Data class.
 *
 * @since 13.3.9
 */
class Product_Data extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Only include published parent variations.
     *
     * This method is used to exclude parent variations that is not published from the product feed with the custom query.
     *
     * @since 13.3.9
     * @access public
     *
     * @param string $where The where clause.
     * @param object $query The query object.
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
     * Run the class
     *
     * @codeCoverageIgnore
     * @since 13.3.9
     */
    public function run() {
        add_filter( 'posts_where', array( $this, 'only_include_published_parent_variations' ), 10, 2 );
    }
}
