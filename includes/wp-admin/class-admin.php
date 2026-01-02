<?php
/**
 * Admin functionality for AT Protocol plugin.
 *
 * @package ATProto
 */

namespace ATProto\WP_Admin;

use ATProto\ATProto;
use ATProto\Identity\Crypto;
use ATProto\Identity\DID_Document;

defined( 'ABSPATH' ) || exit;

/**
 * Admin class.
 */
class Admin {
	/**
	 * Relay endpoint for requesting crawl.
	 *
	 * @var string
	 */
	const RELAY_ENDPOINT = 'https://bsky.network/xrpc/com.atproto.sync.requestCrawl';

	/**
	 * Initialize admin functionality.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_init', array( self::class, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_scripts' ) );
		add_filter( 'plugin_action_links_' . ATPROTO_PLUGIN_BASENAME, array( self::class, 'add_action_links' ) );
	}

	/**
	 * Handle admin actions.
	 *
	 * @return void
	 */
	public static function handle_actions() {
		if ( ! isset( $_POST['atproto_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['atproto_action'] ) );

		if ( 'request_crawl' === $action ) {
			check_admin_referer( 'atproto_request_crawl' );
			self::request_crawl();
		}
	}

	/**
	 * Request crawl from relay.
	 *
	 * @return void
	 */
	public static function request_crawl() {
		$hostname = ATProto::get_handle();

		$response = wp_remote_post(
			self::RELAY_ENDPOINT,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( array(
					'hostname' => $hostname,
				) ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			add_settings_error(
				'atproto',
				'request_crawl_failed',
				sprintf(
					/* translators: %s: Error message */
					__( 'Failed to request crawl: %s', 'atproto' ),
					$response->get_error_message()
				),
				'error'
			);
			return;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code >= 200 && $status_code < 300 ) {
			update_option( 'atproto_crawl_requested', time() );
			add_settings_error(
				'atproto',
				'request_crawl_success',
				__( 'Successfully requested crawl from relay. Your site will be indexed soon.', 'atproto' ),
				'success'
			);
		} else {
			$error_message = $body;
			$decoded       = json_decode( $body, true );
			if ( $decoded && isset( $decoded['message'] ) ) {
				$error_message = $decoded['message'];
			}
			add_settings_error(
				'atproto',
				'request_crawl_failed',
				sprintf(
					/* translators: 1: HTTP status code, 2: Error message */
					__( 'Relay returned error %1$d: %2$s', 'atproto' ),
					$status_code,
					$error_message
				),
				'error'
			);
		}
	}

	/**
	 * Add admin menu.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_options_page(
			__( 'AT Protocol', 'atproto' ),
			__( 'AT Protocol', 'atproto' ),
			'manage_options',
			'atproto',
			array( self::class, 'render_settings_page' )
		);
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public static function register_settings() {
		// Settings section.
		add_settings_section(
			'atproto_identity',
			__( 'Identity', 'atproto' ),
			array( self::class, 'render_identity_section' ),
			'atproto'
		);

		add_settings_section(
			'atproto_federation',
			__( 'Federation', 'atproto' ),
			array( self::class, 'render_federation_section' ),
			'atproto'
		);

		// Settings fields.
		add_settings_field(
			'atproto_did',
			__( 'DID', 'atproto' ),
			array( self::class, 'render_did_field' ),
			'atproto',
			'atproto_identity'
		);

		add_settings_field(
			'atproto_handle',
			__( 'Handle', 'atproto' ),
			array( self::class, 'render_handle_field' ),
			'atproto',
			'atproto_identity'
		);

		add_settings_field(
			'atproto_public_key',
			__( 'Public Key', 'atproto' ),
			array( self::class, 'render_public_key_field' ),
			'atproto',
			'atproto_identity'
		);

		add_settings_field(
			'atproto_did_document',
			__( 'DID Document', 'atproto' ),
			array( self::class, 'render_did_document_field' ),
			'atproto',
			'atproto_identity'
		);

		add_settings_field(
			'atproto_post_types',
			__( 'Post Types', 'atproto' ),
			array( self::class, 'render_post_types_field' ),
			'atproto',
			'atproto_federation'
		);

		add_settings_field(
			'atproto_request_crawl',
			__( 'Relay Registration', 'atproto' ),
			array( self::class, 'render_request_crawl_field' ),
			'atproto',
			'atproto_federation'
		);

		// Register settings.
		register_setting( 'atproto', 'atproto_enabled_post_types', array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_post_types' ),
			'default'           => array( 'post' ),
		) );
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public static function enqueue_scripts( $hook ) {
		if ( 'settings_page_atproto' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'atproto-admin',
			ATPROTO_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			ATPROTO_VERSION
		);
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array $links Existing links.
	 * @return array Modified links.
	 */
	public static function add_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'options-general.php?page=atproto' ),
			__( 'Settings', 'atproto' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form action="options.php" method="post">
				<?php
				settings_fields( 'atproto' );
				do_settings_sections( 'atproto' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render identity section description.
	 *
	 * @return void
	 */
	public static function render_identity_section() {
		printf(
			'<p>%s</p>',
			esc_html__( 'Your site\'s AT Protocol identity information.', 'atproto' )
		);
	}

	/**
	 * Render federation section description.
	 *
	 * @return void
	 */
	public static function render_federation_section() {
		printf(
			'<p>%s</p>',
			esc_html__( 'Configure what content is federated to the AT Protocol network.', 'atproto' )
		);
	}

	/**
	 * Render DID field.
	 *
	 * @return void
	 */
	public static function render_did_field() {
		$did = ATProto::get_did();
		printf(
			'<code>%s</code>',
			esc_html( $did )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Your site\'s decentralized identifier (did:web).', 'atproto' )
		);
	}

	/**
	 * Render handle field.
	 *
	 * @return void
	 */
	public static function render_handle_field() {
		$handle = ATProto::get_handle();
		printf(
			'<code>@%s</code>',
			esc_html( $handle )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Your AT Protocol handle based on your domain.', 'atproto' )
		);
	}

	/**
	 * Render public key field.
	 *
	 * @return void
	 */
	public static function render_public_key_field() {
		$public_key = Crypto::get_public_key_multibase();
		if ( $public_key ) {
			printf(
				'<code class="atproto-multiline">%s</code>',
				esc_html( $public_key )
			);
		} else {
			printf(
				'<span class="atproto-error">%s</span>',
				esc_html__( 'No key generated. Please deactivate and reactivate the plugin.', 'atproto' )
			);
		}
	}

	/**
	 * Render DID document field.
	 *
	 * @return void
	 */
	public static function render_did_document_field() {
		$url = home_url( '/.well-known/did.json' );
		printf(
			'<a href="%s" target="_blank">%s</a>',
			esc_url( $url ),
			esc_html( $url )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Your DID document is served at this URL.', 'atproto' )
		);
	}

	/**
	 * Render post types field.
	 *
	 * @return void
	 */
	public static function render_post_types_field() {
		$enabled    = get_option( 'atproto_enabled_post_types', array( 'post' ) );
		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);

		foreach ( $post_types as $post_type ) {
			if ( 'attachment' === $post_type->name ) {
				continue;
			}

			$checked = in_array( $post_type->name, $enabled, true ) ? 'checked' : '';
			printf(
				'<label><input type="checkbox" name="atproto_enabled_post_types[]" value="%s" %s> %s</label><br>',
				esc_attr( $post_type->name ),
				esc_attr( $checked ),
				esc_html( $post_type->label )
			);
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Select which post types should be federated to the AT Protocol network.', 'atproto' )
		);
	}

	/**
	 * Render request crawl field.
	 *
	 * @return void
	 */
	public static function render_request_crawl_field() {
		$last_request = get_option( 'atproto_crawl_requested' );

		?>
		<form method="post" style="display: inline;">
			<?php wp_nonce_field( 'atproto_request_crawl' ); ?>
			<input type="hidden" name="atproto_action" value="request_crawl">
			<?php
			submit_button(
				__( 'Request Crawl', 'atproto' ),
				'secondary',
				'submit',
				false
			);
			?>
		</form>
		<?php

		if ( $last_request ) {
			printf(
				'<p class="description">%s</p>',
				sprintf(
					/* translators: %s: Date and time of last request */
					esc_html__( 'Last requested: %s', 'atproto' ),
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_request ) )
				)
			);
		} else {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Request the Bluesky relay to crawl your site and index your content.', 'atproto' )
			);
		}
	}

	/**
	 * Sanitize post types setting.
	 *
	 * @param array $input The input value.
	 * @return array Sanitized value.
	 */
	public static function sanitize_post_types( $input ) {
		if ( ! is_array( $input ) ) {
			return array( 'post' );
		}

		$valid_post_types = get_post_types( array( 'public' => true ) );

		return array_filter( $input, function ( $type ) use ( $valid_post_types ) {
			return in_array( $type, $valid_post_types, true );
		} );
	}
}
