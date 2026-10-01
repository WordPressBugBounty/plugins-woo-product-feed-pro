<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Classes\UCP
 */

namespace AdTribes\PFP\Classes\UCP;

defined( 'ABSPATH' ) || exit;

/**
 * Secret encryption-at-rest helpers.
 *
 * Encrypts option-stored secrets (signing keys, OAuth tokens) with a key
 * derived from the WordPress auth salt and a per-purpose context string.
 * Prefers libsodium (bundled since PHP 7.2), falling back to AES-256-GCM
 * via OpenSSL; the cipher used is recorded in the blob prefix.
 *
 * @since 13.6.0
 */
class Crypto {

    /**
     * Encrypt plaintext.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $plaintext Plaintext.
     * @param string $context   Purpose context, e.g. 'adt_ucp_keys'.
     * @return string Encrypted blob, empty string on failure.
     */
    public static function encrypt( $plaintext, $context ) {

        $key = self::derive_key( $context );

        if ( function_exists( 'sodium_crypto_secretbox' ) ) {
            $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

            return 'sodium:' . base64_encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        }

        $iv  = random_bytes( 12 );
        $tag = '';
        $ct  = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

        if ( false === $ct ) {
            return '';
        }

        return 'aes-256-gcm:' . base64_encode( $iv . $tag . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    /**
     * Decrypt a blob produced by encrypt().
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $blob    Encrypted blob.
     * @param string $context Purpose context used at encryption time.
     * @return string Plaintext, empty string on failure.
     */
    public static function decrypt( $blob, $context ) {

        // No stored blob is a normal state (nothing saved yet), not a failure.
        if ( '' === (string) $blob ) {
            return '';
        }

        $key = self::derive_key( $context );

        list( $cipher, $encoded ) = array_pad( explode( ':', (string) $blob, 2 ), 2, '' );

        $bytes = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        if ( false === $bytes ) {
            self::log_decrypt_failure( $context, 'blob is not valid base64' );
            return '';
        }

        if ( 'sodium' === $cipher && function_exists( 'sodium_crypto_secretbox_open' ) ) {
            $nonce = substr( $bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $ct    = substr( $bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $plain = sodium_crypto_secretbox_open( $ct, $nonce, $key );

            if ( false === $plain ) {
                self::log_decrypt_failure( $context, 'sodium authentication failed (corrupt blob or rotated WP salts)' );
                return '';
            }

            return $plain;
        }

        if ( 'aes-256-gcm' === $cipher ) {
            $iv    = substr( $bytes, 0, 12 );
            $tag   = substr( $bytes, 12, 16 );
            $ct    = substr( $bytes, 28 );
            $plain = openssl_decrypt( $ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

            if ( false === $plain ) {
                self::log_decrypt_failure( $context, 'AES-GCM authentication failed (corrupt blob or rotated WP salts)' );
                return '';
            }

            return $plain;
        }

        self::log_decrypt_failure( $context, sprintf( 'unknown cipher tag "%s"', $cipher ) );

        return '';
    }

    /**
     * Leave a debug trace when a stored secret fails to decrypt.
     *
     * Callers treat a failed decrypt as "no value" (not connected / no key),
     * which is safe but undiagnosable on its own — e.g. a WP-salt rotation
     * silently invalidates every stored secret. The plaintext and key are
     * never logged.
     *
     * @since 13.6.0
     * @access private
     *
     * @param string $context Purpose context used at encryption time.
     * @param string $why     Failure detail.
     */
    private static function log_decrypt_failure( $context, $why ) {

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( '[AdTribes UCP] Failed to decrypt stored secret (context: %s): %s.', $context, $why ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    /**
     * Derive the 32-byte encryption key for a context.
     *
     * @since 13.6.0
     * @access private
     *
     * @param string $context Purpose context.
     * @return string 32 raw bytes.
     */
    private static function derive_key( $context ) {

        return hash( 'sha256', wp_salt( 'auth' ) . '|' . $context, true );
    }
}
