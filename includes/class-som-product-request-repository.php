<?php
/**
 * Product Request Repository & Database Layer (Phase 8).
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Product_Request_Repository
 */
class SOM_Product_Request_Repository {

	/**
	 * Get full table name with prefix.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'nearmart_product_requests';
	}

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Initialization hooks if needed.
	}

	/**
	 * Create or upgrade custom table `wp_nearmart_product_requests` using dbDelta.
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			merchant_id bigint(20) unsigned NOT NULL,
			shop_id bigint(20) unsigned NOT NULL,
			product_name varchar(255) NOT NULL,
			brand varchar(100) DEFAULT NULL,
			category varchar(100) DEFAULT NULL,
			unit varchar(100) DEFAULT NULL,
			barcode varchar(100) DEFAULT NULL,
			notes text DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			master_product_id bigint(20) unsigned DEFAULT NULL,
			admin_notes text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY shop_id (shop_id),
			KEY merchant_id (merchant_id),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Check if a similar pending or reviewed product request exists for a shop.
	 *
	 * @param int    $shop_id      Shop Post ID.
	 * @param string $product_name Requested product name.
	 * @return bool
	 */
	public static function has_pending_request( $shop_id, $product_name ) {
		global $wpdb;

		$shop_id      = absint( $shop_id );
		$product_name = sanitize_text_field( trim( $product_name ) );

		if ( ! $shop_id || empty( $product_name ) ) {
			return false;
		}

		$table_name = self::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE shop_id = %d AND LOWER(product_name) = LOWER(%s) AND status IN ('pending', 'reviewed')",
				$shop_id,
				$product_name
			)
		);

		return ( (int) $count ) > 0;
	}

	/**
	 * Create a new product request entry.
	 *
	 * @param int   $merchant_id Merchant User ID.
	 * @param int   $shop_id     Shop Post ID.
	 * @param array $data        Array of request data.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public static function create_request( $merchant_id, $shop_id, $data ) {
		global $wpdb;

		$merchant_id  = absint( $merchant_id );
		$shop_id      = absint( $shop_id );
		$product_name = isset( $data['product_name'] ) ? sanitize_text_field( $data['product_name'] ) : '';

		if ( ! $merchant_id || ! $shop_id || empty( $product_name ) ) {
			return false;
		}

		$table_name = self::get_table_name();
		$inserted   = $wpdb->insert(
			$table_name,
			array(
				'merchant_id'  => $merchant_id,
				'shop_id'      => $shop_id,
				'product_name' => $product_name,
				'brand'        => isset( $data['brand'] ) && '' !== $data['brand'] ? sanitize_text_field( $data['brand'] ) : null,
				'category'     => isset( $data['category'] ) && '' !== $data['category'] ? sanitize_text_field( $data['category'] ) : null,
				'unit'         => isset( $data['unit'] ) && '' !== $data['unit'] ? sanitize_text_field( $data['unit'] ) : null,
				'barcode'      => isset( $data['barcode'] ) && '' !== $data['barcode'] ? sanitize_text_field( $data['barcode'] ) : null,
				'notes'        => isset( $data['notes'] ) && '' !== $data['notes'] ? sanitize_textarea_field( $data['notes'] ) : null,
				'status'       => 'pending',
				'created_at'   => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $inserted ? $wpdb->insert_id : false;
	}

	/**
	 * Get product requests for a specific merchant shop.
	 *
	 * @param int $shop_id     Shop Post ID.
	 * @param int $merchant_id Merchant User ID.
	 * @return array
	 */
	public static function get_merchant_requests( $shop_id, $merchant_id ) {
		global $wpdb;

		$shop_id     = absint( $shop_id );
		$merchant_id = absint( $merchant_id );

		if ( ! $shop_id || ! $merchant_id ) {
			return array();
		}

		$table_name = self::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE shop_id = %d AND merchant_id = %d ORDER BY created_at DESC",
				$shop_id,
				$merchant_id
			)
		);

		return ! empty( $results ) ? $results : array();
	}

	/**
	 * Get all product requests for Admin with filtering.
	 *
	 * @param array $args Filter arguments.
	 * @return array
	 */
	public static function get_admin_requests( $args = array() ) {
		global $wpdb;

		$table_name = self::get_table_name();
		$status     = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'all';
		$search     = isset( $args['search'] ) ? sanitize_text_field( trim( $args['search'] ) ) : '';

		$where_clauses = array( '1=1' );
		$params        = array();

		if ( 'all' !== $status && ! empty( $status ) ) {
			$where_clauses[] = 'r.status = %s';
			$params[]        = $status;
		}

		if ( ! empty( $search ) ) {
			$search_like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where_clauses[] = '(r.product_name LIKE %s OR r.brand LIKE %s OR r.barcode LIKE %s OR p.post_title LIKE %s)';
			$params[]        = $search_like;
			$params[]        = $search_like;
			$params[]        = $search_like;
			$params[]        = $search_like;
		}

		$where_sql = implode( ' AND ', $where_clauses );

		$sql = "SELECT r.*, p.post_title AS shop_name, u.display_name AS merchant_name
			FROM {$table_name} r
			LEFT JOIN {$wpdb->posts} p ON (r.shop_id = p.ID)
			LEFT JOIN {$wpdb->users} u ON (r.merchant_id = u.ID)
			WHERE {$where_sql}
			ORDER BY r.created_at DESC";

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$results = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$results = $wpdb->get_results( $sql );
		}

		return ! empty( $results ) ? $results : array();
	}

	/**
	 * Update request status, admin notes, and linked WooCommerce master product ID.
	 *
	 * @param int    $request_id        Request ID.
	 * @param string $status            New status ('pending', 'reviewed', 'completed', 'rejected').
	 * @param string $admin_notes       Admin notes.
	 * @param int    $master_product_id Linked WooCommerce Product ID.
	 * @return bool
	 */
	public static function update_request_status( $request_id, $status, $admin_notes = '', $master_product_id = null ) {
		global $wpdb;

		$request_id        = absint( $request_id );
		$status            = sanitize_key( $status );
		$master_product_id = $master_product_id ? absint( $master_product_id ) : null;

		if ( ! $request_id || ! in_array( $status, array( 'pending', 'reviewed', 'completed', 'rejected' ), true ) ) {
			return false;
		}

		$table_name = self::get_table_name();
		$updated    = $wpdb->update(
			$table_name,
			array(
				'status'            => $status,
				'admin_notes'       => '' !== $admin_notes ? sanitize_textarea_field( $admin_notes ) : null,
				'master_product_id' => $master_product_id,
				'updated_at'        => current_time( 'mysql' ),
			),
			array( 'id' => $request_id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}
}

/* Procedural Helper Functions */

if ( ! function_exists( 'nearmart_create_product_request' ) ) {
	function nearmart_create_product_request( $merchant_id, $shop_id, $data ) {
		return SOM_Product_Request_Repository::create_request( $merchant_id, $shop_id, $data );
	}
}

if ( ! function_exists( 'nearmart_has_pending_product_request' ) ) {
	function nearmart_has_pending_product_request( $shop_id, $product_name ) {
		return SOM_Product_Request_Repository::has_pending_request( $shop_id, $product_name );
	}
}

if ( ! function_exists( 'nearmart_get_merchant_product_requests' ) ) {
	function nearmart_get_merchant_product_requests( $shop_id, $merchant_id ) {
		return SOM_Product_Request_Repository::get_merchant_requests( $shop_id, $merchant_id );
	}
}