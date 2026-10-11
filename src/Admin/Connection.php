<?php
/**
 * Connecting to and disconnecting from Cloudflare.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Admin;

use ClassicFlare\Cloudflare\Client;
use ClassicFlare\Cloudflare\Credentials;
use ClassicFlare\Cloudflare\Exception\ApiException;
use ClassicFlare\Cloudflare\Purger;
use ClassicFlare\Cloudflare\ZoneResolver;
use ClassicFlare\Support\Options;

defined( 'ABSPATH' ) || exit;

final class Connection {

	public const ACTION_CONNECT    = 'classicflare_connect';
	public const ACTION_DISCONNECT = 'classicflare_disconnect';
	public const ACTION_ZONE       = 'classicflare_select_zone';
	public const ACTION_TEST       = 'classicflare_test';

	public const REQUIRED_PERMISSIONS = [
		'Zone Read',
		'Cache Purge',
	];

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_CONNECT, [ $this, 'handle_connect' ] );
		add_action( 'admin_post_' . self::ACTION_DISCONNECT, [ $this, 'handle_disconnect' ] );
		add_action( 'admin_post_' . self::ACTION_ZONE, [ $this, 'handle_select_zone' ] );
		add_action( 'admin_post_' . self::ACTION_TEST, [ $this, 'handle_test' ] );
	}

	public function handle_connect(): void {
		$this->guard( self::ACTION_CONNECT );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$token = isset( $_POST['cf_api_token'] ) ? sanitize_text_field( wp_unslash( $_POST['cf_api_token'] ) ) : '';

		[ $ok, $message ] = $this->connect( $token );

		$this->finish( $message, $ok ? 'success' : 'error' );
	}

	public function handle_disconnect(): void {
		$this->guard( self::ACTION_DISCONNECT );

		$this->disconnect();

		$this->finish( __( 'Disconnected from Cloudflare. The stored token was deleted.', 'classicflare' ), 'success' );
	}

	public function handle_select_zone(): void {
		$this->guard( self::ACTION_ZONE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$zone_id = isset( $_POST['zone_id'] ) ? sanitize_text_field( wp_unslash( $_POST['zone_id'] ) ) : '';

		[ $ok, $message ] = $this->select_zone( $zone_id );

		$this->finish( $message, $ok ? 'success' : 'error' );
	}

	public function handle_test(): void {
		$this->guard( self::ACTION_TEST );

		[ $ok, $message ] = $this->test();

		$this->finish( $message, $ok ? 'success' : 'error' );
	}

	/**
	 * Verify the token and confirm the zone is reachable.
	 *
	 * @return array{0: bool, 1: string}
	 */
	public function test(): array {
		$unavailable = ( new Purger() )->unavailable();

		if ( null !== $unavailable ) {
			return [ false, $unavailable['reason'] . ' ' . $unavailable['hint'] ];
		}

		$client = $this->client();

		try {
			$token = $client->verify_token();
			$zone  = $client->get_zone( Credentials::zone_id() );
		} catch ( ApiException $e ) {
			if ( in_array( $e->getCode(), [ 401, 403 ], true ) ) {
				Options::set_cf_state( [ 'token_status' => 'invalid' ] );
			}

			return [ false, $e->getMessage() ];
		}

		$status = (string) ( $token['status'] ?? 'active' );

		Options::set_cf_state(
			[
				'token_status' => $status,
				'verified_at'  => time(),
			]
		);

		return [
			true,
			sprintf(
				/* translators: 1: token status, 2: zone name. */
				__( 'Token is %1$s and can see %2$s.', 'classicflare' ),
				$status,
				(string) ( $zone['name'] ?? Credentials::zone_id() )
			),
		];
	}

	/**
	 * @param string $token New token, or an empty string to re-check the current one.
	 * @return array{0: bool, 1: string} Success flag and a message for the operator.
	 */
	public function connect( string $token ): array {
		$token = trim( $token );

		if ( '' !== $token ) {
			Options::save_secrets( [ 'cf_api_token' => $token ] );
		}

		if ( ! Credentials::configured() ) {
			return [ false, __( 'Paste your Cloudflare API token first.', 'classicflare' ) ];
		}

		$client = $this->client();

		try {
			$verified = $client->verify_token();
		} catch ( ApiException $e ) {
			Options::set_cf_state( [ 'token_status' => 'invalid' ] );

			return [
				false,
				sprintf(
					/* translators: %s: error message from Cloudflare. */
					__( 'Cloudflare rejected that token: %s', 'classicflare' ),
					$e->getMessage()
				),
			];
		}

		$token_id = (string) ( $verified['id'] ?? '' );

		Options::set_cf_state(
			[
				'token_id'     => $token_id,
				'token_status' => (string) ( $verified['status'] ?? '' ),
				'verified_at'  => time(),
			]
		);

		$missing = $this->missing_permissions( $client, $token_id );

		if ( $missing ) {
			return [
				false,
				sprintf(
					/* translators: %s: comma separated permission names. */
					__( 'That token is valid but is missing permissions: %s. Edit the token in Cloudflare and try again.', 'classicflare' ),
					implode( ', ', $missing )
				),
			];
		}

		if ( null !== Options::external( 'cf_zone_id' ) ) {
			return [
				true,
				sprintf(
					/* translators: %s: zone id. */
					__( 'Token verified. Using zone %s from CLASSICFLARE_CF_ZONE_ID.', 'classicflare' ),
					Credentials::zone_id()
				),
			];
		}

		$resolver = new ZoneResolver( $client );

		try {
			$resolved = $resolver->resolve( true );
		} catch ( ApiException $e ) {
			return [ false, $e->getMessage() ];
		}

		if ( null === $resolved ) {
			$this->remember_available_zones( $resolver );

			return [
				false,
				sprintf(
					/* translators: %s: site hostname. */
					__( 'The token works, but no Cloudflare zone matches %s. Pick the zone below if this site lives under another name, or check the token covers this site.', 'classicflare' ),
					(string) wp_parse_url( home_url(), PHP_URL_HOST )
				),
			];
		}

		Options::set_cf_state( [ 'available_zones' => [] ] );

		return [
			true,
			sprintf(
				/* translators: 1: zone name, 2: Cloudflare plan. */
				__( 'Connected to %1$s (%2$s plan).', 'classicflare' ),
				$resolved['zone_name'],
				'' === $resolved['plan'] ? __( 'unknown', 'classicflare' ) : $resolved['plan']
			),
		];
	}

	/**
	 * @param string $zone_id Zone id.
	 * @return array{0: bool, 1: string}
	 */
	public function select_zone( string $zone_id ): array {
		if ( '' === $zone_id ) {
			return [ false, __( 'Choose a zone.', 'classicflare' ) ];
		}

		try {
			$zone = $this->client()->get_zone( $zone_id );
		} catch ( ApiException $e ) {
			return [ false, $e->getMessage() ];
		}

		Options::set_cf_state(
			[
				'zone_id'         => (string) ( $zone['id'] ?? $zone_id ),
				'zone_name'       => (string) ( $zone['name'] ?? '' ),
				'account_id'      => (string) ( $zone['account']['id'] ?? '' ),
				'plan'            => strtolower( (string) ( $zone['plan']['legacy_id'] ?? '' ) ),
				'resolved_at'     => time(),
				'available_zones' => [],
			]
		);

		return [
			true,
			sprintf(
				/* translators: %s: zone name. */
				__( 'Connected to %s.', 'classicflare' ),
				(string) ( $zone['name'] ?? $zone_id )
			),
		];
	}

	public function disconnect(): void {
		Options::forget_stored_secret( 'cf_api_token' );

		update_option( Options::OPTION_CF_STATE, [], false );
	}

	/**
	 * @param Client $client   API client.
	 * @param string $token_id Token id.
	 * @return array<int, string>
	 */
	private function missing_permissions( Client $client, string $token_id ): array {
		if ( '' === $token_id ) {
			return [];
		}

		try {
			$details = $client->token_details( $token_id );
		} catch ( ApiException $e ) {
			return [];
		}

		$granted = [];

		foreach ( (array) ( $details['policies'] ?? [] ) as $policy ) {
			foreach ( (array) ( $policy['permission_groups'] ?? [] ) as $group ) {
				$granted[] = (string) ( $group['name'] ?? '' );
			}
		}

		if ( ! $granted ) {
			return [];
		}

		$missing = [];

		foreach ( self::REQUIRED_PERMISSIONS as $needed ) {
			$found = false;

			foreach ( $granted as $name ) {
				if ( false !== stripos( $name, $needed ) ) {
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				$missing[] = $needed;
			}
		}

		return $missing;
	}

	/**
	 * @param ZoneResolver $resolver Resolver.
	 */
	private function remember_available_zones( ZoneResolver $resolver ): void {
		try {
			$zones = $resolver->available_zones();
		} catch ( ApiException $e ) {
			$zones = [];
		}

		Options::set_cf_state( [ 'available_zones' => array_slice( $zones, 0, 50 ) ] );
	}

	private function client(): Client {
		return new Client( 15.0 );
	}

	/**
	 * @param string $action Nonce action.
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( Menu::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage the Cloudflare connection.', 'classicflare' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( $action );
	}

	/**
	 * @param string $message Message.
	 * @param string $type    success, error, warning or info.
	 */
	private function finish( string $message, string $type ): void {
		Menu::redirect_with_notice( $message, $type );
	}
}
