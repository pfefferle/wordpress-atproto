<?php
/**
 * Helper functions for AT Protocol plugin.
 *
 * @package ATProto
 */

namespace ATProto;

defined( 'ABSPATH' ) || exit;

/**
 * Get the AT Protocol URI for a WordPress post.
 *
 * @param int|\WP_Post $post The post ID or object.
 * @return string|null The AT-URI or null if not federated.
 */
function get_post_at_uri( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return null;
	}

	return get_post_meta( $post->ID, Repository\Record::META_URI, true ) ?: null;
}

/**
 * Get the AT Protocol TID for a WordPress post.
 *
 * @param int|\WP_Post $post The post ID or object.
 * @return string|null The TID or null if not federated.
 */
function get_post_tid( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return null;
	}

	return get_post_meta( $post->ID, Repository\Record::META_TID, true ) ?: null;
}

/**
 * Check if a post is federated to AT Protocol.
 *
 * @param int|\WP_Post $post The post ID or object.
 * @return bool True if federated.
 */
function is_post_federated( $post ) {
	return ! empty( get_post_tid( $post ) );
}

/**
 * Parse an AT-URI into its components.
 *
 * Format: at://did:method:identifier/collection/rkey
 *
 * @param string $uri The AT-URI to parse.
 * @return array|false Array with 'did', 'collection', 'rkey' or false on failure.
 */
function parse_at_uri( $uri ) {
	if ( 0 !== strpos( $uri, 'at://' ) ) {
		return false;
	}

	$path  = substr( $uri, 5 ); // Remove "at://".
	$parts = explode( '/', $path );

	if ( count( $parts ) < 3 ) {
		return false;
	}

	return array(
		'did'        => $parts[0],
		'collection' => $parts[1],
		'rkey'       => $parts[2],
	);
}

/**
 * Build an AT-URI from components.
 *
 * @param string $did        The DID.
 * @param string $collection The collection NSID.
 * @param string $rkey       The record key.
 * @return string The AT-URI.
 */
function build_at_uri( $did, $collection, $rkey ) {
	return sprintf( 'at://%s/%s/%s', $did, $collection, $rkey );
}

/**
 * Validate an NSID (Namespaced Identifier).
 *
 * @param string $nsid The NSID to validate.
 * @return bool True if valid.
 */
function is_valid_nsid( $nsid ) {
	// NSID format: reverse domain notation segments separated by dots.
	// Each segment: lowercase letters, digits, hyphen (not at start/end).
	$pattern = '/^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)+$/';
	return (bool) preg_match( $pattern, $nsid );
}

/**
 * Get the current user's AT Protocol DID.
 *
 * For a single-site actor model, this returns the site DID.
 *
 * @return string The DID.
 */
function get_current_did() {
	return ATProto::get_did();
}

/**
 * Get the current user's AT Protocol handle.
 *
 * For a single-site actor model, this returns the site handle.
 *
 * @return string The handle.
 */
function get_current_handle() {
	return ATProto::get_handle();
}

/**
 * Sanitize text for AT Protocol (remove unsupported characters).
 *
 * @param string $text The text to sanitize.
 * @return string Sanitized text.
 */
function sanitize_text( $text ) {
	// Remove HTML.
	$text = wp_strip_all_tags( $text );

	// Decode HTML entities.
	$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

	// Normalize whitespace.
	$text = preg_replace( '/\s+/', ' ', $text );

	// Trim.
	$text = trim( $text );

	return $text;
}

/**
 * Truncate text to fit within grapheme limit.
 *
 * @param string $text      The text to truncate.
 * @param int    $max_chars Maximum characters (approximation of graphemes).
 * @param string $suffix    Suffix to append if truncated.
 * @return string Truncated text.
 */
function truncate_text( $text, $max_chars = 300, $suffix = '...' ) {
	if ( mb_strlen( $text ) <= $max_chars ) {
		return $text;
	}

	$truncated = mb_substr( $text, 0, $max_chars - mb_strlen( $suffix ) );

	// Try to break at word boundary.
	$last_space = mb_strrpos( $truncated, ' ' );
	if ( $last_space > $max_chars * 0.8 ) {
		$truncated = mb_substr( $truncated, 0, $last_space );
	}

	return $truncated . $suffix;
}

/**
 * Convert a WordPress datetime to ISO 8601 format.
 *
 * @param string $datetime WordPress datetime string.
 * @param bool   $gmt      Whether the datetime is in GMT.
 * @return string ISO 8601 formatted datetime.
 */
function to_iso8601( $datetime, $gmt = true ) {
	$timestamp = strtotime( $datetime );
	if ( ! $gmt ) {
		$timestamp = get_gmt_from_date( $datetime, 'U' );
	}
	return gmdate( 'Y-m-d\TH:i:s.000\Z', $timestamp );
}
