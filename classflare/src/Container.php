<?php
/**
 * Lazy service locator.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare;

defined( 'ABSPATH' ) || exit;

final class Container {

	/** @var array<string, callable> */
	private array $factories = [];

	/** @var array<string, mixed> */
	private array $resolved = [];

	/**
	 * @param string   $id      Service id.
	 * @param callable $factory Receives the container, returns the service.
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->resolved[ $id ] );
	}

	/**
	 * @param string $id Service id.
	 * @throws \RuntimeException When the id is unknown.
	 * @return mixed
	 */
	public function get( string $id ): mixed {
		if ( array_key_exists( $id, $this->resolved ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \RuntimeException( esc_html( sprintf( 'Unknown service: %s', $id ) ) );
		}

		$this->resolved[ $id ] = ( $this->factories[ $id ] )( $this );

		return $this->resolved[ $id ];
	}

	/**
	 * @param string $id Service id.
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * @param string $id Service id.
	 */
	public function is_resolved( string $id ): bool {
		return array_key_exists( $id, $this->resolved );
	}
}
