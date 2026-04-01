<?php
/**
 * AT Protocol Repository management.
 *
 * A repository is a signed collection of records for a single account.
 * WordPress posts are the single source of truth. The MST, commits,
 * and CAR files are built on-demand from WordPress data.
 *
 * @package ATProto
 */

namespace ATProto\Repository;

use ATProto\ATProto;
use ATProto\Collection\Firehose;

defined( 'ABSPATH' ) || exit;

/**
 * Repository class.
 */
class Repository {
	/**
	 * Option name for cached repository state (rev, root, commit CIDs).
	 *
	 * @var string
	 */
	const OPTION_STATE = 'atproto_repo_state';

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
	 * Get the cached repository state.
	 *
	 * Ensures a valid commit CID exists. If the cached state is missing
	 * or has an empty commit, triggers a full rebuild so that sync
	 * endpoints (getLatestCommit, listRepos) always return valid data.
	 *
	 * @return array The state array.
	 */
	public static function get_state() {
		$state = get_option( self::OPTION_STATE, array() );

		if ( empty( $state ) || empty( $state['commit'] ) ) {
			$build = self::rebuild_commit();
			$state = $build['state'];
		}

		return $state;
	}

	/**
	 * Initialize repository state.
	 *
	 * @return array The initial state.
	 */
	public static function initialize() {
		$build = self::rebuild_commit();
		return $build['state'];
	}

	/**
	 * Create a new record in the repository.
	 *
	 * Computes the record CID and emits a firehose event.
	 * The record data itself lives in WordPress (posts, options, etc.).
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
		$did        = self::get_did();

		// Notify the network.
		self::notify_change( 'create', $collection, $rkey, $record_cid );

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
		// Notify the network.
		self::notify_change( 'delete', $collection, $rkey );

		return true;
	}

	/**
	 * Rebuild the repository commit from current WordPress data.
	 *
	 * Collects all records, builds the MST, and creates a signed commit.
	 * Caches the result in options so that sync endpoints and export_car()
	 * can reuse it without redundant work.
	 *
	 * @param string $rev Optional specific revision to use.
	 * @return array Build result with 'state', 'tree', and 'record_blocks'.
	 */
	public static function rebuild_commit( $rev = '' ) {
		$collections = array(
			'app.bsky.feed.post',
			'app.bsky.actor.profile',
			'site.standard.publication',
			'site.standard.document',
		);

		/** Filter the collections to include in the repository. */
		$collections = apply_filters( 'atproto_rebuild_collections', $collections );

		// Collect all records from WordPress.
		$mst_entries   = array();
		$record_blocks = array();

		foreach ( $collections as $collection ) {
			$result = Record::list_records( $collection, 10000 );

			foreach ( $result['records'] as $record ) {
				$value       = $record['value'];
				$record_cbor = CBOR::encode( $value );
				$record_cid  = CID::from_bytes( $record_cbor );

				$key                          = $collection . '/' . $record['rkey'];
				$mst_entries[ $key ]          = $record_cid;
				$record_blocks[ $record_cid ] = $record_cbor;
			}
		}

		// Build MST in-memory.
		$tree = MST::build_from_entries( $mst_entries );

		// Reuse existing commit if MST root and rev haven't changed.
		$state = get_option( self::OPTION_STATE, array() );

		$rev_unchanged  = empty( $rev ) || $rev === ( $state['rev'] ?? '' );
		$root_unchanged = ! empty( $state['root'] ) && $state['root'] === $tree['root'];
		$has_commit     = ! empty( $state['commit_data'] );

		if ( $rev_unchanged && $root_unchanged && $has_commit ) {
			return array(
				'state'         => $state,
				'tree'          => $tree,
				'record_blocks' => $record_blocks,
			);
		}

		// Create new signed commit.
		if ( empty( $rev ) ) {
			$rev = ! empty( $state['rev'] ) ? $state['rev'] : TID::generate();
		}

		$commit = Commit::create( $tree['root'], $rev, $state['commit'] ?? '' );

		$new_state = array(
			'rev'         => $rev,
			'root'        => $tree['root'],
			'commit'      => $commit['cid'],
			'commit_data' => base64_encode( $commit['data'] ),
		);
		update_option( self::OPTION_STATE, $new_state, false );

		return array(
			'state'         => $new_state,
			'tree'          => $tree,
			'record_blocks' => $record_blocks,
		);
	}

