<?php
/**
 * Actualizaciones automáticas desde las releases de GitHub.
 *
 * @package WP_User_Analyse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Conecta el plugin con Plugin Update Checker (librería MIT incluida en lib/).
 *
 * WordPress muestra "Hay una nueva versión" en Plugins cuando se publica una
 * release en GitHub con una versión mayor que la instalada. Solo se usan
 * releases publicadas, nunca commits sueltos de main.
 *
 * Constantes opcionales en wp-config.php:
 * - WUA_UPDATE_REPO:  URL del repositorio (por defecto el oficial).
 * - WUA_GITHUB_TOKEN: token de solo lectura, necesario si el repositorio es privado.
 * - WUA_DISABLE_UPDATES: true para desactivar la comprobación.
 */
class WUA_Updater {

	const DEFAULT_REPO = 'https://github.com/sabanez/wp-user-analyse/';

	/**
	 * Engancha el comprobador de actualizaciones.
	 */
	public function register() {
		if ( defined( 'WUA_DISABLE_UPDATES' ) && WUA_DISABLE_UPDATES ) {
			return;
		}
		$loader = WUA_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
		if ( ! is_readable( $loader ) ) {
			return;
		}
		require_once $loader;

		if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			return;
		}

		$repo    = defined( 'WUA_UPDATE_REPO' ) ? WUA_UPDATE_REPO : self::DEFAULT_REPO;
		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker( $repo, WUA_FILE, 'wp-user-analyse' );

		// Descarga el zip adjunto a la release (generado por la GitHub Action),
		// que ya trae la carpeta con el nombre correcto y sin archivos de desarrollo.
		$api = $checker->getVcsApi();
		if ( $api && method_exists( $api, 'enableReleaseAssets' ) ) {
			$api->enableReleaseAssets( '/wp-user-analyse\.zip$/i' );
		}

		if ( defined( 'WUA_GITHUB_TOKEN' ) && WUA_GITHUB_TOKEN ) {
			$checker->setAuthentication( WUA_GITHUB_TOKEN );
		}
	}
}
