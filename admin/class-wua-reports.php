<?php
/**
 * Consultas de los informes del panel.
 *
 * @package WP_User_Analyse
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

/**
 * Informes sobre un rango de fechas en la zona horaria del sitio.
 */
class WUA_Reports {

	/**
	 * Inicio del rango (UTC, incluido).
	 *
	 * @var string
	 */
	public $from_utc;

	/**
	 * Fin del rango (UTC, excluido).
	 *
	 * @var string
	 */
	public $to_utc;

	/**
	 * Primer día (local, Y-m-d).
	 *
	 * @var string
	 */
	public $from;

	/**
	 * Último día (local, Y-m-d).
	 *
	 * @var string
	 */
	public $to;

	/**
	 * Desfase horario en segundos.
	 *
	 * @var int
	 */
	private $offset;

	/**
	 * Tablas.
	 *
	 * @var array
	 */
	private $t;

	/**
	 * Constructor.
	 *
	 * @param string $from Día inicial local (Y-m-d).
	 * @param string $to   Día final local (Y-m-d), incluido.
	 */
	public function __construct( $from, $to ) {
		$this->offset   = WUA_Util::tz_offset();
		$this->from     = $from;
		$this->to       = $to;
		$this->from_utc = gmdate( 'Y-m-d H:i:s', strtotime( $from . ' 00:00:00 UTC' ) - $this->offset );
		$this->to_utc   = gmdate( 'Y-m-d H:i:s', strtotime( $to . ' 00:00:00 UTC' ) + DAY_IN_SECONDS - $this->offset );
		$this->t        = WUA_Installer::tables();
	}

	/**
	 * Construye el rango desde la petición (from/to o days).
	 *
	 * @return WUA_Reports
	 */
	public static function from_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filtros de solo lectura.
		$today = wp_date( 'Y-m-d' );
		$from  = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to    = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		$days  = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 0;
		// phpcs:enable

