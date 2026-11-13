<?php
/**
 * Decides when the accumulated purge runs, and retries what failed.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Purge;

use ClassicFlare\Cloudflare\Purger;
use ClassicFlare\Cloudflare\PurgeResult;
use ClassicFlare\Support\Options;
use ClassicFlare\Support\Url;

defined( 'ABSPATH' ) || exit;

final class Coordinator {

	public const MAX_ATTEMPTS = 3;

	public const RETRY_BASE_SECONDS = 60;

	private PurgeRequest $request;

	private bool $shutdown_registered = false;

	public function __construct( private readonly Purger $purger ) {
		$this->request = new PurgeRequest();
	}

	/**
	 * @param array<int, string> $urls   URLs.
	 * @param string             $reason Who asked.
	 */
	public function add( array $urls, string $reason = '' ): void {
		if ( ! $urls ) {
			return;
		}

		$this->request->add( $urls, $reason );
		$this->ensure_shutdown();
	}

	/**
	 * @param string $reason Who asked.
	 */
	public function add_purge_all( string $reason = '' ): void {
		$this->request->add_purge_all( $reason );
		$this->ensure_shutdown();
	}

	public function request(): PurgeRequest {
		return $this->request;
	}

	private function ensure_shutdown(): void {
		if ( $this->shutdown_registered ) {
			return;
		}

		add_action( 'shutdown', [ $this, 'on_shutdown' ], 10 );
		$this->shutdown_registered = true;
	}

	public function on_shutdown(): void {
		$this->dispatch();
	}

	/**
	 * @param bool|null $inline True forces a synchronous purge, false forces cron, null decides
	 *                          from the kind of request this is.
	 * @return PurgeResult|null The result when it ran inline, null when deferred or empty.
	 */
	public function dispatch( ?bool $inline = null ): ?PurgeResult {
		if ( $this->request->is_empty() ) {
			return null;
		}

		$request       = $this->request;
		$this->request = new PurgeRequest();

		$purge_all = $request->is_purge_all();
		$urls      = $purge_all ? [] : $this->final_urls( $request );

		if ( ! $purge_all && ! $urls ) {
			return null;
		}

		$inline ??= $this->should_run_inline();

		if ( ! $inline ) {
			Scheduler::purge_later( $urls, $purge_all, 1, $request->reason() );

			return null;
		}

		$this->release_client();

		return $this->run( $urls, $purge_all, 1, $request->reason() );
	}

	/**
	 * @param mixed $urls      URLs.
	 * @param mixed $purge_all Purge the whole zone.
	 * @param mixed $attempt   Attempt number, starting at 1.
	 * @param mixed $reason    Who asked.
	 */
	public function on_cron( $urls = [], $purge_all = false, $attempt = 1, $reason = '' ): void {
		$this->run(
			Url::normalize_all( is_array( $urls ) ? $urls : [] ),
			(bool) $purge_all,
			max( 1, (int) $attempt ),
			(string) $reason
		);
	}

	/**
	 * @param array<int, string> $urls      URLs.
	 * @param bool               $purge_all Purge the whole zone.
	 * @param int                $attempt   Attempt number, starting at 1.
	 * @param string             $reason    Who asked.
	 */
	public function run( array $urls, bool $purge_all, int $attempt, string $reason ): PurgeResult {
		if ( ! $purge_all && ! $urls ) {
			return PurgeResult::make();
		}

		$unavailable = $this->purger->unavailable();

		if ( null !== $unavailable ) {
			$result = PurgeResult::fatal( $urls, $unavailable['reason'], false );
		} else {
			try {
				$result = $purge_all ? $this->purger->purge_all() : $this->purger->purge_urls( $urls );
			} catch ( \Throwable $e ) {
				$result = PurgeResult::fatal( $urls, $e->getMessage() );
			}
		}

		$retrying = false;

		if ( $result->has_failures() || ( $purge_all && $result->is_fatal() ) ) {
			$retrying = $this->maybe_retry( $result, $purge_all, $attempt, $reason );
		}

		Options::set_last_purge(
			[
				'time'      => time(),
				'reason'    => $reason,
				'purge_all' => $purge_all,
				'ok'        => ! $result->is_fatal() && ! $result->has_failures(),
				'summary'   => $result->summary(),
				'attempt'   => $attempt,
				'retrying'  => $retrying,
			]
		);

		/**
		 * @param PurgeResult        $result    What Cloudflare reported.
		 * @param array<int, string> $urls      URLs that were sent; empty for a full purge.
		 * @param bool               $purge_all Whether the whole zone was purged.
		 * @param string             $reason    Who asked.
		 * @param int                $attempt   Attempt number, starting at 1.
		 */
		do_action( 'classicflare_purged', $result, $urls, $purge_all, $reason, $attempt );

		return $result;
	}

	/**
	 * @param PurgeResult $result    The failed result.
	 * @param bool        $purge_all Whether it was a full purge.
	 * @param int         $attempt   The attempt that just failed.
	 * @param string      $reason    Who asked.
	 * @return bool Whether a retry was scheduled.
	 */
	private function maybe_retry( PurgeResult $result, bool $purge_all, int $attempt, string $reason ): bool {
		if ( ! $result->is_retryable() || $attempt >= self::MAX_ATTEMPTS ) {
			return false;
		}

		$delay = self::retry_delay( $attempt, $result->get_retry_after() );

		return Scheduler::purge_later( $result->failed_urls(), $purge_all, $attempt + 1, $reason, $delay );
	}

	/**
	 * @param int      $attempt     The attempt that just failed, starting at 1.
	 * @param int|null $retry_after Retry-After from a 429, if any.
	 */
	public static function retry_delay( int $attempt, ?int $retry_after = null ): int {
		$backoff = self::RETRY_BASE_SECONDS * ( 2 ** max( 0, $attempt - 1 ) );

		return max( $backoff, (int) $retry_after );
	}

	private function should_run_inline(): bool {
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		return is_admin() || wp_doing_cron() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	private function release_client(): void {
		if ( ! doing_action( 'shutdown' ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
	}

	/**
	 * @param PurgeRequest $request The request.
	 * @return array<int, string>
	 */
	private function final_urls( PurgeRequest $request ): array {
		/**
		 * @param array<int, string> $urls   URLs about to be purged.
		 * @param string             $reason Who asked.
		 */
		$urls = (array) apply_filters( 'classicflare_purge_urls', $request->urls(), $request->reason() );

		$urls = array_merge( $urls, Options::custom_urls() );

		return Url::normalize_all( $urls );
	}
}
