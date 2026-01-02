<?php
/**
 * Blob storage and management.
 *
 * @package ATProto
 */

namespace ATProto\Collection;

use ATProto\Repository\CID;
use ATProto\Transformer\Attachment;

defined( 'ABSPATH' ) || exit;

/**
 * Blobs collection class.
 */
class Blobs {
	/**
	 * Option name for blob index.
	 *
	 * @var string
	 */
	const OPTION_INDEX = 'atproto_blob_index';

	/**
	 * Upload a blob from file.
	 *
	 * @param string $file_path The file path.
	 * @param string $mime_type The MIME type.
	 * @return array|false The blob reference or false.
	 */
	public static function upload_from_file( $file_path, $mime_type = '' ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		$content = file_get_contents( $file_path );
		if ( ! $content ) {
			return false;
		}

		if ( empty( $mime_type ) ) {
			$mime_type = mime_content_type( $file_path );
		}

		return self::store( $content, $mime_type );
	}

	/**
	 * Upload a blob from binary data.
	 *
	 * @param string $data      The binary data.
	 * @param string $mime_type The MIME type.
	 * @return array|false The blob reference or false.
	 */
	public static function upload( $data, $mime_type ) {
		return self::store( $data, $mime_type );
	}

	/**
	 * Store blob data.
	 *
	 * @param string $data      The binary data.
	 * @param string $mime_type The MIME type.
	 * @return array The blob reference.
	 */
	private static function store( $data, $mime_type ) {
		$cid  = CID::from_bytes( $data, CID::CODEC_RAW );
		$size = strlen( $data );

		// Store in uploads directory.
		$upload_dir = wp_upload_dir();
		$blob_dir   = $upload_dir['basedir'] . '/atproto-blobs';

		if ( ! file_exists( $blob_dir ) ) {
			wp_mkdir_p( $blob_dir );

			// Add .htaccess to prevent direct access.
			file_put_contents( $blob_dir . '/.htaccess', 'Deny from all' );
		}

		// Use CID as filename.
		$file_path = $blob_dir . '/' . $cid;
		file_put_contents( $file_path, $data );

		// Update index.
		$index         = get_option( self::OPTION_INDEX, array() );
		$index[ $cid ] = array(
			'mimeType'  => $mime_type,
			'size'      => $size,
			'path'      => $file_path,
			'createdAt' => current_time( 'mysql', true ),
		);
		update_option( self::OPTION_INDEX, $index, false );

		return array(
			'$type'    => 'blob',
			'ref'      => array( '$link' => $cid ),
			'mimeType' => $mime_type,
			'size'     => $size,
		);
	}

	/**
	 * Get blob data by CID.
	 *
	 * @param string $cid The blob CID.
	 * @return array|null Array with 'data', 'mimeType', 'size' or null.
	 */
	public static function get( $cid ) {
		// Check index.
		$index = get_option( self::OPTION_INDEX, array() );

		if ( isset( $index[ $cid ] ) ) {
			$info = $index[ $cid ];

			if ( file_exists( $info['path'] ) ) {
				return array(
					'data'     => file_get_contents( $info['path'] ),
					'mimeType' => $info['mimeType'],
					'size'     => $info['size'],
				);
			}
		}

		// Try to find in WordPress attachments.
		return Attachment::get_blob_by_cid( $cid );
	}

	/**
	 * Check if a blob exists.
	 *
	 * @param string $cid The blob CID.
	 * @return bool True if exists.
	 */
	public static function exists( $cid ) {
		$index = get_option( self::OPTION_INDEX, array() );

		if ( isset( $index[ $cid ] ) && file_exists( $index[ $cid ]['path'] ) ) {
			return true;
		}

		// Check attachments.
		global $wpdb;
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				Attachment::META_BLOB_CID,
				$cid
			)
		);

		return ! empty( $exists );
	}

	/**
	 * Delete a blob.
	 *
	 * @param string $cid The blob CID.
	 * @return bool True on success.
	 */
	public static function delete( $cid ) {
		$index = get_option( self::OPTION_INDEX, array() );

		if ( isset( $index[ $cid ] ) ) {
			if ( file_exists( $index[ $cid ]['path'] ) ) {
				unlink( $index[ $cid ]['path'] );
			}

			unset( $index[ $cid ] );
			update_option( self::OPTION_INDEX, $index, false );

			return true;
		}

		return false;
	}

	/**
	 * List all blobs.
	 *
	 * @param int    $limit  Maximum blobs.
	 * @param string $cursor Pagination cursor (CID).
	 * @return array Array with 'blobs' and 'cursor'.
	 */
	public static function list_all( $limit = 50, $cursor = '' ) {
		$index = get_option( self::OPTION_INDEX, array() );
		$cids  = array_keys( $index );
		sort( $cids );

		// Apply cursor.
		if ( ! empty( $cursor ) ) {
			$pos  = array_search( $cursor, $cids, true );
			$cids = array_slice( $cids, $pos + 1 );
		}

		// Apply limit.
		$next_cursor = '';
		if ( count( $cids ) > $limit ) {
			$cids        = array_slice( $cids, 0, $limit );
			$next_cursor = end( $cids );
		}

		$blobs = array();
		foreach ( $cids as $cid ) {
			$blobs[] = array(
				'cid'      => $cid,
				'mimeType' => $index[ $cid ]['mimeType'],
				'size'     => $index[ $cid ]['size'],
			);
		}

		return array(
			'blobs'  => $blobs,
			'cursor' => $next_cursor,
		);
	}

	/**
	 * Clean up orphaned blobs.
	 *
	 * @return int Number of blobs deleted.
	 */
	public static function cleanup() {
		// This would scan for blobs not referenced by any record.
		// For now, just return 0.
		return 0;
	}

	/**
	 * Get total blob storage size.
	 *
	 * @return int Total size in bytes.
	 */
	public static function get_total_size() {
		$index = get_option( self::OPTION_INDEX, array() );
		$total = 0;

		foreach ( $index as $info ) {
			$total += $info['size'];
		}

		return $total;
	}
}
