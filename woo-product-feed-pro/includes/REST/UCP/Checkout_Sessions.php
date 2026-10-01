<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\REST\UCP
 */

namespace AdTribes\PFP\REST\UCP;

use AdTribes\PFP\Abstracts\Abstract_REST;
use AdTribes\PFP\Classes\UCP\Auth;
use AdTribes\PFP\Classes\UCP\Cart_Bridge;
use AdTribes\PFP\Classes\UCP\Envelope;
use AdTribes\PFP\Classes\UCP\Idempotency;
use AdTribes\PFP\Classes\UCP\Keys;
use AdTribes\PFP\Classes\UCP\Payments;
use AdTribes\PFP\Classes\UCP\Profile;
use AdTribes\PFP\Classes\UCP\UCP_Feature;
use AdTribes\PFP\Classes\UCP\Version_Negotiator;
use AdTribes\PFP\Traits\Singleton_Trait;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * UCP checkout-session REST endpoints (checkout-rest binding, v2026-04-08).
 *
 * Five operations: create, get, update (full-state PUT), complete and
 * cancel. Every request runs the same pipeline — serving gate + API-key
 * auth (permission callback), then version negotiation, then an
 * Idempotency-Key wrap for mutating operations. Business outcomes are
 * HTTP 200 with `messages[]`; protocol errors are 4xx with
 * `{code, content}`. Checkout responses are AP2-signed
 * (`ap2.merchant_authorization`, detached JWS over the body).
 *
 * @since 13.6.0
 */
class Checkout_Sessions extends Abstract_REST {

    use Singleton_Trait;

    /**
     * Register plugin routes with the UCP namespace.
     *
     * @since 13.6.0
     * @return void
     */
    public function register_plugin_routes() {

        $this->namespace = Profile::REST_NAMESPACE;
        $this->rest_base = 'checkout-sessions';

        parent::register_plugin_routes();
    }

