<?php
/**
 * Cryptographic operations for AT Protocol.
 *
 * @package ATProto
 */

namespace ATProto\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * Crypto class for key management and signing.
 *
 * Uses secp256r1 (P-256 / prime256v1) as per AT Protocol specification.
 */
class Crypto {
	/**
	 * The elliptic curve to use (P-256).
	 *
	 * @var string
	 */
	const CURVE = 'prime256v1';

	/**
	 * Multicodec prefix for P-256 public keys (0x1200).
	 *
	 * @var string
	 */
	const P256_MULTICODEC = "\x80\x24";

	/**
	 * Base58btc alphabet for multibase encoding.
	 *
	 * @var string
	 */
	const BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

	/**
	 * Option name for private key.
	 *
	 * @var string
	 */
	const PRIVATE_KEY_OPTION = 'atproto_private_key';

	/**
	 * Option name for public key.
	 *
	 * @var string
	 */
	const PUBLIC_KEY_OPTION = 'atproto_public_key';

	/**
	 * Generate a new keypair and store it.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function generate_keys() {
		// Don't regenerate if keys already exist.
		if ( self::get_private_key() ) {
			return true;
		}

		$config = array(
			'curve_name'       => self::CURVE,
			'private_key_type' => OPENSSL_KEYTYPE_EC,
		);

		$key = openssl_pkey_new( $config );

		if ( ! $key ) {
			return false;
		}

		// Export private key.
		$private_key = '';
		if ( ! openssl_pkey_export( $key, $private_key ) ) {
			return false;
		}

		// Get public key.
		$details = openssl_pkey_get_details( $key );
		if ( ! $details ) {
			return false;
		}

		$public_key = $details['key'];

		// Store keys.
		update_option( self::PRIVATE_KEY_OPTION, $private_key, false );
		update_option( self::PUBLIC_KEY_OPTION, $public_key, false );

		return true;
	}

	/**
	 * Get the private key.
	 *
	 * @return string|false The private key PEM or false if not found.
	 */
	public static function get_private_key() {
		return get_option( self::PRIVATE_KEY_OPTION, false );
	}

	/**
	 * Get the public key.
	 *
	 * @return string|false The public key PEM or false if not found.
	 */
	public static function get_public_key() {
		return get_option( self::PUBLIC_KEY_OPTION, false );
	}

	/**
	 * Get the public key in compressed format.
	 *
	 * @return string|false Binary compressed public key or false on failure.
	 */
	public static function get_compressed_public_key() {
		$public_key_pem = self::get_public_key();
		if ( ! $public_key_pem ) {
			return false;
		}

		$key = openssl_pkey_get_public( $public_key_pem );
		if ( ! $key ) {
			return false;
		}

		$details = openssl_pkey_get_details( $key );
		if ( ! $details || ! isset( $details['ec']['x'], $details['ec']['y'] ) ) {
			return false;
		}

		$x = $details['ec']['x'];
		$y = $details['ec']['y'];

		// Pad X coordinate to 32 bytes.
		$x = str_pad( $x, 32, "\x00", STR_PAD_LEFT );

		// Determine prefix based on Y coordinate parity.
		$y_int    = gmp_import( $y, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN );
		$is_even  = gmp_cmp( gmp_mod( $y_int, 2 ), 0 ) === 0;
		$prefix   = $is_even ? "\x02" : "\x03";

		return $prefix . $x;
	}

	/**
	 * Get the public key in multibase format (for DID document).
	 *
	 * @return string|false Multibase encoded public key or false on failure.
	 */
	public static function get_public_key_multibase() {
		$compressed = self::get_compressed_public_key();
		if ( ! $compressed ) {
			return false;
		}

		// Prepend multicodec prefix for P-256.
		$with_prefix = self::P256_MULTICODEC . $compressed;

		// Encode with base58btc (prefix 'z').
		return 'z' . self::base58_encode( $with_prefix );
	}

