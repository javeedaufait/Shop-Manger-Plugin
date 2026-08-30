<?php
/**
 * Admin Catalog Management Module (Phase 3 HYBRID Catalog).
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Admin_Catalog
 */
class SOM_Admin_Catalog {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );

		// Admin AJAX Endpoints.
		add_action( 'wp_ajax_som_admin_get_shop_catalog', array( __CLASS__, 'ajax_get_shop_catalog' ) );
		add_action( 'wp_ajax_som_admin_search_master_products', array( __CLASS__, 'ajax_search_master_products' ) );
		add_action( 'wp_ajax_som_admin_add_shop_product', array( __CLASS__, 'ajax_add_shop_product' ) );
		add_action( 'wp_ajax_som_admin_add_standalone_product', array( __CLASS__, 'ajax_add_standalone_product' ) );
		add_action( 'wp_ajax_som_admin_update_shop_product', array( __CLASS__, 'ajax_update_shop_product' ) );
		add_action( 'wp_ajax_som_admin_remove_shop_product', array( __CLASS__, 'ajax_remove_shop_product' ) );
		add_action( 'wp_ajax_som_admin_link_standalone_product', array( __CLASS__, 'ajax_link_standalone_product' ) );
	}

	/**
	 * Register Admin Submenu Page under "Shop Onboarding".
	 */
	public static function register_admin_menu() {
		add_submenu_page(
			'som-admin',
			__( 'Shop Catalogs', 'shop-onboarding-manager' ),
			__( 'Shop Catalogs', 'shop-onboarding-manager' ),
			'manage_options',
			'som-admin-catalog',
			array( __CLASS__, 'render_admin_catalog_page' )
		);
	}

	/**
	 * AJAX endpoint: Get Shop Catalog List for Admin (HYBRID Model).
	 */
	public static function ajax_get_shop_catalog() {
		check_ajax_referer( 'som_admin_catalog_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ), 403 );
		}

		$shop_id      = isset( $_POST['shop_id'] ) ? absint( $_POST['shop_id'] ) : 0;
		$search       = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$status       = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'all';
		$stock_status = isset( $_POST['stock_status'] ) ? sanitize_key( wp_unslash( $_POST['stock_status'] ) ) : 'all';
		$type_filter  = isset( $_POST['type_filter'] ) ? sanitize_key( wp_unslash( $_POST['type_filter'] ) ) : 'all';
		$page         = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$limit        = 20;
		$offset       = ( max( 1, $page ) - 1 ) * $limit;

		if ( ! $shop_id || ! in_array( get_post_type( $shop_id ), array( 'shop', 'shop_onboarding' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid shop selected.', 'shop-onboarding-manager' ) ) );
		}

		$raw_products = nearmart_get_shop_products(
			$shop_id,
			array(
				'status'       => $status,
				'stock_status' => $stock_status,
				'limit'        => 500,
				'offset'       => 0,
				'orderby'      => 'created_at',
				'order'        => 'DESC',
			)
		);

		$items = array();
		foreach ( $raw_products as $p ) {
			$item = nearmart_format_catalog_item( $p );
			if ( ! $item ) {
				continue;
			}

			if ( 'linked' === $type_filter && $item['is_standalone'] ) {
				continue;
			}
			if ( 'unlinked' === $type_filter && ! $item['is_standalone'] ) {
				continue;
			}

			if ( ! empty( $search ) ) {
				$match_title = false !== stripos( $item['title'], $search );
				$match_brand = false !== stripos( (string) $item['brand'], $search );
				$match_sku   = false !== stripos( (string) $item['master_sku'], $search );
				$match_ssku  = false !== stripos( (string) $item['shop_sku'], $search );

				if ( ! $match_title && ! $match_brand && ! $match_sku && ! $match_ssku ) {
					continue;
				}
			}

			$items[] = $item;
		}

		$total_count = count( $items );
		$paged_items = array_slice( $items, $offset, $limit );
		$total_pages = ceil( $total_count / $limit );
		$summary     = nearmart_get_shop_catalog_summary( $shop_id );

		wp_send_json_success(
			array(
				'items'        => $paged_items,
				'total_count'  => $total_count,
				'total_pages'  => max( 1, $total_pages ),
				'current_page' => $page,
				'summary'      => $summary,
			)
		);
	}

	/**
	 * AJAX endpoint: Search WooCommerce Master Products for Admin.
	 */
	public static function ajax_search_master_products() {
		check_ajax_referer( 'som_admin_catalog_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ), 403 );
		}

		$query   = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		$shop_id = isset( $_POST['shop_id'] ) ? absint( $_POST['shop_id'] ) : 0;

		if ( mb_strlen( $query ) < 2 ) {
			wp_send_json_success( array( 'results' => array() ) );
		}

		global $wpdb;
		$search_like = '%' . $wpdb->esc_like( $query ) . '%';

		$sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm_sku ON (p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku')
			LEFT JOIN {$wpdb->postmeta} pm_barcode ON (p.ID = pm_barcode.post_id AND pm_barcode.meta_key = '_nearmart_barcode')
			WHERE p.post_type = 'product'
			AND p.post_status = 'publish'
			AND (
				p.post_title LIKE %s
				OR pm_sku.meta_value LIKE %s
				OR pm_barcode.meta_value LIKE %s
			)
			ORDER BY p.post_title ASC
			LIMIT 20";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$product_ids = $wpdb->get_col( $wpdb->prepare( $sql, $search_like, $search_like, $search_like ) );
		$results     = array();

		if ( ! empty( $product_ids ) ) {
			foreach ( $product_ids as $pid ) {
				$pid = absint( $pid );
				$already_in_catalog = $shop_id ? nearmart_has_shop_product( $shop_id, $pid ) : false;
				$specs              = nearmart_get_master_product_specs( $pid );
				$cats               = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'names' ) );
				$thumb_url          = get_the_post_thumbnail_url( $pid, 'thumbnail' );

				$reg_price = get_post_meta( $pid, '_regular_price', true );
				if ( '' === $reg_price || null === $reg_price ) {
					$reg_price = get_post_meta( $pid, '_price', true );
				}
				$sug_price = ( '' !== $reg_price && null !== $reg_price && is_numeric( $reg_price ) ) ? number_format( (float) $reg_price, 2, '.', '' ) : '';

				$results[] = array(
					'product_id'      => $pid,
					'title'           => get_the_title( $pid ),
					'category'        => ! empty( $cats ) ? $cats[0] : __( 'Uncategorized', 'shop-onboarding-manager' ),
					'brand'           => $specs['brand_name'],
					'unit'            => $specs['unit'],
					'barcode'         => $specs['barcode'],
					'sku'             => $specs['sku'],
					'suggested_price' => $sug_price,
					'thumb_url'       => $thumb_url ? $thumb_url : '',
					'in_catalog'      => $already_in_catalog,
				);
			}
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * AJAX endpoint: Add Master Product to Shop Catalog by Admin.
	 */
	public static function ajax_add_shop_product() {
		check_ajax_referer( 'som_admin_catalog_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ), 403 );
		}

		$shop_id        = isset( $_POST['shop_id'] ) ? absint( $_POST['shop_id'] ) : 0;
		$product_id     = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$price          = isset( $_POST['price'] ) ? floatval( $_POST['price'] ) : 0.00;
		$sale_price     = ( isset( $_POST['sale_price'] ) && '' !== $_POST['sale_price'] ) ? floatval( $_POST['sale_price'] ) : null;
		$stock_quantity = ( isset( $_POST['stock_quantity'] ) && '' !== $_POST['stock_quantity'] ) ? intval( $_POST['stock_quantity'] ) : null;
		$stock_status   = isset( $_POST['stock_status'] ) ? sanitize_key( $_POST['stock_status'] ) : 'instock';
		$status         = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'active';
		$shop_sku       = isset( $_POST['shop_sku'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_sku'] ) ) : null;

		if ( ! $shop_id || ! $product_id || get_post_type( $product_id ) !== 'product' ) {
			wp_send_json_error( array( 'message' => __( 'Invalid shop or master product.', 'shop-onboarding-manager' ) ) );
		}

		if ( nearmart_has_shop_product( $shop_id, $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Product is already in this shop catalog.', 'shop-onboarding-manager' ) ) );
		}

		$result = nearmart_add_shop_product(
			$shop_id,
			$product_id,
			array(
				'price'          => $price,
				'sale_price'     => $sale_price,
				'stock_quantity' => $stock_quantity,
				'stock_status'   => $stock_status,
				'status'         => $status,
				'shop_sku'       => $shop_sku,
			)
		);

		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to add product to shop catalog.', 'shop-onboarding-manager' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Master product added to shop catalog successfully!', 'shop-onboarding-manager' ) ) );
	}

	/**
	 * AJAX endpoint: Add Standalone Product to Shop Catalog by Admin (product_id = NULL).
	 */
	public static function ajax_add_standalone_product() {
		check_ajax_referer( 'som_admin_catalog_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ), 403 );
		}

		$shop_id         = isset( $_POST['shop_id'] ) ? absint( $_POST['shop_id'] ) : 0;
		$custom_name     = isset( $_POST['custom_name'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_name'] ) ) : '';
		$custom_category = isset( $_POST['custom_category'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_category'] ) ) : '';
		$custom_brand    = isset( $_POST['custom_brand'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_brand'] ) ) : '';
		$custom_unit     = isset( $_POST['custom_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_unit'] ) ) : '';
		$custom_barcode  = isset( $_POST['custom_barcode'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_barcode'] ) ) : '';
		$price           = isset( $_POST['price'] ) ? floatval( $_POST['price'] ) : 0.00;
		$sale_price      = ( isset( $_POST['sale_price'] ) && '' !== $_POST['sale_price'] ) ? floatval( $_POST['sale_price'] ) : null;
		$stock_quantity  = ( isset( $_POST['stock_quantity'] ) && '' !== $_POST['stock_quantity'] ) ? intval( $_POST['stock_quantity'] ) : null;
		$stock_status    = isset( $_POST['stock_status'] ) ? sanitize_key( $_POST['stock_status'] ) : 'instock';
		$status          = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'active';
		$shop_sku        = isset( $_POST['shop_sku'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_sku'] ) ) : null;

		if ( ! $shop_id || empty( $custom_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Shop ID and Product name are required.', 'shop-onboarding-manager' ) ) );
		}

		$insert_id = nearmart_add_standalone_shop_product(
			$shop_id,
			array(
				'custom_name'     => $custom_name,
				'custom_category' => $custom_category,
				'custom_brand'    => $custom_brand,
				'custom_unit'     => $custom_unit,
				'custom_barcode'  => $custom_barcode,
				'price'           => $price,
				'sale_price'      => $sale_price,
				'stock_quantity'  => $stock_quantity,
				'stock_status'    => $stock_status,
				'status'          => $status,
				'shop_sku'        => $shop_sku,
			)
		);

		if ( ! $insert_id ) {
			wp_send_json_error( array( 'message' => __( 'Failed to add standalone product to shop catalog.', 'shop-onboarding-manager' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Standalone product added to shop catalog successfully!', 'shop-onboarding-manager' ) ) );
	}

	/**
	 * AJAX endpoint: Update Shop Product by Admin (HYBRID Model).
	 */
	public static function ajax_update_shop_product() {
		check_ajax_referer( 'som_admin_catalog_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ), 403 );
		}

		$id             = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$shop_id        = isset( $_POST['shop_id'] ) ? absint( $_POST['shop_id'] ) : 0;
		$product_id     = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$price          = isset( $_POST['price'] ) ? floatval( $_POST['price'] ) : 0.00;
		$sale_price     = ( isset( $_POST['sale_price'] ) && '' !== $_POST['sale_price'] ) ? floatval( $_POST['sale_price'] ) : null;
		$stock_quantity = ( isset( $_POST['stock_quantity'] ) && '' !== $_POST['stock_quantity'] ) ? intval( $_POST['stock_quantity'] ) : null;
		$stock_status   = isset( $_POST['stock_status'] ) ? sanitize_key( $_POST['stock_status'] ) : 'instock';
		$status         = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'active';
		$shop_sku       = isset( $_POST['shop_sku'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_sku'] ) ) : null;

		// Custom fields for standalone items
		$custom_name     = isset( $_POST['custom_name'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_name'] ) ) : null;
		$custom_category = isset( $_POST['custom_category'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_category'] ) ) : null;
		$custom_brand    = isset( $_POST['custom_brand'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_brand'] ) ) : null;
		$custom_unit     = isset( $_POST['custom_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_unit'] ) ) : null;
		$custom_barcode  = isset( $_POST['custom_barcode'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_barcode'] ) ) : null;

		$row = null;
		if ( $id ) {
			$row = nearmart_get_shop_product_by_id( $id );
		} elseif ( $shop_id && $product_id ) {
			$row = nearmart_get_shop_product( $shop_id, $product_id );
		}

		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Shop product catalog entry not found.', 'shop-onboarding-manager' ) ) );
		}

		$update_data = array(
			'price'          => $price,
			'sale_price'     => $sale_price,
			'stock_quantity' => $stock_quantity,
			'stock_status'   => $stock_status,
			'status'         => $status,
			'shop_sku'       => $shop_sku,
		);

		if ( empty( $row->product_id ) ) {
			if ( null !== $custom_name ) {
				$update_data['custom_name'] = $custom_name;
			}
			if ( null !== $custom_category ) {
				$update_data['custom_category'] = $custom_category;
			}
			if ( null !== $custom_brand ) {
				$update_data['custom_brand'] = $custom_brand;
			}
			if ( null !== $custom_unit ) {
				$update_data['custom_unit'] = $custom_unit;
			}
			if ( null !== $custom_barcode ) {
				$update_data['custom_barcode'] = $custom_barcode;
			}
		}

		$result = nearmart_update_shop_product_by_id( $row->id, $update_data );

		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to update shop product.', 'shop-onboarding-manager' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Shop product updated successfully!', 'shop-onboarding-manager' ) ) );
	}

	/**
	 * AJAX endpoint: Link Standalone Product to Master Product by Admin.
	 */
	public static function ajax_link_standalone_product() {
		check_ajax_referer( 'som_admin_catalog_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ), 403 );
		}

		$id                = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$master_product_id = isset( $_POST['master_product_id'] ) ? absint( $_POST['master_product_id'] ) : 0;

		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid catalog item ID.', 'shop-onboarding-manager' ) ) );
		}

		if ( ! $master_product_id || get_post_type( $master_product_id ) !== 'product' || get_post_status( $master_product_id ) !== 'publish' ) {
			wp_send_json_error( array( 'message' => __( 'Invalid or inactive WooCommerce master product selected.', 'shop-onboarding-manager' ) ) );
		}

		$row = nearmart_get_shop_product_by_id( $id );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Shop product catalog entry not found.', 'shop-onboarding-manager' ) ) );
		}

		if ( nearmart_has_shop_product( $row->shop_id, $master_product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This shop catalog already contains a record linked to that master product.', 'shop-onboarding-manager' ) ) );
		}

		$result = nearmart_update_shop_product_by_id( $id, array( 'product_id' => $master_product_id ) );

		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to link shop product to master product.', 'shop-onboarding-manager' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Shop product linked to WooCommerce master product successfully!', 'shop-onboarding-manager' ) ) );
	}

	/**
	 * AJAX endpoint: Remove Product from Shop Catalog by Admin.
	 */
	public static function ajax_remove_shop_product() {
		check_ajax_referer( 'som_admin_catalog_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ), 403 );
		}

		$id         = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$shop_id    = isset( $_POST['shop_id'] ) ? absint( $_POST['shop_id'] ) : 0;
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		$row = null;
		if ( $id ) {
			$row = nearmart_get_shop_product_by_id( $id );
		} elseif ( $shop_id && $product_id ) {
			$row = nearmart_get_shop_product( $shop_id, $product_id );
		}

		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product catalog entry.', 'shop-onboarding-manager' ) ) );
		}

		$result = nearmart_remove_shop_product_by_id( $row->id );

		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to remove product from shop catalog.', 'shop-onboarding-manager' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Product removed from shop catalog.', 'shop-onboarding-manager' ) ) );
	}

	/**
	 * Enqueue admin scripts & styles.
	 */
	public static function enqueue_admin_assets( $hook ) {
		if ( 'shop-onboarding_page_som-admin-catalog' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'jquery' );
	}

	/**
	 * Render Admin Catalog Management Screen HTML.
	 */
	public static function render_admin_catalog_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'shop-onboarding-manager' ) );
		}

		$shops = get_posts(
			array(
				'post_type'      => array( 'shop', 'shop_onboarding' ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$nonce = wp_create_nonce( 'som_admin_catalog_nonce' );
		?>
		<div class="wrap som-admin-wrap">
			<h1 class="wp-heading-inline">&#127978; <?php esc_html_e( 'Shop Catalogs Management', 'shop-onboarding-manager' ); ?></h1>
			<hr class="wp-header-end" />

			<div style="background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:16px 20px; margin-top:16px; display:flex; align-items:center; justify-content:space-between; gap:20px; flex-wrap:wrap;">
				<div style="display:flex; align-items:center; gap:12px;">
					<label for="som_admin_shop_select" style="font-weight:700; font-size:0.95rem; color:#1d2327;">
						&#127978; <?php esc_html_e( 'Select Shop:', 'shop-onboarding-manager' ); ?>
					</label>
					<select id="som_admin_shop_select" style="min-width:280px; padding:6px 12px; font-size:0.95rem; border-radius:4px;">
						<option value=""><?php esc_html_e( '-- Choose a Shop --', 'shop-onboarding-manager' ); ?></option>
						<?php foreach ( $shops as $s ) : ?>
							<option value="<?php echo esc_attr( $s->ID ); ?>"><?php echo esc_html( $s->post_title ); ?> (ID: <?php echo esc_html( $s->ID ); ?>)</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div>
					<button type="button" id="som_admin_btn_open_add" class="button button-primary button-large" disabled>
						&#10133; <?php esc_html_e( 'Add Product to Shop Catalog', 'shop-onboarding-manager' ); ?>
					</button>
				</div>
			</div>

			<div id="som_admin_catalog_container" style="display:none; margin-top:20px;">
				<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:20px;">
					<div style="background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:14px; text-align:center;">
						<span style="font-size:1.8rem; font-weight:800; color:#1d2327; display:block;" id="som_stat_total">0</span>
						<span style="font-size:0.82rem; color:#64748b; font-weight:600; text-transform:uppercase;"><?php esc_html_e( 'Total Products', 'shop-onboarding-manager' ); ?></span>
					</div>
					<div style="background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:14px; text-align:center;">
						<span style="font-size:1.8rem; font-weight:800; color:#16a34a; display:block;" id="som_stat_active">0</span>
						<span style="font-size:0.82rem; color:#64748b; font-weight:600; text-transform:uppercase;"><?php esc_html_e( 'Active Listed', 'shop-onboarding-manager' ); ?></span>
					</div>
					<div style="background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:14px; text-align:center;">
						<span style="font-size:1.8rem; font-weight:800; color:#ea580c; display:block;" id="som_stat_outofstock">0</span>
						<span style="font-size:0.82rem; color:#64748b; font-weight:600; text-transform:uppercase;"><?php esc_html_e( 'Out of Stock', 'shop-onboarding-manager' ); ?></span>
					</div>
				</div>

				<div style="background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:16px 20px;">
					<div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:16px;">
						<input type="text" id="som_admin_cat_search" class="regular-text" placeholder="Search by name, brand, or SKU..." style="max-width:320px;" />
						<div style="display:flex; gap:10px; flex-wrap:wrap;">
							<select id="som_admin_type_filter">
								<option value="all"><?php esc_html_e( 'All Product Types', 'shop-onboarding-manager' ); ?></option>
								<option value="linked"><?php esc_html_e( 'Master-Linked Products', 'shop-onboarding-manager' ); ?></option>
								<option value="unlinked"><?php esc_html_e( 'Unlinked / Standalone Products', 'shop-onboarding-manager' ); ?></option>
							</select>
							<select id="som_admin_cat_status">
								<option value="all"><?php esc_html_e( 'All Statuses', 'shop-onboarding-manager' ); ?></option>
								<option value="active"><?php esc_html_e( 'Active', 'shop-onboarding-manager' ); ?></option>
								<option value="inactive"><?php esc_html_e( 'Inactive', 'shop-onboarding-manager' ); ?></option>
							</select>
							<select id="som_admin_cat_stock">
								<option value="all"><?php esc_html_e( 'All Availability', 'shop-onboarding-manager' ); ?></option>
								<option value="instock"><?php esc_html_e( 'In Stock', 'shop-onboarding-manager' ); ?></option>
								<option value="outofstock"><?php esc_html_e( 'Out of Stock', 'shop-onboarding-manager' ); ?></option>
							</select>
						</div>
					</div>

					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th style="width: 50px;">Image</th>
								<th>Product Name & Specs</th>
								<th>Type</th>
								<th>Category</th>
								<th>Shop Price</th>
								<th>Availability</th>
								<th>Status</th>
								<th style="width: 160px; text-align: right;">Actions</th>
							</tr>
						</thead>
						<tbody id="som_admin_catalog_tbody">
							<tr>
								<td colspan="8" style="text-align: center; padding: 20px;">Select a shop above to view catalog.</td>
							</tr>
						</tbody>
					</table>

					<div style="display:flex; justify-content:space-between; align-items:center; margin-top:16px;">
						<span id="som_admin_pagination_info" style="color:#64748b; font-size:0.88rem;">Showing 0 items</span>
						<div>
							<button type="button" id="som_admin_prev_btn" class="button" disabled>&larr; Previous</button>
							<button type="button" id="som_admin_next_btn" class="button" disabled>Next &rarr;</button>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- ADMIN MODAL 1: Link Standalone Product to Master -->
		<div id="som_admin_link_modal" class="som-modal-overlay" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:99999; align-items:center; justify-content:center;">
			<div style="background:#fff; width:100%; max-width:600px; border-radius:8px; padding:20px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
				<div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:16px;">
					<h3 style="margin:0;">&#128279; <?php esc_html_e( 'Link Shop Product to WooCommerce Master Product', 'shop-onboarding-manager' ); ?></h3>
					<button type="button" class="button-link" onclick="document.getElementById('som_admin_link_modal').style.display='none';">&times;</button>
				</div>
				<input type="hidden" id="som_admin_link_item_id" value="" />
				<p style="font-size:0.9rem; color:#64748b;">
					Target Standalone Product: <strong id="som_admin_link_target_name" style="color:#1e293b;"></strong>
				</p>

				<div class="som-form-group" style="margin-bottom:16px;">
					<label for="som_admin_link_search" style="font-weight:700; display:block; margin-bottom:6px;"><?php esc_html_e( 'Search WooCommerce Master Products:', 'shop-onboarding-manager' ); ?></label>
					<input type="text" id="som_admin_link_search" class="regular-text" style="width:100%;" placeholder="Type master product name or SKU..." />
					<div id="som_admin_link_results" style="max-height:220px; overflow-y:auto; border:1px solid #c3c4c7; border-radius:4px; margin-top:8px;"></div>
				</div>

				<div id="som_admin_link_selected_wrap" style="display:none; background:#f0fdf4; border:1px solid #bbf7d0; padding:12px; border-radius:6px; margin-bottom:16px;">
					<strong style="color:#166534;" id="som_admin_link_selected_title"></strong>
					<input type="hidden" id="som_admin_link_selected_mpid" value="" />
				</div>

				<div style="display:flex; justify-content:flex-end; gap:10px;">
					<button type="button" class="button" onclick="document.getElementById('som_admin_link_modal').style.display='none';">Cancel</button>
					<button type="button" id="som_admin_btn_confirm_link" class="button button-primary" disabled>&#128279; Link Product</button>
				</div>
			</div>
		</div>

		<!-- ADMIN MODAL 2: Dual-Mode Add Product to Shop Catalog -->
		<div id="som_admin_add_modal" class="som-modal-overlay" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:99999; align-items:center; justify-content:center;">
			<div style="background:#fff; width:100%; max-width:640px; border-radius:8px; padding:20px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
				<div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:16px;">
					<h3 style="margin:0;">&#10133; <?php esc_html_e( 'Add Product to Shop Catalog', 'shop-onboarding-manager' ); ?></h3>
					<button type="button" class="button-link" onclick="document.getElementById('som_admin_add_modal').style.display='none';">&times;</button>
				</div>

				<!-- Dual Tab Switcher -->
				<div style="display:flex; gap:10px; border-bottom:2px solid #e2e8f0; margin-bottom:16px;">
					<button type="button" id="som_admin_tab_btn_master" class="button button-secondary active" style="font-weight:700;">
						&#128065; Search Existing Master Product
					</button>
					<button type="button" id="som_admin_tab_btn_standalone" class="button button-secondary" style="font-weight:700;">
						&#10133; Add Standalone New Product
					</button>
				</div>

				<!-- TAB 1: Master Product Search -->
				<div id="som_admin_tab_content_master">
					<div class="som-form-group" style="margin-bottom:16px;">
						<label for="som_admin_add_search" style="font-weight:700; display:block; margin-bottom:6px;"><?php esc_html_e( 'Search Master Product:', 'shop-onboarding-manager' ); ?></label>
						<input type="text" id="som_admin_add_search" class="regular-text" style="width:100%;" placeholder="Type master product name or SKU..." />
						<div id="som_admin_add_results" style="max-height:180px; overflow-y:auto; border:1px solid #c3c4c7; border-radius:4px; margin-top:8px;"></div>
					</div>

					<form id="som_admin_form_add" style="display:none; border-top:1px solid #e2e8f0; padding-top:14px;">
						<input type="hidden" id="som_admin_add_pid" value="" />
						<p style="font-weight:700; color:#16a34a; margin-bottom:12px;" id="som_admin_add_selected_title"></p>

						<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
							<div>
								<label style="font-weight:700; display:block; font-size:0.85rem;">Shop Price (₹) *</label>
								<input type="number" step="0.01" id="som_admin_add_price" class="regular-text" style="width:100%;" required />
							</div>
							<div>
								<label style="font-weight:700; display:block; font-size:0.85rem;">Sale Price (₹)</label>
								<input type="number" step="0.01" id="som_admin_add_sale_price" class="regular-text" style="width:100%;" />
							</div>
						</div>

						<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
							<div>
								<label style="font-weight:700; display:block; font-size:0.85rem;">Availability</label>
								<select id="som_admin_add_stock_status" style="width:100%;">
									<option value="instock">Available</option>
									<option value="outofstock">Unavailable</option>
								</select>
							</div>
							<div>
								<label style="font-weight:700; display:block; font-size:0.85rem;">Stock Qty</label>
								<input type="number" id="som_admin_add_stock_quantity" class="regular-text" style="width:100%;" />
							</div>
						</div>

						<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px;">
							<div>
								<label style="font-weight:700; display:block; font-size:0.85rem;">Shop SKU</label>
								<input type="text" id="som_admin_add_shop_sku" class="regular-text" style="width:100%;" />
							</div>
							<div>
								<label style="font-weight:700; display:block; font-size:0.85rem;">Status</label>
								<select id="som_admin_add_status" style="width:100%;">
									<option value="active">Active</option>
									<option value="inactive">Inactive</option>
								</select>
							</div>
						</div>

						<div style="display:flex; justify-content:flex-end; gap:10px;">
							<button type="button" class="button" onclick="document.getElementById('som_admin_add_modal').style.display='none';">Cancel</button>
							<button type="submit" id="som_admin_btn_save_add" class="button button-primary">Save Master Product to Shop</button>
						</div>
					</form>
				</div>

				<!-- TAB 2: Standalone Product Creation -->
				<div id="som_admin_tab_content_standalone" style="display:none;">
					<form id="som_admin_form_add_standalone">
						<div style="margin-bottom:12px;">
							<label style="font-weight:700; display:block; font-size:0.85rem;">Product Name *</label>
							<input type="text" id="som_admin_st_name" class="regular-text" style="width:100%;" required placeholder="e.g. Fresh Organic Milk 1L" />
						</div>

						<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
							<div>
								<label style="font-weight:700; display:block; font-size:0.85rem;">Category</label>
								<input type="text" id="som_admin_st_category" class="regular-text" style="width:100%;" placeholder="e.g. Dairy" />
							</div>
							<div>
								<label style="font-weight:700; display:block; font-size:0.85rem;">Brand</label>
								<input type="text" id="som_admin_st_brand" class="regular-text" style="width:100%;" placeholder="e.g. Local Dairy" />
							</div>
						</div>

						<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
							<div>
								<label style="font-weight:700; display:block; font-size:0.85rem;">Unit / Size</label>
								<input type="text" id="som_admin_st_unit" class="regular-text" style="width:100%;" placeholder="e.g. 1L, 500g" />
							</div>
							<div>
								<label style="font-weight:700; display:block; font-size:0.85rem;">Barcode / SKU</label>
								<input type="text" id="som_admin_st_barcode" class="regular-text" style="width:100%;" placeholder="e.g. 89012345678" />
							</div>
						</div>

						<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
							<div>
								<label style="font-weight:700; display:block; font-size:0.85rem;">Shop Price (₹) *</label>
								<input type="number" step="0.01" id="som_admin_st_price" class="regular-text" style="width:100%;" required placeholder="0.00" />
							</div>
							<div>
								<label style="font-weight:700; display:block; font-size:0.85rem;">Sale Price (₹)</label>
								<input type="number" step="0.01" id="som_admin_st_sale_price" class="regular-text" style="width:100%;" placeholder="Optional" />
							</div>
						</div>

						<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
							<div>
								<label style="font-weight:700; display:block; font-size:0.85rem;">Availability</label>
								<select id="som_admin_st_stock_status" style="width:100%;">
									<option value="instock">Available</option>
									<option value="outofstock">Unavailable</option>
								</select>
							</div>
							<div>
								<label style="font-weight:700; display:block; font-size:0.85rem;">Stock Qty</label>
								<input type="number" id="som_admin_st_stock_quantity" class="regular-text" style="width:100%;" placeholder="Optional" />
							</div>
						</div>

						<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px;">
							<div>
								<label style="font-weight:700; display:block; font-size:0.85rem;">Shop SKU</label>
								<input type="text" id="som_admin_st_shop_sku" class="regular-text" style="width:100%;" />
							</div>
							<div>
								<label style="font-weight:700; display:block; font-size:0.85rem;">Status</label>
								<select id="som_admin_st_status" style="width:100%;">
									<option value="active">Active</option>
									<option value="inactive">Inactive</option>
								</select>
							</div>
						</div>

						<div style="display:flex; justify-content:flex-end; gap:10px;">
							<button type="button" class="button" onclick="document.getElementById('som_admin_add_modal').style.display='none';">Cancel</button>
							<button type="submit" id="som_admin_btn_save_standalone" class="button button-primary">Save Standalone Product to Shop</button>
						</div>
					</form>
				</div>
			</div>
		</div>

		<!-- ADMIN MODAL 3: Edit Shop Product -->
		<div id="som_admin_edit_modal" class="som-modal-overlay" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:99999; align-items:center; justify-content:center;">
			<div style="background:#fff; width:100%; max-width:600px; border-radius:8px; padding:20px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
				<div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:16px;">
					<h3 style="margin:0;">&#9998; <?php esc_html_e( 'Edit Shop Catalog Entry', 'shop-onboarding-manager' ); ?></h3>
					<button type="button" class="button-link" onclick="document.getElementById('som_admin_edit_modal').style.display='none';">&times;</button>
				</div>

				<form id="som_admin_form_edit">
					<input type="hidden" id="som_admin_edit_id" value="" />

					<div id="som_admin_edit_master_specs" style="background:#f8fafc; border:1px solid #e2e8f0; padding:10px 14px; border-radius:6px; margin-bottom:14px; display:none;">
						<strong id="som_admin_edit_title" style="font-size:1.05rem; color:#0f172a; display:block;"></strong>
						<span id="som_admin_edit_meta" style="font-size:0.8rem; color:#64748b;"></span>
					</div>

					<div id="som_admin_edit_standalone_specs" style="background:#f0fdf4; border:1px solid #bbf7d0; padding:12px; border-radius:6px; margin-bottom:14px; display:none;">
						<div style="margin-bottom:10px;">
							<label style="font-weight:700; display:block; font-size:0.85rem;">Product Name *</label>
							<input type="text" id="som_admin_edit_custom_name" class="regular-text" style="width:100%;" />
						</div>
						<div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px;">
							<div>
								<label style="font-weight:700; display:block; font-size:0.85rem;">Category</label>
								<input type="text" id="som_admin_edit_custom_category" class="regular-text" style="width:100%;" />
							</div>
							<div>
								<label style="font-weight:700; display:block; font-size:0.85rem;">Brand</label>
								<input type="text" id="som_admin_edit_custom_brand" class="regular-text" style="width:100%;" />
							</div>
						</div>
					</div>

					<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
						<div>
							<label style="font-weight:700; display:block; font-size:0.85rem;">Shop Price (₹) *</label>
							<input type="number" step="0.01" id="som_admin_edit_price" class="regular-text" style="width:100%;" required />
						</div>
						<div>
							<label style="font-weight:700; display:block; font-size:0.85rem;">Sale Price (₹)</label>
							<input type="number" step="0.01" id="som_admin_edit_sale_price" class="regular-text" style="width:100%;" />
						</div>
					</div>

					<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
						<div>
							<label style="font-weight:700; display:block; font-size:0.85rem;">Availability</label>
							<select id="som_admin_edit_stock_status" style="width:100%;">
								<option value="instock">Available</option>
								<option value="outofstock">Unavailable</option>
							</select>
						</div>
						<div>
							<label style="font-weight:700; display:block; font-size:0.85rem;">Stock Qty</label>
							<input type="number" id="som_admin_edit_stock_quantity" class="regular-text" style="width:100%;" />
						</div>
					</div>

					<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px;">
						<div>
							<label style="font-weight:700; display:block; font-size:0.85rem;">Shop SKU</label>
							<input type="text" id="som_admin_edit_shop_sku" class="regular-text" style="width:100%;" />
						</div>
						<div>
							<label style="font-weight:700; display:block; font-size:0.85rem;">Status</label>
							<select id="som_admin_edit_status" style="width:100%;">
								<option value="active">Active</option>
								<option value="inactive">Inactive</option>
							</select>
						</div>
					</div>

					<div style="display:flex; justify-content:flex-end; gap:10px;">
						<button type="button" class="button" onclick="document.getElementById('som_admin_edit_modal').style.display='none';">Cancel</button>
						<button type="submit" id="som_admin_btn_save_edit" class="button button-primary">Update Product</button>
					</div>
				</form>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			var nonce = '<?php echo esc_js( $nonce ); ?>';
			var currentPage = 1;
			var currentShopId = 0;

			function escapeHtml(str) {
				return str ? $('<div>').text(str).html() : '';
			}

			$('#som_admin_shop_select').on('change', function() {
				currentShopId = $(this).val();
				if (currentShopId) {
					$('#som_admin_catalog_container').slideDown();
					$('#som_admin_btn_open_add').prop('disabled', false);
					loadAdminCatalog(1);
				} else {
					$('#som_admin_catalog_container').slideUp();
					$('#som_admin_btn_open_add').prop('disabled', true);
				}
			});

			function loadAdminCatalog(page) {
				if (!currentShopId) return;
				currentPage = page || 1;
				var $tbody = $('#som_admin_catalog_tbody');
				$tbody.html('<tr><td colspan="8" style="text-align:center; padding: 20px;">Loading catalog...</td></tr>');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'som_admin_get_shop_catalog',
						nonce: nonce,
						shop_id: currentShopId,
						search: $('#som_admin_cat_search').val(),
						type_filter: $('#som_admin_type_filter').val(),
						status: $('#som_admin_cat_status').val(),
						stock_status: $('#som_admin_cat_stock').val(),
						page: currentPage
					},
					success: function(res) {
						if (res.success) {
							var items = res.data.items;
							var summary = res.data.summary;

							$('#som_stat_total').text(summary.total);
							$('#som_stat_active').text(summary.active);
							$('#som_stat_outofstock').text(summary.outofstock);

							if (!items || items.length === 0) {
								$tbody.html('<tr><td colspan="8" style="text-align:center; padding: 20px; color:#64748b;">No catalog items found matching your filters.</td></tr>');
								$('#som_admin_pagination_info').text('Showing 0 items');
								$('#som_admin_prev_btn, #som_admin_next_btn').prop('disabled', true);
								return;
							}

							var html = '';
							$.each(items, function(i, item) {
								html += '<tr>';
								html += '<td>';
								if (item.thumb_url) {
									html += '<img src="' + item.thumb_url + '" style="width:36px; height:36px; border-radius:4px; object-fit:cover;" />';
								} else {
									html += '<span style="font-size:1.2rem;">&#128230;</span>';
								}
								html += '</td>';

								html += '<td><strong>' + escapeHtml(item.title) + '</strong>';
								var metaArr = [];
								if (item.brand) metaArr.push('Brand: ' + escapeHtml(item.brand));
								if (item.unit) metaArr.push('Unit: ' + escapeHtml(item.unit));
								if (item.shop_sku) metaArr.push('Shop SKU: ' + escapeHtml(item.shop_sku));
								if (metaArr.length > 0) {
									html += '<br /><span style="font-size:0.78rem; color:#64748b;">' + metaArr.join(' &bull; ') + '</span>';
								}
								html += '</td>';

								// Type Badge
								if (item.is_standalone) {
									html += '<td><span style="font-size:0.75rem; color:#0369a1; background:#e0f2fe; padding:3px 8px; border-radius:10px; font-weight:700;">Unlinked</span></td>';
								} else {
									html += '<td><span style="font-size:0.75rem; color:#15803d; background:#dcfce7; padding:3px 8px; border-radius:10px; font-weight:700;">Master-Linked</span></td>';
								}

								html += '<td>' + escapeHtml(item.category) + '</td>';
								html += '<td>₹' + item.price + (item.sale_price ? ' <del style="color:#94a3b8;">₹' + item.sale_price + '</del>' : '') + '</td>';
								html += '<td>' + (item.stock_status === 'instock' ? '<span style="color:#16a34a; font-weight:700;">Available</span>' : '<span style="color:#dc2626; font-weight:700;">Unavailable</span>') + '</td>';
								html += '<td>' + item.status + '</td>';

								html += '<td style="text-align:right;">';
								if (item.is_standalone) {
									html += '<button type="button" class="button button-small som-admin-btn-link" data-id="' + item.id + '" data-title="' + escapeHtml(item.title) + '" style="margin-right:4px;">&#128279; Link</button>';
								}
								html += '<button type="button" class="button button-small som-admin-btn-edit" data-item=\'' + JSON.stringify(item) + '\' style="margin-right:4px;">Edit</button>';
								html += '<button type="button" class="button button-small button-link-delete som-admin-btn-remove" data-id="' + item.id + '" data-title="' + escapeHtml(item.title) + '">Remove</button>';
								html += '</td>';
								html += '</tr>';
							});

							$tbody.html(html);
							$('#som_admin_pagination_info').text('Page ' + res.data.current_page + ' of ' + res.data.total_pages + ' (' + res.data.total_count + ' items)');
							$('#som_admin_prev_btn').prop('disabled', res.data.current_page <= 1);
							$('#som_admin_next_btn').prop('disabled', res.data.current_page >= res.data.total_pages);
						} else {
							$tbody.html('<tr><td colspan="8" style="text-align:center; color:#dc2626;">' + (res.data.message || 'Error loading catalog') + '</td></tr>');
						}
					}
				});
			}

			var timer = null;
			$('#som_admin_cat_search').on('keyup input', function() {
				clearTimeout(timer);
				timer = setTimeout(function() { loadAdminCatalog(1); }, 400);
			});

			$('#som_admin_type_filter, #som_admin_cat_status, #som_admin_cat_stock').on('change', function() {
				loadAdminCatalog(1);
			});

			$('#som_admin_prev_btn').on('click', function() { if (currentPage > 1) loadAdminCatalog(currentPage - 1); });
			$('#som_admin_next_btn').on('click', function() { loadAdminCatalog(currentPage + 1); });

			// Open Admin Add Modal with Dual Tabs
			$('#som_admin_btn_open_add').on('click', function() {
				if (!currentShopId) return;
				$('#som_admin_tab_btn_master').click();
				$('#som_admin_add_search').val('');
				$('#som_admin_add_results').html('<p style="padding:10px; color:#64748b; margin:0; text-align:center;">Type to search master products...</p>');
				$('#som_admin_form_add').hide();
				$('#som_admin_form_add_standalone')[0].reset();
				$('#som_admin_add_modal').css('display', 'flex');
			});

			// Admin Add Modal Tab Switchers
			$('#som_admin_tab_btn_master').on('click', function() {
				$('#som_admin_tab_btn_standalone').removeClass('button-primary').addClass('button-secondary');
				$(this).removeClass('button-secondary').addClass('button-primary');
				$('#som_admin_tab_content_standalone').hide();
				$('#som_admin_tab_content_master').show();
			});

			$('#som_admin_tab_btn_standalone').on('click', function() {
				$('#som_admin_tab_btn_master').removeClass('button-primary').addClass('button-secondary');
				$(this).removeClass('button-secondary').addClass('button-primary');
				$('#som_admin_tab_content_master').hide();
				$('#som_admin_tab_content_standalone').show();
				$('#som_admin_st_name').focus();
			});

			// Search Master Products in Admin Add Modal
			var addTimer = null;
			$('#som_admin_add_search').on('keyup input', function() {
				clearTimeout(addTimer);
				var q = $(this).val().trim();
				if (q.length < 2) {
					$('#som_admin_form_add').slideUp();
					return;
				}

				addTimer = setTimeout(function() {
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: { action: 'som_admin_search_master_products', nonce: nonce, shop_id: currentShopId, q: q },
						success: function(res) {
							if (res.success && res.data.results && res.data.results.length > 0) {
								var html = '';
								$.each(res.data.results, function(i, m) {
									html += '<div class="som-admin-add-item" data-mp=\'' + JSON.stringify(m) + '\' style="padding:8px 12px; border-bottom:1px solid #f1f5f9; cursor:pointer;">';
									html += '<strong>' + escapeHtml(m.title) + '</strong>';
									if (m.unit) html += ' (' + escapeHtml(m.unit) + ')';
									if (m.suggested_price) html += ' - Suggested: ₹' + m.suggested_price;
									if (m.in_catalog) html += ' <span style="color:#16a34a; font-weight:700;">(Already in Catalog)</span>';
									html += '</div>';
								});
								$('#som_admin_add_results').html(html);
							} else {
								$('#som_admin_add_results').html('<p style="padding:10px; color:#64748b; margin:0; text-align:center;">No master products found.</p>');
							}
						}
					});
				}, 300);
			});

			// Select Master Product in Admin Add Modal
			$(document).on('click', '.som-admin-add-item', function() {
				var mp = $(this).data('mp');
				if (!mp) return;

				if (mp.in_catalog) {
					alert('This product is already in this shop catalog!');
					return;
				}

				$('#som_admin_add_pid').val(mp.product_id);
				$('#som_admin_add_selected_title').text('Selected Master: ' + mp.title + (mp.unit ? ' (' + mp.unit + ')' : ''));
				if (mp.suggested_price) {
					$('#som_admin_add_price').val(mp.suggested_price);
				} else {
					$('#som_admin_add_price').val('');
				}
				$('#som_admin_form_add').slideDown();
			});

			// Save Admin Add Master Product Form
			$('#som_admin_form_add').on('submit', function(e) {
				e.preventDefault();
				var $btn = $('#som_admin_btn_save_add');
				$btn.prop('disabled', true).text('Saving...');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'som_admin_add_shop_product',
						nonce: nonce,
						shop_id: currentShopId,
						product_id: $('#som_admin_add_pid').val(),
						price: $('#som_admin_add_price').val(),
						sale_price: $('#som_admin_add_sale_price').val(),
						stock_status: $('#som_admin_add_stock_status').val(),
						stock_quantity: $('#som_admin_add_stock_quantity').val(),
						shop_sku: $('#som_admin_add_shop_sku').val(),
						status: $('#som_admin_add_status').val()
					},
					success: function(res) {
						$btn.prop('disabled', false).text('Save Master Product to Shop');
						if (res.success) {
							alert(res.data.message || 'Product added successfully!');
							$('#som_admin_add_modal').hide();
							loadAdminCatalog(1);
						} else {
							alert(res.data.message || 'Error adding product.');
						}
					}
				});
			});

			// Save Admin Add Standalone Product Form
			$('#som_admin_form_add_standalone').on('submit', function(e) {
				e.preventDefault();
				var $btn = $('#som_admin_btn_save_standalone');
				$btn.prop('disabled', true).text('Saving...');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'som_admin_add_standalone_product',
						nonce: nonce,
						shop_id: currentShopId,
						custom_name: $('#som_admin_st_name').val(),
						custom_category: $('#som_admin_st_category').val(),
						custom_brand: $('#som_admin_st_brand').val(),
						custom_unit: $('#som_admin_st_unit').val(),
						custom_barcode: $('#som_admin_st_barcode').val(),
						price: $('#som_admin_st_price').val(),
						sale_price: $('#som_admin_st_sale_price').val(),
						stock_status: $('#som_admin_st_stock_status').val(),
						stock_quantity: $('#som_admin_st_stock_quantity').val(),
						shop_sku: $('#som_admin_st_shop_sku').val(),
						status: $('#som_admin_st_status').val()
					},
					success: function(res) {
						$btn.prop('disabled', false).text('Save Standalone Product to Shop');
						if (res.success) {
							alert(res.data.message || 'Standalone product added successfully!');
							$('#som_admin_add_modal').hide();
							loadAdminCatalog(1);
						} else {
							alert(res.data.message || 'Error adding standalone product.');
						}
					}
				});
			});

			// Open Admin Link Modal for Standalone Items
			$(document).on('click', '.som-admin-btn-link', function() {
				var itemId = $(this).data('id');
				var title = $(this).data('title');

				$('#som_admin_link_item_id').val(itemId);
				$('#som_admin_link_target_name').text(title);
				$('#som_admin_link_search').val('');
				$('#som_admin_link_results').html('<p style="padding:10px; color:#64748b; margin:0; text-align:center;">Type to search master products...</p>');
				$('#som_admin_link_selected_wrap').hide();
				$('#som_admin_btn_confirm_link').prop('disabled', true);
				$('#som_admin_link_modal').css('display', 'flex');
			});

			// Admin Search Master Product to Link
			var linkTimer = null;
			$('#som_admin_link_search').on('keyup input', function() {
				clearTimeout(linkTimer);
				var q = $(this).val().trim();
				if (q.length < 2) return;

				linkTimer = setTimeout(function() {
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: { action: 'som_admin_search_master_products', nonce: nonce, shop_id: currentShopId, q: q },
						success: function(res) {
							if (res.success && res.data.results && res.data.results.length > 0) {
								var html = '';
								$.each(res.data.results, function(i, m) {
									html += '<div class="som-admin-link-item" data-mp=\'' + JSON.stringify(m) + '\' style="padding:8px 12px; border-bottom:1px solid #f1f5f9; cursor:pointer;">';
									html += '<strong>' + escapeHtml(m.title) + '</strong>';
									if (m.unit) html += ' (' + escapeHtml(m.unit) + ')';
									html += '</div>';
								});
								$('#som_admin_link_results').html(html);
							} else {
								$('#som_admin_link_results').html('<p style="padding:10px; color:#64748b; margin:0; text-align:center;">No master products found.</p>');
							}
						}
					});
				}, 300);
			});

			// Select Master Product to Link
			$(document).on('click', '.som-admin-link-item', function() {
				var mp = $(this).data('mp');
				if (!mp) return;

				$('#som_admin_link_selected_mpid').val(mp.product_id);
				$('#som_admin_link_selected_title').text('Selected Master: ' + mp.title + (mp.unit ? ' (' + mp.unit + ')' : ''));
				$('#som_admin_link_selected_wrap').show();
				$('#som_admin_btn_confirm_link').prop('disabled', false);
			});

			// Confirm Link
			$('#som_admin_btn_confirm_link').on('click', function() {
				var itemId = $('#som_admin_link_item_id').val();
				var mpid = $('#som_admin_link_selected_mpid').val();
				if (!itemId || !mpid) return;

				var $btn = $(this);
				$btn.prop('disabled', true).text('Linking...');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: { action: 'som_admin_link_standalone_product', nonce: nonce, id: itemId, master_product_id: mpid },
					success: function(res) {
						$btn.prop('disabled', false).html('&#128279; Link Product');
						if (res.success) {
							alert(res.data.message || 'Product linked successfully!');
							$('#som_admin_link_modal').hide();
							loadAdminCatalog(currentPage);
						} else {
							alert(res.data.message || 'Error linking product.');
						}
					}
				});
			});

			// Edit Admin Item
			$(document).on('click', '.som-admin-btn-edit', function() {
				var item = $(this).data('item');
				if (!item) return;

				$('#som_admin_edit_id').val(item.id);

				if (item.is_standalone) {
					$('#som_admin_edit_master_specs').hide();
					$('#som_admin_edit_standalone_specs').show();
					$('#som_admin_edit_custom_name').val(item.title);
					$('#som_admin_edit_custom_category').val(item.category || '');
					$('#som_admin_edit_custom_brand').val(item.brand || '');
				} else {
					$('#som_admin_edit_standalone_specs').hide();
					$('#som_admin_edit_master_specs').show();
					$('#som_admin_edit_title').text(item.title);
					$('#som_admin_edit_meta').text('Category: ' + item.category + ' | Brand: ' + (item.brand || 'N/A'));
				}

				$('#som_admin_edit_price').val(item.price);
				$('#som_admin_edit_sale_price').val(item.sale_price || '');
				$('#som_admin_edit_stock_status').val(item.stock_status);
				$('#som_admin_edit_stock_quantity').val(item.stock_quantity || '');
				$('#som_admin_edit_shop_sku').val(item.shop_sku || '');
				$('#som_admin_edit_status').val(item.status);

				$('#som_admin_edit_modal').css('display', 'flex');
			});

			// Save Admin Edit Form
			$('#som_admin_form_edit').on('submit', function(e) {
				e.preventDefault();
				var postData = {
					action: 'som_admin_update_shop_product',
					nonce: nonce,
					id: $('#som_admin_edit_id').val(),
					price: $('#som_admin_edit_price').val(),
					sale_price: $('#som_admin_edit_sale_price').val(),
					stock_status: $('#som_admin_edit_stock_status').val(),
					stock_quantity: $('#som_admin_edit_stock_quantity').val(),
					shop_sku: $('#som_admin_edit_shop_sku').val(),
					status: $('#som_admin_edit_status').val()
				};

				if ($('#som_admin_edit_standalone_specs').is(':visible')) {
					postData.custom_name = $('#som_admin_edit_custom_name').val();
					postData.custom_category = $('#som_admin_edit_custom_category').val();
					postData.custom_brand = $('#som_admin_edit_custom_brand').val();
				}

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: postData,
					success: function(res) {
						if (res.success) {
							alert(res.data.message || 'Product updated successfully!');
							$('#som_admin_edit_modal').hide();
							loadAdminCatalog(currentPage);
						} else {
							alert(res.data.message || 'Error updating product.');
						}
					}
				});
			});

			// Remove Admin Item
			$(document).on('click', '.som-admin-btn-remove', function() {
				var itemId = $(this).data('id');
				var title = $(this).data('title');
				if (!confirm('Are you sure you want to remove "' + title + '" from this shop catalog?')) return;

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: { action: 'som_admin_remove_shop_product', nonce: nonce, id: itemId },
					success: function(res) {
						if (res.success) {
							alert(res.data.message || 'Product removed successfully!');
							loadAdminCatalog(currentPage);
						} else {
							alert(res.data.message || 'Error removing product.');
						}
					}
				});
			});
		});
		</script>
		<?php
	}
}