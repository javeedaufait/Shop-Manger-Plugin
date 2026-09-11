<?php
/**
 * NearMart Versioned REST API Module (Phase 3 HYBRID Catalog).
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
	 * Sanitize 'lang' REST request parameter ('en' or 'ml', default 'en').
	 *
	 * @param string $param Raw parameter string.
	 * @return string Sanitized language code ('en' or 'ml').
	 */
	public static function sanitize_lang_param( $param ) {
		$param = sanitize_key( $param );
		return in_array( $param, array( 'en', 'ml' ), true ) ? $param : 'en';
	}

	/**
	 * Get standard 'lang' REST route argument definition.
	 *
	 * @return array
	 */
	public static function get_lang_arg_definition() {
		return array(
			'default'           => 'en',
			'sanitize_callback' => array( __CLASS__, 'sanitize_lang_param' ),
		);
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
					'lang'   => self::get_lang_arg_definition(),
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
					'lang'    => self::get_lang_arg_definition(),
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
					'lang'     => self::get_lang_arg_definition(),
				),
			)
		);

		// 4. GET /wp-json/nearmart/v1/products/search (APP-10.2)
		register_rest_route(
			self::NAMESPACE,
			'/products/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'search_nearby_products' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'      => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'lat'    => array(
						'required'          => false,
						'sanitize_callback' => array( 'SOM_Mobile_Shops', 'sanitize_float' ),
					),
					'lng'    => array(
						'required'          => false,
						'sanitize_callback' => array( 'SOM_Mobile_Shops', 'sanitize_float' ),
					),
					'radius' => array(
						'default'           => 30,
						'sanitize_callback' => array( 'SOM_Mobile_Shops', 'sanitize_float' ),
					),
					'area'   => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'   => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'limit'  => array(
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'lang'   => self::get_lang_arg_definition(),
				),
			)
		);

		// 5. GET /wp-json/nearmart/v1/products/{product_id}
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
					'lang'       => self::get_lang_arg_definition(),
				),
			)
		);

		// 6. GET /wp-json/nearmart/v1/customer/favorites
		register_rest_route(
			self::NAMESPACE,
			'/customer/favorites',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_customer_favorites' ),
				'permission_callback' => array( __CLASS__, 'permissions_authenticated_customer' ),
				'args'                => array(
					'lat'  => array(
						'type'     => 'number',
						'required' => false,
					),
					'lng'  => array(
						'type'     => 'number',
						'required' => false,
					),
					'lang' => self::get_lang_arg_definition(),
				),
			)
		);

		// 7. POST /wp-json/nearmart/v1/customer/favorites/{shop_id}
		register_rest_route(
			self::NAMESPACE,
			'/customer/favorites/(?P<shop_id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'add_customer_favorite' ),
				'permission_callback' => array( __CLASS__, 'permissions_authenticated_customer' ),
				'args'                => array(
					'shop_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// 8. DELETE /wp-json/nearmart/v1/customer/favorites/{shop_id}
		register_rest_route(
			self::NAMESPACE,
			'/customer/favorites/(?P<shop_id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'remove_customer_favorite' ),
				'permission_callback' => array( __CLASS__, 'permissions_authenticated_customer' ),
				'args'                => array(
					'shop_id' => array(
						'required'          => true,
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
			return self::format_error_response( 'shop_not_found', __( 'Shop not found or unavailable.', 'nearmart' ), 404 );
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
	 * Endpoint 3: GET /wp-json/nearmart/v1/shops/{shop_id}/products (HYBRID Model).
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
		$lang     = self::sanitize_lang_param( $request->get_param( 'lang' ) );

		// Validate Shop Exists
		$shop = self::format_shop( $shop_id );
		if ( ! $shop ) {
			return self::format_error_response( 'shop_not_found', __( 'Shop not found or unavailable.', 'nearmart' ), 404 );
		}

		$offset     = ( $page - 1 ) * $limit;
		$has_filter = ! empty( $search ) || ! empty( $category );

		// Query active products from repository
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
			$item = nearmart_format_catalog_item( $p );
			if ( ! $item || 'active' !== $item['status'] ) {
				continue;
			}

			$title       = $item['title'];
			$description = '';
			$cat_name    = $item['category'];

			if ( ! empty( $p->product_id ) ) {
				$title       = SOM_Master_Product::get_localized_title( $p->product_id, $lang );
				$description = SOM_Master_Product::get_localized_description( $p->product_id, $lang );
				$cat_terms   = wp_get_post_terms( $p->product_id, 'product_cat' );
				if ( ! is_wp_error( $cat_terms ) && ! empty( $cat_terms ) ) {
					$cat_name = SOM_Master_Product::get_localized_category_name( $cat_terms[0], $lang );
				}
			}

			// Filter by search
			if ( ! empty( $search ) ) {
				$match_title = false !== stripos( $title, $search );
				$match_brand = false !== stripos( (string) $item['brand'], $search );
				$match_sku   = false !== stripos( (string) $item['master_sku'], $search );
				$match_ssku  = false !== stripos( (string) $item['shop_sku'], $search );

				if ( ! $match_title && ! $match_brand && ! $match_sku && ! $match_ssku ) {
					continue;
				}
			}

			// Filter by category name
			if ( ! empty( $category ) && false === stripos( $cat_name, $category ) ) {
				continue;
			}

			$products[] = array(
				'id'             => (int) $item['id'],
				'name'           => $title,
				'description'    => $description,
				'image'          => $item['thumb_url'] ? (string) $item['thumb_url'] : null,
				'category'       => $cat_name,
				'brand'          => $item['brand'] ? (string) $item['brand'] : null,
				'unit'           => $item['unit'] ? (string) $item['unit'] : null,
				'barcode'        => $item['barcode'] ? (string) $item['barcode'] : null,
				'price'          => (float) $item['price'],
				'sale_price'     => null !== $item['sale_price'] && '' !== $item['sale_price'] ? (float) $item['sale_price'] : null,
				'available'      => 'instock' === $item['stock_status'],
				'stock_quantity' => null !== $item['stock_quantity'] ? (int) $item['stock_quantity'] : null,
				'shop_sku'       => $item['shop_sku'] ? (string) $item['shop_sku'] : null,
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
		$lang       = self::sanitize_lang_param( $request->get_param( 'lang' ) );

		$post = get_post( $product_id );
		if ( $post && 'product' === $post->post_type && 'publish' === $post->post_status ) {
			$specs       = nearmart_get_master_product_specs( $product_id );
			$cat_terms   = wp_get_post_terms( $product_id, 'product_cat' );
			$cat_name    = ! empty( $cat_terms ) && ! is_wp_error( $cat_terms ) ? SOM_Master_Product::get_localized_category_name( $cat_terms[0], $lang ) : __( 'Uncategorized', 'nearmart' );
			$thumb_url   = get_the_post_thumbnail_url( $product_id, 'full' );
			$name        = SOM_Master_Product::get_localized_title( $product_id, $lang );
			$description = SOM_Master_Product::get_localized_description( $product_id, $lang );

			$reg_price = get_post_meta( $product_id, '_regular_price', true );
			if ( '' === $reg_price || null === $reg_price ) {
				$reg_price = get_post_meta( $product_id, '_price', true );
			}
			$sug_price = ( '' !== $reg_price && null !== $reg_price && is_numeric( $reg_price ) ) ? (float) number_format( (float) $reg_price, 2, '.', '' ) : null;

			$product_data = array(
				'id'              => (int) $product_id,
				'name'            => $name,
				'description'     => $description,
				'image'           => $thumb_url ? (string) $thumb_url : null,
				'category'        => $cat_name,
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

		// Fallback check for Standalone Shop Product row ID
		$shop_row = nearmart_get_shop_product_by_id( $product_id );
		if ( $shop_row ) {
			$item = nearmart_format_catalog_item( $shop_row );
			if ( $item ) {
				return new WP_REST_Response(
					array(
						'success' => true,
						'data'    => array(
							'product' => array(
								'id'             => (int) $item['id'],
								'name'           => $item['title'],
								'description'    => '',
								'image'          => $item['thumb_url'] ? (string) $item['thumb_url'] : null,
								'category'       => $item['category'],
								'brand'          => $item['brand'] ? (string) $item['brand'] : null,
								'unit'           => $item['unit'] ? (string) $item['unit'] : null,
								'barcode'        => $item['barcode'] ? (string) $item['barcode'] : null,
								'price'          => (float) $item['price'],
								'sale_price'     => null !== $item['sale_price'] && '' !== $item['sale_price'] ? (float) $item['sale_price'] : null,
								'available'      => 'instock' === $item['stock_status'],
								'stock_quantity' => null !== $item['stock_quantity'] ? (int) $item['stock_quantity'] : null,
								'shop_sku'       => $item['shop_sku'] ? (string) $item['shop_sku'] : null,
							),
						),
					),
					200
				);
			}
		}

		return self::format_error_response( 'product_not_found', __( 'Product not found or unavailable.', 'nearmart' ), 404 );
	}

	/**
	 * Endpoint 5: GET /wp-json/nearmart/v1/products/search (APP-10.2).
	 * Searches products across nearby shops within customer's delivery/pickup radius.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function search_nearby_products( WP_REST_Request $request ) {
		global $wpdb;

		$q        = trim( (string) $request->get_param( 'q' ) );
		$user_lat = $request->get_param( 'lat' );
		$user_lng = $request->get_param( 'lng' );
		$radius   = max( 1, min( 100, (float) ( $request->get_param( 'radius' ) ?: 30 ) ) );
		$area     = trim( (string) $request->get_param( 'area' ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$limit    = max( 1, min( 50, (int) $request->get_param( 'limit' ) ) );
		$lang     = self::sanitize_lang_param( $request->get_param( 'lang' ) );

		if ( empty( $q ) ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => array(
						'products'   => array(),
						'pagination' => array(
							'page'        => $page,
							'limit'       => $limit,
							'total'       => 0,
							'total_pages' => 0,
						),
						'query'      => '',
					),
				),
				200
			);
		}

		$has_coordinates = null !== $user_lat && null !== $user_lng && is_numeric( $user_lat ) && is_numeric( $user_lng );

		// 1. Resolve eligible published shops within radius/area
		$query_args = array(
			'post_type'      => array( 'shop', 'shop_onboarding' ),
			'post_status'    => 'publish',
			'posts_per_page' => 150,
		);
		$shops_query = new WP_Query( $query_args );
		$eligible_shops = array();

		if ( $shops_query->have_posts() ) {
			foreach ( $shops_query->posts as $post ) {
				$shop_id = $post->ID;
				$address = (string) get_post_meta( $shop_id, 'som_address', true );

				// Area filter if no GPS coordinates
				if ( ! empty( $area ) && ! $has_coordinates ) {
					$title_matches   = stripos( $post->post_title, $area ) !== false;
					$address_matches = stripos( $address, $area ) !== false;
					if ( ! $title_matches && ! $address_matches ) {
						continue;
					}
				}

				$lat_raw  = get_post_meta( $shop_id, 'som_latitude', true );
				$lng_raw  = get_post_meta( $shop_id, 'som_longitude', true );
				$shop_lat = '' !== $lat_raw && is_numeric( $lat_raw ) ? (float) $lat_raw : null;
				$shop_lng = '' !== $lng_raw && is_numeric( $lng_raw ) ? (float) $lng_raw : null;

				$distance_km   = null;
				$distance_text = null;

				if ( $has_coordinates && null !== $shop_lat && null !== $shop_lng ) {
					$distance_km = SOM_Mobile_Shops::calculate_haversine_distance( (float) $user_lat, (float) $user_lng, $shop_lat, $shop_lng );
					if ( $distance_km > $radius ) {
						continue;
					}
					$distance_text = $distance_km < 1
						? round( $distance_km * 1000 ) . ' m'
						: $distance_km . ' km';
				}

				$eligible_shops[ $shop_id ] = array(
					'shop_id'       => $shop_id,
					'shop_name'     => $post->post_title,
					'shop_address'  => $address,
					'distance_km'   => $distance_km,
					'distance_text' => $distance_text,
				);
			}
		}

		if ( empty( $eligible_shops ) ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => array(
						'products'   => array(),
						'pagination' => array(
							'page'        => $page,
							'limit'       => $limit,
							'total'       => 0,
							'total_pages' => 0,
						),
						'query'      => $q,
					),
				),
				200
			);
		}

		$shop_ids     = array_keys( $eligible_shops );
		$placeholders = implode( ',', array_fill( 0, count( $shop_ids ), '%d' ) );
		$table_name   = SOM_Catalog_Repository::get_table_name();

		// 2. Query matching products in wp_nearmart_shop_products
		$like_param = '%' . $wpdb->esc_like( $q ) . '%';

		// Match master WC products by title or Malayalam title
		$matching_master_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key IN ('_nearmart_name_ml', '_nearmart_barcode', '_nearmart_unit'))
				WHERE p.post_type = 'product' AND p.post_status = 'publish'
				AND (p.post_title LIKE %s OR pm.meta_value LIKE %s)",
				$like_param,
				$like_param
			)
		);

		$master_condition = '';
		$query_params     = $shop_ids;
		if ( ! empty( $matching_master_ids ) ) {
			$master_placeholders = implode( ',', array_fill( 0, count( $matching_master_ids ), '%d' ) );
			$master_condition    = "product_id IN ({$master_placeholders}) OR ";
			$query_params        = array_merge( $query_params, $matching_master_ids );
		}

		$query_params[] = $like_param;
		$query_params[] = $like_param;
		$query_params[] = $like_param;
		$query_params[] = $like_param;

		$sql = "SELECT * FROM {$table_name}
			WHERE shop_id IN ({$placeholders})
			AND status = 'active'
			AND stock_status != 'deleted'
			AND (
				{$master_condition}
				custom_name LIKE %s
				OR custom_brand LIKE %s
				OR shop_sku LIKE %s
				OR custom_barcode LIKE %s
			)";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ) );

		$matching_products = array();
		if ( ! empty( $rows ) ) {
			foreach ( $rows as $row ) {
				$item = nearmart_format_catalog_item( $row );
				if ( ! $item || 'active' !== $item['status'] ) {
					continue;
				}

				// Check availability: exclude unavailable products per business rule
				$is_available = ( 'instock' === $item['stock_status'] && ( null === $item['stock_quantity'] || $item['stock_quantity'] > 0 ) );
				if ( ! $is_available ) {
					continue;
				}

				$title       = $item['title'];
				$description = '';
				$cat_name    = $item['category'];

				if ( ! empty( $row->product_id ) ) {
					$title       = SOM_Master_Product::get_localized_title( $row->product_id, $lang );
					$description = SOM_Master_Product::get_localized_description( $row->product_id, $lang );
					$cat_terms   = wp_get_post_terms( $row->product_id, 'product_cat' );
					if ( ! is_wp_error( $cat_terms ) && ! empty( $cat_terms ) ) {
						$cat_name = SOM_Master_Product::get_localized_category_name( $cat_terms[0], $lang );
					}
				}

				$shop_info = $eligible_shops[ $row->shop_id ] ?? null;
				if ( ! $shop_info ) {
					continue;
				}

				$price           = (float) $item['price'];
				$sale_price      = null !== $item['sale_price'] && '' !== $item['sale_price'] ? (float) $item['sale_price'] : null;
				$effective_price = ( null !== $sale_price && $sale_price < $price ) ? $sale_price : $price;

				// Produce / Weighed at shop detection
				$is_store_priced = ( $effective_price <= 0 || ! empty( $row->is_variable ) );
				$pricing_type    = $is_store_priced ? 'store_priced' : 'fixed';

				$matching_products[] = array(
					'id'              => (int) $item['id'],
					'shop_id'         => (int) $row->shop_id,
					'shop_name'       => $shop_info['shop_name'],
					'shop_address'    => $shop_info['shop_address'],
					'distance_km'     => $shop_info['distance_km'],
					'distance_text'   => $shop_info['distance_text'],
					'name'            => $title,
					'description'     => $description,
					'image'           => $item['thumb_url'] ? (string) $item['thumb_url'] : null,
					'category'        => $cat_name,
					'brand'           => $item['brand'] ? (string) $item['brand'] : null,
					'unit'            => $item['unit'] ? (string) $item['unit'] : null,
					'barcode'         => $item['barcode'] ? (string) $item['barcode'] : null,
					'price'           => $price,
					'sale_price'      => $sale_price,
					'available'       => true,
					'stock_quantity'  => null !== $item['stock_quantity'] ? (int) $item['stock_quantity'] : null,
					'shop_sku'        => $item['shop_sku'] ? (string) $item['shop_sku'] : null,
					'pricing_type'    => $pricing_type,
					'is_store_priced' => $is_store_priced,
				);
			}
		}

		// Sort by distance (nearest shops first) if coordinates available, then price
		usort(
			$matching_products,
			function( $a, $b ) {
				if ( null !== $a['distance_km'] && null !== $b['distance_km'] ) {
					if ( $a['distance_km'] !== $b['distance_km'] ) {
						return $a['distance_km'] <=> $b['distance_km'];
					}
				}
				return $a['price'] <=> $b['price'];
			}
		);

		// Server-side Pagination
		$total_count = count( $matching_products );
		$total_pages = max( 1, (int) ceil( $total_count / $limit ) );
		$offset      = ( $page - 1 ) * $limit;
		$paged       = array_slice( $matching_products, $offset, $limit );

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
					'query'      => $q,
				),
			),
			200
		);
	}
	/**
	 * Permission callback: Ensure user is authenticated.
	 *
	 * @return bool|WP_Error
	 */
	public static function permissions_authenticated_customer() {
		if ( class_exists( 'SOM_Mobile_Auth' ) && method_exists( 'SOM_Mobile_Auth', 'permissions_authenticated' ) ) {
			return SOM_Mobile_Auth::permissions_authenticated();
		}
		if ( is_user_logged_in() && get_current_user_id() > 0 ) {
			return true;
		}
		return new WP_Error(
			'rest_not_logged_in',
			__( 'Authentication required to manage favorite stores.', 'nearmart' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Endpoint: GET /customer/favorites - List authenticated customer favorite shops.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function get_customer_favorites( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return self::format_error_response( 'unauthorized', __( 'Authentication required.', 'nearmart' ), 401 );
		}

		$fav_ids = get_user_meta( $user_id, 'nearmart_favorite_shops', true );
		if ( ! is_array( $fav_ids ) ) {
			$fav_ids = array();
		}
		$fav_ids = array_values( array_unique( array_filter( array_map( 'absint', $fav_ids ) ) ) );

		$lat  = $request->get_param( 'lat' );
		$lng  = $request->get_param( 'lng' );
		$lang = self::sanitize_lang_param( $request->get_param( 'lang' ) );

		$valid_shops = array();
		$valid_ids   = array();

		foreach ( $fav_ids as $shop_id ) {
			$shop_data = self::format_shop( $shop_id );
			if ( ! $shop_data ) {
				continue;
			}

			// Add distance if lat/lng are provided
			if ( null !== $lat && null !== $lng && null !== $shop_data['latitude'] && null !== $shop_data['longitude'] ) {
				if ( class_exists( 'SOM_Mobile_Shops' ) && method_exists( 'SOM_Mobile_Shops', 'calculate_haversine_distance' ) ) {
					$dist_km = SOM_Mobile_Shops::calculate_haversine_distance( (float) $lat, (float) $lng, (float) $shop_data['latitude'], (float) $shop_data['longitude'] );
					$shop_data['distance_km']   = $dist_km;
					$shop_data['distance_text'] = $dist_km < 1 ? round( $dist_km * 1000 ) . ' m' : round( $dist_km, 1 ) . ' km';
				}
			}

			$shop_data['is_favorite'] = true;
			$valid_ids[]              = (int) $shop_id;
			$valid_shops[]            = $shop_data;
		}

		// Sort by distance if calculated
		if ( null !== $lat && null !== $lng ) {
			usort(
				$valid_shops,
				function( $a, $b ) {
					if ( isset( $a['distance_km'], $b['distance_km'] ) ) {
						return $a['distance_km'] <=> $b['distance_km'];
					}
					return 0;
				}
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'shop_ids' => $valid_ids,
					'shops'    => $valid_shops,
				),
			),
			200
		);
	}

	/**
	 * Endpoint: POST /customer/favorites/{shop_id} - Add shop to customer favorites.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function add_customer_favorite( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return self::format_error_response( 'unauthorized', __( 'Authentication required.', 'nearmart' ), 401 );
		}

		$shop_id = absint( $request->get_param( 'shop_id' ) );
		$shop    = self::format_shop( $shop_id );

		if ( ! $shop ) {
			return self::format_error_response( 'invalid_shop_id', __( 'Shop not found or not active.', 'nearmart' ), 404 );
		}

		$fav_ids = get_user_meta( $user_id, 'nearmart_favorite_shops', true );
		if ( ! is_array( $fav_ids ) ) {
			$fav_ids = array();
		}

		if ( ! in_array( $shop_id, $fav_ids, true ) ) {
			$fav_ids[] = $shop_id;
			$fav_ids   = array_values( array_unique( array_map( 'absint', $fav_ids ) ) );
			update_user_meta( $user_id, 'nearmart_favorite_shops', $fav_ids );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Shop added to favorites.', 'nearmart' ),
				'data'    => array(
					'favorited' => true,
					'shop_id'   => $shop_id,
					'shop_ids'  => $fav_ids,
				),
			),
			200
		);
	}

	/**
	 * Endpoint: DELETE /customer/favorites/{shop_id} - Remove shop from customer favorites.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function remove_customer_favorite( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return self::format_error_response( 'unauthorized', __( 'Authentication required.', 'nearmart' ), 401 );
		}

		$shop_id = absint( $request->get_param( 'shop_id' ) );
		$fav_ids = get_user_meta( $user_id, 'nearmart_favorite_shops', true );
		if ( ! is_array( $fav_ids ) ) {
			$fav_ids = array();
		}

		$fav_ids = array_values( array_diff( array_map( 'absint', $fav_ids ), array( $shop_id ) ) );
		update_user_meta( $user_id, 'nearmart_favorite_shops', $fav_ids );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Shop removed from favorites.', 'nearmart' ),
				'data'    => array(
					'favorited' => false,
					'shop_id'   => $shop_id,
					'shop_ids'  => $fav_ids,
				),
			),
			200
		);
	}
}
