<?php
/**
 * Runs PHPUnit inside a WordPress Playground runPHP step.
 *
 * @package Personal_RAG
 */

chdir( '/wordpress/wp-content/plugins/personal-rag' );

$_SERVER['argv'] = array(
	'phpunit',
	'-c',
	'/wordpress/wp-content/plugins/personal-rag/phpunit.xml.dist',
);
$_SERVER['argc'] = count( $_SERVER['argv'] );

require '/wordpress/wp-content/plugins/personal-rag/vendor/autoload.php';

$status = PHPUnit\TextUI\Command::main( false );
if ( 0 !== $status ) {
	exit( $status );
}
