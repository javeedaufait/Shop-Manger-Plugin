<?php
/**
 * Admin Product Requests Management Module (Approved – Ready to Add Workflow).
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Admin_Product_Requests
 */
class SOM_Admin_Product_Requests {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );

		// Admin AJAX Endpoints.
		add_action( 'wp_ajax_som_admin_get_product_requests', array( __CLASS__, 'ajax_get_product_requests' ) );
		add_action( 'wp_ajax_som_admin_update_product_request', array( __CLASS__, 'ajax_update_product_request' ) );
		add_action( 'wp_ajax_som_admin_create_master_from_request', array( __CLASS__, 'ajax_create_master_from_request' ) );
	}

	/**
	 * Register Admin Submenu Page under "Shop Onboarding".
	 */
	public static function register_admin_menu() {
		add_submenu_page(
			'som-admin',
			__( 'Product Requests', 'nearmart' ),
			__( 'Product Requests', 'nearmart' ),
			'manage_options',
			'som-product-requests',
			array( __CLASS__, 'render_admin_requests_page' )
		);
	}

	/**
	 * Enqueue admin assets for Product Requests page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_admin_assets( $hook ) {
		if ( false === strpos( $hook, 'som-product-requests' ) ) {
			return;
		}
		wp_enqueue_script( 'jquery' );
		wp_enqueue_style( 'som-frontend-style', SOM_PLUGIN_URL . 'assets/css/som-frontend.css', array(), SOM_VERSION );
	}

	/**
	 * AJAX endpoint: Get Product Requests for Admin.
	 */
	public static function ajax_get_product_requests() {
		check_ajax_referer( 'som_admin_requests_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'nearmart' ) ), 403 );
		}

		$status   = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'all';
		$search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		$requests = SOM_Product_Request_Repository::get_admin_requests(
			array(
				'status' => $status,
				'search' => $search,
			)
		);

		$formatted = array();
		foreach ( $requests as $r ) {
			$master_title = '';
			$edit_url     = '';
			if ( $r->master_product_id ) {
				$mp = get_post( $r->master_product_id );
				if ( $mp ) {
					$master_title = $mp->post_title;
					$edit_url     = admin_url( 'post.php?post=' . $r->master_product_id . '&action=edit' );
				}
			}

			$formatted[] = array(
				'id'                => $r->id,
				'merchant_id'       => $r->merchant_id,
				'merchant_name'     => $r->merchant_name ? $r->merchant_name : 'Merchant #' . $r->merchant_id,
				'shop_id'           => $r->shop_id,
				'shop_name'         => $r->shop_name ? $r->shop_name : 'Shop #' . $r->shop_id,
				'product_name'      => $r->product_name,
				'brand'             => $r->brand ? $r->brand : '',
				'category'          => $r->category ? $r->category : '',
				'unit'              => $r->unit ? $r->unit : '',
				'barcode'           => $r->barcode ? $r->barcode : '',
				'notes'             => $r->notes ? $r->notes : '',
				'status'            => $r->status,
				'master_product_id' => $r->master_product_id,
				'master_title'      => $master_title,
				'edit_url'          => $edit_url,
				'admin_notes'       => $r->admin_notes ? $r->admin_notes : '',
				'created_at'        => date_i18n( 'M j, Y g:i a', strtotime( $r->created_at ) ),
			);
		}

		wp_send_json_success( array( 'requests' => $formatted ) );
	}

	/**
	 * Ensure shop product relationship exists with status 'pending_setup'.
	 *
	 * @param int $shop_id           Shop Post ID.
	 * @param int $master_product_id WooCommerce Master Product ID.
	 */
	private static function ensure_pending_shop_product( $shop_id, $master_product_id ) {
		$shop_id           = absint( $shop_id );
		$master_product_id = absint( $master_product_id );

		if ( ! $shop_id || ! $master_product_id ) {
			return;
		}

		$existing = nearmart_get_shop_product( $shop_id, $master_product_id );
		if ( ! $existing ) {
			nearmart_add_shop_product(
				$shop_id,
				$master_product_id,
				array(
					'price'        => 0.00,
					'stock_status' => 'instock',
					'status'       => 'pending_setup',
				)
			);
		}
	}

	/**
	 * AJAX endpoint: Create New WooCommerce Master Product from Merchant Request.
	 */
	public static function ajax_create_master_from_request() {
		check_ajax_referer( 'som_admin_requests_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'nearmart' ) ), 403 );
		}

		$request_id  = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		$title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$title_ml    = isset( $_POST['title_ml'] ) ? sanitize_text_field( wp_unslash( $_POST['title_ml'] ) ) : '';
		$category    = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
		$brand       = isset( $_POST['brand'] ) ? sanitize_text_field( wp_unslash( $_POST['brand'] ) ) : '';
		$unit        = isset( $_POST['unit'] ) ? sanitize_text_field( wp_unslash( $_POST['unit'] ) ) : '';
		$barcode     = isset( $_POST['barcode'] ) ? sanitize_text_field( wp_unslash( $_POST['barcode'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$admin_notes = isset( $_POST['admin_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ) ) : '';

		if ( ! $request_id || empty( $title ) ) {
			wp_send_json_error( array( 'message' => __( 'Request ID and Master Product Title are required.', 'nearmart' ) ) );
		}

		$req = SOM_Product_Request_Repository::get_request_by_id( $request_id );
		if ( ! $req ) {
			wp_send_json_error( array( 'message' => __( 'Product request not found.', 'nearmart' ) ) );
		}

		// Create WooCommerce Master Product Post
		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => $description,
				'post_status'  => 'publish',
				'post_type'    => 'product',
			)
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Failed to create WooCommerce master product.', 'nearmart' ) ) );
		}

		// Update product meta specs
		if ( ! empty( $brand ) ) {
			update_post_meta( $post_id, '_nearmart_brand_name', $brand );
		}
		if ( ! empty( $unit ) ) {
			update_post_meta( $post_id, '_nearmart_unit', $unit );
		}
		if ( ! empty( $barcode ) ) {
			update_post_meta( $post_id, '_nearmart_barcode', $barcode );
		}
		if ( ! empty( $title_ml ) ) {
			update_post_meta( $post_id, '_nearmart_name_ml', $title_ml );
		}

		// Assign taxonomy term if category provided
		if ( ! empty( $category ) ) {
			wp_set_object_terms( $post_id, $category, 'product_cat', true );
		}

		// Ensure shop product exists with pending_setup status
		self::ensure_pending_shop_product( $req->shop_id, $post_id );

		// Set request status to 'approved' (Approved – Ready to Add)
		$updated = SOM_Product_Request_Repository::update_request_status( $request_id, 'approved', $admin_notes, $post_id );

		if ( ! $updated ) {
			wp_send_json_error( array( 'message' => __( 'Master product created, but failed to update request status.', 'nearmart' ) ) );
		}

		$edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );

		wp_send_json_success(
			array(
				'message'      => __( 'Master product created! Request approved and ready for merchant setup.', 'nearmart' ),
				'product_id'   => $post_id,
				'product_name' => $title,
				'edit_url'     => $edit_url,
			)
		);
	}

	/**
	 * AJAX endpoint: Update Product Request Status & Master Product Association by Admin.
	 */
	public static function ajax_update_product_request() {
		check_ajax_referer( 'som_admin_requests_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'nearmart' ) ), 403 );
		}

		$request_id        = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		$status            = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'pending';
		$admin_notes       = isset( $_POST['admin_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ) ) : '';
		$master_product_id = isset( $_POST['master_product_id'] ) && '' !== $_POST['master_product_id'] ? absint( $_POST['master_product_id'] ) : null;

		if ( ! $request_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request ID.', 'nearmart' ) ) );
		}

		$req = SOM_Product_Request_Repository::get_request_by_id( $request_id );
		if ( ! $req ) {
			wp_send_json_error( array( 'message' => __( 'Product request not found.', 'nearmart' ) ) );
		}

		// Validation Rule: Cannot set status to approved or completed without a linked master product
		if ( in_array( $status, array( 'approved', 'completed' ), true ) && empty( $master_product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'A master product must be created or linked before approving or completing a request.', 'nearmart' ) ) );
		}

		if ( $master_product_id ) {
			$p = get_post( $master_product_id );
			if ( ! $p || 'product' !== $p->post_type ) {
				wp_send_json_error( array( 'message' => __( 'Invalid WooCommerce master product ID.', 'nearmart' ) ) );
			}
			self::ensure_pending_shop_product( $req->shop_id, $master_product_id );
		}

		$updated = SOM_Product_Request_Repository::update_request_status( $request_id, $status, $admin_notes, $master_product_id );

		if ( ! $updated ) {
			wp_send_json_error( array( 'message' => __( 'Failed to update request status.', 'nearmart' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Product request updated successfully!', 'nearmart' ) ) );
	}

	/**
	 * Render Admin Product Requests Page.
	 */
	public static function render_admin_requests_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nearmart' ) );
		}

		wp_enqueue_script( 'jquery' );
		wp_enqueue_style( 'som-frontend-style', SOM_PLUGIN_URL . 'assets/css/som-frontend.css', array(), SOM_VERSION );

		$nonce = wp_create_nonce( 'som_admin_requests_nonce' );

		?>
		<div class="wrap som-admin-wrap" style="max-width: 1200px; margin-top: 20px;">
			<h1 class="wp-heading-inline" style="font-size: 1.8rem; font-weight: 800; color: #0f172a; margin-bottom: 16px;">
				&#128221; <?php esc_html_e( 'Merchant Product Requests', 'nearmart' ); ?>
			</h1>
			<p style="color: #64748b; font-size: 0.95rem; margin-top: 4px; margin-bottom: 24px;">
				<?php esc_html_e( 'Review merchant product requests. Create or link a master product to approve requests. Merchants will then configure price & stock in their catalog.', 'nearmart' ); ?>
			</p>

			<!-- Filter Bar -->
			<div class="som-admin-card" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 20px;">
				<div style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap; justify-content: space-between;">
					<div style="display: flex; gap: 12px; flex: 1; min-width: 280px;">
						<input type="text" id="som_req_search" class="regular-text" placeholder="Search by requested name, brand, or shop..." style="height: 40px; border-radius: 6px; padding: 0 12px; width: 100%;" />
					</div>
					<div style="display: flex; gap: 10px;">
						<select id="som_req_status_filter" class="postform" style="height: 40px; border-radius: 6px;">
							<option value="all"><?php esc_html_e( 'All Statuses', 'nearmart' ); ?></option>
							<option value="pending"><?php esc_html_e( 'Pending Review', 'nearmart' ); ?></option>
							<option value="reviewed"><?php esc_html_e( 'Under Review', 'nearmart' ); ?></option>
							<option value="approved"><?php esc_html_e( 'Approved – Ready to Add', 'nearmart' ); ?></option>
							<option value="completed"><?php esc_html_e( 'Added to Catalog', 'nearmart' ); ?></option>
							<option value="rejected"><?php esc_html_e( 'Rejected', 'nearmart' ); ?></option>
						</select>
					</div>
				</div>
			</div>

			<!-- Requests Table Wrap -->
			<div class="som-admin-card" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
				<table class="wp-list-table widefat fixed striped table-view-list" style="border: 1px solid #e2e8f0; border-radius: 8px;">
					<thead>
						<tr>
							<th style="width: 60px;">ID</th>
							<th><?php esc_html_e( 'Shop & Merchant', 'nearmart' ); ?></th>
							<th><?php esc_html_e( 'Requested Product & Specs', 'nearmart' ); ?></th>
							<th><?php esc_html_e( 'Category', 'nearmart' ); ?></th>
							<th><?php esc_html_e( 'Date', 'nearmart' ); ?></th>
							<th><?php esc_html_e( 'Status', 'nearmart' ); ?></th>
							<th style="width: 110px; text-align: right;"><?php esc_html_e( 'Actions', 'nearmart' ); ?></th>
						</tr>
					</thead>
					<tbody id="som_admin_requests_tbody">
						<tr>
							<td colspan="7" style="text-align: center; padding: 24px; color: #64748b;">
								&#128259; <?php esc_html_e( 'Loading product requests...', 'nearmart' ); ?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<!-- MODAL: Review & Fulfill Product Request -->
		<div id="som_admin_request_modal" class="som-modal-overlay" style="display: none;">
			<div class="som-modal-content" style="max-width: 680px;">
				<div class="som-modal-header">
					<h3>&#128221; <?php esc_html_e( 'Review & Fulfill Product Request', 'nearmart' ); ?></h3>
					<button type="button" class="som-modal-close" onclick="document.getElementById('som_admin_request_modal').style.display='none';">&times;</button>
				</div>

				<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 16px;">
					<strong id="som_req_title" style="font-size: 1.05rem; color: #0f172a; display: block;"></strong>
					<div id="som_req_meta" style="font-size: 0.82rem; color: #64748b; margin-top: 4px;"></div>
					<div id="som_req_notes" style="font-size: 0.8rem; color: #334155; margin-top: 8px; font-style: italic;"></div>
				</div>

				<!-- Action Mode Selector -->
				<div class="som-modal-tab-bar">
					<button type="button" id="som_req_tab_create" class="button button-primary som-modal-tab-btn">
						&#10133; <?php esc_html_e( 'Create New Master Product', 'nearmart' ); ?>
					</button>
					<button type="button" id="som_req_tab_link" class="button button-secondary som-modal-tab-btn">
						&#128279; <?php esc_html_e( 'Link Existing Master / Update Status', 'nearmart' ); ?>
					</button>
				</div>

				<!-- TAB 1: Create New Master Product Pre-filled Form -->
				<form id="som_admin_form_create_master" style="margin-bottom: 10px;">
					<input type="hidden" id="som_create_req_id" value="" />

					<div class="som-form-group" style="margin-bottom: 12px;">
						<label for="som_create_title" class="som-label required"><?php esc_html_e( 'Master Product Name (English) *', 'nearmart' ); ?></label>
						<input type="text" id="som_create_title" class="som-input" required />
					</div>
					<div class="som-form-group" style="margin-bottom: 12px;">
						<label for="som_create_title_ml" class="som-label"><?php esc_html_e( 'Malayalam Product Name (Optional Display Override)', 'nearmart' ); ?></label>
						<input type="text" id="som_create_title_ml" class="som-input" placeholder="e.g. ഓർഗാനിക് മൾട്ടിഗ്രെയിൻ ആട്ട 5kg" />
					</div>

					<div class="som-form-row som-grid-2col">
						<div>
							<label for="som_create_category" class="som-label"><?php esc_html_e( 'Category', 'nearmart' ); ?></label>
							<input type="text" id="som_create_category" class="som-input" />
						</div>
						<div>
							<label for="som_create_brand" class="som-label"><?php esc_html_e( 'Brand', 'nearmart' ); ?></label>
							<input type="text" id="som_create_brand" class="som-input" />
						</div>
					</div>

					<div class="som-form-row som-grid-2col">
						<div>
							<label for="som_create_unit" class="som-label"><?php esc_html_e( 'Unit / Size', 'nearmart' ); ?></label>
							<input type="text" id="som_create_unit" class="som-input" />
						</div>
						<div>
							<label for="som_create_barcode" class="som-label"><?php esc_html_e( 'Barcode / SKU', 'nearmart' ); ?></label>
							<input type="text" id="som_create_barcode" class="som-input" />
						</div>
					</div>

					<div class="som-form-group" style="margin-bottom: 12px;">
						<label for="som_create_desc" class="som-label"><?php esc_html_e( 'Description / Notes', 'nearmart' ); ?></label>
						<textarea id="som_create_desc" class="som-input" rows="2"></textarea>
					</div>

					<div class="som-form-group" style="margin-bottom: 16px;">
						<label for="som_create_admin_notes" class="som-label"><?php esc_html_e( 'Admin Note to Merchant (Optional)', 'nearmart' ); ?></label>
						<input type="text" id="som_create_admin_notes" class="som-input" placeholder="e.g. Approved – Ready to add to your catalog." />
					</div>

					<button type="submit" id="som_btn_create_master" class="button button-primary button-large" style="width: 100%;">
						&#10133; <?php esc_html_e( 'Create Master Product & Approve Request', 'nearmart' ); ?>
					</button>
				</form>

				<!-- TAB 2: Link Existing Master or Update Status Form -->
				<form id="som_admin_form_update_request" style="display: none;">
					<input type="hidden" id="som_req_id" name="request_id" value="" />

					<div id="som_req_master_status_box" style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:10px 14px; margin-bottom:14px; display:none;">
						<strong style="color:#166534; font-size:0.9rem;" id="som_req_master_status_text"></strong>
						<div id="som_req_master_edit_link" style="margin-top:4px;"></div>
					</div>

					<div class="som-form-group" style="margin-bottom: 14px;">
						<label for="som_req_status" class="som-label required"><?php esc_html_e( 'Request Status', 'nearmart' ); ?></label>
						<select id="som_req_status" name="status" class="som-select" required>
							<option value="pending"><?php esc_html_e( 'Pending Review', 'nearmart' ); ?></option>
							<option value="reviewed"><?php esc_html_e( 'Under Review', 'nearmart' ); ?></option>
							<option value="approved"><?php esc_html_e( 'Approved – Ready to Add', 'nearmart' ); ?></option>
							<option value="completed"><?php esc_html_e( 'Added to Catalog', 'nearmart' ); ?></option>
							<option value="rejected"><?php esc_html_e( 'Rejected', 'nearmart' ); ?></option>
						</select>
					</div>

					<div class="som-form-group" style="margin-bottom: 14px;">
						<label for="som_req_master_id" class="som-label"><?php esc_html_e( 'Linked Master Product ID (WooCommerce Post ID)', 'nearmart' ); ?></label>
						<input type="number" id="som_req_master_id" name="master_product_id" class="som-input" placeholder="e.g. 35 (Enter WooCommerce Post ID to link)" />
					</div>

					<div class="som-form-group" style="margin-bottom: 18px;">
						<label for="som_req_admin_notes" class="som-label"><?php esc_html_e( 'Admin Notes / Message for Merchant', 'nearmart' ); ?></label>
						<textarea id="som_req_admin_notes" name="admin_notes" class="som-input" rows="3" placeholder="Add notes or instructions for the merchant..."></textarea>
					</div>

					<button type="submit" id="som_req_btn_save" class="button button-primary button-large" style="width: 100%;">
						&#128190; <?php esc_html_e( 'Save Status & Master Link', 'nearmart' ); ?>
					</button>
				</form>
			</div>
		</div>

		<script>
		if (typeof jQuery !== 'undefined') {
			jQuery(document).ready(function($) {
				var nonce = '<?php echo esc_js( $nonce ); ?>';
				var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';

				function escapeHtml(str) {
					return str ? $('<div>').text(str).html() : '';
				}

				function getStatusBadge(status) {
					var label = status.toUpperCase();
					var style = 'background:#f1f5f9; color:#475569;';

					if (status === 'pending') {
						label = 'Pending Review';
						style = 'background:#fef3c7; color:#92400e;';
					} else if (status === 'reviewed') {
						label = 'Under Review';
						style = 'background:#e0f2fe; color:#075985;';
					} else if (status === 'approved') {
						label = 'Approved – Ready to Add';
						style = 'background:#dcfce7; color:#15803d; font-weight:700;';
					} else if (status === 'completed') {
						label = 'Added to Catalog';
						style = 'background:#d1fae5; color:#065f46;';
					} else if (status === 'rejected') {
						label = 'Rejected';
						style = 'background:#fee2e2; color:#991b1b;';
					}

					return '<span style="font-size:0.75rem; padding:3px 8px; border-radius:10px; font-weight:600; ' + style + '">' + label + '</span>';
				}

				function loadRequests() {
					var $tbody = $('#som_admin_requests_tbody');
					$tbody.html('<tr><td colspan="7" style="text-align:center; padding: 20px; color:#64748b;">&#128259; Loading requests...</td></tr>');

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: {
							action: 'som_admin_get_product_requests',
							nonce: nonce,
							status: $('#som_req_status_filter').val(),
							search: $('#som_req_search').val()
						},
						success: function(res) {
							if (res.success) {
								var list = res.data.requests;
								if (!list || list.length === 0) {
									$tbody.html('<tr><td colspan="7" style="text-align:center; padding: 24px; color:#64748b;">No merchant product requests found.</td></tr>');
									return;
								}

								var html = '';
								$.each(list, function(i, req) {
									html += '<tr>';
									html += '<td><strong>#' + req.id + '</strong></td>';
									html += '<td><strong>' + escapeHtml(req.shop_name) + '</strong><br /><span style="font-size:0.75rem; color:#64748b;">by ' + escapeHtml(req.merchant_name) + '</span></td>';

									html += '<td><strong>' + escapeHtml(req.product_name) + '</strong>';
									var specs = [];
									if (req.brand) specs.push('Brand: ' + escapeHtml(req.brand));
									if (req.unit) specs.push('Unit: ' + escapeHtml(req.unit));
									if (req.barcode) specs.push('Barcode: ' + escapeHtml(req.barcode));
									if (specs.length > 0) {
										html += '<br /><span style="font-size:0.75rem; color:#64748b;">' + specs.join(' &bull; ') + '</span>';
									}
									if (req.master_product_id) {
										html += '<br /><span style="font-size:0.75rem; color:#16a34a; font-weight:600;">Linked Master: ID #' + req.master_product_id + (req.master_title ? ' (' + escapeHtml(req.master_title) + ')' : '') + '</span>';
										if (req.edit_url) {
											html += ' &bull; <a href="' + req.edit_url + '" target="_blank" style="font-size:0.75rem; color:#2563eb; font-weight:600; text-decoration:underline;">Edit Master ↗</a>';
										}
									}
									html += '</td>';

									html += '<td><span class="som-cat-meta-tag">' + (req.category ? escapeHtml(req.category) : '—') + '</span></td>';
									html += '<td><span style="font-size:0.8rem; color:#64748b;">' + req.created_at + '</span></td>';
									html += '<td>' + getStatusBadge(req.status) + '</td>';
									html += '<td style="text-align:right;"><button type="button" class="button button-small som-btn-review-req" data-req=\'' + JSON.stringify(req) + '\'>Review</button></td>';
									html += '</tr>';
								});

								$tbody.html(html);
							} else {
								$tbody.html('<tr><td colspan="7" style="text-align:center; color:#ef4444;">' + (res.data.message || 'Error loading requests') + '</td></tr>');
							}
						}
					});
				}

				loadRequests();

				var timer = null;
				$('#som_req_search').on('keyup input', function() {
					clearTimeout(timer);
					timer = setTimeout(loadRequests, 400);
				});

				$('#som_req_status_filter').on('change', loadRequests);

				// Modal Tab Switchers
				$('#som_req_tab_create').on('click', function() {
					$('#som_req_tab_link').removeClass('button-primary').addClass('button-secondary');
					$(this).removeClass('button-secondary').addClass('button-primary');
					$('#som_admin_form_update_request').hide();
					$('#som_admin_form_create_master').show();
				});

				$('#som_req_tab_link').on('click', function() {
					$('#som_req_tab_create').removeClass('button-primary').addClass('button-secondary');
					$(this).removeClass('button-secondary').addClass('button-primary');
					$('#som_admin_form_create_master').hide();
					$('#som_admin_form_update_request').show();
				});

				// Open Review Modal & Pre-fill Create Form
				$(document).on('click', '.som-btn-review-req', function() {
					var req = $(this).data('req');
					if (!req) return;

					// Populate Create Master Form (Pre-filled)
					$('#som_create_req_id').val(req.id);
					$('#som_create_title').val(req.product_name || '');
					$('#som_create_category').val(req.category || '');
					$('#som_create_brand').val(req.brand || '');
					$('#som_create_unit').val(req.unit || '');
					$('#som_create_barcode').val(req.barcode || '');
					$('#som_create_desc').val(req.notes || '');
					$('#som_create_admin_notes').val(req.admin_notes || 'Approved – Ready to add to your catalog.');

					// Populate Link/Update Form
					$('#som_req_id').val(req.id);
					$('#som_req_title').text('Requested: ' + req.product_name);

					var meta = [];
					meta.push('Shop: ' + escapeHtml(req.shop_name));
					meta.push('Merchant: ' + escapeHtml(req.merchant_name));
					if (req.brand) meta.push('Brand: ' + escapeHtml(req.brand));
					if (req.category) meta.push('Category: ' + escapeHtml(req.category));
					if (req.unit) meta.push('Unit: ' + escapeHtml(req.unit));
					if (req.barcode) meta.push('Barcode: ' + escapeHtml(req.barcode));
					$('#som_req_meta').html(meta.join(' &bull; '));

					if (req.notes) {
						$('#som_req_notes').html('Merchant Notes: "' + escapeHtml(req.notes) + '"').show();
					} else {
						$('#som_req_notes').hide();
					}

					if (req.master_product_id) {
						$('#som_req_master_status_text').text('Linked Master Product: ID #' + req.master_product_id + (req.master_title ? ' (' + req.master_title + ')' : ''));
						if (req.edit_url) {
							$('#som_req_master_edit_link').html('<a href="' + req.edit_url + '" target="_blank" style="color:#2563eb; font-weight:700; text-decoration:underline;">Edit WooCommerce Master Product ↗</a>');
						} else {
							$('#som_req_master_edit_link').html('');
						}
						$('#som_req_master_status_box').show();
					} else {
						$('#som_req_master_status_box').hide();
					}

					$('#som_req_status').val(req.status);
					$('#som_req_master_id').val(req.master_product_id || '');
					$('#som_req_admin_notes').val(req.admin_notes || '');

					// Default tab selection
					if (req.master_product_id) {
						$('#som_req_tab_link').click();
					} else {
						$('#som_req_tab_create').click();
					}

					$('#som_admin_request_modal').show();
				});

				// Form 1: Create Master Product Submit
				$('#som_admin_form_create_master').on('submit', function(e) {
					e.preventDefault();
					var $btn = $('#som_btn_create_master');
					$btn.prop('disabled', true).text('Creating Master Product...');

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: {
							action: 'som_admin_create_master_from_request',
							nonce: nonce,
							request_id: $('#som_create_req_id').val(),
							title: $('#som_create_title').val(),
							title_ml: $('#som_create_title_ml').val(),
							category: $('#som_create_category').val(),
							brand: $('#som_create_brand').val(),
							unit: $('#som_create_unit').val(),
							barcode: $('#som_create_barcode').val(),
							description: $('#som_create_desc').val(),
							admin_notes: $('#som_create_admin_notes').val()
						},
						success: function(res) {
							$btn.prop('disabled', false).html('&#10133; Create Master Product & Approve Request');
							if (res.success) {
								alert(res.data.message || 'Master product created successfully!');
								$('#som_admin_request_modal').hide();
								loadRequests();
							} else {
								alert(res.data.message || 'Error creating master product.');
							}
						},
						error: function() {
							$btn.prop('disabled', false).html('&#10133; Create Master Product & Approve Request');
							alert('<?php echo esc_js( __( 'Server error creating master product.', 'nearmart' ) ); ?>');
						}
					});
				});

				// Form 2: Update Request Status Submit
				$('#som_admin_form_update_request').on('submit', function(e) {
					e.preventDefault();
					var $btn = $('#som_req_btn_save');
					$btn.prop('disabled', true).text('Updating...');

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: {
							action: 'som_admin_update_product_request',
							nonce: nonce,
							request_id: $('#som_req_id').val(),
							status: $('#som_req_status').val(),
							master_product_id: $('#som_req_master_id').val(),
							admin_notes: $('#som_req_admin_notes').val()
						},
						success: function(res) {
							$btn.prop('disabled', false).html('&#128190; Save Status & Master Link');
							if (res.success) {
								alert(res.data.message || 'Request updated successfully!');
								$('#som_admin_request_modal').hide();
								loadRequests();
							} else {
								alert(res.data.message || 'Error updating request.');
							}
						},
						error: function() {
							$btn.prop('disabled', false).html('&#128190; Save Status & Master Link');
							alert('<?php echo esc_js( __( 'Server error updating request.', 'nearmart' ) ); ?>');
						}
					});
				});
			});
		}
		</script>
		<?php
	}
}