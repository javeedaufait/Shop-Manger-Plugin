<?php
/**
 * NearMart Master Product Catalog Management Module.
 *
 * Configures WooCommerce Products as NearMart Master Products,
 * focusing on Name, Description, Image, Category, Brand, Unit, and Barcode.
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Master_Product
 */
class SOM_Master_Product {

	/**
	 * Taxonomy slug for Brand.
	 */
	const BRAND_TAXONOMY = 'product_brand';

	/**
	 * Meta keys for Master Product specifications.
	 */
	const META_UNIT           = '_nearmart_unit';
	const META_BARCODE        = '_nearmart_barcode';
	const META_NAME_ML        = '_nearmart_name_ml';
	const META_DESCRIPTION_ML = '_nearmart_description_ml';

	/**
	 * Initialize module hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_brand_taxonomy' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_master_product_metabox' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save_master_product_meta' ), 10, 2 );

		// Customize WooCommerce product admin screen.
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'customize_product_data_tabs' ), 99 );
		add_filter( 'product_type_options', array( __CLASS__, 'remove_product_type_options' ), 99 );
		add_action( 'admin_head', array( __CLASS__, 'render_admin_custom_css' ) );
	}

	/**
	 * Register Brand custom taxonomy for WooCommerce product post type.
	 */
	public static function register_brand_taxonomy() {
		if ( taxonomy_exists( self::BRAND_TAXONOMY ) ) {
			return;
		}

		$labels = array(
			'name'              => __( 'Brands', 'nearmart' ),
			'singular_name'     => __( 'Brand', 'nearmart' ),
			'search_items'      => __( 'Search Brands', 'nearmart' ),
			'all_items'         => __( 'All Brands', 'nearmart' ),
			'parent_item'       => __( 'Parent Brand', 'nearmart' ),
			'parent_item_colon' => __( 'Parent Brand:', 'nearmart' ),
			'edit_item'         => __( 'Edit Brand', 'nearmart' ),
			'update_item'       => __( 'Update Brand', 'nearmart' ),
			'add_new_item'      => __( 'Add New Brand', 'nearmart' ),
			'new_item_name'     => __( 'New Brand Name', 'nearmart' ),
			'menu_name'         => __( 'Brands', 'nearmart' ),
		);

		$args = array(
			'hierarchical'      => true,
			'labels'            => $labels,
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => true,
			'rewrite'           => array( 'slug' => 'brand' ),
			'show_in_rest'      => true,
		);

		register_taxonomy( self::BRAND_TAXONOMY, array( 'product' ), $args );
	}

	/**
	 * Add NearMart Master Product Specifications Metabox to WooCommerce product edit screen.
	 */
	public static function add_master_product_metabox() {
		add_meta_box(
			'nearmart_master_product_specs',
			__( '📦 NearMart Master Product Specifications', 'nearmart' ),
			array( __CLASS__, 'render_master_product_metabox' ),
			'product',
			'normal',
			'high'
		);
	}

