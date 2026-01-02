<?php
/**
 * AT Protocol Timestamp Identifier (TID) generation.
 *
 * @package ATProto
 */

namespace ATProto\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * TID class for generating AT Protocol timestamp identifiers.
 *
 * TIDs are 13-character base32-sortable identifiers encoding:
 * - Timestamp in microseconds (high 53 bits)
 * - Clock identifier (10 bits)
 *
 * Format: base32-sortable encoding of 64-bit value.
 */
class TID {
	/**
	 * Base32 sortable alphabet (Crockford's variant without ambiguous chars).
	 *
	 * @var string
	 */
	const ALPHABET = '234567abcdefghijklmnopqrstuvwxyz';

	/**
	 * TID length in characters.
	 *
	 * @var int
	 */
	const LENGTH = 13;

	/**
	 * Last generated timestamp to ensure uniqueness.
	 *
	 * @var int
	 */
	private static $last_timestamp = 0;

	/**
	 * Clock ID for this process.
	 *
	 * @var int
	 */
	private static $clock_id = null;

	/**
	 * Generate a new TID.
	 *
	 * @return string A 13-character TID.
	 */
	public static function generate() {
		// Get current timestamp in microseconds.
		$timestamp = self::get_timestamp();

		// Ensure monotonically increasing.
		if ( $timestamp <= self::$last_timestamp ) {
			$timestamp = self::$last_timestamp + 1;
		}
		self::$last_timestamp = $timestamp;

		// Get clock ID (random 10-bit value per process).
		if ( null === self::$clock_id ) {
			self::$clock_id = wp_rand( 0, 1023 );
		}

		// Combine: timestamp (53 bits) + clock_id (10 bits) + 1 bit padding.
		// Actually TID uses timestamp in top 53 bits, then 10 bits clock ID.
		$value = ( $timestamp << 10 ) | self::$clock_id;

		return self::encode( $value );
	}

	/**
	 * Generate a TID from a specific timestamp.
	 *
	 * @param int $microseconds Timestamp in microseconds.
	 * @return string A 13-character TID.
	 */
	public static function from_timestamp( $microseconds ) {
		if ( null === self::$clock_id ) {
			self::$clock_id = wp_rand( 0, 1023 );
		}

		$value = ( $microseconds << 10 ) | self::$clock_id;

		return self::encode( $value );
	}

	/**
	 * Extract timestamp from a TID.
	 *
	 * @param string $tid The TID to decode.
	 * @return int Timestamp in microseconds.
	 */
	public static function to_timestamp( $tid ) {
		$value = self::decode( $tid );
		return $value >> 10;
	}

	/**
	 * Validate a TID format.
	 *
	 * @param string $tid The TID to validate.
	 * @return bool True if valid TID format.
	 */
	public static function is_valid( $tid ) {
		if ( strlen( $tid ) !== self::LENGTH ) {
			return false;
		}

		// Check all characters are in alphabet.
		for ( $i = 0; $i < self::LENGTH; $i++ ) {
			if ( false === strpos( self::ALPHABET, $tid[ $i ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get current timestamp in microseconds.
	 *
	 * @return int Timestamp in microseconds.
	 */
	private static function get_timestamp() {
		// microtime(true) returns seconds with microseconds as float.
		return (int) ( microtime( true ) * 1000000 );
	}

	/**
	 * Encode a 64-bit value to TID string.
	 *
	 * @param int $value The value to encode.
	 * @return string The 13-character TID.
	 */
	private static function encode( $value ) {
		$result = '';

		// Encode 64 bits in groups of 5 bits (base32).
		// 13 characters * 5 bits = 65 bits, so we have 1 extra bit.
		for ( $i = 0; $i < self::LENGTH; $i++ ) {
			$shift    = ( self::LENGTH - 1 - $i ) * 5;
			$index    = ( $value >> $shift ) & 0x1F;
			$result  .= self::ALPHABET[ $index ];
		}

		return $result;
	}

	/**
	 * Decode a TID string to 64-bit value.
	 *
	 * @param string $tid The TID to decode.
	 * @return int The decoded value.
	 */
	private static function decode( $tid ) {
		$value = 0;

		for ( $i = 0; $i < self::LENGTH; $i++ ) {
			$char  = $tid[ $i ];
			$index = strpos( self::ALPHABET, $char );

			if ( false === $index ) {
				return 0;
			}

			$shift = ( self::LENGTH - 1 - $i ) * 5;
			$value |= ( $index << $shift );
		}

		return $value;
	}

	/**
	 * Compare two TIDs chronologically.
	 *
	 * @param string $tid1 First TID.
	 * @param string $tid2 Second TID.
	 * @return int -1 if tid1 < tid2, 0 if equal, 1 if tid1 > tid2.
	 */
	public static function compare( $tid1, $tid2 ) {
		// TIDs are designed to be lexicographically sortable.
		return strcmp( $tid1, $tid2 );
	}
}