	/**
	 * Notify the network of a repository change.
	 *
	 * Bumps the revision, rebuilds the commit, and emits a firehose event
	 * with the correct commit CID.
	 *
	 * @param string      $action     The action (create, update, delete).
	 * @param string      $collection The collection NSID.
	 * @param string      $rkey       The record key.
	 * @param string|null $cid        The record CID (null for delete).
	 * @return void
	 */
	public static function notify_change( $action, $collection, $rkey, $cid = null ) {
		// Rebuild commit with a new revision.
		$rev   = TID::generate();
		$build = self::rebuild_commit( $rev );

		// Build a minimal CAR file containing the commit, MST nodes,
		// and the affected record so the relay can index inline.
		$blocks_car = self::build_blocks_car( $build, $collection, $rkey, $cid );

		// Emit firehose event with inline blocks.
		$op = Firehose::create_op( $action, $collection, $rkey, $cid );
		Firehose::emit_commit( array( $op ), $blocks_car );
	}

	/**
	 * Build a minimal CAR file for firehose commit blocks.
	 *
	 * Contains the signed commit, all MST nodes, and the affected record.
	 *
	 * @param array       $build      The rebuild_commit() result.
	 * @param string      $collection The affected collection.
	 * @param string      $rkey       The affected record key.
	 * @param string|null $cid        The record CID (null for deletes).
	 * @return string The CAR file bytes.
	 */
	private static function build_blocks_car( $build, $collection, $rkey, $cid = null ) {
		$state = $build['state'];
		$tree  = $build['tree'];

		$commit_cid  = $state['commit'];
		$commit_data = base64_decode( $state['commit_data'] );

		// CAR v1 header.
		$header      = array(
			'version' => 1,
			'roots'   => array(
				array( '$link' => $commit_cid ),
			),
		);
		$header_cbor = CBOR::encode( $header );
		$car         = self::encode_varint( strlen( $header_cbor ) ) . $header_cbor;

		// Add commit block.
		$car .= self::encode_car_block( $commit_cid, $commit_data );

		// Add all MST node blocks (proof path).
		foreach ( $tree['blocks'] as $block_cid => $data ) {
			$car .= self::encode_car_block( $block_cid, $data );
		}

		// Add the affected record block (skip for deletes).
		if ( $cid && isset( $build['record_blocks'][ $cid ] ) ) {
			$car .= self::encode_car_block( $cid, $build['record_blocks'][ $cid ] );
		}

		return $car;
	}

	/**
	 * Get a record from the repository.
	 *
	 * Delegates to Record class which reads from WordPress.
	 *
	 * @param string $collection The collection NSID.
	 * @param string $rkey       The record key.
	 * @return array|null The record or null.
	 */
	public static function get_record( $collection, $rkey ) {
		$record = Record::get( $collection, $rkey );

		if ( ! $record ) {
			return null;
		}

		return array(
			'uri'   => 'at://' . self::get_did() . '/' . $collection . '/' . $rkey,
			'cid'   => $record['cid'],
			'value' => $record['value'],
		);
	}

	/**
	 * List records in a collection.
	 *
	 * Delegates to Record class which reads from WordPress.
	 *
	 * @param string $collection The collection NSID.
	 * @param int    $limit      Maximum records.
	 * @param string $cursor     Pagination cursor.
	 * @param bool   $reverse    Reverse order.
	 * @return array Array with records and cursor.
	 */
	public static function list_records( $collection, $limit = 50, $cursor = '', $reverse = false ) {
		$result = Record::list_records( $collection, $limit, $cursor, $reverse );
		$did    = self::get_did();

		$records = array();

		foreach ( $result['records'] as $record ) {
			$records[] = array(
				'uri'   => 'at://' . $did . '/' . $collection . '/' . $record['rkey'],
				'cid'   => $record['cid'],
				'value' => $record['value'],
			);
		}

		return array(
			'records' => $records,
			'cursor'  => $result['cursor'],
		);
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
	 * Builds the entire repository in-memory from WordPress data.
	 * WordPress posts are the single source of truth.
	 *
	 * Idempotent: reuses the existing commit if the MST root hasn't changed.
	 *
	 * @return string CAR file bytes.
	 */
	public static function export_car() {
		$build = self::rebuild_commit();

		$state         = $build['state'];
		$tree          = $build['tree'];
		$record_blocks = $build['record_blocks'];

		$commit_cid  = $state['commit'];
		$commit_data = base64_decode( $state['commit_data'] );

		// Assemble CAR v1.
		$header = array(
			'version' => 1,
			'roots'   => array(
				array( '$link' => $commit_cid ),
			),
		);

		$header_cbor = CBOR::encode( $header );
		$car         = self::encode_varint( strlen( $header_cbor ) ) . $header_cbor;

		// Add commit block.
		$car .= self::encode_car_block( $commit_cid, $commit_data );

		// Add MST blocks.
		foreach ( $tree['blocks'] as $cid => $data ) {
			$car .= self::encode_car_block( $cid, $data );
		}

		// Add record blocks.
		foreach ( $record_blocks as $cid => $data ) {
			$car .= self::encode_car_block( $cid, $data );
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
