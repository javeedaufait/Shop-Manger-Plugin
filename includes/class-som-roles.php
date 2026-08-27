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
		// Capability hooks if needed.
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