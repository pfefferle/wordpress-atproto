<?php
/**
 * Base Transformer class.
 *
 * @package ATProto
 */

namespace ATProto\Transformer;

use ATProto\ATProto;
use ATProto\Repository\TID;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract base transformer.
 */
abstract class Base {
	/**
	 * The object being transformed.
	 *
	 * @var mixed
	 */
	protected $object;

	/**
	 * Constructor.
	 *
	 * @param mixed $object The object to transform.
	 */
	public function __construct( $object ) {
		$this->object = $object;
	}

	/**
	 * Transform the object to an AT Protocol record.
	 *
	 * @return array The AT Protocol record.
	 */
	abstract public function transform();

	/**
	 * Get the collection NSID for this record type.
	 *
	 * @return string The collection NSID.
	 */
	abstract public function get_collection();

	/**
	 * Get or generate the record key (TID).
	 *
	 * @return string The record key.
	 */
	abstract public function get_rkey();

	/**
	 * Get the AT-URI for this record.
	 *
	 * @return string The AT-URI.
	 */
	public function get_uri() {
		return sprintf(
			'at://%s/%s/%s',
			ATProto::get_did(),
			$this->get_collection(),
			$this->get_rkey()
		);
	}

	/**
	 * Convert datetime to ISO 8601 format.
	 *
	 * @param string $datetime The datetime string.
	 * @param bool   $is_gmt   Whether the datetime is in GMT.
	 * @return string ISO 8601 formatted datetime.
	 */
	protected function to_iso8601( $datetime, $is_gmt = true ) {
		$timestamp = strtotime( $datetime );
		return gmdate( 'Y-m-d\TH:i:s.000\Z', $timestamp );
	}

	/**
	 * Get the language code.
	 *
	 * @return array Array of language codes.
	 */
	protected function get_langs() {
		$locale = get_locale();
		$lang   = substr( $locale, 0, 2 );
		return array( $lang );
	}

	/**
	 * Sanitize and truncate text.
	 *
	 * @param string $text      The text to process.
	 * @param int    $max_chars Maximum characters.
	 * @return string The processed text.
	 */
	protected function truncate_text( $text, $max_chars = 300 ) {
		// Strip HTML and decode entities.
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		if ( mb_strlen( $text ) <= $max_chars ) {
			return $text;
		}

		$truncated = mb_substr( $text, 0, $max_chars - 3 );
		$last_space = mb_strrpos( $truncated, ' ' );

		if ( $last_space > $max_chars * 0.8 ) {
			$truncated = mb_substr( $truncated, 0, $last_space );
		}

		return $truncated . '...';
	}
}
