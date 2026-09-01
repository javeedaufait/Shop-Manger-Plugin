<?php
/**
 * NearMart Mobile Authentication & Token REST API Module (Phase APP-2).
 *
 * Base Namespace: nearmart/v1
 * Auth Route: /wp-json/nearmart/v1/auth/
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Mobile_Auth
 */
class SOM_Mobile_Auth {

	/**
	 * Namespace for NearMart API v1.
	 */
	const NAMESPACE = 'nearmart/v1';

	/**
	 * Token expiration in seconds (30 days).
	 */
	const TOKEN_EXPIRY = 2592000; // 30 * 86400

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Hook into WordPress authentication filter for REST requests
		add_filter( 'determine_current_user', array( __CLASS__, 'determine_current_user' ), 20 );

		// Register REST routes
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register auth routes under /wp-json/nearmart/v1/auth/
	 */
	public static function register_routes() {
		// 1. POST /wp-json/nearmart/v1/auth/register
		register_rest_route(
			self::NAMESPACE,
			'/auth/register',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_register' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'name'     => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'email'    => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					),
					'password' => array(
						'required'          => true,
						'type'              => 'string',
					),
					'phone'    => array(
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// 2. POST /wp-json/nearmart/v1/auth/login
		register_rest_route(
			self::NAMESPACE,
			'/auth/login',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_login' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'username' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'password' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		// 3. POST /wp-json/nearmart/v1/auth/logout
		register_rest_route(
			self::NAMESPACE,
			'/auth/logout',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_logout' ),
				'permission_callback' => array( __CLASS__, 'permissions_authenticated' ),
			)
		);

		// 4. GET /wp-json/nearmart/v1/auth/me
		register_rest_route(
			self::NAMESPACE,
			'/auth/me',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_get_current_user' ),
				'permission_callback' => array( __CLASS__, 'permissions_authenticated' ),
			)
		);

		// 5. PUT /wp-json/nearmart/v1/auth/profile
		register_rest_route(
			self::NAMESPACE,
			'/auth/profile',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'rest_update_profile' ),
				'permission_callback' => array( __CLASS__, 'permissions_authenticated' ),
				'args'                => array(
					'name'             => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'first_name'       => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'last_name'        => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'email'            => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_email',
					),
					'phone'            => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'current_password' => array(
						'required' => false,
						'type'     => 'string',
					),
					'new_password'     => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Permission callback: Check if user is authenticated via Bearer token or session.
	 *
	 * @return bool|WP_Error
	 */
	public static function permissions_authenticated() {
		if ( is_user_logged_in() && get_current_user_id() > 0 ) {
			return true;
		}

		return new WP_Error(
			'rest_not_logged_in',
			__( 'Authentication required. Please provide a valid Bearer token.', 'nearmart' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Base64URL encode string.
	 *
	 * @param string $data Data to encode.
	 * @return string Base64URL string.
	 */
	private static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Base64URL decode string.
	 *
	 * @param string $data Data to decode.
	 * @return string Decoded string.
	 */
	private static function base64url_decode( $data ) {
		return base64_decode( strtr( $data, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $data ) % 4 ) % 4 ) );
	}

	/**
	 * Get secret signing key based on WordPress salts.
	 *
	 * @return string
	 */
	private static function get_signing_key() {
		if ( defined( 'AUTH_KEY' ) && '' !== AUTH_KEY ) {
			return AUTH_KEY;
		}
		return wp_salt( 'auth' );
	}

	/**
	 * Get user token version for revocation check.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function get_user_token_version( $user_id ) {
		$ver = get_user_meta( $user_id, 'som_auth_token_version', true );
		if ( '' === $ver || false === $ver ) {
			$ver = 1;
			update_user_meta( $user_id, 'som_auth_token_version', $ver );
		}
		return (int) $ver;
	}

	/**
	 * Invalidate all active tokens for a user.
	 *
	 * @param int $user_id User ID.
	 * @return int New token version.
	 */
	public static function invalidate_user_tokens( $user_id ) {
		$new_ver = self::get_user_token_version( $user_id ) + 1;
		update_user_meta( $user_id, 'som_auth_token_version', $new_ver );
		return $new_ver;
	}

	/**
	 * Generate signed Bearer Token for a user.
	 *
	 * @param int $user_id User ID.
	 * @param int $expiry_seconds Lifespan in seconds.
	 * @return array Array with 'token' and 'expires_at'.
	 */
	public static function generate_token( $user_id, $expiry_seconds = self::TOKEN_EXPIRY ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}

		$issued_at  = time();
		$expires_at = $issued_at + $expiry_seconds;
		$version    = self::get_user_token_version( $user_id );
		$role       = ! empty( $user->roles ) ? $user->roles[0] : 'customer';

		$header = array(
			'typ' => 'JWT',
			'alg' => 'HS256',
		);

		$payload = array(
			'iss'  => home_url(),
			'uid'  => (int) $user_id,
			'role' => $role,
			'iat'  => $issued_at,
			'exp'  => $expires_at,
			'ver'  => $version,
			'jti'  => wp_generate_password( 16, false ),
		);

		$header_encoded  = self::base64url_encode( wp_json_encode( $header ) );
		$payload_encoded = self::base64url_encode( wp_json_encode( $payload ) );

		$signature = hash_hmac( 'sha256', $header_encoded . '.' . $payload_encoded, self::get_signing_key(), true );
		$signature_encoded = self::base64url_encode( $signature );

		$token_string = $header_encoded . '.' . $payload_encoded . '.' . $signature_encoded;

		return array(
			'token'      => $token_string,
			'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', $expires_at ),
		);
	}

	/**
	 * Validate a signed Bearer Token and return User ID.
	 *
	 * @param string $token_string Raw token.
	 * @return int|false User ID or false if invalid/expired.
	 */
	public static function validate_token( $token_string ) {
		if ( empty( $token_string ) || ! is_string( $token_string ) ) {
			return false;
		}

		$parts = explode( '.', $token_string );
		if ( 3 !== count( $parts ) ) {
			return false;
		}

		list( $header_enc, $payload_enc, $sig_enc ) = $parts;

		// 1. Verify Signature
		$expected_sig = self::base64url_encode(
			hash_hmac( 'sha256', $header_enc . '.' . $payload_enc, self::get_signing_key(), true )
		);

		if ( ! hash_equals( $expected_sig, $sig_enc ) ) {
			return false;
		}

		// 2. Decode Payload
		$payload_json = self::base64url_decode( $payload_enc );
		$payload      = json_decode( $payload_json, true );

		if ( ! is_array( $payload ) || empty( $payload['uid'] ) || empty( $payload['exp'] ) ) {
			return false;
		}

		// 3. Check Expiry
		if ( time() > (int) $payload['exp'] ) {
			return false;
		}

		$user_id = (int) $payload['uid'];

		// 4. Verify Token Version against DB
		$current_ver = self::get_user_token_version( $user_id );
		if ( isset( $payload['ver'] ) && (int) $payload['ver'] !== $current_ver ) {
			return false;
		}

		// 5. Verify User exists
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		return $user_id;
	}

	/**
	 * Extract Bearer token from incoming HTTP headers.
	 *
	 * @return string|false
	 */
	private static function get_bearer_token() {
		$header = '';

		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		} elseif ( function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			if ( isset( $headers['Authorization'] ) ) {
				$header = sanitize_text_field( $headers['Authorization'] );
			}
		}

		if ( ! empty( $header ) && preg_match( '/^Bearer\s+(.+)$/i', $header, $matches ) ) {
			return trim( $matches[1] );
		}

		return false;
	}

