<?php
/**
 * Works out which URLs a change invalidates.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Purge;

use ClassicFlare\Support\Options;
use ClassicFlare\Support\Url;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

final class UrlCollector {

	private const FEED_TYPES = [ 'rss2', 'atom', 'rdf' ];

	public function __construct( private readonly Sitemaps $sitemaps = new Sitemaps() ) {}

	/**
	 * @param int          $post_id Post id.
	 * @param WP_Post|null $before  Pre-update post, when available, so a changed permalink
	 *                              purges the old URL too.
	 * @param bool         $deleting Whether the post is going away.
	 * @return array<int, string>
	 */
	public function for_post( int $post_id, ?WP_Post $before = null, bool $deleting = false ): array {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return [];
		}

		$urls = [];

		if ( Options::flag( $deleting ? 'purge.homepage_on_delete' : 'purge.homepage_on_edit', true ) ) {
			$urls = array_merge( $urls, $this->home() );
		}

		if ( Options::flag( 'purge.post_on_edit', true ) ) {
			$urls = array_merge( $urls, $this->permalinks_for( $post, $before ) );
		}

		if ( Options::flag( $deleting ? 'purge.archives_on_delete' : 'purge.archives_on_edit', true ) ) {
			$urls = array_merge( $urls, $this->archives_for( $post ) );
			$urls = array_merge( $urls, $this->terms_for( $post ) );
		}

		if ( 'attachment' === $post->post_type ) {
			$urls = array_merge( $urls, $this->attachment_sizes( $post_id ) );
		}

		if ( Options::flag( 'purge.sitemaps', true ) ) {
			$urls = array_merge( $urls, $this->sitemaps->for_post( $post ) );
		}

		/**
		 * @param array<int, string> $urls    Collected URLs.
		 * @param int                $post_id Post id.
		 * @param WP_Post            $post    The post.
		 */
		$urls = (array) apply_filters( 'classicflare_collect_post_urls', $urls, $post_id, $post );

		return Url::normalize_all( $urls );
	}

	/**
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 * @return array<int, string>
	 */
	public function for_term( int $term_id, string $taxonomy ): array {
		$urls = $this->home();

		$link = get_term_link( $term_id, $taxonomy );

		if ( is_string( $link ) ) {
			$urls[] = $link;

			if ( Options::flag( 'purge.feeds', true ) ) {
				foreach ( self::FEED_TYPES as $type ) {
					$feed = get_term_feed_link( $term_id, $taxonomy, $type );
					if ( is_string( $feed ) && '' !== $feed ) {
						$urls[] = $feed;
					}
				}
			}
		}

		if ( Options::flag( 'purge.sitemaps', true ) ) {
			$urls = array_merge( $urls, $this->sitemaps->for_taxonomy( $taxonomy ) );
		}

		return Url::normalize_all( $urls );
	}

	/**
	 * @return array<int, string>
	 */
	public function home(): array {
		$home = function_exists( 'icl_get_home_url' ) ? icl_get_home_url() : home_url( '/' );
		$home = user_trailingslashit( $home );

		$urls = [ $home ];

		$depth = max( 0, (int) Options::get( 'purge.pagination_depth', 5 ) );
		for ( $page = 2; $page <= $depth + 1; $page++ ) {
			$urls[] = $this->home_page_url( $page );
		}

		$posts_page = (int) get_option( 'page_for_posts' );
		if ( $posts_page > 0 ) {
			$link = get_permalink( $posts_page );
			if ( is_string( $link ) ) {
				$urls[] = $link;
			}
		}

		$urls = array_merge( $urls, $this->site_feeds() );

		return $urls;
	}

	/**
	 * @return array<int, string>
	 */
	public function site_feeds(): array {
		if ( ! Options::flag( 'purge.feeds', true ) ) {
			return [];
		}

		$feeds = [];

		foreach ( [ 'atom_url', 'rdf_url', 'rss_url', 'rss2_url', 'comments_atom_url', 'comments_rss2_url' ] as $key ) {
			$feed = get_bloginfo_rss( $key );
			if ( is_string( $feed ) && '' !== $feed ) {
				$feeds[] = $feed;
			}
		}

		return $feeds;
	}

	/**
	 * @param int $page Page number, 2 or more.
	 */
	private function home_page_url( int $page ): string {
		global $wp_rewrite;

		if ( ! $wp_rewrite instanceof \WP_Rewrite || ! $wp_rewrite->using_permalinks() ) {
			return add_query_arg( 'paged', $page, home_url( '/' ) );
		}

		return user_trailingslashit( trailingslashit( home_url() ) . $wp_rewrite->pagination_base . '/' . $page, 'paged' );
	}

	/**
	 * @param WP_Post      $post   The post.
	 * @param WP_Post|null $before Pre-update post.
	 * @return array<int, string>
	 */
	private function permalinks_for( WP_Post $post, ?WP_Post $before ): array {
		$urls = [];

		$permalink = $this->permalink_for( $post );
		if ( '' !== $permalink ) {
			$urls[] = $permalink;
			$urls   = array_merge( $urls, $this->variants_for( $permalink, $post ) );
		}

		if ( $before instanceof WP_Post ) {
			$previous = $this->permalink_for( $before );
			if ( '' !== $previous && $previous !== $permalink ) {
				$urls[] = $previous;
				$urls   = array_merge( $urls, $this->variants_for( $previous, $post ) );
			}
		}

		return $urls;
	}

	/**
	 * @param WP_Post $post The post.
	 */
	private function permalink_for( WP_Post $post ): string {
		if ( 'publish' === $post->post_status ) {
			$link = get_permalink( $post );

			return is_string( $link ) ? $link : '';
		}

		if ( 'trash' === $post->post_status ) {
			$clone              = clone $post;
			$clone->post_status = 'publish';
			$clone->post_name   = (string) preg_replace( '/__trashed$/', '', $post->post_name );

			$link = get_permalink( $clone );

			return is_string( $link ) ? $link : '';
		}

		if ( ! function_exists( 'get_sample_permalink' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		$sample = get_sample_permalink( $post->ID );

		if ( ! is_array( $sample ) || ! isset( $sample[0] ) ) {
			return '';
		}

		$slug = '' !== (string) $sample[1] ? (string) $sample[1] : $post->post_name;

		return str_replace( [ '%postname%', '%pagename%' ], $slug, (string) $sample[0] );
	}

	/**
	 * @param string  $url  Base URL.
	 * @param WP_Post $post The post, whose type decides whether it has a comment feed at all.
	 * @return array<int, string>
	 */
	private function variants_for( string $url, WP_Post $post ): array {
		if ( ! Options::flag( 'purge.feeds', true ) || ! post_type_supports( $post->post_type, 'comments' ) ) {
			return [];
		}

		if ( ! str_contains( $url, '?' ) ) {
			return Url::feed_variants( $url );
		}

		$feeds = [];

		foreach ( self::FEED_TYPES as $type ) {
			$feed = get_post_comments_feed_link( $post->ID, $type );
			if ( is_string( $feed ) && '' !== $feed ) {
				$feeds[] = $feed;
			}
		}

		return $feeds;
	}

	/**
	 * @param WP_Post $post The post.
	 * @return array<int, string>
	 */
	private function archives_for( WP_Post $post ): array {
		$urls = [];

		$timestamp = strtotime( $post->post_date_gmt ?: $post->post_date );
		if ( $timestamp ) {
			$year  = (int) gmdate( 'Y', $timestamp );
			$month = (int) gmdate( 'm', $timestamp );
			$day   = (int) gmdate( 'd', $timestamp );

			$urls[] = get_year_link( $year );
			$urls[] = get_month_link( $year, $month );
			$urls[] = get_day_link( $year, $month, $day );
		}

		$author = (int) $post->post_author;
		if ( $author > 0 ) {
			$urls[] = get_author_posts_url( $author );

			if ( Options::flag( 'purge.feeds', true ) ) {
				$urls[] = get_author_feed_link( $author );
			}
		}

		$archive = get_post_type_archive_link( $post->post_type );
		if ( is_string( $archive ) && '' !== $archive ) {
			$urls[] = $archive;

			if ( Options::flag( 'purge.feeds', true ) ) {
				$feed = get_post_type_archive_feed_link( $post->post_type );
				if ( is_string( $feed ) && '' !== $feed ) {
					$urls[] = $feed;
				}
			}
		}

		return $urls;
	}

	/**
	 * @param WP_Post $post The post.
	 * @return array<int, string>
	 */
	private function terms_for( WP_Post $post ): array {
		$urls       = [];
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );

		foreach ( $taxonomies as $taxonomy ) {
			if ( empty( $taxonomy->public ) ) {
				continue;
			}

			$terms = get_the_terms( $post, $taxonomy->name );

			if ( ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				if ( ! $term instanceof WP_Term ) {
					continue;
				}

				$link = get_term_link( $term );
				if ( ! is_string( $link ) ) {
					continue;
				}

				$urls[] = $link;

				if ( Options::flag( 'purge.feeds', true ) ) {
					$feed = get_term_feed_link( $term->term_id, $term->taxonomy );
					if ( is_string( $feed ) && '' !== $feed ) {
						$urls[] = $feed;
					}
				}
			}
		}

		return $urls;
	}

	/**
	 * @param int $post_id Attachment id.
	 * @return array<int, string>
	 */
	private function attachment_sizes( int $post_id ): array {
		$urls = [];

		$full = wp_get_attachment_url( $post_id );
		if ( is_string( $full ) && '' !== $full ) {
			$urls[] = $full;
		}

		$page = get_permalink( $post_id );
		if ( is_string( $page ) && '' !== $page ) {
			$urls[] = $page;
		}

		foreach ( get_intermediate_image_sizes() as $size ) {
			$image = wp_get_attachment_image_src( $post_id, $size );

			if ( is_array( $image ) && ! empty( $image[0] ) ) {
				$urls[] = (string) $image[0];
			}
		}

		return $urls;
	}
}