	/**
	 * Sign data with the private key.
	 *
	 * @param string $data The data to sign.
	 * @return string|false The signature or false on failure.
	 */
	public static function sign( $data ) {
		$private_key = self::get_private_key();
		if ( ! $private_key ) {
			return false;
		}

		$key = openssl_pkey_get_private( $private_key );
		if ( ! $key ) {
			return false;
		}

		$signature = '';
		if ( ! openssl_sign( $data, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			return false;
		}

		// Convert DER signature to raw format (r || s).
		return self::der_to_raw( $signature );
	}

	/**
	 * Verify a signature.
	 *
	 * @param string $data      The original data.
	 * @param string $signature The raw signature (r || s).
	 * @param string $public_key_pem The public key in PEM format.
	 * @return bool True if valid, false otherwise.
	 */
	public static function verify( $data, $signature, $public_key_pem ) {
		$key = openssl_pkey_get_public( $public_key_pem );
		if ( ! $key ) {
			return false;
		}

		// Convert raw signature to DER format.
		$der_signature = self::raw_to_der( $signature );

		$result = openssl_verify( $data, $der_signature, $key, OPENSSL_ALGO_SHA256 );

		return 1 === $result;
	}

	/**
	 * Convert DER-encoded signature to raw format (r || s).
	 *
	 * @param string $der The DER-encoded signature.
	 * @return string The raw signature.
	 */
	private static function der_to_raw( $der ) {
		$offset = 0;

		// Skip SEQUENCE tag and length.
		$offset += 2;
		if ( ord( $der[1] ) > 127 ) {
			$offset += ord( $der[1] ) - 128;
		}

		// Read R.
		$offset++; // INTEGER tag.
		$r_len  = ord( $der[ $offset++ ] );
		$r      = substr( $der, $offset, $r_len );
		$offset += $r_len;

		// Read S.
		$offset++; // INTEGER tag.
		$s_len = ord( $der[ $offset++ ] );
		$s     = substr( $der, $offset, $s_len );

		// Remove leading zeros and pad to 32 bytes.
		$r = ltrim( $r, "\x00" );
		$s = ltrim( $s, "\x00" );
		$r = str_pad( $r, 32, "\x00", STR_PAD_LEFT );
		$s = str_pad( $s, 32, "\x00", STR_PAD_LEFT );

		return $r . $s;
	}

	/**
	 * Convert raw signature (r || s) to DER format.
	 *
	 * @param string $raw The raw signature.
	 * @return string The DER-encoded signature.
	 */
	private static function raw_to_der( $raw ) {
		$r = substr( $raw, 0, 32 );
		$s = substr( $raw, 32, 32 );

		// Add leading zero if high bit is set (to ensure positive integer).
		if ( ord( $r[0] ) >= 128 ) {
			$r = "\x00" . $r;
		}
		if ( ord( $s[0] ) >= 128 ) {
			$s = "\x00" . $s;
		}

		// Remove leading zeros (except one if high bit would be set).
		$r = ltrim( $r, "\x00" );
		$s = ltrim( $s, "\x00" );
		if ( ord( $r[0] ) >= 128 ) {
			$r = "\x00" . $r;
		}
		if ( ord( $s[0] ) >= 128 ) {
			$s = "\x00" . $s;
		}
		if ( '' === $r ) {
			$r = "\x00";
		}
		if ( '' === $s ) {
			$s = "\x00";
		}

		$r_enc = "\x02" . chr( strlen( $r ) ) . $r;
		$s_enc = "\x02" . chr( strlen( $s ) ) . $s;

		$body = $r_enc . $s_enc;

		return "\x30" . chr( strlen( $body ) ) . $body;
	}

	/**
	 * Encode bytes in base58btc.
	 *
	 * @param string $data Binary data.
	 * @return string Base58btc encoded string.
	 */
	private static function base58_encode( $data ) {
		$num = gmp_import( $data, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN );

		if ( gmp_cmp( $num, 0 ) === 0 ) {
			return self::BASE58_ALPHABET[0];
		}

		$result = '';
		$base   = gmp_init( 58 );

		while ( gmp_cmp( $num, 0 ) > 0 ) {
			list( $num, $remainder ) = gmp_div_qr( $num, $base );
			$result = self::BASE58_ALPHABET[ gmp_intval( $remainder ) ] . $result;
		}

		// Add leading '1's for each leading zero byte.
		for ( $i = 0; $i < strlen( $data ) && "\x00" === $data[ $i ]; $i++ ) {
			$result = self::BASE58_ALPHABET[0] . $result;
		}

		return $result;
	}

	/**
	 * Decode base58btc string to bytes.
	 *
	 * @param string $data Base58btc encoded string.
	 * @return string Binary data.
	 */
	public static function base58_decode( $data ) {
		$num  = gmp_init( 0 );
		$base = gmp_init( 58 );

		for ( $i = 0; $i < strlen( $data ); $i++ ) {
			$pos = strpos( self::BASE58_ALPHABET, $data[ $i ] );
			if ( false === $pos ) {
				return '';
			}
			$num = gmp_add( gmp_mul( $num, $base ), $pos );
		}

		$bytes = gmp_export( $num, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN );

		// Add leading zero bytes.
		for ( $i = 0; $i < strlen( $data ) && self::BASE58_ALPHABET[0] === $data[ $i ]; $i++ ) {
			$bytes = "\x00" . $bytes;
		}

		return $bytes;
	}

	/**
	 * Delete stored keys.
	 *
	 * @return bool True on success.
	 */
	public static function delete_keys() {
		delete_option( self::PRIVATE_KEY_OPTION );
		delete_option( self::PUBLIC_KEY_OPTION );
		return true;
	}

	/**
	 * Check if keys exist.
	 *
	 * @return bool True if keys exist.
	 */
	public static function has_keys() {
		return false !== self::get_private_key() && false !== self::get_public_key();
	}
}
