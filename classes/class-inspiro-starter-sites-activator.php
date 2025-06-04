<?php
/**
 * Fired during plugin activation.
 *
 * @since   1.0.0
 * @package WPZOOM_Inspiro_Starter_Sites
 */

/**
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since 1.0.0
 */

namespace Inspiro\Starter_Sites;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class for plugin activator.
 */
final class Inspiro_Starter_Sites_Activator {

/**
	 * Initialize hooks.
	 *
	 * @since 2.2.0
	 * @return void
	 */
	public static function init() {

		// Activation
		register_activation_hook( INSPIRO_STARTER_SITES__FILE__, array( __CLASS__, 'activate' ) );

		// Deactivation
		register_deactivation_hook( INSPIRO_STARTER_SITES__FILE__, array( __CLASS__, 'deactivate' ) );

		// Hook to handle redirection after activation
		add_action( 'admin_init', array( __CLASS__, 'inspiro_starter_sites_activation_redirect') );

	}

	/**
	 * Execute this on activation of the plugin.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		/**
		 * Allow developers to hook activation.
		 *
		 * @see inspiro_starter_sites_activate
		 */
		$activate = apply_filters( 'inspiro_starter_sites_activate', true );

		if ( $activate ) {
			update_option( 'inspiro_starter_sites_activation_redirect', true );
		}
	}

	/**
	 * Execute this on deactivation of the plugin.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		/**
		 * Allow developers to hook deactivation.
		 *
		 * @see inspiro_starter_sites_deactivate
		 */
		$deactivate = apply_filters( 'inspiro_starter_sites_deactivate', true );

		if ( $deactivate ) {
			delete_option( 'inspiro_starter_sites_activation_redirect' );

		}
	}

	// Redirect to the settings page after activation
	public static function inspiro_starter_sites_activation_redirect() {

		$plugin_import_page = Helpers::get_plugin_page_setup_data();

		$import_page_url = 'themes.php?page=' . $plugin_import_page['menu_slug'];

		if( isset( $plugin_import_page['parent_slug'] ) && 'inspiro' == $plugin_import_page['parent_slug'] ) {
			if ( get_option( 'inspiro_starter_sites_activation_redirect', false ) ) {
				delete_option( 'inspiro_starter_sites_activation_redirect' );
			}
			return;
		}

		if ( get_option( 'inspiro_starter_sites_activation_redirect', false ) ) {
			delete_option( 'inspiro_starter_sites_activation_redirect' );
			wp_safe_redirect( $import_page_url );
			exit;
		}
	}

}

Inspiro_Starter_Sites_Activator::init();