<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Classes\UCP
 */

namespace AdTribes\PFP\Classes\UCP;

defined( 'ABSPATH' ) || exit;

/**
 * UCP response envelope (`ucp` member) builder.
 *
 * Every checkout response carries a `ucp` object with the negotiated
 * version, the declared capabilities, and the response-level payment
 * handler instances (per ucp.json `response_checkout_schema`).
 *
 * @since 13.6.0
 */
class Envelope {

    /**
     * Build the `ucp` envelope for a checkout response.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $status  'success' or 'error'. Error envelopes carry only version + status.
     * @param string $version Negotiated UCP version; defaults to the pinned version.
     * @return array Envelope.
     */
    public static function build( $status = 'success', $version = '' ) {

        $version = '' !== $version ? $version : Profile::UCP_VERSION;

        if ( 'error' === $status ) {
            return array(
                'version' => $version,
                'status'  => 'error',
            );
        }

        $capabilities = array();
        foreach ( array( 'checkout', 'fulfillment', 'discount', 'order', 'ap2_mandate' ) as $capability ) {
            $capabilities[ 'dev.ucp.shopping.' . $capability ] = array(
                array( 'version' => $version ),
            );
        }

        /**
         * Filter the response-level payment handler instances.
         *
         * Keyed by reverse-domain handler name (e.g. `com.google.pay`),
         * each an array of handler instance objects. Populated by the
         * payments gateway-adapter layer when a supported gateway is
         * active.
         *
         * @since 13.6.0
         *
         * @param array $handlers Handler name => instances.
         */
        $payment_handlers = apply_filters( 'adt_ucp_response_payment_handlers', array() );

        return array(
            'version'          => $version,
            'capabilities'     => $capabilities,
            'payment_handlers' => empty( $payment_handlers ) ? new \stdClass() : $payment_handlers,
        );
    }
}
