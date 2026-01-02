<?php
/**
 * AT Protocol Repository management.
 *
 * A repository is a signed collection of records for a single account.
 * It uses a Merkle Search Tree (MST) for efficient storage and sync.
 *
 * @package ATProto
 */

namespace ATProto\Repository;

use ATProto\ATProto;
use ATProto\Identity\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Repository class.
 */
class Repository {
	/**
	 * Option name for repository state.
	 *
	 * @var string
	 */
	const OPTION_STATE = 'atproto_repo_state';

	/**
	 * Option name for commits.
	 *
	 * @var string
	 */
	const OPTION_COMMITS = 'atproto_repo_commits';

	/**
	 * Get the repository DID.
	 *
	 * @return string The DID.
	 */
	public static function get_did() {
		return ATProto::get_did();
	}

	/**
	 * Get the repository handle.
	 *
	 * @return string The handle.
	 */
	public static function get_handle() {
		return ATProto::get_handle();
	}

	/**
	 * Get the current revision.
	 *
	 * @return string The current rev (TID).
	 */
	public static function get_rev() {
		$state = self::get_state();
		return $state['rev'] ?? '';
	}

	/**
	 * Get the current root CID.
	 *
	 * @return string The root CID.
	 */
	public static function get_root() {
		$state = self::get_state();
		return $state['root'] ?? '';
	}

	/**
	 * Get the repository state.
	 *
	 * @return array The state array.
	 */
	public static function get_state() {
		$state = get_option( self::OPTION_STATE, array() );

		if ( empty( $state ) ) {
			$state = self::initialize();
		}

		return $state;
	}

	/**
	 * Initialize a new repository.
	 *
	 * @return array The initial state.
	 */
	public static function initialize() {
		// Create empty MST.
		$mst_root = MST::create_empty();

		// Generate first revision.
		$rev = TID::generate();

		// Create initial commit.
		$commit = Commit::create( $mst_root, $rev, '' );

		$state = array(
			'rev'    => $rev,
			'root'   => $mst_root,
			'commit' => $commit['cid'],
		);

		update_option( self::OPTION_STATE, $state, false );

		// Store the commit.
		self::store_commit( $commit );

		return $state;
	}

	/**
	 * Create a new record in the repository.
	 *
	 * @param string $collection The collection NSID.
	 * @param array  $record     The record data.
	 * @param string $rkey       Optional specific record key.
	 * @return array The created record info (uri, cid).
	 */
	public static function create_record( $collection, $record, $rkey = '' ) {
		if ( empty( $rkey ) ) {
			$rkey = TID::generate();
		}

		// Ensure $type is set.
		if ( ! isset( $record['$type'] ) ) {
			$record['$type'] = $collection;
		}

		// Compute CID for the record.
		$record_cid = CID::from_cbor( $record );

		// Build the MST key (collection/rkey).
		$key = $collection . '/' . $rkey;

		// Update MST.
		$state    = self::get_state();
		$old_root = $state['root'];
		$new_root = MST::insert( $old_root, $key, $record_cid );

		// Create new commit.
		$rev    = TID::generate();
		$commit = Commit::create( $new_root, $rev, $state['commit'] );

		// Update state.
		$state = array(
			'rev'    => $rev,
			'root'   => $new_root,
			'commit' => $commit['cid'],
		);

		update_option( self::OPTION_STATE, $state, false );

		// Store commit.
		self::store_commit( $commit );

		// Store record data.
		self::store_record_data( $key, $record, $record_cid );

		$did = self::get_did();

		return array(
			'uri' => "at://{$did}/{$collection}/{$rkey}",
			'cid' => $record_cid,
		);
	}

	/**
	 * Update a record in the repository.
	 *
	 * @param string $collection The collection NSID.
	 * @param string $rkey       The record key.
	 * @param array  $record     The new record data.
	 * @return array The updated record info (uri, cid).
	 */
	public static function put_record( $collection, $rkey, $record ) {
		// Same as create, but key already exists.
		return self::create_record( $collection, $record, $rkey );
	}

	/**
	 * Delete a record from the repository.
	 *
	 * @param string $collection The collection NSID.
	 * @param string $rkey       The record key.
	 * @return bool True on success.
	 */
	public static function delete_record( $collection, $rkey ) {
		$key = $collection . '/' . $rkey;

		// Update MST.
		$state    = self::get_state();
		$old_root = $state['root'];
		$new_root = MST::delete( $old_root, $key );

		if ( $new_root === $old_root ) {
			// Key didn't exist.
			return false;
		}

		// Create new commit.
		$rev    = TID::generate();
		$commit = Commit::create( $new_root, $rev, $state['commit'] );

		// Update state.
		$state = array(
			'rev'    => $rev,
			'root'   => $new_root,
			'commit' => $commit['cid'],
		);

		update_option( self::OPTION_STATE, $state, false );

		// Store commit.
		self::store_commit( $commit );

		// Delete record data.
		self::delete_record_data( $key );

		return true;
	}

	/**
	 * Get a record from the repository.
	 *
	 * @param string $collection The collection NSID.
	 * @param string $rkey       The record key.
	 * @return array|null The record or null.
	 */
	public static function get_record( $collection, $rkey ) {
		$key  = $collection . '/' . $rkey;
		$data = self::get_record_data( $key );

		if ( ! $data ) {
			return null;
		}

		return array(
			'uri'   => 'at://' . self::get_did() . '/' . $collection . '/' . $rkey,
			'cid'   => $data['cid'],
			'value' => $data['record'],
		);
	}

