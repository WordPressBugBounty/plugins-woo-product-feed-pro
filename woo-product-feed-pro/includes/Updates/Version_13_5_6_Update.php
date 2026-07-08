<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Updates
 */

namespace AdTribes\PFP\Updates;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Helpers\Product_Feed_Helper;

/**
 * Class Version_13_5_6_Update
 *
 * Renames the stored output-field keys on existing OpenAI feeds to OpenAI's GA
 * spec names (issue #985).
 *
 * The v13.5.6 OpenAI channel update renamed the channel's default output field
 * names (e.g. `id` -> `item_id`, `link` -> `url`, `enable_search` ->
 * `is_eligible_search`). Those defaults only seed NEW feeds; feeds created
 * before the upgrade persist their own mapping in the `adt_attributes` post meta
 * with the old, non-spec names. Without this migration such feeds keep emitting
 * the old field names and miss the JSON-boolean casting that is keyed on the
 * `is_eligible_*` names.
 *
 * The migration is intentionally conservative:
 * - Only the `attribute` (output field name) is rewritten; the `mapfrom`
 *   WooCommerce data source is left untouched.
 * - `sale_price_effective_date` is NOT migrated — it was split into two separate
 *   fields and cannot be auto-split, so users remap it manually.
 * - Fields dropped from the channel (e.g. `inventory_quantity`) are left in
 *   place rather than deleted, so no user configuration is destroyed.
 * It is idempotent: an attribute already using the new name matches no old key
 * and is left as-is.
 *
 * @since 13.5.6
 */
class Version_13_5_6_Update extends Abstract_Class {

    /**
     * Per-blog option flag marking this migration as complete.
     *
     * Used instead of the network-global installed-version gate so that every site
     * on a multisite network is migrated exactly once (see run()).
     *
     * @since 13.5.6
     */
    const MIGRATION_FLAG = 'adt_pfp_openai_ga_field_rename_done';

    /**
     * Holds the version number.
     *
     * @since 13.5.6
     * @access protected
     *
     * @var string
     */
    protected $version = '13.5.6';

    /**
     * Whether to force update.
     *
     * @since 13.5.6
     * @access protected
     *
     * @var bool
     */
    protected $force_update = false;

    /**
     * Old -> new OpenAI output field name map.
     *
     * Mirrors the renames applied to the channel definition in
     * `classes/channels/class-openai.php`.
     *
     * @since 13.5.6
     * @access private
     *
     * @var array<string,string>
     */
    private $field_rename_map = array(
        'id'                    => 'item_id',
        'link'                  => 'url',
        'image_link'            => 'image_url',
        'additional_image_link' => 'additional_image_urls',
        'video_link'            => 'video_url',
        'model_3d_link'         => 'model_3d_url',
        'enable_search'         => 'is_eligible_search',
        'enable_checkout'       => 'is_eligible_checkout',
        'item_group_id'         => 'group_id',
        'return_window'         => 'return_deadline_in_days',
        'product_review_count'  => 'review_count',
        'product_review_rating' => 'star_rating',
        'store_review_rating'   => 'store_star_rating',
        'raw_review_data'       => 'reviews',
    );

    /**
     * Constructor.
     *
     * @since 13.5.6
     * @access public
     *
     * @param bool $force_update Whether to force update.
     */
    public function __construct( $force_update = false ) {
        $this->force_update = $force_update;
    }

    /**
     * Rewrite stored OpenAI attribute field names to the GA spec names.
     *
     * @since 13.5.6
     * @access public
     */
    public function update() {
        global $wpdb;

        $feed_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'trash'",
                Product_Feed::POST_TYPE
            )
        );

        if ( empty( $feed_ids ) ) {
            return;
        }

        foreach ( $feed_ids as $feed_id ) {
            $channel_hash = get_post_meta( $feed_id, Product_Feed::META_PREFIX . 'channel_hash', true );
            if ( empty( $channel_hash ) ) {
                continue;
            }

            $channel = Product_Feed_Helper::get_channel_from_legacy_channel_hash( $channel_hash );
            if ( ! is_array( $channel ) || ( $channel['fields'] ?? '' ) !== 'openai' ) {
                continue;
            }

            $attributes = get_post_meta( $feed_id, Product_Feed::META_PREFIX . 'attributes', true );
            if ( ! is_array( $attributes ) ) {
                continue;
            }

            $changed = false;
            foreach ( $attributes as $index => $attribute ) {
                if ( ! is_array( $attribute ) || ! isset( $attribute['attribute'] ) ) {
                    continue;
                }

                $current = $attribute['attribute'];
                if ( isset( $this->field_rename_map[ $current ] ) ) {
                    $attributes[ $index ]['attribute'] = $this->field_rename_map[ $current ];
                    $changed                           = true;
                }
            }

            if ( $changed ) {
                update_post_meta( $feed_id, Product_Feed::META_PREFIX . 'attributes', $attributes );
            }
        }
    }

    /**
     * Run the migration.
     *
     * Runs against the CURRENT blog only. `Activation::run()` already loops
     * `$blog_ids` and calls `_activate_plugin()` (which invokes this method)
     * per blog, so this method must not loop blogs itself.
     *
     * @since 13.5.6
     * @access public
     */
    public function run() {
        // Gate on a per-blog option flag rather than the network-global installed-version
        // option the sibling updates use. On multisite, Activation runs this inside a
        // switch_to_blog() loop and bumps the network version option after the first blog,
        // which would leave blogs #2..N unmigrated. A per-blog flag (resolved against the
        // switched blog) ensures each site's stored feed attributes are migrated exactly once.
        if ( ! $this->force_update && 'yes' === get_option( self::MIGRATION_FLAG ) ) {
            return;
        }

        $this->update();

        update_option( self::MIGRATION_FLAG, 'yes', false );
    }
}
