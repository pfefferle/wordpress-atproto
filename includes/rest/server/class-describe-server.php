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
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function handle_request( \WP_REST_Request $request ) {
		$did = ATProto::get_did();

		$response = array(
			'did'                       => $did,
			'availableUserDomains'      => array(
				ATProto::get_handle(),
			),
			'inviteCodeRequired'        => false,
			'phoneVerificationRequired' => false,
			'contact'                   => array(
				'email' => get_option( 'admin_email' ),
			),
		);

		// Add links if privacy policy page is set.
		$privacy_policy_url = get_privacy_policy_url();
		if ( $privacy_policy_url ) {
			$response['links'] = array(
				'privacyPolicy' => $privacy_policy_url,
			);
		}

		return $this->xrpc_response( $response );
	}
}
