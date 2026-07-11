<?php
/**
 * Runs ONLY on real uninstall (plugin deleted from WordPress), never on
 * deactivation. Removes the plugin's option and any leftover transients.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'hrd_sms_auth_options' );
delete_option( '_hrd_needs_rewrite_flush' );
delete_option( 'hrd_sms_auth_installed_at' );

// Clean up orphaned options from the removed telemetry feature.
delete_option( 'hrd-sms-auth_telemetry_version' );
delete_option( 'hrd-sms-auth_telemetry_activated' );
wp_clear_scheduled_hook( 'hrd_usage_tracker_dispatch' );

global $wpdb;

// Remove all plugin transients (value + timeout rows) in one pass.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_hrd\_sms\_%'
	    OR option_name LIKE '\_transient\_timeout\_hrd\_sms\_%'"
);
