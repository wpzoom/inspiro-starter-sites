<?php
/**
 * The Premium Demos page view.
 *
 * @package Inspiro\Starter_Sites
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

$premium_demos = apply_filters( 'inspiro_starter_sites_premium_demos', array() );
	
?>

<?php foreach ( $premium_demos as $index => $demo_section ) : ?>
	<div class="plugin-info-wrap">
		<h3 class="inspiro-starter-sites-onboard_content-main-title"><?php echo esc_html( $demo_section['name'] ); ?> <?php echo esc_html__( 'Demos', 'inspiro-starter-sites' ); ?> <?php echo '(' . count( $demo_section['demos'] ) . ')' ?></h3>
		<p class="inspiro-starter-sites-onboard_content-main-intro"><?php echo wp_kses_post( $demo_section['desc'] ); ?></p>
		<ol class="inspiro-starter-sites-onboard_content-main-steps">
			<li id="step-choose-design" class="inspiro-starter-sites-onboard_content-main-step step-1 step-choose-design">
				<div class="inspiro-starter-sites-onboard_content-main-step-content">
					<form method="post" action="#">
						<ul>
							<?php foreach ( $demo_section['demos'] as $demo ) {  ?>
							<li>
								<figure title="<?php echo esc_attr( $demo['import_file_name'] ); ?>">
									<div class="preview-thumbnail" style="background-image:url('<?php echo esc_url( $demo['import_preview_image_url'] ); ?>')">
										<a href="<?php echo esc_url( $demo['preview_url'] ); ?>" target="_blank" class="button-select-template"><?php esc_html_e( 'View Demo', 'inspiro-starter-sites' ); ?></a>
									</div>
									<figcaption>
										<h5><?php echo esc_html( $demo['import_file_name'] ); ?></h5>

										<p>
											<a href="<?php echo esc_url( $demo['preview_url'] ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Live preview', 'inspiro-starter-sites' ); ?>"><?php esc_html_e( 'Live preview', 'inspiro-starter-sites' ); ?></a>

										</p>
									</figcaption>
								</figure>
							</li>
							<?php } ?>
							
						</ul>
					</form>
				</div>
			</li>

		</ol>


		<br/>
		<br/>
        <a href="<?php echo esc_url( $demo_section['purchase'] ); ?>?utm_source=wpadmin&utm_medium=demos-starter-sites&utm_campaign=starter-sites-premium" target="_blank" class="button button-large button-primary">
			<?php
				// translators: %s: Demo name
				printf( esc_html__( 'Get %s Today &rarr;', 'inspiro-starter-sites' ), esc_html( $demo_section['name'] ) );
			?>
		</a>
	</div>
<?php endforeach; ?>	
