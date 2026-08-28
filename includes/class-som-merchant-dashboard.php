<?php
/**
 * Frontend Merchant Dashboard Module.
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Merchant_Dashboard
 */
class SOM_Merchant_Dashboard {

	/**
	 * Agreement Version constant.
	 */
	const AGREEMENT_VERSION = 'v1.0';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_shortcode( 'som_merchant_dashboard', array( __CLASS__, 'render_dashboard_shortcode' ) );

		// AJAX endpoints for dashboard actions.
		add_action( 'wp_ajax_som_merchant_confirm_details', array( __CLASS__, 'ajax_confirm_details' ) );
		add_action( 'wp_ajax_som_merchant_accept_agreement', array( __CLASS__, 'ajax_accept_agreement' ) );
		add_action( 'wp_ajax_som_merchant_request_change', array( __CLASS__, 'ajax_request_change' ) );

		// AJAX endpoints for catalog management (shared APIs).
		add_action( 'wp_ajax_som_merchant_get_catalog', array( __CLASS__, 'ajax_get_catalog' ) );
		add_action( 'wp_ajax_som_merchant_search_master_products', array( __CLASS__, 'ajax_search_master_products' ) );
		add_action( 'wp_ajax_som_merchant_add_catalog_product', array( __CLASS__, 'ajax_add_catalog_product' ) );
		add_action( 'wp_ajax_som_merchant_update_catalog_product', array( __CLASS__, 'ajax_update_catalog_product' ) );
		add_action( 'wp_ajax_som_merchant_remove_catalog_product', array( __CLASS__, 'ajax_remove_catalog_product' ) );
	}

	/**
	 * Evaluate and update shop status to 'committed' if conditions are met.
	 *
	 * @param int $shop_id Shop Post ID.
	 * @return bool
	 */
	public static function evaluate_commitment_status( $shop_id ) {
		$agreement_accepted = (bool) get_post_meta( $shop_id, 'som_agreement_accepted', true );
		$is_verified        = has_term( 'verified', 'shop_status', $shop_id );

		if ( $is_verified && $agreement_accepted ) {
			wp_set_object_terms( $shop_id, 'committed', 'shop_status' );
			return true;
		}

		return false;
	}

	/**
	 * AJAX endpoint: Confirm Shop Details.
	 */
	public static function ajax_confirm_details() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id || ! nearmart_user_can_manage_shop_catalog( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ) );
		}

		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );
		if ( ! $shop_id ) {
			wp_send_json_error( array( 'message' => __( 'No shop linked to your account.', 'shop-onboarding-manager' ) ) );
		}

		$now = current_time( 'mysql' );
		update_post_meta( $shop_id, 'som_details_confirmed', 1 );
		update_post_meta( $shop_id, 'som_details_confirmed_at', $now );
		update_post_meta( $shop_id, 'som_details_confirmed_by', $user_id );

		wp_send_json_success(
			array(
				'message'      => __( 'Shop details successfully confirmed.', 'shop-onboarding-manager' ),
				'confirmed_at' => $now,
			)
		);
	}

	/**
	 * AJAX endpoint: Accept Participation Agreement.
	 */
	public static function ajax_accept_agreement() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id || ! nearmart_user_can_manage_shop_catalog( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ) );
		}

		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );
		if ( ! $shop_id ) {
			wp_send_json_error( array( 'message' => __( 'No shop linked to your account.', 'shop-onboarding-manager' ) ) );
		}

		$now = current_time( 'mysql' );
		update_post_meta( $shop_id, 'som_agreement_accepted', 1 );
		update_post_meta( $shop_id, 'som_agreement_accepted_at', $now );
		update_post_meta( $shop_id, 'som_agreement_accepted_by', $user_id );
		update_post_meta( $shop_id, 'som_agreement_version', self::AGREEMENT_VERSION );

		self::evaluate_commitment_status( $shop_id );

		wp_send_json_success(
			array(
				'message'     => __( 'Participation agreement accepted successfully.', 'shop-onboarding-manager' ),
				'accepted_at' => $now,
				'version'     => self::AGREEMENT_VERSION,
			)
		);
	}

	/**
	 * AJAX endpoint: Request Data Correction.
	 */
	public static function ajax_request_change() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id || ! nearmart_user_can_manage_shop_catalog( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ) );
		}

		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );
		if ( ! $shop_id ) {
			wp_send_json_error( array( 'message' => __( 'No shop linked to your account.', 'shop-onboarding-manager' ) ) );
		}

		$field_name    = isset( $_POST['field_name'] ) ? sanitize_text_field( wp_unslash( $_POST['field_name'] ) ) : '';
		$requested_val = isset( $_POST['requested_value'] ) ? sanitize_textarea_field( wp_unslash( $_POST['requested_value'] ) ) : '';

		if ( empty( $field_name ) || empty( $requested_val ) ) {
			wp_send_json_error( array( 'message' => __( 'Field name and requested value are required.', 'shop-onboarding-manager' ) ) );
		}

		$requests = get_post_meta( $shop_id, 'som_change_requests', true );
		if ( ! is_array( $requests ) ) {
			$requests = array();
		}

		$new_request = array(
			'id'              => uniqid( 'req_' ),
			'field_name'      => $field_name,
			'requested_value' => $requested_val,
			'requested_by'    => $user_id,
			'requested_at'    => current_time( 'mysql' ),
			'status'          => 'pending',
		);

		$requests[] = $new_request;
		update_post_meta( $shop_id, 'som_change_requests', $requests );

		wp_send_json_success(
			array(
				'message' => __( 'Change request submitted for admin review.', 'shop-onboarding-manager' ),
				'request' => $new_request,
			)
		);
	}

	/**
	 * AJAX endpoint: Get Merchant Catalog List.
	 */
	public static function ajax_get_catalog() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );

		if ( ! $shop_id || ! nearmart_user_can_manage_shop( $user_id, $shop_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized catalog access.', 'shop-onboarding-manager' ) ), 403 );
		}

		$search       = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$status       = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'all';
		$stock_status = isset( $_POST['stock_status'] ) ? sanitize_key( wp_unslash( $_POST['stock_status'] ) ) : 'all';
		$page         = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$limit        = 10;
		$offset       = ( max( 1, $page ) - 1 ) * $limit;

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

		wp_send_json_success(
			array(
				'items'        => $paged_items,
				'total_count'  => $total_count,
				'total_pages'  => max( 1, $total_pages ),
				'current_page' => $page,
			)
		);
	}

	/**
	 * AJAX endpoint: Search WooCommerce Master Products to add.
	 */
	public static function ajax_search_master_products() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );

		if ( ! $shop_id || ! nearmart_user_can_manage_shop( $user_id, $shop_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ), 403 );
		}

		$query = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( ! empty( $query ) ) {
			$args['s'] = $query;
		}

		$search_query = new WP_Query( $args );
		$results      = array();

		if ( $search_query->have_posts() ) {
			while ( $search_query->have_posts() ) {
				$search_query->the_post();
				$pid = get_the_ID();

				$already_in_catalog = nearmart_has_shop_product( $shop_id, $pid );
				$specs              = nearmart_get_master_product_specs( $pid );
				$cats               = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'names' ) );
				$thumb_url          = get_the_post_thumbnail_url( $pid, 'thumbnail' );

				$results[] = array(
					'product_id' => $pid,
					'title'      => get_the_title(),
					'category'   => ! empty( $cats ) ? $cats[0] : __( 'Uncategorized', 'shop-onboarding-manager' ),
					'brand'      => $specs['brand_name'],
					'unit'       => $specs['unit'],
					'barcode'    => $specs['barcode'],
					'sku'        => $specs['sku'],
					'thumb_url'  => $thumb_url ? $thumb_url : '',
					'in_catalog' => $already_in_catalog,
				);
			}
			wp_reset_postdata();
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * AJAX endpoint: Add Master Product to Merchant Shop Catalog.
	 */
	public static function ajax_add_catalog_product() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );

		if ( ! $shop_id || ! nearmart_user_can_manage_shop( $user_id, $shop_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ), 403 );
		}

		$product_id     = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$price          = isset( $_POST['price'] ) ? floatval( $_POST['price'] ) : 0.00;
		$sale_price     = ( isset( $_POST['sale_price'] ) && '' !== $_POST['sale_price'] ) ? floatval( $_POST['sale_price'] ) : null;
		$stock_quantity = ( isset( $_POST['stock_quantity'] ) && '' !== $_POST['stock_quantity'] ) ? intval( $_POST['stock_quantity'] ) : null;
		$stock_status   = isset( $_POST['stock_status'] ) ? sanitize_key( $_POST['stock_status'] ) : 'instock';
		$status         = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'active';

		if ( ! $product_id || get_post_type( $product_id ) !== 'product' ) {
			wp_send_json_error( array( 'message' => __( 'Invalid master product selected.', 'shop-onboarding-manager' ) ) );
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
			)
		);

		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to add product to catalog.', 'shop-onboarding-manager' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Product added to your shop catalog successfully!', 'shop-onboarding-manager' ) ) );
	}

	/**
	 * AJAX endpoint: Update Shop Product in Catalog.
	 */
	public static function ajax_update_catalog_product() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );

		if ( ! $shop_id || ! nearmart_user_can_manage_shop( $user_id, $shop_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ), 403 );
		}

		$product_id     = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$price          = isset( $_POST['price'] ) ? floatval( $_POST['price'] ) : 0.00;
		$sale_price     = ( isset( $_POST['sale_price'] ) && '' !== $_POST['sale_price'] ) ? floatval( $_POST['sale_price'] ) : null;
		$stock_quantity = ( isset( $_POST['stock_quantity'] ) && '' !== $_POST['stock_quantity'] ) ? intval( $_POST['stock_quantity'] ) : null;
		$stock_status   = isset( $_POST['stock_status'] ) ? sanitize_key( $_POST['stock_status'] ) : 'instock';
		$status         = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'active';

		if ( ! $product_id || ! nearmart_has_shop_product( $shop_id, $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Product not found in your shop catalog.', 'shop-onboarding-manager' ) ) );
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
			)
		);

		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to update catalog product.', 'shop-onboarding-manager' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Catalog product updated successfully!', 'shop-onboarding-manager' ) ) );
	}

	/**
	 * AJAX endpoint: Remove Product from Merchant Shop Catalog.
	 */
	public static function ajax_remove_catalog_product() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );

		if ( ! $shop_id || ! nearmart_user_can_manage_shop( $user_id, $shop_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ), 403 );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product ID.', 'shop-onboarding-manager' ) ) );
		}

		$result = nearmart_remove_shop_product( $shop_id, $product_id );

		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to remove product from catalog.', 'shop-onboarding-manager' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Product removed from your catalog.', 'shop-onboarding-manager' ) ) );
	}

	/**
	 * Render [som_merchant_dashboard] shortcode.
	 */
	public static function render_dashboard_shortcode() {
		wp_enqueue_script( 'jquery' );
		wp_enqueue_style( 'som-frontend-style', SOM_PLUGIN_URL . 'assets/css/som-frontend.css', array(), SOM_VERSION );

		$user_id = get_current_user_id();
		if ( ! $user_id || ! nearmart_user_can_manage_shop_catalog( $user_id ) ) {
			return '<div class="som-merchant-card"><div class="som-response-msg error" style="display:block;">' .
				esc_html__( 'Please log in with a merchant or staff account to access your merchant dashboard.', 'shop-onboarding-manager' ) .
				' <br /><br /><a href="' . esc_url( home_url( '/merchant-login/' ) ) . '" class="som-submit-btn som-btn-secondary" style="text-decoration:none; display:inline-block; width:auto; padding:10px 20px;">' .
				esc_html__( 'Go to Merchant Login &rarr;', 'shop-onboarding-manager' ) . '</a></div></div>';
		}

		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );
		if ( ! $shop_id ) {
			return '<div class="som-merchant-card"><div class="som-card-header"><h2>' .
				esc_html__( 'Merchant Dashboard', 'shop-onboarding-manager' ) . '</h2></div><p>' .
				esc_html__( 'No shop is currently linked to your merchant user account. Please contact NearMart support.', 'shop-onboarding-manager' ) .
				'</p></div>';
		}

		// Shop Meta Details.
		$shop_name  = get_the_title( $shop_id );
		$owner_name = get_post_meta( $shop_id, 'som_owner_name', true );
		$phone      = get_post_meta( $shop_id, 'som_phone_number', true );
		$address    = get_post_meta( $shop_id, 'som_address', true );
		$shop_type  = get_post_meta( $shop_id, 'som_shop_type', true );
		$photo_id   = get_post_meta( $shop_id, 'som_shop_photo_id', true );
		$photo_url  = $photo_id ? wp_get_attachment_image_url( $photo_id, 'medium' ) : '';

		// Verification & Agreement Meta.
		$is_verified        = has_term( 'verified', 'shop_status', $shop_id );
		$is_committed       = has_term( 'committed', 'shop_status', $shop_id );
		$details_confirmed  = (bool) get_post_meta( $shop_id, 'som_details_confirmed', true );
		$confirmed_at       = get_post_meta( $shop_id, 'som_details_confirmed_at', true );
		$agreement_accepted = (bool) get_post_meta( $shop_id, 'som_agreement_accepted', true );
		$agreement_at       = get_post_meta( $shop_id, 'som_agreement_accepted_at', true );

		// Catalog Summary Metrics.
		$catalog_summary = nearmart_get_shop_catalog_summary( $shop_id );
		$catalog_url     = home_url( '/merchant-catalog/' );

		$change_requests = get_post_meta( $shop_id, 'som_change_requests', true );
		if ( ! is_array( $change_requests ) ) {
			$change_requests = array();
		}

		$nonce = wp_create_nonce( 'som_merchant_dashboard_nonce' );

		ob_start();
		?>
		<div class="som-merchant-dashboard-wrap">
			<!-- Portal Navigation Header -->
			<?php
			if ( class_exists( 'SOM_Merchant_Catalog' ) ) {
				echo SOM_Merchant_Catalog::render_portal_nav( 'dashboard' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>

			<!-- Dashboard Header -->
			<div class="som-dashboard-header" style="margin-top: 16px;">
				<div class="som-header-title">
					<h2>&#127978; <?php echo esc_html( $shop_name ); ?></h2>
					<p><?php esc_html_e( 'Merchant Management Portal', 'shop-onboarding-manager' ); ?></p>
				</div>
				<div class="som-status-badges">
					<?php if ( $is_committed ) : ?>
						<span class="som-badge som-badge-verification committed">&#127881; <?php esc_html_e( 'Committed Partner', 'shop-onboarding-manager' ); ?></span>
					<?php elseif ( $is_verified ) : ?>
						<span class="som-badge som-badge-verification verified">&#10003; <?php esc_html_e( 'Verified Shop', 'shop-onboarding-manager' ); ?></span>
					<?php else : ?>
						<span class="som-badge som-badge-pending">&#8987; <?php esc_html_e( 'Pending Verification', 'shop-onboarding-manager' ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<!-- Main 2-Column Dashboard Grid -->
			<div class="som-dashboard-grid">
				<!-- Card 1: Shop Information -->
				<div class="som-dash-card">
					<h3>&#128205; <?php esc_html_e( 'Shop Information', 'shop-onboarding-manager' ); ?></h3>
					<?php if ( $photo_url ) : ?>
						<div class="som-dash-photo">
							<img src="<?php echo esc_url( $photo_url ); ?>" alt="<?php echo esc_attr( $shop_name ); ?>" />
						</div>
					<?php endif; ?>

					<div class="som-info-list">
						<div class="som-info-item">
							<span class="som-info-label"><?php esc_html_e( 'Owner Name:', 'shop-onboarding-manager' ); ?></span>
							<span class="som-info-val"><?php echo esc_html( $owner_name ? $owner_name : '—' ); ?></span>
						</div>
						<div class="som-info-item">
							<span class="som-info-label"><?php esc_html_e( 'Phone Number:', 'shop-onboarding-manager' ); ?></span>
							<span class="som-info-val"><?php echo esc_html( $phone ? $phone : '—' ); ?></span>
						</div>
						<div class="som-info-item">
							<span class="som-info-label"><?php esc_html_e( 'Shop Type:', 'shop-onboarding-manager' ); ?></span>
							<span class="som-info-val"><?php echo esc_html( $shop_type ? $shop_type : '—' ); ?></span>
						</div>
						<div class="som-info-item">
							<span class="som-info-label"><?php esc_html_e( 'Address:', 'shop-onboarding-manager' ); ?></span>
							<span class="som-info-val"><?php echo esc_html( $address ? $address : '—' ); ?></span>
						</div>
					</div>

					<!-- Confirm Details Action -->
					<div class="som-action-box">
						<h4><?php esc_html_e( 'Information Confirmation', 'shop-onboarding-manager' ); ?></h4>
						<?php if ( $details_confirmed ) : ?>
							<p style="color: #15803d; font-weight: 600; margin: 0; font-size: 0.9rem;">
								&#10003; <?php printf( esc_html__( 'Confirmed on %s', 'shop-onboarding-manager' ), esc_html( $confirmed_at ) ); ?>
							</p>
						<?php else : ?>
							<button type="button" id="som_btn_confirm_details" class="som-submit-btn som-btn-secondary" style="margin-top: 6px;">
								&#10003; <?php esc_html_e( 'I confirm my shop details are correct', 'shop-onboarding-manager' ); ?>
							</button>
						<?php endif; ?>
					</div>
				</div>

				<!-- Card 2: Participation Agreement & Correction Request -->
				<div class="som-dash-card">
					<h3>&#128203; <?php esc_html_e( 'Participation Agreement', 'shop-onboarding-manager' ); ?></h3>

					<div class="som-agreement-box">
						<p class="som-agreement-text">
							"<?php esc_html_e( 'I agree to participate as a shop partner in the platform and allow my shop to be listed as a participating shop when the platform launches.', 'shop-onboarding-manager' ); ?>"
						</p>
						<span class="som-agreement-ver"><?php printf( esc_html__( 'Agreement Version: %s', 'shop-onboarding-manager' ), esc_html( self::AGREEMENT_VERSION ) ); ?></span>
					</div>

					<?php if ( $agreement_accepted ) : ?>
						<div class="som-alert-committed">
							<strong>&#127881; <?php esc_html_e( 'Agreement Accepted!', 'shop-onboarding-manager' ); ?></strong>
							<p><?php printf( esc_html__( 'Accepted on %s', 'shop-onboarding-manager' ), esc_html( $agreement_at ) ); ?></p>
						</div>
					<?php else : ?>
						<div style="margin-bottom: 16px;">
							<label class="som-checkbox-label">
								<input type="checkbox" id="som_chk_agreement" />
								<span><?php esc_html_e( 'I accept the participation terms above', 'shop-onboarding-manager' ); ?></span>
							</label>
							<button type="button" id="som_btn_accept_agreement" class="som-submit-btn" style="margin-top: 10px;" disabled>
								&#9998; <?php esc_html_e( 'Accept Agreement', 'shop-onboarding-manager' ); ?>
							</button>
						</div>
					<?php endif; ?>

					<!-- Request Correction Box -->
					<div style="margin-top: 20px; border-top: 1px solid #e2e8f0; padding-top: 16px;">
						<h3>&#9998; <?php esc_html_e( 'Request Information Correction', 'shop-onboarding-manager' ); ?></h3>
						<form id="som_form_change_request">
							<div class="som-form-group">
								<label for="som_cr_field" class="som-sublabel"><?php esc_html_e( 'Select Field to Change', 'shop-onboarding-manager' ); ?></label>
								<select id="som_cr_field" name="field_name" class="som-select" required>
									<option value=""><?php esc_html_e( '-- Select Field --', 'shop-onboarding-manager' ); ?></option>
									<option value="Shop Name"><?php esc_html_e( 'Shop Name', 'shop-onboarding-manager' ); ?></option>
									<option value="Owner Name"><?php esc_html_e( 'Owner Name', 'shop-onboarding-manager' ); ?></option>
									<option value="Phone Number"><?php esc_html_e( 'Phone Number', 'shop-onboarding-manager' ); ?></option>
									<option value="Address"><?php esc_html_e( 'Address', 'shop-onboarding-manager' ); ?></option>
									<option value="Shop Type"><?php esc_html_e( 'Shop Type', 'shop-onboarding-manager' ); ?></option>
								</select>
							</div>

							<div class="som-form-group">
								<label for="som_cr_val" class="som-sublabel"><?php esc_html_e( 'Correct Value', 'shop-onboarding-manager' ); ?></label>
								<input type="text" id="som_cr_val" name="requested_value" class="som-input" required placeholder="Enter correct detail..." />
							</div>

							<button type="submit" id="som_btn_submit_cr" class="som-submit-btn som-btn-outline" style="min-height: 40px; padding: 8px 14px;">
								&#128233; <?php esc_html_e( 'Submit Correction Request', 'shop-onboarding-manager' ); ?>
							</button>
						</form>
					</div>
				</div>

				<!-- Card 3: Full Width - Compact My Shop Catalog Summary Widget -->
				<div class="som-dash-card full-width" style="grid-column: 1 / -1;">
					<div class="som-catalog-header" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 14px; margin-bottom: 20px;">
						<div>
							<h3 style="border:none; margin:0; padding:0;">&#128722; <?php esc_html_e( 'My Shop Catalog Summary', 'shop-onboarding-manager' ); ?></h3>
							<p style="font-size: 0.9rem; color: #64748b; margin: 4px 0 0 0;"><?php esc_html_e( 'Quick overview of items, active listings, and stock availability.', 'shop-onboarding-manager' ); ?></p>
						</div>
						<a href="<?php echo esc_url( $catalog_url ); ?>" class="som-submit-btn" style="width: auto; padding: 10px 20px; min-height: 42px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center;">
							<?php esc_html_e( 'Manage Catalog &rarr;', 'shop-onboarding-manager' ); ?>
						</a>
					</div>

					<!-- Summary Stat Grid (3 Metric Cards) -->
					<div class="som-cat-summary-grid">
						<div class="som-summary-card">
							<div class="som-summary-icon">&#128230;</div>
							<div class="som-summary-info">
								<span class="som-summary-val"><?php echo esc_html( $catalog_summary['total'] ); ?></span>
								<span class="som-summary-lbl"><?php esc_html_e( 'Total Products', 'shop-onboarding-manager' ); ?></span>
							</div>
						</div>

						<div class="som-summary-card success">
							<div class="som-summary-icon">&#10003;</div>
							<div class="som-summary-info">
								<span class="som-summary-val"><?php echo esc_html( $catalog_summary['active'] ); ?></span>
								<span class="som-summary-lbl"><?php esc_html_e( 'Active Listings', 'shop-onboarding-manager' ); ?></span>
							</div>
						</div>

						<div class="som-summary-card warning">
							<div class="som-summary-icon">&#9888;</div>
							<div class="som-summary-info">
								<span class="som-summary-val"><?php echo esc_html( $catalog_summary['outofstock'] ); ?></span>
								<span class="som-summary-lbl"><?php esc_html_e( 'Out of Stock', 'shop-onboarding-manager' ); ?></span>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Response Message Alert -->
			<div id="som_dash_msg" class="som-response-msg"></div>
		</div>

		<!-- Dashboard Inline JavaScript Handler -->
		<script>
		if (typeof jQuery !== 'undefined') {
			jQuery(document).ready(function($) {
				var nonce = '<?php echo esc_js( $nonce ); ?>';
				var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';

				// 1. Confirm Details Button
				$('#som_btn_confirm_details').on('click', function() {
					var $btn = $(this);
					$btn.prop('disabled', true).text('Confirming...');
					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: { action: 'som_merchant_confirm_details', nonce: nonce },
						success: function(res) {
							if (res.success) {
								$btn.parent().html('<p style="color: #15803d; font-weight: 600; margin: 0; font-size: 0.9rem;">&#10003; Confirmed just now</p>');
							} else {
								alert(res.data.message || 'Error confirming details.');
								$btn.prop('disabled', false).html('&#10003; I confirm my shop details are correct');
							}
						}
					});
				});

				// 2. Agreement Checkbox & Accept Button
				$('#som_chk_agreement').on('change', function() {
					$('#som_btn_accept_agreement').prop('disabled', !$(this).is(':checked'));
				});

				$('#som_btn_accept_agreement').on('click', function() {
					var $btn = $(this);
					$btn.prop('disabled', true).text('Accepting...');
					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: { action: 'som_merchant_accept_agreement', nonce: nonce },
						success: function(res) {
							if (res.success) {
								location.reload();
							} else {
								alert(res.data.message || 'Error accepting agreement.');
								$btn.prop('disabled', false).html('&#9998; Accept Agreement');
							}
						}
					});
				});

				// 3. Correction Request Form
				$('#som_form_change_request').on('submit', function(e) {
					e.preventDefault();
					var $btn = $('#som_btn_submit_cr');
					$btn.prop('disabled', true).text('Submitting...');
					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: {
							action: 'som_merchant_request_change',
							nonce: nonce,
							field_name: $('#som_cr_field').val(),
							requested_value: $('#som_cr_val').val()
						},
						success: function(res) {
							$btn.prop('disabled', false).html('&#128233; Submit Correction Request');
							if (res.success) {
								alert(res.data.message);
								$('#som_form_change_request')[0].reset();
							} else {
								alert(res.data.message);
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