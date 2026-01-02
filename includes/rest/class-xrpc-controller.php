<?php
/**
 * Base XRPC Controller for AT Protocol endpoints.
 *
 * @package ATProto
 */

namespace ATProto\Rest;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract XRPC Controller class.
 */
abstract class XRPC_Controller extends WP_REST_Controller {
	/**
	 * Namespace for XRPC endpoints.
	 *
	 * @var string
	 */
	protected $namespace = 'xrpc';

	/**
	 * Get the XRPC method name (NSID).
	 *
	 * @return string The method NSID (e.g., "com.atproto.identity.resolveHandle").
	 */
	abstract public function get_method_name();

	/**
	 * Get the XRPC method type.
	 *
	 * @return string Either 'query' (GET) or 'procedure' (POST).
	 */
	abstract public function get_method_type();

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$method = 'query' === $this->get_method_type() ? WP_REST_Server::READABLE : WP_REST_Server::CREATABLE;

		register_rest_route(
			$this->namespace,
			'/' . $this->get_method_name(),
			array(
				array(
					'methods'             => $method,
					'callback'            => array( $this, 'handle_request' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => $this->get_endpoint_args(),
				),
			)
		);
	}

	/**
	 * Handle the XRPC request.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response or error.
	 */
	abstract public function handle_request( WP_REST_Request $request );

	/**
	 * Check request permissions.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool|WP_Error True if permitted, WP_Error otherwise.
	 */
	public function check_permissions( WP_REST_Request $request ) {
		// Default: public access for query methods.
		if ( 'query' === $this->get_method_type() ) {
			return true;
		}

		// Procedures require authentication by default.
		return $this->verify_auth( $request );
	}

	/**
	 * Get endpoint arguments.
	 *
	 * @return array The endpoint arguments.
	 */
	public function get_endpoint_args() {
		return array();
	}

	/**
	 * Verify authentication for the request.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool|WP_Error True if authenticated, WP_Error otherwise.
	 */
	protected function verify_auth( WP_REST_Request $request ) {
		// Check for Bearer token.
		$auth_header = $request->get_header( 'Authorization' );

		if ( empty( $auth_header ) ) {
			return new WP_Error(
				'AuthenticationRequired',
				__( 'Authentication required.', 'atproto' ),
				array( 'status' => 401 )
			);
		}

		// TODO: Implement proper JWT/OAuth token verification.
		return new WP_Error(
			'InvalidToken',
			__( 'Invalid or expired token.', 'atproto' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Create an XRPC error response.
	 *
	 * @param string $error   The error code.
	 * @param string $message The error message.
	 * @param int    $status  HTTP status code.
	 * @return WP_Error The error object.
	 */
	protected function xrpc_error( $error, $message, $status = 400 ) {
		return new WP_Error(
			$error,
			$message,
			array( 'status' => $status )
		);
	}

	/**
	 * Create an XRPC success response.
	 *
	 * @param array $data   The response data.
	 * @param int   $status HTTP status code.
	 * @return WP_REST_Response The response object.
	 */
	protected function xrpc_response( $data, $status = 200 ) {
		return new WP_REST_Response( $data, $status );
	}
}
