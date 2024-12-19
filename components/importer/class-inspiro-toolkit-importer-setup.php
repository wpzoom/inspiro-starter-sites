<?php
/**
 * Register demo importer setup class.
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
class Inspiro_Toolkit_Importer_Setup {

		/**
		 * Instance
		 *
		 * @var $instance
		 */
		private static $instance;

		/**
		 * Initiator
		 *
		 * @since 1.0.0
		 * @return object
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

	/**
	 * The Constructor.
	 */
	public function __construct() {

		add_filter( 'wpzi/register_plugins', array( $this, 'wpzi_register_plugins' ) );
		add_filter( 'wpzi/import_files', array( $this, 'wpzi_import_files' ) );
		add_action( 'wpzi/after_import', array( $this, 'wpzi_after_import_setup' ) );

		add_filter( 'inspiro_toolkit_premium_demos', array( $this, 'premium_demos' ) );

	}

	public function wpzi_register_plugins( $plugins ) {
		$theme_plugins = [
		  [
			  'name'     => 'Instagram Widget by WPZOOM',
			  'slug'     => 'instagram-widget-by-wpzoom',
			  'required' => false,
		  ],
		  [
			  'name'     => 'WPZOOM Forms',
			  'slug'     => 'wpzoom-forms',
			  'required' => true,
		  ],
		];

		// Check if user is on the theme recommeneded plugins step and a demo was selected.
		if (
			isset( $_GET['step'] ) &&
			$_GET['step'] === 'import' &&
			isset( $_GET['import'] )
		) {

		// Adding one additional plugin for the first demo import ('import' number = 0).
		if ( $_GET['import'] === '0' ) {

			$theme_plugins[] =  [
				'name'     => 'WPZOOM Portfolio',
				'slug'     => 'wpzoom-portfolio',
				'required' => true,
			];
		} elseif ( $_GET['import'] === '1' ) {


			$theme_plugins[] =  [
				'name'     => 'Elementor',
				'slug'     => 'elementor',
				'required' => true,
			];
			$theme_plugins[] = [
			'name'     => 'Elementor Addons by WPZOOM',
			'slug'     => 'wpzoom-elementor-addons',
			'required' => true,
			];
			$theme_plugins[] =  [
				'name'     => 'WPZOOM Portfolio',
				'slug'     => 'wpzoom-portfolio',
				'required' => true,
			];
		} elseif ( $_GET['import'] === '2' ) {

			$theme_plugins[] =  [
				'name'     => 'WooCommerce',
				'slug'     => 'woocommerce',
				'required' => true,
			];
			}
		}
		return array_merge( $plugins, $theme_plugins );
	  
	}

	public function wpzi_import_files() {
		return [
			[
				'import_file_name'           => 'Inspiro Lite - Gutenberg Blocks',
				'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-blocks.xml',
				'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-widgets.wie',
				'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-customizer.dat',
				'import_preview_image_url'   => 'https://www.wpzoom.com/wp-content/uploads/2024/10/inspiro-lite-block.png',
				'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-blocks/',
			],
			[
				'import_file_name'           => 'Inspiro Lite - Elementor',
				'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite.xml',
				'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-widgets.wie',
				'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-customizer.dat',
				'import_preview_image_url'   => 'https://www.wpzoom.com/wp-content/uploads/2021/10/inspiro-lite-elementor-1.png',
				'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite/',
			],
			[
				'import_file_name'           => 'Inspiro Lite - WooCommerce Shop',
				'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-woo.xml',
				'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-woo-widgets.wie',
				'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-woo.dat',
				'import_preview_image_url'   => 'https://www.wpzoom.com/wp-content/uploads/2024/10/inspiro-lite-woo.png',
				'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-woo/',
			],
		];
	}

	public function premium_demos() {
		return array(
			'inspiro-premium' => array(
				'name'     => 'Inspiro Premium Demos',
				'desc'     => 'Below you can view demos available in the Inspiro Premium theme. You can get access to all of them by purchasing the Premium version of the theme.',
				'purchase' => 'https://www.wpzoom.com/themes/inspiro/',
				'demos' => array(
					array(
						'import_file_name'           => 'Agency / Business (new)',
						'import_preview_image_url'   => 'https://demo.wpzoom.com/inspiro-pro-demo/wp-content/themes/inspiro-pro-select/images/site-layout_agency-dark.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-agency2/',
					),
					array(
						'import_file_name'           => 'Premium Demo',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/home-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro/',
					),
				)
			),
			'ispiro-pro' => array(
				'name'     => 'Inspiro PRO Demos',
				'desc'     => 'Inspiro PRO is a newer version of the Inspiro theme, which can be purchased separately.',
				'purchase' => 'https://www.wpzoom.com/themes/inspiro-pro/',
				'demos' => array(
					array(
						'import_file_name'           => 'Eccentric',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro-pro/flow-1/home-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-pro/',
					),
					array(
						'import_file_name'           => 'Offbeat',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro-pro/flow-2/home-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-pro-2/',
					),
				)
			)
		);
	}


	public function wpzi_after_import_setup() {
		// Assign menus to their locations.
		$main_menu = get_term_by( 'name', 'Main', 'nav_menu' );

		set_theme_mod( 'nav_menu_locations', [
				'primary' => $main_menu->term_id, // replace 'main-menu' here with the menu location identifier from register_nav_menu() function in your theme.
			]
		);

		// Assign front page and posts page (blog page).
		$front_page_id = self::get_page_by_title( 'Homepage' );
		$blog_page_id  = self::get_page_by_title( 'Blog' );

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front_page_id->ID );
		update_option( 'page_for_posts', $blog_page_id->ID );

	}

	public static function get_page_by_title( $page_title ) {

		$posts = get_posts(
			array(
				'post_type'              => 'page',
				'title'                  => $page_title,
				'post_status'            => 'all',
				'numberposts'            => 1,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,           
				'orderby'                => 'post_date ID',
				'order'                  => 'ASC',
			)
		);
			
		if ( ! empty( $posts ) ) {
			$page_got_by_title = $posts[0];
		} else {
			$page_got_by_title = null;
		}

		return $page_got_by_title;
	}


}

Inspiro_Toolkit_Importer_Setup::get_instance();
