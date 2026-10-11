<?php
/**
 * Cloudflare API client.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Cloudflare;

use ClassicFlare\Cloudflare\Exception\ApiException;
use ClassicFlare\Cloudflare\Exception\AuthException;
use ClassicFlare\Cloudflare\Exception\ClientException;
use ClassicFlare\Cloudflare\Exception\RateLimitException;
use ClassicFlare\Cloudflare\Exception\ServerException;
use ClassicFlare\Cloudflare\Exception\TransportException;
use ClassicFlare\Support\Redactor;

defined( 'ABSPATH' ) || exit;

final class Client {

	private const BASE = 'https://api.cloudflare.com/client/v4/';

	/**
	 * @param float|null $timeout Override the request timeout.
	 */
	public function __construct( private readonly ?float $timeout = null ) {}

	/**
	 * @return array<string, mixed>
	 */
	public function verify_token(): array {
		return (array) $this->request( 'GET', 'user/tokens/verify' )->result;
	}

	/**
	 * @param string $token_id Token id from verify_token().
	 * @return array<string, mixed>
	 */
	public function token_details( string $token_id ): array {
		return (array) $this->request( 'GET', 'user/tokens/' . rawurlencode( $token_id ) )->result;
	}

	/**
	 * @param string $name Zone name.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_zones( string $name = '' ): array {
		$query = [ 'per_page' => 50 ];

		if ( '' !== $name ) {
			$query['name'] = $name;
		}

		return (array) $this->request( 'GET', 'zones', [ 'query' => $query ] )->result;
	}

	/**
	 * @param string $zone_id Zone id.
	 * @return array<string, mixed>
	 */
	public function get_zone( string $zone_id ): array {
		return (array) $this->request( 'GET', 'zones/' . rawurlencode( $zone_id ) )->result;
	}

	/**
	 * @param string               $zone_id Zone id.
	 * @param array<string, mixed> $body    { files: [...] } or { purge_everything: true }.
	 */
	public function purge_cache( string $zone_id, array $body ): Response {
		return $this->request(
			'POST',
			'zones/' . rawurlencode( $zone_id ) . '/purge_cache',
			[ 'json' => $body ]
		);
	}

	/**
	 * @param string               $method  HTTP method.
	 * @param string               $path    Path under the API base.
	 * @param array<string, mixed> $options query and json.
	 * @throws ApiException When the call fails.
	 */
	public function request( string $method, string $path, array $options = [] ): Response {
		$headers = Credentials::headers();

		if ( ! $headers ) {
			throw new AuthException( esc_html__( 'No Cloudflare credentials are configured.', 'classicflare' ), 401 );
		}

		$url = self::BASE . ltrim( $path, '/' );

		if ( ! empty( $options['query'] ) && is_array( $options['query'] ) ) {
			$url = add_query_arg( $options['query'], $url );
		}

		$args = [
			'method'  => strtoupper( $method ),
			'timeout' => $this->timeout(),
			'headers' => $headers + [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
		];

		if ( isset( $options['json'] ) ) {
			$args['body'] = (string) wp_json_encode( $options['json'] );
		}

		$raw = wp_remote_request( $url, $args );

		if ( is_wp_error( $raw ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new TransportException( Redactor::scrub( $raw->get_error_message() ), 0 );
		}

		$response = Response::from_http( $raw );

		if ( $response->ok() ) {
			return $response;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		throw $this->classify( $response );
	}

	/**
	 * @param Response $response The failed response.
	 */
	private function classify( Response $response ): ApiException {
		$message = Redactor::scrub( $response->error_message() );
		$status  = $response->status;
		$errors  = $response->errors;

		if ( 429 === $status ) {
			return new RateLimitException( $message, $status, $errors, $response->retry_after() );
		}

		if ( 401 === $status || 403 === $status ) {
			return new AuthException( $message, $status, $errors );
		}

		if ( 408 === $status || $status >= 500 ) {
			return new ServerException( $message, $status, $errors );
		}

		if ( $status >= 400 ) {
			return new ClientException( $message, $status, $errors );
		}

		return new ClientException( $message, $status ?: 400, $errors );
	}

	private function timeout(): float {
		if ( null !== $this->timeout ) {
			return $this->timeout;
		}

		return ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) ? 15.0 : 5.0;
	}
}
