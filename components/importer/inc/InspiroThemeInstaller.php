<?php
/**
 * Thems Installer class - responsible for installing Inspiro Theme.
 *
 * @package Inspiro\Starter_Sites
 */

namespace Inspiro\Starter_Sites;

class InspiroThemeInstaller {

	/**
	 * Constructor.
	 */
	public function init() {
		add_action( 'wp_ajax_handle_inspiro_theme', [ $this, 'handle_theme' ] );
	}

	/**
	 * Handles the installation and activation of the Inspiro theme.
	 */
	public function handle_theme() {

		check_ajax_referer( 'inspiro-starter-sites-ajax-verification', 'security' );

		// Verify user permissions
		if ( ! current_user_can( 'install_themes' ) || ! current_user_can( 'switch_themes' ) ) {
			wp_send_json_error( ['message' => 'You do not have sufficient permissions to manage themes.'] );
		}

		// Theme slug for Inspiro
		$theme_slug = 'inspiro';

		// Check if the theme is installed
		$installed_themes = \wp_get_themes();
		
		if ( ! isset( $installed_themes[ $theme_slug ] ) ) {
			
			// Theme is not installed, so install it from the wordpress repository
			$theme_url = 'https://downloads.wordpress.org/theme/inspiro.zip';
			
			$install_result = $this->install_and_activate_theme( $theme_slug, $theme_url, false );
			
			if ( is_wp_error( $install_result ) ) {
				wp_send_json_error( ['message' => $install_result->get_error_message()] );
			}
		}

		// Activate the theme
		switch_theme( $theme_slug );

		// Verify the activation
		if ( get_template() === $theme_slug ) {
			wp_send_json_success( ['message' => 'Inspiro theme installed and activated successfully.'] );
		} else {
			wp_send_json_error( ['message' => 'Failed to activate the Inspiro theme.'] );
		}
	}	
	
	public function install_and_activate_theme( $theme_slug, $theme_url, $activate = false ) {

		// Check if the theme is already installed
		$theme_dir = get_theme_root() . '/' . $theme_slug;
		if ( is_dir( $theme_dir ) ) {
			if ( $activate ) {
				// Activate the theme if requested
				switch_theme( $theme_slug );
				return "Theme '$theme_slug' activated.";
			}
			return "Theme '$theme_slug' is already installed.";
		}
	
		// Ensure the necessary WordPress functionality is available
		if ( ! function_exists( 'download_url' ) || ! function_exists( 'unzip_file' ) ) {
			return 'Required WordPress functions are not available.';
		}
	
		// Download the theme
		$temp_file = download_url( $theme_url );
		if ( is_wp_error( $temp_file ) ) {
			return 'Error downloading theme: ' . $temp_file->get_error_message();
		}
	
		// Unzip the downloaded file to the theme directory
		$theme_root = get_theme_root();
		$result = unzip_file( $temp_file, $theme_root );
		wp_delete_file( $temp_file ); // Delete the temporary file
	
		if ( is_wp_error( $result ) ) {
			return 'Error unzipping theme: ' . $result->get_error_message();
		}
	
		// Activate the theme if requested
		if ( $activate ) {
			switch_theme( $theme_slug );
			return "Theme '$theme_slug' installed and activated.";
		}
	
		return "Theme '$theme_slug' installed.";
	}
	

}
