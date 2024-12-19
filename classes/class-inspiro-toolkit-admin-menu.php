<?php
/**
 * Register admin menu elements.
 *
 * @since   1.0.0
 * @package WPZOOM_Inspiro_Toolkit
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class for admin menu.
 */
class Inspiro_Toolkit_Admin_Menu {

	/**
	 * Go Pro link.
	 *
	 * @var string
	 */
	private static $goProLink = '#link_to_pro_version';

	/**
	 * The Constructor.
	 */
	public function __construct() {

		// Let's add menu item with subitems
		add_action( 'admin_menu', array( $this, 'register_menus' ), 15 );
		add_action( 'plugin_action_links_' . INSPIRO_TOOLKIT_PLUGIN_BASE, array( $this, 'plugin_action_links' ) );
		
		// Add go PRO link to plugin page.
		add_action( 'admin_menu', array( $this, 'plugin_add_go_pro_link_to_menu' ), 15 );
		add_action( 'admin_head', array( $this, 'add_css_go_pro_menu' ) );
		add_action( 'admin_footer', array( $this, 'add_target_blank_go_pro_menu' ) );

	}

	/**
	 * Register admin menus.
	 */
	public function register_menus() {
		
		$page_title = esc_html__( 'Inspiro Toolkit Settings Page', 'inspiro-toolkit' );

		// Main menu item.
		add_menu_page(
			$page_title,
			esc_html__( 'Inspiro Toolkit', 'inspiro-toolkit' ),
			'manage_options',
			INSPIRO_TOOLKIT_SETTINGS_PAGE,
			array( $this, 'admin_page' ),
			'dashicons-admin-tools',
			78
		);

		// //Demo content menu item
		// add_submenu_page(
		// 	INSPIRO_TOOLKIT_SETTINGS_PAGE,
		// 	esc_html__( 'Demo Content', 'inspiro-toolkit'),
		// 	esc_html__( 'Demo Content', 'inspiro-toolkit'),
		// 	'manage_options',
		// 	INSPIRO_TOOLKIT_SETTINGS_PAGE,
		// 	array( $this, 'demo_content_page' ),
		// 	5
		// );

		//About the plugin menu item
		add_submenu_page(
			INSPIRO_TOOLKIT_SETTINGS_PAGE,
			esc_html__( 'About the Inspiro Toolkit', 'inspiro-toolkit'),
			esc_html__( 'About', 'inspiro-toolkit'),
			'manage_options',
			'inspiro-toolkit-about',
			array( $this, 'about_the_plugin_page' ),
			6
		);

	}

	/**
	 * Admin page.
	 *
	 * @since 1.0.0
	 */
	public function admin_page() {
		do_action( 'inspiro_toolkit_admin_page' );
	}
	
	/**
	 * Demo content page.
	 *
	 * @since 1.0.0
	 */
	public function demo_content_page() {
		do_action( 'inspiro_toolkit_demo_content_page' );
	}

	/**
	 * About the plugin page.
	 *
	 * @since 1.0.0
	 */
	public function about_the_plugin_page() {
		do_action( 'inspiro_toolkit_about_page' );
	}

	/**
	 * Add settings and go PRO link to plugin page.
	 *
	 * @param array $links Array of links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {

		// Settings link
		$settings_link = '<a href="' . admin_url( 'admin.php?page=' . INSPIRO_TOOLKIT_SETTINGS_PAGE ) . '">' . esc_html__( 'Dashboard', 'inspiro-toolkit' ) . '</a>';

		// Add settings link to the array
		array_unshift( $links, $settings_link );

		// Add Go Pro link if the plugin is not active
		if( ! defined( 'INSPIRO_TOOLKIT_PRO_VERSION' ) ) {
			$links['go_pro'] = sprintf( 
				'<a href="%1$s" target="_blank" class="inspiro-toolkit-gopro" style="color:#0BB4AA;font-weight:bold;">UPGRADE &rarr; <span class="rcb-premium-badge" style="background-color: #0BB4AA; color: #fff; margin-left: 5px; font-size: 11px; min-height: 16px;  border-radius: 8px; display: inline-block; font-weight: 600; line-height: 1.6; padding: 0 8px">%2$s</span></a>',
				self::$goProLink, 
				esc_html__( 'PRO', 'inspiro-toolkit' )
			);
		}

		return $links;

	}

	// Add Go Pro link to the Portfolio menu
	public function plugin_add_go_pro_link_to_menu() {
		global $submenu;

		// Add Go Pro link to the Portfolio menu
		if( ! defined( 'INSPIRO_TOOLKIT_PRO_VERSION' ) ) {
			$submenu[ INSPIRO_TOOLKIT_SETTINGS_PAGE ][] = array( 
				'' . esc_html__( 'UPGRADE &rarr;', 'inspiro-toolkit' ) . '',
				'manage_options', 
				self::$goProLink 
			);
		}
	}

	/**
	 * Add CSS to Go Pro link.
	 */
	public function add_css_go_pro_menu() {
		?>
		<style>
			#adminmenu #toplevel_page_inspiro-toolkit-demo-import a[href="<?php echo self::$goProLink; ?>"] {
				color: #0BB4AA;
				font-weight: bold;
			}
		</style>
		<?php
	}

	/**
	 * Add target="_blank" to Go Pro link.
	 */
	public function add_target_blank_go_pro_menu() {
		?>
		<script>
			jQuery( document ).ready( function( $ ) {
				$('a[href$="<?php echo self::$goProLink; ?>"]').attr('target', '_blank');				
			});
		</script>
		<?php
	}

}

new Inspiro_Toolkit_Admin_Menu();