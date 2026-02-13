<?php
/**
 * Publication Transformer - converts WordPress site settings to a
 * site.standard.publication AT Protocol record.
 *
 * @package ATProto
 */

namespace ATProto\Transformer;

use ATProto\ATProto;
use ATProto\Repository\TID;

defined( 'ABSPATH' ) || exit;

/**
 * Publication transformer class.
 */
class Publication extends Base {
	/**
	 * Option name for storing the publication TID.
	 *
	 * @var string
	 */
	const OPTION_TID = 'atproto_publication_tid';

	/**
	 * Transform site settings to a site.standard.publication record.
	 *
	 * @return array The site.standard.publication record.
	 */
	public function transform() {
		$record = array(
			'$type'       => 'site.standard.publication',
			'url'         => home_url( '/' ),
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
		);

		// Add site icon as avatar blob reference.
		$site_icon_id = get_option( 'site_icon' );
		if ( $site_icon_id ) {
			$blob = Attachment::get_blob_ref( $site_icon_id );
			if ( $blob ) {
				$record['avatar'] = $blob;
			}
		}

		// Add basic theme information.
		$theme = $this->get_basic_theme();
		if ( $theme ) {
			$record['theme'] = $theme;
		}

		/**
		 * Filter the transformed publication record.
		 *
		 * @param array $record The record.
		 */
		return apply_filters( 'atproto_transform_publication', $record );
	}

	/**
	 * Get the collection NSID.
	 *
	 * @return string
	 */
	public function get_collection() {
		return 'site.standard.publication';
	}

	/**
	 * Get the record key (stable TID stored in options).
	 *
	 * @return string
	 */
	public function get_rkey() {
		$rkey = get_option( self::OPTION_TID );

		if ( empty( $rkey ) ) {
			$rkey = TID::generate();
			update_option( self::OPTION_TID, $rkey, false );
		}

		return $rkey;
	}

	/**
	 * Extract basic theme colors from the active theme.
	 *
	 * @return array|null Theme data or null if unavailable.
	 */
	protected function get_basic_theme() {
		// Try block theme global styles first.
		if ( function_exists( 'wp_get_global_styles' ) ) {
			$styles = wp_get_global_styles();

			if ( ! empty( $styles['color']['background'] ) || ! empty( $styles['color']['text'] ) ) {
				$theme = array();

				if ( ! empty( $styles['color']['background'] ) ) {
					$rgb = self::hex_to_rgb( $styles['color']['background'] );
					if ( $rgb ) {
						$theme['backgroundColor'] = $rgb;
					}
				}

				if ( ! empty( $styles['color']['text'] ) ) {
					$rgb = self::hex_to_rgb( $styles['color']['text'] );
					if ( $rgb ) {
						$theme['textColor'] = $rgb;
					}
				}

				if ( ! empty( $theme ) ) {
					return $theme;
				}
			}
		}

		// Fallback to classic theme mods.
		$bg_color = get_theme_mod( 'background_color' );
		if ( $bg_color ) {
			$rgb = self::hex_to_rgb( '#' . ltrim( $bg_color, '#' ) );
			if ( $rgb ) {
				return array(
					'backgroundColor' => $rgb,
				);
			}
		}

		return null;
	}

	/**
	 * Convert a hex color to an RGB array.
	 *
	 * @param string $hex The hex color string (e.g., '#ff0000' or 'ff0000').
	 * @return array|null Array with 'r', 'g', 'b' keys, or null on failure.
	 */
	public static function hex_to_rgb( $hex ) {
		$hex = ltrim( $hex, '#' );

		// Handle CSS custom properties or non-hex values.
		if ( ! preg_match( '/^[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', $hex ) ) {
			return null;
		}

		// Expand shorthand (e.g., 'f00' to 'ff0000').
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		return array(
			'r' => hexdec( substr( $hex, 0, 2 ) ),
			'g' => hexdec( substr( $hex, 2, 2 ) ),
			'b' => hexdec( substr( $hex, 4, 2 ) ),
		);
	}
}
