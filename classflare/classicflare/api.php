<?php
/**
 * ClassicFlare public API.

 *     do_action( 'classicflare_purge_url', $urls, $args );
 *     do_action( 'classicflare_purge_post', $post_id, $args );
 *     do_action( 'classicflare_purge_all', $args );

 * @package ClassicFlare
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'classicflare_purge_url' ) ) {
	/**
	 * @param string|array<int, string> $urls One URL or a list, absolute or root-relative.
	 * @param array<string, mixed>      $args Options; see the file header.
	 * @return bool False when rejected or, with `now`, when Cloudflare reported a failure.
	 */
	function classicflare_purge_url( string|array $urls, array $args = [] ): bool {
		$args = \ClassicFlare\Api\Facade::args( $args, 'api' );

		return classicflare_result_ok(
			\ClassicFlare\Api\Facade::purge_urls( array_map( 'strval', (array) $urls ), $args['now'], $args['reason'] )
		);
	}
}

if ( ! function_exists( 'classicflare_purge_post' ) ) {
	/**
	 * @param int|\WP_Post         $post Post or post id.
	 * @param array<string, mixed> $args Options; see the file header.
	 * @return bool False when rejected or, with `now`, when Cloudflare reported a failure.
	 */
	function classicflare_purge_post( int|\WP_Post $post, array $args = [] ): bool {
		$args = \ClassicFlare\Api\Facade::args( $args, 'api:post' );

		return classicflare_result_ok(
			\ClassicFlare\Api\Facade::purge_post( $post, $args['now'], $args['reason'] )
		);
	}
}

if ( ! function_exists( 'classicflare_purge_all' ) ) {
	/**
	 * @param array<string, mixed> $args Options; see the file header.
	 * @return bool False when rejected or, with `now`, when Cloudflare reported a failure.
	 */
	function classicflare_purge_all( array $args = [] ): bool {
		$args = \ClassicFlare\Api\Facade::args( $args, 'api:all' );

		return classicflare_result_ok(
			\ClassicFlare\Api\Facade::purge_all( $args['now'], $args['reason'] )
		);
	}
}

if ( ! function_exists( 'classicflare_is_active' ) ) {
	function classicflare_is_active(): bool {
		return \ClassicFlare\Api\Facade::is_active();
	}
}

if ( ! function_exists( 'classicflare_result_ok' ) ) {
	/**
	 * @param \ClassicFlare\Cloudflare\PurgeResult|null $result Result.
	 */
	function classicflare_result_ok( ?\ClassicFlare\Cloudflare\PurgeResult $result ): bool {
		return null !== $result && ! $result->is_fatal() && ! $result->has_failures();
	}
}
