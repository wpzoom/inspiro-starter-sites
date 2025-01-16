<?php
/**
 * WPZOOM Inspiro Toolkit
 *
 * @package   WPZOOM Inspiro Toolkit
 * @author    WPZOOM
 * @copyright 2024 WPZOOM
 * @license   GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name: WPZOOM Inspiro Toolkit
 * Plugin URI:  https://www.wpzoom.com/plugins/inspiro-toolkit/
 * Description: This plugin adds the required functionality to the Inspiro theme.
 * Author:      WPZOOM
 * Author URI:  https://www.wpzoom.com
 * Text Domain: inspiro-toolkit
 * Version:     1.0.0
 * License:     GPL2+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'INSPIRO_TOOLKIT_VERSION' ) ) {
	define( 'INSPIRO_TOOLKIT_VERSION', get_file_data( __FILE__, [ 'Version' ] )[0] ); // phpcs:ignore
}

// settings page url attribute
define( 'INSPIRO_TOOLKIT_SETTINGS_PAGE', 'inspiro-toolkit-demo-import' );

define( 'INSPIRO_TOOLKIT__FILE__', __FILE__ );
define( 'INSPIRO_TOOLKIT_PLUGIN_BASE', plugin_basename( INSPIRO_TOOLKIT__FILE__ ) );
define( 'INSPIRO_TOOLKIT_PLUGIN_DIR', dirname( INSPIRO_TOOLKIT_PLUGIN_BASE ) );

define( 'INSPIRO_TOOLKIT_PATH', plugin_dir_path( INSPIRO_TOOLKIT__FILE__ ) );
define( 'INSPIRO_TOOLKIT_URL', plugin_dir_url( INSPIRO_TOOLKIT__FILE__ ) );

// Define the UTM code for the footer menu
define( 'INSPIRO_TOOLKIT_MARKETING_UTM_CODE_FOOTER_MENU', '?utm_source=inspiro-toolkit&utm_medium=footer-menu&utm_campaign=inspiro-toolkit' );

// Load the activator class
require_once INSPIRO_TOOLKIT_PATH . 'classes/class-inspiro-toolkit-activator.php';

// Load the plugin after the theme is loaded
add_action( 'plugins_loaded', 'inspiro_toolkit_load' );

/**
 * Load the plugin
 */
function inspiro_toolkit_load() {

	// Load the plugin classes
	require_once INSPIRO_TOOLKIT_PATH . 'classes/class-inspiro-toolkit-admin-menu.php';
	require_once INSPIRO_TOOLKIT_PATH . 'classes/class-inspiro-toolkit-admin-helpers.php';	

}

// Load after the plugin is loaded
add_action( 'plugins_loaded', 'inspiro_toolkit_plugins_loaded' );

/**
 * Load the plugin after the theme is loaded
 */
function inspiro_toolkit_plugins_loaded() {
	
	// Load the plugin text domain
	load_plugin_textdomain( 'inspiro-toolkit', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Load the demo importer class
	require_once INSPIRO_TOOLKIT_PATH . 'components/importer/class-inspiro-toolkit-importer.php';
	require_once INSPIRO_TOOLKIT_PATH . 'components/importer/class-inspiro-toolkit-importer-setup.php';
}