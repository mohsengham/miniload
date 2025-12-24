<?php
/**
 * MiniLoad Media Search Optimizer
 * Accelerates WordPress media library search
 *
 * @package MiniLoad
 * @since 1.0.0
 */

namespace MiniLoad\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Media_Search_Optimizer {

	/**
	 * Media search table
	 */
	private $media_table;

	/**
	 * Maximum items to index at once
	 */
	private $batch_size = 100;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->media_table = $wpdb->prefix . 'miniload_media_search';

		// Only run if enabled
		if ( ! get_option( 'miniload_media_search_enabled', false ) ) {
			return;
		}

		// Create table if needed
		add_action( 'init', array( $this, 'maybe_create_table' ) );

		// Hook into media uploads
		add_action( 'add_attachment', array( $this, 'index_new_media' ) );
		add_action( 'edit_attachment', array( $this, 'update_media_index' ) );
		add_action( 'delete_attachment', array( $this, 'remove_media_index' ) );

		// Enhance media library search
		add_action( 'pre_get_posts', array( $this, 'enhance_media_search' ), 999 );

		// AJAX handlers
		add_action( 'wp_ajax_miniload_search_media', array( $this, 'ajax_search_media' ) );
		add_action( 'wp_ajax_miniload_rebuild_media_index', array( $this, 'ajax_rebuild_index' ) );

		// Add to admin search modal
		add_filter( 'miniload_admin_search_tabs', array( $this, 'add_media_tab' ) );

