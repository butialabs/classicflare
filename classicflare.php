<?php
/**
 * Plugin Name:       ClassicFlare
 * Plugin URI:        https://github.com/butialabs/classicflare
 * Description:       Clears the Cloudflare cache when posts, comments, feeds or sitemaps change.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Requires CP:       2.0
 * Author:            Butiá Labs
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       classicflare
 * Domain Path:       /languages
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare;

defined( 'ABSPATH' ) || exit;

const VERSION = '1.0.0';
const MIN_PHP = '8.1';
const MIN_WP  = '6.2';
const MIN_CP  = '2.0';

define( 'ClassicFlare\PLUGIN_FILE', __FILE__ );
define( 'ClassicFlare\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ClassicFlare\PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ClassicFlare\PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

if ( function_exists( 'classicpress_version' ) ) {
	$classicflare_platform = 'ClassicPress';
	$classicflare_version  = (string) classicpress_version();
	$classicflare_min      = MIN_CP;
} else {
	$classicflare_platform = 'WordPress';
	$classicflare_version  = (string) ( $GLOBALS['wp_version'] ?? get_bloginfo( 'version' ) );
	$classicflare_min      = MIN_WP;
}

if ( version_compare( PHP_VERSION, MIN_PHP, '<' ) || version_compare( $classicflare_version, $classicflare_min, '<' ) ) {
	add_action(
		'admin_notices',
		static function () use ( $classicflare_platform, $classicflare_version ): void {
			if ( 'plugins.php' !== ( $GLOBALS['pagenow'] ?? '' ) || ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: required ClassicPress version, 3: required WordPress version, 4: running PHP version, 5: platform name (ClassicPress or WordPress), 6: running platform version */
						__( 'ClassicFlare requires PHP %1$s and ClassicPress %2$s or WordPress %3$s or newer. This site runs PHP %4$s and %5$s %6$s, so the plugin is idle.', 'classicflare' ),
						MIN_PHP,
						MIN_CP,
						MIN_WP,
						PHP_VERSION,
						$classicflare_platform,
						$classicflare_version
					)
				)
			);
		}
	);
	return;
}

unset( $classicflare_platform, $classicflare_version, $classicflare_min );

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = __NAMESPACE__ . '\\';
			if ( ! str_starts_with( $class, $prefix ) ) {
				return;
			}
			$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
			$path     = __DIR__ . '/src/' . $relative . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

require_once __DIR__ . '/api.php';

register_activation_hook( __FILE__, [ Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Deactivator::class, 'deactivate' ] );

Plugin::boot();
