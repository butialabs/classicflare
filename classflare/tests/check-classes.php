<?php
declare( strict_types = 1 );

$root  = dirname( __DIR__ );
$files = [];

$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src' ) );

foreach ( $rii as $file ) {
	if ( $file->isFile() && 'php' === $file->getExtension() ) {
		$files[] = $file->getPathname();
	}
}

$files[] = $root . '/api.php';
$files[] = $root . '/classicflare.php';
$files[] = $root . '/uninstall.php';

$prefix = 'ClassicFlare' . chr( 92 );
$used   = [];

$patterns = [
	'/^use (ClassicFlare(?:\\\\[A-Za-z0-9_]+)+)/m',
	'/\\\\?(ClassicFlare(?:\\\\[A-Za-z0-9_]+)+)::/m',
	'/new \\\\?(ClassicFlare(?:\\\\[A-Za-z0-9_]+)+)\s*\(/m',
];

foreach ( $files as $file ) {
	$src = (string) file_get_contents( $file );

	foreach ( $patterns as $pattern ) {
		if ( preg_match_all( $pattern, $src, $matches ) ) {
			foreach ( $matches[1] as $class ) {
				$used[ $class ] = true;
			}
		}
	}
}

ksort( $used );

$constants = [ 'PLUGIN_DIR', 'PLUGIN_URL', 'PLUGIN_FILE', 'PLUGIN_BASENAME', 'VERSION', 'DB_VERSION', 'MIN_PHP', 'MIN_WP' ];

$missing = [];

foreach ( array_keys( $used ) as $fq ) {
	$rel = str_replace( chr( 92 ), '/', substr( $fq, strlen( $prefix ) ) );

	if ( in_array( $rel, $constants, true ) ) {
		continue;
	}

	$path = $root . '/src/' . $rel . '.php';

	if ( ! is_file( $path ) ) {
		$missing[] = $fq . '  ->  src/' . $rel . '.php';
	}
}

echo 'referenced classes: ' . count( $used ) . PHP_EOL;

if ( $missing ) {
	echo "MISSING:" . PHP_EOL . implode( PHP_EOL, $missing ) . PHP_EOL;
	exit( 1 );
}

echo 'OK: every referenced class resolves to a file' . PHP_EOL;
