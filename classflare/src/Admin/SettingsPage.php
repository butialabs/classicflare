<?php
/**
 * Settings screen.
 *
 * @package ClassicFlare
 */

declare( strict_types = 1 );

namespace ClassicFlare\Admin;

use ClassicFlare\Cloudflare\Credentials;
use ClassicFlare\Purge\Sitemaps;
use ClassicFlare\Support\Options;
use ClassicFlare\Support\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Core markup only (nav-tab, form-table, inline notices), so it needs no stylesheet and looks
 * native on WordPress and ClassicPress alike. Each settings tab posts only its own fields; the
 * sanitizer keeps the others as they were.
 */
final class SettingsPage {

	private const TRIGGERS = [ 'post_on_edit', 'on_new_comment', 'on_comment_status', 'on_attachment_edit', 'on_term_change', 'on_theme_switch', 'on_menu_change' ];

	private const SCOPE = [ 'homepage_on_edit', 'homepage_on_delete', 'archives_on_edit', 'archives_on_delete', 'feeds', 'sitemaps' ];

	/**
	 * @return array<string, string>
	 */
	private static function tabs(): array {
		return [
			'connection' => __( 'Connection', 'classicflare' ),
			'triggers'   => __( 'When to purge', 'classicflare' ),
			'scope'      => __( 'What to purge', 'classicflare' ),
			'general'    => __( 'General', 'classicflare' ),
		];
	}

