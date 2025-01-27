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

		// Composer autoloader.
		require_once INSPIRO_STARTER_SITES_PATH . 'components/importer/vendor/autoload.php';

		// Instantiate the demo importer class.
		$iss_import = Inspiro\Starter_Sites\WpzoomImporter::get_instance();


		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		if ( 'Inspiro' == $theme_name && ! class_exists( 'WPZOOM' ) ) {
			add_action( 'admin_menu', array( $this, 'add_prevent_conflict_menu_item' ) );
        	add_action( 'admin_init', array( $this, 'redirect_page' ) );
			add_action( 'admin_print_styles', array( $this, 'add_css_hide_duplicate_menu' ) );
		}

	}

	public function enqueue_scripts( $hook ) {		

		if ( 'inspiro_page_inspiro-demo' !== $hook && 'appearance_page_inspiro-starter-sites' !== $hook ) {
			return;
		}
		
		wp_enqueue_script( 
			'inspiro-starter-sites-admin', 
			INSPIRO_STARTER_SITES_URL . 'components/importer/assets/js/admin.js',
			array( 'jquery' ), 
			INSPIRO_STARTER_SITES_VERSION, 
			true 
		);

		wp_enqueue_script( 'jquery-ui' );
		wp_enqueue_script( 'jquery-ui-tabs' );

		wp_enqueue_style( 
			'inspiro-starter-sites-admin', 
			INSPIRO_STARTER_SITES_URL . 'components/importer/assets/css/admin.css', 
			array(),
			INSPIRO_STARTER_SITES_VERSION 
		);
	}

	/**
     * Add admin page
     */
    public function add_prevent_conflict_menu_item() {
        add_submenu_page(
            'themes.php',
            esc_html__( 'Inspiro Starter Sites', 'inspiro-starter-sites' ),
            esc_html__( 'Inspiro Starter Sites', 'inspiro-starter-sites' ),
            'manage_options',
            'inspiro-starter-sites',
            '__return_null' // Avoid output by returning null.
        );
    }

    /**
     * Redirect to the correct page
     */
    public function redirect_page() {
        global $pagenow;

        // Verify if we are on the correct page and sanitize the input.
        $is_dashboard_page = (
            'themes.php' === $pagenow &&
            isset( $_GET['page'] ) &&
            'inspiro-starter-sites' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        );

        if ( $is_dashboard_page ) {
            wp_redirect( admin_url( 'admin.php?page=inspiro-demo' ) );
            exit; // Prevent further execution.
        }
    }


	/**
	 * Add CSS to hide duplicate menu
	 */
	public function add_css_hide_duplicate_menu() {
		?>
		<style>
			#menu-appearance a[href="themes.php?page=inspiro-starter-sites"] {
				display: none;
			}
		</style>
		<?php
	}


}

new Inspiro_Starter_Sites_Importer();
