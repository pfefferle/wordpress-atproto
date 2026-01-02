<?php
/**
 * Content Identifier (CID) for AT Protocol.
 *
 * CIDs are self-describing content-addressed identifiers.
 * AT Protocol uses CIDv1 with dag-cbor codec and SHA-256 hash.
 *
 * @package ATProto
 */

namespace ATProto\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * CID class for content addressing.
 */
class CID {
	/**
	 * CID version 1.
	 *
	 * @var int
	 */
	const VERSION = 1;

	/**
	 * DAG-CBOR multicodec (0x71).
	 *
	 * @var int
	 */
	const CODEC_DAG_CBOR = 0x71;

	/**
	 * Raw multicodec (0x55).
	 *
	 * @var int
	 */
	const CODEC_RAW = 0x55;

	/**
	 * SHA-256 multihash code (0x12).
	 *
	 * @var int
	 */
	const HASH_SHA256 = 0x12;

	/**
	 * SHA-256 hash length.
	 *
	 * @var int
	 */
	const HASH_LENGTH = 32;

	/**
	 * Base32 lower alphabet (RFC 4648).
	 *
	 * @var string
	 */
	const BASE32_ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

	/**
	 * Create a CID from raw bytes (for dag-cbor data).
	 *
	 * @param string $data The raw data to hash.
	 * @param int    $codec The multicodec (default: DAG-CBOR).
	 * @return string The CID as a base32 string.
	 */
	public static function from_bytes( $data, $codec = self::CODEC_DAG_CBOR ) {
		// Hash the data with SHA-256.
		$hash = hash( 'sha256', $data, true );

		// Build the CID bytes:
		// - Version (varint): 1
		// - Codec (varint): 0x71 for dag-cbor
		// - Multihash: 0x12 (sha256) + 0x20 (32 bytes) + hash
		$cid_bytes = self::encode_varint( self::VERSION );
		$cid_bytes .= self::encode_varint( $codec );
		$cid_bytes .= self::encode_varint( self::HASH_SHA256 );
		$cid_bytes .= self::encode_varint( self::HASH_LENGTH );
		$cid_bytes .= $hash;

		// Encode as base32 with 'b' prefix (base32lower).
		return 'b' . self::base32_encode( $cid_bytes );
	}

	/**
	 * Create a CID from CBOR-encoded data.
	 *
	 * @param array $data The data array to encode and hash.
	 * @return string The CID as a base32 string.
	 */
	public static function from_cbor( $data ) {
		$cbor = CBOR::encode( $data );
		return self::from_bytes( $cbor, self::CODEC_DAG_CBOR );
	}

	/**
	 * Create a CID from a file (for blobs).
	 *
	 * @param string $file_path The path to the file.
	 * @return string|false The CID as a base32 string or false on failure.
	 */
	public static function from_file( $file_path ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return false;
		}

