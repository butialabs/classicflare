<?php
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'YEAR_IN_SECONDS', 31536000 );

$GLOBALS['__options'] = [];
$GLOBALS['__filters'] = [];

function get_option( $name, $default = false ) {
	return $GLOBALS['__options'][ $name ] ?? $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['__options'][ $name ] = $value;
	return true;
}
function add_option( $name, $value, $x = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['__options'] ) ) {
		return false;
	}
	$GLOBALS['__options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['__options'][ $name ] );
	return true;
}
function get_site_option( $name, $default = false ) {
	return get_option( $name, $default );
}
function maybe_serialize( $v ) {
	return is_array( $v ) || is_object( $v ) ? serialize( $v ) : $v;
}
function apply_filters( $tag, $value, ...$rest ) {
	return $value;
}
function add_filter( $tag, $cb, $p = 10, $a = 1 ) {
	return true;
}
function add_action( $tag, $cb, $p = 10, $a = 1 ) {
	return true;
}
function do_action( $tag, ...$args ) {
	return null;
}
function wp_parse_url( $url, $component = -1 ) {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}
function home_url( $path = '' ) {
	return 'https://example.com' . $path;
}
function wp_json_encode( $data ) {
	return json_encode( $data );
}
function wp_rand( $min = 0, $max = 0 ) {
	return random_int( $min, $max );
}
function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, (array) $args );
}
function __( $text, $domain = null ) {
	return $text;
}
function _n( $single, $plural, $number, $domain = null ) {
	return 1 === $number ? $single : $plural;
}
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function sanitize_text_field( $t ) { return trim( wp_strip_all_tags( (string) $t ) ); }
function wp_strip_all_tags( $t ) { return strip_tags( (string) $t ); }
function sanitize_key( $k ) { return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function user_trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }

final class FakeSitemapIndex {
	public function get_sitemap_list(): array {
		return [
			[ 'loc' => 'https://example.com/wp-sitemap-posts-post-1.xml' ],
			[ 'loc' => 'https://example.com/wp-sitemap-posts-post-2.xml' ],
			[ 'loc' => 'https://example.com/wp-sitemap-posts-page-1.xml' ],
			[ 'loc' => 'https://example.com/wp-sitemap-taxonomies-category-1.xml' ],
			[ 'loc' => 'https://example.com/wp-sitemap-users-1.xml' ],
		];
	}
}
final class FakeSitemapServer {
	public FakeSitemapIndex $index;
	public function __construct() { $this->index = new FakeSitemapIndex(); }
	public function sitemaps_enabled(): bool { return true; }
}
function wp_sitemaps_get_server() { return new FakeSitemapServer(); }
$GLOBALS['__cron'] = [];
function wp_next_scheduled( $hook, $args = [] ) { return $GLOBALS['__cron'][ $hook ] ?? false; }
function wp_schedule_event( $ts, $recurrence, $hook, $args = [] ) { $GLOBALS['__cron'][ $hook ] = $ts; return true; }
function wp_schedule_single_event( $ts, $hook, $args = [] ) { $GLOBALS['__cron'][ $hook ] = $ts; return true; }
function wp_clear_scheduled_hook( $hook, $args = [] ) { unset( $GLOBALS['__cron'][ $hook ] ); return true; }

$GLOBALS['__http'] = [];
final class WP_Error {}
function wp_remote_get( $url, $args = [] ) { $GLOBALS['__http'][] = $url; return new WP_Error(); }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

function get_object_taxonomies( $type, $output = 'names' ) {
	return 'product' === $type ? [ 'product_cat' ] : [ 'category', 'post_tag' ];
}

final class WP_Post {
	public function __construct( public string $post_type = 'post', public int $ID = 1 ) {}
}
function get_transient( $k ) { return $GLOBALS['__options'][ '_t_' . $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__options'][ '_t_' . $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__options'][ '_t_' . $k ] ); return true; }

spl_autoload_register( function ( string $class ): void {
	if ( ! str_starts_with( $class, 'ClassicFlare\\' ) ) {
		return;
	}
	$rel  = str_replace( '\\', '/', substr( $class, strlen( 'ClassicFlare\\' ) ) );
	$path = dirname( __DIR__ ) . '/src/' . $rel . '.php';
	if ( is_readable( $path ) ) {
		require_once $path;
	}
} );

use ClassicFlare\Cloudflare\PurgeResult;
use ClassicFlare\Purge\Coordinator;
use ClassicFlare\Purge\Scheduler;
use ClassicFlare\Purge\Sitemaps;
use ClassicFlare\Support\Options;
use ClassicFlare\Support\Redactor;
use ClassicFlare\Support\Url;

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
	echo "  FAIL {$label}\n";
	echo "       expected: " . var_export( $expected, true ) . "\n";
	echo "       actual:   " . var_export( $actual, true ) . "\n";
}

function check_true( string $label, $actual ): void {
	check( $label, (bool) $actual, true );
}

