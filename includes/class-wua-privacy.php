<?php
/**
 * Integración con las herramientas de privacidad de WordPress (RGPD).
 *
 * @package WP_User_Analyse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exportación y borrado de datos personales, y texto sugerido de política.
 */
class WUA_Privacy {

	const PER_PAGE = 200;

	/**
	 * Engancha los hooks.
	 */
	public function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
		add_action( 'delete_user', array( $this, 'on_delete_user' ) );
	}

	/**
	 * Registra el exportador.
	 *
	 * @param array $exporters Exportadores.
	 * @return array
	 */
	public function add_exporter( $exporters ) {
		$exporters['wp-user-analyse'] = array(
			'exporter_friendly_name' => __( 'Actividad web (WP User Analyse)', 'wp-user-analyse' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Registra el borrador.
	 *
	 * @param array $erasers Borradores.
	 * @return array
	 */
	public function add_eraser( $erasers ) {
		$erasers['wp-user-analyse'] = array(
			'eraser_friendly_name' => __( 'Actividad web (WP User Analyse)', 'wp-user-analyse' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exporta las páginas vistas del usuario.
	 *
	 * @param string $email Email.
	 * @param int    $page  Página.
	 * @return array
	 */
	public function export( $email, $page = 1 ) {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}
		$t      = WUA_Installer::table( 'pageviews' );
		$offset = ( max( 1, (int) $page ) - 1 ) * self::PER_PAGE;
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, url, title, viewed_at, active_seconds FROM {$t} WHERE user_id = %d ORDER BY id LIMIT %d OFFSET %d", $user->ID, self::PER_PAGE, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$data = array();
		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'wua-pageviews',
				'group_label' => __( 'Páginas visitadas', 'wp-user-analyse' ),
				'item_id'     => 'wua-pv-' . $row->id,
				'data'        => array(
					array( 'name' => __( 'Página', 'wp-user-analyse' ), 'value' => $row->url ),
					array( 'name' => __( 'Título', 'wp-user-analyse' ), 'value' => $row->title ),
					array( 'name' => __( 'Fecha (UTC)', 'wp-user-analyse' ), 'value' => $row->viewed_at ),
					array( 'name' => __( 'Segundos activos', 'wp-user-analyse' ), 'value' => $row->active_seconds ),
				),
			);
		}
		return array( 'data' => $data, 'done' => count( $rows ) < self::PER_PAGE );
	}

	/**
	 * Borra toda la actividad del usuario.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public function erase( $email ) {
		$user    = get_user_by( 'email', $email );
		$removed = $user ? self::delete_user_data( (int) $user->ID ) : 0;
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Al borrar un usuario, borra su actividad.
	 *
	 * @param int $user_id Usuario.
	 */
	public function on_delete_user( $user_id ) {
		self::delete_user_data( (int) $user_id );
	}

	/**
	 * Borra todas las filas de un usuario.
	 *
	 * @param int $user_id Usuario.
	 * @return int Filas borradas.
	 */
	public static function delete_user_data( $user_id ) {
		global $wpdb;
		$t     = WUA_Installer::tables();
		$total = 0;
		foreach ( array( 'events', 'pageviews', 'sessions', 'visitors' ) as $key ) {
			$total += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t[ $key ]} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $total;
	}

	/**
	 * Texto sugerido para la política de privacidad.
	 */
	public function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . __( 'Si aceptas las cookies de estadística, esta web registra de forma propia, sin terceros, las páginas que visitas, el tiempo que pasas en ellas, los botones que pulsas y la posición de tus clics. Si tienes una cuenta, esa actividad se asocia a tu usuario, incluida la que hiciste antes de registrarte desde el mismo navegador. Con independencia de las cookies, si tienes una cuenta se registran la fecha de tu alta y de tus accesos. Los datos se conservan durante el plazo configurado y puedes pedir su exportación o borrado.', 'wp-user-analyse' ) . '</p>';
		wp_add_privacy_policy_content( 'WP User Analyse', wp_kses_post( $text ) );
	}
}
