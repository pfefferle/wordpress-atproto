<?php
/**
 * DAG-CBOR encoding for AT Protocol.
 *
 * DAG-CBOR is a deterministic subset of CBOR used in IPLD.
 * Rules:
 * - Map keys must be strings and sorted by byte length, then lexicographically
 * - No indefinite-length items
 * - No floating point (only integers)
 * - CID links encoded as tag 42
 *
 * @package ATProto
 */

namespace ATProto\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * CBOR class for DAG-CBOR encoding/decoding.
 */
class CBOR {
	/**
	 * CBOR major types.
	 */
	const TYPE_UNSIGNED = 0;
	const TYPE_NEGATIVE = 1;
	const TYPE_BYTES    = 2;
	const TYPE_STRING   = 3;
	const TYPE_ARRAY    = 4;
	const TYPE_MAP      = 5;
	const TYPE_TAG      = 6;
	const TYPE_SPECIAL  = 7;

	/**
	 * Special values.
	 */
	const SPECIAL_FALSE = 20;
	const SPECIAL_TRUE  = 21;
	const SPECIAL_NULL  = 22;

	/**
	 * CID tag number.
	 */
	const TAG_CID = 42;

	/**
	 * Encode data as DAG-CBOR.
	 *
	 * @param mixed $data The data to encode.
	 * @return string The CBOR-encoded bytes.
	 */
	public static function encode( $data ) {
		return self::encode_value( $data );
	}

	/**
	 * Encode a value.
	 *
	 * @param mixed $value The value to encode.
	 * @return string CBOR bytes.
	 */
	private static function encode_value( $value ) {
		if ( is_null( $value ) ) {
			return self::encode_simple( self::SPECIAL_NULL );
		}

		if ( is_bool( $value ) ) {
			return self::encode_simple( $value ? self::SPECIAL_TRUE : self::SPECIAL_FALSE );
		}

		if ( is_int( $value ) ) {
			return self::encode_integer( $value );
		}

		if ( is_string( $value ) ) {
			// Check if it's binary data (contains non-UTF8).
			if ( ! mb_check_encoding( $value, 'UTF-8' ) || self::is_binary( $value ) ) {
				return self::encode_bytes( $value );
			}
			return self::encode_string( $value );
		}

		if ( is_array( $value ) ) {
			// Check if it's a CID link.
			if ( isset( $value['$link'] ) && 1 === count( $value ) ) {
				return self::encode_cid_link( $value['$link'] );
			}

			// Check if it's a bytes object.
			if ( isset( $value['$bytes'] ) && 1 === count( $value ) ) {
				$bytes = base64_decode( $value['$bytes'], true );
				return self::encode_bytes( $bytes );
			}

			// Check if associative array (map) or sequential array.
			if ( self::is_assoc( $value ) ) {
				return self::encode_map( $value );
			}

			return self::encode_array( $value );
		}

		// Unsupported type.
		return self::encode_simple( self::SPECIAL_NULL );
	}

	/**
	 * Encode an integer.
	 *
	 * @param int $value The integer value.
	 * @return string CBOR bytes.
	 */
	private static function encode_integer( $value ) {
		if ( $value >= 0 ) {
			return self::encode_type_value( self::TYPE_UNSIGNED, $value );
		}

		// Negative integers: encode as -(n+1).
		return self::encode_type_value( self::TYPE_NEGATIVE, -1 - $value );
	}

	/**
	 * Encode a byte string.
	 *
	 * @param string $value The bytes.
	 * @return string CBOR bytes.
	 */
	private static function encode_bytes( $value ) {
		return self::encode_type_value( self::TYPE_BYTES, strlen( $value ) ) . $value;
	}

	/**
	 * Encode a text string.
	 *
	 * @param string $value The string.
	 * @return string CBOR bytes.
	 */
	private static function encode_string( $value ) {
		return self::encode_type_value( self::TYPE_STRING, strlen( $value ) ) . $value;
	}

