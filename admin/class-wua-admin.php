<?php
/**
 * Panel de administración.
 *
 * @package WP_User_Analyse
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- los informes solo leen filtros por GET.

/**
 * Menú, páginas de informes, ajustes y exportación CSV.
 */
class WUA_Admin {

	const PER_PAGE = 50;

	/**
	 * Engancha los hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_wua_export', array( $this, 'export_csv' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WUA_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Capacidad necesaria para ver los informes.
	 *
	 * @return string
	 */
	public static function capability() {
		return (string) apply_filters( 'wua_capability', 'manage_options' );
	}

	/**
	 * Páginas del menú: slug => [título, método].
	 *
	 * @return array
	 */
	private function pages() {
		return array(
			'wua'          => array( __( 'Resumen', 'wp-user-analyse' ), 'page_summary' ),
			'wua-pages'    => array( __( 'Páginas', 'wp-user-analyse' ), 'page_pages' ),
			'wua-clicks'   => array( __( 'Clics', 'wp-user-analyse' ), 'page_clicks' ),
			'wua-users'    => array( __( 'Usuarios', 'wp-user-analyse' ), 'page_users' ),
			'wua-signups'  => array( __( 'Altas', 'wp-user-analyse' ), 'page_signups' ),
			'wua-sessions' => array( __( 'Sesiones', 'wp-user-analyse' ), 'page_sessions' ),
			'wua-heatmap'  => array( __( 'Mapa de calor', 'wp-user-analyse' ), 'page_heatmap' ),
			'wua-settings' => array( __( 'Ajustes', 'wp-user-analyse' ), 'page_settings' ),
		);
	}

	/**
	 * Registra el menú.
	 */
	public function menu() {
		$cap = self::capability();
		add_menu_page( __( 'Actividad web', 'wp-user-analyse' ), __( 'Actividad web', 'wp-user-analyse' ), $cap, 'wua', array( $this, 'page_summary' ), 'dashicons-chart-area', 3 );
		foreach ( $this->pages() as $slug => $page ) {
			add_submenu_page( 'wua', $page[0], $page[0], 'wua-settings' === $slug ? 'manage_options' : $cap, $slug, array( $this, $page[1] ) );
		}
	}

	/**
	 * Enlace a ajustes en la lista de plugins.
	 *
	 * @param array $links Enlaces.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wua-settings' ) ) . '">' . esc_html__( 'Ajustes', 'wp-user-analyse' ) . '</a>' );
		return $links;
	}

	/**
	 * Carga CSS y JS en las páginas del plugin.
	 *
	 * @param string $hook Pantalla.
	 */
	public function assets( $hook ) {
		if ( false === strpos( $hook, 'wua' ) ) {
			return;
		}
		wp_enqueue_style( 'wua-admin', WUA_URL . 'assets/css/admin.css', array(), WUA_VERSION );
		wp_enqueue_script( 'wua-admin', WUA_URL . 'assets/js/admin.js', array(), WUA_VERSION, true );
	}

	/* =========================================================
	 * Utilidades de presentación
	 * ======================================================= */

	/**
	 * Duración legible.
	 *
	 * @param float $seconds Segundos.
	 * @return string
	 */
	public static function duration( $seconds ) {
		$seconds = (int) round( (float) $seconds );
		if ( $seconds < 60 ) {
			return $seconds . ' s';
		}
		$h = intdiv( $seconds, 3600 );
		$m = intdiv( $seconds % 3600, 60 );
		$s = $seconds % 60;
		if ( $h ) {
			return sprintf( '%d h %02d min', $h, $m );
		}
		return sprintf( '%d min %02d s', $m, $s );
	}

	/**
	 * Fecha UTC mostrada en la zona del sitio.
	 *
	 * @param string|null $utc Fecha UTC.
	 * @return string
	 */
	public static function date( $utc ) {
		if ( empty( $utc ) || '0000-00-00 00:00:00' === $utc ) {
			return '—';
		}
		return wp_date( 'd/m/Y H:i', strtotime( $utc . ' UTC' ) );
	}

	/**
	 * Número con formato local.
	 *
	 * @param float $n        Número.
	 * @param int   $decimals Decimales.
	 * @return string
	 */
	private static function num( $n, $decimals = 0 ) {
		return number_format_i18n( (float) $n, $decimals );
	}

	/**
	 * Parámetro GET saneado.
	 *
	 * @param string $key     Clave.
	 * @param string $default Por defecto.
	 * @return string
	 */
	private static function param( $key, $default = '' ) {
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $default;
	}

	/**
	 * Página actual de la paginación.
	 *
	 * @return int
	 */
	private static function paged() {
		return max( 1, absint( self::param( 'paged', '1' ) ) );
	}

	/**
	 * Tipo de usuario elegido en el filtro.
	 *
	 * @return string
	 */
	private static function user_type() {
		$type = self::param( 'type', 'all' );
		return in_array( $type, array( 'all', 'anon', 'registered' ), true ) ? $type : 'all';
	}

