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

$imported_demo_id = get_option( 'inspiro_starter_sites_imported_demo_id', false );

?>
<div class="inspiro-starter-sites-onboard_wrapper">

<div class="inspiro-starter-sites__admin-notices js-inspiro-starter-sites-admin-notices-container"></div>

    <div id="tabs"><!-- #tabs -->

	    <?php require_once INSPIRO_STARTER_SITES_PATH . 'components/admin/parts/side-nav.php'; ?>

        <div class="inspiro-starter-sites-onboard_content-wrapper">

            <div class="inspiro-starter-sites-onboard_header">

                <!-- Onboard title -->
                <div class="inspiro-starter-sites-onboard_title-wrapper">
                    <h3 class="wpz-onboard_content-side-section-title icon-docs">Inspiro Starter Sites</h3>
                    <h2 class="inspiro-starter-sites-onboard_framework-version">v <?php echo esc_html( INSPIRO_STARTER_SITES_VERSION ); ?></h2>
                </div>
                <p class="wpz-onboard_content-main-intro">
                    <?php esc_html_e( 'Importing demo data is the fastest and easiest way to set up your new theme. Choose your desired template, click on \'Import Demo\' and start editing pre-designed content and layouts instead of building everything from scratch.', 'inspiro-starter-sites' ); ?>
                </p>

                <p class="section_footer">
                    <a href="<?php echo esc_url( __( 'https://www.wpzoom.com/themes/inspiro/starter-sites/?utm_source=wpadmin&utm_medium=demos-starter-sites&utm_campaign=starter-sites-plugin', 'inspiro' ) ); ?>"
                        target="_blank" class="button button-primary">
                            <?php esc_html_e( 'View Premium Starter Sites &#8599;', 'inspiro' ); ?>
                    </a>
                </p>
            </div>


            <div class="inspiro-starter-sites-onboard_content">
                <div class="inspiro-starter-sites-onboard_content-main">

					<div id="demo-importer" class="inspiro-starter-sites-onboard_content-main-tab">
						<div class="plugin-info-wrap welcome-section">
							<ol class="inspiro-starter-sites-onboard_content-main-steps">
								<li id="step-choose-design" class="inspiro-starter-sites-onboard_content-main-step step-1 step-choose-design">
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
											<li data-name="<?php echo esc_attr( strtolower( $import_file['import_file_name'] ) ); ?>">
												<figure title="<?php echo esc_attr( $import_file['import_file_name'] ); ?>">
													<div class="preview-thumbnail inspiro-starter-sites-import" style="background-image:url('<?php echo esc_url( $img_src ) ?>')">
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
						</div>
					</div><!--/#demo-importer-->

					<div id="premium-demos" class="inspiro-starter-sites-onboard_content-main-tab inspiro-starter-sites-onboard_content-main-theme-child">
						<?php require_once INSPIRO_STARTER_SITES_PATH . 'components/importer/views/premium-demos.php'; ?>
					</div><!--/#premium-demos-->

                </div><!--/.inspiro-starter-sites-onboard_content-main-->
            </div><!--/.inspiro-starter-sites-onboard_content-->
        </div><!--/.inspiro-starter-sites-onboard_content-wrapper-->


    </div>
    <?php require_once INSPIRO_STARTER_SITES_PATH . 'components/admin/parts/footer.php'; ?>
</div><!--/.inspiro-starter-sites-onboard_wrapper-->