<?php
/**
 * Bootstrap and wiring.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare;

use ClassicFlare\Cloudflare\Client;
use ClassicFlare\Cloudflare\Purger;
use ClassicFlare\Purge\Coordinator;
use ClassicFlare\Purge\Hooks;
use ClassicFlare\Purge\Scheduler;
use ClassicFlare\Purge\Sitemaps;
use ClassicFlare\Purge\UrlCollector;
use ClassicFlare\Support\Options;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?self $instance = null;

	private Container $container;

	private function __construct() {
		$this->container = new Container();
		$this->register_services();
	}

	public static function boot(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register_hooks();
		}

		return self::$instance;
	}

	public static function instance(): ?self {
		return self::$instance;
	}

	public function container(): Container {
		return $this->container;
	}

	/**
	 * @param string $id Service id.
	 * @return mixed
	 */
	public static function service( string $id ): mixed {
		$plugin = self::instance();

		return $plugin ? $plugin->container->get( $id ) : null;
	}

	private function register_services(): void {
		$this->container->set( 'cloudflare', static fn (): Client => new Client() );

		$this->container->set( 'purger', static fn ( Container $c ): Purger => new Purger( $c->get( 'cloudflare' ) ) );

		$this->container->set( 'collector', static fn (): UrlCollector => new UrlCollector() );

		$this->container->set(
			'coordinator',
			static fn ( Container $c ): Coordinator => new Coordinator( $c->get( 'purger' ) )
		);
	}

	private function register_hooks(): void {
		add_action( Scheduler::HOOK_PURGE, [ $this, 'run_scheduled_purge' ], 10, 4 );
		add_action( Scheduler::HOOK_SITEMAPS, [ $this, 'refresh_sitemaps' ] );
		add_action( Scheduler::HOOK_SITEMAPS_NOW, [ $this, 'refresh_sitemaps' ] );

		Api\Facade::register_actions();

		$admin_bar = new Admin\AdminBar();

		if ( is_admin() ) {
			( new Admin\Menu() )->register();
			( new Admin\Notices() )->register();
			$admin_bar->register_handler();
		}

		if ( Options::flag( 'admin_bar', true ) ) {
			$admin_bar->register_nodes();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'classicflare', Cli\Command::class );
		}

		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'init', [ Scheduler::class, 'schedule' ] );

		( new Hooks( $this->container ) )->register();
	}

	public static function is_enabled(): bool {
		if ( defined( 'CLASSICFLARE_DISABLE' ) && constant( 'CLASSICFLARE_DISABLE' ) ) {
			return false;
		}

		if ( 'true' === strtolower( (string) getenv( 'CLASSICFLARE_DISABLE' ) ) ) {
			return false;
		}

		return Options::flag( 'enabled', true );
	}

	/**
	 * @param mixed $urls      URLs.
	 * @param mixed $purge_all Purge the whole zone.
	 * @param mixed $attempt   Attempt number.
	 * @param mixed $reason    Who asked.
	 */
	public function run_scheduled_purge( $urls = [], $purge_all = false, $attempt = 1, $reason = '' ): void {
		/** @var Coordinator $coordinator */
		$coordinator = $this->container->get( 'coordinator' );
		$coordinator->on_cron( $urls, $purge_all, $attempt, $reason );
	}

	public function refresh_sitemaps(): void {
		if ( ! Options::flag( 'purge.sitemaps', true ) ) {
			return;
		}

		( new Sitemaps() )->refresh();
	}
}
