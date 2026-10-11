<?php
/**
 * HTTP 429 from the Cloudflare API.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Cloudflare\Exception;

defined( 'ABSPATH' ) || exit;

final class RateLimitException extends ApiException {

	/**
	 * @param string                           $message     Message.
	 * @param int                              $code        HTTP status.
	 * @param array<int, array<string, mixed>> $api_errors  Cloudflare errors.
	 * @param int|null                         $retry_after Seconds from the Retry-After header.
	 */
	public function __construct( string $message, int $code = 429, array $api_errors = [], ?int $retry_after = null ) {
		parent::__construct( $message, $code, $api_errors );

		$this->retry_after = $retry_after;
	}

	public function is_retryable(): bool {
		return true;
	}
}
