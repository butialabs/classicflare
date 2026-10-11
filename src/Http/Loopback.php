<?php
/**
 * Requests this site makes to itself.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Http;

use ClassicFlare\Support\Url;

defined( 'ABSPATH' ) || exit;

final class Loopback {

	/**
	 * @param string               $url   URL about to be fetched.
	 * @param array<string, mixed> $extra Additional wp_remote_get arguments.
	 * @return array<string, mixed>
	 */
	public static function args( string $url, array $extra = [] ): array {
		$args = [ 'timeout' => 10 ];

		if ( Url::is_same_host( $url ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$args['sslverify'] = (bool) apply_filters( 'https_local_ssl_verify', false );
		}

		return array_merge( $args, $extra );
	}
}
