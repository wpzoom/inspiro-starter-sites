<?php
/**
 * Register admin menu elements.
 *
 * @since   1.0.0
 * @package WPZOOM_Inspiro_Starter_Sites
 */

namespace Inspiro\Starter_Sites;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class for admin menu.
 */
class Inspiro_Starter_Sites_Admin_Menu {

	
	/**
	 * The Constructor.
	 */
	public function __construct() {

		$current_theme  = wp_get_theme();
		$theme_name     = $current_theme->get( 'Name' );
        $theme_template = get_template();

		// Remove Inspiro Lite Demo menu item
		add_action( 'admin_menu', array( $this, 'remove_inspiro_demo_page' ), 999 );

		// Add plugin action links
		add_action( 'plugin_action_links_' . INSPIRO_STARTER_SITES_PLUGIN_BASE, array( $this, 'plugin_action_links' ) );

		if( 'Inspiro' == $theme_name || 'inspiro' == $theme_template ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		}

	}

	/**
	 * Register admin menus.
	 */
	public function register_menus() {

		// Add the "Import Demo" submenu page
		add_submenu_page( // phpcs:ignore WPThemeReview.PluginTerritory.NoAddAdminPages.add_menu_pages_add_submenu_page
			'inspiro',                   // parent slug
			esc_html__( 'Import Demo', 'inspiro-starter-sites' ),      // page title
			esc_html__( 'Import Demo', 'inspiro-starter-sites' ),      // menu title
			'manage_options',              // capability
			'inspiro-demo',            // menu slug,
			array( $this, 'admin_page' )               // callback function
		);

	}

	/**
	 * Admin page.
	 *
	 * @since 1.0.0
	 */
	public function admin_page() {
		do_action( 'inspiro_starter_sites_admin_page' );
	}
	
	/**
	 * Demo content page.
	 *
	 * @since 1.0.0
	 */
	public function demo_content_page() {
		do_action( 'inspiro_starter_sites_demo_content_page' );
	}

	/**
	 * About the plugin page.
	 *
	 * @since 1.0.0
	 */
	public function about_the_plugin_page() {
		do_action( 'inspiro_starter_sites_about_page' );
	}

	/**
	 * Enqueue scripts.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_scripts( $hook ) {
		wp_enqueue_style( 
			'inspiro-starter-sites-admin-menu', 
			INSPIRO_STARTER_SITES_URL . 'components/importer/assets/css/menu.css', 
			array(),
			INSPIRO_STARTER_SITES_VERSION 
		);

	}	

	/**
	 * Remove Inspiro Lite Demo page.
	 *
	 * @since 1.0.0
	 */
	public function remove_inspiro_demo_page() {
		global $menu, $submenu;
	
		$custom_page_slug = 'inspiro-demo';
	
		// Check and remove top-level menu
		foreach ( $menu as $key => $menu_item ) {
			if (isset($menu_item[2]) && $menu_item[2] === "admin.php?page=$custom_page_slug") {
				unset($menu[$key]); // Remove the menu
				return;
			}
		}
		// Check and remove submenu if it exists
		foreach ($submenu as $parent_slug => $submenu_items) {
			foreach ($submenu_items as $key => $submenu_item) {
				if (isset($submenu_item[2]) && $submenu_item[2] === $custom_page_slug) {
					unset($submenu[$parent_slug][$key]); // Remove the submenu item
					return;
				}
			}
		}
	
	}
	

	/**
	 * Add settings and go PRO link to plugin page.
	 *
	 * @param array $links Array of links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {

		$plugin_import_page = Helpers::get_plugin_page_setup_data();

		if ( ! $plugin_import_page ) {
			return $links;
		}
		if( ! isset( $plugin_import_page['menu_slug'] ) ) {
			return $links;
		}

		$import_page_url = admin_url( 'themes.php?page=' . $plugin_import_page['menu_slug'] );

		if( isset( $plugin_import_page['parent_slug'] ) && 'inspiro' == $plugin_import_page['parent_slug'] ) {
			$import_page_url = admin_url( 'admin.php?page=' . $plugin_import_page['menu_slug'] );
		}

		// Settings link
		$import_page_link = '<a href="' . esc_url( $import_page_url ) . '">' . esc_html__( 'Open Demo Importer', 'inspiro-starter-sites' ) . '</a>';

		// Add import_page link to the array
		array_unshift( $links, $import_page_link );

		return $links;

		//Add Go Pro link if the plugin is not active
		if( ! defined( 'INSPIRO_STARTER_SITES_PRO_VERSION' ) ) {
			$links['go_pro'] = sprintf( 
				'<a href="%1$s" target="_blank" class="inspiro-starter-sites-gopro" style="color:#0BB4AA;font-weight:bold;">UPGRADE &rarr; <span class="rcb-premium-badge" style="background-color: #0BB4AA; color: #fff; margin-left: 5px; font-size: 11px; min-height: 16px;  border-radius: 8px; display: inline-block; font-weight: 600; line-height: 1.6; padding: 0 8px">%2$s</span></a>',
				self::$goProLink, 
				esc_html__( 'PRO', 'inspiro-starter-sites' )
			);
		}


	}

	// Add Go Pro link to the Portfolio menu
	public function plugin_add_go_pro_link_to_menu() {
		global $submenu;

		// Add Go Pro link to the Portfolio menu
		if( ! defined( 'INSPIRO_STARTER_SITES_PRO_VERSION' ) ) {
			$submenu[ INSPIRO_STARTER_SITES_SETTINGS_PAGE ][] = array( 
				'' . esc_html__( 'UPGRADE &rarr;', 'inspiro-starter-sites' ) . '',
				'manage_options', 
				self::$goProLink 
			);
		}
	}

}

new Inspiro_Starter_Sites_Admin_Menu();