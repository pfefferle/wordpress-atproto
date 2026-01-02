<?php
/**
 * XRPC endpoint: com.atproto.identity.resolveHandle
 *
 * Resolves a handle to a DID.
 *
 * @package ATProto
 */

namespace ATProto\Rest\Identity;

use ATProto\ATProto;
use ATProto\Rest\XRPC_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Resolve Handle controller.
 */
class Resolve_Handle extends XRPC_Controller {
	/**
	 * Get the XRPC method name.
	 *
	 * @return string
	 */
	public function get_method_name() {
		return 'com.atproto.identity.resolveHandle';
	}

	/**
	 * Get the XRPC method type.
	 *
	 * @return string
	 */
	public function get_method_type() {
		return 'query';
	}

	/**
	 * Get endpoint arguments.
	 *
	 * @return array
	 */
	public function get_endpoint_args() {
		return array(
			'handle' => array(
				'description'       => __( 'The handle to resolve.', 'atproto' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_handle' ),
			),
		);
	}

	/**
	 * Validate handle parameter.
	 *
	 * @param string $handle The handle to validate.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_handle( $handle ) {
		// Handle should be a valid domain name or handle format.
		if ( empty( $handle ) ) {
			return new WP_Error(
				'InvalidHandle',
				__( 'Handle is required.', 'atproto' )
			);
		}

		// Remove @ prefix if present.
		$handle = ltrim( $handle, '@' );

		// Basic validation: should look like a domain.
		if ( ! preg_match( '/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*$/', $handle ) ) {
			return new WP_Error(
				'InvalidHandle',
				__( 'Invalid handle format.', 'atproto' )
			);
		}

		return true;
	}

	/**
	 * Handle the request.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_request( WP_REST_Request $request ) {
		$handle = ltrim( $request->get_param( 'handle' ), '@' );

		// Check if this handle is for our site.
		$our_handle = ATProto::get_handle();

		if ( strtolower( $handle ) === strtolower( $our_handle ) ) {
			return $this->xrpc_response( array(
				'did' => ATProto::get_did(),
			) );
		}

		// For other handles, we could look up from cache or return error.
		// For now, we only resolve our own handle.
		return $this->xrpc_error(
			'HandleNotFound',
			sprintf(
				/* translators: %s: the handle */
				__( 'Handle not found: %s', 'atproto' ),
				$handle
			),
			404
		);
	}
}
