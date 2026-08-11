<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Helpers
 */

namespace AdTribes\PFP\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Enforces minimum image dimensions on feeds that require them
 * (Google Shopping and Facebook Catalog require at least 500x500 px).
 *
 * @since 13.5.5
 */
class Image_Size_Validator {

    /**
     * Channel `fields` keys that this validator applies to by default.
     *
     * Google Merchant Center and Facebook/Meta Catalog both reject product
     * images smaller than 500x500 for non-apparel categories.
     */
    const DEFAULT_CHANNELS = array( 'google_shopping', 'facebook_drm' );

    /**
     * Default minimum width and height in pixels.
     */
    const DEFAULT_MIN_WIDTH  = 500;
    const DEFAULT_MIN_HEIGHT = 500;

    /**
     * Whether the minimum image size validation applies to the given feed channel.
     *
     * Opt-in by default. Merchants enable it from
     * Product Feed Pro → Settings → General → "Enforce minimum 500x500 image size
     * on Google Shopping and Facebook Catalog feeds" (option key
     * `adt_pfp_enable_image_size_validation`). Developers may force the state via
     * the `adt_pfp_image_size_validation_enabled` filter, which takes priority
     * over the admin setting.
     *
     * @param array $feed_channel The feed channel array (must contain `fields`).
     * @return bool
     */
    public static function applies_to_channel( $feed_channel ) {
        if ( ! is_array( $feed_channel ) || empty( $feed_channel['fields'] ) ) {
            return false;
        }

        // Default the filter value to the admin setting so the checkbox on the
        // settings page is the canonical user-facing toggle. The filter still wins
        // for developers who need to force on/off from code.
        $option_enabled = 'yes' === get_option( 'adt_pfp_enable_image_size_validation' );

        /**
         * Filter whether minimum image size validation is enabled globally.
         *
         * Shipping opt-in by default so existing Google Shopping / Facebook Catalog
         * feeds keep their current behaviour after an auto-update. The default
         * passed here reflects the admin checkbox at
         * Settings → General → "Enforce minimum 500x500 image size...".
         * Returning a hardcoded boolean from a filter callback will override the
         * admin setting in both directions.
         *
         * @since 13.5.5
         *
         * @param bool $enabled Default: the admin setting value.
         */
        if ( ! apply_filters( 'adt_pfp_image_size_validation_enabled', $option_enabled ) ) {
            return false;
        }

        /**
         * Filter the list of channel `fields` keys that should enforce the minimum image size.
         *
         * @since 13.5.5
         *
         * @param array $channels     Channel `fields` keys. Default Google Shopping + Facebook Catalog.
         * @param array $feed_channel The feed channel array.
         */
        $channels = apply_filters(
            'adt_pfp_image_size_validation_channels',
            self::DEFAULT_CHANNELS,
            $feed_channel
        );

        return in_array( $feed_channel['fields'], (array) $channels, true );
    }

    /**
     * Minimum image dimensions for the given feed channel.
     *
     * @param array $feed_channel The feed channel array.
     * @return array { width: int, height: int }
     */
    public static function get_min_dimensions( $feed_channel ) {
        $defaults = array(
            'width'  => self::DEFAULT_MIN_WIDTH,
            'height' => self::DEFAULT_MIN_HEIGHT,
        );

        /**
         * Filter the minimum image dimensions (in pixels) required for products
         * included in Google Shopping / Facebook Catalog feeds.
         *
         * @since 13.5.5
         *
         * @param array $dimensions   { width: int, height: int }.
         * @param array $feed_channel The feed channel array.
         */
        $dimensions = apply_filters( 'adt_pfp_image_size_min_dimensions', $defaults, $feed_channel );

        return array(
            'width'  => isset( $dimensions['width'] ) ? (int) $dimensions['width'] : self::DEFAULT_MIN_WIDTH,
            'height' => isset( $dimensions['height'] ) ? (int) $dimensions['height'] : self::DEFAULT_MIN_HEIGHT,
        );
    }

