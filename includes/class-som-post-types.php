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
			'name'                  => _x( 'Shops', 'Post type general name', 'nearmart' ),
			'singular_name'         => _x( 'Shop', 'Post type singular name', 'nearmart' ),
			'menu_name'             => _x( 'Shops', 'Admin Menu text', 'nearmart' ),
			'name_admin_bar'        => _x( 'Shop', 'Add New on Toolbar', 'nearmart' ),
			'add_new'               => __( 'Add New Shop', 'nearmart' ),
			'add_new_item'          => __( 'Add New Shop', 'nearmart' ),
			'new_item'              => __( 'New Shop', 'nearmart' ),
			'edit_item'             => __( 'Edit Shop', 'nearmart' ),
			'view_item'             => __( 'View Shop', 'nearmart' ),
			'all_items'             => __( 'All Shops', 'nearmart' ),
			'search_items'          => __( 'Search Shops', 'nearmart' ),
			'parent_item_colon'     => __( 'Parent Shops:', 'nearmart' ),
			'not_found'             => __( 'No shops found.', 'nearmart' ),
			'not_found_in_trash'    => __( 'No shops found in Trash.', 'nearmart' ),
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