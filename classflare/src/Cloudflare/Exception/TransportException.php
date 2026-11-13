<?php
/**
 * TransportException.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Cloudflare\Exception;

defined( 'ABSPATH' ) || exit;

final class TransportException extends ApiException {

	public function is_retryable(): bool {
		return true;
	}
}
