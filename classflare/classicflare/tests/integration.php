<?php
/**
 * @package ClassicFlare
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['__options'] = [];
$GLOBALS['__cron']    = [];
$GLOBALS['__calls']   = [];
$GLOBALS['__script']  = [];

function get_option( $n, $d = false ) { return $GLOBALS['__options'][ $n ] ?? $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['__options'][ $n ] = $v; return true; }
function add_option( $n, $v, $x = '', $a = null ) {
	if ( array_key_exists( $n, $GLOBALS['__options'] ) ) { return false; }
	$GLOBALS['__options'][ $n ] = $v; return true;
}
function delete_option( $n ) { unset( $GLOBALS['__options'][ $n ] ); return true; }
function maybe_serialize( $v ) { return is_array( $v ) || is_object( $v ) ? serialize( $v ) : $v; }
function apply_filters( $t, $v, ...$r ) { return $v; }
function add_filter( ...$a ) { return true; }
function add_action( ...$a ) { return true; }
function do_action( ...$a ) { return null; }
function doing_action( $h = null ) { return false; }
function wp_parse_url( $u, $c = -1 ) { return -1 === $c ? parse_url( $u ) : parse_url( $u, $c ); }
function home_url( $p = '' ) { return 'https://example.com' . $p; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_json_encode( $d ) { return json_encode( $d ); }
function __( $t, $d = null ) { return $t; }
function esc_html__( $t, $d = null ) { return $t; }
function _n( $s, $p, $n, $d = null ) { return 1 === $n ? $s : $p; }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function get_transient( $k ) { return false; }
function wp_cache_delete( ...$a ) { return true; }
function is_admin() { return true; }
function wp_doing_cron() { return false; }
function wp_doing_ajax() { return false; }
function spawn_cron() { return true; }
function wp_schedule_single_event( $ts, $hook, $args = [] ) {
	$GLOBALS['__cron'][] = [ 'ts' => $ts, 'hook' => $hook, 'args' => $args ];
	return true;
}

final class WP_Error {
	public function get_error_message() { return 'cURL error 28: timed out'; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

function wp_remote_request( $url, $args ) {
	$GLOBALS['__calls'][] = [
		'url'  => $url,
		'body' => json_decode( (string) ( $args['body'] ?? 'null' ), true ),
	];

	$next = array_shift( $GLOBALS['__script'] ) ?? [ 200, [ 'success' => true, 'errors' => [], 'result' => [ 'id' => 'x' ] ], [] ];

	if ( 'timeout' === $next ) {
		return new WP_Error();
	}

	return [
		'response' => [ 'code' => $next[0] ],
		'body'     => json_encode( $next[1] ),
		'headers'  => $next[2],
	];
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_headers( $r ) { return $r['headers']; }

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'ClassicFlare\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$path = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

use ClassicFlare\Cloudflare\Purger;
use ClassicFlare\Purge\Coordinator;
use ClassicFlare\Purge\Scheduler;
use ClassicFlare\Support\Options;

$pass = 0;
$fail = 0;

function check( string $label, $actual, $expected ): void {
	global $pass, $fail;
	if ( $actual === $expected ) {
		++$pass;
		echo "  ok   {$label}\n";
		return;
	}
	++$fail;
	echo "  FAIL {$label}\n       expected: " . var_export( $expected, true ) . "\n       actual:   " . var_export( $actual, true ) . "\n";
}
function check_true( string $label, $actual ): void { check( $label, (bool) $actual, true ); }

function reset_world( string $plan = 'free' ): Coordinator {
	$GLOBALS['__options'] = [
		Options::OPTION_SECRETS  => [ 'cf_api_token' => 'test-token-1234' ],
		Options::OPTION_CF_STATE => [ 'zone_id' => 'zone123', 'zone_name' => 'example.com', 'plan' => $plan ],
	];
	$GLOBALS['__cron']   = [];
	$GLOBALS['__calls']  = [];
	$GLOBALS['__script'] = [];
	Options::flush();

	return new Coordinator( new Purger() );
}

/**
 * @param int $n How many.
 * @return array<int, string>
 */
