<?php
/**
 * Merkle Search Tree (MST) for AT Protocol.
 *
 * MST is a deterministic search tree used for repository data structure.
 * Keys are sorted lexicographically, and the tree is content-addressed.
 *
 * This is a simplified implementation storing tree state in WordPress options.
 *
 * @package ATProto
 */

namespace ATProto\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * MST class for Merkle Search Tree operations.
 */
class MST {
	/**
	 * Option name for MST nodes.
	 *
	 * @var string
	 */
	const OPTION_NODES = 'atproto_mst_nodes';

	/**
	 * Option name for MST entries (key -> cid mapping).
	 *
	 * @var string
	 */
	const OPTION_ENTRIES = 'atproto_mst_entries';

	/**
	 * Fanout (max entries per node).
	 *
	 * @var int
	 */
	const FANOUT = 32;

	/**
	 * Create an empty MST.
	 *
	 * @return string The root CID of the empty tree.
	 */
	public static function create_empty() {
		$node = array(
			'e' => array(), // Entries.
			'l' => null,    // Left subtree.
		);

		$cid = self::store_node( $node );

		return $cid;
	}

	/**
	 * Insert a key-value pair into the MST.
	 *
	 * @param string $root_cid The current root CID.
	 * @param string $key      The key to insert.
	 * @param string $value    The value CID.
	 * @return string The new root CID.
	 */
	public static function insert( $root_cid, $key, $value ) {
		// Store the entry in our flat index.
		$entries         = get_option( self::OPTION_ENTRIES, array() );
		$entries[ $key ] = $value;
		update_option( self::OPTION_ENTRIES, $entries, false );

		// Rebuild tree from entries.
		return self::build_tree( $entries );
	}

	/**
	 * Delete a key from the MST.
	 *
	 * @param string $root_cid The current root CID.
	 * @param string $key      The key to delete.
	 * @return string The new root CID.
	 */
	public static function delete( $root_cid, $key ) {
		$entries = get_option( self::OPTION_ENTRIES, array() );

		if ( ! isset( $entries[ $key ] ) ) {
			return $root_cid;
		}

		unset( $entries[ $key ] );
		update_option( self::OPTION_ENTRIES, $entries, false );

		return self::build_tree( $entries );
	}

	/**
	 * Get a value from the MST.
	 *
	 * @param string $root_cid The root CID.
	 * @param string $key      The key to look up.
	 * @return string|null The value CID or null.
	 */
	public static function get( $root_cid, $key ) {
		$entries = get_option( self::OPTION_ENTRIES, array() );
		return $entries[ $key ] ?? null;
	}

	/**
	 * List entries in the MST with optional prefix filter.
	 *
	 * @param string $root_cid The root CID.
	 * @param string $prefix   Optional key prefix.
	 * @param int    $limit    Maximum entries.
	 * @param string $cursor   Start after this key.
	 * @param bool   $reverse  Reverse order.
	 * @return array Array of entries.
	 */
	public static function list_entries( $root_cid, $prefix = '', $limit = 100, $cursor = '', $reverse = false ) {
		$entries = get_option( self::OPTION_ENTRIES, array() );

		// Filter by prefix.
		if ( ! empty( $prefix ) ) {
			$entries = array_filter(
				$entries,
				function ( $key ) use ( $prefix ) {
					return 0 === strpos( $key, $prefix );
				},
				ARRAY_FILTER_USE_KEY
			);
		}

		// Sort keys.
		$keys = array_keys( $entries );
		sort( $keys, SORT_STRING );

		if ( $reverse ) {
			$keys = array_reverse( $keys );
		}

		// Apply cursor.
		if ( ! empty( $cursor ) ) {
			$found = false;
			$keys  = array_filter(
				$keys,
				function ( $key ) use ( $cursor, &$found, $reverse ) {
					if ( $found ) {
						return true;
					}
					if ( $key === $cursor ) {
						$found = true;
						return false;
					}
					if ( ! $reverse && $key > $cursor ) {
						$found = true;
						return true;
					}
					if ( $reverse && $key < $cursor ) {
						$found = true;
						return true;
					}
					return false;
				}
			);
			$keys = array_values( $keys );
		}

		// Apply limit.
		$keys = array_slice( $keys, 0, $limit );

		// Build result.
		$result = array();
		foreach ( $keys as $key ) {
			$result[] = array(
				'key' => $key,
				'cid' => $entries[ $key ],
			);
		}

		return $result;
	}

