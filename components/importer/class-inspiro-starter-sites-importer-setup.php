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
		$theme_template = get_template();

		add_filter( 'inspiro_starter_sites/register_plugins', array( $this, 'register_plugins' ) );
		add_filter( 'inspiro_starter_sites/import_files', array( $this, 'import_files' ) );
		add_action( 'inspiro_starter_sites/after_import', array( $this, 'after_import_setup' ) );
		add_filter( 'upload_mimes', array( $this, 'allow_svg_during_import' ) );
		add_filter( 'wp_check_filetype_and_ext', array( $this, 'fix_svg_filetype_check' ), 10, 5 );

		add_filter( 'inspiro_starter_sites_premium_demos', array( $this, 'premium_demos' ) );

		if ( ( 'Inspiro' == $theme_name || 'inspiro' == $theme_template ) && ! class_exists( 'WPZOOM' ) ) {
			add_filter( 'inspiro_starter_sites/plugin_page_setup', array( $this, 'new_menu' ) );
		}
		
	}

	public function allow_svg_during_import( $mimes ) {
		$mimes['svg']  = 'image/svg+xml';
		$mimes['svgz'] = 'image/svg+xml';
		return $mimes;
	}

	public function fix_svg_filetype_check( $data, $file, $filename, $mimes, $real_mime = '' ) {
		if ( empty( $data['type'] ) ) {
			$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			if ( $ext === 'svg' || $ext === 'svgz' ) {
				$data['type'] = 'image/svg+xml';
				$data['ext']  = $ext;
			}
		}
		return $data;
	}

	public function register_plugins( $plugins ) {
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

		// Check if user is on the theme recommended plugins step and a demo was selected.
		if ( isset( $_GET['step'] ) && $_GET['step'] === 'import' && isset( $_GET['import'] ) ) {

			$import_index = (int) sanitize_text_field( wp_unslash( $_GET['import'] ) );

			check_admin_referer( 'importer_step' );

			$import_files = $this->import_files();
			$import_id    = isset( $import_files[ $import_index ]['import_id'] ) ? $import_files[ $import_index ]['import_id'] : '';

			$demo_plugins = $this->demo_plugins();
			$catalog      = $this->plugin_catalog();

			if ( isset( $demo_plugins[ $import_id ] ) ) {
				foreach ( $demo_plugins[ $import_id ] as $slug ) {
					if ( isset( $catalog[ $slug ] ) ) {
						$theme_plugins[] = array_merge(
							$catalog[ $slug ],
							[ 'slug' => $slug, 'required' => true ]
						);
					}
				}
			}
		}
		return array_merge( $plugins, $theme_plugins );

	}

	/**
	 * Catalog of plugin metadata, keyed by slug. Single source of truth — every
	 * plugin reference in demo_plugins() resolves through here.
	 */
	private function plugin_catalog() {
		return [
			'wpzoom-video-popup-block' => [
				'name' => 'Video Popup Block by WPZOOM',
				'desc' => 'Enables you to embed engaging video popups on your WordPress site effortlessly.',
			],
			'wpzoom-portfolio' => [
				'name' => 'WPZOOM Portfolio',
				'desc' => 'Showcases your projects in a professional and visually appealing portfolio layout.',
			],
			'elementor' => [
				'name' => 'Elementor',
				'desc' => 'The most popular page builder for WordPress that allows users to design custom layouts with a drag-and-drop interface.',
			],
			'wpzoom-elementor-addons' => [
				'name' => 'Elementor Addons by WPZOOM',
				'desc' => 'Enhances Elementor with additional widgets and features for expanded design functionality.',
			],
			'woocommerce' => [
				'name' => 'WooCommerce',
				'desc' => 'The leading e-commerce plugin for WordPress, enabling users to build and manage online stores effortlessly.',
			],
            'carousel-block' => [
                'name' => 'Carousel Slider Block',
                'desc' => 'A responsive modern carousel slider for the Gutenberg block editor that lets you add any blocks to your slides.',
            ],
			'icon-block' => [
				'name' => 'The Icon Block',
				'desc' => 'Easily add SVG icons and graphics to the WordPress block editor.',
			],
			'recipe-card-blocks-by-wpzoom' => [
				'name' => 'Recipe Card Blocks',
				'desc' => 'Beautiful Recipe Card Blocks for Food Bloggers with Schema Markup (JSON-LD) for the new WordPress editor (Gutenberg).',
			],
			'makeiteasy-slider' => [
				'name' => 'Makeiteasy Slider',
				'desc' => 'Block based slider, leverages the speed and versatility of the Swiper slider.',
			],
			'slider-block' => [
				'name' => 'Image Slider Block',
				'desc' => 'Display Multiple Images In Beautiful Slider & Reduce Page Scroll.',
			],
			'social-icons-widget-by-wpzoom' => [
				'name' => 'Social Icons Widget & Block by WPZOOM',
				'desc' => 'Displays social media icon links in a clean, customizable widget or block.',
			],
		];
	}

	/**
	 * Map of import_id → list of plugin slugs required by that demo.
	 * Duplicate keys would trigger a PHP warning, so the dead-case bug
	 * (two `case 'inspiro-lite-business'` blocks) can no longer happen.
	 */
	private function demo_plugins() {
		return [
			'inspiro-lite-blocks'         		 => [ 'wpzoom-video-popup-block', 'wpzoom-portfolio' ],
			'inspiro-lite'                		 => [ 'elementor', 'wpzoom-elementor-addons', 'wpzoom-portfolio' ],
			'inspiro-lite-woo'            		 => [ 'woocommerce' ],
			'inspiro-lite-medical'        		 => [ 'icon-block' ],
			'inspiro-lite-finance'        		 => [ 'icon-block' ],
			'inspiro-lite-freelancer'     		 => [ 'wpzoom-portfolio', 'icon-block' ],
			'inspiro-lite-freelancer-grey'		 => [ 'wpzoom-portfolio', 'icon-block' ],
			'inspiro-lite-persona'        		 => [ 'wpzoom-portfolio', 'icon-block' ],
			'inspiro-lite-remix'          		 => [ 'wpzoom-portfolio', 'icon-block' ],
			'inspiro-lite-recipe-blocks'  		 => [ 'recipe-card-blocks-by-wpzoom' ],
			'inspiro-lite-magazine'       		 => [ 'makeiteasy-slider' ],
			'inspiro-lite-energy'         		 => [ 'slider-block', 'wpzoom-portfolio', 'icon-block' ],
			'inspiro-lite-video'          		 => [ 'slider-block', 'wpzoom-portfolio', 'wpzoom-video-popup-block', 'icon-block' ],
			'inspiro-lite-business'       		 => [ 'wpzoom-portfolio', 'social-icons-widget-by-wpzoom', 'icon-block' ],
			'inspiro-lite-business-elementor'    => [ 'wpzoom-portfolio', 'social-icons-widget-by-wpzoom', 'elementor', 'wpzoom-elementor-addons' ],
			'inspiro-lite-charity'         		 => [ 'wpzoom-portfolio', 'social-icons-widget-by-wpzoom', 'carousel-block' ],
			'inspiro-lite-events'          		 => [ 'social-icons-widget-by-wpzoom' ],
			'inspiro-lite-construction'    		 => [ 'social-icons-widget-by-wpzoom' ],
            'inspiro-lite-architecture'          => [ 'elementor', 'wpzoom-elementor-addons', 'wpzoom-portfolio', 'social-icons-widget-by-wpzoom' ],
            'inspiro-lite-lawyer'                => [ 'elementor', 'wpzoom-elementor-addons', 'social-icons-widget-by-wpzoom' ],
			'inspiro-lite-winery'    	  		 => [ 'social-icons-widget-by-wpzoom' ],
			'inspiro-lite-fitness'		   		 => [ 'social-icons-widget-by-wpzoom' ],
			'inspiro-lite-insurance'		   	 => [ 'social-icons-widget-by-wpzoom', 'icon-block' ],
            'inspiro-lite-agency'                => [ 'icon-block', 'slider-block', 'wpzoom-portfolio' ],
			'inspiro-lite-advisory'		   	 	 => [ 'icon-block' ]
		];
	}

	public function import_files() {

		$demos_preview_url = INSPIRO_STARTER_SITES_URL .'assets/images/preview-demos/';

		return [
			[	'import_id'                  => 'inspiro-lite-blocks',
				'import_file_name'           => 'Business / Portfolio (Block Editor)',
				'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-blocks.xml',
				'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-widgets.wie',
				'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-customizer.dat',
				'import_preview_image_url'   => $demos_preview_url . 'inspiro-lite-block.png',
				'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-blocks/',
				'type'                       => 'blocks',
				'group'                      => 'business-portfolio',
				'group_label'                => 'Business / Portfolio',
				'categories'                 => [ 'business', 'portfolio', 'video' ],
			],
			[
				'import_id'                  => 'inspiro-lite',
				'import_file_name'           => 'Business / Portfolio (Elementor)',
				'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite.xml',
				'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-widgets.wie',
				'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-elementor.dat',
				'import_preview_image_url'   => $demos_preview_url .  'inspiro-lite-elementor-1.png',
				'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite/',
				'type'                       => 'elementor',
				'group'                      => 'business-portfolio',
				'group_label'                => 'Business / Portfolio',
				'categories'                 => [ 'business', 'portfolio' ],
			],
			[
				'import_id'                  => 'inspiro-lite-business',
				'import_file_name'           => 'Business (Block Editor)',
				'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-business.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-business.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-business.dat',
				'import_preview_image_url'   => $demos_preview_url .  'business.png',
				'preview_url'                => 'https://inspiro.wpzoom.com/business/',
				'type'                       => 'blocks',
				'group'                      => 'business',
				'group_label'                => 'Business',
				'is_new'                     => true,
				'categories'                 => [ 'business', 'portfolio' ],
			],
			[
				'import_id'                  => 'inspiro-lite-business-elementor',
				'import_file_name'           => 'Business (Elementor)',
				'import_file_url'          	 => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-business-elementor.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-business-elementor.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-business-elementor.dat',
				'import_preview_image_url'   => $demos_preview_url .  'business-elementor.png',
				'preview_url'                => 'https://inspiro.wpzoom.com/business-elementor/',
                'type'                       => 'elementor',
				'group'                      => 'business',
				'group_label'                => 'Business',
				'is_new'                     => true,
				'categories'                 => [ 'business', 'portfolio' ],
			],
            [
                'import_id'                  => 'inspiro-lite-agency',
                'import_file_name'           => 'Creative Agency (Lite)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-agency.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-agency.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-agency.dat',
                'import_preview_image_url'   => $demos_preview_url .  'agency.png',
                'preview_url'                => 'https://inspiro.wpzoom.com/lite-new/',
                'is_new'                     => true,
                'categories'                 => [ 'portfolio', 'creative', 'business', 'video' ],
            ],
			[	
				'import_id'                  => 'inspiro-lite-woo',
				'import_file_name'           => 'WooCommerce Shop',
				'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-woo.xml',
				'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-woo-widgets.wie',
				'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-woo.dat',
				'import_preview_image_url'   => $demos_preview_url .  'inspiro-lite-woo.png',
				'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-woo/',
				'platform'                   => 'woocommerce',
				'categories'                 => [ 'woocommerce' ],
			],
            [
                'import_id'                  => 'inspiro-lite-energy',
                'import_file_name'           => 'Green Energy',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-energy.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-energy.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-energy.dat',
                'import_preview_image_url'   => $demos_preview_url .  'energy.png',
                'preview_url'                => 'https://inspiro.wpzoom.com/energy/',
                'is_new'                     => true,
                'categories'                 => [ 'business' ],
            ],
            [
                'import_id'                  => 'inspiro-lite-architecture',
                'import_file_name'           => 'Architecture (Lite)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-architecture.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-architecture.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-architecture.dat',
                'import_preview_image_url'   => $demos_preview_url .  'architecture.png',
                'preview_url'                => 'https://inspiro.wpzoom.com/architecture/',
                'type'                       => 'elementor',
                'is_new'                     => true,
                'categories'                 => [ 'portfolio', 'creative', 'architecture', 'construction' ],
            ],
            [
                'import_id'                  => 'inspiro-lite-advisory',
                'import_file_name'           => 'Consulting Company (Lite)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-advisory.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-advisory.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-advisory.dat',
                'import_preview_image_url'   => $demos_preview_url .  'advisory.png',
                'preview_url'                => 'https://inspiro.wpzoom.com/advisory/',
                'is_new'                     => true,
                'categories'                 => [ 'lawyer', 'business' ],
            ],
            [
                'import_id'                  => 'inspiro-lite-lawyer',
                'import_file_name'           => 'Lawyer / Legal Firm (Lite)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-lawyer.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-lawyer.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-lawyer.dat',
                'import_preview_image_url'   => $demos_preview_url .  'lawyer.png',
                'preview_url'                => 'https://inspiro.wpzoom.com/lawyer/',
                'type'                       => 'elementor',
                'is_new'                     => true,
                'categories'                 => [ 'business', 'legal', 'lawyer' ],
            ],
            [
                'import_id'                  => 'inspiro-lite-medical',
                'import_file_name'           => 'Medical / Doctor (Lite)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-medical.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/medical.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-medical.dat',
                'import_preview_image_url'   => $demos_preview_url .  'medical.png',
                'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-medical/',
                'categories'                 => [ 'health' ],
            ],
            [
                'import_id'                  => 'inspiro-lite-finance',
                'import_file_name'           => 'Finance / Tech (Lite)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-finance.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/finance.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-finance.dat',
                'import_preview_image_url'   => $demos_preview_url .  'finance.png',
                'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-finance/',
                'categories'                 => [ 'business' ],
            ],
            [
                'import_id'                  => 'inspiro-lite-insurance',
                'import_file_name'           => 'Insurance Company (Lite)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-insurance.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-insurance.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-insurance.dat',
                'import_preview_image_url'   => $demos_preview_url .  'insurance.png',
                'preview_url'                => 'https://inspiro.wpzoom.com/insurance/',
                'is_new'                     => true,
                'categories'                 => [ 'lawyer', 'business' ],
            ],
			[
                'import_id'                  => 'inspiro-lite-recipe-blocks',
                'import_file_name'           => 'Food Blog (Lite)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-recipe-blocks.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/recipe-blocks.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-recipe-blocks.dat',
                'import_preview_image_url'   => $demos_preview_url .  'recipe-blocks.png',
                'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-recipe-blocks/',
                'categories'                 => [ 'food-restaurant' ],
            ],
            [
                'import_id'                  => 'inspiro-lite-magazine',
                'import_file_name'           => 'Magazine',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-magazine.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-magazine.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-magazine.dat',
                'import_preview_image_url'   => $demos_preview_url .  'magazine.png',
                'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-magazine/',
                'categories'                 => [ 'magazine' ],
            ],
            [
                'import_id'                  => 'inspiro-lite-persona',
                'import_file_name'           => 'Persona Lite (Dark)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-persona.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-persona.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-persona.dat',
                'import_preview_image_url'   => $demos_preview_url .  'persona.png',
                'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-persona/',
                'categories'                 => [ 'portfolio', 'creative', 'video' ],
            ],
            [
                'import_id'                  => 'inspiro-lite-remix',
                'import_file_name'           => 'Remix',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-remix.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-remix.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-remix.dat',
                'import_preview_image_url'   => $demos_preview_url .  'remix.png',
                'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-remix/',
                'categories'                 => [ 'portfolio', 'creative' ],
            ],

            [
                'import_id'                  => 'inspiro-lite-video',
                'import_file_name'           => 'Video Production (Lite)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-video.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-video.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-video.dat',
                'import_preview_image_url'   => $demos_preview_url .  'video.png',
                'preview_url'                => 'https://inspiro.wpzoom.com/video/',
                'categories'                 => [ 'video', 'creative', 'portfolio' ],
            ],
			[
                'import_id'                  => 'inspiro-lite-charity',
                'import_file_name'           => 'Charity / NGO (Lite)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-charity.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-charity.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-charity.dat',
                'import_preview_image_url'   => $demos_preview_url .  'charity.png',
                'preview_url'                => 'https://inspiro.wpzoom.com/charity/',
				'is_new'                     => true,
				'categories'                 => [ 'non-profit' ],
            ],
			[
                'import_id'                  => 'inspiro-lite-events',
                'import_file_name'           => 'Events / Conference',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-events.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-events.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-events.dat',
                'import_preview_image_url'   => $demos_preview_url .  'event.png',
                'preview_url'                => 'https://inspiro.wpzoom.com/event/',
				'is_new'                     => true,
				'categories'                 => [ 'business' ],
            ],
			[
                'import_id'                  => 'inspiro-lite-construction',
                'import_file_name'           => 'Construction / Building',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-construction.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-construction.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-construction.dat',
                'import_preview_image_url'   => $demos_preview_url .  'construction.png',
                'preview_url'                => 'https://inspiro.wpzoom.com/construction/',
				'is_new'                     => true,
				'categories'                 => [ 'construction' ],
            ],
			[
                'import_id'                  => 'inspiro-lite-winery',
                'import_file_name'           => 'Winery (Lite)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-winery.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-winery.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-winery.dat',
                'import_preview_image_url'   => $demos_preview_url .  'winery.png',
                'preview_url'                => 'https://inspiro.wpzoom.com/winery/',
				'is_new'                     => true,
				'categories'                 => [ 'food-restaurant' ],
            ],
			[
                'import_id'                  => 'inspiro-lite-fitness',
                'import_file_name'           => 'Gym / Fitness (Lite)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-fitness.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-fitness.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-fitness.dat',
                'import_preview_image_url'   => $demos_preview_url .  'fitness.png',
                'preview_url'                => 'https://inspiro.wpzoom.com/fitness/',
				'is_new'                     => true,
				'categories'                 => [ 'sport', 'health' ],
            ],
            [
                'import_id'                  => 'inspiro-lite-freelancer',
                'import_file_name'           => 'Freelancer (One-Page)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-freelancer.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/freelancer.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-freelancer1.dat',
                'import_preview_image_url'   => $demos_preview_url .  'freelancer.png',
                'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-freelancer/',
                'categories'                 => [ 'portfolio', 'creative' ],
            ],
            [
                'import_id'                  => 'inspiro-lite-freelancer-grey',
                'import_file_name'           => 'Freelancer #2 (One-Page)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-freelancer2.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/freelancer2.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-freelancer2.dat',
                'import_preview_image_url'   => $demos_preview_url .  'freelancer2.png',
                'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-freelancer-blocks/',
                'categories'                 => [ 'portfolio', 'creative' ],
            ],

		];
	}

	/**
	 * Human-readable labels for category slugs used in import_files().
	 * Filterable so other code can extend the available categories.
	 */
	public function category_labels() {
		return apply_filters( 'inspiro_starter_sites/category_labels', [
			'business'        => __( 'Business', 'inspiro-starter-sites' ),
			'portfolio'       => __( 'Portfolio', 'inspiro-starter-sites' ),
			'creative'        => __( 'Creative', 'inspiro-starter-sites' ),
            'lawyer'          => __( 'Legal / Lawyer', 'inspiro-starter-sites' ),
			'woocommerce'     => __( 'WooCommerce', 'inspiro-starter-sites' ),
			'video'           => __( 'Video', 'inspiro-starter-sites' ),
			'photography'     => __( 'Photography', 'inspiro-starter-sites' ),
			'food-restaurant' => __( 'Food & Restaurant', 'inspiro-starter-sites' ),
			'health'          => __( 'Health', 'inspiro-starter-sites' ),
			'sport'           => __( 'Sport', 'inspiro-starter-sites' ),
			'non-profit'      => __( 'Non-profit', 'inspiro-starter-sites' ),
			'education'       => __( 'Education', 'inspiro-starter-sites' ),
			'construction'    => __( 'Construction', 'inspiro-starter-sites' ),
			'real-estate'     => __( 'Real Estate & Architecture', 'inspiro-starter-sites' ),
			'magazine'        => __( 'Magazine', 'inspiro-starter-sites' ),
            'architecture'    => __( 'Architecture', 'inspiro-starter-sites' ),
			'automotive'      => __( 'Automotive', 'inspiro-starter-sites' ),
		] );
	}

	public function premium_demos() {

		// Pull the list from the remote endpoint so new premium demos appear
		// automatically, without requiring a plugin update. Falls back to the
		// bundled list when the remote list can't be fetched.
		$demos = $this->get_remote_premium_demos();
		if ( empty( $demos ) ) {
			$demos = $this->get_fallback_premium_demos();
		}

		return array(
			'inspiro-premium' => array(
				'name'     => 'Inspiro Premium',
				'desc'     => 'Below you can view demos available in the Inspiro Premium theme. You can get access to all of them by purchasing the Premium version of the theme.',
				'purchase' => 'https://www.wpzoom.com/themes/inspiro-lite/upgrade/',
				'demos'    => $demos,
			),
		);
	}

	/**
	 * Fetch the premium demos list from the remote endpoint, cached for a day.
	 *
	 * Returns an empty array on any failure (no connection, non-200, malformed
	 * JSON) so premium_demos() can fall back to the bundled list.
	 *
	 * @return array
	 */
	private function get_remote_premium_demos() {
		$cache_key = 'inspiro_starter_sites_remote_premium_demos';
		$cached    = get_transient( $cache_key );

		// A cached value (including an empty array from a recent failure) short-circuits.
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}

		$url = apply_filters(
			'inspiro_starter_sites/premium_demos_remote_url',
			'https://www.wpzoom.com/frame/inspiro-starter-sites.json'
		);

		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache the failure briefly so we don't hammer the endpoint on every page load.
			set_transient( $cache_key, array(), HOUR_IN_SECONDS );
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['demos'] ) || ! is_array( $data['demos'] ) ) {
			set_transient( $cache_key, array(), HOUR_IN_SECONDS );
			return array();
		}

		$demos = array();
		foreach ( $data['demos'] as $demo ) {
			if ( empty( $demo['title'] ) || empty( $demo['demo'] ) ) {
				continue;
			}
			$demos[] = array(
				'import_file_name'         => sanitize_text_field( $demo['title'] ),
				'import_preview_image_url' => isset( $demo['image'] ) ? esc_url_raw( $demo['image'] ) : '',
				'preview_url'              => esc_url_raw( $demo['demo'] ),
			);
		}

		// Nothing usable parsed out — fall back rather than caching an empty list for a day.
		if ( empty( $demos ) ) {
			set_transient( $cache_key, array(), HOUR_IN_SECONDS );
			return array();
		}

		$ttl = apply_filters( 'inspiro_starter_sites/premium_demos_cache_ttl', DAY_IN_SECONDS );
		set_transient( $cache_key, $demos, $ttl );

		return $demos;
	}

	/**
	 * Bundled premium demos list, used as a fallback when the remote list
	 * is unavailable.
	 *
	 * @return array
	 */
	private function get_fallback_premium_demos() {
		return array(
					array(
						'import_file_name'         => 'Business / Portfolio',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro/',
					),
					array(
						'import_file_name'         => 'Gutenberg (Block Editor)',
						'import_preview_image_url' => 'https://www.wpzoom.com/wp-content/uploads/2024/12/inspiro-pp-blocks-1.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-premium-blocks/',
					),
					array(
						'import_file_name'         => 'STUDIO*',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-studio/home2-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-studio/',
					),
					array(
						'import_file_name'         => 'Movies & TV Shows',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-movie/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-movie/',
					),
					array(
						'import_file_name'         => 'Eccentric',
						'import_preview_image_url' => 'https://demo.wpzoom.com/inspiro-pro-demo/wp-content/themes/inspiro-pro-select/images/default.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-eccentric/',
					),
					array(
						'import_file_name'         => 'Business',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro-pro/business/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-business/',
					),
					array(
						'import_file_name'         => 'Video Portfolio / Agency',
						'import_preview_image_url' => 'https://www.wpzoom.com/wp-content/uploads/2025/01/inspiro-video-1.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-video/',
					),
					array(
						'import_file_name'         => 'Agency / Business',
						'import_preview_image_url' => 'https://www.wpzoom.com/wp-content/uploads/2016/04/agency.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-agency/',
					),
					array(
						'import_file_name'         => 'Video Portfolio (Blocks)',
						'import_preview_image_url' => 'https://www.wpzoom.com/wp-content/uploads/2025/02/inspiro-video-blocks.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-video-blocks/',
					),
					array(
						'import_file_name'         => 'Video Portfolio / Agency 2',
						'import_preview_image_url' => 'https://www.wpzoom.com/wp-content/uploads/2020/04/video2.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-video2/',
					),
					array(
						'import_file_name'         => 'Green Energy',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-energy/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-energy/',
					),
					array(
						'import_file_name'         => 'Fine Dining Restaurant',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-fine-dining-blocks/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-fine-dining-blocks/',
					),
					array(
						'import_file_name'         => 'Cargo & Logistics',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-logistics/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-logistics/',
					),
					array(
						'import_file_name'         => 'Investment Startup',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-investment/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-investment/',
					),
					array(
						'import_file_name'         => 'Moving Company',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-moving/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-moving/',
					),
					array(
						'import_file_name'         => 'Dental Clinic',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-dental/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-dental/',
					),
					array(
						'import_file_name'         => 'Podcast',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-podcast/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-podcast/',
					),
					array(
						'import_file_name'         => 'Remix',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-remix/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-remix/',
					),
					array(
						'import_file_name'         => 'Persona',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-persona/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-persona/',
					),
					array(
						'import_file_name'         => 'Photography / Wedding',
						'import_preview_image_url' => 'https://www.wpzoom.com/wp-content/uploads/2016/04/photo.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-photography/',
					),
					array(
						'import_file_name'         => 'Real Estate',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-real-estate/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-real-estate/',
					),
					array(
						'import_file_name'         => 'Hotel / Real Estate',
						'import_preview_image_url' => 'https://www.wpzoom.com/wp-content/uploads/2024/11/home-hotel-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-hotel/',
					),
					array(
						'import_file_name'         => 'Gear Shop / WooCommerce',
						'import_preview_image_url' => 'https://www.wpzoom.com/wp-content/uploads/2023/07/demo-shop.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-shop/',
					),
					array(
						'import_file_name'         => 'Furniture Shop / WooCommerce',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro-pro/shop/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-furniture/',
					),
					array(
						'import_file_name'         => 'Construction',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-construction/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-construction/',
					),
					array(
						'import_file_name'         => 'Construction (Light)',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-construction-light/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-construction-light/',
					),
					array(
						'import_file_name'         => 'Fitness / Gym',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro-pro/fitness/fitness-home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-fitness/',
					),
					array(
						'import_file_name'         => 'Agency / Business #2',
						'import_preview_image_url' => 'https://demo.wpzoom.com/inspiro-pro-demo/wp-content/themes/inspiro-pro-select/images/site-layout_agency-dark.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-agency2/',
					),
					array(
						'import_file_name'         => 'Finance / Tech',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-finance/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-finance/',
					),
					array(
						'import_file_name'         => 'Insurance Company',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-insurance/thumbs/home.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-insurance/',
					),
					array(
						'import_file_name'         => 'Lawyer / Law Firm',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-lawyer/thumbs/home.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-lawyer/',
					),
					array(
						'import_file_name'         => 'Jewelry Shop / WooCommerce',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/shop2/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-jewelry2/',
					),
					array(
						'import_file_name'         => 'Wellness / Spa',
						'import_preview_image_url' => 'https://www.wpzoom.com/wp-content/uploads/2023/09/demo-wellness.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-wellness/',
					),
					array(
						'import_file_name'         => 'Medical / Doctor',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-medical/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-medical/',
					),
					array(
						'import_file_name'         => 'Kids Summer Camp',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-scout/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-kids/',
					),
					array(
						'import_file_name'         => 'Architecture',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-architecture/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-architecture/',
					),
					array(
						'import_file_name'         => 'Wedding Photographer',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/wedding/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-wedding-photography/',
					),
					array(
						'import_file_name'         => 'Food Blog',
						'import_preview_image_url' => 'https://www.wpzoom.com/wp-content/uploads/2024/12/inspiro-recipe-1.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-recipe/',
					),
					array(
						'import_file_name'         => 'Coffee Shop',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-coffee/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-coffee-shop/',
					),
					array(
						'import_file_name'         => 'Winery',
						'import_preview_image_url' => 'https://demo.wpzoom.com/inspiro-pro-demo/wp-content/themes/inspiro-pro-select/images/site-layout_winery.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-pro-winery/',
					),
					array(
						'import_file_name'         => 'Restaurant / Café',
						'import_preview_image_url' => 'https://www.wpzoom.com/wp-content/uploads/2020/11/inspiro-restaurant-2-1.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-restaurant/',
					),
					array(
						'import_file_name'         => 'Education / University',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-education/thumbs/home.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-school/',
					),
					array(
						'import_file_name'         => 'Events / Conference',
						'import_preview_image_url' => 'https://www.wpzoom.com/wp-content/uploads/2019/06/inspiro-events-conference.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-event/',
					),
					array(
						'import_file_name'         => 'NowMag Magazine',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-nowmag/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-nowmag/',
					),
					array(
						'import_file_name'         => 'Magazine',
						'import_preview_image_url' => 'https://www.wpzoom.com/wp-content/uploads/2023/09/inspiro-magazine.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-magazine/',
					),
					array(
						'import_file_name'         => 'Car Rental',
						'import_preview_image_url' => 'https://www.wpzoom.com/wp-content/uploads/2023/09/inspiro-rent.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-rent/',
					),
					array(
						'import_file_name'         => 'Author / Coach',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-author/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-author/',
					),
					array(
						'import_file_name'         => 'Church',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-church/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-church/',
					),
					array(
						'import_file_name'         => 'Charity / NGO',
						'import_preview_image_url' => 'https://demo.wpzoom.com/inspiro-pro-demo/wp-content/themes/inspiro-pro-select/images/inspiro-pro-char.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-pro-charity/',
					),
					array(
						'import_file_name'         => 'Music Band (One-pager)',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-band/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-band/',
					),
					array(
						'import_file_name'         => 'Freelancer (One-pager)',
						'import_preview_image_url' => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-freelancer/home-thumb.png',
						'preview_url'              => 'https://demo.wpzoom.com/inspiro-freelancer2/',
					),
		);
	}


	public function after_import_setup( $selected_import ) {
		// Assign menus to their locations.
		$main_menu = get_term_by( 'name', 'Main', 'nav_menu' );

		set_theme_mod( 'nav_menu_locations', [
				'primary' => $main_menu->term_id, // replace 'main-menu' here with the menu location identifier from register_nav_menu() function in your theme.
			]
		);

		// Assign front page and posts page (blog page).
		$front_page = self::get_page_by_title( 'Homepage' );
		$blog_page  = self::get_page_by_title( 'Blog' );

		if ( $front_page || $blog_page ) {
			update_option( 'show_on_front', 'page' );
		}
		if ( $front_page ) {
			update_option( 'page_on_front', $front_page->ID );
		}
		if ( $blog_page ) {
			update_option( 'page_for_posts', $blog_page->ID );
		}

		// Set demo layout option based on imported demo
		if ( isset( $selected_import['import_id'] ) ) {
			// Extract demo name from import_id (e.g., 'inspiro-lite-remix' -> 'remix')
			$demo_layout = '';
			
			switch ( $selected_import['import_id'] ) {
				case 'inspiro-lite-remix':
					$demo_layout = 'remix';
					break;
				case 'inspiro-lite':
					$demo_layout = 'business';
					break;
				case 'inspiro-lite-blocks':
					$demo_layout = 'business-blocks';
					break;
				case 'inspiro-lite-woo':
					$demo_layout = 'woocommerce';
					break;
				case 'inspiro-lite-medical':
					$demo_layout = 'medical';
					break;
				case 'inspiro-lite-freelancer':
					$demo_layout = 'freelancer';
					break;
				case 'inspiro-lite-freelancer-grey':
					$demo_layout = 'freelancer-grey';
					break;
				case 'inspiro-lite-finance':
					$demo_layout = 'finance';
					break;
				case 'inspiro-lite-recipe-blocks':
					$demo_layout = 'recipe';
					break;
				case 'inspiro-lite-magazine':
					$demo_layout = 'magazine';
					break;
				case 'inspiro-lite-persona':
					$demo_layout = 'persona';
					break;
                case 'inspiro-lite-energy':
                    $demo_layout = 'energy-blocks';
                    break;
				// Add more demo mappings as needed
			}
			
			if ( ! empty( $demo_layout ) ) {
				update_option( 'inspiro_demo_layout', $demo_layout );
			}
		}

		if ( did_action( 'elementor/loaded' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

	}

	public static function get_page_by_title( $page_title ) {

		// Prefer slug lookup — it's how the WXR importer identifies the page
		// (e.g. Homepage → 'homepage'), and avoids matching against any
		// stale starter-content page with the same title.
		$slug = sanitize_title( $page_title );
		$page = get_page_by_path( $slug );
		if ( $page ) {
			return $page;
		}

		$posts = get_posts(
			array(
				'post_type'              => 'page',
				'title'                  => $page_title,
				'post_status'            => 'publish',
				'numberposts'            => 1,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'orderby'                => 'post_date ID',
				'order'                  => 'ASC',
			)
		);

		return ! empty( $posts ) ? $posts[0] : null;
	}


	/*
	 * Register new menu for the demo importer
	*/
	public function new_menu() {
		
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
