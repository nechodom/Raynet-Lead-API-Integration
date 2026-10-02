<?php
/**
 * Runs every test file in this directory in its own PHP process, and the
 * JavaScript tests in js/ with Node.
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

$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );

foreach ( glob( __DIR__ . '/js/test-*.js' ) as $file ) {
	echo "\n=== js/" . basename( $file ) . " ===\n";

	// Missing Node counts as a failure: a skipped suite would read as passed.
	if ( '' === $node ) {
		echo "Node.js not found; this suite needs it.\n";
		$failed++;
		continue;
	}

	$output = array();
	$status = 0;
	exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $output, $status );

	echo implode( "\n", $output ) . "\n";

	if ( 0 !== $status ) {
		$failed++;
	}
}

echo "\n" . ( 0 === $failed ? 'All suites passed.' : $failed . ' suite(s) failed.' ) . "\n";
exit( $failed > 0 ? 1 : 0 );
