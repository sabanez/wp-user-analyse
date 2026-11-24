<?php
/**
 * Endpoint REST de recogida de eventos.
 *
 * @package WP_User_Analyse
 */

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/wua/v1/collect
 */
class WUA_Rest {

	const MAX_BODY   = 65536;
	const MAX_EVENTS = 50;
	const MAX_PING   = 60;

	/**
	 * Límite de eventos por sesión, contra abusos.
	 */
	const MAX_SESSION_EVENTS = 5000;

	/**
	 * Engancha los hooks.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Registra la ruta.
	 */
	public function routes() {
		register_rest_route(
			'wua/v1',
			'/collect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'collect' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Respuesta vacía.
	 *
	 * @param int $status Código HTTP.
	 * @return WP_REST_Response
	 */
	private function done( $status = 204 ) {
		return new WP_REST_Response( null, $status );
	}

	/**
	 * Procesa un lote de eventos.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response
	 */
	public function collect( WP_REST_Request $request ) {
		$body = $request->get_body();
		if ( strlen( $body ) > self::MAX_BODY ) {
			return $this->done( 413 );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || ! WUA_Util::is_uuid( $data['v'] ?? null ) || ! WUA_Util::is_uuid( $data['s'] ?? null ) || empty( $data['e'] ) || ! is_array( $data['e'] ) ) {
			return $this->done( 400 );
		}

		if ( WUA_Settings::is_ip_excluded( WUA_Util::client_ip() ) ) {
			return $this->done();
		}

		$user_id = WUA_Util::user_from_cookie();
		if ( WUA_Settings::is_user_excluded( $user_id ) ) {
			return $this->done();
		}

		$events = array_slice( array_values( array_filter( $data['e'], 'is_array' ) ), 0, self::MAX_EVENTS );
		$first  = $this->first_pageview( $events );

		if ( $first && WUA_Settings::is_path_excluded( WUA_Util::clean_path( $first['u'] ?? '/' ) ) ) {
			return $this->done();
		}

		$utm     = $first && isset( $first['utm'] ) && is_array( $first['utm'] ) ? $first['utm'] : array();
		$landing = $first ? WUA_Util::clean_path( $first['u'] ?? '/' ) : '';
		$ref     = $first ? WUA_Util::url( $first['r'] ?? '' ) : '';
		$source  = WUA_Util::text( $utm['s'] ?? '', 100 );
		$medium  = WUA_Util::text( $utm['m'] ?? '', 100 );
		$camp    = WUA_Util::text( $utm['c'] ?? '', 150 );

		$visitor = WUA_Store::upsert_visitor(
			$data['v'],
			$user_id,
			array(
				'referrer' => $ref,
				'landing'  => $landing,
				'source'   => $source,
				'medium'   => $medium,
				'campaign' => $camp,
			)
		);
		if ( ! $visitor ) {
			return $this->done( 500 );
		}

		// El visitante acaba de identificarse: su historial anónimo pasa a ser del usuario.
		if ( $user_id && $visitor->prev_user_id !== $user_id ) {
			WUA_Store::link_visitor_to_user( (int) $visitor->id, $user_id );
		}

		$session = WUA_Store::upsert_session(
			$data['s'],
			(int) $visitor->id,
			$user_id,
			array(
				'entry_url'    => $landing,
				'referrer'     => $ref,
				'utm_source'   => $source,
				'utm_medium'   => $medium,
				'utm_campaign' => $camp,
				'device'       => $first ? WUA_Util::device_from_width( $first['vw'] ?? 0 ) : '',
			)
		);
		if ( ! $session ) {
			return $this->done( 409 );
		}
		if ( (int) $session->pageviews + (int) $session->clicks > self::MAX_SESSION_EVENTS ) {
			return $this->done( 429 );
		}

		$pageviews = array();
		foreach ( $events as $event ) {
			$this->handle_event( $event, $session, $pageviews );
		}

		return $this->done();
	}

	/**
	 * Primera página vista del lote, si la hay.
	 *
	 * @param array $events Eventos.
	 * @return array|null
	 */
	private function first_pageview( array $events ) {
		foreach ( $events as $event ) {
			if ( isset( $event['t'] ) && 'pv' === $event['t'] ) {
				return $event;
			}
		}
		return null;
	}

	/**
	 * Resuelve el id de una página vista del lote o de la base de datos.
	 *
	 * @param mixed  $pvid      UUID.
	 * @param object $session   Sesión.
	 * @param array  $pageviews Caché del lote (pvid => [id, url]).
	 * @return array|null [id, url]
	 */
	private function resolve_pageview( $pvid, $session, array &$pageviews ) {
		if ( ! WUA_Util::is_uuid( $pvid ) ) {
			return null;
		}
		if ( ! isset( $pageviews[ $pvid ] ) ) {
			$id = WUA_Store::pageview_id( $pvid, $session );
			if ( ! $id ) {
				return null;
			}
			$pageviews[ $pvid ] = array( $id, WUA_Store::pageview_url( $id ) );
		}
		return $pageviews[ $pvid ];
	}

	/**
	 * Procesa un evento.
	 *
	 * @param array  $e         Evento.
	 * @param object $session   Sesión.
	 * @param array  $pageviews Caché del lote.
	 */
	private function handle_event( array $e, $session, array &$pageviews ) {
		$type = isset( $e['t'] ) ? (string) $e['t'] : '';
		$when = WUA_Util::event_time( $e['ts'] ?? 0 );

		switch ( $type ) {
			case 'pv':
				if ( ! WUA_Util::is_uuid( $e['pv'] ?? null ) ) {
					return;
				}
				$url = WUA_Util::clean_path( $e['u'] ?? '/' );
				if ( WUA_Settings::is_path_excluded( $url ) ) {
					return;
				}
				$id = WUA_Store::add_pageview(
					$e['pv'],
					$session,
					array(
						'url'     => $url,
						'title'   => WUA_Util::text( $e['ti'] ?? '', 255 ),
						'post_id' => absint( $e['p'] ?? 0 ),
					),
					$when
				);
				if ( $id ) {
					$pageviews[ $e['pv'] ] = array( $id, $url );
				}
				return;

			case 'ping':
				$pv = $this->resolve_pageview( $e['pv'] ?? null, $session, $pageviews );
				if ( $pv ) {
					$seconds = max( 0, min( self::MAX_PING, (int) ( $e['a'] ?? 0 ) ) );
					$scroll  = max( 0, min( 100, (int) ( $e['sc'] ?? 0 ) ) );
					WUA_Store::add_activity( $pv[0], $session, $seconds, $scroll );
				}
				return;

			case 'click':
			case 'custom':
				if ( 'click' === $type && ! WUA_Settings::get( 'track_clicks' ) ) {
					return;
				}
				$pv = $this->resolve_pageview( $e['pv'] ?? null, $session, $pageviews );
				WUA_Store::add_event(
					$type,
					array(
						'label'       => WUA_Util::text( $e['l'] ?? '', 191 ),
						'selector'    => WUA_Util::text( $e['s'] ?? '', 255 ),
						'target_url'  => WUA_Util::url( $e['h'] ?? '' ),
						'page_url'    => $pv ? $pv[1] : '',
						'pageview_id' => $pv ? $pv[0] : 0,
					),
					$session,
					0,
					$when
				);
				return;

			case 'hm':
				if ( ! WUA_Settings::get( 'heatmap' ) ) {
					return;
				}
				$pv = $this->resolve_pageview( $e['pv'] ?? null, $session, $pageviews );
				$vw = max( 0, min( 10000, (int) ( $e['vw'] ?? 0 ) ) );
				if ( ! $pv || ! $vw ) {
					return;
				}
				WUA_Store::add_heatmap_point(
					array(
						'url'      => $pv[1],
						'device'   => WUA_Util::device_from_width( $vw ),
						'selector' => WUA_Util::text( $e['s'] ?? '', 255 ),
						'rx'       => max( 0, min( 10000, (int) ( $e['rx'] ?? 0 ) ) ),
						'ry'       => max( 0, min( 10000, (int) ( $e['ry'] ?? 0 ) ) ),
						'x'        => max( 0, (int) ( $e['x'] ?? 0 ) ),
						'y'        => max( 0, (int) ( $e['y'] ?? 0 ) ),
						'vw'       => $vw,
					),
					$when
				);
				return;
		}
	}
}
