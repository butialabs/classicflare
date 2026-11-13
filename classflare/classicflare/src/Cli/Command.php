<?php
/**
 * WP-CLI commands.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Cli;

use ClassicFlare\Admin\Connection;
use ClassicFlare\Api\Facade;
use ClassicFlare\Cloudflare\Credentials;
use ClassicFlare\Cloudflare\PurgeResult;
use ClassicFlare\Purge\Sitemaps;
use ClassicFlare\Support\Options;
use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

final class Command {

	/**
	 * Purge one or more URLs.
	 *
	 * ## OPTIONS
	 *
	 * <url>...
	 * : URLs to purge, absolute or root-relative.
	 *
	 * ## EXAMPLES
	 *
	 *     wp classicflare purge https://example.com/ /about/
	 *
	 * @param array<int, string>    $args       URLs.
	 * @param array<string, string> $assoc_args Unused.
	 */
	public function purge( array $args, array $assoc_args ): void {
		$this->report( Facade::purge_urls( $args, true, 'cli' ), 'No usable URLs were supplied.' );
	}

	/**
	 * Purge everything a post touches: permalink, archives, terms, feeds and sitemaps.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post id.
	 *
	 * [--dry-run]
	 * : List the URLs instead of purging them.
	 *
	 * ## EXAMPLES
	 *
	 *     wp classicflare post 42 --dry-run
	 *
	 * @param array<int, string>    $args       Post id.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function post( array $args, array $assoc_args ): void {
		$post_id = (int) ( $args[0] ?? 0 );

		if ( Utils\get_flag_value( $assoc_args, 'dry-run', false ) ) {
			/** @var \ClassicFlare\Purge\UrlCollector $collector */
			$collector = \ClassicFlare\Plugin::service( 'collector' );

			foreach ( $collector->for_post( $post_id ) as $url ) {
				WP_CLI::line( $url );
			}

			return;
		}

		$this->report( Facade::purge_post( $post_id, true, 'cli:post' ), 'That post produced no purgeable URLs.' );
	}

	/**
	 * Purge the whole Cloudflare zone.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @subcommand purge-all
	 *
	 * @param array<int, string>    $args       Unused.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function purge_all( array $args, array $assoc_args ): void {
		WP_CLI::confirm( 'Purge everything Cloudflare caches for this zone?', $assoc_args );

		$this->report( Facade::purge_all( true, 'cli:all' ), 'The plugin is not running.' );
	}

	/**
	 * Connect with a token: verify it, check its permissions and find the zone.
	 *
	 * ## OPTIONS
	 *
	 * [<token>]
	 * : API token to store. Omit it to re-check the token already configured, for example one
	 * set with CLASSICFLARE_CF_API_TOKEN.
	 *
	 * ## EXAMPLES
	 *
	 *     wp classicflare connect "$CF_TOKEN"
	 *
	 * @param array<int, string>    $args       Token.
	 * @param array<string, string> $assoc_args Unused.
	 */
	public function connect( array $args, array $assoc_args ): void {
		[ $ok, $message ] = ( new Connection() )->connect( (string) ( $args[0] ?? '' ) );

		if ( ! $ok ) {
			WP_CLI::error( $message );
		}

		WP_CLI::success( $message );
	}

	/**
	 * @param array<int, string>    $args       Unused.
	 * @param array<string, string> $assoc_args Unused.
	 */
	public function test( array $args, array $assoc_args ): void {
		[ $ok, $message ] = ( new Connection() )->test();

		if ( ! $ok ) {
			WP_CLI::error( $message );
		}

		WP_CLI::success( $message );
	}

	/**
	 * Show the connection, the settings that matter and the last purge.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int, string>    $args       Unused.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function status( array $args, array $assoc_args ): void {
		$last     = Options::last_purge();
		$sitemaps = new Sitemaps();

		$rows = [
			[ 'key' => 'token', 'value' => Credentials::token_source() ],
			[ 'key' => 'token_status', 'value' => (string) Options::cf_state( 'token_status', '' ) ],
			[ 'key' => 'zone_id', 'value' => Credentials::zone_id() ],
			[ 'key' => 'zone_name', 'value' => Credentials::zone_name() ],
			[ 'key' => 'plan', 'value' => (string) Options::cf_state( 'plan', '' ) ],
			[ 'key' => 'automatic', 'value' => \ClassicFlare\Plugin::is_enabled() ? 'on' : 'off' ],
			[ 'key' => 'sitemaps', 'value' => $sitemaps->provider_label() ],
			[
				'key'   => 'last_purge',
				'value' => empty( $last['time'] )
					? 'never'
					: gmdate( 'Y-m-d H:i:s', (int) $last['time'] ) . ' UTC: ' . (string) ( $last['summary'] ?? '' ),
			],
		];

		Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, [ 'key', 'value' ] );
	}

	/**
	 * @param PurgeResult|null $result   Result.
	 * @param string           $rejected Message when nothing was sent.
	 */
	private function report( ?PurgeResult $result, string $rejected ): void {
		if ( null === $result ) {
			WP_CLI::error( $rejected );
		}

		if ( $result->is_fatal() || $result->has_failures() ) {
			WP_CLI::error( $result->summary() );
		}

		WP_CLI::success( $result->summary() );
	}
}
