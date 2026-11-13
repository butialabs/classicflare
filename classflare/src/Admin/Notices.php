<?php
/**
 * Admin notices.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Admin;

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
