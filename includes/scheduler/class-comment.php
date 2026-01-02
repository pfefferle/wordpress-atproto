<?php
/**
 * Comment federation scheduler.
 *
 * Handles WordPress comments as AT Protocol reply records.
 *
 * @package ATProto
 */

namespace ATProto\Scheduler;

use ATProto\ATProto;
use ATProto\Repository\Record;
use ATProto\Repository\TID;

defined( 'ABSPATH' ) || exit;

/**
 * Comment scheduler class.
 */
class Comment {
	/**
	 * Comment meta key for AT Protocol TID.
	 *
	 * @var string
	 */
	const META_TID = '_atproto_tid';

	/**
	 * Comment meta key for AT Protocol URI.
	 *
	 * @var string
	 */
	const META_URI = '_atproto_uri';

	/**
	 * Comment meta key for source (local or federated).
	 *
	 * @var string
	 */
	const META_SOURCE = '_atproto_source';

	/**
	 * Comment meta key for remote DID.
	 *
	 * @var string
	 */
	const META_REMOTE_DID = '_atproto_remote_did';

	/**
	 * Initialize the scheduler.
	 *
	 * @return void
	 */
	public static function init() {
		// Hook into comment status transitions.
		add_action( 'transition_comment_status', array( self::class, 'handle_status_change' ), 10, 3 );

		// Hook into new approved comments.
		add_action( 'comment_post', array( self::class, 'handle_new_comment' ), 10, 3 );

		// Hook into comment updates.
		add_action( 'edit_comment', array( self::class, 'handle_update' ), 10, 2 );
	}

	/**
	 * Handle comment status transitions.
	 *
	 * @param string      $new_status New comment status.
	 * @param string      $old_status Old comment status.
	 * @param \WP_Comment $comment    The comment object.
	 * @return void
	 */
	public static function handle_status_change( $new_status, $old_status, $comment ) {
		// Check if parent post is federated.
		if ( ! self::should_federate( $comment ) ) {
			return;
		}

		// Approving a comment.
		if ( 'approved' === $new_status && 'approved' !== $old_status ) {
			self::schedule_create( $comment->comment_ID );
			return;
		}

		// Unapproving a comment.
		if ( 'approved' !== $new_status && 'approved' === $old_status ) {
			self::schedule_delete( $comment->comment_ID );
			return;
		}
	}

	/**
	 * Handle new comments.
	 *
	 * @param int        $comment_id       The comment ID.
	 * @param int|string $comment_approved Approval status.
	 * @param array      $comment_data     Comment data.
	 * @return void
	 */
	public static function handle_new_comment( $comment_id, $comment_approved, $comment_data ) {
		// Only process approved comments.
		if ( 1 !== $comment_approved && 'approve' !== $comment_approved ) {
			return;
		}

		$comment = get_comment( $comment_id );
		if ( ! $comment || ! self::should_federate( $comment ) ) {
			return;
		}

		self::schedule_create( $comment_id );
	}

	/**
	 * Handle comment updates.
	 *
	 * @param int   $comment_id   The comment ID.
	 * @param array $comment_data The comment data.
	 * @return void
	 */
	public static function handle_update( $comment_id, $comment_data ) {
		$comment = get_comment( $comment_id );

		if ( ! $comment || 'approved' !== wp_get_comment_status( $comment ) ) {
			return;
		}

		if ( ! self::should_federate( $comment ) ) {
			return;
		}

		self::schedule_update( $comment_id );
	}

	/**
	 * Check if a comment should be federated.
	 *
	 * @param \WP_Comment $comment The comment to check.
	 * @return bool True if should be federated.
	 */
	public static function should_federate( $comment ) {
		// Check if parent post is federated.
		$post = get_post( $comment->comment_post_ID );
		if ( ! $post ) {
			return false;
		}

		// Use post scheduler's check.
		if ( ! Post::should_federate( $post ) ) {
			return false;
		}

		// Skip trackbacks/pingbacks.
		if ( in_array( $comment->comment_type, array( 'trackback', 'pingback' ), true ) ) {
			return false;
		}

		// Skip comments from federated sources (they're already in the network).
		$source = get_comment_meta( $comment->comment_ID, self::META_SOURCE, true );
		if ( 'federated' === $source ) {
			return false;
		}

		/**
		 * Filter whether a comment should be federated.
		 *
		 * @param bool        $should_federate Whether to federate.
		 * @param \WP_Comment $comment         The comment object.
		 */
		return apply_filters( 'atproto_should_federate_comment', true, $comment );
	}

	/**
	 * Schedule creation of AT Protocol reply record.
	 *
	 * @param int $comment_id The comment ID.
	 * @return void
	 */
	public static function schedule_create( $comment_id ) {
		// Generate TID if not exists.
		$tid = get_comment_meta( $comment_id, self::META_TID, true );
		if ( empty( $tid ) ) {
			$tid = TID::generate();
			update_comment_meta( $comment_id, self::META_TID, $tid );
		}

		// Mark as local source.
		update_comment_meta( $comment_id, self::META_SOURCE, 'local' );

		// Generate AT URI.
		$uri = 'at://' . ATProto::get_did() . '/app.bsky.feed.post/' . $tid;
		update_comment_meta( $comment_id, self::META_URI, $uri );

		/**
		 * Fires when a comment is created for AT Protocol.
		 *
		 * @param int    $comment_id The comment ID.
		 * @param string $tid        The AT Protocol TID.
		 * @param string $uri        The AT Protocol URI.
		 */
		do_action( 'atproto_comment_created', $comment_id, $tid, $uri );
	}

