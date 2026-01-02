<?php
/**
 * XRPC endpoint: com.atproto.sync.getRepo
 *
 * Download a repository as a CAR file.
 *
 * @package ATProto
 */

namespace ATProto\Rest\Sync;

use ATProto\ATProto;
use ATProto\Repository\Repository;
use ATProto\Rest\XRPC_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Get Repo controller.
 */
class Get_Repo extends XRPC_Controller {
	/**
	 * Get the XRPC method name.
	 *
	 * @return string
	 */
	public function get_method_name() {
		return 'com.atproto.sync.getRepo';
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
			'did'   => array(
				'description'       => __( 'The DID of the repo.', 'atproto' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'since' => array(
				'description'       => __( 'The revision to start from.', 'atproto' ),
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
		$did   = $request->get_param( 'did' );
		$since = $request->get_param( 'since' );

		// Check if this is our repository.
		if ( $did !== ATProto::get_did() ) {
			return $this->xrpc_error(
				'RepoNotFound',
				__( 'Repository not found.', 'atproto' ),
				404
			);
		}

		// Export repository as CAR.
		$car = Repository::export_car();

		// Return as binary.
		$response = new WP_REST_Response( null, 200 );
		$response->header( 'Content-Type', 'application/vnd.ipld.car' );
		$response->header( 'Content-Length', strlen( $car ) );

		// We need to output directly for binary data.
		add_filter( 'rest_pre_serve_request', function ( $served ) use ( $car ) {
			echo $car;
			return true;
		} );

		return $response;
	}
}
