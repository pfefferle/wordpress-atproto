<?php
/**
 * Handler for incoming AT Protocol likes.
 *
 * @package ATProto
 */

namespace ATProto\Handler;

defined( 'ABSPATH' ) || exit;

/**
 * Like handler class.
 */
class Like extends Handler {
	/**
	 * Post meta key for storing like count.
	 *
	 * @var string
	 */
	const META_LIKE_COUNT = '_atproto_like_count';

	/**
	 * Post meta key for storing likes list.
	 *
	 * @var string
	 */
	const META_LIKES = '_atproto_likes';

	/**
	 * Handle the incoming like.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function handle() {
		// Get the subject (what was liked).
		$subject = $this->record['subject'] ?? array();
		$uri     = $subject['uri'] ?? '';

		if ( empty( $uri ) ) {
			return false;
		}

		// Find the local post.
		$post_id = $this->find_local_post( $uri );

		if ( ! $post_id ) {
			// Maybe it's a comment.
			$comment_id = $this->find_local_comment( $uri );
			if ( $comment_id ) {
				return $this->handle_comment_like( $comment_id );
			}
			return false;
		}

		return $this->handle_post_like( $post_id );
	}

	/**
	 * Handle a like on a post.
	 *
	 * @param int $post_id The post ID.
	 * @return bool True on success.
	 */
	private function handle_post_like( $post_id ) {
		// Get existing likes.
		$likes = get_post_meta( $post_id, self::META_LIKES, true );
		if ( ! is_array( $likes ) ) {
			$likes = array();
		}

		// Check if already liked by this DID.
		if ( isset( $likes[ $this->did ] ) ) {
			return true; // Already liked.
		}

		// Add the like.
		$likes[ $this->did ] = array(
			'handle'     => $this->handle,
			'created_at' => $this->record['createdAt'] ?? current_time( 'mysql', true ),
			'uri'        => $this->record['uri'] ?? '',
		);

		update_post_meta( $post_id, self::META_LIKES, $likes );

		// Update count.
		$count = count( $likes );
		update_post_meta( $post_id, self::META_LIKE_COUNT, $count );

		/**
		 * Fires when a post receives a like from AT Protocol.
		 *
		 * @param int    $post_id The post ID.
		 * @param string $did     The liker's DID.
		 * @param string $handle  The liker's handle.
		 * @param array  $record  The like record.
		 */
		do_action( 'atproto_post_liked', $post_id, $this->did, $this->handle, $this->record );

		return true;
	}

	/**
	 * Handle a like on a comment.
	 *
	 * @param int $comment_id The comment ID.
	 * @return bool True on success.
	 */
	private function handle_comment_like( $comment_id ) {
		// Get existing likes.
		$likes = get_comment_meta( $comment_id, self::META_LIKES, true );
		if ( ! is_array( $likes ) ) {
			$likes = array();
		}

		// Check if already liked.
		if ( isset( $likes[ $this->did ] ) ) {
			return true;
		}

		// Add the like.
		$likes[ $this->did ] = array(
			'handle'     => $this->handle,
			'created_at' => $this->record['createdAt'] ?? current_time( 'mysql', true ),
		);

		update_comment_meta( $comment_id, self::META_LIKES, $likes );

		// Update count.
		$count = count( $likes );
		update_comment_meta( $comment_id, self::META_LIKE_COUNT, $count );

		/**
		 * Fires when a comment receives a like from AT Protocol.
		 *
		 * @param int    $comment_id The comment ID.
		 * @param string $did        The liker's DID.
		 * @param string $handle     The liker's handle.
		 */
		do_action( 'atproto_comment_liked', $comment_id, $this->did, $this->handle );

		return true;
	}

	/**
	 * Remove a like (for undo operations).
	 *
	 * @param string $uri The AT-URI of the liked content.
	 * @return bool True on success.
	 */
	public function remove( $uri ) {
		$post_id = $this->find_local_post( $uri );

		if ( $post_id ) {
			$likes = get_post_meta( $post_id, self::META_LIKES, true );
			if ( is_array( $likes ) && isset( $likes[ $this->did ] ) ) {
				unset( $likes[ $this->did ] );
				update_post_meta( $post_id, self::META_LIKES, $likes );
				update_post_meta( $post_id, self::META_LIKE_COUNT, count( $likes ) );
				return true;
			}
		}

		$comment_id = $this->find_local_comment( $uri );

		if ( $comment_id ) {
			$likes = get_comment_meta( $comment_id, self::META_LIKES, true );
			if ( is_array( $likes ) && isset( $likes[ $this->did ] ) ) {
				unset( $likes[ $this->did ] );
				update_comment_meta( $comment_id, self::META_LIKES, $likes );
				update_comment_meta( $comment_id, self::META_LIKE_COUNT, count( $likes ) );
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the like count for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return int The like count.
	 */
	public static function get_post_like_count( $post_id ) {
		return (int) get_post_meta( $post_id, self::META_LIKE_COUNT, true );
	}

	/**
	 * Get the likes for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return array Array of likes.
	 */
	public static function get_post_likes( $post_id ) {
		$likes = get_post_meta( $post_id, self::META_LIKES, true );
		return is_array( $likes ) ? $likes : array();
	}
}
