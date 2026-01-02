<?php
/**
 * Post Transformer - converts WordPress posts to AT Protocol records.
 *
 * @package ATProto
 */

namespace ATProto\Transformer;

use ATProto\Repository\TID;
use ATProto\Repository\Record;

defined( 'ABSPATH' ) || exit;

/**
 * Post transformer class.
 */
class Post extends Base {
	/**
	 * The WordPress post.
	 *
	 * @var \WP_Post
	 */
	protected $object;

	/**
	 * Transform the post to an AT Protocol record.
	 *
	 * @return array The app.bsky.feed.post record.
	 */
	public function transform() {
		$text = $this->build_text();

		$record = array(
			'$type'     => 'app.bsky.feed.post',
			'text'      => $text,
			'createdAt' => $this->to_iso8601( $this->object->post_date_gmt ),
			'langs'     => $this->get_langs(),
		);

		// Add facets.
		$facets = Facet::extract( $text );
		if ( ! empty( $facets ) ) {
			$record['facets'] = $facets;
		}

		// Add embed.
		$embed = $this->build_embed();
		if ( $embed ) {
			$record['embed'] = $embed;
		}

		// Add tags from post categories/tags.
		$tags = $this->get_tags();
		if ( ! empty( $tags ) ) {
			$record['tags'] = $tags;
		}

		/**
		 * Filter the transformed post record.
		 *
		 * @param array    $record The record.
		 * @param \WP_Post $post   The WordPress post.
		 */
		return apply_filters( 'atproto_transform_post', $record, $this->object );
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
		$rkey = get_post_meta( $this->object->ID, Record::META_TID, true );

		if ( empty( $rkey ) ) {
			$rkey = TID::generate();
			update_post_meta( $this->object->ID, Record::META_TID, $rkey );
		}

		return $rkey;
	}

	/**
	 * Build the post text.
	 *
	 * @return string The text content.
	 */
	protected function build_text() {
		$parts = array();

		// Title.
		if ( ! empty( $this->object->post_title ) ) {
			$parts[] = $this->object->post_title;
		}

		// Excerpt or content summary.
		$excerpt = $this->get_excerpt();
		if ( ! empty( $excerpt ) ) {
			$parts[] = $excerpt;
		}

		// Permalink.
		$permalink = get_permalink( $this->object );
		$parts[]   = $permalink;

		$text = implode( "\n\n", $parts );

		// Ensure we fit within 300 graphemes.
		if ( mb_strlen( $text ) > 300 ) {
			$url_len     = strlen( $permalink );
			$available   = 300 - $url_len - 4; // 4 for newlines.
			$title_excerpt = $this->object->post_title;

			if ( ! empty( $excerpt ) ) {
				$title_excerpt .= "\n\n" . $excerpt;
			}

			$title_excerpt = $this->truncate_text( $title_excerpt, $available );
			$text = $title_excerpt . "\n\n" . $permalink;
		}

		return $text;
	}

	/**
	 * Get the post excerpt.
	 *
	 * @return string The excerpt.
	 */
	protected function get_excerpt() {
		if ( ! empty( $this->object->post_excerpt ) ) {
			return wp_strip_all_tags( $this->object->post_excerpt );
		}

		$content = wp_strip_all_tags( $this->object->post_content );
		return wp_trim_words( $content, 30, '...' );
	}

	/**
	 * Build the embed object.
	 *
	 * @return array|null The embed or null.
	 */
	protected function build_embed() {
		$permalink   = get_permalink( $this->object );
		$title       = get_the_title( $this->object );
		$description = $this->get_excerpt();

		$embed = array(
			'$type'    => 'app.bsky.embed.external',
			'external' => array(
				'uri'         => $permalink,
				'title'       => $title,
				'description' => $description,
			),
		);

		// Add thumbnail if featured image exists.
		$thumb_id = get_post_thumbnail_id( $this->object );
		if ( $thumb_id ) {
			$blob = Attachment::get_blob_ref( $thumb_id );
			if ( $blob ) {
				$embed['external']['thumb'] = $blob;
			}
		}

		return $embed;
	}

	/**
	 * Get tags from post taxonomies.
	 *
	 * @return array Array of tag strings.
	 */
	protected function get_tags() {
		$tags = array();

		// Get post tags.
		$post_tags = get_the_tags( $this->object->ID );
		if ( $post_tags ) {
			foreach ( $post_tags as $tag ) {
				$tags[] = $tag->name;
			}
		}

		// Get categories.
		$categories = get_the_category( $this->object->ID );
		if ( $categories ) {
			foreach ( $categories as $cat ) {
				if ( 'uncategorized' !== $cat->slug ) {
					$tags[] = $cat->name;
				}
			}
		}

		// Limit to 8 tags, remove duplicates.
		$tags = array_unique( $tags );
		$tags = array_slice( $tags, 0, 8 );

		return $tags;
	}

	/**
	 * Create a Post transformer from a post ID.
	 *
	 * @param int $post_id The post ID.
	 * @return self|null The transformer or null.
	 */
	public static function from_id( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return null;
		}

		return new self( $post );
	}
}
