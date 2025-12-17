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

		add_filter( 'inspiro_starter_sites_premium_demos', array( $this, 'premium_demos' ) );

		if ( ( 'Inspiro' == $theme_name || 'inspiro' == $theme_template ) && ! class_exists( 'WPZOOM' ) ) {
			add_filter( 'inspiro_starter_sites/plugin_page_setup', array( $this, 'new_menu' ) );
		}
		
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

		} elseif ( $import_step === '4' || $import_step === '5' || $import_step === '9' || $import_step === '10' ) {

            $theme_plugins[] =  [
                'name'     => 'WPZOOM Portfolio',
                'slug'     => 'wpzoom-portfolio',
                'desc'     => 'Showcases your projects in a professional and visually appealing portfolio layout.',
                'required' => true,
            ];

            $theme_plugins[] =  [
                'name'     => 'The Icon Block',
                'slug'     => 'icon-block',
                'desc'     => 'Easily add SVG icons and graphics to the WordPress block editor.',
                'required' => true,
            ];
        } elseif ( $import_step === '3' || $import_step === '6' ) {

            $theme_plugins[] =  [
                'name'     => 'The Icon Block',
                'slug'     => 'icon-block',
                'desc'     => 'Easily add SVG icons and graphics to the WordPress block editor.',
                'required' => true,
            ];
        } elseif ( $import_step === '7' ) {

            $theme_plugins[] =  [
                'name'     => 'Recipe Card Blocks',
                'slug'     => 'recipe-card-blocks-by-wpzoom',
                'desc'     => 'Beautiful Recipe Card Blocks for Food Bloggers with Schema Markup (JSON-LD) for the new WordPress editor (Gutenberg).',
                'required' => true,
            ];
        } elseif ( $import_step === '8' ) {

            $theme_plugins[] =  [
                'name'     => 'Makeiteasy Slider',
                'slug'     => 'makeiteasy-slider',
                'desc'     => 'Block based slider, leverages the speed and versatility of the Swiper slider.',
                'required' => true,
            ];
        } elseif ( $import_step === '11' ) {

            $theme_plugins[] =  [
                'name'     => 'Image Slider Block',
                'slug'     => 'slider-block',
                'desc'     => 'Display Multiple Images In Beautiful Slider & Reduce Page Scroll.',
                'required' => true,
            ];

            $theme_plugins[] =  [
                'name'     => 'WPZOOM Portfolio',
                'slug'     => 'wpzoom-portfolio',
                'desc'     => 'Showcases your projects in a professional and visually appealing portfolio layout.',
                'required' => true,
            ];

            $theme_plugins[] =  [
                'name'     => 'The Icon Block',
                'slug'     => 'icon-block',
                'desc'     => 'Easily add SVG icons and graphics to the WordPress block editor.',
                'required' => true,
            ];
        } elseif ( $import_step === '12' ) {

            $theme_plugins[] =  [
                'name'     => 'Image Slider Block',
                'slug'     => 'slider-block',
                'desc'     => 'Display Multiple Images In Beautiful Slider & Reduce Page Scroll.',
                'required' => true,
            ];

            $theme_plugins[] =  [
                'name'     => 'WPZOOM Portfolio',
                'slug'     => 'wpzoom-portfolio',
                'desc'     => 'Showcases your projects in a professional and visually appealing portfolio layout.',
                'required' => true,
            ];

            $theme_plugins[] = [
                'name'     => 'Video Popup Block by WPZOOM',
                'slug'     => 'wpzoom-video-popup-block',
                'desc'     => 'Enables you to embed engaging video popups on your WordPress site effortlessly.',
                'required' => true,
            ];

            $theme_plugins[] =  [
                'name'     => 'The Icon Block',
                'slug'     => 'icon-block',
                'desc'     => 'Easily add SVG icons and graphics to the WordPress block editor.',
                'required' => true,
            ];
        }
    }
		return array_merge( $plugins, $theme_plugins );
	  
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
			],
			[	
				'import_id'                  => 'inspiro-lite',
				'import_file_name'           => 'Business / Portfolio (Elementor)',
				'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite.xml',
				'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-widgets.wie',
				'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-elementor.dat',
				'import_preview_image_url'   => $demos_preview_url .  'inspiro-lite-elementor-1.png',
				'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite/',
			],
			[	
				'import_id'                  => 'inspiro-lite-woo',
				'import_file_name'           => 'WooCommerce Shop',
				'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-woo.xml',
				'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-woo-widgets.wie',
				'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-woo.dat',
				'import_preview_image_url'   => $demos_preview_url .  'inspiro-lite-woo.png',
				'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-woo/',
			],
            [
                'import_id'                  => 'inspiro-lite-medical',
                'import_file_name'           => 'Medical / Doctor (Lite)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-medical.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/medical.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-medical.dat',
                'import_preview_image_url'   => $demos_preview_url .  'medical.png',
                'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-medical/',
            ],
            [
                'import_id'                  => 'inspiro-lite-freelancer',
                'import_file_name'           => 'Freelancer (One-Page)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-freelancer.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/freelancer.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-freelancer1.dat',
                'import_preview_image_url'   => $demos_preview_url .  'freelancer.png',
                'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-freelancer/',
            ],
            [
                'import_id'                  => 'inspiro-lite-freelancer-grey',
                'import_file_name'           => 'Freelancer #2 (One-Page)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-freelancer2.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/freelancer2.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-freelancer2.dat',
                'import_preview_image_url'   => $demos_preview_url .  'freelancer2.png',
                'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-freelancer-blocks/',
            ],
            [
                'import_id'                  => 'inspiro-lite-finance',
                'import_file_name'           => 'Finance / Tech (Lite)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-finance.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/finance.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-finance.dat',
                'import_preview_image_url'   => $demos_preview_url .  'finance.png',
                'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-finance/',
            ],
			[
                'import_id'                  => 'inspiro-lite-recipe-blocks',
                'import_file_name'           => 'Food Blog (Lite)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-recipe-blocks.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/recipe-blocks.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-recipe-blocks.dat',
                'import_preview_image_url'   => $demos_preview_url .  'recipe-blocks.png',
                'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-recipe-blocks/',
            ],
            [
                'import_id'                  => 'inspiro-lite-magazine',
                'import_file_name'           => 'Magazine',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-magazine.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-magazine.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-magazine.dat',
                'import_preview_image_url'   => $demos_preview_url .  'magazine.png',
                'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-magazine/',
            ],
            [
                'import_id'                  => 'inspiro-lite-persona',
                'import_file_name'           => 'Persona Lite (Dark)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-persona.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-persona.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-persona.dat',
                'import_preview_image_url'   => $demos_preview_url .  'persona.png',
                'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-persona/',
            ],
            [
                'import_id'                  => 'inspiro-lite-remix',
                'import_file_name'           => 'Remix (New)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-remix.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-remix.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-remix.dat',
                'import_preview_image_url'   => $demos_preview_url .  'remix.png',
                'preview_url'                => 'https://demo.wpzoom.com/inspiro-lite-remix/',
            ],
            [
                'import_id'                  => 'inspiro-lite-energy',
                'import_file_name'           => 'Green Energy (New)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-energy.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-energy.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-energy.dat',
                'import_preview_image_url'   => $demos_preview_url .  'energy.png',
                'preview_url'                => 'https://inspiro.wpzoom.com/energy/',
            ],
            [
                'import_id'                  => 'inspiro-lite-video',
                'import_file_name'           => 'Video Production (Lite)',
                'import_file_url'            => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-video.xml',
                'import_widget_file_url'     => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-video.wie',
                'import_customizer_file_url' => 'https://www.wpzoom.com/downloads/xml/inspiro-lite-video.dat',
                'import_preview_image_url'   => $demos_preview_url .  'video.png',
                'preview_url'                => 'https://inspiro.wpzoom.com/video/',
            ],
		];
	}

	public function premium_demos() {

		$demos_preview_url     = INSPIRO_STARTER_SITES_URL .'assets/images/preview-demos/inspiro-premium/';

		return array(
			'inspiro-premium' => array(
				'name'     => 'Inspiro Premium',
				'desc'     => 'Below you can view demos available in the Inspiro Premium theme. You can get access to all of them by purchasing the Premium version of the theme.',
				'purchase' => 'https://www.wpzoom.com/themes/inspiro/',
				'demos' => array(
					array(
						'import_file_name'           => 'Business / Portfolio',
						'import_preview_image_url'   => $demos_preview_url .  'home-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro/',
					),
                    array(
                        'import_file_name'           => 'Business / Portfolio (Blocks)',
                        'import_preview_image_url'   => 'https://www.wpzoom.com/wp-content/uploads/2024/12/inspiro-pp-blocks-1.png',
                        'preview_url'                => 'https://demo.wpzoom.com/inspiro-premium-blocks/',
                    ),
                    array(
                        'import_file_name'           => 'Eccentric',
                        'import_preview_image_url'   => 'https://demo.wpzoom.com/inspiro-pro-demo/wp-content/themes/inspiro-pro-select/images/default.png',
                        'preview_url'                => 'https://demo.wpzoom.com/inspiro-eccentric/',
                    ),
                    array(
                        'import_file_name'           => 'Business',
                        'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro-pro/business/home-thumb.png',
                        'preview_url'                => 'https://demo.wpzoom.com/inspiro-business/',
                    ),
                    array(
                        'import_file_name'           => 'Persona',
                        'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-persona/home-thumb.png',
                        'preview_url'                => 'https://demo.wpzoom.com/inspiro-persona/',
                    ),
					array(
						'import_file_name'           => 'Video Production',
						'import_preview_image_url'   => $demos_preview_url . 'home-video-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-video/',
					),
                    array(
                        'import_file_name'           => 'Video Portfolio (Blocks)',
                        'import_preview_image_url'   => 'https://www.wpzoom.com/wp-content/uploads/2025/02/inspiro-video-blocks.png',
                        'preview_url'                => 'https://demo.wpzoom.com/inspiro-video-blocks/',
                    ),
					array(
						'import_file_name'           => 'Video Production #2',
						'import_preview_image_url'   => $demos_preview_url . 'home-video2-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-video2/',
					),
                    array(
                        'import_file_name'           => 'Agency / Business',
                        'import_preview_image_url'   => $demos_preview_url . 'home-agency-thumb.png',
                        'preview_url'                => 'https://demo.wpzoom.com/inspiro-agency/',
                    ),
                    array(
                        'import_file_name'           => 'Agency / Business #2',
                        'import_preview_image_url'   => $demos_preview_url . 'site-layout_agency-dark.png',
                        'preview_url'                => 'https://demo.wpzoom.com/inspiro-agency2/',
                    ),
                    array(
                        'import_file_name'           => 'Construction',
                        'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-construction/home-thumb.png',
                        'preview_url'                => 'https://demo.wpzoom.com/inspiro-construction/',
                    ),
                    array(
                        'import_file_name'           => 'Insurance Company',
                        'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-insurance/thumbs/home.png',
                        'preview_url'                => 'https://demo.wpzoom.com/inspiro-insurance/',
                    ),
                    array(
                        'import_file_name'           => 'Lawyer / Law Firm',
                        'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-lawyer/thumbs/home.png',
                        'preview_url'                => 'https://demo.wpzoom.com/inspiro-lawyer/',
                    ),
                    array(
                        'import_file_name'           => 'Education / University',
                        'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-education/thumbs/home.png',
                        'preview_url'                => 'https://demo.wpzoom.com/inspiro-school/',
                    ),
                    array(
                        'import_file_name'           => 'Finance / Tech',
                        'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-finance/home-thumb.png',
                        'preview_url'                => 'https://demo.wpzoom.com/inspiro-finance/',
                    ),
                    array(
                        'import_file_name'           => 'Shop / WooCommerce',
                        'import_preview_image_url'   => $demos_preview_url .  'shop-home-thumb.png',
                        'preview_url'                => 'https://demo.wpzoom.com/inspiro-shop/',
                    ),
                    array(
                        'import_file_name'           => 'Jewelry Shop / WooCommerce',
                        'import_preview_image_url'   => $demos_preview_url . 'home-thumb5.png',
                        'preview_url'                => 'https://demo.wpzoom.com/inspiro-jewelry2/',
                    ),
                    array(
                        'import_file_name'           => 'Wellness / Spa',
                        'import_preview_image_url'   => $demos_preview_url . 'home-thumb6.png',
                        'preview_url'                => 'https://demo.wpzoom.com/inspiro-wellness/',
                    ),
                    array(
                        'import_file_name'           => 'Medical / Doctor',
                        'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-medical/home-thumb.png',
                        'preview_url'                => 'https://demo.wpzoom.com/inspiro-medical/',
                    ),
					array(
						'import_file_name'           => 'Kids Summer Camp',
						'import_preview_image_url'   => $demos_preview_url . 'home-thumb2.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-kids/',
					),
					array(
						'import_file_name'           => 'Architecture',
						'import_preview_image_url'   => $demos_preview_url . 'home-thumb3.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-architecture/',
					),
					array(
						'import_file_name'           => 'Wedding Photography',
						'import_preview_image_url'   => $demos_preview_url . 'home-thumb4.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-wedding-photography/',
					),
					array(
						'import_file_name'           => 'Photography',
						'import_preview_image_url'   => $demos_preview_url . 'home-photography-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-photography/',
					),
                    array(
                        'import_file_name'           => 'Food Blog',
                        'import_preview_image_url'   => 'https://www.wpzoom.com/wp-content/uploads/2024/12/inspiro-recipe-1.png',
                        'preview_url'                => 'https://demo.wpzoom.com/inspiro-recipe/',
                    ),
                    array(
                        'import_file_name'           => 'Coffee Shop',
                        'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-coffee/home-thumb.png',
                        'preview_url'                => 'https://demo.wpzoom.com/inspiro-coffee-shop/',
                    ),
                    array(
                        'import_file_name'           => 'Winery',
                        'import_preview_image_url'   => 'https://demo.wpzoom.com/inspiro-pro-demo/wp-content/themes/inspiro-pro-select/images/site-layout_winery.png',
                        'preview_url'                => 'https://demo.wpzoom.com/inspiro-pro-winery/',
                    ),
					array(
						'import_file_name'           => 'Hotel / Real Estate',
						'import_preview_image_url'   => $demos_preview_url . 'home-hotel-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-hotel/',
					),
					array(
						'import_file_name'           => 'Restaurant',
						'import_preview_image_url'   => $demos_preview_url . 'home-restaurant-thumb.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-restaurant/',
					),
					array(
						'import_file_name'           => 'Events / Conference',
						'import_preview_image_url'   => $demos_preview_url . 'demo-events.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-event/',
					),
					array(
						'import_file_name'           => 'Magazine',
						'import_preview_image_url'   => $demos_preview_url . 'home-thumb7.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-magazine/',
					),
					array(
						'import_file_name'           => 'Car Rental / Dealer',
						'import_preview_image_url'   => $demos_preview_url . 'inspiro-rent.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-auto/',
					),
					array(
						'import_file_name'           => 'Author / Coach',
						'import_preview_image_url'   => $demos_preview_url . 'home-thumb8.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-author/',
					),
					array(
						'import_file_name'           => 'Church',
						'import_preview_image_url'   => $demos_preview_url . 'home-thumb9.png',
						'preview_url'                => 'https://demo.wpzoom.com/inspiro-church/',
					),
                    array(
                        'import_file_name'           => 'Charity / NGO',
                        'import_preview_image_url'   => 'https://demo.wpzoom.com/inspiro-pro-demo/wp-content/themes/inspiro-pro-select/images/inspiro-pro-char.png',
                        'preview_url'                => 'https://demo.wpzoom.com/inspiro-pro-charity/',
                    ),
                    array(
                        'import_file_name'           => 'Freelancer (One-page)',
                        'import_preview_image_url'   => 'https://wpzoom.s3.us-east-1.amazonaws.com/elementor/templates/assets/thumbs/inspiro/inspiro-freelancer/home-thumb.png',
                        'preview_url'                => 'https://demo.wpzoom.com/inspiro-freelancer2/',
                    ),
				),
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
		$front_page_id = self::get_page_by_title( 'Homepage' );
		$blog_page_id  = self::get_page_by_title( 'Blog' );

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front_page_id->ID );
		update_option( 'page_for_posts', $blog_page_id->ID );

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