function urls( int $n ): array {
	$out = [];
	for ( $i = 1; $i <= $n; $i++ ) {
		$out[] = "https://example.com/post-{$i}/";
	}
	return $out;
}

echo "== chunking follows the plan limit ==\n";
$c = reset_world();
$c->add( urls( 75 ), 'test' );
$result = $c->dispatch( true );
check( 'three calls for 75 URLs', count( $GLOBALS['__calls'] ), 3 );
check( 'chunks of 30, 30, 15', array_map( static fn ( $call ) => count( $call['body']['files'] ), $GLOBALS['__calls'] ), [ 30, 30, 15 ] );
check( 'every URL succeeded', count( $result->succeeded() ), 75 );
check_true( 'the purge endpoint for the right zone', str_ends_with( $GLOBALS['__calls'][0]['url'], 'zones/zone123/purge_cache' ) );
check( 'nothing scheduled after a clean run', $GLOBALS['__cron'], [] );

$c = reset_world( 'enterprise' );
$c->add( urls( 75 ), 'test' );
$c->dispatch( true );
check( 'one call on enterprise', count( $GLOBALS['__calls'] ), 1 );

echo "== a big change escalates to purge_everything ==\n";
$c = reset_world();
$GLOBALS['__options'][ Options::OPTION_SETTINGS ] = [ 'purge' => [ 'max_urls' => 50 ] ];
Options::flush();
$c->add( urls( 51 ), 'test' );
$c->dispatch( true );
check( 'one call', count( $GLOBALS['__calls'] ), 1 );
check( 'purge_everything', $GLOBALS['__calls'][0]['body'], [ 'purge_everything' => true ] );

echo "== only the failed chunk is retried ==\n";
$c = reset_world();
$GLOBALS['__script'] = [
	[ 200, [ 'success' => true, 'errors' => [], 'result' => [] ], [] ],
	[ 503, [ 'success' => false, 'errors' => [ [ 'code' => 10000, 'message' => 'Service unavailable' ] ] ], [] ],
];
$c->add( urls( 45 ), 'test' );
$result = $c->dispatch( true );
check( 'both chunks were attempted', count( $GLOBALS['__calls'] ), 2 );
check( 'first chunk purged', count( $result->succeeded() ), 30 );
check( 'second chunk failed', count( $result->failed_urls() ), 15 );
check( 'one retry scheduled', count( $GLOBALS['__cron'] ), 1 );
check( 'on the purge hook', $GLOBALS['__cron'][0]['hook'], Scheduler::HOOK_PURGE );
check( 'carrying only the 15 failures', $GLOBALS['__cron'][0]['args'][0], array_slice( urls( 45 ), 30 ) );
check( 'as attempt two', $GLOBALS['__cron'][0]['args'][2], 2 );
check_true( 'a minute out', $GLOBALS['__cron'][0]['ts'] >= time() + 59 );
check( 'last purge recorded as failed', Options::last_purge()['ok'], false );
check( 'and as retrying', Options::last_purge()['retrying'], true );

echo "== Retry-After from a 429 is honoured ==\n";
$c = reset_world();
$GLOBALS['__script'] = [
	[ 429, [ 'success' => false, 'errors' => [ [ 'code' => 971, 'message' => 'Too many requests' ] ] ], [ 'retry-after' => '600' ] ],
];
$c->add( urls( 3 ), 'test' );
$result = $c->dispatch( true );
check( 'a 429 is a failure, never a skip, whatever code it carries', count( $result->failed_urls() ), 3 );
check_true( 'retried after ten minutes, not one', $GLOBALS['__cron'][0]['ts'] >= time() + 599 );

