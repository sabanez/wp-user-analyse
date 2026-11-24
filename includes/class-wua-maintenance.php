<?php
/**
 * Tareas programadas: purga de datos antiguos.
 *
 * @package WP_User_Analyse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Purga diaria según los plazos de retención.
 */
class WUA_Maintenance {

	const HOOK = 'wua_daily_maintenance';

	/**
	 * Engancha los hooks.
	 */
	public function register() {
		add_action( self::HOOK, array( __CLASS__, 'purge' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule();
		}
	}

	/**
	 * Programa la tarea diaria a las 3:00 (hora del servidor, aprox.).
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:00' ), 'daily', self::HOOK );
		}
	}

	/**
	 * Elimina la tarea.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Borra los datos que superan su plazo de retención, en bloques para no
	 * bloquear las tablas.
	 */
	public static function purge() {
		global $wpdb;
		$t = WUA_Installer::tables();

		$cut = static function ( $days ) {
			return gmdate( 'Y-m-d H:i:s', time() - ( (int) $days * DAY_IN_SECONDS ) );
		};

		$jobs = array(
			array( $t['heatmap'], 'created_at', $cut( WUA_Settings::get( 'heatmap_retention' ) ) ),
			array( $t['events'], 'created_at', $cut( WUA_Settings::get( 'events_retention' ) ) ),
			array( $t['pageviews'], 'viewed_at', $cut( WUA_Settings::get( 'retention_days' ) ) ),
			array( $t['sessions'], 'started_at', $cut( WUA_Settings::get( 'retention_days' ) ) ),
		);

		foreach ( $jobs as $job ) {
			list( $table, $column, $before ) = $job;
			do {
				$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$column} < %s LIMIT 5000", $before ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} while ( $deleted >= 5000 );
		}

		// Visitantes anónimos sin actividad dentro del plazo.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['visitors']} WHERE user_id IS NULL AND last_seen < %s", $cut( WUA_Settings::get( 'retention_days' ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
