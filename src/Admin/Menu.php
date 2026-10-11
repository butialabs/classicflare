<?php
/**
 * Admin menu registration.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Admin;

use ClassicFlare\Support\Options;

defined( 'ABSPATH' ) || exit;

final class Menu {

	public const SLUG  = 'classicflare';
	public const GROUP = 'classicflare';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_filter( 'plugin_action_links_' . \ClassicFlare\PLUGIN_BASENAME, [ $this, 'action_links' ] );

		( new Connection() )->register();
	}

	public function add_page(): void {
		add_options_page(
			__( 'ClassicFlare', 'classicflare' ),
			__( 'ClassicFlare', 'classicflare' ),
			self::capability(),
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Options::OPTION_SETTINGS,
			[
				'type'              => 'array',
				'sanitize_callback' => [ SettingsPage::class, 'sanitize' ],
				'default'           => Options::defaults(),
			]
		);
	}

	/**
	 * @param array<int|string, string> $links Existing links.
	 * @return array<int|string, string>
	 */
	public function action_links( $links ): array {
		$links = is_array( $links ) ? $links : [];

		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::url() ),
				esc_html__( 'Settings', 'classicflare' )
			)
		);

		return $links;
	}

	public function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage ClassicFlare.', 'classicflare' ), '', [ 'response' => 403 ] );
		}

		( new SettingsPage() )->render();
	}

	public static function capability(): string {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * @param string $tab Tab id, or empty for the first tab.
	 */
	public static function url( string $tab = '' ): string {
		$args = [ 'page' => self::SLUG ];

		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}

		return add_query_arg( $args, admin_url( 'options-general.php' ) );
	}

	/**
	 * @param string $message Message.
	 * @param string $type    success, error, warning or info.
	 */
	public static function redirect_with_notice( string $message, string $type = 'success' ): void {
		add_settings_error( self::GROUP, 'classicflare_notice', $message, $type );
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', self::url() ) );
		exit;
	}
}
