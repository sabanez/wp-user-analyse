<?php
/**
 * Desinstalación: borra tablas y ajustes.
 *
 * Para conservar los datos al desinstalar, define WUA_KEEP_DATA en wp-config.php.
 *
 * @package WP_User_Analyse
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'WUA_KEEP_DATA' ) && WUA_KEEP_DATA ) {
	return;
}

global $wpdb;
foreach ( array( 'wua_visitors', 'wua_sessions', 'wua_pageviews', 'wua_events', 'wua_heatmap' ) as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}
delete_option( 'wua_settings' );
delete_option( 'wua_db_version' );
wp_clear_scheduled_hook( 'wua_daily_maintenance' );
