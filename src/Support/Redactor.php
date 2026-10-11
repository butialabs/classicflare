<?php
/**
 * Secret redaction for anything that can reach storage or the screen.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Support;

defined( 'ABSPATH' ) || exit;

final class Redactor {

	private const MASK = '[redacted]';

	/**
	 * @param string $text Arbitrary text, typically an exception message or API body.
	 */
	public static function scrub( string $text ): string {
		if ( '' === $text ) {
			return $text;
		}

		$needles = [];
		foreach ( Options::SECRET_KEYS as $key ) {
			$value = Options::secret( $key );
			if ( strlen( $value ) >= 8 ) {
				$needles[] = $value;
			}
		}

		if ( $needles ) {
			$text = str_replace( $needles, self::MASK, $text );
		}

		$patterns = [
			'/(Bearer\s+)[A-Za-z0-9_\-\.]{16,}/i',
			'/(X-Auth-Key["\':\s]+)[A-Za-z0-9_\-]{16,}/i',
			'/(X-Auth-Email["\':\s]+)[^\s"\',]+/i',
			'/(["\'](?:token|api_token|api_key|secret|password)["\']\s*:\s*["\'])[^"\']{8,}/i',
		];

		foreach ( $patterns as $pattern ) {
			$text = (string) preg_replace( $pattern, '$1' . self::MASK, $text );
		}

		return $text;
	}

	/**
	 * @param string $secret Raw secret.
	 */
	public static function mask( string $secret ): string {
		$length = strlen( $secret );

		if ( 0 === $length ) {
			return '';
		}

		if ( $length <= 4 ) {
			return str_repeat( "\u{2022}", $length );
		}

		return str_repeat( "\u{2022}", 4 ) . substr( $secret, -4 );
	}
}
