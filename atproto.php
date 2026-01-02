<?php
/**
 * Plugin Name: AT Protocol
 * Plugin URI: https://github.com/pfefferle/wordpress-atproto
 * Description: AT Protocol (Bluesky) integration for WordPress - enables your site as a federated PDS node.
 * Version: 0.1.0
 * Author: Matthias Pfefferle
 * Author URI: https://notiz.blog/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: atproto
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package ATProto
 */

namespace ATProto;

defined( 'ABSPATH' ) || exit;

define( 'ATPROTO_VERSION', '0.1.0' );
define( 'ATPROTO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ATPROTO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ATPROTO_PLUGIN_FILE', __FILE__ );
define( 'ATPROTO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Require Composer autoloader if available.
if ( file_exists( ATPROTO_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once ATPROTO_PLUGIN_DIR . 'vendor/autoload.php';
}

// Require plugin autoloader.
require_once ATPROTO_PLUGIN_DIR . 'includes/class-autoloader.php';

// Require helper functions.
require_once ATPROTO_PLUGIN_DIR . 'includes/functions.php';

/**
 * Initialize the plugin.
 *
 * @return void
 */
function init() {
	// Register autoloader.
	Autoloader::register();

	// Initialize main plugin class.
	ATProto::init();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\init' );

/**
 * Plugin activation hook.
 *
 * @return void
 */
function activate() {
	// Register autoloader for activation.
	Autoloader::register();

	// Generate cryptographic keys on activation.
	Identity\Crypto::generate_keys();

	// Flush rewrite rules for DID document endpoint.
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\activate' );

/**
 * Plugin deactivation hook.
 *
 * @return void
 */
function deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate' );
