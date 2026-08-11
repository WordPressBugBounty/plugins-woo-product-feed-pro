<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Traits\Singleton_Trait;
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Helpers\Product_Feed_Helper;
use AdTribes\PFP\Helpers\Sanitization;
use AdTribes\PFP\Classes\CLI\Formatter;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's abilities on the WordPress Abilities API.
 *
 * Every ability is a thin wrapper around the plugin's existing service layer
 * (the same helpers the admin UI and WP-CLI use). Destructive abilities fire
 * the same WP/Woo actions and filters as their admin-UI equivalents so audit
 * trails, cache invalidation, and third-party integrations keep working — there
 * is no separate "via-AI" code path.
 *
 * Registration is gated on the Abilities API being available (WordPress core
 * 6.9+; a graceful no-op on older WordPress), on WooCommerce being active (the
 * whole plugin only boots when it is), and can be disabled with the
 * `PFP_DISABLE_ABILITIES` constant or the `adt_pfp_enable_abilities` filter.
 *
 * A subset of these abilities first shipped in Product Feed Elite 5.0.9 even
 * though the features they wrap live in this plugin; they are registered here
 * with a registered-name guard so an Elite version that still registers them
 * keeps working during the transition (Elite registers earlier on the same hook).
 *
 * @since 13.5.6
 */
class Abilities extends Abstract_Class {

    use Singleton_Trait;

    /**
     * The shared AdTribes ability category slug.
     *
     * @since 13.5.6
     * @var string
     */
    const CATEGORY = 'adtribes';

    /**
     * The built-in "Custom Feed" channel hash (from legacy_channel_statics.php).
     *
     * @since 13.5.6
     * @var string
     */
    const CUSTOM_FEED_CHANNEL_HASH = 'b0c1fa7a7dd12cadb7d8be768ce91315';

    /**
     * The standard (non-custom) feed refresh intervals.
     *
     * @since 13.5.6
     * @var string[]
     */
    const STANDARD_INTERVALS = array( '', 'hourly', 'twicedaily', 'daily' );

    /**
     * Maximum number of feeds a single get-channel-summary aggregation will load.
     *
     * Bounds the per-row helper loads; when a channel has more feeds than this the
     * response is capped and flagged with `truncated => true`.
     *
     * @since 13.5.6
     * @var int
     */
    const CHANNEL_SUMMARY_LIMIT = 500;

