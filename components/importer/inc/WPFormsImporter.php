<?php
/**
 * Class WPForms importer used in the Inspiro\Starter_Sites Importer.
 *
 * @see https://wordpress.org/plugins/wpforms-lite/
 * @package Inspiro\Starter_Sitesi
 */

namespace Inspiro\Starter_Sites;

class WPFormsImporter {

	/**
	 * The path to the import file.
	 *
	 * @since 3.3.0
	 *
	 * @var string
	 */
	private $import_file_path = false;

	/**
	 * The InspiroStarterSitesImporter instance.
	 *
	 * @since 3.3.0
	 *
	 * @var InspiroStarterSitesImporter
	 */
	private $iss;

	/**
	 * Constructor.
	 *
	 * @since 3.3.0
	 *
	 * @param string $import_file_path The path to the import file.
	 */
	public function __construct( $import_file_path ) {

		$this->import_file_path = $import_file_path;
		$this->iss             = InspiroStarterSitesImporter::get_instance();
	}

	/**
	 * Import WPForms data.
	 *
	 * @since 3.3.0
	 */
	public function import() {

		// WPForms plugin is not active!
		if ( ! class_exists( 'WPForms' ) || ! function_exists( 'wpforms' )  ) {
			$this->log_error( esc_html__( 'The WPForms plugin is not activated, so the WPForms import was skipped!', 'inspiro-starter-sites' ) );
			return;
		}

		$wpforms_api = method_exists( wpforms(), 'obj' ) ? wpforms()->obj( 'api' ) : wpforms()->get("api");

		if ( ! is_a( $wpforms_api, "WPForms\API" ) ) {
			$this->log_error( esc_html__( 'The WPForms plugin\'s version is not >= v1.8.6, so the WPForms import was skipped!', 'inspiro-starter-sites' ) );
			return;
		}

		$import = $wpforms_api->import_forms( $this->import_file_path );

		if ( is_wp_error( $import ) ) {
			$this->log_error( sprintf( 'WPForms import failed: %1$s', $import->get_error_message() ) );
			return;
		}

		Helpers::append_to_file(
			esc_html__( 'WPForms import finished successfully!', 'inspiro-starter-sites' ),
			$this->iss->get_log_file_path(),
			esc_html__( 'Importing WPForms' , 'inspiro-starter-sites' )
		);
	}

	/**
	 * Log error message.
	 *
	 * @since 3.3.0
	 *
	 * @param string $error_message The error message.
	 */
	private function log_error( $error_message ) {

		// Add any error messages to the frontend_error_messages variable in inspiro_starter_sites main class.
		$this->iss->append_to_frontend_error_messages( $error_message );

		// Write error to log file.
		Helpers::append_to_file(
			$error_message,
			$this->iss->get_log_file_path(),
			esc_html__( 'Importing WPForms' , 'inspiro-starter-sites' )
		);
	}
}
