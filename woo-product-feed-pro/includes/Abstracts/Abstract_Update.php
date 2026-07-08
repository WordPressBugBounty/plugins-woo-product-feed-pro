<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Abstracts
 */

namespace AdTribes\PFP\Abstracts;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract base for one-shot upgrade routines.
 *
 * Centralises the per-blog completion-flag gating so each migration runs exactly
 * once per blog. `Activation::run()` invokes `run()` per blog inside a
 * `switch_to_blog()` loop; gating on a per-blog option (rather than the
 * network-global installed-version option, which is bumped after the first blog)
 * ensures every site on a multisite network is migrated.
 *
 * Subclasses only declare `MIGRATION_FLAG` and implement `update()`. `update()`
 * MUST be idempotent: when the flag is first introduced, an install that already
 * ran the migration under the old version gate re-runs it once.
 *
 * @since 13.5.6
 */
abstract class Abstract_Update extends Abstract_Class {

    /**
     * Per-blog option name flagging this migration as complete.
     *
     * Subclasses MUST override with a unique option name.
     *
     * @since 13.5.6
     *
     * @var string
     */
    const MIGRATION_FLAG = '';

    /**
     * Whether to force the migration to run regardless of the completion flag.
     *
     * @since 13.5.6
     * @access protected
     *
     * @var bool
     */
    protected $force_update = false;

    /**
     * Constructor.
     *
     * @since 13.5.6
     * @access public
     *
     * @param bool $force_update Whether to force the migration to run.
     */
    public function __construct( $force_update = false ) {
        $this->force_update = $force_update;
    }

    /**
     * Perform the migration for the current blog.
     *
     * Must be idempotent (see class docblock).
     *
     * @since 13.5.6
     * @access public
     */
    abstract public function update();

    /**
     * Run the migration once per blog, gated on the per-blog completion flag.
     *
     * @since 13.5.6
     * @access public
     */
    public function run() {
        if ( ! $this->force_update && 'yes' === get_option( static::MIGRATION_FLAG ) ) {
            return;
        }

        $this->update();

        update_option( static::MIGRATION_FLAG, 'yes', false );
    }
}
