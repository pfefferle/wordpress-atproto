<?php
/**
 * AT Protocol Record management.
 *
 * @package ATProto
 */

namespace ATProto\Repository;

use ATProto\ATProto;

defined( 'ABSPATH' ) || exit;

/**
 * Record class for managing AT Protocol records.
 */
class Record {
	/**
	 * Post meta key for storing AT Protocol TID.
	 *
	 * @var string
	 */
	const META_TID = '_atproto_tid';

	/**
	 * Post meta key for storing AT Protocol CID.
	 *
	 * @var string
	 */
	const META_CID = '_atproto_cid';

	/**
	 * Post meta key for storing AT Protocol URI.
	 *
	 * @var string
	 */
	const META_URI = '_atproto_uri';

	/**
	 * Post meta key for storing AT Protocol collection.
	 *
	 * @var string
	 */
	const META_COLLECTION = '_atproto_collection';

	/**
	 * Get a record by collection and rkey.
	 *
	 * @param string $collection The collection NSID.
	 * @param string $rkey       The record key (TID).
	 * @return array|null The record or null if not found.
	 */
	public static function get( $collection, $rkey ) {
		// For app.bsky.actor.profile with rkey=self, generate from WordPress settings.
		if ( 'app.bsky.actor.profile' === $collection && 'self' === $rkey ) {
			return self::get_profile_record();
		}

		// For app.bsky.feed.post, look up WordPress posts.
		if ( 'app.bsky.feed.post' === $collection ) {
			return self::get_post_record( $rkey );
		}

		// For other collections, check post meta.
		$args = array(
			'post_type'      => 'any',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'   => self::META_TID,
					'value' => $rkey,
				),
				array(
					'key'   => self::META_COLLECTION,
					'value' => $collection,
				),
			),
		);

		$posts = get_posts( $args );

		if ( empty( $posts ) ) {
			return null;
		}

		return self::post_to_record( $posts[0], $collection );
	}

	/**
	 * Get the profile record from WordPress settings.
	 *
	 * @return array The profile record.
	 */
	private static function get_profile_record() {
		$value = array(
			'$type'       => 'app.bsky.actor.profile',
			'displayName' => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
		);

		// Add avatar from site icon if available.
		$site_icon_id = get_option( 'site_icon' );
		if ( $site_icon_id ) {
			$icon_url = wp_get_attachment_image_url( $site_icon_id, 'full' );
			if ( $icon_url ) {
				// Get image data for blob.
				$icon_path = get_attached_file( $site_icon_id );
				if ( $icon_path && file_exists( $icon_path ) ) {
					$mime_type = get_post_mime_type( $site_icon_id );
					$file_size = filesize( $icon_path );

					// Create blob reference.
					$value['avatar'] = array(
						'$type'    => 'blob',
						'ref'      => array(
							'$link' => CID::from_file( $icon_path ),
						),
						'mimeType' => $mime_type,
						'size'     => $file_size,
					);
				}
			}
		}

		// Compute CID for the profile.
		$cid = CID::from_cbor( $value );

		return array(
			'rkey'  => 'self',
			'cid'   => $cid,
			'value' => $value,
		);
	}

	/**
	 * Get a post record by TID.
	 *
	 * @param string $rkey The record key (TID).
	 * @return array|null The record or null if not found.
	 */
	private static function get_post_record( $rkey ) {
		// Look up by TID in post meta.
		$args = array(
			'post_type'      => get_option( 'atproto_enabled_post_types', array( 'post' ) ),
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'   => self::META_TID,
					'value' => $rkey,
				),
			),
		);

		$posts = get_posts( $args );

		if ( empty( $posts ) ) {
			return null;
		}

		return self::post_to_record( $posts[0], 'app.bsky.feed.post' );
	}

	/**
	 * Convert a WordPress post to an AT Protocol record.
	 *
	 * @param \WP_Post $post       The WordPress post.
	 * @param string   $collection The collection NSID.
	 * @return array The AT Protocol record.
	 */
	public static function post_to_record( $post, $collection = 'app.bsky.feed.post' ) {
		$rkey = get_post_meta( $post->ID, self::META_TID, true );
		$cid  = get_post_meta( $post->ID, self::META_CID, true );

		if ( 'app.bsky.feed.post' !== $collection ) {
			return array(
				'rkey'  => $rkey,
				'cid'   => $cid,
				'value' => array(),
			);
		}

		// Build the post record.
		$text = self::get_post_text( $post );

		$value = array(
			'$type'     => 'app.bsky.feed.post',
			'text'      => $text,
			'createdAt' => gmdate( 'c', strtotime( $post->post_date_gmt ) ),
		);

		// Add facets for links, mentions, hashtags.
		$facets = self::extract_facets( $text );
		if ( ! empty( $facets ) ) {
			$value['facets'] = $facets;
		}

		// Add embed for featured image or links.
		$embed = self::get_post_embed( $post );
		if ( $embed ) {
			$value['embed'] = $embed;
		}

		// Add langs.
		$locale = get_locale();
		$lang   = substr( $locale, 0, 2 );
		$value['langs'] = array( $lang );

		return array(
			'rkey'  => $rkey ?: TID::generate(),
			'cid'   => $cid ?: '',
			'value' => $value,
		);
	}

	/**
	 * Get post text for AT Protocol.
	 *
	 * @param \WP_Post $post The WordPress post.
	 * @return string The post text (max 300 chars for Bluesky).
	 */
	private static function get_post_text( $post ) {
		$text = '';

		// Use title + excerpt or content.
		if ( ! empty( $post->post_title ) ) {
			$text = $post->post_title;
		}

		$excerpt = ! empty( $post->post_excerpt )
			? $post->post_excerpt
			: wp_trim_words( wp_strip_all_tags( $post->post_content ), 40, '...' );

		if ( ! empty( $excerpt ) ) {
			$text .= "\n\n" . $excerpt;
		}

		// Add permalink.
		$permalink = get_permalink( $post );
		$text     .= "\n\n" . $permalink;

		// Bluesky has a 300 grapheme limit, we'll use 300 chars as approximation.
		if ( mb_strlen( $text ) > 300 ) {
			// Ensure we have room for the URL.
			$url_length   = strlen( $permalink );
			$max_text_len = 300 - $url_length - 4; // 4 for newlines.
			$short_text   = mb_substr( $text, 0, $max_text_len - 3 ) . '...';
			$text         = $short_text . "\n\n" . $permalink;
		}

		return $text;
	}

	/**
	 * Extract facets (links, mentions, hashtags) from text.
	 *
	 * @param string $text The text to extract facets from.
	 * @return array Array of facets.
	 */
	private static function extract_facets( $text ) {
		$facets = array();

		// Extract URLs.
		$url_pattern = '#\bhttps?://[^\s<>\[\]]+#i';
		if ( preg_match_all( $url_pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $match ) {
				$url   = $match[0];
				$start = $match[1];
				$end   = $start + strlen( $url );

				// Convert byte offsets to UTF-8 byte positions.
				$byte_start = strlen( mb_convert_encoding( substr( $text, 0, $start ), 'UTF-8' ) );
				$byte_end   = $byte_start + strlen( mb_convert_encoding( $url, 'UTF-8' ) );

				$facets[] = array(
					'index'    => array(
						'byteStart' => $byte_start,
						'byteEnd'   => $byte_end,
					),
					'features' => array(
						array(
							'$type' => 'app.bsky.richtext.facet#link',
							'uri'   => $url,
						),
					),
				);
			}
		}

		// Extract mentions (@handle.domain.tld).
		$mention_pattern = '/@([a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)+)/';
		if ( preg_match_all( $mention_pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $index => $match ) {
				$mention = $match[0];
				$handle  = $matches[1][ $index ][0];
				$start   = $match[1];
				$end     = $start + strlen( $mention );

				$byte_start = strlen( substr( $text, 0, $start ) );
				$byte_end   = $byte_start + strlen( $mention );

				$facets[] = array(
					'index'    => array(
						'byteStart' => $byte_start,
						'byteEnd'   => $byte_end,
					),
					'features' => array(
						array(
							'$type' => 'app.bsky.richtext.facet#mention',
							'did'   => 'did:web:' . $handle, // Simplified - would need actual resolution.
						),
					),
				);
			}
		}

		// Extract hashtags.
		$hashtag_pattern = '/#([a-zA-Z][a-zA-Z0-9_]*)/';
		if ( preg_match_all( $hashtag_pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $index => $match ) {
				$hashtag = $match[0];
				$tag     = $matches[1][ $index ][0];
				$start   = $match[1];
				$end     = $start + strlen( $hashtag );

				$byte_start = strlen( substr( $text, 0, $start ) );
				$byte_end   = $byte_start + strlen( $hashtag );

				$facets[] = array(
					'index'    => array(
						'byteStart' => $byte_start,
						'byteEnd'   => $byte_end,
					),
					'features' => array(
						array(
							'$type' => 'app.bsky.richtext.facet#tag',
							'tag'   => $tag,
						),
					),
				);
			}
		}

		return $facets;
	}

	/**
	 * Get post embed (external link card or images).
	 *
	 * @param \WP_Post $post The WordPress post.
	 * @return array|null The embed or null.
	 */
	private static function get_post_embed( $post ) {
		// For now, create an external embed with the post URL.
		$permalink   = get_permalink( $post );
		$title       = get_the_title( $post );
		$description = wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '...' );

		$embed = array(
			'$type'    => 'app.bsky.embed.external',
			'external' => array(
				'uri'         => $permalink,
				'title'       => $title,
				'description' => $description,
			),
		);

		// Add thumbnail if featured image exists.
		$thumbnail_id = get_post_thumbnail_id( $post );
		if ( $thumbnail_id ) {
			$thumb_url = wp_get_attachment_image_url( $thumbnail_id, 'medium' );
			if ( $thumb_url ) {
				// Note: In a full implementation, this would be a blob reference.
				// For now, we'll omit it as it requires upload to the PDS first.
			}
		}

		return $embed;
	}

	/**
	 * List records in a collection.
	 *
	 * @param string $collection The collection NSID.
	 * @param int    $limit      Maximum records to return.
	 * @param string $cursor     Pagination cursor.
	 * @param bool   $reverse    Reverse order.
	 * @return array Array with 'records' and optional 'cursor'.
	 */
	public static function list_records( $collection, $limit = 50, $cursor = '', $reverse = false ) {
		$records = array();

		if ( 'app.bsky.feed.post' === $collection ) {
			$args = array(
				'post_type'      => get_option( 'atproto_enabled_post_types', array( 'post' ) ),
				'post_status'    => 'publish',
				'posts_per_page' => $limit + 1, // Fetch one extra to check for more.
				'orderby'        => 'date',
				'order'          => $reverse ? 'ASC' : 'DESC',
			);

			// Handle cursor (post ID).
			if ( ! empty( $cursor ) ) {
				$cursor_post = get_post( absint( $cursor ) );
				if ( $cursor_post ) {
					$args['date_query'] = array(
						array(
							'before'    => $cursor_post->post_date,
							'inclusive' => false,
						),
					);
				}
			}

			$posts = get_posts( $args );

			$next_cursor = '';
			if ( count( $posts ) > $limit ) {
				$last_post   = array_pop( $posts );
				$next_cursor = (string) $last_post->ID;
			}

			foreach ( $posts as $post ) {
				// Ensure post has a TID.
				$tid = get_post_meta( $post->ID, self::META_TID, true );
				if ( empty( $tid ) ) {
					$tid = TID::generate();
					update_post_meta( $post->ID, self::META_TID, $tid );
				}

				$records[] = self::post_to_record( $post, $collection );
			}

			return array(
				'records' => $records,
				'cursor'  => $next_cursor,
			);
		}

		// For app.bsky.actor.profile, return the profile record.
		if ( 'app.bsky.actor.profile' === $collection ) {
			return array(
				'records' => array( self::get_profile_record() ),
				'cursor'  => '',
			);
		}

		// For other collections, return empty for now.
		return array(
			'records' => array(),
			'cursor'  => '',
		);
	}

	/**
	 * Create a new record.
	 *
	 * @param string $collection The collection NSID.
	 * @param array  $record     The record data.
	 * @param string $rkey       Optional specific record key.
	 * @return array|false The created record or false on failure.
	 */
	public static function create( $collection, $record, $rkey = '' ) {
		// Use Repository to create the record.
		return Repository::create_record( $collection, $record, $rkey );
	}

	/**
	 * Update an existing record.
	 *
	 * @param string $collection The collection NSID.
	 * @param string $rkey       The record key.
	 * @param array  $record     The new record data.
	 * @return array|false The updated record or false on failure.
	 */
	public static function update( $collection, $rkey, $record ) {
		return Repository::put_record( $collection, $rkey, $record );
	}

	/**
	 * Delete a record.
	 *
	 * @param string $collection The collection NSID.
	 * @param string $rkey       The record key.
	 * @return bool True on success, false on failure.
	 */
	public static function delete( $collection, $rkey ) {
		return Repository::delete_record( $collection, $rkey );
	}

	/**
	 * Synchronize a WordPress post to the repository.
	 *
	 * @param \WP_Post $post The WordPress post.
	 * @return array|false The record info or false on failure.
	 */
	public static function sync_post( $post ) {
		// Get or generate TID.
		$rkey = get_post_meta( $post->ID, self::META_TID, true );
		if ( empty( $rkey ) ) {
			$rkey = TID::generate();
			update_post_meta( $post->ID, self::META_TID, $rkey );
		}

		// Build the record.
		$record_data = self::post_to_record( $post, 'app.bsky.feed.post' );
		$record      = $record_data['value'];

		// Store in repository.
		$result = Repository::create_record( 'app.bsky.feed.post', $record, $rkey );

		if ( $result ) {
			// Update post meta with CID and URI.
			update_post_meta( $post->ID, self::META_CID, $result['cid'] );
			update_post_meta( $post->ID, self::META_URI, $result['uri'] );
			update_post_meta( $post->ID, self::META_COLLECTION, 'app.bsky.feed.post' );
		}

		return $result;
	}

	/**
	 * Compute CID for a record.
	 *
	 * @param array $record The record data.
	 * @return string The CID.
	 */
	public static function compute_cid( $record ) {
		return CID::from_cbor( $record );
	}

	/**
	 * Serialize a record to CBOR.
	 *
	 * @param array $record The record data.
	 * @return string The CBOR bytes.
	 */
	public static function to_cbor( $record ) {
		return CBOR::encode( $record );
	}

	/**
	 * Deserialize a record from CBOR.
	 *
	 * @param string $cbor The CBOR bytes.
	 * @return array The record data.
	 */
	public static function from_cbor( $cbor ) {
		return CBOR::decode( $cbor );
	}
}