echo "== a transport timeout is retried ==\n";
$c = reset_world();
$GLOBALS['__script'] = [ 'timeout' ];
$c->add( urls( 2 ), 'test' );
$c->dispatch( true );
check( 'retry scheduled', count( $GLOBALS['__cron'] ), 1 );

echo "== a bad token is not retried ==\n";
$c = reset_world();
$GLOBALS['__script'] = [
	[ 403, [ 'success' => false, 'errors' => [ [ 'code' => 10000, 'message' => 'Authentication error' ] ] ], [] ],
];
$c->add( urls( 2 ), 'test' );
$result = $c->dispatch( true );
check( 'failed', count( $result->failed_urls() ), 2 );
check( 'no retry for an auth failure', $GLOBALS['__cron'], [] );
check( 'and the token is flagged for the settings screen', Options::cf_state( 'token_status' ), 'invalid' );

echo "== URLs outside the zone are skipped, not failed ==\n";
$c = reset_world();
$GLOBALS['__script'] = [
	[ 400, [ 'success' => false, 'errors' => [ [ 'code' => 1134, 'message' => 'Not in zone' ] ] ], [] ],
];
$c->add( [ 'https://other.example.net/a/' ], 'test' );
$result = $c->dispatch( true );
check( 'skipped', count( $result->skipped() ), 1 );
check( 'nothing failed', $result->has_failures(), false );
check( 'nothing retried', $GLOBALS['__cron'], [] );

echo "== retries stop at the ceiling ==\n";
$c = reset_world();
$GLOBALS['__script'] = [ [ 500, [ 'success' => false, 'errors' => [] ], [] ] ];
$c->run( urls( 1 ), false, Coordinator::MAX_ATTEMPTS, 'test' );
check( 'the last attempt schedules nothing', $GLOBALS['__cron'], [] );

echo "== a cron retry runs with the arguments it was given ==\n";
$c = reset_world();
$c->on_cron( [ 'https://example.com/again/' ], false, 2, 'retry' );
check( 'one call', count( $GLOBALS['__calls'] ), 1 );
check( 'with the stored URL', $GLOBALS['__calls'][0]['body']['files'], [ 'https://example.com/again/' ] );
check( 'recorded', Options::last_purge()['attempt'], 2 );

echo "== a full purge that fails is retried as a full purge ==\n";
$c = reset_world();
$GLOBALS['__script'] = [ [ 502, [ 'success' => false, 'errors' => [] ], [] ] ];
$c->add_purge_all( 'test' );
$c->dispatch( true );
check( 'retry scheduled', count( $GLOBALS['__cron'] ), 1 );
check( 'as a full purge', $GLOBALS['__cron'][0]['args'][1], true );

echo "== without a connection nothing is sent ==\n";
$c = reset_world();
$GLOBALS['__options'] = [];
Options::flush();
$c->add( urls( 2 ), 'test' );
$result = $c->dispatch( true );
check( 'no API call', $GLOBALS['__calls'], [] );
check_true( 'reported as a failure', $result->is_fatal() );
check( 'and not retried', $GLOBALS['__cron'], [] );

echo "== custom paths ride along with every URL purge ==\n";
$c = reset_world();
Options::save_custom_urls( "/llms.txt\n/robots.txt" );
$c->add( [ 'https://example.com/a/' ], 'test' );
$c->dispatch( true );
check(
	'custom paths appended',
	$GLOBALS['__calls'][0]['body']['files'],
	[ 'https://example.com/a/', 'https://example.com/llms.txt', 'https://example.com/robots.txt' ]
);

echo "== front-end requests defer to cron ==\n";
$c = reset_world();
$c->add( urls( 2 ), 'comment' );
$c->dispatch( false );
check( 'no API call during the request', $GLOBALS['__calls'], [] );
check( 'scheduled immediately instead', count( $GLOBALS['__cron'] ), 1 );
check( 'as attempt one', $GLOBALS['__cron'][0]['args'][2], 1 );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
