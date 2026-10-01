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
 * UCP signing key lifecycle.
 *
 * Generates and stores the store's EC P-256 keypair used for the profile
 * `signing_keys`, AP2 `merchant_authorization` signing, and webhook
 * signatures. The private key is encrypted at rest with a key derived
 * from the WordPress auth salt; the admin UI only ever sees the key ID.
 *
 * @since 13.6.0
 */
class Keys {

    use Singleton_Trait;

    /**
     * The option name that holds the (encrypted) keypair.
     *
     * @since 13.6.0
     * @var string
     */
    const OPTION_NAME = 'adt_ucp_keys';

    /**
     * Whether the environment can generate P-256 keys.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool True when OpenSSL supports the prime256v1 curve.
     */
    public function is_supported() {

        return function_exists( 'openssl_pkey_new' )
            && function_exists( 'openssl_get_curve_names' )
            && in_array( 'prime256v1', (array) openssl_get_curve_names(), true );
    }

    /**
     * Whether a usable keypair is stored.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool True when a keypair exists and the private key decrypts.
     */
    public function has_keys() {

        $stored = get_option( self::OPTION_NAME );

        return is_array( $stored )
            && ! empty( $stored['jwk'] )
            && '' !== $this->get_private_key_pem();
    }

    /**
     * Whether a keypair is stored but its private key cannot be decrypted.
     *
     * The private key is encrypted under a key derived from `wp_salt('auth')`,
     * so rotating the WP salts — or cloning a site to a host that
     * regenerates them — leaves a stored keypair whose private half is gone
     * for good. Signing then throws and the published JWK matches no usable
     * key, with nothing to see but a WP_DEBUG log line.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool True when the stored private key is unrecoverable.
     */
    public function is_unrecoverable() {

        $stored = get_option( self::OPTION_NAME );

        if ( ! is_array( $stored ) || empty( $stored['private'] ) ) {
            return false; // Nothing stored is a normal pre-generation state.
        }

        return '' === $this->decrypt( $stored['private'] );
    }

    /**
     * Ensure a keypair exists, generating one when missing.
     *
     * An unrecoverable keypair is replaced rather than kept: its private
     * half can never be recovered, so holding on to it only keeps the store
     * publishing a JWK it cannot sign with.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool True when a usable keypair is available.
     */
    public function ensure_keys() {

        if ( $this->has_keys() ) {
            return true;
        }

        return $this->generate_keys( $this->is_unrecoverable() );
    }

    /**
     * Generate and store a new EC P-256 keypair.
     *
     * @since 13.6.0
     * @access public
     *
     * @param bool $force Regenerate even when a keypair already exists.
     * @return bool True on success.
     */
    public function generate_keys( $force = false ) {

        if ( ! $force && is_array( get_option( self::OPTION_NAME ) ) && $this->has_keys() ) {
            return true;
        }

        if ( ! $this->is_supported() ) {
            return false;
        }

        $key_resource = openssl_pkey_new(
            array(
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name'       => 'prime256v1',
            )
        );

        if ( false === $key_resource ) {
            return false;
        }

        $private_pem = '';
        if ( ! openssl_pkey_export( $key_resource, $private_pem ) ) {
            return false;
        }

        $details = openssl_pkey_get_details( $key_resource );
        if ( false === $details || empty( $details['ec']['x'] ) || empty( $details['ec']['y'] ) ) {
            return false;
        }

        $x = Jws::base64url_encode( str_pad( $details['ec']['x'], 32, "\x00", STR_PAD_LEFT ) );
        $y = Jws::base64url_encode( str_pad( $details['ec']['y'], 32, "\x00", STR_PAD_LEFT ) );

        $jwk = array(
            'kty' => 'EC',
            'crv' => 'P-256',
            'x'   => $x,
            'y'   => $y,
            'kid' => self::compute_kid( $x, $y ),
            'use' => 'sig',
            'alg' => 'ES256',
        );

        $encrypted = $this->encrypt( $private_pem );
        if ( '' === $encrypted ) {
            return false;
        }

        return $this->save_option(
            array(
                'private'    => $encrypted,
                'public_pem' => $details['key'],
                'jwk'        => $jwk,
                'created_at' => time(),
            )
        );
    }

