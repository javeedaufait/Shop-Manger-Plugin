<?php
/**
 * NearMart Customer Shopping Cart REST API Controller.
 *
 * Handles cart management for logged-in customers and guests with single-shop MVP enforcement.
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Mobile_Cart
 */
class SOM_Mobile_Cart {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'nearmart/v1';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register Cart REST routes.
	 */
	public static function register_routes() {
		// 1. GET /wp-json/nearmart/v1/cart
		register_rest_route(
			self::NAMESPACE,
			'/cart',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_cart' ),
				'permission_callback' => '__return_true',
			)
		);

		// 2. POST /wp-json/nearmart/v1/cart/items
		register_rest_route(
			self::NAMESPACE,
			'/cart/items',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'add_item' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'shop_id'      => array(
						'type'     => 'integer',
						'required' => true,
					),
					'product_id'   => array(
						'type'     => 'integer',
						'required' => true,
					),
					'quantity'     => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'replace_cart' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		// 3. PUT /wp-json/nearmart/v1/cart/items/{item_id}
		register_rest_route(
			self::NAMESPACE,
			'/cart/items/(?P<item_id>[a-zA-Z0-9_\-]+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_item' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'quantity' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		// 4. DELETE /wp-json/nearmart/v1/cart/items/{item_id}
		register_rest_route(
			self::NAMESPACE,
			'/cart/items/(?P<item_id>[a-zA-Z0-9_\-]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'remove_item' ),
				'permission_callback' => '__return_true',
			)
		);

		// 5. DELETE /wp-json/nearmart/v1/cart
		register_rest_route(
			self::NAMESPACE,
			'/cart',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'clear_cart' ),
				'permission_callback' => '__return_true',
			)
		);

		// 6. POST /wp-json/nearmart/v1/cart/merge
		register_rest_route(
			self::NAMESPACE,
			'/cart/merge',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'merge_cart' ),
				'permission_callback' => function () {
					return get_current_user_id() > 0;
				},
				'args'                => array(
					'guest_cart_token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Resolve cart owner identifier (user_{id} or guest_{uuid}).
	 *
	 * @param WP_REST_Request $request
	 * @return array{type: string, id: string, token: string}
	 */
	private static function get_cart_identifier( WP_REST_Request $request ) {
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			return array(
				'type'  => 'user',
				'id'    => (string) $user_id,
				'token' => '',
			);
		}

		// Check session header or parameter
		$token = $request->get_header( 'x-cart-session' );
		if ( empty( $token ) ) {
			$token = $request->get_param( 'cart_session' );
		}

		if ( empty( $token ) || ! is_string( $token ) ) {
			$token = wp_generate_uuid4();
		}

		$token = sanitize_text_field( $token );

		return array(
			'type'  => 'guest',
			'id'    => $token,
			'token' => $token,
		);
	}

	/**
	 * Load cart data from storage.
	 *
	 * @param array $identifier
	 * @return array
	 */
	private static function load_cart_data( $identifier ) {
		$default = array(
			'shop_id'        => null,
			'shop_name'      => null,
			'items'          => array(),
			'item_count'     => 0,
			'total_quantity' => 0,
			'subtotal'       => 0.0,
			'session_token'  => $identifier['token'],
		);

		if ( 'user' === $identifier['type'] ) {
			$saved = get_user_meta( (int) $identifier['id'], 'nearmart_customer_cart', true );
		} else {
			$saved = get_transient( 'nearmart_cart_' . $identifier['id'] );
		}

		if ( ! is_array( $saved ) ) {
			return $default;
		}

		return wp_parse_args( $saved, $default );
	}

	/**
	 * Recalculate totals and persist cart.
	 *
	 * @param array $identifier
	 * @param array $cart
	 * @return array
	 */
	private static function save_cart_data( $identifier, array $cart ) {
		$items          = isset( $cart['items'] ) && is_array( $cart['items'] ) ? array_values( $cart['items'] ) : array();
		$total_quantity = 0;
		$subtotal       = 0.0;

		foreach ( $items as &$item ) {
			$qty              = max( 1, (int) ( $item['quantity'] ?? 1 ) );
			$price            = (float) ( $item['price'] ?? 0.0 );
			$item['quantity'] = $qty;
			$item['price']    = round( $price, 2 );
			$item['item_total'] = round( $qty * $price, 2 );

			$total_quantity += $qty;
			$subtotal       += $item['item_total'];
		}
		unset( $item );

		$cart['items']          = $items;
		$cart['item_count']     = count( $items );
		$cart['total_quantity'] = $total_quantity;
		$cart['subtotal']       = round( $subtotal, 2 );
		$cart['session_token']  = $identifier['token'];

		if ( 0 === $cart['item_count'] ) {
			$cart['shop_id']   = null;
			$cart['shop_name'] = null;
		}

		if ( 'user' === $identifier['type'] ) {
			update_user_meta( (int) $identifier['id'], 'nearmart_customer_cart', $cart );
		} else {
			set_transient( 'nearmart_cart_' . $identifier['id'], $cart, 30 * DAY_IN_SECONDS );
		}

		return $cart;
	}

	/**
	 * Wrap response and attach session header.
	 *
	 * @param array $cart
	 * @param array $identifier
	 * @param int   $status
	 * @return WP_REST_Response
	 */
	private static function cart_response( array $cart, array $identifier, $status = 200 ) {
		$response = new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'cart' => $cart,
				),
			),
			$status
		);

		if ( ! empty( $identifier['token'] ) ) {
			$response->header( 'X-Cart-Session', $identifier['token'] );
		}

		return $response;
	}

	/**
	 * Endpoint: GET /cart
	 */
	public static function get_cart( WP_REST_Request $request ) {
		$ident = self::get_cart_identifier( $request );
		$cart  = self::load_cart_data( $ident );

		return self::cart_response( $cart, $ident );
	}

	/**
	 * Endpoint: POST /cart/items
	 */
	public static function add_item( WP_REST_Request $request ) {
		$ident        = self::get_cart_identifier( $request );
		$cart         = self::load_cart_data( $ident );
		$shop_id      = (int) $request->get_param( 'shop_id' );
		$product_id   = (int) $request->get_param( 'product_id' );
		$quantity     = max( 1, (int) $request->get_param( 'quantity' ) );
		$replace_cart = (bool) $request->get_param( 'replace_cart' );

		// Resolve Shop Name
		$shop_name = sanitize_text_field( (string) $request->get_param( 'shop_name' ) );
		if ( empty( $shop_name ) ) {
			$shop_post = get_post( $shop_id );
			$shop_name = $shop_post ? $shop_post->post_title : sprintf( 'Shop #%d', $shop_id );
		}

		// Single-Shop MVP Conflict Check
		if ( ! empty( $cart['shop_id'] ) && count( $cart['items'] ) > 0 && (int) $cart['shop_id'] !== $shop_id ) {
			if ( ! $replace_cart ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'code'    => 'DIFFERENT_SHOP_CONFLICT',
						'message' => __( 'Your cart contains products from another store. Starting a cart with this store will replace your existing cart.', 'nearmart' ),
						'data'    => array(
							'current_shop_id'   => (int) $cart['shop_id'],
							'current_shop_name' => (string) $cart['shop_name'],
							'new_shop_id'       => $shop_id,
							'new_shop_name'     => $shop_name,
						),
					),
					409
				);
			}

			// Clear previous shop cart
			$cart['items']     = array();
			$cart['shop_id']   = $shop_id;
			$cart['shop_name'] = $shop_name;
		}

		if ( empty( $cart['shop_id'] ) ) {
			$cart['shop_id']   = $shop_id;
			$cart['shop_name'] = $shop_name;
		}

		// Check if product already exists in cart
		$item_id   = 'item_' . $product_id;
		$existing  = false;

		foreach ( $cart['items'] as &$item ) {
			if ( $item['product_id'] === $product_id ) {
				$item['quantity'] += $quantity;
				$existing = true;
				break;
			}
		}
		unset( $item );

		if ( ! $existing ) {
			// Extract product details passed or fall back
			$name  = sanitize_text_field( (string) $request->get_param( 'name' ) );
			$image = esc_url_raw( (string) $request->get_param( 'image' ) );
			$unit  = sanitize_text_field( (string) $request->get_param( 'unit' ) );
			$price = (float) $request->get_param( 'price' );

			if ( empty( $name ) ) {
				$name = sprintf( 'Product #%d', $product_id );
			}

			$cart['items'][] = array(
				'item_id'    => $item_id,
				'product_id' => $product_id,
				'shop_id'    => $shop_id,
				'name'       => $name,
				'image'      => ! empty( $image ) ? $image : null,
				'unit'       => ! empty( $unit ) ? $unit : null,
				'price'      => round( $price, 2 ),
				'quantity'   => $quantity,
				'item_total' => round( $price * $quantity, 2 ),
			);
		}

		$cart = self::save_cart_data( $ident, $cart );

		return self::cart_response( $cart, $ident, 201 );
	}

	/**
	 * Endpoint: PUT /cart/items/{item_id}
	 */
	public static function update_item( WP_REST_Request $request ) {
		$ident    = self::get_cart_identifier( $request );
		$cart     = self::load_cart_data( $ident );
		$item_id  = sanitize_text_field( $request->get_param( 'item_id' ) );
		$quantity = (int) $request->get_param( 'quantity' );

		$found = false;
		$new_items = array();

		foreach ( $cart['items'] as $item ) {
			if ( $item['item_id'] === $item_id ) {
				$found = true;
				if ( $quantity > 0 ) {
					$item['quantity'] = $quantity;
					$new_items[] = $item;
				}
				// If quantity <= 0, item is dropped (removed)
			} else {
				$new_items[] = $item;
			}
		}

		if ( ! $found ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'ITEM_NOT_FOUND',
					'message' => __( 'Item not found in cart.', 'nearmart' ),
				),
				404
			);
		}

		$cart['items'] = $new_items;
		$cart = self::save_cart_data( $ident, $cart );

		return self::cart_response( $cart, $ident );
	}

	/**
	 * Endpoint: DELETE /cart/items/{item_id}
	 */
	public static function remove_item( WP_REST_Request $request ) {
		$ident   = self::get_cart_identifier( $request );
		$cart    = self::load_cart_data( $ident );
		$item_id = sanitize_text_field( $request->get_param( 'item_id' ) );

		$new_items = array();
		$removed   = false;

		foreach ( $cart['items'] as $item ) {
			if ( $item['item_id'] === $item_id ) {
				$removed = true;
			} else {
				$new_items[] = $item;
			}
		}

		if ( ! $removed ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'ITEM_NOT_FOUND',
					'message' => __( 'Item not found in cart.', 'nearmart' ),
				),
				404
			);
		}

		$cart['items'] = $new_items;
		$cart = self::save_cart_data( $ident, $cart );

		return self::cart_response( $cart, $ident );
	}

	/**
	 * Endpoint: DELETE /cart
	 */
	public static function clear_cart( WP_REST_Request $request ) {
		$ident = self::get_cart_identifier( $request );
		$cart  = array(
			'shop_id'        => null,
			'shop_name'      => null,
			'items'          => array(),
			'item_count'     => 0,
			'total_quantity' => 0,
			'subtotal'       => 0.0,
			'session_token'  => $ident['token'],
		);

		$cart = self::save_cart_data( $ident, $cart );

		return self::cart_response( $cart, $ident );
	}

	/**
	 * Endpoint: POST /cart/merge
	 * Merges a guest cart into the logged-in customer's cart upon login.
	 */
	public static function merge_cart( WP_REST_Request $request ) {
		$user_id     = get_current_user_id();
		$guest_token = sanitize_text_field( (string) $request->get_param( 'guest_cart_token' ) );

		$user_ident  = array( 'type' => 'user', 'id' => (string) $user_id, 'token' => '' );
		$guest_ident = array( 'type' => 'guest', 'id' => $guest_token, 'token' => $guest_token );

		$guest_cart = self::load_cart_data( $guest_ident );
		$user_cart  = self::load_cart_data( $user_ident );

		// If guest cart is empty, simply return user cart
		if ( empty( $guest_cart['items'] ) ) {
			return self::cart_response( $user_cart, $user_ident );
		}

		// If user cart is empty, adopt guest cart completely
		if ( empty( $user_cart['items'] ) ) {
			$merged = self::save_cart_data( $user_ident, $guest_cart );
			delete_transient( 'nearmart_cart_' . $guest_token );
			return self::cart_response( $merged, $user_ident );
		}

		// If both have items from the same shop, merge items
		if ( (int) $user_cart['shop_id'] === (int) $guest_cart['shop_id'] ) {
			foreach ( $guest_cart['items'] as $g_item ) {
				$found = false;
				foreach ( $user_cart['items'] as &$u_item ) {
					if ( $u_item['product_id'] === $g_item['product_id'] ) {
						$u_item['quantity'] += $g_item['quantity'];
						$found = true;
						break;
					}
				}
				unset( $u_item );

				if ( ! $found ) {
					$user_cart['items'][] = $g_item;
				}
			}

			$merged = self::save_cart_data( $user_ident, $user_cart );
			delete_transient( 'nearmart_cart_' . $guest_token );
			return self::cart_response( $merged, $user_ident );
		}

		// Different shop conflict: Keep user cart by default, return note
		return self::cart_response( $user_cart, $user_ident );
	}
}
