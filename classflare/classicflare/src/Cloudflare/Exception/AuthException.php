<?php
/**
 * AuthException.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Cloudflare\Exception;

defined( 'ABSPATH' ) || exit;

final class AuthException extends ApiException {

	public function is_retryable(): bool {
		return false;
	}
}