    /**
     * Get the decrypted private key PEM.
     *
     * @since 13.6.0
     * @access public
     *
     * @return string PEM text, empty string when unavailable.
     */
    public function get_private_key_pem() {

        $stored = get_option( self::OPTION_NAME );
        if ( ! is_array( $stored ) || empty( $stored['private'] ) ) {
            return '';
        }

        return $this->decrypt( $stored['private'] );
    }

    /**
     * Get the public key PEM.
     *
     * @since 13.6.0
     * @access public
     *
     * @return string PEM text, empty string when unavailable.
     */
    public function get_public_key_pem() {

        $stored = get_option( self::OPTION_NAME );

        return is_array( $stored ) && ! empty( $stored['public_pem'] ) ? $stored['public_pem'] : '';
    }

    /**
     * Get the public JWK for the profile `signing_keys` entry.
     *
     * @since 13.6.0
     * @access public
     *
     * @return array JWK members, empty array when unavailable.
     */
    public function get_public_jwk() {

        $stored = get_option( self::OPTION_NAME );

        return is_array( $stored ) && ! empty( $stored['jwk'] ) ? (array) $stored['jwk'] : array();
    }

    /**
     * Get the key ID (RFC 7638 JWK thumbprint).
     *
     * @since 13.6.0
     * @access public
     *
     * @return string Key ID, empty string when unavailable.
     */
    public function get_kid() {

        $jwk = $this->get_public_jwk();

        return $jwk['kid'] ?? '';
    }

    /**
     * Sign a payload with the stored key.
     *
     * @since 13.6.0
     * @access public
     *
     * @param array|string $payload  Payload; arrays are JCS-canonicalized.
     * @param bool         $detached Whether to omit the payload segment.
     * @throws \RuntimeException When no usable key is stored.
     * @return string Compact JWS.
     */
    public function sign( $payload, $detached = true ) {

        $private_pem = $this->get_private_key_pem();
        if ( '' === $private_pem ) {
            throw new \RuntimeException( 'UCP: no signing key available.' );
        }

        return Jws::sign( $payload, $private_pem, array( 'kid' => $this->get_kid() ), $detached );
    }

    /**
     * Delete the stored keypair.
     *
     * @since 13.6.0
     * @access public
     *
     * @return bool True if the option was deleted.
     */
    public function delete_keys() {

        return delete_option( self::OPTION_NAME );
    }

    /**
     * Compute the RFC 7638 JWK thumbprint for an EC P-256 key.
     *
     * The thumbprint hashes the fixed-order required members exactly as
     * specified: {"crv","kty","x","y"}.
     *
     * @since 13.6.0
     * @access public
     *
     * @param string $x Base64url X coordinate.
     * @param string $y Base64url Y coordinate.
     * @return string Base64url SHA-256 thumbprint.
     */
    public static function compute_kid( $x, $y ) {

        $canonical = '{"crv":"P-256","kty":"EC","x":"' . $x . '","y":"' . $y . '"}';

        return Jws::base64url_encode( hash( 'sha256', $canonical, true ) );
    }

    /**
     * Save the keypair option without autoloading it.
     *
     * @since 13.6.0
     * @access private
     *
     * @param array $data Option payload.
     * @return bool True on success.
     */
    private function save_option( array $data ) {

        if ( false === get_option( self::OPTION_NAME ) ) {
            return add_option( self::OPTION_NAME, $data, '', 'no' );
        }

        return update_option( self::OPTION_NAME, $data );
    }

    /**
     * Encrypt plaintext for key storage.
     *
     * @since 13.6.0
     * @access private
     *
     * @param string $plaintext Plaintext.
     * @return string Encrypted blob, empty string on failure.
     */
    private function encrypt( $plaintext ) {

        return Crypto::encrypt( $plaintext, 'adt_ucp_keys' );
    }

    /**
     * Decrypt a blob produced by encrypt().
     *
     * @since 13.6.0
     * @access private
     *
     * @param string $blob Encrypted blob.
     * @return string Plaintext, empty string on failure.
     */
    private function decrypt( $blob ) {

        return Crypto::decrypt( $blob, 'adt_ucp_keys' );
    }
}
