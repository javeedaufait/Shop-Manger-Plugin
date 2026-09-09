<?php
/**
 * NearMart Push Notifications Module (APP-9.1).
 *
 * Handles Expo Push Token registration, token deduplication,
 * authoritative order lifecycle triggers, bilingual localization (EN/ML),
 * idempotency guards, and push dispatch via Expo Push API.
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SOM_Push_Notifications {

	const EXPO_API_URL = 'https://exp.host/--/api/v2/push/send';
	const USER_META_KEY = '_nearmart_expo_push_tokens';

	/**
	 * Initialize hooks and REST routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// Hook into order events
		add_action( 'nearmart_order_created', array( __CLASS__, 'on_order_created' ), 10, 2 );
		add_action( 'nearmart_order_fulfillment_status_changed', array( __CLASS__, 'on_fulfillment_status_changed' ), 10, 4 );
		add_action( 'nearmart_order_produce_finalized', array( __CLASS__, 'on_produce_finalized' ), 10, 2 );
	}

	/**
	 * Register REST routes for push token registration.
	 */
	public static function register_routes() {
		// POST /wp-json/nearmart/v1/notifications/register-token
		register_rest_route(
			'nearmart/v1',
			'/notifications/register-token',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_register_token' ),
				'permission_callback' => array( 'SOM_Mobile_Auth', 'check_authenticated_permission' ),
				'args'                => array(
					'token'     => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'platform'  => array(
						'default'           => 'android',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'device_id' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// POST /wp-json/nearmart/v1/notifications/deregister-token
		register_rest_route(
			'nearmart/v1',
			'/notifications/deregister-token',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_deregister_token' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * REST Endpoint: Register or refresh an Expo push token.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_register_token( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$token     = trim( (string) $request->get_param( 'token' ) );
		$platform  = sanitize_text_field( (string) $request->get_param( 'platform' ) );
		$device_id = sanitize_text_field( (string) $request->get_param( 'device_id' ) );

		if ( empty( $token ) || ! self::is_valid_expo_push_token( $token ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'INVALID_PUSH_TOKEN',
					'message' => 'Invalid Expo push token format.',
				),
				400
			);
		}

		self::save_user_push_token( $user_id, $token, $platform, $device_id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Push notification token registered successfully.',
				'data'    => array(
					'user_id'   => $user_id,
					'token'     => $token,
					'platform'  => $platform,
					'device_id' => $device_id,
				),
			),
			200
		);
	}

	/**
	 * REST Endpoint: Deregister an Expo push token upon logout.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_deregister_token( WP_REST_Request $request ) {
		$token = trim( (string) $request->get_param( 'token' ) );

		if ( ! empty( $token ) ) {
			self::remove_token_globally( $token );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Push token deregistered.',
			),
			200
		);
	}

	/**
	 * Validate format of Expo Push Token.
	 *
	 * @param string $token
	 * @return bool
	 */
	public static function is_valid_expo_push_token( $token ) {
		return (
			str_starts_with( $token, 'ExponentPushToken[' ) ||
			str_starts_with( $token, 'ExpoPushToken[' )
		);
	}

	/**
	 * Save token against user, ensuring no other user has this same token.
	 *
	 * @param int $user_id
	 * @param string $token
	 * @param string $platform
	 * @param string $device_id
	 */
	public static function save_user_push_token( $user_id, $token, $platform = 'android', $device_id = '' ) {
		// 1. Remove this token from any other users to prevent cross-account leaks
		self::remove_token_globally( $token, $user_id );

		// 2. Fetch current tokens for this user
		$tokens = get_user_meta( $user_id, self::USER_META_KEY, true );
		if ( ! is_array( $tokens ) ) {
			$tokens = array();
		}

		// 3. Remove existing entry for this token or device_id if present
		$filtered = array();
		foreach ( $tokens as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( ( $entry['token'] ?? '' ) === $token ) {
				continue;
			}
			if ( ! empty( $device_id ) && ( $entry['device_id'] ?? '' ) === $device_id ) {
				continue;
			}
			$filtered[] = $entry;
		}

		// 4. Append updated token record
		$filtered[] = array(
			'token'      => $token,
			'platform'   => $platform,
			'device_id'  => $device_id,
			'updated_at' => current_time( 'mysql' ),
		);

		update_user_meta( $user_id, self::USER_META_KEY, $filtered );
	}

	/**
	 * Remove a token across all users, optionally excluding a specific user.
	 *
	 * @param string $token
	 * @param int $exclude_user_id
	 */
	public static function remove_token_globally( $token, $exclude_user_id = 0 ) {
		global $wpdb;

		// Query users having this meta
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
				self::USER_META_KEY
			)
		);

		if ( empty( $results ) ) {
			return;
		}

		foreach ( $results as $row ) {
			$uid = absint( $row->user_id );
			if ( $exclude_user_id && $uid === $exclude_user_id ) {
				continue;
			}

			$user_tokens = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $user_tokens ) ) {
				continue;
			}

			$has_change = false;
			$new_tokens = array();
			foreach ( $user_tokens as $t ) {
				if ( is_array( $t ) && ( $t['token'] ?? '' ) === $token ) {
					$has_change = true;
				} else {
					$new_tokens[] = $t;
				}
			}

			if ( $has_change ) {
				update_user_meta( $uid, self::USER_META_KEY, $new_tokens );
			}
		}
	}

	/**
	 * Get active Expo push tokens for a user.
	 *
	 * @param int $user_id
	 * @return array List of string tokens
	 */
	public static function get_user_tokens( $user_id ) {
		$data = get_user_meta( $user_id, self::USER_META_KEY, true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$tokens = array();
		foreach ( $data as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['token'] ) ) {
				$tokens[] = $entry['token'];
			}
		}
		return array_unique( $tokens );
	}

	/**
	 * Get push tokens for all merchants assigned to a specific shop.
	 *
	 * @param int $shop_id
	 * @return array
	 */
	public static function get_shop_merchant_tokens( $shop_id ) {
		$shop_id = absint( $shop_id );
		if ( ! $shop_id ) {
			return array();
		}

		// Find users with som_shop_id = $shop_id
		$merchants = get_users(
			array(
				'meta_key'   => 'som_shop_id',
				'meta_value' => $shop_id,
				'fields'     => 'ID',
			)
		);

		// Also include post author of the shop if valid merchant
		$author_id = get_post_field( 'post_author', $shop_id );
		if ( $author_id && ! in_array( $author_id, $merchants, true ) ) {
			$merchants[] = absint( $author_id );
		}

		$all_tokens = array();
		foreach ( $merchants as $merchant_id ) {
			$tokens = self::get_user_tokens( $merchant_id );
			$all_tokens = array_merge( $all_tokens, $tokens );
		}

		return array_unique( $all_tokens );
	}

	/**
	 * Send push notification via Expo Push API.
	 *
	 * @param array $tokens Array of Expo push token strings.
	 * @param string $title
	 * @param string $body
	 * @param array $data Deep linking and context data.
	 * @return bool
	 */
	public static function send_push( $tokens, $title, $body, $data = array() ) {
		if ( empty( $tokens ) || ! is_array( $tokens ) ) {
			return false;
		}

		$messages = array();
		foreach ( $tokens as $token ) {
			if ( ! self::is_valid_expo_push_token( $token ) ) {
				continue;
			}
			$messages[] = array(
				'to'        => $token,
				'sound'     => 'default',
				'title'     => $title,
				'body'      => $body,
				'data'      => $data,
				'channelId' => 'nearmart_orders',
				'priority'  => 'high',
			);
		}

		if ( empty( $messages ) ) {
			return false;
		}

		$response = wp_remote_post(
			self::EXPO_API_URL,
			array(
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $messages ),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[NearMart Push Error]: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body_raw = wp_remote_retrieve_body( $response );
		$json = json_decode( $body_raw, true );

		// Prune invalid tokens if DeviceNotRegistered
		if ( isset( $json['data'] ) && is_array( $json['data'] ) ) {
			foreach ( $json['data'] as $idx => $ticket ) {
				if ( isset( $ticket['status'] ) && 'error' === $ticket['status'] ) {
					if ( isset( $ticket['details']['error'] ) && 'DeviceNotRegistered' === $ticket['details']['error'] ) {
						if ( isset( $messages[ $idx ]['to'] ) ) {
							self::remove_token_globally( $messages[ $idx ]['to'] );
						}
					}
				}
			}
		}

		return ( 200 === $code );
	}

	/**
	 * Get preferred language for a user ('en' or 'ml').
	 *
	 * @param int $user_id
	 * @return string
	 */
	public static function get_user_language( $user_id ) {
		$lang = get_user_meta( $user_id, 'nearmart_preferred_lang', true );
		return ( 'ml' === $lang ) ? 'ml' : 'en';
	}

	/* =========================================================================
	 *                    AUTHORITATIVE ORDER EVENT HANDLERS
	 * ========================================================================= */

	/**
	 * Event: New Order Created.
	 * Triggered after successful order placement.
	 * Notifies: Assigned Shop Merchants.
	 *
	 * @param WC_Order $wc_order
	 * @param int $shop_id
	 */
	public static function on_order_created( $wc_order, $shop_id ) {
		$order_id = $wc_order->get_id();

		// Idempotency check
		$notified_key = '_nearmart_notified_new_order';
		if ( 'yes' === $wc_order->get_meta( $notified_key ) ) {
			return;
		}

		$merchant_tokens = self::get_shop_merchant_tokens( $shop_id );
		if ( empty( $merchant_tokens ) ) {
			return;
		}

		$order_number = $wc_order->get_meta( '_nearmart_order_number' ) ?: 'NM-ORD-' . $order_id;
		$item_count   = $wc_order->get_item_count();

		$title = 'New Order Received';
		$body  = sprintf( 'New order %1$s received (%2$d items). Please review and accept.', $order_number, $item_count );

		$deep_link_data = array(
			'type'         => 'merchant_new_order',
			'order_id'     => $order_id,
			'order_number' => $order_number,
			'target_role'  => 'merchant',
		);

		$sent = self::send_push( $merchant_tokens, $title, $body, $deep_link_data );
		if ( $sent ) {
			$wc_order->update_meta_data( $notified_key, 'yes' );
			$wc_order->save();
		}
	}

	/**
	 * Event: Fulfillment Status Changed.
	 * Triggered when order status transitions (accepted, preparing, ready_for_pickup, completed, rejected).
	 * Notifies: Customer.
	 *
	 * @param WC_Order $wc_order
	 * @param string $new_status
	 * @param string $old_status
	 * @param string $reason Optional rejection reason.
	 */
	public static function on_fulfillment_status_changed( $wc_order, $new_status, $old_status, $reason = '' ) {
		$order_id    = $wc_order->get_id();
		$customer_id = $wc_order->get_customer_id();

		if ( ! $customer_id ) {
			return;
		}

		// Idempotency check: Do not re-notify if this status notification was already dispatched
		$notified_key = '_nearmart_notified_status_' . $new_status;
		if ( 'yes' === $wc_order->get_meta( $notified_key ) ) {
			return;
		}

		$customer_tokens = self::get_user_tokens( $customer_id );
		if ( empty( $customer_tokens ) ) {
			return;
		}

		$lang         = self::get_user_language( $customer_id );
		$order_number = $wc_order->get_meta( '_nearmart_order_number' ) ?: 'NM-ORD-' . $order_id;
		$pickup_code  = $wc_order->get_meta( '_nearmart_pickup_code' ) ?: '';
		$shop_name    = $wc_order->get_meta( '_nearmart_shop_name' ) ?: 'Store';

		$title = '';
		$body  = '';

		switch ( $new_status ) {
			case 'accepted':
				if ( 'ml' === $lang ) {
					$title = 'ഓർഡർ സ്വീകരിച്ചു';
					$body  = sprintf( 'നിങ്ങളുടെ ഓർഡർ %1$s %2$s സ്വീകരിച്ചു.', $order_number, $shop_name );
				} else {
					$title = 'Order Accepted';
					$body  = sprintf( 'Your order %1$s has been accepted by %2$s.', $order_number, $shop_name );
				}
				break;

			case 'preparing':
				if ( 'ml' === $lang ) {
					$title = 'ഓർഡർ തയ്യാറാക്കുന്നു';
					$body  = sprintf( 'നിങ്ങളുടെ ഓർഡർ %1$s പായ്ക്ക് ചെയ്യുന്നു.', $order_number );
				} else {
					$title = 'Order Packing';
					$body  = sprintf( 'Your order %1$s is being prepared and packed.', $order_number );
				}
				break;

			case 'ready_for_pickup':
				if ( 'ml' === $lang ) {
					$title = 'പിക്കപ്പിനായി തയ്യാർ!';
					$body  = sprintf( 'നിങ്ങളുടെ ഓർഡർ %1$s തയ്യാറാണ്. %2$s കോഡ് കൗണ്ടറിൽ കാണിക്കുക.', $order_number, $pickup_code );
				} else {
					$title = 'Ready for Pickup!';
					$body  = sprintf( 'Your order %1$s is ready for pickup. Show code %2$s at the counter.', $order_number, $pickup_code );
				}
				break;

			case 'completed':
				if ( 'ml' === $lang ) {
					$title = 'ഓർഡർ പൂർത്തിയായി';
					$body  = sprintf( 'നന്ദി! നിങ്ങളുടെ ഓർഡർ %1$s കൈപ്പറ്റി.', $order_number );
				} else {
					$title = 'Order Completed';
					$body  = sprintf( 'Thank you! Your order %1$s has been picked up.', $order_number );
				}
				break;

			case 'rejected':
				if ( 'ml' === $lang ) {
					$title = 'ഓർഡർ അപ്‌ഡേറ്റ്';
					$body  = ! empty( $reason )
						? sprintf( 'നിങ്ങളുടെ ഓർഡർ %1$s നിരസിച്ചു. കാരണം: %2$s', $order_number, $reason )
						: sprintf( 'നിങ്ങളുടെ ഓർഡർ %1$s സ്റ്റോർ നിരസിച്ചു.', $order_number );
				} else {
					$title = 'Order Update';
					$body  = ! empty( $reason )
						? sprintf( 'Your order %1$s was rejected. Reason: %2$s', $order_number, $reason )
						: sprintf( 'Your order %1$s could not be accepted by the store.', $order_number );
				}
				break;

			default:
				return;
		}

		$deep_link_data = array(
			'type'         => 'order_status_update',
			'order_id'     => $order_id,
			'order_number' => $order_number,
			'status'       => $new_status,
			'target_role'  => 'customer',
		);

		$sent = self::send_push( $customer_tokens, $title, $body, $deep_link_data );
		if ( $sent ) {
			$wc_order->update_meta_data( $notified_key, 'yes' );
			$wc_order->save();
		}
	}

	/**
	 * Event: Produce Weighed and Total Finalized.
	 * Triggered when all variable produce items have been weighed and final price is confirmed.
	 * Notifies: Customer.
	 *
	 * @param WC_Order $wc_order
	 * @param float $final_total
	 */
	public static function on_produce_finalized( $wc_order, $final_total ) {
		$order_id    = $wc_order->get_id();
		$customer_id = $wc_order->get_customer_id();

		if ( ! $customer_id ) {
			return;
		}

		$notified_key = '_nearmart_notified_produce_finalized';
		if ( 'yes' === $wc_order->get_meta( $notified_key ) ) {
			return;
		}

		$customer_tokens = self::get_user_tokens( $customer_id );
		if ( empty( $customer_tokens ) ) {
			return;
		}

		$lang         = self::get_user_language( $customer_id );
		$order_number = $wc_order->get_meta( '_nearmart_order_number' ) ?: 'NM-ORD-' . $order_id;
		$formatted_total = number_format( (float) $final_total, 2, '.', '' );

		if ( 'ml' === $lang ) {
			$title = 'വില കണക്കാക്കി';
			$body  = sprintf( 'തൂക്കം നോക്കി. നിങ്ങളുടെ ഓർഡർ %1$s അന്തിമ തുക ₹%2$s.', $order_number, $formatted_total );
		} else {
			$title = 'Produce Price Finalized';
			$body  = sprintf( 'Your order total is now ₹%1$s.', $formatted_total );
		}

		$deep_link_data = array(
			'type'         => 'order_produce_finalized',
			'order_id'     => $order_id,
			'order_number' => $order_number,
			'final_total'  => $final_total,
			'target_role'  => 'customer',
		);

		$sent = self::send_push( $customer_tokens, $title, $body, $deep_link_data );
		if ( $sent ) {
			$wc_order->update_meta_data( $notified_key, 'yes' );
			$wc_order->save();
		}
	}
}

SOM_Push_Notifications::init();
