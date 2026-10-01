<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Classes\UCP
 */

namespace AdTribes\PFP\Classes\UCP;

use AdTribes\PFP\Traits\Singleton_Trait;

defined( 'ABSPATH' ) || exit;

/**
 * UCP pre-flight checks.
 *
 * Server-side validation of everything Google's UCP integration needs
 * from the store: transport (HTTPS profile without redirects),
 * platform (OpenSSL curves, WooCommerce version, single-site), commerce
 * config (guest checkout, US store, shipping, policy pages), a
 * supported Stripe gateway, the AdTribes account link, and the manual
 * confirmations (Merchant Center link, Google approval). The aggregate
 * verdict feeds the `preflight_passed` gate — a store never goes live
 * without a passing run.
 *
 * @since 13.6.0
 */
class Preflight {

    use Singleton_Trait;

    /**
     * Settings key holding the last run's results.
     *
     * @since 13.6.0
     * @var string
     */
    const RESULTS_KEY = 'preflight_results';

    /**
     * Run all pre-flight checks, persist the results and the gate.
     *
     * @since 13.6.0
     * @access public
     *
     * @return array {
     *     @type bool  $passed Whether every required check passed.
     *     @type int   $ran_at Unix timestamp.
     *     @type array $checks Check results.
     * }
     */
    public function run() {

        $checks = array(
            $this->check_woocommerce_version(),
            $this->check_openssl(),
            $this->check_signing_key(),
            $this->check_single_site(),
            $this->check_store_country(),
            $this->check_guest_checkout(),
            $this->check_shipping(),
            $this->check_policy_pages(),
            $this->check_gateway(),
            $this->check_profile_reachable(),
            $this->check_account_connected(),
            $this->check_manual( 'merchant_center_linked', __( 'Google Merchant Center linked', 'woo-product-feed-pro' ), __( 'Confirm that this store is claimed and linked in Google Merchant Center.', 'woo-product-feed-pro' ) ),
            $this->check_manual( 'google_approved', __( 'Google UCP approval granted', 'woo-product-feed-pro' ), __( 'Confirm that Google has approved this store for agentic checkout (interest form / waitlist).', 'woo-product-feed-pro' ) ),
        );

        /**
         * Filter the pre-flight check results.
         *
         * @since 13.6.0
         *
         * @param array $checks Check results.
         */
        $checks = (array) apply_filters( 'adt_ucp_preflight_checks', $checks );

        $passed = true;
        foreach ( $checks as $check ) {
            if ( ! empty( $check['required'] ) && empty( $check['passed'] ) ) {
                $passed = false;
                break;
            }
        }

        $results = array(
            'passed' => $passed,
            'ran_at' => time(),
            'checks' => $checks,
        );

        $settings = Settings::instance();
        $settings->set( self::RESULTS_KEY, $results );
        $settings->set( 'preflight_passed', $passed ? 'yes' : 'no' );

        return $results;
    }

    /**
     * Get the last run's results.
     *
     * @since 13.6.0
     * @access public
     *
     * @return array Results, empty array when never run.
     */
    public function get_results() {

        $results = Settings::instance()->get( self::RESULTS_KEY );

        return is_array( $results ) ? $results : array();
    }

    /**
     * Build a check result.
     *
     * @since 13.6.0
     * @access private
     *
     * @param string $id       Check ID.
     * @param string $label    Human label.
     * @param bool   $passed   Whether it passed.
     * @param bool   $required Whether failure blocks going live.
     * @param string $detail   Current state / failure detail.
     * @param string $fix      Fix-it guidance.
     * @param array  $extra    Extra members to merge (kind, action).
     * @return array Check result.
     */
    private function result( $id, $label, $passed, $required, $detail = '', $fix = '', $extra = array() ) {

        return array_merge(
            array(
                'id'       => $id,
                'label'    => $label,
                'passed'   => (bool) $passed,
                'required' => (bool) $required,
                'detail'   => $detail,
                'fix'      => $passed ? '' : $fix,
                'kind'     => 'auto',
                'action'   => null,
            ),
            $extra
        );
    }

    /**
     * Build an action descriptor for a check.
     *
     * @since 13.6.0
     * @access private
     *
     * @param string $label    Button label.
     * @param string $url      Target URL.
     * @param bool   $external Whether the target is external.
     * @return array Action descriptor.
     */
    private function action( $label, $url, $external = false ) {

        return array(
            'label'    => $label,
            'url'      => $url,
            'external' => (bool) $external,
        );
    }

