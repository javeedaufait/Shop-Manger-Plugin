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

		// AJAX endpoints for catalog management.
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

		$change_requests = get_post_meta( $shop_id, 'som_change_requests', true );
		if ( ! is_array( $change_requests ) ) {
			$change_requests = array();
		}

		$nonce = wp_create_nonce( 'som_merchant_dashboard_nonce' );

		ob_start();
		?>
		<div class="som-merchant-dashboard-wrap">
			<!-- Dashboard Header -->
			<div class="som-dashboard-header">
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

				<!-- Card 3: Full Width - My Catalog Section -->
				<div class="som-dash-card full-width" style="grid-column: 1 / -1;">
					<div class="som-catalog-header">
						<div>
							<h3 style="border:none; margin:0; padding:0;">&#128722; <?php esc_html_e( 'My Shop Catalog', 'shop-onboarding-manager' ); ?></h3>
							<p style="font-size: 0.9rem; color: #64748b; margin: 4px 0 0 0;"><?php esc_html_e( 'Manage prices, stock availability, and items listed for your store.', 'shop-onboarding-manager' ); ?></p>
						</div>
						<button type="button" id="som_btn_open_add_modal" class="som-submit-btn" style="width: auto; padding: 10px 18px; min-height: 40px;">
							&#10133; <?php esc_html_e( 'Add Product to Catalog', 'shop-onboarding-manager' ); ?>
						</button>
					</div>

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

					<!-- Catalog Table -->
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
			</div>

			<!-- Response Message Alert -->
			<div id="som_dash_msg" class="som-response-msg"></div>
		</div>

		<!-- MODAL 1: Add Product Modal -->
		<div id="som_add_product_modal" class="som-modal-overlay" style="display: none;">
			<div class="som-modal-content">
				<div class="som-modal-header">
					<h3>&#10133; <?php esc_html_e( 'Add Master Product to Catalog', 'shop-onboarding-manager' ); ?></h3>
					<button type="button" class="som-modal-close" onclick="document.getElementById('som_add_product_modal').style.display='none';">&times;</button>
				</div>

				<div class="som-form-group">
					<label for="som_master_search" class="som-label"><?php esc_html_e( '1. Search Master Product', 'shop-onboarding-manager' ); ?></label>
					<input type="text" id="som_master_search" class="som-input" placeholder="<?php esc_attr_e( 'Type product name (e.g. Milk, Rice, Sugar)...', 'shop-onboarding-manager' ); ?>" />
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

					<div class="som-form-group">
						<label for="som_add_status" class="som-label"><?php esc_html_e( 'Listing Status', 'shop-onboarding-manager' ); ?></label>
						<select id="som_add_status" name="status" class="som-select">
							<option value="active"><?php esc_html_e( 'Active (Visible to customers)', 'shop-onboarding-manager' ); ?></option>
							<option value="inactive"><?php esc_html_e( 'Inactive (Hidden)', 'shop-onboarding-manager' ); ?></option>
						</select>
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

					<div class="som-form-group">
						<label for="som_edit_status" class="som-label"><?php esc_html_e( 'Listing Status', 'shop-onboarding-manager' ); ?></label>
						<select id="som_edit_status" name="status" class="som-select">
							<option value="active"><?php esc_html_e( 'Active', 'shop-onboarding-manager' ); ?></option>
							<option value="inactive"><?php esc_html_e( 'Inactive', 'shop-onboarding-manager' ); ?></option>
						</select>
					</div>

					<button type="submit" id="som_btn_save_edit" class="som-submit-btn">
						&#128190; <?php esc_html_e( 'Update Product', 'shop-onboarding-manager' ); ?>
					</button>
				</form>
			</div>
		</div>

		<!-- Dashboard Inline JavaScript Handler -->
		<script>
		if (typeof jQuery !== 'undefined') {
			jQuery(document).ready(function($) {
				var nonce = '<?php echo esc_js( $nonce ); ?>';
				var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
				var currentPage = 1;

				// Helper escape function
				function escapeHtml(str) {
					return str ? $('<div>').text(str).html() : '';
				}

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

				// 4. Catalog Management Logic
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
									var metaStr = '';
									if (item.brand) metaStr += 'Brand: ' + escapeHtml(item.brand) + ' &bull; ';
									if (item.unit) metaStr += 'Unit: ' + escapeHtml(item.unit);
									if (metaStr) html += '<span class="som-cat-meta-tag">' + metaStr + '</span>';
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

				// Initial Load & Filters
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

				// Master Products Search Function for Modal
				function performMasterSearch(queryStr) {
					$('#som_master_results').html('<p style="padding:12px; color:#64748b; margin:0; text-align:center;">&#128259; Searching master products...</p>');
					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: { action: 'som_merchant_search_master_products', nonce: nonce, q: queryStr },
						success: function(res) {
							if (res.success && res.data.results && res.data.results.length > 0) {
								var html = '';
								$.each(res.data.results, function(i, m) {
									html += '<div class="som-master-item" data-master=\'' + JSON.stringify(m) + '\'>';
									html += '<div class="som-master-item-info">';
									if (m.thumb_url) {
										html += '<img src="' + m.thumb_url + '" style="width:36px; height:36px; border-radius:4px; object-fit:cover;" />';
									} else {
										html += '<span style="font-size:1.2rem;">&#128230;</span>';
									}
									html += '<div><strong>' + escapeHtml(m.title) + '</strong><br /><span style="font-size:0.75rem; color:#64748b;">Category: ' + escapeHtml(m.category) + ' &bull; Unit: ' + escapeHtml(m.unit || 'Standard') + '</span></div>';
									html += '</div>';
									if (m.in_catalog) {
										html += '<span style="font-size:0.8rem; color:#16a34a; font-weight:700;">&#10003; In Catalog</span>';
									} else {
										html += '<button type="button" class="som-btn-icon">Select</button>';
									}
									html += '</div>';
								});
								$('#som_master_results').html(html);
							} else {
								$('#som_master_results').html('<p style="padding:12px; color:#64748b; margin:0; text-align:center;">No master products found matching your search.</p>');
							}
						},
						error: function() {
							$('#som_master_results').html('<p style="padding:12px; color:#ef4444; margin:0; text-align:center;">Failed to search products. Please try again.</p>');
						}
					});
				}

				// Open Add Modal & Fetch Products Immediately
				$('#som_btn_open_add_modal').on('click', function() {
					$('#som_add_product_modal').show();
					$('#som_master_search').val('').focus();
					$('#som_form_add_catalog_product').hide();
					performMasterSearch('');
				});

				// Type-ahead Master Search in Modal
				var masterTimer = null;
				$('#som_master_search').on('keyup input', function() {
					clearTimeout(masterTimer);
					var q = $(this).val().trim();
					masterTimer = setTimeout(function() {
						performMasterSearch(q);
					}, 300);
				});

				// Select Master Product from search results
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
							status: $('#som_add_status').val()
						},
						success: function(res) {
							$btn.prop('disabled', false).html('&#128190; Save to My Catalog');
							if (res.success) {
								$('#som_add_product_modal').hide();
								loadCatalog(1);
							} else {
								alert(res.data.message || 'Error adding product.');
							}
						}
					});
				});

				// Open Edit Modal
				$(document).on('click', '.som-btn-edit-item', function() {
					var item = $(this).data('item');
					if (!item) return;

					$('#som_edit_product_id').val(item.product_id);
					$('#som_edit_selected_title').text('Editing: ' + item.title);
					$('#som_edit_price').val(item.price);
					$('#som_edit_sale_price').val(item.sale_price);
					$('#som_edit_stock_status').val(item.stock_status);
					$('#som_edit_stock_quantity').val(item.stock_quantity);
					$('#som_edit_status').val(item.status);

					$('#som_edit_product_modal').show();
				});

				// Save Edited Product
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
						}
					});
				});

				// Remove Product
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