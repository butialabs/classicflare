<?php
/**
 * WP-Cron plumbing.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Purge;

defined( 'ABSPATH' ) || exit;

final class Scheduler {

	public const HOOK_PURGE = 'classicflare_purge';

	public const HOOK_SITEMAPS = 'classicflare_sitemaps';

	public const HOOK_SITEMAPS_NOW = 'classicflare_sitemaps_now';

	/**
	 * @return array<int, string>
	 */
	public static function hooks(): array {
		return [ self::HOOK_PURGE, self::HOOK_SITEMAPS, self::HOOK_SITEMAPS_NOW ];
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK_SITEMAPS ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::HOOK_SITEMAPS );
		}
	}

	public static function unschedule(): void {
		foreach ( self::hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * @param array<int, string> $urls      URLs, ignored when $purge_all is true.
	 * @param bool               $purge_all Purge the whole zone.
	 * @param int                $attempt   Which attempt this will be, starting at 1.
	 * @param string             $reason    Who asked.
	 * @param int                $delay     Seconds from now.
	 */
	public static function purge_later( array $urls, bool $purge_all, int $attempt, string $reason, int $delay = 0 ): bool {
		$args = [ $purge_all ? [] : array_values( $urls ), $purge_all, $attempt, $reason ];

		$scheduled = true === wp_schedule_single_event( time() + max( 0, $delay ), self::HOOK_PURGE, $args );

		if ( $scheduled && $delay <= 0 && function_exists( 'spawn_cron' ) && ! wp_doing_cron() ) {
			spawn_cron();
		}

		return $scheduled;
	}
}