	/**
	 * Render Master Product Specifications metabox HTML.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_master_product_metabox( $post ) {
		wp_nonce_field( 'nearmart_master_product_nonce', 'nearmart_master_product_nonce_field' );

		$unit        = get_post_meta( $post->ID, self::META_UNIT, true );
		$barcode     = get_post_meta( $post->ID, self::META_BARCODE, true );
		$name_ml     = get_post_meta( $post->ID, self::META_NAME_ML, true );
		$desc_ml     = get_post_meta( $post->ID, self::META_DESCRIPTION_ML, true );
		?>
		<style>
			.nearmart-specs-grid {
				display: grid;
				grid-template-columns: 1fr 1fr;
				gap: 16px;
				margin-top: 8px;
			}
			.nearmart-spec-field label {
				display: block;
				font-weight: 600;
				margin-bottom: 4px;
				color: #1e293b;
			}
			.nearmart-spec-field input, .nearmart-spec-field textarea {
				width: 100%;
				padding: 8px 12px;
				font-size: 0.95rem;
				border: 1px solid #cbd5e1;
				border-radius: 6px;
			}
			.nearmart-spec-desc {
				font-size: 0.825rem;
				color: #64748b;
				margin-top: 4px;
			}
		</style>
		<div class="nearmart-specs-grid">
			<div class="nearmart-spec-field">
				<label for="_nearmart_unit"><?php esc_html_e( 'Unit / Pack Size', 'nearmart' ); ?></label>
				<input type="text" id="_nearmart_unit" name="_nearmart_unit" value="<?php echo esc_attr( $unit ); ?>" placeholder="<?php esc_attr_e( 'e.g. 1 kg, 500 ml, 750g, Pack of 6', 'nearmart' ); ?>" />
				<div class="nearmart-spec-desc"><?php esc_html_e( 'Master unit of measurement for this product.', 'nearmart' ); ?></div>
			</div>

			<div class="nearmart-spec-field">
				<label for="_nearmart_barcode"><?php esc_html_e( 'Barcode / EAN / UPC', 'nearmart' ); ?></label>
				<input type="text" id="_nearmart_barcode" name="_nearmart_barcode" value="<?php echo esc_attr( $barcode ); ?>" placeholder="<?php esc_attr_e( 'e.g. 8901030345123', 'nearmart' ); ?>" />
				<div class="nearmart-spec-desc"><?php esc_html_e( 'Global Trade Item Number / GTIN / Barcode.', 'nearmart' ); ?></div>
			</div>

			<div class="nearmart-spec-field" style="grid-column: span 2;">
				<label for="_nearmart_name_ml"><?php esc_html_e( 'Malayalam Product Name (Optional Display Override)', 'nearmart' ); ?></label>
				<input type="text" id="_nearmart_name_ml" name="_nearmart_name_ml" value="<?php echo esc_attr( $name_ml ); ?>" placeholder="<?php esc_attr_e( 'e.g. ഹാർലിക്സ് ഹെൽത്ത് ഡ്രിങ്ക് 500g', 'nearmart' ); ?>" />
				<div class="nearmart-spec-desc"><?php esc_html_e( 'Optional Malayalam localized display name for NearMart merchant portal and customer view.', 'nearmart' ); ?></div>
			</div>

			<div class="nearmart-spec-field" style="grid-column: span 2;">
				<label for="_nearmart_description_ml"><?php esc_html_e( 'Malayalam Description (Optional Display Override)', 'nearmart' ); ?></label>
				<textarea id="_nearmart_description_ml" name="_nearmart_description_ml" rows="3"><?php echo esc_textarea( $desc_ml ); ?></textarea>
				<div class="nearmart-spec-desc"><?php esc_html_e( 'Optional Malayalam localized description.', 'nearmart' ); ?></div>
			</div>
		</div>
		<?php
		}

	/**
	 * Save Master Product specifications meta data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_master_product_meta( $post_id, $post ) {
		if ( ! isset( $_POST['nearmart_master_product_nonce_field'] ) || ! wp_verify_nonce( $_POST['nearmart_master_product_nonce_field'], 'nearmart_master_product_nonce' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['_nearmart_unit'] ) ) {
			update_post_meta( $post_id, self::META_UNIT, sanitize_text_field( wp_unslash( $_POST['_nearmart_unit'] ) ) );
		}

		if ( isset( $_POST['_nearmart_barcode'] ) ) {
			update_post_meta( $post_id, self::META_BARCODE, sanitize_text_field( wp_unslash( $_POST['_nearmart_barcode'] ) ) );
		}

		if ( isset( $_POST['_nearmart_name_ml'] ) ) {
			update_post_meta( $post_id, self::META_NAME_ML, sanitize_text_field( wp_unslash( $_POST['_nearmart_name_ml'] ) ) );
		}

		if ( isset( $_POST['_nearmart_description_ml'] ) ) {
			update_post_meta( $post_id, self::META_DESCRIPTION_ML, sanitize_textarea_field( wp_unslash( $_POST['_nearmart_description_ml'] ) ) );
		}
	}

	/**
	 * Remove unnecessary WooCommerce product data tabs for Master Products.
	 *
	 * @param array $tabs WooCommerce product data tabs.
	 * @return array
	 */
	public static function customize_product_data_tabs( $tabs ) {
		// Keep General (for SKU) and Inventory if needed, hide commerce tabs.
		unset( $tabs['shipping'] );
		unset( $tabs['tax'] );
		unset( $tabs['inventory'] );
		unset( $tabs['linked_product'] );
		unset( $tabs['attribute'] );
		unset( $tabs['variations'] );
		unset( $tabs['advanced'] );

		return $tabs;
	}

