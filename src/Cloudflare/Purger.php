<?php
/**
 * Cloudflare cache purger.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Cloudflare;

use ClassicFlare\Cloudflare\Exception\ApiException;
use ClassicFlare\Cloudflare\Exception\AuthException;
use ClassicFlare\Cloudflare\Exception\RateLimitException;
use ClassicFlare\Cloudflare\Exception\ServerException;
use ClassicFlare\Cloudflare\Exception\TransportException;
use ClassicFlare\Support\Lock;
use ClassicFlare\Support\Options;

defined( 'ABSPATH' ) || exit;

final class Purger {

	public const CHUNK            = 30;
	public const CHUNK_ENTERPRISE = 500;

	private const SKIP_CODES = [ 971, 1134 ];

	private Client $client;

	/**
	 * @param Client|null $client Injectable for tests.
	 */
	public function __construct( ?Client $client = null ) {
		$this->client = $client ?? new Client();
	}

	public function chunk_size(): int {
		return 'enterprise' === strtolower( (string) Options::cf_state( 'plan', '' ) )
			? self::CHUNK_ENTERPRISE
			: self::CHUNK;
	}

	/**
	 * @return array{reason: string, hint: string}|null
	 */
	public function unavailable(): ?array {
		if ( ! Credentials::configured() ) {
			return [
				'reason' => __( 'No Cloudflare API token is configured.', 'classicflare' ),
				'hint'   => __( 'Paste a token on the settings screen, or define CLASSICFLARE_CF_API_TOKEN.', 'classicflare' ),
			];
		}

		if ( '' === Credentials::zone_id() ) {
			return [
				'reason' => __( 'No Cloudflare zone has been found for this site.', 'classicflare' ),
				'hint'   => __( 'Connect on the settings screen, or define CLASSICFLARE_CF_ZONE_ID.', 'classicflare' ),
			];
		}

		if ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL ) {
			$allowed = defined( 'WP_ACCESSIBLE_HOSTS' ) ? (string) constant( 'WP_ACCESSIBLE_HOSTS' ) : '';

			if ( ! str_contains( $allowed, 'api.cloudflare.com' ) ) {
				return [
					'reason' => __( 'WP_HTTP_BLOCK_EXTERNAL is on and api.cloudflare.com is not in WP_ACCESSIBLE_HOSTS.', 'classicflare' ),
					'hint'   => __( 'Add api.cloudflare.com to WP_ACCESSIBLE_HOSTS in wp-config.php.', 'classicflare' ),
				];
			}
		}

		return null;
	}

	/**
	 * @param array<int, string> $urls Absolute URLs.
	 */
	public function purge_urls( array $urls ): PurgeResult {
		$urls = array_values( array_unique( $urls ) );

		if ( ! $urls ) {
			return PurgeResult::make();
		}

		$zone = Credentials::zone_id();

		if ( '' === $zone ) {
			return PurgeResult::fatal( $urls, __( 'No Cloudflare zone is configured.', 'classicflare' ), false );
		}

		$result = PurgeResult::make();

		foreach ( array_chunk( $urls, $this->chunk_size() ) as $chunk ) {
			$result->merge( $this->purge_chunk( $zone, $chunk ) );
		}

		return $result;
	}

	public function purge_all(): PurgeResult {
		$zone = Credentials::zone_id();

		if ( '' === $zone ) {
			return PurgeResult::fatal( [], __( 'No Cloudflare zone is configured.', 'classicflare' ), false );
		}

		if ( ! Lock::acquire( 'cf_purge_all_' . $zone, 60 ) ) {
			return PurgeResult::make()->note( __( 'A full Cloudflare purge already ran moments ago; skipping.', 'classicflare' ) );
		}

		try {
			$this->client->purge_cache( $zone, [ 'purge_everything' => true ] );
		} catch ( ApiException $e ) {
			Lock::release( 'cf_purge_all_' . $zone );

			return $this->from_exception( [], $e );
		}

		return PurgeResult::make()->note( __( 'Everything in the Cloudflare cache was purged.', 'classicflare' ) );
	}

	/**
	 * @param string             $zone  Zone id.
	 * @param array<int, string> $chunk Up to 30 URLs.
	 */
	private function purge_chunk( string $zone, array $chunk ): PurgeResult {
		$result = PurgeResult::make();

		try {
			$this->client->purge_cache( $zone, [ 'files' => array_values( $chunk ) ] );
		} catch ( ApiException $e ) {
			return $this->from_exception( $chunk, $e );
		}

		foreach ( $chunk as $url ) {
			$result->succeed( $url );
		}

		return $result;
	}

	/**
	 * @param array<int, string> $urls URLs that were in flight.
	 * @param ApiException       $e    The exception.
	 */
	private function from_exception( array $urls, ApiException $e ): PurgeResult {
		$codes = $e->error_codes();

		$is_about_the_request = $e instanceof RateLimitException
			|| $e instanceof AuthException
			|| $e instanceof ServerException
			|| $e instanceof TransportException;

		if ( $e instanceof AuthException ) {
			Options::set_cf_state( [ 'token_status' => 'invalid' ] );
		}

		if ( ! $is_about_the_request && $codes && ! array_diff( $codes, self::SKIP_CODES ) ) {
			$result = PurgeResult::make();

			foreach ( $urls as $url ) {
				$result->skip( $url, __( 'Not in this Cloudflare zone, or not purgeable.', 'classicflare' ) );
			}

			return $result->note(
				__( 'Cloudflare does not recognise these URLs as belonging to the configured zone. Check that the hostname is proxied (orange cloud).', 'classicflare' )
			);
		}

		return PurgeResult::fatal( $urls, $e->getMessage(), $e->is_retryable() )
			->retry_after( $e->retry_after() );
	}
}