echo "== Url::normalize ==\n";
check( 'absolute url kept', Url::normalize( 'https://example.com/a/' ), 'https://example.com/a/' );
check( 'host lowercased', Url::normalize( 'https://EXAMPLE.com/A/' ), 'https://example.com/A/' );
check( 'root-relative resolved', Url::normalize( '/hello/' ), 'https://example.com/hello/' );
check( 'empty path becomes slash', Url::normalize( 'https://example.com' ), 'https://example.com/' );
check( 'fragment dropped', Url::normalize( 'https://example.com/a/#top' ), 'https://example.com/a/' );
check( 'query kept', Url::normalize( 'https://example.com/a/?x=1' ), 'https://example.com/a/?x=1' );
check( 'garbage rejected', Url::normalize( 'not a url' ), '' );
check( 'empty rejected', Url::normalize( '' ), '' );

echo "== Url::normalize_all dedupes ==\n";
check(
	'duplicates collapse',
	Url::normalize_all( [ 'https://example.com/a/', 'https://EXAMPLE.com/a/', '/b/' ] ),
	[ 'https://example.com/a/', 'https://example.com/b/' ]
);

echo "== Url feed variants ==\n";
check(
	'feed variants',
	Url::feed_variants( 'https://example.com/p' ),
	[ 'https://example.com/p/feed/', 'https://example.com/p/feed/atom/', 'https://example.com/p/feed/rdf/' ]
);
check( 'no feed/ appended to a plain permalink', Url::feed_variants( 'https://example.com/?p=1' ), [] );
check( 'HTML-escaped ampersands are decoded', Url::normalize( 'https://example.com/?feed=rss2&amp;cat=1' ), 'https://example.com/?feed=rss2&cat=1' );

echo "== PurgeResult semantics ==\n";
$r = PurgeResult::make();
$r->succeed( 'https://example.com/a/' );
$r->skip( 'https://example.com/b/', 'Not cached.' );
$r->fail( 'https://example.com/c/', 'boom' );
check( 'only failures are retried', $r->failed_urls(), [ 'https://example.com/c/' ] );
check_true( 'has failures', $r->has_failures() );
check( 'succeeded count', count( $r->succeeded() ), 1 );
check( 'skipped count', count( $r->skipped() ), 1 );
check_true( 'skip is not a failure', ! in_array( 'https://example.com/b/', $r->failed_urls(), true ) );

$clean = PurgeResult::make();
$clean->skip( 'https://example.com/x/', 'Not cached.' );
check_true( 'all-skipped result has no failures', ! $clean->has_failures() );

$fatal = PurgeResult::fatal( [ 'https://example.com/a/', 'https://example.com/b/' ], 'cloudflare down' );
check( 'fatal moves every url to failed', count( $fatal->failed_urls() ), 2 );
check_true( 'fatal is retryable by default', $fatal->is_retryable() );

$hard = PurgeResult::fatal( [ 'https://example.com/a/' ], 'bad token', false );
check_true( 'non-retryable fatal', ! $hard->is_retryable() );

echo "== PurgeResult::merge ==\n";
$a = PurgeResult::make()->succeed( 'https://example.com/1/' );
$b = PurgeResult::make()->fail( 'https://example.com/2/', 'nope' )->retry_after( 30 );
$a->merge( $b );
check( 'merged failures', $a->failed_urls(), [ 'https://example.com/2/' ] );
check( 'merged retry_after', $a->get_retry_after(), 30 );

echo "== Redactor ==\n";
check( 'bearer token masked', Redactor::scrub( 'Authorization: Bearer abcdefghijklmnop1234' ), 'Authorization: Bearer [redacted]' );
check_true( 'json token masked', str_contains( Redactor::scrub( '{"api_token":"supersecretvalue123"}' ), '[redacted]' ) );
check( 'plain text untouched', Redactor::scrub( 'nothing secret here' ), 'nothing secret here' );
check( 'mask keeps last four', Redactor::mask( 'abcdefgh1234' ), str_repeat( "\u{2022}", 4 ) . '1234' );

echo "== Options defaults and budget ==\n";
$defaults = Options::defaults();
check( 'automatic purging is on', $defaults['enabled'], true );
check( 'feeds are purged by default', $defaults['purge']['feeds'], true );
check( 'sitemaps are purged by default', $defaults['purge']['sitemaps'], true );

$footprint = Options::settings_footprint();
echo "  autoloaded settings footprint: {$footprint} bytes (budget " . Options::SETTINGS_BUDGET_BYTES . ")\n";
check_true( 'settings fit the autoload budget', $footprint <= Options::SETTINGS_BUDGET_BYTES );

echo "== Options dot paths ==\n";
check( 'nested get', Options::get( 'purge.pagination_depth' ), 5 );
check( 'missing path returns default', Options::get( 'nope.nope', 'fallback' ), 'fallback' );
check( 'flag coerces', Options::flag( 'enabled' ), true );

echo "== Custom purge paths ==\n";
$parsed = Options::sanitize_custom_urls( "llms.txt\n\n  /sitemap.xml  \nllms.txt\n/shop/*\n" );
check( 'bare filename gets a leading slash', $parsed['text'], "/llms.txt\n/sitemap.xml" );
check( 'wildcards are rejected', $parsed['rejected'], [ '/shop/*' ] );

