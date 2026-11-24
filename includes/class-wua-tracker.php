<?php
/**
 * Carga del script de seguimiento en la parte pública.
 *
 * @package WP_User_Analyse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Encola tracker.js con su configuración.
 */
class WUA_Tracker {

	/**
	 * Engancha los hooks.
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'init', array( $this, 'consent_api_integration' ) );
		add_filter( 'show_admin_bar', array( $this, 'hide_admin_bar_in_preview' ) );
	}

	/**
	 * Indica si la página se está cargando dentro del visor de mapa de calor.
	 *
	 * @return bool
	 */
	public static function is_heatmap_preview() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lectura de un indicador.
		return isset( $_GET['wua_heatmap'] ) && current_user_can( 'manage_options' );
	}

	/**
	 * Oculta la barra de administración en el visor de mapa de calor.
	 *
	 * @param bool $show Mostrar.
	 * @return bool
	 */
	public function hide_admin_bar_in_preview( $show ) {
		return self::is_heatmap_preview() ? false : $show;
	}

	/**
	 * Modo de consentimiento efectivo.
	 *
	 * @return string consent_api | custom | none
	 */
	public static function consent_mode() {
		$mode = WUA_Settings::get( 'consent_mode' );
		if ( 'auto' === $mode ) {
			return function_exists( 'wp_has_consent' ) ? 'consent_api' : 'custom';
		}
		return $mode;
	}

	/**
	 * Declara el plugin y su cookie ante la WP Consent API, si está activa.
	 */
	public function consent_api_integration() {
		$plugin = plugin_basename( WUA_FILE );
		add_filter( "wp_consent_api_registered_{$plugin}", '__return_true' );

		if ( function_exists( 'wp_add_cookie_info' ) ) {
			wp_add_cookie_info( 'wua_vid', 'WP User Analyse', 'statistics', __( '13 meses', 'wp-user-analyse' ), __( 'Identifica de forma anónima al visitante para medir su actividad en la web.', 'wp-user-analyse' ) );
			wp_add_cookie_info( 'wua_sid', 'WP User Analyse', 'statistics', __( 'Sesión', 'wp-user-analyse' ), __( 'Agrupa las páginas vistas en una misma visita.', 'wp-user-analyse' ) );
		}
	}

	/**
	 * Encola el script.
	 */
	public function enqueue() {
		if ( self::is_heatmap_preview() || is_customize_preview() ) {
			return;
		}
		if ( WUA_Settings::is_user_excluded( get_current_user_id() ) ) {
			return;
		}

		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( WUA_Settings::is_path_excluded( $path ) ) {
			return;
		}

		$mode = self::consent_mode();
		$deps = array();
		if ( 'consent_api' === $mode && wp_script_is( 'wp-consent-api', 'registered' ) ) {
			$deps[] = 'wp-consent-api';
		}

		wp_enqueue_script( 'wua-tracker', WUA_URL . 'assets/js/tracker.js', $deps, WUA_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );

		$s = WUA_Settings::all();
		// wp_add_inline_script conserva los tipos (wp_localize_script los convierte en texto y "0" sería verdadero en JS).
		$config = array(
			'endpoint'       => esc_url_raw( rest_url( 'wua/v1/collect' ) ),
			'consentMode'    => $mode,
			'sessionTimeout' => (int) $s['session_timeout'],
			'clicks'         => (int) $s['track_clicks'],
			'selectors'      => (string) $s['click_selectors'],
			'scroll'         => (int) $s['track_scroll'],
			'heatmap'        => (int) $s['heatmap'],
			'heatmapSample'  => (int) $s['heatmap_sample'],
			'respectDnt'     => (int) $s['respect_dnt'],
			'postId'         => is_singular() ? (int) get_queried_object_id() : 0,
			'cookiePath'     => COOKIEPATH ? COOKIEPATH : '/',
			'cookieDomain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'         => is_ssl() ? 1 : 0,
		);
		wp_add_inline_script( 'wua-tracker', 'window.wuaConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}
}
