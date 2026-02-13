<?php
/**
 * Internal REST endpoint: Firehose Events.
 *
 * Serves queued firehose events to the Go WebSocket bridge.
 *
 * @package ATProto
 */

namespace ATProto\Rest\Internal;

use ATProto\Collection\Firehose;

defined( 'ABSPATH' ) || exit;

/**
 * Firehose Events controller.
 */
class Firehose_Events extends \WP_REST_Controller {
	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'atproto/v1';

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/firehose/events',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_events' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'since' => array(
							'description'       => __( 'Return events after this sequence number.', 'atproto' ),
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
						'limit' => array(
							'description'       => __( 'Maximum number of events to return.', 'atproto' ),
							'type'              => 'integer',
							'default'           => 100,
							'minimum'           => 1,
							'maximum'           => 1000,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Check request permissions.
	 *
	 * Requires authentication via Application Passwords (or cookie auth).
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return bool|\WP_Error True if permitted.
	 */
	public function check_permissions( \WP_REST_Request $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new \WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to access firehose events.', 'atproto' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Handle the events request.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function get_events( \WP_REST_Request $request ) {
		$since = $request->get_param( 'since' );
		$limit = $request->get_param( 'limit' );

		$events = Firehose::get_events( $since, $limit );

		return new \WP_REST_Response(
			array(
				'events' => $events,
				'seq'    => Firehose::get_seq(),
			),
			200
		);
	}
}
