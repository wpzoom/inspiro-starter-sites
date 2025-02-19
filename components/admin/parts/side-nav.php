<?php
/**
 * The importer side navigation view.
 *
 * @package Inspiro\Starter_Sites
 */

namespace Inspiro\Starter_Sites;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

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


<div class="inspiro-starter-sites-onboard_side_nav">
    <div class="wpz-onboard_title-wrapper">
		<h1 class="wpz-onboard_title">
			<svg width="30" height="30" viewBox="0 0 46 46" fill="none" xmlns="https://www.w3.org/2000/svg">
				<path fill-rule="evenodd" clip-rule="evenodd" d="M23 46C35.7025 46 46 35.7025 46 23C46 10.2975 35.7025 0 23 0C10.2975 0 0 10.2975 0 23C0 35.7025 10.2975 46 23 46ZM19.4036 10.3152C19.4036 8.31354 21.0263 6.69091 23.0279 6.69091H26.2897C26.4899 6.69091 26.6521 6.85317 26.6521 7.05333V13.5025C26.6521 13.622 26.5884 13.7324 26.4848 13.7922L19.9055 17.5908C19.6824 17.7196 19.4036 17.5586 19.4036 17.3011V10.3152ZM19.5709 24.0613L26.1503 20.2627C26.3733 20.134 26.6521 20.2949 26.6521 20.5525V35.6849C26.6521 37.6865 25.0295 39.3091 23.0279 39.3091H19.7661C19.5659 39.3091 19.4036 39.1468 19.4036 38.9467V24.3511C19.4036 24.2316 19.4674 24.1211 19.5709 24.0613Z" fill="#242628"></path>
			</svg>
			Inspiro <span>Sites</span>
		</h1>
	</div>
    <ul class="inspiro-starter-sites-onboard_tabs">

        <li class="inspiro-starter-sites-onboard_tab inspiro-starter-sites-onboard_tab-demos ui-tabs-tab ui-corner-top ui-state-default ui-tab ui-tabs-active ui-state-active">
            <a href="<?php echo esc_url( $import_page_url ); ?>#demo-importer" title="Demo to Import">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
					<path d="M3 9H21" stroke="#242628" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M11 5.995L10.995 6L11 6.005L11.005 6L11 5.995" stroke="#242628" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M8.5 5.995L8.495 6L8.5 6.005L8.505 6L8.5 5.995" stroke="#242628" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M6 5.995L5.995 6L6 6.005L6.005 6L6 5.995" stroke="#242628" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M11 5.995L10.995 6L11 6.005L11.005 6L11 5.995" stroke="#242628" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M8.5 5.995L8.495 6L8.5 6.005L8.505 6L8.5 5.995" stroke="#242628" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M6 5.995L5.995 6L6 6.005L6.005 6L6 5.995" stroke="#242628" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M17.5 21H18C19.6569 21 21 19.6569 21 18V6C21 4.34315 19.6569 3 18 3H6C4.34315 3 3 4.34315 3 6V18C3 19.6569 4.34315 21 6 21H6.5" stroke="#242628" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M12 18L14 16" stroke="#242628" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M10 16L12 18" stroke="#242628" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M12 13.5V18" stroke="#242628" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M14 21H10" stroke="#242628" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
				<?php esc_html_e( 'Demo Importer', 'inspiro-starter-sites' ); ?>
            </a>    
        </li>
        <li class="inspiro-starter-sites-onboard_tab inspiro-starter-sites-onboard_tab-premium-demos">

            <a href="<?php echo esc_url( $import_page_url ); ?>#premium-demos" title="Premium Demos">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M4.64479 10.9348L6.77737 11.7451C8.38017 12.3542 9.64583 13.6198 10.2549 15.2226L11.0652 17.3552C11.2127 17.7434 11.5847 18 12 18C12.4152 18 12.7873 17.7434 12.9348 17.3552L13.7451 15.2226C14.3542 13.6198 15.6198 12.3542 17.2226 11.7451L19.3552 10.9348C19.7434 10.7873 20 10.4152 20 9.99999C20 9.58475 19.7434 9.21271 19.3552 9.06521L17.2226 8.25487C15.6198 7.64582 14.3542 6.38016 13.7451 4.77736L12.9348 2.64479C12.7873 2.25662 12.4152 2 12 2C11.5847 2 11.2127 2.25662 11.0652 2.64479L10.2549 4.77736C9.64583 6.38016 8.38017 7.64582 6.77737 8.25487L4.64479 9.06521C4.25662 9.21271 4 9.58475 4 9.99999C4 10.4152 4.25662 10.7873 4.64479 10.9348Z" stroke="#242628" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M4 19V15" stroke="#242628" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M2 17H6" stroke="#242628" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M3 5V1" stroke="#242628" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M1 3H5" stroke="#242628" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <?php esc_html_e( 'Premium Starter Sites', 'inspiro-starter-sites' ); ?>
            </a>
        </li>
    </ul>
</div>