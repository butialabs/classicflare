<?php
/**
 * Settings access: options, environment variables and constants.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Support;

defined( 'ABSPATH' ) || exit;

final class Options {

	public const OPTION_SETTINGS   = 'classicflare_settings';
	public const OPTION_SECRETS    = 'classicflare_secrets';
	public const OPTION_CF_STATE   = 'classicflare_cf_state';
	public const OPTION_LAST_PURGE = 'classicflare_last_purge';
	public const OPTION_CUSTOM_URLS = 'classicflare_custom_urls';
	public const SETTINGS_BUDGET_BYTES = 4096;
	public const SECRET_KEYS = [
		'cf_api_token',
	];

	private static ?array $settings_cache = null;

	/** @var array<int, string>|null */
	private static ?array $custom_urls_cache = null;

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'enabled'                  => true,
			'admin_bar'                => true,
			'purge'                    => [
				'homepage_on_edit'   => true,
				'homepage_on_delete' => true,
				'archives_on_edit'   => true,
				'archives_on_delete' => true,
				'post_on_edit'       => true,
				'on_new_comment'     => true,
				'on_comment_status'  => true,
				'on_attachment_edit' => true,
				'on_term_change'     => true,
				'on_theme_switch'    => true,
				'on_menu_change'     => true,
				'feeds'              => true,
				'sitemaps'           => true,
				'pagination_depth'   => 5,
				'max_urls'           => 1000,
				'post_types_off'     => [],
			],
			'delete_data_on_uninstall' => false,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}

		$stored = get_option( self::OPTION_SETTINGS, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		self::$settings_cache = self::merge_deep( self::defaults(), $stored );

		return self::$settings_cache;
	}

	/**
	 * @param string $path          Dot path.
	 * @param mixed  $default_value Returned when the path is absent.
	 * @return mixed
	 */
	public static function get( string $path, mixed $default_value = null ): mixed {
		$node = self::all();

		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
				return $default_value;
			}
			$node = $node[ $segment ];
		}

		return $node;
	}

	/**
	 * @param string $path    Dot path.
	 * @param bool   $default_value Fallback.
	 */
	public static function flag( string $path, bool $default_value = false ): bool {
		$value = self::get( $path, $default_value );

		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * @return array<int, string>
	 */
	public static function disabled_post_types(): array {
		$off = self::get( 'purge.post_types_off', [] );

		return is_array( $off ) ? array_values( array_filter( $off, 'is_string' ) ) : [];
	}

	/**
	 * @param string $post_type Post type slug.
	 */
	public static function post_type_enabled( string $post_type ): bool {
		return ! in_array( $post_type, self::disabled_post_types(), true );
	}

	public static function custom_urls_raw(): string {
		return (string) get_option( self::OPTION_CUSTOM_URLS, '' );
	}

	/**
	 * @return array<int, string>
	 */
	public static function custom_urls(): array {
		if ( null !== self::$custom_urls_cache ) {
			return self::$custom_urls_cache;
		}

		$raw = self::custom_urls_raw();

		self::$custom_urls_cache = '' === trim( $raw )
			? []
			: Url::normalize_all( preg_split( '/\R/', $raw ) ?: [] );

		return self::$custom_urls_cache;
	}

	/**
	 * @param string $raw Already sanitised text.
	 */
	public static function save_custom_urls( string $raw ): bool {
		self::$custom_urls_cache = null;

		return update_option( self::OPTION_CUSTOM_URLS, $raw, false );
	}

	/**
	 * @param string $raw Raw textarea contents.
	 * @return array{text: string, rejected: array<int, string>}
	 */
	public static function sanitize_custom_urls( string $raw ): array {
		$lines    = preg_split( '/\R/', $raw ) ?: [];
		$clean    = [];
		$rejected = [];

		foreach ( $lines as $line ) {
			$line = trim( sanitize_text_field( $line ) );

			if ( '' === $line ) {
				continue;
			}

			if ( str_contains( $line, '*' ) ) {
				$rejected[] = $line;
				continue;
			}

			if ( ! preg_match( '#^https?://#i', $line ) && ! str_starts_with( $line, '/' ) ) {
				$line = '/' . $line;
			}

			if ( '' === Url::normalize( $line ) ) {
				$rejected[] = $line;
				continue;
			}

			$clean[ $line ] = true;
		}

		return [
			'text'     => implode( "\n", array_keys( $clean ) ),
			'rejected' => $rejected,
		];
	}

	/**
	 * @param array<string, mixed> $settings Full settings tree.
	 */
	public static function save( array $settings ): bool {
		self::$settings_cache = null;

		return update_option( self::OPTION_SETTINGS, $settings, true );
	}

	public static function settings_footprint(): int {
		return strlen( maybe_serialize( self::all() ) );
	}

	/**
	 * @param string $key One of SECRET_KEYS.
	 */
	public static function secret( string $key ): string {
		$external = self::external_secret( $key );
		if ( null !== $external ) {
			return $external;
		}

		$stored = get_option( self::OPTION_SECRETS, [] );

		return is_array( $stored ) && isset( $stored[ $key ] ) ? (string) $stored[ $key ] : '';
	}

	/**
	 * @param string $key One of SECRET_KEYS.
	 */
	public static function secret_source( string $key ): string {
		$name = self::constant_name( $key );

		$env = getenv( $name );
		if ( is_string( $env ) && '' !== $env ) {
			return 'env';
		}

		if ( defined( $name ) && '' !== (string) constant( $name ) ) {
			return 'constant';
		}

		$stored = get_option( self::OPTION_SECRETS, [] );
		if ( is_array( $stored ) && ! empty( $stored[ $key ] ) ) {
			return 'database';
		}

		return 'unset';
	}

	/**
	 * @param string $key One of SECRET_KEYS.
	 */
	public static function secret_is_external( string $key ): bool {
		return in_array( self::secret_source( $key ), [ 'env', 'constant' ], true );
	}

	/**
	 * @param array<string, string> $secrets Key to value.
	 */
	public static function save_secrets( array $secrets ): bool {
		$stored = get_option( self::OPTION_SECRETS, [] );
		$stored = is_array( $stored ) ? $stored : [];

		foreach ( $secrets as $key => $value ) {
			if ( ! in_array( $key, self::SECRET_KEYS, true ) ) {
				continue;
			}

			if ( self::secret_is_external( $key ) ) {
				continue;
			}

			$value = (string) $value;
			if ( '' === $value ) {
				unset( $stored[ $key ] );
				continue;
			}

			$stored[ $key ] = $value;
		}

		return update_option( self::OPTION_SECRETS, $stored, false );
	}

	/**
	 * @return array<int, string>
	 */
	public static function orphaned_secrets(): array {
		$stored = get_option( self::OPTION_SECRETS, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}

		$orphans = [];
		foreach ( self::SECRET_KEYS as $key ) {
			if ( ! empty( $stored[ $key ] ) && null !== self::external_secret( $key ) ) {
				$orphans[] = $key;
			}
		}

		return $orphans;
	}

	/**
	 * @param string $key One of SECRET_KEYS.
	 */
	public static function forget_stored_secret( string $key ): bool {
		$stored = get_option( self::OPTION_SECRETS, [] );
		if ( ! is_array( $stored ) || ! isset( $stored[ $key ] ) ) {
			return false;
		}

		unset( $stored[ $key ] );

		return update_option( self::OPTION_SECRETS, $stored, false );
	}

	/**
	 * @param string $key One of SECRET_KEYS.
	 */
	private static function external_secret( string $key ): ?string {
		$name = self::constant_name( $key );

		$env = getenv( $name );
		if ( is_string( $env ) && '' !== $env ) {
			return $env;
		}

		if ( defined( $name ) ) {
			$value = (string) constant( $name );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * @param string $key Key name.
	 */
	public static function constant_name( string $key ): string {
		return 'CLASSICFLARE_' . strtoupper( $key );
	}

	/**
	 * @param string             $key    Key name, e.g. cf_zone_id.
	 * @param array<int, string> $legacy Legacy constant names to honour.
	 */
	public static function external( string $key, array $legacy = [] ): ?string {
		$name = self::constant_name( $key );

		$env = getenv( $name );
		if ( is_string( $env ) && '' !== $env ) {
			return $env;
		}

		if ( defined( $name ) ) {
			return (string) constant( $name );
		}

		foreach ( $legacy as $constant ) {
			if ( defined( $constant ) ) {
				return (string) constant( $constant );
			}
		}

		return null;
	}

	/**
	 * @param string $key           Optional key.
	 * @param mixed  $default_value Fallback for a single key.
	 * @return mixed
	 */
	public static function cf_state( string $key = '', mixed $default_value = null ): mixed {
		$state = get_option( self::OPTION_CF_STATE, [] );
		$state = is_array( $state ) ? $state : [];

		if ( '' === $key ) {
			return $state;
		}

		return $state[ $key ] ?? $default_value;
	}

	/**
	 * @param array<string, mixed> $patch Keys to merge.
	 */
	public static function set_cf_state( array $patch ): bool {
		$state = self::cf_state();

		return update_option( self::OPTION_CF_STATE, array_merge( (array) $state, $patch ), false );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function last_purge(): array {
		$last = get_option( self::OPTION_LAST_PURGE, [] );

		return is_array( $last ) ? $last : [];
	}

	/**
	 * @param array<string, mixed> $record What happened.
	 */
	public static function set_last_purge( array $record ): void {
		update_option( self::OPTION_LAST_PURGE, $record, false );
	}

	/**
	 * @param array<string, mixed> $defaults Defaults.
	 * @param array<string, mixed> $stored   Stored values.
	 * @return array<string, mixed>
	 */
	private static function merge_deep( array $defaults, array $stored ): array {
		foreach ( $stored as $key => $value ) {
			if ( is_array( $value ) && isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) ) {
				$defaults[ $key ] = self::merge_deep( $defaults[ $key ], $value );
				continue;
			}
			$defaults[ $key ] = $value;
		}

		return $defaults;
	}

	public static function flush(): void {
		self::$settings_cache    = null;
		self::$custom_urls_cache = null;
	}
}
