<?php
/**
 * Comment Transformer - converts WordPress comments to AT Protocol reply records.
 *
 * @package ATProto
 */

namespace ATProto\Transformer;

use ATProto\ATProto;
use ATProto\Repository\TID;
use ATProto\Repository\Record;
use ATProto\Scheduler\Comment as CommentScheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Comment transformer class.
 */
class Comment extends Base {
	/**
	 * The WordPress comment.
	 *
	 * @var \WP_Comment
	 */
	protected $object;

	/**
	 * Transform the comment to an AT Protocol reply record.
	 *
	 * @return array The app.bsky.feed.post record (reply).
	 */
	public function transform() {
		$text = $this->build_text();

		$record = array(
			'$type'     => 'app.bsky.feed.post',
			'text'      => $text,
			'createdAt' => $this->to_iso8601( $this->object->comment_date_gmt ),
			'langs'     => $this->get_langs(),
			'reply'     => $this->build_reply_ref(),
		);

		// Add facets.
		$facets = Facet::extract( $text );
		if ( ! empty( $facets ) ) {
			$record['facets'] = $facets;
		}

		/**
		 * Filter the transformed comment record.
		 *
		 * @param array       $record  The record.
		 * @param \WP_Comment $comment The WordPress comment.
		 */
		return apply_filters( 'atproto_transform_comment', $record, $this->object );
	}

	/**
	 * Get the collection NSID.
	 *
	 * @return string
	 */
	public function get_collection() {
		return 'app.bsky.feed.post';
	}

	/**
	 * Get the record key.
	 *
	 * @return string
	 */
	public function get_rkey() {
		$rkey = get_comment_meta( $this->object->comment_ID, CommentScheduler::META_TID, true );

		if ( empty( $rkey ) ) {
			$rkey = TID::generate();
			update_comment_meta( $this->object->comment_ID, CommentScheduler::META_TID, $rkey );
		}

		return $rkey;
	}

	/**
	 * Build the comment text.
	 *
	 * @return string The text content.
	 */
	protected function build_text() {
		$text = wp_strip_all_tags( $this->object->comment_content );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = trim( $text );

		// Truncate if needed.
		if ( mb_strlen( $text ) > 300 ) {
			$text = $this->truncate_text( $text, 300 );
		}

		return $text;
	}

	/**
	 * Build the reply reference.
	 *
	 * @return array The reply reference object.
	 */
	protected function build_reply_ref() {
		// Get the root post.
		$post_id  = $this->object->comment_post_ID;
		$post_tid = get_post_meta( $post_id, Record::META_TID, true );
		$post_cid = get_post_meta( $post_id, Record::META_CID, true );
		$post_uri = get_post_meta( $post_id, Record::META_URI, true );

		if ( empty( $post_uri ) ) {
			$post_uri = 'at://' . ATProto::get_did() . '/app.bsky.feed.post/' . $post_tid;
		}

		$root = array(
			'uri' => $post_uri,
			'cid' => $post_cid ?: '',
		);

		$parent = $root;

		// If this is a reply to another comment, update parent.
		if ( $this->object->comment_parent ) {
			$parent_tid = get_comment_meta( $this->object->comment_parent, CommentScheduler::META_TID, true );
			$parent_cid = get_comment_meta( $this->object->comment_parent, '_atproto_cid', true );
			$parent_uri = get_comment_meta( $this->object->comment_parent, CommentScheduler::META_URI, true );

			if ( $parent_tid ) {
				if ( empty( $parent_uri ) ) {
					$parent_uri = 'at://' . ATProto::get_did() . '/app.bsky.feed.post/' . $parent_tid;
				}

				$parent = array(
					'uri' => $parent_uri,
					'cid' => $parent_cid ?: '',
				);
			}
		}

		return array(
			'root'   => $root,
			'parent' => $parent,
		);
	}

	/**
	 * Create a Comment transformer from a comment ID.
	 *
	 * @param int $comment_id The comment ID.
	 * @return self|null The transformer or null.
	 */
	public static function from_id( $comment_id ) {
		$comment = get_comment( $comment_id );

		if ( ! $comment ) {
			return null;
		}

		return new self( $comment );
	}
}
