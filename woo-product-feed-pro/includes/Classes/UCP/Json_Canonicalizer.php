<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Classes\UCP
 */

namespace AdTribes\PFP\Classes\UCP;

defined( 'ABSPATH' ) || exit;

/**
 * RFC 8785 JSON Canonicalization Scheme (JCS) implementation.
 *
 * Produces byte-identical canonical JSON for signing: object properties
 * sorted by UTF-16 code units, numbers serialized per ECMAScript
 * Number::toString, and strings escaped with the minimal escape set.
 * Required for AP2 `merchant_authorization` signing and webhook
 * signatures, where the verifier re-canonicalizes independently.
 *
 * PHP cannot distinguish an empty array from an empty object — pass
 * `stdClass` (or a `JsonSerializable` returning one) for empty objects.
 *
 * @since 13.6.0
 */
class Json_Canonicalizer {

    /**
     * Canonicalize a PHP value into its JCS JSON string.
     *
     * @since 13.6.0
     * @access public
     *
     * @param mixed $data Value composed of null, bool, int, float, string, array and stdClass.
     * @throws \InvalidArgumentException When the value contains a type JSON cannot represent.
     * @return string Canonical JSON.
     */
    public static function canonicalize( $data ) {

        if ( null === $data ) {
            return 'null';
        }

        if ( is_bool( $data ) ) {
            return $data ? 'true' : 'false';
        }

        if ( is_int( $data ) || is_float( $data ) ) {
            return self::serialize_number( $data );
        }

        if ( is_string( $data ) ) {
            return self::serialize_string( $data );
        }

        if ( $data instanceof \JsonSerializable ) {
            return self::canonicalize( $data->jsonSerialize() );
        }

        if ( is_object( $data ) ) {
            return self::serialize_object( (array) $data );
        }

        if ( is_array( $data ) ) {
            return self::is_list( $data )
                ? self::serialize_array( $data )
                : self::serialize_object( $data );
        }

        throw new \InvalidArgumentException( 'JCS: unsupported type ' . esc_html( gettype( $data ) ) );
    }

    /**
     * Whether an array is a JSON array (sequential integer keys from zero).
     *
     * An empty array serializes as a JSON array.
     *
     * @since 13.6.0
     * @access private
     *
     * @param array $data Array to inspect.
     * @return bool True when the array is a list.
     */
    private static function is_list( array $data ) {

        $expected = 0;
        foreach ( $data as $key => $unused ) {
            if ( $key !== $expected ) {
                return false;
            }
            ++$expected;
        }

        return true;
    }

    /**
     * Serialize a JSON array.
     *
     * @since 13.6.0
     * @access private
     *
     * @param array $data List values.
     * @return string Canonical JSON array.
     */
    private static function serialize_array( array $data ) {

        $items = array();
        foreach ( $data as $value ) {
            $items[] = self::canonicalize( $value );
        }

        return '[' . implode( ',', $items ) . ']';
    }

    /**
     * Serialize a JSON object with keys sorted by UTF-16 code units.
     *
     * @since 13.6.0
     * @access private
     *
     * @param array $data Key/value pairs.
     * @return string Canonical JSON object.
     */
    private static function serialize_object( array $data ) {

        $keys = array_map( 'strval', array_keys( $data ) );

        usort(
            $keys,
            function ( $a, $b ) {
                return strcmp( self::to_utf16be( $a ), self::to_utf16be( $b ) );
            }
        );

        $members = array();
        foreach ( $keys as $key ) {
            $members[] = self::serialize_string( $key ) . ':' . self::canonicalize( $data[ $key ] );
        }

        return '{' . implode( ',', $members ) . '}';
    }

    /**
     * Serialize a string with the RFC 8785 minimal escape set.
     *
     * Escapes double quote, backslash, and the two-character forms for
     * backspace, tab, newline, form feed and carriage return; all other
     * control characters as lowercase \u00xx; everything else passes
     * through as literal UTF-8.
     *
     * @since 13.6.0
     * @access private
     *
     * @param string $value UTF-8 string.
     * @return string Canonical JSON string including surrounding quotes.
     */
    private static function serialize_string( $value ) {

        static $short_escapes = array(
            0x08 => '\b',
            0x09 => '\t',
            0x0a => '\n',
            0x0c => '\f',
            0x0d => '\r',
        );

        $out    = '"';
        $length = strlen( $value );

        for ( $i = 0; $i < $length; $i++ ) {
            $char = $value[ $i ];
            $byte = ord( $char );

            if ( '"' === $char ) {
                $out .= '\"';
            } elseif ( '\\' === $char ) {
                $out .= '\\\\';
            } elseif ( isset( $short_escapes[ $byte ] ) ) {
                $out .= $short_escapes[ $byte ];
            } elseif ( $byte < 0x20 ) {
                $out .= sprintf( '\u%04x', $byte );
            } else {
                $out .= $char;
            }
        }

        return $out . '"';
    }

