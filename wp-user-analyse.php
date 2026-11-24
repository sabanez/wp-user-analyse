<?php
/**
 * Plugin Name:       WP User Analyse
 * Description:       Registra la actividad web de visitantes y usuarios registrados: altas, accesos, páginas vistas, sesiones, tiempo activo, clics y mapa de calor.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Labolife
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-user-analyse
 * Domain Path:       /languages
 *
 * @package WP_User_Analyse
 */

defined( 'ABSPATH' ) || exit;

define( 'WUA_VERSION', '0.1.0' );
define( 'WUA_DB_VERSION', '1' );
define( 'WUA_FILE', __FILE__ );
define( 'WUA_DIR', plugin_dir_path( __FILE__ ) );
define( 'WUA_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'WUA_' ) ) {
			return;
		}
		$file = 'class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
		foreach ( array( 'includes', 'admin' ) as $dir ) {
			$path = WUA_DIR . $dir . '/' . $file;
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

register_activation_hook( __FILE__, array( 'WUA_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WUA_Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'WUA_Plugin', 'instance' ) );
