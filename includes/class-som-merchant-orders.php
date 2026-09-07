<?php
/**
 * Merchant Orders Management Module (APP-8.1 Foundation).
 *
 * Provides a dedicated merchant-facing order management dashboard,
 * strictly scoped to each merchant's own shop, with multi-status filtering,
 * historical line item snapshot inspection, and robust server-side ownership validation.
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Merchant_Orders
 */
class SOM_Merchant_Orders {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_shortcode( 'som_merchant_orders', array( __CLASS__, 'render_orders_shortcode' ) );

		// AJAX Endpoints
		add_action( 'wp_ajax_som_merchant_get_orders', array( __CLASS__, 'ajax_get_orders' ) );
		add_action( 'wp_ajax_som_merchant_get_order_details', array( __CLASS__, 'ajax_get_order_details' ) );
	}

	/**
	 * AJAX endpoint: Fetch paginated merchant orders with search and multi-status filtering.
	 */
	public static function ajax_get_orders() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id || ! nearmart_user_can_manage_shop_catalog( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'nearmart' ) ), 403 );
		}

		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );
		if ( ! $shop_id || ! nearmart_user_can_manage_shop( $user_id, $shop_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No store associated with your merchant account.', 'nearmart' ) ), 403 );
		}

		// Filters & Pagination parameters
		$search             = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$fulfillment_status = isset( $_POST['fulfillment_status'] ) ? sanitize_key( $_POST['fulfillment_status'] ) : 'all';
		$pricing_status     = isset( $_POST['pricing_status'] ) ? sanitize_key( $_POST['pricing_status'] ) : 'all';
		$payment_status     = isset( $_POST['payment_status'] ) ? sanitize_key( $_POST['payment_status'] ) : 'all';
		$page               = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$limit              = isset( $_POST['per_page'] ) ? min( 100, max( 10, absint( $_POST['per_page'] ) ) ) : 25;

		// Meta query with strict server-side shop isolation
		$meta_query = array( 'relation' => 'AND' );
		$meta_query[] = array(
			'key'     => '_nearmart_shop_id',
			'value'   => $shop_id,
			'compare' => '=',
		);

		if ( 'all' !== $fulfillment_status && in_array( $fulfillment_status, SOM_Mobile_Orders::FULFILLMENT_STATUSES, true ) ) {
			$meta_query[] = array(
				'key'     => '_nearmart_fulfillment_status',
				'value'   => $fulfillment_status,
				'compare' => '=',
			);
		}

		if ( 'all' !== $pricing_status && in_array( $pricing_status, SOM_Mobile_Orders::PRICING_STATUSES, true ) ) {
			$meta_query[] = array(
				'key'     => '_nearmart_pricing_status',
				'value'   => $pricing_status,
				'compare' => '=',
			);
		}

		if ( 'all' !== $payment_status && in_array( $payment_status, SOM_Mobile_Orders::PAYMENT_STATUSES, true ) ) {
			$meta_query[] = array(
				'key'     => '_nearmart_payment_status',
				'value'   => $payment_status,
				'compare' => '=',
			);
		}

		// Query orders using native WooCommerce wc_get_orders
		$query_args = array(
			'limit'      => $limit,
			'page'       => $page,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'paginate'   => true,
			'meta_query' => $meta_query,
		);

		// If numeric search, attempt order ID lookup
		if ( ! empty( $search ) && is_numeric( $search ) ) {
			$specific_order = wc_get_order( absint( $search ) );
			if ( $specific_order && (int) $specific_order->get_meta( '_nearmart_shop_id' ) === (int) $shop_id ) {
				$formatted = SOM_Mobile_Orders::format_order( $specific_order );
				wp_send_json_success(
					array(
						'orders'       => $formatted ? array( $formatted ) : array(),
						'total_count'  => 1,
						'total_pages'  => 1,
						'current_page' => 1,
						'per_page'     => $limit,
					)
				);
			}
		}

		$results = wc_get_orders( $query_args );
		$orders  = array();

		if ( $results && isset( $results->orders ) ) {
			foreach ( $results->orders as $wc_order ) {
				$formatted = SOM_Mobile_Orders::format_order( $wc_order );
				if ( ! $formatted ) {
					continue;
				}

				// Apply search term filter on order number, pickup code, customer name, customer phone
				if ( ! empty( $search ) ) {
					$match_num   = false !== stripos( (string) $formatted['order_number'], $search );
					$match_code  = false !== stripos( (string) $formatted['pickup_code'], $search );
					$match_cname = false !== stripos( (string) $formatted['customer_name'], $search );
					$match_cphon = false !== stripos( (string) $formatted['customer_phone'], $search );

					if ( ! $match_num && ! $match_code && ! $match_cname && ! $match_cphon ) {
						continue;
					}
				}

				// Include WooCommerce native status
				$formatted['wc_status']     = $wc_order->get_status();
				$formatted['items_count']   = count( $formatted['items'] );
				$formatted['date_created']  = $wc_order->get_date_created() ? $wc_order->get_date_created()->date_i18n( 'M j, Y, g:i A' ) : '—';
				$formatted['date_human']    = $wc_order->get_date_created() ? human_time_diff( $wc_order->get_date_created()->getTimestamp(), current_time( 'timestamp' ) ) . ' ago' : '';

				$orders[] = $formatted;
			}
		}

		$total_count = isset( $results->total ) ? (int) $results->total : count( $orders );
		$total_pages = isset( $results->max_num_pages ) ? max( 1, (int) $results->max_num_pages ) : 1;

		wp_send_json_success(
			array(
				'orders'       => $orders,
				'total_count'  => $total_count,
				'total_pages'  => $total_pages,
				'current_page' => $page,
				'per_page'     => $limit,
			)
		);
	}

	/**
	 * AJAX endpoint: Fetch complete historical order details for single order inspection.
	 */
	public static function ajax_get_order_details() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id || ! nearmart_user_can_manage_shop_catalog( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'nearmart' ) ), 403 );
		}

		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );
		if ( ! $shop_id || ! nearmart_user_can_manage_shop( $user_id, $shop_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No store associated with your merchant account.', 'nearmart' ) ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing order ID.', 'nearmart' ) ), 400 );
		}

		$wc_order = wc_get_order( $order_id );
		if ( ! ( $wc_order instanceof WC_Order ) ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'nearmart' ) ), 404 );
		}

		// Critical Server-Side Ownership Check
		$order_shop_id = absint( $wc_order->get_meta( '_nearmart_shop_id' ) );
		if ( $order_shop_id !== (int) $shop_id ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access. This order belongs to another store.', 'nearmart' ) ), 403 );
		}

		$formatted = SOM_Mobile_Orders::format_order( $wc_order );
		if ( ! $formatted ) {
			wp_send_json_error( array( 'message' => __( 'Failed to format order details.', 'nearmart' ) ), 500 );
		}

		$formatted['wc_status']    = $wc_order->get_status();
		$formatted['items_count']  = count( $formatted['items'] );
		$formatted['date_created'] = $wc_order->get_date_created() ? $wc_order->get_date_created()->date_i18n( 'M j, Y, g:i A' ) : '—';
		$formatted['date_human']   = $wc_order->get_date_created() ? human_time_diff( $wc_order->get_date_created()->getTimestamp(), current_time( 'timestamp' ) ) . ' ago' : '';

		wp_send_json_success( array( 'order' => $formatted ) );
	}

	/**
	 * Render [som_merchant_orders] shortcode.
	 */
	public static function render_orders_shortcode() {
		wp_enqueue_script( 'jquery' );
		wp_enqueue_style( 'som-frontend-style', SOM_PLUGIN_URL . 'assets/css/som-frontend.css', array(), SOM_VERSION );

		$user_id = get_current_user_id();
		if ( ! $user_id || ! nearmart_user_can_manage_shop_catalog( $user_id ) ) {
			return '<div class="som-merchant-card"><div class="som-response-msg error" style="display:block;">' .
				esc_html__( 'Please log in with a merchant or staff account to access your orders.', 'nearmart' ) .
				' <br /><br /><a href="' . esc_url( home_url( '/merchant-login/' ) ) . '" class="som-submit-btn som-btn-secondary" style="text-decoration:none; display:inline-block; width:auto; padding:10px 20px;">' .
				esc_html__( 'Go to Merchant Login &rarr;', 'nearmart' ) . '</a></div></div>';
		}

		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );
		if ( ! $shop_id ) {
			return '<div class="som-merchant-card"><div class="som-card-header"><h2>' .
				esc_html__( 'Orders Management', 'nearmart' ) . '</h2></div><p>' .
				esc_html__( 'No shop is currently linked to your merchant user account. Please contact NearMart support.', 'nearmart' ) .
				'</p></div>';
		}

		$shop_name = get_the_title( $shop_id );
		$nonce     = wp_create_nonce( 'som_merchant_dashboard_nonce' );
		$target_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

		ob_start();
		?>
		<div class="som-merchant-dashboard-wrap" id="som_merchant_orders_wrap">
			<!-- Portal Navigation Header -->
			<?php echo SOM_Merchant_Catalog::render_portal_nav( 'orders' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<!-- Orders Header Section -->
			<div class="som-dashboard-header" style="margin-top: 16px;">
				<div class="som-header-title">
					<h2>&#128230; <?php printf( esc_html__( 'Store Orders — %s', 'nearmart' ), esc_html( $shop_name ) ); ?></h2>
					<p><?php esc_html_e( 'View, filter, and review customer pickup orders placed at your store.', 'nearmart' ); ?></p>
				</div>
				<div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
					<button type="button" id="som_orders_refresh_btn" class="som-btn-icon" style="min-height: 42px; padding: 0 16px; font-weight: 600;">
						&#128259; <?php esc_html_e( 'Refresh Orders', 'nearmart' ); ?>
					</button>
				</div>
			</div>

			<!-- Main Orders Table Card -->
			<div class="som-dash-card full-width" style="margin-top: 20px;">
				<!-- Search & Multi-Status Filter Bar -->
				<div class="som-orders-filter-bar">
					<div class="som-orders-search-wrap">
						<input type="text" id="som_order_search" class="som-input" placeholder="<?php esc_attr_e( 'Search by Order ID (NM-ORD-...), Pickup Code, or Customer...', 'nearmart' ); ?>" />
					</div>
					<div class="som-orders-filters">
						<select id="som_filter_fulfillment" class="som-select" title="<?php esc_attr_e( 'Filter by Fulfillment Status', 'nearmart' ); ?>">
							<option value="all"><?php esc_html_e( 'All Fulfillment', 'nearmart' ); ?></option>
							<option value="pending">&#9203; <?php esc_html_e( 'Pending', 'nearmart' ); ?></option>
							<option value="accepted">&#10003; <?php esc_html_e( 'Accepted', 'nearmart' ); ?></option>
							<option value="preparing">&#129528; <?php esc_html_e( 'Preparing', 'nearmart' ); ?></option>
							<option value="ready_for_pickup">&#128722; <?php esc_html_e( 'Ready for Pickup', 'nearmart' ); ?></option>
							<option value="completed">&#127881; <?php esc_html_e( 'Completed', 'nearmart' ); ?></option>
							<option value="rejected">&#10060; <?php esc_html_e( 'Rejected', 'nearmart' ); ?></option>
							<option value="cancelled">&#128683; <?php esc_html_e( 'Cancelled', 'nearmart' ); ?></option>
						</select>

						<select id="som_filter_pricing" class="som-select" title="<?php esc_attr_e( 'Filter by Pricing Status', 'nearmart' ); ?>">
							<option value="all"><?php esc_html_e( 'All Pricing', 'nearmart' ); ?></option>
							<option value="fixed"><?php esc_html_e( 'Fixed Price', 'nearmart' ); ?></option>
							<option value="pending_verification">&#9878; <?php esc_html_e( 'Pending Weighing', 'nearmart' ); ?></option>
							<option value="finalized"><?php esc_html_e( 'Finalized', 'nearmart' ); ?></option>
						</select>

						<select id="som_filter_payment" class="som-select" title="<?php esc_attr_e( 'Filter by Payment Status', 'nearmart' ); ?>">
							<option value="all"><?php esc_html_e( 'All Payment', 'nearmart' ); ?></option>
							<option value="unpaid"><?php esc_html_e( 'Unpaid (Counter)', 'nearmart' ); ?></option>
							<option value="payment_pending"><?php esc_html_e( 'Payment Pending', 'nearmart' ); ?></option>
							<option value="paid"><?php esc_html_e( 'Paid', 'nearmart' ); ?></option>
							<option value="refunded"><?php esc_html_e( 'Refunded', 'nearmart' ); ?></option>
						</select>

						<select id="som_order_per_page" class="som-select" style="min-width: 110px;">
							<option value="10"><?php esc_html_e( '10 / page', 'nearmart' ); ?></option>
							<option value="25" selected><?php esc_html_e( '25 / page', 'nearmart' ); ?></option>
							<option value="50"><?php esc_html_e( '50 / page', 'nearmart' ); ?></option>
						</select>
					</div>
				</div>

				<!-- Orders Table Wrap -->
				<div class="som-catalog-table-wrap">
					<table class="som-catalog-table compact-table som-orders-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Order & Code', 'nearmart' ); ?></th>
								<th><?php esc_html_e( 'Date & Time', 'nearmart' ); ?></th>
								<th><?php esc_html_e( 'Customer', 'nearmart' ); ?></th>
								<th><?php esc_html_e( 'Items', 'nearmart' ); ?></th>
								<th><?php esc_html_e( 'Order Total', 'nearmart' ); ?></th>
								<th><?php esc_html_e( 'Fulfillment Status', 'nearmart' ); ?></th>
								<th><?php esc_html_e( 'Pricing / Payment', 'nearmart' ); ?></th>
								<th style="text-align: right;"><?php esc_html_e( 'Action', 'nearmart' ); ?></th>
							</tr>
						</thead>
						<tbody id="som_orders_tbody">
							<tr>
								<td colspan="8" style="text-align: center; padding: 28px; color: #64748b;">
									&#128259; <?php esc_html_e( 'Loading orders...', 'nearmart' ); ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Pagination Bar -->
				<div class="som-catalog-pagination" style="display:flex; justify-content:space-between; align-items:center; margin-top:16px; flex-wrap:wrap; gap:12px;">
					<span id="som_orders_info" style="color: #64748b; font-size: 0.88rem; font-weight: 500;"><?php esc_html_e( 'Showing 0 orders', 'nearmart' ); ?></span>
					<div class="som-pagination-btns" style="display:flex; gap:8px;">
						<button type="button" id="som_orders_prev_btn" class="som-btn-icon" disabled>&larr; <?php esc_html_e( 'Previous', 'nearmart' ); ?></button>
						<button type="button" id="som_orders_next_btn" class="som-btn-icon" disabled><?php esc_html_e( 'Next', 'nearmart' ); ?> &rarr;</button>
					</div>
				</div>
			</div>

			<!-- Response Message Alert -->
			<div id="som_orders_msg" class="som-response-msg"></div>
		</div>

		<!-- MODAL: Merchant Order Details Snapshot Inspection -->
		<div id="som_order_details_modal" class="som-modal-overlay" style="display: none;">
			<div class="som-modal-content" style="max-width: 780px;">
				<div class="som-modal-header">
					<div style="display: flex; align-items: center; gap: 10px;">
						<span style="font-size: 1.4rem;">&#128221;</span>
						<div>
							<h3 id="som_mod_order_title" style="margin: 0;"><?php esc_html_e( 'Order Details', 'nearmart' ); ?></h3>
							<span id="som_mod_order_date" style="font-size: 0.82rem; color: #64748b;"></span>
						</div>
					</div>
					<button type="button" class="som-modal-close" id="som_btn_close_order_modal">&times;</button>
				</div>

				<div class="som-modal-body" style="padding: 16px 20px 24px;">
					<div id="som_order_details_loading" style="text-align: center; padding: 30px; color: #64748b;">
						&#128259; <?php esc_html_e( 'Loading order snapshot details...', 'nearmart' ); ?>
					</div>

					<div id="som_order_details_content" style="display: none;">
						<!-- Order Header Cards Grid -->
						<div class="som-order-meta-grid">
							<!-- Pickup Code Card -->
							<div class="som-order-meta-box pickup-code-box">
								<span class="meta-box-label"><?php esc_html_e( 'Pickup Code', 'nearmart' ); ?></span>
								<span class="meta-box-code" id="som_det_pickup_code">——</span>
								<span class="meta-box-hint">&#128204; <?php esc_html_e( 'Verify at store pickup counter', 'nearmart' ); ?></span>
							</div>

							<!-- Customer Details Card -->
							<div class="som-order-meta-box">
								<span class="meta-box-label"><?php esc_html_e( 'Customer Information', 'nearmart' ); ?></span>
								<div class="meta-info-row">
									<strong>&#128100;</strong> <span id="som_det_customer_name">——</span>
								</div>
								<div class="meta-info-row">
									<strong>&#128222;</strong> <a href="#" id="som_det_customer_phone_link" style="color: #2563eb; text-decoration: none; font-weight: 600;">——</a>
								</div>
								<div id="som_det_note_wrap" class="meta-info-note" style="display: none;">
									<strong>&#128172; <?php esc_html_e( 'Customer Note:', 'nearmart' ); ?></strong>
									<span id="som_det_customer_note"></span>
								</div>
							</div>

							<!-- 3-Tier Status Overview Card -->
							<div class="som-order-meta-box">
								<span class="meta-box-label"><?php esc_html_e( 'Order Lifecycle Statuses', 'nearmart' ); ?></span>
								<div class="status-summary-row">
									<span class="status-title"><?php esc_html_e( 'Fulfillment:', 'nearmart' ); ?></span>
									<span id="som_det_fulfillment_badge" class="som-badge">——</span>
								</div>
								<div class="status-summary-row">
									<span class="status-title"><?php esc_html_e( 'Pricing:', 'nearmart' ); ?></span>
									<span id="som_det_pricing_badge" class="som-badge">——</span>
								</div>
								<div class="status-summary-row">
									<span class="status-title"><?php esc_html_e( 'Payment:', 'nearmart' ); ?></span>
									<span id="som_det_payment_badge" class="som-badge">——</span>
								</div>
								<div class="status-summary-row wc-row">
									<span class="status-title"><?php esc_html_e( 'WooCommerce:', 'nearmart' ); ?></span>
									<span id="som_det_wc_status" class="som-badge gray">——</span>
								</div>
							</div>
						</div>

						<!-- Line Items Snapshot Table -->
						<div style="margin-top: 20px;">
							<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
								<h4 style="margin: 0; font-size: 1rem; color: #1e293b;">
									&#128717; <?php esc_html_e( 'Order Items Snapshot', 'nearmart' ); ?> (<span id="som_det_item_count">0</span>)
								</h4>
								<span style="font-size: 0.8rem; color: #64748b;">
									&#128279; <?php esc_html_e( 'Historical snapshot at placement time', 'nearmart' ); ?>
								</span>
							</div>

							<div class="som-catalog-table-wrap" style="border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
								<table class="som-catalog-table compact-table" style="width: 100%;">
									<thead>
										<tr style="background: #f8fafc; text-align: left;">
											<th style="width: 44px;"><?php esc_html_e( 'Image', 'nearmart' ); ?></th>
											<th><?php esc_html_e( 'Item Description', 'nearmart' ); ?></th>
											<th><?php esc_html_e( 'Type', 'nearmart' ); ?></th>
											<th><?php esc_html_e( 'Requested Qty', 'nearmart' ); ?></th>
											<th><?php esc_html_e( 'Actual Qty', 'nearmart' ); ?></th>
											<th><?php esc_html_e( 'Unit Price', 'nearmart' ); ?></th>
											<th style="text-align: right;"><?php esc_html_e( 'Line Total', 'nearmart' ); ?></th>
										</tr>
									</thead>
									<tbody id="som_det_items_tbody">
										<!-- Populated via JS -->
									</tbody>
								</table>
							</div>
						</div>

						<!-- Totals & Payment Summary Box -->
						<div class="som-order-totals-summary">
							<div class="totals-line">
								<span><?php esc_html_e( 'Fixed Items Subtotal:', 'nearmart' ); ?></span>
								<strong id="som_det_fixed_subtotal">₹0.00</strong>
							</div>
							<div class="totals-line provisional-note" id="som_det_provisional_row" style="display: none;">
								<span>&#9878; <?php esc_html_e( 'Produce Weighing Status:', 'nearmart' ); ?></span>
								<span style="color: #7e22ce; font-weight: 600;"><?php esc_html_e( 'Contains store-weighed produce (TBD at counter)', 'nearmart' ); ?></span>
							</div>
							<div class="totals-line grand-total">
								<span id="som_det_total_label"><?php esc_html_e( 'Grand Total:', 'nearmart' ); ?></span>
								<span class="total-price" id="som_det_grand_total">₹0.00</span>
							</div>
						</div>

						<!-- Notice Box for APP-8.1 Scope -->
						<div class="som-scope-notice">
							<span>&#9432;</span>
							<span><?php esc_html_e( 'APP-8.1 Read-Only Foundation: Order item weighing and status fulfillment workflows will be enabled in the upcoming APP-8.2 release.', 'nearmart' ); ?></span>
						</div>
					</div>
				</div>
			</div>
		</div>

		<script type="text/javascript">
		(function($) {
			'use strict';

			var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
			var nonce   = '<?php echo esc_js( $nonce ); ?>';
			var currentPage = 1;
			var searchTimer = null;
			var initialOrderId = <?php echo absint( $target_id ); ?>;

			// Status badge color helpers
			function getFulfillmentBadge(status) {
				var s = (status || 'pending').toLowerCase();
				var cls = 'pending';
				var label = 'Pending';

				switch(s) {
					case 'pending':
						cls = 'pending'; label = 'Pending'; break;
					case 'accepted':
						cls = 'blue'; label = 'Accepted'; break;
					case 'preparing':
						cls = 'purple'; label = 'Preparing'; break;
					case 'ready_for_pickup':
						cls = 'verified'; label = 'Ready for Pickup'; break;
					case 'completed':
						cls = 'verified'; label = 'Completed'; break;
					case 'rejected':
					case 'cancelled':
						cls = 'error'; label = s.charAt(0).toUpperCase() + s.slice(1); break;
					default:
						cls = 'gray'; label = s;
				}
				return '<span class="som-badge ' + cls + '">' + escapeHtml(label) + '</span>';
			}

			function getPricingBadge(status) {
				var s = (status || 'fixed').toLowerCase();
				switch(s) {
					case 'fixed':
						return '<span class="som-badge verified">Fixed Price</span>';
					case 'pending_verification':
						return '<span class="som-badge purple">&#9878; Weighed at Shop</span>';
					case 'finalized':
						return '<span class="som-badge blue">Finalized</span>';
					default:
						return '<span class="som-badge gray">' + escapeHtml(s) + '</span>';
				}
			}

			function getPaymentBadge(status) {
				var s = (status || 'unpaid').toLowerCase();
				switch(s) {
					case 'paid':
						return '<span class="som-badge verified">Paid</span>';
					case 'payment_pending':
						return '<span class="som-badge pending">Payment Pending</span>';
					case 'unpaid':
						return '<span class="som-badge orange">Unpaid (Counter)</span>';
					case 'refunded':
					case 'failed':
						return '<span class="som-badge error">' + escapeHtml(s) + '</span>';
					default:
						return '<span class="som-badge gray">' + escapeHtml(s) + '</span>';
				}
			}

			function escapeHtml(text) {
				if (!text) return '';
				return String(text)
					.replace(/&/g, '&amp;')
					.replace(/</g, '&lt;')
					.replace(/>/g, '&gt;')
					.replace(/"/g, '&quot;')
					.replace(/'/g, '&#039;');
			}

			// Load Orders List
			function loadOrders(page) {
				currentPage = page || 1;
				var $tbody = $('#som_orders_tbody');
				$tbody.html('<tr><td colspan="8" style="text-align: center; padding: 28px; color: #64748b;">&#128259; <?php echo esc_js( __( 'Loading orders...', 'nearmart' ) ); ?></td></tr>');

				var postData = {
					action: 'som_merchant_get_orders',
					nonce: nonce,
					search: $('#som_order_search').val(),
					fulfillment_status: $('#som_filter_fulfillment').val(),
					pricing_status: $('#som_filter_pricing').val(),
					payment_status: $('#som_filter_payment').val(),
					per_page: $('#som_order_per_page').val(),
					page: currentPage
				};

				$.post(ajaxUrl, postData, function(response) {
					if (!response || !response.success || !response.data) {
						$tbody.html('<tr><td colspan="8" style="text-align:center; color:#ef4444; padding:24px;">&#10060; <?php echo esc_js( __( 'Failed to load orders. Please refresh.', 'nearmart' ) ); ?></td></tr>');
						return;
					}

					var data = response.data;
					var orders = data.orders || [];

					if (orders.length === 0) {
						$tbody.html('<tr><td colspan="8" style="text-align:center; padding:36px; color:#64748b;"><div style="font-size:2rem; margin-bottom:8px;">&#128230;</div><strong><?php echo esc_js( __( 'No orders found matching your filters.', 'nearmart' ) ); ?></strong></td></tr>');
						$('#som_orders_info').text('<?php echo esc_js( __( 'Showing 0 orders', 'nearmart' ) ); ?>');
						$('#som_orders_prev_btn, #som_orders_next_btn').prop('disabled', true);
						return;
					}

					var html = '';
					$.each(orders, function(idx, o) {
						var totalDisplay = '₹' + (o.final_total !== null ? o.final_total : (o.estimated_total !== null ? o.estimated_total : o.fixed_items_subtotal));
						if (!o.is_total_final) {
							totalDisplay += '*';
						}

						html += '<tr style="border-bottom: 1px solid #f1f5f9;">';
						// 1. Order & Pickup Code
						html += '<td>';
						html += '<div style="font-weight: 700; color: #0f172a;">' + escapeHtml(o.order_number) + '</div>';
						html += '<span class="som-badge pickup-badge">&#128204; ' + escapeHtml(o.pickup_code) + '</span>';
						html += '</td>';

						// 2. Date & Time
						html += '<td>';
						html += '<div style="font-size: 0.88rem; font-weight: 600; color: #334155;">' + escapeHtml(o.date_created) + '</div>';
						if (o.date_human) {
							html += '<div style="font-size: 0.78rem; color: #64748b;">' + escapeHtml(o.date_human) + '</div>';
						}
						html += '</td>';

						// 3. Customer
						html += '<td>';
						html += '<div style="font-weight: 600; color: #1e293b;">' + escapeHtml(o.customer_name || 'Guest') + '</div>';
						if (o.customer_phone) {
							html += '<div style="font-size: 0.82rem; color: #64748b;">&#128222; ' + escapeHtml(o.customer_phone) + '</div>';
						}
						html += '</td>';

						// 4. Items
						html += '<td>';
						html += '<span style="font-weight: 600;">' + (o.items_count || o.items.length) + ' items</span>';
						html += '</td>';

						// 5. Order Total
						html += '<td>';
						html += '<div style="font-weight: 800; font-size: 1.05rem; color: #059669;">' + escapeHtml(totalDisplay) + '</div>';
						if (!o.is_total_final) {
							html += '<div style="font-size: 0.75rem; color: #7e22ce; font-weight: 600;">Est. (Weighed)</div>';
						}
						html += '</td>';

						// 6. Fulfillment Status
						html += '<td>' + getFulfillmentBadge(o.fulfillment_status) + '</td>';

						// 7. Pricing & Payment
						html += '<td>';
						html += '<div style="margin-bottom: 4px;">' + getPricingBadge(o.pricing_status) + '</div>';
						html += '<div>' + getPaymentBadge(o.payment_status) + '</div>';
						html += '</td>';

						// 8. Action
						html += '<td style="text-align: right;">';
						html += '<button type="button" class="som-btn-icon som-btn-view-order" data-order-id="' + o.id + '" style="font-weight: 700; color: #2563eb; border-color: #cbd5e1;">';
						html += '&#128065; <?php echo esc_js( __( 'View', 'nearmart' ) ); ?>';
						html += '</button>';
						html += '</td>';

						html += '</tr>';
					});

					$tbody.html(html);

					// Pagination state
					$('#som_orders_info').text('Showing ' + orders.length + ' of ' + data.total_count + ' orders (Page ' + data.current_page + ' of ' + data.total_pages + ')');
					$('#som_orders_prev_btn').prop('disabled', data.current_page <= 1);
					$('#som_orders_next_btn').prop('disabled', data.current_page >= data.total_pages);
				}).fail(function() {
					$tbody.html('<tr><td colspan="8" style="text-align:center; color:#ef4444; padding:24px;">&#10060; <?php echo esc_js( __( 'Error connecting to server.', 'nearmart' ) ); ?></td></tr>');
				});
			}

			// Open Order Details Modal with Server-Side Validation
			function viewOrderDetails(orderId) {
				if (!orderId) return;

				$('#som_order_details_modal').fadeIn(150);
				$('#som_order_details_loading').show();
				$('#som_order_details_content').hide();

				$.post(ajaxUrl, {
					action: 'som_merchant_get_order_details',
					nonce: nonce,
					order_id: orderId
				}, function(response) {
					$('#som_order_details_loading').hide();

					if (!response || !response.success || !response.data || !response.data.order) {
						var err = (response && response.data && response.data.message) ? response.data.message : '<?php echo esc_js( __( 'Failed to load order details.', 'nearmart' ) ); ?>';
						alert(err);
						$('#som_order_details_modal').fadeOut(100);
						return;
					}

					var o = response.data.order;

					$('#som_mod_order_title').text('Order: ' + o.order_number);
					$('#som_mod_order_date').text('Placed on ' + o.date_created);

					$('#som_det_pickup_code').text(o.pickup_code);
					$('#som_det_customer_name').text(o.customer_name || 'Guest Customer');

					if (o.customer_phone) {
						$('#som_det_customer_phone_link').text(o.customer_phone).attr('href', 'tel:' + o.customer_phone);
					} else {
						$('#som_det_customer_phone_link').text('No phone provided').removeAttr('href');
					}

					if (o.customer_note) {
						$('#som_det_customer_note').text('"' + o.customer_note + '"');
						$('#som_det_note_wrap').show();
					} else {
						$('#som_det_note_wrap').hide();
					}

					// Badges
					$('#som_det_fulfillment_badge').replaceWith($(getFulfillmentBadge(o.fulfillment_status)).attr('id', 'som_det_fulfillment_badge'));
					$('#som_det_pricing_badge').replaceWith($(getPricingBadge(o.pricing_status)).attr('id', 'som_det_pricing_badge'));
					$('#som_det_payment_badge').replaceWith($(getPaymentBadge(o.payment_status)).attr('id', 'som_det_payment_badge'));
					$('#som_det_wc_status').text('wc-' + (o.wc_status || 'processing'));

					// Line items snapshot
					var items = o.items || [];
					$('#som_det_item_count').text(items.length);
					var itemRows = '';

					$.each(items, function(i, it) {
						var isWeighed = (it.pricing_type === 'store_priced');
						var typeLabel = (it.item_type === 'standalone') ? '<span class="som-badge gray">Standalone Produce</span>' : '<span class="som-badge blue">Master Product</span>';
						var unitPriceDisplay = isWeighed ? '<span style="color:#7e22ce; font-weight:700;">Weighed at shop</span>' : '₹' + it.price;
						var lineTotalDisplay = isWeighed && it.item_total <= 0 ? '<span style="color:#7e22ce; font-weight:700;">TBD</span>' : '₹' + it.item_total;

						itemRows += '<tr style="border-bottom: 1px solid #f1f5f9;">';
						// Image
						itemRows += '<td>';
						if (it.image) {
							itemRows += '<img src="' + escapeHtml(it.image) + '" style="width:36px; height:36px; object-fit:contain; border-radius:6px; border:1px solid #e2e8f0;" />';
						} else {
							itemRows += '<div style="width:36px; height:36px; background:#f1f5f9; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:1.1rem;">📦</div>';
						}
						itemRows += '</td>';

						// Description
						itemRows += '<td>';
						itemRows += '<strong style="color:#0f172a; font-size:0.92rem;">' + escapeHtml(it.name) + '</strong>';
						if (it.unit) {
							itemRows += '<div style="font-size:0.78rem; color:#64748b;">Unit: ' + escapeHtml(it.unit) + '</div>';
						}
						itemRows += '</td>';

						// Type
						itemRows += '<td>' + typeLabel + '</td>';

						// Requested Qty
						itemRows += '<td><strong>' + it.requested_quantity + ' ' + (it.unit || 'pc') + '</strong></td>';

						// Actual Qty
						itemRows += '<td>' + (it.actual_quantity !== null ? '<strong>' + it.actual_quantity + '</strong>' : '<span style="color:#94a3b8;">Pending Weighing</span>') + '</td>';

						// Unit Price
						itemRows += '<td>' + unitPriceDisplay + '</td>';

						// Line Total
						itemRows += '<td style="text-align:right; font-weight:700; color:#0f172a;">' + lineTotalDisplay + '</td>';
						itemRows += '</tr>';
					});

					$('#som_det_items_tbody').html(itemRows);

					// Totals
					$('#som_det_fixed_subtotal').text('₹' + Number(o.fixed_items_subtotal).toFixed(2));

					if (!o.is_total_final) {
						$('#som_det_provisional_row').show();
						$('#som_det_total_label').text('Estimated Total:');
						var estTotal = o.estimated_total !== null ? o.estimated_total : o.fixed_items_subtotal;
						$('#som_det_grand_total').text('₹' + Number(estTotal).toFixed(2) + '*');
					} else {
						$('#som_det_provisional_row').hide();
						$('#som_det_total_label').text('Grand Total:');
						var finalTotal = o.final_total !== null ? o.final_total : o.fixed_items_subtotal;
						$('#som_det_grand_total').text('₹' + Number(finalTotal).toFixed(2));
					}

					$('#som_order_details_content').show();
				}).fail(function() {
					$('#som_order_details_loading').hide();
					alert('<?php echo esc_js( __( 'Error fetching order details from server.', 'nearmart' ) ); ?>');
					$('#som_order_details_modal').fadeOut(100);
				});
			}

			// Event Handlers
			$(document).ready(function() {
				loadOrders(1);

				// Auto-open if query param order_id was supplied
				if (initialOrderId > 0) {
					viewOrderDetails(initialOrderId);
				}

				// Search input with debounce
				$('#som_order_search').on('input', function() {
					clearTimeout(searchTimer);
					searchTimer = setTimeout(function() {
						loadOrders(1);
					}, 350);
				});

				// Filters
				$('#som_filter_fulfillment, #som_filter_pricing, #som_filter_payment, #som_order_per_page').on('change', function() {
					loadOrders(1);
				});

				// Refresh button
				$('#som_orders_refresh_btn').on('click', function() {
					loadOrders(currentPage);
				});

				// Pagination clicks
				$('#som_orders_prev_btn').on('click', function() {
					if (currentPage > 1) {
						loadOrders(currentPage - 1);
					}
				});

				$('#som_orders_next_btn').on('click', function() {
					loadOrders(currentPage + 1);
				});

				// View Order Button in Table
				$(document).on('click', '.som-btn-view-order', function() {
					var orderId = $(this).data('order-id');
					viewOrderDetails(orderId);
				});

				// Modal Close
				$('#som_btn_close_order_modal').on('click', function() {
					$('#som_order_details_modal').fadeOut(100);
				});

				// Click outside modal content to close
				$('#som_order_details_modal').on('click', function(e) {
					if ($(e.target).is('#som_order_details_modal')) {
						$('#som_order_details_modal').fadeOut(100);
					}
				});
			});
		})(jQuery);
		</script>
		<?php
		return ob_get_clean();
	}
}
