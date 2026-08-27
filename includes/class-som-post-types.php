<?php
/**
 * Post Types Registration Module.
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Post_Types
 */
class SOM_Post_Types {

	/**
	 * Initialize post type hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_types' ), 10 );
	}

	/**
	 * Register custom post types.
	 */
	public static function register_post_types() {
		$labels = array(
			'name'                  => _x( 'Shops', 'Post type general name', 'shop-onboarding-manager' ),
			'singular_name'         => _x( 'Shop', 'Post type singular name', 'shop-onboarding-manager' ),
			'menu_name'             => _x( 'Shops', 'Admin Menu text', 'shop-onboarding-manager' ),
			'name_admin_bar'        => _x( 'Shop', 'Add New on Toolbar', 'shop-onboarding-manager' ),
			'add_new'               => __( 'Add New Shop', 'shop-onboarding-manager' ),
			'add_new_item'          => __( 'Add New Shop', 'shop-onboarding-manager' ),
			'new_item'              => __( 'New Shop', 'shop-onboarding-manager' ),
			'edit_item'             => __( 'Edit Shop', 'shop-onboarding-manager' ),
			'view_item'             => __( 'View Shop', 'shop-onboarding-manager' ),
			'all_items'             => __( 'All Shops', 'shop-onboarding-manager' ),
			'search_items'          => __( 'Search Shops', 'shop-onboarding-manager' ),
			'parent_item_colon'     => __( 'Parent Shops:', 'shop-onboarding-manager' ),
			'not_found'             => __( 'No shops found.', 'shop-onboarding-manager' ),
			'not_found_in_trash'    => __( 'No shops found in Trash.', 'shop-onboarding-manager' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_nav_menus'  => false,
			'query_var'          => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'menu_position'      => 56,
			'menu_icon'          => 'dashicons-store',
			'supports'           => array( 'title', 'editor', 'author', 'revisions' ),
			'show_in_rest'       => false,
		);

		register_post_type( 'shop', $args );
	}
}