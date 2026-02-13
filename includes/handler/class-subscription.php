<?php
/**
 * Handler for incoming site.standard.graph.subscription records.
 *
 * @package ATProto
 */

namespace ATProto\Handler;

defined( 'ABSPATH' ) || exit;

/**
 * Subscription handler class.
 */
class Subscription extends Handler {
	/**
	 * Option name for storing subscribers.
	 *
	 * @var string
	 */
	const OPTION_SUBSCRIBERS = 'atproto_publication_subscribers';

	/**
	 * Option name for storing subscriber count.
	 *
	 * @var string
	 */
	const OPTION_SUBSCRIBER_COUNT = 'atproto_publication_subscriber_count';

	/**
	 * Handle the incoming subscription.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function handle() {
		// Get the subject (the publication being subscribed to).
		$subject = $this->record['subject'] ?? '';

		// Build our publication AT-URI.
		$publication_tid = get_option( 'atproto_publication_tid' );
		if ( ! $publication_tid ) {
			return false;
		}

		$our_publication_uri = sprintf(
			'at://%s/site.standard.publication/%s',
			\ATProto\ATProto::get_did(),
			$publication_tid
		);

		// Check if they're subscribing to our publication.
		if ( $subject !== $our_publication_uri ) {
			return false;
		}

		// Get existing subscribers.
		$subscribers = get_option( self::OPTION_SUBSCRIBERS, array() );
		if ( ! is_array( $subscribers ) ) {
			$subscribers = array();
		}

		// Check if already subscribed.
		if ( isset( $subscribers[ $this->did ] ) ) {
			return true;
		}

		// Add the subscriber.
		$subscribers[ $this->did ] = array(
			'handle'     => $this->handle,
			'created_at' => $this->record['createdAt'] ?? current_time( 'mysql', true ),
			'uri'        => $this->record['uri'] ?? '',
		);

		update_option( self::OPTION_SUBSCRIBERS, $subscribers, false );

		// Update count.
		$count = count( $subscribers );
		update_option( self::OPTION_SUBSCRIBER_COUNT, $count, false );

		/**
		 * Fires when the site receives a new subscriber.
		 *
		 * @param string $did     The subscriber's DID.
		 * @param string $handle  The subscriber's handle.
		 * @param array  $record  The subscription record.
		 */
		do_action( 'atproto_new_subscriber', $this->did, $this->handle, $this->record );

		return true;
	}

	/**
	 * Handle unsubscribe (record deletion).
	 *
	 * @return bool True on success.
	 */
	public function unsubscribe() {
		$subscribers = get_option( self::OPTION_SUBSCRIBERS, array() );

		if ( ! is_array( $subscribers ) || ! isset( $subscribers[ $this->did ] ) ) {
			return true;
		}

		unset( $subscribers[ $this->did ] );
		update_option( self::OPTION_SUBSCRIBERS, $subscribers, false );

		// Update count.
		$count = count( $subscribers );
		update_option( self::OPTION_SUBSCRIBER_COUNT, $count, false );

		/**
		 * Fires when a subscriber unsubscribes from the site.
		 *
		 * @param string $did    The subscriber's DID.
		 * @param string $handle The subscriber's handle.
		 */
		do_action( 'atproto_unsubscribed', $this->did, $this->handle );

		return true;
	}

	/**
	 * Get the subscriber count.
	 *
	 * @return int The subscriber count.
	 */
	public static function get_subscriber_count() {
		return (int) get_option( self::OPTION_SUBSCRIBER_COUNT, 0 );
	}

	/**
	 * Get all subscribers.
	 *
	 * @return array Array of subscribers.
	 */
	public static function get_subscribers() {
		$subscribers = get_option( self::OPTION_SUBSCRIBERS, array() );
		return is_array( $subscribers ) ? $subscribers : array();
	}
}
