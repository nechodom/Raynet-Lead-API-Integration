<?php
/**
 * Removes plugin data on uninstall, but only when the site asked for it.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$raynet_settings = get_option( 'raynet_lead_settings', array() );

if ( empty( $raynet_settings['delete_on_uninstall'] ) ) {
	return;
}

$raynet_options = array(
	'raynet_lead_settings',
	'raynet_lead_version',
	'raynet_lead_last_error',
	'raynet_lead_migrated_v2',
	'raynet_lead_migrated_forms',
	'raynet_lead_default_form',
	'raynet_lead_elementor_templates',
	// Legacy 1.x options.
	'raynet_username',
	'raynet_api_key',
	'raynet_instance_name',
	'raynet_api_url',
	'raynet_custom_note',
);

foreach ( $raynet_options as $raynet_option ) {
	delete_option( $raynet_option );
}

delete_site_transient( 'raynet_lead_latest_release' );

global $wpdb;

// Backups of Elementor layouts taken before a template was applied.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off cleanup on uninstall.
	$wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_raynet_elementor_backup' )
);

// Forms are posts, so they take their meta with them.
$raynet_forms = get_posts(
	array(
		'post_type'        => 'raynet_form',
		'post_status'      => 'any',
		'numberposts'      => -1,
		'fields'           => 'ids',
		'suppress_filters' => true,
	)
);

foreach ( $raynet_forms as $raynet_form_id ) {
	wp_delete_post( $raynet_form_id, true );
}

// Throttle transients are per IP, so they have to be matched by prefix.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off cleanup on uninstall.
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_raynet_lead_throttle_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_raynet_lead_throttle_' ) . '%'
	)
);
