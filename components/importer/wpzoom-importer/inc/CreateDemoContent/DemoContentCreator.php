<?php
/**
 * Create Demo Content - responsible for importing pre-created demo content.
 *
 * @package wpzi
 */

namespace WPZI\CreateDemoContent;

use WPZI\Helpers;
use WPZI\Importer;
use WPZI\Logger;
use WPZI\WpzoomDemoImport;
use WPZI\PluginInstaller;

class DemoContentCreator {

	/**
	 * Holds all pre-created content.
	 *
	 * @var array
	 */
	private $content;

	/**
	 * Initialize everything needed for the demo content creator class to function properly.
	 */
	public function init() {
		$this->set_content();

		add_action( 'wpzi/demo_content_creator_after_import', array( $this, 'after_import_wpforms_setup' ) );

		add_action( 'wp_ajax_wpzi_import_created_content', array( $this, 'import_created_content' ) );
	}

	/**
	 * Get all default pre-created demo content data.
	 *
	 * @return array[]
	 */
	public function get_default_content() {
		return array(
			array(
				'slug'             => 'about-page',
				'file'             => WPZI_PATH . 'assets/demo-content/about-page.xml',
				'name'             => esc_html__( 'About Us', 'inspiro-toolkit' ),
				'description'      => esc_html__( 'Introduce yourself and your business with a clean layout to reassure your leads and customers.', 'inspiro-toolkit' ),
				'required_plugins' => array(),
			),
			array(
				'slug'             => 'book-now-page',
				'file'             => WPZI_PATH . 'assets/demo-content/book-now-page.xml',
				'name'             => esc_html__( 'Book Now', 'inspiro-toolkit' ),
				'description'      => esc_html__( 'Expand your reach by accepting appointments online plus detailing your services and staff.', 'inspiro-toolkit' ),
				'required_plugins' => array(),
			),
			array(
				'slug'             => 'contact-page',
				'file'             => WPZI_PATH . 'assets/demo-content/contact-page.xml',
				'name'             => esc_html__( 'Contact Us', 'inspiro-toolkit' ),
				'description'      => esc_html__( 'Make it easy to get in touch with you through a completely customizable built-in contact form.', 'inspiro-toolkit' ),
				'required_plugins' => array( 'wpforms-lite' ),
			),
			array(
				'slug'             => 'faq-page',
				'file'             => WPZI_PATH . 'assets/demo-content/faq-page.xml',
				'name'             => esc_html__( 'FAQ', 'inspiro-toolkit' ),
				'description'      => esc_html__( 'Lighten the load on your support team or your inbox by addressing frequently asked questions.', 'inspiro-toolkit' ),
				'required_plugins' => array(),
			),
			array(
				'slug'             => 'meet-the-team-page',
				'file'             => WPZI_PATH . 'assets/demo-content/meet-the-team-page.xml',
				'name'             => esc_html__( 'Meet the Team', 'inspiro-toolkit' ),
				'description'      => esc_html__( 'Help potential clients feel more at ease by showing off your hard-working and trustworthy team.', 'inspiro-toolkit' ),
				'required_plugins' => array( 'wpforms-lite' ),
			),
			array(
				'slug'             => 'menu-page',
				'file'             => WPZI_PATH . 'assets/demo-content/menu-page.xml',
				'name'             => esc_html__( 'Menu', 'inspiro-toolkit' ),
				'description'      => esc_html__( 'Display your delicious dishes online to entice website visitors to become restaurant customers.', 'inspiro-toolkit' ),
				'required_plugins' => array(),
			),
			array(
				'slug'             => 'portfolio-page',
				'file'             => WPZI_PATH . 'assets/demo-content/portfolio-page.xml',
				'name'             => esc_html__( 'Portfolio', 'inspiro-toolkit' ),
				'description'      => esc_html__( 'Impress leads by visually showcasing your achievements, case studies, and past work.', 'inspiro-toolkit' ),
				'required_plugins' => array(),
			),
			array(
				'slug'             => 'services-page',
				'file'             => WPZI_PATH . 'assets/demo-content/services-page.xml',
				'name'             => esc_html__( 'Services', 'inspiro-toolkit' ),
				'description'      => esc_html__( 'Let the world know your services or products\' cost and features in an organized pricing table.', 'inspiro-toolkit' ),
				'required_plugins' => array(),
			),
			array(
				'slug'             => 'shop-page',
				'file'             => WPZI_PATH . 'assets/demo-content/shop-page.xml',
				'name'             => esc_html__( 'Shop', 'inspiro-toolkit' ),
				'description'      => esc_html__( 'Categorize and sell your products online while displaying reviews from happy customers.', 'inspiro-toolkit' ),
				'required_plugins' => array(),
			),
			array(
				'file'             => WPZI_PATH . 'assets/demo-content/testimonials-page.xml',
				'slug'             => 'testimonials-page',
				'name'             => esc_html__( 'Testimonials', 'inspiro-toolkit' ),
				'description'      => esc_html__( 'Tap into the power of social proof by displaying real-life testimonials on your website.', 'inspiro-toolkit' ),
				'required_plugins' => array(),
			),
		);
	}

