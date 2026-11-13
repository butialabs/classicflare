<?php
/**
 * @package ClassicFlare
 */

declare( strict_types = 1 );

$suites = [
	'PSR-4 layout'     => __DIR__ . '/check-psr4.php',
	'Class references' => __DIR__ . '/check-classes.php',
	'Bootstrap'        => __DIR__ . '/boot.php',
	'Unit'             => __DIR__ . '/unit.php',
	'Integration'      => __DIR__ . '/integration.php',
];

$failed = 0;

foreach ( $suites as $name => $script ) {
	echo str_repeat( '=', 60 ) . PHP_EOL;
	echo $name . PHP_EOL;
	echo str_repeat( '=', 60 ) . PHP_EOL;

	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ), $status );

	if ( 0 !== $status ) {
		++$failed;
	}

	echo PHP_EOL;
}

if ( $failed > 0 ) {
	echo "{$failed} suite(s) failed." . PHP_EOL;
	exit( 1 );
}

echo 'All suites passed.' . PHP_EOL;
