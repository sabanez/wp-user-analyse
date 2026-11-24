<?php
/**
 * Altas y accesos de usuarios, capturados en el servidor.
 *
 * @package WP_User_Analyse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registra altas y logins y fusiona el historial anónimo con el usuario.
 */
class WUA_User_Events {

	/**
	 * Engancha los hooks.
	 */
	public function register() {
		add_action( 'user_register', array( $this, 'on_register' ), 20 );
		add_action( 'wp_login', array( $this, 'on_login' ), 20, 2 );
	}

	/**
	 * Nueva alta.
	 *
	 * @param int $user_id Usuario.
	 */
	public function on_register( $user_id ) {
		// Las altas creadas por un administrador desde el panel no son altas en la web.
		if ( is_admin() && ! wp_doing_ajax() && get_current_user_id() && get_current_user_id() !== (int) $user_id ) {
			return;
		}
		$this->record( 'register', (int) $user_id );
	}

	/**
	 * Nuevo acceso.
	 *
	 * @param string  $login Nombre de usuario.
	 * @param WP_User $user  Usuario.
	 */
	public function on_login( $login, $user ) {
		if ( $user instanceof WP_User ) {
			$this->record( 'login', (int) $user->ID );
		}
	}

	/**
	 * Guarda el evento y, si el navegador tiene identificador de visitante
	 * (solo existe si dio su consentimiento), le atribuye su historial.
	 *
	 * @param string $type    register | login.
	 * @param int    $user_id Usuario.
	 */
	private function record( $type, $user_id ) {
		if ( ! $user_id || WUA_Settings::is_user_excluded( $user_id ) ) {
			return;
		}

		$session = null;
		$visitor = null;
		$vid     = isset( $_COOKIE['wua_vid'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['wua_vid'] ) ) : '';
		$sid     = isset( $_COOKIE['wua_sid'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['wua_sid'] ) ) : '';

		if ( WUA_Util::is_uuid( $vid ) ) {
			$visitor = WUA_Store::find_visitor( $vid );
			if ( $visitor ) {
				WUA_Store::link_visitor_to_user( (int) $visitor->id, $user_id );
				if ( WUA_Util::is_uuid( $sid ) ) {
					$session = WUA_Store::find_session( $sid );
					if ( $session && (int) $session->visitor_id !== (int) $visitor->id ) {
						$session = null;
					}
				}
			}
		}

		$referer = wp_get_referer();
		WUA_Store::add_event(
			$type,
			array(
				'page_url'   => $referer ? WUA_Util::clean_path( $referer ) : '',
				'visitor_id' => $visitor ? (int) $visitor->id : 0,
			),
			$session,
			$user_id
		);
	}
}
