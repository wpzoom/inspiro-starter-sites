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
 * Plugin URI:  https://www.wpzoom.com/plugins/inspiro-starter-sites/
 * Description: Import starter templates with Gutenberg Blocks, Elementor, and WooCommerce to create a new website in just a few clicks.
 * Author:      WPZOOM
 * Author URI:  https://www.wpzoom.com
 * Text Domain: inspiro-starter-sites
 * Version:     1.0.0
 * License:     GPL2+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
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


// Instance the plugin
$inspiro_starter_sites = new Inspiro_Starter_Sites_Plugin();

// Hook the plugin into WordPress
add_action( 'init', array( $inspiro_starter_sites, 'instance' ) );

class Inspiro_Starter_Sites_Plugin {

	/**
	 * This plugin's instance.
	 *
	 * @var Inspiro_Starter_Sites_Plugin
	 * @since 1.0.0
	 */
	private static $instance;

	/**
	 * Main Inspiro_Starter_Sites_Plugin Instance.
	 *
	 * Insures that only one instance of Inspiro_Starter_Sites_Plugin exists in memory at any one
	 * time. Also prevents needing to define globals all over the place.
	 *
	 * @since 1.0.0
	 * @static
	 * @return object|Inspiro_Starter_Sites_Plugin The one true Inspiro_Starter_Sites_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new Inspiro_Starter_Sites_Plugin();
		}
		return self::$instance;
	}

	/**
	 * Plugin constructor.
	 *
	 * @since 1.0.0
	 */
	function __construct() {
		add_action( 'init', array( $this, 'i18n' ) );
	}

	/**
	 * Load Textdomain
	 *
	 * Load plugin localization files.
	 *
	 * Fired by `init` action hook.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function i18n() {
		load_plugin_textdomain( 'inspiro-starter-sites', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

}

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

}