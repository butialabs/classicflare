<?php
/**
 * @package ClassicFlare
 */

use ClassicFlare\Plugin;
use ClassicFlare\Purge\Sitemaps;
use ClassicFlare\Support\Options;

final class OmcSitemapTally {
	public static int $pass = 0;
	public static int $fail = 0;
}

function s_check( string $label, $actual, $expected ): void {
	if ( $actual === $expected ) {
		++OmcSitemapTally::$pass;
		WP_CLI::log( "  ok   {$label}" );
		return;
	}
	++OmcSitemapTally::$fail;
	WP_CLI::log( "  FAIL {$label}" );
	WP_CLI::log( '       expected: ' . var_export( $expected, true ) );
	WP_CLI::log( '       actual:   ' . var_export( $actual, true ) );
}

function s_true( string $label, $actual ): void {
	s_check( $label, (bool) $actual, true );
}

final class OmcSitemapSent {
	/** @var array<int, string> */
	public static array $files = [];
}

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( ! str_contains( (string) $url, 'api.cloudflare.com' ) ) {
			return $preempt;
		}

		$body = json_decode( (string) ( $args['body'] ?? '' ), true );

		OmcSitemapSent::$files = array_merge( OmcSitemapSent::$files, (array) ( $body['files'] ?? [] ) );

		return [
			'headers'  => [],
			'body'     => wp_json_encode( [ 'success' => true, 'errors' => [], 'result' => [ 'id' => 'testzone' ] ] ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	},
	10,
	3
);

$sitemaps = new Sitemaps();
$provider = $sitemaps->provider();

WP_CLI::log( '== detection ==' );
WP_CLI::log( '  provider: ' . ( '' === $provider ? '(none)' : $provider ) . ' — ' . $sitemaps->provider_label() );
WP_CLI::log( '  index:    ' . $sitemaps->index_url() );

if ( '' === $provider ) {
	WP_CLI::warning( 'No sitemap generator is active, so there is nothing to test here.' );
	WP_CLI::halt( 0 );
}

$saved_settings = get_option( Options::OPTION_SETTINGS, false );
$saved_secrets  = get_option( Options::OPTION_SECRETS, false );
$saved_state    = get_option( Options::OPTION_CF_STATE, false );

Options::save_secrets( [ 'cf_api_token' => 'test-token-not-a-real-one' ] );
Options::set_cf_state( [ 'zone_id' => 'testzone', 'zone_name' => 'test', 'plan' => 'free' ] );

$settings                            = Options::all();
$settings['enabled']                 = true;
$settings['purge']['sitemaps']       = true;
$settings['purge']['post_types_off'] = [];
Options::save( $settings );
Options::flush();

$plugin = Plugin::instance();

if ( ! $plugin ) {
	WP_CLI::error( 'Plugin is not booted.' );
}

WP_CLI::log( '== discovery ==' );

$discovered = $sitemaps->refresh();

foreach ( $discovered as $entry ) {
	WP_CLI::log( '    ' . $entry );
}

s_true( 'the index listed something', count( $discovered ) > 0 );
s_true( 'and it was stored', $sitemaps->discovered() === $discovered );

foreach ( $discovered as $entry ) {
	$response = wp_remote_get( $entry, [ 'sslverify' => false, 'timeout' => 30 ] );
	$code     = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response );

	s_check( "{$entry} answers", $code, 200 );
}

WP_CLI::log( '== what a post change clears ==' );

$author_id = (int) ( get_users( [ 'number' => 1, 'fields' => 'ID' ] )[0] ?? 1 );

$post_id = wp_insert_post(
	[
		'post_title'    => 'ClassicFlare sitemap test',
		'post_content'  => 'Testing.',
		'post_status'   => 'publish',
		'post_type'     => 'post',
		'post_author'   => $author_id,
		'post_category' => [ (int) get_option( 'default_category' ) ],
	]
);

if ( is_wp_error( $post_id ) || ! $post_id ) {
	WP_CLI::error( 'Could not create the test post.' );
}

$post = get_post( $post_id );
$urls = $sitemaps->for_post( $post );

foreach ( $urls as $url ) {
	WP_CLI::log( '    ' . $url );
}

s_true( 'the index is in there', in_array( $sitemaps->index_url(), $urls, true ) );

