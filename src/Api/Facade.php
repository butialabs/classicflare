<?php
/**
 * Implementation behind the public API functions.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Api;

use ClassicFlare\Cloudflare\PurgeResult;
use ClassicFlare\Plugin;
use ClassicFlare\Purge\Coordinator;
use ClassicFlare\Purge\UrlCollector;
use ClassicFlare\Support\Url;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class Facade {

	public static function register_actions(): void {
		add_action( 'classicflare_purge_url', [ self::class, 'on_purge_url_action' ], 10, 2 );
		add_action( 'classicflare_purge_post', [ self::class, 'on_purge_post_action' ], 10, 2 );
		add_action( 'classicflare_purge_all', [ self::class, 'on_purge_all_action' ], 10, 1 );
	}

	/**
	 * Purge specific URLs.
	 *
	 * @param array<int, string> $urls   URLs, absolute or root-relative.
	 * @param bool               $now    Purge synchronously instead of at shutdown.
	 * @param string             $reason Who asked.
	 */
	public static function purge_urls( array $urls, bool $now = false, string $reason = 'api' ): ?PurgeResult {
		$coordinator = self::coordinator();
		$urls        = Url::normalize_all( $urls );

		if ( ! $coordinator || ! $urls ) {
			return null;
		}

		$coordinator->add( $urls, $reason );

		return self::finish( $coordinator, $now );
	}

	/**
	 * @param int|WP_Post $post   Post or id.
	 * @param bool        $now    Purge synchronously.
	 * @param string      $reason Who asked.
	 */
	public static function purge_post( int|WP_Post $post, bool $now = false, string $reason = 'api:post' ): ?PurgeResult {
		$coordinator = self::coordinator();

		if ( ! $coordinator ) {
			return null;
		}

		/** @var UrlCollector $collector */
		$collector = Plugin::service( 'collector' );
		$urls      = $collector->for_post( $post instanceof WP_Post ? $post->ID : $post );

		if ( ! $urls ) {
			return null;
		}

		$coordinator->add( $urls, $reason );

		return self::finish( $coordinator, $now );
	}

	/**
	 * @param bool   $now    Purge synchronously.
	 * @param string $reason Who asked.
	 */
	public static function purge_all( bool $now = false, string $reason = 'api:all' ): ?PurgeResult {
		$coordinator = self::coordinator();

		if ( ! $coordinator ) {
			return null;
		}

		$coordinator->add_purge_all( $reason );

		return self::finish( $coordinator, $now );
	}

	public static function is_active(): bool {
		$plugin = Plugin::instance();

		if ( ! $plugin ) {
			return false;
		}

		return null === $plugin->container()->get( 'purger' )->unavailable();
	}

	/**
	 * @param mixed $urls URL or list of URLs.
	 * @param mixed $args Optional { now: bool, reason: string }.
	 */
	public static function on_purge_url_action( $urls = [], $args = [] ): void {
		$args = self::args( $args, 'action' );
		self::purge_urls( array_map( 'strval', (array) $urls ), $args['now'], $args['reason'] );
	}

	/**
	 * @param mixed $post Post or id.
	 * @param mixed $args Optional { now: bool, reason: string }.
	 */
	public static function on_purge_post_action( $post = 0, $args = [] ): void {
		$args = self::args( $args, 'action:post' );
		self::purge_post( $post instanceof WP_Post ? $post : (int) $post, $args['now'], $args['reason'] );
	}

	/**
	 * @param mixed $args Optional { now: bool, reason: string }.
	 */
	public static function on_purge_all_action( $args = [] ): void {
		$args = self::args( $args, 'action:all' );
		self::purge_all( $args['now'], $args['reason'] );
	}

	/**
	 * @param mixed  $args           Raw arguments.
	 * @param string $default_reason Reason when none is given.
	 * @return array{now: bool, reason: string}
	 */
	public static function args( $args, string $default_reason ): array {
		$args = is_array( $args ) ? $args : [];

		return [
			'now'    => ! empty( $args['now'] ),
			'reason' => isset( $args['reason'] ) && '' !== (string) $args['reason'] ? (string) $args['reason'] : $default_reason,
		];
	}

	private static function coordinator(): ?Coordinator {
		$coordinator = Plugin::service( 'coordinator' );

		return $coordinator instanceof Coordinator ? $coordinator : null;
	}

	/**
	 * @param Coordinator $coordinator Coordinator.
	 * @param bool        $now         Purge synchronously.
	 */
	private static function finish( Coordinator $coordinator, bool $now ): PurgeResult {
		if ( ! $now ) {
			return PurgeResult::make()->note( __( 'Scheduled for the end of this request.', 'classicflare' ) );
		}

		return $coordinator->dispatch( true ) ?? PurgeResult::make();
	}
}
