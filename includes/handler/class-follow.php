<?php
/**
 * Handler for incoming AT Protocol follows.
 *
 * @package ATProto
 */

namespace ATProto\Handler;

defined( 'ABSPATH' ) || exit;

/**
 * Follow handler class.
 */
class Follow extends Handler {
	/**
	 * Option name for storing followers.
	 *
	 * @var string
	 */
	const OPTION_FOLLOWERS = 'atproto_followers';

	/**
	 * Option name for storing follower count.
	 *
	 * @var string
	 */
	const OPTION_FOLLOWER_COUNT = 'atproto_follower_count';

	/**
	 * Handle the incoming follow.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function handle() {
		// Get the subject (who is being followed).
		$subject = $this->record['subject'] ?? '';

		// Check if they're following our site.
		if ( $subject !== \ATProto\ATProto::get_did() ) {
			return false;
		}

		// Get existing followers.
		$followers = get_option( self::OPTION_FOLLOWERS, array() );
		if ( ! is_array( $followers ) ) {
			$followers = array();
		}

		// Check if already following.
		if ( isset( $followers[ $this->did ] ) ) {
			return true;
		}

		// Add the follower.
		$followers[ $this->did ] = array(
			'handle'     => $this->handle,
			'created_at' => $this->record['createdAt'] ?? current_time( 'mysql', true ),
			'uri'        => $this->record['uri'] ?? '',
		);

		update_option( self::OPTION_FOLLOWERS, $followers, false );

		// Update count.
		$count = count( $followers );
		update_option( self::OPTION_FOLLOWER_COUNT, $count, false );

		/**
		 * Fires when the site receives a new follower.
		 *
		 * @param string $did     The follower's DID.
		 * @param string $handle  The follower's handle.
		 * @param array  $record  The follow record.
		 */
		do_action( 'atproto_new_follower', $this->did, $this->handle, $this->record );

		return true;
	}

	/**
	 * Handle unfollow (record deletion).
	 *
	 * @return bool True on success.
	 */
	public function unfollow() {
		$followers = get_option( self::OPTION_FOLLOWERS, array() );

		if ( ! is_array( $followers ) || ! isset( $followers[ $this->did ] ) ) {
			return true;
		}

		unset( $followers[ $this->did ] );
		update_option( self::OPTION_FOLLOWERS, $followers, false );

		// Update count.
		$count = count( $followers );
		update_option( self::OPTION_FOLLOWER_COUNT, $count, false );

		/**
		 * Fires when a follower unfollows the site.
		 *
		 * @param string $did    The follower's DID.
		 * @param string $handle The follower's handle.
		 */
		do_action( 'atproto_unfollowed', $this->did, $this->handle );

		return true;
	}

	/**
	 * Get the follower count.
	 *
	 * @return int The follower count.
	 */
	public static function get_follower_count() {
		return (int) get_option( self::OPTION_FOLLOWER_COUNT, 0 );
	}

	/**
	 * Get all followers.
	 *
	 * @return array Array of followers.
	 */
	public static function get_followers() {
		$followers = get_option( self::OPTION_FOLLOWERS, array() );
		return is_array( $followers ) ? $followers : array();
	}

	/**
	 * Check if a DID is following.
	 *
	 * @param string $did The DID to check.
	 * @return bool True if following.
	 */
	public static function is_following( $did ) {
		$followers = self::get_followers();
		return isset( $followers[ $did ] );
	}
}
