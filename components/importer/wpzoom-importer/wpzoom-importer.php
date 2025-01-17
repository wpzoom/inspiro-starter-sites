<?php
/**
 * Register WPZOOM importer class.
 *
 * @since   1.0.0
 * @package WPZOOM_Inspiro_Starter_Sites
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main WPZI_Plugin Class.
 */
class WPZI_Importer {
	/**
	 * Constructor for this class.
	 */
	public function __construct() {

		// Set demo importer constants.
		$this->set_importer_constants();

		// Composer autoloader.
		require_once WPZI_PATH . 'vendor/autoload.php';

		// Instantiate the demo importer class.
		$wpzoom_demo_import = WPZI\WpzoomImporter::get_instance();

	}

	/**
	 * Set importer constants.
	 *
	 * Path/URL to root of this importer, with trailing slash and importer version.
	 */
	private function set_importer_constants() {
		
		// Path/URL to root of this importer, with trailing slash.
		if ( ! defined( 'WPZI_PATH' ) ) {
			define( 'WPZI_PATH', plugin_dir_path( __FILE__ ) );
		}
		if ( ! defined( 'WPZI_URL' ) ) {
			define( 'WPZI_URL', plugin_dir_url( __FILE__ ) );
		}

		// Action hook to set the demo importer version constant.
		add_action( 'admin_init', array( $this, 'set_importer_version_constant' ) );
	}


	/**
	 * Set importer version constant -> WPZI_VERSION.
	 */
	public function set_importer_version_constant() {

		if ( ! defined( 'WPZI_VERSION' ) ) {
			define( 'WPZI_VERSION', INSPIRO_STARTER_SITES_VERSION );
		}
	}
}

// Initialize the class.
$wpzi_importer = new WPZI_Importer();