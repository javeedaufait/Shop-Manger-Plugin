<?php
/**
 * NearMart Versioned REST API Module (Phase 9).
 *
 * Base Namespace: nearmart/v1
 * Base URL: /wp-json/nearmart/v1/
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_REST_API
 */
class SOM_REST_API {

	/**
	 * Namespace for NearMart API v1.
	 */
	const NAMESPACE = 'nearmart/v1';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST API routes for Customer App.
	 */
	public static function register_routes() {
		// 1. GET /wp-json/nearmart/v1/shops
		register_rest_route(
			self::NAMESPACE,
			'/shops',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_shops' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'page'   => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'limit'  => array(
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'search' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// 2. GET /wp-json/nearmart/v1/shops/{shop_id}
		register_rest_route(
			self::NAMESPACE,
			'/shops/(?P<shop_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_shop' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'shop_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// 3. GET /wp-json/nearmart/v1/shops/{shop_id}/products
		register_rest_route(
			self::NAMESPACE,
			'/shops/(?P<shop_id>\d+)/products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_shop_products' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'shop_id'  => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'limit'    => array(
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'search'   => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'category' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// 4. GET /wp-json/nearmart/v1/products/{product_id}
		register_rest_route(
			self::NAMESPACE,
			'/products/(?P<product_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_product' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'product_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'shop_id'    => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Format standardized REST error response.
	 *
	 * @param string $code    Error code string.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status code (default 400).
	 * @return WP_REST_Response
	 */
	public static function format_error_response( $code, $message, $status = 400 ) {
		return new WP_REST_Response(
			array(
				'success' => false,
				'error'   => array(
					'code'    => $code,
					'message' => $message,
				),
			),
			$status
		);
	}

	/**
	 * Helper: Format shop data structure.
	 *
	 * @param int $shop_id Shop Post ID.
	 * @return array|null Formatted shop array or null if invalid.
	 */
	public static function format_shop( $shop_id ) {
		$post = get_post( $shop_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'shop', 'shop_onboarding' ), true ) || 'publish' !== $post->post_status ) {
			return null;
		}

		$photo_id  = get_post_meta( $shop_id, 'som_shop_photo_id', true );
		$photo_url = $photo_id ? wp_get_attachment_url( $photo_id ) : '';
		if ( ! $photo_url && has_post_thumbnail( $shop_id ) ) {
			$photo_url = get_the_post_thumbnail_url( $shop_id, 'full' );
		}

		$lat = get_post_meta( $shop_id, 'som_latitude', true );
		$lng = get_post_meta( $shop_id, 'som_longitude', true );

		return array(
			'shop_id'   => (int) $shop_id,
			'name'      => get_the_title( $shop_id ),
			'shop_type' => (string) get_post_meta( $shop_id, 'som_shop_type', true ),
			'address'   => (string) get_post_meta( $shop_id, 'som_address', true ),
			'latitude'  => '' !== $lat && is_numeric( $lat ) ? (float) $lat : null,
			'longitude' => '' !== $lng && is_numeric( $lng ) ? (float) $lng : null,
			'photo_url' => $photo_url ? (string) $photo_url : null,
			'status'    => get_post_meta( $shop_id, 'som_verified', true ) ? 'verified' : 'active',
		);
	}

	/**
	 * Endpoint 1: GET /wp-json/nearmart/v1/shops
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function get_shops( WP_REST_Request $request ) {
		$page   = max( 1, $request->get_param( 'page' ) );
		$limit  = min( 100, max( 1, $request->get_param( 'limit' ) ) );
		$search = $request->get_param( 'search' );

		$args = array(
			'post_type'      => array( 'shop', 'shop_onboarding' ),
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );
		$shops = array();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$formatted = self::format_shop( $post->ID );
				if ( $formatted ) {
					$shops[] = $formatted;
				}
			}
		}

		$total_count = $query->found_posts;
		$total_pages = max( 1, ceil( $total_count / $limit ) );

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'shops'      => $shops,
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
	 * Endpoint 2: GET /wp-json/nearmart/v1/shops/{shop_id}
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function get_shop( WP_REST_Request $request ) {
		$shop_id = $request->get_param( 'shop_id' );
		$shop    = self::format_shop( $shop_id );

		if ( ! $shop ) {
			return self::format_error_response( 'shop_not_found', __( 'Shop not found or unavailable.', 'shop-onboarding-manager' ), 404 );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'shop' => $shop,
				),
			),
			200
		);
	}

	/**
	 * Endpoint 3: GET /wp-json/nearmart/v1/shops/{shop_id}/products
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function get_shop_products( WP_REST_Request $request ) {
		$shop_id  = $request->get_param( 'shop_id' );
		$page     = max( 1, $request->get_param( 'page' ) );
		$limit    = min( 100, max( 1, $request->get_param( 'limit' ) ) );
		$search   = $request->get_param( 'search' );
		$category = $request->get_param( 'category' );

		// Validate Shop Exists
		$shop = self::format_shop( $shop_id );
		if ( ! $shop ) {
			return self::format_error_response( 'shop_not_found', __( 'Shop not found or unavailable.', 'shop-onboarding-manager' ), 404 );
		}

		$offset = ( $page - 1 ) * $limit;
		$has_filter = ! empty( $search ) || ! empty( $category );

		// Query active products from repository with SQL pagination if no in-memory filter needed
		$raw_products = nearmart_get_shop_products(
			$shop_id,
			array(
				'status'       => 'active',
				'stock_status' => 'all',
				'limit'        => $has_filter ? 500 : $limit,
				'offset'       => $has_filter ? 0 : $offset,
				'orderby'      => 'created_at',
				'order'        => 'DESC',
			)
		);

		$products = array();
		foreach ( $raw_products as $p ) {
			$product_id  = $p->product_id;
			$master_post = get_post( $product_id );

			if ( ! $master_post || 'product' !== $master_post->post_type || 'publish' !== $master_post->post_status ) {
				continue;
			}

			$title = $master_post->post_title;
			$sku   = get_post_meta( $product_id, '_sku', true );

			// Filter by search
			if ( ! empty( $search ) ) {
				$match_title = false !== stripos( $title, $search );
				$match_sku   = false !== stripos( (string) $sku, $search );
				$match_ssku  = false !== stripos( (string) $p->shop_sku, $search );

				if ( ! $match_title && ! $match_sku && ! $match_ssku ) {
					continue;
				}
			}

			$cat_terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
			$cat_name  = ! empty( $cat_terms ) ? $cat_terms[0] : __( 'Uncategorized', 'shop-onboarding-manager' );

			// Filter by category name
			if ( ! empty( $category ) && false === stripos( $cat_name, $category ) ) {
				continue;
			}

			$specs     = nearmart_get_master_product_specs( $product_id );
			$thumb_url = get_the_post_thumbnail_url( $product_id, 'full' );

			$products[] = array(
				'id'             => (int) $product_id,
				'name'           => $title,
				'image'          => $thumb_url ? (string) $thumb_url : null,
				'category'       => $cat_name,
				'brand'          => $specs['brand_name'] ? (string) $specs['brand_name'] : null,
				'unit'           => $specs['unit'] ? (string) $specs['unit'] : null,
				'barcode'        => $specs['barcode'] ? (string) $specs['barcode'] : null,
				'price'          => (float) number_format( (float) $p->price, 2, '.', '' ),
				'sale_price'     => null !== $p->sale_price && '' !== $p->sale_price ? (float) number_format( (float) $p->sale_price, 2, '.', '' ) : null,
				'available'      => 'instock' === $p->stock_status,
				'stock_quantity' => null !== $p->stock_quantity ? (int) $p->stock_quantity : null,
				'shop_sku'       => $p->shop_sku ? (string) $p->shop_sku : null,
			);
		}

		if ( $has_filter ) {
			$total_count = count( $products );
			$paged       = array_slice( $products, $offset, $limit );
		} else {
			$summary     = nearmart_get_shop_catalog_summary( $shop_id );
			$total_count = $summary['active'];
			$paged       = $products;
		}
		$total_pages = max( 1, ceil( $total_count / $limit ) );

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'products'   => $paged,
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
	 * Endpoint 4: GET /wp-json/nearmart/v1/products/{product_id}
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function get_product( WP_REST_Request $request ) {
		$product_id = $request->get_param( 'product_id' );
		$shop_id    = $request->get_param( 'shop_id' );

		$post = get_post( $product_id );
		if ( ! $post || 'product' !== $post->post_type || 'publish' !== $post->post_status ) {
			return self::format_error_response( 'product_not_found', __( 'Master product not found or unavailable.', 'shop-onboarding-manager' ), 404 );
		}

		$specs     = nearmart_get_master_product_specs( $product_id );
		$cats      = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
		$thumb_url = get_the_post_thumbnail_url( $product_id, 'full' );

		$reg_price = get_post_meta( $product_id, '_regular_price', true );
		if ( '' === $reg_price || null === $reg_price ) {
			$reg_price = get_post_meta( $product_id, '_price', true );
		}
		$sug_price = ( '' !== $reg_price && null !== $reg_price && is_numeric( $reg_price ) ) ? (float) number_format( (float) $reg_price, 2, '.', '' ) : null;

		$product_data = array(
			'id'              => (int) $product_id,
			'name'            => get_the_title( $product_id ),
			'image'           => $thumb_url ? (string) $thumb_url : null,
			'category'        => ! empty( $cats ) ? $cats[0] : __( 'Uncategorized', 'shop-onboarding-manager' ),
			'brand'           => $specs['brand_name'] ? (string) $specs['brand_name'] : null,
			'unit'            => $specs['unit'] ? (string) $specs['unit'] : null,
			'barcode'         => $specs['barcode'] ? (string) $specs['barcode'] : null,
			'sku'             => $specs['sku'] ? (string) $specs['sku'] : null,
			'suggested_price' => $sug_price,
		);

		if ( $shop_id && nearmart_has_shop_product( $shop_id, $product_id ) ) {
			$shop_item = nearmart_get_shop_product( $shop_id, $product_id );
			if ( $shop_item ) {
				$product_data['shop_context'] = array(
					'shop_id'        => (int) $shop_id,
					'price'          => (float) number_format( (float) $shop_item->price, 2, '.', '' ),
					'sale_price'     => null !== $shop_item->sale_price && '' !== $shop_item->sale_price ? (float) number_format( (float) $shop_item->sale_price, 2, '.', '' ) : null,
					'available'      => 'instock' === $shop_item->stock_status,
					'stock_quantity' => null !== $shop_item->stock_quantity ? (int) $shop_item->stock_quantity : null,
					'shop_sku'       => $shop_item->shop_sku ? (string) $shop_item->shop_sku : null,
				);
			}
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'product' => $product_data,
				),
			),
			200
		);
	}
}