<?php
/**
 * Register demo importer setup class.
 *
 * @since   1.0.0
 * @package Inspiro_Starter_Sites
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class for demo importer.
 */
class Inspiro_Starter_Sites_Importer_Setup {

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

		$current_theme = wp_get_theme();
		$theme_name    = $current_theme->get( 'Name' );

		add_filter( 'iss/register_plugins', array( $this, 'iss_register_plugins' ) );
		add_filter( 'iss/import_files', array( $this, 'iss_import_files' ) );
		add_action( 'iss/after_import', array( $this, 'iss_after_import_setup' ) );

		add_filter( 'inspiro_starter_sites_premium_demos', array( $this, 'premium_demos' ) );

		if ( 'Inspiro' == $theme_name && ! class_exists( 'WPZOOM' ) ) {
			add_filter( 'iss/plugin_page_setup', array( $this, 'iss_new_menu' ) );
		}
		
	}

	public function iss_register_plugins( $plugins ) {
		$theme_plugins = [
			[
				'name'     => 'Instagram Widget by WPZOOM',
				'slug'     => 'instagram-widget-by-wpzoom',
				'desc'     => 'Displays your Instagram feed beautifully on your WordPress site with ease.',
				'required' => false,
		  	],
			[
				'name'     => 'WPZOOM Forms',
				'slug'     => 'wpzoom-forms',
				'desc'     => 'Helps you create customizable contact forms and integrate them seamlessly into your WordPress site.',
				'required' => true,
			],
		];

		// Check if user is on the theme recommeneded plugins step and a demo was selected.
		if ( isset( $_GET['step'] ) && $_GET['step'] === 'import' && isset( $_GET['import'] ) ) {
			
			$import_step = sanitize_text_field( wp_unslash( $_GET['import'] ) );

			check_admin_referer( 'importer_step' );

		// Adding one additional plugin for the first demo import ('import' number = 0).
		if ( $import_step === '0' ) {
			$theme_plugins[] = [
				'name'     => 'Video Popup Block by WPZOOM',
				'slug'     => 'wpzoom-video-popup-block',
				'desc'     => 'Enables you to embed engaging video popups on your WordPress site effortlessly.',
				'required' => true,
			];
			$theme_plugins[] =  [
				'name'     => 'WPZOOM Portfolio',
				'slug'     => 'wpzoom-portfolio',
				'desc'     => 'Showcases your projects in a professional and visually appealing portfolio layout.',
				'required' => true,
			];
		} elseif ( $import_step === '1' ) {


			$theme_plugins[] =  [
				'name'     => 'Elementor',
				'slug'     => 'elementor',
				'desc'     => 'The most popular page builder for WordPress that allows users to design custom layouts with a drag-and-drop interface.',
				'required' => true,
			];
			$theme_plugins[] = [
			'name'     => 'Elementor Addons by WPZOOM',
			'slug'     => 'wpzoom-elementor-addons',
			'desc'     => 'Enhances Elementor with additional widgets and features for expanded design functionality.',
			'required' => true,
			];
			$theme_plugins[] =  [
				'name'     => 'WPZOOM Portfolio',
				'slug'     => 'wpzoom-portfolio',
				'desc'     => 'Showcases your projects in a professional and visually appealing portfolio layout.',
				'required' => true,
			];
		} elseif ( $import_step === '2' ) {

			$theme_plugins[] =  [
				'name'     => 'WooCommerce',
				'slug'     => 'woocommerce',
				'desc'     => 'The leading e-commerce plugin for WordPress, enabling users to build and manage online stores effortlessly.',
				'required' => true,
			];
			}
		}
		return array_merge( $plugins, $theme_plugins );
	  
	}

	public function iss_import_files() {

		$demos_preview_url = INSPIRO_STARTER_SITES_URL .'assets/images/preview-demos/';

		return [
			[	'import_id'                  => 'inspiro-lite-blocks',
				'import_file_name'           => 'Inspiro Lite - Gutenberg Blocks',
				'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-blocks.xml',
				'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-widgets.wie',
				'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-customizer.dat',
				'import_preview_image_url'   => $demos_preview_url . 'inspiro-lite-block.png',
				'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-blocks/',
			],
			[	
				'import_id'                  => 'inspiro-lite',
				'import_file_name'           => 'Inspiro Lite - Elementor',
				'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite.xml',
				'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-widgets.wie',
				'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-customizer.dat',
				'import_preview_image_url'   => $demos_preview_url .  'inspiro-lite-elementor-1.png',
				'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite/',
			],
			[	
				'import_id'                  => 'inspiro-lite-woo',
				'import_file_name'           => 'Inspiro Lite - WooCommerce Shop',
				'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-woo.xml',
				'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-woo-widgets.wie',
				'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-woo.dat',
				'import_preview_image_url'   => $demos_preview_url .  'inspiro-lite-woo.png',
				'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-woo/',
			],
		];
	}

	public function premium_demos() {
		return array(
			'inspiro-premium' => array(
				'name'     => 'Inspiro Premium',
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
					array(
						'import_file_name'           => 'Video Production',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/home-video-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-video/',
					),
					array(
						'import_file_name'           => 'Video Production #2',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/home-video2-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-video2/',
					),
					array(
						'import_file_name'           => 'Kids Summer Camp',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-scout/home-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-kids/',
					),
					array(
						'import_file_name'           => 'Architecture',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-architecture/home-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-architecture/',
					),
					array(
						'import_file_name'           => 'Wedding Photography',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/wedding/home-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-wedding-photography/',
					),
					array(
						'import_file_name'           => 'Photography',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/home-photography-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-photography/',
					),
					array(
						'import_file_name'           => 'Agency / Business',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/home-agency-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-agency/',
					),
					array(
						'import_file_name'           => 'Hotel / Real Estate',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/home-hotel-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-hotel/',
					),
					array(
						'import_file_name'           => 'Shop / WooCommerce',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/shop-home-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-shop/',
					),
					array(
						'import_file_name'           => 'Jewelry Shop / WooCommerce',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/shop2/home-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-jewelry2/',
					),
					array(
						'import_file_name'           => 'Restaurant',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/home-restaurant-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-restaurant/',
					),
					array(
						'import_file_name'           => 'Events / Conference',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/demo-events.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-event/',
					),
					array(
						'import_file_name'           => 'Wellness / Spa',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-wellness/home-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-wellness/',
					),
					array(
						'import_file_name'           => 'Magazine',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-magazine/home-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-magazine/',
					),
					array(
						'import_file_name'           => 'Car Rental / Dealer',
						'import_preview_image_url'   => 'https://demo.wpzoom.com/inspiro-demo/wp-content/themes/inspiro-select/images/inspiro-rent.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-auto/',
					),
					array(
						'import_file_name'           => 'Author / Coach',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-author/home-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-author/',
					),
					array(
						'import_file_name'           => 'Church',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-church/home-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-church/',
					),
				),
			),
			'ispiro-pro' => array(
				'name'     => 'Inspiro PRO',
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
					array(
						'import_file_name'           => 'Agency',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro-pro/agency/home-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-pro-agency/',
					),
					array(
						'import_file_name'           => 'Agency (Dark)',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro-pro/agency-dark/home-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-pro-agency-dark/',
					),
					array(
						'import_file_name'           => 'Business',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro-pro/business/home-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-pro-business/',
					),
					array(
						'import_file_name'           => 'Shop',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro-pro/shop/home-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-pro-shop/',
					),
					array(
						'import_file_name'           => 'Real Estate',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro-pro/real-estate/home-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-pro-real-estate/',
					),
					array(
						'import_file_name'           => 'Charity / NGO',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro-pro/charity/home-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-pro-charity/',
					),
					array(
						'import_file_name'           => 'Fitness / Gym',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro-pro/fitness/fitness-home-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-pro-fitness/',
					),
					array(
						'import_file_name'           => 'Winery',
						'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro-pro/winery/home-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-pro-winery/',
					),
					array(
						'import_file_name'           => 'Tech / Finance',
						'import_preview_image_url'   => 'https://demo.wpzoom.com/inspiro-pro-demo/wp-content/themes/inspiro-pro-select/images/site-layout_tech.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-tech/',
					),
				)
			)
		);
	}


	public function iss_after_import_setup() {
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


	/*
	 * Register new menu for the demo importer
	*/
	public function iss_new_menu() {
		
		return array(
			'parent_slug' => 'inspiro',
			'page_title'  => esc_html__( 'Import Demo', 'inspiro-starter-sites' ),
			'menu_title'  => esc_html__( 'Import Demo', 'inspiro-starter-sites' ),
			'capability'  => 'manage_options',
			'menu_slug'   => 'inspiro-demo',
		);
	}


}

Inspiro_Starter_Sites_Importer_Setup::get_instance();
