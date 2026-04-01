<?php
/**
 * Merkle Search Tree (MST) for AT Protocol.
 *
 * MST is a deterministic search tree used for repository data structure.
 * Keys are sorted lexicographically, and the tree is content-addressed.
 *
 * This implementation builds trees entirely in-memory from WordPress data.
 * Follows the AT Protocol MST spec with fanout 4 (2-bit pairs).
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
	 * In-memory block storage (CID => CBOR bytes).
	 *
	 * @var array
	 */
	private $blocks = array();

	/**
	 * Build an MST from entries in-memory.
	 *
	 * @param array $entries Key => CID mapping (e.g. 'app.bsky.feed.post/tid' => 'bafyrei...').
	 * @return array Array with 'root' (CID string) and 'blocks' (CID => CBOR bytes).
	 */
	public static function build_from_entries( $entries ) {
		$mst = new self();

		if ( empty( $entries ) ) {
			$root = $mst->store_node( null, array() );

			return array(
				'root'   => $root,
				'blocks' => $mst->blocks,
			);
		}

		// Sort entries by key.
		ksort( $entries, SORT_STRING );

		// Build items with layer info.
		$items = array();
		foreach ( $entries as $key => $cid ) {
			$items[] = array(
				'key'   => (string) $key,
				'cid'   => $cid,
				'layer' => self::layer_for_key( (string) $key ),
			);
		}

		$root = $mst->build_node( $items );

		return array(
			'root'   => $root,
			'blocks' => $mst->blocks,
		);
	}

	/**
	 * Build an MST node from a list of items.
	 *
	 * Items at the highest layer become entries in this node.
	 * Items between them at lower layers form subtrees.
	 *
	 * @param array $items Array of ['key' => string, 'cid' => string, 'layer' => int].
	 * @return string The node CID.
	 */
	private function build_node( $items ) {
		if ( empty( $items ) ) {
			return $this->store_node( null, array() );
		}

		// Find max layer among items.
		$max_layer = 0;
		foreach ( $items as $item ) {
			if ( $item['layer'] > $max_layer ) {
				$max_layer = $item['layer'];
			}
		}

		$left_cid    = null;
		$entries     = array();
		$left_group  = array();
		$prev_key    = '';

		foreach ( $items as $item ) {
			if ( $item['layer'] === $max_layer ) {
				// Build subtree from accumulated lower-layer items.
				$subtree_cid = null;
				if ( ! empty( $left_group ) ) {
					$subtree_cid = $this->build_node( $left_group );
					$left_group  = array();
				}

				if ( empty( $entries ) ) {
					// First entry at this layer: accumulated items become left subtree.
					$left_cid = $subtree_cid;
				} else {
					// Set right subtree pointer on previous entry.
					if ( $subtree_cid ) {
						$entries[ count( $entries ) - 1 ]['t'] = array( '$link' => $subtree_cid );
					}
				}

				// Compute prefix compression against previous key in this node.
				$p        = self::shared_prefix_len( $prev_key, $item['key'] );
				$k_suffix = substr( $item['key'], $p );

				$entries[] = array(
					'p' => $p,
					'k' => array( '$bytes' => base64_encode( $k_suffix ) ),
					'v' => array( '$link' => $item['cid'] ),
					't' => null,
				);

				$prev_key = $item['key'];
			} else {
				// Item belongs in a subtree.
				$left_group[] = $item;
			}
		}

		// Remaining lower-layer items become right subtree of last entry.
		if ( ! empty( $left_group ) ) {
			$subtree_cid = $this->build_node( $left_group );
			$entries[ count( $entries ) - 1 ]['t'] = array( '$link' => $subtree_cid );
		}

		return $this->store_node( $left_cid, $entries );
	}

	/**
	 * Store an MST node in-memory and return its CID.
	 *
	 * @param string|null $left_cid The left subtree CID or null.
	 * @param array       $entries  The node entries.
	 * @return string The node CID.
	 */
	private function store_node( $left_cid, $entries ) {
		$node = array(
			'l' => $left_cid ? array( '$link' => $left_cid ) : null,
			'e' => $entries,
		);

		$cbor = CBOR::encode( $node );
		$cid  = CID::from_bytes( $cbor );

		$this->blocks[ $cid ] = $cbor;

		return $cid;
	}

	/**
	 * Count shared prefix bytes between two keys.
	 *
	 * @param string $a First key.
	 * @param string $b Second key.
	 * @return int Number of shared prefix bytes.
	 */
	private static function shared_prefix_len( $a, $b ) {
		$len = min( strlen( $a ), strlen( $b ) );

		for ( $i = 0; $i < $len; $i++ ) {
			if ( $a[ $i ] !== $b[ $i ] ) {
				return $i;
			}
		}

		return $len;
	}

	/**
	 * Compute the MST layer for a key.
	 *
	 * Counts leading zero 2-bit pairs in the SHA-256 hash of the key,
	 * giving a fanout of 4 per the AT Protocol specification.
	 *
	 * @param string $key The record key (e.g. 'app.bsky.feed.post/3jui7kd54zh2y').
	 * @return int The layer (depth) for this key.
	 */
	public static function layer_for_key( $key ) {
		$hash    = hash( 'sha256', $key, true );
		$leading = 0;

		for ( $i = 0, $len = strlen( $hash ); $i < $len; $i++ ) {
			$byte = ord( $hash[ $i ] );

			if ( $byte < 64 ) {
				++$leading; // Top 2 bits are 00.
			}
			if ( $byte < 16 ) {
				++$leading; // Top 4 bits are 0000.
			}
			if ( $byte < 4 ) {
				++$leading; // Top 6 bits are 000000.
			}
			if ( 0 === $byte ) {
				++$leading; // All 8 bits are 00000000.
			} else {
				break;
			}
		}

		return $leading;
	}

	/**
	 * Alias for layer_for_key for backward compatibility.
	 *
	 * @param string $key The key.
	 * @return int The layer.
	 */
	public static function leading_zeros( $key ) {
		return self::layer_for_key( $key );
	}
}
