<?php
/**
 * Creación y actualización de tablas.
 *
 * @package WP_User_Analyse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Instalación, actualización y desactivación.
 */
class WUA_Installer {

	/**
	 * Al activar el plugin.
	 */
	public static function activate() {
		self::create_tables();
		update_option( 'wua_db_version', WUA_DB_VERSION, false );
		if ( false === get_option( WUA_Settings::OPTION ) ) {
			add_option( WUA_Settings::OPTION, WUA_Settings::defaults(), '', false );
		}
		WUA_Maintenance::schedule();
	}

	/**
	 * Al desactivar el plugin. Los datos se conservan; se borran en uninstall.php.
	 */
	public static function deactivate() {
		WUA_Maintenance::unschedule();
	}

	/**
	 * Actualiza el esquema si cambió la versión.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'wua_db_version' ) !== WUA_DB_VERSION ) {
			self::activate();
		}
	}

	/**
	 * Nombres de tabla con prefijo.
	 *
	 * @return array<string,string>
	 */
	public static function tables() {
		global $wpdb;
		return array(
			'visitors'  => $wpdb->prefix . 'wua_visitors',
			'sessions'  => $wpdb->prefix . 'wua_sessions',
			'pageviews' => $wpdb->prefix . 'wua_pageviews',
			'events'    => $wpdb->prefix . 'wua_events',
			'heatmap'   => $wpdb->prefix . 'wua_heatmap',
		);
	}

	/**
	 * Devuelve el nombre de una tabla.
	 *
	 * @param string $key Clave.
	 * @return string
	 */
	public static function table( $key ) {
		$t = self::tables();
		return $t[ $key ];
	}

	/**
	 * Crea o actualiza las tablas con dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$c = $wpdb->get_charset_collate();
		$t = self::tables();

		$sql = array();

		$sql[] = "CREATE TABLE {$t['visitors']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  vid char(36) NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  first_referrer varchar(255) NOT NULL DEFAULT '',
  first_landing varchar(255) NOT NULL DEFAULT '',
  first_source varchar(100) NOT NULL DEFAULT '',
  first_medium varchar(100) NOT NULL DEFAULT '',
  first_campaign varchar(150) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY vid (vid),
  KEY user_id (user_id),
  KEY last_seen (last_seen)
) $c;";

		$sql[] = "CREATE TABLE {$t['sessions']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sid char(36) NOT NULL,
  visitor_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  started_at datetime NOT NULL,
  last_activity_at datetime NOT NULL,
  active_seconds int(10) unsigned NOT NULL DEFAULT 0,
  pageviews int(10) unsigned NOT NULL DEFAULT 0,
  clicks int(10) unsigned NOT NULL DEFAULT 0,
  entry_url varchar(255) NOT NULL DEFAULT '',
  exit_url varchar(255) NOT NULL DEFAULT '',
  referrer varchar(255) NOT NULL DEFAULT '',
  utm_source varchar(100) NOT NULL DEFAULT '',
  utm_medium varchar(100) NOT NULL DEFAULT '',
  utm_campaign varchar(150) NOT NULL DEFAULT '',
  device varchar(10) NOT NULL DEFAULT '',
  synced_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY sid (sid),
  KEY visitor_id (visitor_id),
  KEY user_id (user_id),
  KEY started_at (started_at)
) $c;";

		$sql[] = "CREATE TABLE {$t['pageviews']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  pvid char(36) NOT NULL,
  session_id bigint(20) unsigned NOT NULL,
  visitor_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  url varchar(255) NOT NULL,
  title varchar(255) NOT NULL DEFAULT '',
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  viewed_at datetime NOT NULL,
  active_seconds int(10) unsigned NOT NULL DEFAULT 0,
  max_scroll tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY pvid (pvid),
  KEY session_id (session_id),
  KEY user_id (user_id),
  KEY viewed_at (viewed_at),
  KEY url (url(191))
) $c;";

		$sql[] = "CREATE TABLE {$t['events']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(20) NOT NULL,
  session_id bigint(20) unsigned DEFAULT NULL,
  pageview_id bigint(20) unsigned DEFAULT NULL,
  visitor_id bigint(20) unsigned DEFAULT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  label varchar(191) NOT NULL DEFAULT '',
  selector varchar(255) NOT NULL DEFAULT '',
  target_url varchar(255) NOT NULL DEFAULT '',
  page_url varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY type_created (type,created_at),
  KEY user_id (user_id),
  KEY session_id (session_id)
) $c;";

		$sql[] = "CREATE TABLE {$t['heatmap']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url varchar(255) NOT NULL,
  device varchar(10) NOT NULL,
  selector varchar(255) NOT NULL DEFAULT '',
  rx smallint(5) unsigned NOT NULL DEFAULT 0,
  ry smallint(5) unsigned NOT NULL DEFAULT 0,
  x int(10) unsigned NOT NULL DEFAULT 0,
  y int(10) unsigned NOT NULL DEFAULT 0,
  vw smallint(5) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY page (url(191),device,created_at),
  KEY created_at (created_at)
) $c;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}
