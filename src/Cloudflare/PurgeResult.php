<?php
/**
 * Outcome of one purge attempt.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Cloudflare;

defined( 'ABSPATH' ) || exit;

final class PurgeResult {

	/** @var array<int, string> */
	private array $succeeded = [];

	/** @var array<string, string> URL to reason. */
	private array $failed = [];

	/** @var array<string, string> URL to reason. */
	private array $skipped = [];

	private bool $fatal = false;

	private bool $retryable = true;

	private ?int $retry_after = null;

	private string $note = '';

	public static function make(): self {
		return new self();
	}

	/**
	 * @param array<int, string> $urls      URLs that were in flight.
	 * @param string             $reason    Failure message.
	 * @param bool               $retryable False means retrying cannot help.
	 */
	public static function fatal( array $urls, string $reason, bool $retryable = true ): self {
		$result            = new self();
		$result->fatal     = true;
		$result->retryable = $retryable;

		foreach ( $urls as $url ) {
			$result->failed[ $url ] = $reason;
		}

		if ( ! $urls ) {
			$result->note = $reason;
		}

		return $result;
	}

	/**
	 * @param string $url Purged URL.
	 */
	public function succeed( string $url ): self {
		$this->succeeded[] = $url;

		return $this;
	}

	/**
	 * @param string $url    URL.
	 * @param string $reason Why it failed.
	 */
	public function fail( string $url, string $reason ): self {
		$this->failed[ $url ] = $reason;

		return $this;
	}

	/**
	 * @param string $url    URL.
	 * @param string $reason Why it was skipped. Not an error.
	 */
	public function skip( string $url, string $reason ): self {
		$this->skipped[ $url ] = $reason;

		return $this;
	}

	public function not_retryable(): self {
		$this->retryable = false;

		return $this;
	}

	/**
	 * @param int|null $seconds Seconds.
	 */
	public function retry_after( ?int $seconds ): self {
		$this->retry_after = $seconds;

		return $this;
	}

	/**
	 * @param string $note Note.
	 */
	public function note( string $note ): self {
		$this->note = $note;

		return $this;
	}

	/** @return array<int, string> */
	public function succeeded(): array {
		return $this->succeeded;
	}

	/** @return array<string, string> */
	public function failed(): array {
		return $this->failed;
	}

	/** @return array<string, string> */
	public function skipped(): array {
		return $this->skipped;
	}

	public function is_fatal(): bool {
		return $this->fatal;
	}

	public function is_retryable(): bool {
		return $this->retryable;
	}

	public function has_failures(): bool {
		return [] !== $this->failed;
	}

	public function get_retry_after(): ?int {
		return $this->retry_after;
	}

	/**
	 * @return array<int, string>
	 */
	public function failed_urls(): array {
		return array_keys( $this->failed );
	}

	public function first_error(): string {
		foreach ( $this->failed as $reason ) {
			return $reason;
		}

		return $this->note;
	}

	/**
	 * @param PurgeResult $other Result to absorb.
	 */
	public function merge( self $other ): self {
		$this->succeeded = array_merge( $this->succeeded, $other->succeeded );
		$this->failed    = $this->failed + $other->failed;
		$this->skipped   = $this->skipped + $other->skipped;

		$this->fatal     = $this->fatal || $other->fatal;
		$this->retryable = $this->retryable && $other->retryable;

		if ( null !== $other->retry_after ) {
			$this->retry_after = max( (int) $this->retry_after, $other->retry_after );
		}

		if ( '' !== $other->note ) {
			$this->note = '' === $this->note ? $other->note : $this->note . '; ' . $other->note;
		}

		return $this;
	}

	public function summary(): string {
		$parts = [];

		if ( $this->succeeded ) {
			$parts[] = sprintf(
				/* translators: %d: number of URLs purged. */
				_n( '%d purged', '%d purged', count( $this->succeeded ), 'classicflare' ),
				count( $this->succeeded )
			);
		}

		if ( $this->skipped ) {
			$parts[] = sprintf(
				/* translators: %d: number of URLs outside the zone. */
				_n( '%d skipped', '%d skipped', count( $this->skipped ), 'classicflare' ),
				count( $this->skipped )
			);
		}

		if ( $this->failed ) {
			$parts[] = sprintf(
				/* translators: %d: number of URLs that failed to purge. */
				_n( '%d failed', '%d failed', count( $this->failed ), 'classicflare' ),
				count( $this->failed )
			);
		}

		if ( ! $parts ) {
			return '' !== $this->note ? $this->note : __( 'nothing to do', 'classicflare' );
		}

		$summary = implode( ', ', $parts );

		if ( '' !== $this->note ) {
			$summary .= '. ' . $this->note;
		}

		if ( $this->failed ) {
			$summary .= '. ' . $this->first_error();
		}

		return $summary;
	}
}
