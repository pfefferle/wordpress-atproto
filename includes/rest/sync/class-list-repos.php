<?php
/**
 * XRPC endpoint: com.atproto.sync.listRepos
 *
 * Enumerates all the DID, rev, and commit CID for all repos hosted by this service.
 *
 * @package ATProto
 */

namespace ATProto\Rest\Sync;

use ATProto\ATProto;
use ATProto\Repository\Repository;
use ATProto\Rest\XRPC_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * List Repos controller.
 */
class List_Repos extends XRPC_Controller {
	/**
	 * Get the XRPC method name.
	 *
	 * @return string
	 */
	public function get_method_name() {
		return 'com.atproto.sync.listRepos';
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
			'limit'  => array(
				'description'       => __( 'Maximum number of repos to return.', 'atproto' ),
				'type'              => 'integer',
				'required'          => false,
				'default'           => 500,
				'minimum'           => 1,
				'maximum'           => 1000,
				'sanitize_callback' => 'absint',
			),
			'cursor' => array(
				'description'       => __( 'Pagination cursor.', 'atproto' ),
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
		$state = Repository::get_state();

		// Reinitialize if state is missing the commit CID.
		if ( empty( $state['commit'] ) ) {
			$state = Repository::initialize();
		}

		$repo = array(
			'did'  => ATProto::get_did(),
			'head' => $state['commit'],
			'rev'  => $state['rev'],
		);

		return $this->xrpc_response( array(
			'repos' => array( $repo ),
		) );
	}
}
