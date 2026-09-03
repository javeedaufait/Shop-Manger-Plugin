<?php
/**
 * Merchant Dashboard Core Module (Phase 2 HYBRID Catalog Backend).
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
	 * Initialize hooks.
	 */
	public static function init() {
		add_shortcode( 'som_merchant_dashboard', array( __CLASS__, 'render_dashboard_shortcode' ) );

		// AJAX Endpoints
		add_action( 'wp_ajax_som_merchant_confirm_details', array( __CLASS__, 'ajax_confirm_details' ) );
		add_action( 'wp_ajax_som_merchant_accept_agreement', array( __CLASS__, 'ajax_accept_agreement' ) );
		add_action( 'wp_ajax_som_merchant_request_change', array( __CLASS__, 'ajax_request_change' ) );

		// Catalog AJAX Endpoints (Phase 2 HYBRID Catalog)
		add_action( 'wp_ajax_som_merchant_get_catalog', array( __CLASS__, 'ajax_get_catalog' ) );
		add_action( 'wp_ajax_som_merchant_search_master_products', array( __CLASS__, 'ajax_search_master_products' ) );
		add_action( 'wp_ajax_som_merchant_add_catalog_product', array( __CLASS__, 'ajax_add_catalog_product' ) );
		add_action( 'wp_ajax_som_merchant_add_standalone_product', array( __CLASS__, 'ajax_add_standalone_product' ) );
		add_action( 'wp_ajax_som_merchant_check_similar_master_products', array( __CLASS__, 'ajax_check_similar_master_products' ) );
		add_action( 'wp_ajax_som_merchant_update_catalog_product', array( __CLASS__, 'ajax_update_catalog_product' ) );
		add_action( 'wp_ajax_som_merchant_remove_catalog_product', array( __CLASS__, 'ajax_remove_catalog_product' ) );
	}

	/**
	 * Evaluate and update shop status to 'committed' if conditions are met.
	 *
	 * Conditions:
	 * 1. Shop has taxonomy status 'verified'.
	 * 2. Merchant has accepted agreement (som_agreement_accepted == true).
	 *
	 * @param int $shop_id Shop Post ID.
	 * @return bool Whether status was changed to committed.
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
		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );

		if ( ! $shop_id || ! nearmart_user_can_manage_shop( $user_id, $shop_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'nearmart' ) ), 403 );
		}

		update_post_meta( $shop_id, 'som_details_confirmed', true );
		update_post_meta( $shop_id, 'som_details_confirmed_at', current_time( 'mysql' ) );
		update_post_meta( $shop_id, 'som_details_confirmed_by', $user_id );

		wp_send_json_success( array( 'message' => __( 'Shop details confirmed successfully!', 'nearmart' ) ) );
	}

	/**
	 * AJAX endpoint: Accept Participation Agreement.
	 */
	public static function ajax_accept_agreement() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );

		if ( ! $shop_id || ! nearmart_user_can_manage_shop( $user_id, $shop_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'nearmart' ) ), 403 );
		}

		update_post_meta( $shop_id, 'som_agreement_accepted', true );
		update_post_meta( $shop_id, 'som_agreement_version', '1.0' );
		update_post_meta( $shop_id, 'som_agreement_accepted_at', current_time( 'mysql' ) );
		update_post_meta( $shop_id, 'som_agreement_accepted_by', $user_id );

		self::evaluate_commitment_status( $shop_id );

		wp_send_json_success( array( 'message' => __( 'Participation agreement accepted successfully!', 'nearmart' ) ) );
	}

	/**
	 * AJAX endpoint: Request Change / Update Notes.
	 */
	public static function ajax_request_change() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );

		if ( ! $shop_id || ! nearmart_user_can_manage_shop( $user_id, $shop_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'nearmart' ) ), 403 );
		}

		$notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		if ( empty( $notes ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter details for your change request.', 'nearmart' ) ) );
		}

		$existing_concerns = get_post_meta( $shop_id, 'som_concerns', true );
		$timestamp         = date_i18n( 'M j, Y g:i a' );
		$new_entry         = "[$timestamp] $notes";
		$updated_concerns  = ! empty( $existing_concerns ) ? $existing_concerns . "\n\n" . $new_entry : $new_entry;

		update_post_meta( $shop_id, 'som_concerns', $updated_concerns );

		wp_send_json_success( array( 'message' => __( 'Your change request has been submitted to admin.', 'nearmart' ) ) );
	}

	/**
	 * AJAX endpoint: Get Merchant Shop Catalog with Search, Filter & Pagination (HYBRID model).
	 */
	public static function ajax_get_catalog() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );

		if ( ! $shop_id || ! nearmart_user_can_manage_shop( $user_id, $shop_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'nearmart' ) ), 403 );
		}

		$search       = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$category     = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : 'all';
		$stock_status = isset( $_POST['stock_status'] ) ? sanitize_key( $_POST['stock_status'] ) : 'all';
		$page         = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$limit        = isset( $_POST['per_page'] ) ? min( 100, max( 10, absint( $_POST['per_page'] ) ) ) : 25;
		$offset       = ( $page - 1 ) * $limit;

		$raw_products = nearmart_get_shop_products(
			$shop_id,
			array(
				'status'       => 'all',
				'stock_status' => $stock_status,
				'limit'        => 1000,
				'offset'       => 0,
				'orderby'      => 'created_at',
				'order'        => 'DESC',
			)
		);

		$items      = array();
		$categories = array();

		foreach ( $raw_products as $p ) {
			if ( isset( $p->status ) && in_array( $p->status, array( 'pending_setup', 'deleted' ), true ) ) {
				continue;
			}
			$item = nearmart_format_catalog_item( $p );
			if ( ! $item ) {
				continue;
			}

			if ( ! empty( $item['category'] ) && ! in_array( $item['category'], $categories, true ) ) {
				$categories[] = $item['category'];
			}

			if ( 'all' !== $category && '' !== $category ) {
				if ( false === stripos( $item['category'], $category ) ) {
					continue;
				}
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

		sort( $categories );
		$total_count = count( $items );
		$paged_items = array_slice( $items, $offset, $limit );
		$total_pages = ceil( $total_count / $limit );

		wp_send_json_success(
			array(
				'items'        => $paged_items,
				'total_count'  => $total_count,
				'total_pages'  => max( 1, $total_pages ),
				'current_page' => $page,
				'per_page'     => $limit,
				'categories'   => $categories,
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
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'nearmart' ) ), 403 );
		}

		$query = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';

		if ( mb_strlen( $query ) < 2 ) {
			wp_send_json_success( array( 'results' => array() ) );
		}

		global $wpdb;
		$search_like = '%' . $wpdb->esc_like( $query ) . '%';

		$sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm_sku ON (p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku')
			LEFT JOIN {$wpdb->postmeta} pm_barcode ON (p.ID = pm_barcode.post_id AND pm_barcode.meta_key = '_nearmart_barcode')
			LEFT JOIN {$wpdb->postmeta} pm_ml ON (p.ID = pm_ml.post_id AND pm_ml.meta_key = '_nearmart_name_ml')
			WHERE p.post_type = 'product'
			AND p.post_status = 'publish'
			AND (
				p.post_title LIKE %s
				OR pm_sku.meta_value LIKE %s
				OR pm_barcode.meta_value LIKE %s
				OR pm_ml.meta_value LIKE %s
			)
			ORDER BY p.post_title ASC
			LIMIT 20";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$product_ids = $wpdb->get_col( $wpdb->prepare( $sql, $search_like, $search_like, $search_like, $search_like ) );
		$results     = array();

		if ( ! empty( $product_ids ) ) {
			foreach ( $product_ids as $pid ) {
				$pid = absint( $pid );
				$already_in_catalog = nearmart_has_shop_product( $shop_id, $pid );
				$specs              = nearmart_get_master_product_specs( $pid );
				$cats               = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'names' ) );
				$thumb_url          = get_the_post_thumbnail_url( $pid, 'thumbnail' );

				$reg_price = get_post_meta( $pid, '_regular_price', true );
				if ( '' === $reg_price || null === $reg_price ) {
					$reg_price = get_post_meta( $pid, '_price', true );
				}
				$sug_price = ( '' !== $reg_price && null !== $reg_price && is_numeric( $reg_price ) ) ? number_format( (float) $reg_price, 2, '.', '' ) : '';

				$cat_terms = wp_get_post_terms( $pid, 'product_cat' );
				$category  = ! empty( $cat_terms ) && ! is_wp_error( $cat_terms ) ? SOM_Master_Product::get_localized_category_name( $cat_terms[0] ) : __( 'Uncategorized', 'nearmart' );

				$results[] = array(
					'product_id'      => $pid,
					'title'           => SOM_Master_Product::get_localized_title( $pid ),
					'category'        => $category,
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
	 * AJAX endpoint: Add Master Product to Merchant Shop Catalog.
	 */
	public static function ajax_add_catalog_product() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );

		if ( ! $shop_id || ! nearmart_user_can_manage_shop( $user_id, $shop_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'nearmart' ) ), 403 );
		}

		$product_id     = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$price          = isset( $_POST['price'] ) ? floatval( $_POST['price'] ) : 0.00;
		$sale_price     = ( isset( $_POST['sale_price'] ) && '' !== $_POST['sale_price'] ) ? floatval( $_POST['sale_price'] ) : null;
		$stock_quantity = ( isset( $_POST['stock_quantity'] ) && '' !== $_POST['stock_quantity'] ) ? intval( $_POST['stock_quantity'] ) : null;
		$stock_status   = isset( $_POST['stock_status'] ) ? sanitize_key( $_POST['stock_status'] ) : 'instock';
		$status         = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'active';
		$shop_sku       = isset( $_POST['shop_sku'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_sku'] ) ) : null;

		if ( ! $product_id || get_post_type( $product_id ) !== 'product' || get_post_status( $product_id ) !== 'publish' ) {
			wp_send_json_error( array( 'message' => __( 'Invalid or inactive master product selected.', 'nearmart' ) ) );
		}

		if ( nearmart_has_shop_product( $shop_id, $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This product is already in your shop catalog.', 'nearmart' ) ) );
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
			wp_send_json_error( array( 'message' => __( 'Failed to add product to catalog.', 'nearmart' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Master product added to your shop catalog successfully!', 'nearmart' ) ) );
	}

	/**
	 * AJAX endpoint: Add Standalone Product to Merchant Shop Catalog (product_id = NULL).
	 */
	public static function ajax_add_standalone_product() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );

		if ( ! $shop_id || ! nearmart_user_can_manage_shop( $user_id, $shop_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'nearmart' ) ), 403 );
		}

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

		if ( empty( $custom_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Product name is required.', 'nearmart' ) ) );
		}

		// Check duplicate standalone name in shop
		global $wpdb;
		$table = SOM_Catalog_Repository::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dup = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE shop_id = %d AND LOWER(custom_name) = LOWER(%s) AND status != 'deleted'", $shop_id, $custom_name ) );
		if ( (int) $dup > 0 ) {
			wp_send_json_error( array( 'message' => sprintf( __( 'A product named "%s" is already in your shop catalog.', 'nearmart' ), esc_html( $custom_name ) ) ) );
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
			wp_send_json_error( array( 'message' => __( 'Failed to add standalone product to catalog.', 'nearmart' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Standalone product added to your catalog successfully!', 'nearmart' ) ) );
	}

	/**
	 * AJAX endpoint: Check for similar master products when typing a standalone product name.
	 */
	public static function ajax_check_similar_master_products() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );

		if ( ! $shop_id || ! nearmart_user_can_manage_shop( $user_id, $shop_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'nearmart' ) ), 403 );
		}

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		if ( mb_strlen( trim( $name ) ) < 2 ) {
			wp_send_json_success( array( 'suggestions' => array() ) );
		}

		global $wpdb;
		$search_like = '%' . $wpdb->esc_like( trim( $name ) ) . '%';

		$sql = "SELECT DISTINCT p.ID, p.post_title FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm_ml ON (p.ID = pm_ml.post_id AND pm_ml.meta_key = '_nearmart_name_ml')
			WHERE p.post_type = 'product'
			AND p.post_status = 'publish'
			AND (
				p.post_title LIKE %s
				OR pm_ml.meta_value LIKE %s
			)
			ORDER BY p.post_title ASC
			LIMIT 3";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$posts       = $wpdb->get_results( $wpdb->prepare( $sql, $search_like, $search_like ) );
		$suggestions = array();

		if ( ! empty( $posts ) ) {
			foreach ( $posts as $p ) {
				$specs = nearmart_get_master_product_specs( $p->ID );
				$cats  = wp_get_post_terms( $p->ID, 'product_cat', array( 'fields' => 'names' ) );

				$reg_price = get_post_meta( $p->ID, '_regular_price', true );
				if ( '' === $reg_price || null === $reg_price ) {
					$reg_price = get_post_meta( $p->ID, '_price', true );
				}
				$sug_price = ( '' !== $reg_price && null !== $reg_price && is_numeric( $reg_price ) ) ? number_format( (float) $reg_price, 2, '.', '' ) : '';

				$suggestions[] = array(
					'product_id'      => $p->ID,
					'title'           => SOM_Master_Product::get_localized_title( $p->ID ),
					'category'        => ! empty( $cats ) ? $cats[0] : '',
					'brand'           => $specs['brand_name'],
					'unit'            => $specs['unit'],
					'suggested_price' => $sug_price,
				);
			}
		}

		wp_send_json_success( array( 'suggestions' => $suggestions ) );
	}

	/**
	 * AJAX endpoint: Update Shop Product in Catalog (HYBRID Model).
	 */
	public static function ajax_update_catalog_product() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );

		if ( ! $shop_id || ! nearmart_user_can_manage_shop( $user_id, $shop_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'nearmart' ) ), 403 );
		}

		$id             = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$product_id     = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$price          = isset( $_POST['price'] ) ? floatval( $_POST['price'] ) : 0.00;
		$sale_price     = ( isset( $_POST['sale_price'] ) && '' !== $_POST['sale_price'] ) ? floatval( $_POST['sale_price'] ) : null;
		$stock_quantity = ( isset( $_POST['stock_quantity'] ) && '' !== $_POST['stock_quantity'] ) ? intval( $_POST['stock_quantity'] ) : null;
		$stock_status   = isset( $_POST['stock_status'] ) ? sanitize_key( $_POST['stock_status'] ) : 'instock';
		$status         = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'active';
		$shop_sku       = isset( $_POST['shop_sku'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_sku'] ) ) : null;

		// Standalone fields
		$custom_name     = isset( $_POST['custom_name'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_name'] ) ) : null;
		$custom_category = isset( $_POST['custom_category'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_category'] ) ) : null;
		$custom_brand    = isset( $_POST['custom_brand'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_brand'] ) ) : null;
		$custom_unit     = isset( $_POST['custom_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_unit'] ) ) : null;
		$custom_barcode  = isset( $_POST['custom_barcode'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_barcode'] ) ) : null;

		$row = null;
		if ( $id ) {
			$row = nearmart_get_shop_product_by_id( $id );
		} elseif ( $product_id ) {
			$row = nearmart_get_shop_product( $shop_id, $product_id );
		}

		if ( ! $row || (int) $row->shop_id !== (int) $shop_id ) {
			wp_send_json_error( array( 'message' => __( 'Product not found in your shop catalog.', 'nearmart' ) ) );
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
			wp_send_json_error( array( 'message' => __( 'Failed to update catalog product.', 'nearmart' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Catalog product updated successfully!', 'nearmart' ) ) );
	}

	/**
	 * AJAX endpoint: Remove Product from Merchant Shop Catalog.
	 */
	public static function ajax_remove_catalog_product() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );

		if ( ! $shop_id || ! nearmart_user_can_manage_shop( $user_id, $shop_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'nearmart' ) ), 403 );
		}

		$id         = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		$row = null;
		if ( $id ) {
			$row = nearmart_get_shop_product_by_id( $id );
		} elseif ( $product_id ) {
			$row = nearmart_get_shop_product( $shop_id, $product_id );
		}

		if ( ! $row || (int) $row->shop_id !== (int) $shop_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product catalog entry.', 'nearmart' ) ) );
		}

		$result = nearmart_remove_shop_product_by_id( $row->id );

		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to remove product from catalog.', 'nearmart' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Product removed from your shop catalog.', 'nearmart' ) ) );
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
				esc_html__( 'Please log in with a merchant account to access your dashboard.', 'nearmart' ) .
				' <br /><br /><a href="' . esc_url( home_url( '/merchant-login/' ) ) . '" class="som-submit-btn som-btn-secondary" style="text-decoration:none; display:inline-block; width:auto; padding:10px 20px;">' .
				esc_html__( 'Go to Merchant Login &rarr;', 'nearmart' ) . '</a></div></div>';
		}

		$shop_id = nearmart_get_current_merchant_shop_id( $user_id );
		if ( ! $shop_id ) {
			return '<div class="som-merchant-card"><div class="som-card-header"><h2>' .
				esc_html__( 'Merchant Dashboard', 'nearmart' ) . '</h2></div><p>' .
				esc_html__( 'No shop is currently linked to your merchant user account. Please contact NearMart support.', 'nearmart' ) .
				'</p></div>';
		}

		$shop_name       = get_the_title( $shop_id );
		$owner_name      = get_post_meta( $shop_id, 'som_owner_name', true );
		$phone_number    = get_post_meta( $shop_id, 'som_phone_number', true );
		$address         = get_post_meta( $shop_id, 'som_address', true );
		$shop_type       = get_post_meta( $shop_id, 'som_shop_type', true );
		$is_confirmed    = (bool) get_post_meta( $shop_id, 'som_details_confirmed', true );
		$is_accepted     = (bool) get_post_meta( $shop_id, 'som_agreement_accepted', true );
		$is_verified     = (bool) get_post_meta( $shop_id, 'som_verified', true );
		$concerns        = get_post_meta( $shop_id, 'som_concerns', true );
		$photo_id        = get_post_meta( $shop_id, 'som_shop_photo_id', true );
		$photo_url       = $photo_id ? wp_get_attachment_url( $photo_id ) : '';
		$catalog_summary = nearmart_get_shop_catalog_summary( $shop_id );
		$nonce           = wp_create_nonce( 'som_merchant_dashboard_nonce' );

		ob_start();
		?>
		<div class="som-merchant-dashboard-wrap">
			<?php echo SOM_Merchant_Catalog::render_portal_nav( 'dashboard' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<div class="som-dashboard-header" style="margin-top: 16px;">
				<div class="som-header-title">
					<h2>&#127978; <?php printf( esc_html__( 'Welcome, %s', 'nearmart' ), esc_html( $shop_name ) ); ?></h2>
					<p><?php esc_html_e( 'Manage your store details, catalog items, and account verification.', 'nearmart' ); ?></p>
				</div>
				<div class="som-header-status-badge">
					<?php if ( $is_verified ) : ?>
						<span class="som-badge verified">&#10004; <?php esc_html_e( 'Verified Shop', 'nearmart' ); ?></span>
					<?php else : ?>
						<span class="som-badge pending">&#128336; <?php esc_html_e( 'Verification Pending', 'nearmart' ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<div class="som-dashboard-grid">
				<div class="som-dash-card">
					<div class="som-card-header">
						<h3>&#128202; <?php esc_html_e( 'Catalog Overview', 'nearmart' ); ?></h3>
					</div>
					<div class="som-catalog-stats-grid">
						<div class="som-stat-box">
							<span class="som-stat-value"><?php echo esc_html( $catalog_summary['total'] ); ?></span>
							<span class="som-stat-label"><?php esc_html_e( 'Total Products', 'nearmart' ); ?></span>
						</div>
						<div class="som-stat-box green">
							<span class="som-stat-value"><?php echo esc_html( $catalog_summary['active'] ); ?></span>
							<span class="som-stat-label"><?php esc_html_e( 'Active Listed', 'nearmart' ); ?></span>
						</div>
						<div class="som-stat-box orange">
							<span class="som-stat-value"><?php echo esc_html( $catalog_summary['outofstock'] ); ?></span>
							<span class="som-stat-label"><?php esc_html_e( 'Unavailable', 'nearmart' ); ?></span>
						</div>
					</div>
					<div style="margin-top: 16px;">
						<a href="<?php echo esc_url( function_exists( 'nm_get_page_link' ) ? nm_get_page_link( 'merchant-catalog' ) : home_url( '/merchant-catalog/' ) ); ?>" class="som-submit-btn" style="text-decoration:none; display:block; text-align:center;">
							&#128722; <?php esc_html_e( 'Manage Full Catalog &rarr;', 'nearmart' ); ?>
						</a>
					</div>
				</div>

				<div class="som-dash-card">
					<div class="som-card-header">
						<h3>&#128221; <?php esc_html_e( 'Shop Information', 'nearmart' ); ?></h3>
					</div>
					<div class="som-info-list">
						<div class="som-info-item">
							<strong><?php esc_html_e( 'Shop Name:', 'nearmart' ); ?></strong>
							<span><?php echo esc_html( $shop_name ); ?></span>
						</div>
						<div class="som-info-item">
							<strong><?php esc_html_e( 'Owner:', 'nearmart' ); ?></strong>
							<span><?php echo esc_html( $owner_name ? $owner_name : '—' ); ?></span>
						</div>
						<div class="som-info-item">
							<strong><?php esc_html_e( 'Phone:', 'nearmart' ); ?></strong>
							<span><?php echo esc_html( $phone_number ? $phone_number : '—' ); ?></span>
						</div>
						<div class="som-info-item">
							<strong><?php esc_html_e( 'Category:', 'nearmart' ); ?></strong>
							<span><?php echo esc_html( $shop_type ? $shop_type : '—' ); ?></span>
						</div>
						<div class="som-info-item">
							<strong><?php esc_html_e( 'Address:', 'nearmart' ); ?></strong>
							<span><?php echo esc_html( $address ? $address : '—' ); ?></span>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}