<?php
/**
 * Toolbar controls, and the handler behind every manual purge.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Admin;

use ClassicFlare\Api\Facade;
use ClassicFlare\Cloudflare\Credentials;
use ClassicFlare\Cloudflare\PurgeResult;
use WP_Admin_Bar;

defined( 'ABSPATH' ) || exit;

final class AdminBar {

	public const ACTION = 'classicflare_purge';

	public function register_nodes(): void {
		add_action( 'admin_bar_menu', [ $this, 'add_nodes' ], 100 );
	}

	public function register_handler(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
	}

	/**
	 * @param WP_Admin_Bar $bar Admin bar.
	 */
	public function add_nodes( $bar ): void {
		if ( ! $bar instanceof WP_Admin_Bar || ! current_user_can( Menu::capability() ) ) {
			return;
		}

		if ( '' === Credentials::zone_id() ) {
			return;
		}

		$bar->add_node(
			[
				'id'    => 'classicflare',
				'title' => __( 'Cache', 'classicflare' ),
				'href'  => Menu::url(),
			]
		);

		if ( ! is_admin() ) {
			$bar->add_node(
				[
					'id'     => 'classicflare-this',
					'parent' => 'classicflare',
					'title'  => __( 'Purge this page', 'classicflare' ),
					'href'   => $this->action_url( 'url', $this->current_url() ),
				]
			);
		}

		$bar->add_node(
			[
				'id'     => 'classicflare-all',
				'parent' => 'classicflare',
				'title'  => __( 'Purge everything', 'classicflare' ),
				'href'   => $this->action_url( 'all' ),
			]
		);
	}

	public function handle(): void {
		if ( ! current_user_can( Menu::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to purge the cache.', 'classicflare' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified just above.
		$what = isset( $_REQUEST['what'] ) ? sanitize_key( wp_unslash( $_REQUEST['what'] ) ) : '';
		$url  = isset( $_REQUEST['url'] ) ? esc_url_raw( wp_unslash( $_REQUEST['url'] ) ) : '';
		$back = isset( $_REQUEST['back'] ) ? sanitize_key( wp_unslash( $_REQUEST['back'] ) ) : '';
		// phpcs:enable

		$result = null;

		if ( 'all' === $what ) {
			$result = Facade::purge_all( true, 'manual:all' );
		} elseif ( 'url' === $what && '' !== $url ) {
			$result = Facade::purge_urls( [ $url ], true, 'manual:url' );
		}

		[ $message, $type ] = $this->describe( $result );

		if ( 'settings' === $back ) {
			Menu::redirect_with_notice( $message, $type );
		}

		Notices::flash( $message, $type );

		$referer = wp_get_referer();

		wp_safe_redirect( $referer ? $referer : home_url( '/' ) );
		exit;
	}

	/**
	 * @param PurgeResult|null $result Result, or null when nothing was sent.
	 * @return array{0: string, 1: string}
	 */
	private function describe( ?PurgeResult $result ): array {
		if ( null === $result ) {
			return [ __( 'Nothing to purge.', 'classicflare' ), 'warning' ];
		}

		if ( $result->is_fatal() || $result->has_failures() ) {
			return [
				sprintf(
					/* translators: %s: what Cloudflare reported. */
					__( 'Cloudflare purge failed: %s', 'classicflare' ),
					$result->summary()
				),
				'error',
			];
		}

		return [
			sprintf(
				/* translators: %s: what Cloudflare reported. */
				__( 'Cloudflare: %s', 'classicflare' ),
				$result->summary()
			),
			'success',
		];
	}

	/**
	 * @param string $what Either "all" or "url".
	 * @param string $url  URL, when purging one.
	 */
	private function action_url( string $what, string $url = '' ): string {
		$args = [
			'action' => self::ACTION,
			'what'   => $what,
		];

		if ( '' !== $url ) {
			$args['url'] = rawurlencode( $url );
		}

		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::ACTION );
	}

	private function current_url(): string {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return home_url( '/' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$uri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );

		$scheme = (string) wp_parse_url( home_url(), PHP_URL_SCHEME );
		$host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$port   = wp_parse_url( home_url(), PHP_URL_PORT );

		return $scheme . '://' . $host . ( $port ? ':' . $port : '' ) . $uri;
	}
}
