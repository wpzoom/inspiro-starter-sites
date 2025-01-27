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

		<div class="inspiro-starter-sites-onboard_header">

			<!-- Onboard title -->
			<div class="inspiro-starter-sites-onboard_title-wrapper">
				<h1 class="inspiro-starter-sites-onboard_title">
					<svg width="30" height="30" viewBox="0 0 46 46" fill="none" xmlns="https://www.w3.org/2000/svg"><path fill-rule="evenodd"   clip-rule="evenodd" d="M23 46C35.7025 46 46 35.7025 46 23C46 10.2975 35.7025 0 23 0C10.2975 0 0 10.2975 0 23C0 35.7025 10.2975 46 23 46ZM19.4036 10.3152C19.4036 8.31354 21.0263 6.69091 23.0279 6.69091H26.2897C26.4899 6.69091 26.6521 6.85317 26.6521 7.05333V13.5025C26.6521 13.622 26.5884 13.7324 26.4848 13.7922L19.9055 17.5908C19.6824 17.7196 19.4036 17.5586 19.4036 17.3011V10.3152ZM19.5709 24.0613L26.1503 20.2627C26.3733 20.134 26.6521 20.2949 26.6521 20.5525V35.6849C26.6521 37.6865 25.0295 39.3091 23.0279 39.3091H19.7661C19.5659 39.3091 19.4036 39.1468 19.4036 38.9467V24.3511C19.4036 24.2316 19.4674 24.1211 19.5709 24.0613Z" fill="#242628"/></svg>
					<?php esc_html_e( 'Inspiro Starter Sites', 'inspiro-starter-sites' ); ?>
				</h1>
				<h2 class="inspiro-starter-sites-onboard_framework-version">v <?php echo esc_html( INSPIRO_STARTER_SITES_VERSION ); ?></h2>
			</div>

			<ul class="inspiro-starter-sites-onboard_tabs">
				<li class="inspiro-starter-sites-onboard_tab inspiro-starter-sites-onboard_tab-demos ui-tabs-tab ui-corner-top ui-state-default ui-tab ui-tabs-active ui-state-active">
					<a href="#demo-importer" title="Demo to Import">
						<svg fill="none" height="24" viewBox="0 0 24 24" width="24" xmlns="https://www.w3.org/2000/svg"><path clip-rule="evenodd" d="M15 5.75C11.5482 5.75 8.75 8.54822 8.75 12C8.75 15.4518 11.5482 18.25 15 18.25C15.9599 18.25 16.8674 18.0341 17.6782 17.6489C18.0523 17.4712 18.4997 17.6304 18.6774 18.0045C18.8552 18.3787 18.696 18.8261 18.3218 19.0038C17.3141 19.4825 16.1873 19.75 15 19.75C10.7198 19.75 7.25 16.2802 7.25 12C7.25 7.71979 10.7198 4.25 15 4.25C19.2802 4.25 22.75 7.71979 22.75 12C22.75 12.7682 22.638 13.5115 22.429 14.2139C22.3108 14.6109 21.8932 14.837 21.4962 14.7188C21.0992 14.6007 20.8731 14.1831 20.9913 13.7861C21.1594 13.221 21.25 12.6218 21.25 12C21.25 8.54822 18.4518 5.75 15 5.75Z" fill="black" fill-rule="evenodd"/> <path clip-rule="evenodd" d="M5.25 5C5.25 4.58579 5.58579 4.25 6 4.25H15C15.4142 4.25 15.75 4.58579 15.75 5C15.75 5.41421 15.4142 5.75 15 5.75H6C5.58579 5.75 5.25 5.41421 5.25 5Z" fill="black" fill-rule="evenodd"/> <path clip-rule="evenodd" d="M4.75 8.5C4.75 8.08579 5.08579 7.75 5.5 7.75H8.5C8.91421 7.75 9.25 8.08579 9.25 8.5C9.25 8.91421 8.91421 9.25 8.5 9.25H5.5C5.08579 9.25 4.75 8.91421 4.75 8.5Z" fill="black" fill-rule="evenodd"/> <path clip-rule="evenodd" d="M1.25 8.5C1.25 8.08579 1.58579 7.75 2 7.75H3.5C3.91421 7.75 4.25 8.08579 4.25 8.5C4.25 8.91421 3.91421 9.25 3.5 9.25H2C1.58579 9.25 1.25 8.91421 1.25 8.5Z" fill="black" fill-rule="evenodd"/> <path clip-rule="evenodd" d="M3.25 12.5C3.25 12.0858 3.58579 11.75 4 11.75H8C8.41421 11.75 8.75 12.0858 8.75 12.5C8.75 12.9142 8.41421 13.25 8 13.25H4C3.58579 13.25 3.25 12.9142 3.25 12.5Z" fill="black" fill-rule="evenodd"/> <path clip-rule="evenodd" d="M12.376 8.58397C12.5151 8.37533 12.7492 8.25 13 8.25H17C17.2508 8.25 17.4849 8.37533 17.624 8.58397L19.624 11.584C19.792 11.8359 19.792 12.1641 19.624 12.416L17.624 15.416C17.4849 15.6247 17.2508 15.75 17 15.75H13C12.7492 15.75 12.5151 15.6247 12.376 15.416L10.376 12.416C10.208 12.1641 10.208 11.8359 10.376 11.584L12.376 8.58397ZM13.4014 9.75L11.9014 12L13.4014 14.25H16.5986L18.0986 12L16.5986 9.75H13.4014Z" fill="black" fill-rule="evenodd"/></svg> 
						<?php esc_html_e( 'Demo Importer', 'inspiro-starter-sites' ); ?>
					</a>    
				</li><!-- /.tab-demos -->
				<li class="inspiro-starter-sites-onboard_tab inspiro-starter-sites-onboard_tab-premium-demos">
					<a href="#premium-demos" title="Premium Demos">
						<svg width="20" height="20" viewBox="0 0 40 40" fill="none" xmlns="https://www.w3.org/2000/svg"> <path d="M34 0H14C12.4087 0 10.8826 0.632141 9.75736 1.75736C8.63214 2.88258 8 4.4087 8 6V8H6C4.4087 8 2.88258 8.63214 1.75736 9.75736C0.632141 10.8826 0 12.4087 0 14V34C0 35.5913 0.632141 37.1174 1.75736 38.2426C2.88258 39.3679 4.4087 40 6 40H26C27.5913 40 29.1174 39.3679 30.2426 38.2426C31.3679 37.1174 32 35.5913 32 34V32H34C35.5913 32 37.1174 31.3679 38.2426 30.2426C39.3679 29.1174 40 27.5913 40 26V6C40 4.4087 39.3679 2.88258 38.2426 1.75736C37.1174 0.632141 35.5913 0 34 0ZM28 34C28 34.5304 27.7893 35.0391 27.4142 35.4142C27.0391 35.7893 26.5304 36 26 36H6C5.46957 36 4.96086 35.7893 4.58579 35.4142C4.21071 35.0391 4 34.5304 4 34V20H28V34ZM28 16H4V14C4 13.4696 4.21071 12.9609 4.58579 12.5858C4.96086 12.2107 5.46957 12 6 12H26C26.5304 12 27.0391 12.2107 27.4142 12.5858C27.7893 12.9609 28 13.4696 28 14V16ZM36 26C36 26.5304 35.7893 27.0391 35.4142 27.4142C35.0391 27.7893 34.5304 28 34 28H32V14C31.9946 13.3177 31.8728 12.6413 31.64 12H36V26ZM36 8H12V6C12 5.46957 12.2107 4.96086 12.5858 4.58579C12.9609 4.21071 13.4696 4 14 4H34C34.5304 4 35.0391 4.21071 35.4142 4.58579C35.7893 4.96086 36 5.46957 36 6V8Z" fill="#242628"/> </svg> 
					<?php esc_html_e( 'Premium Demos', 'inspiro-starter-sites' ); ?>
					</a>
				</li>
			</ul>
		</div>

        <div class="inspiro-starter-sites-onboard_content-wrapper">
            <div class="inspiro-starter-sites-onboard_content">
                <div class="inspiro-starter-sites-onboard_content-main">

					<div id="demo-importer" class="inspiro-starter-sites-onboard_content-main-tab">
						<div class="plugin-info-wrap welcome-section">
							<h3 class="inspiro-starter-sites-onboard_content-main-title">
								<?php esc_html_e( 'Demo Importer', 'inspiro-starter-sites' ); ?>
							</h3>
							<p class="about">
								<?php esc_html_e( 'Importing demo data is the fastest and easiest way to set up your new theme. Choose your desired template, click on \'Import Demo\' and start editing pre-designed content and layouts instead of building everything from scratch.', 'inspiro-starter-sites' ); ?>
							</p>
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