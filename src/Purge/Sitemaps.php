<?php
/**
 * Sitemap URLs for the plugin that generates them.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Purge;

use ClassicFlare\Http\Loopback;
use ClassicFlare\Support\Url;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class Sitemaps {

	public const NONE = '';

	public const OPTION_INDEX = 'classicflare_sitemap_index';

	public const REFRESH_SECONDS = 43200;

	private const MAX_ENTRIES = 200;

	private const PROVIDERS = [
		'yoast'    => [ 'class' => 'WPSEO_Sitemaps_Router', 'label' => 'Yoast SEO', 'index' => '/sitemap_index.xml' ],
		'rankmath' => [ 'class' => 'RankMath\Sitemap\Router', 'label' => 'Rank Math', 'index' => '/sitemap_index.xml' ],
		'aioseo'   => [ 'function' => 'aioseo', 'label' => 'All in One SEO', 'index' => '/sitemap.xml' ],
		'seopress' => [ 'const' => 'SEOPRESS_VERSION', 'label' => 'SEOPress', 'index' => '/sitemaps.xml' ],
	];

	/**
	 * @return string Provider id, 'core', or an empty string when nothing generates sitemaps.
	 */
	public function provider(): string {
		foreach ( self::PROVIDERS as $id => $spec ) {
			if ( isset( $spec['class'] ) && class_exists( $spec['class'] ) ) {
				return $id;
			}

			if ( isset( $spec['function'] ) && function_exists( $spec['function'] ) ) {
				return $id;
			}

			if ( isset( $spec['const'] ) && defined( $spec['const'] ) ) {
				return $id;
			}
		}

		return $this->core_enabled() ? 'core' : self::NONE;
	}

	public function provider_label(): string {
		$provider = $this->provider();

		if ( self::NONE === $provider ) {
			return __( 'none found', 'classicflare' );
		}

		return 'core' === $provider
			? __( 'WordPress core', 'classicflare' )
			: (string) self::PROVIDERS[ $provider ]['label'];
	}

	public function index_url(): string {
		$provider = $this->provider();

		if ( self::NONE === $provider ) {
			return '';
		}

		return 'core' === $provider
			? home_url( '/wp-sitemap.xml' )
			: home_url( (string) self::PROVIDERS[ $provider ]['index'] );
	}

	/**
	 * @param WP_Post $post The post.
	 * @return array<int, string>
	 */
	public function for_post( WP_Post $post ): array {
		$tokens = [ $post->post_type, 'author', 'users' ];

		foreach ( get_object_taxonomies( $post->post_type, 'names' ) as $taxonomy ) {
			$tokens[] = $taxonomy;
		}

		return $this->build( $tokens, true, $post );
	}

	/**
	 * @param string $taxonomy Taxonomy name.
	 * @return array<int, string>
	 */
	public function for_taxonomy( string $taxonomy ): array {
		return $this->build( [ $taxonomy ], false, null );
	}

	/**
	 * @return array<int, string>
	 */
	public function all(): array {
		return $this->build( [], true, null, true );
	}

	/**
	 * @return array<int, string>
	 */
	public function index(): array {
		return $this->build( [], false, null );
	}

	/**
	 * @return array<int, string>
	 */
	public function discovered(): array {
		$stored = $this->stored();

		return array_values( array_filter( (array) ( $stored['urls'] ?? [] ), 'is_string' ) );
	}

	public function discovered_at(): ?int {
		$stored = $this->stored();

		return isset( $stored['time'] ) ? (int) $stored['time'] : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		$stored = get_option( self::OPTION_INDEX, [] );

		if ( ! is_array( $stored ) || ( $stored['provider'] ?? '' ) !== $this->provider() ) {
			return [];
		}

		return $stored;
	}

	/**
	 * @return array<int, string> The entries now stored.
	 */
	public function refresh(): array {
		$provider = $this->provider();
		$index    = $this->index_url();

		if ( self::NONE === $provider || '' === $index ) {
			delete_option( self::OPTION_INDEX );

			return [];
		}

		$urls = 'core' === $provider ? $this->core_list() : $this->fetch_list( $index );

		update_option(
			self::OPTION_INDEX,
			[
				'provider' => $provider,
				'index'    => $index,
				'urls'     => $urls,
				'time'     => time(),
			],
			false
		);

		return $urls;
	}

	public function schedule_refresh(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || wp_next_scheduled( Scheduler::HOOK_SITEMAPS_NOW ) ) {
			return;
		}

		wp_schedule_single_event( time() + 30, Scheduler::HOOK_SITEMAPS_NOW );
	}

	/* --------------------------------------------------------------------- */

	/**
	 * @param array<int, string> $tokens  Names the change relates to.
	 * @param bool               $news    Whether a news sitemap is affected.
	 * @param WP_Post|null       $post    The post, when the change is a post change.
	 * @param bool               $all     Take every entry, not just the related ones.
	 * @return array<int, string>
	 */
	private function build( array $tokens, bool $news, ?WP_Post $post, bool $all = false ): array {
		$provider = $this->provider();
		$urls     = [];

		if ( self::NONE !== $provider ) {
			$index = $this->index_url();
			$urls  = [ $index ];

			$stored  = $this->stored();
			$entries = $this->discovered();
			$age     = isset( $stored['time'] ) ? (int) $stored['time'] : 0;

			if ( ! $stored || time() - $age > self::REFRESH_SECONDS ) {
				$this->schedule_refresh();
			}

			foreach ( $entries as $entry ) {
				if ( $all || $this->relates( $entry, $tokens, $news ) ) {
					$urls[] = $entry;
				}
			}
		}

		/**
		 * @param array<int, string> $urls     Sitemap URLs.
		 * @param string             $provider Detected provider id, or an empty string.
		 * @param array<int, string> $tokens   Post type, taxonomies and so on the change touches.
		 * @param WP_Post|null       $post     The post, when the change is a post change.
		 */
		$urls = (array) apply_filters( 'classicflare_sitemap_urls', $urls, $provider, $tokens, $post );

		return Url::normalize_all( $urls );
	}

	/**
	 * @param string             $url    Sitemap URL.
	 * @param array<int, string> $tokens Names the change relates to.
	 * @param bool               $news   Whether a news sitemap counts as related.
	 */
	private function relates( string $url, array $tokens, bool $news ): bool {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = basename( $path );

		if ( '' === $name ) {
			return false;
		}

		if ( $news && preg_match( '/(^|[-_])news([-_.]|$)/i', $name ) ) {
			return true;
		}

		$marker = strpos( $name, '-sitemap' );
		$prefix = false === $marker ? '' : substr( $name, 0, $marker );

		foreach ( $tokens as $token ) {
			if ( '' === $token ) {
				continue;
			}

			if ( $prefix === $token || str_starts_with( $prefix, $token . '-' ) ) {
				return true;
			}

			if ( str_contains( $name, '-' . $token . '-' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<int, string>
	 */
	private function core_list(): array {
		$urls   = [];
		$server = function_exists( 'wp_sitemaps_get_server' ) ? wp_sitemaps_get_server() : null;

		if ( ! $server || ! isset( $server->index ) || ! method_exists( $server->index, 'get_sitemap_list' ) ) {
			return [];
		}

		foreach ( $server->index->get_sitemap_list() as $entry ) {
			if ( ! empty( $entry['loc'] ) ) {
				$urls[] = (string) $entry['loc'];
			}
		}

		return array_slice( $urls, 0, self::MAX_ENTRIES );
	}

	/**
	 * @param string $index Index URL.
	 * @return array<int, string>
	 */
	private function fetch_list( string $index ): array {
		$response = wp_remote_get(
			$index,
			Loopback::args(
				$index,
				[
					'timeout'    => 15,
					'user-agent' => 'ClassicFlare; ' . home_url( '/' ),
				]
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( ! preg_match_all( '#<loc>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*</loc>#is', $body, $matches ) ) {
			return [];
		}

		$urls = [];

		foreach ( $matches[1] as $url ) {
			$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_XML1, 'UTF-8' ) );

			if ( '' !== $url && $url !== $index && Url::is_same_host( $url ) ) {
				$urls[] = $url;
			}
		}

		return array_slice( array_values( array_unique( $urls ) ), 0, self::MAX_ENTRIES );
	}

	private function core_enabled(): bool {
		if ( ! function_exists( 'wp_sitemaps_get_server' ) ) {
			return false;
		}

		$server = wp_sitemaps_get_server();

		return $server && $server->sitemaps_enabled();
	}
}
