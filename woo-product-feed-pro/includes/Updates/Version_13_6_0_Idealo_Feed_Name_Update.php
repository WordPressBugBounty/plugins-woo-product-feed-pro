<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Updates
 */

namespace AdTribes\PFP\Updates;

use AdTribes\PFP\Abstracts\Abstract_Update;
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Helpers\Product_Feed_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Class Version_13_6_0_Idealo_Feed_Name_Update
 *
 * Renames three misspelled output field names on existing Idealo feeds to
 * idealo's spec names (issue #1163).
 *
 * The Idealo channel registered three rows with a correct internal `name` but a
 * misspelled `feed_name`. The `feed_name` is the value the Field Mapping tab saves
 * and the writer emits as the column header, so every feed that mapped one of
 * those rows exports a column idealo does not recognise, with no error shown.
 * Correcting the channel definition only fixes feeds created or re-saved
 * afterwards: a feed persists its own mapping, so existing feeds keep emitting the
 * misspelled column until the merchant re-maps the row by hand. This migration
 * rewrites those stored rows instead.
 *
 * Both storage shapes are rewritten, because a feed can be in either state:
 *
 * - `adt_feed_attributes` (rows keyed `feed_name`) — the normalized schema
 *   `Feed_Attributes_Migrator` writes. Its `VERSION` carries an explicit
 *   do-not-bump warning, so a migrated feed can only be corrected from outside
 *   that class.
 * - `adt_attributes` (rows keyed `attribute`) — the legacy shape. Still the live
 *   value on an unmigrated feed, and never cleared after migration. Rewriting it
 *   also keeps a later migration from re-introducing a misspelling, and from
 *   classifying the row as a custom field because it no longer matches any Idealo
 *   channel attribute.
 *
 * The rename is confined to the output field name. The row's data source, prefix
 * and suffix are untouched, so the merchant's mapping is preserved and only the
 * emitted column header changes. It is idempotent: a row already using a spec
 * name matches no key in the map and is left as-is.
 *
 * KNOWN LIMITATION. The per-blog flag makes this a one-shot pass over the feeds
 * present when it runs, so a feed that arrives afterwards still carries whatever
 * name it was saved with. The one reachable route is
 * `Export_Import_Tools`: a feed exported from a site running an older version and
 * imported into an upgraded one keeps the misspelled name, and the flag is
 * already set. Healing that durably means normalizing on import rather than in a
 * one-shot migration, which is a wider change than this issue calls for.
 *
 * Gating/idempotency live in Abstract_Update.
 *
 * @since 13.6.0
 */
class Version_13_6_0_Idealo_Feed_Name_Update extends Abstract_Update {

    /**
     * Per-blog option name flagging this migration as complete.
     *
     * @since 13.6.0
     *
     * @var string
     */
    const MIGRATION_FLAG = 'adt_pfp_idealo_feed_name_rename_done';

    /**
     * The `fields` value identifying an Idealo feed's channel.
     *
     * Only `Idealo.de` uses it. `Idealo.fr`, `Idealo.it` and `Idealo.co.uk` are
     * `customfeed` channels and carry none of these attributes.
     *
     * @since 13.6.0
     *
     * @var string
     */
    const CHANNEL_FIELDS = 'idealo';

    /**
     * Misspelled -> spec output field name map.
     *
     * Mirrors the corrections applied to the channel definition in
     * `classes/channels/class-idealo.php`. Add a pair here whenever a channel
     * `feed_name` is corrected, so existing feeds follow the definition.
     *
     * @since 13.6.0
     * @access private
     *
     * @var array<string,string>
     */
    private $field_rename_map = array(
        'deliveryCost_trand_o_flex' => 'deliveryCost_trans_o_flex',
        'merchanId'                 => 'merchantId',
        'alcoholicConent'           => 'alcoholicContent',
    );

    /**
     * Rewrite the stored output field names on every Idealo feed.
     *
     * Returns `false` when the feed lookup itself failed, so `Abstract_Update`
     * leaves the flag unset and retries. Without that the one shot this migration
     * gets is spent on a failed query, and no feed on the blog is ever healed.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool|void False when the feed lookup failed, so the migration retries.
     */
    public function update() {
        global $wpdb;

        // Read the channel hash alongside the ID. Calling `get_post_meta()` per
        // feed would prime that feed's whole meta cache — `history_products`
        // included, a serialized product-ID blob on a large catalog — for every
        // feed on the site, including the ones this migration then skips.
        $feeds = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID AS id, m.meta_value AS channel_hash
                   FROM {$wpdb->posts} p
                   INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
                  WHERE p.post_type = %s",
                Product_Feed::META_PREFIX . 'channel_hash',
                Product_Feed::POST_TYPE
            )
        );

        // An empty result and a failed query both come back as an empty array, and
        // they have opposite obligations: nothing to do versus retry next run.
        if ( ! empty( $wpdb->last_error ) ) {
            return false;
        }

        foreach ( $feeds as $feed ) {
            if ( ! $this->is_idealo_channel( (string) $feed->channel_hash ) ) {
                continue;
            }

            $this->rename_rows( (int) $feed->id, 'feed_attributes', 'feed_name' );
            $this->rename_rows( (int) $feed->id, 'attributes', 'attribute' );
        }
    }

    /**
     * Whether the given channel hash resolves to the Idealo attribute list.
     *
     * The hash is resolved through `Product_Feed_Helper` rather than compared to a
     * literal, so a channel whose hash changes stays matched.
     *
     * @since 13.6.0
     * @access private
     *
     * @param string $channel_hash The feed's stored channel hash.
     * @return bool True when the hash resolves to the Idealo channel.
     */
    private function is_idealo_channel( $channel_hash ) {
        if ( '' === $channel_hash ) {
            return false;
        }

        $channel = Product_Feed_Helper::get_channel_from_legacy_channel_hash( $channel_hash );

        return is_array( $channel ) && self::CHANNEL_FIELDS === ( $channel['fields'] ?? '' );
    }

    /**
     * Rewrite every misspelled output field name in one attribute meta value.
     *
     * Writes only when a row actually changed, so a feed that mapped none of the
     * three rows keeps its meta row untouched.
     *
     * @since 13.6.0
     * @access private
     *
     * @param int    $feed_id  The feed post ID.
     * @param string $meta_key The unprefixed meta key holding the rows.
     * @param string $name_key The row key holding the output field name.
     */
    private function rename_rows( $feed_id, $meta_key, $name_key ) {
        $rows = get_post_meta( $feed_id, Product_Feed::META_PREFIX . $meta_key, true );
        if ( ! is_array( $rows ) ) {
            return;
        }

        $changed = false;
        foreach ( $rows as $index => $row ) {
            if ( ! is_array( $row ) || ! isset( $row[ $name_key ] ) ) {
                continue;
            }

            $current = (string) $row[ $name_key ];
            if ( ! isset( $this->field_rename_map[ $current ] ) ) {
                continue;
            }

            $rows[ $index ][ $name_key ] = $this->field_rename_map[ $current ];
            $changed                     = true;
        }

        if ( $changed ) {
            update_post_meta( $feed_id, Product_Feed::META_PREFIX . $meta_key, $rows );
        }
    }
}
