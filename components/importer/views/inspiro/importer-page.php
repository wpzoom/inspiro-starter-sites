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

$category_labels = class_exists( '\Inspiro_Starter_Sites_Importer_Setup' )
	? \Inspiro_Starter_Sites_Importer_Setup::get_instance()->category_labels()
	: array();

// Tally category occurrences across all free demos, and a parallel tally per editor type.
$category_counts          = array();
$category_counts_by_type  = array( 'blocks' => array(), 'elementor' => array() );
$total_by_type            = array( 'blocks' => 0, 'elementor' => 0 );
foreach ( $predefined_themes as $import_file ) {
	$type = ! empty( $import_file['type'] ) ? $import_file['type'] : 'blocks';
	if ( isset( $total_by_type[ $type ] ) ) {
		$total_by_type[ $type ]++;
	}
	if ( ! empty( $import_file['categories'] ) && is_array( $import_file['categories'] ) ) {
		foreach ( $import_file['categories'] as $cat ) {
			$category_counts[ $cat ] = isset( $category_counts[ $cat ] ) ? $category_counts[ $cat ] + 1 : 1;
			if ( isset( $category_counts_by_type[ $type ] ) ) {
				$category_counts_by_type[ $type ][ $cat ] = isset( $category_counts_by_type[ $type ][ $cat ] )
					? $category_counts_by_type[ $type ][ $cat ] + 1
					: 1;
			}
		}
	}
}

$total_demos = count( $predefined_themes );