	/**
	 * Set all pre-created demo pages.
	 * With our pre-created pages being set as defaults.
	 */
	public function set_content() {
		$all_content = array_merge( $this->get_default_content(), Helpers::apply_filters( 'wpzi/register_created_demo_content', array() ) );

		$this->content = array_filter(
			$all_content,
			function ( $item ) {
				if ( empty( $item['slug'] ) || empty( $item['name'] ) || empty( $item['file'] ) ) {
					return false;
				}

				return true;
			}
		);
	}

	public function after_import_wpforms_setup( $slug ) {

		// Perform WPForms setup only if this is a contact or the meet the team page import.
		if ( ! in_array( $slug, array( 'contact-page', 'meet-the-team-page' ), true ) ) {
			return;
		}

		// Is WPForms plugin active?
		$plugin_installer = new PluginInstaller();

		if (
			! (
				$plugin_installer->is_plugin_active( 'wpforms-lite' ) ||
				$plugin_installer->is_plugin_active( 'wpforms' )
			)
		) {
			wp_send_json_error( esc_html__( 'Could not complete the import process for this page. Required WPForms plugin is not activated.', 'inspiro-toolkit' ) );
		}

		if ( ! function_exists( 'wpforms' ) ) {
			wp_send_json_error( esc_html__( 'Could not complete the import process for this page. Required WPForms plugin doesn\'t exist.', 'inspiro-toolkit' ) );
		}

		$form_title = ( $slug === 'meet-the-team-page' ) ? esc_html__( 'Meet the Team Form', 'inspiro-toolkit' ) : esc_html__( 'Contact Form', 'inspiro-toolkit' );
		$form_id = $this->create_wpforms_form( $form_title );

		if ( empty( $form_id ) ) {
			wp_send_json_error( esc_html__( 'Could not complete the import process for this page. Something went wrong while creating a WPForms contact form.', 'inspiro-toolkit' ) );
		}

		$update_page = $this->update_contact_page_form_id( $form_id );

		if ( empty( $update_page ) ) {
			wp_send_json_error( esc_html__( 'Could not complete the import process for this page. Could not update the imported page with correct WPForms form ID.', 'inspiro-toolkit' ) );
		}
	}