		$valid = static function ( $d ) {
			return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) && false !== strtotime( $d );
		};
		if ( $days || ! $valid( $from ) || ! $valid( $to ) ) {
			$days = $days ? min( $days, 3650 ) : 30;
			$to   = $today;
			$from = gmdate( 'Y-m-d', strtotime( $today . ' UTC' ) - ( $days - 1 ) * DAY_IN_SECONDS );
		}
		if ( $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}
		return new self( $from, $to );
	}

	/**
	 * Expresión SQL del día local de una columna UTC.
	 *
	 * @param string $col Columna.
	 * @return string
	 */
	private function day( $col ) {
		return sprintf( 'DATE(DATE_ADD(%s, INTERVAL %d SECOND))', $col, $this->offset );
	}

	/**
	 * Condición de tipo de usuario.
	 *
	 * @param string $type all | anon | registered.
	 * @param string $col  Columna user_id.
	 * @return string
	 */
	private function user_type_sql( $type, $col = 'user_id' ) {
		if ( 'anon' === $type ) {
			return " AND {$col} IS NULL";
		}
		if ( 'registered' === $type ) {
			return " AND {$col} IS NOT NULL";
		}
		return '';
	}

	/**
	 * Cifras clave del periodo.
	 *
	 * @return array
	 */
	public function summary() {
		global $wpdb;
		$s   = $this->t['sessions'];
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS sessions,
					COUNT(DISTINCT visitor_id) AS visitors,
					SUM(user_id IS NOT NULL) AS registered_sessions,
					COUNT(DISTINCT user_id) AS active_users,
					COALESCE(SUM(pageviews), 0) AS pageviews,
					COALESCE(SUM(clicks), 0) AS clicks,
					COALESCE(AVG(active_seconds), 0) AS avg_seconds,
					COALESCE(SUM(active_seconds), 0) AS total_seconds
				FROM {$s} WHERE started_at >= %s AND started_at < %s",
				$this->from_utc,
				$this->to_utc
			),
			ARRAY_A
		);

		$row['signups'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered >= %s AND user_registered < %s", $this->from_utc, $this->to_utc ) );
		$row['logins']  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->t['events']} WHERE type = 'login' AND created_at >= %s AND created_at < %s", $this->from_utc, $this->to_utc ) );

		$row['pages_per_session'] = $row['sessions'] ? round( $row['pageviews'] / $row['sessions'], 1 ) : 0;
		return array_map( 'floatval', $row );
	}

	/**
	 * Serie diaria: sesiones por tipo, altas, accesos y tiempo activo.
	 *
	 * @return array Lista de días con sus valores (incluye días sin datos).
	 */
	public function daily() {
		global $wpdb;
		$days = array();
		for ( $d = strtotime( $this->from . ' UTC' ); $d <= strtotime( $this->to . ' UTC' ); $d += DAY_IN_SECONDS ) {
			$key          = gmdate( 'Y-m-d', $d );
			$days[ $key ] = array(
				'day'        => $key,
				'anon'       => 0,
				'registered' => 0,
				'pageviews'  => 0,
				'minutes'    => 0,
				'signups'    => 0,
				'logins'     => 0,
			);
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$this->day('started_at')} AS d, SUM(user_id IS NULL) AS anon, SUM(user_id IS NOT NULL) AS registered,
					SUM(pageviews) AS pageviews, ROUND(SUM(active_seconds) / 60) AS minutes
				FROM {$this->t['sessions']} WHERE started_at >= %s AND started_at < %s GROUP BY d",
				$this->from_utc,
				$this->to_utc
			),
			ARRAY_A
		);
		foreach ( $rows as $r ) {
			if ( isset( $days[ $r['d'] ] ) ) {
				$days[ $r['d'] ] = array_merge( $days[ $r['d'] ], array_map( 'intval', array_diff_key( $r, array( 'd' => 1 ) ) ) );
			}
		}

		$signups = $wpdb->get_results( $wpdb->prepare( "SELECT {$this->day('user_registered')} AS d, COUNT(*) AS n FROM {$wpdb->users} WHERE user_registered >= %s AND user_registered < %s GROUP BY d", $this->from_utc, $this->to_utc ), ARRAY_A );
		foreach ( $signups as $r ) {
			if ( isset( $days[ $r['d'] ] ) ) {
				$days[ $r['d'] ]['signups'] = (int) $r['n'];
			}
		}

		$logins = $wpdb->get_results( $wpdb->prepare( "SELECT {$this->day('created_at')} AS d, COUNT(*) AS n FROM {$this->t['events']} WHERE type = 'login' AND created_at >= %s AND created_at < %s GROUP BY d", $this->from_utc, $this->to_utc ), ARRAY_A );
		foreach ( $logins as $r ) {
			if ( isset( $days[ $r['d'] ] ) ) {
				$days[ $r['d'] ]['logins'] = (int) $r['n'];
			}
		}

		return array_values( $days );
	}

	/**
	 * Páginas más vistas.
	 *
	 * @param string $user_type all | anon | registered.
	 * @param int    $limit     Límite.
	 * @param int    $offset    Desplazamiento.
	 * @return array
	 */
	public function top_pages( $user_type = 'all', $limit = 50, $offset = 0 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT url, MAX(title) AS title, COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS visitors,
					COUNT(DISTINCT user_id) AS users, ROUND(AVG(active_seconds)) AS avg_seconds, ROUND(AVG(max_scroll)) AS avg_scroll
				FROM {$this->t['pageviews']} WHERE viewed_at >= %s AND viewed_at < %s" . $this->user_type_sql( $user_type ) . '
				GROUP BY url ORDER BY views DESC LIMIT %d OFFSET %d',
				$this->from_utc,
				$this->to_utc,
				$limit,
				$offset
			),
			ARRAY_A
		);
	}

	/**
	 * Elementos más pulsados.
	 *
	 * @param string $user_type all | anon | registered.
	 * @param int    $limit     Límite.
	 * @param int    $offset    Desplazamiento.
	 * @return array
	 */
	public function top_clicks( $user_type = 'all', $limit = 50, $offset = 0 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT label, page_url, MAX(target_url) AS target_url, MAX(selector) AS selector, COUNT(*) AS clicks,
					COUNT(DISTINCT visitor_id) AS visitors, COUNT(DISTINCT user_id) AS users
				FROM {$this->t['events']} WHERE type IN ('click','custom') AND created_at >= %s AND created_at < %s" . $this->user_type_sql( $user_type ) . '
				GROUP BY label, page_url ORDER BY clicks DESC LIMIT %d OFFSET %d',
				$this->from_utc,
				$this->to_utc,
				$limit,
				$offset
			),
			ARRAY_A
		);
	}

	/**
	 * Usuarios registrados con actividad en el periodo.
	 *
	 * @param string $search Búsqueda por nombre o email.
	 * @param int    $limit  Límite.
	 * @param int    $offset Desplazamiento.
	 * @return array
	 */
	public function users( $search = '', $limit = 50, $offset = 0 ) {
		global $wpdb;
		$where = '';
		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$where = $wpdb->prepare( ' AND (u.user_email LIKE %s OR u.display_name LIKE %s OR u.user_login LIKE %s)', $like, $like, $like );
		}
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.user_id, u.display_name, u.user_email, u.user_registered,
					COUNT(*) AS sessions, SUM(s.active_seconds) AS seconds, SUM(s.pageviews) AS pageviews, SUM(s.clicks) AS clicks,
					MAX(s.last_activity_at) AS last_activity,
					(SELECT MAX(e.created_at) FROM {$this->t['events']} e WHERE e.user_id = s.user_id AND e.type = 'login') AS last_login,
					(SELECT COUNT(*) FROM {$this->t['events']} e WHERE e.user_id = s.user_id AND e.type = 'login' AND e.created_at >= %s AND e.created_at < %s) AS logins
				FROM {$this->t['sessions']} s INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
				WHERE s.started_at >= %s AND s.started_at < %s {$where}
				GROUP BY s.user_id ORDER BY last_activity DESC LIMIT %d OFFSET %d",
				$this->from_utc,
				$this->to_utc,
				$this->from_utc,
				$this->to_utc,
				$limit,
				$offset
			),
			ARRAY_A
		);
	}

	/**
	 * Altas del periodo, con su origen y actividad previa al alta.
	 *
	 * @param int $limit  Límite.
	 * @param int $offset Desplazamiento.
	 * @return array
	 */
	public function signups( $limit = 50, $offset = 0 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID AS user_id, u.display_name, u.user_email, u.user_registered,
					v.first_source, v.first_medium, v.first_campaign, v.first_referrer, v.first_landing,
					(SELECT COUNT(*) FROM {$this->t['sessions']} s WHERE s.user_id = u.ID AND s.started_at < u.user_registered) AS sessions_before,
					(SELECT COUNT(*) FROM {$this->t['sessions']} s WHERE s.user_id = u.ID) AS sessions_total
				FROM {$wpdb->users} u
				LEFT JOIN (SELECT user_id, MIN(id) AS vid_id FROM {$this->t['visitors']} WHERE user_id IS NOT NULL GROUP BY user_id) fv ON fv.user_id = u.ID
				LEFT JOIN {$this->t['visitors']} v ON v.id = fv.vid_id
				WHERE u.user_registered >= %s AND u.user_registered < %s
				ORDER BY u.user_registered DESC LIMIT %d OFFSET %d",
				$this->from_utc,
				$this->to_utc,
				$limit,
				$offset
			),
			ARRAY_A
		);
	}

	/**
	 * Sesiones del periodo.
	 *
	 * @param string $user_type all | anon | registered.
	 * @param int    $user_id   Filtrar por usuario.
	 * @param int    $limit     Límite.
	 * @param int    $offset    Desplazamiento.
	 * @return array
	 */
	public function sessions( $user_type = 'all', $user_id = 0, $limit = 50, $offset = 0 ) {
		global $wpdb;
		$extra = $this->user_type_sql( $user_type, 's.user_id' );
		if ( $user_id ) {
			$extra .= $wpdb->prepare( ' AND s.user_id = %d', $user_id );
		}
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, u.display_name, u.user_email FROM {$this->t['sessions']} s
				LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id
				WHERE s.started_at >= %s AND s.started_at < %s {$extra}
				ORDER BY s.started_at DESC LIMIT %d OFFSET %d",
				$this->from_utc,
				$this->to_utc,
				$limit,
				$offset
			),
			ARRAY_A
		);
	}

	/**
	 * Recorrido de una sesión: páginas y eventos en orden.
	 *
	 * @param int $session_id Sesión.
	 * @return array{session: array|null, pages: array, events: array}
	 */
	public static function session_detail( $session_id ) {
		global $wpdb;
		$t       = WUA_Installer::tables();
		$session = $wpdb->get_row( $wpdb->prepare( "SELECT s.*, u.display_name, u.user_email FROM {$t['sessions']} s LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id WHERE s.id = %d", $session_id ), ARRAY_A );
		return array(
			'session' => $session,
			'pages'   => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['pageviews']} WHERE session_id = %d ORDER BY id", $session_id ), ARRAY_A ),
			'events'  => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['events']} WHERE session_id = %d ORDER BY id", $session_id ), ARRAY_A ),
		);
	}

	/**
	 * Páginas con puntos de mapa de calor.
	 *
	 * @param string $device Dispositivo.
	 * @return array
	 */
	public function heatmap_pages( $device ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT url, COUNT(*) AS points FROM {$this->t['heatmap']} WHERE device = %s AND created_at >= %s AND created_at < %s GROUP BY url ORDER BY points DESC LIMIT 200", $device, $this->from_utc, $this->to_utc ), ARRAY_A );
	}

	/**
	 * Puntos de una página.
	 *
	 * @param string $url    Ruta.
	 * @param string $device Dispositivo.
	 * @param int    $limit  Máximo de puntos (los más recientes).
	 * @return array
	 */
	public function heatmap_points( $url, $device, $limit = 20000 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT selector AS s, rx, ry, x, y, vw FROM {$this->t['heatmap']} WHERE url = %s AND device = %s AND created_at >= %s AND created_at < %s ORDER BY id DESC LIMIT %d", $url, $device, $this->from_utc, $this->to_utc, $limit ), ARRAY_A );
	}
}
