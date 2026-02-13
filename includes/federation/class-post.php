<?php
/**
 * Post federation hooks.
 *
 * Handles federation of posts to AT Protocol on publish, update, and delete.
 * Runs directly on WordPress hooks - no WP-Cron scheduling needed.
 *
 * @package ATProto
 */

namespace ATProto\Federation;

use ATProto\ATProto;
use ATProto\Repository\Record;
use ATProto\Repository\Repository;
use ATProto\Repository\TID;
use ATProto\Transformer\Document;

defined( 'ABSPATH' ) || exit;

/**
 * Post federation class.
 */
class Post {
	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'transition_post_status', array( self::class, 'handle_status_change' ), 10, 3 );
		add_action( 'post_updated', array( self::class, 'handle_update' ), 10, 3 );
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
		if ( ! self::should_federate( $post ) ) {
			return;
		}

		// Publishing a post.
		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			self::federate_create( $post );
			return;
		}

		// Unpublishing a post.
		if ( 'publish' !== $new_status && 'publish' === $old_status ) {
			self::federate_delete( $post->ID );
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
		if ( 'publish' !== $post_after->post_status ) {
			return;
		}

		if ( ! self::should_federate( $post_after ) ) {
			return;
		}

		if ( $post_after->post_content === $post_before->post_content &&
			$post_after->post_title === $post_before->post_title ) {
			return;
		}

		self::federate_update( $post_after );
	}

	/**
	 * Check if a post should be federated.
	 *
	 * @param \WP_Post $post The post to check.
	 * @return bool True if should be federated.
	 */
	public static function should_federate( $post ) {
		$enabled_types = get_option( 'atproto_enabled_post_types', array( 'post' ) );

		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			return false;
		}

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
	 * Federate a newly published post.
	 *
	 * Generates TIDs, syncs to the repository, and notifies the network.
	 *
	 * @param \WP_Post $post The post object.
	 * @return void
	 */
	private static function federate_create( $post ) {
		// Generate TID if not exists.
		$tid = get_post_meta( $post->ID, Record::META_TID, true );
		if ( empty( $tid ) ) {
			$tid = TID::generate();
			update_post_meta( $post->ID, Record::META_TID, $tid );
		}

		// Generate document TID.
		$doc_tid = get_post_meta( $post->ID, Document::META_DOCUMENT_TID, true );
		if ( empty( $doc_tid ) ) {
			$doc_tid = TID::generate();
			update_post_meta( $post->ID, Document::META_DOCUMENT_TID, $doc_tid );
		}

		// Sync the post (computes CIDs, updates meta, notifies network).
		Record::sync_post( $post );

		/**
		 * Fires when a post is federated to AT Protocol.
		 *
		 * @param int    $post_id The post ID.
		 * @param string $tid     The AT Protocol TID.
		 */
		do_action( 'atproto_post_created', $post->ID, $tid );
	}

	/**
	 * Federate a post update.
	 *
	 * Clears cached CIDs and re-syncs.
	 *
	 * @param \WP_Post $post The post object.
	 * @return void
	 */
	private static function federate_update( $post ) {
		// Clear cached CIDs so they get recomputed.
		delete_post_meta( $post->ID, Record::META_CID );
		delete_post_meta( $post->ID, Document::META_DOCUMENT_CID );

		// Re-sync the post.
		Record::sync_post( $post );

		/**
		 * Fires when a post is updated for AT Protocol.
		 *
		 * @param int $post_id The post ID.
		 */
		do_action( 'atproto_post_updated', $post->ID );
	}

	/**
	 * Federate a post deletion (unpublish).
	 *
	 * Notifies the network and cleans up meta.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	private static function federate_delete( $post_id ) {
		$tid     = get_post_meta( $post_id, Record::META_TID, true );
		$doc_tid = get_post_meta( $post_id, Document::META_DOCUMENT_TID, true );

		// Notify the network.
		if ( ! empty( $tid ) ) {
			Repository::notify_change( 'delete', 'app.bsky.feed.post', $tid );
		}
		if ( ! empty( $doc_tid ) ) {
			Repository::notify_change( 'delete', 'site.standard.document', $doc_tid );
		}

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
		delete_post_meta( $post_id, Document::META_DOCUMENT_TID );
		delete_post_meta( $post_id, Document::META_DOCUMENT_URI );
		delete_post_meta( $post_id, Document::META_DOCUMENT_CID );
	}
}
