<?php
/**
 * XRPC endpoint: com.atproto.repo.describeRepo
 *
 * Get information about a repository, including the list of collections.
 *
 * @package ATProto
 */

namespace ATProto\Rest\Repo;

use ATProto\ATProto;
use ATProto\Identity\DID_Document;
use ATProto\Rest\XRPC_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Describe Repo controller.
 */
class Describe_Repo extends XRPC_Controller {
	/**
	 * Get the XRPC method name.
	 *
	 * @return string
	 */
	public function get_method_name() {
		return 'com.atproto.repo.describeRepo';
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
			'repo' => array(
				'description'       => __( 'The handle or DID of the repo.', 'atproto' ),
				'type'              => 'string',
				'required'          => true,
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
		$repo = $request->get_param( 'repo' );

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

		// Get available collections.
		$collections = array(
			'app.bsky.feed.post',
			'app.bsky.feed.like',
			'app.bsky.feed.repost',
			'app.bsky.graph.follow',
		);

		/**
		 * Filter the available collections.
		 *
		 * @param array $collections The collections.
		 */
		$collections = apply_filters( 'atproto_repo_collections', $collections );

		return $this->xrpc_response( array(
			'handle'          => $our_handle,
			'did'             => $our_did,
			'didDoc'          => DID_Document::generate(),
			'collections'     => $collections,
			'handleIsCorrect' => true,
		) );
	}
}
