<?php
/**
 * Deactivation routine.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare;

use ClassicFlare\Purge\Scheduler;

defined( 'ABSPATH' ) || exit;

final class Deactivator {

	public static function deactivate(): void {
		Scheduler::unschedule();
	}
}
