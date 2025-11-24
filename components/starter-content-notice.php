<?php
/**
 * Starter Content Notice
 *
 * Display a notice on the Import Demo page when default starter content is detected
 *
 * @package Inspiro_Starter_Sites
 * @since 1.0.0
 */

namespace Inspiro\Starter_Sites;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class for starter content notice
 */
class Starter_Content_Notice {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'inspiro_starter_sites_admin_page', array( $this, 'display_notice' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_inspiro_dismiss_starter_content_notice', array( $this, 'dismiss_notice' ) );
	}

	/**
	 * Display notice if starter content is detected
	 */
	public function display_notice() {
		// Check if we're on the inspiro-demo page
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'inspiro-demo' ) === false ) {
			return;
		}

		// Check if notice has been dismissed
		if ( get_user_meta( get_current_user_id(), 'inspiro_starter_content_notice_dismissed', true ) ) {
			return;
		}

		// Check if the theme has the detection function
		if ( ! function_exists( 'inspiro_has_starter_content' ) ) {
			return;
		}

		// Check if starter content exists
		if ( ! inspiro_has_starter_content() ) {
			return;
		}

		// Display the notice
		?>
		<div class="notice notice-warning is-dismissible inspiro-starter-content-notice" style="margin: 20px 0; padding: 15px; border-left: 4px solid #ffba00;">
			<h3 style="margin-top: 0;"><?php esc_html_e( 'Default Starter Content Detected', 'inspiro-starter-sites' ); ?></h3>
			<p>
				<?php
				esc_html_e(
					'We detected that you have the default WordPress starter content installed (Homepage, About, Contact pages and widgets). Before importing a demo, we recommend removing this content to avoid conflicts and duplicate pages.',
					'inspiro-starter-sites'
				);
				?>
			</p>
			<p style="background: #fff3cd; padding: 10px; border-left: 3px solid #856404;">
				<strong><?php esc_html_e( '⚠️ Important:', 'inspiro-starter-sites' ); ?></strong>
				<?php
				esc_html_e(
					'These pages will be permanently deleted even if you have modified their content. If you have made changes to any of these pages, please back them up before proceeding.',
					'inspiro-starter-sites'
				);
				?>
			</p>
			<p>
				<strong><?php esc_html_e( 'What will be deleted:', 'inspiro-starter-sites' ); ?></strong>
			</p>
			<ul style="list-style: disc; margin-left: 20px;">
				<li><?php esc_html_e( 'Pages: Homepage, About, Contact, Blog', 'inspiro-starter-sites' ); ?></li>
				<li><?php esc_html_e( 'Menu: Main Menu', 'inspiro-starter-sites' ); ?></li>
				<li><?php esc_html_e( 'Widgets from sidebar and footer areas', 'inspiro-starter-sites' ); ?></li>
				<li><?php esc_html_e( 'Front page settings will be reset', 'inspiro-starter-sites' ); ?></li>
			</ul>
			<p>
				<button type="button" class="button button-primary" id="inspiro-delete-starter-content" style="margin-right: 10px;">
					<?php esc_html_e( 'Delete Starter Content', 'inspiro-starter-sites' ); ?>
				</button>
				<button type="button" class="button button-secondary inspiro-dismiss-notice">
					<?php esc_html_e( 'Keep It & Continue', 'inspiro-starter-sites' ); ?>
				</button>
				<span class="spinner" style="float: none; margin: 0 10px;"></span>
				<span class="inspiro-delete-result" style="margin-left: 10px;"></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Enqueue scripts for the notice
	 */
	public function enqueue_scripts( $hook ) {
		// Only enqueue on the inspiro-demo page
		if ( strpos( $hook, 'inspiro-demo' ) === false ) {
			return;
		}

		// Check if starter content exists
		if ( ! function_exists( 'inspiro_has_starter_content' ) || ! inspiro_has_starter_content() ) {
			return;
		}

		wp_enqueue_script(
			'inspiro-starter-content-notice',
			plugin_dir_url( __FILE__ ) . 'assets/starter-content-notice.js',
			array( 'jquery' ),
			'1.0.0',
			true
		);

		wp_localize_script(
			'inspiro-starter-content-notice',
			'inspiroStarterContent',
			array(
				'nonce'         => wp_create_nonce( 'inspiro_delete_starter_content' ),
				'dismissNonce'  => wp_create_nonce( 'inspiro_dismiss_starter_content_notice' ),
				'ajaxurl'       => admin_url( 'admin-ajax.php' ),
				'deleting'      => esc_html__( 'Deleting starter content...', 'inspiro-starter-sites' ),
				'success'       => esc_html__( 'Starter content deleted successfully!', 'inspiro-starter-sites' ),
				'error'         => esc_html__( 'Error deleting starter content. Please try again.', 'inspiro-starter-sites' ),
			)
		);
	}

	/**
	 * Handle notice dismissal
	 */
	public function dismiss_notice() {
		check_ajax_referer( 'inspiro_dismiss_starter_content_notice', 'nonce' );

		update_user_meta( get_current_user_id(), 'inspiro_starter_content_notice_dismissed', true );

		wp_send_json_success();
	}
}

// Initialize the notice
new Starter_Content_Notice();