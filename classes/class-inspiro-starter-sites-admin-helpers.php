<?php
/**
 * Register admin menu elements.
 *
 * @since   1.0.0
 * @package WPZOOM_Inspiro_Starter_Sites
 */

namespace WPZI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class for admin helpers.
 */
class Inspiro_Starter_Sites_Admin_Helpers {

	/**
	 * The Constructor.
	 */
	public function __construct() {

		// Disable Elementor Welcome Redirect
		add_action( 'admin_init', array( $this, 'disable_elementor_welcome_redirect' ) );

		// Disable WooCommerce Wizard
		add_filter( 'woocommerce_enable_setup_wizard', '__return_false' );
		add_action( 'admin_init', array( $this, 'woocommerce_setup_wizard_options_update_once' ) );

	}

	/**
	 * Disable Elementor Welcome Redirect
	 */
	public function disable_elementor_welcome_redirect() {
		delete_transient( 'elementor_activation_redirect' );
	}	

	/**
	 * Disable WooCommerce Wizard
	 */
	public function woocommerce_setup_wizard_options_update_once() {

		if ( get_option( 'woocommerce_setup_wizard_options_update_once' ) != 'completed' ) {
  
			update_option( 'woocommerce_task_list_hidden', 'yes' );
			update_option( 'woocommerce_task_list_complete', 'yes' );
			update_option( 'woocommerce_task_list_welcome_modal_dismissed', 'yes' );
			update_option( 'woocommerce_setup_wizard_options_update_once', 'completed' );
		}

	}

}

new Inspiro_Starter_Sites_Admin_Helpers();