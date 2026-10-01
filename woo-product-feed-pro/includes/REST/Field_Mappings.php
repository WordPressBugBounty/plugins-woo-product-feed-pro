<?php

namespace AdTribes\PFP\REST;

defined( 'ABSPATH' ) || exit;

use AdTribes\PFP\Abstracts\Abstract_REST;
use AdTribes\PFP\Classes\Feed_Attributes_Migrator;
use AdTribes\PFP\Classes\Product_Feed_Attributes;
use AdTribes\PFP\Classes\Sub_Attribute_Registry;
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Helpers\Product_Feed_Helper;
use AdTribes\PFP\Traits\Singleton_Trait;
use WP_REST_Response;
use WP_REST_Server;
use WP_REST_Request;

/**
 * Class Field_Mappings
 *
 * REST API endpoints for the field mapping builder: loads a feed's
 * `feed_attributes` (running the on-read migration when needed) together with
 * the channel attribute catalog, product fields, and sub-attribute registry.
 *
 * @since 13.5.8
 */
class Field_Mappings extends Abstract_REST {

    use Singleton_Trait;

    /**
     * Register the routes.
     *
     * @since 13.5.8
     */
    public function register_routes() {
        $this->rest_base = 'field-mappings';

        // Get field mappings for a specific feed.
        register_rest_route(
            $this->namespace,
            "/$this->rest_base/(?P<feed_id>[\w-]+)",
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_field_mappings' ),
                'permission_callback' => array( $this, 'get_item_permissions_check' ),
            )
        );
    }

    /**
     * Checks if a given request has access to read field mappings.
     *
     * SECURITY: overrides the always-true default in Abstract_REST, which would
     * otherwise leave this route reachable by unauthenticated visitors. The payload
     * exposes a feed's field mapping, channel attribute catalog and product fields,
     * so it is gated behind `manage_woocommerce` — the capability Abstract_REST
     * already requires for writes. Do not remove this method or relax it to
     * `__return_true`.
     *
     * @since 13.5.8
     *
     * @param WP_REST_Request $request Full data about the request.
     * @return bool True if the request has read access, false otherwise.
     */
    public function get_item_permissions_check( $request ) {
        return current_user_can( 'manage_woocommerce' );
    }

    /**
     * Get the field mappings payload for a feed.
     *
     * Returns the feed's `feed_attributes` rows (migrated on read when the
     * feed still holds legacy `attributes` data), the channel attribute
     * catalog composed via `adt_product_feed_attributes`, the product fields,
     * and the sub-attribute registry for the feed's channel.
     *
     * SECURITY: the on-read migration persists state (`$feed->save()`) from a
     * READABLE route, mirroring the accepted `Filters_Rules::maybe_run_migration()`
     * pattern. That write is only safe because it stays behind
     * `get_item_permissions_check()` above plus the REST nonce — #1035 must keep it
     * there and must never expose this route via `nopriv` or an application-password
     * bypass.
     *
     * @since 13.5.8
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_field_mappings( $request ) {
        $feed_id = $request->get_param( 'feed_id' );

        if ( ! $feed_id ) {
            return new WP_REST_Response( array( 'error' => __( 'Feed ID is required', 'woo-product-feed-pro' ) ), 400 );
        }

        $feed          = null;
        $migration_ran = false;

        if ( 'new' === $feed_id ) {
            // New feeds have no post yet: the general-settings step stored the
            // draft in the temporary option, and there is nothing to migrate.
            $temp_feed_data = get_option( ADT_OPTION_TEMP_PRODUCT_FEED, array() );
            if ( ! is_array( $temp_feed_data ) ) {
                $temp_feed_data = array();
            }

            $channel_hash = isset( $temp_feed_data['channel_hash'] ) ? (string) $temp_feed_data['channel_hash'] : '';

            if ( isset( $temp_feed_data['feed_attributes'] ) && is_array( $temp_feed_data['feed_attributes'] ) ) {
                $feed_attributes = $temp_feed_data['feed_attributes'];
            } elseif ( isset( $temp_feed_data['attributes'] ) && is_array( $temp_feed_data['attributes'] ) ) {
                // A draft saved through the legacy tab holds legacy rows only.
                // Transform them for the response, without persisting: the save
                // path stamps and persists the new shape when the form posts.
                $feed_attributes = Feed_Attributes_Migrator::transform_old_attributes_to_new(
                    $temp_feed_data['attributes'],
                    $channel_hash
                );
            } else {
                $feed_attributes = array();
            }
        } else {
            $feed = Product_Feed_Helper::get_product_feed( $feed_id );

            /*
             * SECURITY: `Product_Feed::load()` does not check the post type, so any
             * existing post ID returns a truthy feed. Because the migration below
             * persists (`$feed->save()`), an unchecked ID would let this GET stamp
             * plugin meta onto an arbitrary post or page. Verify the post type
             * before anything writes.
             */
            if ( ! $feed || Product_Feed::POST_TYPE !== get_post_type( $feed->id ) ) {
                return new WP_REST_Response( array( 'error' => __( 'Feed not found', 'woo-product-feed-pro' ) ), 404 );
            }

            /*
             * Migration on read, mirroring the filters/rules trigger. The migrator
             * stamps the version but does not persist, and its return value answers
             * "was legacy data converted" — the save is driven off $needs_save, which
             * is also true when only the stamp was written. See the migrator docblock.
             */
            $needs_save    = false;
            $migration_ran = Feed_Attributes_Migrator::maybe_run_migration( $feed, $needs_save );

            if ( $needs_save ) {
                $feed->save();
            }

            $feed_attributes = is_array( $feed->feed_attributes ) ? $feed->feed_attributes : array();
            $channel_hash    = (string) $feed->channel_hash;
        }

        $channel_fields = $this->get_channel_fields( $channel_hash );

        $response_data = array(
            'feed_attributes'        => $feed_attributes,
            'channel_attributes'     => $this->get_channel_attributes( $channel_fields ),
            'product_fields'         => Product_Feed_Attributes::instance()->get_attributes(),
            'sub_attribute_registry' => $this->get_sub_attribute_registry( $channel_fields ),
            'migration_ran'          => $migration_ran,
        );

        /**
         * Filter the map of attribute group labels that should be gated as
         * Elite-only in the field mapping builder. Same contract as the
         * filters/rules builder — see `Filters_Rules::get_filters_rules()`:
         * each entry pairs a group label from the catalog payloads with the
         * upsell modal key registered in `Upsell::upsell_l10n()`. Pro-side
         * callbacks only add entries when Elite is inactive, so the map is
         * empty on Elite-active sites.
         *
         * NOTE: no callback answers the `field_mapping` type yet, so the map is
         * currently empty on Pro-only sites too — the field mapping catalog has
         * no Elite-gated group today (Elite attributes are simply absent from
         * `product_fields` without Elite). #1037 must register a gated group
         * before the UI relies on this key being populated.
         *
         * @since 13.5.8
         *
         * @param array             $gated_groups Map of group label => upsell modal key. Empty by default.
         * @param string|null       $feed_channel The feed's channel `fields` value, or null when unresolved.
         * @param string            $type         Always 'field_mapping' here.
         * @param Product_Feed|null $feed         The feed object, or null for new feeds.
         */
        $response_data['eliteGatedAttrGroups'] = apply_filters(
            'adt_pfp_elite_gated_attr_groups',
            array(),
            '' !== $channel_fields ? $channel_fields : null,
            'field_mapping',
            $feed
        );

        return new WP_REST_Response( $response_data, 200 );
    }

    /**
     * Resolve a channel hash to the channel's `fields` value.
     *
     * @since 13.5.8
     *
     * @param string $channel_hash The feed's channel hash.
     * @return string The channel `fields` value, or an empty string when the hash resolves to no channel.
     */
    private function get_channel_fields( $channel_hash ) {
        if ( '' === $channel_hash ) {
            return '';
        }

        $channel = Product_Feed_Helper::get_channel_from_legacy_channel_hash( $channel_hash );

        return is_array( $channel ) && isset( $channel['fields'] ) ? (string) $channel['fields'] : '';
    }

    /**
     * Get the channel attribute catalog for a channel.
     *
     * Returns `get_channel_attributes()` verbatim: groups of definitions keyed by
     * label, each carrying `name`, `feed_name` and `format`, plus `allowed_values`
     * on the attributes the channel restricts to a closed set. The `format` value
     * is what lets the app pre-populate the required rows for a new feed. The class
     * loading and its path-traversal guard live in
     * `Product_Feed_Helper::get_channel_attribute_catalog()`, which the migrator
     * reads too, so the guard has one owner.
     *
     * @since 13.5.8
     *
     * @param string $channel_fields The channel `fields` value.
     * @return array The channel attribute catalog, or an empty array when the channel cannot be loaded.
     */
    private function get_channel_attributes( $channel_fields ) {
        return Product_Feed_Helper::get_channel_attribute_catalog( $channel_fields );
    }

    /**
     * Get the sub-attribute registry entries for a channel, in the app's shape.
     *
     * Ships the channel's whole registry slice so the app needs no per-change
     * request to look up a nested parent's defaults. Entries are reshaped for
     * the `SubAttributeRegistryEntry` contract in
     * `src/apps/field-mapping-builder/types/index.ts`: the store maps
     * `default_value` to a seeded row's `source_value`, so a zone definition's
     * `zone_key` ships as `default_value` — it is the literal `Shipping_Data`
     * row key the resolver reads.
     *
     * @since 13.5.8
     *
     * @param string $channel_fields The channel `fields` value.
     * @return array Registry entries keyed by parent output tag.
     */
    private function get_sub_attribute_registry( $channel_fields ) {
        if ( '' === $channel_fields ) {
            return array();
        }

        $registry = Sub_Attribute_Registry::get_registry();

        if ( ! isset( $registry[ $channel_fields ] ) || ! is_array( $registry[ $channel_fields ] ) ) {
            return array();
        }

        $reshaped = array();

        foreach ( $registry[ $channel_fields ] as $parent_tag => $definitions ) {
            $entries = array();

            foreach ( (array) $definitions as $definition ) {
                if ( ! is_array( $definition ) || empty( $definition['tag'] ) ) {
                    continue;
                }

                // Clamp filter-supplied sources through the seeder's own helper, so
                // the payload and the seeded rows can never disagree on a default.
                $default_source = Feed_Attributes_Migrator::normalize_sub_source(
                    isset( $definition['default_source'] ) ? $definition['default_source'] : ''
                );

                $entries[] = array(
                    'tag'            => (string) $definition['tag'],
                    'label'          => isset( $definition['label'] ) ? (string) $definition['label'] : '',
                    'default_source' => $default_source,
                    'default_value'  => isset( $definition['zone_key'] ) ? (string) $definition['zone_key'] : '',
                );
            }

            if ( ! empty( $entries ) ) {
                $reshaped[ $parent_tag ] = $entries;
            }
        }

        return $reshaped;
    }
}
