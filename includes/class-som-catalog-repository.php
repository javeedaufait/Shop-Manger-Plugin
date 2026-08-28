<?php
/**
 * Shop Catalog Repository & Database Layer.
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Catalog_Repository
 *
 * Manages custom table `wp_nearmart_shop_products` for shop-specific product catalog data.
 */
class SOM_Catalog_Repository {

	/**
	 * Get full table name with prefix.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'nearmart_shop_products';
	}

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Hook table creation on plugin activation or theme setup if needed.
	}

	/**
	 * Create or upgrade the custom table `wp_nearmart_shop_products` using dbDelta.
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			shop_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			price decimal(10,2) NOT NULL DEFAULT '0.00',
			sale_price decimal(10,2) DEFAULT NULL,
			stock_quantity int(11) DEFAULT NULL,
			stock_status varchar(20) NOT NULL DEFAULT 'instock',
			status varchar(20) NOT NULL DEFAULT 'active',
			shop_sku varchar(100) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY shop_product (shop_id, product_id),
			KEY shop_id (shop_id),
			KEY product_id (product_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Check whether a product exists in a shop's catalog.
	 *
	 * @param int $shop_id    Shop CPT Post ID.
	 * @param int $product_id WooCommerce Product Post ID.
	 * @return bool
	 */
	public static function has_shop_product( $shop_id, $product_id ) {
		global $wpdb;

		$shop_id    = absint( $shop_id );
		$product_id = absint( $product_id );

		if ( ! $shop_id || ! $product_id ) {
			return false;
		}

		$table_name = self::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE shop_id = %d AND product_id = %d",
				$shop_id,
				$product_id
			)
		);

		return ( (int) $count ) > 0;
	}

	/**
	 * Get a single shop product entry.
	 *
	 * @param int $shop_id    Shop CPT Post ID.
	 * @param int $product_id WooCommerce Product Post ID.
	 * @return object|null Database row object or null.
	 */
	public static function get_shop_product( $shop_id, $product_id ) {
		global $wpdb;

		$shop_id    = absint( $shop_id );
		$product_id = absint( $product_id );

		if ( ! $shop_id || ! $product_id ) {
			return null;
		}

		$table_name = self::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE shop_id = %d AND product_id = %d LIMIT 1",
				$shop_id,
				$product_id
			)
		);
	}

	/**
	 * Add a product to a shop catalog.
	 *
	 * @param int   $shop_id    Shop CPT Post ID.
	 * @param int   $product_id WooCommerce Product Post ID.
	 * @param array $data       Price, stock, and status data.
	 * @return int|bool Row ID on success, or false on failure.
	 */
	public static function add_product_to_shop( $shop_id, $product_id, $data = array() ) {
		global $wpdb;

		$shop_id    = absint( $shop_id );
		$product_id = absint( $product_id );

		if ( ! $shop_id || ! $product_id ) {
			return false;
		}

		// If product already exists, fail or update.
		if ( self::has_shop_product( $shop_id, $product_id ) ) {
			return self::update_shop_product( $shop_id, $product_id, $data );
		}

		$table_name = self::get_table_name();

		$price          = isset( $data['price'] ) ? floatval( $data['price'] ) : 0.00;
		$sale_price     = ( isset( $data['sale_price'] ) && '' !== $data['sale_price'] && null !== $data['sale_price'] ) ? floatval( $data['sale_price'] ) : null;
		$stock_quantity = ( isset( $data['stock_quantity'] ) && '' !== $data['stock_quantity'] && null !== $data['stock_quantity'] ) ? intval( $data['stock_quantity'] ) : null;
		$stock_status   = isset( $data['stock_status'] ) ? sanitize_key( $data['stock_status'] ) : 'instock';
		$status         = isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'active';
		$shop_sku       = isset( $data['shop_sku'] ) ? sanitize_text_field( $data['shop_sku'] ) : null;

		$insert_data = array(
			'shop_id'        => $shop_id,
			'product_id'     => $product_id,
			'price'          => $price,
			'sale_price'     => $sale_price,
			'stock_quantity' => $stock_quantity,
			'stock_status'   => $stock_status,
			'status'         => $status,
			'shop_sku'       => $shop_sku,
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		);

		$format = array(
			'%d', // shop_id
			'%d', // product_id
			'%f', // price
			null === $sale_price ? '%s' : '%f', // sale_price
			null === $stock_quantity ? '%s' : '%d', // stock_quantity
			'%s', // stock_status
			'%s', // status
			null === $shop_sku ? '%s' : '%s', // shop_sku
			'%s', // created_at
			'%s', // updated_at
		);

		$result = $wpdb->insert( $table_name, $insert_data, $format );

		if ( false === $result ) {
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Update a product entry in a shop's catalog.
	 *
	 * @param int   $shop_id    Shop CPT Post ID.
	 * @param int   $product_id WooCommerce Product Post ID.
	 * @param array $data       Data fields to update.
	 * @return bool True on success or no changes, false on error.
	 */
	public static function update_shop_product( $shop_id, $product_id, $data = array() ) {
		global $wpdb;

		$shop_id    = absint( $shop_id );
		$product_id = absint( $product_id );

		if ( ! $shop_id || ! $product_id ) {
			return false;
		}

		if ( ! self::has_shop_product( $shop_id, $product_id ) ) {
			return false;
		}

		$table_name  = self::get_table_name();
		$update_data = array();
		$format      = array();

		if ( isset( $data['price'] ) ) {
			$update_data['price'] = floatval( $data['price'] );
			$format[]             = '%f';
		}

		if ( array_key_exists( 'sale_price', $data ) ) {
			$update_data['sale_price'] = ( '' !== $data['sale_price'] && null !== $data['sale_price'] ) ? floatval( $data['sale_price'] ) : null;
			$format[]                  = null === $update_data['sale_price'] ? '%s' : '%f';
		}

		if ( array_key_exists( 'stock_quantity', $data ) ) {
			$update_data['stock_quantity'] = ( '' !== $data['stock_quantity'] && null !== $data['stock_quantity'] ) ? intval( $data['stock_quantity'] ) : null;
			$format[]                      = null === $update_data['stock_quantity'] ? '%s' : '%d';
		}

		if ( isset( $data['stock_status'] ) ) {
			$update_data['stock_status'] = sanitize_key( $data['stock_status'] );
			$format[]                    = '%s';
		}

		if ( isset( $data['status'] ) ) {
			$update_data['status'] = sanitize_key( $data['status'] );
			$format[]              = '%s';
		}

		if ( array_key_exists( 'shop_sku', $data ) ) {
			$update_data['shop_sku'] = ( '' !== $data['shop_sku'] && null !== $data['shop_sku'] ) ? sanitize_text_field( $data['shop_sku'] ) : null;
			$format[]                = null === $update_data['shop_sku'] ? '%s' : '%s';
		}

		if ( empty( $update_data ) ) {
			return true;
		}

		$update_data['updated_at'] = current_time( 'mysql' );
		$format[]                  = '%s';

		$where        = array(
			'shop_id'    => $shop_id,
			'product_id' => $product_id,
		);
		$where_format = array( '%d', '%d' );

		$result = $wpdb->update( $table_name, $update_data, $where, $format, $where_format );

		return false !== $result;
	}

	/**
	 * Remove a product from a shop catalog.
	 *
	 * @param int $shop_id    Shop CPT Post ID.
	 * @param int $product_id WooCommerce Product Post ID.
	 * @return bool True on success, false on failure.
	 */
	public static function remove_product_from_shop( $shop_id, $product_id ) {
		global $wpdb;

		$shop_id    = absint( $shop_id );
		$product_id = absint( $product_id );

		if ( ! $shop_id || ! $product_id ) {
			return false;
		}

		$table_name = self::get_table_name();
		$result     = $wpdb->delete(
			$table_name,
			array(
				'shop_id'    => $shop_id,
				'product_id' => $product_id,
			),
			array( '%d', '%d' )
		);

		return false !== $result;
	}

	/**
	 * Get products belonging to a shop.
	 *
	 * @param int   $shop_id Shop CPT Post ID.
	 * @param array $args    Filter and pagination arguments.
	 * @return array Array of Database row objects.
	 */
	public static function get_shop_products( $shop_id, $args = array() ) {
		global $wpdb;

		$shop_id = absint( $shop_id );
		if ( ! $shop_id ) {
			return array();
		}

		$defaults = array(
			'status'       => 'all', // 'active', 'inactive', or 'all'.
			'stock_status' => 'all', // 'instock', 'outofstock', or 'all'.
			'limit'        => 20,
			'offset'       => 0,
			'orderby'      => 'created_at',
			'order'        => 'DESC',
		);

		$parsed = wp_parse_args( $args, $defaults );

		$table_name = self::get_table_name();
		$where      = array( 'shop_id = %d' );
		$values     = array( $shop_id );

		if ( 'all' !== $parsed['status'] && ! empty( $parsed['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = sanitize_key( $parsed['status'] );
		}

		if ( 'all' !== $parsed['stock_status'] && ! empty( $parsed['stock_status'] ) ) {
			$where[]  = 'stock_status = %s';
			$values[] = sanitize_key( $parsed['stock_status'] );
		}

		$where_clause = implode( ' AND ', $where );

		$allowed_orderby = array( 'id', 'product_id', 'price', 'stock_quantity', 'created_at', 'updated_at' );
		$orderby         = in_array( strtolower( $parsed['orderby'] ), $allowed_orderby, true ) ? strtolower( $parsed['orderby'] ) : 'created_at';
		$order           = 'ASC' === strtoupper( $parsed['order'] ) ? 'ASC' : 'DESC';

		$limit_clause = '';
		$limit        = intval( $parsed['limit'] );
		$offset       = intval( $parsed['offset'] );

		if ( $limit > 0 ) {
			$limit_clause = $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
		}

		$sql = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY {$orderby} {$order}{$limit_clause}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Get summary metrics for a shop catalog (Total, Active, Out-of-Stock).
	 *
	 * @param int $shop_id Shop CPT Post ID.
	 * @return array
	 */
	public static function get_shop_catalog_summary( $shop_id ) {
		global $wpdb;

		$shop_id = absint( $shop_id );
		if ( ! $shop_id ) {
			return array(
				'total'      => 0,
				'active'     => 0,
				'outofstock' => 0,
			);
		}

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE shop_id = %d", $shop_id ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE shop_id = %d AND status = 'active'", $shop_id ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$outofstock = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE shop_id = %d AND stock_status = 'outofstock'", $shop_id ) );

		return array(
			'total'      => $total,
			'active'     => $active,
			'outofstock' => $outofstock,
		);
	}
}

/* ==========================================================================
   GLOBAL PROCEDURAL HELPER FUNCTIONS
   ========================================================================== */

if ( ! function_exists( 'nearmart_add_shop_product' ) ) {
	/**
	 * Add product to shop catalog helper.
	 */
	function nearmart_add_shop_product( $shop_id, $product_id, $data = array() ) {
		return SOM_Catalog_Repository::add_product_to_shop( $shop_id, $product_id, $data );
	}
}

if ( ! function_exists( 'nearmart_update_shop_product' ) ) {
	/**
	 * Update shop product helper.
	 */
	function nearmart_update_shop_product( $shop_id, $product_id, $data = array() ) {
		return SOM_Catalog_Repository::update_shop_product( $shop_id, $product_id, $data );
	}
}

if ( ! function_exists( 'nearmart_remove_shop_product' ) ) {
	/**
	 * Remove shop product helper.
	 */
	function nearmart_remove_shop_product( $shop_id, $product_id ) {
		return SOM_Catalog_Repository::remove_product_from_shop( $shop_id, $product_id );
	}
}

if ( ! function_exists( 'nearmart_has_shop_product' ) ) {
	/**
	 * Check shop product existence helper.
	 */
	function nearmart_has_shop_product( $shop_id, $product_id ) {
		return SOM_Catalog_Repository::has_shop_product( $shop_id, $product_id );
	}
}

if ( ! function_exists( 'nearmart_get_shop_product' ) ) {
	/**
	 * Get single shop product helper.
	 */
	function nearmart_get_shop_product( $shop_id, $product_id ) {
		return SOM_Catalog_Repository::get_shop_product( $shop_id, $product_id );
	}
}

if ( ! function_exists( 'nearmart_get_shop_products' ) ) {
	/**
	 * Get products belonging to a shop helper.
	 */
	function nearmart_get_shop_products( $shop_id, $args = array() ) {
		return SOM_Catalog_Repository::get_shop_products( $shop_id, $args );
	}
}

if ( ! function_exists( 'nearmart_get_shop_catalog_summary' ) ) {
	/**
	 * Get shop catalog summary helper.
	 */
	function nearmart_get_shop_catalog_summary( $shop_id ) {
		return SOM_Catalog_Repository::get_shop_catalog_summary( $shop_id );
	}
}