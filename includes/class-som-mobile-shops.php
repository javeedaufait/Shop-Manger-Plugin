<?php
/**
 * NearMart Mobile Shops & Nearby Discovery REST API Controller.
 *
 * Dedicated controller for customer mobile app location discovery.
 * Does not modify legacy endpoints in class-som-rest-api.php.
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Mobile_Shops
 */
class SOM_Mobile_Shops {

	/**
	 * Namespace for NearMart mobile REST API.
	 */
	const NAMESPACE = 'nearmart/v1';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register mobile discovery routes.
	 */
	public static function register_routes() {
		// 1. GET /wp-json/nearmart/v1/shops/nearby
		register_rest_route(
			self::NAMESPACE,
			'/shops/nearby',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_nearby_shops' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'lat'    => array(
						'type'              => 'number',
						'required'          => false,
						'sanitize_callback' => array( __CLASS__, 'sanitize_float' ),
					),
					'lng'    => array(
						'type'              => 'number',
						'required'          => false,
						'sanitize_callback' => array( __CLASS__, 'sanitize_float' ),
					),
					'radius' => array(
						'type'              => 'number',
						'default'           => 30, // 30 km search radius
						'sanitize_callback' => array( __CLASS__, 'sanitize_float' ),
					),
					'area'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'search' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'   => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'limit'  => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// 2. GET /wp-json/nearmart/v1/areas
		register_rest_route(
			self::NAMESPACE,
			'/areas',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_areas' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Sanitize float parameters.
	 *
	 * @param mixed $value
	 * @return float|null
	 */
	public static function sanitize_float( $value ) {
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Calculate Haversine distance in kilometers.
	 *
	 * @param float $lat1 Latitude 1.
	 * @param float $lon1 Longitude 1.
	 * @param float $lat2 Latitude 2.
	 * @param float $lon2 Longitude 2.
	 * @return float Distance in km.
	 */
	public static function calculate_haversine_distance( $lat1, $lon1, $lat2, $lon2 ) {
		$earth_radius = 6371; // Radius in kilometers

		$d_lat = deg2rad( $lat2 - $lat1 );
		$d_lon = deg2rad( $lon2 - $lon1 );

		$a = sin( $d_lat / 2 ) * sin( $d_lat / 2 ) +
			cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) *
			sin( $d_lon / 2 ) * sin( $d_lon / 2 );

		$c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

		return round( $earth_radius * $c, 1 );
	}

	/**
	 * Endpoint: GET /wp-json/nearmart/v1/shops/nearby
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function get_nearby_shops( WP_REST_Request $request ) {
		$user_lat = $request->get_param( 'lat' );
		$user_lng = $request->get_param( 'lng' );
		$radius   = max( 1, min( 100, (float) ( $request->get_param( 'radius' ) ?: 30 ) ) );
		$area     = trim( (string) $request->get_param( 'area' ) );
		$search   = trim( (string) $request->get_param( 'search' ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$limit    = max( 1, min( 50, (int) $request->get_param( 'limit' ) ) );

		$has_coordinates = null !== $user_lat && null !== $user_lng && is_numeric( $user_lat ) && is_numeric( $user_lng );

		// Query published shops
		$query_args = array(
			'post_type'      => array( 'shop', 'shop_onboarding' ),
			'post_status'    => 'publish',
			'posts_per_page' => 150, // Retrieve reasonable pool for client-distance calculation
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( ! empty( $search ) ) {
			$query_args['s'] = $search;
		}

		$query = new WP_Query( $query_args );
		$all_shops = array();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$shop_id = $post->ID;
				$address = (string) get_post_meta( $shop_id, 'som_address', true );

				// Area text filter if specified and no GPS coordinates
				if ( ! empty( $area ) && ! $has_coordinates ) {
					$title_matches   = stripos( $post->post_title, $area ) !== false;
					$address_matches = stripos( $address, $area ) !== false;
					if ( ! $title_matches && ! $address_matches ) {
						continue;
					}
				}

				$lat_raw = get_post_meta( $shop_id, 'som_latitude', true );
				$lng_raw = get_post_meta( $shop_id, 'som_longitude', true );

				$shop_lat = '' !== $lat_raw && is_numeric( $lat_raw ) ? (float) $lat_raw : null;
				$shop_lng = '' !== $lng_raw && is_numeric( $lng_raw ) ? (float) $lng_raw : null;

				$distance_km   = null;
				$distance_text = null;

				if ( $has_coordinates && null !== $shop_lat && null !== $shop_lng ) {
					$distance_km = self::calculate_haversine_distance( $user_lat, $user_lng, $shop_lat, $shop_lng );

					// Exclude stores beyond requested search radius if GPS is active
					if ( $distance_km > $radius ) {
						continue;
					}

					$distance_text = $distance_km < 1
						? round( $distance_km * 1000 ) . ' m'
						: $distance_km . ' km';
				}

				$all_shops[] = self::format_nearby_shop_dto( $post, $shop_lat, $shop_lng, $distance_km, $distance_text );
			}
		}

		// Sort by distance if GPS coordinates available
		if ( $has_coordinates ) {
			usort(
				$all_shops,
				function( $a, $b ) {
					if ( null === $a['distance_km'] && null === $b['distance_km'] ) {
						return 0;
					}
					if ( null === $a['distance_km'] ) {
						return 1;
					}
					if ( null === $b['distance_km'] ) {
						return -1;
					}
					return $a['distance_km'] <=> $b['distance_km'];
				}
			);
		}

		// Paginate results
		$total_count = count( $all_shops );
		$total_pages = max( 1, (int) ceil( $total_count / $limit ) );
		$offset      = ( $page - 1 ) * $limit;
		$paged_shops = array_slice( $all_shops, $offset, $limit );

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'shops'      => $paged_shops,
					'user_location' => $has_coordinates ? array(
						'latitude'  => (float) $user_lat,
						'longitude' => (float) $user_lng,
						'radius_km' => $radius,
					) : null,
					'filter_area' => ! empty( $area ) ? $area : null,
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
	 * Endpoint: GET /wp-json/nearmart/v1/areas
	 * Returns list of popular / registered localities for manual area selection.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function get_areas( WP_REST_Request $request ) {
		// Collect areas from registered shops
		$shops = get_posts(
			array(
				'post_type'      => array( 'shop', 'shop_onboarding' ),
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
			)
		);

		$detected_areas = array();
		foreach ( $shops as $shop_id ) {
			$address = (string) get_post_meta( $shop_id, 'som_address', true );
			if ( ! empty( $address ) ) {
				$parts = array_map( 'trim', explode( ',', $address ) );
				foreach ( $parts as $part ) {
					if ( strlen( $part ) >= 3 && ! is_numeric( $part ) && ! in_array( strtolower( $part ), array( 'kerala', 'india', 'road', 'street', 'near' ), true ) ) {
						$detected_areas[ $part ] = true;
					}
				}
			}
		}

		// Curated Kerala neighborhood hubs
		$curated = array(
			'Edappally, Kochi',
			'Palarivattom, Kochi',
			'Kaloor, Kochi',
			'Kakkanad, Kochi',
			'Fort Kochi',
			'Aluva, Ernakulam',
			'Kozhikode City',
			'Mananchira, Kozhikode',
			'Thrissur Round',
			'Trivandrum City',
			'Kottayam',
			'Malappuram',
			'Kannur',
		);

		$area_list = array();
		foreach ( $curated as $hub ) {
			$area_list[] = array(
				'id'    => sanitize_title( $hub ),
				'name'  => $hub,
				'is_hub' => true,
			);
		}

		foreach ( array_keys( $detected_areas ) as $detected ) {
			$slug = sanitize_title( $detected );
			// Prevent duplicates
			$exists = false;
			foreach ( $area_list as $existing ) {
				if ( $existing['id'] === $slug ) {
					$exists = true;
					break;
				}
			}
			if ( ! $exists ) {
				$area_list[] = array(
					'id'    => $slug,
					'name'  => $detected,
					'is_hub' => false,
				);
			}
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'areas' => $area_list,
				),
			),
			200
		);
	}

	/**
	 * Format clean shop DTO for mobile app discovery.
	 * Strictly omits WordPress/WooCommerce internals.
	 *
	 * @param WP_Post    $post Post object.
	 * @param float|null $lat Latitude.
	 * @param float|null $lng Longitude.
	 * @param float|null $distance_km Distance in km.
	 * @param string|null $distance_text Distance text (e.g. "1.2 km").
	 * @return array
	 */
	private static function format_nearby_shop_dto( $post, $lat, $lng, $distance_km, $distance_text ) {
		$shop_id = $post->ID;

		$photo_id  = get_post_meta( $shop_id, 'som_shop_photo_id', true );
		$photo_url = $photo_id ? wp_get_attachment_url( $photo_id ) : '';
		if ( ! $photo_url && has_post_thumbnail( $shop_id ) ) {
			$photo_url = get_the_post_thumbnail_url( $shop_id, 'medium' );
		}

		$is_verified = (bool) get_post_meta( $shop_id, 'som_verified', true );
		$phone       = (string) get_post_meta( $shop_id, 'som_phone_number', true );
		$shop_type   = (string) get_post_meta( $shop_id, 'som_shop_type', true );

		return array(
			'shop_id'       => (int) $shop_id,
			'name'          => get_the_title( $shop_id ),
			'shop_type'     => ! empty( $shop_type ) ? $shop_type : 'Grocery & Supermarket',
			'photo_url'     => $photo_url ? (string) $photo_url : null,
			'address'       => (string) get_post_meta( $shop_id, 'som_address', true ),
			'phone'         => ! empty( $phone ) ? $phone : null,
			'latitude'      => $lat,
			'longitude'     => $lng,
			'distance_km'   => $distance_km,
			'distance_text' => $distance_text,
			'status'        => $is_verified ? 'verified' : 'active',
			'is_open'       => true,
			'availability'  => array(
				'status'       => 'open',
				'badge'        => 'Open Now',
				'timing'       => '8:00 AM - 10:00 PM',
				'pickup_ready' => true,
			),
		);
	}
}