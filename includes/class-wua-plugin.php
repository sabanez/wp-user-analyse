<?php
/**
 * Arranque del plugin.
 *
 * @package WP_User_Analyse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Conecta todos los componentes con WordPress.
 */
final class WUA_Plugin {

	/**
	 * Instancia única.
	 *
	 * @var WUA_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Devuelve la instancia única.
	 *
	 * @return WUA_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		load_plugin_textdomain( 'wp-user-analyse', false, dirname( plugin_basename( WUA_FILE ) ) . '/languages' );

		WUA_Installer::maybe_upgrade();

		( new WUA_Tracker() )->register();
		( new WUA_Rest() )->register();
		( new WUA_User_Events() )->register();
		( new WUA_Maintenance() )->register();
		( new WUA_Privacy() )->register();
		( new WUA_Updater() )->register();

		if ( is_admin() ) {
			( new WUA_Admin() )->register();
		}
	}
}
