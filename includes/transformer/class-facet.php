<?php
/**
 * Facet extraction for AT Protocol rich text.
 *
 * Facets annotate text with links, mentions, and hashtags.
 *
 * @package ATProto
 */

namespace ATProto\Transformer;

defined( 'ABSPATH' ) || exit;

/**
 * Facet class for rich text annotations.
 */
class Facet {
	/**
	 * Extract all facets from text.
	 *
	 * @param string $text The text to extract from.
	 * @return array Array of facets.
	 */
	public static function extract( $text ) {
		$facets = array();

		// Extract in order: links, mentions, hashtags.
		$facets = array_merge( $facets, self::extract_links( $text ) );
		$facets = array_merge( $facets, self::extract_mentions( $text ) );
		$facets = array_merge( $facets, self::extract_hashtags( $text ) );

		// Sort by byte start position.
		usort( $facets, function ( $a, $b ) {
			return $a['index']['byteStart'] - $b['index']['byteStart'];
		} );

		return $facets;
	}

	/**
	 * Extract URL links from text.
	 *
	 * @param string $text The text.
	 * @return array Array of link facets.
	 */
	public static function extract_links( $text ) {
		$facets  = array();
		$pattern = '#\bhttps?://[^\s<>\[\]"\']+#iu';

		if ( ! preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $facets;
		}

		foreach ( $matches[0] as $match ) {
			$url   = $match[0];
			$start = $match[1];

			// Clean trailing punctuation.
			$url = rtrim( $url, '.,;:!?)' );

			// Calculate byte positions.
			$byte_start = self::char_to_byte_offset( $text, $start );
			$byte_end   = $byte_start + strlen( $url );

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

		return $facets;
	}

	/**
	 * Extract mentions from text.
	 *
	 * @param string $text The text.
	 * @return array Array of mention facets.
	 */
	public static function extract_mentions( $text ) {
		$facets = array();

		// Match @handle.domain.tld format.
		$pattern = '/@([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+)/u';

		if ( ! preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $facets;
		}

		foreach ( $matches[0] as $index => $match ) {
			$mention = $match[0];
			$handle  = $matches[1][ $index ][0];
			$start   = $match[1];

			$byte_start = self::char_to_byte_offset( $text, $start );
			$byte_end   = $byte_start + strlen( $mention );

			// Resolve handle to DID.
			$did = self::resolve_handle( $handle );

			$facets[] = array(
				'index'    => array(
					'byteStart' => $byte_start,
					'byteEnd'   => $byte_end,
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#mention',
						'did'   => $did,
					),
				),
			);
		}

		return $facets;
	}

	/**
	 * Extract hashtags from text.
	 *
	 * @param string $text The text.
	 * @return array Array of hashtag facets.
	 */
	public static function extract_hashtags( $text ) {
		$facets = array();

		// Match #hashtag format (letters, numbers, underscores).
		$pattern = '/#([a-zA-Z][a-zA-Z0-9_]{0,63})/u';

		if ( ! preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $facets;
		}

		foreach ( $matches[0] as $index => $match ) {
			$hashtag = $match[0];
			$tag     = $matches[1][ $index ][0];
			$start   = $match[1];

			$byte_start = self::char_to_byte_offset( $text, $start );
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

		return $facets;
	}

	/**
	 * Convert character offset to byte offset.
	 *
	 * @param string $text   The text.
	 * @param int    $offset The character offset.
	 * @return int The byte offset.
	 */
	private static function char_to_byte_offset( $text, $offset ) {
		$substring = mb_substr( $text, 0, $offset );
		return strlen( $substring );
	}

	/**
	 * Resolve a handle to a DID.
	 *
	 * @param string $handle The handle to resolve.
	 * @return string The DID.
	 */
	private static function resolve_handle( $handle ) {
		// Check if it's our own handle.
		if ( strtolower( $handle ) === strtolower( \ATProto\ATProto::get_handle() ) ) {
			return \ATProto\ATProto::get_did();
		}

		// Try DNS resolution.
		$did = self::resolve_handle_dns( $handle );
		if ( $did ) {
			return $did;
		}

		// Fallback to did:web.
		return 'did:web:' . $handle;
	}

	/**
	 * Resolve handle via DNS TXT record.
	 *
	 * @param string $handle The handle.
	 * @return string|false The DID or false.
	 */
	private static function resolve_handle_dns( $handle ) {
		$records = @dns_get_record( '_atproto.' . $handle, DNS_TXT );

		if ( empty( $records ) ) {
			return false;
		}

		foreach ( $records as $record ) {
			if ( isset( $record['txt'] ) && 0 === strpos( $record['txt'], 'did=' ) ) {
				return substr( $record['txt'], 4 );
			}
		}

		return false;
	}

	/**
	 * Build facets for a specific text with known URLs.
	 *
	 * @param string $text The text.
	 * @param array  $urls Array of URLs to create facets for.
	 * @return array Array of facets.
	 */
	public static function for_urls( $text, $urls ) {
		$facets = array();

		foreach ( $urls as $url ) {
			$pos = strpos( $text, $url );
			if ( false === $pos ) {
				continue;
			}

			$byte_start = self::char_to_byte_offset( $text, $pos );
			$byte_end   = $byte_start + strlen( $url );

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

		return $facets;
	}
}
