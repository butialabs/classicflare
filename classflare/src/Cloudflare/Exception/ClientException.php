<?php
/**
 * ClientException.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Cloudflare\Exception;

defined( 'ABSPATH' ) || exit;

final class ClientException extends ApiException {

	public function is_retryable(): bool {
		return false;
	}
}
