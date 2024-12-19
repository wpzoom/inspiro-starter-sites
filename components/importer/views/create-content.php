<?php
/**
 * The create content page view.
 *
 * @package wpzi
 */

namespace WPZI;

$demo_content_creator = new CreateDemoContent\DemoContentCreator();
$content_items        = $demo_content_creator->get_default_content();
?>

<div class="wpzi wpzi--create-content">

	<?php echo wp_kses_post( ViewHelpers::plugin_header_output() ); ?>

	<div class="wpzi__content-container">

		<div class="wpzi__admin-notices js-wpzi-admin-notices-container"></div>

		<div class="wpzi__content-container-content">
			<div class="wpzi__content-container-content--main">
				<div class="wpzi-create-content">
					<div class="wpzi-create-content-header">
						<h2><?php esc_html_e( 'Create Demo Content', 'inspiro-toolkit' ); ?></h2>
						<p>
							<?php esc_html_e( 'Select which pre-built pages you want to import to use on your website. After that, all you need to do is customize the content to fit your needs and your page will be good to go.', 'inspiro-toolkit' ); ?>
						</p>
					</div>
					<div class="wpzi-create-content-content">
						<div>
							<?php foreach ( $content_items as $item ) : ?>
								<label class="content-item content-item-<?php echo esc_attr( $item['slug'] ); ?>" for="wpzi-<?php echo esc_attr( $item['slug'] ); ?>-content-item">
									<div class="content-item-content">
										<div class="content-item-content-title">
											<h3><?php echo esc_html( $item['name'] ); ?></h3>
										</div>
										<?php if ( ! empty( $item['description'] ) ) : ?>
											<p>
												<?php echo wp_kses_post( $item['description'] ); ?>
											</p>
										<?php endif; ?>
										<div class="content-item-error js-wpzi-content-item-error"></div>
										<div class="content-item-info js-wpzi-content-item-info"></div>
									</div>
									<span class="content-item-checkbox">
										<input type="checkbox" id="wpzi-<?php echo esc_attr( $item['slug'] ); ?>-content-item" name="<?php echo esc_attr( $item['slug'] ); ?>" data-plugins="<?php echo esc_attr( implode( ',', $item['required_plugins'] ) ); ?>">
										<span class="checkbox">
											<img src="<?php echo esc_url( WPZI_URL . 'assets/images/icons/check-solid-white.svg' ); ?>" class="wpzi-check-icon" alt="<?php esc_attr_e( 'Checkmark icon', 'inspiro-toolkit' ); ?>">
											<img src="<?php echo esc_url( WPZI_URL . 'assets/images/loader.svg' ); ?>" class="wpzi-loading wpzi-loading-md" alt="<?php esc_attr_e( 'Loading...', 'inspiro-toolkit' ); ?>">
										</span>
									</span>
								</label>
							<?php endforeach; ?>
						</div>

						<div class="wpzi-create-content-content-notice js-wpzi-create-content-install-plugins-notice">
							<p>
								<?php esc_html_e( 'The following plugins will be installed for free: ', 'inspiro-toolkit' ); ?>
								<span class="js-wpzi-create-content-install-plugins-list"></span>
							</p>
						</div>
					</div>
					<div class="wpzi-create-content-footer">
						<a href="<?php echo esc_url( $this->get_plugin_settings_url() ); ?>" class="button"><img src="<?php echo esc_url( WPZI_URL . 'assets/images/icons/long-arrow-alt-left-blue.svg' ); ?>" alt="<?php esc_attr_e( 'Back icon', 'inspiro-toolkit' ); ?>"><span><?php esc_html_e( 'Go Back' , 'inspiro-toolkit' ); ?></span></a>
						<a href="#" class="button button-primary js-wpzi-create-content"><?php esc_html_e( 'Import' , 'inspiro-toolkit' ); ?></a>
					</div>
				</div>
			</div>
			<div class="wpzi__content-container-content--side">
				<?php echo wp_kses_post( ViewHelpers::small_theme_card() ); ?>
			</div>
		</div>

	</div>
</div>
