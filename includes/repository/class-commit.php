<?php
/**
 * AT Protocol Repository Commit.
 *
 * A commit represents a signed snapshot of the repository state.
 * Each commit references the MST root and the previous commit.
 *
 * @package ATProto
 */

namespace ATProto\Repository;

use ATProto\ATProto;
use ATProto\Identity\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Commit class for repository commits.
 */
class Commit {
	/**
	 * Commit version.
	 *
	 * @var int
	 */
	const VERSION = 3;

	/**
	 * Create a new signed commit.
	 *
	 * @param string $data_cid The MST root CID.
	 * @param string $rev      The revision TID.
	 * @param string $prev_cid The previous commit CID (empty for first commit).
	 * @return array The commit object with CID.
	 */
	public static function create( $data_cid, $rev, $prev_cid = '' ) {
		$did = ATProto::get_did();

		// Build unsigned commit object.
		$commit = array(
			'did'     => $did,
			'version' => self::VERSION,
			'data'    => array( '$link' => $data_cid ),
			'rev'     => $rev,
		);

		if ( ! empty( $prev_cid ) ) {
			$commit['prev'] = array( '$link' => $prev_cid );
		} else {
			$commit['prev'] = null;
		}

		// Encode for signing (without signature).
		$unsigned_cbor = CBOR::encode( $commit );

		// Sign the commit.
		$signature = Crypto::sign( $unsigned_cbor );

		if ( false === $signature ) {
			// Fallback to empty signature if signing fails.
			$signature = '';
		}

		// Add signature to commit.
		$commit['sig'] = array( '$bytes' => base64_encode( $signature ) );

		// Encode final commit.
		$signed_cbor = CBOR::encode( $commit );

		// Compute CID.
		$cid = CID::from_bytes( $signed_cbor );

		return array(
			'cid'    => $cid,
			'object' => $commit,
			'data'   => $signed_cbor,
			'rev'    => $rev,
		);
	}

	/**
	 * Verify a commit signature.
	 *
	 * @param array  $commit     The commit object.
	 * @param string $public_key The public key PEM.
	 * @return bool True if valid.
	 */
	public static function verify( $commit, $public_key ) {
		// Extract signature.
		if ( ! isset( $commit['sig']['$bytes'] ) ) {
			return false;
		}

		$signature = base64_decode( $commit['sig']['$bytes'], true );

		// Build unsigned commit for verification.
		// Must remove 'sig' entirely (not set to null) to match the
		// CBOR encoding used during signing in create().
		$unsigned = $commit;
		unset( $unsigned['sig'] );

		$unsigned_cbor = CBOR::encode( $unsigned );

		return Crypto::verify( $unsigned_cbor, $signature, $public_key );
	}

	/**
	 * Parse a commit from CBOR bytes.
	 *
	 * @param string $data The CBOR bytes.
	 * @return array|null The commit object or null.
	 */
	public static function parse( $data ) {
		$commit = CBOR::decode( $data );

		if ( ! $commit || ! isset( $commit['did'], $commit['data'], $commit['rev'] ) ) {
			return null;
		}

		return $commit;
	}

	/**
	 * Get the data (MST root) CID from a commit.
	 *
	 * @param array $commit The commit object.
	 * @return string|null The data CID.
	 */
	public static function get_data_cid( $commit ) {
		return $commit['data']['$link'] ?? null;
	}

	/**
	 * Get the previous commit CID.
	 *
	 * @param array $commit The commit object.
	 * @return string|null The previous CID or null.
	 */
	public static function get_prev_cid( $commit ) {
		return $commit['prev']['$link'] ?? null;
	}

	/**
	 * Get the revision from a commit.
	 *
	 * @param array $commit The commit object.
	 * @return string The revision TID.
	 */
	public static function get_rev( $commit ) {
		return $commit['rev'] ?? '';
	}

	/**
	 * Get the DID from a commit.
	 *
	 * @param array $commit The commit object.
	 * @return string The DID.
	 */
	public static function get_did( $commit ) {
		return $commit['did'] ?? '';
	}

}
