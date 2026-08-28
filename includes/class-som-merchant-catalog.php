<?php
/**
 * Dedicated Merchant Catalog Module (Phase 5 Workflow).
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
					<p><?php esc_html_e( 'Manage prices, stock availability, and items listed for your store catalog.', 'shop-onboarding-manager' ); ?></p>
				</div>
				<div>
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
						<input type="text" id="som_cat_search" class="som-input" placeholder="Search catalog items by name or SKU..." />
					</div>
					<div class="som-catalog-filters">
						<select id="som_cat_status_filter" class="som-select" style="min-height: 44px;">
							<option value="all"><?php esc_html_e( 'All Statuses', 'shop-onboarding-manager' ); ?></option>
							<option value="active"><?php esc_html_e( 'Active', 'shop-onboarding-manager' ); ?></option>
							<option value="inactive"><?php esc_html_e( 'Inactive', 'shop-onboarding-manager' ); ?></option>
						</select>
						<select id="som_cat_stock_filter" class="som-select" style="min-height: 44px;">
							<option value="all"><?php esc_html_e( 'All Stock', 'shop-onboarding-manager' ); ?></option>
							<option value="instock"><?php esc_html_e( 'In Stock', 'shop-onboarding-manager' ); ?></option>
							<option value="outofstock"><?php esc_html_e( 'Out of Stock', 'shop-onboarding-manager' ); ?></option>
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
								<th><?php esc_html_e( 'Category', 'shop-onboarding-manager' ); ?></th>
								<th><?php esc_html_e( 'Shop Price', 'shop-onboarding-manager' ); ?></th>
								<th><?php esc_html_e( 'Stock Status', 'shop-onboarding-manager' ); ?></th>
								<th><?php esc_html_e( 'Status', 'shop-onboarding-manager' ); ?></th>
								<th style="width: 120px; text-align: right;"><?php esc_html_e( 'Actions', 'shop-onboarding-manager' ); ?></th>
							</tr>
						</thead>
						<tbody id="som_catalog_tbody">
							<tr>
								<td colspan="7" style="text-align: center; padding: 24px; color: #64748b;">
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

		<!-- MODAL 1: Add Master Product to Catalog Modal -->
		<div id="som_add_product_modal" class="som-modal-overlay" style="display: none;">
			<div class="som-modal-content">
				<div class="som-modal-header">
					<h3>&#10133; <?php esc_html_e( 'Add Master Product to Catalog', 'shop-onboarding-manager' ); ?></h3>
					<button type="button" class="som-modal-close" onclick="document.getElementById('som_add_product_modal').style.display='none';">&times;</button>
				</div>

				<div class="som-form-group">
					<label for="som_master_search" class="som-label"><?php esc_html_e( '1. Search Master Product (Name, SKU, or Barcode)', 'shop-onboarding-manager' ); ?></label>
					<input type="text" id="som_master_search" class="som-input" placeholder="<?php esc_attr_e( 'Type at least 2 characters to search...', 'shop-onboarding-manager' ); ?>" />
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
							<label for="som_add_stock_status" class="som-label"><?php esc_html_e( 'Stock Status', 'shop-onboarding-manager' ); ?></label>
							<select id="som_add_stock_status" name="stock_status" class="som-select">
								<option value="instock"><?php esc_html_e( 'In Stock', 'shop-onboarding-manager' ); ?></option>
								<option value="outofstock"><?php esc_html_e( 'Out of Stock', 'shop-onboarding-manager' ); ?></option>
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
		</div>

		<!-- MODAL 2: Edit Catalog Product Modal -->
		<div id="som_edit_product_modal" class="som-modal-overlay" style="display: none;">
			<div class="som-modal-content">
				<div class="som-modal-header">
					<h3>&#9998; <?php esc_html_e( 'Edit Catalog Product', 'shop-onboarding-manager' ); ?></h3>
					<button type="button" class="som-modal-close" onclick="document.getElementById('som_edit_product_modal').style.display='none';">&times;</button>
				</div>

				<form id="som_form_edit_catalog_product">
					<input type="hidden" id="som_edit_product_id" name="product_id" value="" />
					<p style="font-weight: 700; color: #0f172a; margin-bottom: 14px; font-size: 1.05rem;" id="som_edit_selected_title"></p>

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
							<label for="som_edit_stock_status" class="som-label"><?php esc_html_e( 'Stock Status', 'shop-onboarding-manager' ); ?></label>
							<select id="som_edit_stock_status" name="stock_status" class="som-select">
								<option value="instock"><?php esc_html_e( 'In Stock', 'shop-onboarding-manager' ); ?></option>
								<option value="outofstock"><?php esc_html_e( 'Out of Stock', 'shop-onboarding-manager' ); ?></option>
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

		<!-- Catalog Inline JavaScript Handler -->
		<script>
		if (typeof jQuery !== 'undefined') {
			jQuery(document).ready(function($) {
				var nonce = '<?php echo esc_js( $nonce ); ?>';
				var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
				var currentPage = 1;

				function escapeHtml(str) {
					return str ? $('<div>').text(str).html() : '';
				}

				function loadCatalog(page) {
					currentPage = page || 1;
					var $tbody = $('#som_catalog_tbody');
					$tbody.html('<tr><td colspan="7" style="text-align:center; padding: 20px; color:#64748b;">&#128259; Loading catalog...</td></tr>');

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
									$tbody.html('<tr><td colspan="7" style="text-align:center; padding: 24px; color:#64748b;">No products in your catalog yet. Click <strong>"Add Product to Catalog"</strong> to add items!</td></tr>');
									$('#som_catalog_info').text('Showing 0 items');
									$('#som_cat_prev_btn, #som_cat_next_btn').prop('disabled', true);
									return;
								}

								var html = '';
								$.each(items, function(i, item) {
									html += '<tr data-product-id="' + item.product_id + '">';
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

									html += '<td><span class="som-cat-meta-tag">' + escapeHtml(item.category) + '</span></td>';

									html += '<td><span class="som-cat-price">';
									if (item.sale_price) {
										html += '<del>₹' + item.price + '</del> ₹' + item.sale_price;
									} else {
										html += '₹' + item.price;
									}
									html += '</span></td>';

									html += '<td><span class="som-cat-badge ' + item.stock_status + '">' + (item.stock_status === 'instock' ? 'In Stock' : 'Out of Stock') + '</span></td>';
									html += '<td><span class="som-cat-badge ' + item.status + '">' + item.status + '</span></td>';

									html += '<td><div class="som-cat-actions">';
									html += '<button type="button" class="som-btn-icon som-btn-edit-item" data-item=\'' + JSON.stringify(item) + '\'>&#9998; Edit</button>';
									html += '<button type="button" class="som-btn-icon danger som-btn-remove-item" data-id="' + item.product_id + '">&#128465;</button>';
									html += '</div></td>';
									html += '</tr>';
								});

								$tbody.html(html);

								$('#som_catalog_info').text('Page ' + res.data.current_page + ' of ' + res.data.total_pages + ' (' + res.data.total_count + ' items)');
								$('#som_cat_prev_btn').prop('disabled', res.data.current_page <= 1);
								$('#som_cat_next_btn').prop('disabled', res.data.current_page >= res.data.total_pages);
							} else {
								$tbody.html('<tr><td colspan="7" style="text-align:center; color:#ef4444;">' + (res.data.message || 'Error loading catalog') + '</td></tr>');
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

				// Open Add Modal Logic
				$('#som_btn_open_add_modal').on('click', function() {
					$('#som_add_product_modal').show();
					$('#som_master_search').val('').focus();
					$('#som_form_add_catalog_product').hide();
					$('#som_master_results').html('<p style="padding:14px; text-align:center; color:#64748b; margin:0;">Type at least 2 characters to search master products by name, SKU, or barcode...</p>');
				});

				// Type-ahead Master Search in Modal (min 2 chars)
				var masterTimer = null;
				$('#som_master_search').on('keyup input', function() {
					clearTimeout(masterTimer);
					var q = $(this).val().trim();

					if (q.length < 2) {
						$('#som_form_add_catalog_product').slideUp();
						$('#som_master_results').html('<p style="padding:14px; text-align:center; color:#64748b; margin:0;">Type at least 2 characters to search master products by name, SKU, or barcode...</p>');
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
								$('#som_master_results').html(html);
							} else {
								$('#som_master_results').html('<p style="padding:14px; color:#64748b; margin:0; text-align:center;">No master products found matching your search.</p>');
							}
						},
						error: function() {
							$('#som_master_results').html('<p style="padding:14px; color:#ef4444; margin:0; text-align:center;">Failed to search products. Please try again.</p>');
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

				// Save Added Product
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

				// Edit Product Logic
				$(document).on('click', '.som-btn-edit-item', function() {
					var item = $(this).data('item');
					if (!item) return;

					$('#som_edit_product_id').val(item.product_id);
					$('#som_edit_selected_title').text('Editing: ' + item.title);
					$('#som_edit_price').val(item.price);
					$('#som_edit_sale_price').val(item.sale_price);
					$('#som_edit_stock_status').val(item.stock_status);
					$('#som_edit_stock_quantity').val(item.stock_quantity);
					$('#som_edit_shop_sku').val(item.shop_sku || '');
					$('#som_edit_status').val(item.status);

					$('#som_edit_product_modal').show();
				});

				$('#som_form_edit_catalog_product').on('submit', function(e) {
					e.preventDefault();
					var $btn = $('#som_btn_save_edit');
					$btn.prop('disabled', true).text('Updating...');

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: {
							action: 'som_merchant_update_catalog_product',
							nonce: nonce,
							product_id: $('#som_edit_product_id').val(),
							price: $('#som_edit_price').val(),
							sale_price: $('#som_edit_sale_price').val(),
							stock_status: $('#som_edit_stock_status').val(),
							stock_quantity: $('#som_edit_stock_quantity').val(),
							shop_sku: $('#som_edit_shop_sku').val(),
							status: $('#som_edit_status').val()
						},
						success: function(res) {
							$btn.prop('disabled', false).html('&#128190; Update Product');
							if (res.success) {
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

				// Remove Product Logic
				$(document).on('click', '.som-btn-remove-item', function() {
					var pid = $(this).data('id');
					if (!confirm('Are you sure you want to remove this product from your shop catalog?')) return;

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: { action: 'som_merchant_remove_catalog_product', nonce: nonce, product_id: pid },
						success: function(res) {
							if (res.success) {
								loadCatalog(currentPage);
							} else {
								alert(res.data.message || 'Error removing product.');
							}
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