?>
<div class="inspiro-starter-sites">
<div class="inspiro-starter-sites-demo-section">
	<h3 class="inspiro-starter-sites-demo-section-title"><?php esc_html_e( 'Free Starter Sites', 'inspiro-starter-sites' ); ?></h3>
	<p class="inspiro-starter-sites-demo-section-description"><?php esc_html_e( 'Import any of these starter sites directly into Inspiro Lite.', 'inspiro-starter-sites' ); ?></p>

	<div class="inspiro-starter-sites-demo-filter" role="tablist" aria-label="<?php esc_attr_e( 'Filter starter sites by editor', 'inspiro-starter-sites' ); ?>">
		<button type="button" class="inspiro-starter-sites-demo-filter-btn is-active" data-filter="all" aria-pressed="true">
			<span class="inspiro-starter-sites-demo-filter-btn__label"><?php esc_html_e( 'All Themes', 'inspiro-starter-sites' ); ?></span>
		</button>
		<button type="button" class="inspiro-starter-sites-demo-filter-btn inspiro-starter-sites-demo-filter-btn--blocks" data-filter="blocks" aria-pressed="false">
			<span class="inspiro-starter-sites-demo-filter-btn__icon" aria-hidden="true">
			<svg width="17" height="18" viewBox="0 0 17 18" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M15.3194 6.26524C15.5486 5.92364 16.0111 5.83121 16.349 6.06028C16.7029 6.28935 16.7954 6.7515 16.53 7.08506C15.4682 8.68452 13.787 9.3637 12.5523 9.66108L12.5121 13.9169C12.307 15.1105 11.7117 16.075 10.7063 16.8064C9.60427 17.6062 8.23683 18 6.57981 18C4.61311 18 3.02447 17.349 1.8179 16.063C0.611332 14.777 0 13.0529 0 10.8989H0.0120664L0 7.08908C0 6.95244 0.0120664 6.81982 0.0120664 6.68318C0.0804385 4.73007 0.679705 3.14266 1.8179 1.93704C3.02447 0.651036 4.61311 0 6.57981 0C8.23683 0 9.60427 0.393835 10.7022 1.2016C11.8002 2.00134 12.4116 3.05827 12.5483 4.40053V4.49699C12.5483 5.01139 12.126 5.43335 11.6112 5.43335C11.0964 5.43335 10.6741 5.01139 10.6741 4.49699V4.40053C10.6057 3.50435 10.1995 2.81313 9.47959 2.31078C8.75967 1.80844 7.79844 1.55124 6.61601 1.55124C5.20432 1.55124 4.06613 2.03751 3.1974 3.00201C2.39302 3.88212 1.97475 5.11587 1.90638 6.67515L1.89431 10.9069C1.89431 12.6711 2.32867 14.0375 3.1974 15.002C4.05004 15.9665 5.20432 16.4528 6.61601 16.4528C7.79844 16.4528 8.75967 16.1956 9.47959 15.6932C10.1754 15.207 10.5655 14.5559 10.662 13.7281V9.89015C8.28911 10.0228 7.36408 12.0844 7.35201 12.1125C7.22733 12.3979 6.95787 12.5586 6.67231 12.5586C6.57981 12.5586 6.47122 12.5305 6.37469 12.4903C6.0087 12.3255 5.83576 11.8915 5.99664 11.5137C6.04892 11.3932 7.39223 8.39518 10.9074 8.39518H10.9757C11.1245 8.39518 13.9761 8.28667 15.3194 6.26524Z" fill="currentColor"/></svg>		</span>
			<span class="inspiro-starter-sites-demo-filter-btn__label"><?php esc_html_e( 'Block Editor', 'inspiro-starter-sites' ); ?></span>
		</button>
		<button type="button" class="inspiro-starter-sites-demo-filter-btn inspiro-starter-sites-demo-filter-btn--elementor" data-filter="elementor" aria-pressed="false">
			<span class="inspiro-starter-sites-demo-filter-btn__icon" aria-hidden="true">
				<svg viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" focusable="false"><circle cx="16" cy="16" r="16" fill="#B6224C"/><rect x="10" y="9.5" width="2.4" height="13" rx="0.4" fill="#fff"/><rect x="14.6" y="9.5" width="7.4" height="2.4" rx="0.4" fill="#fff"/><rect x="14.6" y="14.8" width="7.4" height="2.4" rx="0.4" fill="#fff"/><rect x="14.6" y="20.1" width="7.4" height="2.4" rx="0.4" fill="#fff"/></svg>
			</span>
			<span class="inspiro-starter-sites-demo-filter-btn__label"><?php esc_html_e( 'Elementor', 'inspiro-starter-sites' ); ?></span>
		</button>
		 
	</div>

	<?php if ( ! empty( $category_counts ) ) : ?>
		<ul class="inspiro-starter-sites-demo-categories" role="tablist" aria-label="<?php esc_attr_e( 'Filter starter sites by category', 'inspiro-starter-sites' ); ?>">
			<li>
				<button type="button" class="inspiro-starter-sites-demo-category is-active" data-category="all" aria-pressed="true">
					<span class="inspiro-starter-sites-demo-category__label"><?php esc_html_e( 'All categories', 'inspiro-starter-sites' ); ?></span>
					<span class="inspiro-starter-sites-demo-category__count" data-count-all="<?php echo esc_attr( $total_demos ); ?>" data-count-blocks="<?php echo esc_attr( $total_by_type['blocks'] ); ?>" data-count-elementor="<?php echo esc_attr( $total_by_type['elementor'] ); ?>"><?php echo esc_html( $total_demos ); ?></span>
				</button>
			</li>
			<?php foreach ( $category_labels as $slug => $label ) :
				if ( empty( $category_counts[ $slug ] ) ) {
					continue;
				}
				$count_all       = (int) $category_counts[ $slug ];
				$count_blocks    = isset( $category_counts_by_type['blocks'][ $slug ] ) ? (int) $category_counts_by_type['blocks'][ $slug ] : 0;
				$count_elementor = isset( $category_counts_by_type['elementor'][ $slug ] ) ? (int) $category_counts_by_type['elementor'][ $slug ] : 0;
			?>
				<li>
					<button type="button" class="inspiro-starter-sites-demo-category" data-category="<?php echo esc_attr( $slug ); ?>" aria-pressed="false">
						<span class="inspiro-starter-sites-demo-category__label"><?php echo esc_html( $label ); ?></span>
						<span class="inspiro-starter-sites-demo-category__count" data-count-all="<?php echo esc_attr( $count_all ); ?>" data-count-blocks="<?php echo esc_attr( $count_blocks ); ?>" data-count-elementor="<?php echo esc_attr( $count_elementor ); ?>"><?php echo esc_html( $count_all ); ?></span>
					</button>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
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

					$demo_type       = ! empty( $import_file['type'] ) ? $import_file['type'] : 'blocks';
					$demo_categories = ! empty( $import_file['categories'] ) && is_array( $import_file['categories'] )
						? implode( ' ', array_map( 'sanitize_html_class', $import_file['categories'] ) )
						: '';

				?>
				<li data-name="<?php echo esc_attr( strtolower( $import_file['import_file_name'] ) ); ?>" data-import-id="<?php echo esc_attr( $import_file['import_id'] ); ?>" data-type="<?php echo esc_attr( $demo_type ); ?>" data-categories="<?php echo esc_attr( $demo_categories ); ?>">
					<figure title="<?php echo esc_attr( $import_file['import_file_name'] ); ?>">
						<div class="preview-thumbnail inspiro-starter-sites-import" style="background-image:url('<?php echo esc_url( $img_src ) ?>')">
							<div class="inspiro-starter-sites-demo-badges">
								<span class="inspiro-starter-sites-demo-badge inspiro-starter-sites-demo-badge-free"><?php esc_html_e( 'Free', 'inspiro-starter-sites' ); ?></span>
								<?php if ( ! empty( $import_file['is_new'] ) ) : ?>
									<span class="inspiro-starter-sites-demo-badge inspiro-starter-sites-demo-badge-new"><?php esc_html_e( 'New', 'inspiro-starter-sites' ); ?></span>
								<?php endif; ?>
							</div>
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
</div>
