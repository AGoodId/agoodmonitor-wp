<?php
/**
 * AGoodMonitor — Uninstall
 *
 * Körs av WordPress när pluginet raderas via admin (Plugins > Ta bort).
 * Rensar alla options, transients, schemalagda cron-jobb och .htaccess
 * som pluginet skapat.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Options.
delete_option( 'agoodmonitor_api_key' );
delete_option( 'agoodmonitor_api_url' );
delete_option( 'agoodmonitor_last_report' );

// Transient för Site Health-cache.
delete_transient( 'agoodmonitor_health_issues' );

// Schemalagda cron-jobb.
wp_clear_scheduled_hook( 'agoodmonitor_send_health_report' );
wp_clear_scheduled_hook( 'agoodmonitor_cleanup_link_log' );

// Link monitor — DB-version option och logg-tabell.
delete_option( 'agoodmonitor_link_monitor_db_version' );
global $wpdb;
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'agoodmonitor_link_log' );

// .htaccess i uploads — ta bara bort filen om vi skapade den.
$upload_dir = wp_upload_dir();
$htaccess   = trailingslashit( $upload_dir['basedir'] ) . '.htaccess';

if ( file_exists( $htaccess ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$content = file_get_contents( $htaccess );
	if ( false !== $content && strpos( $content, 'AGoodMonitor' ) !== false ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $htaccess );
	}
}
