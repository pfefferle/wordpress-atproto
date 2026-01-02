<?php
/**
 * XRPC endpoint: com.atproto.repo.putRecord
 *
 * Write a record, creating or updating it as needed.
 *
 * @package ATProto
 */

namespace ATProto\Rest\Repo;

use ATProto\ATProto;
use ATProto\Repository\Repository;
use ATProto\Rest\XRPC_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Put Record controller.
 */
class Put_Record extends XRPC_Controller {
	/**
	 * Get the XRPC method name.
	 *
	 * @return string
	 */
	public function get_method_name() {
		return 'com.atproto.repo.putRecord';
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
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'validate'   => array(
				'description' => __( 'Validate the record.', 'atproto' ),
				'type'        => 'boolean',
				'required'    => false,
				'default'     => true,
			),
			'record'     => array(
				'description' => __( 'The record to write.', 'atproto' ),
				'type'        => 'object',
				'required'    => true,
			),
			'swapRecord' => array(
				'description'       => __( 'Compare and swap with the previous record CID.', 'atproto' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
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
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_request( WP_REST_Request $request ) {
		$repo       = $request->get_param( 'repo' );
		$collection = $request->get_param( 'collection' );
		$rkey       = $request->get_param( 'rkey' );
		$record     = $request->get_param( 'record' );
		$swap_record = $request->get_param( 'swapRecord' );

		// Check if this is our repository.
		$our_did    = ATProto::get_did();
		$our_handle = ATProto::get_handle();
		$is_local   = ( $repo === $our_did || $repo === $our_handle );

		if ( ! $is_local ) {
			return $this->xrpc_error(
				'RepoNotFound',
				__( 'Cannot write to remote repository.', 'atproto' ),
				400
			);
		}

		// Check swap condition if specified.
		if ( $swap_record ) {
			$existing = Repository::get_record( $collection, $rkey );
			if ( $existing && $existing['cid'] !== $swap_record ) {
				return $this->xrpc_error(
					'InvalidSwap',
					__( 'Record has been modified.', 'atproto' ),
					400
				);
			}
		}

		// Ensure $type is set.
		if ( ! isset( $record['$type'] ) ) {
			$record['$type'] = $collection;
		}

		// Write the record.
		$result = Repository::put_record( $collection, $rkey, $record );

		if ( ! $result ) {
			return $this->xrpc_error(
				'WriteFailed',
				__( 'Failed to write record.', 'atproto' ),
				500
			);
		}

		return $this->xrpc_response( $result );
	}
}
