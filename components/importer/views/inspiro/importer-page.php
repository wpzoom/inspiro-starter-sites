<?php
/**
 * The importer page view.
 *
 * @package Inspiro\Starter_Sites
 */

namespace Inspiro\Starter_Sites;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

$the_theme = wp_get_theme();

$predefined_themes = $this->import_files;
$premium_demos     = apply_filters( 'inspiro_starter_sites_premium_demos', array() );
$premium_section   = ! empty( $premium_demos['inspiro-premium'] ) ? $premium_demos['inspiro-premium'] : array();

$imported_demo_id = get_option( 'inspiro_starter_sites_imported_demo_id', false );

?>
<div class="inspiro-starter-sites-demo-section">
	<h3 class="inspiro-starter-sites-demo-section-title"><?php esc_html_e( 'Free Starter Sites', 'inspiro-starter-sites' ); ?></h3>
	<p class="inspiro-starter-sites-demo-section-description"><?php esc_html_e( 'Import any of these starter sites directly into Inspiro Lite.', 'inspiro-starter-sites' ); ?></p>
</div>
<ol class="wpz-onboard_content-main-steps">
	<li id="step-choose-design" class="wpz-onboard_content-main-step step-1 step-choose-design">
		<form method="post" action="#">
			<ul>
				<?php foreach ( $predefined_themes as $index => $import_file ) : 

						$import_btn_label = esc_html__( 'Import Demo', 'inspiro-starter-sites' );
						$imported_demo = false;
						$imported_btn_classname = '';

						if( $imported_demo_id && $imported_demo_id == $import_file['import_id'] ) {
							$imported_demo = true;
							$imported_btn_classname = 'button-secondary';
							$import_btn_label = esc_html__( 'Imported', 'inspiro-starter-sites' );
						}
					
					?>
				<?php
					// Prepare import item display data.
					$img_src = isset( $import_file['import_preview_image_url'] ) ? $import_file['import_preview_image_url'] : '';
					// Default to the theme screenshot, if a custom preview image is not defined.
					if ( empty( $img_src ) ) {
						$theme = wp_get_theme();
						$img_src = $theme->get_screenshot();
					}

				?>
				<li data-name="<?php echo esc_attr( strtolower( $import_file['import_file_name'] ) ); ?>" data-import-id="<?php echo esc_attr( $import_file['import_id'] ); ?>">
					<figure title="<?php echo esc_attr( $import_file['import_file_name'] ); ?>">
						<div class="preview-thumbnail inspiro-starter-sites-import" style="background-image:url('<?php echo esc_url( $img_src ) ?>')">
							<span class="inspiro-starter-sites-demo-badge inspiro-starter-sites-demo-badge-free"><?php esc_html_e( 'Free', 'inspiro-starter-sites' ); ?></span>
							<a href="<?php echo esc_url( $import_file['preview_url'] ); ?>" target="_blank" class="button-select-template"><?php esc_html_e( 'View Demo', 'inspiro-starter-sites' ); ?></a></div>
						<figcaption>
							<h5><?php echo esc_html( $import_file['import_file_name'] ); ?></h5>

							<?php
								$step_url        =  wp_nonce_url( $this->get_plugin_settings_url( [ 'step' => 'import', 'import' => esc_attr( $index ) ] ), 'importer_step' );
								$delete_step_url = wp_nonce_url( $this->get_plugin_settings_url( [ 'step' => 'delete_import', 'imported_demo' => esc_attr( $index ) ] ), 'importer_step' );
							?>

							<a href="<?php echo esc_url( $step_url ); ?>" class="button button-primary <?php echo esc_attr( $imported_btn_classname ); ?>"><?php echo esc_html( $import_btn_label ); ?></a>

							<?php if( $imported_demo ) { ?>
								<a href="<?php echo esc_url( $delete_step_url ); ?>" class="delete-imported-demo-content" title="<?php esc_attr_e( 'Delete imported demo content', 'inspiro-starter-sites' ); ?>"></a>
							<?php } ?>

						</figcaption>
					</figure>
				</li>
				<?php endforeach; ?>
			</ul>
		</form>
	</li>
</ol>

<?php if ( ! empty( $premium_section['demos'] ) ) : ?>
	<div class="inspiro-starter-sites-demo-section inspiro-starter-sites-demo-section-premium">
		<h3 class="inspiro-starter-sites-demo-section-title">
			<?php
			printf(
				/* translators: %d: number of premium demos */
				esc_html__( 'Premium Starter Sites (%d)', 'inspiro-starter-sites' ),
				count( $premium_section['demos'] )
			);
			?>
		</h3>
		<p class="inspiro-starter-sites-demo-section-description"><?php echo esc_html( $premium_section['desc'] ); ?></p>
	</div>
	<ol class="wpz-onboard_content-main-steps">
		<li class="wpz-onboard_content-main-step step-1 step-choose-design">
			<form method="post" action="#">
				<ul>
					<?php foreach ( $premium_section['demos'] as $demo ) : ?>
						<li class="inspiro-starter-sites-demo-card-premium">
							<figure title="<?php echo esc_attr( $demo['import_file_name'] ); ?>">
								<div class="preview-thumbnail-demo">
									<span class="inspiro-starter-sites-demo-badge inspiro-starter-sites-demo-badge-premium"><?php esc_html_e( 'Premium', 'inspiro-starter-sites' ); ?></span>
									<a href="<?php echo esc_url( $demo['preview_url'] ); ?>" target="_blank" rel="noopener">
										<img src="<?php echo esc_url( $demo['import_preview_image_url'] ); ?>" alt="<?php echo esc_attr( $demo['import_file_name'] ); ?>" />
									</a>
								</div>
								<figcaption>
									<h5><?php echo esc_html( $demo['import_file_name'] ); ?></h5>
									<a href="<?php echo esc_url( $premium_section['purchase'] ); ?>?utm_source=wpadmin&utm_medium=demos-starter-sites&utm_campaign=starter-sites-premium" target="_blank" rel="noopener" class="button button-primary"><?php esc_html_e( 'Unlock in Premium', 'inspiro-starter-sites' ); ?></a>
									<a href="<?php echo esc_url( $demo['preview_url'] ); ?>" target="_blank" rel="noopener" class="button button-secondary-gray"><?php esc_html_e( 'Preview', 'inspiro-starter-sites' ); ?></a>
								</figcaption>
							</figure>
						</li>
					<?php endforeach; ?>
				</ul>
			</form>
		</li>
	</ol>
<?php endif; ?>
