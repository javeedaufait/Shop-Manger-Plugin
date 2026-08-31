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

		// Category Term Meta Hooks for Malayalam Name
		add_action( 'product_cat_add_form_fields', array( __CLASS__, 'add_category_ml_field' ) );
		add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'edit_category_ml_field' ), 10, 1 );
		add_action( 'created_product_cat', array( __CLASS__, 'save_category_ml_field' ), 10, 1 );
		add_action( 'edited_product_cat', array( __CLASS__, 'save_category_ml_field' ), 10, 1 );
	}

	/**
	 * Register custom taxonomies.
	 */
	public static function register_taxonomies() {
		$labels = array(
			'name'              => _x( 'Shop Statuses', 'taxonomy general name', 'nearmart' ),
			'singular_name'     => _x( 'Shop Status', 'taxonomy singular name', 'nearmart' ),
			'search_items'      => __( 'Search Shop Statuses', 'nearmart' ),
			'all_items'         => __( 'All Shop Statuses', 'nearmart' ),
			'edit_item'         => __( 'Edit Shop Status', 'nearmart' ),
			'update_item'       => __( 'Update Shop Status', 'nearmart' ),
			'add_new_item'      => __( 'Add New Shop Status', 'nearmart' ),
			'new_item_name'     => __( 'New Shop Status Name', 'nearmart' ),
			'menu_name'         => __( 'Statuses', 'nearmart' ),
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

	/**
	 * Add Malayalam Category Name field to Product Category add form.
	 */
	public static function add_category_ml_field() {
		?>
		<div class="form-field term-nearmart-name-ml-wrap">
			<label for="_nearmart_name_ml"><?php esc_html_e( 'Malayalam Category Name (Optional Display Override)', 'nearmart' ); ?></label>
			<input type="text" name="_nearmart_name_ml" id="_nearmart_name_ml" value="" placeholder="e.g. ഗ്രോസറി & പച്ചക്കറികൾ" />
			<p><?php esc_html_e( 'Optional Malayalam localized category display name for NearMart portal and customer app.', 'nearmart' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Add Malayalam Category Name field to Product Category edit form.
	 *
	 * @param WP_Term $term Current term object.
	 */
	public static function edit_category_ml_field( $term ) {
		$ml_name = get_term_meta( $term->term_id, '_nearmart_name_ml', true );
		?>
		<tr class="form-field term-nearmart-name-ml-wrap">
			<th scope="row"><label for="_nearmart_name_ml"><?php esc_html_e( 'Malayalam Category Name', 'nearmart' ); ?></label></th>
			<td>
				<input type="text" name="_nearmart_name_ml" id="_nearmart_name_ml" value="<?php echo esc_attr( $ml_name ); ?>" placeholder="e.g. ഗ്രോസറി & പച്ചക്കറികൾ" />
				<p class="description"><?php esc_html_e( 'Optional Malayalam localized category display name for NearMart portal and customer app.', 'nearmart' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save Malayalam Category Name term meta.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function save_category_ml_field( $term_id ) {
		if ( isset( $_POST['_nearmart_name_ml'] ) ) {
			update_term_meta( $term_id, '_nearmart_name_ml', sanitize_text_field( wp_unslash( $_POST['_nearmart_name_ml'] ) ) );
		}
	}
}