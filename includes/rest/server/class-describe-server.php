<?php
/**
 * XRPC endpoint: com.atproto.server.describeServer
 *
 * Describes the server's capabilities and configuration.
 *
 * @package ATProto
 */

namespace ATProto\Rest\Server;

use ATProto\ATProto;
use ATProto\Rest\XRPC_Controller;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Describe Server controller.
 */
class Describe_Server extends XRPC_Controller {
	/**
	 * Get the XRPC method name.
	 *
	 * @return string
	 */
	public function get_method_name() {
		return 'com.atproto.server.describeServer';
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
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function handle_request( WP_REST_Request $request ) {
		$did = ATProto::get_did();

		return $this->xrpc_response( array(
			'did'              => $did,
			'availableUserDomains' => array(
				ATProto::get_handle(),
			),
			'inviteCodeRequired' => false,
			'phoneVerificationRequired' => false,
			'links' => array(
				'privacyPolicy'  => home_url( '/privacy-policy/' ),
				'termsOfService' => home_url( '/terms-of-service/' ),
			),
			'contact' => array(
				'email' => get_option( 'admin_email' ),
			),
		) );
	}
}
