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

// Build "render units": demos that share a 'group' key collapse into a single
// card that offers a Block Editor / Elementor toggle. Everything else renders
// standalone. The underlying import flow is untouched — each variant keeps its
// own entry (and index) in $predefined_themes, so importing still targets the
// correct demo.
$render_units    = array();
$group_positions = array();
foreach ( $predefined_themes as $index => $import_file ) {
	$group = ! empty( $import_file['group'] ) ? $import_file['group'] : '';
	if ( '' !== $group ) {
		if ( ! isset( $group_positions[ $group ] ) ) {
			$group_positions[ $group ] = count( $render_units );
			$render_units[]            = array( 'kind' => 'group', 'group' => $group, 'variants' => array() );
		}
		$render_units[ $group_positions[ $group ] ]['variants'][ $index ] = $import_file;
	} else {
		$render_units[] = array( 'kind' => 'single', 'index' => $index, 'file' => $import_file );
	}
}

// The editor type(s) a render unit covers (a group covers all its variants).
$unit_types = function ( $unit ) {
	$types = array();
	$files = 'group' === $unit['kind'] ? $unit['variants'] : array( $unit['file'] );
	foreach ( $files as $file ) {
		$types[] = ! empty( $file['type'] ) ? $file['type'] : 'blocks';
	}
	return array_values( array_unique( $types ) );
};

// The union of categories across a render unit's variants.
$unit_categories = function ( $unit ) {
	$cats  = array();
	$files = 'group' === $unit['kind'] ? $unit['variants'] : array( $unit['file'] );
	foreach ( $files as $file ) {
		if ( ! empty( $file['categories'] ) && is_array( $file['categories'] ) ) {
			$cats = array_merge( $cats, $file['categories'] );
		}
	}
	return array_values( array_unique( $cats ) );
};

// Tally counts per *card* (render unit) and per editor type for the filters.
// A grouped card counts toward every editor type it offers.
$category_counts          = array();
$category_counts_by_type  = array( 'blocks' => array(), 'elementor' => array() );
$total_by_type            = array( 'blocks' => 0, 'elementor' => 0 );
foreach ( $render_units as $unit ) {
	$types = $unit_types( $unit );
	foreach ( $types as $type ) {
		if ( isset( $total_by_type[ $type ] ) ) {
			$total_by_type[ $type ]++;
		}
	}
	foreach ( $unit_categories( $unit ) as $cat ) {
		$category_counts[ $cat ] = isset( $category_counts[ $cat ] ) ? $category_counts[ $cat ] + 1 : 1;
		foreach ( $types as $type ) {
			if ( isset( $category_counts_by_type[ $type ] ) ) {
				$category_counts_by_type[ $type ][ $cat ] = isset( $category_counts_by_type[ $type ][ $cat ] )
					? $category_counts_by_type[ $type ][ $cat ] + 1
					: 1;
			}
		}
	}
}

$total_demos = count( $render_units );

// Render the primary import button (+ optional delete link) for a given demo
// variant by its index in $predefined_themes. Returned as an HTML string so it
// can be reused for both standalone and grouped cards.
$render_import_action = function ( $index, $import_file ) use ( $imported_demo_id ) {
	$import_btn_label       = esc_html__( 'Import Demo', 'inspiro-starter-sites' );
	$imported_demo          = false;
	$imported_btn_classname = '';

	if ( $imported_demo_id && $imported_demo_id == $import_file['import_id'] ) {
		$imported_demo          = true;
		$imported_btn_classname = 'button-secondary';
		$import_btn_label       = esc_html__( 'Imported', 'inspiro-starter-sites' );
	}

	$step_url        = wp_nonce_url( $this->get_plugin_settings_url( [ 'step' => 'import', 'import' => esc_attr( $index ) ] ), 'importer_step' );
	$delete_step_url = wp_nonce_url( $this->get_plugin_settings_url( [ 'step' => 'delete_import', 'imported_demo' => esc_attr( $index ) ] ), 'importer_step' );

	ob_start();
	?>
	<a href="<?php echo esc_url( $step_url ); ?>" class="button button-primary <?php echo esc_attr( $imported_btn_classname ); ?>"><?php echo esc_html( $import_btn_label ); ?></a>
	<?php if ( $imported_demo ) : ?>
		<a href="<?php echo esc_url( $delete_step_url ); ?>" class="delete-imported-demo-content" title="<?php esc_attr_e( 'Delete imported demo content', 'inspiro-starter-sites' ); ?>"></a>
	<?php endif;
	return ob_get_clean();
};

