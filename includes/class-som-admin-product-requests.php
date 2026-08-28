<?php
/**
 * Admin Product Requests Management Module (Phase 8).
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
	}

	/**
	 * Register Admin Submenu Page under "Shop Onboarding".
	 */
	public static function register_admin_menu() {
		add_submenu_page(
			'som-admin',
			__( 'Product Requests', 'shop-onboarding-manager' ),
			__( 'Product Requests', 'shop-onboarding-manager' ),
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
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ), 403 );
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
			if ( $r->master_product_id ) {
				$mp = get_post( $r->master_product_id );
				if ( $mp ) {
					$master_title = $mp->post_title;
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
				'admin_notes'       => $r->admin_notes ? $r->admin_notes : '',
				'created_at'        => date_i18n( 'M j, Y g:i a', strtotime( $r->created_at ) ),
			);
		}

		wp_send_json_success( array( 'requests' => $formatted ) );
	}

	/**
	 * AJAX endpoint: Update Product Request Status & Master Product Association by Admin.
	 */
	public static function ajax_update_product_request() {
		check_ajax_referer( 'som_admin_requests_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ), 403 );
		}

		$request_id        = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		$status            = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'pending';
		$admin_notes       = isset( $_POST['admin_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ) ) : '';
		$master_product_id = isset( $_POST['master_product_id'] ) && '' !== $_POST['master_product_id'] ? absint( $_POST['master_product_id'] ) : null;

		if ( ! $request_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request ID.', 'shop-onboarding-manager' ) ) );
		}

		if ( $master_product_id ) {
			$p = get_post( $master_product_id );
			if ( ! $p || 'product' !== $p->post_type ) {
				wp_send_json_error( array( 'message' => __( 'Invalid WooCommerce master product ID.', 'shop-onboarding-manager' ) ) );
			}
		}

		$updated = SOM_Product_Request_Repository::update_request_status( $request_id, $status, $admin_notes, $master_product_id );

		if ( ! $updated ) {
			wp_send_json_error( array( 'message' => __( 'Failed to update request status.', 'shop-onboarding-manager' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Product request updated successfully!', 'shop-onboarding-manager' ) ) );
	}

	/**
	 * Render Admin Product Requests Page.
	 */
	public static function render_admin_requests_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'shop-onboarding-manager' ) );
		}

		wp_enqueue_script( 'jquery' );
		wp_enqueue_style( 'som-frontend-style', SOM_PLUGIN_URL . 'assets/css/som-frontend.css', array(), SOM_VERSION );

		$nonce = wp_create_nonce( 'som_admin_requests_nonce' );

		?>
		<div class="wrap som-admin-wrap" style="max-width: 1200px; margin-top: 20px;">
			<h1 class="wp-heading-inline" style="font-size: 1.8rem; font-weight: 800; color: #0f172a; margin-bottom: 16px;">
				&#128221; <?php esc_html_e( 'Merchant Product Requests', 'shop-onboarding-manager' ); ?>
			</h1>
			<p style="color: #64748b; font-size: 0.95rem; margin-top: 4px; margin-bottom: 24px;">
				<?php esc_html_e( 'Review new product requests submitted by merchants. Create the master product in WooCommerce Products, then link it and mark the request as Completed.', 'shop-onboarding-manager' ); ?>
			</p>

			<!-- Filter Bar -->
			<div class="som-admin-card" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 20px;">
				<div style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap; justify-content: space-between;">
					<div style="display: flex; gap: 12px; flex: 1; min-width: 280px;">
						<input type="text" id="som_req_search" class="regular-text" placeholder="Search by requested name, brand, or shop..." style="height: 40px; border-radius: 6px; padding: 0 12px; width: 100%;" />
					</div>
					<div style="display: flex; gap: 10px;">
						<select id="som_req_status_filter" class="postform" style="height: 40px; border-radius: 6px;">
							<option value="all"><?php esc_html_e( 'All Statuses', 'shop-onboarding-manager' ); ?></option>
							<option value="pending"><?php esc_html_e( 'Pending', 'shop-onboarding-manager' ); ?></option>
							<option value="reviewed"><?php esc_html_e( 'Reviewed', 'shop-onboarding-manager' ); ?></option>
							<option value="completed"><?php esc_html_e( 'Completed', 'shop-onboarding-manager' ); ?></option>
							<option value="rejected"><?php esc_html_e( 'Rejected', 'shop-onboarding-manager' ); ?></option>
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
							<th><?php esc_html_e( 'Shop & Merchant', 'shop-onboarding-manager' ); ?></th>
							<th><?php esc_html_e( 'Requested Product & Specs', 'shop-onboarding-manager' ); ?></th>
							<th><?php esc_html_e( 'Category', 'shop-onboarding-manager' ); ?></th>
							<th><?php esc_html_e( 'Date', 'shop-onboarding-manager' ); ?></th>
							<th><?php esc_html_e( 'Status', 'shop-onboarding-manager' ); ?></th>
							<th style="width: 110px; text-align: right;"><?php esc_html_e( 'Actions', 'shop-onboarding-manager' ); ?></th>
						</tr>
					</thead>
					<tbody id="som_admin_requests_tbody">
						<tr>
							<td colspan="7" style="text-align: center; padding: 24px; color: #64748b;">
								&#128259; <?php esc_html_e( 'Loading product requests...', 'shop-onboarding-manager' ); ?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<!-- MODAL: Review & Update Product Request -->
		<div id="som_admin_request_modal" class="som-modal-overlay" style="display: none;">
			<div class="som-modal-content">
				<div class="som-modal-header">
					<h3>&#128221; <?php esc_html_e( 'Review Product Request', 'shop-onboarding-manager' ); ?></h3>
					<button type="button" class="som-modal-close" onclick="document.getElementById('som_admin_request_modal').style.display='none';">&times;</button>
				</div>

				<form id="som_admin_form_update_request">
					<input type="hidden" id="som_req_id" name="request_id" value="" />

					<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 16px;">
						<strong id="som_req_title" style="font-size: 1.05rem; color: #0f172a; display: block;"></strong>
						<div id="som_req_meta" style="font-size: 0.82rem; color: #64748b; margin-top: 4px;"></div>
						<div id="som_req_notes" style="font-size: 0.8rem; color: #334155; margin-top: 8px; font-style: italic;"></div>
					</div>

					<div class="som-form-group" style="margin-bottom: 14px;">
						<label for="som_req_status" class="som-label required"><?php esc_html_e( 'Request Status', 'shop-onboarding-manager' ); ?></label>
						<select id="som_req_status" name="status" class="som-select" required>
							<option value="pending"><?php esc_html_e( 'Pending (Awaiting Review)', 'shop-onboarding-manager' ); ?></option>
							<option value="reviewed"><?php esc_html_e( 'Reviewed (In Progress)', 'shop-onboarding-manager' ); ?></option>
							<option value="completed"><?php esc_html_e( 'Completed (Master Product Created)', 'shop-onboarding-manager' ); ?></option>
							<option value="rejected"><?php esc_html_e( 'Rejected', 'shop-onboarding-manager' ); ?></option>
						</select>
					</div>

					<div class="som-form-group" style="margin-bottom: 14px;">
						<label for="som_req_master_id" class="som-label"><?php esc_html_e( 'Linked Master Product ID (WooCommerce Post ID)', 'shop-onboarding-manager' ); ?></label>
						<input type="number" id="som_req_master_id" name="master_product_id" class="som-input" placeholder="e.g. 35 (Optional - link after creating WooCommerce product)" />
					</div>

					<div class="som-form-group" style="margin-bottom: 18px;">
						<label for="som_req_admin_notes" class="som-label"><?php esc_html_e( 'Admin Notes / Message for Merchant', 'shop-onboarding-manager' ); ?></label>
						<textarea id="som_req_admin_notes" name="admin_notes" class="som-input" rows="3" placeholder="Add notes or instructions for the merchant..."></textarea>
					</div>

					<button type="submit" id="som_req_btn_save" class="button button-primary button-large" style="width: 100%;">
						&#128190; <?php esc_html_e( 'Update Request Status', 'shop-onboarding-manager' ); ?>
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
										html += '<br /><span style="font-size:0.75rem; color:#16a34a; font-weight:600;">Linked Master Product: ID #' + req.master_product_id + (req.master_title ? ' (' + escapeHtml(req.master_title) + ')' : '') + '</span>';
									}
									html += '</td>';

									html += '<td><span class="som-cat-meta-tag">' + (req.category ? escapeHtml(req.category) : '—') + '</span></td>';
									html += '<td><span style="font-size:0.8rem; color:#64748b;">' + req.created_at + '</span></td>';

									var badgeClass = req.status;
									if (req.status === 'pending') badgeClass = 'inactive';
									if (req.status === 'reviewed') badgeClass = 'active';
									if (req.status === 'completed') badgeClass = 'instock';
									if (req.status === 'rejected') badgeClass = 'outofstock';

									html += '<td><span class="som-cat-badge ' + badgeClass + '">' + req.status.toUpperCase() + '</span></td>';

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

				// Open Review Modal
				$(document).on('click', '.som-btn-review-req', function() {
					var req = $(this).data('req');
					if (!req) return;

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

					$('#som_req_status').val(req.status);
					$('#som_req_master_id').val(req.master_product_id || '');
					$('#som_req_admin_notes').val(req.admin_notes || '');

					$('#som_admin_request_modal').show();
				});

				// Update Request Form Submit
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
							$btn.prop('disabled', false).html('&#128190; Update Request Status');
							if (res.success) {
								alert(res.data.message || 'Request updated successfully!');
								$('#som_admin_request_modal').hide();
								loadRequests();
							} else {
								alert(res.data.message || 'Error updating request.');
							}
						},
						error: function() {
							$btn.prop('disabled', false).html('&#128190; Update Request Status');
							alert('Server error updating request.');
						}
					});
				});
			});
		}
		</script>
		<?php
	}
}