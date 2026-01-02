<?php
/**
 * Autoloader for ATProto plugin classes.
 *
 * @package ATProto
 */

namespace ATProto;

defined( 'ABSPATH' ) || exit;

/**
 * Autoloader class.
 */
class Autoloader {
	/**
	 * Register the autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	/**
	 * Autoload classes.
	 *
	 * @param string $class_name The fully-qualified class name.
	 * @return void
	 */
	public static function autoload( $class_name ) {
		// Only autoload classes from this namespace.
		if ( 0 !== strpos( $class_name, 'ATProto\\' ) ) {
			return;
		}

		// Remove namespace prefix.
		$class_name = str_replace( 'ATProto\\', '', $class_name );

		// Convert namespace separators to directory separators.
		$class_path = str_replace( '\\', DIRECTORY_SEPARATOR, $class_name );

		// Convert class name to file name.
		$class_path = strtolower( $class_path );
		$class_path = str_replace( '_', '-', $class_path );

		// Handle subdirectories in namespace.
		$parts     = explode( DIRECTORY_SEPARATOR, $class_path );
		$file_name = array_pop( $parts );
		$file_name = 'class-' . $file_name . '.php';

		if ( ! empty( $parts ) ) {
			$directory = implode( DIRECTORY_SEPARATOR, $parts );
			$file_path = ATPROTO_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $file_name;
		} else {
			$file_path = ATPROTO_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . $file_name;
		}

		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}
}
