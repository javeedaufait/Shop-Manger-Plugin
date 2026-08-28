<?php
/**
 * Plugin Name: Shop Onboarding Manager
 * Plugin URI:  https://example.com/shop-onboarding-manager
 * Description: MVP foundation for onboarding supermarkets and grocery shops.
 * Version:     1.3.0
 * Author:      Nearmart
 * Text Domain: shop-onboarding-manager
 * Domain Path: /languages
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'SOM_VERSION', '1.3.0' );
define( 'SOM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SOM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SOM_PLUGIN_FILE', __FILE__ );

// Require module files.
require_once SOM_PLUGIN_DIR . 'includes/class-som-post-types.php';
require_once SOM_PLUGIN_DIR . 'includes/class-som-taxonomies.php';
require_once SOM_PLUGIN_DIR . 'includes/class-som-roles.php';
require_once SOM_PLUGIN_DIR . 'includes/class-som-shop-meta.php';
require_once SOM_PLUGIN_DIR . 'includes/class-som-merchant-manager.php';
require_once SOM_PLUGIN_DIR . 'includes/class-som-merchant-dashboard.php';
require_once SOM_PLUGIN_DIR . 'includes/class-som-merchant-catalog.php';
require_once SOM_PLUGIN_DIR . 'includes/class-som-form-handler.php';
require_once SOM_PLUGIN_DIR . 'includes/class-som-admin-manager.php';
require_once SOM_PLUGIN_DIR . 'includes/class-som-admin-catalog.php';
require_once SOM_PLUGIN_DIR . 'includes/class-som-catalog-repository.php';
require_once SOM_PLUGIN_DIR . 'includes/class-som-master-product.php';
require_once SOM_PLUGIN_DIR . 'includes/class-som-catalog-permissions.php';
require_once SOM_PLUGIN_DIR . 'includes/class-som-plugin.php';

/**
 * Activation callback.
 */
function som_activate_plugin() {
	SOM_Plugin::activate();
}
register_activation_hook( __FILE__, 'som_activate_plugin' );

/**
 * Deactivation callback.
 */
function som_deactivate_plugin() {
	SOM_Plugin::deactivate();
}
register_deactivation_hook( __FILE__, 'som_deactivate_plugin' );

/**
 * Initialize the plugin.
 */
function som_init_plugin() {
	SOM_Plugin::instance();
}
add_action( 'plugins_loaded', 'som_init_plugin' );