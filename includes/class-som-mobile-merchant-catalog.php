<?php
/**
 * NearMart Mobile Merchant Catalog REST API Controller (APP-9.2).
 *
 * Dedicated REST controller for quick mobile catalog management and availability control.
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SOM_Mobile_Merchant_Catalog {

	const NAMESPACE = 'nearmart/v1';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST routes for Merchant Catalog.
	 */
	public static function register_routes() {
		// 1. GET /wp-json/nearmart/v1/merchant/products
		register_rest_route(
			self::NAMESPACE,
			'/merchant/products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_merchant_products' ),
				'permission_callback' => array( __CLASS__, 'check_merchant_permission' ),
				'args'                => array(
					'search'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'status'   => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => 'all',
						'sanitize_callback' => 'sanitize_key',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'limit'    => array(
						'type'              => 'integer',
						'default'           => 50,
						'sanitize_callback' => 'absint',
					),
					'lang'     => array(
						'type'              => 'string',
						'default'           => 'en',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// 2. POST /wp-json/nearmart/v1/merchant/products/{id}/availability
		register_rest_route(
			self::NAMESPACE,
			'/merchant/products/(?P<id>\d+)/availability',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'update_product_availability' ),
				'permission_callback' => array( __CLASS__, 'check_merchant_permission' ),
				'args'                => array(
					'id'           => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'available'    => array(
						'type'     => 'boolean',
						'required' => false,
					),
					'stock_status' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Permission callback: Validate user is authenticated and linked to a merchant shop.
	 */
	public static function check_merchant_permission( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to manage your shop catalog.', 'nearmart' ),
				array( 'status' => 401 )
			);
		}

		if ( current_user_can( 'administrator' ) ) {
			return true;
		}

		$shop_id = class_exists( 'SOM_Catalog_Permissions' )
			? SOM_Catalog_Permissions::get_current_merchant_shop_id( $user_id )
			: absint( get_user_meta( $user_id, 'som_shop_id', true ) );

		if ( ! $shop_id ) {
			return new WP_Error(
				'rest_forbidden_no_shop',
				__( 'No active shop associated with this merchant account.', 'nearmart' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Endpoint 1: GET /merchant/products
	 */
	public static function get_merchant_products( WP_REST_Request $request ) {
		$user_id  = get_current_user_id();
		$is_admin = current_user_can( 'administrator' );

		$shop_id = class_exists( 'SOM_Catalog_Permissions' )
			? SOM_Catalog_Permissions::get_current_merchant_shop_id( $user_id )
			: absint( get_user_meta( $user_id, 'som_shop_id', true ) );

		// Security: Prevent merchant from attempting to override shop_id
		$req_shop_id = absint( $request->get_param( 'shop_id' ) );
		if ( $is_admin && $req_shop_id > 0 ) {
			$shop_id = $req_shop_id;
		} elseif ( ! $is_admin && $req_shop_id > 0 && $req_shop_id !== $shop_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'REST_FORBIDDEN_SHOP_OVERRIDE',
					'message' => __( 'Merchants cannot access another shop catalog.', 'nearmart' ),
				),
				403
			);
		}

		if ( ! $shop_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'MERCHANT_SHOP_NOT_FOUND',
					'message' => __( 'No shop associated with this merchant account.', 'nearmart' ),
				),
				403
			);
		}

		$search       = sanitize_text_field( (string) ( $request->get_param( 'search' ) ?: $request->get_param( 'q' ) ) );
		$stock_filter = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( empty( $stock_filter ) ) {
			$stock_filter = 'all';
		}

		// Normalize filter aliases: available -> instock, unavailable -> outofstock
		if ( 'available' === $stock_filter ) {
			$stock_filter = 'instock';
		} elseif ( 'unavailable' === $stock_filter ) {
			$stock_filter = 'outofstock';
		}

		$page  = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$limit = min( 100, max( 1, absint( $request->get_param( 'limit' ) ?: 50 ) ) );
		$lang  = sanitize_key( (string) $request->get_param( 'lang' ) ?: 'en' );
		if ( ! in_array( $lang, array( 'en', 'ml' ), true ) ) {
			$lang = 'en';
		}

		// Query shop products from repository
		$raw_products = nearmart_get_shop_products(
			$shop_id,
			array(
				'status'       => 'active',
				'stock_status' => 'all', // We filter locally to support search & multi-status cleanly
				'limit'        => 1000,
				'offset'       => 0,
				'orderby'      => 'created_at',
				'order'        => 'DESC',
			)
		);

		$products = array();

		foreach ( $raw_products as $p ) {
			if ( isset( $p->status ) && in_array( $p->status, array( 'pending_setup', 'deleted' ), true ) ) {
				continue;
			}

			$item = nearmart_format_catalog_item( $p );
			if ( ! $item ) {
				continue;
			}

			$title       = $item['title'];
			$description = '';
			$cat_name    = $item['category'];

			// Localize title and category if master-linked
			if ( ! empty( $p->product_id ) && class_exists( 'SOM_Master_Product' ) ) {
				$title     = SOM_Master_Product::get_localized_title( $p->product_id, $lang );
				$cat_terms = wp_get_post_terms( $p->product_id, 'product_cat' );
				if ( ! is_wp_error( $cat_terms ) && ! empty( $cat_terms ) ) {
					$cat_name = SOM_Master_Product::get_localized_category_name( $cat_terms[0], $lang );
				}
			}

			// Filter by stock status
			if ( 'all' !== $stock_filter && $item['stock_status'] !== $stock_filter ) {
				continue;
			}

			// Filter by search query (name, brand, master_sku, shop_sku, barcode)
			if ( ! empty( $search ) ) {
				$match_title = false !== stripos( $title, $search );
				$match_brand = false !== stripos( (string) $item['brand'], $search );
				$match_sku   = false !== stripos( (string) $item['master_sku'], $search );
				$match_ssku  = false !== stripos( (string) $item['shop_sku'], $search );
				$match_code  = false !== stripos( (string) $item['barcode'], $search );

				if ( ! $match_title && ! $match_brand && ! $match_sku && ! $match_ssku && ! $match_code ) {
					continue;
				}
			}

			$products[] = array(
				'id'            => (int) $item['id'],
				'product_id'    => $item['product_id'] ? (int) $item['product_id'] : null,
				'is_standalone' => (bool) $item['is_standalone'],
				'name'          => $title,
				'category'      => $cat_name,
				'brand'         => $item['brand'] ? (string) $item['brand'] : null,
				'unit'          => $item['unit'] ? (string) $item['unit'] : null,
				'barcode'       => $item['barcode'] ? (string) $item['barcode'] : null,
				'shop_sku'      => $item['shop_sku'] ? (string) $item['shop_sku'] : null,
				'price'         => (float) $item['price'],
				'sale_price'    => null !== $item['sale_price'] && '' !== $item['sale_price'] ? (float) $item['sale_price'] : null,
				'available'     => 'instock' === $item['stock_status'],
				'stock_status'  => $item['stock_status'],
				'image'         => $item['thumb_url'] ? (string) $item['thumb_url'] : null,
			);
		}

		$total_count = count( $products );
		$offset      = ( $page - 1 ) * $limit;
		$paged_items = array_slice( $products, $offset, $limit );
		$total_pages = max( 1, ceil( $total_count / $limit ) );

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'products'   => $paged_items,
					'pagination' => array(
						'page'        => $page,
						'limit'       => $limit,
						'total'       => $total_count,
						'total_pages' => $total_pages,
					),
				),
			),
			200
		);
	}

	/**
	 * Endpoint 2: POST /merchant/products/{id}/availability
	 */
	public static function update_product_availability( WP_REST_Request $request ) {
		$user_id  = get_current_user_id();
		$is_admin = current_user_can( 'administrator' );

		$shop_id = class_exists( 'SOM_Catalog_Permissions' )
			? SOM_Catalog_Permissions::get_current_merchant_shop_id( $user_id )
			: absint( get_user_meta( $user_id, 'som_shop_id', true ) );

		$id = absint( $request->get_param( 'id' ) );
		if ( ! $id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'INVALID_PRODUCT_ID',
					'message' => __( 'A valid product ID is required.', 'nearmart' ),
				),
				400
			);
		}

		// Fetch catalog row
		$row = nearmart_get_shop_product_by_id( $id );
		if ( ! $row ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'PRODUCT_NOT_FOUND',
					'message' => __( 'Product not found in shop catalog.', 'nearmart' ),
				),
				404
			);
		}

		// Security: Strict multi-shop isolation check
		if ( ! $is_admin && (int) $row->shop_id !== (int) $shop_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'CROSS_SHOP_FORBIDDEN',
					'message' => __( 'Access denied. You can only update products belonging to your shop.', 'nearmart' ),
				),
				403
			);
		}

		// Determine new stock status
		$new_stock_status = '';
		if ( null !== $request->get_param( 'available' ) ) {
			$is_avail = (bool) $request->get_param( 'available' );
			$new_stock_status = $is_avail ? 'instock' : 'outofstock';
		} elseif ( $request->has_param( 'stock_status' ) ) {
			$raw_status = sanitize_key( (string) $request->get_param( 'stock_status' ) );
			if ( in_array( $raw_status, array( 'instock', 'outofstock' ), true ) ) {
				$new_stock_status = $raw_status;
			}
		}

		if ( empty( $new_stock_status ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'INVALID_AVAILABILITY_STATUS',
					'message' => __( 'Availability must be specified as true/false or "instock"/"outofstock".', 'nearmart' ),
				),
				400
			);
		}

		// Update database
		$updated = nearmart_update_shop_product_by_id(
			$id,
			array(
				'stock_status' => $new_stock_status,
			)
		);

		if ( false === $updated ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'UPDATE_FAILED',
					'message' => __( 'Failed to update product availability.', 'nearmart' ),
				),
				500
			);
		}

		// Return formatted updated product
		$fresh_row = nearmart_get_shop_product_by_id( $id );
		$formatted = nearmart_format_catalog_item( $fresh_row );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'instock' === $new_stock_status
					? __( 'Product is now available for customer orders.', 'nearmart' )
					: __( 'Product is now marked unavailable.', 'nearmart' ),
				'data'    => array(
					'product' => array(
						'id'            => (int) $formatted['id'],
						'name'          => $formatted['title'],
						'available'     => 'instock' === $fresh_row->stock_status,
						'stock_status'  => $fresh_row->stock_status,
						'price'         => (float) $fresh_row->price,
						'sale_price'    => null !== $fresh_row->sale_price && '' !== $fresh_row->sale_price ? (float) $fresh_row->sale_price : null,
					),
				),
			),
			200
		);
	}
}

SOM_Mobile_Merchant_Catalog::init();
