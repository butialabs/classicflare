<?php
/**
 * Work out which Cloudflare zone this site belongs to.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Cloudflare;

use ClassicFlare\Cloudflare\Exception\ApiException;
use ClassicFlare\Support\Options;

defined( 'ABSPATH' ) || exit;

final class ZoneResolver {

	public function __construct( private readonly Client $client ) {}

	/**
	 * @param bool $force Ignore the cached value.
	 * @return array{zone_id: string, zone_name: string, account_id: string, plan: string}|null
	 * @throws ApiException When the API refuses.
	 */
	public function resolve( bool $force = false ): ?array {
		if ( ! $force ) {
			$cached = Options::cf_state();

			if ( is_array( $cached ) && ! empty( $cached['zone_id'] ) ) {
				return [
					'zone_id'    => (string) $cached['zone_id'],
					'zone_name'  => (string) ( $cached['zone_name'] ?? '' ),
					'account_id' => (string) ( $cached['account_id'] ?? '' ),
					'plan'       => (string) ( $cached['plan'] ?? '' ),
				];
			}
		}

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		if ( '' === $host ) {
			return null;
		}

		$zone = $this->walk_labels( $host ) ?? $this->longest_suffix_match( $host );

		if ( null === $zone ) {
			return null;
		}

		$resolved = [
			'zone_id'    => (string) ( $zone['id'] ?? '' ),
			'zone_name'  => (string) ( $zone['name'] ?? '' ),
			'account_id' => (string) ( $zone['account']['id'] ?? '' ),
			'plan'       => strtolower( (string) ( $zone['plan']['legacy_id'] ?? '' ) ),
		];

		Options::set_cf_state( $resolved + [ 'resolved_at' => time() ] );

		return $resolved;
	}

	/**
	 * @param string $host Hostname.
	 * @return array<string, mixed>|null
	 */
	private function walk_labels( string $host ): ?array {
		$labels = explode( '.', $host );

		while ( count( $labels ) >= 2 ) {
			$candidate = implode( '.', $labels );
			$zones     = $this->client->list_zones( $candidate );

			if ( 1 === count( $zones ) ) {
				return (array) $zones[0];
			}

			array_shift( $labels );
		}

		return null;
	}

	/**
	 * @param string $host Hostname.
	 * @return array<string, mixed>|null
	 */
	private function longest_suffix_match( string $host ): ?array {
		$zones = $this->client->list_zones();

		$best   = null;
		$length = 0;

		foreach ( $zones as $zone ) {
			$name = strtolower( (string) ( $zone['name'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			if ( $host === $name || str_ends_with( $host, '.' . $name ) ) {
				if ( strlen( $name ) > $length ) {
					$best   = (array) $zone;
					$length = strlen( $name );
				}
			}
		}

		return $best;
	}

	/**
	 * @return array<int, array{id: string, name: string}>
	 */
	public function available_zones(): array {
		$zones = [];

		foreach ( $this->client->list_zones() as $zone ) {
			$zones[] = [
				'id'   => (string) ( $zone['id'] ?? '' ),
				'name' => (string) ( $zone['name'] ?? '' ),
			];
		}

		return $zones;
	}
}