	/**
	 * AJAX callback for importing the pre-created demo content.
	 * Has to contain the `slug` POST parameter.
	 */
	public function import_created_content() {
		check_ajax_referer( 'wpzi-ajax-verification', 'security' );

		// Check if user has the WP capability to import content.
		if ( ! current_user_can( 'import' ) ) {
			wp_send_json_error( esc_html__( 'Could not import this page. You don\'t have permission to import content.', 'inspiro-toolkit' ) );
		}

		$slug = ! empty( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';

		if ( empty( $slug ) ) {
			wp_send_json_error( esc_html__( 'Could not import this page. Page slug is missing.', 'inspiro-toolkit' ) );
		}

		// Install required plugins.
		$content_item = $this->get_content_data( $slug );
		$wpzi         = WpzoomDemoImport::get_instance();
		$refresh      = false;

		if ( ! empty( $content_item['required_plugins'] ) ) {
			foreach ( $content_item['required_plugins'] as $plugin_slug ) {
				if ( ! $wpzi->plugin_installer->is_plugin_active( $plugin_slug ) ) {
					$wpzi->plugin_installer->install_plugin( $plugin_slug );
					$refresh = true;
				}
			}
		}

		if ( $refresh ) {
			wp_send_json_success( [ 'refresh' => true ] );
		}

		// Import the pre-created page.
		$error = $this->import_content( $slug );

		if ( ! empty( $error ) ) {
			wp_send_json_error(
				sprintf( /* translators: %s - The actual error message. */
					esc_html__( 'An error occured while importing this page: %s', 'inspiro-toolkit' ),
					esc_html( $error )
				)
			);
		}

		wp_send_json_success();
	}

	/**
	 * Get the data of a registered pre-created content via the slug.
	 *
	 * @param string $slug The pre-created content slug.
	 *
	 * @return array
	 */
	public function get_content_data( $slug ) {
		$data = [];

		foreach ( $this->content as $item ) {
			if ( $item['slug'] === $slug ) {
				$data = $item;
				break;
			}
		}

		return $data;
	}

	/**
	 * Import the content for the selected pre-created content slug.
	 *
	 * @param string $slug The pre-created content slug.
	 *
	 * @return string
	 */
	private function import_content( $slug ) {
		$import_file = $this->get_import_file( $slug );

		if ( empty( $import_file ) ) {
			return esc_html__( 'The demo content import file is missing.', 'inspiro-toolkit' );
		}

		// Change the date to allow same page import multiple times.
		add_filter( 'wxr_importer.pre_process.post', function ( $data ) {
			if ( $data['post_type'] === 'page' ) {
				$data['post_date'] = date( 'Y-m-d H:i:s' );
			}

			return $data;
		} );

		// Increase PHP max execution time.
		if ( strpos( ini_get( 'disable_functions' ), 'set_time_limit' ) === false ) {
			set_time_limit( Helpers::apply_filters( 'wpzi/set_time_limit_for_demo_data_import', 300 ) );
		}

		// Disable import of authors.
		add_filter( 'wxr_importer.pre_process.user', '__return_false' );

		// Configure logger instance and set it to the importer.
		$logger            = new Logger();
		$logger->min_level = 'warning';

		// Create importer instance with proper parameters.
		$importer = new Importer(
			array(
				'fetch_attachments'      => true,
				'aggressive_url_search'  => true,
				'prefill_existing_posts' => false,
			),
			$logger
		);

		Helpers::do_action( 'wpzi/demo_content_creater_before_import', $slug );

		ob_start();
			$importer->import( $import_file );
		$message = ob_get_clean(); // Catch any output and clear the buffers.

		Helpers::do_action( 'wpzi/demo_content_creator_after_import', $slug );

		return $importer->logger->error_output;
	}

	/**
	 * Get the demo import file for the provided slug.
	 *
	 * @param string $slug The pre-created content slug.
	 *
	 * @return string
	 */
	private function get_import_file( $slug ) {
		$content_data = $this->get_content_data( $slug );

		return ! empty( $content_data['file'] ) ? $content_data['file'] : '';
	}

	/**
	 * Create a WPForms contact form, for the pre-created pages.
	 *
	 * @param string $title The title of the contact form.
	 *
	 * @return false|int
	 */
	private function create_wpforms_form( $title ) {
		$form_id = wpforms()->form->add( $title );

		if ( empty( $form_id ) || is_wp_error( $form_id ) ) {
			return false;
		}

		$form_id = wpforms()->form->update(
			$form_id,
			array(
				'id'       => $form_id,
				'field_id' => '3',
				'fields'   => array(
					'0' => array(
						'id'       => '0',
						'type'     => 'name',
						'format'   => 'first-last',
						'label'    => esc_html__( 'Name', 'inspiro-toolkit' ),
						'required' => '1',
						'size'     => 'medium',
					),
					'1' => array(
						'id'       => '1',
						'type'     => 'email',
						'label'    => esc_html__( 'Email', 'inspiro-toolkit' ),
						'required' => '1',
						'size'     => 'medium',
					),
					'2' => array(
						'id'          => '2',
						'type'        => 'textarea',
						'label'       => esc_html__( 'Comment or Message', 'inspiro-toolkit' ),
						'description' => '',
						'required'    => '1',
						'size'        => 'medium',
						'placeholder' => '',
						'css'         => '',
					),
				),
				'settings' => array(
					'form_title'             => $title,
					'notification_enable'    => '1',
					'notifications'          => array(
						'1' => array(
							'email'          => '{admin_email}',
							'sender_address' => '{admin_email}',
							'replyto'        => '{field_id="1"}',
							'message'        => '{all_fields}',
						),
					),
					'confirmations'          => array(
						'1' => array(
							'type'           => 'message',
							'message'        => esc_html__( 'Thanks for contacting us! We will be in touch with you shortly.', 'inspiro-toolkit' ),
							'message_scroll' => '1',
						),
					),
					'antispam'               => '1',
					'submit_text'            => esc_html__( 'Submit', 'inspiro-toolkit' ),
					'submit_text_processing' => esc_html__( 'Sending...', 'inspiro-toolkit' ),
				),
			)
		);

		if ( empty( $form_id ) || is_wp_error( $form_id ) ) {
			return false;
		}

		return $form_id;
	}


	/**
	 * Find the imported contact page and update the form ID.
	 *
	 * @param int $form_id The WPForms form ID.
	 *
	 * @return bool
	 */
	private function update_contact_page_form_id( $form_id ) {
		$pages = get_posts( array(
			'post_type'  => 'page',
			'meta_query' => array(
				array(
					'key'   => 'wpzi_precreated_demo',
					'value' => 'contact-page',
				),
				array(
					'key'   => 'wpzi_precreated_demo_updated',
					'value' => 'no',
				)
			),
		) );

		if ( empty( $pages ) ) {
			return false;
		}

		$contact_page = $pages[0];

		// Replace the placeholder form ID with the newly created contact form.
		$contact_page->post_content = str_replace(
			'9999',
			(string) $form_id,
			$contact_page->post_content
		);

		$update_page = wp_update_post( $contact_page, true );

		if ( is_wp_error( $update_page ) ) {
			return false;
		}

		update_post_meta( $contact_page->ID, 'wpzi_precreated_demo_updated', 'yes' );

		return true;
	}
}
