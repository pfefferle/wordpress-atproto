<?php
/**
 * Base handler for incoming AT Protocol activities.
 *
 * @package ATProto
 */

namespace ATProto\Handler;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract Handler class.
 */
abstract class Handler {
	/**
	 * The record to handle.
	 *
	 * @var array
	 */
	protected $record;

	/**
	 * The author's DID.
	 *
	 * @var string
	 */
	protected $did;

	/**
	 * The author's handle.
	 *
	 * @var string
	 */
	protected $handle;

	/**
	 * Constructor.
	 *
	 * @param array  $record The AT Protocol record.
	 * @param string $did    The author's DID.
	 * @param string $handle The author's handle.
	 */
	public function __construct( $record, $did, $handle = '' ) {
		$this->record = $record;
		$this->did    = $did;
		$this->handle = $handle;
	}

	/**
	 * Handle the incoming record.
	 *
	 * @return bool True on success, false on failure.
	 */
	abstract public function handle();

	/**
	 * Get the handler for a record type.
	 *
	 * @param array  $record The AT Protocol record.
	 * @param string $did    The author's DID.
	 * @param string $handle The author's handle.
	 * @return Handler|null The appropriate handler or null.
	 */
	public static function get_handler( $record, $did, $handle = '' ) {
		$type = $record['$type'] ?? '';

		switch ( $type ) {
			case 'app.bsky.feed.like':
				return new Like( $record, $did, $handle );

			case 'app.bsky.feed.repost':
				return new Repost( $record, $did, $handle );

			case 'app.bsky.graph.follow':
				return new Follow( $record, $did, $handle );

			case 'app.bsky.feed.post':
				// Check if it's a reply.
				if ( isset( $record['reply'] ) ) {
					return new Reply( $record, $did, $handle );
				}
				return null; // Regular posts not handled.

			default:
				return null;
		}
	}

	/**
	 * Dispatch a record to the appropriate handler.
	 *
	 * @param array  $record The AT Protocol record.
	 * @param string $did    The author's DID.
	 * @param string $handle The author's handle.
	 * @return bool True on success, false on failure or no handler.
	 */
	public static function dispatch( $record, $did, $handle = '' ) {
		$handler = self::get_handler( $record, $did, $handle );

		if ( ! $handler ) {
			return false;
		}

		return $handler->handle();
	}

	/**
	 * Parse an AT-URI and find the local WordPress post.
	 *
	 * @param string $uri The AT-URI.
	 * @return int|null The WordPress post ID or null.
	 */
	protected function find_local_post( $uri ) {
		global $wpdb;

		// Parse the AT-URI.
		$parsed = \ATProto\parse_at_uri( $uri );
		if ( ! $parsed ) {
			return null;
		}

		// Check if the DID matches our site.
		if ( $parsed['did'] !== \ATProto\ATProto::get_did() ) {
			return null;
		}

		// Look up the post by TID.
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				\ATProto\Repository\Record::META_TID,
				$parsed['rkey']
			)
		);

		return $post_id ? (int) $post_id : null;
	}

	/**
	 * Parse an AT-URI and find the local WordPress comment.
	 *
	 * @param string $uri The AT-URI.
	 * @return int|null The WordPress comment ID or null.
	 */
	protected function find_local_comment( $uri ) {
		global $wpdb;

		// Parse the AT-URI.
		$parsed = \ATProto\parse_at_uri( $uri );
		if ( ! $parsed ) {
			return null;
		}

		// Check if the DID matches our site.
		if ( $parsed['did'] !== \ATProto\ATProto::get_did() ) {
			return null;
		}

		// Look up the comment by TID.
		$comment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = %s AND meta_value = %s",
				\ATProto\Scheduler\Comment::META_TID,
				$parsed['rkey']
			)
		);

		return $comment_id ? (int) $comment_id : null;
	}
}