	/**
	 * Get all keys in the MST.
	 *
	 * @param string $root_cid The root CID.
	 * @return array Array of keys.
	 */
	public static function list_keys( $root_cid ) {
		$entries = get_option( self::OPTION_ENTRIES, array() );
		$keys    = array_keys( $entries );
		sort( $keys, SORT_STRING );
		return $keys;
	}

	/**
	 * Build tree structure from entries.
	 *
	 * @param array $entries The entries array.
	 * @return string The root CID.
	 */
	private static function build_tree( $entries ) {
		if ( empty( $entries ) ) {
			return self::create_empty();
		}

		// Sort entries by key.
		ksort( $entries, SORT_STRING );

		// Build a simple tree structure.
		// For simplicity, we create a flat node with all entries.
		// A full implementation would create a proper tree based on key prefixes.

		$node_entries = array();

		foreach ( $entries as $key => $cid ) {
			$node_entries[] = array(
				'k' => $key,
				'v' => array( '$link' => $cid ),
			);
		}

		// Split into chunks if too many entries.
		if ( count( $node_entries ) > self::FANOUT ) {
			return self::build_tree_recursive( $node_entries, 0 );
		}

		$node = array(
			'e' => $node_entries,
			'l' => null,
		);

		return self::store_node( $node );
	}

	/**
	 * Build tree recursively for large entry sets.
	 *
	 * @param array $entries The entries.
	 * @param int   $depth   Current depth.
	 * @return string The node CID.
	 */
	private static function build_tree_recursive( $entries, $depth ) {
		if ( count( $entries ) <= self::FANOUT ) {
			$node = array(
				'e' => $entries,
				'l' => null,
			);
			return self::store_node( $node );
		}

		// Split entries into chunks.
		$chunks = array_chunk( $entries, self::FANOUT );
		$result = array();

		foreach ( $chunks as $chunk ) {
			$child_cid = self::build_tree_recursive( $chunk, $depth + 1 );
			$result[]  = array(
				'k' => $chunk[0]['k'],
				'p' => $depth,
				't' => array( '$link' => $child_cid ),
			);
		}

		$node = array(
			'e' => $result,
			'l' => null,
		);

		return self::store_node( $node );
	}

	/**
	 * Store a node and return its CID.
	 *
	 * @param array $node The node data.
	 * @return string The node CID.
	 */
	private static function store_node( $node ) {
		$cbor = CBOR::encode( $node );
		$cid  = CID::from_bytes( $cbor );

		$nodes         = get_option( self::OPTION_NODES, array() );
		$nodes[ $cid ] = array(
			'node' => $node,
			'data' => base64_encode( $cbor ),
		);
		update_option( self::OPTION_NODES, $nodes, false );

		return $cid;
	}

	/**
	 * Get a node by CID.
	 *
	 * @param string $cid The node CID.
	 * @return array|null The node or null.
	 */
	public static function get_node( $cid ) {
		$nodes = get_option( self::OPTION_NODES, array() );
		return $nodes[ $cid ]['node'] ?? null;
	}

	/**
	 * Get all MST blocks for CAR export.
	 *
	 * @param string $root_cid The root CID.
	 * @return array Array of CID => data.
	 */
	public static function get_all_blocks( $root_cid ) {
		$nodes  = get_option( self::OPTION_NODES, array() );
		$blocks = array();

		foreach ( $nodes as $cid => $data ) {
			$blocks[ $cid ] = base64_decode( $data['data'], true );
		}

		return $blocks;
	}

	/**
	 * Count entries in the MST.
	 *
	 * @param string $root_cid The root CID.
	 * @return int The entry count.
	 */
	public static function count( $root_cid ) {
		$entries = get_option( self::OPTION_ENTRIES, array() );
		return count( $entries );
	}

	/**
	 * Get the depth of the tree (for tree height key calculation).
	 *
	 * @param string $key The key.
	 * @return int The depth.
	 */
	public static function leading_zeros( $key ) {
		$hash = hash( 'sha256', $key, true );

		$zeros = 0;
		for ( $i = 0; $i < strlen( $hash ); $i++ ) {
			$byte = ord( $hash[ $i ] );

			if ( 0 === $byte ) {
				$zeros += 8;
				continue;
			}

			// Count leading zeros in this byte.
			for ( $j = 7; $j >= 0; $j-- ) {
				if ( $byte & ( 1 << $j ) ) {
					return $zeros;
				}
				$zeros++;
			}
		}

		return $zeros;
	}
}
