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
 * Class for demo importer.
 */
class Inspiro_Toolkit_Importer {

	/**
	 * The Constructor.
	 */
	public function __construct() {
		require_once INSPIRO_TOOLKIT_PATH . 'components/importer/wpzoom-importer/wpzoom-importer.php';
	

		add_filter( 'wpzi/plugin_page_setup', array( $this, 'wpzi_new_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

	}

	public function wpzi_new_menu() {
		return array(
			'parent_slug' => 'inspiro-toolkit-demo-import',
			'page_title'  => esc_html__( 'Import Demo Data', 'inspiro-toolkit' ),
			'menu_title'  => esc_html__( 'Import Demo Data', 'inspiro-toolkit' ),
			'capability'  => 'manage_options',
			'menu_slug'   => 'inspiro-toolkit-demo-import',
		);
	}

	public function enqueue_scripts( $hook ) {


		if ( 'toplevel_page_inspiro-toolkit-demo-import' !== $hook ) {
			return;
		}
		
		wp_enqueue_script( 
			'inspiro-toolkit-importer', 
			INSPIRO_TOOLKIT_URL . 'components/importer/assets/js/importer.js',
			array( 'jquery' ), 
			INSPIRO_TOOLKIT_VERSION, 
			true 
		);

		wp_enqueue_script( 'jquery-ui' );
		wp_enqueue_script( 'jquery-ui-tabs' );

		wp_enqueue_style( 
			'inspiro-toolkit-importer', 
			INSPIRO_TOOLKIT_URL . 'components/importer/assets/css/importer.css', 
			array(),
			INSPIRO_TOOLKIT_VERSION 
		);
	}


}

new Inspiro_Toolkit_Importer();