	/**
	 * URL de la página actual con parámetros cambiados.
	 *
	 * @param array $args Parámetros.
	 * @return string
	 */
	private static function url( array $args ) {
		$keep = array();
		foreach ( array( 'page', 'from', 'to', 'days', 'type', 'device', 's', 'user' ) as $k ) {
			if ( '' !== self::param( $k ) ) {
				$keep[ $k ] = self::param( $k );
			}
		}
		return add_query_arg( array_merge( $keep, $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Cabecera común: título, filtros de fecha y aviso de consentimiento.
	 *
	 * @param string      $title   Título.
	 * @param WUA_Reports $r       Informe.
	 * @param array       $options with_type, with_device, with_search, export.
	 */
	private function header( $title, WUA_Reports $r, array $options = array() ) {
		$page = self::param( 'page', 'wua' );
		echo '<div class="wrap wua-wrap"><h1>' . esc_html( $title ) . '</h1>';
		$this->consent_notice();

		echo '<form method="get" class="wua-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( $page ) . '">';
		echo '<label>' . esc_html__( 'Desde', 'wp-user-analyse' ) . ' <input type="date" name="from" value="' . esc_attr( $r->from ) . '"></label> ';
		echo '<label>' . esc_html__( 'Hasta', 'wp-user-analyse' ) . ' <input type="date" name="to" value="' . esc_attr( $r->to ) . '"></label> ';

		if ( ! empty( $options['with_type'] ) ) {
			$types = array(
				'all'        => __( 'Todos', 'wp-user-analyse' ),
				'anon'       => __( 'Visitantes anónimos', 'wp-user-analyse' ),
				'registered' => __( 'Usuarios registrados', 'wp-user-analyse' ),
			);
			echo '<select name="type">';
			foreach ( $types as $k => $label ) {
				echo '<option value="' . esc_attr( $k ) . '"' . selected( self::user_type(), $k, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select> ';
		}
		if ( ! empty( $options['with_device'] ) ) {
			$devices = array(
				'desktop' => __( 'Escritorio', 'wp-user-analyse' ),
				'tablet'  => __( 'Tablet', 'wp-user-analyse' ),
				'mobile'  => __( 'Móvil', 'wp-user-analyse' ),
			);
			echo '<select name="device">';
			foreach ( $devices as $k => $label ) {
				echo '<option value="' . esc_attr( $k ) . '"' . selected( self::param( 'device', 'desktop' ), $k, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select> ';
		}
		if ( ! empty( $options['with_search'] ) ) {
			echo '<input type="search" name="s" placeholder="' . esc_attr__( 'Nombre o email', 'wp-user-analyse' ) . '" value="' . esc_attr( self::param( 's' ) ) . '"> ';
		}
		if ( ! empty( $options['extra_hidden'] ) ) {
			foreach ( $options['extra_hidden'] as $k => $v ) {
				echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
			}
		}
		submit_button( __( 'Filtrar', 'wp-user-analyse' ), 'secondary', '', false );

		echo ' <span class="wua-presets">';
		foreach ( array( 7, 30, 90, 365 ) as $days ) {
			/* translators: %d: número de días. */
			echo '<a href="' . esc_url( add_query_arg( array( 'page' => $page, 'days' => $days ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( sprintf( __( '%d días', 'wp-user-analyse' ), $days ) ) . '</a> ';
		}
		echo '</span>';

		if ( ! empty( $options['export'] ) ) {
			$export = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'wua_export',
						'report' => $options['export'],
						'from'   => $r->from,
						'to'     => $r->to,
						'type'   => self::user_type(),
						's'      => self::param( 's' ),
					),
					admin_url( 'admin-post.php' )
				),
				'wua_export'
			);
			echo ' <a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Exportar CSV', 'wp-user-analyse' ) . '</a>';
		}
		echo '</form>';
	}

	/**
	 * Cierre común.
	 */
	private function footer() {
		echo '</div>';
	}

	/**
	 * Aviso si no se está registrando nada por falta de integración de consentimiento.
	 */
	private function consent_notice() {
		$mode = WUA_Settings::get( 'consent_mode' );
		if ( 'auto' === $mode && 'custom' === WUA_Tracker::consent_mode() ) {
			echo '<div class="notice notice-warning"><p>' . wp_kses_post(
				sprintf(
					/* translators: %s: enlace a ajustes. */
					__( 'No se detecta la WP Consent API. El plugin no registra visitas hasta que vuestro gestor de cookies llame a <code>wua.grantConsent()</code> tras el consentimiento de estadística. Revisa el modo de consentimiento en <a href="%s">Ajustes</a>.', 'wp-user-analyse' ),
					esc_url( admin_url( 'admin.php?page=wua-settings' ) )
				)
			) . '</p></div>';
		}
	}

	/**
	 * Paginación simple (anterior / siguiente).
	 *
	 * @param int $count Filas mostradas.
	 */
	private function pagination( $count ) {
		$paged = self::paged();
		if ( 1 === $paged && $count < self::PER_PAGE ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">';
		if ( $paged > 1 ) {
			echo '<a class="button" href="' . esc_url( self::url( array( 'paged' => $paged - 1 ) ) ) . '">&laquo; ' . esc_html__( 'Anterior', 'wp-user-analyse' ) . '</a> ';
		}
		/* translators: %d: número de página. */
		echo '<span class="displaying-num">' . esc_html( sprintf( __( 'Página %d', 'wp-user-analyse' ), $paged ) ) . '</span> ';
		if ( self::PER_PAGE === $count ) {
			echo '<a class="button" href="' . esc_url( self::url( array( 'paged' => $paged + 1 ) ) ) . '">' . esc_html__( 'Siguiente', 'wp-user-analyse' ) . ' &raquo;</a>';
		}
		echo '</div></div>';
	}

	/**
	 * Tabla genérica.
	 *
	 * @param array    $columns Clave => título.
	 * @param array    $rows    Filas ya formateadas (HTML seguro por celda).
	 * @param string   $empty   Texto si no hay filas.
	 */
	private function table( array $columns, array $rows, $empty = '' ) {
		echo '<table class="widefat striped wua-table"><thead><tr>';
		foreach ( $columns as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="' . count( $columns ) . '">' . esc_html( $empty ? $empty : __( 'No hay datos en este periodo.', 'wp-user-analyse' ) ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( array_keys( $columns ) as $key ) {
				echo '<td>' . wp_kses_post( isset( $row[ $key ] ) ? $row[ $key ] : '' ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Enlace a la ficha de un usuario.
	 *
	 * @param int    $user_id Usuario.
	 * @param string $name    Nombre.
	 * @param string $email   Email.
	 * @return string
	 */
	private static function user_link( $user_id, $name, $email ) {
		if ( ! $user_id ) {
			return '<span class="wua-muted">' . esc_html__( 'Anónimo', 'wp-user-analyse' ) . '</span>';
		}
		$url = add_query_arg( array( 'page' => 'wua-users', 'user' => (int) $user_id ), admin_url( 'admin.php' ) );
		return '<a href="' . esc_url( $url ) . '"><strong>' . esc_html( $name ) . '</strong></a><br><span class="wua-muted">' . esc_html( $email ) . '</span>';
	}

	/**
	 * Enlace al recorrido de una sesión.
	 *
	 * @param int    $session_id Sesión.
	 * @param string $text       Texto.
	 * @return string
	 */
	private static function session_link( $session_id, $text ) {
		$url = add_query_arg( array( 'page' => 'wua-sessions', 'session' => (int) $session_id ), admin_url( 'admin.php' ) );
		return '<a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>';
	}

	/**
	 * Enlace a una página pública.
	 *
	 * @param string $path  Ruta.
	 * @param string $title Título.
	 * @return string
	 */
	private static function page_link( $path, $title = '' ) {
		$out = '<a href="' . esc_url( home_url( $path ) ) . '" target="_blank" rel="noopener">' . esc_html( $path ) . '</a>';
		if ( '' !== $title ) {
			$out .= '<br><span class="wua-muted">' . esc_html( $title ) . '</span>';
		}
		return $out;
	}

	/**
	 * Comprueba permisos.
	 */
	private function guard() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'No tienes permiso para ver esta página.', 'wp-user-analyse' ) );
		}
	}

	/* =========================================================
	 * Páginas
	 * ======================================================= */

	/**
	 * Resumen.
	 */
	public function page_summary() {
		$this->guard();
		$r = WUA_Reports::from_request();
		$s = $r->summary();
		$this->header( __( 'Actividad web: resumen', 'wp-user-analyse' ), $r );

		$cards = array(
			array( __( 'Visitantes únicos', 'wp-user-analyse' ), self::num( $s['visitors'] ) ),
			array( __( 'Sesiones', 'wp-user-analyse' ), self::num( $s['sessions'] ) ),
			array( __( 'Sesiones de registrados', 'wp-user-analyse' ), self::num( $s['registered_sessions'] ) ),
			array( __( 'Usuarios activos', 'wp-user-analyse' ), self::num( $s['active_users'] ) ),
			array( __( 'Altas', 'wp-user-analyse' ), self::num( $s['signups'] ) ),
			array( __( 'Accesos (logins)', 'wp-user-analyse' ), self::num( $s['logins'] ) ),
			array( __( 'Páginas vistas', 'wp-user-analyse' ), self::num( $s['pageviews'] ) ),
			array( __( 'Páginas por sesión', 'wp-user-analyse' ), self::num( $s['pages_per_session'], 1 ) ),
			array( __( 'Tiempo activo medio por sesión', 'wp-user-analyse' ), self::duration( $s['avg_seconds'] ) ),
			array( __( 'Tiempo activo total', 'wp-user-analyse' ), self::duration( $s['total_seconds'] ) ),
			array( __( 'Clics registrados', 'wp-user-analyse' ), self::num( $s['clicks'] ) ),
		);
		echo '<div class="wua-cards">';
		foreach ( $cards as $card ) {
			echo '<div class="wua-card"><span class="wua-card-label">' . esc_html( $card[0] ) . '</span><span class="wua-card-value">' . esc_html( $card[1] ) . '</span></div>';
		}
		echo '</div>';

		$daily = $r->daily();
		echo '<div class="wua-panel"><h2>' . esc_html__( 'Sesiones por día', 'wp-user-analyse' ) . '</h2>';
		echo '<div class="wua-chart" data-chart="sessions"></div></div>';
		echo '<div class="wua-panel"><h2>' . esc_html__( 'Altas y accesos por día', 'wp-user-analyse' ) . '</h2>';
		echo '<div class="wua-chart" data-chart="users"></div></div>';
		echo '<div class="wua-panel"><h2>' . esc_html__( 'Tiempo activo por día (minutos)', 'wp-user-analyse' ) . '</h2>';
		echo '<div class="wua-chart" data-chart="minutes"></div></div>';
		echo '<script type="application/json" id="wua-daily">' . wp_json_encode( $daily ) . '</script>';

		echo '<div class="wua-two">';
		echo '<div class="wua-panel"><h2>' . esc_html__( 'Páginas más vistas', 'wp-user-analyse' ) . '</h2>';
		$rows = array();
		foreach ( $r->top_pages( 'all', 10 ) as $p ) {
			$rows[] = array(
				'url'   => self::page_link( $p['url'], $p['title'] ),
				'views' => self::num( $p['views'] ),
				'time'  => self::duration( $p['avg_seconds'] ),
			);
		}
		$this->table(
			array(
				'url'   => __( 'Página', 'wp-user-analyse' ),
				'views' => __( 'Vistas', 'wp-user-analyse' ),
				'time'  => __( 'Tiempo medio', 'wp-user-analyse' ),
			),
			$rows
		);
		echo '</div><div class="wua-panel"><h2>' . esc_html__( 'Botones y enlaces más pulsados', 'wp-user-analyse' ) . '</h2>';
		$rows = array();
		foreach ( $r->top_clicks( 'all', 10 ) as $c ) {
			$rows[] = array(
				'label'  => '<strong>' . esc_html( '' !== $c['label'] ? $c['label'] : '(sin texto)' ) . '</strong><br><span class="wua-muted">' . esc_html( $c['page_url'] ) . '</span>',
				'clicks' => self::num( $c['clicks'] ),
			);
		}
		$this->table(
			array(
				'label'  => __( 'Elemento', 'wp-user-analyse' ),
				'clicks' => __( 'Clics', 'wp-user-analyse' ),
			),
			$rows
		);
		echo '</div></div>';
		$this->footer();
	}

	/**
	 * Páginas.
	 */
	public function page_pages() {
		$this->guard();
		$r = WUA_Reports::from_request();
		$this->header(
			__( 'Páginas visitadas', 'wp-user-analyse' ),
			$r,
			array(
				'with_type' => true,
				'export'    => 'pages',
			)
		);
		$data = $r->top_pages( self::user_type(), self::PER_PAGE, ( self::paged() - 1 ) * self::PER_PAGE );
		$rows = array();
		foreach ( $data as $p ) {
			$rows[] = array(
				'url'      => self::page_link( $p['url'], $p['title'] ),
				'views'    => self::num( $p['views'] ),
				'visitors' => self::num( $p['visitors'] ),
				'users'    => self::num( $p['users'] ),
				'time'     => self::duration( $p['avg_seconds'] ),
				'scroll'   => (int) $p['avg_scroll'] . ' %',
			);
		}
		$this->table(
			array(
				'url'      => __( 'Página', 'wp-user-analyse' ),
				'views'    => __( 'Vistas', 'wp-user-analyse' ),
				'visitors' => __( 'Visitantes', 'wp-user-analyse' ),
				'users'    => __( 'Usuarios registrados', 'wp-user-analyse' ),
				'time'     => __( 'Tiempo activo medio', 'wp-user-analyse' ),
				'scroll'   => __( 'Scroll medio', 'wp-user-analyse' ),
			),
			$rows
		);
		$this->pagination( count( $data ) );
		$this->footer();
	}

	/**
	 * Clics.
	 */
	public function page_clicks() {
		$this->guard();
		$r = WUA_Reports::from_request();
		$this->header(
			__( 'Clics en botones y enlaces', 'wp-user-analyse' ),
			$r,
			array(
				'with_type' => true,
				'export'    => 'clicks',
			)
		);
		echo '<p class="description">' . wp_kses_post( __( 'Para dar un nombre fijo a un botón, añade el atributo <code>data-wua-track="Nombre"</code> en su HTML.', 'wp-user-analyse' ) ) . '</p>';
		$data = $r->top_clicks( self::user_type(), self::PER_PAGE, ( self::paged() - 1 ) * self::PER_PAGE );
		$rows = array();
		foreach ( $data as $c ) {
			$rows[] = array(
				'label'    => '<strong>' . esc_html( '' !== $c['label'] ? $c['label'] : __( '(sin texto)', 'wp-user-analyse' ) ) . '</strong>' . ( $c['target_url'] ? '<br><span class="wua-muted">→ ' . esc_html( $c['target_url'] ) . '</span>' : '' ),
				'page'     => esc_html( $c['page_url'] ),
				'clicks'   => self::num( $c['clicks'] ),
				'visitors' => self::num( $c['visitors'] ),
				'users'    => self::num( $c['users'] ),
				'selector' => '<code class="wua-selector">' . esc_html( $c['selector'] ) . '</code>',
			);
		}
		$this->table(
			array(
				'label'    => __( 'Elemento', 'wp-user-analyse' ),
				'page'     => __( 'Página', 'wp-user-analyse' ),
				'clicks'   => __( 'Clics', 'wp-user-analyse' ),
				'visitors' => __( 'Visitantes', 'wp-user-analyse' ),
				'users'    => __( 'Usuarios registrados', 'wp-user-analyse' ),
				'selector' => __( 'Selector', 'wp-user-analyse' ),
			),
			$rows
		);
		$this->pagination( count( $data ) );
		$this->footer();
	}

	/**
	 * Usuarios (lista o ficha).
	 */
	public function page_users() {
		$this->guard();
		$user_id = absint( self::param( 'user', '0' ) );
		if ( $user_id ) {
			$this->page_user_detail( $user_id );
			return;
		}
		$r = WUA_Reports::from_request();
		$this->header(
			__( 'Usuarios registrados activos', 'wp-user-analyse' ),
			$r,
			array(
				'with_search' => true,
				'export'      => 'users',
			)
		);
		$data = $r->users( self::param( 's' ), self::PER_PAGE, ( self::paged() - 1 ) * self::PER_PAGE );
		$rows = array();
		foreach ( $data as $u ) {
			$rows[] = array(
				'user'       => self::user_link( $u['user_id'], $u['display_name'], $u['user_email'] ),
				'registered' => self::date( $u['user_registered'] ),
				'sessions'   => self::num( $u['sessions'] ),
				'logins'     => self::num( $u['logins'] ),
				'time'       => self::duration( $u['seconds'] ),
				'pageviews'  => self::num( $u['pageviews'] ),
				'clicks'     => self::num( $u['clicks'] ),
				'last_login' => self::date( $u['last_login'] ),
				'last'       => self::date( $u['last_activity'] ),
			);
		}
		$this->table(
			array(
				'user'       => __( 'Usuario', 'wp-user-analyse' ),
				'registered' => __( 'Alta', 'wp-user-analyse' ),
				'sessions'   => __( 'Sesiones', 'wp-user-analyse' ),
				'logins'     => __( 'Accesos', 'wp-user-analyse' ),
				'time'       => __( 'Tiempo activo', 'wp-user-analyse' ),
				'pageviews'  => __( 'Páginas vistas', 'wp-user-analyse' ),
				'clicks'     => __( 'Clics', 'wp-user-analyse' ),
				'last_login' => __( 'Último acceso', 'wp-user-analyse' ),
				'last'       => __( 'Última actividad', 'wp-user-analyse' ),
			),
			$rows,
			__( 'Ningún usuario registrado tuvo actividad en este periodo.', 'wp-user-analyse' )
		);
		$this->pagination( count( $data ) );
		$this->footer();
	}

	/**
	 * Ficha de un usuario.
	 *
	 * @param int $user_id Usuario.
	 */
	private function page_user_detail( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_die( esc_html__( 'Usuario no encontrado.', 'wp-user-analyse' ) );
		}
		$r = WUA_Reports::from_request();
		/* translators: %s: nombre del usuario. */
		$this->header( sprintf( __( 'Actividad de %s', 'wp-user-analyse' ), $user->display_name ), $r, array( 'extra_hidden' => array( 'user' => $user_id ) ) );

		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=wua-users' ) ) . '">&larr; ' . esc_html__( 'Volver a usuarios', 'wp-user-analyse' ) . '</a> · ';
		echo esc_html( $user->user_email ) . ' · ' . esc_html__( 'Alta:', 'wp-user-analyse' ) . ' ' . esc_html( self::date( $user->user_registered ) ) . ' · ';
		echo '<a href="' . esc_url( get_edit_user_link( $user_id ) ) . '">' . esc_html__( 'Editar usuario', 'wp-user-analyse' ) . '</a></p>';

		$sessions = $r->sessions( 'all', $user_id, self::PER_PAGE, ( self::paged() - 1 ) * self::PER_PAGE );
		$rows     = array();
		foreach ( $sessions as $s ) {
			$rows[] = array(
				'start'  => self::session_link( $s['id'], self::date( $s['started_at'] ) ),
				'time'   => self::duration( $s['active_seconds'] ),
				'pages'  => self::num( $s['pageviews'] ),
				'clicks' => self::num( $s['clicks'] ),
				'entry'  => esc_html( $s['entry_url'] ),
				'device' => esc_html( $s['device'] ),
				'source' => esc_html( $s['utm_source'] ? $s['utm_source'] : wp_parse_url( $s['referrer'], PHP_URL_HOST ) ),
			);
		}
		echo '<h2>' . esc_html__( 'Sesiones', 'wp-user-analyse' ) . '</h2>';
		$this->table(
			array(
				'start'  => __( 'Inicio', 'wp-user-analyse' ),
				'time'   => __( 'Tiempo activo', 'wp-user-analyse' ),
				'pages'  => __( 'Páginas', 'wp-user-analyse' ),
				'clicks' => __( 'Clics', 'wp-user-analyse' ),
				'entry'  => __( 'Entrada', 'wp-user-analyse' ),
				'device' => __( 'Dispositivo', 'wp-user-analyse' ),
				'source' => __( 'Origen', 'wp-user-analyse' ),
			),
			$rows
		);
		$this->pagination( count( $sessions ) );
		$this->footer();
	}

	/**
	 * Altas.
	 */
	public function page_signups() {
		$this->guard();
		$r = WUA_Reports::from_request();
		$this->header( __( 'Altas de usuarios', 'wp-user-analyse' ), $r, array( 'export' => 'signups' ) );
		echo '<p class="description">' . esc_html__( 'Incluye todas las altas de WordPress del periodo. El origen y las sesiones previas solo existen si el visitante aceptó las cookies de estadística antes de registrarse.', 'wp-user-analyse' ) . '</p>';
		$data = $r->signups( self::PER_PAGE, ( self::paged() - 1 ) * self::PER_PAGE );
		$rows = array();
		foreach ( $data as $u ) {
			$source = $u['first_source'] ? $u['first_source'] . ( $u['first_medium'] ? ' / ' . $u['first_medium'] : '' ) : (string) wp_parse_url( (string) $u['first_referrer'], PHP_URL_HOST );
			$rows[] = array(
				'date'    => self::date( $u['user_registered'] ),
				'user'    => self::user_link( $u['user_id'], $u['display_name'], $u['user_email'] ),
				'source'  => esc_html( '' !== $source ? $source : ( $u['first_landing'] ? __( 'Directo', 'wp-user-analyse' ) : '—' ) ),
				'landing' => esc_html( $u['first_landing'] ? $u['first_landing'] : '—' ),
				'before'  => self::num( $u['sessions_before'] ),
				'total'   => self::num( $u['sessions_total'] ),
			);
		}
		$this->table(
			array(
				'date'    => __( 'Fecha de alta', 'wp-user-analyse' ),
				'user'    => __( 'Usuario', 'wp-user-analyse' ),
				'source'  => __( 'Origen de la primera visita', 'wp-user-analyse' ),
				'landing' => __( 'Página de entrada', 'wp-user-analyse' ),
				'before'  => __( 'Sesiones antes del alta', 'wp-user-analyse' ),
				'total'   => __( 'Sesiones totales', 'wp-user-analyse' ),
			),
			$rows,
			__( 'No hubo altas en este periodo.', 'wp-user-analyse' )
		);
		$this->pagination( count( $data ) );
		$this->footer();
	}

	/**
	 * Sesiones (lista o recorrido).
	 */
	public function page_sessions() {
		$this->guard();
		$session_id = absint( self::param( 'session', '0' ) );
		if ( $session_id ) {
			$this->page_session_detail( $session_id );
			return;
		}
		$r = WUA_Reports::from_request();
		$this->header(
			__( 'Sesiones', 'wp-user-analyse' ),
			$r,
			array(
				'with_type' => true,
				'export'    => 'sessions',
			)
		);
		$data = $r->sessions( self::user_type(), 0, self::PER_PAGE, ( self::paged() - 1 ) * self::PER_PAGE );
		$rows = array();
		foreach ( $data as $s ) {
			$rows[] = array(
				'start'  => self::session_link( $s['id'], self::date( $s['started_at'] ) ),
				'user'   => self::user_link( $s['user_id'], (string) $s['display_name'], (string) $s['user_email'] ),
				'time'   => self::duration( $s['active_seconds'] ),
				'pages'  => self::num( $s['pageviews'] ),
				'clicks' => self::num( $s['clicks'] ),
				'entry'  => esc_html( $s['entry_url'] ),
				'exit'   => esc_html( $s['exit_url'] ),
				'device' => esc_html( $s['device'] ),
				'source' => esc_html( $s['utm_source'] ? $s['utm_source'] : (string) wp_parse_url( $s['referrer'], PHP_URL_HOST ) ),
			);
		}
		$this->table(
			array(
				'start'  => __( 'Inicio', 'wp-user-analyse' ),
				'user'   => __( 'Usuario', 'wp-user-analyse' ),
				'time'   => __( 'Tiempo activo', 'wp-user-analyse' ),
				'pages'  => __( 'Páginas', 'wp-user-analyse' ),
				'clicks' => __( 'Clics', 'wp-user-analyse' ),
				'entry'  => __( 'Entrada', 'wp-user-analyse' ),
				'exit'   => __( 'Salida', 'wp-user-analyse' ),
				'device' => __( 'Dispositivo', 'wp-user-analyse' ),
				'source' => __( 'Origen', 'wp-user-analyse' ),
			),
			$rows
		);
		$this->pagination( count( $data ) );
		$this->footer();
	}

	/**
	 * Recorrido de una sesión.
	 *
	 * @param int $session_id Sesión.
	 */
	private function page_session_detail( $session_id ) {
		$d = WUA_Reports::session_detail( $session_id );
		if ( ! $d['session'] ) {
			wp_die( esc_html__( 'Sesión no encontrada.', 'wp-user-analyse' ) );
		}
		$s = $d['session'];
		echo '<div class="wrap wua-wrap"><h1>' . esc_html__( 'Recorrido de la sesión', 'wp-user-analyse' ) . '</h1>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=wua-sessions' ) ) . '">&larr; ' . esc_html__( 'Volver a sesiones', 'wp-user-analyse' ) . '</a></p>';
		echo '<p>' . wp_kses_post( self::user_link( $s['user_id'], (string) $s['display_name'], (string) $s['user_email'] ) ) . '</p>';
		echo '<p>' . esc_html( self::date( $s['started_at'] ) ) . ' · ' . esc_html( self::duration( $s['active_seconds'] ) ) . ' ' . esc_html__( 'de tiempo activo', 'wp-user-analyse' ) . ' · ' . esc_html( $s['device'] );
		if ( $s['referrer'] || $s['utm_source'] ) {
			echo ' · ' . esc_html__( 'Origen:', 'wp-user-analyse' ) . ' ' . esc_html( trim( $s['utm_source'] . ' ' . $s['utm_medium'] . ' ' . $s['utm_campaign'] . ' ' . $s['referrer'] ) );
		}
		echo '</p>';

		// Mezcla páginas y eventos en una línea temporal.
		$timeline = array();
		foreach ( $d['pages'] as $p ) {
			$timeline[] = array(
				'time' => $p['viewed_at'],
				'kind' => 1,
				'id'   => (int) $p['id'],
				'html' => '<span class="wua-tag">' . esc_html__( 'Página', 'wp-user-analyse' ) . '</span> ' . self::page_link( $p['url'], $p['title'] ) . ' <span class="wua-muted">· ' . esc_html( self::duration( $p['active_seconds'] ) ) . ' · scroll ' . (int) $p['max_scroll'] . ' %</span>',
			);
		}
		$types = array(
			'click'    => __( 'Clic', 'wp-user-analyse' ),
			'custom'   => __( 'Evento', 'wp-user-analyse' ),
			'login'    => __( 'Acceso', 'wp-user-analyse' ),
			'register' => __( 'Alta', 'wp-user-analyse' ),
		);
		foreach ( $d['events'] as $e ) {
			$timeline[] = array(
				'time' => $e['created_at'],
				'kind' => 0,
				'id'   => (int) $e['id'],
				'html' => '<span class="wua-tag wua-tag-' . esc_attr( $e['type'] ) . '">' . esc_html( isset( $types[ $e['type'] ] ) ? $types[ $e['type'] ] : $e['type'] ) . '</span> ' . esc_html( $e['label'] ) . ( $e['target_url'] ? ' <span class="wua-muted">→ ' . esc_html( $e['target_url'] ) . '</span>' : '' ),
			);
		}
		usort(
			$timeline,
			static function ( $a, $b ) {
				// Mismo segundo: el clic o el acceso precede a la página que abre.
				return array( $a['time'], $a['kind'], $a['id'] ) <=> array( $b['time'], $b['kind'], $b['id'] );
			}
		);
		echo '<ol class="wua-timeline">';
		foreach ( $timeline as $item ) {
			echo '<li><time>' . esc_html( wp_date( 'H:i:s', strtotime( $item['time'] . ' UTC' ) ) ) . '</time> ' . wp_kses_post( $item['html'] ) . '</li>';
		}
		echo '</ol></div>';
	}

	/**
	 * Mapa de calor.
	 */
	public function page_heatmap() {
		$this->guard();
		$r      = WUA_Reports::from_request();
		$device = self::param( 'device', 'desktop' );
		$device = in_array( $device, array( 'desktop', 'tablet', 'mobile' ), true ) ? $device : 'desktop';
		$pages  = $r->heatmap_pages( $device );
		$url    = self::param( 'url', $pages ? $pages[0]['url'] : '' );

		$this->header(
			__( 'Mapa de calor de clics', 'wp-user-analyse' ),
			$r,
			array(
				'with_device'  => true,
				'extra_hidden' => array( 'url' => $url ),
			)
		);

		if ( ! WUA_Settings::get( 'heatmap' ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'El mapa de calor está desactivado en Ajustes.', 'wp-user-analyse' ) . '</p></div>';
		}
		if ( ! $pages ) {
			echo '<p>' . esc_html__( 'Todavía no hay clics registrados para este dispositivo y periodo.', 'wp-user-analyse' ) . '</p>';
			$this->footer();
			return;
		}

		echo '<div class="wua-heatmap-layout"><div class="wua-heatmap-pages"><h2>' . esc_html__( 'Páginas', 'wp-user-analyse' ) . '</h2><ul>';
		foreach ( $pages as $p ) {
			$link = self::url( array( 'url' => $p['url'], 'device' => $device ) );
			echo '<li' . ( $p['url'] === $url ? ' class="current"' : '' ) . '><a href="' . esc_url( $link ) . '">' . esc_html( $p['url'] ) . '</a> <span class="wua-muted">' . esc_html( self::num( $p['points'] ) ) . '</span></li>';
		}
		echo '</ul></div>';

		$widths = array(
			'desktop' => 1366,
			'tablet'  => 820,
			'mobile'  => 390,
		);
		$points = $r->heatmap_points( $url, $device );
		$src    = add_query_arg( 'wua_heatmap', '1', home_url( $url ) );
		echo '<div class="wua-heatmap-view">';
		/* translators: 1: número de clics, 2: ruta. */
		echo '<p><strong>' . esc_html( sprintf( __( '%1$s clics en %2$s', 'wp-user-analyse' ), self::num( count( $points ) ), $url ) ) . '</strong> <span class="wua-muted">' . esc_html__( 'Los clics se recolocan sobre el elemento pulsado; si ya no existe, se usan sus coordenadas.', 'wp-user-analyse' ) . '</span></p>';
		echo '<div class="wua-heatmap-frame-wrap"><iframe id="wua-heatmap-frame" src="' . esc_url( $src ) . '" width="' . (int) $widths[ $device ] . '" height="900" sandbox="allow-same-origin allow-scripts"></iframe></div>';
		echo '<script type="application/json" id="wua-heatmap-points">' . wp_json_encode( $points ) . '</script>';
		echo '</div></div>';
		$this->footer();
	}

	/* =========================================================
	 * Ajustes
	 * ======================================================= */

	/**
	 * Registra la opción.
	 */
	public function register_settings() {
		register_setting(
			'wua',
			WUA_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'WUA_Settings', 'sanitize' ),
			)
		);
	}

	/**
	 * Página de ajustes.
	 */
	public function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para ver esta página.', 'wp-user-analyse' ) );
		}
		$s    = WUA_Settings::all();
		$name = WUA_Settings::OPTION;

