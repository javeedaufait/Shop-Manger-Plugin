<?php
/**
 * Dedicated Merchant Catalog Module (Exclusion & Auto-Sync Bugfixes).
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
		add_action( 'wp_ajax_som_merchant_fulfill_approved_request', array( __CLASS__, 'ajax_fulfill_approved_request' ) );
	}

	/**
	 * AJAX endpoint: Merchant Submit Request for Product.
	 */
	public static function ajax_request_new_product() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );

		if ( ! $shop_id || ! nearmart_user_can_manage_shop( $user_id, $shop_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'nearmart' ) ), 403 );
		}

		$product_name = isset( $_POST['product_name'] ) ? sanitize_text_field( wp_unslash( $_POST['product_name'] ) ) : '';
		$brand        = isset( $_POST['brand'] ) ? sanitize_text_field( wp_unslash( $_POST['brand'] ) ) : '';
		$category     = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
		$unit         = isset( $_POST['unit'] ) ? sanitize_text_field( wp_unslash( $_POST['unit'] ) ) : '';
		$barcode      = isset( $_POST['barcode'] ) ? sanitize_text_field( wp_unslash( $_POST['barcode'] ) ) : '';
		$notes        = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		if ( empty( $product_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Product name is required.', 'nearmart' ) ) );
		}

		if ( nearmart_has_pending_product_request( $shop_id, $product_name ) ) {
			wp_send_json_error( array( 'message' => sprintf( __( 'A product request for "%s" is already pending review.', 'nearmart' ), esc_html( $product_name ) ) ) );
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
			wp_send_json_error( array( 'message' => __( 'Failed to submit product request. Please try again.', 'nearmart' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Your product request has been submitted successfully! Admin will review it shortly.', 'nearmart' ) ) );
	}

	/**
	 * AJAX endpoint: Get Submitted Product Requests for Logged-In Merchant.
	 */
	public static function ajax_get_merchant_product_requests() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );

		if ( ! $shop_id || ! nearmart_user_can_manage_shop( $user_id, $shop_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'nearmart' ) ), 403 );
		}

		$requests  = nearmart_get_merchant_product_requests( $shop_id, $user_id );
		$formatted = array();

		foreach ( $requests as $r ) {
			$master_title  = '';
			$is_in_catalog = false;

			if ( $r->master_product_id ) {
				$mp = get_post( $r->master_product_id );
				if ( $mp ) {
					$master_title = $mp->post_title;
				}

				// Check if merchant ALREADY has this product active in their catalog
				$sp = nearmart_get_shop_product( $shop_id, $r->master_product_id );
				if ( $sp && 'active' === $sp->status ) {
					$is_in_catalog = true;
					// Auto-sync request status to completed if product is already active
					if ( 'completed' !== $r->status ) {
						SOM_Product_Request_Repository::update_request_status( $r->id, 'completed', $r->admin_notes, $r->master_product_id );
						$r->status = 'completed';
					}
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
				'is_in_catalog'     => $is_in_catalog,
				'master_product_id' => $r->master_product_id,
				'master_title'      => $master_title,
				'admin_notes'       => $r->admin_notes ? $r->admin_notes : '',
				'created_at'        => date_i18n( 'M j, Y g:i a', strtotime( $r->created_at ) ),
			);
		}

		wp_send_json_success( array( 'requests' => $formatted ) );
	}

	/**
	 * AJAX endpoint: Merchant Fulfill Approved Product Request (Add to Catalog).
	 */
	public static function ajax_fulfill_approved_request() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );

		if ( ! $shop_id || ! nearmart_user_can_manage_shop( $user_id, $shop_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'nearmart' ) ), 403 );
		}

		$request_id     = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		$price          = isset( $_POST['price'] ) ? floatval( $_POST['price'] ) : 0.00;
		$sale_price     = ( isset( $_POST['sale_price'] ) && '' !== $_POST['sale_price'] ) ? floatval( $_POST['sale_price'] ) : null;
		$stock_status   = isset( $_POST['stock_status'] ) ? sanitize_key( $_POST['stock_status'] ) : 'instock';
		$stock_quantity = ( isset( $_POST['stock_quantity'] ) && '' !== $_POST['stock_quantity'] ) ? intval( $_POST['stock_quantity'] ) : null;
		$shop_sku       = isset( $_POST['shop_sku'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_sku'] ) ) : null;

		if ( ! $request_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product request ID.', 'nearmart' ) ) );
		}

		$req = SOM_Product_Request_Repository::get_request_by_id( $request_id );

		if ( ! $req || (int) $req->shop_id !== (int) $shop_id ) {
			wp_send_json_error( array( 'message' => __( 'Product request not found for your shop.', 'nearmart' ) ) );
		}

		if ( 'approved' !== $req->status || ! $req->master_product_id ) {
			wp_send_json_error( array( 'message' => __( 'This product request is not in Approved – Ready to Add status.', 'nearmart' ) ) );
		}

		$master_product_id = absint( $req->master_product_id );

		// Reuse existing shop product relationship if already created
		$existing = nearmart_get_shop_product( $shop_id, $master_product_id );

		if ( $existing ) {
			nearmart_update_shop_product_by_id(
				$existing->id,
				array(
					'price'          => $price,
					'sale_price'     => $sale_price,
					'stock_status'   => $stock_status,
					'stock_quantity' => $stock_quantity,
					'shop_sku'       => $shop_sku,
					'status'         => 'active',
				)
			);
		} else {
			nearmart_add_shop_product(
				$shop_id,
				$master_product_id,
				array(
					'price'          => $price,
					'sale_price'     => $sale_price,
					'stock_status'   => $stock_status,
					'stock_quantity' => $stock_quantity,
					'shop_sku'       => $shop_sku,
					'status'         => 'active',
				)
			);
		}

		// Update product request status to completed (Added to Catalog)
		SOM_Product_Request_Repository::update_request_status( $request_id, 'completed', $req->admin_notes, $master_product_id );

		wp_send_json_success( array( 'message' => __( 'Product added to your shop catalog successfully!', 'nearmart' ) ) );
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
				<span>&#127978;</span> <strong><?php esc_html_e( 'Merchant Portal', 'nearmart' ); ?></strong>
			</div>
			<div class="som-portal-nav-links">
				<a href="<?php echo esc_url( $dashboard_url ); ?>" class="som-nav-link<?php echo esc_attr( $dash_active ); ?>">
					&#127968; <?php esc_html_e( 'Dashboard', 'nearmart' ); ?>
				</a>
				<a href="<?php echo esc_url( $catalog_url ); ?>" class="som-nav-link<?php echo esc_attr( $cat_active ); ?>">
					&#128722; <?php esc_html_e( 'My Catalog', 'nearmart' ); ?>
				</a>
				<a href="#" id="som_btn_open_my_requests" class="som-nav-link">
					&#128221; <?php esc_html_e( 'My Product Requests', 'nearmart' ); ?>
				</a>
				<a href="<?php echo esc_url( $logout_url ); ?>" class="som-nav-link logout">
					&#128682; <?php esc_html_e( 'Log Out', 'nearmart' ); ?>
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
				esc_html__( 'Please log in with a merchant or staff account to access your shop catalog.', 'nearmart' ) .
				' <br /><br /><a href="' . esc_url( home_url( '/merchant-login/' ) ) . '" class="som-submit-btn som-btn-secondary" style="text-decoration:none; display:inline-block; width:auto; padding:10px 20px;">' .
				esc_html__( 'Go to Merchant Login &rarr;', 'nearmart' ) . '</a></div></div>';
		}

		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );
		if ( ! $shop_id ) {
			return '<div class="som-merchant-card"><div class="som-card-header"><h2>' .
				esc_html__( 'My Catalog', 'nearmart' ) . '</h2></div><p>' .
				esc_html__( 'No shop is currently linked to your merchant user account. Please contact NearMart support.', 'nearmart' ) .
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
					<h2>&#128722; <?php printf( esc_html__( 'My Shop Catalog — %s', 'nearmart' ), esc_html( $shop_name ) ); ?></h2>
					<p><?php esc_html_e( 'Manage prices, availability, and products for your store catalog.', 'nearmart' ); ?></p>
				</div>
				<div style="display: flex; gap: 10px; flex-wrap: wrap;">
					<button type="button" id="som_btn_open_add_modal" class="som-submit-btn" style="width: auto; padding: 12px 20px; min-height: 44px;">
						&#10133; <?php esc_html_e( 'Add Product to Catalog', 'nearmart' ); ?>
					</button>
				</div>
			</div>

			<!-- Main Dedicated Catalog Card -->
			<div class="som-dash-card full-width" style="margin-top: 20px;">
				<!-- Compact Scalable Search & Filter Bar -->
				<div class="som-catalog-bar" style="display:flex; gap:12px; flex-wrap:wrap; align-items:center; justify-content:space-between; margin-bottom:16px;">
					<div class="som-catalog-search-wrap" style="flex:1; min-width:260px;">
						<input type="text" id="som_cat_search" class="som-input" placeholder="Search by product name, brand, SKU or barcode..." style="width:100%; min-height:42px;" />
					</div>
					<div class="som-catalog-filters" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
						<select id="som_cat_category_filter" class="som-select" style="min-height: 42px; min-width:140px;">
							<option value="all"><?php esc_html_e( 'All Categories', 'nearmart' ); ?></option>
						</select>
						<select id="som_cat_stock_filter" class="som-select" style="min-height: 42px; min-width:130px;">
							<option value="all"><?php esc_html_e( 'All Availability', 'nearmart' ); ?></option>
							<option value="instock"><?php esc_html_e( 'Available', 'nearmart' ); ?></option>
							<option value="outofstock"><?php esc_html_e( 'Unavailable', 'nearmart' ); ?></option>
						</select>
						<select id="som_cat_per_page" class="som-select" style="min-height: 42px; min-width:120px;">
							<option value="25"><?php esc_html_e( '25 per page', 'nearmart' ); ?></option>
							<option value="50"><?php esc_html_e( '50 per page', 'nearmart' ); ?></option>
							<option value="100"><?php esc_html_e( '100 per page', 'nearmart' ); ?></option>
						</select>
					</div>
				</div>

				<!-- Compact Streamlined Catalog Table Wrap -->
				<div class="som-catalog-table-wrap">
					<table class="som-catalog-table compact-table" style="width:100%; border-collapse:collapse;">
						<thead>
							<tr style="border-bottom:2px solid #e2e8f0; background:#f8fafc; text-align:left;">
								<th style="width: 50px; padding:10px 12px;"><?php esc_html_e( 'Image', 'nearmart' ); ?></th>
								<th style="padding:10px 12px;"><?php esc_html_e( 'Product Name & Specs', 'nearmart' ); ?></th>
								<th style="padding:10px 12px;"><?php esc_html_e( 'Category', 'nearmart' ); ?></th>
								<th style="padding:10px 12px;"><?php esc_html_e( 'Shop Price', 'nearmart' ); ?></th>
								<th style="padding:10px 12px;"><?php esc_html_e( 'Availability', 'nearmart' ); ?></th>
								<th style="width: 120px; padding:10px 12px; text-align: right;"><?php esc_html_e( 'Actions', 'nearmart' ); ?></th>
							</tr>
						</thead>
						<tbody id="som_catalog_tbody">
							<tr>
								<td colspan="6" style="text-align: center; padding: 24px; color: #64748b;">
									&#128259; <?php esc_html_e( 'Loading catalog items...', 'nearmart' ); ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Scalable Pagination Bar -->
				<div class="som-catalog-pagination" style="display:flex; justify-content:space-between; align-items:center; margin-top:16px; flex-wrap:wrap; gap:12px;">
					<span id="som_catalog_info" style="color: #64748b; font-size: 0.88rem; font-weight: 500;">Showing 0 products</span>
					<div class="som-pagination-btns" style="display:flex; gap:8px;">
						<button type="button" id="som_cat_prev_btn" class="som-btn-icon" disabled>&larr; Previous</button>
						<button type="button" id="som_cat_next_btn" class="som-btn-icon" disabled>Next &rarr;</button>
					</div>
				</div>
			</div>

			<!-- Response Message Alert -->
			<div id="som_dash_msg" class="som-response-msg"></div>
		</div>

		<!-- MODAL 1: Dual-Mode Add Product Modal (Merchant-Friendly Terminology) -->
		<div id="som_add_product_modal" class="som-modal-overlay" style="display: none;">
			<div class="som-modal-content" style="max-width: 680px;">
				<div class="som-modal-header">
					<h3>&#10133; <?php esc_html_e( 'Add Product to Catalog', 'nearmart' ); ?></h3>
					<button type="button" class="som-modal-close" onclick="document.getElementById('som_add_product_modal').style.display='none';">&times;</button>
				</div>

				<!-- Dual-Mode Tab Controls -->
				<div style="display: flex; gap: 8px; border-bottom: 2px solid #e2e8f0; margin-bottom: 18px;">
					<button type="button" id="som_tab_btn_master" class="som-modal-tab-btn active" style="padding: 10px 16px; border: none; background: none; font-weight: 700; color: #2563eb; border-bottom: 3px solid #2563eb; cursor: pointer;">
						&#128065; <?php esc_html_e( 'Add an Existing Product', 'nearmart' ); ?>
					</button>
					<button type="button" id="som_tab_btn_standalone" class="som-modal-tab-btn" style="padding: 10px 16px; border: none; background: none; font-weight: 700; color: #64748b; border-bottom: 3px solid transparent; cursor: pointer;">
						&#10133; <?php esc_html_e( 'Add a New Product', 'nearmart' ); ?>
					</button>
				</div>

				<!-- TAB 1: Add an Existing Product -->
				<div id="som_tab_content_master" class="som-tab-content">
					<p style="font-size: 0.88rem; color: #64748b; margin: 0 0 14px 0;">
						<?php esc_html_e( 'Search the NearMart product catalog and add an existing product to your shop.', 'nearmart' ); ?>
					</p>

					<div class="som-form-group">
						<label for="som_master_search" class="som-label"><?php esc_html_e( 'Search Product (Name, Brand, SKU, or Barcode)', 'nearmart' ); ?></label>
						<input type="text" id="som_master_search" class="som-input" placeholder="<?php esc_attr_e( 'Search by product name, brand, SKU or barcode...', 'nearmart' ); ?>" />
						<div id="som_master_results" class="som-master-search-results"></div>
					</div>

					<form id="som_form_add_catalog_product" style="display: none; border-top: 1px solid #e2e8f0; padding-top: 16px;">
						<input type="hidden" id="som_add_product_id" name="product_id" value="" />
						<p style="font-weight: 700; color: #16a34a; margin-bottom: 12px;" id="som_add_selected_title"></p>

						<div class="som-form-row">
							<div class="som-form-group">
								<label for="som_add_price" class="som-label required"><?php esc_html_e( 'Shop Price (₹)', 'nearmart' ); ?></label>
								<input type="number" step="0.01" id="som_add_price" name="price" class="som-input" required placeholder="0.00" />
							</div>
							<div class="som-form-group">
								<label for="som_add_sale_price" class="som-label"><?php esc_html_e( 'Sale Price (₹)', 'nearmart' ); ?></label>
								<input type="number" step="0.01" id="som_add_sale_price" name="sale_price" class="som-input" placeholder="Optional" />
							</div>
						</div>

						<div class="som-form-row">
							<div class="som-form-group">
								<label for="som_add_stock_status" class="som-label"><?php esc_html_e( 'Availability', 'nearmart' ); ?></label>
								<select id="som_add_stock_status" name="stock_status" class="som-select">
									<option value="instock"><?php esc_html_e( 'Available', 'nearmart' ); ?></option>
									<option value="outofstock"><?php esc_html_e( 'Unavailable', 'nearmart' ); ?></option>
								</select>
							</div>
							<div class="som-form-group">
								<label for="som_add_stock_quantity" class="som-label"><?php esc_html_e( 'Stock Qty', 'nearmart' ); ?></label>
								<input type="number" id="som_add_stock_quantity" name="stock_quantity" class="som-input" placeholder="Optional" />
							</div>
						</div>

						<div class="som-form-row">
							<div class="som-form-group">
								<label for="som_add_shop_sku" class="som-label"><?php esc_html_e( 'Shop SKU', 'nearmart' ); ?></label>
								<input type="text" id="som_add_shop_sku" name="shop_sku" class="som-input" placeholder="e.g. STORE-ITEM-01 (Optional)" />
							</div>
							<div class="som-form-group">
								<label for="som_add_status" class="som-label"><?php esc_html_e( 'Listing Status', 'nearmart' ); ?></label>
								<select id="som_add_status" name="status" class="som-select">
									<option value="active"><?php esc_html_e( 'Active (Visible to customers)', 'nearmart' ); ?></option>
									<option value="inactive"><?php esc_html_e( 'Inactive (Hidden)', 'nearmart' ); ?></option>
								</select>
							</div>
						</div>

						<button type="submit" id="som_btn_save_add" class="som-submit-btn">
							&#128190; <?php esc_html_e( 'Save to My Catalog', 'nearmart' ); ?>
						</button>
					</form>
				</div>

				<!-- TAB 2: Add a New Product -->
				<div id="som_tab_content_standalone" class="som-tab-content" style="display: none;">
					<p style="font-size: 0.88rem; color: #64748b; margin-bottom: 14px;">
						<?php esc_html_e( 'Add a product that is not currently in the NearMart catalog. It will initially be available only in your shop.', 'nearmart' ); ?>
					</p>

					<!-- Lightweight Master Similarity Banner -->
					<div id="som_standalone_suggestion_banner" style="display: none; background: #eff6ff; border: 1px solid #93c5fd; border-radius: 8px; padding: 12px; margin-bottom: 14px;">
						<div style="display: flex; gap: 10px; align-items: center; justify-content: space-between;">
							<div style="font-size: 0.85rem; color: #1e40af;">
								<strong>💡 <?php esc_html_e( 'Similar Existing Product Found:', 'nearmart' ); ?></strong>
								<span id="som_suggested_master_title" style="font-weight: 700;"></span>
							</div>
							<div style="display: flex; gap: 6px;">
								<button type="button" id="som_btn_use_suggested_master" class="button button-small button-primary" style="font-size: 0.8rem;">
									&#128279; <?php esc_html_e( 'Use Existing Product', 'nearmart' ); ?>
								</button>
								<button type="button" id="som_btn_dismiss_suggestion" class="button button-small" style="font-size: 0.8rem;">
									&times; <?php esc_html_e( 'Dismiss', 'nearmart' ); ?>
								</button>
							</div>
						</div>
					</div>

					<form id="som_form_add_standalone_product">
						<div class="som-form-group">
							<label for="som_st_name" class="som-label required"><?php esc_html_e( 'Product Name', 'nearmart' ); ?></label>
							<input type="text" id="som_st_name" name="custom_name" class="som-input" required placeholder="e.g. Local Fresh Country Milk 1L" />
						</div>

						<div class="som-form-row">
							<div class="som-form-group">
								<label for="som_st_category" class="som-label"><?php esc_html_e( 'Category (Optional)', 'nearmart' ); ?></label>
								<input type="text" id="som_st_category" name="custom_category" class="som-input" placeholder="e.g. Dairy & Milk" />
							</div>
							<div class="som-form-group">
								<label for="som_st_brand" class="som-label"><?php esc_html_e( 'Brand (Optional)', 'nearmart' ); ?></label>
								<input type="text" id="som_st_brand" name="custom_brand" class="som-input" placeholder="e.g. Local Farm" />
							</div>
						</div>

						<div class="som-form-row">
							<div class="som-form-group">
								<label for="som_st_unit" class="som-label"><?php esc_html_e( 'Approximate Unit/Size (Optional)', 'nearmart' ); ?></label>
								<input type="text" id="som_st_unit" name="custom_unit" class="som-input" placeholder="e.g. 1L, 500g, 10 pcs" />
							</div>
							<div class="som-form-group">
								<label for="som_st_barcode" class="som-label"><?php esc_html_e( 'Barcode / SKU (Optional)', 'nearmart' ); ?></label>
								<input type="text" id="som_st_barcode" name="custom_barcode" class="som-input" placeholder="e.g. 8909999000111" />
							</div>
						</div>

						<div class="som-form-row">
							<div class="som-form-group">
								<label for="som_st_price" class="som-label required"><?php esc_html_e( 'Shop Price (₹)', 'nearmart' ); ?></label>
								<input type="number" step="0.01" id="som_st_price" name="price" class="som-input" required placeholder="0.00" />
							</div>
							<div class="som-form-group">
								<label for="som_st_sale_price" class="som-label"><?php esc_html_e( 'Sale Price (₹)', 'nearmart' ); ?></label>
								<input type="number" step="0.01" id="som_st_sale_price" name="sale_price" class="som-input" placeholder="Optional" />
							</div>
						</div>

						<div class="som-form-row">
							<div class="som-form-group">
								<label for="som_st_stock_status" class="som-label"><?php esc_html_e( 'Availability', 'nearmart' ); ?></label>
								<select id="som_st_stock_status" name="stock_status" class="som-select">
									<option value="instock"><?php esc_html_e( 'Available', 'nearmart' ); ?></option>
									<option value="outofstock"><?php esc_html_e( 'Unavailable', 'nearmart' ); ?></option>
								</select>
							</div>
							<div class="som-form-group">
								<label for="som_st_stock_quantity" class="som-label"><?php esc_html_e( 'Stock Qty', 'nearmart' ); ?></label>
								<input type="number" id="som_st_stock_quantity" name="stock_quantity" class="som-input" placeholder="Optional" />
							</div>
						</div>

						<div class="som-form-row">
							<div class="som-form-group">
								<label for="som_st_shop_sku" class="som-label"><?php esc_html_e( 'Shop SKU', 'nearmart' ); ?></label>
								<input type="text" id="som_st_shop_sku" name="shop_sku" class="som-input" placeholder="e.g. LOCAL-MILK-01 (Optional)" />
							</div>
							<div class="som-form-group">
								<label for="som_st_status" class="som-label"><?php esc_html_e( 'Listing Status', 'nearmart' ); ?></label>
								<select id="som_st_status" name="status" class="som-select">
									<option value="active"><?php esc_html_e( 'Active (Visible to customers)', 'nearmart' ); ?></option>
									<option value="inactive"><?php esc_html_e( 'Inactive (Hidden)', 'nearmart' ); ?></option>
								</select>
							</div>
						</div>

						<button type="submit" id="som_btn_save_standalone" class="som-submit-btn">
							&#128190; <?php esc_html_e( 'Save New Product', 'nearmart' ); ?>
						</button>
					</form>
				</div>
			</div>
		</div>

		<!-- MODAL 2: Edit Catalog Product Modal -->
		<div id="som_edit_product_modal" class="som-modal-overlay" style="display: none;">
			<div class="som-modal-content">
				<div class="som-modal-header">
					<h3>&#9998; <?php esc_html_e( 'Edit Catalog Product', 'nearmart' ); ?></h3>
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
							&#8505; <?php esc_html_e( 'Master product specifications are managed by platform admins and cannot be changed here.', 'nearmart' ); ?>
						</p>
					</div>

					<!-- Editable Product Specs Box (For Standalone Items) -->
					<div id="som_edit_standalone_specs_box" style="display:none; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:14px; margin-bottom:16px;">
						<p style="font-size:0.82rem; font-weight:700; color:#166534; margin-bottom:10px;">
							&#10133; <?php esc_html_e( 'Shop Product Specifications', 'nearmart' ); ?>
						</p>
						<div class="som-form-group">
							<label for="som_edit_custom_name" class="som-label required"><?php esc_html_e( 'Product Name', 'nearmart' ); ?></label>
							<input type="text" id="som_edit_custom_name" name="custom_name" class="som-input" />
						</div>

						<div class="som-form-row">
							<div class="som-form-group">
								<label for="som_edit_custom_category" class="som-label"><?php esc_html_e( 'Category', 'nearmart' ); ?></label>
								<input type="text" id="som_edit_custom_category" name="custom_category" class="som-input" />
							</div>
							<div class="som-form-group">
								<label for="som_edit_custom_brand" class="som-label"><?php esc_html_e( 'Brand', 'nearmart' ); ?></label>
								<input type="text" id="som_edit_custom_brand" name="custom_brand" class="som-input" />
							</div>
						</div>

						<div class="som-form-row">
							<div class="som-form-group">
								<label for="som_edit_custom_unit" class="som-label"><?php esc_html_e( 'Unit / Size', 'nearmart' ); ?></label>
								<input type="text" id="som_edit_custom_unit" name="custom_unit" class="som-input" />
							</div>
							<div class="som-form-group">
								<label for="som_edit_custom_barcode" class="som-label"><?php esc_html_e( 'Barcode / SKU', 'nearmart' ); ?></label>
								<input type="text" id="som_edit_custom_barcode" name="custom_barcode" class="som-input" />
							</div>
						</div>
					</div>

					<div class="som-form-row">
						<div class="som-form-group">
							<label for="som_edit_price" class="som-label required"><?php esc_html_e( 'Shop Price (₹)', 'nearmart' ); ?></label>
							<input type="number" step="0.01" id="som_edit_price" name="price" class="som-input" required />
						</div>
						<div class="som-form-group">
							<label for="som_edit_sale_price" class="som-label"><?php esc_html_e( 'Sale Price (₹)', 'nearmart' ); ?></label>
							<input type="number" step="0.01" id="som_edit_sale_price" name="sale_price" class="som-input" placeholder="Optional" />
						</div>
					</div>

					<div class="som-form-row">
						<div class="som-form-group">
							<label for="som_edit_stock_status" class="som-label"><?php esc_html_e( 'Availability', 'nearmart' ); ?></label>
							<select id="som_edit_stock_status" name="stock_status" class="som-select">
								<option value="instock"><?php esc_html_e( 'Available', 'nearmart' ); ?></option>
								<option value="outofstock"><?php esc_html_e( 'Unavailable', 'nearmart' ); ?></option>
							</select>
						</div>
						<div class="som-form-group">
							<label for="som_edit_stock_quantity" class="som-label"><?php esc_html_e( 'Stock Qty', 'nearmart' ); ?></label>
							<input type="number" id="som_edit_stock_quantity" name="stock_quantity" class="som-input" placeholder="Optional" />
						</div>
					</div>

					<div class="som-form-row">
						<div class="som-form-group">
							<label for="som_edit_shop_sku" class="som-label"><?php esc_html_e( 'Shop SKU', 'nearmart' ); ?></label>
							<input type="text" id="som_edit_shop_sku" name="shop_sku" class="som-input" placeholder="e.g. STORE-ITEM-01 (Optional)" />
						</div>
						<div class="som-form-group">
							<label for="som_edit_status" class="som-label"><?php esc_html_e( 'Listing Status', 'nearmart' ); ?></label>
							<select id="som_edit_status" name="status" class="som-select">
								<option value="active"><?php esc_html_e( 'Active', 'nearmart' ); ?></option>
								<option value="inactive"><?php esc_html_e( 'Inactive', 'nearmart' ); ?></option>
							</select>
						</div>
					</div>

					<button type="submit" id="som_btn_save_edit" class="som-submit-btn">
						&#128190; <?php esc_html_e( 'Update Product', 'nearmart' ); ?>
					</button>
				</form>
			</div>
		</div>

		<!-- MODAL 3: Merchant Request Product Modal -->
		<div id="som_request_product_modal" class="som-modal-overlay" style="display: none;">
			<div class="som-modal-content">
				<div class="som-modal-header">
					<h3>&#10133; <?php esc_html_e( 'Request Product', 'nearmart' ); ?></h3>
					<button type="button" class="som-modal-close" onclick="document.getElementById('som_request_product_modal').style.display='none';">&times;</button>
				</div>

				<p style="font-size: 0.88rem; color: #64748b; margin-bottom: 16px;">
					<?php esc_html_e( 'Can\'t find a product in our catalog? Submit a request below. Admin will review and add the product to the NearMart platform.', 'nearmart' ); ?>
				</p>

				<form id="som_form_request_new_product">
					<div class="som-form-group">
						<label for="som_req_product_name" class="som-label required"><?php esc_html_e( 'Product Name', 'nearmart' ); ?></label>
						<input type="text" id="som_req_product_name" name="product_name" class="som-input" required placeholder="e.g. Organic Multigrain Atta 5kg" />
					</div>

					<div class="som-form-row">
						<div class="som-form-group">
							<label for="som_req_brand" class="som-label"><?php esc_html_e( 'Brand (Optional)', 'nearmart' ); ?></label>
							<input type="text" id="som_req_brand" name="brand" class="som-input" placeholder="e.g. Aashirvaad" />
						</div>
						<div class="som-form-group">
							<label for="som_req_category" class="som-label"><?php esc_html_e( 'Category (Optional)', 'nearmart' ); ?></label>
							<input type="text" id="som_req_category" name="category" class="som-input" placeholder="e.g. Groceries" />
						</div>
					</div>

					<div class="som-form-row">
						<div class="som-form-group">
							<label for="som_req_unit" class="som-label"><?php esc_html_e( 'Approximate Unit/Size (Optional)', 'nearmart' ); ?></label>
							<input type="text" id="som_req_unit" name="unit" class="som-input" placeholder="e.g. 5kg, 500ml, 10 pcs" />
						</div>
						<div class="som-form-group">
							<label for="som_req_barcode" class="som-label"><?php esc_html_e( 'Barcode / SKU (Optional)', 'nearmart' ); ?></label>
							<input type="text" id="som_req_barcode" name="barcode" class="som-input" placeholder="e.g. 890123456789" />
						</div>
					</div>

					<div class="som-form-group">
						<label for="som_req_notes" class="som-label"><?php esc_html_e( 'Additional Notes / Description (Optional)', 'nearmart' ); ?></label>
						<textarea id="som_req_notes" name="notes" class="som-input" rows="3" placeholder="Provide any additional details to help admin identify the product..."></textarea>
					</div>

					<button type="submit" id="som_btn_submit_req" class="som-submit-btn">
						&#128238; <?php esc_html_e( 'Submit Request', 'nearmart' ); ?>
					</button>
				</form>
			</div>
		</div>

		<!-- MODAL 4: Merchant View My Product Requests Modal -->
		<div id="som_my_requests_modal" class="som-modal-overlay" style="display: none;">
			<div class="som-modal-content" style="max-width: 760px;">
				<div class="som-modal-header">
					<h3>&#128221; <?php esc_html_e( 'My Product Requests', 'nearmart' ); ?></h3>
					<button type="button" class="som-modal-close" onclick="document.getElementById('som_my_requests_modal').style.display='none';">&times;</button>
				</div>

				<div id="som_my_requests_list_wrap" style="max-height: 440px; overflow-y: auto;">
					<p style="padding:20px; text-align:center; color:#64748b;">&#128259; Loading your requests...</p>
				</div>
			</div>
		</div>

		<!-- MODAL 5: Fulfill Approved Product Request (Add to My Catalog) -->
		<div id="som_fulfill_request_modal" class="som-modal-overlay" style="display: none;">
			<div class="som-modal-content" style="max-width: 600px;">
				<div class="som-modal-header">
					<h3>&#10133; <?php esc_html_e( 'Add Approved Product to My Catalog', 'nearmart' ); ?></h3>
					<button type="button" class="som-modal-close" onclick="document.getElementById('som_fulfill_request_modal').style.display='none';">&times;</button>
				</div>

				<form id="som_form_fulfill_approved_request">
					<input type="hidden" id="som_fulfill_req_id" name="request_id" value="" />

					<div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px; margin-bottom: 16px;">
						<strong id="som_fulfill_title" style="font-size: 1.05rem; color: #166534; display: block;"></strong>
						<div id="som_fulfill_meta" style="font-size: 0.82rem; color: #15803d; margin-top: 4px;"></div>
					</div>

					<div class="som-form-row">
						<div class="som-form-group">
							<label for="som_fulfill_price" class="som-label required"><?php esc_html_e( 'Shop Price (₹)', 'nearmart' ); ?></label>
							<input type="number" step="0.01" id="som_fulfill_price" name="price" class="som-input" required placeholder="0.00" />
						</div>
						<div class="som-form-group">
							<label for="som_fulfill_sale_price" class="som-label"><?php esc_html_e( 'Sale Price (₹)', 'nearmart' ); ?></label>
							<input type="number" step="0.01" id="som_fulfill_sale_price" name="sale_price" class="som-input" placeholder="Optional" />
						</div>
					</div>

					<div class="som-form-row">
						<div class="som-form-group">
							<label for="som_fulfill_stock_status" class="som-label"><?php esc_html_e( 'Availability', 'nearmart' ); ?></label>
							<select id="som_fulfill_stock_status" name="stock_status" class="som-select">
								<option value="instock"><?php esc_html_e( 'Available', 'nearmart' ); ?></option>
								<option value="outofstock"><?php esc_html_e( 'Unavailable', 'nearmart' ); ?></option>
							</select>
						</div>
						<div class="som-form-group">
							<label for="som_fulfill_stock_quantity" class="som-label"><?php esc_html_e( 'Stock Qty', 'nearmart' ); ?></label>
							<input type="number" id="som_fulfill_stock_quantity" name="stock_quantity" class="som-input" placeholder="Optional" />
						</div>
					</div>

					<div class="som-form-group" style="margin-bottom: 16px;">
						<label for="som_fulfill_shop_sku" class="som-label"><?php esc_html_e( 'Shop SKU (Optional)', 'nearmart' ); ?></label>
						<input type="text" id="som_fulfill_shop_sku" name="shop_sku" class="som-input" placeholder="e.g. STORE-ITEM-01" />
					</div>

					<button type="submit" id="som_btn_save_fulfill" class="som-submit-btn">
						&#128190; <?php esc_html_e( 'Add Product to My Catalog', 'nearmart' ); ?>
					</button>
				</form>
			</div>
		</div>

		<!-- Catalog Inline JavaScript Handler -->
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
					$tbody.html('<tr><td colspan="6" style="text-align:center; padding: 20px; color:#64748b;">&#128259; Loading catalog...</td></tr>');

					var perPage = parseInt($('#som_cat_per_page').val(), 10) || 25;

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: {
							action: 'som_merchant_get_catalog',
							nonce: nonce,
							search: $('#som_cat_search').val(),
							category: $('#som_cat_category_filter').val(),
							stock_status: $('#som_cat_stock_filter').val(),
							page: currentPage,
							per_page: perPage
						},
						success: function(res) {
							if (res.success) {
								var items = res.data.items;
								var totalCount = res.data.total_count || 0;
								var totalPages = res.data.total_pages || 1;

								// Populate dynamic categories dropdown if returned
								if (res.data.categories && res.data.categories.length > 0) {
									var currentCat = $('#som_cat_category_filter').val();
									var catHtml = '<option value="all">All Categories</option>';
									$.each(res.data.categories, function(idx, catName) {
										var sel = (catName === currentCat) ? ' selected' : '';
										catHtml += '<option value="' + escapeHtml(catName) + '"' + sel + '>' + escapeHtml(catName) + '</option>';
									});
									$('#som_cat_category_filter').html(catHtml);
								}

								if (!items || items.length === 0) {
									$tbody.html('<tr><td colspan="6" style="text-align:center; padding: 24px; color:#64748b;">No products found matching your filters. Click <strong>"Add Product to Catalog"</strong> to add items!</td></tr>');
									$('#som_catalog_info').text('Showing 0 products');
									$('#som_cat_prev_btn, #som_cat_next_btn').prop('disabled', true);
									return;
								}

								var html = '';
								$.each(items, function(i, item) {
									html += '<tr data-id="' + item.id + '" style="border-bottom:1px solid #f1f5f9;">';
									html += '<td style="padding:8px 12px;"><div class="som-cat-thumb-box" style="width:40px; height:40px; border-radius:6px; overflow:hidden; background:#f8fafc; display:flex; align-items:center; justify-content:center;">';
									if (item.thumb_url) {
										html += '<img src="' + item.thumb_url + '" alt="' + escapeHtml(item.title) + '" style="width:100%; height:100%; object-fit:cover;" />';
									} else {
										html += '<span class="som-cat-placeholder" style="font-size:1.1rem;">&#128230;</span>';
									}
									html += '</div></td>';

									// Compact Product Title (2-line clamp) & Inline Type Badge
									html += '<td style="padding:8px 12px;">';
									html += '<div style="line-height:1.25;">';
									html += '<strong style="display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; font-size:0.92rem; color:#1e293b;" title="' + escapeHtml(item.title) + '">' + escapeHtml(item.title) + '</strong>';
									html += '<div style="margin-top:3px; font-size:0.78rem; color:#64748b; display:flex; align-items:center; gap:6px; flex-wrap:wrap;">';
									if (item.is_standalone) {
										html += '<span style="font-size:0.68rem; color:#0369a1; background:#e0f2fe; padding:1px 6px; border-radius:4px; font-weight:700;">Shop Product</span>';
									} else {
										html += '<span style="font-size:0.68rem; color:#15803d; background:#dcfce7; padding:1px 6px; border-radius:4px; font-weight:700;">Catalog Product</span>';
									}
									var metaArr = [];
									if (item.brand) metaArr.push('Brand: ' + escapeHtml(item.brand));
									if (item.unit) metaArr.push('Unit: ' + escapeHtml(item.unit));
									if (item.shop_sku) metaArr.push('SKU: ' + escapeHtml(item.shop_sku));
									if (metaArr.length > 0) {
										html += '<span>' + metaArr.join(' &bull; ') + '</span>';
									}
									html += '</div></div></td>';

									html += '<td style="padding:8px 12px;"><span style="font-size:0.85rem; color:#475569;">' + escapeHtml(item.category) + '</span></td>';

									html += '<td style="padding:8px 12px;"><span class="som-cat-price" style="font-weight:700; color:#0f172a;">';
									if (item.sale_price) {
										html += '<del style="color:#94a3b8; font-weight:400; font-size:0.82rem; margin-right:4px;">₹' + item.price + '</del> ₹' + item.sale_price;
									} else {
										html += '₹' + item.price;
									}
									html += '</span></td>';

									var availLabel = item.stock_status === 'instock' ? 'Available' : 'Unavailable';
									var availColor = item.stock_status === 'instock' ? '#16a34a' : '#dc2626';
									var availBg = item.stock_status === 'instock' ? '#f0fdf4' : '#fef2f2';
									html += '<td style="padding:8px 12px;"><span style="font-size:0.78rem; font-weight:700; color:' + availColor + '; background:' + availBg + '; padding:3px 8px; border-radius:12px;">' + availLabel + '</span></td>';

									html += '<td style="padding:8px 12px; text-align:right;"><div class="som-cat-actions" style="display:flex; justify-content:flex-end; gap:4px;">';
									html += '<button type="button" class="som-btn-icon som-btn-edit-item" data-item=\'' + JSON.stringify(item) + '\' style="padding:4px 8px; font-size:0.8rem;">&#9998; Edit</button>';
									html += '<button type="button" class="som-btn-icon danger som-btn-remove-item" data-id="' + item.id + '" data-title="' + escapeHtml(item.title) + '" style="padding:4px 8px; font-size:0.8rem;">&#128465;</button>';
									html += '</div></td>';
									html += '</tr>';
								});

								$tbody.html(html);

								// Calculate exact range string e.g. "Showing 1–25 of 527 products"
								var startItem = totalCount > 0 ? (currentPage - 1) * perPage + 1 : 0;
								var endItem = Math.min(totalCount, currentPage * perPage);
								$('#som_catalog_info').text('Showing ' + startItem + '–' + endItem + ' of ' + totalCount + ' products');

								$('#som_cat_prev_btn').prop('disabled', currentPage <= 1);
								$('#som_cat_next_btn').prop('disabled', currentPage >= totalPages);
							} else {
								$tbody.html('<tr><td colspan="6" style="text-align:center; color:#ef4444; padding:20px;">' + (res.data.message || 'Error loading catalog') + '</td></tr>');
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

				$('#som_cat_category_filter, #som_cat_stock_filter, #som_cat_per_page').on('change', function() {
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
					var html = '<div style="text-align:center; padding:18px 12px 14px 12px;">';
					html += '<strong style="font-size:0.95rem; color:#1e293b; display:block; margin-bottom:8px;">Can\'t find what you\'re looking for?</strong>';
					html += '<div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-top:8px;">';
					html += '<button type="button" class="som-submit-btn som-btn-secondary som-btn-trigger-req" style="width:auto; padding:8px 16px; font-size:0.85rem;">&#128221; Request Product</button>';
					html += '<button type="button" class="som-submit-btn som-btn-trigger-add-new" style="width:auto; padding:8px 16px; font-size:0.85rem;">&#10133; Add New Product</button>';
					html += '</div></div>';
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
					$('#som_master_results').html('<p style="padding:14px; color:#64748b; margin:0; text-align:center;">&#128259; Searching products...</p>');

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

								html += '<div style="text-align:center; padding: 14px 0 6px 0; border-top:1px solid #f1f5f9;">';
								html += '<strong style="font-size:0.9rem; color:#1e293b; display:block; margin-bottom:6px;">Can\'t find what you\'re looking for?</strong>';
								html += '<div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-top:6px;">';
								html += '<button type="button" class="som-submit-btn som-btn-secondary som-btn-trigger-req" style="width:auto; padding:6px 14px; font-size:0.82rem;">&#128221; Request Product</button>';
								html += '<button type="button" class="som-submit-btn som-btn-trigger-add-new" style="width:auto; padding:6px 14px; font-size:0.82rem;">&#10133; Add New Product</button>';
								html += '</div></div>';
								$('#som_master_results').html(html);
							} else {
								var html = '<div style="text-align:center; padding:18px 12px 14px 12px;">';
								html += '<p style="color:#64748b; margin:0 0 10px 0;">No existing products found matching your search.</p>';
								html += '<strong style="font-size:0.95rem; color:#1e293b; display:block; margin-bottom:8px;">Can\'t find what you\'re looking for?</strong>';
								html += '<div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-top:8px;">';
								html += '<button type="button" class="som-submit-btn som-btn-secondary som-btn-trigger-req" style="width:auto; padding:8px 16px; font-size:0.85rem;">&#128221; Request Product</button>';
								html += '<button type="button" class="som-submit-btn som-btn-trigger-add-new" style="width:auto; padding:8px 16px; font-size:0.85rem;">&#10133; Add New Product</button>';
								html += '</div></div>';
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
						alert('<?php echo esc_js( __( 'This product is already in your catalog!', 'nearmart' ) ); ?>');
						return;
					}

					$('.som-master-item').removeClass('selected');
					$(this).addClass('selected');

					$('#som_add_product_id').val(m.product_id);
					$('#som_add_selected_title').html('&#10003; Selected Product: ' + escapeHtml(m.title) + (m.unit ? ' (' + escapeHtml(m.unit) + ')' : ''));

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

				// Trigger Add New Tab Switcher Event Listener
				$(document).on('click', '.som-btn-trigger-add-new', function() {
					var searchVal = $('#som_master_search').val().trim();
					$('#som_tab_btn_standalone').click();
					if (searchVal) {
						$('#som_st_name').val(searchVal);
					}
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
							alert('<?php echo esc_js( __( 'Server error adding product. Please try again.', 'nearmart' ) ); ?>');
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
							$btn.prop('disabled', false).html('&#128190; Save New Product');
							if (res.success) {
								alert(res.data.message || 'New product added successfully!');
								$('#som_add_product_modal').hide();
								$('#som_form_add_standalone_product')[0].reset();
								$('#som_standalone_suggestion_banner').hide();
								loadCatalog(1);
							} else {
								alert(res.data.message || 'Error adding new product.');
							}
						},
						error: function() {
							$btn.prop('disabled', false).html('&#128190; Save New Product');
							alert('<?php echo esc_js( __( 'Server error adding product. Please try again.', 'nearmart' ) ); ?>');
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
						$('#som_edit_master_specs_box').hide();
						$('#som_edit_standalone_specs_box').show();

						$('#som_edit_custom_name').val(item.title);
						$('#som_edit_custom_category').val(item.category || '');
						$('#som_edit_custom_brand').val(item.brand || '');
						$('#som_edit_custom_unit').val(item.unit || '');
						$('#som_edit_custom_barcode').val(item.barcode || '');
					} else {
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
							alert('<?php echo esc_js( __( 'Server error updating product. Please try again.', 'nearmart' ) ); ?>');
						}
					});
				});

				// Remove Product Action
				$(document).on('click', '.som-btn-remove-item', function() {
					var itemId = $(this).data('id');
					var title = $(this).data('title') || 'this product';

					var msg = 'Are you sure you want to remove "' + title + '" from your shop catalog?\n\n' +
						'This will only remove the item from your store.';

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
							alert('<?php echo esc_js( __( 'Server error removing product. Please try again.', 'nearmart' ) ); ?>');
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
							$btn.prop('disabled', false).html('&#128238; Submit Request');
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

				function getMerchantStatusBadge(status, isInCatalog) {
					var label = status.toUpperCase();
					var style = 'background:#f1f5f9; color:#475569;';

					if (status === 'pending') {
						label = 'Pending Review';
						style = 'background:#fef3c7; color:#92400e;';
					} else if (status === 'reviewed') {
						label = 'Under Review';
						style = 'background:#e0f2fe; color:#075985;';
					} else if (status === 'approved') {
						if (isInCatalog) {
							label = 'Added to Catalog';
							style = 'background:#d1fae5; color:#065f46; font-weight:700;';
						} else {
							label = 'Approved – Ready to Add';
							style = 'background:#dcfce7; color:#15803d; font-weight:700;';
						}
					} else if (status === 'completed') {
						label = 'Added to Catalog';
						style = 'background:#d1fae5; color:#065f46;';
					} else if (status === 'rejected') {
						label = 'Rejected';
						style = 'background:#fee2e2; color:#991b1b;';
					}

					return '<span class="som-cat-badge" style="font-size:0.75rem; padding:4px 8px; border-radius:10px; font-weight:600; ' + style + '">' + label + '</span>';
				}

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

								var html = '<table class="som-catalog-table" style="width:100%;"><thead><tr><th>Product Name</th><th>Details</th><th>Status</th><th style="text-align:right;">Action</th></tr></thead><tbody>';
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

									html += '<td>' + getMerchantStatusBadge(r.status, r.is_in_catalog) + '</td>';

									html += '<td style="text-align:right;">';
									if (r.status === 'approved' && !r.is_in_catalog) {
										html += '<button type="button" class="som-submit-btn som-btn-open-fulfill" data-req=\'' + JSON.stringify(r) + '\' style="width:auto; padding:6px 12px; font-size:0.8rem; font-weight:700; background:#16a34a;">&#10133; Add to My Catalog</button>';
									} else if (r.status === 'completed' || r.is_in_catalog) {
										html += '<span style="font-size:0.8rem; color:#16a34a; font-weight:600;">&#10003; Added to Catalog</span>';
									} else {
										html += '<span style="font-size:0.8rem; color:#94a3b8;">' + r.created_at + '</span>';
									}
									html += '</td>';
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

				// Open Fulfill Approved Request Modal
				$(document).on('click', '.som-btn-open-fulfill', function() {
					var req = $(this).data('req');
					if (!req) return;

					$('#som_fulfill_req_id').val(req.id);
					var title = req.master_title ? req.master_title : req.product_name;
					$('#som_fulfill_title').text('Selected Product: ' + title);

					var meta = [];
					if (req.brand) meta.push('Brand: ' + escapeHtml(req.brand));
					if (req.category) meta.push('Category: ' + escapeHtml(req.category));
					if (req.unit) meta.push('Unit: ' + escapeHtml(req.unit));
					$('#som_fulfill_meta').html(meta.join(' &bull; '));

					$('#som_fulfill_price').val('');
					$('#som_fulfill_sale_price').val('');
					$('#som_fulfill_stock_quantity').val('');
					$('#som_fulfill_shop_sku').val('');

					$('#som_my_requests_modal').hide();
					$('#som_fulfill_request_modal').show();
					$('#som_fulfill_price').focus();
				});

				// Submit Fulfill Form
				$('#som_form_fulfill_approved_request').on('submit', function(e) {
					e.preventDefault();
					var $btn = $('#som_btn_save_fulfill');
					$btn.prop('disabled', true).text('Saving to Catalog...');

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: {
							action: 'som_merchant_fulfill_approved_request',
							nonce: nonce,
							request_id: $('#som_fulfill_req_id').val(),
							price: $('#som_fulfill_price').val(),
							sale_price: $('#som_fulfill_sale_price').val(),
							stock_status: $('#som_fulfill_stock_status').val(),
							stock_quantity: $('#som_fulfill_stock_quantity').val(),
							shop_sku: $('#som_fulfill_shop_sku').val()
						},
						success: function(res) {
							$btn.prop('disabled', false).html('&#128190; Add Product to My Catalog');
							if (res.success) {
								alert(res.data.message || 'Product added to your shop catalog successfully!');
								$('#som_fulfill_request_modal').hide();
								loadCatalog(1);
							} else {
								alert(res.data.message || 'Error adding product.');
							}
						},
						error: function() {
							$btn.prop('disabled', false).html('&#128190; Add Product to My Catalog');
							alert('<?php echo esc_js( __( 'Server error adding product. Please try again.', 'nearmart' ) ); ?>');
						}
					});
				});
			});
		}
		</script>
		<?php
		return ob_get_clean();
	}
}