	/**
	 * Filter: determine_current_user to authenticate REST requests via Bearer Token.
	 *
	 * @param int|false $user_id Current user ID.
	 * @return int|false Authenticated user ID.
	 */
	public static function determine_current_user( $user_id ) {
		// If already determined by WordPress session, return it
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}

		$token = self::get_bearer_token();
		if ( ! $token ) {
			return $user_id;
		}

		$valid_uid = self::validate_token( $token );
		if ( $valid_uid ) {
			return $valid_uid;
		}

		return $user_id;
	}

	/**
	 * Format User DTO for consistent API responses.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public static function format_user_data( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}

		$roles = (array) $user->roles;
		$role  = ! empty( $roles ) ? reset( $roles ) : 'customer';

		$phone = get_user_meta( $user_id, 'billing_phone', true );
		if ( ! $phone ) {
			$phone = get_user_meta( $user_id, 'som_phone', true );
		}

		$data = array(
			'id'              => (int) $user->ID,
			'username'        => $user->user_login,
			'email'           => $user->user_email,
			'name'            => $user->display_name,
			'first_name'      => $user->first_name,
			'last_name'       => $user->last_name,
			'phone'           => $phone ? (string) $phone : null,
			'role'            => $role,
			'registered_date' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $user->user_registered ) ),
		);

		// If Merchant, attach linked shop profile
		if ( in_array( 'merchant', $roles, true ) || in_array( 'administrator', $roles, true ) ) {
			$shop_id = (int) get_user_meta( $user_id, 'som_shop_id', true );
			if ( $shop_id && 'publish' === get_post_status( $shop_id ) ) {
				$photo_id  = get_post_meta( $shop_id, 'som_shop_photo_id', true );
				$photo_url = $photo_id ? wp_get_attachment_url( $photo_id ) : ( has_post_thumbnail( $shop_id ) ? get_the_post_thumbnail_url( $shop_id, 'full' ) : null );

				$data['shop'] = array(
					'shop_id'     => $shop_id,
					'name'        => get_the_title( $shop_id ),
					'shop_type'   => (string) get_post_meta( $shop_id, 'som_shop_type', true ),
					'address'     => (string) get_post_meta( $shop_id, 'som_address', true ),
					'phone'       => (string) get_post_meta( $shop_id, 'som_phone_number', true ),
					'owner_name'  => (string) get_post_meta( $shop_id, 'som_owner_name', true ),
					'photo_url'   => $photo_url ? (string) $photo_url : null,
					'status'      => get_post_meta( $shop_id, 'som_verified', true ) ? 'verified' : 'active',
				);
			} else {
				$data['shop'] = null;
			}
		}

		return $data;
	}

	/**
	 * Endpoint 1: POST /wp-json/nearmart/v1/auth/register
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_register( WP_REST_Request $request ) {
		$name     = trim( $request->get_param( 'name' ) );
		$email    = trim( $request->get_param( 'email' ) );
		$password = $request->get_param( 'password' );
		$phone    = trim( $request->get_param( 'phone' ) );

		if ( empty( $name ) || empty( $email ) || empty( $password ) ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'error_code' => 'INVALID_INPUT',
					'message'    => __( 'Name, email, and password are required.', 'nearmart' ),
					'details'    => array(),
				),
				400
			);
		}

		if ( ! is_email( $email ) ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'error_code' => 'INVALID_EMAIL',
					'message'    => __( 'Please provide a valid email address.', 'nearmart' ),
					'details'    => array( 'email' => $email ),
				),
				400
			);
		}

		if ( strlen( $password ) < 6 ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'error_code' => 'WEAK_PASSWORD',
					'message'    => __( 'Password must be at least 6 characters long.', 'nearmart' ),
					'details'    => array(),
				),
				400
			);
		}

		if ( email_exists( $email ) ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'error_code' => 'EMAIL_EXISTS',
					'message'    => __( 'An account with this email address already exists.', 'nearmart' ),
					'details'    => array( 'email' => $email ),
				),
				409
			);
		}

		// Generate clean username from email or name
		$base_username = sanitize_user( current( explode( '@', $email ) ), true );
		$username      = $base_username;
		$i             = 1;
		while ( username_exists( $username ) ) {
			$username = $base_username . $i;
			$i++;
		}

		$user_id = wp_create_user( $username, $password, $email );
		if ( is_wp_error( $user_id ) ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'error_code' => 'REGISTRATION_FAILED',
					'message'    => $user_id->get_error_message(),
					'details'    => array(),
				),
				500
			);
		}

		// Assign Customer role and name
		$user = new WP_User( $user_id );
		$user->set_role( 'customer' );

		// Parse display name
		$parts      = explode( ' ', $name, 2 );
		$first_name = $parts[0];
		$last_name  = isset( $parts[1] ) ? $parts[1] : '';

		wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => $name,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
			)
		);

		if ( ! empty( $phone ) ) {
			update_user_meta( $user_id, 'billing_phone', $phone );
			update_user_meta( $user_id, 'som_phone', $phone );
		}

		// Generate Token
		$token_data = self::generate_token( $user_id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'user'       => self::format_user_data( $user_id ),
					'token'      => $token_data['token'],
					'expires_at' => $token_data['expires_at'],
				),
				'message' => __( 'Customer registered and logged in successfully.', 'nearmart' ),
			),
			201
		);
	}

	/**
	 * Endpoint 2: POST /wp-json/nearmart/v1/auth/login
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_login( WP_REST_Request $request ) {
		$username = trim( $request->get_param( 'username' ) );
		$password = $request->get_param( 'password' );

		if ( empty( $username ) || empty( $password ) ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'error_code' => 'INVALID_CREDENTIALS',
					'message'    => __( 'Username/email and password are required.', 'nearmart' ),
					'details'    => array(),
				),
				400
			);
		}

		// Authenticate credentials against WordPress
		$user = wp_authenticate( $username, $password );

		if ( is_wp_error( $user ) ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'error_code' => 'INVALID_CREDENTIALS',
					'message'    => __( 'Invalid username/email or password.', 'nearmart' ),
					'details'    => array(),
				),
				401
			);
		}

		// Generate Token
		$token_data = self::generate_token( $user->ID );

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'user'       => self::format_user_data( $user->ID ),
					'token'      => $token_data['token'],
					'expires_at' => $token_data['expires_at'],
				),
				'message' => __( 'Logged in successfully.', 'nearmart' ),
			),
			200
		);
	}

	/**
	 * Endpoint 3: POST /wp-json/nearmart/v1/auth/logout
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_logout( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			// Invalidate all tokens for this user
			self::invalidate_user_tokens( $user_id );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => new stdClass(),
				'message' => __( 'Logged out successfully. Active tokens invalidated.', 'nearmart' ),
			),
			200
		);
	}

	/**
	 * Endpoint 4: GET /wp-json/nearmart/v1/auth/me
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_get_current_user( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$data    = self::format_user_data( $user_id );

		if ( ! $data ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'error_code' => 'USER_NOT_FOUND',
					'message'    => __( 'User profile not found.', 'nearmart' ),
					'details'    => array(),
				),
				404
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'user' => $data,
				),
				'message' => '',
			),
			200
		);
	}

	/**
	 * Endpoint 5: PUT /wp-json/nearmart/v1/auth/profile
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_update_profile( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'error_code' => 'USER_NOT_FOUND',
					'message'    => __( 'User profile not found.', 'nearmart' ),
					'details'    => array(),
				),
				404
			);
		}

		$name             = $request->get_param( 'name' );
		$first_name       = $request->get_param( 'first_name' );
		$last_name        = $request->get_param( 'last_name' );
		$email            = $request->get_param( 'email' );
		$phone            = $request->get_param( 'phone' );
		$current_password = $request->get_param( 'current_password' );
		$new_password     = $request->get_param( 'new_password' );

		$update_args = array( 'ID' => $user_id );

		// Update Name
		if ( null !== $name && '' !== trim( $name ) ) {
			$update_args['display_name'] = trim( $name );
		}
		if ( null !== $first_name ) {
			$update_args['first_name'] = trim( $first_name );
		}
		if ( null !== $last_name ) {
			$update_args['last_name'] = trim( $last_name );
		}

		// Update Email
		if ( ! empty( $email ) && $email !== $user->user_email ) {
			if ( ! is_email( $email ) ) {
				return new WP_REST_Response(
					array(
						'success'    => false,
						'error_code' => 'INVALID_EMAIL',
						'message'    => __( 'Please provide a valid email address.', 'nearmart' ),
						'details'    => array( 'email' => $email ),
					),
					400
				);
			}

			$existing = email_exists( $email );
			if ( $existing && $existing !== $user_id ) {
				return new WP_REST_Response(
					array(
						'success'    => false,
						'error_code' => 'EMAIL_EXISTS',
						'message'    => __( 'This email address is already in use by another account.', 'nearmart' ),
						'details'    => array( 'email' => $email ),
					),
					409
				);
			}

			$update_args['user_email'] = $email;
		}

		// Update Password if requested
		if ( ! empty( $new_password ) ) {
			if ( empty( $current_password ) ) {
				return new WP_REST_Response(
					array(
						'success'    => false,
						'error_code' => 'CURRENT_PASSWORD_REQUIRED',
						'message'    => __( 'Current password is required to set a new password.', 'nearmart' ),
						'details'    => array(),
					),
					400
				);
			}

			if ( ! wp_check_password( $current_password, $user->user_pass, $user->ID ) ) {
				return new WP_REST_Response(
					array(
						'success'    => false,
						'error_code' => 'INVALID_CURRENT_PASSWORD',
						'message'    => __( 'Current password is incorrect.', 'nearmart' ),
						'details'    => array(),
					),
					400
				);
			}

			if ( strlen( $new_password ) < 6 ) {
				return new WP_REST_Response(
					array(
						'success'    => false,
						'error_code' => 'WEAK_PASSWORD',
						'message'    => __( 'New password must be at least 6 characters long.', 'nearmart' ),
						'details'    => array(),
					),
					400
				);
			}

			$update_args['user_pass'] = $new_password;
		}

		$result = wp_update_user( $update_args );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'error_code' => 'PROFILE_UPDATE_FAILED',
					'message'    => $result->get_error_message(),
					'details'    => array(),
				),
				500
			);
		}

		// Update Phone
		if ( null !== $phone ) {
			update_user_meta( $user_id, 'billing_phone', trim( $phone ) );
			update_user_meta( $user_id, 'som_phone', trim( $phone ) );
		}

		// If password was changed, issue new token
		$token_response = null;
		if ( ! empty( $new_password ) ) {
			self::invalidate_user_tokens( $user_id );
			$token_response = self::generate_token( $user_id );
		}

		$response_data = array(
			'user' => self::format_user_data( $user_id ),
		);

		if ( $token_response ) {
			$response_data['token']      = $token_response['token'];
			$response_data['expires_at'] = $token_response['expires_at'];
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $response_data,
				'message' => __( 'Profile updated successfully.', 'nearmart' ),
			),
			200
		);
	}
}