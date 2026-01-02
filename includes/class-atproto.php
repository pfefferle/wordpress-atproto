<?php
/**
 * Main ATProto plugin class.
 *
 * @package ATProto
 */

namespace ATProto;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
class ATProto {
	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	public static function init() {
		// Load text domain.
		add_action( 'init', array( self::class, 'load_textdomain' ) );

		// Initialize identity module.
		Identity\DID_Document::init();

		// Initialize schedulers for federation.
		Scheduler\Post::init();
		Scheduler\Comment::init();
		Scheduler\Relay::init();

		// Initialize admin.
		if ( is_admin() ) {
			WP_Admin\Admin::init();
		}

		// Initialize REST API / XRPC endpoints.
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );

		// Add rewrite rules for .well-known/did.json.
		add_action( 'init', array( self::class, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( self::class, 'add_query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'handle_did_document' ) );

		// Add link to DID document in HTML head.
		add_action( 'wp_head', array( self::class, 'add_did_link' ) );
	}

	/**
	 * Load plugin text domain.
	 *
	 * @return void
	 */
	public static function load_textdomain() {
		load_plugin_textdomain(
			'atproto',
			false,
			dirname( ATPROTO_PLUGIN_BASENAME ) . '/languages/'
		);
	}

	/**
	 * Register REST API routes for XRPC endpoints.
	 *
	 * @return void
	 */
	public static function register_rest_routes() {
		// Register XRPC endpoints.
		$controllers = array(
			// Identity.
			new Rest\Identity\Resolve_Handle(),
			// Server.
			new Rest\Server\Describe_Server(),
			// Repository - read.
			new Rest\Repo\Describe_Repo(),
			new Rest\Repo\Get_Record(),
			new Rest\Repo\List_Records(),
			// Repository - write.
			new Rest\Repo\Create_Record(),
			new Rest\Repo\Put_Record(),
			new Rest\Repo\Delete_Record(),
			new Rest\Repo\Upload_Blob(),
			// Sync.
			new Rest\Sync\Get_Repo(),
			new Rest\Sync\Get_Blob(),
			new Rest\Sync\Subscribe_Repos(),
		);

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}

	/**
	 * Add rewrite rules for .well-known endpoints and XRPC.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules() {
		// DID document.
		add_rewrite_rule(
			'^\.well-known/did\.json$',
			'index.php?atproto_did_document=1',
			'top'
		);

		// Handle verification (atproto-did).
		add_rewrite_rule(
			'^\.well-known/atproto-did$',
			'index.php?atproto_did=1',
			'top'
		);

		// XRPC endpoints - rewrite /xrpc/* to REST API.
		add_rewrite_rule(
			'^xrpc/(.+)$',
			'index.php?rest_route=/xrpc/$matches[1]',
			'top'
		);
	}

	/**
	 * Add query variables.
	 *
	 * @param array $vars Existing query variables.
	 * @return array Modified query variables.
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'atproto_did_document';
		$vars[] = 'atproto_did';
		return $vars;
	}

	/**
	 * Handle DID document request.
	 *
	 * @return void
	 */
	public static function handle_did_document() {
		// Handle /.well-known/atproto-did (plain text DID for handle verification).
		if ( get_query_var( 'atproto_did' ) ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Access-Control-Allow-Origin: *' );

			echo esc_html( self::get_did() );
			exit;
		}

		// Handle /.well-known/did.json (full DID document).
		if ( ! get_query_var( 'atproto_did_document' ) ) {
			return;
		}

		$did_document = Identity\DID_Document::generate();

		header( 'Content-Type: application/did+json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );

		echo wp_json_encode( $did_document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Add DID document link to HTML head.
	 *
	 * @return void
	 */
	public static function add_did_link() {
		printf(
			'<link rel="alternate" type="application/did+json" href="%s" />' . "\n",
			esc_url( home_url( '/.well-known/did.json' ) )
		);
	}

	/**
	 * Get the site's DID identifier.
	 *
	 * @return string The did:web identifier.
	 */
	public static function get_did() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$port = wp_parse_url( home_url(), PHP_URL_PORT );
		$path = wp_parse_url( home_url(), PHP_URL_PATH );

		$did = 'did:web:' . $host;

		if ( $port ) {
			$did .= '%3A' . $port;
		}

		if ( $path && '/' !== $path ) {
			$did .= str_replace( '/', ':', $path );
		}

		return $did;
	}

	/**
	 * Get the site's AT Protocol handle.
	 *
	 * @return string The AT Protocol handle (domain).
	 */
	public static function get_handle() {
		return wp_parse_url( home_url(), PHP_URL_HOST );
	}
}
