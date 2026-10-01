<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Updates
 */

namespace AdTribes\PFP\Updates;

use AdTribes\PFP\Abstracts\Abstract_Update;
use AdTribes\PFP\Classes\Phantom_Feed_Cleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Class Version_13_6_0_Phantom_Feed_Cleanup_Update
 *
 * Starts the removal of the phantom `adt_product_feed` posts earlier versions
 * accumulated (issue #1189).
 *
 * The migration deletes no post itself. Affected sites hold hundreds to over
 * eleven thousand phantoms, which no single admin request can delete, so this
 * only queues the Action Scheduler chain that deletes them one bounded batch at a
 * time. `Phantom_Feed_Cleanup::schedule()` is a no-op on a site that has none.
 *
 * It does clear one option row here, because that one is cheap and the chain
 * cannot reach it. Saving a phantom ran `Product_Feed::save_legacy_options()`,
 * which appends the feed to `adt_cron_projects` keyed on its project hash. A
 * phantom has none, so every phantom on the site collapsed into a single entry
 * with an empty `project_hash` - and that entry is exactly the blank, hashless
 * placeholder `Version_13_3_5_Update` has to self-heal around (#1028). Dropping
 * it matches what that migration already does with such a row.
 *
 * Gating/idempotency live in Abstract_Update.
 *
 * @since 13.6.0
 */
class Version_13_6_0_Phantom_Feed_Cleanup_Update extends Abstract_Update {

    /**
     * Per-blog option name flagging this migration as complete.
     *
     * @since 13.6.0
     *
     * @var string
     */
    const MIGRATION_FLAG = 'adt_pfp_phantom_feed_cleanup_queued';

    /**
     * Queue the phantom feed cleanup for this blog.
     *
     * Returns `false` when Action Scheduler is not loaded yet, so the flag stays
     * unset and the next run queues the cleanup instead of skipping it forever.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool|void False when the cleanup could not be queued.
     */
    public function update() {
        if ( ! function_exists( 'as_schedule_single_action' ) ) {
            return false;
        }

        $this->drop_hashless_legacy_projects();

        Phantom_Feed_Cleanup::schedule();
    }

    /**
     * Remove the `adt_cron_projects` entries a phantom feed left behind.
     *
     * An entry with no project hash names no feed: `Product_Feed` keys the option
     * on that hash, and `Version_13_3_5_Update` skips such an entry outright.
     *
     * @since 13.6.0
     * @access private
     */
    private function drop_hashless_legacy_projects() {
        $cron_projects = get_option( 'adt_cron_projects', array() );

        if ( ! is_array( $cron_projects ) || empty( $cron_projects ) ) {
            return;
        }

        $kept = array_filter(
            $cron_projects,
            function ( $project ) {
                return ! is_array( $project ) || ! empty( $project['project_hash'] );
            }
        );

        if ( count( $kept ) !== count( $cron_projects ) ) {
            update_option( 'adt_cron_projects', $kept, false );
        }
    }
}