	/**
	 * Encode an array.
	 *
	 * @param array $value The array.
	 * @return string CBOR bytes.
	 */
	private static function encode_array( $value ) {
		$result = self::encode_type_value( self::TYPE_ARRAY, count( $value ) );

		foreach ( $value as $item ) {
			$result .= self::encode_value( $item );
		}

		return $result;
	}

	/**
	 * Encode a map (associative array).
	 *
	 * DAG-CBOR requires keys sorted by:
	 * 1. Byte length (shorter first)
	 * 2. Lexicographic byte order
	 *
	 * @param array $value The associative array.
	 * @return string CBOR bytes.
	 */
	private static function encode_map( $value ) {
		// Get keys and sort them per DAG-CBOR rules.
		$keys = array_keys( $value );
		usort( $keys, function ( $a, $b ) {
			$len_a = strlen( $a );
			$len_b = strlen( $b );

			if ( $len_a !== $len_b ) {
				return $len_a - $len_b;
			}

			return strcmp( $a, $b );
		} );

		$result = self::encode_type_value( self::TYPE_MAP, count( $keys ) );

		foreach ( $keys as $key ) {
			$result .= self::encode_string( (string) $key );
			$result .= self::encode_value( $value[ $key ] );
		}

		return $result;
	}

	/**
	 * Encode a CID link.
	 *
	 * @param string $cid The CID string.
	 * @return string CBOR bytes.
	 */
	private static function encode_cid_link( $cid ) {
		// CID links are encoded as tag 42 + bytes.
		// The bytes are: 0x00 (multibase identity prefix) + raw CID bytes.
		$cid_bytes = CID::to_bytes( $cid );

		if ( false === $cid_bytes ) {
			// Invalid CID, encode as null.
			return self::encode_simple( self::SPECIAL_NULL );
		}

		// Prepend 0x00 (identity multibase).
		$tagged_bytes = "\x00" . $cid_bytes;

		return self::encode_type_value( self::TYPE_TAG, self::TAG_CID ) .
			self::encode_bytes( $tagged_bytes );
	}

	/**
	 * Encode a simple value (bool, null).
	 *
	 * @param int $value The simple value code.
	 * @return string CBOR bytes.
	 */
	private static function encode_simple( $value ) {
		return chr( ( self::TYPE_SPECIAL << 5 ) | $value );
	}

	/**
	 * Encode a type with value.
	 *
	 * @param int $type  The major type.
	 * @param int $value The additional value.
	 * @return string CBOR bytes.
	 */
	private static function encode_type_value( $type, $value ) {
		$major = $type << 5;

		if ( $value < 24 ) {
			return chr( $major | $value );
		}

		if ( $value < 256 ) {
			return chr( $major | 24 ) . chr( $value );
		}

		if ( $value < 65536 ) {
			return chr( $major | 25 ) . pack( 'n', $value );
		}

		if ( $value < 4294967296 ) {
			return chr( $major | 26 ) . pack( 'N', $value );
		}

		// 64-bit value.
		return chr( $major | 27 ) . pack( 'J', $value );
	}

	/**
	 * Decode CBOR data.
	 *
	 * @param string $data The CBOR bytes.
	 * @return mixed The decoded value.
	 */
	public static function decode( $data ) {
		$offset = 0;
		return self::decode_value( $data, $offset );
	}

