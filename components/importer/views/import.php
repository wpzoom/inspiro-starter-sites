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

    <div class="ui-tabs">

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
        		</div>

            </div>

        </div><!-- /.inspiro-starter-sites-onboard_content-wrapper -->
    
    </div><!-- /.ui-tabs -->
	<?php require_once INSPIRO_STARTER_SITES_PATH . 'components/admin/parts/footer.php'; ?>
</div>
