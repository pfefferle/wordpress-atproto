<?php
/**
 * Post federation scheduler.
 *
 * Handles scheduling and processing of post federation to AT Protocol.
 *
 * @package ATProto
 */

namespace ATProto\Scheduler;

use ATProto\ATProto;
use ATProto\Repository\Record;
use ATProto\Repository\TID;

defined( 'ABSPATH' ) || exit;

/**
 * Post scheduler class.
 */
class Post {
	/**
	 * Initialize the scheduler.
	 *
	 * @return void
	 */
	public static function init() {
		// Hook into post status transitions.
		add_action( 'transition_post_status', array( self::class, 'handle_status_change' ), 10, 3 );

		// Hook into post updates.
		add_action( 'post_updated', array( self::class, 'handle_update' ), 10, 3 );

		// Register scheduled action.
		add_action( 'atproto_federate_post', array( self::class, 'process_federation' ) );
	}

	/**
	 * Handle post status transitions.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       The post object.
	 * @return void
	 */
	public static function handle_status_change( $new_status, $old_status, $post ) {
		// Check if this post type should be federated.
		if ( ! self::should_federate( $post ) ) {
			return;
		}

		// Publishing a post.
		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			self::schedule_create( $post->ID );
			return;
		}

		// Unpublishing a post.
		if ( 'publish' !== $new_status && 'publish' === $old_status ) {
			self::schedule_delete( $post->ID );
			return;
		}
	}

	/**
	 * Handle post updates.
	 *
	 * @param int      $post_id     The post ID.
	 * @param \WP_Post $post_after  Post object after update.
	 * @param \WP_Post $post_before Post object before update.
	 * @return void
	 */
	public static function handle_update( $post_id, $post_after, $post_before ) {
		// Only handle published posts.
		if ( 'publish' !== $post_after->post_status ) {
			return;
		}

		// Check if this post type should be federated.
		if ( ! self::should_federate( $post_after ) ) {
			return;
		}

		// Check if content actually changed.
		if ( $post_after->post_content === $post_before->post_content &&
			$post_after->post_title === $post_before->post_title ) {
			return;
		}

		self::schedule_update( $post_id );
	}

	/**
	 * Check if a post should be federated.
	 *
	 * @param \WP_Post $post The post to check.
	 * @return bool True if should be federated.
	 */
	public static function should_federate( $post ) {
		// Get enabled post types.
		$enabled_types = get_option( 'atproto_enabled_post_types', array( 'post' ) );

		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			return false;
		}

		// Check for password protection.
		if ( ! empty( $post->post_password ) ) {
			return false;
		}

		/**
		 * Filter whether a post should be federated.
		 *
		 * @param bool     $should_federate Whether to federate.
		 * @param \WP_Post $post            The post object.
		 */
		return apply_filters( 'atproto_should_federate_post', true, $post );
	}

	/**
	 * Schedule creation of AT Protocol record.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public static function schedule_create( $post_id ) {
		// Generate TID if not exists.
		$tid = get_post_meta( $post_id, Record::META_TID, true );
		if ( empty( $tid ) ) {
			$tid = TID::generate();
			update_post_meta( $post_id, Record::META_TID, $tid );
		}

		// Set collection.
		update_post_meta( $post_id, Record::META_COLLECTION, 'app.bsky.feed.post' );

		// Generate AT URI.
		$uri = 'at://' . ATProto::get_did() . '/app.bsky.feed.post/' . $tid;
		update_post_meta( $post_id, Record::META_URI, $uri );

		/**
		 * Fires when a post is created for AT Protocol.
		 *
		 * @param int    $post_id The post ID.
		 * @param string $tid     The AT Protocol TID.
		 * @param string $uri     The AT Protocol URI.
		 */
		do_action( 'atproto_post_created', $post_id, $tid, $uri );

		// For immediate federation, we'd schedule here.
		// For now, records are created on-demand when queried.
	}

	/**
	 * Schedule update of AT Protocol record.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public static function schedule_update( $post_id ) {
		// Clear CID to force regeneration.
		delete_post_meta( $post_id, Record::META_CID );

		/**
		 * Fires when a post is updated for AT Protocol.
		 *
		 * @param int $post_id The post ID.
		 */
		do_action( 'atproto_post_updated', $post_id );
	}

	/**
	 * Schedule deletion of AT Protocol record.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public static function schedule_delete( $post_id ) {
		$tid = get_post_meta( $post_id, Record::META_TID, true );

		/**
		 * Fires when a post is deleted from AT Protocol.
		 *
		 * @param int    $post_id The post ID.
		 * @param string $tid     The AT Protocol TID.
		 */
		do_action( 'atproto_post_deleted', $post_id, $tid );

		// Clean up meta.
		delete_post_meta( $post_id, Record::META_TID );
		delete_post_meta( $post_id, Record::META_CID );
		delete_post_meta( $post_id, Record::META_URI );
		delete_post_meta( $post_id, Record::META_COLLECTION );
	}

	/**
	 * Process federation for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public static function process_federation( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || ! self::should_federate( $post ) ) {
			return;
		}

		// Convert post to record.
		$record = Record::post_to_record( $post, 'app.bsky.feed.post' );

		/**
		 * Fires after a post is processed for federation.
		 *
		 * @param int   $post_id The post ID.
		 * @param array $record  The AT Protocol record.
		 */
		do_action( 'atproto_post_federated', $post_id, $record );
	}
}