		echo '<div class="wrap wua-wrap"><h1>' . esc_html__( 'Actividad web: ajustes', 'wp-user-analyse' ) . '</h1>';
		$this->consent_notice();
		echo '<form method="post" action="options.php">';
		settings_fields( 'wua' );
		echo '<table class="form-table" role="presentation">';

		$this->field_text( __( 'Identificador de la web', 'wp-user-analyse' ), 'site_id', $s, __( 'Único entre todas vuestras webs. Se usará al sincronizar con HubSpot.', 'wp-user-analyse' ) );
		$this->field_text( __( 'Entidad', 'wp-user-analyse' ), 'entity', $s, __( 'Valor de la propiedad Entity de HubSpot para esta web (por ejemplo IFMI). Se usará al sincronizar.', 'wp-user-analyse' ) );

		$modes = array(
			'auto'        => __( 'Automático: WP Consent API si está activa; si no, esperar a wua.grantConsent()', 'wp-user-analyse' ),
			'consent_api' => __( 'WP Consent API (categoría estadística)', 'wp-user-analyse' ),
			'custom'      => __( 'Manual: el gestor de cookies llama a wua.grantConsent()', 'wp-user-analyse' ),
			'none'        => __( 'Sin consentimiento (solo si vuestro DPO lo ha validado)', 'wp-user-analyse' ),
		);
		echo '<tr><th scope="row">' . esc_html__( 'Consentimiento', 'wp-user-analyse' ) . '</th><td>';
		foreach ( $modes as $k => $label ) {
			echo '<label><input type="radio" name="' . esc_attr( $name ) . '[consent_mode]" value="' . esc_attr( $k ) . '"' . checked( $s['consent_mode'], $k, false ) . disabled( WUA_Settings::is_locked( 'consent_mode' ), true, false ) . '> ' . esc_html( $label ) . '</label><br>';
		}
		/* translators: %s: modo efectivo. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Modo efectivo ahora: %s', 'wp-user-analyse' ), WUA_Tracker::consent_mode() ) ) . '</p></td></tr>';

		$this->field_check( __( 'Clics', 'wp-user-analyse' ), 'track_clicks', $s, __( 'Registrar clics en botones y enlaces', 'wp-user-analyse' ) );
		$this->field_text( __( 'Elementos a registrar', 'wp-user-analyse' ), 'click_selectors', $s, __( 'Selectores CSS separados por comas.', 'wp-user-analyse' ), 'large-text' );
		$this->field_check( __( 'Scroll', 'wp-user-analyse' ), 'track_scroll', $s, __( 'Registrar la profundidad de scroll por página', 'wp-user-analyse' ) );
		$this->field_check( __( 'Mapa de calor', 'wp-user-analyse' ), 'heatmap', $s, __( 'Registrar la posición de los clics', 'wp-user-analyse' ) );
		$this->field_number( __( 'Muestreo del mapa de calor (%)', 'wp-user-analyse' ), 'heatmap_sample', $s, 1, 100, __( 'Porcentaje de sesiones cuyos clics se guardan para el mapa. Bájalo en webs con mucho tráfico.', 'wp-user-analyse' ) );

		echo '<tr><th scope="row">' . esc_html__( 'Roles excluidos', 'wp-user-analyse' ) . '</th><td>';
		foreach ( wp_roles()->get_names() as $role => $label ) {
			echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '[excluded_roles][]" value="' . esc_attr( $role ) . '"' . checked( in_array( $role, (array) $s['excluded_roles'], true ), true, false ) . disabled( WUA_Settings::is_locked( 'excluded_roles' ), true, false ) . '> ' . esc_html( translate_user_role( $label ) ) . '</label><br>';
		}
		echo '</td></tr>';

		$this->field_textarea( __( 'IPs excluidas', 'wp-user-analyse' ), 'excluded_ips', $s, __( 'Una por línea. Termina en punto para un rango, por ejemplo 192.168.', 'wp-user-analyse' ) );
		$this->field_textarea( __( 'Rutas excluidas', 'wp-user-analyse' ), 'excluded_paths', $s, __( 'Una por línea, por prefijo, por ejemplo /mi-cuenta/', 'wp-user-analyse' ) );
		$this->field_check( __( 'Do Not Track', 'wp-user-analyse' ), 'respect_dnt', $s, __( 'No registrar navegadores con Do Not Track activado', 'wp-user-analyse' ) );
		$this->field_number( __( 'Fin de sesión por inactividad (minutos)', 'wp-user-analyse' ), 'session_timeout', $s, 5, 240 );
		$this->field_number( __( 'Retención de sesiones y páginas (días)', 'wp-user-analyse' ), 'retention_days', $s, 30, 3650 );
		$this->field_number( __( 'Retención de clics y eventos (días)', 'wp-user-analyse' ), 'events_retention', $s, 7, 3650 );
		$this->field_number( __( 'Retención del mapa de calor (días)', 'wp-user-analyse' ), 'heatmap_retention', $s, 7, 3650 );

		echo '</table>';
		echo '<p class="description">' . wp_kses_post( __( 'Cualquier ajuste puede fijarse en <code>wp-config.php</code> con una constante, por ejemplo <code>define( \'WUA_SITE_ID\', \'labolife-es\' );</code>. Los ajustes fijados así aparecen bloqueados.', 'wp-user-analyse' ) ) . '</p>';
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Campo de texto.
	 *
	 * @param string $label Etiqueta.
	 * @param string $key   Clave.
	 * @param array  $s     Ajustes.
	 * @param string $help  Ayuda.
	 * @param string $class Clase CSS.
	 */
	private function field_text( $label, $key, $s, $help = '', $class = 'regular-text' ) {
		echo '<tr><th scope="row"><label for="wua-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="text" id="wua-' . esc_attr( $key ) . '" class="' . esc_attr( $class ) . '" name="' . esc_attr( WUA_Settings::OPTION . '[' . $key . ']' ) . '" value="' . esc_attr( $s[ $key ] ) . '"' . disabled( WUA_Settings::is_locked( $key ), true, false ) . '>';
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Campo numérico.
	 *
	 * @param string $label Etiqueta.
	 * @param string $key   Clave.
	 * @param array  $s     Ajustes.
	 * @param int    $min   Mínimo.
	 * @param int    $max   Máximo.
	 * @param string $help  Ayuda.
	 */
	private function field_number( $label, $key, $s, $min, $max, $help = '' ) {
		echo '<tr><th scope="row"><label for="wua-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="number" id="wua-' . esc_attr( $key ) . '" class="small-text" min="' . (int) $min . '" max="' . (int) $max . '" name="' . esc_attr( WUA_Settings::OPTION . '[' . $key . ']' ) . '" value="' . esc_attr( $s[ $key ] ) . '"' . disabled( WUA_Settings::is_locked( $key ), true, false ) . '>';
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Casilla.
	 *
	 * @param string $label Etiqueta.
	 * @param string $key   Clave.
	 * @param array  $s     Ajustes.
	 * @param string $text  Texto de la casilla.
	 */
	private function field_check( $label, $key, $s, $text ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label>';
		echo '<input type="checkbox" name="' . esc_attr( WUA_Settings::OPTION . '[' . $key . ']' ) . '" value="1"' . checked( (int) $s[ $key ], 1, false ) . disabled( WUA_Settings::is_locked( $key ), true, false ) . '> ' . esc_html( $text );
		echo '</label></td></tr>';
	}

	/**
	 * Área de texto.
	 *
	 * @param string $label Etiqueta.
	 * @param string $key   Clave.
	 * @param array  $s     Ajustes.
	 * @param string $help  Ayuda.
	 */
	private function field_textarea( $label, $key, $s, $help = '' ) {
		echo '<tr><th scope="row"><label for="wua-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<textarea id="wua-' . esc_attr( $key ) . '" rows="4" class="large-text code" name="' . esc_attr( WUA_Settings::OPTION . '[' . $key . ']' ) . '"' . disabled( WUA_Settings::is_locked( $key ), true, false ) . '>' . esc_textarea( is_array( $s[ $key ] ) ? implode( "\n", $s[ $key ] ) : $s[ $key ] ) . '</textarea>';
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/* =========================================================
	 * Exportación CSV
	 * ======================================================= */

	/**
	 * Descarga un informe en CSV (separador ; y BOM para Excel).
	 */
	public function export_csv() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'No tienes permiso.', 'wp-user-analyse' ) );
		}
		check_admin_referer( 'wua_export' );

		$r      = WUA_Reports::from_request();
		$report = self::param( 'report' );
		$type   = self::user_type();
		$max    = 50000;

		switch ( $report ) {
			case 'pages':
				$rows = $r->top_pages( $type, $max );
				break;
			case 'clicks':
				$rows = $r->top_clicks( $type, $max );
				break;
			case 'users':
				$rows = $r->users( self::param( 's' ), $max );
				break;
			case 'signups':
				$rows = $r->signups( $max );
				break;
			case 'sessions':
				$rows = $r->sessions( $type, 0, $max );
				break;
			default:
				wp_die( esc_html__( 'Informe desconocido.', 'wp-user-analyse' ) );
		}

		$filename = sprintf( 'actividad-%s-%s-%s.csv', $report, $r->from, $r->to );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		if ( $rows ) {
			fputcsv( $out, array_keys( $rows[0] ), ';' );
			foreach ( $rows as $row ) {
				// Evita la inyección de fórmulas al abrir el CSV en Excel.
				$row = array_map(
					static function ( $v ) {
						$v = (string) $v;
						return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@' ), true ) ) ? "'" . $v : $v;
					},
					$row
				);
				fputcsv( $out, $row, ';' );
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
