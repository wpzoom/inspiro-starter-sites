<?php
/**
 * Register demo importer class.
 *
 * @since   1.0.0
 * @package WPZOOM_Inspiro_Starter_Sites
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Class for demo importer.
 */
class Inspiro_Starter_Sites_Importer {

	/**
	 * The Constructor.
	 */
	public function __construct() {

		$current_theme = wp_get_theme();
		$theme_name    = $current_theme->get( 'Name' );

		require_once INSPIRO_STARTER_SITES_PATH . 'components/importer/wpzoom-importer/wpzoom-importer.php';
	
		if ( 'Inspiro' == $theme_name ) {
			add_filter( 'wpzi/plugin_page_setup', array( $this, 'wpzi_new_menu' ) );
		}
		
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

	}

	public function wpzi_new_menu() {
		
		return array(
			'parent_slug' => 'inspiro',
			'page_title'  => esc_html__( 'Import Demo', 'inspiro-starter-sites' ),
			'menu_title'  => esc_html__( 'Import Demo', 'inspiro-starter-sites' ),
			'capability'  => 'manage_options',
			'menu_slug'   => 'inspiro-demo',
		);
	}

	public function enqueue_scripts( $hook ) {		

		if ( 'inspiro_page_inspiro-demo' !== $hook && 'appearance_page_inspiro-starter-sites' !== $hook ) {
			return;
		}
		
		wp_enqueue_script( 
			'inspiro-starter-sites-importer', 
			INSPIRO_STARTER_SITES_URL . 'components/importer/assets/js/importer.js',
			array( 'jquery' ), 
			INSPIRO_STARTER_SITES_VERSION, 
			true 
		);

		wp_enqueue_script( 'jquery-ui' );
		wp_enqueue_script( 'jquery-ui-tabs' );

		wp_enqueue_style( 
			'inspiro-starter-sites-importer', 
			INSPIRO_STARTER_SITES_URL . 'components/importer/assets/css/importer.css', 
			array(),
			INSPIRO_STARTER_SITES_VERSION 
		);
	}


}

new Inspiro_Starter_Sites_Importer();
