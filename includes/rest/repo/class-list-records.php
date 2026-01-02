<?php
/**
 * XRPC endpoint: com.atproto.repo.listRecords
 *
 * List a range of records in a repository.
 *
 * @package ATProto
 */

namespace ATProto\Rest\Repo;

use ATProto\ATProto;
use ATProto\Repository\Record;
use ATProto\Rest\XRPC_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * List Records controller.
 */
class List_Records extends XRPC_Controller {
	/**
	 * Default limit for records.
	 *
	 * @var int
	 */
	const DEFAULT_LIMIT = 50;

	/**
	 * Maximum limit for records.
	 *
	 * @var int
	 */
	const MAX_LIMIT = 100;

	/**
	 * Get the XRPC method name.
	 *
	 * @return string
	 */
	public function get_method_name() {
		return 'com.atproto.repo.listRecords';
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
			'limit'      => array(
				'description'       => __( 'The number of records to return.', 'atproto' ),
				'type'              => 'integer',
				'required'          => false,
				'default'           => self::DEFAULT_LIMIT,
				'minimum'           => 1,
				'maximum'           => self::MAX_LIMIT,
				'sanitize_callback' => 'absint',
			),
			'cursor'     => array(
				'description'       => __( 'Pagination cursor.', 'atproto' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'reverse'    => array(
				'description'       => __( 'Reverse the order of records.', 'atproto' ),
				'type'              => 'boolean',
				'required'          => false,
				'default'           => false,
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
		$limit      = min( $request->get_param( 'limit' ), self::MAX_LIMIT );
		$cursor     = $request->get_param( 'cursor' );
		$reverse    = $request->get_param( 'reverse' );

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

		// Get records.
		$result = Record::list_records( $collection, $limit, $cursor, $reverse );

		$records = array();
		foreach ( $result['records'] as $record ) {
			$records[] = array(
				'uri'   => 'at://' . $our_did . '/' . $collection . '/' . $record['rkey'],
				'cid'   => $record['cid'] ?? null,
				'value' => $record['value'] ?? $record,
			);
		}

		$response = array(
			'records' => $records,
		);

		if ( ! empty( $result['cursor'] ) ) {
			$response['cursor'] = $result['cursor'];
		}

		return $this->xrpc_response( $response );
	}
}
