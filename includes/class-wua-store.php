<?php
/**
 * Escritura en las tablas del plugin.
 *
 * @package WP_User_Analyse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Operaciones de escritura. Todas las fechas en UTC.
 */
class WUA_Store {

	/**
	 * Crea o actualiza un visitante y devuelve su fila.
	 *
	 * @param string $vid     UUID del visitante.
	 * @param int    $user_id Usuario conectado (0 si anónimo).
	 * @param array  $first   Datos de primera visita: referrer, landing, source, medium, campaign.
	 * @return object|null Fila con id, user_id y prev_user_id (usuario antes de esta petición).
	 */
	public static function upsert_visitor( $vid, $user_id, array $first ) {
		global $wpdb;
		$t   = WUA_Installer::table( 'visitors' );
		$now = WUA_Util::now();

		$before = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id FROM {$t} WHERE vid = %s", $vid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $before ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"INSERT IGNORE INTO {$t} (vid, user_id, first_seen, last_seen, first_referrer, first_landing, first_source, first_medium, first_campaign) VALUES (%s, NULLIF(%d, 0), %s, %s, %s, %s, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$vid,
					$user_id,
					$now,
					$now,
					$first['referrer'],
					$first['landing'],
					$first['source'],
					$first['medium'],
					$first['campaign']
				)
			);
		} elseif ( $user_id ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET last_seen = %s, user_id = %d WHERE id = %d", $now, $user_id, $before->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET last_seen = %s WHERE id = %d", $now, $before->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id FROM {$t} WHERE vid = %s", $vid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $row ) {
			$row->prev_user_id = $before ? (int) $before->user_id : 0;
		}
		return $row;
	}

	/**
	 * Crea o actualiza una sesión y devuelve su fila.
	 *
	 * @param string $sid        UUID de la sesión.
	 * @param int    $visitor_id Visitante.
	 * @param int    $user_id    Usuario (0 si anónimo).
	 * @param array  $data       entry_url, referrer, utm_source, utm_medium, utm_campaign, device.
	 * @return object|null Fila, o null si la sesión pertenece a otro visitante.
	 */
	public static function upsert_session( $sid, $visitor_id, $user_id, array $data ) {
		global $wpdb;
		$t   = WUA_Installer::table( 'sessions' );
		$now = WUA_Util::now();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT IGNORE INTO {$t} (sid, visitor_id, user_id, started_at, last_activity_at, entry_url, exit_url, referrer, utm_source, utm_medium, utm_campaign, device) VALUES (%s, %d, NULLIF(%d, 0), %s, %s, %s, %s, %s, %s, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$sid,
				$visitor_id,
				$user_id,
				$now,
				$now,
				$data['entry_url'],
				$data['entry_url'],
				$data['referrer'],
				$data['utm_source'],
				$data['utm_medium'],
				$data['utm_campaign'],
				$data['device']
			)
		);

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE sid = %s", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row || (int) $row->visitor_id !== (int) $visitor_id ) {
			return null;
		}

		$sets = array( $wpdb->prepare( 'last_activity_at = %s', $now ) );
		if ( $user_id && (int) $row->user_id !== (int) $user_id ) {
			$sets[]       = $wpdb->prepare( 'user_id = %d', $user_id );
			$row->user_id = $user_id;
		}
		if ( '' === $row->device && '' !== $data['device'] ) {
			$sets[] = $wpdb->prepare( 'device = %s', $data['device'] );
		}
		if ( '' === $row->entry_url && '' !== $data['entry_url'] ) {
			$sets[] = $wpdb->prepare( 'entry_url = %s', $data['entry_url'] );
		}
		$wpdb->query( "UPDATE {$t} SET " . implode( ', ', $sets ) . $wpdb->prepare( ' WHERE id = %d', $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $row;
	}

	/**
	 * Registra una página vista. Idempotente por pvid.
	 *
	 * @param string $pvid    UUID de la página vista.
	 * @param object $session Sesión.
	 * @param array  $data    url, title, post_id.
	 * @param string $when    Fecha UTC (por defecto, ahora).
	 * @return int Id de la página vista (0 si pertenece a otra sesión).
	 */
	public static function add_pageview( $pvid, $session, array $data, $when = '' ) {
		global $wpdb;
		$t = WUA_Installer::table( 'pageviews' );
		$s = WUA_Installer::table( 'sessions' );

		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT IGNORE INTO {$t} (pvid, session_id, visitor_id, user_id, url, title, post_id, viewed_at) VALUES (%s, %d, %d, NULLIF(%d, 0), %s, %s, %d, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$pvid,
				$session->id,
				$session->visitor_id,
				(int) $session->user_id,
				$data['url'],
				$data['title'],
				$data['post_id'],
				$when ? $when : WUA_Util::now()
			)
		);

		if ( $inserted ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$s} SET pageviews = pageviews + 1, exit_url = %s WHERE id = %d", $data['url'], $session->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->insert_id;
		}
		return self::pageview_id( $pvid, $session );
	}

	/**
	 * Id y url de una página vista de la sesión.
	 *
	 * @param string $pvid    UUID.
	 * @param object $session Sesión.
	 * @return int
	 */
	public static function pageview_id( $pvid, $session ) {
		global $wpdb;
		$t = WUA_Installer::table( 'pageviews' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE pvid = %s AND session_id = %d", $pvid, $session->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Url de una página vista.
	 *
	 * @param int $pageview_id Id.
	 * @return string
	 */
	public static function pageview_url( $pageview_id ) {
		global $wpdb;
		$t = WUA_Installer::table( 'pageviews' );
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT url FROM {$t} WHERE id = %d", $pageview_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Suma tiempo activo y actualiza la profundidad de scroll.
	 *
	 * @param int    $pageview_id Página vista.
	 * @param object $session     Sesión.
	 * @param int    $seconds     Segundos activos a sumar.
	 * @param int    $scroll      Profundidad máxima (0-100).
	 */
	public static function add_activity( $pageview_id, $session, $seconds, $scroll ) {
		global $wpdb;
		$t = WUA_Installer::table( 'pageviews' );
		$s = WUA_Installer::table( 'sessions' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET active_seconds = active_seconds + %d, max_scroll = GREATEST(max_scroll, %d) WHERE id = %d", $seconds, $scroll, $pageview_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $seconds > 0 ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$s} SET active_seconds = active_seconds + %d WHERE id = %d", $seconds, $session->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Registra un evento (clic, personalizado, alta, acceso).
	 *
	 * @param string      $type    Tipo.
	 * @param array       $data    label, selector, target_url, page_url, pageview_id.
	 * @param object|null $session Sesión (puede faltar en eventos de servidor).
	 * @param int         $user_id Usuario, si no hay sesión.
	 * @param string      $when    Fecha UTC (por defecto, ahora).
	 */
	public static function add_event( $type, array $data, $session = null, $user_id = 0, $when = '' ) {
		global $wpdb;
		$data = wp_parse_args(
			$data,
			array(
				'label'       => '',
				'selector'    => '',
				'target_url'  => '',
				'page_url'    => '',
				'pageview_id' => 0,
				'visitor_id'  => 0,
			)
		);
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			WUA_Installer::table( 'events' ),
			array(
				'type'        => $type,
				'session_id'  => $session ? (int) $session->id : null,
				'pageview_id' => $data['pageview_id'] ? (int) $data['pageview_id'] : null,
				'visitor_id'  => $session ? (int) $session->visitor_id : ( $data['visitor_id'] ? (int) $data['visitor_id'] : null ),
				'user_id'     => $session && $session->user_id ? (int) $session->user_id : ( $user_id ? (int) $user_id : null ),
				'label'       => $data['label'],
				'selector'    => $data['selector'],
				'target_url'  => $data['target_url'],
				'page_url'    => $data['page_url'],
				'created_at'  => $when ? $when : WUA_Util::now(),
			)
		);
		if ( $session && 'click' === $type ) {
			$s = WUA_Installer::table( 'sessions' );
			$wpdb->query( $wpdb->prepare( "UPDATE {$s} SET clicks = clicks + 1 WHERE id = %d", $session->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Registra un punto del mapa de calor.
	 *
	 * @param array  $data url, device, selector, rx, ry, x, y, vw.
	 * @param string $when Fecha UTC (por defecto, ahora).
	 */
	public static function add_heatmap_point( array $data, $when = '' ) {
		global $wpdb;
		$data['created_at'] = $when ? $when : WUA_Util::now();
		$wpdb->insert( WUA_Installer::table( 'heatmap' ), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Vincula un visitante a un usuario y le atribuye su historial anónimo.
	 *
	 * @param int $visitor_id Visitante.
	 * @param int $user_id    Usuario.
	 */
	public static function link_visitor_to_user( $visitor_id, $user_id ) {
		global $wpdb;
		$t = WUA_Installer::tables();
		$wpdb->query( $wpdb->prepare( "UPDATE {$t['visitors']} SET user_id = %d WHERE id = %d", $user_id, $visitor_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( array( 'sessions', 'pageviews', 'events' ) as $key ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t[ $key ]} SET user_id = %d WHERE visitor_id = %d AND user_id IS NULL", $user_id, $visitor_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Busca un visitante por UUID.
	 *
	 * @param string $vid UUID.
	 * @return object|null
	 */
	public static function find_visitor( $vid ) {
		global $wpdb;
		$t = WUA_Installer::table( 'visitors' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id FROM {$t} WHERE vid = %s", $vid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Busca una sesión por UUID.
	 *
	 * @param string $sid UUID.
	 * @return object|null
	 */
	public static function find_session( $sid ) {
		global $wpdb;
		$t = WUA_Installer::table( 'sessions' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE sid = %s", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
