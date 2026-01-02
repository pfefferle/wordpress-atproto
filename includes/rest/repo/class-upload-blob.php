<?php
/**
 * XRPC endpoint: com.atproto.repo.uploadBlob
 *
 * Upload a blob to the repository.
 *
 * @package ATProto
 */

namespace ATProto\Rest\Repo;

use ATProto\Collection\Blobs;
use ATProto\Rest\XRPC_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Upload Blob controller.
 */
class Upload_Blob extends XRPC_Controller {
	/**
	 * Maximum blob size (1MB).
	 *
	 * @var int
	 */
	const MAX_BLOB_SIZE = 1000000;

	/**
	 * Get the XRPC method name.
	 *
	 * @return string
	 */
	public function get_method_name() {
		return 'com.atproto.repo.uploadBlob';
	}

	/**
	 * Get the XRPC method type.
	 *
	 * @return string
	 */
	public function get_method_type() {
		return 'procedure';
	}

	/**
	 * Handle the request.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_request( \WP_REST_Request $request ) {
		// Get content type.
		$content_type = $request->get_content_type();
		$mime_type    = $content_type['value'] ?? 'application/octet-stream';

		// Get body as binary.
		$body = $request->get_body();

		if ( empty( $body ) ) {
			return $this->xrpc_error(
				'InvalidRequest',
				__( 'No blob data provided.', 'atproto' ),
				400
			);
		}

		// Check size.
		if ( strlen( $body ) > self::MAX_BLOB_SIZE ) {
			return $this->xrpc_error(
				'BlobTooLarge',
				__( 'Blob exceeds maximum size.', 'atproto' ),
				400
			);
		}

		// Upload the blob.
		$blob = Blobs::upload( $body, $mime_type );

		if ( ! $blob ) {
			return $this->xrpc_error(
				'UploadFailed',
				__( 'Failed to upload blob.', 'atproto' ),
				500
			);
		}

		return $this->xrpc_response( array(
			'blob' => $blob,
		) );
	}
}
