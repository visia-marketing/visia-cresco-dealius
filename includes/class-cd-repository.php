<?php
/**
 * Database access for `dealius_property` posts: idempotent upsert keyed on the
 * Dealius MainPropertyID, retirement of properties no longer in the feed, and
 * featured-image sideloading.
 */

namespace Cresco\Dealius;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Repository {

	/** @var Logger */
	private $logger;

	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Find the post ID for a building (by building key), or null.
	 *
	 * @param string $building_key
	 * @return int|null
	 */
	public function find_post_id( $building_key ) {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
				 WHERE pm.meta_key = %s AND pm.meta_value = %s
				 AND p.post_type = %s AND p.post_status = 'publish'
				 ORDER BY p.post_modified DESC, p.ID DESC",
				Model::META_IDENTITY,
				(string) $building_key,
				Model::POST_TYPE
			)
		);

		if ( empty( $ids ) ) {
			return null;
		}

		// Self-heal: if more than one post shares this building key (e.g. created by
		// overlapping imports, or pre-merge sale/lease records), keep the freshest
		// and delete the rest.
		$keep = (int) array_shift( $ids );
		foreach ( $ids as $dupe ) {
			$this->logger->log( 'Removing duplicate property post ' . $dupe . ' for building ' . $building_key . ' (keeping ' . $keep . ').' );
			$this->delete_post( (int) $dupe );
		}
		return $keep;
	}

	/**
	 * Find-or-create the property post for a building, set its title, and ensure
	 * the identity meta keys are present.
	 *
	 * @param string     $building_key
	 * @param int|string $main_property_id representative PropertyID (reference)
	 * @param string     $title
	 * @return int post ID
	 */
	public function upsert_post( $building_key, $main_property_id, $title ) {
		$post_id = $this->find_post_id( $building_key );

		if ( $post_id ) {
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => $title,
				)
			);
			update_post_meta( $post_id, Model::META_IDENTITY, $building_key );
			update_post_meta( $post_id, Model::META_PROPERTY_ID, $main_property_id );
			update_post_meta( $post_id, 'id', $main_property_id );
			return $post_id;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => Model::POST_TYPE,
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			$this->logger->log( 'Failed to insert property post for building ' . $building_key );
			return 0;
		}

		update_post_meta( $post_id, Model::META_IDENTITY, $building_key );
		update_post_meta( $post_id, Model::META_PROPERTY_ID, $main_property_id );
		update_post_meta( $post_id, 'id', $main_property_id );
		return (int) $post_id;
	}

	/**
	 * All published property post IDs, keyed by building key.
	 *
	 * @return array<string,int> building_key => post_id
	 */
	public function all_by_key() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS k, pm.post_id AS post_id
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
				 WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = 'publish'",
				Model::META_IDENTITY,
				Model::POST_TYPE
			)
		);
		$map = array();
		foreach ( $rows as $row ) {
			$map[ (string) $row->k ] = (int) $row->post_id;
		}
		return $map;
	}

	/**
	 * Retire (delete) properties whose building key is not in the kept set.
	 *
	 * @param array $kept_keys building keys seen this run
	 * @return int number of properties deleted
	 */
	public function retire_missing( array $kept_keys ) {
		$kept    = array_flip( array_map( 'strval', $kept_keys ) );
		$deleted = 0;

		foreach ( $this->all_by_key() as $key => $post_id ) {
			if ( ! isset( $kept[ (string) $key ] ) ) {
				$this->delete_post( $post_id );
				$this->logger->log( 'Retired property (not in feed): building ' . $key . ' / post ' . $post_id );
				$deleted++;
			}
		}
		return $deleted;
	}

	/**
	 * Delete a post and its featured image attachment.
	 *
	 * @param int $post_id
	 * @return bool
	 */
	public function delete_post( $post_id ) {
		if ( empty( $post_id ) ) {
			return false;
		}
		$thumb_id = get_post_thumbnail_id( $post_id );
		wp_delete_post( $post_id, true );
		if ( $thumb_id ) {
			wp_delete_attachment( $thumb_id, true );
		}
		return true;
	}

	/**
	 * Sideload the property image as the featured image, skipping the download if
	 * the current featured image already came from the same source URL.
	 *
	 * @param int    $post_id
	 * @param string $image_url
	 */
	/**
	 * Sideload the property image as the featured image.
	 *
	 * Dealius file URLs are token-gated and have NO file extension, so we download
	 * manually (with the token in $fetch_url) and give the file a proper name +
	 * extension before sideloading. Dedup/skip is keyed on $source_key (the raw,
	 * token-less URL) so re-imports don't re-download just because the token changed.
	 *
	 * @param int    $post_id
	 * @param string $fetch_url  URL to download (token included)
	 * @param string $source_key stable identity for dedup (raw URL); defaults to $fetch_url
	 */
	public function update_featured_image( $post_id, $fetch_url, $source_key = null ) {
		if ( empty( $fetch_url ) ) {
			return;
		}
		if ( null === $source_key ) {
			$source_key = $fetch_url;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$current_thumb_id = get_post_thumbnail_id( $post_id );
		if ( $current_thumb_id ) {
			if ( get_post_meta( $current_thumb_id, 'original_img_url', true ) === $source_key ) {
				return; // Unchanged; nothing to do.
			}
			wp_delete_attachment( $current_thumb_id, true );
		}

		$tmp = download_url( $fetch_url, 30 );
		if ( is_wp_error( $tmp ) ) {
			$this->logger->log( 'Featured image download failed for post ' . $post_id . ' - ' . $tmp->get_error_message() );
			return;
		}

		$mime    = wp_get_image_mime( $tmp );
		$ext_map = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		);
		$ext = isset( $ext_map[ $mime ] ) ? $ext_map[ $mime ] : 'jpg';

		$file_array = array(
			'name'     => 'dealius-' . md5( $source_key ) . '.' . $ext,
			'tmp_name' => $tmp,
		);

		$new_id = media_handle_sideload( $file_array, $post_id );
		if ( is_wp_error( $new_id ) ) {
			if ( file_exists( $tmp ) ) {
				@unlink( $tmp );
			}
			$this->logger->log( 'Featured image sideload failed for post ' . $post_id . ' - ' . $new_id->get_error_message() );
			return;
		}

		update_post_meta( $new_id, 'original_img_url', $source_key );
		set_post_thumbnail( $post_id, $new_id );
	}
}
