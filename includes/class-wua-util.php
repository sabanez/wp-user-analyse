<?php
/**
 * Utilidades comunes.
 *
 * @package WP_User_Analyse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Funciones auxiliares sin estado.
 */
class WUA_Util {

	/**
	 * Fecha y hora actual en UTC (formato MySQL). Todo se guarda en UTC.
	 *
	 * @return string
	 */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Fecha UTC de un evento a partir de la marca de tiempo del navegador.
	 * Conserva el orden real de los eventos de un lote; si el reloj del
	 * navegador se desvía más de 10 minutos, usa la hora del servidor.
	 *
	 * @param mixed $ts Segundos Unix enviados por el navegador.
	 * @return string
	 */
	public static function event_time( $ts ) {
		$ts  = (int) $ts;
		$now = time();
		if ( $ts <= 0 || abs( $now - $ts ) > 600 ) {
			$ts = $now;
		}
		return gmdate( 'Y-m-d H:i:s', min( $ts, $now ) );
	}

	/**
	 * Valida un UUID v4 generado por el navegador.
	 *
	 * @param mixed $value Valor.
	 * @return bool
	 */
	public static function is_uuid( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value );
	}

	/**
	 * Tipo de dispositivo según el ancho de ventana.
	 *
	 * @param int $vw Ancho en píxeles.
	 * @return string desktop | tablet | mobile | ''
	 */
	public static function device_from_width( $vw ) {
		$vw = (int) $vw;
		if ( $vw <= 0 ) {
			return '';
		}
		if ( $vw < 768 ) {
			return 'mobile';
		}
		if ( $vw < 1100 ) {
			return 'tablet';
		}
		return 'desktop';
	}

	/**
	 * Normaliza una ruta de página: solo el path, sin dominio ni parámetros.
	 *
	 * @param mixed $path Ruta.
	 * @return string
	 */
	public static function clean_path( $path ) {
		$path = (string) wp_parse_url( (string) $path, PHP_URL_PATH );
		$path = sanitize_text_field( rawurldecode( $path ) );
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		return mb_substr( $path, 0, 255 );
	}

	/**
	 * Texto corto y saneado.
	 *
	 * @param mixed $value Valor.
	 * @param int   $max   Longitud máxima.
	 * @return string
	 */
	public static function text( $value, $max ) {
		return mb_substr( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ), 0, $max );
	}

	/**
	 * URL saneada y recortada.
	 *
	 * @param mixed $value Valor.
	 * @return string
	 */
	public static function url( $value ) {
		return mb_substr( esc_url_raw( is_scalar( $value ) ? (string) $value : '' ), 0, 255 );
	}

	/**
	 * IP del cliente. Por defecto solo REMOTE_ADDR, que no se puede falsificar.
	 * Detrás de un proxy o CDN, usa el filtro wua_client_ip.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return (string) apply_filters( 'wua_client_ip', $ip );
	}

	/**
	 * Usuario conectado según su cookie de sesión de WordPress.
	 *
	 * La API REST pone el usuario a 0 si la petición no lleva nonce, y sendBeacon
	 * no puede enviarlo. Además los nonces caducan en páginas cacheadas. Por eso se
	 * valida directamente la cookie logged_in.
	 *
	 * @return int
	 */
	public static function user_from_cookie() {
		$id = wp_validate_auth_cookie( '', 'logged_in' );
		return $id ? (int) $id : 0;
	}

	/**
	 * Desfase en segundos de la zona horaria del sitio, para agrupar por día local.
	 *
	 * @return int
	 */
	public static function tz_offset() {
		return (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
	}
}