	/**
	 * Decode a value.
	 *
	 * @param string $data   The CBOR bytes.
	 * @param int    $offset Current offset (modified).
	 * @return mixed The decoded value.
	 */
	private static function decode_value( $data, &$offset ) {
		if ( $offset >= strlen( $data ) ) {
			return null;
		}

		$initial = ord( $data[ $offset++ ] );
		$type    = $initial >> 5;
		$info    = $initial & 0x1F;

		// Get additional value.
		$value = self::decode_additional( $data, $offset, $info );

		switch ( $type ) {
			case self::TYPE_UNSIGNED:
				return $value;

			case self::TYPE_NEGATIVE:
				return -1 - $value;

			case self::TYPE_BYTES:
				$bytes   = substr( $data, $offset, $value );
				$offset += $value;
				return array( '$bytes' => base64_encode( $bytes ) );

			case self::TYPE_STRING:
				$str     = substr( $data, $offset, $value );
				$offset += $value;
				return $str;

			case self::TYPE_ARRAY:
				$arr = array();
				for ( $i = 0; $i < $value; $i++ ) {
					$arr[] = self::decode_value( $data, $offset );
				}
				return $arr;

			case self::TYPE_MAP:
				$map = array();
				for ( $i = 0; $i < $value; $i++ ) {
					$key         = self::decode_value( $data, $offset );
					$map[ $key ] = self::decode_value( $data, $offset );
				}
				return $map;

			case self::TYPE_TAG:
				$tagged = self::decode_value( $data, $offset );
				if ( self::TAG_CID === $value && isset( $tagged['$bytes'] ) ) {
					// Decode CID link.
					$cid_bytes = base64_decode( $tagged['$bytes'], true );
					// Remove 0x00 multibase prefix.
					if ( "\x00" === $cid_bytes[0] ) {
						$cid_bytes = substr( $cid_bytes, 1 );
					}
					return array( '$link' => 'b' . self::base32_encode( $cid_bytes ) );
				}
				return $tagged;

			case self::TYPE_SPECIAL:
				switch ( $info ) {
					case self::SPECIAL_FALSE:
						return false;
					case self::SPECIAL_TRUE:
						return true;
					case self::SPECIAL_NULL:
						return null;
				}
				return null;
		}

		return null;
	}

	/**
	 * Decode additional value.
	 *
	 * @param string $data   The CBOR bytes.
	 * @param int    $offset Current offset (modified).
	 * @param int    $info   The additional info byte.
	 * @return int The decoded value.
	 */
	private static function decode_additional( $data, &$offset, $info ) {
		if ( $info < 24 ) {
			return $info;
		}

		switch ( $info ) {
			case 24:
				return ord( $data[ $offset++ ] );

			case 25:
				$bytes   = substr( $data, $offset, 2 );
				$offset += 2;
				return unpack( 'n', $bytes )[1];

			case 26:
				$bytes   = substr( $data, $offset, 4 );
				$offset += 4;
				return unpack( 'N', $bytes )[1];

			case 27:
				$bytes   = substr( $data, $offset, 8 );
				$offset += 8;
				return unpack( 'J', $bytes )[1];
		}

		return 0;
	}

	/**
	 * Check if array is associative.
	 *
	 * @param array $arr The array to check.
	 * @return bool True if associative.
	 */
	private static function is_assoc( $arr ) {
		if ( empty( $arr ) ) {
			return false;
		}

		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Check if string contains binary data.
	 *
	 * @param string $str The string to check.
	 * @return bool True if binary.
	 */
	private static function is_binary( $str ) {
		return preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $str );
	}

	/**
	 * Base32 encode for CID reconstruction.
	 *
	 * @param string $data Binary data.
	 * @return string Base32 string.
	 */
	private static function base32_encode( $data ) {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
		$result   = '';
		$buffer   = 0;
		$bits     = 0;

		for ( $i = 0; $i < strlen( $data ); $i++ ) {
			$buffer = ( $buffer << 8 ) | ord( $data[ $i ] );
			$bits  += 8;

			while ( $bits >= 5 ) {
				$bits   -= 5;
				$result .= $alphabet[ ( $buffer >> $bits ) & 0x1F ];
			}
		}

		if ( $bits > 0 ) {
			$result .= $alphabet[ ( $buffer << ( 5 - $bits ) ) & 0x1F ];
		}

		return $result;
	}
}