	/**
	 * List records in a collection.
	 *
	 * @param string $collection The collection NSID.
	 * @param int    $limit      Maximum records.
	 * @param string $cursor     Pagination cursor.
	 * @param bool   $reverse    Reverse order.
	 * @return array Array with records and cursor.
	 */
	public static function list_records( $collection, $limit = 50, $cursor = '', $reverse = false ) {
		// Get all records for collection from MST.
		$state   = self::get_state();
		$prefix  = $collection . '/';
		$entries = MST::list_entries( $state['root'], $prefix, $limit + 1, $cursor, $reverse );

		$records     = array();
		$next_cursor = '';

		foreach ( $entries as $index => $entry ) {
			if ( count( $records ) >= $limit ) {
				$next_cursor = $entry['key'];
				break;
			}

			$data = self::get_record_data( $entry['key'] );
			if ( $data ) {
				$parts     = explode( '/', $entry['key'], 2 );
				$rkey      = $parts[1] ?? '';
				$records[] = array(
					'uri'   => 'at://' . self::get_did() . '/' . $entry['key'],
					'cid'   => $entry['cid'],
					'value' => $data['record'],
				);
			}
		}

		return array(
			'records' => $records,
			'cursor'  => $next_cursor,
		);
	}

	/**
	 * Get the signed commit object.
	 *
	 * @return array The current commit.
	 */
	public static function get_commit() {
		$state   = self::get_state();
		$commits = get_option( self::OPTION_COMMITS, array() );

		return $commits[ $state['commit'] ] ?? null;
	}

	/**
	 * Store a commit.
	 *
	 * @param array $commit The commit object.
	 * @return void
	 */
	private static function store_commit( $commit ) {
		$commits = get_option( self::OPTION_COMMITS, array() );

		// Keep last 100 commits.
		if ( count( $commits ) > 100 ) {
			$commits = array_slice( $commits, -100, null, true );
		}

		$commits[ $commit['cid'] ] = $commit;
		update_option( self::OPTION_COMMITS, $commits, false );
	}

	/**
	 * Store record data.
	 *
	 * @param string $key    The MST key.
	 * @param array  $record The record data.
	 * @param string $cid    The record CID.
	 * @return void
	 */
	private static function store_record_data( $key, $record, $cid ) {
		$records         = get_option( 'atproto_records', array() );
		$records[ $key ] = array(
			'record' => $record,
			'cid'    => $cid,
		);
		update_option( 'atproto_records', $records, false );
	}

	/**
	 * Get record data.
	 *
	 * @param string $key The MST key.
	 * @return array|null The record data or null.
	 */
	private static function get_record_data( $key ) {
		$records = get_option( 'atproto_records', array() );
		return $records[ $key ] ?? null;
	}

	/**
	 * Delete record data.
	 *
	 * @param string $key The MST key.
	 * @return void
	 */
	private static function delete_record_data( $key ) {
		$records = get_option( 'atproto_records', array() );
		unset( $records[ $key ] );
		update_option( 'atproto_records', $records, false );
	}

	/**
	 * Describe the repository.
	 *
	 * @return array Repository description.
	 */
	public static function describe() {
		$collections = array(
			'app.bsky.feed.post',
			'app.bsky.feed.like',
			'app.bsky.feed.repost',
			'app.bsky.graph.follow',
		);

		/**
		 * Filter repository collections.
		 *
		 * @param array $collections The collections.
		 */
		$collections = apply_filters( 'atproto_repo_collections', $collections );

		return array(
			'handle'          => self::get_handle(),
			'did'             => self::get_did(),
			'collections'     => $collections,
			'handleIsCorrect' => true,
		);
	}

	/**
	 * Export repository as CAR (Content Addressable aRchive) file.
	 *
	 * @return string CAR file bytes.
	 */
	public static function export_car() {
		// CAR v1 format.
		$state = self::get_state();

		// Build header.
		$header = array(
			'version' => 1,
			'roots'   => array(
				array( '$link' => $state['commit'] ),
			),
		);

		$header_cbor = CBOR::encode( $header );

		// CAR format: varint(header_len) + header + blocks.
		$car = self::encode_varint( strlen( $header_cbor ) ) . $header_cbor;

		// Add commit block.
		$commit = self::get_commit();
		if ( $commit ) {
			$commit_data = $commit['data'] ?? CBOR::encode( $commit['object'] );
			$car        .= self::encode_car_block( $state['commit'], $commit_data );
		}

		// Add MST blocks.
		$mst_blocks = MST::get_all_blocks( $state['root'] );
		foreach ( $mst_blocks as $cid => $data ) {
			$car .= self::encode_car_block( $cid, $data );
		}

		// Add record blocks.
		$records = get_option( 'atproto_records', array() );
		foreach ( $records as $key => $data ) {
			$record_cbor = CBOR::encode( $data['record'] );
			$car        .= self::encode_car_block( $data['cid'], $record_cbor );
		}

		return $car;
	}

	/**
	 * Encode a CAR block.
	 *
	 * @param string $cid  The CID.
	 * @param string $data The block data.
	 * @return string CAR block bytes.
	 */
	private static function encode_car_block( $cid, $data ) {
		$cid_bytes = CID::to_bytes( $cid );

		if ( false === $cid_bytes ) {
			return '';
		}

		$block = $cid_bytes . $data;
		return self::encode_varint( strlen( $block ) ) . $block;
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
}
