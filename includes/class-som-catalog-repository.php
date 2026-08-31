<?php
/**
 * Shop Catalog Repository & Database Layer (Phase 1 HYBRID Catalog).
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
 * Supports both WooCommerce Master-Linked Products (product_id set) and Standalone Products (product_id = NULL).
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
		// Initialization hooks.
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
			product_id bigint(20) unsigned DEFAULT NULL,
			custom_name varchar(255) DEFAULT NULL,
			custom_category varchar(100) DEFAULT NULL,
			custom_brand varchar(100) DEFAULT NULL,
			custom_unit varchar(100) DEFAULT NULL,
			custom_barcode varchar(100) DEFAULT NULL,
			custom_image_id bigint(20) unsigned DEFAULT NULL,
			price decimal(10,2) NOT NULL DEFAULT '0.00',
			sale_price decimal(10,2) DEFAULT NULL,
			stock_quantity int(11) DEFAULT NULL,
			stock_status varchar(20) NOT NULL DEFAULT 'instock',
			status varchar(20) NOT NULL DEFAULT 'active',
			shop_sku varchar(100) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY shop_id (shop_id),
			KEY product_id (product_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Run custom column & nullability migration for existing tables
		self::run_table_migrations();
	}

	/**
	 * Run migrations for existing installations to ensure product_id is NULLable and custom columns exist.
	 */
	public static function run_table_migrations() {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$col_info = $wpdb->get_row( "SHOW COLUMNS FROM {$table_name} LIKE 'product_id'" );
		if ( $col_info && 'NO' === strtoupper( $col_info->Null ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table_name} MODIFY product_id bigint(20) unsigned DEFAULT NULL" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$custom_name_info = $wpdb->get_row( "SHOW COLUMNS FROM {$table_name} LIKE 'custom_name'" );
		if ( ! $custom_name_info ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"ALTER TABLE {$table_name}
				ADD COLUMN custom_name varchar(255) DEFAULT NULL AFTER product_id,
				ADD COLUMN custom_category varchar(100) DEFAULT NULL AFTER custom_name,
				ADD COLUMN custom_brand varchar(100) DEFAULT NULL AFTER custom_category,
				ADD COLUMN custom_unit varchar(100) DEFAULT NULL AFTER custom_brand,
				ADD COLUMN custom_barcode varchar(100) DEFAULT NULL AFTER custom_unit,
				ADD COLUMN custom_image_id bigint(20) unsigned DEFAULT NULL AFTER custom_barcode"
			);
		}
	}

	/**
	 * Check whether a master-linked product exists in a shop's catalog.
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
	 * Get a single master-linked shop product entry.
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
	 * Get a single shop product entry by row primary key ID.
	 *
	 * @param int $id Database row ID.
	 * @return object|null Database row object or null.
	 */
	public static function get_shop_product_by_id( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}
		$table_name = self::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d LIMIT 1", $id ) );
	}

	/**
	 * Add a WooCommerce Master-Linked product to a shop catalog.
	 *
	 * @param int   $shop_id    Shop CPT Post ID.
	 * @param int   $product_id WooCommerce Product Post ID.
	 * @param array $data       Price, stock, and shop SKU details.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public static function add_shop_product( $shop_id, $product_id, $data = array() ) {
		global $wpdb;

		$shop_id    = absint( $shop_id );
		$product_id = absint( $product_id );

		if ( ! $shop_id || ! $product_id ) {
			return false;
		}

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
			'%d',
			'%d',
			'%f',
			null === $sale_price ? '%s' : '%f',
			null === $stock_quantity ? '%s' : '%d',
			'%s',
			'%s',
			null === $shop_sku ? '%s' : '%s',
			'%s',
			'%s',
		);

		$result = $wpdb->insert( $table_name, $insert_data, $format );

		return false !== $result ? $wpdb->insert_id : false;
	}

	/**
	 * Add a Standalone shop product to a shop catalog (product_id = NULL).
	 *
	 * @param int   $shop_id Shop CPT Post ID.
	 * @param array $data    Custom title, category, brand, unit, barcode, price, stock, SKU.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public static function add_standalone_shop_product( $shop_id, $data = array() ) {
		global $wpdb;

		$shop_id     = absint( $shop_id );
		$custom_name = isset( $data['custom_name'] ) ? sanitize_text_field( $data['custom_name'] ) : '';

		if ( ! $shop_id || empty( $custom_name ) ) {
			return false;
		}

		$table_name = self::get_table_name();

		$price           = isset( $data['price'] ) ? floatval( $data['price'] ) : 0.00;
		$sale_price      = ( isset( $data['sale_price'] ) && '' !== $data['sale_price'] && null !== $data['sale_price'] ) ? floatval( $data['sale_price'] ) : null;
		$stock_quantity  = ( isset( $data['stock_quantity'] ) && '' !== $data['stock_quantity'] && null !== $data['stock_quantity'] ) ? intval( $data['stock_quantity'] ) : null;
		$stock_status    = isset( $data['stock_status'] ) ? sanitize_key( $data['stock_status'] ) : 'instock';
		$status          = isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'active';
		$shop_sku        = isset( $data['shop_sku'] ) ? sanitize_text_field( $data['shop_sku'] ) : null;
		$custom_category = isset( $data['custom_category'] ) ? sanitize_text_field( $data['custom_category'] ) : null;
		$custom_brand    = isset( $data['custom_brand'] ) ? sanitize_text_field( $data['custom_brand'] ) : null;
		$custom_unit     = isset( $data['custom_unit'] ) ? sanitize_text_field( $data['custom_unit'] ) : null;
		$custom_barcode  = isset( $data['custom_barcode'] ) ? sanitize_text_field( $data['custom_barcode'] ) : null;
		$custom_image_id = isset( $data['custom_image_id'] ) ? absint( $data['custom_image_id'] ) : null;

		$insert_data = array(
			'shop_id'         => $shop_id,
			'product_id'      => null,
			'custom_name'     => $custom_name,
			'custom_category' => $custom_category,
			'custom_brand'    => $custom_brand,
			'custom_unit'     => $custom_unit,
			'custom_barcode'  => $custom_barcode,
			'custom_image_id' => $custom_image_id,
			'price'           => $price,
			'sale_price'      => $sale_price,
			'stock_quantity'  => $stock_quantity,
			'stock_status'    => $stock_status,
			'status'          => $status,
			'shop_sku'        => $shop_sku,
			'created_at'      => current_time( 'mysql' ),
			'updated_at'      => current_time( 'mysql' ),
		);

		$format = array(
			'%d',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			null === $custom_image_id ? '%s' : '%d',
			'%f',
			null === $sale_price ? '%s' : '%f',
			null === $stock_quantity ? '%s' : '%d',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
		);

		$result = $wpdb->insert( $table_name, $insert_data, $format );

		return false !== $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update a product entry in a shop's catalog by shop_id and product_id.
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

		$row = self::get_shop_product( $shop_id, $product_id );
		if ( ! $row ) {
			return false;
		}

		return self::update_shop_product_by_id( $row->id, $data );
	}

	/**
	 * Update shop product by row primary key ID.
	 *
	 * @param int   $id   Row primary key ID.
	 * @param array $data Fields to update.
	 * @return bool
	 */
	public static function update_shop_product_by_id( $id, $data = array() ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$table_name  = self::get_table_name();
		$update_data = array();
		$format      = array();

		if ( array_key_exists( 'product_id', $data ) ) {
			$update_data['product_id'] = $data['product_id'] ? absint( $data['product_id'] ) : null;
			$format[]                  = null === $update_data['product_id'] ? '%s' : '%d';
		}

		if ( isset( $data['custom_name'] ) ) {
			$update_data['custom_name'] = sanitize_text_field( $data['custom_name'] );
			$format[]                   = '%s';
		}
		if ( array_key_exists( 'custom_category', $data ) ) {
			$update_data['custom_category'] = '' !== $data['custom_category'] && null !== $data['custom_category'] ? sanitize_text_field( $data['custom_category'] ) : null;
			$format[]                      = null === $update_data['custom_category'] ? '%s' : '%s';
		}
		if ( array_key_exists( 'custom_brand', $data ) ) {
			$update_data['custom_brand'] = '' !== $data['custom_brand'] && null !== $data['custom_brand'] ? sanitize_text_field( $data['custom_brand'] ) : null;
			$format[]                    = null === $update_data['custom_brand'] ? '%s' : '%s';
		}
		if ( array_key_exists( 'custom_unit', $data ) ) {
			$update_data['custom_unit'] = '' !== $data['custom_unit'] && null !== $data['custom_unit'] ? sanitize_text_field( $data['custom_unit'] ) : null;
			$format[]                   = null === $update_data['custom_unit'] ? '%s' : '%s';
		}
		if ( array_key_exists( 'custom_barcode', $data ) ) {
			$update_data['custom_barcode'] = '' !== $data['custom_barcode'] && null !== $data['custom_barcode'] ? sanitize_text_field( $data['custom_barcode'] ) : null;
			$format[]                      = null === $update_data['custom_barcode'] ? '%s' : '%s';
		}
		if ( array_key_exists( 'custom_image_id', $data ) ) {
			$update_data['custom_image_id'] = $data['custom_image_id'] ? absint( $data['custom_image_id'] ) : null;
			$format[]                       = null === $update_data['custom_image_id'] ? '%s' : '%d';
		}
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

		$result = $wpdb->update( $table_name, $update_data, array( 'id' => $id ), $format, array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Remove a master-linked product from a shop catalog.
	 *
	 * @param int $shop_id    Shop CPT Post ID.
	 * @param int $product_id WooCommerce Product Post ID.
	 * @return bool
	 */
	public static function remove_product_from_shop( $shop_id, $product_id ) {
		global $wpdb;

		$shop_id    = absint( $shop_id );
		$product_id = absint( $product_id );

		if ( ! $shop_id || ! $product_id ) {
			return false;
		}

		$row = self::get_shop_product( $shop_id, $product_id );
		if ( ! $row ) {
			return false;
		}

		return self::remove_shop_product_by_id( $row->id );
	}

	/**
	 * Remove shop product entry by row primary key ID.
	 *
	 * @param int $id Row primary key ID.
	 * @return bool
	 */
	public static function remove_shop_product_by_id( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$table_name = self::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );

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
			'status'       => 'all',
			'stock_status' => 'all',
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
		} else {
			$where[]  = "status != 'pending_setup' AND status != 'deleted'";
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

	/**
	 * Unified Catalog Item Formatter: Normalizes output for both master-linked and standalone products.
	 *
	 * @param object $row Database row object from wp_nearmart_shop_products.
	 * @return array|null Formatted array or null if empty.
	 */
	public static function format_catalog_item( $row ) {
		if ( ! $row ) {
			return null;
		}

		$is_standalone = empty( $row->product_id );
		$title         = '';
		$category      = '';
		$brand         = '';
		$unit          = '';
		$barcode       = '';
		$master_sku    = '';
		$thumb_url     = '';

		if ( ! $is_standalone ) {
			$master_post = get_post( $row->product_id );
			if ( $master_post && 'product' === $master_post->post_type ) {
				$title     = SOM_Master_Product::get_localized_title( $row->product_id );
				$cat_terms = wp_get_post_terms( $row->product_id, 'product_cat' );
				if ( ! is_wp_error( $cat_terms ) && ! empty( $cat_terms ) ) {
					$category = SOM_Master_Product::get_localized_category_name( $cat_terms[0] );
				} else {
					$category = __( 'Uncategorized', 'nearmart' );
				}
				$specs      = nearmart_get_master_product_specs( $row->product_id );
				$brand      = $specs['brand_name'] ? $specs['brand_name'] : '';
				$unit       = $specs['unit'] ? $specs['unit'] : '';
				$barcode    = $specs['barcode'] ? $specs['barcode'] : '';
				$master_sku = $specs['sku'] ? $specs['sku'] : '';
				$thumb_url  = get_the_post_thumbnail_url( $row->product_id, 'thumbnail' );
			}
		}

		// Fallback to custom fields for standalone or missing master posts
		if ( empty( $title ) ) {
			$title = ! empty( $row->custom_name ) ? $row->custom_name : __( 'Unnamed Product', 'nearmart' );
		}
		if ( empty( $category ) ) {
			$category = ! empty( $row->custom_category ) ? $row->custom_category : __( 'Uncategorized', 'nearmart' );
		}
		if ( empty( $brand ) ) {
			$brand = ! empty( $row->custom_brand ) ? $row->custom_brand : '';
		}
		if ( empty( $unit ) ) {
			$unit = ! empty( $row->custom_unit ) ? $row->custom_unit : '';
		}
		if ( empty( $barcode ) ) {
			$barcode = ! empty( $row->custom_barcode ) ? $row->custom_barcode : '';
		}
		if ( ! empty( $row->custom_image_id ) ) {
			$custom_thumb = wp_get_attachment_image_url( $row->custom_image_id, 'thumbnail' );
			if ( $custom_thumb ) {
				$thumb_url = $custom_thumb;
			}
		}

		return array(
			'id'             => (int) $row->id,
			'shop_id'        => (int) $row->shop_id,
			'product_id'     => $row->product_id ? (int) $row->product_id : null,
			'is_standalone'  => $is_standalone,
			'title'          => $title,
			'category'       => $category,
			'brand'          => $brand,
			'unit'           => $unit,
			'barcode'        => $barcode,
			'master_sku'     => $master_sku,
			'thumb_url'      => $thumb_url ? $thumb_url : '',
			'price'          => number_format( (float) $row->price, 2, '.', '' ),
			'sale_price'     => null !== $row->sale_price && '' !== $row->sale_price ? number_format( (float) $row->sale_price, 2, '.', '' ) : null,
			'stock_quantity' => null !== $row->stock_quantity ? (int) $row->stock_quantity : null,
			'stock_status'   => $row->stock_status,
			'status'         => $row->status,
			'shop_sku'       => $row->shop_sku ? $row->shop_sku : '',
			'created_at'     => $row->created_at,
			'updated_at'     => $row->updated_at,
		);
	}
}

/* Procedural Global Helpers */

if ( ! function_exists( 'nearmart_has_shop_product' ) ) {
	function nearmart_has_shop_product( $shop_id, $product_id ) {
		return SOM_Catalog_Repository::has_shop_product( $shop_id, $product_id );
	}
}

if ( ! function_exists( 'nearmart_get_shop_product' ) ) {
	function nearmart_get_shop_product( $shop_id, $product_id ) {
		return SOM_Catalog_Repository::get_shop_product( $shop_id, $product_id );
	}
}

if ( ! function_exists( 'nearmart_add_shop_product' ) ) {
	function nearmart_add_shop_product( $shop_id, $product_id, $data = array() ) {
		return SOM_Catalog_Repository::add_shop_product( $shop_id, $product_id, $data );
	}
}

if ( ! function_exists( 'nearmart_update_shop_product' ) ) {
	function nearmart_update_shop_product( $shop_id, $product_id, $data = array() ) {
		return SOM_Catalog_Repository::update_shop_product( $shop_id, $product_id, $data );
	}
}

if ( ! function_exists( 'nearmart_remove_shop_product' ) ) {
	function nearmart_remove_shop_product( $shop_id, $product_id ) {
		return SOM_Catalog_Repository::remove_product_from_shop( $shop_id, $product_id );
	}
}

if ( ! function_exists( 'nearmart_get_shop_products' ) ) {
	function nearmart_get_shop_products( $shop_id, $args = array() ) {
		return SOM_Catalog_Repository::get_shop_products( $shop_id, $args );
	}
}

if ( ! function_exists( 'nearmart_get_shop_catalog_summary' ) ) {
	function nearmart_get_shop_catalog_summary( $shop_id ) {
		return SOM_Catalog_Repository::get_shop_catalog_summary( $shop_id );
	}
}

if ( ! function_exists( 'nearmart_add_standalone_shop_product' ) ) {
	function nearmart_add_standalone_shop_product( $shop_id, $data = array() ) {
		return SOM_Catalog_Repository::add_standalone_shop_product( $shop_id, $data );
	}
}

if ( ! function_exists( 'nearmart_get_shop_product_by_id' ) ) {
	function nearmart_get_shop_product_by_id( $id ) {
		return SOM_Catalog_Repository::get_shop_product_by_id( $id );
	}
}

if ( ! function_exists( 'nearmart_update_shop_product_by_id' ) ) {
	function nearmart_update_shop_product_by_id( $id, $data = array() ) {
		return SOM_Catalog_Repository::update_shop_product_by_id( $id, $data );
	}
}

if ( ! function_exists( 'nearmart_remove_shop_product_by_id' ) ) {
	function nearmart_remove_shop_product_by_id( $id ) {
		return SOM_Catalog_Repository::remove_shop_product_by_id( $id );
	}
}

if ( ! function_exists( 'nearmart_format_catalog_item' ) ) {
	function nearmart_format_catalog_item( $row ) {
		return SOM_Catalog_Repository::format_catalog_item( $row );
	}
}