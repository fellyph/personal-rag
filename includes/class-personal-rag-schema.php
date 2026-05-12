<?php
/**
 * Database schema management.
 *
 * @package Personal_RAG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and tracks Personal RAG tables.
 */
class Personal_RAG_Schema {
	const DB_VERSION     = '2';
	const OPTION_VERSION = 'personal_rag_db_version';

	/**
	 * Returns plugin table names.
	 *
	 * @return array<string,string>
	 */
	public static function table_names() {
		global $wpdb;

		return array(
			'sources' => $wpdb->prefix . 'personal_rag_sources',
			'chunks'  => $wpdb->prefix . 'personal_rag_chunks',
			'vectors' => $wpdb->prefix . 'personal_rag_vectors',
		);
	}

	/**
	 * Installs the schema when needed.
	 *
	 * @return void
	 */
	public static function maybe_install_schema() {
		if ( get_option( self::OPTION_VERSION ) !== self::DB_VERSION ) {
			self::install_schema();
		}
	}

	/**
	 * Installs or upgrades plugin tables.
	 *
	 * @return void
	 */
	public static function install_schema() {
		global $wpdb;

		$tables  = self::table_names();
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$tables['sources']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source_type varchar(20) NOT NULL DEFAULT 'post',
				source_id bigint(20) unsigned NOT NULL,
				post_type varchar(20) NOT NULL DEFAULT '',
				post_status varchar(20) NOT NULL DEFAULT '',
				title text NOT NULL,
				url text NOT NULL,
				content_hash char(64) NOT NULL DEFAULT '',
				updated_at datetime NOT NULL,
				indexed_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source_unique (source_type, source_id),
				KEY content_hash (content_hash)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$tables['chunks']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source_id bigint(20) unsigned NOT NULL,
				chunk_index int(11) NOT NULL DEFAULT 0,
				chunk_text longtext NOT NULL,
				chunk_hash char(64) NOT NULL DEFAULT '',
				token_estimate int(11) NOT NULL DEFAULT 0,
				embedding_status varchar(20) NOT NULL DEFAULT 'queued',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source_chunk (source_id, chunk_index),
				KEY source_id (source_id),
				KEY embedding_status (embedding_status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$tables['vectors']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				chunk_id bigint(20) unsigned NOT NULL,
				model varchar(100) NOT NULL DEFAULT '',
				dimensions int(11) NOT NULL DEFAULT 0,
				vector longtext NOT NULL,
				norm double NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY chunk_id (chunk_id),
				KEY model_dimensions (model, dimensions)
			) {$charset};"
		);

		update_option( self::OPTION_VERSION, self::DB_VERSION );
	}
}
