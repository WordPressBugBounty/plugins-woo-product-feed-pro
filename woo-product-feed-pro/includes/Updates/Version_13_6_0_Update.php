<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Updates
 */

namespace AdTribes\PFP\Updates;

use AdTribes\PFP\Abstracts\Abstract_Update;
use AdTribes\PFP\Classes\UCP\Idempotency;

defined( 'ABSPATH' ) || exit;

/**
 * Class Version_13_6_0_Update
 *
 * Gating/idempotency live in Abstract_Update; update() creates the UCP
 * idempotency-key table via dbDelta, which only applies schema
 * differences, so a one-time re-run is a no-op.
 *
 * @since 13.6.0
 */
class Version_13_6_0_Update extends Abstract_Update {

    /**
     * Per-blog option name flagging this migration as complete.
     *
     * @since 13.6.0
     *
     * @var string
     */
    const MIGRATION_FLAG = 'adt_pfp_update_13_6_0_done';

    /**
     * Create the UCP idempotency table.
     *
     * Returns the verified result so a create that failed (e.g. an index
     * the host's MySQL rejects) leaves the migration flag unset and is
     * retried, rather than silently disabling idempotency forever.
     *
     * @since 13.6.0
     *
     * @return bool True when the table exists afterwards.
     */
    public function update() {

        return Idempotency::create_table();
    }
}
