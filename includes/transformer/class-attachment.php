<?php
/**
 * Attachment Transformer - handles WordPress attachments as AT Protocol blobs.
 *
 * @package ATProto
 */

namespace ATProto\Transformer;

use ATProto\Repository\CID;

defined( 'ABSPATH' ) || exit;

/**
 * Attachment transformer class.
 */
class Attachment extends Base {
	/**
	 * The WordPress attachment post.
	 *
	 * @var \WP_Post
	 */
	protected $object;

	/**
	 * Meta key for blob reference.
	 *
	 * @var string
	 */
	const META_BLOB_REF = '_atproto_blob_ref';

	/**
	 * Meta key for blob CID.
	 *
	 * @var string
	 */
	const META_BLOB_CID = '_atproto_blob_cid';

	/**
	 * Supported image MIME types.
	 *
	 * @var array
	 */
	const SUPPORTED_IMAGE_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
	);

	/**
	 * Maximum image size in bytes (1MB).
	 *
	 * @var int
	 */
	const MAX_IMAGE_SIZE = 1000000;

	/**
	 * Transform the attachment to a blob reference.
	 *
	 * @return array|null The blob reference or null.
	 */
	public function transform() {
		$file_path = get_attached_file( $this->object->ID );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return null;
		}

		$mime_type = get_post_mime_type( $this->object );
		$file_size = filesize( $file_path );

		// Check if it's a supported image type.
		if ( ! in_array( $mime_type, self::SUPPORTED_IMAGE_TYPES, true ) ) {
			return null;
		}

		// Check file size.
		if ( $file_size > self::MAX_IMAGE_SIZE ) {
			// Try to get a smaller version.
			$file_path = $this->get_resized_image();
			if ( ! $file_path ) {
				return null;
			}
			$file_size = filesize( $file_path );
		}

		// Read file content.
		$content = file_get_contents( $file_path );
		if ( ! $content ) {
			return null;
		}

		// Compute CID.
		$cid = CID::from_bytes( $content, CID::CODEC_RAW );

		// Store blob reference.
		$blob_ref = array(
			'$type'    => 'blob',
			'ref'      => array( '$link' => $cid ),
			'mimeType' => $mime_type,
			'size'     => $file_size,
		);

		// Cache the blob ref.
		update_post_meta( $this->object->ID, self::META_BLOB_REF, $blob_ref );
		update_post_meta( $this->object->ID, self::META_BLOB_CID, $cid );

		return $blob_ref;
	}

	/**
	 * Get the collection NSID (not applicable for blobs).
	 *
	 * @return string
	 */
	public function get_collection() {
		return '';
	}

	/**
	 * Get the record key (not applicable for blobs).
	 *
	 * @return string
	 */
	public function get_rkey() {
		return '';
	}

	/**
	 * Get a resized version of the image.
	 *
	 * @return string|null The resized image path or null.
	 */
	protected function get_resized_image() {
		// Try to get a medium-sized version.
		$sizes = array( 'medium_large', 'medium', 'thumbnail' );

		foreach ( $sizes as $size ) {
			$data = wp_get_attachment_image_src( $this->object->ID, $size );
			if ( $data ) {
				$url  = $data[0];
				$path = str_replace(
					wp_upload_dir()['baseurl'],
					wp_upload_dir()['basedir'],
					$url
				);

				if ( file_exists( $path ) && filesize( $path ) <= self::MAX_IMAGE_SIZE ) {
					return $path;
				}
			}
		}

		return null;
	}

	/**
	 * Get blob reference for an attachment ID.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return array|null The blob reference or null.
	 */
	public static function get_blob_ref( $attachment_id ) {
		// Check cache first.
		$cached = get_post_meta( $attachment_id, self::META_BLOB_REF, true );
		if ( $cached ) {
			return $cached;
		}

		// Transform the attachment.
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return null;
		}

		$transformer = new self( $attachment );
		return $transformer->transform();
	}

	/**
	 * Get blob data by CID.
	 *
	 * @param string $cid The blob CID.
	 * @return array|null Array with 'data' and 'mimeType' or null.
	 */
	public static function get_blob_by_cid( $cid ) {
		global $wpdb;

		// Find attachment by CID.
		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				self::META_BLOB_CID,
				$cid
			)
		);

		if ( ! $attachment_id ) {
			return null;
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return null;
		}

		$mime_type = get_post_mime_type( $attachment_id );
		$content   = file_get_contents( $file_path );

		if ( ! $content ) {
			return null;
		}

		return array(
			'data'     => $content,
			'mimeType' => $mime_type,
			'size'     => strlen( $content ),
		);
	}

	/**
	 * Create an Attachment transformer from an attachment ID.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return self|null The transformer or null.
	 */
	public static function from_id( $attachment_id ) {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return null;
		}

		return new self( $attachment );
	}
}
