<?php
/**
 * Runs every test file in this directory in its own PHP process.
 *
 * Usage: php tests/run.php
 */

$files  = glob( __DIR__ . '/test-*.php' );
$failed = 0;

foreach ( $files as $file ) {
	echo "\n=== " . basename( $file ) . " ===\n";

	$output = array();
	$status = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $output, $status );

	echo implode( "\n", $output ) . "\n";

	if ( 0 !== $status ) {
		$failed++;
	}
}

echo "\n" . ( 0 === $failed ? 'All suites passed.' : $failed . ' suite(s) failed.' ) . "\n";
exit( $failed > 0 ? 1 : 0 );
