<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Classes\UCP
 */

namespace AdTribes\PFP\Classes\UCP;

defined( 'ABSPATH' ) || exit;

/**
 * Compact JWS signing and verification (ES256 only).
 *
 * Supports detached payloads (RFC 7515 Appendix F) as required for AP2
 * `merchant_authorization` and webhook signatures. Array payloads are
 * canonicalized with JCS so an independent verifier that re-canonicalizes
 * the JSON obtains the same signing input.
 *
 * @since 13.6.0
 */
class Jws {

    /**
     * ECDSA P-256 coordinate/scalar length in bytes.
     *
     * @since 13.6.0
     * @var int
     */
    const P256_PART_LENGTH = 32;

    /**
     * Sign a payload, returning a compact JWS.
     *
     * @since 13.6.0
     * @access public
     *
     * @param array|string $payload         Payload. Arrays are JCS-canonicalized; strings are signed as-is.
     * @param string       $private_key_pem PEM-encoded EC P-256 private key.
     * @param array        $header          Extra protected-header members (e.g. `kid`). `alg` is forced to ES256.
     * @param bool         $detached        When true, the payload segment is omitted from the output.
     * @throws \RuntimeException When signing fails.
     * @return string Compact JWS (`header.payload.signature`, payload empty when detached).
     */
    public static function sign( $payload, $private_key_pem, array $header = array(), $detached = true ) {

        $header['alg'] = 'ES256';

        $payload_bytes = is_string( $payload ) ? $payload : Json_Canonicalizer::canonicalize( $payload );

        $encoded_header  = self::base64url_encode( Json_Canonicalizer::canonicalize( $header ) );
        $encoded_payload = self::base64url_encode( $payload_bytes );
        $signing_input   = $encoded_header . '.' . $encoded_payload;

        $der_signature = '';
        if ( ! openssl_sign( $signing_input, $der_signature, $private_key_pem, OPENSSL_ALGO_SHA256 ) ) {
            throw new \RuntimeException( 'JWS: signing failed: ' . esc_html( (string) openssl_error_string() ) );
        }

        $signature = self::base64url_encode( self::der_to_raw( $der_signature ) );

        return $encoded_header . '.' . ( $detached ? '' : $encoded_payload ) . '.' . $signature;
    }

    /**
     * Verify a compact JWS.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string            $jws            Compact JWS. The payload segment may be empty (detached).
     * @param string            $public_key_pem PEM-encoded EC P-256 public key.
     * @param array|string|null $payload        Detached payload; required when the JWS payload segment is empty.
     * @return bool True when the signature is valid.
     */
    public static function verify( $jws, $public_key_pem, $payload = null ) {

        $segments = explode( '.', $jws );
        if ( 3 !== count( $segments ) ) {
            return false;
        }

        list( $encoded_header, $encoded_payload, $encoded_signature ) = $segments;

        $header = json_decode( self::base64url_decode( $encoded_header ), true );
        if ( ! is_array( $header ) || 'ES256' !== ( $header['alg'] ?? '' ) ) {
            return false;
        }

        if ( '' === $encoded_payload ) {
            if ( null === $payload ) {
                return false;
            }
            $payload_bytes   = is_string( $payload ) ? $payload : Json_Canonicalizer::canonicalize( $payload );
            $encoded_payload = self::base64url_encode( $payload_bytes );
        }

        $raw_signature = self::base64url_decode( $encoded_signature );
        if ( 2 * self::P256_PART_LENGTH !== strlen( $raw_signature ) ) {
            return false;
        }

        $signing_input = $encoded_header . '.' . $encoded_payload;

        return 1 === openssl_verify(
            $signing_input,
            self::raw_to_der( $raw_signature ),
            $public_key_pem,
            OPENSSL_ALGO_SHA256
        );
    }

    /**
     * Base64url encode (RFC 4648 §5, unpadded).
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $data Bytes.
     * @return string Base64url text.
     */
    public static function base64url_encode( $data ) {

        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    /**
     * Base64url decode.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $data Base64url text.
     * @return string Bytes (empty string on invalid input).
     */
    public static function base64url_decode( $data ) {

        $padded  = strtr( $data, '-_', '+/' );
        $padded .= str_repeat( '=', ( 4 - ( strlen( $padded ) % 4 ) ) % 4 );
        $decoded = base64_decode( $padded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

        return false === $decoded ? '' : $decoded;
    }

    /**
     * Convert a DER-encoded ECDSA signature to the raw r||s JOSE form.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $der DER signature (ASN.1 SEQUENCE of two INTEGERs).
     * @throws \RuntimeException When the DER structure is not a two-integer sequence.
     * @return string Raw signature, 64 bytes for P-256.
     */
    public static function der_to_raw( $der ) {

        $offset = 0;
        $length = strlen( $der );

        if ( $length < 8 || 0x30 !== ord( $der[ $offset++ ] ) ) {
            throw new \RuntimeException( 'JWS: invalid DER signature.' );
        }

        // Sequence length (long form is a single extra byte for P-256 sizes).
        $seq_length = ord( $der[ $offset++ ] );
        if ( $seq_length > 0x80 ) {
            $offset += $seq_length - 0x80;
        }

        $parts = array();
        for ( $i = 0; $i < 2; $i++ ) {
            if ( $offset >= $length || 0x02 !== ord( $der[ $offset++ ] ) ) {
                throw new \RuntimeException( 'JWS: invalid DER signature.' );
            }

            $int_length = ord( $der[ $offset++ ] );
            $int_bytes  = substr( $der, $offset, $int_length );
            $offset    += $int_length;

            $int_bytes = ltrim( $int_bytes, "\x00" );
            if ( strlen( $int_bytes ) > self::P256_PART_LENGTH ) {
                throw new \RuntimeException( 'JWS: DER integer larger than curve size.' );
            }

            $parts[] = str_pad( $int_bytes, self::P256_PART_LENGTH, "\x00", STR_PAD_LEFT );
        }

        return $parts[0] . $parts[1];
    }

    /**
     * Convert a raw r||s JOSE signature to DER.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $raw Raw signature, 64 bytes for P-256.
     * @return string DER signature.
     */
    public static function raw_to_der( $raw ) {

        $encode_integer = function ( $bytes ) {
            $bytes = ltrim( $bytes, "\x00" );
            if ( '' === $bytes ) {
                $bytes = "\x00";
            }
            // Prepend 0x00 when the high bit is set so the integer stays positive.
            if ( ord( $bytes[0] ) > 0x7F ) {
                $bytes = "\x00" . $bytes;
            }

            return "\x02" . chr( strlen( $bytes ) ) . $bytes;
        };

        $body = $encode_integer( substr( $raw, 0, self::P256_PART_LENGTH ) )
            . $encode_integer( substr( $raw, self::P256_PART_LENGTH ) );

        return "\x30" . chr( strlen( $body ) ) . $body;
    }
}
