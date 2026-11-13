<?php
/**
 * @package ClassicFlare
 */

use ClassicFlare\Cloudflare\Client;
use ClassicFlare\Cloudflare\Purger;
use ClassicFlare\Cloudflare\Exception\AuthException;
use ClassicFlare\Cloudflare\Exception\ClientException;
use ClassicFlare\Cloudflare\Exception\RateLimitException;
use ClassicFlare\Cloudflare\Exception\ServerException;
use ClassicFlare\Cloudflare\Exception\TransportException;
use ClassicFlare\Plugin;
use ClassicFlare\Purge\Scheduler;
use ClassicFlare\Support\Options;

final class OmcCfTally {
	public static int $pass = 0;
	public static int $fail = 0;
	/** @var array<string, mixed> */
	public static array $next = [];
	public static int $calls = 0;
	public static string $last_body = '';
}

function c_check( string $label, $actual, $expected ): void {
	if ( $actual === $expected ) {
		++OmcCfTally::$pass;
		WP_CLI::log( "  ok   {$label}" );
		return;
	}
	++OmcCfTally::$fail;
	WP_CLI::log( "  FAIL {$label}" );
	WP_CLI::log( '       expected: ' . var_export( $expected, true ) );
	WP_CLI::log( '       actual:   ' . var_export( $actual, true ) );
}

function c_true( string $label, $actual ): void {
	c_check( $label, (bool) $actual, true );
}

/**
 * @param int   $status  HTTP status.
 * @param array $body    Decoded body.
 * @param array $headers Response headers.
 */
function cf_will_answer( int $status, array $body, array $headers = [] ): void {
	OmcCfTally::$next = [
		'status'  => $status,
		'body'    => $body,
		'headers' => $headers,
	];
}

function cf_will_fail_transport(): void {
	OmcCfTally::$next = [ 'wp_error' => true ];
}

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( ! str_contains( (string) $url, 'api.cloudflare.com' ) ) {
			return $preempt;
		}

		++OmcCfTally::$calls;
		OmcCfTally::$last_body = (string) ( $args['body'] ?? '' );

		$next             = OmcCfTally::$next;
		OmcCfTally::$next = [];

		if ( ! $next ) {
			return new WP_Error( 'classicflare_test', 'No fixture queued for this call.' );
		}

		if ( ! empty( $next['wp_error'] ) ) {
			return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
		}

		return [
			'headers'  => $next['headers'],
			'body'     => wp_json_encode( $next['body'] ),
			'response' => [
				'code'    => $next['status'],
				'message' => 'x',
			],
			'cookies'  => [],
			'filename' => null,
		];
	},
	10,
	3
);

$saved_secrets = get_option( Options::OPTION_SECRETS, false );
$saved_state   = get_option( Options::OPTION_CF_STATE, false );
$saved_last    = get_option( Options::OPTION_LAST_PURGE, false );

Options::save_secrets( [ 'cf_api_token' => 'test-token-not-a-real-one' ] );
Options::set_cf_state(
	[
		'zone_id'   => 'testzone123',
		'zone_name' => 'wordpress.local',
		'plan'      => 'free',
	]
);

$client = new Client( 5.0 );
$purger = new Purger( $client );

WP_CLI::log( '== purger availability ==' );
c_check( 'usable with a token and a zone', $purger->unavailable(), null );
c_check( 'free plan chunks at 30', $purger->chunk_size(), 30 );

WP_CLI::log( '== error classification ==' );

$cases = [
	[ 401, [ 'success' => false, 'errors' => [ [ 'code' => 10000, 'message' => 'Authentication error' ] ] ], AuthException::class, false ],
	[ 403, [ 'success' => false, 'errors' => [ [ 'code' => 9109, 'message' => 'Forbidden' ] ] ], AuthException::class, false ],
	[ 400, [ 'success' => false, 'errors' => [ [ 'code' => 1012, 'message' => 'Bad request' ] ] ], ClientException::class, false ],
	[ 500, [ 'success' => false, 'errors' => [ [ 'code' => 0, 'message' => 'Internal error' ] ] ], ServerException::class, true ],
	[ 429, [ 'success' => false, 'errors' => [ [ 'code' => 10000, 'message' => 'Rate limited' ] ] ], RateLimitException::class, true ],
];

foreach ( $cases as [ $status, $body, $expected_class, $retryable ] ) {
	cf_will_answer( $status, $body );

	try {
		$client->purge_cache( 'testzone123', [ 'purge_everything' => true ] );
		c_check( "HTTP {$status} threw", 'no exception', $expected_class );
	} catch ( \Throwable $e ) {
		c_check( "HTTP {$status} maps to " . substr( strrchr( $expected_class, '\\' ), 1 ), get_class( $e ), $expected_class );
		c_check( "HTTP {$status} retryable flag", $e->is_retryable(), $retryable );
	}
}

WP_CLI::log( '== 429 carries Retry-After ==' );
cf_will_answer(
	429,
	[ 'success' => false, 'errors' => [ [ 'code' => 10000, 'message' => 'Rate limited' ] ] ],
	[ 'retry-after' => '42' ]
);

try {
	$client->purge_cache( 'testzone123', [ 'purge_everything' => true ] );
	c_check( 'threw', false, true );
} catch ( RateLimitException $e ) {
	c_check( 'Retry-After parsed', $e->retry_after(), 42 );
}

