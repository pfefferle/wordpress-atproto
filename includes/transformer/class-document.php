<?php
/**
 * Document Transformer - converts WordPress posts to
 * site.standard.document AT Protocol records.
 *
 * @package ATProto
 */

namespace ATProto\Transformer;

use ATProto\ATProto;
use ATProto\Repository\Record;
use ATProto\Repository\TID;

defined( 'ABSPATH' ) || exit;

/**
 * Document transformer class.
 */
class Document extends Base {
	/**
	 * Post meta key for storing the document TID.
	 *
	 * @var string
	 */
	const META_DOCUMENT_TID = '_atproto_document_tid';

	/**
	 * Post meta key for storing the document URI.
	 *
	 * @var string
	 */
	const META_DOCUMENT_URI = '_atproto_document_uri';

	/**
	 * Post meta key for storing the document CID.
	 *
	 * @var string
	 */
	const META_DOCUMENT_CID = '_atproto_document_cid';

	/**
	 * The WordPress post.
	 *
	 * @var \WP_Post
	 */
	protected $object;

	/**
	 * Transform the post to a site.standard.document record.
	 *
	 * @return array The site.standard.document record.
	 */
	public function transform() {
		$record = array(
			'$type'       => 'site.standard.document',
			'title'       => get_the_title( $this->object ),
			'publishedAt' => $this->to_iso8601( $this->object->post_date_gmt ),
		);

		// Add publication reference (site AT-URI).
		$publication_tid = get_option( Publication::OPTION_TID );
		if ( $publication_tid ) {
			$record['site'] = sprintf(
				'at://%s/site.standard.publication/%s',
				ATProto::get_did(),
				$publication_tid
			);
		}

		// Add relative permalink as path.
		$permalink     = get_permalink( $this->object );
		$home_url      = home_url();
		$relative_path = str_replace( $home_url, '', $permalink );
		if ( $relative_path ) {
			$record['path'] = $relative_path;
		}

		// Add description from excerpt.
		$excerpt = $this->get_excerpt();
		if ( ! empty( $excerpt ) ) {
			$record['description'] = $excerpt;
		}

		// Add cover image blob reference.
		$thumb_id = get_post_thumbnail_id( $this->object );
		if ( $thumb_id ) {
			$blob = Attachment::get_blob_ref( $thumb_id );
			if ( $blob ) {
				$record['coverImage'] = $blob;
			}
		}

		// Add plain text content.
		$text_content = $this->get_text_content();
		if ( ! empty( $text_content ) ) {
			$record['textContent'] = $text_content;
		}

		// Add bsky post strong reference if available.
		$bsky_uri = get_post_meta( $this->object->ID, Record::META_URI, true );
		$bsky_cid = get_post_meta( $this->object->ID, Record::META_CID, true );
		if ( $bsky_uri && $bsky_cid ) {
			$record['bskyPostRef'] = array(
				'uri' => $bsky_uri,
				'cid' => $bsky_cid,
			);
		}

		// Add tags.
		$tags = $this->get_tags();
		if ( ! empty( $tags ) ) {
			$record['tags'] = $tags;
		}

		// Add updatedAt if the post was modified after publish.
		if ( $this->object->post_modified_gmt !== $this->object->post_date_gmt ) {
			$record['updatedAt'] = $this->to_iso8601( $this->object->post_modified_gmt );
		}

		/**
		 * Filter the transformed document record.
		 *
		 * @param array    $record The record.
		 * @param \WP_Post $post   The WordPress post.
		 */
		return apply_filters( 'atproto_transform_document', $record, $this->object );
	}

	/**
	 * Get the collection NSID.
	 *
	 * @return string
	 */
	public function get_collection() {
		return 'site.standard.document';
	}

	/**
	 * Get the record key.
	 *
	 * @return string
	 */
	public function get_rkey() {
		$rkey = get_post_meta( $this->object->ID, self::META_DOCUMENT_TID, true );

		if ( empty( $rkey ) ) {
			$rkey = TID::generate();
			update_post_meta( $this->object->ID, self::META_DOCUMENT_TID, $rkey );
		}

		return $rkey;
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
		return wp_trim_words( $content, 55, '...' );
	}

	/**
	 * Get the plain text content of the post.
	 *
	 * @return string The text content.
	 */
	protected function get_text_content() {
		$content = apply_filters( 'the_content', $this->object->post_content );
		$content = wp_strip_all_tags( $content );
		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( '/\s+/', ' ', $content ) );
	}

	/**
	 * Get tags from post taxonomies.
	 *
	 * @return array Array of tag strings.
	 */
	protected function get_tags() {
		$tags = array();

		$post_tags = get_the_tags( $this->object->ID );
		if ( $post_tags ) {
			foreach ( $post_tags as $tag ) {
				$tags[] = $tag->name;
			}
		}

		$categories = get_the_category( $this->object->ID );
		if ( $categories ) {
			foreach ( $categories as $cat ) {
				if ( 'uncategorized' !== $cat->slug ) {
					$tags[] = $cat->name;
				}
			}
		}

		$tags = array_unique( $tags );
		$tags = array_slice( $tags, 0, 8 );

		return $tags;
	}

	/**
	 * Create a Document transformer from a post ID.
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
