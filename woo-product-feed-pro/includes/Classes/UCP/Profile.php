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
 * Serves the UCP discovery profile at /.well-known/ucp.
 *
 * The UCP specification imposes hard requirements on this URL: HTTPS,
 * HTTP 200 with no 3xx redirect (so WordPress canonical redirects must
 * be bypassed), and `Cache-Control: public` with max-age of at least 60
 * seconds. The rewrite rule is always registered — availability is
 * decided at request time by UCP_Feature::is_serving() — so toggling the
 * feature never requires a rewrite flush.
 *
 * @since 13.6.0
 */
class Profile extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Pinned UCP specification version served by this connector.
     *
     * @since 13.6.0
     * @var string
     */
    const UCP_VERSION = '2026-04-08';

    /**
     * REST namespace of the UCP checkout/order endpoints.
     *
     * @since 13.6.0
     * @var string
     */
    const REST_NAMESPACE = 'adtribes-ucp/v1';

    /**
     * Query variable that routes profile requests.
     *
     * @since 13.6.0
     * @var string
     */
    const QUERY_VAR = 'adt_ucp_profile';

    /**
     * Cache lifetime advertised on the profile response, in seconds.
     *
     * The spec requires max-age >= 60.
     *
     * @since 13.6.0
     * @var int
     */
    const CACHE_MAX_AGE = 300;

    /**
     * Register the rewrite rule for /.well-known/ucp.
     *
     * @since 13.6.0
     * @access public
     */
    public function add_rewrite_rule() {

        add_rewrite_rule( '^\.well-known/ucp/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
    }

    /**
     * Register the profile query variable.
     *
     * @since 13.6.0
     * @access public
     *
     * @param array $query_vars Public query variables.
     * @return array Query variables.
     */
    public function register_query_var( $query_vars ) {

        $query_vars[] = self::QUERY_VAR;

        return $query_vars;
    }

    /**
     * Prevent canonical (trailing-slash) redirects on the profile URL.
     *
     * The spec forbids 3xx responses on the profile endpoint.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string|false $redirect_url  The redirect URL computed by WordPress.
     * @param string       $requested_url The originally requested URL.
     * @return string|false Redirect URL, or false to cancel the redirect.
     */
    public function bypass_canonical_redirect( $redirect_url, $requested_url ) {

        if ( get_query_var( self::QUERY_VAR ) ) {
            return false;
        }

        return $redirect_url;
    }

    /**
     * Serve the profile when the request matches, then exit.
     *
     * Hooked before redirect_canonical() (priority 10 on
     * template_redirect) as a second line of defense against 3xx.
     *
     * @since 13.6.0
     * @access public
     */
    public function maybe_serve_profile() {

        if ( ! get_query_var( self::QUERY_VAR ) ) {
            return;
        }

        if ( ! UCP_Feature::instance()->is_serving() ) {
            $this->send_json( array( 'error' => 'not_found' ), 404 );
        }

        if ( ! Keys::instance()->ensure_keys() ) {
            $this->send_json( array( 'error' => 'signing_keys_unavailable' ), 500 );
        }

        $this->send_json( $this->get_profile_data(), 200 );
    }

    /**
     * Build the UCP profile document.
     *
     * The `payment_handlers` declaration is appended by the payments
     * gateway-adapter layer via the `adt_ucp_profile_data` filter once a
     * supported gateway is detected — the profile is not valid for
     * Google's checkout test without it.
     *
     * @since 13.6.0
     * @access public
     *
     * @return array Profile document.
     */
    public function get_profile_data() {

        $profile = array(
            'ucp'          => array(
                'version'  => self::UCP_VERSION,
                'services' => array(
                    'dev.ucp.shopping' => array(
                        'version'      => self::UCP_VERSION,
                        'rest'         => array(
                            'endpoint' => untrailingslashit( rest_url( self::REST_NAMESPACE ) ),
                        ),
                        'capabilities' => array(
                            array( 'name' => 'dev.ucp.shopping.checkout' ),
                            array( 'name' => 'dev.ucp.shopping.fulfillment' ),
                            array( 'name' => 'dev.ucp.shopping.discount' ),
                            array( 'name' => 'dev.ucp.shopping.order' ),
                            array( 'name' => 'dev.ucp.shopping.ap2_mandate' ),
                        ),
                    ),
                ),
            ),
            'signing_keys' => array( Keys::instance()->get_public_jwk() ),
        );

        /**
         * Filter the UCP profile document before it is served.
         *
         * @since 13.6.0
         *
         * @param array $profile Profile document.
         */
        return (array) apply_filters( 'adt_ucp_profile_data', $profile );
    }

    /**
     * Emit a JSON response with the spec-mandated headers and exit.
     *
     * @since 13.6.0
     * @access private
     *
     * @param array $data   Response body.
     * @param int   $status HTTP status code.
     */
    private function send_json( array $data, $status ) {

        status_header( $status );
        header( 'Content-Type: application/json; charset=utf-8' );

        if ( 200 === $status ) {
            header( 'Cache-Control: public, max-age=' . self::CACHE_MAX_AGE );
        } else {
            nocache_headers();
        }

        echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        exit;
    }

    /**
     * Run the class.
     *
     * @since 13.6.0
     * @access public
     */
    public function run() {

        add_action( 'init', array( $this, 'add_rewrite_rule' ) );
        add_filter( 'query_vars', array( $this, 'register_query_var' ) );
        add_filter( 'redirect_canonical', array( $this, 'bypass_canonical_redirect' ), 10, 2 );
        add_action( 'template_redirect', array( $this, 'maybe_serve_profile' ), 0 );
    }
}