		$data = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $data ) {
			return false;
		}

		// Use RAW codec for blobs.
		return self::from_bytes( $data, self::CODEC_RAW );
	}

	/**
	 * Verify that a CID matches the given data.
	 *
	 * @param string $cid  The CID to verify.
	 * @param string $data The raw data.
	 * @return bool True if the CID matches the data.
	 */
	public static function verify( $cid, $data ) {
		$expected = self::from_bytes( $data );
		return $cid === $expected;
	}

	/**
	 * Parse a CID string into its components.
	 *
	 * @param string $cid The CID string.
	 * @return array|false Array with version, codec, hash_algo, hash or false.
	 */
	public static function parse( $cid ) {
		if ( empty( $cid ) ) {
			return false;
		}

		// Check for base32 prefix.
		if ( 'b' !== $cid[0] ) {
			// Could be base58btc (z prefix) or other encoding.
			return false;
		}

		// Decode base32.
		$bytes = self::base32_decode( substr( $cid, 1 ) );
		if ( false === $bytes || strlen( $bytes ) < 4 ) {
			return false;
		}

		$offset = 0;

		// Read version.
		$version = self::decode_varint( $bytes, $offset );

		// Read codec.
		$codec = self::decode_varint( $bytes, $offset );

		// Read hash algorithm.
		$hash_algo = self::decode_varint( $bytes, $offset );

		// Read hash length.
		$hash_length = self::decode_varint( $bytes, $offset );

		// Read hash.
		$hash = substr( $bytes, $offset, $hash_length );

		return array(
			'version'   => $version,
			'codec'     => $codec,
			'hash_algo' => $hash_algo,
			'hash'      => $hash,
		);
	}

	/**
	 * Convert CID to bytes.
	 *
	 * @param string $cid The CID string.
	 * @return string|false Binary CID or false on failure.
	 */
	public static function to_bytes( $cid ) {
		if ( empty( $cid ) || 'b' !== $cid[0] ) {
			return false;
		}

		return self::base32_decode( substr( $cid, 1 ) );
	}

	/**
	 * Check if a string is a valid CID.
	 *
	 * @param string $cid The string to check.
	 * @return bool True if valid CID format.
	 */
	public static function is_valid( $cid ) {
		return false !== self::parse( $cid );
	}

	/**
	 * Create a CID link object for CBOR encoding.
	 *
	 * @param string $cid The CID string.
	 * @return array The CID link object.
	 */
	public static function link( $cid ) {
		return array( '$link' => $cid );
	}

	/**
	 * Encode an unsigned integer as a varint.
	 *
	 * @param int $value The value to encode.
	 * @return string The varint bytes.
	 */
	private static function encode_varint( $value ) {
		$bytes = '';

		while ( $value >= 0x80 ) {
			$bytes .= chr( ( $value & 0x7F ) | 0x80 );
			$value >>= 7;
		}

		$bytes .= chr( $value );

		return $bytes;
	}

	/**
	 * Decode a varint from bytes.
	 *
	 * @param string $bytes  The byte string.
	 * @param int    $offset Current offset (modified).
	 * @return int The decoded value.
	 */
	private static function decode_varint( $bytes, &$offset ) {
		$value = 0;
		$shift = 0;

		do {
			$byte   = ord( $bytes[ $offset++ ] );
			$value |= ( $byte & 0x7F ) << $shift;
			$shift += 7;
		} while ( $byte >= 0x80 && $offset < strlen( $bytes ) );

		return $value;
	}

	/**
	 * Encode bytes as base32 (RFC 4648, lowercase, no padding).
	 *
	 * @param string $data Binary data.
	 * @return string Base32 encoded string.
	 */
	private static function base32_encode( $data ) {
		$result   = '';
		$buffer   = 0;
		$bits     = 0;

		for ( $i = 0; $i < strlen( $data ); $i++ ) {
			$buffer = ( $buffer << 8 ) | ord( $data[ $i ] );
			$bits  += 8;

			while ( $bits >= 5 ) {
				$bits   -= 5;
				$result .= self::BASE32_ALPHABET[ ( $buffer >> $bits ) & 0x1F ];
			}
		}

		if ( $bits > 0 ) {
			$result .= self::BASE32_ALPHABET[ ( $buffer << ( 5 - $bits ) ) & 0x1F ];
		}

		return $result;
	}

	/**
	 * Decode base32 string to bytes.
	 *
	 * @param string $data Base32 encoded string.
	 * @return string|false Binary data or false on failure.
	 */
	private static function base32_decode( $data ) {
		$data   = strtolower( $data );
		$result = '';
		$buffer = 0;
		$bits   = 0;

		for ( $i = 0; $i < strlen( $data ); $i++ ) {
			$pos = strpos( self::BASE32_ALPHABET, $data[ $i ] );

			if ( false === $pos ) {
				return false;
			}

			$buffer = ( $buffer << 5 ) | $pos;
			$bits  += 5;

			if ( $bits >= 8 ) {
				$bits   -= 8;
				$result .= chr( ( $buffer >> $bits ) & 0xFF );
			}
		}

		return $result;
	}

	/**
	 * Get the hash from a CID.
	 *
	 * @param string $cid The CID string.
	 * @return string|false The raw hash bytes or false.
	 */
	public static function get_hash( $cid ) {
		$parsed = self::parse( $cid );
		return $parsed ? $parsed['hash'] : false;
	}

	/**
	 * Compare two CIDs.
	 *
	 * @param string $cid1 First CID.
	 * @param string $cid2 Second CID.
	 * @return bool True if equal.
	 */
	public static function equals( $cid1, $cid2 ) {
		return $cid1 === $cid2;
	}
}
