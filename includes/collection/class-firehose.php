<?php
/**
 * Firehose - AT Protocol event streaming.
 *
 * Manages the event stream for repository changes.
 *
 * @package ATProto
 */

namespace ATProto\Collection;

use ATProto\ATProto;
use ATProto\Repository\Repository;
use ATProto\Repository\CBOR;

defined( 'ABSPATH' ) || exit;

/**
 * Firehose class for event streaming.
 */
class Firehose {
	/**
	 * Option name for event queue.
	 *
	 * @var string
	 */
	const OPTION_QUEUE = 'atproto_firehose_queue';

	/**
	 * Option name for sequence number.
	 *
	 * @var string
	 */
	const OPTION_SEQ = 'atproto_firehose_seq';

	/**
	 * Maximum queue size.
	 *
	 * @var int
	 */
	const MAX_QUEUE_SIZE = 1000;

	/**
	 * Emit a commit event.
	 *
	 * @param array $operations Array of operations.
	 * @return int The sequence number.
	 */
	public static function emit_commit( $operations ) {
		$seq = self::next_seq();

		$event = array(
			'$type' => '#commit',
			'seq'   => $seq,
			'time'  => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			'repo'  => ATProto::get_did(),
			'rev'   => Repository::get_rev(),
			'ops'   => $operations,
		);

		self::queue_event( $event );

		/**
		 * Fires when a commit event is emitted.
		 *
		 * @param array $event The event data.
		 * @param int   $seq   The sequence number.
		 */
		do_action( 'atproto_firehose_commit', $event, $seq );

		return $seq;
	}

	/**
	 * Emit an identity event.
	 *
	 * @param string $handle The new handle.
	 * @return int The sequence number.
	 */
	public static function emit_identity( $handle ) {
		$seq = self::next_seq();

		$event = array(
			'$type'  => '#identity',
			'seq'    => $seq,
			'time'   => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			'did'    => ATProto::get_did(),
			'handle' => $handle,
		);

		self::queue_event( $event );

		/**
		 * Fires when an identity event is emitted.
		 *
		 * @param array $event The event data.
		 */
		do_action( 'atproto_firehose_identity', $event );

		return $seq;
	}

	/**
	 * Emit an account event.
	 *
	 * @param bool   $active Whether account is active.
	 * @param string $status Account status.
	 * @return int The sequence number.
	 */
	public static function emit_account( $active, $status = '' ) {
		$seq = self::next_seq();

		$event = array(
			'$type'  => '#account',
			'seq'    => $seq,
			'time'   => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			'did'    => ATProto::get_did(),
			'active' => $active,
		);

		if ( $status ) {
			$event['status'] = $status;
		}

		self::queue_event( $event );

		return $seq;
	}

	/**
	 * Get next sequence number.
	 *
	 * @return int The next sequence number.
	 */
	private static function next_seq() {
		$seq = (int) get_option( self::OPTION_SEQ, 0 );
		$seq++;
		update_option( self::OPTION_SEQ, $seq, false );
		return $seq;
	}

	/**
	 * Get current sequence number.
	 *
	 * @return int The current sequence number.
	 */
	public static function get_seq() {
		return (int) get_option( self::OPTION_SEQ, 0 );
	}

	/**
	 * Queue an event.
	 *
	 * @param array $event The event to queue.
	 * @return void
	 */
	private static function queue_event( $event ) {
		$queue   = get_option( self::OPTION_QUEUE, array() );
		$queue[] = $event;

		// Trim queue if too large.
		if ( count( $queue ) > self::MAX_QUEUE_SIZE ) {
			$queue = array_slice( $queue, -self::MAX_QUEUE_SIZE );
		}

		update_option( self::OPTION_QUEUE, $queue, false );
	}

	/**
	 * Get events from queue.
	 *
	 * @param int $since_seq Get events after this sequence number.
	 * @param int $limit     Maximum events to return.
	 * @return array Array of events.
	 */
	public static function get_events( $since_seq = 0, $limit = 100 ) {
		$queue  = get_option( self::OPTION_QUEUE, array() );
		$events = array();

		foreach ( $queue as $event ) {
			if ( $event['seq'] > $since_seq ) {
				$events[] = $event;

				if ( count( $events ) >= $limit ) {
					break;
				}
			}
		}

		return $events;
	}

	/**
	 * Create commit operation for a record.
	 *
	 * @param string $action     The action (create, update, delete).
	 * @param string $collection The collection.
	 * @param string $rkey       The record key.
	 * @param string $cid        The record CID (null for delete).
	 * @return array The operation.
	 */
	public static function create_op( $action, $collection, $rkey, $cid = null ) {
		$op = array(
			'action'     => $action,
			'path'       => $collection . '/' . $rkey,
		);

		if ( 'delete' !== $action && $cid ) {
			$op['cid'] = array( '$link' => $cid );
		}

		return $op;
	}

	/**
	 * Encode events as CBOR for WebSocket.
	 *
	 * @param array $events The events.
	 * @return string CBOR-encoded frames.
	 */
	public static function encode_events( $events ) {
		$frames = '';

		foreach ( $events as $event ) {
			// Each event is a frame: header + body.
			$header = array(
				'op' => 1, // Regular message.
				't'  => $event['$type'],
			);

			$header_cbor = CBOR::encode( $header );
			$body_cbor   = CBOR::encode( $event );

			// Frame format: varint(header_len) + header + body.
			$frames .= self::encode_varint( strlen( $header_cbor ) );
			$frames .= $header_cbor;
			$frames .= $body_cbor;
		}

		return $frames;
	}

	/**
	 * Encode a varint.
	 *
	 * @param int $value The value.
	 * @return string Varint bytes.
	 */
	private static function encode_varint( $value ) {
		$bytes = '';

		while ( $value >= 0x80 ) {
			$bytes .= chr( ( $value & 0x7F ) | 0x80 );
			$value >>= 7;
		}

		$bytes .= chr( $value );

		return $bytes;
	}

	/**
	 * Clear the event queue.
	 *
	 * @return void
	 */
	public static function clear_queue() {
		update_option( self::OPTION_QUEUE, array(), false );
	}
}
