<?php
/**
 * PHPUnit bootstrap for Playground-backed tests.
 *
 * @package Personal_RAG
 */

$wp_load = getenv( 'WP_LOAD_PATH' );
if ( ! $wp_load ) {
	$wp_load = '/wordpress/wp-load.php';
}

if ( ! file_exists( $wp_load ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fwrite( STDERR, "Could not find wp-load.php. Run PHPUnit through the WordPress Playground CLI.\n" );
	exit( 1 );
}

require_once $wp_load;
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once dirname( __DIR__, 2 ) . '/personal-rag.php';

Personal_RAG_Schema::install_schema();

do_action( 'init' );
