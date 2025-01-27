<?php
/**
 * The importer page view.
 *
 * @package Inspiro\Starter_Sites
 */

namespace Inspiro\Starter_Sites;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

$plugin_installer = new PluginInstaller();
$theme_plugins    = $plugin_installer->get_theme_plugins();

// Check if the Inspiro theme is active.
$is_theme_active = false;
$is_theme_recommended = true;
$theme_slug = 'inspiro';

if ( get_template() === $theme_slug ) {
	$is_theme_active = true;
}

$plugin_import_page = Helpers::get_plugin_page_setup_data();
$import_page_url = admin_url( 'themes.php?page=' . $plugin_import_page['menu_slug'] );

if( isset( $plugin_import_page['parent_slug'] ) && 'inspiro' == $plugin_import_page['parent_slug'] ) {
	$import_page_url = admin_url( 'admin.php?page=' . $plugin_import_page['menu_slug'] );
}

?>

<div class="inspiro-starter-sites inspiro-starter-sites--install-plugins inspiro-starter-sites-onboard_wrapper">

<div class="inspiro-starter-sites__admin-notices js-inspiro-starter-sites-admin-notices-container"></div>

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
				<a href="<?php echo esc_url( $import_page_url ) ?>#demo-importer" title="Demo to Import">
					<svg fill="none" height="24" viewBox="0 0 24 24" width="24" xmlns="https://www.w3.org/2000/svg"><path clip-rule="evenodd" d="M15 5.75C11.5482 5.75 8.75 8.54822 8.75 12C8.75 15.4518 11.5482 18.25 15 18.25C15.9599 18.25 16.8674 18.0341 17.6782 17.6489C18.0523 17.4712 18.4997 17.6304 18.6774 18.0045C18.8552 18.3787 18.696 18.8261 18.3218 19.0038C17.3141 19.4825 16.1873 19.75 15 19.75C10.7198 19.75 7.25 16.2802 7.25 12C7.25 7.71979 10.7198 4.25 15 4.25C19.2802 4.25 22.75 7.71979 22.75 12C22.75 12.7682 22.638 13.5115 22.429 14.2139C22.3108 14.6109 21.8932 14.837 21.4962 14.7188C21.0992 14.6007 20.8731 14.1831 20.9913 13.7861C21.1594 13.221 21.25 12.6218 21.25 12C21.25 8.54822 18.4518 5.75 15 5.75Z" fill="black" fill-rule="evenodd"/> <path clip-rule="evenodd" d="M5.25 5C5.25 4.58579 5.58579 4.25 6 4.25H15C15.4142 4.25 15.75 4.58579 15.75 5C15.75 5.41421 15.4142 5.75 15 5.75H6C5.58579 5.75 5.25 5.41421 5.25 5Z" fill="black" fill-rule="evenodd"/> <path clip-rule="evenodd" d="M4.75 8.5C4.75 8.08579 5.08579 7.75 5.5 7.75H8.5C8.91421 7.75 9.25 8.08579 9.25 8.5C9.25 8.91421 8.91421 9.25 8.5 9.25H5.5C5.08579 9.25 4.75 8.91421 4.75 8.5Z" fill="black" fill-rule="evenodd"/> <path clip-rule="evenodd" d="M1.25 8.5C1.25 8.08579 1.58579 7.75 2 7.75H3.5C3.91421 7.75 4.25 8.08579 4.25 8.5C4.25 8.91421 3.91421 9.25 3.5 9.25H2C1.58579 9.25 1.25 8.91421 1.25 8.5Z" fill="black" fill-rule="evenodd"/> <path clip-rule="evenodd" d="M3.25 12.5C3.25 12.0858 3.58579 11.75 4 11.75H8C8.41421 11.75 8.75 12.0858 8.75 12.5C8.75 12.9142 8.41421 13.25 8 13.25H4C3.58579 13.25 3.25 12.9142 3.25 12.5Z" fill="black" fill-rule="evenodd"/> <path clip-rule="evenodd" d="M12.376 8.58397C12.5151 8.37533 12.7492 8.25 13 8.25H17C17.2508 8.25 17.4849 8.37533 17.624 8.58397L19.624 11.584C19.792 11.8359 19.792 12.1641 19.624 12.416L17.624 15.416C17.4849 15.6247 17.2508 15.75 17 15.75H13C12.7492 15.75 12.5151 15.6247 12.376 15.416L10.376 12.416C10.208 12.1641 10.208 11.8359 10.376 11.584L12.376 8.58397ZM13.4014 9.75L11.9014 12L13.4014 14.25H16.5986L18.0986 12L16.5986 9.75H13.4014Z" fill="black" fill-rule="evenodd"/></svg> 
					<?php esc_html_e( 'Demo Importer', 'inspiro-starter-sites' ); ?>
				</a>    
			</li><!-- /.tab-demos -->
			<li class="inspiro-starter-sites-onboard_tab inspiro-starter-sites-onboard_tab-premium-demos">
				<a href="<?php echo esc_url( $import_page_url ) ?>#premium-demos" title="Premium Demos">
					<svg width="20" height="20" viewBox="0 0 40 40" fill="none" xmlns="https://www.w3.org/2000/svg"> <path d="M34 0H14C12.4087 0 10.8826 0.632141 9.75736 1.75736C8.63214 2.88258 8 4.4087 8 6V8H6C4.4087 8 2.88258 8.63214 1.75736 9.75736C0.632141 10.8826 0 12.4087 0 14V34C0 35.5913 0.632141 37.1174 1.75736 38.2426C2.88258 39.3679 4.4087 40 6 40H26C27.5913 40 29.1174 39.3679 30.2426 38.2426C31.3679 37.1174 32 35.5913 32 34V32H34C35.5913 32 37.1174 31.3679 38.2426 30.2426C39.3679 29.1174 40 27.5913 40 26V6C40 4.4087 39.3679 2.88258 38.2426 1.75736C37.1174 0.632141 35.5913 0 34 0ZM28 34C28 34.5304 27.7893 35.0391 27.4142 35.4142C27.0391 35.7893 26.5304 36 26 36H6C5.46957 36 4.96086 35.7893 4.58579 35.4142C4.21071 35.0391 4 34.5304 4 34V20H28V34ZM28 16H4V14C4 13.4696 4.21071 12.9609 4.58579 12.5858C4.96086 12.2107 5.46957 12 6 12H26C26.5304 12 27.0391 12.2107 27.4142 12.5858C27.7893 12.9609 28 13.4696 28 14V16ZM36 26C36 26.5304 35.7893 27.0391 35.4142 27.4142C35.0391 27.7893 34.5304 28 34 28H32V14C31.9946 13.3177 31.8728 12.6413 31.64 12H36V26ZM36 8H12V6C12 5.46957 12.2107 4.96086 12.5858 4.58579C12.9609 4.21071 13.4696 4 14 4H34C34.5304 4 35.0391 4.21071 35.4142 4.58579C35.7893 4.96086 36 5.46957 36 6V8Z" fill="#242628"/> </svg> 
				<?php esc_html_e( 'Premium Demos', 'inspiro-starter-sites' ); ?>
				</a>
			</li>
		</ul>
	</div>

	<div class="inspiro-starter-sites__content-container">

		<div class="inspiro-starter-sites__content-container-content">
			<div class="inspiro-starter-sites__content-container-content--main">
				<?php if ( isset( $_GET['import'] ) ) : 

					check_admin_referer( 'importer_step' );
					
					?>
					<div class="inspiro-starter-sites-install-plugins-content js-inspiro-starter-sites-install-plugins-content">
						<div class="inspiro-starter-sites-install-plugins-content-header">

                            <?php if( ! $is_theme_active ) { ?>

							<h2><?php esc_html_e( 'Before We Import Your Demo', 'inspiro-starter-sites' ); ?></h2>

                             <?php } else { ?>
                                <h2><?php esc_html_e( 'Install Recommended Plugins', 'inspiro-starter-sites' ); ?></h2>
                            <?php } ?>

							<?php if( ! $is_theme_active ) { ?>
							<p>
								<?php esc_html_e( 'Starter sites are optimized for the Inspiro theme. Install and activate it for a seamless setup and optimal performance.', 'inspiro-starter-sites' ); ?>
							</p>
							<?php } ?>

							<p>
								<?php esc_html_e( 'To get the best experience with your selected starter site, the plugins marked with a lock icon are required. Other plugins are optional but recommended if you want to add extra features to your website.', 'inspiro-starter-sites' ); ?>
							</p>

							<?php if ( ! empty( $this->import_files[ $_GET['import'] ]['import_notice'] ) ) : 
									$import_notice = sanitize_text_field( wp_unslash( $this->import_files[ $_GET['import'] ] ) );
								?>
								<div class="notice  notice-info">
									<p><?php echo wp_kses_post( $import_notice['import_notice'] ); ?></p>
								</div>
							<?php endif; ?>
						</div>
						
						<?php if( ! $is_theme_active ) : ?>
						<div class="inspiro-starter-sites-install-theme-content">
							<label class="theme-item theme-item-inspiro" for="inspiro-starter-sites-inspiro-theme">
								<div class="theme-item-content">
									<div class="theme-item-content-title">
										<h3><?php esc_html_e( 'Install Inspiro Theme', 'inspiro-starter-sites' ); ?></h3>
									</div>
									<p><?php echo wp_kses_post( __( 'A free WordPress theme by WPZOOM, perfect for showcasing videos, photos, and creative projects.', 'inspiro-starter-sites' ) ) ?> </p>
									<div class="theme-item-error js-inspiro-starter-sites-theme-item-error"></div>
									<div class="theme-item-info js-inspiro-starter-sites-theme-item-info"></div>
								</div>
								<span class="theme-item-checkbox">
									<input type="checkbox" id="inspiro-starter-sites-inspiro-theme" name="inspiro" <?php checked( $is_theme_recommended ); ?>>
									<span class="checkbox">
										<img src="<?php echo esc_url( INSPIRO_STARTER_SITES_URL . 'components/importer/assets/images/icons/check-solid-white.svg' ); ?>" class="inspiro-starter-sites-check-icon" alt="<?php esc_attr_e( 'Checkmark icon', 'inspiro-starter-sites' ); ?>">
										<img src="<?php echo esc_url( INSPIRO_STARTER_SITES_URL . 'components/importer/assets/images/loader.svg' ); ?>" class="inspiro-starter-sites-loading inspiro-starter-sites-loading-md" alt="<?php esc_attr_e( 'Loading...', 'inspiro-starter-sites' ); ?>">
									</span>
								</span>
							</label>
						</div>
						<?php endif; ?>
						


						<div class="inspiro-starter-sites-install-plugins-content-content">
							<?php if ( empty( $theme_plugins ) ) : ?>
								<div class="inspiro-starter-sites-content-notice">
									<p>
										<?php esc_html_e( 'All required/recommended plugins are already installed. You can import your demo content.' , 'inspiro-starter-sites' ); ?>
									</p>
								</div>
							<?php else : ?>
								<div>
								<?php foreach ( $theme_plugins as $plugin ) : ?>
									<?php $is_plugin_active = $plugin_installer->is_plugin_active( $plugin['slug'] ); ?>
									<label class="plugin-item plugin-item-<?php echo esc_attr( $plugin['slug'] ); ?><?php echo $is_plugin_active ? ' plugin-item--active' : ''; ?><?php echo ! empty( $plugin['required'] ) ? ' plugin-item--required' : ''; ?>" for="inspiro-starter-sites-<?php echo esc_attr( $plugin['slug'] ); ?>-plugin">
										<div class="plugin-item-content">
											<div class="plugin-item-content-title">
												<h3><?php echo esc_html( $plugin['name'] ); ?></h3>
											</div>
											<?php if ( ! empty( $plugin['desc'] ) ) : ?>
												<p>
													<?php echo wp_kses_post( $plugin['desc'] ); ?>
												</p>
											<?php endif; ?>
											<div class="plugin-item-error js-inspiro-starter-sites-plugin-item-error"></div>
											<div class="plugin-item-info js-inspiro-starter-sites-plugin-item-info"></div>
										</div>
										<span class="plugin-item-checkbox">
											<input type="checkbox" id="inspiro-starter-sites-<?php echo esc_attr( $plugin['slug'] ); ?>-plugin" name="<?php echo esc_attr( $plugin['slug'] ); ?>" <?php checked( ! empty( $plugin['preselected'] ) || ! empty( $plugin['required'] ) || $is_plugin_active ); ?><?php disabled( $is_plugin_active ); ?>>
											<span class="checkbox">
												<img src="<?php echo esc_url( INSPIRO_STARTER_SITES_URL . 'components/importer/assets/images/icons/check-solid-white.svg' ); ?>" class="inspiro-starter-sites-check-icon" alt="<?php esc_attr_e( 'Checkmark icon', 'inspiro-starter-sites' ); ?>">
												<?php if ( ! empty( $plugin['required'] ) ) : ?>
													<img src="<?php echo esc_url( INSPIRO_STARTER_SITES_URL . 'components/importer/assets/images/icons/lock.svg' ); ?>" class="inspiro-starter-sites-lock-icon" alt="<?php esc_attr_e( 'Lock icon', 'inspiro-starter-sites' ); ?>">
												<?php endif; ?>
												<img src="<?php echo esc_url( INSPIRO_STARTER_SITES_URL . 'components/importer/assets/images/loader.svg' ); ?>" class="inspiro-starter-sites-loading inspiro-starter-sites-loading-md" alt="<?php esc_attr_e( 'Loading...', 'inspiro-starter-sites' ); ?>">
											</span>
										</span>
									</label>
								<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</div>
						<div class="inspiro-starter-sites-install-plugins-content-footer">
							<a href="<?php echo esc_url( $this->get_plugin_settings_url() ); ?>" class="button"><span><?php esc_html_e( '&larr; Go Back' , 'inspiro-starter-sites' ); ?></span></a>
							<a href="#" class="button button-primary js-inspiro-starter-sites-install-plugins-before-import"><?php esc_html_e( 'Install & Import' , 'inspiro-starter-sites' ); ?></a>
						</div>
					</div>
				<?php else : ?>
					<div class="js-inspiro-starter-sites-auto-start-manual-import"></div>
				<?php endif; ?>

				<div class="inspiro-starter-sites-importing js-inspiro-starter-sites-importing">
					<div class="inspiro-starter-sites-importing-header">
						<h2><?php esc_html_e( 'Importing Content' , 'inspiro-starter-sites' ); ?></h2>
						<p><?php esc_html_e( 'Please wait while the content is being imported. Avoid refreshing the page or clicking the back button, as this may result in the demo content not being imported correctly.' , 'inspiro-starter-sites' ); ?></p>
					</div>
					<div class="inspiro-starter-sites-importing-content">
						<img class="inspiro-starter-sites-importing-content-importing" src="<?php echo esc_url( INSPIRO_STARTER_SITES_URL . 'components/importer/assets/images/importing.svg' ); ?>" alt="<?php esc_attr_e( 'Importing animation', 'inspiro-starter-sites' ); ?>">
					</div>
				</div>

				<div class="inspiro-starter-sites-imported js-inspiro-starter-sites-imported">
					<div class="inspiro-starter-sites-imported-header">
						<h2 class="js-inspiro-starter-sites-ajax-response-title"><?php esc_html_e( 'Demo Content Successfully Imported' , 'inspiro-starter-sites' ); ?></h2>
						<div class="js-inspiro-starter-sites-ajax-response-subtitle">
							<p>
								<?php esc_html_e( 'Congratulations! Your demo has been imported successfully. You can now either customize your website or view it live.' , 'inspiro-starter-sites' ); ?>
							</p>
						</div>
					</div>
					<div class="inspiro-starter-sites-imported-content">
						<div class="inspiro-starter-sites__response  js-inspiro-starter-sites-ajax-complete-response"></div>
					</div>
					<div class="inspiro-starter-sites-imported-footer">
						<?php echo wp_kses(
							$this->get_import_successful_buttons_html(),
							[
								'a' => [
									'href'   => [],
									'class'  => [],
									'target' => [],
								],
							]
						); ?>
					</div>
				</div>
			</div>
			<div class="inspiro-starter-sites__content-container-content--side">
				<?php
					$selected = isset( $_GET['import'] ) ? (int) $_GET['import'] : null;
					echo wp_kses_post( ViewHelpers::small_theme_card( $selected ) );
				?>
			</div>
		</div>

	</div>
	<?php require_once INSPIRO_STARTER_SITES_PATH . 'components/admin/parts/footer.php'; ?>
</div>
