<?php
/**
 * Ajustes del plugin (una opción serializada por sitio).
 *
 * @package WP_User_Analyse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lectura, valores por defecto y saneado de ajustes.
 *
 * Cualquier ajuste puede fijarse desde wp-config.php con una constante
 * WUA_<CLAVE_EN_MAYÚSCULAS>, por ejemplo define( 'WUA_SITE_ID', 'labolife-es' ).
 * Así la misma configuración se replica en varias webs sin tocar el panel.
 */
class WUA_Settings {

	const OPTION = 'wua_settings';

	const DEFAULT_CLICK_SELECTORS = 'a, button, input[type=submit], input[type=button], [role=button], [data-wua-track]';

	/**
	 * Caché de la petición.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Valores por defecto.
	 *
	 * @return array
	 */
	public static function defaults() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return array(
			'site_id'          => sanitize_title( (string) $host ),
			'entity'           => '',
			'consent_mode'     => 'auto',
			'track_clicks'     => 1,
			'click_selectors'  => self::DEFAULT_CLICK_SELECTORS,
			'track_scroll'     => 1,
			'heatmap'          => 1,
			'heatmap_sample'   => 100,
			'excluded_roles'   => array( 'administrator' ),
			'excluded_ips'     => '',
			'excluded_paths'   => '',
			'respect_dnt'      => 0,
			'session_timeout'  => 30,
			'retention_days'   => 395,
			'events_retention' => 180,
			'heatmap_retention' => 90,
		);
	}

	/**
	 * Todos los ajustes, con constantes de wp-config.php aplicadas.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			$values = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
			foreach ( array_keys( $values ) as $key ) {
				$const = 'WUA_' . strtoupper( $key );
				if ( defined( $const ) ) {
					$values[ $key ] = constant( $const );
				}
			}
			self::$cache = $values;
		}
		return self::$cache;
	}

	/**
	 * Un ajuste.
	 *
	 * @param string $key Clave.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Indica si un ajuste está fijado por constante.
	 *
	 * @param string $key Clave.
	 * @return bool
	 */
	public static function is_locked( $key ) {
		return defined( 'WUA_' . strtoupper( $key ) );
	}

	/**
	 * Vacía la caché (tras guardar).
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Sanea los ajustes enviados desde el formulario.
	 *
	 * @param mixed $input Datos del formulario.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$d     = self::defaults();
		$out   = array();

		$out['site_id']      = sanitize_title( isset( $input['site_id'] ) ? $input['site_id'] : $d['site_id'] );
		$out['entity']       = sanitize_text_field( isset( $input['entity'] ) ? $input['entity'] : '' );
		$mode                = isset( $input['consent_mode'] ) ? $input['consent_mode'] : 'auto';
		$out['consent_mode'] = in_array( $mode, array( 'auto', 'consent_api', 'custom', 'none' ), true ) ? $mode : 'auto';

		foreach ( array( 'track_clicks', 'track_scroll', 'heatmap', 'respect_dnt' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$selectors              = isset( $input['click_selectors'] ) ? wp_strip_all_tags( $input['click_selectors'] ) : '';
		$out['click_selectors'] = '' === trim( $selectors ) ? self::DEFAULT_CLICK_SELECTORS : trim( $selectors );

		$out['heatmap_sample'] = self::clamp( $input, 'heatmap_sample', 1, 100, $d['heatmap_sample'] );

		$roles                 = isset( $input['excluded_roles'] ) && is_array( $input['excluded_roles'] ) ? $input['excluded_roles'] : array();
		$out['excluded_roles'] = array_values( array_map( 'sanitize_key', $roles ) );

		$out['excluded_ips']   = self::clean_lines( isset( $input['excluded_ips'] ) ? $input['excluded_ips'] : '' );
		$out['excluded_paths'] = self::clean_lines( isset( $input['excluded_paths'] ) ? $input['excluded_paths'] : '' );

		$out['session_timeout']   = self::clamp( $input, 'session_timeout', 5, 240, $d['session_timeout'] );
		$out['retention_days']    = self::clamp( $input, 'retention_days', 30, 3650, $d['retention_days'] );
		$out['events_retention']  = self::clamp( $input, 'events_retention', 7, 3650, $d['events_retention'] );
		$out['heatmap_retention'] = self::clamp( $input, 'heatmap_retention', 7, 3650, $d['heatmap_retention'] );

		self::flush();
		return $out;
	}

	/**
	 * Entero acotado.
	 *
	 * @param array  $input   Datos.
	 * @param string $key     Clave.
	 * @param int    $min     Mínimo.
	 * @param int    $max     Máximo.
	 * @param int    $default Por defecto.
	 * @return int
	 */
	private static function clamp( $input, $key, $min, $max, $default ) {
		if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
			return $default;
		}
		return max( $min, min( $max, (int) $input[ $key ] ) );
	}

	/**
	 * Limpia una lista de una entrada por línea.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	private static function clean_lines( $text ) {
		$lines = preg_split( '/[\r\n,]+/', (string) $text );
		$lines = array_filter( array_map( 'trim', array_map( 'sanitize_text_field', $lines ) ) );
		return implode( "\n", array_unique( $lines ) );
	}

	/**
	 * Lista (array) a partir de un ajuste multilínea.
	 *
	 * @param string $key Clave.
	 * @return string[]
	 */
	public static function lines( $key ) {
		$value = self::get( $key );
		if ( is_array( $value ) ) {
			return $value;
		}
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', (string) $value ) ) ) );
	}

	/**
	 * Indica si un usuario está excluido del seguimiento por su rol.
	 *
	 * @param int $user_id Usuario.
	 * @return bool
	 */
	public static function is_user_excluded( $user_id ) {
		if ( ! $user_id ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		$excluded = (array) self::get( 'excluded_roles' );
		return (bool) array_intersect( $excluded, (array) $user->roles );
	}

	/**
	 * Indica si una IP está excluida (admite prefijos tipo "192.168.").
	 *
	 * @param string $ip IP.
	 * @return bool
	 */
	public static function is_ip_excluded( $ip ) {
		foreach ( self::lines( 'excluded_ips' ) as $rule ) {
			if ( $rule === $ip || ( '.' === substr( $rule, -1 ) && 0 === strpos( $ip, $rule ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Indica si una ruta está excluida (por prefijo).
	 *
	 * @param string $path Ruta.
	 * @return bool
	 */
	public static function is_path_excluded( $path ) {
		foreach ( self::lines( 'excluded_paths' ) as $prefix ) {
			if ( '' !== $prefix && 0 === strpos( $path, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
