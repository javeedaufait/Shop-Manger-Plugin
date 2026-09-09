<?php
/**
 * NearMart WooCommerce Order Engine & Customer REST API Controller.
 *
 * Implements native WooCommerce WC_Order persistence (HPOS compatible)
 * with NearMart hybrid catalog mapping, dual-layer quantity model,
 * multi-shop merchant isolation, and three-tier status lifecycle.
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Mobile_Orders
 */
class SOM_Mobile_Orders {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'nearmart/v1';

	/**
	 * Legacy post type for backwards compatibility.
	 */
	const LEGACY_POST_TYPE = 'nearmart_order';

	/**
	 * Valid NearMart fulfillment statuses in lifecycle order.
	 */
	const FULFILLMENT_STATUSES = array(
		'pending',
		'accepted',
		'preparing',
		'ready_for_pickup',
		'completed',
		'cancelled',
		'rejected',
	);

	/**
	 * Valid NearMart pricing statuses.
	 */
	const PRICING_STATUSES = array(
		'fixed',
		'pending_verification',
		'finalized',
	);

	/**
	 * Valid NearMart payment statuses.
	 */
	const PAYMENT_STATUSES = array(
		'unpaid',
		'payment_pending',
		'paid',
		'failed',
		'refunded',
	);

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Register legacy post type for backward compatibility
		add_action( 'init', array( __CLASS__, 'register_legacy_post_type' ), 10 );

		// Register NearMart REST API routes
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// Hook 1: Enable decimal quantities in WooCommerce stock calculations
		add_filter( 'woocommerce_stock_amount', 'floatval' );

		// Hook 2: Disable WooCommerce core stock reduction on NearMart orders
		// (NearMart directly manages shop-specific stock in wp_nearmart_shop_products)
		add_filter( 'woocommerce_can_reduce_order_stock', array( __CLASS__, 'filter_can_reduce_order_stock' ), 10, 2 );

		// Hook 3: Suppress premature PDF invoice generation while pricing is pending
		add_filter( 'wpo_wcpdf_is_document_allowed', array( __CLASS__, 'filter_prevent_provisional_invoice' ), 10, 2 );