    /**
     * WooCommerce version supports headless cart bootstrapping.
     *
     * @since 13.6.0
     * @access private
     *
     * @return array Check result.
     */
    private function check_woocommerce_version() {

        $version = defined( 'WC_VERSION' ) ? WC_VERSION : '0';
        $passed  = version_compare( $version, '6.4', '>=' );

        return $this->result(
            'woocommerce_version',
            __( 'WooCommerce 6.4 or newer', 'woo-product-feed-pro' ),
            $passed,
            true,
            sprintf( 'WooCommerce %s', $version ),
            __( 'Update WooCommerce to at least 6.4.', 'woo-product-feed-pro' )
        );
    }

    /**
     * OpenSSL supports the P-256 curve for signing keys.
     *
     * @since 13.6.0
     * @access private
     *
     * @return array Check result.
     */
    private function check_openssl() {

        return $this->result(
            'openssl_p256',
            __( 'Server can generate EC P-256 signing keys', 'woo-product-feed-pro' ),
            Keys::instance()->is_supported(),
            true,
            '',
            __( 'Ask your host to enable OpenSSL with prime256v1 curve support.', 'woo-product-feed-pro' )
        );
    }

    /**
     * The stored signing key is still readable.
     *
     * The private key is encrypted under the WordPress auth salt, so a salt
     * rotation (or a staging→production clone that regenerates salts) makes
     * it permanently undecryptable. Without this check the only symptom is
     * webhooks and merchant authorizations quietly failing to sign, so the
     * state is surfaced here with a concrete fix.
     *
     * @since 13.6.0
     * @access private
     *
     * @return array Check result.
     */
    private function check_signing_key() {

        $unrecoverable = Keys::instance()->is_unrecoverable();

        return $this->result(
            'signing_key',
            __( 'Store signing key readable', 'woo-product-feed-pro' ),
            ! $unrecoverable,
            true,
            $unrecoverable ? __( 'The stored signing key cannot be decrypted.', 'woo-product-feed-pro' ) : '',
            __( 'The signing key was encrypted with this site\'s WordPress security salts, and those salts have since changed (a salt rotation, or a copy of the site moved between environments). It cannot be recovered. Save the settings on this page to generate a replacement, then re-run pre-flight — the store publishes a new key ID, so re-validate the connector in Merchant Center.', 'woo-product-feed-pro' )
        );
    }

    /**
     * Multisite is out of scope for the pilot.
     *
     * @since 13.6.0
     * @access private
     *
     * @return array Check result.
     */
    private function check_single_site() {

        return $this->result(
            'single_site',
            __( 'Single-site WordPress installation', 'woo-product-feed-pro' ),
            ! is_multisite(),
            true,
            '',
            __( 'UCP is not yet supported on multisite networks.', 'woo-product-feed-pro' )
        );
    }

    /**
     * Store must fulfill from a UCP-supported country.
     *
     * Google's dedicated-support form (checked 2026-08-05): UCP is
     * available to merchants who fulfill from within the US, Canada,
     * Australia, or the United Kingdom and have a local bank account.
     *
     * @since 13.6.0
     * @access private
     *
     * @return array Check result.
     */
    private function check_store_country() {

        $country = WC()->countries->get_base_country();

        /**
         * Filter the countries eligible for UCP.
         *
         * @since 13.6.0
         *
         * @param array $countries ISO 3166-1 alpha-2 codes.
         */
        $allowed = (array) apply_filters( 'adt_ucp_allowed_countries', array( 'US', 'CA', 'AU', 'GB' ) );

        return $this->result(
            'store_country',
            __( 'Store based in a supported country', 'woo-product-feed-pro' ),
            in_array( $country, $allowed, true ),
            true,
            sprintf( 'Store country: %s', $country ),
            __( 'Google UCP is currently available to merchants who fulfill from within the United States, Canada, Australia, or the United Kingdom (with a local bank account).', 'woo-product-feed-pro' )
        );
    }