WP_CLI::log( '== a 429 is never downgraded to a skip ==' );

cf_will_answer(
	429,
	[ 'success' => false, 'errors' => [ [ 'code' => 971, 'message' => 'Rate limited' ] ] ],
	[ 'retry-after' => '15' ]
);

$result = $purger->purge_urls( [ 'https://wordpress.local/limited/' ] );

c_true( 'reported as a failure, not a skip', $result->has_failures() );
c_check( 'no skips', count( $result->skipped() ), 0 );
c_true( 'retryable', $result->is_retryable() );
c_check( 'Retry-After survives into the result', $result->get_retry_after(), 15 );

WP_CLI::log( '== transport failure is retryable ==' );
cf_will_fail_transport();

try {
	$client->purge_cache( 'testzone123', [ 'purge_everything' => true ] );
	c_check( 'threw', false, true );
} catch ( \Throwable $e ) {
	c_check( 'maps to TransportException', get_class( $e ), TransportException::class );
	c_true( 'retryable', $e->is_retryable() );
}

WP_CLI::log( '== HTTP 200 with success:false is still a failure ==' );
cf_will_answer( 200, [ 'success' => false, 'errors' => [ [ 'code' => 1012, 'message' => 'Nope' ] ] ] );

try {
	$client->purge_cache( 'testzone123', [ 'purge_everything' => true ] );
	c_check( '200 with success:false threw', false, true );
} catch ( \Throwable $e ) {
	c_true( '200 with success:false is treated as an error', $e instanceof ClientException );
}

WP_CLI::log( '== 971 and 1134 are skips ==' );

cf_will_answer( 400, [ 'success' => false, 'errors' => [ [ 'code' => 971, 'message' => 'Not in zone' ] ] ] );

$result = $purger->purge_urls( [ 'https://elsewhere.example/a/' ] );

c_true( 'no failures', ! $result->has_failures() );
c_check( 'one skip', count( $result->skipped() ), 1 );
WP_CLI::log( '  summary: ' . $result->summary() );

WP_CLI::log( '== successful purge ==' );

OmcCfTally::$calls = 0;
cf_will_answer( 200, [ 'success' => true, 'result' => [ 'id' => 'testzone123' ] ] );

$result = $purger->purge_urls( [ 'https://wordpress.local/a/', 'https://wordpress.local/b/' ] );

c_check( 'one API call for the chunk', OmcCfTally::$calls, 1 );
c_check( 'both urls reported purged', count( $result->succeeded() ), 2 );
c_true( 'request body carries files', str_contains( OmcCfTally::$last_body, 'files' ) );

WP_CLI::log( '== a failed chunk fails all of its urls, and only those ==' );

cf_will_answer( 500, [ 'success' => false, 'errors' => [ [ 'code' => 0, 'message' => 'boom' ] ] ] );

$result = $purger->purge_urls( [ 'https://wordpress.local/x/', 'https://wordpress.local/y/' ] );

c_check( 'both urls failed together', count( $result->failed_urls() ), 2 );
c_true( 'retryable', $result->is_retryable() );

$coordinator = Plugin::instance()->container()->get( 'coordinator' );

WP_CLI::log( '== a bad token is not retried ==' );

wp_clear_scheduled_hook( Scheduler::HOOK_PURGE );
cf_will_answer( 401, [ 'success' => false, 'errors' => [ [ 'code' => 10000, 'message' => 'Authentication error' ] ] ] );

$result = $coordinator->run( [ 'https://wordpress.local/z/' ], false, 1, 'cf-test' );

c_true( 'failed', $result->has_failures() );
c_check( 'nothing scheduled', wp_next_scheduled( Scheduler::HOOK_PURGE, [ [ 'https://wordpress.local/z/' ], false, 2, 'cf-test' ] ), false );
c_check( 'token flagged', Options::cf_state( 'token_status' ), 'invalid' );

WP_CLI::log( '== a 429 is retried after Retry-After ==' );

cf_will_answer(
	429,
	[ 'success' => false, 'errors' => [ [ 'code' => 971, 'message' => 'Rate limited' ] ] ],
	[ 'retry-after' => '300' ]
);

$before = time();
$coordinator->run( [ 'https://wordpress.local/rate/' ], false, 1, 'cf-test' );
$next = wp_next_scheduled( Scheduler::HOOK_PURGE, [ [ 'https://wordpress.local/rate/' ], false, 2, 'cf-test' ] );

c_true( 'retry scheduled', false !== $next );
c_true( 'no sooner than Retry-After', $next >= $before + 300 );
c_check( 'last purge says it will retry', Options::last_purge()['retrying'] ?? null, true );

wp_clear_scheduled_hook( Scheduler::HOOK_PURGE, [ [ 'https://wordpress.local/rate/' ], false, 2, 'cf-test' ] );

foreach ( [ Options::OPTION_SECRETS => $saved_secrets, Options::OPTION_CF_STATE => $saved_state, Options::OPTION_LAST_PURGE => $saved_last ] as $option => $value ) {
	if ( false === $value ) {
		delete_option( $option );
	} else {
		update_option( $option, $value, false );
	}
}

WP_CLI::log( '' );
WP_CLI::log( OmcCfTally::$pass . ' passed, ' . OmcCfTally::$fail . ' failed' );

if ( OmcCfTally::$fail > 0 ) {
	WP_CLI::halt( 1 );
}