// Allowed HTML for the import-action markup produced above.
$import_action_allowed_html = array(
	'a' => array(
		'href'  => array(),
		'class' => array(),
		'title' => array(),
	),
);

// Inline editor icons (Gutenberg glyph + Elementor mark), keyed by type.
$editor_icons = array(
	'blocks'    => '<svg width="17" height="18" viewBox="0 0 17 18" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M15.3194 6.26524C15.5486 5.92364 16.0111 5.83121 16.349 6.06028C16.7029 6.28935 16.7954 6.7515 16.53 7.08506C15.4682 8.68452 13.787 9.3637 12.5523 9.66108L12.5121 13.9169C12.307 15.1105 11.7117 16.075 10.7063 16.8064C9.60427 17.6062 8.23683 18 6.57981 18C4.61311 18 3.02447 17.349 1.8179 16.063C0.611332 14.777 0 13.0529 0 10.8989H0.0120664L0 7.08908C0 6.95244 0.0120664 6.81982 0.0120664 6.68318C0.0804385 4.73007 0.679705 3.14266 1.8179 1.93704C3.02447 0.651036 4.61311 0 6.57981 0C8.23683 0 9.60427 0.393835 10.7022 1.2016C11.8002 2.00134 12.4116 3.05827 12.5483 4.40053V4.49699C12.5483 5.01139 12.126 5.43335 11.6112 5.43335C11.0964 5.43335 10.6741 5.01139 10.6741 4.49699V4.40053C10.6057 3.50435 10.1995 2.81313 9.47959 2.31078C8.75967 1.80844 7.79844 1.55124 6.61601 1.55124C5.20432 1.55124 4.06613 2.03751 3.1974 3.00201C2.39302 3.88212 1.97475 5.11587 1.90638 6.67515L1.89431 10.9069C1.89431 12.6711 2.32867 14.0375 3.1974 15.002C4.05004 15.9665 5.20432 16.4528 6.61601 16.4528C7.79844 16.4528 8.75967 16.1956 9.47959 15.6932C10.1754 15.207 10.5655 14.5559 10.662 13.7281V9.89015C8.28911 10.0228 7.36408 12.0844 7.35201 12.1125C7.22733 12.3979 6.95787 12.5586 6.67231 12.5586C6.57981 12.5586 6.47122 12.5305 6.37469 12.4903C6.0087 12.3255 5.83576 11.8915 5.99664 11.5137C6.04892 11.3932 7.39223 8.39518 10.9074 8.39518H10.9757C11.1245 8.39518 13.9761 8.28667 15.3194 6.26524Z" fill="currentColor"/></svg>',
	'elementor' => '<svg viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" focusable="false"><circle cx="16" cy="16" r="16" fill="#B6224C"/><rect x="10" y="9.5" width="2.4" height="13" rx="0.4" fill="#fff"/><rect x="14.6" y="9.5" width="7.4" height="2.4" rx="0.4" fill="#fff"/><rect x="14.6" y="14.8" width="7.4" height="2.4" rx="0.4" fill="#fff"/><rect x="14.6" y="20.1" width="7.4" height="2.4" rx="0.4" fill="#fff"/></svg>',
);

$editor_labels = array(
	'blocks'    => esc_html__( 'Block Editor', 'inspiro-starter-sites' ),
	'elementor' => esc_html__( 'Elementor', 'inspiro-starter-sites' ),
);

