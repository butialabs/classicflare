<?php
/**
 * Admin notices.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Admin;

use ClassicFlare\Cloudflare\Credentials;
use ClassicFlare\Support\Options;

defined( 'ABSPATH' ) || exit;

final class Notices {

	private const FLASH = 'classicflare_flash_';

	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
		add_action( 'network_admin_notices', [ $this, 'render' ] );
	}

	public function render(): void {
		if ( ! current_user_can( Menu::capability() ) ) {
			return;
		}

		$this->render_flash();

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';

		if ( str_ends_with( $id, '_page_' . Menu::SLUG ) ) {
			return;
		}

		if ( ! in_array( $id, [ 'dashboard', 'plugins', 'dashboard-network', 'plugins-network' ], true ) ) {
			return;
		}

		if ( '' === Credentials::zone_id() ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'ClassicFlare is not connected to Cloudflare yet, so nothing is being purged.', 'classicflare' ),
				esc_url( Menu::url() ),
				esc_html__( 'Connect now', 'classicflare' )
			);

			return;
		}

		if ( 'invalid' === Options::cf_state( 'token_status', '' ) ) {
			printf(
				'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Cloudflare is rejecting the ClassicFlare API token, so cache purges are failing.', 'classicflare' ),
				esc_url( Menu::url() ),
				esc_html__( 'Fix the connection', 'classicflare' )
			);
		}
	}

	/**
	 * @param string $message Message.
	 * @param string $type    success, error, warning or info.
	 */
	public static function flash( string $message, string $type = 'success' ): void {
		set_transient(
			self::FLASH . get_current_user_id(),
			[
				'message' => $message,
				'type'    => $type,
			],
			MINUTE_IN_SECONDS * 5
		);
	}

	private function render_flash(): void {
		$key   = self::FLASH . get_current_user_id();
		$flash = get_transient( $key );

		if ( ! is_array( $flash ) || empty( $flash['message'] ) ) {
			return;
		}

		delete_transient( $key );

		$type = in_array( $flash['type'] ?? '', [ 'success', 'error', 'warning', 'info' ], true ) ? $flash['type'] : 'info';

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( (string) $flash['message'] )
		);
	}
}
