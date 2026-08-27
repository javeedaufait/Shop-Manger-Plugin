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
	 * Role slug for merchant.
	 */
	const MERCHANT_ROLE = 'merchant';

	/**
	 * Initialize role hooks if needed.
	 */
	public static function init() {
		// Future role-based capability hooks.
	}

	/**
	 * Register custom user roles.
	 */
	public static function register_roles() {
		add_role(
			self::MERCHANT_ROLE,
			__( 'Merchant', 'shop-onboarding-manager' ),
			array(
				'read'         => true,
				'upload_files' => true,
			)
		);
	}
}