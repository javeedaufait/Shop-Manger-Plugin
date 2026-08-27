<?php
/**
 * Taxonomies Registration Module.
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Taxonomies
 */
class SOM_Taxonomies {

	/**
	 * Initialize taxonomy hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ), 10 );
	}

	/**
	 * Register custom taxonomies.
	 */
	public static function register_taxonomies() {
		$labels = array(
			'name'              => _x( 'Shop Statuses', 'taxonomy general name', 'shop-onboarding-manager' ),
			'singular_name'     => _x( 'Shop Status', 'taxonomy singular name', 'shop-onboarding-manager' ),
			'search_items'      => __( 'Search Shop Statuses', 'shop-onboarding-manager' ),
			'all_items'         => __( 'All Shop Statuses', 'shop-onboarding-manager' ),
			'edit_item'         => __( 'Edit Shop Status', 'shop-onboarding-manager' ),
			'update_item'       => __( 'Update Shop Status', 'shop-onboarding-manager' ),
			'add_new_item'      => __( 'Add New Shop Status', 'shop-onboarding-manager' ),
			'new_item_name'     => __( 'New Shop Status Name', 'shop-onboarding-manager' ),
			'menu_name'         => __( 'Statuses', 'shop-onboarding-manager' ),
		);

		$args = array(
			'hierarchical'      => false,
			'labels'            => $labels,
			'public'            => false,
			'publicly_queryable' => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_quick_edit'=> true,
			'query_var'         => false,
			'rewrite'           => false,
			'show_in_rest'      => false,
		);

		register_taxonomy( 'shop_status', array( 'shop' ), $args );
	}

	/**
	 * Seed default shop status terms.
	 */
	public static function seed_default_statuses() {
		$default_statuses = array(
			'Contacted'  => 'contacted',
			'Interested' => 'interested',
			'Verified'   => 'verified',
			'Committed'  => 'committed',
			'Rejected'   => 'rejected',
		);

		foreach ( $default_statuses as $name => $slug ) {
			if ( ! term_exists( $slug, 'shop_status' ) ) {
				wp_insert_term(
					$name,
					'shop_status',
					array(
						'slug' => $slug,
					)
				);
			}
		}
	}
}