<?php
/**
 * XRPC endpoint: com.atproto.repo.createRecord
 *
 * Create a new record in a repository.
 *
 * @package ATProto
 */

namespace ATProto\Rest\Repo;

use ATProto\ATProto;
use ATProto\Repository\Repository;
use ATProto\Repository\TID;
use ATProto\Handler\Handler;
use ATProto\Rest\XRPC_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Create Record controller.
 */
class Create_Record extends XRPC_Controller {
	/**
	 * Get the XRPC method name.
	 *
	 * @return string
	 */
	public function get_method_name() {
		return 'com.atproto.repo.createRecord';
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
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'validate'   => array(
				'description' => __( 'Validate the record.', 'atproto' ),
				'type'        => 'boolean',
				'required'    => false,
				'default'     => true,
			),
			'record'     => array(
				'description' => __( 'The record to create.', 'atproto' ),
				'type'        => 'object',
				'required'    => true,
			),
			'swapCommit' => array(
				'description'       => __( 'Compare and swap with the previous commit.', 'atproto' ),
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
		$record     = $request->get_param( 'record' );

		// Check if this is our repository (for local writes).
		$our_did    = ATProto::get_did();
		$our_handle = ATProto::get_handle();
		$is_local   = ( $repo === $our_did || $repo === $our_handle );

		if ( $is_local ) {
			// Local write - store in our repository.
			return $this->handle_local_create( $collection, $record, $rkey );
		}

		// Remote write - this is an incoming federated record.
		return $this->handle_remote_create( $repo, $collection, $record, $rkey );
	}

	/**
	 * Handle local record creation.
	 *
	 * @param string $collection The collection.
	 * @param array  $record     The record.
	 * @param string $rkey       Optional record key.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function handle_local_create( $collection, $record, $rkey = '' ) {
		if ( empty( $rkey ) ) {
			$rkey = TID::generate();
		}

		// Ensure $type is set.
		if ( ! isset( $record['$type'] ) ) {
			$record['$type'] = $collection;
		}

		// Create the record.
		$result = Repository::create_record( $collection, $record, $rkey );

		if ( ! $result ) {
			return $this->xrpc_error(
				'CreateFailed',
				__( 'Failed to create record.', 'atproto' ),
				500
			);
		}

		return $this->xrpc_response( $result );
	}

	/**
	 * Handle remote/federated record creation.
	 *
	 * @param string $repo       The remote repo DID.
	 * @param string $collection The collection.
	 * @param array  $record     The record.
	 * @param string $rkey       The record key.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function handle_remote_create( $repo, $collection, $record, $rkey ) {
		// This is an incoming federated record.
		// Dispatch to appropriate handler.

		// Get handle from DID (would need resolution in production).
		$handle = $repo;
		if ( 0 === strpos( $repo, 'did:' ) ) {
			// For now, extract handle from did:web.
			if ( 0 === strpos( $repo, 'did:web:' ) ) {
				$handle = substr( $repo, 8 );
			}
		}

		// Add URI to record for handler.
		$record['uri'] = "at://{$repo}/{$collection}/{$rkey}";

		// Dispatch.
		$handled = Handler::dispatch( $record, $repo, $handle );

		if ( $handled ) {
			return $this->xrpc_response( array(
				'uri' => $record['uri'],
				'cid' => '', // Would compute actual CID.
			) );
		}

		return $this->xrpc_error(
			'UnsupportedCollection',
			__( 'Collection not supported.', 'atproto' ),
			400
		);
	}
}