// Optional platform logos shown in the "Available for" line instead of the
// editor icon (e.g. a WooCommerce store). Keyed by a demo's 'platform' value.
$platform_icons = array(
	'woocommerce' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 95 26" focusable="false"><path d="M12.0825 25.2704C14.8471 25.2704 17.0657 23.9052 18.7381 20.7651L22.4584 13.8023V19.707C22.4584 23.1884 24.7111 25.2704 28.1925 25.2704C30.923 25.2704 32.9368 24.0758 34.8822 20.7651L43.4492 6.29339C45.3264 3.11918 43.9953 0.72998 39.8654 0.72998C37.6469 0.72998 36.2134 1.44674 34.9164 3.87006L29.0117 14.9628V5.09879C29.0117 2.1635 27.6123 0.72998 25.0183 0.72998C22.9704 0.72998 21.3321 1.6174 20.0692 4.07485L14.5058 14.9628V5.20119C14.5058 2.0611 13.2088 0.72998 10.0687 0.72998H3.65206C1.22873 0.72998 0 1.85632 0 3.93833C0 6.02034 1.29699 7.21494 3.65206 7.21494H6.28017V19.6729C6.28017 23.1884 8.63523 25.2704 12.0825 25.2704Z" fill="#873EFF"/><path fill-rule="evenodd" clip-rule="evenodd" d="M55.9772 0.72998C48.9803 0.72998 43.6217 5.95208 43.6217 13.0173C43.6217 20.0825 49.0144 25.2704 55.9772 25.2704C62.94 25.2704 68.2645 20.0483 68.2986 13.0173C68.2986 5.95208 62.94 0.72998 55.9772 0.72998ZM55.9772 17.7274C53.3491 17.7274 51.5401 15.7478 51.5401 13.0173C51.5401 10.2868 53.3491 8.27301 55.9772 8.27301C58.6053 8.27301 60.4143 10.2868 60.4143 13.0173C60.4143 15.7478 58.6395 17.7274 55.9772 17.7274Z" fill="#873EFF"/><path fill-rule="evenodd" clip-rule="evenodd" d="M70.0369 13.0173C70.0369 5.95208 75.3955 0.72998 82.3583 0.72998C89.3211 0.72998 94.6797 5.98621 94.6797 13.0173C94.6797 20.0483 89.3211 25.2704 82.3583 25.2704C75.3955 25.2704 70.0369 20.0825 70.0369 13.0173ZM77.9554 13.0173C77.9554 15.7478 79.6961 17.7274 82.3583 17.7274C84.9864 17.7274 86.7954 15.7478 86.7954 13.0173C86.7954 10.2868 84.9864 8.27301 82.3583 8.27301C79.7302 8.27301 77.9554 10.2868 77.9554 13.0173Z" fill="#873EFF"/></svg>',
);

$platform_labels = array(
	'woocommerce' => esc_html__( 'WooCommerce', 'inspiro-starter-sites' ),
);

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

<div class="inspiro-starter-sites-feedback-root js-inspiro-starter-sites-feedback-root" hidden></div>

