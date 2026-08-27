<?php
/**
 * Roles & Capabilities Management Module.
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Roles
 */
class SOM_Roles {

	/**
	 * Role slugs.
	 */
	const MERCHANT_ROLE    = 'merchant';
	const FIELD_AGENT_ROLE = 'field_agent';

	/**
	 * Initialize role hooks.
	 */
	public static function init() {
		add_filter( 'login_redirect', array( __CLASS__, 'field_agent_login_redirect' ), 10, 3 );
		add_action( 'admin_init', array( __CLASS__, 'restrict_field_agent_admin' ) );
	}

	/**
	 * Redirect Field Agents to /onboard-shop/ upon login.
	 *
	 * @param string  $redirect_to Redirect URL.
	 * @param string  $request Requested URL.
	 * @param WP_User $user Logged in user.
	 * @return string
	 */
	public static function field_agent_login_redirect( $redirect_to, $request, $user ) {
		if ( isset( $user->roles ) && is_array( $user->roles ) ) {
			if ( in_array( self::FIELD_AGENT_ROLE, $user->roles, true ) && ! in_array( 'administrator', $user->roles, true ) ) {
				return home_url( '/onboard-shop/' );
			}
		}
		return $redirect_to;
	}

	/**
	 * Restrict Field Agents from accessing wp-admin dashboard directly.
	 */
	public static function restrict_field_agent_admin() {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			if ( in_array( self::FIELD_AGENT_ROLE, (array) $user->roles, true ) && ! current_user_can( 'administrator' ) ) {
				wp_safe_redirect( home_url( '/onboard-shop/' ) );
				exit;
			}
		}
	}

	/**
	 * Register custom user roles.
	 */
	public static function register_roles() {
		// 1. Merchant Role (Frontend portal access only).
		add_role(
			self::MERCHANT_ROLE,
			__( 'Merchant', 'shop-onboarding-manager' ),
			array(
				'read'         => true,
				'upload_files' => true,
			)
		);

		// 2. Field Agent Role (Field team onboarding staff).
		add_role(
			self::FIELD_AGENT_ROLE,
			__( 'Field Agent', 'shop-onboarding-manager' ),
			array(
				'read'          => true,
				'edit_posts'    => true,
				'publish_posts' => true,
				'upload_files'  => true,
			)
		);
	}
}