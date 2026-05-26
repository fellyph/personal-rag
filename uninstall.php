<?php
/**
 * Remove Personal RAG data when the plugin is deleted.
 *
 * @package Personal_RAG
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$personal_rag_tables = array(
	$wpdb->prefix . 'personal_rag_vectors',
	$wpdb->prefix . 'personal_rag_chunks',
	$wpdb->prefix . 'personal_rag_sources',
);

foreach ( $personal_rag_tables as $personal_rag_table ) {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing plugin-owned custom tables on uninstall.
	$wpdb->query(
		$wpdb->prepare(
			'DROP TABLE IF EXISTS %i',
			$personal_rag_table
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
}

delete_option( 'personal_rag_db_version' );