<ol class="wpz-onboard_content-main-steps">
	<li id="step-choose-design" class="wpz-onboard_content-main-step step-1 step-choose-design">
		<form method="post" action="#">
			<ul>
				<?php foreach ( $render_units as $unit ) : ?>
					<?php if ( 'single' === $unit['kind'] ) :
						$index       = $unit['index'];
						$import_file = $unit['file'];

						$import_btn_label       = esc_html__( 'Import Demo', 'inspiro-starter-sites' );
						$imported_demo          = false;
						$imported_btn_classname = '';

						if ( $imported_demo_id && $imported_demo_id == $import_file['import_id'] ) {
							$imported_demo          = true;
							$imported_btn_classname = 'button-secondary';
							$import_btn_label       = esc_html__( 'Imported', 'inspiro-starter-sites' );
						}

						// Prepare import item display data.
						$img_src = isset( $import_file['import_preview_image_url'] ) ? $import_file['import_preview_image_url'] : '';
						// Default to the theme screenshot, if a custom preview image is not defined.
						if ( empty( $img_src ) ) {
							$theme   = wp_get_theme();
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
							<a href="<?php echo esc_url( $import_file['preview_url'] ); ?>" target="_blank" class="button-select-template"><?php esc_html_e( 'View Demo', 'inspiro-starter-sites' ); ?></a></div>
						<figcaption>
							<div class="inspiro-starter-sites-demo-name">
								<h5><?php echo esc_html( $import_file['import_file_name'] ); ?></h5>
								<div class="inspiro-starter-sites-demo-badges">
									<span class="inspiro-starter-sites-demo-badge inspiro-starter-sites-demo-badge-free"><?php esc_html_e( 'Free', 'inspiro-starter-sites' ); ?></span>
									<?php if ( ! empty( $import_file['is_new'] ) ) : ?>
										<span class="inspiro-starter-sites-demo-badge inspiro-starter-sites-demo-badge-new"><?php esc_html_e( 'New', 'inspiro-starter-sites' ); ?></span>
									<?php endif; ?>
								</div>
							</div>

								<?php
									// Editor badge is always shown; a platform badge (e.g. WooCommerce) is appended when set.
									$availability_items = array(
										array(
											'kind'  => $demo_type,
											'icon'  => isset( $editor_icons[ $demo_type ] ) ? $editor_icons[ $demo_type ] : $editor_icons['blocks'],
											'label' => isset( $editor_labels[ $demo_type ] ) ? $editor_labels[ $demo_type ] : $editor_labels['blocks'],
										),
									);

									$availability_platform = ! empty( $import_file['platform'] ) ? $import_file['platform'] : '';
									if ( $availability_platform && isset( $platform_icons[ $availability_platform ] ) ) {
										$availability_items[] = array(
											'kind'  => $availability_platform,
											'icon'  => $platform_icons[ $availability_platform ],
											'label' => isset( $platform_labels[ $availability_platform ] ) ? $platform_labels[ $availability_platform ] : $availability_platform,
										);
									}
								?>
								<p class="inspiro-starter-sites-demo-availability">
									<span class="inspiro-starter-sites-demo-availability__label"><?php esc_html_e( 'Available for', 'inspiro-starter-sites' ); ?></span>
									<?php foreach ( $availability_items as $availability_item ) : ?>
										<span class="inspiro-starter-sites-demo-availability__icon inspiro-starter-sites-demo-availability__icon--<?php echo esc_attr( $availability_item['kind'] ); ?>" title="<?php echo esc_attr( $availability_item['label'] ); ?>" aria-hidden="true">
											<?php echo $availability_item['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted static inline SVG ?>
										</span>
										<span class="screen-reader-text"><?php echo esc_html( $availability_item['label'] ); ?></span>
									<?php endforeach; ?>
								</p>

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
					<?php else :
						// Grouped card: one tile with a Block Editor / Elementor toggle.
						$variants = $unit['variants'];

						// Order variants blocks-first for a predictable default + toggle order.
						uasort( $variants, function ( $a, $b ) {
							$order = array( 'blocks' => 0, 'elementor' => 1 );
							$ta    = ! empty( $a['type'] ) ? $a['type'] : 'blocks';
							$tb    = ! empty( $b['type'] ) ? $b['type'] : 'blocks';
							$wa    = isset( $order[ $ta ] ) ? $order[ $ta ] : 9;
							$wb    = isset( $order[ $tb ] ) ? $order[ $tb ] : 9;
							return $wa <=> $wb;
						} );

						$active_index = array_key_first( $variants );

						// Restore the toggle to whichever variant was actually imported,
						// so returning to this page shows the right editor + "Imported" state.
						if ( $imported_demo_id ) {
							foreach ( $variants as $vidx => $vfile ) {
								if ( isset( $vfile['import_id'] ) && $imported_demo_id == $vfile['import_id'] ) {
									$active_index = $vidx;
									break;
								}
							}
						}

						$active_file  = $variants[ $active_index ];
						$active_type  = ! empty( $active_file['type'] ) ? $active_file['type'] : 'blocks';

						$group_name = '';
						foreach ( $variants as $vfile ) {
							if ( ! empty( $vfile['group_label'] ) ) { $group_name = $vfile['group_label']; break; }
						}
						if ( '' === $group_name ) { $group_name = $active_file['import_file_name']; }

						$active_img = isset( $active_file['import_preview_image_url'] ) ? $active_file['import_preview_image_url'] : '';
						if ( empty( $active_img ) ) { $theme = wp_get_theme(); $active_img = $theme->get_screenshot(); }

						$demo_types_attr = implode( ' ', array_map( 'sanitize_html_class', $unit_types( $unit ) ) );
						$demo_categories = implode( ' ', array_map( 'sanitize_html_class', $unit_categories( $unit ) ) );
					?>
					<li class="inspiro-starter-sites-demo-card-grouped" data-name="<?php echo esc_attr( strtolower( $group_name ) ); ?>" data-import-id="<?php echo esc_attr( $active_file['import_id'] ); ?>" data-type="<?php echo esc_attr( $demo_types_attr ); ?>" data-categories="<?php echo esc_attr( $demo_categories ); ?>" data-active-variant="<?php echo esc_attr( $active_type ); ?>">
						<figure title="<?php echo esc_attr( $group_name ); ?>">
							<div class="preview-thumbnail inspiro-starter-sites-import js-inspiro-starter-sites-variant-thumb" style="background-image:url('<?php echo esc_url( $active_img ); ?>')">
								<a href="<?php echo esc_url( $active_file['preview_url'] ); ?>" target="_blank" class="button-select-template js-inspiro-starter-sites-variant-preview"><?php esc_html_e( 'View Demo', 'inspiro-starter-sites' ); ?></a></div>
							<figcaption>
								<div class="inspiro-starter-sites-demo-name">
									<h5><?php echo esc_html( $group_name ); ?></h5>
									<div class="inspiro-starter-sites-demo-badges">
										<span class="inspiro-starter-sites-demo-badge inspiro-starter-sites-demo-badge-free"><?php esc_html_e( 'Free', 'inspiro-starter-sites' ); ?></span>
										<span class="inspiro-starter-sites-demo-badge inspiro-starter-sites-demo-badge-new js-inspiro-starter-sites-variant-new"<?php echo empty( $active_file['is_new'] ) ? ' style="display:none;"' : ''; ?>><?php esc_html_e( 'New', 'inspiro-starter-sites' ); ?></span>
									</div>
								</div>

								<div class="inspiro-starter-sites-demo-variants" role="group" aria-label="<?php esc_attr_e( 'Choose page builder', 'inspiro-starter-sites' ); ?>">
									<?php foreach ( $variants as $vindex => $vfile ) :
										$vtype     = ! empty( $vfile['type'] ) ? $vfile['type'] : 'blocks';
										$vlabel    = isset( $editor_labels[ $vtype ] ) ? $editor_labels[ $vtype ] : $vtype;
										$is_active = ( $vindex === $active_index );
										$vimg      = isset( $vfile['import_preview_image_url'] ) ? $vfile['import_preview_image_url'] : '';
										if ( empty( $vimg ) ) { $theme = wp_get_theme(); $vimg = $theme->get_screenshot(); }
									?>
										<button type="button" class="inspiro-starter-sites-demo-variant-btn inspiro-starter-sites-demo-variant-btn--<?php echo esc_attr( $vtype ); ?><?php echo $is_active ? ' is-active' : ''; ?>" data-variant="<?php echo esc_attr( $vtype ); ?>" data-img="<?php echo esc_url( $vimg ); ?>" data-preview="<?php echo esc_url( $vfile['preview_url'] ); ?>" data-is-new="<?php echo ! empty( $vfile['is_new'] ) ? '1' : '0'; ?>" aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>">
											<span class="inspiro-starter-sites-demo-variant-btn__icon" aria-hidden="true"><?php echo isset( $editor_icons[ $vtype ] ) ? $editor_icons[ $vtype ] : $editor_icons['blocks']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted static inline SVG ?></span>
											<span class="inspiro-starter-sites-demo-variant-btn__label"><?php echo esc_html( $vlabel ); ?></span>
										</button>
									<?php endforeach; ?>
								</div>

								<?php foreach ( $variants as $vindex => $vfile ) :
									$vtype     = ! empty( $vfile['type'] ) ? $vfile['type'] : 'blocks';
									$is_active = ( $vindex === $active_index );
								?>
									<div class="inspiro-starter-sites-demo-variant-action js-inspiro-starter-sites-variant-action" data-variant="<?php echo esc_attr( $vtype ); ?>"<?php echo $is_active ? '' : ' style="display:none;"'; ?>>
										<?php echo wp_kses( $render_import_action( $vindex, $vfile ), $import_action_allowed_html ); ?>
									</div>
								<?php endforeach; ?>

							</figcaption>
						</figure>
					</li>
					<?php endif; ?>
				<?php endforeach; ?>
					<li class="inspiro-starter-sites-demo-suggest-card">
						<button type="button" class="inspiro-starter-sites-demo-suggest-tile js-inspiro-starter-sites-suggest-demo">
							<span class="inspiro-starter-sites-demo-suggest-tile__icon" aria-hidden="true">+</span>
							<span class="inspiro-starter-sites-demo-suggest-tile__label"><?php esc_html_e( 'Suggest a new demo', 'inspiro-starter-sites' ); ?></span>
							<span class="inspiro-starter-sites-demo-suggest-tile__hint"><?php esc_html_e( 'Tell us what you would like us to build next', 'inspiro-starter-sites' ); ?></span>
						</button>
					</li>
			</ul>
		</form>
	</li>
</ol>

<?php if ( ! empty( $premium_section['demos'] ) ) : ?>
    <hr />
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
									<a href="<?php echo esc_url( $demo['preview_url'] ); ?>" target="_blank" rel="noopener">
										<img src="<?php echo esc_url( $demo['import_preview_image_url'] ); ?>" alt="<?php echo esc_attr( $demo['import_file_name'] ); ?>" />
									</a>
								</div>
								<figcaption>
									<div class="inspiro-starter-sites-demo-name">
										<h5><?php echo esc_html( $demo['import_file_name'] ); ?></h5>
										<span class="inspiro-starter-sites-demo-badge inspiro-starter-sites-demo-badge-premium"><?php esc_html_e( 'Premium', 'inspiro-starter-sites' ); ?></span>
									</div>
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
