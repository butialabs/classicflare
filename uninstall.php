<?php
/**
 * @package ClassicFlare
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

function classicflare_uninstall_site(): void {
	global $wpdb;

	foreach ( [ 'classicflare_purge', 'classicflare_sitemaps', 'classicflare_sitemaps_now' ] as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	$settings = get_option( 'classicflare_settings', [] );

	if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	$options = [
		'classicflare_settings',
		'classicflare_secrets',
		'classicflare_cf_state',
		'classicflare_custom_urls',
		'classicflare_sitemap_index',
		'classicflare_last_purge',
	];

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'classicflare_lock_' ) . '%',
			$wpdb->esc_like( '_transient_classicflare_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_classicflare_' ) . '%'
		)
	);
}

function classicflare_uninstall(): void {
	if ( ! is_multisite() ) {
		classicflare_uninstall_site();

		return;
	}

	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) as $site_id ) {
		switch_to_blog( (int) $site_id );
		classicflare_uninstall_site();
		restore_current_blog();
	}
}

classicflare_uninstall();