	/**
	 * Remove Downloadable and Virtual options from WooCommerce product edit screen.
	 *
	 * @param array $options Product type options.
	 * @return array
	 */
	public static function remove_product_type_options( $options ) {
		unset( $options['virtual'] );
		unset( $options['downloadable'] );
		return $options;
	}

	/**
	 * Render custom inline CSS in WooCommerce admin product edit screen to hide pricing/tax fields.
	 */
	public static function render_admin_custom_css() {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}
		?>
		<style id="nearmart-master-product-admin-css">
			/* Hide WooCommerce price and tax fields in master product editor */
			#general_product_data .pricing,
			#general_product_data .options_group.pricing,
			._regular_price_field,
			._sale_price_field,
			._tax_status_field,
			._tax_class_field,
			.show_if_downloadable,
			.show_if_virtual {
				display: none !important;
			}
		</style>
		<?php
	}

	/**
	 * Get master product specifications for a given product ID.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return array Specs array (unit, barcode, brand, sku).
	 */
	public static function get_master_product_specs( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return array(
				'unit'       => '',
				'barcode'    => '',
				'brand_name' => '',
				'brand_id'   => 0,
				'sku'        => '',
			);
		}

		$unit       = get_post_meta( $product_id, self::META_UNIT, true );
		$barcode    = get_post_meta( $product_id, self::META_BARCODE, true );
		$sku        = get_post_meta( $product_id, '_sku', true );
		$brand_name = '';
		$brand_id   = 0;

		$terms = wp_get_post_terms( $product_id, self::BRAND_TAXONOMY );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$brand_name = $terms[0]->name;
			$brand_id   = $terms[0]->term_id;
		}

		return array(
			'unit'       => $unit ? $unit : '',
			'barcode'    => $barcode ? $barcode : '',
			'brand_name' => $brand_name,
			'brand_id'   => $brand_id,
			'sku'        => $sku ? $sku : '',
		);
	}

	/**
	 * Get localized Master Product Title based on current user language preference.
	 *
	 * @param int|WP_Post $product Product ID or Post object.
	 * @param string|null $lang Optional language code ('en' or 'ml').
	 * @return string Localized title or canonical English title.
	 */
	public static function get_localized_title( $product, $lang = null ) {
		$post = get_post( $product );
		if ( ! $post ) {
			return '';
		}

		if ( null === $lang ) {
			$user_id = get_current_user_id();
			$lang    = $user_id ? get_user_meta( $user_id, 'nm_preferred_language', true ) : 'en';
		}

		if ( 'ml' === $lang ) {
			$ml_title = get_post_meta( $post->ID, self::META_NAME_ML, true );
			if ( ! empty( $ml_title ) ) {
				return $ml_title;
			}
		}

		return $post->post_title;
	}

	/**
	 * Get localized Master Product Description based on current user language preference.
	 *
	 * @param int|WP_Post $product Product ID or Post object.
	 * @param string|null $lang Optional language code ('en' or 'ml').
	 * @return string Localized description or canonical English description.
	 */
	public static function get_localized_description( $product, $lang = null ) {
		$post = get_post( $product );
		if ( ! $post ) {
			return '';
		}

		if ( null === $lang ) {
			$user_id = get_current_user_id();
			$lang    = $user_id ? get_user_meta( $user_id, 'nm_preferred_language', true ) : 'en';
		}

		if ( 'ml' === $lang ) {
			$ml_desc = get_post_meta( $post->ID, self::META_DESCRIPTION_ML, true );
			if ( ! empty( $ml_desc ) ) {
				return $ml_desc;
			}
		}

		return $post->post_content;
	}

	/**
	 * Get localized Product Category Name based on current user language preference.
	 *
	 * @param int|WP_Term $term Term ID or WP_Term object.
	 * @param string|null $lang Optional language code.
	 * @return string Localized category name or canonical English name.
	 */
	public static function get_localized_category_name( $term, $lang = null ) {
		if ( is_numeric( $term ) ) {
			$term = get_term( $term, 'product_cat' );
		}
		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}

		if ( null === $lang ) {
			$user_id = get_current_user_id();
			$lang    = $user_id ? get_user_meta( $user_id, 'nm_preferred_language', true ) : 'en';
		}

		if ( 'ml' === $lang ) {
			$ml_cat_name = get_term_meta( $term->term_id, '_nearmart_name_ml', true );
			if ( ! empty( $ml_cat_name ) ) {
				return $ml_cat_name;
			}
		}

		return $term->name;
	}

	/**
	 * Update master product specifications for a given product ID.
	 *
	 * @param int   $product_id WooCommerce product ID.
	 * @param array $specs      Array containing unit, barcode, brand, sku.
	 * @return bool
	 */
	public static function update_master_product_specs( $product_id, $specs = array() ) {
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return false;
		}

		if ( isset( $specs['unit'] ) ) {
			update_post_meta( $product_id, self::META_UNIT, sanitize_text_field( $specs['unit'] ) );
		}

		if ( isset( $specs['barcode'] ) ) {
			update_post_meta( $product_id, self::META_BARCODE, sanitize_text_field( $specs['barcode'] ) );
		}

		if ( isset( $specs['sku'] ) ) {
			update_post_meta( $product_id, '_sku', sanitize_text_field( $specs['sku'] ) );
		}

		if ( isset( $specs['brand'] ) ) {
			$brand = $specs['brand'];
			if ( is_numeric( $brand ) ) {
				wp_set_object_terms( $product_id, array( intval( $brand ) ), self::BRAND_TAXONOMY );
			} else {
				wp_set_object_terms( $product_id, sanitize_text_field( $brand ), self::BRAND_TAXONOMY );
			}
		}

		return true;
	}
}

