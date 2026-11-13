<?php
/**
 * Cross-request mutex.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Support;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

final class Lock {

	private const PREFIX = 'classicflare_lock_';

	/**
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds before the lock is considered abandoned.
	 */
	public static function acquire( string $name, int $ttl = 60 ): bool {
		$option  = self::PREFIX . $name;
		$expires = time() + max( 1, $ttl );

		if ( add_option( $option, (string) $expires, '', false ) ) {
			return true;
		}

		$held = (int) get_option( $option, 0 );
		if ( $held > time() ) {
			return false;
		}

		global $wpdb;

		$updated = $wpdb->update(
			$wpdb->options,
			[ 'option_value' => (string) $expires ],
			[
				'option_name'  => $option,
				'option_value' => (string) $held,
			],
			[ '%s' ],
			[ '%s', '%s' ]
		);

		if ( 1 === $updated ) {
			wp_cache_delete( $option, 'options' );
			return true;
		}

		return false;
	}

	/**
	 * @param string $name Lock name.
	 */
	public static function release( string $name ): void {
		delete_option( self::PREFIX . $name );
	}

	/**
	 * @param string $name Lock name.
	 */
	public static function held( string $name ): bool {
		return (int) get_option( self::PREFIX . $name, 0 ) > time();
	}

	/**
	 * @param string $name Lock name.
	 */
	public static function option_name( string $name ): string {
		return self::PREFIX . $name;
	}
}
