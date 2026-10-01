<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Classes\UCP
 */

namespace AdTribes\PFP\Classes\UCP;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * UCP protocol version negotiation.
 *
 * UCP versions are date strings; this connector pins a single supported
 * version (Profile::UCP_VERSION). Agents identify themselves with the
 * `UCP-Agent` header and may request a version via the `UCP-Version`
 * header; an absent version header negotiates to the pinned version.
 *
 * NOTE: the exact request-header grammar must be ratified against the
 * pinned 2026-04-08 spec fixtures before the checkout endpoints go live
 * (tracked in the Step 8 protocol tests) — the behaviors here are kept
 * deliberately small and filterable for that reason.
 *
 * @since 13.6.0
 */
class Version_Negotiator {

    /**
     * Get the UCP versions this connector supports.
     *
     * @since 13.6.0
     * @access public
     *
     * @return array Version date strings, newest first.
     */
    public static function get_supported_versions() {

        /**
         * Filter the supported UCP protocol versions.
         *
         * @since 13.6.0
         *
         * @param array $versions Version date strings.
         */
        return (array) apply_filters( 'adt_ucp_supported_versions', array( Profile::UCP_VERSION ) );
    }

    /**
     * Whether a version is supported.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $version Version date string.
     * @return bool True when supported.
     */
    public static function is_supported( $version ) {

        return in_array( $version, self::get_supported_versions(), true );
    }

    /**
     * Validate the protocol headers of a UCP request.
     *
     * @since 13.6.0
     * @access public
     *
     * @param WP_REST_Request $request Request.
     * @return true|WP_Error True when the request is acceptable.
     */
    public static function validate_request( WP_REST_Request $request ) {

        if ( '' === trim( (string) $request->get_header( 'ucp_agent' ) ) ) {
            return new WP_Error(
                'invalid_request',
                __( 'Missing UCP-Agent header.', 'woo-product-feed-pro' ),
                array( 'status' => 400 )
            );
        }

        $requested = trim( (string) $request->get_header( 'ucp_version' ) );

        if ( '' !== $requested && ! self::is_supported( $requested ) ) {
            return new WP_Error(
                'version_not_supported',
                __( 'The requested UCP version is not supported.', 'woo-product-feed-pro' ),
                array(
                    'status'             => 400,
                    'supported_versions' => self::get_supported_versions(),
                )
            );
        }

        return true;
    }

    /**
     * Get the negotiated version for a request.
     *
     * @since 13.6.0
     * @access public
     *
     * @param WP_REST_Request $request Request.
     * @return string Version date string.
     */
    public static function get_negotiated_version( WP_REST_Request $request ) {

        $requested = trim( (string) $request->get_header( 'ucp_version' ) );

        return '' !== $requested && self::is_supported( $requested ) ? $requested : Profile::UCP_VERSION;
    }
}