foreach ( $discovered as $entry ) {
	$name = basename( (string) wp_parse_url( $entry, PHP_URL_PATH ) );

	if ( preg_match( '/(^|[-_])news([-_.]|$)/i', $name ) ) {
		s_true( "news sitemap {$name} is cleared", in_array( $entry, $urls, true ) );
	}

	if ( preg_match( '/^post-sitemap\d*\.xml$/i', $name ) || preg_match( '/^wp-sitemap-posts-post-\d+\.xml$/i', $name ) ) {
		s_true( "post sitemap {$name} is cleared", in_array( $entry, $urls, true ) );
	}

	if ( preg_match( '/^(category|post_tag|wp-sitemap-taxonomies)/i', $name ) ) {
		s_true( "taxonomy sitemap {$name} is cleared", in_array( $entry, $urls, true ) );
	}
}

$custom = [];

foreach ( get_post_types( [ 'public' => true ], 'names' ) as $type ) {
	if ( ! in_array( $type, [ 'post', 'page', 'attachment' ], true ) ) {
		$custom[] = $type;
	}
}

if ( $custom ) {
	WP_CLI::log( '== custom post type: ' . $custom[0] . ' ==' );

	$cpt_id = wp_insert_post(
		[
			'post_title'  => 'ClassicFlare custom type test',
			'post_status' => 'publish',
			'post_type'   => $custom[0],
			'post_author' => $author_id,
		]
	);

	$cpt_urls = $sitemaps->for_post( get_post( $cpt_id ) );

	foreach ( $cpt_urls as $url ) {
		WP_CLI::log( '    ' . $url );
	}

	$own = array_filter(
		$discovered,
		static function ( string $entry ) use ( $custom ): bool {
			$name = basename( (string) wp_parse_url( $entry, PHP_URL_PATH ) );

			return str_starts_with( $name, $custom[0] . '-sitemap' ) || str_contains( $name, '-' . $custom[0] . '-' );
		}
	);

	foreach ( $own as $entry ) {
		s_true( basename( (string) wp_parse_url( $entry, PHP_URL_PATH ) ) . ' is cleared', in_array( $entry, $cpt_urls, true ) );
	}

	s_true(
		'and the sitemap for ordinary posts is not',
		! in_array( home_url( '/post-sitemap.xml' ), $cpt_urls, true )
	);

	wp_delete_post( (int) $cpt_id, true );
}

WP_CLI::log( '== a post edit sends them to Cloudflare ==' );

OmcSitemapSent::$files = [];

wp_update_post(
	[
		'ID'         => $post_id,
		'post_title' => 'ClassicFlare sitemap test, edited',
	]
);

do_action( 'shutdown' );

s_check( 'no sitemap was left out', array_values( array_diff( $urls, OmcSitemapSent::$files ) ), [] );
s_true( 'the permalink went too', in_array( get_permalink( $post_id ), OmcSitemapSent::$files, true ) );
s_true( 'and the site feed', in_array( get_feed_link(), OmcSitemapSent::$files, true ) );

WP_CLI::log( '== post type switched off ==' );

$settings                            = Options::all();
$settings['purge']['post_types_off'] = [ 'post' ];
Options::save( $settings );
Options::flush();

s_check( 'post is off', Options::post_type_enabled( 'post' ), false );
s_check( 'page is still on', Options::post_type_enabled( 'page' ), true );

OmcSitemapSent::$files = [];

wp_update_post(
	[
		'ID'         => $post_id,
		'post_title' => 'ClassicFlare sitemap test, edited while off',
	]
);

do_action( 'shutdown' );

s_check( 'nothing was sent for a switched-off type', OmcSitemapSent::$files, [] );

wp_delete_post( (int) $post_id, true );
do_action( 'shutdown' );

foreach ( [ Options::OPTION_SETTINGS => $saved_settings, Options::OPTION_SECRETS => $saved_secrets, Options::OPTION_CF_STATE => $saved_state ] as $option => $value ) {
	if ( false === $value ) {
		delete_option( $option );
	} else {
		update_option( $option, $value, Options::OPTION_SETTINGS === $option );
	}
}

WP_CLI::log( '' );
WP_CLI::log( OmcSitemapTally::$pass . ' passed, ' . OmcSitemapTally::$fail . ' failed' );

if ( OmcSitemapTally::$fail > 0 ) {
	WP_CLI::halt( 1 );
}
