<?php
/**
 * Activation routine.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare;

use ClassicFlare\Purge\Scheduler;
use ClassicFlare\Support\Options;

defined( 'ABSPATH' ) || exit;

final class Activator {

	/**
	 * @param bool $network_wide Whether this is a network activation.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			return;
		}

		if ( false === get_option( Options::OPTION_SETTINGS, false ) ) {
			add_option( Options::OPTION_SETTINGS, Options::defaults(), '', true );
		}

		Scheduler::schedule();
	}
}