    /**
     * Run the class.
     *
     * @since 13.5.6
     * @access public
     */
    public function run() {
        // The Abilities API ( wp_register_ability ) must be available (WordPress core 6.9+); no-op otherwise.
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        // Escape hatch: a hard constant or a filter can switch registration off.
        if ( defined( 'PFP_DISABLE_ABILITIES' ) && PFP_DISABLE_ABILITIES ) {
            return;
        }

        /**
         * Filter whether the plugin registers its abilities on the Abilities API.
         *
         * @since 13.5.6
         *
         * @param bool $enabled Whether abilities are registered. Default true.
         */
        if ( ! apply_filters( 'adt_pfp_enable_abilities', true ) ) {
            return;
        }

        add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
        // Priority 20 so an Elite version that still registers the adopted abilities
        // (Elite <= 5.0.9 registers at the default 10) always registers first and the
        // registered-name guard here skips them without a duplicate-registration notice.
        add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ), 20 );
    }

    /**
     * Register the shared `adtribes` ability category.
     *
     * Guarded so a sibling AdTribes plugin that already registered the category
     * does not trigger a duplicate-registration notice.
     *
     * @since 13.5.6
     * @access public
     */
    public function register_category() {
        if ( wp_has_ability_category( self::CATEGORY ) ) {
            return;
        }

        wp_register_ability_category(
            self::CATEGORY,
            array(
                'label'       => __( 'AdTribes', 'woo-product-feed-pro' ),
                'description' => __( 'Abilities for the AdTribes plugin family.', 'woo-product-feed-pro' ),
            )
        );
    }

    /**
     * Register all abilities.
     *
     * @since 13.5.6
     * @access public
     */
    public function register_abilities() {
        $feed_id_schema = array(
            'type'        => array( 'integer', 'string' ),
            'description' => __( 'The feed ID or legacy project hash.', 'woo-product-feed-pro' ),
        );

        $utm_properties = array(
            'utm_enabled'  => array(
                'type'        => 'boolean',
                'description' => __( 'Whether UTM tagging is applied to the feed product URLs.', 'woo-product-feed-pro' ),
            ),
            'utm_source'   => array( 'type' => 'string' ),
            'utm_medium'   => array( 'type' => 'string' ),
            'utm_campaign' => array( 'type' => 'string' ),
            'utm_term'     => array( 'type' => 'string' ),
            'utm_content'  => array( 'type' => 'string' ),
        );

        // adtribes/list-feeds — read.
        wp_register_ability(
            'adtribes/list-feeds',
            array(
                'label'               => __( 'List product feeds', 'woo-product-feed-pro' ),
                'description'         => __( 'Lists product feeds with their channel, country, status, last-generated time, product count and file URL.', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_read' ),
                'execute_callback'    => array( $this, 'list_feeds' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'status'   => array(
                            'type'        => 'string',
                            'enum'        => array( 'publish', 'draft', 'any' ),
                            'description' => __( 'Filter by post status. Default: any.', 'woo-product-feed-pro' ),
                        ),
                        'channel'  => array(
                            'type'        => 'string',
                            'description' => __( 'Filter by channel hash or channel name (case-insensitive).', 'woo-product-feed-pro' ),
                        ),
                        'per_page' => array(
                            'type'        => 'integer',
                            'minimum'     => 1,
                            'maximum'     => 500,
                            'description' => __( 'Maximum number of feeds to return. Default: 100.', 'woo-product-feed-pro' ),
                        ),
                        'page'     => array(
                            'type'        => 'integer',
                            'minimum'     => 1,
                            'description' => __( 'Page number for pagination. Default: 1.', 'woo-product-feed-pro' ),
                        ),
                    ),
                    'default'    => array(),
                ),
                'meta'                => $this->meta( $this->annotations_read() ),
            )
        );

        // adtribes/get-feed — read.
        wp_register_ability(
            'adtribes/get-feed',
            array(
                'label'               => __( 'Get product feed', 'woo-product-feed-pro' ),
                'description'         => __( 'Returns the full configuration for a single product feed.', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_read' ),
                'execute_callback'    => array( $this, 'get_feed' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array( 'feed_id' => $feed_id_schema ),
                    'required'   => array( 'feed_id' ),
                ),
                'meta'                => $this->meta( $this->annotations_read() ),
            )
        );

        // adtribes/create-feed — destructive, non-idempotent.
        wp_register_ability(
            'adtribes/create-feed',
            array(
                'label'               => __( 'Create product feed', 'woo-product-feed-pro' ),
                'description'         => __( 'Creates a product feed for a channel and country. Field/category mappings and filters/rules can be set with the dedicated abilities.', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_manage' ),
                'execute_callback'    => array( $this, 'create_feed' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'title'            => array(
                            'type'        => 'string',
                            'description' => __( 'The feed title.', 'woo-product-feed-pro' ),
                        ),
                        'channel'          => array(
                            'type'        => 'string',
                            'description' => __( 'Channel hash or name (case-insensitive).', 'woo-product-feed-pro' ),
                        ),
                        'country'          => array(
                            'type'        => 'string',
                            'description' => __( 'Two-letter country code (e.g. AU, US).', 'woo-product-feed-pro' ),
                        ),
                        'file_format'      => array(
                            'type'        => 'string',
                            'description' => __( 'File format: xml, csv, txt, tsv, jsonl, jsonl.gz, csv.gz.', 'woo-product-feed-pro' ),
                        ),
                        'refresh_interval' => array(
                            'type'        => 'string',
                            'description' => __( 'Refresh interval: empty, hourly, twicedaily, daily.', 'woo-product-feed-pro' ),
                        ),
                        'post_status'      => array(
                            'type'        => 'string',
                            'enum'        => array( 'publish', 'draft' ),
                            'description' => __( 'Initial post status. Default: draft.', 'woo-product-feed-pro' ),
                        ),
                        'attributes'       => array(
                            'type'        => 'array',
                            'description' => __( 'Optional field mapping entries (see adtribes/set-field-mapping).', 'woo-product-feed-pro' ),
                            'items'       => array( 'type' => 'object' ),
                        ),
                        'mappings'         => array(
                            'type'        => 'array',
                            'description' => __( 'Optional category mapping entries (see adtribes/set-category-mapping).', 'woo-product-feed-pro' ),
                        ),
                    ),
                    'required'   => array( 'title', 'channel', 'country', 'file_format' ),
                ),
                'meta'                => $this->meta( $this->annotations_write( false ) ),
            )
        );

        // adtribes/update-feed — destructive, idempotent.
        wp_register_ability(
            'adtribes/update-feed',
            array(
                'label'               => __( 'Update product feed', 'woo-product-feed-pro' ),
                'description'         => __( "Updates an existing feed's configuration (title, channel, country, file format, refresh interval, post status).", 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_manage' ),
                'execute_callback'    => array( $this, 'update_feed' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'feed_id'          => $feed_id_schema,
                        'title'            => array( 'type' => 'string' ),
                        'channel'          => array( 'type' => 'string' ),
                        'country'          => array( 'type' => 'string' ),
                        'file_format'      => array( 'type' => 'string' ),
                        'refresh_interval' => array( 'type' => 'string' ),
                        'post_status'      => array(
                            'type' => 'string',
                            'enum' => array( 'publish', 'draft' ),
                        ),
                    ),
                    'required'   => array( 'feed_id' ),
                ),
                'meta'                => $this->meta( $this->annotations_write( true ) ),
            )
        );

        // adtribes/delete-feed — destructive, idempotent.
        wp_register_ability(
            'adtribes/delete-feed',
            array(
                'label'               => __( 'Delete product feed', 'woo-product-feed-pro' ),
                'description'         => __( 'Permanently deletes a product feed.', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_manage' ),
                'execute_callback'    => array( $this, 'delete_feed' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array( 'feed_id' => $feed_id_schema ),
                    'required'   => array( 'feed_id' ),
                ),
                'meta'                => $this->meta( $this->annotations_write( true ) ),
            )
        );

        // adtribes/regenerate-feed — destructive, idempotent.
        wp_register_ability(
            'adtribes/regenerate-feed',
            array(
                'label'               => __( 'Regenerate product feed', 'woo-product-feed-pro' ),
                'description'         => __( 'Regenerates a feed now. Runs asynchronously via Action Scheduler by default; pass async=false to run inline (resource-heavy).', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_manage' ),
                'execute_callback'    => array( $this, 'regenerate_feed' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'feed_id' => $feed_id_schema,
                        'async'   => array(
                            'type'        => 'boolean',
                            'description' => __( 'Schedule the refresh instead of running it inline. Default: true.', 'woo-product-feed-pro' ),
                        ),
                    ),
                    'required'   => array( 'feed_id' ),
                ),
                'meta'                => $this->meta( $this->annotations_write( true ) ),
            )
        );

        // adtribes/list-channels — read.
        wp_register_ability(
            'adtribes/list-channels',
            array(
                'label'               => __( 'List channels', 'woo-product-feed-pro' ),
                'description'         => __( 'Lists supported channels with their per-country availability.', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_read' ),
                'execute_callback'    => array( $this, 'list_channels' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'country' => array(
                            'type'        => 'string',
                            'description' => __( 'Two-letter country code to narrow results. Empty returns "All countries" channels.', 'woo-product-feed-pro' ),
                        ),
                    ),
                    'default'    => array(),
                ),
                'meta'                => $this->meta( $this->annotations_read() ),
            )
        );

        // adtribes/list-google-taxonomy — read.
        wp_register_ability(
            'adtribes/list-google-taxonomy',
            array(
                'label'               => __( 'List Google product taxonomy', 'woo-product-feed-pro' ),
                'description'         => __( 'Returns the cached Google product taxonomy for category mapping. Supports a search substring and a result limit.', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_read' ),
                'execute_callback'    => array( $this, 'list_google_taxonomy' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'search' => array(
                            'type'        => 'string',
                            'description' => __( 'Case-insensitive substring to filter categories by.', 'woo-product-feed-pro' ),
                        ),
                        'limit'  => array(
                            'type'        => 'integer',
                            'minimum'     => 1,
                            'maximum'     => 5000,
                            'description' => __( 'Maximum number of categories to return. Default: 200.', 'woo-product-feed-pro' ),
                        ),
                    ),
                    'default'    => array(),
                ),
                'meta'                => $this->meta( $this->annotations_read() ),
            )
        );

        // adtribes/list-feed-attributes — read (no input).
        wp_register_ability(
            'adtribes/list-feed-attributes',
            array(
                'label'               => __( 'List feed attributes', 'woo-product-feed-pro' ),
                'description'         => __( 'Returns the WooCommerce product attributes available for field mapping, grouped as they appear in the feed editor.', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_read' ),
                'execute_callback'    => array( $this, 'list_feed_attributes' ),
                'meta'                => $this->meta( $this->annotations_read() ),
            )
        );

        // adtribes/set-field-mapping — destructive, idempotent.
        wp_register_ability(
            'adtribes/set-field-mapping',
            array(
                'label'               => __( 'Set field mapping', 'woo-product-feed-pro' ),
                'description'         => __( 'Maps WooCommerce attributes to feed-channel fields for a feed. Each entry maps a channel field (attribute) to a WooCommerce source (mapfrom), with optional prefix/suffix/static value.', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_manage' ),
                'execute_callback'    => array( $this, 'set_field_mapping' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'feed_id'    => $feed_id_schema,
                        'attributes' => array(
                            'type'        => 'array',
                            'description' => __( 'Field mapping entries.', 'woo-product-feed-pro' ),
                            'items'       => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'attribute'    => array(
                                        'type'        => 'string',
                                        'description' => __( 'The channel field name (feed_name, e.g. g:title).', 'woo-product-feed-pro' ),
                                    ),
                                    'mapfrom'      => array(
                                        'type'        => 'string',
                                        'description' => __( 'The WooCommerce source attribute.', 'woo-product-feed-pro' ),
                                    ),
                                    'prefix'       => array( 'type' => 'string' ),
                                    'suffix'       => array( 'type' => 'string' ),
                                    'value'        => array( 'type' => 'string' ),
                                    'static_value' => array( 'type' => 'string' ),
                                ),
                            ),
                        ),
                    ),
                    'required'   => array( 'feed_id', 'attributes' ),
                ),
                'meta'                => $this->meta( $this->annotations_write( true ) ),
            )
        );

        // adtribes/set-category-mapping — destructive, idempotent.
        wp_register_ability(
            'adtribes/set-category-mapping',
            array(
                'label'               => __( 'Set category mapping', 'woo-product-feed-pro' ),
                'description'         => __( 'Maps WooCommerce product categories to Google/channel categories for a feed. Keyed by WooCommerce category term ID, each entry provides a map_to_category value.', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_manage' ),
                'execute_callback'    => array( $this, 'set_category_mapping' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'feed_id'  => $feed_id_schema,
                        'mappings' => array(
                            'type'        => 'object',
                            'description' => __( 'Category mappings keyed by WooCommerce category term ID.', 'woo-product-feed-pro' ),
                        ),
                    ),
                    'required'   => array( 'feed_id', 'mappings' ),
                ),
                'meta'                => $this->meta( $this->annotations_write( true ) ),
            )
        );

        // adtribes/add-filter-rule — destructive, non-idempotent.
        wp_register_ability(
            'adtribes/add-filter-rule',
            array(
                'label'               => __( 'Add filter or rule', 'woo-product-feed-pro' ),
                'description'         => __( 'Appends a product filter (include/exclude) or a transform rule to a feed.', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_manage' ),
                'execute_callback'    => array( $this, 'add_filter_rule' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'feed_id'          => $feed_id_schema,
                        'type'             => array(
                            'type'        => 'string',
                            'enum'        => array( 'filter', 'rule' ),
                            'description' => __( 'Whether to add a filter or a transform rule.', 'woo-product-feed-pro' ),
                        ),
                        'mode'             => array(
                            'type'        => 'string',
                            'enum'        => array( 'include', 'exclude' ),
                            'description' => __( 'For filters: include or exclude matching products. Default: include.', 'woo-product-feed-pro' ),
                        ),
                        'group_logic'      => array(
                            'type'        => 'string',
                            'enum'        => array( 'and', 'or' ),
                            'description' => __( 'For filters: how this filter group joins existing groups. Default: and.', 'woo-product-feed-pro' ),
                        ),
                        'attribute'        => array(
                            'type'        => 'string',
                            'description' => __( 'The attribute the condition tests.', 'woo-product-feed-pro' ),
                        ),
                        'condition'        => array(
                            'type'        => 'string',
                            'description' => __( 'The condition operator (see the feed builder for valid values).', 'woo-product-feed-pro' ),
                        ),
                        'value'            => array(
                            'type'        => 'string',
                            'description' => __( 'The value the condition compares against.', 'woo-product-feed-pro' ),
                        ),
                        'case_sensitive'   => array( 'type' => 'boolean' ),
                        'name'             => array(
                            'type'        => 'string',
                            'description' => __( 'For rules: an optional rule name.', 'woo-product-feed-pro' ),
                        ),
                        'action'           => array(
                            'type'        => 'string',
                            'description' => __( 'For rules: the action to apply when the condition matches.', 'woo-product-feed-pro' ),
                        ),
                        'action_attribute' => array(
                            'type'        => 'string',
                            'description' => __( 'For rules: the attribute the action modifies. Defaults to the condition attribute.', 'woo-product-feed-pro' ),
                        ),
                        'action_value'     => array(
                            'type'        => 'string',
                            'description' => __( 'For rules: the value used by the action.', 'woo-product-feed-pro' ),
                        ),
                        'find'             => array(
                            'type'        => 'string',
                            'description' => __( 'For rules: the search term for find/replace actions.', 'woo-product-feed-pro' ),
                        ),
                    ),
                    'required'   => array( 'feed_id', 'type' ),
                ),
                'meta'                => $this->meta( $this->annotations_write( false ) ),
            )
        );

        // adtribes/get-feed-validation — read.
        wp_register_ability(
            'adtribes/get-feed-validation',
            array(
                'label'               => __( 'Validate feed', 'woo-product-feed-pro' ),
                'description'         => __( "Validates a feed's configuration against its channel's field spec and reports missing required field mappings and configuration issues.", 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_read' ),
                'execute_callback'    => array( $this, 'get_feed_validation' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array( 'feed_id' => $feed_id_schema ),
                    'required'   => array( 'feed_id' ),
                ),
                'meta'                => $this->meta( $this->annotations_read() ),
            )
        );

        /*
         * The abilities below wrap features owned by this plugin but first shipped
         * in Product Feed Elite 5.0.9. They are registered through maybe_register()
         * so an Elite version that still registers them (having run earlier on this
         * hook) wins and no duplicate-registration notice fires.
         */

        // adtribes/create-custom-feed — destructive, non-idempotent.
        $this->maybe_register(
            'adtribes/create-custom-feed',
            array(
                'label'               => __( 'Create custom feed', 'woo-product-feed-pro' ),
                'description'         => __( 'Creates a custom-schema product feed (the "Custom Feed" channel) whose output fields you define explicitly via the attributes mapping.', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_manage' ),
                'execute_callback'    => array( $this, 'create_custom_feed' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'title'            => array(
                            'type'        => 'string',
                            'description' => __( 'The feed title.', 'woo-product-feed-pro' ),
                        ),
                        'country'          => array(
                            'type'        => 'string',
                            'description' => __( 'Two-letter country code (e.g. AU, US).', 'woo-product-feed-pro' ),
                        ),
                        'file_format'      => array(
                            'type'        => 'string',
                            'description' => __( 'File format: xml, csv, txt, tsv, jsonl, jsonl.gz, csv.gz.', 'woo-product-feed-pro' ),
                        ),
                        'refresh_interval' => array(
                            'type'        => 'string',
                            'description' => __( 'Refresh interval: empty, hourly, twicedaily, daily.', 'woo-product-feed-pro' ),
                        ),
                        'post_status'      => array(
                            'type' => 'string',
                            'enum' => array( 'publish', 'draft' ),
                        ),
                        'attributes'       => array(
                            'type'        => 'array',
                            'description' => __( 'Output field definitions. Each entry maps an output field (attribute) to a WooCommerce source (mapfrom), with optional prefix/suffix/static value.', 'woo-product-feed-pro' ),
                            'items'       => array( 'type' => 'object' ),
                        ),
                    ),
                    'required'   => array( 'title', 'country', 'file_format' ),
                ),
                'meta'                => $this->meta( $this->annotations_write( false ) ),
            )
        );

        // adtribes/set-utm-tagging — destructive, idempotent.
        $this->maybe_register(
            'adtribes/set-utm-tagging',
            array(
                'label'               => __( 'Set UTM tagging', 'woo-product-feed-pro' ),
                'description'         => __( "Configures the UTM parameters appended to a feed's product URLs.", 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_manage' ),
                'execute_callback'    => array( $this, 'set_utm_tagging' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array_merge( array( 'feed_id' => $feed_id_schema ), $utm_properties ),
                    'required'   => array( 'feed_id' ),
                ),
                'meta'                => $this->meta( $this->annotations_write( true ) ),
            )
        );

        // adtribes/clone-feed — destructive, non-idempotent.
        $this->maybe_register(
            'adtribes/clone-feed',
            array(
                'label'               => __( 'Clone feed', 'woo-product-feed-pro' ),
                'description'         => __( 'Duplicates an existing feed as a new draft (e.g. Google Shopping AU → NZ). Optionally retarget the copy to a different country/channel.', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_manage' ),
                'execute_callback'    => array( $this, 'clone_feed' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'feed_id' => $feed_id_schema,
                        'title'   => array(
                            'type'        => 'string',
                            'description' => __( 'Title for the new feed. Defaults to "Copy of {original}".', 'woo-product-feed-pro' ),
                        ),
                        'country' => array(
                            'type'        => 'string',
                            'description' => __( 'Optional new target country for the clone.', 'woo-product-feed-pro' ),
                        ),
                        'channel' => array(
                            'type'        => 'string',
                            'description' => __( 'Optional new channel (hash or name) for the clone.', 'woo-product-feed-pro' ),
                        ),
                    ),
                    'required'   => array( 'feed_id' ),
                ),
                'meta'                => $this->meta( $this->annotations_write( false ) ),
            )
        );

        // adtribes/bulk-update-feeds — destructive, non-idempotent.
        $this->maybe_register(
            'adtribes/bulk-update-feeds',
            array(
                'label'               => __( 'Bulk update feeds', 'woo-product-feed-pro' ),
                'description'         => __( 'Applies the same configuration change (country, file format, refresh interval, post status, or UTM parameters) across multiple feeds at once.', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_manage' ),
                'execute_callback'    => array( $this, 'bulk_update_feeds' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array_merge(
                        array(
                            'feed_ids'         => array(
                                'type'        => 'array',
                                'description' => __( 'The feed IDs (or legacy project hashes) to update. Maximum 500 per call.', 'woo-product-feed-pro' ),
                                'items'       => array( 'type' => array( 'integer', 'string' ) ),
                                // Each feed saves and (re)schedules synchronously, so bound the batch like the 500-capped read paths.
                                'maxItems'    => 500,
                            ),
                            'country'          => array( 'type' => 'string' ),
                            'file_format'      => array( 'type' => 'string' ),
                            'refresh_interval' => array( 'type' => 'string' ),
                            'post_status'      => array(
                                'type' => 'string',
                                'enum' => array( 'publish', 'draft' ),
                            ),
                        ),
                        $utm_properties
                    ),
                    'required'   => array( 'feed_ids' ),
                ),
                'meta'                => $this->meta( $this->annotations_write( false ) ),
            )
        );

        // adtribes/get-tracking-status — read.
        $this->maybe_register(
            'adtribes/get-tracking-status',
            array(
                'label'               => __( 'Get tracking status', 'woo-product-feed-pro' ),
                'description'         => __( 'Reports whether Facebook Pixel, Facebook Conversion API, and Google Ads dynamic remarketing are configured and enabled.', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_read' ),
                'execute_callback'    => array( $this, 'get_tracking_status' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(),
                    'default'    => array(),
                ),
                'meta'                => $this->meta( $this->annotations_read() ),
            )
        );

        // adtribes/set-facebook-pixel — destructive, idempotent.
        $this->maybe_register(
            'adtribes/set-facebook-pixel',
            array(
                'label'               => __( 'Set Facebook Pixel', 'woo-product-feed-pro' ),
                'description'         => __( 'Configures the Facebook Pixel ID, enables/disables the pixel, and sets the variable-product content_ids mode. The pixel fires the standard WooCommerce events automatically.', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_manage_settings' ),
                'execute_callback'    => array( $this, 'set_facebook_pixel' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'pixel_id'    => array(
                            'type'        => 'string',
                            'description' => __( 'The numeric Facebook Pixel ID. Pass an empty string to clear it.', 'woo-product-feed-pro' ),
                        ),
                        'enabled'     => array(
                            'type'        => 'boolean',
                            'description' => __( 'Whether the client-side pixel is active.', 'woo-product-feed-pro' ),
                        ),
                        'content_ids' => array(
                            'type'        => 'string',
                            'enum'        => array( 'variation', 'variable' ),
                            'description' => __( 'Which ID to report for variable products in pixel events.', 'woo-product-feed-pro' ),
                        ),
                    ),
                ),
                'meta'                => $this->meta( $this->annotations_write( true ) ),
            )
        );

        // adtribes/set-google-tracking — destructive, idempotent.
        $this->maybe_register(
            'adtribes/set-google-tracking',
            array(
                'label'               => __( 'Set Google tracking', 'woo-product-feed-pro' ),
                'description'         => __( 'Configures Google Ads dynamic remarketing: the conversion/remarketing ID and whether the remarketing tag is active.', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_manage_settings' ),
                'execute_callback'    => array( $this, 'set_google_tracking' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'conversion_id' => array(
                            'type'        => 'string',
                            'description' => __( 'The numeric Google Ads conversion/remarketing ID (used as AW-{id}). Pass an empty string to clear it.', 'woo-product-feed-pro' ),
                        ),
                        'enabled'       => array(
                            'type'        => 'boolean',
                            'description' => __( 'Whether the remarketing tag is active.', 'woo-product-feed-pro' ),
                        ),
                    ),
                ),
                'meta'                => $this->meta( $this->annotations_write( true ) ),
            )
        );

        // adtribes/get-feed-stats — read.
        $this->maybe_register(
            'adtribes/get-feed-stats',
            array(
                'label'               => __( 'Get feed statistics', 'woo-product-feed-pro' ),
                'description'         => __( 'Returns per-feed statistics: product count, products in the generated file, approximate excluded count, file size, generation status and last-updated time.', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_read' ),
                'execute_callback'    => array( $this, 'get_feed_stats' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array( 'feed_id' => $feed_id_schema ),
                    'required'   => array( 'feed_id' ),
                ),
                'meta'                => $this->meta( $this->annotations_read() ),
            )
        );

        // adtribes/get-channel-summary — read.
        $this->maybe_register(
            'adtribes/get-channel-summary',
            array(
                'label'               => __( 'Get channel summary', 'woo-product-feed-pro' ),
                'description'         => __( 'Aggregates statistics across all feeds for a single channel: feed count, published count, feeds in error, and total product count.', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_read' ),
                'execute_callback'    => array( $this, 'get_channel_summary' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'channel' => array(
                            'type'        => 'string',
                            'description' => __( 'Channel hash or name (case-insensitive).', 'woo-product-feed-pro' ),
                        ),
                        'country' => array(
                            'type'        => 'string',
                            'description' => __( 'Optional country code to narrow channel resolution.', 'woo-product-feed-pro' ),
                        ),
                    ),
                    'required'   => array( 'channel' ),
                ),
                'meta'                => $this->meta( $this->annotations_read() ),
            )
        );

        // adtribes/get-excluded-products — read.
        $this->maybe_register(
            'adtribes/get-excluded-products',
            array(
                'label'               => __( 'Get excluded products', 'woo-product-feed-pro' ),
                'description'         => __( 'Reports products excluded from a feed: the derived excluded count, plus products flagged with the manual per-product exclude toggle. Per-rule/per-filter attribution is not tracked by the plugin.', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_read' ),
                'execute_callback'    => array( $this, 'get_excluded_products' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'feed_id' => $feed_id_schema,
                        'limit'   => array(
                            'type'        => 'integer',
                            'minimum'     => 1,
                            'maximum'     => 500,
                            'description' => __( 'Maximum number of manually-excluded products to return. Default: 100.', 'woo-product-feed-pro' ),
                        ),
                    ),
                    'required'   => array( 'feed_id' ),
                ),
                'meta'                => $this->meta( $this->annotations_read() ),
            )
        );

        // adtribes/set-feed-schedule — destructive, idempotent.
        $this->maybe_register(
            'adtribes/set-feed-schedule',
            array(
                'label'               => __( 'Set feed schedule', 'woo-product-feed-pro' ),
                'description'         => __( 'Configures a feed refresh schedule: a standard interval (hourly/twicedaily/daily) or a custom interval (every N hours, weekly, monthly, yearly, with optional day-of-week restrictions; requires Product Feed Elite).', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_manage' ),
                'execute_callback'    => array( $this, 'set_feed_schedule' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'feed_id'          => $feed_id_schema,
                        'refresh_interval' => array(
                            'type'        => 'string',
                            'enum'        => array( '', 'hourly', 'twicedaily', 'daily', 'custom' ),
                            'description' => __( 'The refresh interval. Empty stops the schedule; "custom" uses the fields below and requires Product Feed Elite.', 'woo-product-feed-pro' ),
                        ),
                        'schedule'         => array(
                            'type'        => 'string',
                            'enum'        => array( 'hourly', 'hours', 'daily', 'twicedaily', 'weekly', 'monthly', 'yearly' ),
                            'description' => __( 'For a custom interval: the base schedule.', 'woo-product-feed-pro' ),
                        ),
                        'schedule_hours'   => array(
                            'type'        => 'integer',
                            'minimum'     => 1,
                            'description' => __( 'For a custom "hours" schedule: run every N hours.', 'woo-product-feed-pro' ),
                        ),
                        'days'             => array(
                            'type'        => 'array',
                            'description' => __( 'For a custom interval: days of the week to run on (1=Monday … 7=Sunday). Defaults to every day.', 'woo-product-feed-pro' ),
                            'items'       => array(
                                'type'    => 'integer',
                                'minimum' => 1,
                                'maximum' => 7,
                            ),
                        ),
                    ),
                    'required'   => array( 'feed_id', 'refresh_interval' ),
                ),
                'meta'                => $this->meta( $this->annotations_write( true ) ),
            )
        );

        // adtribes/pause-feed-schedule — destructive, idempotent.
        $this->maybe_register(
            'adtribes/pause-feed-schedule',
            array(
                'label'               => __( 'Pause feed schedule', 'woo-product-feed-pro' ),
                'description'         => __( 'Pauses a feed\'s scheduled refresh without deleting the feed or its stored schedule. The feed stays accessible; resume it with adtribes/set-feed-schedule.', 'woo-product-feed-pro' ),
                'category'            => self::CATEGORY,
                'permission_callback' => array( $this, 'can_manage' ),
                'execute_callback'    => array( $this, 'pause_feed_schedule' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array( 'feed_id' => $feed_id_schema ),
                    'required'   => array( 'feed_id' ),
                ),
                'meta'                => $this->meta( $this->annotations_write( true ) ),
            )
        );
    }

    /*
     * Permission callbacks.
     */

    /**
     * Permission callback for read abilities.
     *
     * Read abilities expose admin-only store data (feed configuration, channel
     * setup and feed file URLs), so they require the same capability as the
     * plugin's REST endpoints and management abilities — not just `read`, which
     * every logged-in user (including Subscribers) has.
     *
     * @since 13.5.6
     * @access public
     *
     * @return bool Whether the current user can read feed data.
     */
    public function can_read() {
        return current_user_can( 'manage_woocommerce' );
    }

    /**
     * Permission callback for feed-management (destructive) abilities.
     *
     * @since 13.5.6
     * @access public
     *
     * @return bool Whether the current user can manage WooCommerce.
     */
    public function can_manage() {
        return current_user_can( 'manage_woocommerce' );
    }

    /**
     * Permission callback for site-wide settings (destructive) abilities.
     *
     * Mirrors the capability gate the plugin's admin Settings screen uses, so an
     * ability cannot write site-wide tracking configuration that the admin UI would
     * not let the same user change.
     *
     * @since 13.5.6
     * @access public
     *
     * @return bool Whether the current user can manage the plugin settings.
     */
    public function can_manage_settings() {
        /**
         * This filter is documented in includes/Classes/Admin_Pages/Settings_Page.php
         */
        $capability = apply_filters( 'adt_pfp_admin_capability', 'manage_options' );
        return current_user_can( $capability );
    }

    /*
     * Execute callbacks.
     */

    /**
     * Execute: list product feeds.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The paginated feed rows, or a WP_Error on failure.
     */
    public function list_feeds( $input = null ) {
        $data     = $this->input_array( $input );
        $status   = isset( $data['status'] ) ? (string) $data['status'] : 'any';
        $per_page = isset( $data['per_page'] ) ? absint( $data['per_page'] ) : 100;
        $page     = isset( $data['page'] ) ? max( 1, absint( $data['page'] ) ) : 1;

        $query_args = array(
            'post_type'      => Product_Feed::POST_TYPE,
            'post_status'    => $status,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'fields'         => 'ids',
            // Count the full result set so callers can detect truncation.
            'no_found_rows'  => false,
        );

        if ( ! empty( $data['channel'] ) ) {
            $channel = Formatter::resolve_channel( (string) $data['channel'] );
            if ( ! $channel ) {
                return new \WP_Error(
                    'adt_unknown_channel',
                    /* translators: %s: channel identifier. */
                    sprintf( __( 'Unknown channel: %s', 'woo-product-feed-pro' ), $data['channel'] ),
                    array( 'status' => 400 )
                );
            }
            $query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                array(
                    'key'   => 'adt_channel_hash',
                    'value' => $channel['channel_hash'],
                ),
            );
        }

        $query = new \WP_Query( $query_args );
        $rows  = array();
        foreach ( $query->posts as $id ) {
            $feed = Product_Feed_Helper::get_product_feed( (int) $id );
            if ( $feed ) {
                $rows[] = Formatter::feed_to_row( $feed );
            }
        }

        return array(
            'total'    => (int) $query->found_posts,
            'count'    => count( $rows ),
            'page'     => $page,
            'per_page' => $per_page,
            'items'    => $rows,
        );
    }

    /**
     * Execute: get a single feed's full configuration.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The feed configuration, or a WP_Error on failure.
     */
    public function get_feed( $input = null ) {
        $feed = $this->get_feed_from_input( $input );
        if ( is_wp_error( $feed ) ) {
            return $feed;
        }

        return Formatter::feed_to_full( $feed );
    }

    /**
     * Execute: create a product feed.
     *
     * Mirrors the WP-CLI `create` command and the admin create flow.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The new feed summary, or a WP_Error on failure.
     */
    public function create_feed( $input = null ) {
        $data        = $this->input_array( $input );
        $title       = isset( $data['title'] ) ? (string) $data['title'] : '';
        $country     = isset( $data['country'] ) ? (string) $data['country'] : '';
        $file_format = isset( $data['file_format'] ) ? (string) $data['file_format'] : '';

        $channel = Formatter::resolve_channel( isset( $data['channel'] ) ? (string) $data['channel'] : '', $country );
        if ( ! $channel ) {
            return new \WP_Error(
                'adt_unknown_channel',
                /* translators: %s: channel identifier. */
                sprintf( __( 'Unknown channel: %s', 'woo-product-feed-pro' ), isset( $data['channel'] ) ? $data['channel'] : '' ),
                array( 'status' => 400 )
            );
        }

        if ( '' === $title || '' === $country || '' === $file_format ) {
            return new \WP_Error(
                'adt_missing_required',
                __( 'title, channel, country and file_format are required.', 'woo-product-feed-pro' ),
                array( 'status' => 400 )
            );
        }

        $allowed_formats = Formatter::supported_file_formats();
        if ( ! in_array( $file_format, $allowed_formats, true ) ) {
            return new \WP_Error(
                'adt_unsupported_format',
                /* translators: 1: file format, 2: comma-separated list of allowed formats. */
                sprintf( __( 'Unsupported file format: %1$s. Allowed: %2$s', 'woo-product-feed-pro' ), $file_format, implode( ', ', $allowed_formats ) ),
                array( 'status' => 400 )
            );
        }

        $post_status = ( isset( $data['post_status'] ) && 'publish' === $data['post_status'] ) ? 'publish' : 'draft';

        $hash  = Product_Feed_Helper::generate_legacy_project_hash();
        $props = array(
            'channel_hash'        => $channel['channel_hash'],
            'country'             => $country,
            'file_format'         => $file_format,
            'file_name'           => $hash,
            'legacy_project_hash' => $hash,
            'status'              => 'not run yet',
        );

        if ( ! empty( $channel['utm_source'] ) ) {
            $props['utm_source'] = $channel['utm_source'];
        }
        if ( isset( $data['refresh_interval'] ) ) {
            $props['refresh_interval'] = (string) $data['refresh_interval'];
        }
        if ( isset( $data['attributes'] ) && is_array( $data['attributes'] ) ) {
            $props['attributes'] = Sanitization::sanitize_array( $data['attributes'] );
        }
        if ( isset( $data['mappings'] ) && is_array( $data['mappings'] ) ) {
            $props['mappings'] = Sanitization::sanitize_array( $data['mappings'] );
        }

        $feed = new Product_Feed();
        try {
            $feed->title       = $title;
            $feed->post_status = $post_status;
            $feed->set_props( $props );
            $feed->save();

            if ( 'publish' === $post_status ) {
                $feed->register_action();
            }
        } catch ( \Exception $e ) {
            return new \WP_Error( 'adt_create_failed', $e->getMessage(), array( 'status' => 500 ) );
        }

        return array(
            'feed_id'     => (int) $feed->id,
            'title'       => (string) $feed->title,
            'post_status' => (string) $feed->post_status,
            'file_url'    => (string) $feed->get_file_url(),
        );
    }

    /**
     * Execute: update a product feed.
     *
     * Mirrors the WP-CLI `update` command.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The updated feed summary, or a WP_Error on failure.
     */
    public function update_feed( $input = null ) {
        $feed = $this->get_feed_from_input( $input );
        if ( is_wp_error( $feed ) ) {
            return $feed;
        }

        $data  = $this->input_array( $input );
        $props = array();

        foreach ( array( 'title', 'country', 'file_format', 'refresh_interval', 'post_status' ) as $key ) {
            if ( isset( $data[ $key ] ) ) {
                $props[ $key ] = (string) $data[ $key ];
            }
        }

        if ( isset( $props['file_format'] ) ) {
            $allowed_formats = Formatter::supported_file_formats();
            if ( ! in_array( $props['file_format'], $allowed_formats, true ) ) {
                return new \WP_Error(
                    'adt_unsupported_format',
                    /* translators: 1: file format, 2: comma-separated list of allowed formats. */
                    sprintf( __( 'Unsupported file format: %1$s. Allowed: %2$s', 'woo-product-feed-pro' ), $props['file_format'], implode( ', ', $allowed_formats ) ),
                    array( 'status' => 400 )
                );
            }
        }

        if ( ! empty( $data['channel'] ) ) {
            $country = isset( $props['country'] ) ? $props['country'] : $feed->country;
            $channel = Formatter::resolve_channel( (string) $data['channel'], $country );
            if ( ! $channel ) {
                return new \WP_Error(
                    'adt_unknown_channel',
                    /* translators: %s: channel identifier. */
                    sprintf( __( 'Unknown channel: %s', 'woo-product-feed-pro' ), $data['channel'] ),
                    array( 'status' => 400 )
                );
            }
            $props['channel_hash'] = $channel['channel_hash'];
        }

        if ( empty( $props ) ) {
            return new \WP_Error(
                'adt_nothing_to_update',
                __( 'Nothing to update. Provide at least one property.', 'woo-product-feed-pro' ),
                array( 'status' => 400 )
            );
        }

        $reschedule = array_key_exists( 'refresh_interval', $props ) || array_key_exists( 'post_status', $props );

        try {
            if ( isset( $props['title'] ) ) {
                $feed->title = (string) $props['title'];
                unset( $props['title'] );
            }
            if ( isset( $props['post_status'] ) ) {
                $feed->post_status = (string) $props['post_status'];
                unset( $props['post_status'] );
            }

            $feed->set_props( $props );
            $feed->save();

            if ( $reschedule ) {
                if ( 'publish' === $feed->post_status ) {
                    $feed->register_action();
                } else {
                    $feed->unregister_action();
                }
            }
        } catch ( \Exception $e ) {
            return new \WP_Error( 'adt_update_failed', $e->getMessage(), array( 'status' => 500 ) );
        }

        return array(
            'feed_id' => (int) $feed->id,
            'updated' => true,
        );
    }

    /**
     * Execute: delete a product feed.
     *
     * Fires the same hooks as the admin/CLI delete path.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The delete result, or a WP_Error on failure.
     */
    public function delete_feed( $input = null ) {
        $feed = $this->get_feed_from_input( $input );
        if ( is_wp_error( $feed ) ) {
            return $feed;
        }

        $feed_id = (int) $feed->id;

        do_action( 'adt_before_delete_product_feed', $feed );
        $feed->delete();
        do_action( 'adt_after_delete_product_feed', $feed );

        return array(
            'feed_id' => $feed_id,
            'deleted' => true,
        );
    }

    /**
     * Execute: regenerate a product feed.
     *
     * Mirrors the WP-CLI `refresh` command. Defaults to asynchronous scheduling.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The regenerate result, or a WP_Error on failure.
     */
    public function regenerate_feed( $input = null ) {
        $feed = $this->get_feed_from_input( $input );
        if ( is_wp_error( $feed ) ) {
            return $feed;
        }

        $data  = $this->input_array( $input );
        $async = isset( $data['async'] ) ? (bool) $data['async'] : true;

        if ( $async ) {
            as_schedule_single_action(
                time() + 1,
                ADT_PFP_AS_GENERATE_PRODUCT_FEED,
                array( 'feed_id' => $feed->id )
            );

            return array(
                'feed_id'   => (int) $feed->id,
                'scheduled' => true,
                'mode'      => 'async',
            );
        }

        Product_Feed_Helper::disable_cache();

        try {
            $feed->generate( 'manual' );
        } catch ( \Exception $e ) {
            return new \WP_Error( 'adt_regenerate_failed', $e->getMessage(), array( 'status' => 500 ) );
        }

        return array(
            'feed_id' => (int) $feed->id,
            'status'  => (string) $feed->status,
            'mode'    => 'inline',
        );
    }

    /**
     * Execute: list channels.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array The channel rows.
     */
    public function list_channels( $input = null ) {
        $data    = $this->input_array( $input );
        $country = isset( $data['country'] ) ? (string) $data['country'] : '';

        $rows = array();
        foreach ( Product_Feed_Attributes::get_channels( $country ) as $channel ) {
            $rows[] = array(
                'name'         => isset( $channel['name'] ) ? (string) $channel['name'] : '',
                'channel_hash' => isset( $channel['channel_hash'] ) ? (string) $channel['channel_hash'] : '',
                'fields'       => isset( $channel['fields'] ) ? (string) $channel['fields'] : '',
                'taxonomy'     => isset( $channel['taxonomy'] ) ? (string) $channel['taxonomy'] : '',
                'type'         => isset( $channel['type'] ) ? (string) $channel['type'] : '',
                'utm_source'   => isset( $channel['utm_source'] ) ? (string) $channel['utm_source'] : '',
            );
        }

        return $rows;
    }

    /**
     * Execute: list the cached Google product taxonomy.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The taxonomy items, or a WP_Error if the cache is missing.
     */
    public function list_google_taxonomy( $input = null ) {
        $data   = $this->input_array( $input );
        $search = isset( $data['search'] ) ? strtolower( trim( (string) $data['search'] ) ) : '';
        $limit  = isset( $data['limit'] ) ? min( 5000, max( 1, absint( $data['limit'] ) ) ) : 200;

        $path = Google_Product_Taxonomy_Fetcher::GOOGLE_PRODUCT_TAXONOMY_FILE_PATH;
        if ( ! file_exists( $path ) ) {
            return new \WP_Error(
                'adt_taxonomy_unavailable',
                __( 'The Google product taxonomy has not been fetched yet. It is downloaded on a weekly schedule; please try again later.', 'woo-product-feed-pro' ),
                array( 'status' => 404 )
            );
        }

        $raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( false === $raw || '' === $raw ) {
            return new \WP_Error(
                'adt_taxonomy_empty',
                __( 'The Google product taxonomy cache is empty.', 'woo-product-feed-pro' ),
                array( 'status' => 404 )
            );
        }

        $items = array();
        $total = 0;
        $lines = preg_split( '/\r\n|\r|\n/', $raw );
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line || 0 === strpos( $line, '#' ) ) {
                continue;
            }

            $parts = explode( ' - ', $line, 2 );
            if ( count( $parts ) < 2 ) {
                continue;
            }

            $category = trim( $parts[1] );
            if ( '' !== $search && false === strpos( strtolower( $category ), $search ) ) {
                continue;
            }

            ++$total;
            if ( count( $items ) < $limit ) {
                $items[] = array(
                    'id'       => (int) trim( $parts[0] ),
                    'category' => $category,
                    'path'     => array_map( 'trim', explode( '>', $category ) ),
                );
            }
        }

        return array(
            'total' => $total,
            'count' => count( $items ),
            'limit' => $limit,
            'items' => $items,
        );
    }

    /**
     * Execute: list feed attributes available for mapping.
     *
     * @since 13.5.6
     * @access public
     *
     * @return array The grouped attribute catalog.
     */
    public function list_feed_attributes() {
        return Product_Feed_Attributes::instance()->get_attributes();
    }

    /**
     * Execute: set a feed's field mapping.
     *
     * Fires the same filter/action as the admin Field Mapping tab.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The updated mapping, or a WP_Error on failure.
     */
    public function set_field_mapping( $input = null ) {
        $feed = $this->get_feed_from_input( $input );
        if ( is_wp_error( $feed ) ) {
            return $feed;
        }

        $data = $this->input_array( $input );
        if ( empty( $data['attributes'] ) || ! is_array( $data['attributes'] ) ) {
            return new \WP_Error(
                'adt_missing_attributes',
                __( 'A non-empty attributes array is required.', 'woo-product-feed-pro' ),
                array( 'status' => 400 )
            );
        }

        $attributes = Sanitization::sanitize_array( $data['attributes'] );

        // Mirror the admin cleanup: drop the static_value flag unless it is 'true'.
        foreach ( $attributes as $key => $attribute ) {
            if ( isset( $attribute['static_value'] ) && 'true' !== $attribute['static_value'] ) {
                unset( $attributes[ $key ]['static_value'] );
            }
        }

        /**
         * This filter is documented in includes/Classes/Admin_Pages/Edit_Feed_Page.php
         */
        $props = apply_filters( 'adt_edit_feed_field_mapping_tab_props', array( 'attributes' => $attributes ), $feed );

        $feed->set_props( $props );
        $feed->save();

        /**
         * This action is documented in includes/Classes/Admin_Pages/Edit_Feed_Page.php
         */
        do_action( 'adt_after_process_field_mapping_tab_form', $feed, $props );

        return array(
            'feed_id'    => (int) $feed->id,
            'attributes' => (array) $feed->attributes,
        );
    }

    /**
     * Execute: set a feed's category mapping.
     *
     * Fires the same filter/action as the admin Category Mapping tab.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The updated mapping, or a WP_Error on failure.
     */
    public function set_category_mapping( $input = null ) {
        $feed = $this->get_feed_from_input( $input );
        if ( is_wp_error( $feed ) ) {
            return $feed;
        }

        $data = $this->input_array( $input );
        if ( empty( $data['mappings'] ) || ! is_array( $data['mappings'] ) ) {
            return new \WP_Error(
                'adt_missing_mappings',
                __( 'A non-empty mappings object is required.', 'woo-product-feed-pro' ),
                array( 'status' => 400 )
            );
        }

        $mappings = Sanitization::sanitize_array( $data['mappings'] );

        /**
         * This filter is documented in includes/Classes/Admin_Pages/Edit_Feed_Page.php
         */
        $props = apply_filters( 'adt_edit_feed_category_mapping_tab_props', array( 'mappings' => $mappings ), $feed );

        $feed->set_props( $props );
        $feed->save();

        /**
         * This action is documented in includes/Classes/Admin_Pages/Edit_Feed_Page.php
         */
        do_action( 'adt_after_process_category_mapping_tab_form', $feed, $props );

        return array(
            'feed_id'  => (int) $feed->id,
            'mappings' => (array) $feed->mappings,
        );
    }

    /**
     * Execute: append a filter or transform rule to a feed.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The updated filters/rules, or a WP_Error on failure.
     */
    public function add_filter_rule( $input = null ) {
        $feed = $this->get_feed_from_input( $input );
        if ( is_wp_error( $feed ) ) {
            return $feed;
        }

        $data = $this->input_array( $input );
        $type = isset( $data['type'] ) ? (string) $data['type'] : '';

        if ( 'filter' === $type ) {
            return $this->add_filter( $feed, $data );
        }

        if ( 'rule' === $type ) {
            return $this->add_rule( $feed, $data );
        }

        return new \WP_Error(
            'adt_invalid_type',
            __( 'The type must be "filter" or "rule".', 'woo-product-feed-pro' ),
            array( 'status' => 400 )
        );
    }

    /**
     * Execute: validate a feed against its channel's field spec.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The validation report, or a WP_Error on failure.
     */
    public function get_feed_validation( $input = null ) {
        $feed = $this->get_feed_from_input( $input );
        if ( is_wp_error( $feed ) ) {
            return $feed;
        }

        $channel = $feed->channel;
        if ( empty( $channel ) || empty( $channel['fields'] ) ) {
            return new \WP_Error(
                'adt_no_channel',
                __( 'The feed has no channel selected, so it cannot be validated.', 'woo-product-feed-pro' ),
                array( 'status' => 400 )
            );
        }

        $spec = $this->get_channel_spec( (string) $channel['fields'] );
        if ( is_wp_error( $spec ) ) {
            return $spec;
        }

        // Collect the channel's required fields (feed_name + label).
        $required_fields = array();
        foreach ( $spec as $group ) {
            if ( ! is_array( $group ) ) {
                continue;
            }
            foreach ( $group as $label => $field ) {
                if ( is_array( $field ) && isset( $field['format'], $field['feed_name'] ) && 'required' === $field['format'] ) {
                    $required_fields[] = array(
                        'feed_name' => (string) $field['feed_name'],
                        'label'     => is_string( $label ) ? $label : (string) $field['feed_name'],
                    );
                }
            }
        }

        // Index the feed's mapped fields by channel field name, noting whether each has a source.
        $mapped        = (array) $feed->attributes;
        $mapped_fields = array();
        foreach ( $mapped as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['attribute'] ) ) {
                continue;
            }
            $has_source = ( ! empty( $entry['mapfrom'] ) )
                || ( isset( $entry['value'] ) && '' !== (string) $entry['value'] )
                || ( isset( $entry['static_value'] ) && 'true' === $entry['static_value'] );

            $mapped_fields[ (string) $entry['attribute'] ] = $has_source;
        }

        $errors   = array();
        $warnings = array();

        if ( empty( $feed->country ) ) {
            $errors[] = __( 'The feed has no target country set.', 'woo-product-feed-pro' );
        }
        if ( empty( $mapped ) ) {
            $errors[] = __( 'The feed has no field mappings configured.', 'woo-product-feed-pro' );
        }

        foreach ( $required_fields as $field ) {
            if ( ! array_key_exists( $field['feed_name'], $mapped_fields ) ) {
                $errors[] = sprintf(
                    /* translators: 1: field label, 2: channel field name. */
                    __( 'Required field "%1$s" (%2$s) is not mapped.', 'woo-product-feed-pro' ),
                    $field['label'],
                    $field['feed_name']
                );
            } elseif ( ! $mapped_fields[ $field['feed_name'] ] ) {
                $warnings[] = sprintf(
                    /* translators: 1: field label, 2: channel field name. */
                    __( 'Required field "%1$s" (%2$s) is mapped but has no source value.', 'woo-product-feed-pro' ),
                    $field['label'],
                    $field['feed_name']
                );
            }
        }

        return array(
            'feed_id'         => (int) $feed->id,
            'channel'         => isset( $channel['name'] ) ? (string) $channel['name'] : '',
            'valid'           => empty( $errors ),
            'errors'          => $errors,
            'warnings'        => $warnings,
            'required_fields' => wp_list_pluck( $required_fields, 'feed_name' ),
            'mapped_fields'   => array_keys( $mapped_fields ),
        );
    }

    /**
     * Execute: create a custom-schema product feed.
     *
     * A "custom feed" is a feed on the built-in "Custom Feed" channel whose output
     * fields are defined explicitly through the attributes (field mapping) prop.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The new feed summary, or a WP_Error on failure.
     */
    public function create_custom_feed( $input = null ) {
        $data        = $this->input_array( $input );
        $title       = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';
        $country     = isset( $data['country'] ) ? (string) $data['country'] : '';
        $file_format = isset( $data['file_format'] ) ? (string) $data['file_format'] : '';

        if ( '' === $title || '' === $country || '' === $file_format ) {
            return new \WP_Error(
                'adt_missing_required',
                __( 'title, country and file_format are required.', 'woo-product-feed-pro' ),
                array( 'status' => 400 )
            );
        }

        $format_error = $this->validate_file_format( $file_format );
        if ( is_wp_error( $format_error ) ) {
            return $format_error;
        }

        $channel = Product_Feed_Helper::get_channel_from_legacy_channel_hash( self::CUSTOM_FEED_CHANNEL_HASH );
        if ( ! $channel ) {
            return new \WP_Error(
                'adt_custom_channel_missing',
                __( 'The built-in Custom Feed channel could not be found.', 'woo-product-feed-pro' ),
                array( 'status' => 500 )
            );
        }

        $post_status = ( isset( $data['post_status'] ) && 'publish' === $data['post_status'] ) ? 'publish' : 'draft';

        $hash  = Product_Feed_Helper::generate_legacy_project_hash();
        $props = array(
            'channel_hash'        => $channel['channel_hash'],
            'country'             => $country,
            'file_format'         => $file_format,
            'file_name'           => $hash,
            'legacy_project_hash' => $hash,
            'status'              => 'not run yet',
        );

        if ( ! empty( $channel['utm_source'] ) ) {
            $props['utm_source'] = $channel['utm_source'];
        }
        if ( isset( $data['refresh_interval'] ) ) {
            $refresh_interval = (string) $data['refresh_interval'];
            $interval_error   = $this->validate_refresh_interval( $refresh_interval );
            if ( is_wp_error( $interval_error ) ) {
                return $interval_error;
            }
            $props['refresh_interval'] = $refresh_interval;
        }
        if ( isset( $data['attributes'] ) && is_array( $data['attributes'] ) ) {
            $props['attributes'] = Sanitization::sanitize_array( $data['attributes'] );
        }

        $feed = Product_Feed_Helper::get_product_feed( 0 );
        try {
            $feed->title       = $title;
            $feed->post_status = $post_status;
            $feed->set_props( $props );
            $feed->save();

            if ( 'publish' === $post_status ) {
                $feed->register_action();
            }
        } catch ( \Exception $e ) {
            return new \WP_Error( 'adt_create_failed', $e->getMessage(), array( 'status' => 500 ) );
        }

        return array(
            'feed_id'     => (int) $feed->id,
            'title'       => (string) $feed->title,
            'channel'     => isset( $channel['name'] ) ? (string) $channel['name'] : '',
            'post_status' => (string) $feed->post_status,
            'file_url'    => (string) $feed->get_file_url(),
        );
    }

    /**
     * Execute: set a feed's UTM tagging.
     *
     * Fires the same filter/action as the admin Conversion & Google Analytics tab.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The updated UTM configuration, or a WP_Error on failure.
     */
    public function set_utm_tagging( $input = null ) {
        $feed = $this->get_feed_from_input( $input );
        if ( is_wp_error( $feed ) ) {
            return $feed;
        }

        $data  = $this->input_array( $input );
        $props = array();

        if ( array_key_exists( 'utm_enabled', $data ) ) {
            $props['utm_enabled'] = (bool) $data['utm_enabled'];
        }
        $props = array_merge( $props, $this->sanitize_utm_props( $data ) );

        if ( empty( $props ) ) {
            return new \WP_Error(
                'adt_nothing_to_update',
                __( 'Nothing to update. Provide at least one UTM property.', 'woo-product-feed-pro' ),
                array( 'status' => 400 )
            );
        }

        /**
         * This filter is documented in includes/Classes/Admin_Pages/Edit_Feed_Page.php
         */
        $props = apply_filters( 'adt_edit_feed_conversion_analytics_tab_props', $props, $feed );

        $feed->set_props( $props );
        $feed->save();

        /**
         * This action is documented in includes/Classes/Admin_Pages/Edit_Feed_Page.php
         */
        do_action( 'adt_after_process_conversion_analytics_tab_form', $feed, $props );

        return array(
            'feed_id'      => (int) $feed->id,
            'utm_enabled'  => (bool) $feed->utm_enabled,
            'utm_source'   => (string) $feed->utm_source,
            'utm_medium'   => (string) $feed->utm_medium,
            'utm_campaign' => (string) $feed->utm_campaign,
            'utm_term'     => (string) $feed->utm_term,
            'utm_content'  => (string) $feed->utm_content,
        );
    }

    /**
     * Execute: clone a feed.
     *
     * Mirrors the WP-CLI `duplicate` command and the admin clone flow, firing the
     * same before/after hooks.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The new feed summary, or a WP_Error on failure.
     */
    public function clone_feed( $input = null ) {
        $original = $this->get_feed_from_input( $input );
        if ( is_wp_error( $original ) ) {
            return $original;
        }

        $data = $this->input_array( $input );
        $hash = Product_Feed_Helper::generate_legacy_project_hash();

        $new_feed                      = clone $original;
        $new_feed->id                  = 0;
        $new_feed->post_status         = 'draft';
        $new_feed->status              = 'not run yet';
        $new_feed->legacy_project_hash = $hash;
        $new_feed->file_name           = $hash;
        $new_feed->last_updated        = '';

        if ( isset( $data['title'] ) && '' !== trim( (string) $data['title'] ) ) {
            $new_feed->title = sanitize_text_field( (string) $data['title'] );
        } else {
            /* translators: %s: the original feed title. */
            $new_feed->title = sprintf( __( 'Copy of %s', 'woo-product-feed-pro' ), $original->title );
        }

        // Optional AU → NZ style retargeting of the clone.
        $retarget = array();
        if ( ! empty( $data['country'] ) ) {
            $retarget['country'] = (string) $data['country'];
        }
        if ( ! empty( $data['channel'] ) ) {
            $country = isset( $retarget['country'] ) ? $retarget['country'] : (string) $new_feed->country;
            $channel = Formatter::resolve_channel( (string) $data['channel'], $country );
            if ( ! $channel ) {
                return new \WP_Error(
                    'adt_unknown_channel',
                    /* translators: %s: channel identifier. */
                    sprintf( __( 'Unknown channel: %s', 'woo-product-feed-pro' ), $data['channel'] ),
                    array( 'status' => 400 )
                );
            }
            $retarget['channel_hash'] = $channel['channel_hash'];
        }
        if ( ! empty( $retarget ) ) {
            $new_feed->set_props( $retarget );
        }

        /**
         * This action is documented in includes/Classes/Product_Feed_Admin.php
         */
        do_action( 'adt_clone_product_feed_before_save', $new_feed, $original );

        try {
            $new_feed->save();
            $new_feed->register_action();
        } catch ( \Exception $e ) {
            return new \WP_Error( 'adt_clone_failed', $e->getMessage(), array( 'status' => 500 ) );
        }

        /**
         * This action is documented in includes/Classes/CLI/Feed_Command.php
         */
        do_action( 'adt_after_clone_product_feed', $new_feed, $original );

        return array(
            'feed_id'        => (int) $new_feed->id,
            'title'          => (string) $new_feed->title,
            'source_feed_id' => (int) $original->id,
            'post_status'    => (string) $new_feed->post_status,
            'file_url'       => (string) $new_feed->get_file_url(),
        );
    }

    /**
     * Execute: apply a config change across multiple feeds.
     *
     * There is no bulk config-edit primitive in the plugin (the admin bulk actions
     * only delete/duplicate/activate/deactivate/refresh/cancel), so this iterates
     * the feeds and applies the single-feed update path to each, reusing the feed's
     * own register_action()/unregister_action() to reschedule — the same primitive
     * the `adtribes/set-feed-schedule` ability uses.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The per-feed results, or a WP_Error on failure.
     */
    public function bulk_update_feeds( $input = null ) {
        $data     = $this->input_array( $input );
        $feed_ids = ( isset( $data['feed_ids'] ) && is_array( $data['feed_ids'] ) ) ? $data['feed_ids'] : array();

        if ( empty( $feed_ids ) ) {
            return new \WP_Error(
                'adt_missing_feed_ids',
                __( 'A non-empty feed_ids array is required.', 'woo-product-feed-pro' ),
                array( 'status' => 400 )
            );
        }

        $changes = array();
        foreach ( array( 'country', 'file_format', 'refresh_interval', 'post_status' ) as $key ) {
            if ( isset( $data[ $key ] ) ) {
                $changes[ $key ] = (string) $data[ $key ];
            }
        }
        if ( array_key_exists( 'utm_enabled', $data ) ) {
            $changes['utm_enabled'] = (bool) $data['utm_enabled'];
        }
        $changes = array_merge( $changes, $this->sanitize_utm_props( $data ) );

        if ( empty( $changes ) ) {
            return new \WP_Error(
                'adt_nothing_to_update',
                __( 'Nothing to update. Provide at least one property to apply.', 'woo-product-feed-pro' ),
                array( 'status' => 400 )
            );
        }

        if ( isset( $changes['file_format'] ) ) {
            $format_error = $this->validate_file_format( $changes['file_format'] );
            if ( is_wp_error( $format_error ) ) {
                return $format_error;
            }
        }

        if ( isset( $changes['refresh_interval'] ) ) {
            $interval_error = $this->validate_refresh_interval( $changes['refresh_interval'] );
            if ( is_wp_error( $interval_error ) ) {
                return $interval_error;
            }
        }

        $results       = array();
        $updated_count = 0;

        foreach ( $feed_ids as $feed_id ) {
            $feed = Product_Feed_Helper::get_product_feed( is_numeric( $feed_id ) ? (int) $feed_id : (string) $feed_id );
            if ( ! $feed || ! $feed->id ) {
                $results[] = array(
                    'feed_id' => $feed_id,
                    'updated' => false,
                    'error'   => 'not_found',
                );
                continue;
            }

            $props      = $changes;
            $reschedule = array_key_exists( 'refresh_interval', $props ) || array_key_exists( 'post_status', $props );

            try {
                if ( isset( $props['post_status'] ) ) {
                    $feed->post_status = (string) $props['post_status'];
                    unset( $props['post_status'] );
                }

                $feed->set_props( $props );
                $feed->save();

                if ( $reschedule ) {
                    if ( 'publish' === $feed->post_status ) {
                        $feed->register_action();
                    } else {
                        $feed->unregister_action();
                    }
                }

                ++$updated_count;
                $results[] = array(
                    'feed_id' => (int) $feed->id,
                    'updated' => true,
                );
            } catch ( \Exception $e ) {
                $results[] = array(
                    'feed_id' => (int) $feed->id,
                    'updated' => false,
                    'error'   => $e->getMessage(),
                );
            }
        }

        return array(
            'requested' => count( $feed_ids ),
            'updated'   => $updated_count,
            'results'   => $results,
        );
    }

    /**
     * Execute: read the site's tracking/pixel status.
     *
     * Facebook Pixel and Google Ads dynamic remarketing are features of this
     * plugin; the Facebook Conversion API entry defaults to unsupported and is
     * overridden by Product Feed Elite via the filter below.
     *
     * @since 13.5.6
     * @access public
     *
     * @return array The tracking status for each supported integration.
     */
    public function get_tracking_status() {
        $pixel_id      = (string) get_option( 'adt_facebook_pixel_id', '' );
        $conversion_id = (string) get_option( 'adt_adwords_conversion_id', '' );

        $status = array(
            'facebook_pixel'         => array(
                'enabled'  => ( 'yes' === get_option( 'adt_add_facebook_pixel', 'no' ) ) && is_numeric( $pixel_id ) && (int) $pixel_id > 0,
                'pixel_id' => $pixel_id,
            ),
            'facebook_capi'          => array(
                'supported' => false,
                'note'      => __( 'The Facebook Conversion API is a Product Feed Elite feature.', 'woo-product-feed-pro' ),
            ),
            'google_ads_remarketing' => array(
                'enabled'       => ( 'yes' === get_option( 'adt_add_remarketing', 'no' ) ) && is_numeric( $conversion_id ) && (int) $conversion_id > 0,
                'conversion_id' => $conversion_id,
            ),
            'google_analytics'       => array(
                'supported' => false,
                'note'      => __( 'Google Analytics is configured per feed via UTM tagging (see adtribes/set-utm-tagging), not as a site-wide setting.', 'woo-product-feed-pro' ),
            ),
            'tiktok_pixel'           => array(
                'supported' => false,
                'note'      => __( 'TikTok Pixel is not a feature of this plugin.', 'woo-product-feed-pro' ),
            ),
        );

        /**
         * Filter the tracking status reported by the adtribes/get-tracking-status ability.
         *
         * Lets extensions report integrations this plugin does not own — Product
         * Feed Elite replaces the `facebook_capi` entry with its Conversion API state.
         *
         * @since 13.5.6
         *
         * @param array $status The tracking status, keyed by integration.
         */
        return apply_filters( 'adt_pfp_ability_tracking_status', $status );
    }

    /**
     * Execute: configure the Facebook Pixel.
     *
     * Writes the same site options the admin Settings → General screen writes
     * (autoloaded, since they are read on the front end).
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The updated pixel configuration, or a WP_Error on failure.
     */
    public function set_facebook_pixel( $input = null ) {
        $data    = $this->input_array( $input );
        $updated = array();

        if ( array_key_exists( 'pixel_id', $data ) ) {
            $pixel_id = sanitize_text_field( (string) $data['pixel_id'] );
            if ( '' !== $pixel_id && ! ctype_digit( $pixel_id ) ) {
                return new \WP_Error(
                    'adt_invalid_pixel_id',
                    __( 'The Facebook Pixel ID must be numeric.', 'woo-product-feed-pro' ),
                    array( 'status' => 400 )
                );
            }
            update_option( 'adt_facebook_pixel_id', $pixel_id, true );
            $updated['pixel_id'] = $pixel_id;
        }

        if ( array_key_exists( 'enabled', $data ) ) {
            update_option( 'adt_add_facebook_pixel', $data['enabled'] ? 'yes' : 'no', true );
            $updated['enabled'] = (bool) $data['enabled'];
        }

        if ( array_key_exists( 'content_ids', $data ) ) {
            $content_ids = ( 'variation' === $data['content_ids'] ) ? 'variation' : 'variable';
            update_option( 'adt_facebook_pixel_content_ids', $content_ids, true );
            $updated['content_ids'] = $content_ids;
        }

        if ( empty( $updated ) ) {
            return new \WP_Error(
                'adt_nothing_to_update',
                __( 'Nothing to update. Provide pixel_id, enabled and/or content_ids.', 'woo-product-feed-pro' ),
                array( 'status' => 400 )
            );
        }

        return array(
            'updated'        => $updated,
            'facebook_pixel' => array(
                'enabled'  => 'yes' === get_option( 'adt_add_facebook_pixel', 'no' ),
                'pixel_id' => (string) get_option( 'adt_facebook_pixel_id', '' ),
            ),
        );
    }

    /**
     * Execute: configure Google Ads dynamic remarketing.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The updated configuration, or a WP_Error on failure.
     */
    public function set_google_tracking( $input = null ) {
        $data    = $this->input_array( $input );
        $updated = array();

        if ( array_key_exists( 'conversion_id', $data ) ) {
            $conversion_id = sanitize_text_field( (string) $data['conversion_id'] );
            if ( '' !== $conversion_id && ! ctype_digit( $conversion_id ) ) {
                return new \WP_Error(
                    'adt_invalid_conversion_id',
                    __( 'The Google Ads conversion ID must be numeric.', 'woo-product-feed-pro' ),
                    array( 'status' => 400 )
                );
            }
            update_option( 'adt_adwords_conversion_id', $conversion_id, true );
            $updated['conversion_id'] = $conversion_id;
        }

        if ( array_key_exists( 'enabled', $data ) ) {
            update_option( 'adt_add_remarketing', $data['enabled'] ? 'yes' : 'no', true );
            $updated['enabled'] = (bool) $data['enabled'];
        }

        if ( empty( $updated ) ) {
            return new \WP_Error(
                'adt_nothing_to_update',
                __( 'Nothing to update. Provide conversion_id and/or enabled.', 'woo-product-feed-pro' ),
                array( 'status' => 400 )
            );
        }

        return array(
            'updated'                => $updated,
            'google_ads_remarketing' => array(
                'enabled'       => 'yes' === get_option( 'adt_add_remarketing', 'no' ),
                'conversion_id' => (string) get_option( 'adt_adwords_conversion_id', '' ),
            ),
        );
    }

    /**
     * Execute: products excluded from a feed.
     *
     * Returns the derived per-feed excluded count (published total minus the count
     * actually written to the feed file) and the products flagged with the manual
     * per-product exclude toggle. The plugin does not record which filter/rule
     * excluded which product, so per-rule attribution is unavailable; the manual
     * exclude flag also applies globally rather than per feed.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The excluded-products report, or a WP_Error on failure.
     */
    public function get_excluded_products( $input = null ) {
        $feed = $this->get_feed_from_input( $input );
        if ( is_wp_error( $feed ) ) {
            return $feed;
        }

        $data  = $this->input_array( $input );
        $limit = isset( $data['limit'] ) ? min( 500, max( 1, absint( $data['limit'] ) ) ) : 100;

        // Derived per-feed excluded count: published total (pre-filter) minus the
        // number of products actually written to the generated file.
        $products_count   = (int) $feed->products_count;
        $products_in_feed = $this->latest_history_count( (array) $feed->history_products );
        $has_run          = '' !== (string) $feed->last_updated && null !== $products_in_feed;
        $excluded_count   = ( $has_run && $products_count >= $products_in_feed ) ? $products_count - $products_in_feed : null;

        // The only individually-queryable exclusion state: the manual per-product flag.
        $manual_ids = get_posts(
            array(
                'post_type'      => 'product',
                'post_status'    => 'any',
                'posts_per_page' => $limit,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                    array(
                        'key'   => '_woosea_exclude_product',
                        'value' => 'Yes',
                    ),
                ),
            )
        );

        $manually_excluded = array();
        foreach ( $manual_ids as $product_id ) {
            $manually_excluded[] = array(
                'product_id' => (int) $product_id,
                'name'       => get_the_title( $product_id ),
                'reason'     => 'manually_excluded',
            );
        }

        return array(
            'feed_id'                 => (int) $feed->id,
            'excluded_count'          => $excluded_count,
            'excluded_count_note'     => __( 'Derived as the published product total minus the count actually written to the feed file; null until the feed has generated at least once.', 'woo-product-feed-pro' ),
            'manually_excluded'       => $manually_excluded,
            'manually_excluded_count' => count( $manually_excluded ),
            'attribution_note'        => __( 'Products flagged with the manual per-product "exclude from feed" toggle. That flag applies to all feeds, not only this one. Rule/filter-based exclusions are not individually tracked, so per-rule attribution is unavailable.', 'woo-product-feed-pro' ),
        );
    }

    /**
     * Execute: per-feed statistics.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The feed statistics, or a WP_Error on failure.
     */
    public function get_feed_stats( $input = null ) {
        $feed = $this->get_feed_from_input( $input );
        if ( is_wp_error( $feed ) ) {
            return $feed;
        }

        $file_path   = (string) $feed->get_file_path();
        $file_exists = '' !== $file_path && file_exists( $file_path );

        $products_count   = (int) $feed->products_count;
        $products_in_feed = $this->latest_history_count( (array) $feed->history_products );
        $has_run          = '' !== (string) $feed->last_updated && null !== $products_in_feed;
        $excluded_count   = ( $has_run && $products_count >= $products_in_feed ) ? $products_count - $products_in_feed : null;

        $channel = $feed->channel;

        return array(
            'feed_id'          => (int) $feed->id,
            'title'            => (string) $feed->title,
            'channel'          => is_array( $channel ) && isset( $channel['name'] ) ? (string) $channel['name'] : '',
            'status'           => (string) $feed->status,
            'has_error'        => 'error' === (string) $feed->status,
            'products_count'   => $products_count,
            'products_in_feed' => $has_run ? (int) $products_in_feed : null,
            'excluded_count'   => $excluded_count,
            'file_url'         => (string) $feed->get_file_url(),
            'file_size'        => $file_exists ? (int) filesize( $file_path ) : 0,
            'file_exists'      => (bool) $file_exists,
            'last_updated'     => (string) $feed->last_updated,
        );
    }

    /**
     * Execute: aggregate statistics across all feeds for a channel.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The channel summary, or a WP_Error on failure.
     */
    public function get_channel_summary( $input = null ) {
        $data       = $this->input_array( $input );
        $identifier = isset( $data['channel'] ) ? (string) $data['channel'] : '';
        $country    = isset( $data['country'] ) ? (string) $data['country'] : '';

        $channel = Formatter::resolve_channel( $identifier, $country );
        if ( ! $channel ) {
            return new \WP_Error(
                'adt_unknown_channel',
                /* translators: %s: channel identifier. */
                sprintf( __( 'Unknown channel: %s', 'woo-product-feed-pro' ), $identifier ),
                array( 'status' => 400 )
            );
        }

        // Fetch one more than the cap so a hidden remainder can be reported to the caller.
        $ids = get_posts(
            array(
                'post_type'      => Product_Feed::POST_TYPE,
                'post_status'    => 'any',
                'posts_per_page' => self::CHANNEL_SUMMARY_LIMIT + 1,
                'orderby'        => 'ID',
                'order'          => 'DESC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                    array(
                        'key'   => 'adt_channel_hash',
                        'value' => $channel['channel_hash'],
                    ),
                ),
            )
        );

        $truncated = count( $ids ) > self::CHANNEL_SUMMARY_LIMIT;
        if ( $truncated ) {
            $ids = array_slice( $ids, 0, self::CHANNEL_SUMMARY_LIMIT );
        }

        $feeds           = array();
        $total_products  = 0;
        $published_count = 0;
        $error_count     = 0;

        foreach ( $ids as $id ) {
            $feed = Product_Feed_Helper::get_product_feed( (int) $id );
            if ( ! $feed ) {
                continue;
            }

            $total_products += (int) $feed->products_count;
            if ( 'publish' === (string) $feed->post_status ) {
                ++$published_count;
            }
            if ( 'error' === (string) $feed->status ) {
                ++$error_count;
            }

            // Trimmed row only — the full per-feed payload is available via
            // adtribes/list-feeds with a channel filter; this call is an aggregate.
            $feeds[] = array(
                'feed_id'        => (int) $feed->id,
                'title'          => (string) $feed->title,
                'status'         => (string) $feed->status,
                'post_status'    => (string) $feed->post_status,
                'products_count' => (int) $feed->products_count,
            );
        }

        return array(
            'channel'         => isset( $channel['name'] ) ? (string) $channel['name'] : '',
            'channel_hash'    => (string) $channel['channel_hash'],
            'feed_count'      => count( $feeds ),
            'truncated'       => $truncated,
            'published_count' => $published_count,
            'error_count'     => $error_count,
            'total_products'  => $total_products,
            'feeds'           => $feeds,
        );
    }

    /**
     * Execute: configure a feed's refresh schedule.
     *
     * Standard intervals are handled by this plugin. A custom interval requires
     * Product Feed Elite: when Elite is active the feed instance returned by the
     * helper is the Elite factory, whose set_props()/register_action() persist and
     * schedule the custom interval; without Elite the request is rejected.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The updated schedule, or a WP_Error on failure.
     */
    public function set_feed_schedule( $input = null ) {
        $feed = $this->get_feed_from_input( $input );
        if ( is_wp_error( $feed ) ) {
            return $feed;
        }

        $data     = $this->input_array( $input );
        $interval = isset( $data['refresh_interval'] ) ? (string) $data['refresh_interval'] : '';

        if ( 'custom' === $interval ) {
            if ( ! class_exists( '\AdTribes\PFE\Factories\Product_Feed' ) ) {
                return new \WP_Error(
                    'adt_requires_elite',
                    __( 'Custom refresh intervals require Product Feed Elite. Use a standard interval (hourly, twicedaily, daily) instead.', 'woo-product-feed-pro' ),
                    array( 'status' => 400 )
                );
            }

            $schedule        = isset( $data['schedule'] ) ? (string) $data['schedule'] : 'daily';
            $valid_schedules = array( 'hourly', 'hours', 'daily', 'twicedaily', 'weekly', 'monthly', 'yearly' );
            if ( ! in_array( $schedule, $valid_schedules, true ) ) {
                return new \WP_Error(
                    'adt_invalid_schedule',
                    /* translators: %s: comma-separated list of valid schedules. */
                    sprintf( __( 'Invalid schedule. Valid values: %s', 'woo-product-feed-pro' ), implode( ', ', $valid_schedules ) ),
                    array( 'status' => 400 )
                );
            }

            $custom_refresh_interval = array(
                'schedule'       => $schedule,
                'schedule_hours' => isset( $data['schedule_hours'] ) ? absint( $data['schedule_hours'] ) : '',
                'days'           => ( isset( $data['days'] ) && is_array( $data['days'] ) && ! empty( $data['days'] ) ) ? array_map( 'absint', $data['days'] ) : array( 1, 2, 3, 4, 5, 6, 7 ),
                'commence'       => 'now',
                'commence_date'  => '',
            );

            $feed->set_props(
                array(
                    'refresh_interval'        => 'custom',
                    'custom_refresh_interval' => $custom_refresh_interval,
                )
            );
        } else {
            if ( ! in_array( $interval, self::STANDARD_INTERVALS, true ) ) {
                return new \WP_Error(
                    'adt_invalid_interval',
                    __( 'refresh_interval must be one of: (empty), hourly, twicedaily, daily, custom.', 'woo-product-feed-pro' ),
                    array( 'status' => 400 )
                );
            }

            $feed->set_props( array( 'refresh_interval' => $interval ) );
        }

        try {
            $feed->save();

            if ( 'publish' === (string) $feed->post_status && '' !== (string) $feed->refresh_interval ) {
                $feed->register_action();
            } else {
                $feed->unregister_action();
            }
        } catch ( \Exception $e ) {
            return new \WP_Error( 'adt_schedule_failed', $e->getMessage(), array( 'status' => 500 ) );
        }

        $scheduled = 'publish' === (string) $feed->post_status && '' !== (string) $feed->refresh_interval;

        return array(
            'feed_id'                 => (int) $feed->id,
            'refresh_interval'        => (string) $feed->refresh_interval,
            'custom_refresh_interval' => ( 'custom' === (string) $feed->refresh_interval && method_exists( $feed, 'get_custom_refresh_interval' ) ) ? $feed->get_custom_refresh_interval() : null,
            'post_status'             => (string) $feed->post_status,
            'scheduled'               => $scheduled,
        );
    }

    /**
     * Execute: pause a feed's scheduled refresh.
     *
     * Unregisters the recurring Action Scheduler action without deleting the feed
     * or clearing its stored interval (when Product Feed Elite is active the feed
     * instance's override also clears the Elite group). The feed stays published
     * and accessible; resume it by calling adtribes/set-feed-schedule.
     *
     * @since 13.5.6
     * @access public
     *
     * @param mixed $input The ability input.
     * @return array|\WP_Error The pause result, or a WP_Error on failure.
     */
    public function pause_feed_schedule( $input = null ) {
        $feed = $this->get_feed_from_input( $input );
        if ( is_wp_error( $feed ) ) {
            return $feed;
        }

        // Only a published feed with a stored interval has a live schedule to
        // pause; unregister runs regardless to clear any stray scheduled action.
        $was_scheduled = 'publish' === (string) $feed->post_status && '' !== (string) $feed->refresh_interval;

        $feed->unregister_action();

        return array(
            'feed_id'          => (int) $feed->id,
            'paused'           => $was_scheduled,
            'refresh_interval' => (string) $feed->refresh_interval,
            'post_status'      => (string) $feed->post_status,
        );
    }

    /*
     * Internal helpers.
     */

    /**
     * Register an ability unless another plugin already registered the name.
     *
     * Product Feed Elite <= 5.0.9 registers some of the abilities this plugin now
     * owns; Elite runs earlier on the `wp_abilities_api_init` hook, so skipping an
     * already-registered name here lets those sites keep the Elite registration
     * without a duplicate-registration notice.
     *
     * @since 13.5.6
     * @access private
     *
     * @param string $name The ability name, including the namespace prefix.
     * @param array  $args The ability registration arguments.
     */
    private function maybe_register( $name, $args ) {
        if ( wp_has_ability( $name ) ) {
            return;
        }

        wp_register_ability( $name, $args );
    }

    /**
     * Build the meta array for an ability.
     *
     * @since 13.5.6
     * @access private
     *
     * @param array $annotations The annotation triplet.
     * @return array The meta array.
     */
    private function meta( $annotations ) {
        return array(
            'annotations'  => $annotations,
            'show_in_rest' => true,
        );
    }

    /**
     * Annotations for a read-only, idempotent ability.
     *
     * @since 13.5.6
     * @access private
     *
     * @return array The annotation triplet.
     */
    private function annotations_read() {
        return array(
            'readonly'   => true,
            'idempotent' => true,
        );
    }

    /**
     * Annotations for a destructive ability.
     *
     * @since 13.5.6
     * @access private
     *
     * @param bool $idempotent Whether calling the ability repeatedly is idempotent.
     * @return array The annotation triplet.
     */
    private function annotations_write( $idempotent ) {
        return array(
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => (bool) $idempotent,
        );
    }

    /**
     * Normalize the ability input to an associative array.
     *
     * @since 13.5.6
     * @access private
     *
     * @param mixed $input The raw ability input.
     * @return array The input as an array.
     */
    private function input_array( $input ) {
        return is_array( $input ) ? $input : array();
    }

    /**
     * Validate a feed file format against the supported list.
     *
     * @since 13.5.6
     * @access private
     *
     * @param string $format The file format to validate.
     * @return true|\WP_Error True when supported, or a WP_Error otherwise.
     */
    private function validate_file_format( $format ) {
        $allowed_formats = Formatter::supported_file_formats();
        if ( ! in_array( $format, $allowed_formats, true ) ) {
            return new \WP_Error(
                'adt_unsupported_format',
                /* translators: 1: file format, 2: comma-separated list of allowed formats. */
                sprintf( __( 'Unsupported file format: %1$s. Allowed: %2$s', 'woo-product-feed-pro' ), $format, implode( ', ', $allowed_formats ) ),
                array( 'status' => 400 )
            );
        }

        return true;
    }

    /**
     * Validate a standard (non-custom) refresh interval.
     *
     * @since 13.5.6
     * @access private
     *
     * @param string $interval The refresh interval to validate.
     * @return true|\WP_Error True when valid, or a WP_Error otherwise.
     */
    private function validate_refresh_interval( $interval ) {
        if ( ! in_array( $interval, self::STANDARD_INTERVALS, true ) ) {
            return new \WP_Error(
                'adt_invalid_interval',
                __( 'refresh_interval must be one of: (empty), hourly, twicedaily, daily.', 'woo-product-feed-pro' ),
                array( 'status' => 400 )
            );
        }

        return true;
    }

    /**
     * Extract and sanitize the UTM properties from an input array.
     *
     * Holds the UTM key list in a single place so the feed-edit and bulk-edit paths
     * cannot diverge.
     *
     * @since 13.5.6
     * @access private
     *
     * @param array $input The ability input array.
     * @return array The sanitized UTM properties present in the input.
     */
    private function sanitize_utm_props( $input ) {
        $props = array();
        foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) as $key ) {
            if ( isset( $input[ $key ] ) ) {
                $props[ $key ] = sanitize_text_field( (string) $input[ $key ] );
            }
        }

        return $props;
    }

    /**
     * Get the most recent product count from a feed's history_products.
     *
     * Newer versions store each entry as array( 'Y-m-d' => count ); older versions
     * store a bare count. Returns null when there is no history.
     *
     * @since 13.5.6
     * @access private
     *
     * @param array $history_products The feed's history_products array.
     * @return int|null The latest product count, or null when there is no history.
     */
    private function latest_history_count( $history_products ) {
        if ( empty( $history_products ) ) {
            return null;
        }

        $latest = end( $history_products );
        if ( is_array( $latest ) ) {
            $value = reset( $latest );
            return null === $value ? null : (int) $value;
        }

        return (int) $latest;
    }

    /**
     * Resolve the `feed_id` input to a Product_Feed, or a WP_Error.
     *
     * @since 13.5.6
     * @access private
     *
     * @param mixed $input The ability input.
     * @return Product_Feed|\WP_Error The feed, or a WP_Error when missing/not found.
     */
    private function get_feed_from_input( $input ) {
        $data    = $this->input_array( $input );
        $feed_id = isset( $data['feed_id'] ) ? $data['feed_id'] : null;

        if ( empty( $feed_id ) ) {
            return new \WP_Error(
                'adt_missing_feed_id',
                __( 'A feed_id is required.', 'woo-product-feed-pro' ),
                array( 'status' => 400 )
            );
        }

        $feed = Product_Feed_Helper::get_product_feed( is_numeric( $feed_id ) ? (int) $feed_id : (string) $feed_id );
        if ( ! $feed || ! $feed->id ) {
            return new \WP_Error(
                'adt_feed_not_found',
                /* translators: %s: feed identifier. */
                sprintf( __( 'Feed not found: %s', 'woo-product-feed-pro' ), $feed_id ),
                array( 'status' => 404 )
            );
        }

        return $feed;
    }

    /**
     * Append a single filter group to a feed.
     *
     * Builds the same structure the admin Filters tab persists, using the
     * builder's own condition vocabulary for validation.
     *
     * @since 13.5.6
     * @access private
     *
     * @param Product_Feed $feed The feed.
     * @param array        $data The ability input.
     * @return array|\WP_Error The updated filters, or a WP_Error on failure.
     */
    private function add_filter( $feed, $data ) {
        $attribute = isset( $data['attribute'] ) ? (string) $data['attribute'] : '';
        if ( '' === $attribute ) {
            return new \WP_Error(
                'adt_missing_attribute',
                __( 'An attribute is required for a filter.', 'woo-product-feed-pro' ),
                array( 'status' => 400 )
            );
        }

        $valid_conditions = Filters::instance()->get_conditions( true );
        $condition        = isset( $data['condition'] ) ? (string) $data['condition'] : '';
        if ( ! in_array( $condition, $valid_conditions, true ) ) {
            return new \WP_Error(
                'adt_invalid_condition',
                /* translators: %s: comma-separated list of valid conditions. */
                sprintf( __( 'Invalid condition. Valid values: %s', 'woo-product-feed-pro' ), implode( ', ', $valid_conditions ) ),
                array( 'status' => 400 )
            );
        }

        $mode  = ( isset( $data['mode'] ) && 'exclude' === $data['mode'] ) ? 'exclude' : 'include';
        $value = in_array( $condition, array( 'is_empty', 'is_not_empty' ), true ) ? '' : ( isset( $data['value'] ) ? (string) $data['value'] : '' );

        $group = array(
            'type'   => 'group',
            'fields' => array(
                array(
                    'type' => 'field',
                    'data' => array(
                        'attribute'      => $attribute,
                        'condition'      => $condition,
                        'value'          => $value,
                        'case_sensitive' => ! empty( $data['case_sensitive'] ),
                    ),
                ),
            ),
        );

        $feed_filters = (array) $feed->feed_filters;
        if ( empty( $feed_filters[ $mode ] ) ) {
            $feed_filters[ $mode ] = array( $group );
        } else {
            $group_logic             = ( isset( $data['group_logic'] ) && 'or' === $data['group_logic'] ) ? 'or' : 'and';
            $feed_filters[ $mode ][] = array(
                'type'  => 'group_logic',
                'value' => $group_logic,
            );
            $feed_filters[ $mode ][] = $group;
        }

        $feed->set_props( array( 'feed_filters' => $feed_filters ) );
        $feed->set_data_version( 'feed_filters', '13.4.6' );
        $feed->save();

        return array(
            'feed_id'      => (int) $feed->id,
            'type'         => 'filter',
            'mode'         => $mode,
            'feed_filters' => $feed_filters,
        );
    }

    /**
     * Append a single transform rule to a feed.
     *
     * @since 13.5.6
     * @since 13.5.7 Reject combine_fields — its ordered segments list cannot be expressed
     *               through this ability's flat rule shape. truncate/in_list are accepted
     *               (they are valid via get_actions()/get_conditions()) but, like the
     *               pre-existing set_attribute/exclude precedent, save as silent no-ops on
     *               Pro since no handler exists for them here.
     * @access private
     *
     * @param Product_Feed $feed The feed.
     * @param array        $data The ability input.
     * @return array|\WP_Error The updated rules, or a WP_Error on failure.
     */
    private function add_rule( $feed, $data ) {
        $if_attribute = isset( $data['attribute'] ) ? (string) $data['attribute'] : '';
        if ( '' === $if_attribute ) {
            return new \WP_Error(
                'adt_missing_attribute',
                __( 'An attribute is required for the rule condition.', 'woo-product-feed-pro' ),
                array( 'status' => 400 )
            );
        }

        $valid_conditions = Rules::instance()->get_conditions( true );
        $condition        = isset( $data['condition'] ) ? (string) $data['condition'] : '';
        if ( ! in_array( $condition, $valid_conditions, true ) ) {
            return new \WP_Error(
                'adt_invalid_condition',
                /* translators: %s: comma-separated list of valid conditions. */
                sprintf( __( 'Invalid condition. Valid values: %s', 'woo-product-feed-pro' ), implode( ', ', $valid_conditions ) ),
                array( 'status' => 400 )
            );
        }

        $valid_actions = Rules::instance()->get_actions( true );
        $action_type   = isset( $data['action'] ) ? (string) $data['action'] : '';
        if ( ! in_array( $action_type, $valid_actions, true ) ) {
            return new \WP_Error(
                'adt_invalid_action',
                /* translators: %s: comma-separated list of valid actions. */
                sprintf( __( 'Invalid action. Valid values: %s', 'woo-product-feed-pro' ), implode( ', ', $valid_actions ) ),
                array( 'status' => 400 )
            );
        }

        // combine_fields is configured through an ordered segments list this ability's
        // rule shape cannot express — a rule created here would save but never apply.
        if ( 'combine_fields' === $action_type ) {
            return new \WP_Error(
                'adt_unsupported_action',
                __( 'The combine_fields action requires a segments configuration this ability does not support. Configure it in the Filters & Rules builder instead.', 'woo-product-feed-pro' ),
                array( 'status' => 400 )
            );
        }

        $value            = in_array( $condition, array( 'is_empty', 'is_not_empty' ), true ) ? '' : ( isset( $data['value'] ) ? (string) $data['value'] : '' );
        $action_attribute = isset( $data['action_attribute'] ) ? (string) $data['action_attribute'] : $if_attribute;

        $rule = array(
            'name' => isset( $data['name'] ) ? (string) $data['name'] : '',
            'if'   => array(
                array(
                    'type'   => 'group',
                    'fields' => array(
                        array(
                            'type' => 'field',
                            'data' => array(
                                'attribute'      => $if_attribute,
                                'condition'      => $condition,
                                'value'          => $value,
                                'case_sensitive' => ! empty( $data['case_sensitive'] ),
                            ),
                        ),
                    ),
                ),
            ),
            'then' => array(
                array(
                    'attribute'      => $action_attribute,
                    'action'         => $action_type,
                    'value'          => isset( $data['action_value'] ) ? (string) $data['action_value'] : '',
                    'find'           => isset( $data['find'] ) ? (string) $data['find'] : '',
                    'case_sensitive' => ! empty( $data['case_sensitive'] ),
                ),
            ),
        );

        $feed_rules   = (array) $feed->feed_rules;
        $feed_rules[] = $rule;

        $feed->set_props( array( 'feed_rules' => $feed_rules ) );
        $feed->set_data_version( 'feed_rules', '13.4.6' );
        $feed->save();

        return array(
            'feed_id'    => (int) $feed->id,
            'type'       => 'rule',
            'feed_rules' => $feed_rules,
        );
    }

    /**
     * Load a channel's field spec via its channel class.
     *
     * @since 13.5.6
     * @access private
     *
     * @param string $fields The channel `fields` key (channel class suffix).
     * @return array|\WP_Error The channel attribute spec, or a WP_Error on failure.
     */
    private function get_channel_spec( $fields ) {
        $class_name = 'WooSEA_' . $fields;

        if ( ! class_exists( $class_name ) ) {
            $file = ADT_PFP_CHANNEL_CLASS_ROOT_PATH . 'class-' . $fields . '.php';
            if ( ! file_exists( $file ) ) {
                return new \WP_Error(
                    'adt_channel_spec_missing',
                    __( 'The channel definition could not be found.', 'woo-product-feed-pro' ),
                    array( 'status' => 500 )
                );
            }
            require_once $file;
        }

        if ( ! class_exists( $class_name ) || ! method_exists( $class_name, 'get_channel_attributes' ) ) {
            return new \WP_Error(
                'adt_channel_spec_missing',
                __( 'The channel does not expose a field spec.', 'woo-product-feed-pro' ),
                array( 'status' => 500 )
            );
        }

        try {
            $method = new \ReflectionMethod( $class_name, 'get_channel_attributes' );
            if ( $method->isStatic() ) {
                $spec = call_user_func( array( $class_name, 'get_channel_attributes' ) );
            } else {
                $instance = new $class_name();
                $spec     = $instance->get_channel_attributes();
            }
        } catch ( \Throwable $e ) {
            return new \WP_Error( 'adt_channel_spec_error', $e->getMessage(), array( 'status' => 500 ) );
        }

        return is_array( $spec ) ? $spec : array();
    }
}