	public function render(): void {
		$settings = Options::all();
		$active   = $this->active_tab();

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'ClassicFlare', 'classicflare' ) );

		$this->render_nav( $active );

		if ( 'connection' === $active ) {
			$this->render_connection();
		} else {
			echo '<form method="post" action="options.php">';
			settings_fields( Menu::GROUP );
			printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $this->field_name( 'tab' ) ), esc_attr( $active ) );

			match ( $active ) {
				'triggers' => $this->render_triggers( $settings ),
				'scope'    => $this->render_scope( $settings ),
				default    => $this->render_general( $settings ),
			};

			submit_button();
			echo '</form>';
		}

		echo '</div>';
	}

	/**
	 * @param string $active Active tab id.
	 */
	private function render_nav( string $active ): void {
		printf( '<nav class="nav-tab-wrapper wp-clearfix" aria-label="%s">', esc_attr__( 'Settings sections', 'classicflare' ) );

		foreach ( self::tabs() as $id => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s"%s>%s</a>',
				esc_url( Menu::url( $id ) ),
				$id === $active ? ' nav-tab-active' : '',
				$id === $active ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	private function active_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return array_key_exists( $tab, self::tabs() ) ? $tab : 'connection';
	}

	/* --------------------------------------------------------------------- */
	/* Connection                                                             */
	/* --------------------------------------------------------------------- */

	private function render_connection(): void {
		$source   = Credentials::token_source();
		$external = in_array( $source, [ 'env', 'constant' ], true );
		$zone_id  = Credentials::zone_id();

		printf(
			'<p>%s</p><ol><li>%s</li><li>%s</li><li>%s</li><li>%s</li><li>%s</li></ol><p>%s</p>',
			esc_html__( 'To connect, create an API token in Cloudflare:', 'classicflare' ),
			sprintf(
				/* translators: %s: link to the Cloudflare API Tokens page. */
				esc_html__( 'Open %s and click Create Token.', 'classicflare' ),
				'<a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener noreferrer">' . esc_html__( 'API Tokens in your Cloudflare profile', 'classicflare' ) . '</a>'
			),
			esc_html__( 'Choose Create Custom Token.', 'classicflare' ),
			esc_html__( 'Under Permissions, add two rows: Zone, Zone, Read and Zone, Cache Purge, Purge.', 'classicflare' ),
			esc_html__( 'Under Zone Resources, choose Include, Specific zone, and this site\'s domain.', 'classicflare' ),
			esc_html__( 'Click Continue to summary, then Create Token, and paste the token below. Cloudflare shows it only once.', 'classicflare' ),
			esc_html__( 'A global API key is never asked for: it covers your whole account and cannot be limited to one site.', 'classicflare' )
		);

		$this->open_post_form( Connection::ACTION_CONNECT );

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="classicflare-token">' . esc_html__( 'API token', 'classicflare' ) . '</label></th><td>';

		if ( $external ) {
			printf(
				'<code>%s</code><p class="description">%s</p>',
				esc_html( Redactor::mask( Credentials::token() ) ),
				esc_html(
					'env' === $source
						? __( 'Set by an environment variable, never stored in the database.', 'classicflare' )
						: __( 'Set by a constant in wp-config.php, never stored in the database.', 'classicflare' )
				)
			);
		} else {
			printf(
				'<input type="password" id="classicflare-token" name="cf_api_token" value="" autocomplete="new-password" class="regular-text" placeholder="%s" />',
				esc_attr(
					'database' === $source
						? Redactor::mask( Credentials::token() )
						: __( 'paste your token here', 'classicflare' )
				)
			);

			printf(
				'<p class="description">%s</p>',
				sprintf(
					/* translators: %s: constant name. */
					esc_html__( 'Leave blank to keep the current token. Better still, define %s in wp-config.php or the environment.', 'classicflare' ),
					'<b>' . esc_html( Options::constant_name( 'cf_api_token' ) ) . '</b>'
				)
			);
		}

		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Status', 'classicflare' ) . '</th><td>';
		$this->render_status( $zone_id );
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( '' === $zone_id ? __( 'Connect', 'classicflare' ) : __( 'Save and reconnect', 'classicflare' ) );
		echo '</form>';

		$this->render_zone_picker();
		$this->render_tools( $source, $zone_id );
	}

	/**
	 * @param string $zone_id Zone id.
	 */
	private function render_status( string $zone_id ): void {
		$zone_name = Credentials::zone_name();
		$plan      = (string) Options::cf_state( 'plan', '' );

		if ( '' === $zone_id ) {
			$this->inline_notice( 'warning', __( 'Not connected. Nothing is being purged.', 'classicflare' ) );
		} elseif ( 'invalid' === Options::cf_state( 'token_status', '' ) ) {
			$this->inline_notice( 'error', __( 'Cloudflare refused the token on the last call. Paste a new one and reconnect.', 'classicflare' ) );
		} else {
			$this->inline_notice(
				'success',
				sprintf(
					/* translators: 1: zone name, 2: Cloudflare plan name. */
					__( 'Connected to %1$s (%2$s plan).', 'classicflare' ),
					'' !== $zone_name ? $zone_name : $zone_id,
					'' !== $plan ? $plan : __( 'unknown', 'classicflare' )
				)
			);
		}

		$last = Options::last_purge();

		if ( empty( $last['time'] ) ) {
			return;
		}

		$this->inline_notice(
			! empty( $last['ok'] ) ? 'info' : 'error',
			sprintf(
				/* translators: 1: human time difference, 2: what happened, 3: retry note. */
				__( 'Last purge %1$s ago: %2$s%3$s', 'classicflare' ),
				human_time_diff( (int) $last['time'], time() ),
				(string) ( $last['summary'] ?? '' ),
				! empty( $last['retrying'] ) ? ' ' . __( '(will retry automatically)', 'classicflare' ) : ''
			)
		);
	}

	private function render_zone_picker(): void {
		$zones = Options::cf_state( 'available_zones', [] );

		if ( ! is_array( $zones ) || ! $zones || '' !== Credentials::zone_id() ) {
			return;
		}

		printf( '<h2>%s</h2>', esc_html__( 'Choose the zone', 'classicflare' ) );

		$this->open_post_form( Connection::ACTION_ZONE );

		echo '<table class="form-table" role="presentation"><tbody><tr>';
		printf( '<th scope="row"><label for="classicflare-zone">%s</label></th><td>', esc_html__( 'Zone', 'classicflare' ) );
		echo '<select id="classicflare-zone" name="zone_id">';

		foreach ( $zones as $zone ) {
			printf(
				'<option value="%s">%s</option>',
				esc_attr( (string) ( $zone['id'] ?? '' ) ),
				esc_html( (string) ( $zone['name'] ?? '' ) )
			);
		}

		echo '</select></td></tr></tbody></table>';

		submit_button( __( 'Use this zone', 'classicflare' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * One small form per row: forms cannot nest, but each can live inside its own cell.
	 *
	 * @param string $source  Where the token comes from.
	 * @param string $zone_id Zone id.
	 */
	private function render_tools( string $source, string $zone_id ): void {
		if ( ! Credentials::configured() ) {
			return;
		}

		printf( '<h2>%s</h2>', esc_html__( 'Tools', 'classicflare' ) );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Test connection', 'classicflare' ) . '</th><td>';
		$this->open_post_form( Connection::ACTION_TEST );
		submit_button( __( 'Test connection', 'classicflare' ), 'secondary', 'submit', false );
		printf( '<p class="description">%s</p>', esc_html__( 'Checks that the token is valid and can still see the zone.', 'classicflare' ) );
		echo '</form></td></tr>';

		if ( '' !== $zone_id ) {
			echo '<tr><th scope="row">' . esc_html__( 'Purge everything', 'classicflare' ) . '</th><td>';
			$this->open_post_form(
				AdminBar::ACTION,
				__( 'Purge everything Cloudflare caches for this zone?', 'classicflare' )
			);
			echo '<input type="hidden" name="what" value="all" /><input type="hidden" name="back" value="settings" />';
			submit_button( __( 'Purge everything', 'classicflare' ), 'secondary', 'submit', false );
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Clears every file Cloudflare holds for this zone, not only this site\'s pages. Cloudflare rate limits it, so keep it for when something is badly out of date: ordinary edits are already handled automatically.', 'classicflare' )
			);
			echo '</form></td></tr>';
		}

		if ( 'database' === $source ) {
			echo '<tr><th scope="row">' . esc_html__( 'Disconnect', 'classicflare' ) . '</th><td>';
			$this->open_post_form( Connection::ACTION_DISCONNECT );
			printf( '<button type="submit" class="button button-link-delete">%s</button>', esc_html__( 'Disconnect and delete the stored token', 'classicflare' ) );
			echo '</form></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Open a nonced form posting to admin-post.php.
	 *
	 * @param string $action  admin-post action, also the nonce action.
	 * @param string $confirm Optional confirmation question.
	 */
	private function open_post_form( string $action, string $confirm = '' ): void {
		printf(
			'<form method="post" action="%s"%s>',
			esc_url( admin_url( 'admin-post.php' ) ),
			'' === $confirm ? '' : ' onsubmit="return window.confirm(\'' . esc_js( $confirm ) . '\');"'
		);
		wp_nonce_field( $action );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( $action ) );
	}

	/**
	 * @param string $type    success, error, warning or info.
	 * @param string $message Message.
	 */
	private function inline_notice( string $type, string $message ): void {
		printf( '<div class="notice notice-%s inline"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}

	/* --------------------------------------------------------------------- */
	/* Settings tabs                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function render_triggers( array $settings ): void {
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->checkbox(
			'enabled',
			__( 'Automatic purging', 'classicflare' ),
			(bool) $settings['enabled'],
			__( 'Off means none of the events below purge anything. Manual purges, the API and WP-CLI still work.', 'classicflare' )
		);

		$labels = [
			'post_on_edit'       => __( 'A post or page is published, updated or trashed', 'classicflare' ),
			'on_new_comment'     => __( 'A new comment is published', 'classicflare' ),
			'on_comment_status'  => __( 'A comment is approved, unapproved, spammed or trashed', 'classicflare' ),
			'on_attachment_edit' => __( 'A media file is edited or deleted', 'classicflare' ),
			'on_term_change'     => __( 'A category or tag is created, edited or deleted', 'classicflare' ),
			'on_theme_switch'    => __( 'The theme or Customizer changes (purges everything)', 'classicflare' ),
			'on_menu_change'     => __( 'A menu changes (purges everything)', 'classicflare' ),
		];

		foreach ( self::TRIGGERS as $key ) {
			$this->checkbox( 'purge[' . $key . ']', $labels[ $key ], (bool) ( $settings['purge'][ $key ] ?? false ) );
		}

		echo '</tbody></table>';
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function render_scope( array $settings ): void {
		printf(
			'<p>%s</p>',
			esc_html__( 'A post appears on more pages than its own. These decide how far a single change reaches.', 'classicflare' )
		);

		echo '<table class="form-table" role="presentation"><tbody>';

		$labels = [
			'homepage_on_edit'   => __( 'The homepage, when a post changes', 'classicflare' ),
			'homepage_on_delete' => __( 'The homepage, when a post is deleted', 'classicflare' ),
			'archives_on_edit'   => __( 'Category, tag, date and author archives, when a post changes', 'classicflare' ),
			'archives_on_delete' => __( 'Archives, when a post is deleted', 'classicflare' ),
			'feeds'              => __( 'RSS and Atom feeds', 'classicflare' ),
		];

		foreach ( $labels as $key => $label ) {
			$this->checkbox( 'purge[' . $key . ']', $label, (bool) ( $settings['purge'][ $key ] ?? false ) );
		}

		$sitemaps = new Sitemaps();

		$this->checkbox(
			'purge[sitemaps]',
			__( 'XML sitemaps', 'classicflare' ),
			(bool) ( $settings['purge']['sitemaps'] ?? true ),
			( Sitemaps::NONE === $sitemaps->provider()
				? __( 'Nothing on this site generates sitemaps right now, so none are cleared.', 'classicflare' )
				: sprintf(
					/* translators: %s: name of the plugin generating sitemaps. */
					__( 'Sitemaps here come from %s. A cached one keeps listing the posts you had yesterday.', 'classicflare' ),
					$sitemaps->provider_label()
				) )
			. ' ' . sprintf(
				/* translators: %s: comma-separated list of supported sitemap generators. */
				__( 'Supported: %s.', 'classicflare' ),
				implode( ', ', Sitemaps::supported_labels() )
			)
		);

		$this->number(
			'purge[pagination_depth]',
			__( 'Paged homepage depth', 'classicflare' ),
			(int) $settings['purge']['pagination_depth'],
			0,
			50,
			__( 'How many of /page/2/, /page/3/ and so on to clear alongside the homepage.', 'classicflare' )
		);

		$this->number(
			'purge[max_urls]',
			__( 'Purge everything above', 'classicflare' ),
			(int) $settings['purge']['max_urls'],
			10,
			100000,
			__( 'When one change would clear more URLs than this, purging the whole zone is faster and cheaper, so that is what happens.', 'classicflare' )
		);

		echo '<tr><th scope="row">' . esc_html__( 'Post types', 'classicflare' ) . '</th><td>';
		printf( '<fieldset><legend class="screen-reader-text">%s</legend>', esc_html__( 'Post types', 'classicflare' ) );

		foreach ( self::purgeable_post_types() as $post_type ) {
			printf(
				'<label><input type="checkbox" name="%s" value="1" %s /> %s <code>%s</code></label><br />',
				esc_attr( $this->field_name( 'purge[post_types][' . $post_type->name . ']' ) ),
				checked( Options::post_type_enabled( $post_type->name ), true, false ),
				esc_html( $post_type->labels->name ?? $post_type->name ),
				esc_html( $post_type->name )
			);
		}

		printf(
			'</fieldset><p class="description">%s</p></td></tr>',
			esc_html__( 'Which post types purge anything when they change. A type switched off still purges when the API or WP-CLI asks for it.', 'classicflare' )
		);

		printf(
			'<tr><th scope="row"><label for="classicflare-custom-urls">%s</label></th><td><textarea id="classicflare-custom-urls" name="%s" rows="4" class="large-text code" placeholder="%s">%s</textarea><p class="description">%s</p></td></tr>',
			esc_html__( 'Always purge these too', 'classicflare' ),
			esc_attr( $this->field_name( 'custom_urls' ) ),
			esc_attr( "/llms.txt\n/robots.txt" ),
			esc_textarea( Options::custom_urls_raw() ),
			esc_html__( 'One per line: a path such as /llms.txt, or a full URL. Purged alongside every automatic purge. Wildcards are not accepted: Cloudflare would read the asterisk literally and match nothing.', 'classicflare' )
		);

		echo '</tbody></table>';
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function render_general( array $settings ): void {
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->checkbox(
			'admin_bar',
			__( 'Toolbar menu', 'classicflare' ),
			(bool) $settings['admin_bar'],
			__( 'Adds "Purge this page" and "Purge everything" to the toolbar for administrators.', 'classicflare' )
		);

		$this->checkbox(
			'delete_data_on_uninstall',
			__( 'Delete data on uninstall', 'classicflare' ),
			(bool) $settings['delete_data_on_uninstall'],
			__( 'Removes the settings and the stored token when the plugin is deleted. Nothing in Cloudflare is changed.', 'classicflare' )
		);

		echo '</tbody></table>';
	}

	/* --------------------------------------------------------------------- */
	/* Sanitizer                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * @param mixed $input Raw posted value.
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $input ): array {
		$input    = is_array( $input ) ? $input : [];
		$defaults = Options::defaults();

		// Only a post from one of the tabs carries "tab"; anything else is a stored value.
		if ( ! isset( $input['tab'] ) ) {
			return self::sanitize_stored( $input, $defaults );
		}

		$out   = self::sanitize_stored( Options::all(), $defaults );
		$purge = is_array( $input['purge'] ?? null ) ? $input['purge'] : [];

		switch ( (string) $input['tab'] ) {
			case 'triggers':
				$out['enabled'] = ! empty( $input['enabled'] );

				foreach ( self::TRIGGERS as $key ) {
					$out['purge'][ $key ] = ! empty( $purge[ $key ] );
				}
				break;

			case 'scope':
				foreach ( self::SCOPE as $key ) {
					$out['purge'][ $key ] = ! empty( $purge[ $key ] );
				}

				$out['purge']['pagination_depth'] = self::int_in_range( $purge['pagination_depth'] ?? 5, 0, 50, 5 );
				$out['purge']['max_urls']         = self::int_in_range( $purge['max_urls'] ?? 1000, 10, 100000, 1000 );
				$out['purge']['post_types_off']   = self::disabled_post_types_from( $purge['post_types'] ?? [] );

				self::save_custom_urls( (string) ( $input['custom_urls'] ?? '' ) );
				break;

			case 'general':
				$out['admin_bar']                = ! empty( $input['admin_bar'] );
				$out['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );
				break;
		}

		Options::flush();

		return $out;
	}

	/**
	 * @param string $raw Textarea contents.
	 */
	private static function save_custom_urls( string $raw ): void {
		$parsed = Options::sanitize_custom_urls( $raw );

		Options::save_custom_urls( $parsed['text'] );

		if ( $parsed['rejected'] ) {
			add_settings_error(
				Menu::GROUP,
				'classicflare_custom_urls_rejected',
				sprintf(
					/* translators: %s: comma separated list of the lines that were dropped. */
					__( 'Not saved: %s. A path cannot contain a wildcard.', 'classicflare' ),
					implode( ', ', $parsed['rejected'] )
				),
				'warning'
			);
		}
	}

	/**
	 * @param array<string, mixed> $input    Value being stored.
	 * @param array<string, mixed> $defaults Defaults.
	 * @return array<string, mixed>
	 */
	private static function sanitize_stored( array $input, array $defaults ): array {
		$purge = is_array( $input['purge'] ?? null ) ? $input['purge'] : [];
		$out   = [
			'enabled'                  => filter_var( $input['enabled'] ?? $defaults['enabled'], FILTER_VALIDATE_BOOLEAN ),
			'admin_bar'                => filter_var( $input['admin_bar'] ?? $defaults['admin_bar'], FILTER_VALIDATE_BOOLEAN ),
			'purge'                    => [],
			'delete_data_on_uninstall' => filter_var( $input['delete_data_on_uninstall'] ?? false, FILTER_VALIDATE_BOOLEAN ),
		];

		foreach ( $defaults['purge'] as $key => $default ) {
			$value = $purge[ $key ] ?? $default;

			if ( 'post_types_off' === $key ) {
				$out['purge'][ $key ] = is_array( $value )
					? array_values( array_map( 'sanitize_key', array_filter( $value, 'is_string' ) ) )
					: [];
			} elseif ( 'pagination_depth' === $key ) {
				$out['purge'][ $key ] = self::int_in_range( $value, 0, 50, $default );
			} elseif ( 'max_urls' === $key ) {
				$out['purge'][ $key ] = self::int_in_range( $value, 10, 100000, $default );
			} else {
				$out['purge'][ $key ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			}
		}

		Options::flush();

		return $out;
	}

	/* --------------------------------------------------------------------- */
	/* Field helpers                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * @return array<int, \WP_Post_Type>
	 */
	public static function purgeable_post_types(): array {
		$types = [];

		foreach ( get_post_types( [], 'objects' ) as $post_type ) {
			if ( 'attachment' === $post_type->name || ! is_post_type_viewable( $post_type ) ) {
				continue;
			}

			$types[] = $post_type;
		}

		return $types;
	}

	/**
	 * @param string $name        Field name inside the option array.
	 * @param string $label       Label.
	 * @param bool   $checked     State.
	 * @param string $description Optional help text.
	 */
	private function checkbox( string $name, string $label, bool $checked, string $description = '' ): void {
		$id = $this->field_id( $name );

		printf(
			'<tr><th scope="row"><label for="%s">%s</label></th><td><input type="checkbox" id="%s" name="%s" value="1" %s />%s</td></tr>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $id ),
			esc_attr( $this->field_name( $name ) ),
			checked( $checked, true, false ),
			'' === $description ? '' : '<p class="description">' . esc_html( $description ) . '</p>'
		);
	}

	/**
	 * @param string $name        Field name.
	 * @param string $label       Label.
	 * @param int    $value       Current value.
	 * @param int    $min         Minimum.
	 * @param int    $max         Maximum.
	 * @param string $description Help text.
	 */
	private function number( string $name, string $label, int $value, int $min, int $max, string $description = '' ): void {
		$id = $this->field_id( $name );

		printf(
			'<tr><th scope="row"><label for="%s">%s</label></th><td><input type="number" id="%s" name="%s" value="%s" min="%s" max="%s" class="small-text" />%s</td></tr>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $id ),
			esc_attr( $this->field_name( $name ) ),
			esc_attr( (string) $value ),
			esc_attr( (string) $min ),
			esc_attr( (string) $max ),
			'' === $description ? '' : '<p class="description">' . esc_html( $description ) . '</p>'
		);
	}

	/**
	 * @param string $name Key such as purge[feeds].
	 */
	private function field_id( string $name ): string {
		return 'classicflare-' . trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $name ) ), '-' );
	}

	/**
	 * @param string $name Key such as purge[feeds].
	 */
	private function field_name( string $name ): string {
		if ( ! str_contains( $name, '[' ) ) {
			return Options::OPTION_SETTINGS . '[' . $name . ']';
		}

		$first = strstr( $name, '[', true );
		$rest  = substr( $name, strlen( (string) $first ) );

		return Options::OPTION_SETTINGS . '[' . $first . ']' . $rest;
	}

	/**
	 * @param mixed $posted Posted post_types map, slug to "1".
	 * @return array<int, string>
	 */
	private static function disabled_post_types_from( mixed $posted ): array {
		$posted = is_array( $posted ) ? $posted : [];
		$off    = Options::disabled_post_types();

		foreach ( self::purgeable_post_types() as $post_type ) {
			$off = array_diff( $off, [ $post_type->name ] );

			if ( empty( $posted[ $post_type->name ] ) ) {
				$off[] = $post_type->name;
			}
		}

		sort( $off );

		return array_values( array_unique( $off ) );
	}

	/**
	 * @param mixed $value         Raw value.
	 * @param int   $min           Minimum.
	 * @param int   $max           Maximum.
	 * @param int   $default_value Fallback.
	 */
	private static function int_in_range( mixed $value, int $min, int $max, int $default_value ): int {
		$value = (int) $value;

		return ( $value >= $min && $value <= $max ) ? $value : $default_value;
	}
}
