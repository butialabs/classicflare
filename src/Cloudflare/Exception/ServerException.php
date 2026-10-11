<?php
/**
 * ServerException.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Cloudflare\Exception;

defined( 'ABSPATH' ) || exit;

final class ServerException extends ApiException {

	public function is_retryable(): bool {
		return true;
	}
}
