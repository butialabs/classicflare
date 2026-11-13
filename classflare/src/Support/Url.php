<?php
/**
 * URL normalisation.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Support;

defined( 'ABSPATH' ) || exit;

final class Url {

	/**
	 * @param string $url Absolute or root-relative URL.
	 * @return string Empty string when the URL is unusable.
	 */
	public static function normalize( string $url ): string {
		$url = str_replace( '&amp;', '&', trim( $url ) );

		if ( '' === $url ) {
			return '';
		}

		if ( str_starts_with( $url, '/' ) ) {
			$url = home_url( $url );
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? self::site_scheme() ) );
		$host   = strtolower( (string) $parts['host'] );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = (string) ( $parts['path'] ?? '/' );

		if ( '' === $path ) {
			$path = '/';
		}

		$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';

		return $scheme . '://' . $host . $port . $path . $query;
	}

	/**
	 * @param array<int, mixed> $urls Raw URLs.
	 * @return array<int, string>
	 */
	public static function normalize_all( array $urls ): array {
		$out = [];

		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) ) {
				continue;
			}

			$normalized = self::normalize( $url );
			if ( '' !== $normalized ) {
				$out[ $normalized ] = true;
			}
		}

		return array_keys( $out );
	}

	public static function site_scheme(): string {
		$scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );

		return is_string( $scheme ) && '' !== $scheme ? strtolower( $scheme ) : 'http';
	}

	/**
	 * @param string $url Absolute URL.
	 */
	public static function is_same_host( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) && is_string( $home ) && strtolower( $host ) === strtolower( $home );
	}

	/**
	 * @param string $url Absolute URL.
	 * @return array<int, string>
	 */
	public static function feed_variants( string $url ): array {
		if ( str_contains( $url, '?' ) ) {
			return [];
		}

		$base = self::with_trailing_slash( $url );

		return [
			$base . 'feed/',
			$base . 'feed/atom/',
			$base . 'feed/rdf/',
		];
	}

	/**
	 * @param string $url Absolute URL.
	 */
	public static function with_trailing_slash( string $url ): string {
		$query = '';
		$mark  = strpos( $url, '?' );

		if ( false !== $mark ) {
			$query = substr( $url, $mark );
			$url   = substr( $url, 0, $mark );
		}

		return rtrim( $url, '/' ) . '/' . $query;
	}
}
