<?php
/**
 * XRPC endpoint: _health
 *
 * Basic health check endpoint for AT Protocol PDS.
 *
 * @package ATProto
 */

namespace ATProto\Rest\Server;

use ATProto\Rest\XRPC_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Health check controller.
 */
class Health extends XRPC_Controller {
	/**
	 * Get the XRPC method name.
	 *
	 * @return string
	 */
	public function get_method_name() {
		return '_health';
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
	 * Handle the request.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function handle_request( \WP_REST_Request $request ) {
		return $this->xrpc_response( array(
			'version' => ATPROTO_VERSION,
		) );
	}
}
