<?php
/**
 * Handler for incoming AT Protocol reposts (reblogs).
 *
 * @package ATProto
 */

namespace ATProto\Handler;

defined( 'ABSPATH' ) || exit;

/**
 * Repost handler class.
 */
class Repost extends Handler {
	/**
	 * Post meta key for storing repost count.
	 *
	 * @var string
	 */
	const META_REPOST_COUNT = '_atproto_repost_count';

	/**
	 * Post meta key for storing reposts list.
	 *
	 * @var string
	 */
	const META_REPOSTS = '_atproto_reposts';

	/**
	 * Handle the incoming repost.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function handle() {
		// Get the subject (what was reposted).
		$subject = $this->record['subject'] ?? array();
		$uri     = $subject['uri'] ?? '';

		if ( empty( $uri ) ) {
			return false;
		}

		// Find the local post.
		$post_id = $this->find_local_post( $uri );

		if ( ! $post_id ) {
			return false;
		}

		// Get existing reposts.
		$reposts = get_post_meta( $post_id, self::META_REPOSTS, true );
		if ( ! is_array( $reposts ) ) {
			$reposts = array();
		}

		// Check if already reposted by this DID.
		if ( isset( $reposts[ $this->did ] ) ) {
			return true;
		}

		// Add the repost.
		$reposts[ $this->did ] = array(
			'handle'     => $this->handle,
			'created_at' => $this->record['createdAt'] ?? current_time( 'mysql', true ),
			'uri'        => $this->record['uri'] ?? '',
		);

		update_post_meta( $post_id, self::META_REPOSTS, $reposts );

		// Update count.
		$count = count( $reposts );
		update_post_meta( $post_id, self::META_REPOST_COUNT, $count );

		/**
		 * Fires when a post is reposted via AT Protocol.
		 *
		 * @param int    $post_id The post ID.
		 * @param string $did     The reposter's DID.
		 * @param string $handle  The reposter's handle.
		 * @param array  $record  The repost record.
		 */
		do_action( 'atproto_post_reposted', $post_id, $this->did, $this->handle, $this->record );

		return true;
	}

	/**
	 * Remove a repost (for undo operations).
	 *
	 * @param string $uri The AT-URI of the reposted content.
	 * @return bool True on success.
	 */
	public function remove( $uri ) {
		$post_id = $this->find_local_post( $uri );

		if ( ! $post_id ) {
			return false;
		}

		$reposts = get_post_meta( $post_id, self::META_REPOSTS, true );
		if ( ! is_array( $reposts ) || ! isset( $reposts[ $this->did ] ) ) {
			return true;
		}

		unset( $reposts[ $this->did ] );
		update_post_meta( $post_id, self::META_REPOSTS, $reposts );
		update_post_meta( $post_id, self::META_REPOST_COUNT, count( $reposts ) );

		/**
		 * Fires when a repost is removed.
		 *
		 * @param int    $post_id The post ID.
		 * @param string $did     The reposter's DID.
		 */
		do_action( 'atproto_repost_removed', $post_id, $this->did );

		return true;
	}

	/**
	 * Get the repost count for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return int The repost count.
	 */
	public static function get_post_repost_count( $post_id ) {
		return (int) get_post_meta( $post_id, self::META_REPOST_COUNT, true );
	}

	/**
	 * Get the reposts for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return array Array of reposts.
	 */
	public static function get_post_reposts( $post_id ) {
		$reposts = get_post_meta( $post_id, self::META_REPOSTS, true );
		return is_array( $reposts ) ? $reposts : array();
	}
}
