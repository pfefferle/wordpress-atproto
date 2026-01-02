<?php
/**
 * Handler for incoming AT Protocol replies.
 *
 * @package ATProto
 */

namespace ATProto\Handler;

use ATProto\Scheduler\Comment;

defined( 'ABSPATH' ) || exit;

/**
 * Reply handler class.
 */
class Reply extends Handler {
	/**
	 * Handle the incoming reply.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function handle() {
		// Get the reply reference.
		$reply = $this->record['reply'] ?? array();
		$root  = $reply['root'] ?? array();
		$parent = $reply['parent'] ?? array();

		$root_uri   = $root['uri'] ?? '';
		$parent_uri = $parent['uri'] ?? '';

		if ( empty( $root_uri ) ) {
			return false;
		}

		// Find the root post (the original WordPress post).
		$post_id = $this->find_local_post( $root_uri );

		if ( ! $post_id ) {
			// Root is not a local post.
			return false;
		}

		// Find the parent comment if different from root.
		$parent_comment_id = 0;
		if ( $parent_uri && $parent_uri !== $root_uri ) {
			$parent_comment_id = $this->find_local_comment( $parent_uri );
			// If parent not found, it might be a remote comment or direct reply to post.
		}

		// Store as WordPress comment.
		$comment_id = Comment::store_federated_reply(
			array(
				'text'      => $this->record['text'] ?? '',
				'createdAt' => $this->record['createdAt'] ?? '',
				'uri'       => $this->record['uri'] ?? '',
			),
			$this->did,
			$this->handle,
			$post_id,
			$parent_comment_id
		);

		if ( ! $comment_id ) {
			return false;
		}

		/**
		 * Fires when a reply is received from AT Protocol.
		 *
		 * @param int    $comment_id The created comment ID.
		 * @param int    $post_id    The post ID.
		 * @param string $did        The author's DID.
		 * @param array  $record     The reply record.
		 */
		do_action( 'atproto_reply_received', $comment_id, $post_id, $this->did, $this->record );

		return true;
	}

	/**
	 * Handle deletion of a reply.
	 *
	 * @param string $uri The AT-URI of the reply to delete.
	 * @return bool True on success.
	 */
	public function delete( $uri ) {
		global $wpdb;

		// Find the comment by AT-URI.
		$comment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = %s AND meta_value = %s",
				Comment::META_URI,
				$uri
			)
		);

		if ( ! $comment_id ) {
			return false;
		}

		// Verify the DID matches.
		$stored_did = get_comment_meta( $comment_id, Comment::META_REMOTE_DID, true );
		if ( $stored_did !== $this->did ) {
			return false; // Not authorized.
		}

		// Delete the comment.
		$result = wp_delete_comment( $comment_id, true );

		if ( $result ) {
			/**
			 * Fires when a federated reply is deleted.
			 *
			 * @param int    $comment_id The comment ID.
			 * @param string $did        The author's DID.
			 */
			do_action( 'atproto_reply_deleted', $comment_id, $this->did );
		}

		return (bool) $result;
	}
}