    /**
     * Register the checkout-session routes.
     *
     * @since 13.6.0
     * @return void
     */
    public function register_routes() {

        $id_pattern = '(?P<id>[A-Za-z0-9_\-]+)';

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'create_item' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/' . $id_pattern,
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'get_item' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                ),
                array(
                    'methods'             => 'PUT',
                    'callback'            => array( $this, 'update_item' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/' . $id_pattern . '/complete',
            array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'complete_item' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/' . $id_pattern . '/cancel',
            array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'cancel_item' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                ),
            )
        );
    }

    /**
     * Permission check for every UCP route: serving gate + API-key auth.
     *
     * @since 13.6.0
     *
     * @param WP_REST_Request $request Request.
     * @return true|WP_Error True when authorized.
     */
    public function permissions_check( $request ) {

        if ( ! UCP_Feature::instance()->is_serving() ) {
            return new WP_Error(
                'not_found',
                __( 'Not found.', 'woo-product-feed-pro' ),
                array( 'status' => 404 )
            );
        }

        return Auth::instance()->authenticate_request( $request );
    }

    /**
     * POST /checkout-sessions — create a checkout session.
     *
     * @since 13.6.0
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response Response.
     */
    public function create_item( $request ) {

        return $this->handle(
            $request,
            'POST /checkout-sessions',
            function ( WP_REST_Request $request ) {

                $params = $request->get_json_params();
                if ( ! is_array( $params ) || empty( $params['line_items'] ) ) {
                    return new WP_Error(
                        'invalid_request',
                        __( 'A JSON body with line_items is required.', 'woo-product-feed-pro' ),
                        array( 'status' => 400 )
                    );
                }

                $bridge = Cart_Bridge::instance();

                if ( false === $bridge->create( Auth::instance()->get_request_environment( $request ) ) ) {
                    return new WP_Error(
                        'internal_error',
                        __( 'Unable to create a checkout session.', 'woo-product-feed-pro' ),
                        array( 'status' => 500 )
                    );
                }

                $this->apply_request_state( $bridge, $params );

                $checkout = $bridge->get_snapshot();

                // No item made it into the cart: no valid resource exists — return
                // the error_response shape (HTTP 200) and discard the session.
                if ( empty( $checkout['line_items'] ) ) {
                    $messages = $checkout['messages'];
                    $bridge->destroy();

                    return array(
                        array(
                            'ucp'      => Envelope::build( 'error', Version_Negotiator::get_negotiated_version( $request ) ),
                            'messages' => $messages,
                        ),
                        200,
                    );
                }

                return array( $this->checkout_body( $checkout, $request ), 201 );
            }
        );
    }

    /**
     * GET /checkout-sessions/{id} — retrieve a checkout session.
     *
     * @since 13.6.0
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response Response.
     */
    public function get_item( $request ) {

        return $this->handle(
            $request,
            'GET /checkout-sessions/{id}',
            function ( WP_REST_Request $request ) {

                $bridge = $this->load_session( $request );
                if ( is_wp_error( $bridge ) ) {
                    return $bridge;
                }

                return array( $this->checkout_body( $bridge->get_snapshot(), $request ), 200 );
            },
            false
        );
    }

    /**
     * PUT /checkout-sessions/{id} — update a checkout session (full state).
     *
     * @since 13.6.0
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response Response.
     */
    public function update_item( $request ) {

        return $this->handle(
            $request,
            'PUT /checkout-sessions/{id}',
            function ( WP_REST_Request $request ) {

                $bridge = $this->load_session( $request );
                if ( is_wp_error( $bridge ) ) {
                    return $bridge;
                }

                $guard = $this->guard_not_terminal( $bridge, $request );
                if ( null !== $guard ) {
                    return $guard;
                }

                $params = $request->get_json_params();
                if ( ! is_array( $params ) ) {
                    return new WP_Error(
                        'invalid_request',
                        __( 'A JSON body is required.', 'woo-product-feed-pro' ),
                        array( 'status' => 400 )
                    );
                }

                $this->apply_request_state( $bridge, $params );

                return array( $this->checkout_body( $bridge->get_snapshot(), $request ), 200 );
            }
        );
    }

    /**
     * POST /checkout-sessions/{id}/complete — place the order.
     *
     * @since 13.6.0
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response Response.
     */
    public function complete_item( $request ) {

        return $this->handle(
            $request,
            'POST /checkout-sessions/{id}/complete',
            function ( WP_REST_Request $request ) {

                $bridge = $this->load_session( $request );
                if ( is_wp_error( $bridge ) ) {
                    return $bridge;
                }

                $guard = $this->guard_not_terminal( $bridge, $request );
                if ( null !== $guard ) {
                    return $guard;
                }

                $params   = $request->get_json_params();
                $checkout = Payments::instance()->complete( $bridge, is_array( $params ) ? $params : array(), $request );

                return array( $this->checkout_body( $checkout, $request ), 200 );
            }
        );
    }

    /**
     * POST /checkout-sessions/{id}/cancel — cancel a checkout session.
     *
     * @since 13.6.0
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response Response.
     */
    public function cancel_item( $request ) {

        return $this->handle(
            $request,
            'POST /checkout-sessions/{id}/cancel',
            function ( WP_REST_Request $request ) {

                $bridge = $this->load_session( $request );
                if ( is_wp_error( $bridge ) ) {
                    return $bridge;
                }

                if ( 'completed' === $bridge->get_terminal_status() ) {
                    $bridge->add_message(
                        'error',
                        'checkout_not_cancelable',
                        'A completed checkout session cannot be canceled.',
                        'unrecoverable'
                    );

                    return array( $this->checkout_body( $bridge->get_snapshot(), $request ), 200 );
                }

                $bridge->set_terminal_status( 'canceled' );

                return array( $this->checkout_body( $bridge->get_snapshot(), $request ), 200 );
            }
        );
    }

    /**
     * Shared request pipeline: version negotiation and idempotency wrap.
     *
     * @since 13.6.0
     * @access private
     *
     * @param WP_REST_Request $request  Request.
     * @param string          $endpoint Idempotency endpoint scope.
     * @param callable        $operation Operation returning array{0: array body, 1: int status} or WP_Error.
     * @param bool            $mutating Whether the operation honors Idempotency-Key.
     * @throws \Throwable Re-thrown after releasing the idempotency claim.
     * @return WP_REST_Response Response.
     */
    private function handle( WP_REST_Request $request, $endpoint, callable $operation, $mutating = true ) {

        $version_check = Version_Negotiator::validate_request( $request );
        if ( is_wp_error( $version_check ) ) {
            return $this->protocol_error( $version_check );
        }

        $idempotency_key = trim( (string) $request->get_header( 'idempotency_key' ) );
        $raw_body        = (string) $request->get_body();

        // Scope the idempotency record to the authenticated environment and the
        // concrete session ID. Without this a single key reused across two
        // sessions would collide on the literal `{id}` template (wrong replay
        // or a spurious 409), and a staging key could replay a production
        // response.
        $scope = Auth::instance()->get_request_environment( $request ) . '|' . str_replace( '{id}', (string) $request['id'], $endpoint );

        // Claim the key *before* running the operation, not just read it. A
        // read-then-run leaves a window in which two concurrent retries of
        // the same key both see a miss and both complete the checkout.
        $claimed = false;

        if ( $mutating && '' !== $idempotency_key ) {
            $check = Idempotency::instance()->claim( $idempotency_key, $scope, $raw_body );

            if ( Idempotency::STATE_REPLAY === $check['state'] ) {
                return new WP_REST_Response( json_decode( $check['response'], true ), $check['status'] );
            }

            if ( Idempotency::STATE_MISMATCH === $check['state'] ) {
                return $this->protocol_error(
                    new WP_Error(
                        'idempotency_conflict',
                        __( 'The Idempotency-Key was reused with a different request body.', 'woo-product-feed-pro' ),
                        array( 'status' => 409 )
                    )
                );
            }

            if ( Idempotency::STATE_IN_PROGRESS === $check['state'] ) {
                return $this->protocol_error(
                    new WP_Error(
                        'idempotency_in_progress',
                        __( 'A request with this Idempotency-Key is still in progress. Retry shortly.', 'woo-product-feed-pro' ),
                        array( 'status' => 409 )
                    )
                );
            }

            $claimed = true;
        }

        try {
            $result = $operation( $request );
        } catch ( \Throwable $e ) {
            // Never leave a claim behind on a fatal — the client could not
            // then retry the key until the purge runs.
            if ( $claimed ) {
                Idempotency::instance()->release( $idempotency_key, $scope );
            }

            throw $e;
        }

        if ( is_wp_error( $result ) ) {
            // A protocol error is not a stored outcome; free the key so the
            // agent can retry it with a corrected request.
            if ( $claimed ) {
                Idempotency::instance()->release( $idempotency_key, $scope );
            }

            return $this->protocol_error( $result );
        }

        list( $body, $status ) = $result;

        if ( $claimed ) {
            Idempotency::instance()->store( $idempotency_key, $scope, $raw_body, $status, (string) wp_json_encode( $body ) );
        }

        return new WP_REST_Response( $body, $status );
    }

    /**
     * Load the session referenced by the request, as a protocol concern.
     *
     * @since 13.6.0
     * @access private
     *
     * @param WP_REST_Request $request Request.
     * @return Cart_Bridge|WP_Error Bridge with the session loaded, or not_found.
     */
    private function load_session( WP_REST_Request $request ) {

        $bridge = Cart_Bridge::instance();

        if ( ! $bridge->load( (string) $request['id'] ) ) {
            return new WP_Error(
                'not_found',
                __( 'Checkout session not found.', 'woo-product-feed-pro' ),
                array( 'status' => 404 )
            );
        }

        // Bind the session to the environment that created it. A valid key for
        // a different environment must not be able to load, complete or cancel
        // it. 404 (not 403) so a mismatch does not confirm the session exists.
        // Legacy sessions with no recorded owner are exempt.
        $owner = $bridge->get_owner_environment();
        if ( '' !== $owner && Auth::instance()->get_request_environment( $request ) !== $owner ) {
            return new WP_Error(
                'not_found',
                __( 'Checkout session not found.', 'woo-product-feed-pro' ),
                array( 'status' => 404 )
            );
        }

        return $bridge;
    }

    /**
     * Return a business-error response when the session is in a terminal state.
     *
     * @since 13.6.0
     * @access private
     *
     * @param Cart_Bridge     $bridge  Loaded bridge.
     * @param WP_REST_Request $request Request.
     * @return array|null Response tuple, or null when not terminal.
     */
    private function guard_not_terminal( Cart_Bridge $bridge, WP_REST_Request $request ) {

        $terminal = $bridge->get_terminal_status();

        if ( '' === $terminal ) {
            return null;
        }

        $bridge->add_message(
            'error',
            'checkout_not_modifiable',
            sprintf( 'This checkout session is %s and can no longer be modified.', $terminal ),
            'unrecoverable'
        );

        return array( $this->checkout_body( $bridge->get_snapshot(), $request ), 200 );
    }

    /**
     * Apply agent-supplied checkout state to the cart bridge.
     *
     * Shared by create (initial state) and update (full-state PUT).
     *
     * @since 13.6.0
     * @access private
     *
     * @param Cart_Bridge $bridge Loaded bridge.
     * @param array       $params Request body.
     */
    private function apply_request_state( Cart_Bridge $bridge, array $params ) {

        if ( isset( $params['line_items'] ) && is_array( $params['line_items'] ) ) {
            $bridge->set_line_items( $params['line_items'] );
        }

        if ( isset( $params['buyer'] ) && is_array( $params['buyer'] ) ) {
            $bridge->set_buyer( $params['buyer'] );
        }

        if ( isset( $params['fulfillment']['methods'] ) && is_array( $params['fulfillment']['methods'] ) ) {
            $method = $params['fulfillment']['methods'][0] ?? array();

            $destinations = isset( $method['destinations'] ) && is_array( $method['destinations'] ) ? $method['destinations'] : array();
            $selected_id  = $method['selected_destination_id'] ?? '';

            $destination = array();
            foreach ( $destinations as $candidate ) {
                if ( '' !== $selected_id && ( $candidate['id'] ?? '' ) === $selected_id ) {
                    $destination = $candidate;
                    break;
                }
            }
            if ( empty( $destination ) && ! empty( $destinations ) ) {
                $destination = $destinations[0];
            }

            if ( ! empty( $destination ) ) {
                $bridge->set_destination( $destination );
            }

            if ( isset( $method['groups'] ) && is_array( $method['groups'] ) ) {
                foreach ( $method['groups'] as $group ) {
                    if ( ! empty( $group['id'] ) && ! empty( $group['selected_option_id'] ) ) {
                        $bridge->select_fulfillment_option( (string) $group['id'], (string) $group['selected_option_id'] );
                    }
                }
            }
        }

        if ( isset( $params['discounts']['codes'] ) && is_array( $params['discounts']['codes'] ) ) {
            $bridge->apply_coupons( $params['discounts']['codes'] );
        }
    }

    /**
     * Assemble the final response body: envelope + checkout + AP2 signature.
     *
     * @since 13.6.0
     * @access private
     *
     * @param array           $checkout Checkout snapshot.
     * @param WP_REST_Request $request  Request (for the negotiated version).
     * @return array Response body.
     */
    private function checkout_body( array $checkout, WP_REST_Request $request ) {

        $body = array_merge(
            array( 'ucp' => Envelope::build( 'success', Version_Negotiator::get_negotiated_version( $request ) ) ),
            $checkout
        );

        // AP2 merchant_authorization: detached JWS over the body excluding
        // the ap2 member itself (JCS-canonicalized), per the ap2_mandate
        // extension. Verifiers re-canonicalize independently.
        try {
            $keys = Keys::instance();
            if ( $keys->has_keys() ) {
                $body['ap2'] = array(
                    'merchant_authorization' => $keys->sign( $body ),
                );
            }
        } catch ( \Exception $e ) {
            unset( $body['ap2'] );
        }

        return $body;
    }

    /**
     * Map a WP_Error to a protocol error response ({code, content}).
     *
     * @since 13.6.0
     * @access private
     *
     * @param WP_Error $error Error with a `status` data member.
     * @return WP_REST_Response Response.
     */
    private function protocol_error( WP_Error $error ) {

        $data   = (array) $error->get_error_data();
        $status = isset( $data['status'] ) ? (int) $data['status'] : 400;

        $body = array(
            'code'    => $error->get_error_code(),
            'content' => $error->get_error_message(),
        );

        if ( isset( $data['supported_versions'] ) ) {
            $body['supported_versions'] = $data['supported_versions'];
        }

        return new WP_REST_Response( $body, $status );
    }
}
