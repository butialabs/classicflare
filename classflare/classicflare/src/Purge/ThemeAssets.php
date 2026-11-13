<?php
/**
 * Purges the active theme's stylesheets when its version changes.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Purge;

use ClassicFlare\Support\Lock;
use ClassicFlare\Support\Options;
use ClassicFlare\Support\Url;
use WP_Theme;

defined( 'ABSPATH' ) || exit;

final class ThemeAssets {

	/** Stores [ stylesheet directory => Version header ] for the active theme and its parent. */
	public const OPTION = 'classicflare_theme_versions';

	public const STYLESHEETS = [ 'style.css', 'style.min.css' ];

	private const LOCK = 'theme_versions';

	public function __construct( private readonly Coordinator $coordinator ) {}

	public function register(): void {
		add_action( 'admin_init', [ $this, 'check' ] );
		add_action( 'after_switch_theme', [ $this, 'check' ] );
		add_action( 'upgrader_process_complete', [ $this, 'on_upgrade' ], 20, 2 );
	}

	/**
	 * @param mixed $upgrader   The upgrader.
	 * @param mixed $hook_extra What was upgraded.
	 */
	public function on_upgrade( $upgrader = null, $hook_extra = [] ): void {
		if ( ! is_array( $hook_extra ) || 'theme' !== ( $hook_extra['type'] ?? '' ) ) {
			return;
		}

		// Core clears the theme header cache on this same action, at priority 9.
		$this->check();
	}

	/**
	 * Compares the active themes' versions with the stored ones and purges what changed.
	 *
	 * @return array<int, string> URLs queued for purging.
	 */
	public function check(): array {
		if ( function_exists( 'wp_installing' ) && wp_installing() ) {
			return [];
		}

		$current = $this->versions();
		$stored  = get_option( self::OPTION, false );

		if ( $stored === $current ) {
			return [];
		}

		if ( ! is_array( $stored ) ) {
			// First run: remember the versions without purging anything.
			update_option( self::OPTION, $current, true );

			return [];
		}

		$changed = [];

		foreach ( $current as $slug => $version ) {
			if ( array_key_exists( $slug, $stored ) && (string) $stored[ $slug ] !== $version ) {
				$changed[ $slug ] = (string) $stored[ $slug ];
			}
		}

		if ( $changed && ! Lock::acquire( self::LOCK, 30 ) ) {
			// Another request is handling this very change.
			return [];
		}

		update_option( self::OPTION, $current, true );

		if ( ! $changed || ! Options::flag( 'purge.on_theme_update', true ) ) {
			if ( $changed ) {
				Lock::release( self::LOCK );
			}

			return [];
		}

		$urls = [];

		foreach ( $changed as $slug => $previous ) {
			$urls = array_merge( $urls, $this->urls_for( $slug, $current[ $slug ], $previous ) );
		}

		/**
		 * @param array<int, string>    $urls    Stylesheet URLs about to be purged.
		 * @param array<string, string> $changed Theme directory => previous version.
		 * @param array<string, string> $current Theme directory => current version.
		 */
		$urls = Url::normalize_all( (array) apply_filters( 'classicflare_theme_asset_urls', $urls, $changed, $current ) );

		if ( $urls ) {
			$this->coordinator->add( $urls, 'theme:version' );
		}

		Lock::release( self::LOCK );

		return $urls;
	}

	/**
	 * @return array<string, string> Theme directory => Version header, child first.
	 */
	public function versions(): array {
		$versions = [];

		foreach ( $this->themes() as $slug => $theme ) {
			$versions[ $slug ] = (string) $theme->get( 'Version' );
		}

		return $versions;
	}

	/**
	 * @param string $slug     Theme directory.
	 * @param string $version  Current version.
	 * @param string $previous Version before the change.
	 * @return array<int, string>
	 */
	public function urls_for( string $slug, string $version, string $previous ): array {
		$theme = wp_get_theme( $slug );

		if ( ! $theme instanceof WP_Theme || ! $theme->exists() ) {
			return [];
		}

		$base = trailingslashit( $theme->get_stylesheet_directory_uri() );
		$dir  = trailingslashit( $theme->get_stylesheet_directory() );

		$versions = array_unique(
			array_filter(
				[ $version, $previous, $this->core_version() ],
				static fn ( string $v ): bool => '' !== $v
			)
		);

		$urls = [];

		foreach ( self::STYLESHEETS as $file ) {
			if ( 'style.css' !== $file && ! is_file( $dir . $file ) ) {
				continue;
			}

			$urls[] = $base . $file;

			foreach ( $versions as $ver ) {
				$urls[] = $base . $file . '?ver=' . rawurlencode( $ver );
			}
		}

		return $urls;
	}

	/**
	 * @return array<string, WP_Theme> The active theme and, for a child theme, its parent.
	 */
	private function themes(): array {
		$themes = [];

		foreach ( array_unique( [ (string) get_stylesheet(), (string) get_template() ] ) as $slug ) {
			if ( '' === $slug ) {
				continue;
			}

			$theme = wp_get_theme( $slug );

			if ( $theme instanceof WP_Theme && $theme->exists() ) {
				$themes[ $slug ] = $theme;
			}
		}

		return $themes;
	}

	/**
	 * The version core appends when a style is enqueued without one.
	 */
	private function core_version(): string {
		if ( function_exists( 'classicpress_asset_version' ) ) {
			return (string) classicpress_asset_version( 'style' );
		}

		return (string) get_bloginfo( 'version' );
	}
}