Options::save_custom_urls( $parsed['text'] );
check(
	'stored paths resolve to absolute URLs',
	Options::custom_urls(),
	[ 'https://example.com/llms.txt', 'https://example.com/sitemap.xml' ]
);

Options::save_custom_urls( '' );
check( 'an empty field yields no URLs', Options::custom_urls(), [] );

echo "== Sitemaps ==\n";
$sitemaps = new Sitemaps();
check( 'core is detected when nothing else generates them', $sitemaps->provider(), 'core' );
check( 'nothing discovered yet', $sitemaps->discovered(), [] );
check( 'so a change still clears the index', $sitemaps->index(), [ 'https://example.com/wp-sitemap.xml' ] );

update_option(
	Sitemaps::OPTION_INDEX,
	[
		'provider' => 'core',
		'index'    => 'https://example.com/wp-sitemap.xml',
		'time'     => time(),
		'urls'     => [
			'https://example.com/post-sitemap.xml',
			'https://example.com/post-sitemap2.xml',
			'https://example.com/post-archive-sitemap.xml',
			'https://example.com/page-sitemap.xml',
			'https://example.com/product-sitemap.xml',
			'https://example.com/category-sitemap.xml',
			'https://example.com/post_tag-sitemap.xml',
			'https://example.com/product_cat-sitemap.xml',
			'https://example.com/news-sitemap.xml',
			'https://example.com/author-sitemap.xml',
			'https://example.com/wp-sitemap-posts-post-3.xml',
			'https://example.com/wp-sitemap-taxonomies-category-1.xml',
			'https://example.com/wp-sitemap-users-1.xml',
		],
	],
	false
);

check(
	'a post takes its own pages, its taxonomies, the author file and news',
	$sitemaps->for_post( new WP_Post( 'post' ) ),
	[
		'https://example.com/wp-sitemap.xml',
		'https://example.com/post-sitemap.xml',
		'https://example.com/post-sitemap2.xml',
		'https://example.com/post-archive-sitemap.xml',
		'https://example.com/category-sitemap.xml',
		'https://example.com/post_tag-sitemap.xml',
		'https://example.com/news-sitemap.xml',
		'https://example.com/author-sitemap.xml',
		'https://example.com/wp-sitemap-posts-post-3.xml',
		'https://example.com/wp-sitemap-taxonomies-category-1.xml',
		'https://example.com/wp-sitemap-users-1.xml',
	]
);

check(
	'a custom post type takes its own file and its own taxonomy',
	$sitemaps->for_post( new WP_Post( 'product' ) ),
	[
		'https://example.com/wp-sitemap.xml',
		'https://example.com/product-sitemap.xml',
		'https://example.com/product_cat-sitemap.xml',
		'https://example.com/news-sitemap.xml',
		'https://example.com/author-sitemap.xml',
		'https://example.com/wp-sitemap-users-1.xml',
	]
);

check(
	'a term change takes the taxonomy files and leaves news alone',
	$sitemaps->for_taxonomy( 'category' ),
	[
		'https://example.com/wp-sitemap.xml',
		'https://example.com/category-sitemap.xml',
		'https://example.com/wp-sitemap-taxonomies-category-1.xml',
	]
);

check( 'and everything is everything', count( $sitemaps->all() ), 14 );

delete_option( Sitemaps::OPTION_INDEX );


echo "== Post type switches ==\n";
check( 'everything is on by default', Options::post_type_enabled( 'product' ), true );
$settings                            = Options::all();
$settings['purge']['post_types_off'] = [ 'product' ];
Options::save( $settings );
check( 'a switched-off type', Options::post_type_enabled( 'product' ), false );
check( 'its neighbours are unaffected', Options::post_type_enabled( 'post' ), true );
$settings['purge']['post_types_off'] = [];
Options::save( $settings );


echo "== Cron events ==\n";
$GLOBALS['__cron'] = [];
Scheduler::schedule();
check_true( 'the sitemap refresh is scheduled', wp_next_scheduled( Scheduler::HOOK_SITEMAPS ) );
Scheduler::unschedule();
check( 'and unscheduled cleanly', wp_next_scheduled( Scheduler::HOOK_SITEMAPS ), false );
check_true( 'a retry can be scheduled', Scheduler::purge_later( [ 'https://example.com/a/' ], false, 2, 'test', 60 ) );
check_true( 'it lands on the purge hook', wp_next_scheduled( Scheduler::HOOK_PURGE ) );

echo "== Retry delays grow, and honour Retry-After ==\n";
check( 'first retry after a minute', Coordinator::retry_delay( 1 ), 60 );
check( 'then two', Coordinator::retry_delay( 2 ), 120 );
check( 'Retry-After wins when longer', Coordinator::retry_delay( 1, 900 ), 900 );
check( 'but never shortens the backoff', Coordinator::retry_delay( 2, 5 ), 120 );
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
