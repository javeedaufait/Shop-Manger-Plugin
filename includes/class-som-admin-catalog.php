<?php
/**
 * Admin Catalog Management Module (Phase 7).
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
		add_action( 'wp_ajax_som_admin_update_shop_product', array( __CLASS__, 'ajax_update_shop_product' ) );
		add_action( 'wp_ajax_som_admin_remove_shop_product', array( __CLASS__, 'ajax_remove_shop_product' ) );
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
	 * AJAX endpoint: Get Shop Catalog List for Admin.
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
			$product_id  = $p->product_id;
			$master_post = get_post( $product_id );

			if ( ! $master_post || 'product' !== $master_post->post_type ) {
				continue;
			}

			$title = $master_post->post_title;
			$sku   = get_post_meta( $product_id, '_sku', true );

			if ( ! empty( $search ) ) {
				$match_title = false !== stripos( $title, $search );
				$match_sku   = false !== stripos( $sku, $search );
				$match_ssku  = false !== stripos( (string) $p->shop_sku, $search );

				if ( ! $match_title && ! $match_sku && ! $match_ssku ) {
					continue;
				}
			}

			$cat_terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
			$cat_name  = ! empty( $cat_terms ) ? $cat_terms[0] : __( 'Uncategorized', 'shop-onboarding-manager' );
			$specs     = nearmart_get_master_product_specs( $product_id );
			$thumb_url = get_the_post_thumbnail_url( $product_id, 'thumbnail' );

			$items[] = array(
				'id'             => $p->id,
				'product_id'     => $p->product_id,
				'title'          => $title,
				'category'       => $cat_name,
				'brand'          => $specs['brand_name'],
				'unit'           => $specs['unit'],
				'barcode'        => $specs['barcode'],
				'master_sku'     => $sku,
				'shop_sku'       => $p->shop_sku,
				'price'          => number_format( (float) $p->price, 2, '.', '' ),
				'sale_price'     => null !== $p->sale_price && '' !== $p->sale_price ? number_format( (float) $p->sale_price, 2, '.', '' ) : '',
				'stock_quantity' => $p->stock_quantity,
				'stock_status'   => $p->stock_status,
				'status'         => $p->status,
				'thumb_url'      => $thumb_url ? $thumb_url : '',
			);
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

		$shop_id = isset( $_POST['shop_id'] ) ? absint( $_POST['shop_id'] ) : 0;
		$query   = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';

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

		if ( ! $shop_id || ! in_array( get_post_type( $shop_id ), array( 'shop', 'shop_onboarding' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid shop selected.', 'shop-onboarding-manager' ) ) );
		}

		if ( ! $product_id || get_post_type( $product_id ) !== 'product' || get_post_status( $product_id ) !== 'publish' ) {
			wp_send_json_error( array( 'message' => __( 'Invalid or inactive master product selected.', 'shop-onboarding-manager' ) ) );
		}

		if ( nearmart_has_shop_product( $shop_id, $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This product is already in this shop\'s catalog.', 'shop-onboarding-manager' ) ) );
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

		wp_send_json_success( array( 'message' => __( 'Product added to shop catalog successfully!', 'shop-onboarding-manager' ) ) );
	}

	/**
	 * AJAX endpoint: Update Shop Product by Admin.
	 */
	public static function ajax_update_shop_product() {
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

		if ( ! $shop_id || ! $product_id || ! nearmart_has_shop_product( $shop_id, $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Product entry not found in this shop\'s catalog.', 'shop-onboarding-manager' ) ) );
		}

		$result = nearmart_update_shop_product(
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
			wp_send_json_error( array( 'message' => __( 'Failed to update shop product.', 'shop-onboarding-manager' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Shop product updated successfully!', 'shop-onboarding-manager' ) ) );
	}

	/**
	 * AJAX endpoint: Remove Product from Shop Catalog by Admin.
	 */
	public static function ajax_remove_shop_product() {
		check_ajax_referer( 'som_admin_catalog_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ), 403 );
		}

		$shop_id    = isset( $_POST['shop_id'] ) ? absint( $_POST['shop_id'] ) : 0;
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		if ( ! $shop_id || ! $product_id || ! nearmart_has_shop_product( $shop_id, $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Product entry not found in this shop\'s catalog.', 'shop-onboarding-manager' ) ) );
		}

		$result = nearmart_remove_shop_product( $shop_id, $product_id );

		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to remove product from catalog.', 'shop-onboarding-manager' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Product removed from shop catalog.', 'shop-onboarding-manager' ) ) );
	}

	/**
	 * Render [som-admin-catalog] Admin Submenu Page.
	 */
	
	/**
	 * Enqueue styles and scripts for Admin Catalog page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_admin_assets( $hook ) {
		if ( false === strpos( $hook, 'som-admin-catalog' ) ) {
			return;
		}
		wp_enqueue_script( 'jquery' );
		wp_enqueue_style( 'som-frontend-style', SOM_PLUGIN_URL . 'assets/css/som-frontend.css', array(), SOM_VERSION );
	}

	public static function render_admin_catalog_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'shop-onboarding-manager' ) );
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

		$selected_shop_id = isset( $_GET['shop_id'] ) ? absint( $_GET['shop_id'] ) : ( ! empty( $shops ) ? $shops[0]->ID : 0 );
		$nonce            = wp_create_nonce( 'som_admin_catalog_nonce' );

		?>
		<div class="wrap som-admin-wrap" style="max-width: 1200px; margin-top: 20px;">
			<h1 class="wp-heading-inline" style="font-size: 1.8rem; font-weight: 800; color: #0f172a; margin-bottom: 16px;">
				&#127978; <?php esc_html_e( 'Shop Catalog Management', 'shop-onboarding-manager' ); ?>
			</h1>
			<p style="color: #64748b; font-size: 0.95rem; margin-top: 4px; margin-bottom: 24px;">
				<?php esc_html_e( 'Select any onboarded shop below to manage its active listings, shop prices, stock availability, and catalog entries.', 'shop-onboarding-manager' ); ?>
			</p>

			<!-- Shop Selector Card -->
			<div class="som-admin-card" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 24px;">
				<div style="display: flex; gap: 16px; align-items: center; justify-content: space-between; flex-wrap: wrap;">
					<div style="display: flex; gap: 12px; align-items: center; flex: 1; min-width: 280px;">
						<label for="som_admin_shop_select" style="font-weight: 700; color: #1e293b; font-size: 1.05rem; white-space: nowrap;">
							&#127978; <?php esc_html_e( 'Select Shop:', 'shop-onboarding-manager' ); ?>
						</label>
						<select id="som_admin_shop_select" class="regular-text" style="font-size: 1rem; padding: 6px 12px; border-radius: 8px; border: 1px solid #cbd5e1; width: 100%; max-width: 380px;">
							<?php if ( empty( $shops ) ) : ?>
								<option value=""><?php esc_html_e( 'No onboarded shops found', 'shop-onboarding-manager' ); ?></option>
							<?php else : ?>
								<?php foreach ( $shops as $s ) : ?>
									<option value="<?php echo esc_attr( $s->ID ); ?>" <?php selected( $selected_shop_id, $s->ID ); ?>>
										<?php echo esc_html( $s->post_title ); ?> (ID: <?php echo esc_html( $s->ID ); ?>)
									</option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
					</div>

					<button type="button" id="som_admin_btn_open_add" class="button button-primary button-hero" style="background: #16a34a; border-color: #16a34a; font-weight: 700; border-radius: 8px; font-size: 0.95rem; height: 42px; line-height: 40px; padding: 0 18px;" <?php echo empty( $shops ) ? 'disabled' : ''; ?>>
						&#10133; <?php esc_html_e( 'Add Master Product to Shop Catalog', 'shop-onboarding-manager' ); ?>
					</button>
				</div>

				<!-- Selected Shop Metric Summary -->
				<div id="som_admin_shop_summary_bar" class="som-cat-summary-grid" style="margin-top: 20px; border-top: 1px solid #f1f5f9; padding-top: 18px;">
					<div class="som-summary-card">
						<div class="som-summary-icon">&#128230;</div>
						<div class="som-summary-info">
							<span id="som_sum_total" class="som-summary-val">0</span>
							<span class="som-summary-lbl"><?php esc_html_e( 'Total Products', 'shop-onboarding-manager' ); ?></span>
						</div>
					</div>

					<div class="som-summary-card success">
						<div class="som-summary-icon">&#10003;</div>
						<div class="som-summary-info">
							<span id="som_sum_active" class="som-summary-val">0</span>
							<span class="som-summary-lbl"><?php esc_html_e( 'Active Listings', 'shop-onboarding-manager' ); ?></span>
						</div>
					</div>

					<div class="som-summary-card warning">
						<div class="som-summary-icon">&#9888;</div>
						<div class="som-summary-info">
							<span id="som_sum_outofstock" class="som-summary-val">0</span>
							<span class="som-summary-lbl"><?php esc_html_e( 'Out of Stock', 'shop-onboarding-manager' ); ?></span>
						</div>
					</div>
				</div>
			</div>

			<!-- Main Catalog Table Card -->
			<div class="som-admin-card" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
				<!-- Search & Filter Controls -->
				<div class="som-catalog-bar" style="margin-bottom: 18px;">
					<div class="som-catalog-search-wrap">
						<input type="text" id="som_admin_cat_search" class="regular-text" placeholder="Search shop catalog by name or SKU..." style="font-size: 0.95rem; height: 40px; border-radius: 6px; padding: 0 12px; width: 100%;" />
					</div>
					<div class="som-catalog-filters" style="display: flex; gap: 10px;">
						<select id="som_admin_cat_status_filter" class="postform" style="height: 40px; border-radius: 6px;">
							<option value="all"><?php esc_html_e( 'All Statuses', 'shop-onboarding-manager' ); ?></option>
							<option value="active"><?php esc_html_e( 'Active', 'shop-onboarding-manager' ); ?></option>
							<option value="inactive"><?php esc_html_e( 'Inactive', 'shop-onboarding-manager' ); ?></option>
						</select>
						<select id="som_admin_cat_stock_filter" class="postform" style="height: 40px; border-radius: 6px;">
							<option value="all"><?php esc_html_e( 'All Availability', 'shop-onboarding-manager' ); ?></option>
							<option value="instock"><?php esc_html_e( 'Available', 'shop-onboarding-manager' ); ?></option>
							<option value="outofstock"><?php esc_html_e( 'Unavailable', 'shop-onboarding-manager' ); ?></option>
						</select>
					</div>
				</div>

				<!-- Catalog Table Wrap -->
				<div class="som-catalog-table-wrap">
					<table class="wp-list-table widefat fixed striped table-view-list" style="border: 1px solid #e2e8f0; border-radius: 8px;">
						<thead>
							<tr>
								<th style="width: 60px;"><?php esc_html_e( 'Image', 'shop-onboarding-manager' ); ?></th>
								<th><?php esc_html_e( 'Product Name & Specs', 'shop-onboarding-manager' ); ?></th>
								<th><?php esc_html_e( 'Category', 'shop-onboarding-manager' ); ?></th>
								<th><?php esc_html_e( 'Shop Price', 'shop-onboarding-manager' ); ?></th>
								<th><?php esc_html_e( 'Availability', 'shop-onboarding-manager' ); ?></th>
								<th><?php esc_html_e( 'Status', 'shop-onboarding-manager' ); ?></th>
								<th style="width: 120px; text-align: right;"><?php esc_html_e( 'Actions', 'shop-onboarding-manager' ); ?></th>
							</tr>
						</thead>
						<tbody id="som_admin_catalog_tbody">
							<tr>
								<td colspan="7" style="text-align: center; padding: 24px; color: #64748b;">
									&#128259; <?php esc_html_e( 'Loading shop catalog...', 'shop-onboarding-manager' ); ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Pagination Bar -->
				<div class="som-catalog-pagination" style="margin-top: 16px;">
					<span id="som_admin_catalog_info">Showing 0 items</span>
					<div class="som-pagination-btns">
						<button type="button" id="som_admin_cat_prev_btn" class="button" disabled>&larr; Previous</button>
						<button type="button" id="som_admin_cat_next_btn" class="button" disabled>Next &rarr;</button>
					</div>
				</div>
			</div>
		</div>

		<!-- MODAL 1: Admin Add Product to Shop Catalog Modal -->
		<div id="som_admin_add_modal" class="som-modal-overlay" style="display: none;">
			<div class="som-modal-content">
				<div class="som-modal-header">
					<h3>&#10133; <?php esc_html_e( 'Add Master Product to Shop Catalog', 'shop-onboarding-manager' ); ?></h3>
					<button type="button" class="som-modal-close" onclick="document.getElementById('som_admin_add_modal').style.display='none';">&times;</button>
				</div>

				<div class="som-form-group">
					<label for="som_admin_master_search" class="som-label"><?php esc_html_e( '1. Search Master Product (Name, SKU, or Barcode)', 'shop-onboarding-manager' ); ?></label>
					<input type="text" id="som_admin_master_search" class="som-input" placeholder="<?php esc_attr_e( 'Type at least 2 characters to search...', 'shop-onboarding-manager' ); ?>" />
					<div id="som_admin_master_results" class="som-master-search-results"></div>
				</div>

				<form id="som_admin_form_add_product" style="display: none; border-top: 1px solid #e2e8f0; padding-top: 16px;">
					<input type="hidden" id="som_admin_add_product_id" name="product_id" value="" />
					<p style="font-weight: 700; color: #16a34a; margin-bottom: 12px;" id="som_admin_add_selected_title"></p>

					<div class="som-form-row">
						<div class="som-form-group">
							<label for="som_admin_add_price" class="som-label required"><?php esc_html_e( 'Shop Price (₹)', 'shop-onboarding-manager' ); ?></label>
							<input type="number" step="0.01" id="som_admin_add_price" name="price" class="som-input" required placeholder="0.00" />
						</div>
						<div class="som-form-group">
							<label for="som_admin_add_sale_price" class="som-label"><?php esc_html_e( 'Sale Price (₹)', 'shop-onboarding-manager' ); ?></label>
							<input type="number" step="0.01" id="som_admin_add_sale_price" name="sale_price" class="som-input" placeholder="Optional" />
						</div>
					</div>

					<div class="som-form-row">
						<div class="som-form-group">
							<label for="som_admin_add_stock_status" class="som-label"><?php esc_html_e( 'Availability', 'shop-onboarding-manager' ); ?></label>
							<select id="som_admin_add_stock_status" name="stock_status" class="som-select">
								<option value="instock"><?php esc_html_e( 'Available', 'shop-onboarding-manager' ); ?></option>
								<option value="outofstock"><?php esc_html_e( 'Unavailable', 'shop-onboarding-manager' ); ?></option>
							</select>
						</div>
						<div class="som-form-group">
							<label for="som_admin_add_stock_quantity" class="som-label"><?php esc_html_e( 'Stock Qty', 'shop-onboarding-manager' ); ?></label>
							<input type="number" id="som_admin_add_stock_quantity" name="stock_quantity" class="som-input" placeholder="Optional" />
						</div>
					</div>

					<div class="som-form-row">
						<div class="som-form-group">
							<label for="som_admin_add_shop_sku" class="som-label"><?php esc_html_e( 'Shop SKU', 'shop-onboarding-manager' ); ?></label>
							<input type="text" id="som_admin_add_shop_sku" name="shop_sku" class="som-input" placeholder="e.g. STORE-ITEM-01 (Optional)" />
						</div>
						<div class="som-form-group">
							<label for="som_admin_add_status" class="som-label"><?php esc_html_e( 'Listing Status', 'shop-onboarding-manager' ); ?></label>
							<select id="som_admin_add_status" name="status" class="som-select">
								<option value="active"><?php esc_html_e( 'Active (Visible to customers)', 'shop-onboarding-manager' ); ?></option>
								<option value="inactive"><?php esc_html_e( 'Inactive (Hidden)', 'shop-onboarding-manager' ); ?></option>
							</select>
						</div>
					</div>

					<button type="submit" id="som_admin_btn_save_add" class="button button-primary button-large" style="width: 100%; margin-top: 10px; background: #16a34a; border-color: #16a34a;">
						&#128190; <?php esc_html_e( 'Save Product to Shop Catalog', 'shop-onboarding-manager' ); ?>
					</button>
				</form>
			</div>
		</div>

		<!-- MODAL 2: Admin Edit Shop Product Modal -->
		<div id="som_admin_edit_modal" class="som-modal-overlay" style="display: none;">
			<div class="som-modal-content">
				<div class="som-modal-header">
					<h3>&#9998; <?php esc_html_e( 'Edit Shop Product Data', 'shop-onboarding-manager' ); ?></h3>
					<button type="button" class="som-modal-close" onclick="document.getElementById('som_admin_edit_modal').style.display='none';">&times;</button>
				</div>

				<!-- Read-Only Master Product Specs Box -->
				<div id="som_admin_edit_master_specs_box" class="som-master-specs-box" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; margin-bottom:16px;">
					<div style="display:flex; gap:12px; align-items:center;">
						<div id="som_admin_edit_thumb_wrap">
							<span style="font-size:1.5rem;">&#128230;</span>
						</div>
						<div>
							<strong id="som_admin_edit_master_title" style="font-size:1.05rem; color:#0f172a; display:block;"></strong>
							<div id="som_admin_edit_master_meta" style="font-size:0.8rem; color:#64748b; margin-top:2px;"></div>
						</div>
					</div>
					<p style="font-size:0.75rem; color:#94a3b8; margin:8px 0 0 0; font-style:italic;">
						&#8505; <?php esc_html_e( 'Master product specifications (Name, Category, Image, Barcode) are managed by platform admins in WooCommerce Products and cannot be changed here.', 'shop-onboarding-manager' ); ?>
					</p>
				</div>

				<form id="som_admin_form_edit_product">
					<input type="hidden" id="som_admin_edit_product_id" name="product_id" value="" />

					<div class="som-form-row">
						<div class="som-form-group">
							<label for="som_admin_edit_price" class="som-label required"><?php esc_html_e( 'Shop Price (₹)', 'shop-onboarding-manager' ); ?></label>
							<input type="number" step="0.01" id="som_admin_edit_price" name="price" class="som-input" required />
						</div>
						<div class="som-form-group">
							<label for="som_admin_edit_sale_price" class="som-label"><?php esc_html_e( 'Sale Price (₹)', 'shop-onboarding-manager' ); ?></label>
							<input type="number" step="0.01" id="som_admin_edit_sale_price" name="sale_price" class="som-input" placeholder="Optional" />
						</div>
					</div>

					<div class="som-form-row">
						<div class="som-form-group">
							<label for="som_admin_edit_stock_status" class="som-label"><?php esc_html_e( 'Availability', 'shop-onboarding-manager' ); ?></label>
							<select id="som_admin_edit_stock_status" name="stock_status" class="som-select">
								<option value="instock"><?php esc_html_e( 'Available', 'shop-onboarding-manager' ); ?></option>
								<option value="outofstock"><?php esc_html_e( 'Unavailable', 'shop-onboarding-manager' ); ?></option>
							</select>
						</div>
						<div class="som-form-group">
							<label for="som_admin_edit_stock_quantity" class="som-label"><?php esc_html_e( 'Stock Qty', 'shop-onboarding-manager' ); ?></label>
							<input type="number" id="som_admin_edit_stock_quantity" name="stock_quantity" class="som-input" placeholder="Optional" />
						</div>
					</div>

					<div class="som-form-row">
						<div class="som-form-group">
							<label for="som_admin_edit_shop_sku" class="som-label"><?php esc_html_e( 'Shop SKU', 'shop-onboarding-manager' ); ?></label>
							<input type="text" id="som_admin_edit_shop_sku" name="shop_sku" class="som-input" placeholder="e.g. STORE-ITEM-01 (Optional)" />
						</div>
						<div class="som-form-group">
							<label for="som_admin_edit_status" class="som-label"><?php esc_html_e( 'Listing Status', 'shop-onboarding-manager' ); ?></label>
							<select id="som_admin_edit_status" name="status" class="som-select">
								<option value="active"><?php esc_html_e( 'Active', 'shop-onboarding-manager' ); ?></option>
								<option value="inactive"><?php esc_html_e( 'Inactive', 'shop-onboarding-manager' ); ?></option>
							</select>
						</div>
					</div>

					<button type="submit" id="som_admin_btn_save_edit" class="button button-primary button-large" style="width: 100%; margin-top: 10px;">
						&#128190; <?php esc_html_e( 'Update Shop Product Data', 'shop-onboarding-manager' ); ?>
					</button>
				</form>
			</div>
		</div>

		<!-- Admin Catalog JavaScript Handler -->
		<script>
		if (typeof jQuery !== 'undefined') {
			jQuery(document).ready(function($) {
				var nonce = '<?php echo esc_js( $nonce ); ?>';
				var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
				var currentPage = 1;

				function getSelectedShopId() {
					return $('#som_admin_shop_select').val();
				}

				function escapeHtml(str) {
					return str ? $('<div>').text(str).html() : '';
				}

				function loadAdminCatalog(page) {
					var shopId = getSelectedShopId();
					if (!shopId) {
						$('#som_admin_catalog_tbody').html('<tr><td colspan="7" style="text-align:center; padding: 24px; color:#64748b;">Please select a shop above.</td></tr>');
						return;
					}

					currentPage = page || 1;
					var $tbody = $('#som_admin_catalog_tbody');
					$tbody.html('<tr><td colspan="7" style="text-align:center; padding: 20px; color:#64748b;">&#128259; Loading shop catalog...</td></tr>');

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: {
							action: 'som_admin_get_shop_catalog',
							nonce: nonce,
							shop_id: shopId,
							search: $('#som_admin_cat_search').val(),
							status: $('#som_admin_cat_status_filter').val(),
							stock_status: $('#som_admin_cat_stock_filter').val(),
							page: currentPage
						},
						success: function(res) {
							if (res.success) {
								if (res.data.summary) {
									$('#som_sum_total').text(res.data.summary.total);
									$('#som_sum_active').text(res.data.summary.active);
									$('#som_sum_outofstock').text(res.data.summary.outofstock);
								}

								var items = res.data.items;
								if (!items || items.length === 0) {
									$tbody.html('<tr><td colspan="7" style="text-align:center; padding: 24px; color:#64748b;">No products in this shop catalog yet. Click <strong>"Add Master Product to Shop Catalog"</strong> to add items!</td></tr>');
									$('#som_admin_catalog_info').text('Showing 0 items');
									$('#som_admin_cat_prev_btn, #som_admin_cat_next_btn').prop('disabled', true);
									return;
								}

								var html = '';
								$.each(items, function(i, item) {
									html += '<tr data-product-id="' + item.product_id + '">';
									html += '<td><div class="som-cat-thumb-box">';
									if (item.thumb_url) {
										html += '<img src="' + item.thumb_url + '" alt="' + escapeHtml(item.title) + '" style="width:40px; height:40px; border-radius:6px; object-fit:cover;" />';
									} else {
										html += '<span class="som-cat-placeholder">&#128230;</span>';
									}
									html += '</div></td>';

									html += '<td class="som-cat-product-info">';
									html += '<strong>' + escapeHtml(item.title) + '</strong>';
									var metaArr = [];
									if (item.brand) metaArr.push('Brand: ' + escapeHtml(item.brand));
									if (item.unit) metaArr.push('Unit: ' + escapeHtml(item.unit));
									if (item.shop_sku) metaArr.push('Shop SKU: ' + escapeHtml(item.shop_sku));
									if (metaArr.length > 0) {
										html += '<br /><span style="font-size:0.75rem; color:#64748b;">' + metaArr.join(' &bull; ') + '</span>';
									}
									html += '</td>';

									html += '<td><span class="som-cat-meta-tag">' + escapeHtml(item.category) + '</span></td>';

									html += '<td><span class="som-cat-price">';
									if (item.sale_price) {
										html += '<del>₹' + item.price + '</del> ₹' + item.sale_price;
									} else {
										html += '₹' + item.price;
									}
									html += '</span></td>';

									var availLabel = item.stock_status === 'instock' ? 'Available' : 'Unavailable';
									html += '<td><span class="som-cat-badge ' + item.stock_status + '">' + availLabel + '</span></td>';
									html += '<td><span class="som-cat-badge ' + item.status + '">' + item.status + '</span></td>';

									html += '<td><div class="som-cat-actions" style="display:flex; gap:6px; justify-content:flex-end;">';
									html += '<button type="button" class="button button-small som-admin-btn-edit" data-item=\'' + JSON.stringify(item) + '\'>&#9998; Edit</button>';
									html += '<button type="button" class="button button-small button-link-delete som-admin-btn-remove" data-id="' + item.product_id + '" data-title="' + escapeHtml(item.title) + '">&#128465;</button>';
									html += '</div></td>';
									html += '</tr>';
								});

								$tbody.html(html);

								$('#som_admin_catalog_info').text('Page ' + res.data.current_page + ' of ' + res.data.total_pages + ' (' + res.data.total_count + ' items)');
								$('#som_admin_cat_prev_btn').prop('disabled', res.data.current_page <= 1);
								$('#som_admin_cat_next_btn').prop('disabled', res.data.current_page >= res.data.total_pages);
							} else {
								$tbody.html('<tr><td colspan="7" style="text-align:center; color:#ef4444;">' + (res.data.message || 'Error loading catalog') + '</td></tr>');
							}
						}
					});
				}

				// Shop Selector Change
				$('#som_admin_shop_select').on('change', function() {
					loadAdminCatalog(1);
				});

				loadAdminCatalog(1);

				// Filters & Search
				var searchTimer = null;
				$('#som_admin_cat_search').on('keyup input', function() {
					clearTimeout(searchTimer);
					searchTimer = setTimeout(function() { loadAdminCatalog(1); }, 400);
				});

				$('#som_admin_cat_status_filter, #som_admin_cat_stock_filter').on('change', function() {
					loadAdminCatalog(1);
				});

				$('#som_admin_cat_prev_btn').on('click', function() { if (currentPage > 1) loadAdminCatalog(currentPage - 1); });
				$('#som_admin_cat_next_btn').on('click', function() { loadAdminCatalog(currentPage + 1); });

				// Open Add Modal
				$('#som_admin_btn_open_add').on('click', function() {
					$('#som_admin_add_modal').show();
					$('#som_admin_master_search').val('').focus();
					$('#som_admin_form_add_product').hide();
					$('#som_admin_master_results').html('<p style="padding:14px; text-align:center; color:#64748b; margin:0;">Type at least 2 characters to search master products by name, SKU, or barcode...</p>');
				});

				// Type-ahead Search
				var masterTimer = null;
				$('#som_admin_master_search').on('keyup input', function() {
					clearTimeout(masterTimer);
					var q = $(this).val().trim();

					if (q.length < 2) {
						$('#som_admin_form_add_product').slideUp();
						$('#som_admin_master_results').html('<p style="padding:14px; text-align:center; color:#64748b; margin:0;">Type at least 2 characters to search master products by name, SKU, or barcode...</p>');
						return;
					}

					masterTimer = setTimeout(function() {
						performAdminMasterSearch(q);
					}, 300);
				});

				function performAdminMasterSearch(queryStr) {
					if (!queryStr || queryStr.length < 2) return;
					$('#som_admin_master_results').html('<p style="padding:14px; color:#64748b; margin:0; text-align:center;">&#128259; Searching master products...</p>');

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: { action: 'som_admin_search_master_products', nonce: nonce, shop_id: getSelectedShopId(), q: queryStr },
						success: function(res) {
							if (res.success && res.data.results && res.data.results.length > 0) {
								var html = '';
								$.each(res.data.results, function(i, m) {
									html += '<div class="som-master-item ' + (m.in_catalog ? 'in-catalog-disabled' : '') + '" data-master=\'' + JSON.stringify(m) + '\'>';
									html += '<div class="som-master-item-info">';
									if (m.thumb_url) {
										html += '<img src="' + m.thumb_url + '" style="width:42px; height:42px; border-radius:6px; object-fit:cover;" />';
									} else {
										html += '<span style="font-size:1.4rem;">&#128230;</span>';
									}
									html += '<div>';
									html += '<strong>' + escapeHtml(m.title) + '</strong>';
									var metaArr = [];
									if (m.category) metaArr.push('Cat: ' + escapeHtml(m.category));
									if (m.brand) metaArr.push('Brand: ' + escapeHtml(m.brand));
									if (m.unit) metaArr.push('Unit: ' + escapeHtml(m.unit));
									if (m.sku) metaArr.push('SKU: ' + escapeHtml(m.sku));
									if (m.barcode) metaArr.push('Barcode: ' + escapeHtml(m.barcode));
									html += '<br /><span style="font-size:0.75rem; color:#64748b;">' + metaArr.join(' &bull; ') + '</span>';
									if (m.suggested_price) {
										html += '<br /><span style="font-size:0.8rem; color:#16a34a; font-weight:600;">Suggested Price: ₹' + m.suggested_price + '</span>';
									}
									html += '</div></div>';

									if (m.in_catalog) {
										html += '<span class="som-badge-in-catalog" style="font-size:0.8rem; color:#16a34a; background:#dcfce7; padding:4px 10px; border-radius:12px; font-weight:700;">&#10003; Already in shop catalog</span>';
									} else {
										html += '<button type="button" class="button button-small">Select</button>';
									}
									html += '</div>';
								});
								$('#som_admin_master_results').html(html);
							} else {
								$('#som_admin_master_results').html('<p style="padding:14px; color:#64748b; margin:0; text-align:center;">No master products found matching your search.</p>');
							}
						},
						error: function() {
							$('#som_admin_master_results').html('<p style="padding:14px; color:#ef4444; margin:0; text-align:center;">Failed to search products. Please try again.</p>');
						}
					});
				}

				// Select Master Product
				$(document).on('click', '#som_admin_master_results .som-master-item', function() {
					var m = $(this).data('master');
					if (!m) return;

					if (m.in_catalog) {
						alert('This product is already in this shop catalog!');
						return;
					}

					$('#som_admin_master_results .som-master-item').removeClass('selected');
					$(this).addClass('selected');

					$('#som_admin_add_product_id').val(m.product_id);
					$('#som_admin_add_selected_title').html('&#10003; Selected Product: ' + escapeHtml(m.title) + (m.unit ? ' (' + escapeHtml(m.unit) + ')' : ''));

					if (m.suggested_price) {
						$('#som_admin_add_price').val(m.suggested_price);
					} else {
						$('#som_admin_add_price').val('');
					}
					$('#som_admin_add_sale_price').val('');
					$('#som_admin_add_stock_quantity').val('');
					$('#som_admin_add_shop_sku').val('');

					$('#som_admin_form_add_product').slideDown();
				});

				// Submit Add Product Form
				$('#som_admin_form_add_product').on('submit', function(e) {
					e.preventDefault();
					var $btn = $('#som_admin_btn_save_add');
					$btn.prop('disabled', true).text('Saving...');

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: {
							action: 'som_admin_add_shop_product',
							nonce: nonce,
							shop_id: getSelectedShopId(),
							product_id: $('#som_admin_add_product_id').val(),
							price: $('#som_admin_add_price').val(),
							sale_price: $('#som_admin_add_sale_price').val(),
							stock_status: $('#som_admin_add_stock_status').val(),
							stock_quantity: $('#som_admin_add_stock_quantity').val(),
							shop_sku: $('#som_admin_add_shop_sku').val(),
							status: $('#som_admin_add_status').val()
						},
						success: function(res) {
							$btn.prop('disabled', false).html('&#128190; Save Product to Shop Catalog');
							if (res.success) {
								alert(res.data.message || 'Product added to shop catalog successfully!');
								$('#som_admin_add_modal').hide();
								$('#som_admin_form_add_product')[0].reset();
								$('#som_admin_form_add_product').hide();
								loadAdminCatalog(1);
							} else {
								alert(res.data.message || 'Error adding product.');
							}
						},
						error: function() {
							$btn.prop('disabled', false).html('&#128190; Save Product to Shop Catalog');
							alert('Server error adding product. Please try again.');
						}
					});
				});

				// Open Edit Modal
				$(document).on('click', '.som-admin-btn-edit', function() {
					var item = $(this).data('item');
					if (!item) return;

					$('#som_admin_edit_product_id').val(item.product_id);
					$('#som_admin_edit_master_title').text(item.title);

					var masterMeta = [];
					if (item.category) masterMeta.push('Cat: ' + escapeHtml(item.category));
					if (item.brand) masterMeta.push('Brand: ' + escapeHtml(item.brand));
					if (item.unit) masterMeta.push('Unit: ' + escapeHtml(item.unit));
					if (item.barcode) masterMeta.push('Barcode: ' + escapeHtml(item.barcode));
					if (item.master_sku) masterMeta.push('Master SKU: ' + escapeHtml(item.master_sku));
					$('#som_admin_edit_master_meta').html(masterMeta.join(' &bull; '));

					if (item.thumb_url) {
						$('#som_admin_edit_thumb_wrap').html('<img src="' + item.thumb_url + '" style="width:44px; height:44px; border-radius:6px; object-fit:cover;" />');
					} else {
						$('#som_admin_edit_thumb_wrap').html('<span style="font-size:1.5rem;">&#128230;</span>');
					}

					$('#som_admin_edit_price').val(item.price);
					$('#som_admin_edit_sale_price').val(item.sale_price);
					$('#som_admin_edit_stock_status').val(item.stock_status);
					$('#som_admin_edit_stock_quantity').val(item.stock_quantity);
					$('#som_admin_edit_shop_sku').val(item.shop_sku || '');
					$('#som_admin_edit_status').val(item.status);

					$('#som_admin_edit_modal').show();
				});

				// Submit Edit Form
				$('#som_admin_form_edit_product').on('submit', function(e) {
					e.preventDefault();
					var $btn = $('#som_admin_btn_save_edit');
					$btn.prop('disabled', true).text('Updating...');

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: {
							action: 'som_admin_update_shop_product',
							nonce: nonce,
							shop_id: getSelectedShopId(),
							product_id: $('#som_admin_edit_product_id').val(),
							price: $('#som_admin_edit_price').val(),
							sale_price: $('#som_admin_edit_sale_price').val(),
							stock_status: $('#som_admin_edit_stock_status').val(),
							stock_quantity: $('#som_admin_edit_stock_quantity').val(),
							shop_sku: $('#som_admin_edit_shop_sku').val(),
							status: $('#som_admin_edit_status').val()
						},
						success: function(res) {
							$btn.prop('disabled', false).html('&#128190; Update Shop Product Data');
							if (res.success) {
								alert(res.data.message || 'Product updated successfully!');
								$('#som_admin_edit_modal').hide();
								loadAdminCatalog(currentPage);
							} else {
								alert(res.data.message || 'Error updating product.');
							}
						},
						error: function() {
							$btn.prop('disabled', false).html('&#128190; Update Shop Product Data');
							alert('Server error updating product. Please try again.');
						}
					});
				});

				// Remove Product Action
				$(document).on('click', '.som-admin-btn-remove', function() {
					var pid = $(this).data('id');
					var title = $(this).data('title') || 'this product';
					var shopName = $('#som_admin_shop_select option:selected').text();

					var msg = 'Are you sure you want to remove "' + title + '" from ' + shopName + ' catalog?\n\n' +
						'This will only remove the item from this shop. The WooCommerce master product will not be deleted.';

					if (!confirm(msg)) return;

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: {
							action: 'som_admin_remove_shop_product',
							nonce: nonce,
							shop_id: getSelectedShopId(),
							product_id: pid
						},
						success: function(res) {
							if (res.success) {
								alert(res.data.message || 'Product removed from shop catalog.');
								loadAdminCatalog(currentPage);
							} else {
								alert(res.data.message || 'Error removing product.');
							}
						},
						error: function() {
							alert('Server error removing product. Please try again.');
						}
					});
				});
			});
		}
		</script>
		<?php
	}
}