	/**
	 * Schedule update of AT Protocol reply record.
	 *
	 * @param int $comment_id The comment ID.
	 * @return void
	 */
	public static function schedule_update( $comment_id ) {
		/**
		 * Fires when a comment is updated for AT Protocol.
		 *
		 * @param int $comment_id The comment ID.
		 */
		do_action( 'atproto_comment_updated', $comment_id );
	}

	/**
	 * Schedule deletion of AT Protocol reply record.
	 *
	 * @param int $comment_id The comment ID.
	 * @return void
	 */
	public static function schedule_delete( $comment_id ) {
		$tid = get_comment_meta( $comment_id, self::META_TID, true );

		/**
		 * Fires when a comment is deleted from AT Protocol.
		 *
		 * @param int    $comment_id The comment ID.
		 * @param string $tid        The AT Protocol TID.
		 */
		do_action( 'atproto_comment_deleted', $comment_id, $tid );

		// Clean up meta.
		delete_comment_meta( $comment_id, self::META_TID );
		delete_comment_meta( $comment_id, self::META_URI );
		delete_comment_meta( $comment_id, self::META_SOURCE );
	}

	/**
	 * Convert a comment to an AT Protocol reply record.
	 *
	 * @param \WP_Comment $comment The WordPress comment.
	 * @return array The AT Protocol record.
	 */
	public static function comment_to_record( $comment ) {
		$tid = get_comment_meta( $comment->comment_ID, self::META_TID, true );

		// Get parent post's AT URI.
		$post_tid = get_post_meta( $comment->comment_post_ID, Record::META_TID, true );
		$post_uri = 'at://' . ATProto::get_did() . '/app.bsky.feed.post/' . $post_tid;

		// Build reply reference.
		$reply = array(
			'root'   => array(
				'uri' => $post_uri,
				'cid' => '', // Would need actual CID.
			),
			'parent' => array(
				'uri' => $post_uri,
				'cid' => '', // Would need actual CID.
			),
		);

		// If this is a reply to another comment, update parent.
		if ( $comment->comment_parent ) {
			$parent_tid = get_comment_meta( $comment->comment_parent, self::META_TID, true );
			if ( $parent_tid ) {
				$parent_uri           = 'at://' . ATProto::get_did() . '/app.bsky.feed.post/' . $parent_tid;
				$reply['parent']['uri'] = $parent_uri;
			}
		}

		// Build the record.
		$text = wp_strip_all_tags( $comment->comment_content );

		// Truncate if needed.
		if ( mb_strlen( $text ) > 300 ) {
			$text = mb_substr( $text, 0, 297 ) . '...';
		}

		$value = array(
			'$type'     => 'app.bsky.feed.post',
			'text'      => $text,
			'reply'     => $reply,
			'createdAt' => gmdate( 'c', strtotime( $comment->comment_date_gmt ) ),
		);

		// Add langs.
		$locale         = get_locale();
		$lang           = substr( $locale, 0, 2 );
		$value['langs'] = array( $lang );

		return array(
			'rkey'  => $tid,
			'cid'   => '',
			'value' => $value,
		);
	}

	/**
	 * Store a federated reply as a WordPress comment.
	 *
	 * @param array  $record     The AT Protocol record.
	 * @param string $did        The author's DID.
	 * @param string $handle     The author's handle.
	 * @param int    $post_id    The WordPress post ID.
	 * @param int    $parent_id  Optional parent comment ID.
	 * @return int|false The comment ID or false on failure.
	 */
	public static function store_federated_reply( $record, $did, $handle, $post_id, $parent_id = 0 ) {
		$comment_data = array(
			'comment_post_ID'      => $post_id,
			'comment_author'       => $handle,
			'comment_author_email' => '',
			'comment_author_url'   => 'https://' . $handle,
			'comment_content'      => $record['text'] ?? '',
			'comment_type'         => 'comment',
			'comment_parent'       => $parent_id,
			'comment_approved'     => 1, // Auto-approve federated comments.
			'comment_date_gmt'     => isset( $record['createdAt'] )
				? gmdate( 'Y-m-d H:i:s', strtotime( $record['createdAt'] ) )
				: current_time( 'mysql', true ),
		);

		$comment_id = wp_insert_comment( $comment_data );

		if ( ! $comment_id ) {
			return false;
		}

		// Store AT Protocol metadata.
		update_comment_meta( $comment_id, self::META_SOURCE, 'federated' );
		update_comment_meta( $comment_id, self::META_REMOTE_DID, $did );

		if ( isset( $record['uri'] ) ) {
			update_comment_meta( $comment_id, self::META_URI, $record['uri'] );
		}

		/**
		 * Fires after storing a federated reply.
		 *
		 * @param int    $comment_id The comment ID.
		 * @param array  $record     The AT Protocol record.
		 * @param string $did        The author's DID.
		 */
		do_action( 'atproto_federated_reply_stored', $comment_id, $record, $did );

		return $comment_id;
	}
}
