<?php
/**
 * Explicit, resumable maintenance of retired file download logs.
 * Loaded only by WP-CLI. Never runs during a web request or upgrade.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EDD_File_Download_Log_Cleanup {

	private $db;
	private $predicate;
	private $lock_name;
	private $active = false;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
		$this->lock_name = 'edd_cleanup_' . md5( DB_NAME . ':' . $wpdb->prefix );
		$this->predicate = "p.post_type = 'edd_log' AND EXISTS (
			SELECT 1 FROM {$wpdb->term_relationships} r
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id
			INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			WHERE r.object_id = p.ID AND tt.taxonomy = 'edd_log_type' AND t.slug = 'file_download'
		)";
	}

	private function unambiguous() {
		$db = $this->db;
		return "NOT EXISTS (
			SELECT 1 FROM {$db->term_relationships} r2
			INNER JOIN {$db->term_taxonomy} tt2 ON tt2.term_taxonomy_id = r2.term_taxonomy_id
			INNER JOIN {$db->terms} t2 ON t2.term_id = tt2.term_id
			WHERE r2.object_id = p.ID AND tt2.taxonomy = 'edd_log_type' AND t2.slug <> 'file_download'
		)";
	}

	private function query( $sql ) {
		$result = $this->db->query( $sql );
		if ( false === $result ) {
			throw new RuntimeException( 'Cleanup database operation failed: ' . $this->db->last_error );
		}
		return $result;
	}

	private function column( $sql ) {
		$result = $this->db->get_col( $sql );
		if ( $this->db->last_error ) {
			throw new RuntimeException( 'Cleanup database read failed: ' . $this->db->last_error );
		}
		return $result;
	}

	public function report() {
		$db = $this->db;
		$counts = $this->column( "SELECT COUNT(*) FROM {$db->posts} p WHERE {$this->predicate}" );
		$ambiguous = $this->column( "SELECT COUNT(*) FROM {$db->posts} p WHERE {$this->predicate} AND NOT (" . $this->unambiguous() . ')' );
		$meta = $this->column( "SELECT COUNT(*) FROM {$db->postmeta} m INNER JOIN {$db->posts} p ON p.ID = m.post_id WHERE {$this->predicate}" );
		$relations = $this->column( "SELECT COUNT(*) FROM {$db->term_relationships} r INNER JOIN {$db->posts} p ON p.ID = r.object_id WHERE {$this->predicate}" );
		return array( 'logs' => (int) $counts[0], 'ambiguous' => (int) $ambiguous[0], 'postmeta' => (int) $meta[0], 'relationships' => (int) $relations[0] );
	}

	public function acquire_lock() {
		$result = $this->column( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $this->lock_name ) );
		if ( empty( $result[0] ) ) {
			throw new RuntimeException( 'Another cleanup is running, or database advisory locks are unavailable.' );
		}
	}

	public function release_lock() {
		$this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name ) );
	}

	public function require_transactional_tables() {
		$db = $this->db;
		foreach ( array( $db->posts, $db->postmeta, $db->term_relationships, $db->term_taxonomy, $db->terms ) as $table ) {
			$engine = $this->column( $db->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			if ( empty( $engine[0] ) || 'INNODB' !== strtoupper( $engine[0] ) ) {
				throw new RuntimeException( 'Cleanup requires InnoDB tables: ' . $table );
			}
		}
	}

	public function rollback() {
		if ( $this->active ) {
			$this->db->query( 'ROLLBACK' );
			$this->active = false;
		}
	}

	/** Delete one batch; retry only rolled-back lock conflicts. */
	public function batch( $size ) {
		$db = $this->db;
		$size = max( 1, min( 5000, (int) $size ) );
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			try {
				$this->query( 'START TRANSACTION' );
				$this->active = true;
				$ids = $this->column( "SELECT p.ID FROM {$db->posts} p WHERE {$this->predicate} AND " . $this->unambiguous() . " ORDER BY p.ID LIMIT {$size} FOR UPDATE" );
				if ( ! $ids ) {
					$this->query( 'COMMIT' );
					$this->active = false;
					return 0;
				}
				$list = implode( ',', array_map( 'absint', $ids ) );
				// Lock dependent rows and collect terms before removing their relationships.
				$this->column( "SELECT meta_id FROM {$db->postmeta} WHERE post_id IN ({$list}) FOR UPDATE" );
				$terms = $this->column( "SELECT term_taxonomy_id FROM {$db->term_relationships} WHERE object_id IN ({$list}) FOR UPDATE" );
				$verified = $this->column( "SELECT p.ID FROM {$db->posts} p WHERE p.ID IN ({$list}) AND {$this->predicate} AND " . $this->unambiguous() . ' FOR UPDATE' );
				if ( count( $verified ) !== count( $ids ) ) {
					throw new RuntimeException( 'Log classification changed; batch aborted.' );
				}
				$this->query( "DELETE FROM {$db->postmeta} WHERE post_id IN ({$list})" );
				$this->query( "DELETE FROM {$db->term_relationships} WHERE object_id IN ({$list})" );
				$this->query( "DELETE FROM {$db->posts} WHERE ID IN ({$list}) AND post_type = 'edd_log'" );
				$this->query( 'COMMIT' );
				$this->active = false;
			} catch ( Exception $error ) {
				$message = $error->getMessage();
				$this->rollback();
				if ( $attempt < 2 && preg_match( '/deadlock|lock wait timeout/i', $message ) ) {
					usleep( 250000 * ( $attempt + 1 ) );
					continue;
				}
				throw $error;
			}
			// Invalidate only after commit; do not flush the site's entire cache.
			foreach ( $ids as $id ) {
				clean_post_cache( (int) $id );
				wp_cache_delete( (int) $id, 'posts' );
				wp_cache_delete( (int) $id, 'post_meta' );
				wp_cache_delete( (int) $id, 'edd_log_relationships' );
			}
			wp_cache_set( 'last_changed', microtime(), 'posts' );
			foreach ( array_unique( $terms ) as $term_taxonomy_id ) {
				$taxonomy = $db->get_var( $db->prepare( "SELECT taxonomy FROM {$db->term_taxonomy} WHERE term_taxonomy_id = %d", $term_taxonomy_id ) );
				if ( $db->last_error ) {
					throw new RuntimeException( 'Batch committed; term cache refresh failed. Recount terms before resuming.' );
				}
				if ( $taxonomy && taxonomy_exists( $taxonomy ) ) {
					wp_update_term_count_now( array( (int) $term_taxonomy_id ), $taxonomy );
				}
			}
			return count( $ids );
		}
	}
}
