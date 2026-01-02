<?php
/**
 * Relay subscription handler.
 *
 * Handles subscribing to AT Protocol relays and processing incoming events.
 *
 * @package ATProto
 */

namespace ATProto\Scheduler;

use ATProto\ATProto;
use ATProto\Handler\Handler;
use ATProto\Identity\DID_Document;
use ATProto\Repository\CBOR;

defined( 'ABSPATH' ) || exit;

/**
 * Relay class for relay subscription.
 */
class Relay {
	/**
	 * Option name for relay configuration.
	 *
	 * @var string
	 */
	const OPTION_CONFIG = 'atproto_relay_config';

	/**
	 * Option name for last cursor.
	 *
	 * @var string
	 */
	const OPTION_CURSOR = 'atproto_relay_cursor';

	/**
	 * Option name for subscribed DIDs.
	 *
	 * @var string
	 */
	const OPTION_SUBSCRIBED = 'atproto_relay_subscribed';

	/**
	 * Default relay URL.
	 *
	 * @var string
	 */
	const DEFAULT_RELAY = 'https://bsky.network';

	/**
	 * Initialize the relay scheduler.
	 *
	 * @return void
	 */
	public static function init() {
		// Schedule polling cron.
		add_action( 'atproto_relay_poll', array( self::class, 'poll' ) );

		if ( ! wp_next_scheduled( 'atproto_relay_poll' ) ) {
			wp_schedule_event( time(), 'hourly', 'atproto_relay_poll' );
		}
	}

	/**
	 * Get relay URL.
	 *
	 * @return string The relay URL.
	 */
	public static function get_relay_url() {
		$config = get_option( self::OPTION_CONFIG, array() );
		return $config['relay_url'] ?? self::DEFAULT_RELAY;
	}

	/**
	 * Set relay URL.
	 *
	 * @param string $url The relay URL.
	 * @return void
	 */
	public static function set_relay_url( $url ) {
		$config              = get_option( self::OPTION_CONFIG, array() );
		$config['relay_url'] = $url;
		update_option( self::OPTION_CONFIG, $config, false );
	}

	/**
	 * Subscribe to a DID's updates.
	 *
	 * @param string $did The DID to subscribe to.
	 * @return bool True on success.
	 */
	public static function subscribe( $did ) {
		$subscribed         = get_option( self::OPTION_SUBSCRIBED, array() );
		$subscribed[ $did ] = array(
			'subscribed_at' => current_time( 'mysql', true ),
			'last_sync'     => null,
		);
		update_option( self::OPTION_SUBSCRIBED, $subscribed, false );

		return true;
	}

	/**
	 * Unsubscribe from a DID's updates.
	 *
	 * @param string $did The DID to unsubscribe from.
	 * @return bool True on success.
	 */
	public static function unsubscribe( $did ) {
		$subscribed = get_option( self::OPTION_SUBSCRIBED, array() );
		unset( $subscribed[ $did ] );
		update_option( self::OPTION_SUBSCRIBED, $subscribed, false );

		return true;
	}

	/**
	 * Get subscribed DIDs.
	 *
	 * @return array Array of subscribed DIDs.
	 */
	public static function get_subscribed() {
		return get_option( self::OPTION_SUBSCRIBED, array() );
	}

	/**
	 * Poll the relay for updates.
	 *
	 * @return int Number of events processed.
	 */
	public static function poll() {
		$subscribed = self::get_subscribed();

		if ( empty( $subscribed ) ) {
			return 0;
		}

		$processed = 0;

		foreach ( array_keys( $subscribed ) as $did ) {
			$count      = self::poll_did( $did );
			$processed += $count;
		}

		return $processed;
	}

	/**
	 * Poll updates for a specific DID.
	 *
	 * @param string $did The DID to poll.
	 * @return int Number of events processed.
	 */
	private static function poll_did( $did ) {
		// Resolve the DID to get PDS.
		$did_doc = DID_Document::resolve( $did );

		if ( ! $did_doc ) {
			return 0;
		}

		// Get PDS endpoint.
		$pds_url = null;
		foreach ( $did_doc['service'] ?? array() as $service ) {
			if ( '#atproto_pds' === ( $service['id'] ?? '' ) ||
				'AtprotoPersonalDataServer' === ( $service['type'] ?? '' ) ) {
				$pds_url = $service['serviceEndpoint'];
				break;
			}
		}

		if ( ! $pds_url ) {
			return 0;
		}

		// Fetch recent records.
		$records = self::fetch_records( $pds_url, $did );

		if ( empty( $records ) ) {
			return 0;
		}

		// Get handle.
		$handle = $did_doc['alsoKnownAs'][0] ?? $did;
		$handle = str_replace( 'at://', '', $handle );

		// Process records.
		$processed = 0;
		foreach ( $records as $record ) {
			$handled = Handler::dispatch( $record['value'], $did, $handle );
			if ( $handled ) {
				$processed++;
			}
		}

		// Update last sync.
		$subscribed         = get_option( self::OPTION_SUBSCRIBED, array() );
		$subscribed[ $did ]['last_sync'] = current_time( 'mysql', true );
		update_option( self::OPTION_SUBSCRIBED, $subscribed, false );

		return $processed;
	}

	/**
	 * Fetch records from a PDS.
	 *
	 * @param string $pds_url The PDS URL.
	 * @param string $did     The DID.
	 * @return array Array of records.
	 */
	private static function fetch_records( $pds_url, $did ) {
		$records = array();

		// Fetch posts that might be replies to our content.
		$collections = array(
			'app.bsky.feed.post',
			'app.bsky.feed.like',
			'app.bsky.feed.repost',
			'app.bsky.graph.follow',
		);

		foreach ( $collections as $collection ) {
			$url = trailingslashit( $pds_url ) . 'xrpc/com.atproto.repo.listRecords';
			$url = add_query_arg( array(
				'repo'       => $did,
				'collection' => $collection,
				'limit'      => 50,
			), $url );

			$response = wp_remote_get( $url, array(
				'timeout' => 30,
				'headers' => array(
					'Accept' => 'application/json',
				),
			) );

			if ( is_wp_error( $response ) ) {
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				continue;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! empty( $data['records'] ) ) {
				$records = array_merge( $records, $data['records'] );
			}
		}

		return $records;
	}

	/**
	 * Check if we're subscribed to a DID.
	 *
	 * @param string $did The DID to check.
	 * @return bool True if subscribed.
	 */
	public static function is_subscribed( $did ) {
		$subscribed = self::get_subscribed();
		return isset( $subscribed[ $did ] );
	}

	/**
	 * Notify relay of our updates (future implementation).
	 *
	 * @return bool True on success.
	 */
	public static function notify() {
		// This would push updates to a relay.
		// For now, relays pull from us via our XRPC endpoints.
		return true;
	}
}
