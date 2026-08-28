<?php
/**
 * Catalog Permissions & Merchant Isolation Module.
 *
 * Handles merchant shop_id resolution and strict permission checks for catalog operations.
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Catalog_Permissions
 */
class SOM_Catalog_Permissions {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Permission initialization if needed.
	}

	/**
	 * Automatically resolve the assigned shop_id for a logged-in merchant.
	 *
	 * @param int $user_id Optional. User ID. Defaults to current logged-in user.
	 * @return int Shop CPT Post ID or 0 if unassigned/invalid.
	 */
	public static function get_current_merchant_shop_id( $user_id = 0 ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return 0;
		}

		$shop_id = get_user_meta( $user_id, 'som_shop_id', true );
		$shop_id = absint( $shop_id );

		if ( ! $shop_id ) {
			return 0;
		}

		// Verify post exists, is of post_type 'shop', and not trashed.
		$post = get_post( $shop_id );
		if ( ! $post || 'shop' !== $post->post_type || 'trash' === $post->post_status ) {
			return 0;
		}

		return $shop_id;
	}

	/**
	 * Check if a user has permission to manage a specific shop.
	 *
	 * - Admins can manage all shops.
	 * - Merchants can manage ONLY their own assigned shop.
	 *
	 * @param int $user_id User ID to check.
	 * @param int $shop_id Target Shop CPT Post ID.
	 * @return bool True if permitted, false otherwise.
	 */
	public static function user_can_manage_shop( $user_id, $shop_id ) {
		$user_id = absint( $user_id );
		$shop_id = absint( $shop_id );

		if ( ! $user_id || ! $shop_id ) {
			return false;
		}

		// 1. Administrators / Super Users can manage any shop.
		if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'administrator' ) ) {
			return true;
		}

		// 2. Merchants can manage ONLY their assigned shop ID.
		$merchant_shop_id = self::get_current_merchant_shop_id( $user_id );
		if ( $merchant_shop_id && $merchant_shop_id === $shop_id ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if a user has permission to manage a shop's catalog.
	 *
	 * If shop_id is omitted, automatically resolves to current merchant's shop_id.
	 *
	 * @param int $user_id Optional. User ID. Defaults to current user.
	 * @param int $shop_id Optional. Target Shop ID. Auto-resolves for merchants if omitted.
	 * @return bool
	 */
	public static function user_can_manage_shop_catalog( $user_id = 0, $shop_id = 0 ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return false;
		}

		$shop_id = absint( $shop_id );
		if ( ! $shop_id ) {
			$shop_id = self::get_current_merchant_shop_id( $user_id );
		}

		if ( ! $shop_id ) {
			// If admin and no specific shop_id provided, return true for capability check.
			if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'administrator' ) ) {
				return true;
			}
			return false;
		}

		return self::user_can_manage_shop( $user_id, $shop_id );
	}

	/**
	 * Verify catalog action permission and return WP_Error if invalid.
	 *
	 * @param int    $shop_id Target Shop ID.
	 * @param string $action  Action type ('view', 'add', 'edit', 'delete').
	 * @param int    $user_id Optional User ID.
	 * @return true|WP_Error
	 */
	public static function verify_catalog_action( $shop_id, $action = 'view', $user_id = 0 ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return new WP_Error(
				'unauthorized',
				__( 'Authentication required to manage shop catalog.', 'shop-onboarding-manager' ),
				array( 'status' => 401 )
			);
		}

		$shop_id = absint( $shop_id );
		if ( ! $shop_id ) {
			$shop_id = self::get_current_merchant_shop_id( $user_id );
		}

		if ( ! $shop_id ) {
			return new WP_Error(
				'no_linked_shop',
				__( 'No active shop linked to this account.', 'shop-onboarding-manager' ),
				array( 'status' => 403 )
			);
		}

		if ( ! self::user_can_manage_shop( $user_id, $shop_id ) ) {
			return new WP_Error(
				'forbidden_shop_access',
				__( 'Access denied. You can only manage your own shop catalog.', 'shop-onboarding-manager' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * REST API permission callback helper for future REST endpoints.
	 *
	 * @param WP_REST_Request $request REST API request object.
	 * @return true|WP_Error
	 */
	public static function rest_check_shop_catalog_permissions( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to access catalog endpoints.', 'shop-onboarding-manager' ),
				array( 'status' => 401 )
			);
		}

		// Extract shop_id from route param or query.
		$requested_shop_id = absint( $request->get_param( 'shop_id' ) );

		// If user is a merchant, force/validate against their assigned shop_id.
		$merchant_shop_id = self::get_current_merchant_shop_id( $user_id );

		if ( $merchant_shop_id ) {
			if ( $requested_shop_id && $requested_shop_id !== $merchant_shop_id ) {
				return new WP_Error(
					'rest_forbidden_merchant_isolation',
					__( 'Merchants are restricted to their own shop catalog.', 'shop-onboarding-manager' ),
					array( 'status' => 403 )
				);
			}
			$requested_shop_id = $merchant_shop_id;
		}

		if ( ! $requested_shop_id ) {
			if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'administrator' ) ) {
				return true;
			}
			return new WP_Error(
				'rest_missing_shop_id',
				__( 'Valid shop_id is required.', 'shop-onboarding-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( ! self::user_can_manage_shop( $user_id, $requested_shop_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage this shop catalog.', 'shop-onboarding-manager' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}

/* ==========================================================================
   GLOBAL PROCEDURAL HELPER FUNCTIONS FOR CATALOG PERMISSIONS
   ========================================================================== */

if ( ! function_exists( 'nearmart_get_current_merchant_shop_id' ) ) {
	/**
	 * Resolve current merchant shop ID helper.
	 */
	function nearmart_get_current_merchant_shop_id( $user_id = 0 ) {
		return SOM_Catalog_Permissions::get_current_merchant_shop_id( $user_id );
	}
}

if ( ! function_exists( 'nearmart_user_can_manage_shop' ) ) {
	/**
	 * User can manage shop permission check helper.
	 */
	function nearmart_user_can_manage_shop( $user_id, $shop_id ) {
		return SOM_Catalog_Permissions::user_can_manage_shop( $user_id, $shop_id );
	}
}

if ( ! function_exists( 'nearmart_user_can_manage_shop_catalog' ) ) {
	/**
	 * User can manage shop catalog permission check helper.
	 */
	function nearmart_user_can_manage_shop_catalog( $user_id = 0, $shop_id = 0 ) {
		return SOM_Catalog_Permissions::user_can_manage_shop_catalog( $user_id, $shop_id );
	}
}

if ( ! function_exists( 'nearmart_verify_catalog_action' ) ) {
	/**
	 * Verify catalog action helper.
	 */
	function nearmart_verify_catalog_action( $shop_id, $action = 'view', $user_id = 0 ) {
		return SOM_Catalog_Permissions::verify_catalog_action( $shop_id, $action, $user_id );
	}
}

if ( ! function_exists( 'nearmart_rest_check_shop_catalog_permissions' ) ) {
	/**
	 * REST API catalog permissions check callback helper.
	 */
	function nearmart_rest_check_shop_catalog_permissions( WP_REST_Request $request ) {
		return SOM_Catalog_Permissions::rest_check_shop_catalog_permissions( $request );
	}
}