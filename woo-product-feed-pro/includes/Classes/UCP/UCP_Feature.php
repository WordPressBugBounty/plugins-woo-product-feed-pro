<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Classes\UCP
 */

namespace AdTribes\PFP\Classes\UCP;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Traits\Singleton_Trait;

defined( 'ABSPATH' ) || exit;

/**
 * UCP connector feature gate.
 *
 * Single source of truth for whether the UCP connector is available on this
 * store, whether the merchant enabled it and, more importantly, whether the
 * live protocol surface (profile, checkout endpoints, feed attributes,
 * webhooks) may be served. A store that has not cleared every gate must never
 * serve live UCP — an unqualified store would expose an invalid profile and
 * fail Google's checkout test.
 *
 * The gates nest, outermost first:
 *
 * - `is_available()` — the `ADT_UCP_BETA` wp-config constant. Off by default;
 *   the whole connector is hidden and inert until a beta store sets it.
 * - `is_enabled()`   — the merchant opted in (setting, or `ADT_UCP_ENABLED`).
 * - `is_serving()`   — the protocol surface answers (live, or staging mode).
 * - `is_live()`      — every pre-flight gate passed.
 *
 * @since 13.6.0
 */
class UCP_Feature extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Memoised beta constant read.
     *
     * The nested gates call `is_available()` several times per request, so the
     * constant read happens once and the result is reused. Only the constant
     * read is cached — the `adt_ucp_is_available` filter still runs on every
     * call, so a callback added later still closes the gate.
     *
     * @since 13.6.0
     * @access protected
     * @var bool|null Null until the constants are read.
     */
    protected $beta_constant = null;

    /**
     * Whether the UCP connector is available on this store at all.
     *
     * The connector ships as a closed beta. A store only sees it after the
     * merchant adds `define( 'ADT_UCP_BETA', true );` to `wp-config.php`.
     * Without that constant the admin page, the admin REST controller, the
     * notices and the whole protocol surface stay hidden and inert, and no
     * stored setting or filter on the inner gates can turn them on.
     *
     * A site that declares itself a test site with `ADT_PFP_TESTING_SITE` also
     * unlocks the beta, so a test site that already sets that constant needs no
     * second one. This is a convenience, not a guarantee: the shared Cypress CI
     * site (rymera.test) sets neither constant, so the `@feature:ai-discovery`
     * suites stay parked until an operator adds one to that site.
     *
     * The bootstrap files read this gate while the plugin loads, so a callback
     * on `adt_ucp_is_available` must be added from an mu-plugin to change what
     * gets wired. Added later it still gates every request-time check.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool True when the beta is unlocked on this store.
     */
    public function is_available() {

        if ( null === $this->beta_constant ) {
            $this->beta_constant = ( defined( 'ADT_UCP_BETA' ) && ADT_UCP_BETA )
                || ( defined( 'ADT_PFP_TESTING_SITE' ) && ADT_PFP_TESTING_SITE );
        }

        $available = $this->beta_constant;

        /**
         * Filter whether the UCP connector beta is available on this store.
         *
         * @since 13.6.0
         *
         * @param bool $available Whether the beta is unlocked.
         */
        return (bool) apply_filters( 'adt_ucp_is_available', $available );
    }

    /**
     * Whether the merchant has opted in to the UCP connector.
     *
     * The `ADT_UCP_ENABLED` constant overrides the stored setting so local
     * and staging environments can force the feature on or off. Both the
     * constant and the setting only apply once the beta is available — see
     * `is_available()`.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool True when the feature is enabled.
     */
    public function is_enabled() {

        if ( ! $this->is_available() ) {
            return false;
        }

        if ( defined( 'ADT_UCP_ENABLED' ) ) {
            $enabled = (bool) ADT_UCP_ENABLED;
        } else {
            $enabled = 'yes' === Settings::instance()->get( 'enabled' );
        }

        /**
         * Filter whether the UCP connector feature is enabled.
         *
         * @since 13.6.0
         *
         * @param bool $enabled Whether the feature is enabled.
         */
        return (bool) apply_filters( 'adt_ucp_is_enabled', $enabled );
    }

    /**
     * Get the enablement gates and their current state.
     *
     * All gates must pass before the store serves live UCP:
     * pre-flight checks, a connected AdTribes account, and acknowledged
     * Google approval. AI Discovery is free in v1, so there is no
     * billing-consent gate.
     *
     * @since 13.6.0
     * @access public
     *
     * @return array Gate name => bool passed.
     */
    public function get_gates() {

        $settings = Settings::instance();

        $gates = array(
            'preflight_passed'  => 'yes' === $settings->get( 'preflight_passed' ),
            // Read the account gate from the token itself, not the settings
            // mirror. If the account option is wiped or half-written while
            // the mirror still says 'yes', a mirror-based gate would let the
            // store serve live UCP with no usable account — and every
            // mandate verification would then fail at runtime.
            'account_connected' => Account_Client::instance()->is_connected(),
            'google_approved'   => 'yes' === $settings->get( 'google_approved' ),
        );

        /**
         * Filter the UCP enablement gates.
         *
         * @since 13.6.0
         *
         * @param array $gates Gate name => bool passed.
         */
        return (array) apply_filters( 'adt_ucp_gates', $gates );
    }

    /**
     * Whether the store may serve live UCP traffic.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool True when enabled and all gates pass.
     */
    public function is_live() {

        if ( ! $this->is_available() ) {
            return false;
        }

        $live = $this->is_enabled() && ! in_array( false, $this->get_gates(), true );

        /**
         * Filter whether the UCP connector is live.
         *
         * @since 13.6.0
         *
         * @param bool $live Whether the connector is live.
         */
        return (bool) apply_filters( 'adt_ucp_is_live', $live );
    }

    /**
     * Whether the UCP protocol surface should be served at all.
     *
     * Staging mode serves the profile and endpoints for Google's
     * "Validate" and "Test checkout integration" flows before the store
     * goes live, without requiring every gate to pass.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool True when the protocol surface should be served.
     */
    public function is_serving() {

        if ( ! $this->is_available() ) {
            return false;
        }

        $serving = $this->is_live()
            || ( $this->is_enabled() && 'yes' === Settings::instance()->get( 'staging_mode' ) );

        /**
         * Filter whether the UCP protocol surface should be served.
         *
         * @since 13.6.0
         *
         * @param bool $serving Whether the protocol surface should be served.
         */
        return (bool) apply_filters( 'adt_ucp_is_serving', $serving );
    }

    /**
     * Run the class.
     *
     * @since 13.6.0
     * @access public
     */
    public function run() {}
}
