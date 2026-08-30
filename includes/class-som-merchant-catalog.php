<?php
/**
 * Dedicated Merchant Catalog Module (Phase 2 HYBRID Catalog Frontend).
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Merchant_Catalog
 */
class SOM_Merchant_Catalog {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_shortcode( 'som_merchant_catalog', array( __CLASS__, 'render_catalog_shortcode' ) );

		// AJAX Endpoints for Product Requests
		add_action( 'wp_ajax_som_merchant_request_new_product', array( __CLASS__, 'ajax_request_new_product' ) );
		add_action( 'wp_ajax_som_merchant_get_product_requests', array( __CLASS__, 'ajax_get_merchant_product_requests' ) );
	}

	/**
	 * AJAX endpoint: Merchant Submit Request for New Product.
	 */
	public static function ajax_request_new_product() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );

		if ( ! $shop_id || ! nearmart_user_can_manage_shop( $user_id, $shop_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ), 403 );
		}

		$product_name = isset( $_POST['product_name'] ) ? sanitize_text_field( wp_unslash( $_POST['product_name'] ) ) : '';
		$brand        = isset( $_POST['brand'] ) ? sanitize_text_field( wp_unslash( $_POST['brand'] ) ) : '';
		$category     = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
		$unit         = isset( $_POST['unit'] ) ? sanitize_text_field( wp_unslash( $_POST['unit'] ) ) : '';
		$barcode      = isset( $_POST['barcode'] ) ? sanitize_text_field( wp_unslash( $_POST['barcode'] ) ) : '';
		$notes        = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		if ( empty( $product_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Product name is required.', 'shop-onboarding-manager' ) ) );
		}

		if ( nearmart_has_pending_product_request( $shop_id, $product_name ) ) {
			wp_send_json_error( array( 'message' => sprintf( __( 'A product request for "%s" is already pending review.', 'shop-onboarding-manager' ), esc_html( $product_name ) ) ) );
		}

		$insert_id = nearmart_create_product_request(
			$user_id,
			$shop_id,
			array(
				'product_name' => $product_name,
				'brand'        => $brand,
				'category'     => $category,
				'unit'         => $unit,
				'barcode'      => $barcode,
				'notes'        => $notes,
			)
		);

		if ( ! $insert_id ) {
			wp_send_json_error( array( 'message' => __( 'Failed to submit product request. Please try again.', 'shop-onboarding-manager' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Your product request has been submitted successfully! Admin will review it shortly.', 'shop-onboarding-manager' ) ) );
	}

	/**
	 * AJAX endpoint: Get Submitted Product Requests for Logged-In Merchant.
	 */
	public static function ajax_get_merchant_product_requests() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );

		if ( ! $shop_id || ! nearmart_user_can_manage_shop( $user_id, $shop_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ), 403 );
		}

		$requests  = nearmart_get_merchant_product_requests( $shop_id, $user_id );
		$formatted = array();

		foreach ( $requests as $r ) {
			$master_title = '';
			if ( $r->master_product_id ) {
				$mp = get_post( $r->master_product_id );
				if ( $mp ) {
					$master_title = $mp->post_title;
				}
			}

			$formatted[] = array(
				'id'                => $r->id,
				'product_name'      => $r->product_name,
				'brand'             => $r->brand ? $r->brand : '',
				'category'          => $r->category ? $r->category : '',
				'unit'              => $r->unit ? $r->unit : '',
				'barcode'           => $r->barcode ? $r->barcode : '',
				'notes'             => $r->notes ? $r->notes : '',
				'status'            => $r->status,
				'master_product_id' => $r->master_product_id,
				'master_title'      => $master_title,
				'admin_notes'       => $r->admin_notes ? $r->admin_notes : '',
				'created_at'        => date_i18n( 'M j, Y g:i a', strtotime( $r->created_at ) ),
			);
		}

		wp_send_json_success( array( 'requests' => $formatted ) );
	}

	/**
	 * Render portal navigation header bar.
	 *
	 * @param string $active_tab 'dashboard' or 'catalog'.
	 * @return string HTML content.
	 */
	public static function render_portal_nav( $active_tab = 'catalog' ) {
		$dashboard_url = home_url( '/merchant-dashboard/' );
		$catalog_url   = home_url( '/merchant-catalog/' );
		$logout_url    = wp_logout_url( home_url( '/merchant-login/' ) );

		$dash_active = 'dashboard' === $active_tab ? ' active' : '';
		$cat_active  = 'catalog' === $active_tab ? ' active' : '';

		ob_start();
		?>
		<div class="som-portal-nav">
			<div class="som-portal-nav-brand">
				<span>&#127978;</span> <strong><?php esc_html_e( 'Merchant Portal', 'shop-onboarding-manager' ); ?></strong>
			</div>
			<div class="som-portal-nav-links">
				<a href="<?php echo esc_url( $dashboard_url ); ?>" class="som-nav-link<?php echo esc_attr( $dash_active ); ?>">
					&#127968; <?php esc_html_e( 'Dashboard', 'shop-onboarding-manager' ); ?>
				</a>
				<a href="<?php echo esc_url( $catalog_url ); ?>" class="som-nav-link<?php echo esc_attr( $cat_active ); ?>">
					&#128722; <?php esc_html_e( 'My Catalog', 'shop-onboarding-manager' ); ?>
				</a>
				<a href="#" id="som_btn_open_my_requests" class="som-nav-link">
					&#128221; <?php esc_html_e( 'My Product Requests', 'shop-onboarding-manager' ); ?>
				</a>
				<a href="<?php echo esc_url( $logout_url ); ?>" class="som-nav-link logout">
					&#128682; <?php esc_html_e( 'Log Out', 'shop-onboarding-manager' ); ?>
				</a>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render [som_merchant_catalog] shortcode.
	 */
	public static function render_catalog_shortcode() {
		wp_enqueue_script( 'jquery' );
		wp_enqueue_style( 'som-frontend-style', SOM_PLUGIN_URL . 'assets/css/som-frontend.css', array(), SOM_VERSION );

		$user_id = get_current_user_id();
		if ( ! $user_id || ! nearmart_user_can_manage_shop_catalog( $user_id ) ) {
			return '<div class="som-merchant-card"><div class="som-response-msg error" style="display:block;">' .
				esc_html__( 'Please log in with a merchant or staff account to access your shop catalog.', 'shop-onboarding-manager' ) .
				' <br /><br /><a href="' . esc_url( home_url( '/merchant-login/' ) ) . '" class="som-submit-btn som-btn-secondary" style="text-decoration:none; display:inline-block; width:auto; padding:10px 20px;">' .
				esc_html__( 'Go to Merchant Login &rarr;', 'shop-onboarding-manager' ) . '</a></div></div>';
		}

		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );
		if ( ! $shop_id ) {
			return '<div class="som-merchant-card"><div class="som-card-header"><h2>' .
				esc_html__( 'My Catalog', 'shop-onboarding-manager' ) . '</h2></div><p>' .
				esc_html__( 'No shop is currently linked to your merchant user account. Please contact NearMart support.', 'shop-onboarding-manager' ) .
				'</p></div>';
		}

		$shop_name = get_the_title( $shop_id );
		$nonce     = wp_create_nonce( 'som_merchant_dashboard_nonce' );

		ob_start();
		?>
		<div class="som-merchant-dashboard-wrap">
			<!-- Portal Navigation Header -->
			<?php echo self::render_portal_nav( 'catalog' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<!-- Catalog Header Section -->
			<div class="som-dashboard-header" style="margin-top: 16px;">
				<div class="som-header-title">
					<h2>&#128722; <?php printf( esc_html__( 'My Shop Catalog — %s', 'shop-onboarding-manager' ), esc_html( $shop_name ) ); ?></h2>
					<p><?php esc_html_e( 'Manage prices, availability, and products (Master-Linked or Standalone) for your store catalog.', 'shop-onboarding-manager' ); ?></p>
				</div>
				<div style="display: flex; gap: 10px; flex-wrap: wrap;">
					<button type="button" id="som_btn_open_add_modal" class="som-submit-btn" style="width: auto; padding: 12px 20px; min-height: 44px;">
						&#10133; <?php esc_html_e( 'Add Product to Catalog', 'shop-onboarding-manager' ); ?>
					</button>
				</div>
			</div>

			<!-- Main Dedicated Catalog Card -->
			<div class="som-dash-card full-width" style="margin-top: 20px;">
				<!-- Search & Filter Bar -->
				<div class="som-catalog-bar">
					<div class="som-catalog-search-wrap">
						<input type="text" id="som_cat_search" class="som-input" placeholder="Search catalog items by name, brand, or SKU..." />
					</div>
					<div class="som-catalog-filters">
						<select id="som_cat_status_filter" class="som-select" style="min-height: 44px;">
							<option value="all"><?php esc_html_e( 'All Statuses', 'shop-onboarding-manager' ); ?></option>
							<option value="active"><?php esc_html_e( 'Active', 'shop-onboarding-manager' ); ?></option>
							<option value="inactive"><?php esc_html_e( 'Inactive', 'shop-onboarding-manager' ); ?></option>
						</select>
						<select id="som_cat_stock_filter" class="som-select" style="min-height: 44px;">
							<option value="all"><?php esc_html_e( 'All Availability', 'shop-onboarding-manager' ); ?></option>
							<option value="instock"><?php esc_html_e( 'Available', 'shop-onboarding-manager' ); ?></option>
							<option value="outofstock"><?php esc_html_e( 'Unavailable', 'shop-onboarding-manager' ); ?></option>
						</select>
					</div>
				</div>

				<!-- Catalog Table Wrap -->
				<div class="som-catalog-table-wrap">
					<table class="som-catalog-table">
						<thead>
							<tr>
								<th style="width: 60px;"><?php esc_html_e( 'Image', 'shop-onboarding-manager' ); ?></th>
								<th><?php esc_html_e( 'Product Name & Specs', 'shop-onboarding-manager' ); ?></th>
								<th><?php esc_html_e( 'Type', 'shop-onboarding-manager' ); ?></th>
								<th><?php esc_html_e( 'Category', 'shop-onboarding-manager' ); ?></th>
								<th><?php esc_html_e( 'Shop Price', 'shop-onboarding-manager' ); ?></th>
								<th><?php esc_html_e( 'Availability', 'shop-onboarding-manager' ); ?></th>
								<th><?php esc_html_e( 'Status', 'shop-onboarding-manager' ); ?></th>
								<th style="width: 120px; text-align: right;"><?php esc_html_e( 'Actions', 'shop-onboarding-manager' ); ?></th>
							</tr>
						</thead>
						<tbody id="som_catalog_tbody">
							<tr>
								<td colspan="8" style="text-align: center; padding: 24px; color: #64748b;">
									&#128259; <?php esc_html_e( 'Loading catalog items...', 'shop-onboarding-manager' ); ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Pagination Bar -->
				<div class="som-catalog-pagination">
					<span id="som_catalog_info">Showing 0 items</span>
					<div class="som-pagination-btns">
						<button type="button" id="som_cat_prev_btn" class="som-btn-icon" disabled>&larr; Previous</button>
						<button type="button" id="som_cat_next_btn" class="som-btn-icon" disabled>Next &rarr;</button>
					</div>
				</div>
			</div>

			<!-- Response Message Alert -->
			<div id="som_dash_msg" class="som-response-msg"></div>
		</div>

		<!-- MODAL 1: Dual-Mode Add Product Modal (Phase 2 HYBRID Catalog) -->
		<div id="som_add_product_modal" class="som-modal-overlay" style="display: none;">
			<div class="som-modal-content" style="max-width: 680px;">
				<div class="som-modal-header">
					<h3>&#10133; <?php esc_html_e( 'Add Product to Catalog', 'shop-onboarding-manager' ); ?></h3>
					<button type="button" class="som-modal-close" onclick="document.getElementById('som_add_product_modal').style.display='none';">&times;</button>
				</div>

				<!-- Dual-Mode Tab Controls -->
				<div style="display: flex; gap: 8px; border-bottom: 2px solid #e2e8f0; margin-bottom: 18px;">
					<button type="button" id="som_tab_btn_master" class="som-modal-tab-btn active" style="padding: 10px 16px; border: none; background: none; font-weight: 700; color: #2563eb; border-bottom: 3px solid #2563eb; cursor: pointer;">
						&#128065; <?php esc_html_e( 'Search Existing Master Product', 'shop-onboarding-manager' ); ?>
					</button>
					<button type="button" id="som_tab_btn_standalone" class="som-modal-tab-btn" style="padding: 10px 16px; border: none; background: none; font-weight: 700; color: #64748b; border-bottom: 3px solid transparent; cursor: pointer;">
						&#10133; <?php esc_html_e( 'Add Standalone New Product', 'shop-onboarding-manager' ); ?>
					</button>
				</div>

				<!-- TAB 1: Search Existing Master Product -->
				<div id="som_tab_content_master" class="som-tab-content">
					<div class="som-form-group">
						<label for="som_master_search" class="som-label"><?php esc_html_e( '1. Search Master Product (Name, SKU, or Barcode)', 'shop-onboarding-manager' ); ?></label>
						<input type="text" id="som_master_search" class="som-input" placeholder="<?php esc_attr_e( 'Type at least 2 characters to search master catalog...', 'shop-onboarding-manager' ); ?>" />
						<div id="som_master_results" class="som-master-search-results"></div>
					</div>

					<form id="som_form_add_catalog_product" style="display: none; border-top: 1px solid #e2e8f0; padding-top: 16px;">
						<input type="hidden" id="som_add_product_id" name="product_id" value="" />
						<p style="font-weight: 700; color: #16a34a; margin-bottom: 12px;" id="som_add_selected_title"></p>

						<div class="som-form-row">
							<div class="som-form-group">
								<label for="som_add_price" class="som-label required"><?php esc_html_e( 'Shop Price (₹)', 'shop-onboarding-manager' ); ?></label>
								<input type="number" step="0.01" id="som_add_price" name="price" class="som-input" required placeholder="0.00" />
							</div>
							<div class="som-form-group">
								<label for="som_add_sale_price" class="som-label"><?php esc_html_e( 'Sale Price (₹)', 'shop-onboarding-manager' ); ?></label>
								<input type="number" step="0.01" id="som_add_sale_price" name="sale_price" class="som-input" placeholder="Optional" />
							</div>
						</div>

						<div class="som-form-row">
							<div class="som-form-group">
								<label for="som_add_stock_status" class="som-label"><?php esc_html_e( 'Availability', 'shop-onboarding-manager' ); ?></label>
								<select id="som_add_stock_status" name="stock_status" class="som-select">
									<option value="instock"><?php esc_html_e( 'Available', 'shop-onboarding-manager' ); ?></option>
									<option value="outofstock"><?php esc_html_e( 'Unavailable', 'shop-onboarding-manager' ); ?></option>
								</select>
							</div>
							<div class="som-form-group">
								<label for="som_add_stock_quantity" class="som-label"><?php esc_html_e( 'Stock Qty', 'shop-onboarding-manager' ); ?></label>
								<input type="number" id="som_add_stock_quantity" name="stock_quantity" class="som-input" placeholder="Optional" />
							</div>
						</div>

						<div class="som-form-row">
							<div class="som-form-group">
								<label for="som_add_shop_sku" class="som-label"><?php esc_html_e( 'Shop SKU', 'shop-onboarding-manager' ); ?></label>
								<input type="text" id="som_add_shop_sku" name="shop_sku" class="som-input" placeholder="e.g. STORE-ITEM-01 (Optional)" />
							</div>
							<div class="som-form-group">
								<label for="som_add_status" class="som-label"><?php esc_html_e( 'Listing Status', 'shop-onboarding-manager' ); ?></label>
								<select id="som_add_status" name="status" class="som-select">
									<option value="active"><?php esc_html_e( 'Active (Visible to customers)', 'shop-onboarding-manager' ); ?></option>
									<option value="inactive"><?php esc_html_e( 'Inactive (Hidden)', 'shop-onboarding-manager' ); ?></option>
								</select>
							</div>
						</div>

						<button type="submit" id="som_btn_save_add" class="som-submit-btn">
							&#128190; <?php esc_html_e( 'Save to My Catalog', 'shop-onboarding-manager' ); ?>
						</button>
					</form>
				</div>

				<!-- TAB 2: Add Standalone New Product -->
				<div id="som_tab_content_standalone" class="som-tab-content" style="display: none;">
					<p style="font-size: 0.85rem; color: #64748b; margin-bottom: 14px;">
						<?php esc_html_e( 'Create a standalone item specific to your shop. Standalone items do not require a master product.', 'shop-onboarding-manager' ); ?>
					</p>

					<!-- Lightweight Master Similarity Banner -->
					<div id="som_standalone_suggestion_banner" style="display: none; background: #eff6ff; border: 1px solid #93c5fd; border-radius: 8px; padding: 12px; margin-bottom: 14px;">
						<div style="display: flex; gap: 10px; align-items: center; justify-content: space-between;">
							<div style="font-size: 0.85rem; color: #1e40af;">
								<strong>💡 <?php esc_html_e( 'Similar Master Product Found:', 'shop-onboarding-manager' ); ?></strong>
								<span id="som_suggested_master_title" style="font-weight: 700;"></span>
							</div>
							<div style="display: flex; gap: 6px;">
								<button type="button" id="som_btn_use_suggested_master" class="button button-small button-primary" style="font-size: 0.8rem;">
									&#128279; <?php esc_html_e( 'Use Existing Product', 'shop-onboarding-manager' ); ?>
								</button>
								<button type="button" id="som_btn_dismiss_suggestion" class="button button-small" style="font-size: 0.8rem;">
									&times; <?php esc_html_e( 'Dismiss', 'shop-onboarding-manager' ); ?>
								</button>
							</div>
						</div>
					</div>

					<form id="som_form_add_standalone_product">
						<div class="som-form-group">
							<label for="som_st_name" class="som-label required"><?php esc_html_e( 'Product Name', 'shop-onboarding-manager' ); ?></label>
							<input type="text" id="som_st_name" name="custom_name" class="som-input" required placeholder="e.g. Local Fresh Country Milk 1L" />
						</div>

						<div class="som-form-row">
							<div class="som-form-group">
								<label for="som_st_category" class="som-label"><?php esc_html_e( 'Category (Optional)', 'shop-onboarding-manager' ); ?></label>
								<input type="text" id="som_st_category" name="custom_category" class="som-input" placeholder="e.g. Dairy & Milk" />
							</div>
							<div class="som-form-group">
								<label for="som_st_brand" class="som-label"><?php esc_html_e( 'Brand (Optional)', 'shop-onboarding-manager' ); ?></label>
								<input type="text" id="som_st_brand" name="custom_brand" class="som-input" placeholder="e.g. Local Farm" />
							</div>
						</div>

						<div class="som-form-row">
							<div class="som-form-group">
								<label for="som_st_unit" class="som-label"><?php esc_html_e( 'Approximate Unit/Size (Optional)', 'shop-onboarding-manager' ); ?></label>
								<input type="text" id="som_st_unit" name="custom_unit" class="som-input" placeholder="e.g. 1L, 500g, 10 pcs" />
							</div>
							<div class="som-form-group">
								<label for="som_st_barcode" class="som-label"><?php esc_html_e( 'Barcode / SKU (Optional)', 'shop-onboarding-manager' ); ?></label>
								<input type="text" id="som_st_barcode" name="custom_barcode" class="som-input" placeholder="e.g. 8909999000111" />
							</div>
						</div>

						<div class="som-form-row">
							<div class="som-form-group">
								<label for="som_st_price" class="som-label required"><?php esc_html_e( 'Shop Price (₹)', 'shop-onboarding-manager' ); ?></label>
								<input type="number" step="0.01" id="som_st_price" name="price" class="som-input" required placeholder="0.00" />
							</div>
							<div class="som-form-group">
								<label for="som_st_sale_price" class="som-label"><?php esc_html_e( 'Sale Price (₹)', 'shop-onboarding-manager' ); ?></label>
								<input type="number" step="0.01" id="som_st_sale_price" name="sale_price" class="som-input" placeholder="Optional" />
							</div>
						</div>

						<div class="som-form-row">
							<div class="som-form-group">
								<label for="som_st_stock_status" class="som-label"><?php esc_html_e( 'Availability', 'shop-onboarding-manager' ); ?></label>
								<select id="som_st_stock_status" name="stock_status" class="som-select">
									<option value="instock"><?php esc_html_e( 'Available', 'shop-onboarding-manager' ); ?></option>
									<option value="outofstock"><?php esc_html_e( 'Unavailable', 'shop-onboarding-manager' ); ?></option>
								</select>
							</div>
							<div class="som-form-group">
								<label for="som_st_stock_quantity" class="som-label"><?php esc_html_e( 'Stock Qty', 'shop-onboarding-manager' ); ?></label>
								<input type="number" id="som_st_stock_quantity" name="stock_quantity" class="som-input" placeholder="Optional" />
							</div>
						</div>

						<div class="som-form-row">
							<div class="som-form-group">
								<label for="som_st_shop_sku" class="som-label"><?php esc_html_e( 'Shop SKU', 'shop-onboarding-manager' ); ?></label>
								<input type="text" id="som_st_shop_sku" name="shop_sku" class="som-input" placeholder="e.g. LOCAL-MILK-01 (Optional)" />
							</div>
							<div class="som-form-group">
								<label for="som_st_status" class="som-label"><?php esc_html_e( 'Listing Status', 'shop-onboarding-manager' ); ?></label>
								<select id="som_st_status" name="status" class="som-select">
									<option value="active"><?php esc_html_e( 'Active (Visible to customers)', 'shop-onboarding-manager' ); ?></option>
									<option value="inactive"><?php esc_html_e( 'Inactive (Hidden)', 'shop-onboarding-manager' ); ?></option>
								</select>
							</div>
						</div>

						<button type="submit" id="som_btn_save_standalone" class="som-submit-btn">
							&#128190; <?php esc_html_e( 'Save Standalone Product', 'shop-onboarding-manager' ); ?>
						</button>
					</form>
				</div>
			</div>
		</div>

		<!-- MODAL 2: Edit Catalog Product Modal (HYBRID Model) -->
		<div id="som_edit_product_modal" class="som-modal-overlay" style="display: none;">
			<div class="som-modal-content">
				<div class="som-modal-header">
					<h3>&#9998; <?php esc_html_e( 'Edit Catalog Product', 'shop-onboarding-manager' ); ?></h3>
					<button type="button" class="som-modal-close" onclick="document.getElementById('som_edit_product_modal').style.display='none';">&times;</button>
				</div>

				<form id="som_form_edit_catalog_product">
					<input type="hidden" id="som_edit_item_id" name="id" value="" />
					<input type="hidden" id="som_edit_product_id" name="product_id" value="" />

					<!-- Read-Only Master Product Specs Box (For Master-Linked Items) -->
					<div id="som_edit_master_specs_box" class="som-master-specs-box" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; margin-bottom:16px; display:none;">
						<div style="display:flex; gap:12px; align-items:center;">
							<div id="som_edit_thumb_wrap">
								<span style="font-size:1.5rem;">&#128230;</span>
							</div>
							<div>
								<strong id="som_edit_master_title" style="font-size:1.05rem; color:#0f172a; display:block;"></strong>
								<div id="som_edit_master_meta" style="font-size:0.8rem; color:#64748b; margin-top:2px;"></div>
							</div>
						</div>
						<p style="font-size:0.75rem; color:#94a3b8; margin:8px 0 0 0; font-style:italic;">
							&#8505; <?php esc_html_e( 'Master product specifications are managed by platform admins and cannot be changed here.', 'shop-onboarding-manager' ); ?>
						</p>
					</div>

					<!-- Editable Product Specs Box (For Standalone Items) -->
					<div id="som_edit_standalone_specs_box" style="display:none; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:14px; margin-bottom:16px;">
						<p style="font-size:0.82rem; font-weight:700; color:#166534; margin-bottom:10px;">
							&#10133; <?php esc_html_e( 'Standalone Shop Product Specifications', 'shop-onboarding-manager' ); ?>
						</p>
						<div class="som-form-group">
							<label for="som_edit_custom_name" class="som-label required"><?php esc_html_e( 'Product Name', 'shop-onboarding-manager' ); ?></label>
							<input type="text" id="som_edit_custom_name" name="custom_name" class="som-input" />
						</div>

						<div class="som-form-row">
							<div class="som-form-group">
								<label for="som_edit_custom_category" class="som-label"><?php esc_html_e( 'Category', 'shop-onboarding-manager' ); ?></label>
								<input type="text" id="som_edit_custom_category" name="custom_category" class="som-input" />
							</div>
							<div class="som-form-group">
								<label for="som_edit_custom_brand" class="som-label"><?php esc_html_e( 'Brand', 'shop-onboarding-manager' ); ?></label>
								<input type="text" id="som_edit_custom_brand" name="custom_brand" class="som-input" />
							</div>
						</div>

						<div class="som-form-row">
							<div class="som-form-group">
								<label for="som_edit_custom_unit" class="som-label"><?php esc_html_e( 'Unit / Size', 'shop-onboarding-manager' ); ?></label>
								<input type="text" id="som_edit_custom_unit" name="custom_unit" class="som-input" />
							</div>
							<div class="som-form-group">
								<label for="som_edit_custom_barcode" class="som-label"><?php esc_html_e( 'Barcode / SKU', 'shop-onboarding-manager' ); ?></label>
								<input type="text" id="som_edit_custom_barcode" name="custom_barcode" class="som-input" />
							</div>
						</div>
					</div>

					<div class="som-form-row">
						<div class="som-form-group">
							<label for="som_edit_price" class="som-label required"><?php esc_html_e( 'Shop Price (₹)', 'shop-onboarding-manager' ); ?></label>
							<input type="number" step="0.01" id="som_edit_price" name="price" class="som-input" required />
						</div>
						<div class="som-form-group">
							<label for="som_edit_sale_price" class="som-label"><?php esc_html_e( 'Sale Price (₹)', 'shop-onboarding-manager' ); ?></label>
							<input type="number" step="0.01" id="som_edit_sale_price" name="sale_price" class="som-input" placeholder="Optional" />
						</div>
					</div>

					<div class="som-form-row">
						<div class="som-form-group">
							<label for="som_edit_stock_status" class="som-label"><?php esc_html_e( 'Availability', 'shop-onboarding-manager' ); ?></label>
							<select id="som_edit_stock_status" name="stock_status" class="som-select">
								<option value="instock"><?php esc_html_e( 'Available', 'shop-onboarding-manager' ); ?></option>
								<option value="outofstock"><?php esc_html_e( 'Unavailable', 'shop-onboarding-manager' ); ?></option>
							</select>
						</div>
						<div class="som-form-group">
							<label for="som_edit_stock_quantity" class="som-label"><?php esc_html_e( 'Stock Qty', 'shop-onboarding-manager' ); ?></label>
							<input type="number" id="som_edit_stock_quantity" name="stock_quantity" class="som-input" placeholder="Optional" />
						</div>
					</div>

					<div class="som-form-row">
						<div class="som-form-group">
							<label for="som_edit_shop_sku" class="som-label"><?php esc_html_e( 'Shop SKU', 'shop-onboarding-manager' ); ?></label>
							<input type="text" id="som_edit_shop_sku" name="shop_sku" class="som-input" placeholder="e.g. STORE-ITEM-01 (Optional)" />
						</div>
						<div class="som-form-group">
							<label for="som_edit_status" class="som-label"><?php esc_html_e( 'Listing Status', 'shop-onboarding-manager' ); ?></label>
							<select id="som_edit_status" name="status" class="som-select">
								<option value="active"><?php esc_html_e( 'Active', 'shop-onboarding-manager' ); ?></option>
								<option value="inactive"><?php esc_html_e( 'Inactive', 'shop-onboarding-manager' ); ?></option>
							</select>
						</div>
					</div>

					<button type="submit" id="som_btn_save_edit" class="som-submit-btn">
						&#128190; <?php esc_html_e( 'Update Product', 'shop-onboarding-manager' ); ?>
					</button>
				</form>
			</div>
		</div>

		<!-- MODAL 3: Merchant Request New Product Modal -->
		<div id="som_request_product_modal" class="som-modal-overlay" style="display: none;">
			<div class="som-modal-content">
				<div class="som-modal-header">
					<h3>&#10133; <?php esc_html_e( 'Request New Product', 'shop-onboarding-manager' ); ?></h3>
					<button type="button" class="som-modal-close" onclick="document.getElementById('som_request_product_modal').style.display='none';">&times;</button>
				</div>

				<p style="font-size: 0.88rem; color: #64748b; margin-bottom: 16px;">
					<?php esc_html_e( 'Can\'t find a product in our master catalog? Submit a request below. Admin will review and add the master product to the platform.', 'shop-onboarding-manager' ); ?>
				</p>

				<form id="som_form_request_new_product">
					<div class="som-form-group">
						<label for="som_req_product_name" class="som-label required"><?php esc_html_e( 'Product Name', 'shop-onboarding-manager' ); ?></label>
						<input type="text" id="som_req_product_name" name="product_name" class="som-input" required placeholder="e.g. Organic Multigrain Atta 5kg" />
					</div>

					<div class="som-form-row">
						<div class="som-form-group">
							<label for="som_req_brand" class="som-label"><?php esc_html_e( 'Brand (Optional)', 'shop-onboarding-manager' ); ?></label>
							<input type="text" id="som_req_brand" name="brand" class="som-input" placeholder="e.g. Aashirvaad" />
						</div>
						<div class="som-form-group">
							<label for="som_req_category" class="som-label"><?php esc_html_e( 'Category (Optional)', 'shop-onboarding-manager' ); ?></label>
							<input type="text" id="som_req_category" name="category" class="som-input" placeholder="e.g. Groceries" />
						</div>
					</div>

					<div class="som-form-row">
						<div class="som-form-group">
							<label for="som_req_unit" class="som-label"><?php esc_html_e( 'Approximate Unit/Size (Optional)', 'shop-onboarding-manager' ); ?></label>
							<input type="text" id="som_req_unit" name="unit" class="som-input" placeholder="e.g. 5kg, 500ml, 10 pcs" />
						</div>
						<div class="som-form-group">
							<label for="som_req_barcode" class="som-label"><?php esc_html_e( 'Barcode / SKU (Optional)', 'shop-onboarding-manager' ); ?></label>
							<input type="text" id="som_req_barcode" name="barcode" class="som-input" placeholder="e.g. 890123456789" />
						</div>
					</div>

					<div class="som-form-group">
						<label for="som_req_notes" class="som-label"><?php esc_html_e( 'Additional Notes / Description (Optional)', 'shop-onboarding-manager' ); ?></label>
						<textarea id="som_req_notes" name="notes" class="som-input" rows="3" placeholder="Provide any additional details to help admin identify the product..."></textarea>
					</div>

					<button type="submit" id="som_btn_submit_req" class="som-submit-btn">
						&#128238; <?php esc_html_e( 'Submit Product Request', 'shop-onboarding-manager' ); ?>
					</button>
				</form>
			</div>
		</div>

		<!-- MODAL 4: Merchant View My Product Requests Modal -->
		<div id="som_my_requests_modal" class="som-modal-overlay" style="display: none;">
			<div class="som-modal-content" style="max-width: 720px;">
				<div class="som-modal-header">
					<h3>&#128221; <?php esc_html_e( 'My Product Requests', 'shop-onboarding-manager' ); ?></h3>
					<button type="button" class="som-modal-close" onclick="document.getElementById('som_my_requests_modal').style.display='none';">&times;</button>
				</div>

				<div id="som_my_requests_list_wrap" style="max-height: 400px; overflow-y: auto;">
					<p style="padding:20px; text-align:center; color:#64748b;">&#128259; Loading your requests...</p>
				</div>
			</div>
		</div>

		<!-- Catalog Inline JavaScript Handler (Phase 2 HYBRID Catalog) -->
		<script>
		if (typeof jQuery !== 'undefined') {
			jQuery(document).ready(function($) {
				var nonce = '<?php echo esc_js( $nonce ); ?>';
				var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
				var currentPage = 1;
				var suggestedMasterProduct = null;

				function escapeHtml(str) {
					return str ? $('<div>').text(str).html() : '';
				}

				function loadCatalog(page) {
					currentPage = page || 1;
					var $tbody = $('#som_catalog_tbody');
					$tbody.html('<tr><td colspan="8" style="text-align:center; padding: 20px; color:#64748b;">&#128259; Loading catalog...</td></tr>');

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: {
							action: 'som_merchant_get_catalog',
							nonce: nonce,
							search: $('#som_cat_search').val(),
							status: $('#som_cat_status_filter').val(),
							stock_status: $('#som_cat_stock_filter').val(),
							page: currentPage
						},
						success: function(res) {
							if (res.success) {
								var items = res.data.items;
								if (!items || items.length === 0) {
									$tbody.html('<tr><td colspan="8" style="text-align:center; padding: 24px; color:#64748b;">No products in your catalog yet. Click <strong>"Add Product to Catalog"</strong> to add items!</td></tr>');
									$('#som_catalog_info').text('Showing 0 items');
									$('#som_cat_prev_btn, #som_cat_next_btn').prop('disabled', true);
									return;
								}

								var html = '';
								$.each(items, function(i, item) {
									html += '<tr data-id="' + item.id + '">';
									html += '<td><div class="som-cat-thumb-box">';
									if (item.thumb_url) {
										html += '<img src="' + item.thumb_url + '" alt="' + escapeHtml(item.title) + '" />';
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
										html += '<span class="som-cat-meta-tag">' + metaArr.join(' &bull; ') + '</span>';
									}
									html += '</td>';

									// Type Tag
									if (item.is_standalone) {
										html += '<td><span style="font-size:0.75rem; color:#0369a1; background:#e0f2fe; padding:4px 8px; border-radius:10px; font-weight:700;">Standalone</span></td>';
									} else {
										html += '<td><span style="font-size:0.75rem; color:#15803d; background:#dcfce7; padding:4px 8px; border-radius:10px; font-weight:700;">Master-Linked</span></td>';
									}

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

									html += '<td><div class="som-cat-actions">';
									html += '<button type="button" class="som-btn-icon som-btn-edit-item" data-item=\'' + JSON.stringify(item) + '\'>&#9998; Edit</button>';
									html += '<button type="button" class="som-btn-icon danger som-btn-remove-item" data-id="' + item.id + '" data-title="' + escapeHtml(item.title) + '">&#128465;</button>';
									html += '</div></td>';
									html += '</tr>';
								});

								$tbody.html(html);

								$('#som_catalog_info').text('Page ' + res.data.current_page + ' of ' + res.data.total_pages + ' (' + res.data.total_count + ' items)');
								$('#som_cat_prev_btn').prop('disabled', res.data.current_page <= 1);
								$('#som_cat_next_btn').prop('disabled', res.data.current_page >= res.data.total_pages);
							} else {
								$tbody.html('<tr><td colspan="8" style="text-align:center; color:#ef4444;">' + (res.data.message || 'Error loading catalog') + '</td></tr>');
							}
						}
					});
				}

				loadCatalog(1);

				var searchTimer = null;
				$('#som_cat_search').on('keyup input', function() {
					clearTimeout(searchTimer);
					searchTimer = setTimeout(function() { loadCatalog(1); }, 400);
				});

				$('#som_cat_status_filter, #som_cat_stock_filter').on('change', function() {
					loadCatalog(1);
				});

				$('#som_cat_prev_btn').on('click', function() { if (currentPage > 1) loadCatalog(currentPage - 1); });
				$('#som_cat_next_btn').on('click', function() { loadCatalog(currentPage + 1); });

				// Dual-Mode Add Modal Tab Switching
				$('#som_tab_btn_master').on('click', function() {
					$('.som-modal-tab-btn').removeClass('active').css({'color': '#64748b', 'border-bottom-color': 'transparent'});
					$(this).addClass('active').css({'color': '#2563eb', 'border-bottom-color': '#2563eb'});
					$('#som_tab_content_standalone').hide();
					$('#som_tab_content_master').show();
				});

				$('#som_tab_btn_standalone').on('click', function() {
					$('.som-modal-tab-btn').removeClass('active').css({'color': '#64748b', 'border-bottom-color': 'transparent'});
					$(this).addClass('active').css({'color': '#2563eb', 'border-bottom-color': '#2563eb'});
					$('#som_tab_content_master').hide();
					$('#som_tab_content_standalone').show();
					$('#som_st_name').focus();
				});

				// Open Add Modal Logic
				$('#som_btn_open_add_modal').on('click', function() {
					$('#som_add_product_modal').show();
					$('#som_tab_btn_master').click();
					$('#som_master_search').val('').focus();
					$('#som_form_add_catalog_product').hide();
					$('#som_form_add_standalone_product')[0].reset();
					$('#som_standalone_suggestion_banner').hide();
					renderMasterSearchPrompt();
				});

				function renderMasterSearchPrompt() {
					var html = '<p style="padding:14px; text-align:center; color:#64748b; margin:0;">Type at least 2 characters to search master products...</p>';
					html += '<div style="text-align:center; padding-bottom:14px;"><button type="button" class="som-submit-btn som-btn-secondary som-btn-trigger-req" style="width:auto; padding:8px 16px; font-size:0.88rem;">&#10133; Request New Master Product</button></div>';
					$('#som_master_results').html(html);
				}

				// Type-ahead Master Search in Modal
				var masterTimer = null;
				$('#som_master_search').on('keyup input', function() {
					clearTimeout(masterTimer);
					var q = $(this).val().trim();

					if (q.length < 2) {
						$('#som_form_add_catalog_product').slideUp();
						renderMasterSearchPrompt();
						return;
					}

					masterTimer = setTimeout(function() {
						performMasterSearch(q);
					}, 300);
				});

				function performMasterSearch(queryStr) {
					if (!queryStr || queryStr.length < 2) return;
					$('#som_master_results').html('<p style="padding:14px; color:#64748b; margin:0; text-align:center;">&#128259; Searching master products...</p>');

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: { action: 'som_merchant_search_master_products', nonce: nonce, q: queryStr },
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
										html += '<span class="som-badge-in-catalog" style="font-size:0.8rem; color:#16a34a; background:#dcfce7; padding:4px 10px; border-radius:12px; font-weight:700;">&#10003; Already in your catalog</span>';
									} else {
										html += '<button type="button" class="som-btn-icon">Select</button>';
									}
									html += '</div>';
								});

								html += '<div style="text-align:center; padding: 12px 0 4px 0; border-top:1px solid #f1f5f9;"><span style="font-size:0.82rem; color:#64748b;">Can\'t find what you need? </span><button type="button" class="som-btn-trigger-req" style="background:none; border:none; color:#2563eb; font-weight:700; text-decoration:underline; cursor:pointer;">Request New Product</button></div>';
								$('#som_master_results').html(html);
							} else {
								var html = '<p style="padding:14px; color:#64748b; margin:0; text-align:center;">No master products found matching your search.</p>';
								html += '<div style="text-align:center; padding-bottom:14px;"><button type="button" class="som-submit-btn som-btn-secondary som-btn-trigger-req" style="width:auto; padding:8px 16px; font-size:0.88rem;">&#10133; Request New Product</button></div>';
								$('#som_master_results').html(html);
							}
						}
					});
				}

				// Select Master Product
				$(document).on('click', '.som-master-item', function() {
					var m = $(this).data('master');
					if (!m) return;

					if (m.in_catalog) {
						alert('This product is already in your catalog!');
						return;
					}

					$('.som-master-item').removeClass('selected');
					$(this).addClass('selected');

					$('#som_add_product_id').val(m.product_id);
					$('#som_add_selected_title').html('&#10003; Selected Master Product: ' + escapeHtml(m.title) + (m.unit ? ' (' + escapeHtml(m.unit) + ')' : ''));

					if (m.suggested_price) {
						$('#som_add_price').val(m.suggested_price);
					} else {
						$('#som_add_price').val('');
					}
					$('#som_add_sale_price').val('');
					$('#som_add_stock_quantity').val('');
					$('#som_add_shop_sku').val('');

					$('#som_form_add_catalog_product').slideDown();
				});

				// Lightweight Similarity Check on Standalone Name Input
				var simTimer = null;
				$('#som_st_name').on('keyup input', function() {
					clearTimeout(simTimer);
					var val = $(this).val().trim();

					if (val.length < 2) {
						$('#som_standalone_suggestion_banner').hide();
						return;
					}

					simTimer = setTimeout(function() {
						$.ajax({
							url: ajaxUrl,
							type: 'POST',
							data: { action: 'som_merchant_check_similar_master_products', nonce: nonce, name: val },
							success: function(res) {
								if (res.success && res.data.suggestions && res.data.suggestions.length > 0) {
									suggestedMasterProduct = res.data.suggestions[0];
									$('#som_suggested_master_title').text('"' + suggestedMasterProduct.title + '"');
									$('#som_standalone_suggestion_banner').slideDown();
								} else {
									suggestedMasterProduct = null;
									$('#som_standalone_suggestion_banner').slideUp();
								}
							}
						});
					}, 300);
				});

				// Use Suggested Master Shortcut
				$('#som_btn_use_suggested_master').on('click', function() {
					if (!suggestedMasterProduct) return;
					var title = suggestedMasterProduct.title;
					$('#som_standalone_suggestion_banner').hide();
					$('#som_tab_btn_master').click();
					$('#som_master_search').val(title);
					performMasterSearch(title);
				});

				$('#som_btn_dismiss_suggestion').on('click', function() {
					$('#som_standalone_suggestion_banner').slideUp();
				});

				// Submit Add Master Product Form
				$('#som_form_add_catalog_product').on('submit', function(e) {
					e.preventDefault();
					var $btn = $('#som_btn_save_add');
					$btn.prop('disabled', true).text('Saving...');

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: {
							action: 'som_merchant_add_catalog_product',
							nonce: nonce,
							product_id: $('#som_add_product_id').val(),
							price: $('#som_add_price').val(),
							sale_price: $('#som_add_sale_price').val(),
							stock_status: $('#som_add_stock_status').val(),
							stock_quantity: $('#som_add_stock_quantity').val(),
							shop_sku: $('#som_add_shop_sku').val(),
							status: $('#som_add_status').val()
						},
						success: function(res) {
							$btn.prop('disabled', false).html('&#128190; Save to My Catalog');
							if (res.success) {
								alert(res.data.message || 'Product added to your shop catalog successfully!');
								$('#som_add_product_modal').hide();
								$('#som_form_add_catalog_product')[0].reset();
								$('#som_form_add_catalog_product').hide();
								loadCatalog(1);
							} else {
								alert(res.data.message || 'Error adding product.');
							}
						},
						error: function() {
							$btn.prop('disabled', false).html('&#128190; Save to My Catalog');
							alert('Server error adding product. Please try again.');
						}
					});
				});

				// Submit Add Standalone Product Form
				$('#som_form_add_standalone_product').on('submit', function(e) {
					e.preventDefault();
					var $btn = $('#som_btn_save_standalone');
					$btn.prop('disabled', true).text('Saving...');

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: {
							action: 'som_merchant_add_standalone_product',
							nonce: nonce,
							custom_name: $('#som_st_name').val(),
							custom_category: $('#som_st_category').val(),
							custom_brand: $('#som_st_brand').val(),
							custom_unit: $('#som_st_unit').val(),
							custom_barcode: $('#som_st_barcode').val(),
							price: $('#som_st_price').val(),
							sale_price: $('#som_st_sale_price').val(),
							stock_status: $('#som_st_stock_status').val(),
							stock_quantity: $('#som_st_stock_quantity').val(),
							shop_sku: $('#som_st_shop_sku').val(),
							status: $('#som_st_status').val()
						},
						success: function(res) {
							$btn.prop('disabled', false).html('&#128190; Save Standalone Product');
							if (res.success) {
								alert(res.data.message || 'Standalone product added successfully!');
								$('#som_add_product_modal').hide();
								$('#som_form_add_standalone_product')[0].reset();
								$('#som_standalone_suggestion_banner').hide();
								loadCatalog(1);
							} else {
								alert(res.data.message || 'Error adding standalone product.');
							}
						},
						error: function() {
							$btn.prop('disabled', false).html('&#128190; Save Standalone Product');
							alert('Server error adding standalone product. Please try again.');
						}
					});
				});

				// Open Edit Product Modal
				$(document).on('click', '.som-btn-edit-item', function() {
					var item = $(this).data('item');
					if (!item) return;

					$('#som_edit_item_id').val(item.id);
					$('#som_edit_product_id').val(item.product_id || '');

					if (item.is_standalone) {
						// Show Standalone Specs form
						$('#som_edit_master_specs_box').hide();
						$('#som_edit_standalone_specs_box').show();

						$('#som_edit_custom_name').val(item.title);
						$('#som_edit_custom_category').val(item.category || '');
						$('#som_edit_custom_brand').val(item.brand || '');
						$('#som_edit_custom_unit').val(item.unit || '');
						$('#som_edit_custom_barcode').val(item.barcode || '');
					} else {
						// Show Master Specs box
						$('#som_edit_standalone_specs_box').hide();
						$('#som_edit_master_specs_box').show();

						$('#som_edit_master_title').text(item.title);
						var masterMeta = [];
						if (item.category) masterMeta.push('Cat: ' + escapeHtml(item.category));
						if (item.brand) masterMeta.push('Brand: ' + escapeHtml(item.brand));
						if (item.unit) masterMeta.push('Unit: ' + escapeHtml(item.unit));
						if (item.barcode) masterMeta.push('Barcode: ' + escapeHtml(item.barcode));
						if (item.master_sku) masterMeta.push('Master SKU: ' + escapeHtml(item.master_sku));
						$('#som_edit_master_meta').html(masterMeta.join(' &bull; '));

						if (item.thumb_url) {
							$('#som_edit_thumb_wrap').html('<img src="' + item.thumb_url + '" style="width:44px; height:44px; border-radius:6px; object-fit:cover;" />');
						} else {
							$('#som_edit_thumb_wrap').html('<span style="font-size:1.5rem;">&#128230;</span>');
						}
					}

					$('#som_edit_price').val(item.price);
					$('#som_edit_sale_price').val(item.sale_price || '');
					$('#som_edit_stock_status').val(item.stock_status);
					$('#som_edit_stock_quantity').val(item.stock_quantity || '');
					$('#som_edit_shop_sku').val(item.shop_sku || '');
					$('#som_edit_status').val(item.status);

					$('#som_edit_product_modal').show();
				});

				// Save Edit Form
				$('#som_form_edit_catalog_product').on('submit', function(e) {
					e.preventDefault();
					var $btn = $('#som_btn_save_edit');
					$btn.prop('disabled', true).text('Updating...');

					var postData = {
						action: 'som_merchant_update_catalog_product',
						nonce: nonce,
						id: $('#som_edit_item_id').val(),
						product_id: $('#som_edit_product_id').val(),
						price: $('#som_edit_price').val(),
						sale_price: $('#som_edit_sale_price').val(),
						stock_status: $('#som_edit_stock_status').val(),
						stock_quantity: $('#som_edit_stock_quantity').val(),
						shop_sku: $('#som_edit_shop_sku').val(),
						status: $('#som_edit_status').val()
					};

					if ($('#som_edit_standalone_specs_box').is(':visible')) {
						postData.custom_name = $('#som_edit_custom_name').val();
						postData.custom_category = $('#som_edit_custom_category').val();
						postData.custom_brand = $('#som_edit_custom_brand').val();
						postData.custom_unit = $('#som_edit_custom_unit').val();
						postData.custom_barcode = $('#som_edit_custom_barcode').val();
					}

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: postData,
						success: function(res) {
							$btn.prop('disabled', false).html('&#128190; Update Product');
							if (res.success) {
								alert(res.data.message || 'Product updated successfully!');
								$('#som_edit_product_modal').hide();
								loadCatalog(currentPage);
							} else {
								alert(res.data.message || 'Error updating product.');
							}
						},
						error: function() {
							$btn.prop('disabled', false).html('&#128190; Update Product');
							alert('Server error updating product. Please try again.');
						}
					});
				});

				// Remove Product Action
				$(document).on('click', '.som-btn-remove-item', function() {
					var itemId = $(this).data('id');
					var title = $(this).data('title') || 'this product';

					var msg = 'Are you sure you want to remove "' + title + '" from your shop catalog?\n\n' +
						'This will only remove the item from your store. Master products in WooCommerce will not be deleted.';

					if (!confirm(msg)) return;

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: { action: 'som_merchant_remove_catalog_product', nonce: nonce, id: itemId },
						success: function(res) {
							if (res.success) {
								alert(res.data.message || 'Product removed from your shop catalog.');
								loadCatalog(currentPage);
							} else {
								alert(res.data.message || 'Error removing product.');
							}
						},
						error: function() {
							alert('Server error removing product. Please try again.');
						}
					});
				});

				// Request Product Trigger
				$(document).on('click', '.som-btn-trigger-req', function() {
					$('#som_add_product_modal').hide();
					var searchVal = $('#som_master_search').val().trim();
					$('#som_req_product_name').val(searchVal);
					$('#som_request_product_modal').show();
				});

				// Submit Product Request Form
				$('#som_form_request_new_product').on('submit', function(e) {
					e.preventDefault();
					var $btn = $('#som_btn_submit_req');
					$btn.prop('disabled', true).text('Submitting...');

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: {
							action: 'som_merchant_request_new_product',
							nonce: nonce,
							product_name: $('#som_req_product_name').val(),
							brand: $('#som_req_brand').val(),
							category: $('#som_req_category').val(),
							unit: $('#som_req_unit').val(),
							barcode: $('#som_req_barcode').val(),
							notes: $('#som_req_notes').val()
						},
						success: function(res) {
							$btn.prop('disabled', false).html('&#128238; Submit Product Request');
							if (res.success) {
								alert(res.data.message || 'Request submitted successfully!');
								$('#som_request_product_modal').hide();
								$('#som_form_request_new_product')[0].reset();
								loadMyProductRequests();
								$('#som_my_requests_modal').show();
							} else {
								alert(res.data.message || 'Error submitting request.');
							}
						}
					});
				});

				// Open My Requests Modal
				$('#som_btn_open_my_requests').on('click', function(e) {
					e.preventDefault();
					loadMyProductRequests();
					$('#som_my_requests_modal').show();
				});

				function loadMyProductRequests() {
					var $wrap = $('#som_my_requests_list_wrap');
					$wrap.html('<p style="padding:20px; text-align:center; color:#64748b;">&#128259; Loading your requests...</p>');

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: { action: 'som_merchant_get_product_requests', nonce: nonce },
						success: function(res) {
							if (res.success) {
								var list = res.data.requests;
								if (!list || list.length === 0) {
									$wrap.html('<p style="padding:24px; text-align:center; color:#64748b;">You have not submitted any product requests yet.</p>');
									return;
								}

								var html = '<table class="som-catalog-table"><thead><tr><th>Product Name</th><th>Details</th><th>Date</th><th>Status</th></tr></thead><tbody>';
								$.each(list, function(i, r) {
									html += '<tr>';
									html += '<td><strong>' + escapeHtml(r.product_name) + '</strong></td>';

									var meta = [];
									if (r.brand) meta.push('Brand: ' + escapeHtml(r.brand));
									if (r.unit) meta.push('Unit: ' + escapeHtml(r.unit));
									if (r.barcode) meta.push('Barcode: ' + escapeHtml(r.barcode));
									html += '<td><span style="font-size:0.78rem; color:#64748b;">' + meta.join(' &bull; ') + '</span>';
									if (r.admin_notes) {
										html += '<br /><span style="font-size:0.75rem; color:#2563eb;">Admin Note: ' + escapeHtml(r.admin_notes) + '</span>';
									}
									html += '</td>';

									html += '<td><span style="font-size:0.8rem; color:#64748b;">' + r.created_at + '</span></td>';

									var badgeClass = r.status;
									if (r.status === 'pending') badgeClass = 'inactive';
									if (r.status === 'reviewed') badgeClass = 'active';
									if (r.status === 'completed') badgeClass = 'instock';
									if (r.status === 'rejected') badgeClass = 'outofstock';

									html += '<td><span class="som-cat-badge ' + badgeClass + '">' + r.status.toUpperCase() + '</span></td>';
									html += '</tr>';
								});
								html += '</tbody></table>';

								$wrap.html(html);
							} else {
								$wrap.html('<p style="padding:20px; text-align:center; color:#ef4444;">' + (res.data.message || 'Error loading requests') + '</p>');
							}
						}
					});
				}
			});
		}
		</script>
		<?php
		return ob_get_clean();
	}
}