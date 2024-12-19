<?php

$premium_demos = apply_filters( 'inspiro_toolkit_premium_demos', array() );
	
?>

<?php foreach ( $premium_demos as $index => $demo_section ) : ?>
	<div class="theme-info-wrap">
		<h3 class="wpz-onboard_content-main-title"><?php echo esc_html( $demo_section['name'] ); ?></h3>
		<p class="wpz-onboard_content-main-intro"><?php echo wp_kses_post( $demo_section['desc'] ); ?></p>
		<ol class="wpz-onboard_content-main-steps">
			<li id="step-choose-design" class="wpz-onboard_content-main-step step-1 step-choose-design">
				<div class="wpz-onboard_content-main-step-content">
					<form method="post" action="#">
						<ul>
							
							<li class="design_default-elementor">
								<figure title="Portfolio (Default)">
									<div class="preview-thumbnail"
											style="background-image:url('https://demo.wpzoom.com/inspiro-pro-demo/wp-content/themes/inspiro-pro-select/images/site-layout_agency-dark.png')">
										<a href="https://demo.wpzoom.com/inspiro-agency2/" target="_blank"
											class="button-select-template">View Demo</a></div>
									<figcaption>
										<h5>Agency / Business (new)</h5>

										<p>
											<a href="https://demo.wpzoom.com/inspiro-agency2/" target="_blank" rel="noopener" title="Live preview">Live preview</a>

										</p>
									</figcaption>
								</figure>
							</li>
							
						</ul>
					</form>
				</div>
			</li>

		</ol>


		<br/>
		<br/>
		<a href="<?php echo esc_url( $demo_section['purchase'] ); ?>" target="_blank" class="button button-large button-primary">
			<?php esc_html_e( 'Get Inspiro Premium Today &rarr;', 'inspiro' ); ?>
		</a>
	</div>
<?php endforeach; ?>	