    /**
     * Guest checkout must be enabled (pilot is guest-only).
     *
     * @since 13.6.0
     * @access private
     *
     * @return array Check result.
     */
    private function check_guest_checkout() {

        return $this->result(
            'guest_checkout',
            __( 'Guest checkout enabled', 'woo-product-feed-pro' ),
            'yes' === get_option( 'woocommerce_enable_guest_checkout' ),
            true,
            '',
            __( 'Enable "Allow customers to place orders without an account" under WooCommerce → Settings → Accounts & Privacy.', 'woo-product-feed-pro' ),
            array( 'action' => $this->action( __( 'Open account settings', 'woo-product-feed-pro' ), admin_url( 'admin.php?page=wc-settings&tab=account' ) ) )
        );
    }

    /**
     * At least one shipping zone with methods exists.
     *
     * @since 13.6.0
     * @access private
     *
     * @return array Check result.
     */
    private function check_shipping() {

        $has_methods = false;

        if ( class_exists( '\WC_Shipping_Zones' ) ) {
            foreach ( \WC_Shipping_Zones::get_zones() as $zone ) {
                if ( ! empty( $zone['shipping_methods'] ) ) {
                    $has_methods = true;
                    break;
                }
            }

            if ( ! $has_methods ) {
                $default_zone = new \WC_Shipping_Zone( 0 );
                $has_methods  = count( $default_zone->get_shipping_methods( true ) ) > 0;
            }
        }

        return $this->result(
            'shipping',
            __( 'Shipping methods configured', 'woo-product-feed-pro' ),
            $has_methods,
            true,
            '',
            __( 'Add at least one shipping zone with an enabled shipping method.', 'woo-product-feed-pro' ),
            array( 'action' => $this->action( __( 'Open shipping settings', 'woo-product-feed-pro' ), admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ) )
        );
    }

    /**
     * Policy pages required by Google (returns) and legal links (privacy/terms).
     *
     * @since 13.6.0
     * @access private
     *
     * @return array Check result.
     */
    private function check_policy_pages() {

        $missing = array();

        if ( ! get_privacy_policy_url() ) {
            $missing[] = __( 'privacy policy', 'woo-product-feed-pro' );
        }

        $terms_id = wc_terms_and_conditions_page_id();
        if ( $terms_id <= 0 || 'publish' !== get_post_status( $terms_id ) ) {
            $missing[] = __( 'terms & conditions', 'woo-product-feed-pro' );
        }

        $refund_id = wc_get_page_id( 'refund_returns' );
        if ( $refund_id <= 0 || 'publish' !== get_post_status( $refund_id ) ) {
            $missing[] = __( 'refund & returns policy', 'woo-product-feed-pro' );
        }

        return $this->result(
            'policy_pages',
            __( 'Privacy, terms and returns policy pages published', 'woo-product-feed-pro' ),
            empty( $missing ),
            true,
            empty( $missing ) ? '' : sprintf( 'Missing: %s', implode( ', ', $missing ) ),
            __( 'Publish the missing policy pages and assign them under Settings → Privacy and WooCommerce → Settings → Advanced.', 'woo-product-feed-pro' ),
            array( 'action' => $this->action( __( 'Set policy pages', 'woo-product-feed-pro' ), admin_url( 'admin.php?page=wc-settings&tab=advanced' ) ) )
        );
    }

    /**
     * A supported Stripe gateway is active with a publishable key.
     *
     * Not required in v1 — AI Discovery is free and discovery-only, so a
     * missing gateway must not block going live. It becomes relevant when
     * fully automated AP2 checkout ships.
     *
     * @since 13.6.0
     * @access private
     *
     * @return array Check result.
     */
    private function check_gateway() {

        $adapter = Payments::instance()->get_active_adapter();

        if ( null === $adapter ) {
            return $this->result(
                'gateway',
                __( 'Supported Stripe gateway active (for upcoming AP2 checkout)', 'woo-product-feed-pro' ),
                false,
                false,
                '',
                __( 'Optional for now — needed once fully automated AI checkout (AP2) launches. Install the WooCommerce Stripe Gateway or FunnelKit Stripe to be ready.', 'woo-product-feed-pro' ),
                array( 'action' => $this->action( __( 'Install a Stripe gateway', 'woo-product-feed-pro' ), admin_url( 'plugin-install.php?tab=search&type=term&s=stripe' ) ) )
            );
        }

        // A detection-only adapter (e.g. FunnelKit pending its completion
        // spike) must not report as checkout-ready.
        if ( ! $adapter->supports_completion() ) {
            return $this->result(
                'gateway',
                __( 'Supported Stripe gateway active (for upcoming AP2 checkout)', 'woo-product-feed-pro' ),
                false,
                false,
                sprintf( 'Detected: %s', $adapter->get_id() ),
                __( 'Optional for now — the detected Stripe gateway does not yet support agentic checkout completion. Activate the WooCommerce Stripe Gateway to be ready for AP2 checkout.', 'woo-product-feed-pro' ),
                array( 'action' => $this->action( __( 'Install a Stripe gateway', 'woo-product-feed-pro' ), admin_url( 'plugin-install.php?tab=search&type=term&s=stripe' ) ) )
            );
        }

        $has_key = '' !== $adapter->get_publishable_key();

        return $this->result(
            'gateway',
            __( 'Supported Stripe gateway active (for upcoming AP2 checkout)', 'woo-product-feed-pro' ),
            $has_key,
            false,
            sprintf( 'Detected: %s', $adapter->get_id() ),
            __( 'The detected Stripe gateway has no publishable key configured.', 'woo-product-feed-pro' ),
            array( 'action' => $this->action( __( 'Open payment settings', 'woo-product-feed-pro' ), admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) )
        );
    }

