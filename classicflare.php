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

define( 'ClassicFlare\PLUGIN_FILE', __FILE__ );
define( 'ClassicFlare\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ClassicFlare\PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ClassicFlare\PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

if ( version_compare( PHP_VERSION, MIN_PHP, '<' ) || version_compare( get_bloginfo( 'version' ), MIN_WP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: required WordPress version, 3: running PHP version, 4: running WordPress version */
						__( 'ClassicFlare requires PHP %1$s and ClassicPress 2 or WordPress %2$s or newer. This site runs PHP %3$s and version %4$s, so the plugin is idle.', 'classicflare' ),
						MIN_PHP,
						MIN_WP,
						PHP_VERSION,
						get_bloginfo( 'version' )
					)
				)
			);
		}
	);
	return;
}

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
