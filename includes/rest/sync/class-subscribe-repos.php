<?php
/**
 * XRPC endpoint: com.atproto.sync.subscribeRepos
 *
 * WebSocket firehose endpoint (stub - not supported in PHP).
 *
 * @package ATProto
 */

namespace ATProto\Rest\Sync;

use ATProto\Rest\XRPC_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Subscribe Repos controller (stub).
 */
class Subscribe_Repos extends XRPC_Controller {
	/**
	 * Get the XRPC method name.
	 *
	 * @return string
	 */
	public function get_method_name() {
		return 'com.atproto.sync.subscribeRepos';
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
			'cursor' => array(
				'description'       => __( 'The cursor to start from.', 'atproto' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
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
		// WebSocket subscriptions are not supported in PHP.
		// Return a proper error so relays know to use getRepo instead.
		return $this->xrpc_error(
			'MethodNotImplemented',
			__( 'WebSocket subscriptions are not supported. Use com.atproto.sync.getRepo for repository sync.', 'atproto' ),
			501
		);
	}
}