		// Admin assets
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Maybe create media search table
	 */
	public function maybe_create_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$this->media_table} (
			attachment_id bigint(20) NOT NULL,
			search_text longtext,
			file_name varchar(255),
			file_type varchar(100),
			mime_type varchar(100),
			file_size bigint(20),
			image_meta longtext,
			indexed_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (attachment_id),
			KEY idx_file_type (file_type),
			KEY idx_mime_type (mime_type),
			FULLTEXT KEY search_fulltext (search_text, file_name)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}

	/**
	 * Build search text for media item
	 */
	private function build_media_search_text( $attachment_id ) {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
			return '';
		}

		$search_parts = array();

		// Title
		if ( $attachment->post_title ) {
			$search_parts[] = $attachment->post_title;
		}

		// Description
		if ( $attachment->post_content ) {
			$search_parts[] = $attachment->post_content;
		}

		// Caption
		if ( $attachment->post_excerpt ) {
			$search_parts[] = $attachment->post_excerpt;
		}

		// Alt text
		$alt_text = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( $alt_text ) {
			$search_parts[] = $alt_text;
		}

		// File name without extension
		$file_name = basename( get_attached_file( $attachment_id ) );
		$search_parts[] = pathinfo( $file_name, PATHINFO_FILENAME );

		// Image metadata (EXIF, IPTC)
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $metadata['image_meta'] ) ) {
			foreach ( $metadata['image_meta'] as $key => $value ) {
				if ( is_string( $value ) && ! empty( $value ) ) {
					$search_parts[] = $value;
				}
			}
		}

		// Custom fields
		$custom_fields = get_post_custom( $attachment_id );
		foreach ( $custom_fields as $key => $values ) {
			if ( strpos( $key, '_' ) !== 0 ) { // Skip private fields
				foreach ( $values as $value ) {
					if ( is_string( $value ) && ! empty( $value ) ) {
						$search_parts[] = $value;
					}
				}
			}
		}

		return implode( ' ', $search_parts );
	}

	/**
	 * Index new media
	 */
	public function index_new_media( $attachment_id ) {
		$this->index_media_item( $attachment_id );
	}

	/**
	 * Update media index
	 */
	public function update_media_index( $attachment_id ) {
		$this->index_media_item( $attachment_id );
	}

	/**
	 * Index single media item
	 */
	private function index_media_item( $attachment_id ) {
		global $wpdb;

		$attachment = get_post( $attachment_id );
		if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
			return false;
		}

		// Build search text
		$search_text = $this->build_media_search_text( $attachment_id );

		// Get file info
		$file_path = get_attached_file( $attachment_id );
		$file_name = basename( $file_path );
		$file_type = wp_check_filetype( $file_name );
		$file_size = filesize( $file_path );

		// Get image metadata
		$image_meta = '';
		if ( wp_attachment_is_image( $attachment_id ) ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
			$image_meta = json_encode( $metadata );
		}

		// Insert or update
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$wpdb->replace(
			$this->media_table,
			array(
				'attachment_id' => $attachment_id,
				'search_text' => $search_text,
				'file_name' => $file_name,
				'file_type' => $file_type['ext'],
				'mime_type' => $attachment->post_mime_type,
				'file_size' => $file_size,
				'image_meta' => $image_meta,
				'indexed_at' => current_time( 'mysql' )
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return true;
	}

	/**
	 * Remove media from index
	 */
	public function remove_media_index( $attachment_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$wpdb->delete(
			$this->media_table,
			array( 'attachment_id' => $attachment_id ),
			array( '%d' )
		);
	}

	/**
	 * Enhance media library search
	 */
	public function enhance_media_search( $query ) {
		// Only in admin
		if ( ! is_admin() ) {
			return;
		}

		// Only for media library
		if ( ! $query->is_main_query() ) {
			return;
		}

		// Check if this is a media search
		if ( $query->get( 'post_type' ) !== 'attachment' ) {
			return;
		}

		// Check if there's a search term
		$search = $query->get( 's' );
		if ( empty( $search ) ) {
			return;
		}

		// Get matching attachment IDs from our index
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$attachment_ids = $wpdb->get_col( $wpdb->prepare( "
			SELECT attachment_id
			FROM " . esc_sql( $this->media_table ) . "
			WHERE MATCH(search_text, file_name) AGAINST(%s IN BOOLEAN MODE)
			ORDER BY
				CASE
					WHEN file_name LIKE %s THEN 1
					WHEN search_text LIKE %s THEN 2
					ELSE 3
				END
			LIMIT 100
		", $search, $search . '%', '%' . $wpdb->esc_like( $search ) . '%' ) );

		if ( ! empty( $attachment_ids ) ) {
			// Override the search to use our results
			$query->set( 's', '' );
			$query->set( 'post__in', $attachment_ids );
			$query->set( 'orderby', 'post__in' );
		}
	}

	/**
	 * AJAX search media
	 */
	public function ajax_search_media() {
		check_ajax_referer( 'miniload_admin_search_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		if ( ! isset( $_POST['term'] ) ) {
			wp_send_json_error( 'Search term is required' );
		}

		$search_term = sanitize_text_field( wp_unslash( $_POST['term'] ) );
		$results = $this->search_media( $search_term );

		wp_send_json_success( array(
			'results' => $results,
			'term' => $search_term
		) );
	}

	/**
	 * Search media items
	 */
	public function search_media( $term ) {
		global $wpdb;
		$results = array();

		// Search in our index
		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  $wpdb->prepare( "
			SELECT m.attachment_id, m.file_name, m.file_type, m.mime_type, m.file_size
			FROM " . esc_sql( $this->media_table ) . " m
			WHERE MATCH(m.search_text, m.file_name) AGAINST(%s IN BOOLEAN MODE)
			ORDER BY
				CASE
					WHEN m.file_name LIKE %s THEN 1
					WHEN m.search_text LIKE %s THEN 2
					ELSE 3
				END
			LIMIT 20
		", $term, $term . '%', '%' . $wpdb->esc_like( $term ) . '%' )  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_results( $wpdb->prepare( "
			SELECT m.attachment_id, m.file_name, m.file_type, m.mime_type, m.file_size
			FROM " . esc_sql( $this->media_table ) . " m
			WHERE MATCH(m.search_text, m.file_name) AGAINST(%s IN BOOLEAN MODE)
			ORDER BY
				CASE
					WHEN m.file_name LIKE %s THEN 1
					WHEN m.search_text LIKE %s THEN 2
					ELSE 3
				END
			LIMIT 20
		", $term, $term . '%', '%' . $wpdb->esc_like( $term ) . '%' ) );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		foreach ( $cached as $item ) {
			$attachment = get_post( $item->attachment_id );
			if ( ! $attachment ) {
				continue;
			}

			// Get thumbnail
			$thumbnail = '';
			if ( wp_attachment_is_image( $item->attachment_id ) ) {
				$thumbnail = wp_get_attachment_image_url( $item->attachment_id, 'thumbnail' );
			} else {
				$thumbnail = wp_mime_type_icon( $item->mime_type );
			}

			$results[] = array(
				'id' => $item->attachment_id,
				'title' => $attachment->post_title ?: $item->file_name,
				'filename' => $item->file_name,
				'type' => $item->file_type,
				'mime' => $item->mime_type,
				'size' => size_format( $item->file_size ),
				'url' => wp_get_attachment_url( $item->attachment_id ),
				'edit_url' => get_edit_post_link( $item->attachment_id, '' ),
				'thumbnail' => $thumbnail,
				'date' => get_the_date( '', $attachment ),
				'badge' => strtoupper( $item->file_type )
			);
		}

		return $results;
	}

	/**
	 * Add media tab to admin search
	 */
	public function add_media_tab( $tabs ) {
		$tabs['media'] = __( 'Media', 'miniload' );
		return $tabs;
	}

	/**
	 * Rebuild media index (with batch processing)
	 */
	public function ajax_rebuild_index() {
		check_ajax_referer( 'miniload_rebuild_media', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		// Get batch parameters - now using last_id instead of offset
		$last_id = isset( $_POST['last_id'] ) ? absint( $_POST['last_id'] ) :
		           ( isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0 );
		$batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 500;
		$clear_first = isset( $_POST['clear_first'] ) ? ( $_POST['clear_first'] === 'true' ) : ( $last_id === 0 );

		// Process batch
		$miniload_result = $this->rebuild_index_batch( $last_id, $batch_size, $clear_first );
		wp_send_json_success( $miniload_result );
	}

	/**
	 * Rebuild media index (batch processing version) - ULTRA-OPTIMIZED
	 *
	 * @param int $last_id Last processed ID (0 for start)
	 * @param int $batch_size Number of items to process per batch
	 * @param bool $clear_first Whether to clear the index first
	 * @return array Results
	 */
	public function rebuild_index_batch( $last_id = 0, $batch_size = 500, $clear_first = false ) {
		global $wpdb;

		$start_time = microtime( true );

		// Clear existing index on first batch
		if ( $clear_first || $last_id === 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "TRUNCATE TABLE " . esc_sql( $this->media_table ) );
		}

		// Get total media count
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_media = $wpdb->get_var( "
			SELECT COUNT(*)
			FROM {$wpdb->posts}
			WHERE post_type = 'attachment'
			AND post_status = 'inherit'
		" );

		// ULTRA-OPTIMIZED: Get batch using ID-based pagination (handles gaps better than OFFSET)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$attachments = $wpdb->get_results( $wpdb->prepare( "
			SELECT p.ID, p.post_title, p.post_content, p.post_excerpt, p.post_mime_type,
			       pm_file.meta_value as file_path,
			       pm_alt.meta_value as alt_text,
			       pm_meta.meta_value as attachment_metadata
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm_file ON p.ID = pm_file.post_id AND pm_file.meta_key = '_wp_attached_file'
			LEFT JOIN {$wpdb->postmeta} pm_alt ON p.ID = pm_alt.post_id AND pm_alt.meta_key = '_wp_attachment_image_alt'
			LEFT JOIN {$wpdb->postmeta} pm_meta ON p.ID = pm_meta.post_id AND pm_meta.meta_key = '_wp_attachment_metadata'
			WHERE p.post_type = 'attachment'
			  AND p.post_status = 'inherit'
			  AND p.ID > %d
			ORDER BY p.ID ASC
			LIMIT %d
		", $last_id, $batch_size ), ARRAY_A );

		$batch_count = count( $attachments );
		$indexed = 0;
		$last_processed_id = $last_id;

		// Build bulk INSERT values
		$values = array();
		$placeholders = array();
		$upload_dir = wp_upload_dir();
		$base_dir = $upload_dir['basedir'];

		foreach ( $attachments as $attachment ) {
			$attachment_id = (int) $attachment['ID'];
			$last_processed_id = $attachment_id;

			// Skip if no file path
			if ( empty( $attachment['file_path'] ) ) {
				continue;
			}

			// Build search text
			$search_parts = array();

			if ( ! empty( $attachment['post_title'] ) ) {
				$search_parts[] = $attachment['post_title'];
			}
			if ( ! empty( $attachment['post_content'] ) ) {
				$search_parts[] = $attachment['post_content'];
			}
			if ( ! empty( $attachment['post_excerpt'] ) ) {
				$search_parts[] = $attachment['post_excerpt'];
			}
			if ( ! empty( $attachment['alt_text'] ) ) {
				$search_parts[] = $attachment['alt_text'];
			}

			// File name and metadata
			$file_path = $attachment['file_path'];
			$file_name = basename( $file_path );
			$file_name_no_ext = pathinfo( $file_name, PATHINFO_FILENAME );
			$search_parts[] = $file_name_no_ext;

			// Parse metadata if available
			$image_meta_json = '';
			if ( ! empty( $attachment['attachment_metadata'] ) ) {
				$metadata = maybe_unserialize( $attachment['attachment_metadata'] );
				if ( is_array( $metadata ) ) {
					$image_meta_json = wp_json_encode( $metadata );

					// Add EXIF data to search
					if ( ! empty( $metadata['image_meta'] ) && is_array( $metadata['image_meta'] ) ) {
						foreach ( $metadata['image_meta'] as $meta_value ) {
							if ( is_string( $meta_value ) && ! empty( $meta_value ) && $meta_value !== '0' ) {
								$search_parts[] = $meta_value;
							}
						}
					}
				}
			}

			$search_text = implode( ' ', array_filter( $search_parts ) );

			// Get file info
			$file_type = wp_check_filetype( $file_name );
			$full_path = $base_dir . '/' . $file_path;
			$file_size = file_exists( $full_path ) ? filesize( $full_path ) : 0;

			// Add to bulk insert
			$placeholders[] = '(%d, %s, %s, %s, %s, %d, %s, NOW())';
			$values[] = $attachment_id;
			$values[] = $search_text;
			$values[] = $file_name;
			$values[] = $file_type['ext'];
			$values[] = $attachment['post_mime_type'];
			$values[] = $file_size;
			$values[] = $image_meta_json;

			$indexed++;
		}

		// Bulk INSERT - much faster than individual queries
		if ( ! empty( $values ) ) {
			$sql = "REPLACE INTO {$this->media_table}
			        (attachment_id, search_text, file_name, file_type, mime_type, file_size, image_meta, indexed_at)
			        VALUES " . implode( ', ', $placeholders );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( $sql, $values ) );
		}

		// Clear caches periodically
		if ( $last_processed_id % 5000 < $batch_size ) {
			wp_cache_flush();
		}

		$time_taken = round( microtime( true ) - $start_time, 2 );

		// Get count of already indexed items for progress calculation
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$current_indexed = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->media_table}" );
		$progress = $total_media > 0 ? round( ( $current_indexed / $total_media ) * 100, 1 ) : 100;
		$completed = ( $batch_count < $batch_size );

		return array(
			'success' => true,
			'batch_indexed' => $indexed,
			'batch_failed' => 0,
			'batch_count' => $batch_count,
			'offset' => $last_processed_id,
			'next_offset' => $last_processed_id,
			'last_id' => $last_processed_id,
			'total' => $total_media,
			'progress' => $progress,
			'completed' => $completed,
			'time' => $time_taken,
			'processed' => $indexed,
			'message' => $completed ?
				sprintf( __( 'Media index rebuild completed! Processed %d items.', 'miniload' ), $current_indexed ) :
				sprintf( __( 'Processing... %d of %d media items (%d%%)', 'miniload' ), $current_indexed, $total_media, $progress )
		);
	}

	/**
	 * Rebuild entire media index (legacy - for backward compatibility)
	 */
	public function rebuild_index() {
		global $wpdb;

		$start = microtime( true );

		// Clear existing index
		$wpdb->query( "TRUNCATE TABLE " . esc_sql( $this->media_table ) );

		// Get all attachments
		$attachments = get_posts( array(
			'post_type' => 'attachment',
			'posts_per_page' => -1,
			'post_status' => 'inherit',
			'fields' => 'ids'
		) );

		$total = count( $attachments );
		$indexed = 0;

		foreach ( $attachments as $attachment_id ) {
			if ( $this->index_media_item( $attachment_id ) ) {
				$indexed++;
			}

			// Clear cache periodically
			if ( $indexed % 50 === 0 ) {
				wp_cache_flush();
			}
		}

		$time = round( microtime( true ) - $start, 2 );

		return array(
			'message' => sprintf(
				/* translators: %1$d: number of indexed media items, %2$s: time taken in seconds */
				__( 'Indexed %1$d media items in %2$s seconds', 'miniload' ),
				$indexed,
				$time
			),
			'indexed' => $indexed,
			'total' => $total,
			'time' => $time
		);
	}

	/**
	 * Enqueue assets
	 */
	public function enqueue_assets( $hook ) {
		// Only on media library page
		if ( 'upload.php' === $hook ) {
			wp_enqueue_script(
				'miniload-media-search',
				MINILOAD_PLUGIN_URL . 'assets/js/media-search.js',
				array( 'jquery' ),
				MINILOAD_VERSION,
				true
			);

			wp_localize_script( 'miniload-media-search', 'miniload_media', array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'miniload_admin_search_nonce' )
			) );
		}
	}

	/**
	 * Get index stats
	 */
	public function get_stats() {
		global $wpdb;

		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "SELECT COUNT(*) FROM " . esc_sql( $this->media_table ) . ""  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( "SELECT COUNT(*) FROM " . esc_sql( $this->media_table ) );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}
		$indexed = $cached;

		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'" );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}
		$total = $cached;

		return array(
			'indexed' => $indexed,
			'total' => $total,
			'coverage' => $total > 0 ? round( ( $indexed / $total ) * 100, 1 ) : 0
		);
	}
}