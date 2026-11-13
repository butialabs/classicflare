<?php
/**
 * Base Cloudflare API exception.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Cloudflare\Exception;

defined( 'ABSPATH' ) || exit;

class ApiException extends \RuntimeException {

	/** @var array<int, array<string, mixed>> */
	protected array $api_errors = [];

	protected ?int $retry_after = null;

	/**
	 * @param string                           $message    Message.
	 * @param int                              $code       HTTP status.
	 * @param array<int, array<string, mixed>> $api_errors Cloudflare errors array.
	 */
	public function __construct( string $message, int $code = 0, array $api_errors = [] ) {
		parent::__construct( $message, $code );

		$this->api_errors = $api_errors;
	}

	public function is_retryable(): bool {
		return true;
	}

	public function retry_after(): ?int {
		return $this->retry_after;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function api_errors(): array {
		return $this->api_errors;
	}

	/**
	 * @return array<int, int>
	 */
	public function error_codes(): array {
		$codes = [];

		foreach ( $this->api_errors as $error ) {
			if ( isset( $error['code'] ) ) {
				$codes[] = (int) $error['code'];
			}
		}

		return $codes;
	}
}
