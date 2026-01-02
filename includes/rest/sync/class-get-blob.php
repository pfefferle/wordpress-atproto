<?php
/**
 * XRPC endpoint: com.atproto.sync.getBlob
 *
 * Download a blob by CID.
 *
 * @package ATProto
 */

namespace ATProto\Rest\Sync;

use ATProto\ATProto;
use ATProto\Collection\Blobs;
use ATProto\Rest\XRPC_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Get Blob controller.
 */
class Get_Blob extends XRPC_Controller {
	/**
	 * Get the XRPC method name.
	 *
	 * @return string
	 */
	public function get_method_name() {
		return 'com.atproto.sync.getBlob';
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
			'did' => array(
				'description'       => __( 'The DID of the account.', 'atproto' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'cid' => array(
				'description'       => __( 'The CID of the blob.', 'atproto' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Handle the request.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_request( \WP_REST_Request $request ) {
		$did = $request->get_param( 'did' );
		$cid = $request->get_param( 'cid' );

		// Check if this is our repository.
		if ( $did !== ATProto::get_did() ) {
			return $this->xrpc_error(
				'RepoNotFound',
				__( 'Repository not found.', 'atproto' ),
				404
			);
		}

		// Get the blob.
		$blob = Blobs::get( $cid );

		if ( ! $blob ) {
			return $this->xrpc_error(
				'BlobNotFound',
				__( 'Blob not found.', 'atproto' ),
				404
			);
		}

		// Return as binary with appropriate content type.
		$response = new \WP_REST_Response( null, 200 );
		$response->header( 'Content-Type', $blob['mimeType'] );
		$response->header( 'Content-Length', $blob['size'] );

		// Output binary data directly.
		add_filter( 'rest_pre_serve_request', function ( $served ) use ( $blob ) {
			echo $blob['data']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return true;
		} );

		return $response;
	}
}
