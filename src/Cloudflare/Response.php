<?php
/**
 * Cloudflare API response envelope.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Cloudflare;

defined( 'ABSPATH' ) || exit;

final class Response {

	/**
	 * @param int                              $status   HTTP status.
	 * @param bool                             $success  The envelope success flag.
	 * @param mixed                            $result   The result payload.
	 * @param array<int, array<string, mixed>> $errors   Cloudflare errors.
	 * @param array<int, array<string, mixed>> $messages Cloudflare messages.
	 * @param array<string, string>            $headers  Response headers.
	 */
	private function __construct(
		public readonly int $status,
		public readonly bool $success,
		public readonly mixed $result,
		public readonly array $errors,
		public readonly array $messages,
		public readonly array $headers
	) {}

	/**
	 * @param array<string, mixed> $raw Response.
	 */
	public static function from_http( array $raw ): self {
		$status  = (int) wp_remote_retrieve_response_code( $raw );
		$body    = (string) wp_remote_retrieve_body( $raw );
		$headers = wp_remote_retrieve_headers( $raw );

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			return new self( $status, false, null, [], [], self::flatten_headers( $headers ) );
		}

		return new self(
			$status,
			! empty( $decoded['success'] ),
			$decoded['result'] ?? null,
			isset( $decoded['errors'] ) && is_array( $decoded['errors'] ) ? $decoded['errors'] : [],
			isset( $decoded['messages'] ) && is_array( $decoded['messages'] ) ? $decoded['messages'] : [],
			self::flatten_headers( $headers )
		);
	}

	public function ok(): bool {
		return $this->success && $this->status >= 200 && $this->status < 300;
	}

	/**
	 * @return array<int, int>
	 */
	public function error_codes(): array {
		$codes = [];

		foreach ( $this->errors as $error ) {
			if ( isset( $error['code'] ) ) {
				$codes[] = (int) $error['code'];
			}
		}

		return $codes;
	}

	public function error_message(): string {
		$parts = [];

		foreach ( $this->errors as $error ) {
			$code    = isset( $error['code'] ) ? (int) $error['code'] : 0;
			$message = isset( $error['message'] ) ? (string) $error['message'] : '';

			if ( '' === $message ) {
				continue;
			}

			$parts[] = $code > 0 ? sprintf( '%s (%d)', $message, $code ) : $message;
		}

		if ( ! $parts ) {
			return sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Cloudflare answered HTTP %d with no error detail.', 'classicflare' ),
				$this->status
			);
		}

		return implode( '; ', $parts );
	}

	public function retry_after(): ?int {
		$value = $this->headers['retry-after'] ?? '';

		if ( '' === $value ) {
			return null;
		}

		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}

		$timestamp = strtotime( $value );

		return $timestamp ? max( 0, $timestamp - time() ) : null;
	}

	/**
	 * @param mixed $headers Headers from wp_remote_retrieve_headers().
	 * @return array<string, string>
	 */
	private static function flatten_headers( mixed $headers ): array {
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		if ( ! is_array( $headers ) ) {
			return [];
		}

		$flat = [];

		foreach ( $headers as $key => $value ) {
			$flat[ strtolower( (string) $key ) ] = is_array( $value )
				? (string) reset( $value )
				: (string) $value;
		}

		return $flat;
	}
}
