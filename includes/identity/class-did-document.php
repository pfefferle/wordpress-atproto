<?php
/**
 * DID Document generation for AT Protocol.
 *
 * @package ATProto
 */

namespace ATProto\Identity;

use ATProto\ATProto;

defined( 'ABSPATH' ) || exit;

/**
 * DID Document class.
 *
 * Generates and serves the did:web DID document at /.well-known/did.json
 */
class DID_Document {
	/**
	 * Initialize the DID Document functionality.
	 *
	 * @return void
	 */
	public static function init() {
		// Ensure keys are generated.
		if ( ! Crypto::has_keys() ) {
			Crypto::generate_keys();
		}
	}

	/**
	 * Generate the DID document.
	 *
	 * @return array The DID document as an array.
	 */
	public static function generate() {
		$did         = ATProto::get_did();
		$handle      = ATProto::get_handle();
		$public_key  = Crypto::get_public_key_multibase();
		$pds_url     = home_url();

		$document = array(
			'@context'           => array(
				'https://www.w3.org/ns/did/v1',
				'https://w3id.org/security/multikey/v1',
			),
			'id'                 => $did,
			'alsoKnownAs'        => array(
				'at://' . $handle,
			),
			'verificationMethod' => array(
				array(
					'id'                 => $did . '#atproto',
					'type'               => 'Multikey',
					'controller'         => $did,
					'publicKeyMultibase' => $public_key,
				),
			),
			'service'            => array(
				array(
					'id'              => '#atproto_pds',
					'type'            => 'AtprotoPersonalDataServer',
					'serviceEndpoint' => $pds_url,
				),
			),
		);

		/**
		 * Filter the DID document.
		 *
		 * @param array  $document The DID document.
		 * @param string $did      The DID identifier.
		 */
		return apply_filters( 'atproto_did_document', $document, $did );
	}

	/**
	 * Get the DID document as JSON.
	 *
	 * @return string JSON-encoded DID document.
	 */
	public static function get_json() {
		return wp_json_encode(
			self::generate(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * Get the signing key ID.
	 *
	 * @return string The key ID for the AT Protocol signing key.
	 */
	public static function get_signing_key_id() {
		return ATProto::get_did() . '#atproto';
	}

	/**
	 * Get the PDS service endpoint.
	 *
	 * @return string The PDS endpoint URL.
	 */
	public static function get_pds_endpoint() {
		return home_url();
	}

	/**
	 * Resolve a DID to its document.
	 *
	 * @param string $did The DID to resolve.
	 * @return array|false The DID document or false on failure.
	 */
	public static function resolve( $did ) {
		if ( 0 !== strpos( $did, 'did:web:' ) ) {
			// Only support did:web for now.
			return false;
		}

		// Extract hostname and path from did:web.
		$identifier = substr( $did, 8 ); // Remove "did:web:".
		$identifier = str_replace( '%3A', ':', $identifier ); // Decode port.

		// Split by colons to get path segments.
		$parts = explode( ':', $identifier );
		$host  = array_shift( $parts );

		// Build URL.
		if ( empty( $parts ) ) {
			$url = 'https://' . $host . '/.well-known/did.json';
		} else {
			$path = implode( '/', $parts );
			$url  = 'https://' . $host . '/' . $path . '/did.json';
		}

		// Fetch the document.
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept' => 'application/did+json, application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return false;
		}

		$document = json_decode( $body, true );

		if ( ! $document || ! isset( $document['id'] ) ) {
			return false;
		}

		return $document;
	}

	/**
	 * Validate that a handle resolves to a DID and back.
	 *
	 * @param string $handle The handle to validate.
	 * @param string $did    The expected DID.
	 * @return bool True if valid bidirectional resolution.
	 */
	public static function validate_handle( $handle, $did ) {
		// Check DNS TXT record for _atproto.{handle}.
		$records = dns_get_record( '_atproto.' . $handle, DNS_TXT );

		if ( empty( $records ) ) {
			return false;
		}

		foreach ( $records as $record ) {
			if ( isset( $record['txt'] ) && 0 === strpos( $record['txt'], 'did=' ) ) {
				$resolved_did = substr( $record['txt'], 4 );
				if ( $resolved_did === $did ) {
					return true;
				}
			}
		}

		return false;
	}
}
