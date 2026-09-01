<?php
/**
 * Core Plugin Initialization Class.
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Plugin
 */
class SOM_Plugin {

	/**
	 * Single instance of the class.
	 *
	 * @var SOM_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get single instance of the class.
	 *
	 * @return SOM_Plugin
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Register actions and hooks.
	 */
	private function init_hooks() {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ), 5 );
		add_filter( 'plugin_locale', array( __CLASS__, 'filter_plugin_locale' ), 10, 2 );
		add_filter( 'determine_locale', array( __CLASS__, 'filter_determine_locale' ), 10, 1 );
		SOM_Post_Types::init();
		SOM_Taxonomies::init();
		SOM_Roles::init();
		SOM_Shop_Meta::init();
		SOM_Merchant_Manager::init();
		SOM_Merchant_Dashboard::init();
		SOM_Merchant_Catalog::init();
		SOM_Form_Handler::init();
		SOM_Admin_Manager::init();
		SOM_Admin_Catalog::init();
		SOM_Product_Request_Repository::init();
		SOM_Admin_Product_Requests::init();
		SOM_REST_API::init();
		SOM_Catalog_Repository::init();
		SOM_Master_Product::init();
		SOM_Catalog_Permissions::init();

		add_action( 'init', array( __CLASS__, 'register_catalog_rewrites' ) );
	}

		/**
	 * Load plugin textdomain for i18n support.
	 */
	public static function load_textdomain() {
		$locale = 'en_US';

		if ( function_exists( 'pll_current_language' ) ) {
			$cur_pll = pll_current_language();
			if ( 'ml' === $cur_pll ) {
				$locale = 'ml_IN';
			} elseif ( 'en' === $cur_pll ) {
				$locale = 'en_US';
			}
		} elseif ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
			$pref    = get_user_meta( $user_id, 'nm_preferred_language', true );
			if ( 'ml' === $pref ) {
				$locale = 'ml_IN';
			}
		}

		unload_textdomain( 'nearmart' );
		if ( 'ml_IN' === $locale ) {
			load_textdomain( 'nearmart', SOM_PLUGIN_DIR . 'languages/nearmart-ml_IN.mo' );
		} else {
			load_plugin_textdomain( 'nearmart', false, dirname( plugin_basename( SOM_PLUGIN_FILE ) ) . '/languages' );
		}
	}

	public static function filter_plugin_locale( $locale, $domain ) {
		if ( 'nearmart' === $domain ) {
			if ( function_exists( 'pll_current_language' ) ) {
				$cur_pll = pll_current_language();
				if ( 'ml' === $cur_pll ) {
					return 'ml_IN';
				} elseif ( 'en' === $cur_pll ) {
					return 'en_US';
				}
			}

			if ( is_user_logged_in() ) {
				$user_id = get_current_user_id();
				$pref    = get_user_meta( $user_id, 'nm_preferred_language', true );
				if ( 'ml' === $pref ) {
					return 'ml_IN';
				}
			}
		}
		return $locale;
	}

	public static function filter_determine_locale( $locale ) {
		if ( function_exists( 'pll_current_language' ) ) {
			$cur_pll = pll_current_language();
			if ( 'ml' === $cur_pll ) {
				return 'ml_IN';
			} elseif ( 'en' === $cur_pll ) {
				return 'en_US';
			}
		}

		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
			$pref    = get_user_meta( $user_id, 'nm_preferred_language', true );
			if ( 'ml' === $pref ) {
				return 'ml_IN';
			}
		}
		return $locale;
	}

	/**
	 * Register rewrite alias for /merchant/catalog/ -> merchant-catalog page.
	 */
	public static function register_catalog_rewrites() {
		add_rewrite_rule( '^merchant/catalog/?$', 'index.php?pagename=merchant-catalog', 'top' );
	}

	/**
	 * Plugin activation logic.
	 */
	public static function activate() {
		// Register CPT and Taxonomies so rewrites and terms work immediately.
		SOM_Post_Types::register_post_types();
		SOM_Taxonomies::register_taxonomies();

		// Seed default taxonomy terms.
		SOM_Taxonomies::seed_default_statuses();

		// Add merchant & field agent custom roles.
		SOM_Roles::register_roles();

		// Register Brand taxonomy for WooCommerce products.
		SOM_Master_Product::register_brand_taxonomy();

		// Create/Upgrade custom database table for shop catalogs.
		SOM_Catalog_Repository::create_table();
		SOM_Product_Request_Repository::create_table();

		// Auto-create pages if they do not exist.
		self::create_onboarding_page();
		self::create_merchant_login_page();
		self::create_merchant_dashboard_page();
		self::create_merchant_catalog_page();
		self::create_join_nearmart_page();

		self::register_catalog_rewrites();

		// Flush rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Ensure the /onboard-shop/ page exists with [som_onboarding_form] shortcode.
	 */
	public static function create_onboarding_page() {
		$page = get_page_by_path( 'onboard-shop' );
		if ( ! $page ) {
			wp_insert_post(
				array(
					'post_title'     => 'Onboard Shop',
					'post_name'      => 'onboard-shop',
					'post_content'   => '[som_onboarding_form]',
					'post_status'    => 'publish',
					'post_type'      => 'page',
					'comment_status' => 'closed',
				)
			);
		}
	}

	/**
	 * Ensure the /merchant-login/ page exists with [som_merchant_login] shortcode.
	 */
	public static function create_merchant_login_page() {
		$page = get_page_by_path( 'merchant-login' );
		if ( ! $page ) {
			wp_insert_post(
				array(
					'post_title'     => 'Merchant Login',
					'post_name'      => 'merchant-login',
					'post_content'   => '[som_merchant_login]',
					'post_status'    => 'publish',
					'post_type'      => 'page',
					'comment_status' => 'closed',
				)
			);
		}
	}

	/**
	 * Ensure the /merchant-dashboard/ page exists with [som_merchant_dashboard] shortcode.
	 */
	public static function create_merchant_dashboard_page() {
		$page = get_page_by_path( 'merchant-dashboard' );
		if ( ! $page ) {
			wp_insert_post(
				array(
					'post_title'     => 'Merchant Dashboard',
					'post_name'      => 'merchant-dashboard',
					'post_content'   => '[som_merchant_dashboard]',
					'post_status'    => 'publish',
					'post_type'      => 'page',
					'comment_status' => 'closed',
				)
			);
		}
	}

	/**
	 * Ensure the /merchant-catalog/ page exists with [som_merchant_catalog] shortcode.
	 */
	public static function create_merchant_catalog_page() {
		$page = get_page_by_path( 'merchant-catalog' );
		if ( ! $page ) {
			wp_insert_post(
				array(
					'post_title'     => 'My Catalog',
					'post_name'      => 'merchant-catalog',
					'post_content'   => '[som_merchant_catalog]',
					'post_status'    => 'publish',
					'post_type'      => 'page',
					'comment_status' => 'closed',
				)
			);
		}
	}

	/**
	 * Ensure the /join-nearmart/ page exists with [som_join_nearmart_page] shortcode.
	 */
	public static function create_join_nearmart_page() {
		$page = get_page_by_path( 'join-nearmart' );
		if ( ! $page ) {
			wp_insert_post(
				array(
					'post_title'     => 'Join NearMart as a Partner Shop',
					'post_name'      => 'join-nearmart',
					'post_content'   => '[som_join_nearmart_page]',
					'post_status'    => 'publish',
					'post_type'      => 'page',
					'comment_status' => 'closed',
				)
			);
		}
	}

	/**
	 * Plugin deactivation logic.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}