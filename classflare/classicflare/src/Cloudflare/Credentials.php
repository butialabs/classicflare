<?php
/**
 * Cloudflare authentication.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Cloudflare;

use ClassicFlare\Support\Options;

defined( 'ABSPATH' ) || exit;

final class Credentials {

	public static function token(): string {
		return Options::secret( 'cf_api_token' );
	}

	public static function token_source(): string {
		return Options::secret_source( 'cf_api_token' );
	}

	public static function token_is_external(): bool {
		return Options::secret_is_external( 'cf_api_token' );
	}

	public static function configured(): bool {
		return '' !== self::token();
	}

	/**
	 * @return array<string, string>
	 */
	public static function headers(): array {
		$token = self::token();

		return '' === $token ? [] : [ 'Authorization' => 'Bearer ' . $token ];
	}

	public static function zone_id(): string {
		$external = Options::external( 'cf_zone_id' );

		if ( null !== $external && '' !== $external ) {
			return $external;
		}

		return (string) Options::cf_state( 'zone_id', '' );
	}

	public static function zone_name(): string {
		return (string) Options::cf_state( 'zone_name', '' );
	}

	public static function account_id(): string {
		return (string) Options::cf_state( 'account_id', '' );
	}
}
