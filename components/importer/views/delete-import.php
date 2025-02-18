<?php
/**
 * The delete importer page view.
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

<div class="inspiro-starter-sites inspiro-starter-sites--delete-imported inspiro-starter-sites-onboard_wrapper">

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
				<?php if ( isset( $_GET['imported_demo'] ) ) : 
						check_admin_referer( 'importer_step' );
					?>
					<div class="inspiro-starter-sites-delete-imported-content js-inspiro-starter-sites-delete-imported-content">
						<div class="inspiro-starter-sites-delete-imported-content-header">
							<h2><?php esc_html_e( 'Delete Imported Demo Content', 'inspiro-starter-sites' ); ?></h2>
							<p>
								<?php esc_html_e( 'Are you sure you want to delete the imported demo content? This action will permanently remove all imported pages and posts, including any edits made to them.', 'inspiro-starter-sites' ); ?>
							</p>

							<?php if ( ! empty( $this->import_files[ $_GET['imported_demo'] ]['import_notice'] ) ) : 
									$import_notice = sanitize_text_field( wp_unslash( $this->import_files[ $_GET['imported_demo'] ] ) );
								?>
								<div class="notice  notice-info">
									<p><?php echo wp_kses_post( $import_notice['import_notice'] ); ?></p>
								</div>
							<?php endif; ?>
						</div>
						
						<div class="inspiro-starter-sites-delete-imported-content-footer">
							<a href="<?php echo esc_url( $this->get_plugin_settings_url() ); ?>" class="button"><span><?php esc_html_e( '&larr; Go Back' , 'inspiro-starter-sites' ); ?></span></a>
							<a href="#" class="button button-danger js-inspiro-starter-sites-delete-imported-demo"><?php esc_html_e( 'Delete' , 'inspiro-starter-sites' ); ?></a>
						</div>
					</div>
				<?php endif; ?>

				<div class="inspiro-starter-sites-deleting js-inspiro-starter-sites-deleting">
					<div class="inspiro-starter-sites-deleting-header">
						<h2><?php esc_html_e( 'Deleting Demo Content' , 'inspiro-starter-sites' ); ?></h2>
						<p><?php esc_html_e( 'Please wait while the content is being imported. Avoid refreshing the page or clicking the back button to ensure the process runs smoothly.' , 'inspiro-starter-sites' ); ?></p>
					</div>
					<div class="inspiro-starter-sites-deleting-content">
						<img class="inspiro-starter-sites-deleting-content-deleting" src="<?php echo esc_url( INSPIRO_STARTER_SITES_URL . 'components/importer/assets/images/importing.svg' ); ?>" alt="<?php esc_attr_e( 'Deleting animation', 'inspiro-starter-sites' ); ?>">
					</div>
				</div>

				<div class="inspiro-starter-sites-deleted js-inspiro-starter-sites-deleted">
					<div class="inspiro-starter-sites-deleted-content-header">
						<h2 class="js-inspiro-starter-sites-ajax-response-title"><?php esc_html_e( 'Demo Content Deleted Successfully' , 'inspiro-starter-sites' ); ?></h2>
						<p><?php esc_html_e( 'The imported demo content has been successfully deleted. You can now choose to install another demo or start building your website from scratch.' , 'inspiro-starter-sites' ); ?></p>
					</div>
					<div class="inspiro-starter-sites-deleted-content">
						<div class="inspiro-starter-sites__response js-inspiro-starter-sites-ajax-complete-response"></div>
					</div>
					<div class="inspiro-starter-sites-deleted-footer">
						<div class="inspiro-starter-sites-delete-imported-content-footer">
                            <a href="<?php echo esc_url( $this->get_plugin_settings_url() ); ?>" class="button"><span><?php esc_html_e( '&larr; Go back to all demos' , 'inspiro-starter-sites' ); ?></span></a>
                        </div>
					</div>
				</div>
			</div>

		</div>

	    </div>
        </div>
    </div>
	<?php require_once INSPIRO_STARTER_SITES_PATH . 'components/admin/parts/footer.php'; ?>
</div>
