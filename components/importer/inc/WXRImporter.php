<?php
/**
  *
 * @package Inspiro\Starter_Sites
 */

namespace Inspiro\Starter_Sites;

class WXRImporter extends \AwesomeMotive\WPContentImporter2\WXRImporter {
	/**
	 * Constructor method.
	 *
	 * @param array $options Importer options.
	 */
	public function __construct( $options = array() ) {
		parent::__construct( $options );

		// Set current user to $mapping variable.
		// Fixes the [WARNING] Could not find the author for ... log warning messages.
		$current_user_obj = wp_get_current_user();
		$this->mapping['user_slug'][ $current_user_obj->user_login ] = $current_user_obj->ID;

		// WooCommerce product attributes registration.
		if ( class_exists( 'WooCommerce' ) ) {
			add_filter( 'wxr_importer.pre_process.term', array( $this, 'woocommerce_product_attributes_registration' ), 10, 1 );
		}
	}

	/**
	 * Get all protected variables from the WXR_Importer needed for continuing the import.
	 */
	public function get_importer_data() {
		return array(
			'mapping'            => $this->mapping,
			'requires_remapping' => $this->requires_remapping,
			'exists'             => $this->exists,
			'user_slug_override' => $this->user_slug_override,
			'url_remap'          => $this->url_remap,
			'featured_images'    => $this->featured_images,
		);
	}

	/**
	 * Sets all protected variables from the WXR_Importer needed for continuing the import.
	 *
	 * @param array $data with set variables.
	 */
	public function set_importer_data( $data ) {
		$this->mapping            = empty( $data['mapping'] ) ? array() : $data['mapping'];
		$this->requires_remapping = empty( $data['requires_remapping'] ) ? array() : $data['requires_remapping'];
		$this->exists             = empty( $data['exists'] ) ? array() : $data['exists'];
		$this->user_slug_override = empty( $data['user_slug_override'] ) ? array() : $data['user_slug_override'];
		$this->url_remap          = empty( $data['url_remap'] ) ? array() : $data['url_remap'];
		$this->featured_images    = empty( $data['featured_images'] ) ? array() : $data['featured_images'];
	}

	/**
	 * Hook into the pre-process term filter of the content import and register the
	 * custom WooCommerce product attributes, so that the terms can then be imported normally.
	 *
	 * This should probably be removed once the WP importer 2.0 support is added in WooCommerce.
	 *
	 * Fixes: [WARNING] Failed to import pa_size L warnings in content import.
	 * Code from: woocommerce/includes/admin/class-wc-admin-importers.php (ver 2.6.9).
	 *
	 *
	 * @param  array $date The term data to import.
	 * @return array       The unchanged term data.
	 */
	public function woocommerce_product_attributes_registration( $data ) {
		global $wpdb;

		if ( strstr( $data['taxonomy'], 'pa_' ) ) {
			if ( ! taxonomy_exists( $data['taxonomy'] ) ) {
				$attribute_name = wc_sanitize_taxonomy_name( str_replace( 'pa_', '', $data['taxonomy'] ) );
	
				// Check if the attribute already exists
				$attribute_taxonomies = wc_get_attribute_taxonomies();
				$exists = false;
				foreach ( $attribute_taxonomies as $taxonomy ) {
					if ( $taxonomy->attribute_name === $attribute_name ) {
						$exists = true;
						break;
					}
				}
	
				if ( ! $exists ) {
					// Use WooCommerce function to add a new attribute
					wc_create_attribute( array(
						'name'         => $attribute_name,
						'slug'         => $attribute_name,
						'type'         => 'select',
						'order_by'     => 'menu_order',
						'has_archives' => false,
					) );
	
					delete_transient( 'wc_attribute_taxonomies' );
				}
	
				// Register the taxonomy now so that the import works
				register_taxonomy(
					$data['taxonomy'],
					apply_filters( 'woocommerce_taxonomy_objects_' . $data['taxonomy'], array( 'product' ) ),
					apply_filters( 'woocommerce_taxonomy_args_' . $data['taxonomy'], array(
						'hierarchical' => true,
						'show_ui'      => false,
						'query_var'    => true,
						'rewrite'      => false,
					) )
				);
			}
		}

		return $data;
	}
}
