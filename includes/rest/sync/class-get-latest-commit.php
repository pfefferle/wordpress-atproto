<?php
/**
 * XRPC endpoint: com.atproto.sync.getLatestCommit
 *
 * Get the current commit CID & revision of the specified repo.
 * Used by relays to check if a PDS has new data.
 *
 * @package ATProto
 */

namespace ATProto\Rest\Sync;

use ATProto\ATProto;
use ATProto\Repository\Repository;
use ATProto\Rest\XRPC_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Get Latest Commit controller.
 */
class Get_Latest_Commit extends XRPC_Controller {
	/**
	 * Get the XRPC method name.
	 *
	 * @return string
	 */
	public function get_method_name() {
		return 'com.atproto.sync.getLatestCommit';
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
				'description'       => __( 'The DID of the repo.', 'atproto' ),
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

		// Check if this is our repository.
		if ( $did !== ATProto::get_did() ) {
			return $this->xrpc_error(
				'RepoNotFound',
				__( 'Repository not found.', 'atproto' ),
				404
			);
		}

		$state = Repository::get_state();

		// Ensure we have a valid commit.
		if ( empty( $state['commit'] ) ) {
			$state = Repository::initialize();
		}

		return $this->xrpc_response( array(
			'cid' => $state['commit'],
			'rev' => $state['rev'],
		) );
	}
}
