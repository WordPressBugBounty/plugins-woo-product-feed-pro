<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes\Feeds
 */

namespace AdTribes\PFP\Classes\Feeds;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Traits\Singleton_Trait;
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Classes\Filters;

/**
 * Google Product Review class.
 *
 * @since 13.4.5
 */
class Google_Product_Review extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Feed type.
     *
     * @since 13.4.5
     *
     * @var string
     */
    protected $feed_type = 'google_product_review';

    /**
     * Attributes.
     *
     * @since 13.4.5
     *
     * @var array
     */
    protected $review_attributes = array(
        'review_id',
        'reviewer_id',
        'review_ratings',
        'reviewer_name',
        'content',
        'review_reviever_image',
        'review_url',
        'title',
        'review_product_url',
    );

    /**
     * Construct.
     *
     * @since 13.4.5
     */
    public function __construct() {
    }

    /**
     * Add google product review attributes to filters and rules.
     *
     * @since 13.4.5
     *
     * @param array  $attributes The attributes.
     * @param string $channel_type The channel type.
     * @return array
     */
    public function get_attributes( $attributes, $channel_type ) {
        if ( $this->feed_type !== $channel_type ) {
            return $attributes;
        }

        // Add reviews attribute.
        $attributes['Google Product Review'] = array(
            'review_id'             => __( 'Review ID', 'woo-product-feed-pro' ),
            'reviewer_id'           => __( 'Reviewer ID', 'woo-product-feed-pro' ),
            'review_ratings'        => __( 'Review rating', 'woo-product-feed-pro' ),
            'reviewer_name'         => __( 'Reviewer name', 'woo-product-feed-pro' ),
            'content'               => __( 'Review content', 'woo-product-feed-pro' ),
            'review_reviever_image' => __( 'Reviewer image', 'woo-product-feed-pro' ),
            'review_url'            => __( 'Review URL', 'woo-product-feed-pro' ),
            'title'                 => __( 'Product title', 'woo-product-feed-pro' ),
            'review_product_url'    => __( 'Product URL', 'woo-product-feed-pro' ),
        );
        return $attributes;
    }

    /**
     * Maybe skip filter.
     * For google product review, we only want to filter the reviews.
     * Due to the way the filter is applied, we need to skip the filter for other attributes.
     *
     * @since 13.4.5
     *
     * @param bool   $skipped The skipped value.
     * @param array  $filter The filter criteria.
     * @param array  $data The data to filter.
     * @param object $feed The feed.
     * @return bool
     */
    public function maybe_skip_filter( $skipped, $filter, $data, $feed ) {
        if ( $this->feed_type !== $feed->get_channel( 'fields' ) ) {
            return $skipped;
        }

        if ( in_array( $filter['attribute'], $this->review_attributes, true ) ) {
            return true;
        }

        return $skipped;
    }

    /**
     * Filter product feed data value.
     *
     * @since 13.4.5
     *
     * @param array        $data The data.
     * @param array        $filters The filters.
     * @param Product_Feed $feed The feed.
     * @return string
     */
    public function filter_product_feed_data_value( $data, $filters, $feed ) {
        if ( empty( $data ) || empty( $filters ) ) {
            return $data;
        }

        $review_data = $data['reviews'] ?? array();
        if ( empty( $review_data ) ) {
            return $data;
        }

        // Get the filters instance.
        $filters_instance = Filters::instance();

        foreach ( $filters as $filter ) {
            $filter_attribute = $filter['attribute'] ?? '';
            if ( ! in_array( $filter_attribute, $this->review_attributes, true ) ) {
                continue;
            }

            foreach ( $review_data as $index => $review ) {
                if ( ! isset( $review[ $filter_attribute ] ) ) {
                    continue;
                }

                // Process the filter based on whether the value is an array or not.
                $filter_passed = $filters_instance->process_filter_value( $review[ $filter_attribute ], $filter, $feed );

                // If this filter didn't pass, mark the review as not passing.
                if ( ! $filter_passed ) {
                    unset( $review_data[ $index ] );
                }
            }
        }

        // Update the reviews data after filtering.
        $data['reviews'] = $review_data;

        return $data;
    }

    /**
     * Run the class.
     *
     * @since 13.4.5
     */
    public function run() {
        add_filter( 'adt_pfp_get_filters_rules_attributes', array( $this, 'get_attributes' ), 10, 2 );
        add_filter( 'adt_pfp_maybe_skip_filter', array( $this, 'maybe_skip_filter' ), 10, 4 );
        add_filter( 'adt_pfp_filter_product_feed_data', array( $this, 'filter_product_feed_data_value' ), 10, 3 );
    }
}