    /**
     * Serialize a number per ECMAScript Number::toString(10).
     *
     * @since 13.6.0
     * @access private
     *
     * @param int|float $value Number.
     * @throws \InvalidArgumentException For NaN and infinity, which JSON cannot represent.
     * @return string Canonical number text.
     */
    private static function serialize_number( $value ) {

        if ( is_int( $value ) ) {
            return (string) $value;
        }

        if ( is_nan( $value ) || is_infinite( $value ) ) {
            throw new \InvalidArgumentException( 'JCS: NaN and Infinity are not permitted in JSON.' );
        }

        if ( 0.0 === $value ) {
            return '0'; // Covers negative zero as well, matching ECMAScript.
        }

        $sign = '';
        if ( $value < 0 ) {
            $sign  = '-';
            $value = -$value;
        }

        list( $digits, $point ) = self::shortest_decimal( $value );

        $count = strlen( $digits );

        // ECMAScript Number::toString, steps for radix 10.
        if ( $count <= $point && $point <= 21 ) {
            $text = $digits . str_repeat( '0', $point - $count );
        } elseif ( 0 < $point && $point <= 21 ) {
            $text = substr( $digits, 0, $point ) . '.' . substr( $digits, $point );
        } elseif ( -6 < $point && $point <= 0 ) {
            $text = '0.' . str_repeat( '0', -$point ) . $digits;
        } else {
            $mantissa = 1 === $count ? $digits : $digits[0] . '.' . substr( $digits, 1 );
            $exponent = $point - 1;
            $text     = $mantissa . 'e' . ( $exponent >= 0 ? '+' : '-' ) . abs( $exponent );
        }

        return $sign . $text;
    }

    /**
     * Get the shortest round-trip decimal representation of a positive double.
     *
     * @since 13.6.0
     * @access private
     *
     * @param float $value Positive, finite, non-zero double.
     * @return array {digits} significant digits without dot or zero padding, and
     *               {point} the decimal point position such that value = 0.{digits} * 10^{point}.
     */
    private static function shortest_decimal( $value ) {

        // serialize_precision = -1 yields the shortest text that round-trips.
        $previous = ini_set( 'serialize_precision', '-1' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
        $text     = strtolower( (string) json_encode( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
        if ( false !== $previous ) {
            ini_set( 'serialize_precision', $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky
        }

        $exponent = 0;
        $e_pos    = strpos( $text, 'e' );
        if ( false !== $e_pos ) {
            $exponent = (int) substr( $text, $e_pos + 1 );
            $text     = substr( $text, 0, $e_pos );
        }

        $dot_pos = strpos( $text, '.' );
        if ( false !== $dot_pos ) {
            $exponent -= strlen( $text ) - $dot_pos - 1;
            $text      = str_replace( '.', '', $text );
        }

        $text = ltrim( $text, '0' );

        $trimmed   = rtrim( $text, '0' );
        $exponent += strlen( $text ) - strlen( $trimmed );

        return array( $trimmed, strlen( $trimmed ) + $exponent );
    }

    /**
     * Convert a UTF-8 string to UTF-16BE bytes for code-unit ordering.
     *
     * Implemented without the mbstring extension, which WordPress does
     * not guarantee.
     *
     * @since 13.6.0
     * @access private
     *
     * @param string $value UTF-8 string.
     * @return string UTF-16BE byte string.
     */
    private static function to_utf16be( $value ) {

        if ( '' === $value ) {
            return '';
        }

        preg_match_all( '/./us', $value, $matches );

        $out = '';
        foreach ( $matches[0] as $char ) {
            $code_point = self::utf8_code_point( $char );

            if ( $code_point < 0x10000 ) {
                $out .= pack( 'n', $code_point );
            } else {
                $code_point -= 0x10000;
                $out        .= pack( 'n2', 0xD800 | ( $code_point >> 10 ), 0xDC00 | ( $code_point & 0x3FF ) );
            }
        }

        return $out;
    }

    /**
     * Decode a single UTF-8 encoded character to its code point.
     *
     * @since 13.6.0
     * @access private
     *
     * @param string $char One UTF-8 character (1–4 bytes).
     * @return int Unicode code point.
     */
    private static function utf8_code_point( $char ) {

        $bytes = array_values( unpack( 'C*', $char ) );
        $first = $bytes[0];

        if ( $first < 0x80 ) {
            return $first;
        }

        if ( $first < 0xE0 ) {
            return ( ( $first & 0x1F ) << 6 ) | ( $bytes[1] & 0x3F );
        }

        if ( $first < 0xF0 ) {
            return ( ( $first & 0x0F ) << 12 ) | ( ( $bytes[1] & 0x3F ) << 6 ) | ( $bytes[2] & 0x3F );
        }

        return ( ( $first & 0x07 ) << 18 ) | ( ( $bytes[1] & 0x3F ) << 12 ) | ( ( $bytes[2] & 0x3F ) << 6 ) | ( $bytes[3] & 0x3F );
    }
}
