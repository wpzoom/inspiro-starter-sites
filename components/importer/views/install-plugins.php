<?php
/**
 * The install plugins page view.
 *
 * @package wpzi
 */

namespace WPZI;

$plugin_installer = new PluginInstaller();
?>

<div class="wpzi wpzi--install-plugins wpz-onboard_wrapper">

	<div class="wpz-onboard_header">
		<!-- Onboard title -->
		<div class="wpz-onboard_title-wrapper">
			<h1 class="wpz-onboard_title">
				<svg width="30" height="30" viewBox="0 0 46 46" fill="none" xmlns="https://www.w3.org/2000/svg"><path fill-rule="evenodd"   clip-rule="evenodd" d="M23 46C35.7025 46 46 35.7025 46 23C46 10.2975 35.7025 0 23 0C10.2975 0 0 10.2975 0 23C0 35.7025 10.2975 46 23 46ZM19.4036 10.3152C19.4036 8.31354 21.0263 6.69091 23.0279 6.69091H26.2897C26.4899 6.69091 26.6521 6.85317 26.6521 7.05333V13.5025C26.6521 13.622 26.5884 13.7324 26.4848 13.7922L19.9055 17.5908C19.6824 17.7196 19.4036 17.5586 19.4036 17.3011V10.3152ZM19.5709 24.0613L26.1503 20.2627C26.3733 20.134 26.6521 20.2949 26.6521 20.5525V35.6849C26.6521 37.6865 25.0295 39.3091 23.0279 39.3091H19.7661C19.5659 39.3091 19.4036 39.1468 19.4036 38.9467V24.3511C19.4036 24.2316 19.4674 24.1211 19.5709 24.0613Z" fill="#242628"/></svg>
				<?php esc_html_e( 'Inspiro Starter Templates', 'inspiro-toolkit' ); ?>
			</h1>
			<h2 class="wpz-onboard_framework-version">v <?php echo esc_html( INSPIRO_TOOLKIT_VERSION ); ?></h2>
		</div>
	</div>

	<div class="wpzi__content-container">

		<div class="wpzi__admin-notices js-wpzi-admin-notices-container"></div>

		<div class="wpzi__content-container-content">
			<div class="wpzi__content-container-content--main">
				<div class="wpzi-install-plugins-content">
					<div class="wpzi-install-plugins-content-header">
						<h2><?php esc_html_e( 'Install Recommended Plugins', 'inspiro-toolkit' ); ?></h2>
						<p>
							<?php esc_html_e( 'Want to use the best plugins for the job? Here is the list of awesome plugins that will help you achieve your goals.', 'inspiro-toolkit' ); ?>
						</p>
					</div>
					<div class="wpzi-install-plugins-content-content">
						<?php foreach ( $plugin_installer->get_partner_plugins() as $plugin ) : ?>
							<?php $is_plugin_active = $plugin_installer->is_plugin_active( $plugin['slug'] ); ?>
							<label class="plugin-item plugin-item-<?php echo esc_attr( $plugin['slug'] ); ?><?php echo $is_plugin_active ? ' plugin-item--active' : ''; ?>" for="wpzi-<?php echo esc_attr( $plugin['slug'] ); ?>-plugin">
								<div class="plugin-item-content">
									<div class="plugin-item-content-title">
										<h3><?php echo esc_html( $plugin['name'] ); ?></h3>
									</div>
									<?php if ( ! empty( $plugin['description'] ) ) : ?>
										<p>
											<?php echo wp_kses_post( $plugin['description'] ); ?>
										</p>
									<?php endif; ?>
									<div class="plugin-item-error js-wpzi-plugin-item-error"></div>
									<div class="plugin-item-info js-wpzi-plugin-item-info"></div>
								</div>
								<span class="plugin-item-checkbox">
									<input type="checkbox" id="wpzi-<?php echo esc_attr( $plugin['slug'] ); ?>-plugin" name="<?php echo esc_attr( $plugin['slug'] ); ?>" <?php checked( ! empty( $plugin['preselected'] ) || $is_plugin_active ); ?><?php disabled( $is_plugin_active ) ?>>
									<span class="checkbox">
										<img src="<?php echo esc_url( WPZI_URL . 'assets/images/icons/check-solid-white.svg' ); ?>" class="wpzi-check-icon" alt="<?php esc_attr_e( 'Checkmark icon', 'inspiro-toolkit' ); ?>">
										<img src="<?php echo esc_url( WPZI_URL . 'assets/images/loader.svg' ); ?>" class="wpzi-loading wpzi-loading-md" alt="<?php esc_attr_e( 'Loading...', 'inspiro-toolkit' ); ?>">
									</span>
								</span>
							</label>
						<?php endforeach; ?>
					</div>
					<div class="wpzi-install-plugins-content-footer">
						<a href="<?php echo esc_url( $this->get_plugin_settings_url() ); ?>" class="button"><img src="<?php echo esc_url( WPZI_URL . 'assets/images/icons/long-arrow-alt-left-blue.svg' ); ?>" alt="<?php esc_attr_e( 'Back icon', 'inspiro-toolkit' ); ?>"><span><?php esc_html_e( 'Go Back' , 'inspiro-toolkit' ); ?></span></a>
						<a href="#" class="button button-primary js-wpzi-install-plugins"><?php esc_html_e( 'Install & Activate' , 'inspiro-toolkit' ); ?></a>
					</div>
				</div>
			</div>
			<div class="wpzi__content-container-content--side">
				<?php echo wp_kses_post( ViewHelpers::small_theme_card() ); ?>
			</div>
		</div>

	</div>
</div>