/* ==========================================================================
   GLOBAL PROCEDURAL HELPER FUNCTIONS FOR MASTER PRODUCTS
   ========================================================================== */

if ( ! function_exists( 'nearmart_get_master_product_specs' ) ) {
	/**
	 * Helper function to get master product specifications.
	 */
	function nearmart_get_master_product_specs( $product_id ) {
		return SOM_Master_Product::get_master_product_specs( $product_id );
	}
}

if ( ! function_exists( 'nearmart_update_master_product_specs' ) ) {
	/**
	 * Helper function to update master product specifications.
	 */
	function nearmart_update_master_product_specs( $product_id, $specs = array() ) {
		return SOM_Master_Product::update_master_product_specs( $product_id, $specs );
	}
}
if ( ! function_exists( 'nearmart_get_localized_master_title' ) ) {
	/**
	 * Helper function to get localized master product title.
	 */
	function nearmart_get_localized_master_title( $product_id, $lang = null ) {
		return SOM_Master_Product::get_localized_title( $product_id, $lang );
	}
}

if ( ! function_exists( 'nearmart_get_localized_category_name' ) ) {
	/**
	 * Helper function to get localized category name.
	 */
	function nearmart_get_localized_category_name( $term, $lang = null ) {
		return SOM_Master_Product::get_localized_category_name( $term, $lang );
	}
}