    /**
     * Whether the attachment meets the given minimum dimensions.
     *
     * Contract (stable — callers MAY rely on this behaviour):
     * - Attachment IDs `<= 0` are treated as failing the size check and
     *   return `false`. This is intentional so callers can pass a raw
     *   `get_post_thumbnail_id()` result (which returns `0` when no
     *   thumbnail exists) and let the same call site decide between the
     *   "image qualifies" and "fall back to a validated alternative"
     *   branches without re-implementing the zero-check.
     * - If WordPress has no dimension metadata for the attachment (e.g. SVG
     *   or a broken attachment), the image is treated as qualifying so
     *   sites with incomplete metadata are not silently dropped from the
     *   feed.
     *
     * @param int $attachment_id The attachment ID. `0` / negative IDs return false.
     * @param int $min_width     Minimum width in pixels.
     * @param int $min_height    Minimum height in pixels.
     * @return bool True when the attachment meets the threshold, false otherwise.
     */
    public static function attachment_meets_min_size( $attachment_id, $min_width, $min_height ) {
        $attachment_id = (int) $attachment_id;
        if ( $attachment_id <= 0 ) {
            return false;
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( ! is_array( $metadata ) || ! isset( $metadata['width'], $metadata['height'] ) ) {
            return true;
        }

        return (int) $metadata['width'] >= $min_width
            && (int) $metadata['height'] >= $min_height;
    }

    /**
     * Return the first attachment ID from the given list that meets the minimum dimensions.
     *
     * @param int[] $attachment_ids Ordered list of attachment IDs (primary first, then gallery).
     * @param int   $min_width      Minimum width in pixels.
     * @param int   $min_height     Minimum height in pixels.
     * @return int|null The qualifying attachment ID, or null if none qualifies.
     */
    public static function find_qualifying_attachment( array $attachment_ids, $min_width, $min_height ) {
        foreach ( $attachment_ids as $attachment_id ) {
            if ( self::attachment_meets_min_size( $attachment_id, $min_width, $min_height ) ) {
                return (int) $attachment_id;
            }
        }
        return null;
    }

    /**
     * Prime WP post + meta caches for a batch of products and their candidate images.
     *
     * Avoids the N+1 query pattern that would otherwise occur when each product
     * in a feed batch independently looks up its thumbnail + gallery attachment
     * metadata via `wp_get_attachment_metadata()`. Costs a handful of batched
     * queries instead of one per attachment.
     *
     * Post + meta caches are primed for the products themselves, their parents
     * (for variations), and every thumbnail / gallery attachment they reference,
     * since downstream callers read post objects (e.g. `wc_get_product`) as well
     * as meta inside the feed loop.
     *
     * @param int[] $product_ids Product/variation IDs for the current batch.
     */
    public static function prime_caches_for_products( array $product_ids ) {
        $product_ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ) ) ) );
        if ( empty( $product_ids ) ) {
            return;
        }

        // Prime product post + meta caches so thumbnail / gallery lookups and
        // downstream wc_get_product() calls inside the loop are served from cache.
        _prime_post_caches( $product_ids, false, true );

        $attachment_ids = array();
        $parent_ids     = array();
        foreach ( $product_ids as $pid ) {
            self::collect_candidate_attachment_ids( $pid, $attachment_ids );

            $parent_id = (int) wp_get_post_parent_id( $pid );
            if ( $parent_id > 0 ) {
                $parent_ids[] = $parent_id;
            }
        }

        // Variations also walk the parent product's images during validation —
        // prime the parent post + meta caches too so the downstream
        // wc_get_product( $parent_id ) call inside the loop is cached.
        if ( ! empty( $parent_ids ) ) {
            $parent_ids = array_values( array_unique( $parent_ids ) );
            _prime_post_caches( $parent_ids, false, true );
            foreach ( $parent_ids as $parent_id ) {
                self::collect_candidate_attachment_ids( $parent_id, $attachment_ids );
            }
        }

        $attachment_ids = array_values( array_unique( array_filter( $attachment_ids ) ) );
        if ( ! empty( $attachment_ids ) ) {
            _prime_post_caches( $attachment_ids, false, true );
        }
    }

    /**
     * Append a product's thumbnail + gallery attachment IDs to the given accumulator.
     *
     * @param int   $product_id     Product or variation ID.
     * @param int[] $attachment_ids Accumulator passed by reference.
     */
    private static function collect_candidate_attachment_ids( $product_id, array &$attachment_ids ) {
        $thumbnail_id = (int) get_post_thumbnail_id( $product_id );
        if ( $thumbnail_id > 0 ) {
            $attachment_ids[] = $thumbnail_id;
        }

        $gallery_meta = get_post_meta( $product_id, '_product_image_gallery', true );
        if ( empty( $gallery_meta ) ) {
            return;
        }

        foreach ( explode( ',', (string) $gallery_meta ) as $gallery_id ) {
            $gallery_id = (int) trim( $gallery_id );
            if ( $gallery_id > 0 ) {
                $attachment_ids[] = $gallery_id;
            }
        }
    }
}
