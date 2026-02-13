<?php
/**
 * Merkle Search Tree (MST) for AT Protocol.
 *
 * MST is a deterministic search tree used for repository data structure.
 * Keys are sorted lexicographically, and the tree is content-addressed.
 *
 * This implementation builds trees entirely in-memory from WordPress data.
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
	 * Fanout (max entries per node).
	 *
	 * @var int
	 */
	const FANOUT = 32;

	/**
	 * In-memory block storage (CID => CBOR bytes).
	 *
	 * @var array
	 */
	private $blocks = array();

	/**
	 * Build an MST from entries in-memory.
	 *
	 * @param array $entries Key => CID mapping.
	 * @return array Array with 'root' (CID string) and 'blocks' (CID => CBOR bytes).
	 */
	public static function build_from_entries( $entries ) {
		$mst  = new self();
		$root = $mst->build_tree( $entries );

		return array(
			'root'   => $root,
			'blocks' => $mst->blocks,
		);
	}

	/**
	 * Build tree structure from entries.
	 *
	 * @param array $entries The entries array (key => CID).
	 * @return string The root CID.
	 */
	private function build_tree( $entries ) {
		if ( empty( $entries ) ) {
			$node = array(
				'e' => array(),
				'l' => null,
			);
			return $this->store_node( $node );
		}

		// Sort entries by key.
		ksort( $entries, SORT_STRING );

		$node_entries = array();

		foreach ( $entries as $key => $cid ) {
			$node_entries[] = array(
				'k' => $key,
				'v' => array( '$link' => $cid ),
			);
		}

		// Split into chunks if too many entries.
		if ( count( $node_entries ) > self::FANOUT ) {
			return $this->build_tree_recursive( $node_entries, 0 );
		}

		$node = array(
			'e' => $node_entries,
			'l' => null,
		);

		return $this->store_node( $node );
	}

	/**
	 * Build tree recursively for large entry sets.
	 *
	 * @param array $entries The entries.
	 * @param int   $depth   Current depth.
	 * @return string The node CID.
	 */
	private function build_tree_recursive( $entries, $depth ) {
		if ( count( $entries ) <= self::FANOUT ) {
			$node = array(
				'e' => $entries,
				'l' => null,
			);
			return $this->store_node( $node );
		}

		// Split entries into chunks.
		$chunks = array_chunk( $entries, self::FANOUT );
		$result = array();

		foreach ( $chunks as $chunk ) {
			$child_cid = $this->build_tree_recursive( $chunk, $depth + 1 );
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

		return $this->store_node( $node );
	}

	/**
	 * Store a node in-memory and return its CID.
	 *
	 * @param array $node The node data.
	 * @return string The node CID.
	 */
	private function store_node( $node ) {
		$cbor = CBOR::encode( $node );
		$cid  = CID::from_bytes( $cbor );

		$this->blocks[ $cid ] = $cbor;

		return $cid;
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
