<?php
/**
 * Static functions used in the Inspiro\Starter_Sites views.
 *
 * @package Inspiro\Starter_Sites
 */

namespace Inspiro\Starter_Sites;

class ViewHelpers {

	/**
	 * The HTML output of a small card with theme screenshot and title.
	 *
	 * @return string HTML output.
	 */
	public static function small_theme_card( $selected = null ) {
		$theme      = wp_get_theme();
		$screenshot = $theme->get_screenshot();
		$name       = $theme->name;

		if ( isset( $selected ) ) {
			$iss          = InspiroStarterSitesImporter::get_instance();
			$selected_data = $iss->import_files[ $selected ];
			$name          = ! empty( $selected_data['import_file_name'] ) ? $selected_data['import_file_name'] : $name;
			$screenshot    = ! empty( $selected_data['import_preview_image_url'] ) ? $selected_data['import_preview_image_url'] : $screenshot;
		}

		ob_start(); ?>
		<div class="inspiro-starter-sites__card inspiro-starter-sites__card--theme">
			<div class="inspiro-starter-sites__card-content">
				<?php if ( $screenshot ) : ?>
					<div class="screenshot"><img src="<?php echo esc_url( $screenshot ); ?>" alt="<?php esc_attr_e( 'Theme screenshot', 'inspiro-starter-sites' ); ?>" /></div>
				<?php else : ?>
					<div class="screenshot blank"></div>
				<?php endif; ?>
			</div>
			<div class="inspiro-starter-sites__card-footer">
				<h3><?php echo esc_html( $name ); ?></h3>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