		// Hook 4: Suppress premature customer processing/completed emails when price is pending
		add_filter( 'woocommerce_email_enabled_customer_processing_order', array( __CLASS__, 'filter_suppress_provisional_emails' ), 10, 2 );
		add_filter( 'woocommerce_email_enabled_customer_completed_order', array( __CLASS__, 'filter_suppress_provisional_emails' ), 10, 2 );
		// Hook 5: WooCommerce Admin Orders Table Custom Column (HPOS & Legacy CPT)
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_admin_order_columns' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_admin_order_column_hpos' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_admin_order_columns' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_admin_order_column_cpt' ), 10, 2 );
	}

	/**
	 * Register Legacy Custom Post Type for backwards compatibility with test orders.
	 */
	public static function register_legacy_post_type() {
		register_post_type(
			self::LEGACY_POST_TYPE,
			array(
				'labels'             => array(
					'name'          => _x( 'Legacy Orders', 'post type general name', 'nearmart' ),
					'singular_name' => _x( 'Legacy Order', 'post type singular name', 'nearmart' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'query_var'          => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'has_archive'        => false,
				'hierarchical'       => false,
				'supports'           => array( 'title', 'custom-fields' ),
			)
		);
	}

	/**
	 * Filter: Prevent WooCommerce core from reducing global master product stock.
	 *
	 * @param bool     $can_reduce
	 * @param WC_Order $order
	 * @return bool
	 */
	public static function filter_can_reduce_order_stock( $can_reduce, $order ) {
		if ( $order instanceof WC_Order && $order->get_meta( '_nearmart_shop_id' ) ) {
			return false;
		}
		return $can_reduce;
	}

	/**
	 * Filter: Prevent PDF invoice generation if order pricing is pending verification.
	 *
	 * @param bool  $allowed
	 * @param mixed $document
	 * @return bool
	 */
	public static function filter_prevent_provisional_invoice( $allowed, $document ) {
		if ( $document && isset( $document->order ) ) {
			$order = $document->order;
			if ( $order instanceof WC_Order && 'pending_verification' === $order->get_meta( '_nearmart_pricing_status' ) ) {
				return false;
			}
		}
		return $allowed;
	}

	/**
	 * Filter: Suppress premature customer emails when order pricing is not yet finalized.
	 *
	 * @param bool     $enabled
	 * @param WC_Order $order
	 * @return bool
	 */
	public static function filter_suppress_provisional_emails( $enabled, $order ) {
		if ( $order instanceof WC_Order && $order->get_meta( '_nearmart_shop_id' ) ) {
			if ( 'pending_verification' === $order->get_meta( '_nearmart_pricing_status' ) ) {
				return false;
			}
		}
		return $enabled;
	}

	/**
	 * Register REST API routes.
	 */
	public static function register_routes() {
		// 1. POST /wp-json/nearmart/v1/orders - Place Order
		register_rest_route(
			self::NAMESPACE,
			'/orders',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_order' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'shop_id'        => array(
						'type'     => 'integer',
						'required' => true,
					),
					'items'          => array(
						'type'     => 'array',
						'required' => true,
					),
					'customer_name'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'customer_phone' => array(
						'type'     => 'string',
						'required' => true,
					),
					'customer_note'  => array(
						'type'     => 'string',
						'required' => false,
						'default'  => '',
					),
				),
			)
		);

		// 2. GET /wp-json/nearmart/v1/orders - List Customer Orders
		register_rest_route(
			self::NAMESPACE,
			'/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_orders' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'phone'  => array(
						'type'     => 'string',
						'required' => false,
					),
					'status' => array(
						'type'     => 'string',
						'required' => false,
					),
					'limit'  => array(
						'type'    => 'integer',
						'default' => 20,
					),
					'page'   => array(
						'type'    => 'integer',
						'default' => 1,
					),
				),
			)
		);

		// 3. GET /wp-json/nearmart/v1/orders/{order_id} - Single Order Details
		register_rest_route(
			self::NAMESPACE,
			'/orders/(?P<order_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_order_by_id' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'order_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		// 4. POST /wp-json/nearmart/v1/orders/{order_id}/status - Customer Cancel / Status Simulation
		register_rest_route(
			self::NAMESPACE,
			'/orders/(?P<order_id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'update_order_status' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'status' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		// 5. GET /wp-json/nearmart/v1/merchant/orders - List Merchant Orders (Multi-Shop Scoped)
		register_rest_route(
			self::NAMESPACE,
			'/merchant/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_merchant_orders' ),
				'permission_callback' => array( __CLASS__, 'merchant_permission_check' ),
				'args'                => array(
					'status' => array(
						'type'     => 'string',
						'required' => false,
					),
					'limit'  => array(
						'type'    => 'integer',
						'default' => 20,
					),
					'page'   => array(
						'type'    => 'integer',
						'default' => 1,
					),
				),
			)
		);

		// 6. GET /wp-json/nearmart/v1/merchant/orders/{order_id} - Single Merchant Order
		register_rest_route(
			self::NAMESPACE,
			'/merchant/orders/(?P<order_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_merchant_order_by_id' ),
				'permission_callback' => array( __CLASS__, 'merchant_permission_check' ),
				'args'                => array(
					'order_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		// 7. POST /wp-json/nearmart/v1/merchant/orders/{order_id}/weigh - Merchant Finalize Weighed Items
		register_rest_route(
			self::NAMESPACE,
			'/merchant/orders/(?P<order_id>\d+)/weigh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'weigh_merchant_order_items' ),
				'permission_callback' => array( __CLASS__, 'merchant_permission_check' ),
				'args'                => array(
					'order_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'items'    => array(
						'type'     => 'array',
						'required' => true,
					),
				),
			)
		);

		// 8. POST /wp-json/nearmart/v1/merchant/orders/{order_id}/status - Merchant Fulfillment Status Update
		register_rest_route(
			self::NAMESPACE,
			'/merchant/orders/(?P<order_id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'update_merchant_order_status' ),
				'permission_callback' => array( __CLASS__, 'merchant_permission_check' ),
				'args'                => array(
					'order_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'status'   => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Permission check: Verify user is merchant or admin.
	 *
	 * @return bool
	 */
	public static function merchant_permission_check() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		if ( current_user_can( 'administrator' ) ) {
			return true;
		}

		if ( class_exists( 'SOM_Catalog_Permissions' ) ) {
			$shop_id = SOM_Catalog_Permissions::get_current_merchant_shop_id( $user_id );
			return $shop_id > 0;
		}

		return false;
	}

	/**
	 * Resolve customer identifier and cart token.
	 *
	 * @param WP_REST_Request $request
	 * @return array
	 */
	private static function get_request_context( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$header_uid = $request->get_header( 'x-user-id' );
			if ( $header_uid ) {
				$user_id = absint( $header_uid );
			} elseif ( $request->get_param( 'customer_id' ) ) {
				$user_id = absint( $request->get_param( 'customer_id' ) );
			}
		}

		$cart_session = $request->get_header( 'x-cart-session' );
		if ( empty( $cart_session ) ) {
			$cart_session = $request->get_param( 'cart_session' );
		}

		return array(
			'user_id'      => $user_id,
			'cart_session' => sanitize_text_field( (string) $cart_session ),
		);
	}

	/**
	 * Endpoint: POST /orders - Place Order
	 *
	 * Replaces legacy custom post type creation with native WooCommerce WC_Order creation.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function create_order( WP_REST_Request $request ) {
		$context        = self::get_request_context( $request );
		$shop_id        = absint( $request->get_param( 'shop_id' ) );
		$raw_items      = $request->get_param( 'items' );
		$customer_name  = sanitize_text_field( (string) $request->get_param( 'customer_name' ) );
		$customer_phone = sanitize_text_field( (string) $request->get_param( 'customer_phone' ) );
		$customer_note  = sanitize_textarea_field( (string) $request->get_param( 'customer_note' ) );

		// 1. Validate Customer Info
		if ( mb_strlen( trim( $customer_name ) ) < 2 ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'INVALID_CUSTOMER_NAME',
					'message' => __( 'Please provide a valid customer name.', 'nearmart' ),
				),
				400
			);
		}

		$clean_phone = preg_replace( '/[^0-9+]/', '', $customer_phone );
		if ( mb_strlen( $clean_phone ) < 7 ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'INVALID_CUSTOMER_PHONE',
					'message' => __( 'Please provide a valid contact phone number.', 'nearmart' ),
				),
				400
			);
		}

		// 2. Validate Shop exists and is active
		$shop_post = get_post( $shop_id );
		if ( ! $shop_post || 'shop' !== $shop_post->post_type || 'publish' !== $shop_post->post_status ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'SHOP_NOT_FOUND',
					'message' => __( 'The selected shop could not be found or is inactive.', 'nearmart' ),
				),
				404
			);
		}

		$shop_status = get_post_meta( $shop_id, '_shop_status', true );
		if ( ! empty( $shop_status ) && 'active' !== $shop_status && 'approved' !== $shop_status ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'SHOP_INACTIVE',
					'message' => __( 'The selected shop is temporarily unavailable for orders.', 'nearmart' ),
				),
				400
			);
		}

		$shop_name    = $shop_post->post_title;
		$shop_address = (string) get_post_meta( $shop_id, '_shop_address', true );
		$shop_phone   = (string) get_post_meta( $shop_id, '_shop_phone', true );

		// 3. Validate Items
		if ( ! is_array( $raw_items ) || empty( $raw_items ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'EMPTY_ORDER_ITEMS',
					'message' => __( 'Cannot place an order with an empty cart.', 'nearmart' ),
				),
				400
			);
		}

		// 4. Server-Side Price & Product Validation (Zero Client Trust)
		$processed_items      = array();
		$total_quantity       = 0.0;
		$fixed_subtotal       = 0.0;
		$estimated_total      = 0.0;
		$has_store_priced     = false;

		foreach ( $raw_items as $raw_item ) {
			$product_id = absint( $raw_item['product_id'] ?? 0 );
			$quantity   = floatval( $raw_item['quantity'] ?? 1 );
			if ( $quantity <= 0 ) {
				$quantity = 1.0;
			}

			if ( ! $product_id ) {
				continue;
			}

			// Lookup product in shop catalog
			$catalog_row = null;
			if ( function_exists( 'nearmart_get_shop_product_by_id' ) ) {
				$catalog_row = nearmart_get_shop_product_by_id( $product_id );
			}
			if ( ! $catalog_row && function_exists( 'nearmart_get_shop_product' ) ) {
				$catalog_row = nearmart_get_shop_product( $shop_id, $product_id );
			}

			if ( ! $catalog_row ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'code'    => 'PRODUCT_NOT_FOUND',
						'message' => sprintf(
							/* translators: 1: product id, 2: shop name */
							__( 'Item #%1$d is no longer available from %2$s.', 'nearmart' ),
							$product_id,
							$shop_name
						),
					),
					400
				);
			}

			$formatted = function_exists( 'nearmart_format_catalog_item' )
				? nearmart_format_catalog_item( $catalog_row )
				: null;

			// Verify product is active and in stock
			if ( ! $formatted || 'active' !== $formatted['status'] ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'code'    => 'PRODUCT_INACTIVE',
						'message' => sprintf(
							/* translators: %s: product title */
							__( '"%s" is currently inactive and cannot be ordered.', 'nearmart' ),
							$formatted ? $formatted['title'] : 'Product'
						),
					),
					400
				);
			}

			if ( 'instock' !== $formatted['stock_status'] ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'code'    => 'PRODUCT_OUT_OF_STOCK',
						'message' => sprintf(
							/* translators: %s: product title */
							__( '"%s" is currently out of stock.', 'nearmart' ),
							$formatted['title']
						),
					),
					400
				);
			}

			// Validate stock quantity if tracked
			if ( null !== $formatted['stock_quantity'] && $formatted['stock_quantity'] > 0 && $quantity > $formatted['stock_quantity'] ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'code'    => 'EXCEEDS_STOCK_LIMIT',
						'message' => sprintf(
							/* translators: 1: product title, 2: available stock */
							__( 'Only %2$d units available for "%1$s".', 'nearmart' ),
							$formatted['title'],
							$formatted['stock_quantity']
						),
					),
					400
				);
			}

			// Determine Item Type: Master-Linked vs Standalone
			$master_wc_id = ( ! empty( $catalog_row->product_id ) && $catalog_row->product_id > 0 )
				? absint( $catalog_row->product_id )
				: 0;
			$item_type    = $master_wc_id > 0 ? 'master_linked' : 'standalone';

			// Determine Pricing Type: Fixed vs Store-Priced (Produce/Variable)
			$raw_price = ( null !== $formatted['sale_price'] && '' !== $formatted['sale_price'] )
				? $formatted['sale_price']
				: $formatted['price'];

			$is_variable = ! empty( $raw_item['is_variable'] ) || ! empty( $raw_item['pricing_type'] ) && 'store_priced' === $raw_item['pricing_type'];
			if ( null === $raw_price || (float) $raw_price <= 0 || $is_variable ) {
				$pricing_type   = 'store_priced';
				$pricing_status = 'pending';
				$has_store_priced = true;
				$unit_price     = 0.0;
				$line_total     = 0.0;

				// Reference estimate if available
				$ref_price = (float) $raw_price;
				if ( $ref_price > 0 ) {
					$estimated_total += round( $ref_price * $quantity, 2 );
				}
			} else {
				$pricing_type   = 'fixed';
				$pricing_status = 'fixed';
				$unit_price     = round( (float) $raw_price, 2 );
				$line_total     = round( $unit_price * $quantity, 2 );
				$fixed_subtotal += $line_total;
				$estimated_total += $line_total;
			}

			$processed_items[] = array(
				'catalog_id'     => $product_id,
				'master_wc_id'   => $master_wc_id,
				'name'           => $formatted['title'],
				'item_type'      => $item_type,
				'pricing_type'   => $pricing_type,
				'pricing_status' => $pricing_status,
				'unit'           => $formatted['unit'] ? (string) $formatted['unit'] : null,
				'image'          => $formatted['thumb_url'] ? (string) $formatted['thumb_url'] : null,
				'price'          => $unit_price,
				'quantity'       => $quantity,
				'line_total'     => $line_total,
				'shop_sku'       => $formatted['shop_sku'] ? (string) $formatted['shop_sku'] : null,
				'catalog_row'    => $catalog_row,
			);

			$total_quantity += $quantity;
		}

		if ( empty( $processed_items ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'NO_VALID_ITEMS',
					'message' => __( 'No valid items found to place order.', 'nearmart' ),
				),
				400
			);
		}

		$order_pricing_status = $has_store_priced ? 'pending_verification' : 'fixed';
		$fixed_subtotal       = round( $fixed_subtotal, 2 );
		$estimated_total      = round( $estimated_total, 2 );

		// 5. Create WooCommerce Order via WooCommerce CRUD API (HPOS Compliant)
		if ( ! function_exists( 'wc_create_order' ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'WOOCOMMERCE_NOT_FOUND',
					'message' => __( 'WooCommerce is not active.', 'nearmart' ),
				),
				500
			);
		}

		$wc_order = wc_create_order(
			array(
				'status'        => 'on-hold', // Native status for click-and-collect pending payment
				'customer_id'   => $context['user_id'] > 0 ? $context['user_id'] : 0,
				'customer_note' => $customer_note,
			)
		);

		if ( is_wp_error( $wc_order ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'ORDER_CREATION_FAILED',
					'message' => $wc_order->get_error_message(),
				),
				500
			);
		}

		$order_id     = $wc_order->get_id();
		$order_number = sprintf( 'NM-ORD-%d', 1000 + $order_id );
		$pickup_code  = sprintf( 'PU-%s-%d', strtoupper( substr( md5( (string) $order_id ), 0, 4 ) ), $order_id );

		// Customer Billing Info
		$wc_order->set_billing_first_name( $customer_name );
		$wc_order->set_billing_phone( $customer_phone );
		if ( $context['user_id'] > 0 ) {
			$wc_order->set_customer_id( $context['user_id'] );
		}
		$wc_order->set_payment_method( 'nearmart_pay_at_store' );
		$wc_order->set_payment_method_title( __( 'Pay at Store Counter', 'nearmart' ) );

		// Attach Line Items with Snapshots
		foreach ( $processed_items as $item_data ) {
			$item = new WC_Order_Item_Product();
			$item->set_name( $item_data['name'] );
			$item->set_product_id( $item_data['master_wc_id'] ); // WC Master ID or 0 for Standalone
			$item->set_quantity( $item_data['quantity'] );
			$item->set_subtotal( $item_data['line_total'] );
			$item->set_total( $item_data['line_total'] );

			// Immutable Historical Snapshots (No dynamic lookups on past orders)
			$item->add_meta_data( '_nearmart_catalog_id', $item_data['catalog_id'], true );
			$item->add_meta_data( '_nearmart_shop_id', $shop_id, true );
			$item->add_meta_data( '_item_type', $item_data['item_type'], true );
			$item->add_meta_data( '_pricing_type', $item_data['pricing_type'], true );
			$item->add_meta_data( '_pricing_status', $item_data['pricing_status'], true );
			$item->add_meta_data( '_requested_qty', $item_data['quantity'], true );
			$item->add_meta_data( '_requested_unit', $item_data['unit'], true );
			$item->add_meta_data( '_actual_qty', null, true );
			$item->add_meta_data( '_actual_unit', $item_data['unit'], true );
			$item->add_meta_data( '_unit_snapshot', $item_data['unit'], true );
			$item->add_meta_data( '_unit_price_snapshot', $item_data['price'], true );
			$item->add_meta_data( '_original_shop_price', $item_data['price'], true );
			$item->add_meta_data( '_image_snapshot', $item_data['image'], true );
			if ( ! empty( $item_data['shop_sku'] ) ) {
				$item->add_meta_data( '_shop_sku', $item_data['shop_sku'], true );
			}

			$wc_order->add_item( $item );
		}

		// Attach Order-Level NearMart Metadata (HPOS compatible)
		$wc_order->update_meta_data( '_nearmart_order_number', $order_number );
		$wc_order->update_meta_data( '_nearmart_pickup_code', $pickup_code );
		$wc_order->update_meta_data( '_nearmart_shop_id', $shop_id );
		$wc_order->update_meta_data( '_nearmart_shop_name', $shop_name );
		$wc_order->update_meta_data( '_nearmart_shop_address', $shop_address );
		$wc_order->update_meta_data( '_nearmart_shop_phone', $shop_phone );
		$wc_order->update_meta_data( '_nearmart_customer_id', $context['user_id'] );
		$wc_order->update_meta_data( '_nearmart_customer_name', $customer_name );
		$wc_order->update_meta_data( '_nearmart_customer_phone', $customer_phone );
		$wc_order->update_meta_data( '_nearmart_customer_note', $customer_note );
		$wc_order->update_meta_data( '_nearmart_fulfillment_status', 'pending' );
		$wc_order->update_meta_data( '_nearmart_pricing_status', $order_pricing_status );
		$wc_order->update_meta_data( '_nearmart_payment_status', 'unpaid' );
		$wc_order->update_meta_data( '_nearmart_fixed_subtotal', $fixed_subtotal );
		$wc_order->update_meta_data( '_nearmart_estimated_total', $estimated_total );
		$wc_order->update_meta_data( '_nearmart_is_total_provisional', $has_store_priced ? 'yes' : 'no' );
		$wc_order->update_meta_data( '_nearmart_session_token', $context['cart_session'] );
		$wc_order->update_meta_data( '_nearmart_pickup_type', 'pickup' );

		// Calculate Totals & Save Order
		$wc_order->calculate_totals();
		$wc_order->save();

		// Deduct Shop-Specific Inventory in wp_nearmart_shop_products
		foreach ( $processed_items as $item_data ) {
			if ( ! empty( $item_data['catalog_row']->stock_quantity ) && $item_data['catalog_row']->stock_quantity > 0 ) {
				$new_stock = max( 0, $item_data['catalog_row']->stock_quantity - intval( ceil( $item_data['quantity'] ) ) );
				if ( function_exists( 'nearmart_update_shop_product_by_id' ) ) {
					nearmart_update_shop_product_by_id(
						$item_data['catalog_id'],
						array(
							'stock_quantity' => $new_stock,
							'stock_status'   => $new_stock > 0 ? 'instock' : 'outofstock',
						)
					);
				}
			}
		}

		// 6. Clear Customer's Cart
		self::clear_customer_cart( $context );

		// 7. Format and Return Order
		$order_data = self::format_order( $wc_order );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Your order has been placed successfully for store pickup!', 'nearmart' ),
				'data'    => array(
					'order' => $order_data,
				),
			),
			201
		);
	}

	/**
	 * Endpoint: GET /orders - List Customer Orders
	 *
	 * Uses wc_get_orders() for HPOS compatibility with fallback to legacy orders.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function get_orders( WP_REST_Request $request ) {
		$context = self::get_request_context( $request );
		$phone   = sanitize_text_field( (string) $request->get_param( 'phone' ) );
		$status  = sanitize_text_field( (string) $request->get_param( 'status' ) );
		$limit   = min( 50, max( 1, absint( $request->get_param( 'limit' ) ) ) );
		$page    = max( 1, absint( $request->get_param( 'page' ) ) );

		$meta_query = array( 'relation' => 'AND' );

		if ( $context['user_id'] > 0 ) {
			$meta_query[] = array(
				'key'     => '_nearmart_customer_id',
				'value'   => $context['user_id'],
				'compare' => '=',
			);
		} elseif ( ! empty( $phone ) ) {
			$meta_query[] = array(
				'key'     => '_nearmart_customer_phone',
				'value'   => $phone,
				'compare' => 'LIKE',
			);
		} elseif ( ! empty( $context['cart_session'] ) ) {
			$meta_query[] = array(
				'key'     => '_nearmart_session_token',
				'value'   => $context['cart_session'],
				'compare' => '=',
			);
		}

		if ( ! empty( $status ) && in_array( $status, self::FULFILLMENT_STATUSES, true ) ) {
			$meta_query[] = array(
				'key'     => '_nearmart_fulfillment_status',
				'value'   => $status,
				'compare' => '=',
			);
		}

		$query_args = array(
			'limit'    => $limit,
			'page'     => $page,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'paginate' => true,
		);

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query;
		}

		$wc_results = function_exists( 'wc_get_orders' ) ? wc_get_orders( $query_args ) : null;
		$orders     = array();
		$total      = 0;
		$total_pages = 1;

		if ( $wc_results && isset( $wc_results->orders ) ) {
			foreach ( $wc_results->orders as $wc_order ) {
				$formatted = self::format_order( $wc_order );
				if ( $formatted ) {
					$orders[] = $formatted;
				}
			}
			$total       = (int) $wc_results->total;
			$total_pages = (int) $wc_results->max_num_pages;
		}

		// Fallback to legacy orders if none found in WooCommerce (continuity during migration)
		if ( empty( $orders ) && $page === 1 ) {
			$legacy_orders = self::get_legacy_orders( $context, $phone, $status, $limit );
			if ( ! empty( $legacy_orders ) ) {
				$orders = $legacy_orders;
				$total  = count( $legacy_orders );
			}
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'orders'     => $orders,
					'pagination' => array(
						'page'        => $page,
						'limit'       => $limit,
						'total'       => $total,
						'total_pages' => max( 1, $total_pages ),
					),
				),
			),
			200
		);
	}

	/**
	 * Endpoint: GET /orders/{order_id} - Single Order Details
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function get_order_by_id( WP_REST_Request $request ) {
		$order_id = absint( $request->get_param( 'order_id' ) );
		$context  = self::get_request_context( $request );
		$order    = self::format_order( $order_id );

		if ( ! $order ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'ORDER_NOT_FOUND',
					'message' => __( 'Order not found.', 'nearmart' ),
				),
				404
			);
		}

		// Customer privacy check: If user is logged in and not admin, ensure they own the order
		if ( $context['user_id'] > 0 && ! current_user_can( 'manage_options' ) ) {
			$order_cust_id = isset( $order['customer_id'] ) ? absint( $order['customer_id'] ) : 0;
			if ( $order_cust_id > 0 && $order_cust_id !== $context['user_id'] ) {
				$is_shop_merchant = false;
				if ( class_exists( 'SOM_Catalog_Permissions' ) ) {
					$merchant_shop_id = SOM_Catalog_Permissions::get_current_merchant_shop_id( $context['user_id'] );
					if ( $merchant_shop_id > 0 && $merchant_shop_id === (int) $order['shop_id'] ) {
						$is_shop_merchant = true;
					}
				}
				if ( ! $is_shop_merchant ) {
					return new WP_REST_Response(
						array(
							'success' => false,
							'code'    => 'FORBIDDEN',
							'message' => __( 'You do not have permission to view this order.', 'nearmart' ),
						),
						403
					);
				}
			}
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'order' => $order,
				),
			),
			200
		);
	}

	/**
	 * Endpoint: POST /orders/{order_id}/status - Customer Status / Simulation
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function update_order_status( WP_REST_Request $request ) {
		$order_id = absint( $request->get_param( 'order_id' ) );
		$status   = sanitize_text_field( (string) $request->get_param( 'status' ) );

		if ( ! in_array( $status, self::FULFILLMENT_STATUSES, true ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'INVALID_STATUS',
					'message' => sprintf(
						/* translators: %s: valid statuses */
						__( 'Invalid status. Must be one of: %s', 'nearmart' ),
						implode( ', ', self::FULFILLMENT_STATUSES )
					),
				),
				400
			);
		}

		$wc_order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $wc_order ) {
			// Check legacy post
			$post = get_post( $order_id );
			if ( $post && self::LEGACY_POST_TYPE === $post->post_type ) {
				update_post_meta( $order_id, '_nearmart_order_status', $status );
				update_post_meta( $order_id, '_nearmart_fulfillment_status', $status );
				return new WP_REST_Response(
					array(
						'success' => true,
						'message' => sprintf( 'Order status updated to %s.', $status ),
						'data'    => array(
							'order' => self::format_order( $order_id ),
						),
					),
					200
				);
			}

			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'ORDER_NOT_FOUND',
					'message' => __( 'Order not found.', 'nearmart' ),
				),
				404
			);
		}

		// Update NearMart fulfillment status
		$wc_order->update_meta_data( '_nearmart_fulfillment_status', $status );

		// Map to native WooCommerce status
		$wc_status = self::map_fulfillment_to_wc_status( $status, $wc_order->get_meta( '_nearmart_payment_status' ) );
		$wc_order->set_status( $wc_status );

		if ( 'completed' === $status ) {
			$wc_order->update_meta_data( '_nearmart_payment_status', 'paid' );
		}

		$wc_order->save();

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => sprintf( 'Order status updated to %s.', $status ),
				'data'    => array(
					'order' => self::format_order( $wc_order ),
				),
			),
			200
		);
	}

	/**
	 * Endpoint: GET /merchant/orders - List orders for authenticated merchant.
	 *
	 * Strictly scoped to the merchant's assigned shop.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function get_merchant_orders( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$shop_id   = class_exists( 'SOM_Catalog_Permissions' ) ? SOM_Catalog_Permissions::get_current_merchant_shop_id( $user_id ) : 0;
		$is_admin  = current_user_can( 'administrator' );

		$req_shop_id = absint( $request->get_param( 'shop_id' ) );
		if ( $is_admin && $req_shop_id > 0 ) {
			$shop_id = $req_shop_id;
		}

		if ( ! $shop_id && ! $is_admin ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'MERCHANT_SHOP_NOT_FOUND',
					'message' => __( 'No shop associated with this merchant account.', 'nearmart' ),
				),
				403
			);
		}

		$status             = sanitize_text_field( (string) ( $request->get_param( 'fulfillment_status' ) ?: $request->get_param( 'status' ) ) );
		$pricing_status     = sanitize_text_field( (string) $request->get_param( 'pricing_status' ) );
		$payment_status     = sanitize_text_field( (string) $request->get_param( 'payment_status' ) );
		$search             = sanitize_text_field( (string) ( $request->get_param( 'search' ) ?: $request->get_param( 'q' ) ) );
		$limit              = min( 100, max( 1, absint( $request->get_param( 'limit' ) ?: 20 ) ) );
		$page               = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );

		$meta_query = array( 'relation' => 'AND' );
		if ( $shop_id > 0 ) {
			$meta_query[] = array(
				'key'     => '_nearmart_shop_id',
				'value'   => $shop_id,
				'compare' => '=',
			);
		}

		if ( ! empty( $status ) && 'all' !== $status && in_array( $status, self::FULFILLMENT_STATUSES, true ) ) {
			$meta_query[] = array(
				'key'     => '_nearmart_fulfillment_status',
				'value'   => $status,
				'compare' => '=',
			);
		}

		if ( ! empty( $pricing_status ) && 'all' !== $pricing_status && in_array( $pricing_status, self::PRICING_STATUSES, true ) ) {
			$meta_query[] = array(
				'key'     => '_nearmart_pricing_status',
				'value'   => $pricing_status,
				'compare' => '=',
			);
		}

		if ( ! empty( $payment_status ) && 'all' !== $payment_status && in_array( $payment_status, self::PAYMENT_STATUSES, true ) ) {
			$meta_query[] = array(
				'key'     => '_nearmart_payment_status',
				'value'   => $payment_status,
				'compare' => '=',
			);
		}

		$query_args = array(
			'limit'      => $limit,
			'page'       => $page,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'paginate'   => true,
			'meta_query' => $meta_query,
		);

		// If search is numeric and matches an ID
		if ( ! empty( $search ) && is_numeric( $search ) ) {
			$direct_order = wc_get_order( absint( $search ) );
			if ( $direct_order && ( $is_admin || absint( $direct_order->get_meta( '_nearmart_shop_id' ) ) === $shop_id ) ) {
				$formatted = self::format_order( $direct_order );
				return new WP_REST_Response(
					array(
						'success' => true,
						'data'    => array(
							'orders'     => array( $formatted ),
							'pagination' => array(
								'page'        => 1,
								'limit'       => $limit,
								'total'       => 1,
								'total_pages' => 1,
							),
						),
					),
					200
				);
			}
		}

		$wc_results = wc_get_orders( $query_args );
		$orders     = array();

		if ( $wc_results && isset( $wc_results->orders ) ) {
			foreach ( $wc_results->orders as $wc_order ) {
				$formatted = self::format_order( $wc_order );
				if ( ! $formatted ) {
					continue;
				}

				// Apply search term filter on non-ID string searches
				if ( ! empty( $search ) ) {
					$s = mb_strtolower( trim( $search ) );
					$order_num  = mb_strtolower( $formatted['order_number'] ?? '' );
					$pickup     = mb_strtolower( $formatted['pickup_code'] ?? '' );
					$cust_name  = mb_strtolower( $formatted['customer_name'] ?? '' );
					$cust_phone = mb_strtolower( $formatted['customer_phone'] ?? '' );

					if ( false === mb_strpos( $order_num, $s ) &&
						 false === mb_strpos( $pickup, $s ) &&
						 false === mb_strpos( $cust_name, $s ) &&
						 false === mb_strpos( $cust_phone, $s ) ) {
						continue;
					}
				}

				$orders[] = $formatted;
			}
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'orders'     => $orders,
					'pagination' => array(
						'page'        => $page,
						'limit'       => $limit,
						'total'       => (int) ( $wc_results->total ?? count( $orders ) ),
						'total_pages' => max( 1, (int) ( $wc_results->max_num_pages ?? 1 ) ),
					),
				),
			),
			200
		);
	}

	/**
	 * Endpoint: GET /merchant/orders/{order_id} - Single merchant order with ownership verification.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function get_merchant_order_by_id( WP_REST_Request $request ) {
		$order_id = absint( $request->get_param( 'order_id' ) );
		$user_id  = get_current_user_id();
		$is_admin = current_user_can( 'administrator' );
		$shop_id  = class_exists( 'SOM_Catalog_Permissions' ) ? SOM_Catalog_Permissions::get_current_merchant_shop_id( $user_id ) : 0;

		$wc_order = wc_get_order( $order_id );
		if ( ! $wc_order ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'ORDER_NOT_FOUND',
					'message' => __( 'Order not found.', 'nearmart' ),
				),
				404
			);
		}

		$order_shop_id = absint( $wc_order->get_meta( '_nearmart_shop_id' ) );
		if ( ! $is_admin && $order_shop_id !== $shop_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'FORBIDDEN',
					'message' => __( 'You do not have permission to access orders from another shop.', 'nearmart' ),
				),
				403
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'order' => self::format_order( $wc_order ),
				),
			),
			200
		);
	}

	/**
	 * Endpoint: POST /merchant/orders/{order_id}/weigh - Finalize Weighed Items
	 *
	 * Allows merchant to input actual weights (e.g. 2.1 kg) and rates for store-priced items.
	 * Updates WooCommerce line items, recalculates order total, transitions pricing status to finalized.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function weigh_merchant_order_items( WP_REST_Request $request ) {
		$order_id  = absint( $request->get_param( 'order_id' ) );
		$weighed   = $request->get_param( 'items' );
		$user_id   = get_current_user_id();
		$is_admin  = current_user_can( 'administrator' );
		$shop_id   = class_exists( 'SOM_Catalog_Permissions' ) ? SOM_Catalog_Permissions::get_current_merchant_shop_id( $user_id ) : 0;

		$wc_order = wc_get_order( $order_id );
		if ( ! $wc_order ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'ORDER_NOT_FOUND',
					'message' => __( 'Order not found.', 'nearmart' ),
				),
				404
			);
		}

		$order_shop_id = absint( $wc_order->get_meta( '_nearmart_shop_id' ) );
		if ( ! $is_admin && $order_shop_id !== $shop_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'FORBIDDEN',
					'message' => __( 'You cannot modify orders from another store.', 'nearmart' ),
				),
				403
			);
		}

		if ( ! is_array( $weighed ) || empty( $weighed ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'INVALID_WEIGHED_ITEMS',
					'message' => __( 'Please provide items with actual weights and prices.', 'nearmart' ),
				),
				400
			);
		}

		// Index input by catalog_id, product_id, or order_item_id
		$weighed_map = array();
		foreach ( $weighed as $w ) {
			$cid = absint( $w['catalog_id'] ?? $w['product_id'] ?? 0 );
			if ( $cid > 0 ) {
				$weighed_map[ "cat_{$cid}" ] = $w;
			}
			$oid = absint( $w['order_item_id'] ?? 0 );
			if ( $oid > 0 ) {
				$weighed_map[ "item_{$oid}" ] = $w;
			}
		}

		$all_variable_finalized = true;

		foreach ( $wc_order->get_items( 'line_item' ) as $item_id => $item ) {
			$catalog_id   = absint( $item->get_meta( '_nearmart_catalog_id' ) );
			$pricing_type = $item->get_meta( '_pricing_type' );

			if ( 'store_priced' === $pricing_type ) {
				$matched = $weighed_map[ "item_{$item_id}" ] ?? $weighed_map[ "cat_{$catalog_id}" ] ?? null;
				if ( $matched ) {
					$input_actual_qty  = floatval( $matched['actual_quantity'] ?? $matched['actual_qty'] ?? $matched['actual_weight'] ?? $matched['quantity'] ?? 1 );
					$input_unit_price  = floatval( $matched['unit_price'] ?? $matched['price'] ?? $matched['rate'] ?? 0 );

					if ( $input_actual_qty <= 0 ) {
						$input_actual_qty = 1.0;
					}

					$new_line_total = round( $input_actual_qty * $input_unit_price, 2 );

					// Update WooCommerce line item
					$item->set_quantity( $input_actual_qty );
					$item->set_subtotal( $new_line_total );
					$item->set_total( $new_line_total );

					// Update Line Item Snapshots
					$item->update_meta_data( '_actual_qty', $input_actual_qty );
					$item->update_meta_data( '_unit_price_snapshot', $input_unit_price );
					$item->update_meta_data( '_pricing_status', 'finalized' );
					$item->save();
				} else {
					if ( 'finalized' !== $item->get_meta( '_pricing_status' ) ) {
						$all_variable_finalized = false;
					}
				}
			}
		}

		if ( $all_variable_finalized ) {
			$wc_order->update_meta_data( '_nearmart_pricing_status', 'finalized' );
			$wc_order->update_meta_data( '_nearmart_is_total_provisional', 'no' );
		}

		// Recalculate Totals & Save
		$wc_order->calculate_totals();
		$wc_order->save();

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Weighed items updated and order total recalculated successfully.', 'nearmart' ),
				'data'    => array(
					'order' => self::format_order( $wc_order ),
				),
			),
			200
		);
	}

	/**
	 * Endpoint: POST /merchant/orders/{order_id}/status - Merchant Status Transition
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function update_merchant_order_status( WP_REST_Request $request ) {
		$order_id = absint( $request->get_param( 'order_id' ) );
		$status   = sanitize_text_field( (string) $request->get_param( 'status' ) );
		$user_id  = get_current_user_id();
		$is_admin = current_user_can( 'administrator' );
		$shop_id  = class_exists( 'SOM_Catalog_Permissions' ) ? SOM_Catalog_Permissions::get_current_merchant_shop_id( $user_id ) : 0;

		if ( ! in_array( $status, self::FULFILLMENT_STATUSES, true ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'INVALID_STATUS',
					'message' => sprintf(
						/* translators: %s: valid statuses */
						__( 'Invalid status. Must be one of: %s', 'nearmart' ),
						implode( ', ', self::FULFILLMENT_STATUSES )
					),
				),
				400
			);
		}

		$wc_order = wc_get_order( $order_id );
		if ( ! $wc_order ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'ORDER_NOT_FOUND',
					'message' => __( 'Order not found.', 'nearmart' ),
				),
				404
			);
		}

		$order_shop_id = absint( $wc_order->get_meta( '_nearmart_shop_id' ) );
		if ( ! $is_admin && $order_shop_id !== $shop_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'FORBIDDEN',
					'message' => __( 'You cannot change the status of an order belonging to another shop.', 'nearmart' ),
				),
				403
			);
		}

		$current_status = (string) $wc_order->get_meta( '_nearmart_fulfillment_status' );
		if ( empty( $current_status ) ) {
			$current_status = 'pending';
		}

		// Enforce state transitions:
		// pending -> accepted -> preparing -> ready_for_pickup -> completed
		// pending -> preparing
		// pending -> rejected
		$allowed_transitions = array(
			'pending'          => array( 'accepted', 'preparing', 'rejected', 'cancelled' ),
			'accepted'         => array( 'preparing', 'cancelled' ),
			'preparing'        => array( 'ready_for_pickup', 'cancelled' ),
			'ready_for_pickup' => array( 'completed', 'cancelled' ),
			'completed'        => array(),
			'cancelled'        => array(),
			'rejected'         => array(),
		);

		$valid_targets = $allowed_transitions[ $current_status ] ?? array();
		if ( ! in_array( $status, $valid_targets, true ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'INVALID_TRANSITION',
					'message' => sprintf(
						/* translators: 1: current status, 2: target status */
						__( 'Cannot transition order fulfillment status from %1$s to %2$s.', 'nearmart' ),
						$current_status,
						$status
					),
				),
				409
			);
		}

		// Guard: Do not allow ready_for_pickup while store-priced items remain unweighed
		if ( 'ready_for_pickup' === $status ) {
			$pricing_status = (string) $wc_order->get_meta( '_nearmart_pricing_status' );
			$has_unweighed  = false;

			foreach ( $wc_order->get_items( 'line_item' ) as $item ) {
				$ptype   = $item->get_meta( '_pricing_type' );
				$pstatus = $item->get_meta( '_pricing_status' );
				if ( 'store_priced' === $ptype && 'finalized' !== $pstatus ) {
					$has_unweighed = true;
					break;
				}
			}

			if ( $has_unweighed || 'pending_verification' === $pricing_status ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'code'    => 'UNWEIGHED_PRODUCE',
						'message' => __( 'Store-priced produce must be weighed and finalized before marking order ready for pickup.', 'nearmart' ),
					),
					409
				);
			}
		}

		// Rejection reason handling
		if ( 'rejected' === $status ) {
			$reason = sanitize_text_field( (string) $request->get_param( 'reason' ) );
			if ( ! empty( $reason ) ) {
				$wc_order->update_meta_data( '_nearmart_rejection_reason', $reason );
				$wc_order->add_order_note( sprintf( 'Order rejected by merchant. Reason: %s', $reason ) );
			}
		}

		// Update fulfillment status
		$wc_order->update_meta_data( '_nearmart_fulfillment_status', $status );
		$wc_status = self::map_fulfillment_to_wc_status( $status, $wc_order->get_meta( '_nearmart_payment_status' ) );
		$wc_order->set_status( $wc_status );

		// Note: We do NOT automatically mark payment as 'paid' when completing.
		// Fulfillment, pricing, and payment statuses are kept strictly independent.

		$wc_order->save();

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => sprintf( 'Order fulfillment status updated to %s.', $status ),
				'data'    => array(
					'order' => self::format_order( $wc_order ),
				),
			),
			200
		);
	}

	/**
	 * Map NearMart fulfillment status to standard native WooCommerce order status.
	 *
	 * @param string $fulfillment_status
	 * @param string $payment_status
	 * @return string
	 */
	public static function map_fulfillment_to_wc_status( $fulfillment_status, $payment_status = 'unpaid' ) {
		switch ( $fulfillment_status ) {
			case 'completed':
				return 'completed';
			case 'cancelled':
				return 'cancelled';
			case 'rejected':
				return 'failed';
			case 'accepted':
			case 'preparing':
			case 'ready_for_pickup':
				return 'processing';
			case 'pending':
			default:
				return 'paid' === $payment_status ? 'processing' : 'on-hold';
		}
	}

	/**
	 * Format Order DTO for Mobile Application.
	 *
	 * Decoupled from WooCommerce internals.
	 *
	 * @param int|WC_Order $order
	 * @return array|null
	 */
	public static function format_order( $order ) {
		if ( is_numeric( $order ) ) {
			$order_id = absint( $order );
			$wc_order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			if ( $wc_order ) {
				$order = $wc_order;
			} else {
				return self::format_legacy_order( $order_id );
			}
		}

		if ( ! ( $order instanceof WC_Order ) ) {
			return null;
		}

		$order_id     = $order->get_id();
		$order_number = $order->get_meta( '_nearmart_order_number' );
		$pickup_code  = $order->get_meta( '_nearmart_pickup_code' );
		if ( empty( $order_number ) ) {
			$order_number = sprintf( 'NM-ORD-%d', 1000 + $order_id );
		}
		if ( empty( $pickup_code ) ) {
			$pickup_code = sprintf( 'PU-%d', $order_id );
		}

		$fulfillment_status = (string) $order->get_meta( '_nearmart_fulfillment_status' );
		if ( empty( $fulfillment_status ) ) {
			$fulfillment_status = 'pending';
		}

		$pricing_status = (string) $order->get_meta( '_nearmart_pricing_status' );
		if ( empty( $pricing_status ) ) {
			$pricing_status = 'fixed';
		}

		$payment_status = (string) $order->get_meta( '_nearmart_payment_status' );
		if ( empty( $payment_status ) ) {
			$payment_status = $order->is_paid() ? 'paid' : 'unpaid';
		}

		$is_total_final = ( 'fixed' === $pricing_status || 'finalized' === $pricing_status );

		$fixed_subtotal  = (float) $order->get_meta( '_nearmart_fixed_subtotal' );
		$estimated_total = $order->get_meta( '_nearmart_estimated_total' );
		$estimated_total = '' !== $estimated_total && null !== $estimated_total ? (float) $estimated_total : null;

		$wc_total = (float) $order->get_total();

		// Calculate items
		$items          = array();
		$total_quantity = 0.0;
		$has_store_priced = false;

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$catalog_id   = absint( $item->get_meta( '_nearmart_catalog_id' ) );
			$item_type    = $item->get_meta( '_item_type' ) ?: 'master_linked';
			$pricing_type = $item->get_meta( '_pricing_type' ) ?: 'fixed';
			$item_pstatus = $item->get_meta( '_pricing_status' ) ?: 'fixed';
			$unit         = $item->get_meta( '_unit_snapshot' );
			$unit_price   = (float) $item->get_meta( '_unit_price_snapshot' );
			$qty          = (float) $item->get_quantity();
			$item_total   = (float) $item->get_total();
			$req_qty      = $item->get_meta( '_requested_qty' );
			$actual_qty   = $item->get_meta( '_actual_qty' );
			$image        = $item->get_meta( '_image_snapshot' );

			if ( 'store_priced' === $pricing_type ) {
				$has_store_priced = true;
			}

			$items[] = array(
				'order_item_id'      => (int) $item_id,
				'product_id'         => $catalog_id > 0 ? $catalog_id : $item->get_product_id(),
				'name'               => $item->get_name(),
				'item_type'          => $item_type,
				'pricing_type'       => $pricing_type,
				'pricing_status'     => $item_pstatus,
				'unit'               => ! empty( $unit ) ? (string) $unit : null,
				'image'              => ! empty( $image ) ? (string) $image : null,
				'price'              => round( $unit_price, 2 ),
				'quantity'           => $qty,
				'requested_quantity' => '' !== $req_qty && null !== $req_qty ? (float) $req_qty : $qty,
				'actual_quantity'    => '' !== $actual_qty && null !== $actual_qty ? (float) $actual_qty : null,
				'item_total'         => round( $item_total, 2 ),
			);

			$total_quantity += $qty;
		}

		// Check if store-priced produce needs weighing
		$requires_weighing = false;
		foreach ( $items as $it ) {
			if ( 'store_priced' === $it['pricing_type'] && 'finalized' !== $it['pricing_status'] ) {
				$requires_weighing = true;
				break;
			}
		}

		// Calculate available merchant fulfillment actions
		$available_actions = array();
		switch ( $fulfillment_status ) {
			case 'pending':
				$available_actions = array( 'accept', 'reject' );
				break;
			case 'accepted':
				$available_actions = array( 'start_preparing' );
				break;
			case 'preparing':
				if ( $requires_weighing ) {
					$available_actions = array( 'weigh_produce' );
				} else {
					$available_actions = array( 'mark_ready_for_pickup' );
					if ( $has_store_priced ) {
						$available_actions[] = 'weigh_produce';
					}
				}
				break;
			case 'ready_for_pickup':
				$available_actions = array( 'confirm_pickup' );
				break;
			case 'completed':
			case 'cancelled':
			case 'rejected':
			default:
				$available_actions = array();
				break;
		}

		$rejection_reason = $order->get_meta( '_nearmart_rejection_reason' );
		$rejection_reason = ! empty( $rejection_reason ) ? (string) $rejection_reason : null;

		// Payment Eligibility Calculation
		$online_payment_eligible = ( 'fixed' === $pricing_status );
		$allowed_methods         = $online_payment_eligible
			? array( 'upi', 'pay_at_store' )
			: array( 'pay_at_store' );

		$payment_eligibility = array(
			'online_payment_eligible' => $online_payment_eligible,
			'allowed_payment_methods' => $allowed_methods,
			'reason'                  => $online_payment_eligible ? null : 'CONTAINS_STORE_PRICED_ITEMS',
			'notice'                  => $online_payment_eligible
				? __( 'Eligible for instant in-app payment.', 'nearmart' )
				: __( 'Contains items weighed and priced at the store. Payable at pickup.', 'nearmart' ),
		);

		$date_created = $order->get_date_created();

		return array(
			'id'                   => (int) $order_id,
			'order_number'         => $order_number,
			'pickup_code'          => $pickup_code,
			'shop_id'              => (int) $order->get_meta( '_nearmart_shop_id' ),
			'shop_name'            => (string) $order->get_meta( '_nearmart_shop_name' ),
			'shop_address'         => (string) $order->get_meta( '_nearmart_shop_address' ),
			'shop_phone'           => (string) $order->get_meta( '_nearmart_shop_phone' ),
			'customer_id'          => $order->get_customer_id() > 0 ? (int) $order->get_customer_id() : null,
			'customer_name'        => (string) ( $order->get_meta( '_nearmart_customer_name' ) ?: $order->get_billing_first_name() ),
			'customer_phone'       => (string) ( $order->get_meta( '_nearmart_customer_phone' ) ?: $order->get_billing_phone() ),
			'customer_note'        => $order->get_customer_note() ? (string) $order->get_customer_note() : null,
			'status'               => $fulfillment_status, // Authoritative fulfillment status
			'fulfillment_status'   => $fulfillment_status,
			'pricing_status'       => $pricing_status,
			'payment_status'       => $payment_status,
			'is_total_final'       => $is_total_final,
			'fixed_items_subtotal' => round( $fixed_subtotal, 2 ),
			'estimated_total'      => $estimated_total ? round( $estimated_total, 2 ) : null,
			'final_total'          => $is_total_final ? round( $wc_total, 2 ) : null,
			'has_pending_prices'   => ! $is_total_final,
			'requires_weighing'    => $requires_weighing,
			'available_actions'    => $available_actions,
			'rejection_reason'     => $rejection_reason,
			'payment_eligibility'  => $payment_eligibility,
			'items'                => $items,
			'item_count'           => count( $items ),
			'total_quantity'       => $total_quantity,
			'subtotal'             => round( $fixed_subtotal, 2 ),
			'total'                => round( $wc_total, 2 ),
			'pickup_type'          => 'pickup',
			'created_at'           => $date_created ? $date_created->date( 'c' ) : gmdate( 'c' ),
		);
	}

	/**
	 * Format legacy nearmart_order post for backward compatibility.
	 *
	 * @param int $order_id
	 * @return array|null
	 */
	public static function format_legacy_order( $order_id ) {
		$post = get_post( $order_id );
		if ( ! $post || self::LEGACY_POST_TYPE !== $post->post_type ) {
			return null;
		}

		$order_number   = get_post_meta( $order_id, '_nearmart_order_number', true );
		$pickup_code    = get_post_meta( $order_id, '_nearmart_pickup_code', true );
		$shop_id        = (int) get_post_meta( $order_id, '_nearmart_shop_id', true );
		$shop_name      = (string) get_post_meta( $order_id, '_nearmart_shop_name', true );
		$shop_address   = (string) get_post_meta( $order_id, '_nearmart_shop_address', true );
		$shop_phone     = (string) get_post_meta( $order_id, '_nearmart_shop_phone', true );
		$customer_id    = (int) get_post_meta( $order_id, '_nearmart_customer_id', true );
		$customer_name  = (string) get_post_meta( $order_id, '_nearmart_customer_name', true );
		$customer_phone = (string) get_post_meta( $order_id, '_nearmart_customer_phone', true );
		$customer_note  = (string) get_post_meta( $order_id, '_nearmart_customer_note', true );
		$status         = (string) get_post_meta( $order_id, '_nearmart_order_status', true );
		$items          = get_post_meta( $order_id, '_nearmart_order_items', true );
		$total_quantity = (float) get_post_meta( $order_id, '_nearmart_total_quantity', true );
		$subtotal       = (float) get_post_meta( $order_id, '_nearmart_subtotal', true );
		$total          = (float) get_post_meta( $order_id, '_nearmart_total', true );

		if ( empty( $status ) ) {
			$status = 'pending';
		}
		if ( ! is_array( $items ) ) {
			$items = array();
		}
		if ( empty( $order_number ) ) {
			$order_number = sprintf( 'NM-ORD-%d', 1000 + $order_id );
		}
		if ( empty( $pickup_code ) ) {
			$pickup_code = sprintf( 'PU-%d', $order_id );
		}

		return array(
			'id'                   => (int) $order_id,
			'order_number'         => $order_number,
			'pickup_code'          => $pickup_code,
			'shop_id'              => $shop_id,
			'shop_name'            => $shop_name,
			'shop_address'         => $shop_address,
			'shop_phone'           => $shop_phone,
			'customer_id'          => $customer_id > 0 ? $customer_id : null,
			'customer_name'        => $customer_name,
			'customer_phone'       => $customer_phone,
			'customer_note'        => ! empty( $customer_note ) ? $customer_note : null,
			'status'               => $status,
			'fulfillment_status'   => $status,
			'pricing_status'       => 'fixed',
			'payment_status'       => 'unpaid',
			'is_total_final'       => true,
			'fixed_items_subtotal' => round( $subtotal, 2 ),
			'estimated_total'      => round( $total, 2 ),
			'final_total'          => round( $total, 2 ),
			'has_pending_prices'   => false,
			'payment_eligibility'  => array(
				'online_payment_eligible' => true,
				'allowed_payment_methods' => array( 'upi', 'pay_at_store' ),
			),
			'items'                => $items,
			'item_count'           => count( $items ),
			'total_quantity'       => $total_quantity,
			'subtotal'             => round( $subtotal, 2 ),
			'total'                => round( $total, 2 ),
			'pickup_type'          => 'pickup',
			'created_at'           => get_the_date( 'c', $post ),
		);
	}

	/**
	 * Helper: Fetch legacy orders.
	 *
	 * @param array  $context
	 * @param string $phone
	 * @param string $status
	 * @param int    $limit
	 * @return array
	 */
	private static function get_legacy_orders( array $context, $phone, $status, $limit ) {
		$meta_query = array( 'relation' => 'AND' );

		if ( $context['user_id'] > 0 ) {
			$meta_query[] = array(
				'key'     => '_nearmart_customer_id',
				'value'   => $context['user_id'],
				'compare' => '=',
			);
		} elseif ( ! empty( $phone ) ) {
			$meta_query[] = array(
				'key'     => '_nearmart_customer_phone',
				'value'   => $phone,
				'compare' => 'LIKE',
			);
		} elseif ( ! empty( $context['cart_session'] ) ) {
			$meta_query[] = array(
				'key'     => '_nearmart_session_token',
				'value'   => $context['cart_session'],
				'compare' => '=',
			);
		}

		if ( ! empty( $status ) && in_array( $status, self::FULFILLMENT_STATUSES, true ) ) {
			$meta_query[] = array(
				'key'     => '_nearmart_order_status',
				'value'   => $status,
				'compare' => '=',
			);
		}

		$query_args = array(
			'post_type'      => self::LEGACY_POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query;
		}

		$query  = new WP_Query( $query_args );
		$orders = array();

		foreach ( $query->posts as $post ) {
			$formatted = self::format_legacy_order( $post->ID );
			if ( $formatted ) {
				$orders[] = $formatted;
			}
		}

		return $orders;
	}

	/**
	 * Clear customer's active cart after placing order.
	 *
	 * @param array $context
	 */
	private static function clear_customer_cart( array $context ) {
		$empty_cart = array(
			'shop_id'        => null,
			'shop_name'      => null,
			'items'          => array(),
			'item_count'     => 0,
			'total_quantity' => 0,
			'subtotal'       => 0.0,
			'session_token'  => $context['cart_session'],
		);

		if ( $context['user_id'] > 0 ) {
			update_user_meta( $context['user_id'], 'nearmart_customer_cart', $empty_cart );
		}

		if ( ! empty( $context['cart_session'] ) ) {
			delete_transient( 'nearmart_cart_' . $context['cart_session'] );
		}
	}

	/**
	 * Add NearMart custom column to WooCommerce Orders Admin table.
	 *
	 * @param array $columns
	 * @return array
	 */
	public static function add_admin_order_columns( $columns ) {
		$new_columns = array();
		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new_columns['nearmart_fulfillment'] = __( 'NearMart Fulfillment', 'nearmart' );
			}
		}
		if ( ! isset( $new_columns['nearmart_fulfillment'] ) ) {
			$new_columns['nearmart_fulfillment'] = __( 'NearMart Fulfillment', 'nearmart' );
		}
		return $new_columns;
	}

	/**
	 * Render NearMart column content in HPOS orders table.
	 *
	 * @param string   $column
	 * @param WC_Order $order
	 */
	public static function render_admin_order_column_hpos( $column, $order ) {
		if ( 'nearmart_fulfillment' === $column && $order instanceof WC_Order ) {
			self::render_admin_order_column_content( $order );
		}
	}

	/**
	 * Render NearMart column content in legacy CPT shop_order table.
	 *
	 * @param string $column
	 * @param int    $post_id
	 */
	public static function render_admin_order_column_cpt( $column, $post_id ) {
		if ( 'nearmart_fulfillment' === $column ) {
			$order = wc_get_order( $post_id );
			if ( $order instanceof WC_Order ) {
				self::render_admin_order_column_content( $order );
			}
		}
	}

	/**
	 * Render NearMart fulfillment badge, store name, and pickup code in WooCommerce admin.
	 *
	 * @param WC_Order $order
	 */
	public static function render_admin_order_column_content( WC_Order $order ) {
		$shop_id = absint( $order->get_meta( '_nearmart_shop_id' ) );
		if ( ! $shop_id ) {
			echo '<span style="color:#94a3b8;">—</span>';
			return;
		}

		$fulfillment = (string) $order->get_meta( '_nearmart_fulfillment_status' ) ?: 'pending';
		$pickup_code = (string) $order->get_meta( '_nearmart_pickup_code' );
		$shop_name   = get_the_title( $shop_id );

		$badge_styles = array(
			'pending'          => array( 'bg' => '#fffbeb', 'color' => '#b45309', 'label' => '⏳ Pending' ),
			'accepted'         => array( 'bg' => '#eff6ff', 'color' => '#1d4ed8', 'label' => '✓ Accepted' ),
			'preparing'        => array( 'bg' => '#eef2ff', 'color' => '#4338ca', 'label' => '📦 Preparing' ),
			'ready_for_pickup' => array( 'bg' => '#ecfdf5', 'color' => '#047857', 'label' => '🛍️ Ready for Pickup' ),
			'completed'        => array( 'bg' => '#f1f5f9', 'color' => '#475569', 'label' => '🎉 Picked Up' ),
			'rejected'         => array( 'bg' => '#fef2f2', 'color' => '#b91c1c', 'label' => '✕ Rejected' ),
			'cancelled'        => array( 'bg' => '#fef2f2', 'color' => '#b91c1c', 'label' => '✕ Cancelled' ),
		);

		$st = isset( $badge_styles[ $fulfillment ] ) ? $badge_styles[ $fulfillment ] : array( 'bg' => '#f8fafc', 'color' => '#64748b', 'label' => ucfirst( $fulfillment ) );

		echo '<div style="display:flex; flex-direction:column; gap:4px; align-items:flex-start;">';
		echo '<span style="display:inline-block; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:700; background:' . esc_attr( $st['bg'] ) . '; color:' . esc_attr( $st['color'] ) . ';">' . esc_html( $st['label'] ) . '</span>';

		if ( ! empty( $pickup_code ) ) {
			echo '<span style="font-size:11px; font-weight:700; font-family:monospace; color:#1e3a8a; background:#eff6ff; padding:1px 5px; border-radius:3px;">' . esc_html( $pickup_code ) . '</span>';
		}

		if ( ! empty( $shop_name ) ) {
			echo '<span style="font-size:11px; color:#64748b;">🏬 ' . esc_html( $shop_name ) . '</span>';
		}
		echo '</div>';
	}

}
