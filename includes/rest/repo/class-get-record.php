<?php
/**
 * XRPC endpoint: com.atproto.repo.getRecord
 *
 * Get a single record from a repository.
 *
 * @package ATProto
 */

namespace ATProto\Rest\Repo;

use ATProto\ATProto;
use ATProto\Repository\Record;
use ATProto\Rest\XRPC_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Get Record controller.
 */
class Get_Record extends XRPC_Controller {
	/**
	 * Get the XRPC method name.
	 *
	 * @return string
	 */
	public function get_method_name() {
		return 'com.atproto.repo.getRecord';
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
			'repo'       => array(
				'description'       => __( 'The handle or DID of the repo.', 'atproto' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'collection' => array(
				'description'       => __( 'The NSID of the record collection.', 'atproto' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'rkey'       => array(
				'description'       => __( 'The Record Key.', 'atproto' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'cid'        => array(
				'description'       => __( 'The CID of the version of the record.', 'atproto' ),
				'type'              => 'string',
				'required'          => false,
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
		$repo       = $request->get_param( 'repo' );
		$collection = $request->get_param( 'collection' );
		$rkey       = $request->get_param( 'rkey' );
		$cid        = $request->get_param( 'cid' );

		// Check if this is our repository.
		$our_did    = ATProto::get_did();
		$our_handle = ATProto::get_handle();

		$is_our_repo = ( $repo === $our_did || $repo === $our_handle || $repo === '@' . $our_handle );

		if ( ! $is_our_repo ) {
			return $this->xrpc_error(
				'RepoNotFound',
				__( 'Repository not found.', 'atproto' ),
				404
			);
		}

		// Get the record.
		$record = Record::get( $collection, $rkey );

		if ( ! $record ) {
			return $this->xrpc_error(
				'RecordNotFound',
				__( 'Record not found.', 'atproto' ),
				404
			);
		}

		// If CID specified, verify it matches.
		if ( $cid && isset( $record['cid'] ) && $record['cid'] !== $cid ) {
			return $this->xrpc_error(
				'RecordNotFound',
				__( 'Record not found with specified CID.', 'atproto' ),
				404
			);
		}

		return $this->xrpc_response( array(
			'uri'   => 'at://' . $our_did . '/' . $collection . '/' . $rkey,
			'cid'   => $record['cid'] ?? null,
			'value' => $record['value'] ?? $record,
		) );
	}
}