    /**
     * The profile URL is publicly reachable over HTTPS without redirects.
     *
     * @since 13.6.0
     * @access private
     *
     * @return array Check result.
     */
    private function check_profile_reachable() {

        $url = home_url( '/.well-known/ucp', 'https' );

        $response = wp_remote_get(
            $url,
            array(
                'timeout'     => 10,
                'redirection' => 0,
                /**
                 * Filter SSL verification for the pre-flight loopback probe.
                 *
                 * @since 13.6.0
                 *
                 * @param bool $verify Whether to verify SSL.
                 */
                'sslverify'   => (bool) apply_filters( 'adt_ucp_preflight_sslverify', true ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $this->result(
                'profile_reachable',
                __( 'UCP profile reachable over HTTPS', 'woo-product-feed-pro' ),
                false,
                true,
                $response->get_error_message(),
                __( 'The store could not fetch its own profile URL. Check HTTPS, DNS and firewall/loopback settings.', 'woo-product-feed-pro' )
            );
        }

        $code   = (int) wp_remote_retrieve_response_code( $response );
        $passed = 200 === $code;

        return $this->result(
            'profile_reachable',
            __( 'UCP profile reachable over HTTPS', 'woo-product-feed-pro' ),
            $passed,
            true,
            sprintf( 'HTTP %d from %s', $code, $url ),
            ( $code >= 300 && $code < 400 )
                ? __( 'The profile URL redirects — the UCP spec forbids 3xx responses. Check canonical-redirect and caching plugins.', 'woo-product-feed-pro' )
                : __( 'Enable UCP (or staging mode) so the profile is served, and ensure no cache or security layer blocks it.', 'woo-product-feed-pro' )
        );
    }

    /**
     * AdTribes account connected.
     *
     * @since 13.6.0
     * @access private
     *
     * @return array Check result.
     */
    private function check_account_connected() {

        return $this->result(
            'account_connected',
            __( 'AdTribes account connected', 'woo-product-feed-pro' ),
            Account_Client::instance()->is_connected(),
            true,
            '',
            __( 'Connect your AdTribes account to enable AI-agent checkout verification. You can sign up during the connection, and the checkout channel activates after your first credit top-up.', 'woo-product-feed-pro' ),
            array( 'action' => $this->action( __( 'Connect account', 'woo-product-feed-pro' ), Account_Connect::get_connect_action_url(), false ) )
        );
    }

    /**
     * A manual confirmation stored as a settings flag.
     *
     * @since 13.6.0
     * @access private
     *
     * @param string $key   Settings key.
     * @param string $label Check label.
     * @param string $fix   Fix-it guidance.
     * @return array Check result.
     */
    private function check_manual( $key, $label, $fix ) {

        $confirmed = Settings::instance()->get( $key );

        $actions = array(
            'merchant_center_linked' => $this->action( __( 'Open Merchant Center', 'woo-product-feed-pro' ), 'https://merchants.google.com/', true ),
            'google_approved'        => $this->action( __( 'Open UCP interest form', 'woo-product-feed-pro' ), 'https://support.google.com/merchants/answer/16837055', true ),
        );

        return $this->result(
            $key,
            $label,
            'yes' === $confirmed,
            true,
            __( 'Manual confirmation', 'woo-product-feed-pro' ),
            $fix,
            array(
                'kind'   => 'manual',
                'action' => $actions[ $key ] ?? null,
            )
        );
    }
}
