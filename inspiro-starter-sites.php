<?php
/**
 * WPZOOM Inspiro Starter Sites
 *
 * @package   Inspiro Starter Sites
 * @author    WPZOOM
 * @copyright 2024 WPZOOM
 * @license   GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name: Inspiro Starter Sites
 * Plugin URI:  https://www.wpzoom.com/plugins//
 * Description: Import starter templates with Gutenberg Blocks, Elementor, and WooCommerce to create a new website in just a few clicks.
 * Author:      WPZOOM
 * Author URI:  https://www.wpzoom.com
 * Text Domain: inspiro-starter-sites
 * Version:     1.0.12
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'INSPIRO_STARTER_SITES_VERSION' ) ) {
	define( 'INSPIRO_STARTER_SITES_VERSION', get_file_data( __FILE__, [ 'Version' ] )[0] ); // phpcs:ignore
}

// settings page url attribute
define( 'INSPIRO_STARTER_SITES_SETTINGS_PAGE', 'inspiro-starter-sites-demo-import' );

define( 'INSPIRO_STARTER_SITES__FILE__', __FILE__ );
define( 'INSPIRO_STARTER_SITES_PLUGIN_BASE', plugin_basename( INSPIRO_STARTER_SITES__FILE__ ) );
define( 'INSPIRO_STARTER_SITES_PLUGIN_DIR', dirname( INSPIRO_STARTER_SITES_PLUGIN_BASE ) );

define( 'INSPIRO_STARTER_SITES_PATH', plugin_dir_path( INSPIRO_STARTER_SITES__FILE__ ) );
define( 'INSPIRO_STARTER_SITES_URL', plugin_dir_url( INSPIRO_STARTER_SITES__FILE__ ) );

// Define the UTM code for the footer menu
define( 'INSPIRO_STARTER_SITES_MARKETING_UTM_CODE_FOOTER_MENU', '?utm_source=inspiro-starter-sites&utm_medium=footer-menu&utm_campaign=inspiro-starter-sites' );

// Load the activator class
require_once INSPIRO_STARTER_SITES_PATH . 'classes/class-inspiro-starter-sites-activator.php';

// Load the plugin after the theme is loaded
add_action( 'plugins_loaded', 'inspiro_starter_sites_classes' );

/**
 * Load the plugin
 */
function inspiro_starter_sites_classes() {

	// Load the plugin classes
	require_once INSPIRO_STARTER_SITES_PATH . 'classes/class-inspiro-starter-sites-admin-menu.php';
	require_once INSPIRO_STARTER_SITES_PATH . 'classes/class-inspiro-starter-sites-admin-helpers.php';	

	// Load the demo importer class
	require_once INSPIRO_STARTER_SITES_PATH . 'components/importer/class-inspiro-starter-sites-importer.php';
	require_once INSPIRO_STARTER_SITES_PATH . 'components/importer/class-inspiro-starter-sites-importer-setup.php';

	// Load the starter content notice
	require_once INSPIRO_STARTER_SITES_PATH . 'components/starter-content-notice.php';

}