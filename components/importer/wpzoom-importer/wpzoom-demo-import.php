<?php
/**
 * Register demo importer class.
 *
 * @since   1.0.0
 * @package WPZOOM_Inspiro_Toolkit
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main demo importer class with initialization tasks.
 */
class WPZI_Plugin {
	/**
	 * Constructor for this class.
	 */
	public function __construct() {

		// Set demo importer constants.
		$this->set_plugin_constants();

		// Composer autoloader.
		require_once WPZI_PATH . 'vendor/autoload.php';

		// Instantiate the demo importer class.
		$one_click_demo_import = WPZI\WpzoomDemoImport::get_instance();

	}

	/**
	 * Set plugin constants.
	 *
	 * Path/URL to root of this plugin, with trailing slash and plugin version.
	 */
	private function set_plugin_constants() {
		
		// Path/URL to root of this plugin, with trailing slash.
		if ( ! defined( 'WPZI_PATH' ) ) {
			define( 'WPZI_PATH', plugin_dir_path( __FILE__ ) );
		}
		if ( ! defined( 'WPZI_URL' ) ) {
			define( 'WPZI_URL', plugin_dir_url( __FILE__ ) );
		}

		// Action hook to set the demo importer version constant.
		add_action( 'admin_init', array( $this, 'set_plugin_version_constant' ) );
	}


	/**
	 * Set plugin version constant -> WPZI_VERSION.
	 */
	public function set_plugin_version_constant() {
		$plugin_data = get_plugin_data( __FILE__ );

		if ( ! defined( 'WPZI_VERSION' ) ) {
			define( 'WPZI_VERSION', $plugin_data['Version'] );
		}
	}
}

// Instantiate the plugin class.
$wpzi_plugin = new WPZI_Plugin();