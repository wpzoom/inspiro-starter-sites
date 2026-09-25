<?php
/**
 * Theme-level decisions the AI makes for a demo, mapped onto Inspiro.
 *
 * The plan chooses a header per page (solid, or transparent over a photo
 * hero — a page template) and the site header/footer look (theme mods).
 * Inspiro Lite and Inspiro Premium share the header layout mods but name
 * their color mods differently; this class owns that mapping so the
 * generator only deals with the AI's vocabulary.
 *
 * @package Inspiro Starter Sites
 */

namespace Inspiro\Starter_Sites\Ai;

defined( 'ABSPATH' ) || exit;

class ThemeOptions {

	const TEMPLATE_SOLID       = 'page-templates/full-width-no-title.php';
	const TEMPLATE_TRANSPARENT = 'page-templates/full-width-transparent.php';

	/**
	 * Tracked mods whose snapshot value false is a real value (restore it),
	 * not "unset" — see AiDemoGenerator::delete_ai_demos().
	 */
	const BOOLEAN_MODS = array( 'hero_enable' );

	/**
	 * AI header layout slug => `header-menu-style` value (same mod in both
	 * themes; the last two are Premium-only). The "hidden menu" style
	 * (wpz_menu_hamburger) is deliberately absent: both themes hide the
	 * side-panel menu above 1024px, so it left the desktop site with no
	 * navigation at all.
	 */
	const HEADER_LAYOUTS = array(
		'classic'        => 'wpz_menu_normal',
		'menu-left'      => 'wpz_menu_left',
		'menu-center'    => 'wpz_menu_center',
		'split-logo'     => 'wpz_menu_left_logo_center',
		'stacked-center' => 'wpz_menu_center_logo_center',
	);

	/**
	 * Color mods per theme: AI color key => theme mod names it paints.
	 * The header text color also drives logo, hover, hamburger and search
	 * icon so the whole bar stays readable against its background.
	 */
	const COLOR_MODS = array(
		'lite'    => array(
			'header_bg'   => array( 'color_menu_background' ),
			'header_text' => array( 'color_header_menu_color', 'color_header_menu_color_hover', 'color_header_custom_logo_text', 'color_header_custom_logo_hover_text', 'color_menu_hamburger_btn', 'color_menu_search_icon_btn' ),
			'footer_bg'   => array( 'color_footer_background' ),
			'footer_text' => array( 'color_footer_text', 'color_footer_copyright_text' ),
		),
		'premium' => array(
			'header_bg'   => array( 'color-menu-background' ),
			'header_text' => array( 'color-menu-link', 'color-menu-link-hover', 'color-menu-link-current', 'color-logo', 'color-logo-hover', 'color-menu-hamburger' ),
			'footer_bg'   => array( 'footer-background-color' ),
			'footer_text' => array( 'footer-text-color', 'footer-link-color', 'footer-title-color' ),
		),
	);

	/**
	 * Which Inspiro is active: 'premium', 'lite' or '' (another theme —
	 * nothing is changed then).
	 *
	 * @return string
	 */
	public static function theme_kind() {
		if ( AiDemoGenerator::is_premium_inspiro() ) {
			return 'premium';
		}

		return ( 'inspiro' === get_template() || function_exists( 'inspiro_get_color_palettes' ) ) ? 'lite' : '';
	}

	/**
	 * Dialect features this client can render, announced to the AI server
	 * (vars.caps) so it only asks for what the converter and theme support.
	 *
	 * @return string[]
	 */
	public static function caps() {
		$caps = array( 'layout_attrs' );

		// Native grid/row/stack groups: the responsive grid (Max. columns +
		// Min. column width) and child spans need WordPress 6.6+.
		if ( version_compare( get_bloginfo( 'version' ), '6.6', '>=' ) ) {
			$caps[] = 'layout_group';
		}

		if ( self::has_template( self::TEMPLATE_TRANSPARENT ) ) {
			$caps[] = 'page_header';
		}
		if ( '' !== self::theme_kind() ) {
			$caps[] = 'theme_options';
		}
		if ( self::supports_block_css() ) {
			$caps[] = 'block_css';
		}
		if ( '' !== self::icon_placeholder() ) {
			$caps[] = 'icon_block';
		}
		if ( self::supports_html() ) {
			$caps[] = 'html_block';
		}

		return $caps;
	}

	/**
	 * Per-block custom CSS (WordPress 7.0 `style.css` block support). Saving
	 * it also requires `edit_css` — core strips it from the content of users
	 * without that capability.
	 *
	 * @return bool
	 */
	public static function supports_block_css() {
		return function_exists( 'wp_render_custom_css_support_styles' ) && current_user_can( 'edit_css' );
	}

	/**
	 * Custom HTML snippets and map embeds: saving a <style> or an <iframe>
	 * in post content requires unfiltered_html — without it WordPress strips
	 * them, so the AI isn't offered them.
	 *
	 * @return bool
	 */
	public static function supports_html() {
		return current_user_can( 'unfiltered_html' );
	}

	/**
	 * The icon every AI-placed icon block shows: one neutral placeholder the
	 * user swaps for real icons in the editor — the AI decides where icons
	 * go, never which glyph. '' when the site has no core Icon block
	 * (WordPress < 7.0) or the filtered icon isn't registered.
	 *
	 * @return string Icon name, e.g. 'core/shadow'.
	 */
	public static function icon_placeholder() {
		if ( ! class_exists( 'WP_Icons_Registry' ) || ! \WP_Block_Type_Registry::get_instance()->is_registered( 'core/icon' ) ) {
			return '';
		}

		$icon = (string) apply_filters( 'inspiro_starter_sites/ai_icon_placeholder', 'core/shadow' );

		return \WP_Icons_Registry::get_instance()->is_registered( $icon ) ? $icon : '';
	}

	/**
	 * Header layouts the active theme offers (AI slugs).
	 *
	 * @return string[]
	 */
	public static function header_layouts() {
		$kind = self::theme_kind();
		if ( '' === $kind ) {
			return array();
		}

		$slugs = array_keys( self::HEADER_LAYOUTS );

		return 'premium' === $kind ? $slugs : array_slice( $slugs, 0, 3 );
	}

	/**
	 * The page template for a page header choice. Falls back to the solid
	 * template when the theme has no transparent-header template.
	 *
	 * @param string $header 'transparent' or 'solid'.
	 * @return string
	 */
	public static function page_template( $header ) {
		return 'transparent' === $header && self::has_template( self::TEMPLATE_TRANSPARENT )
			? self::TEMPLATE_TRANSPARENT
			: self::TEMPLATE_SOLID;
	}

	/**
	 * Whether the active theme ships a page template.
	 *
	 * @param string $template Template path, e.g. page-templates/x.php.
	 * @return bool
	 */
	private static function has_template( $template ) {
		return array_key_exists( $template, wp_get_theme()->get_page_templates( null, 'page' ) );
	}

	/**
	 * Validate the plan's "theme" field. Invalid or unreadable choices are
	 * dropped — left to the theme's current settings — never guessed.
	 *
	 * @param mixed $raw         Plan "theme" field.
	 * @param bool  $transparent Whether any page uses the transparent header.
	 * @return array
	 */
	public static function sanitize( $raw, $transparent ) {
		$raw   = is_array( $raw ) ? $raw : array();
		$clean = array();

		$layout = isset( $raw['header_layout'] ) ? sanitize_key( (string) $raw['header_layout'] ) : '';
		if ( in_array( $layout, self::header_layouts(), true ) ) {
			$clean['header_layout'] = $layout;
		}

		$width = isset( $raw['header_width'] ) ? sanitize_key( (string) $raw['header_width'] ) : '';
		if ( in_array( $width, array( 'boxed', 'full' ), true ) ) {
			$clean['header_width'] = $width;
		}

		foreach ( array( 'header', 'footer' ) as $area ) {
			$bg   = isset( $raw[ $area . '_bg' ] ) ? sanitize_hex_color( trim( (string) $raw[ $area . '_bg' ] ) ) : '';
			$text = isset( $raw[ $area . '_text' ] ) ? sanitize_hex_color( trim( (string) $raw[ $area . '_text' ] ) ) : '';

			// Colors only travel as a readable pair.
			if ( $bg && $text && self::contrast( $bg, $text ) >= 3 ) {
				$clean[ $area . '_bg' ]   = $bg;
				$clean[ $area . '_text' ] = $text;
			}
		}

		// The header text color also paints the navigation that floats over
		// transparent-header heroes, so it has to be light there — and a
		// light bar would then lose its contrast, so the bar goes dark.
		if ( $transparent && isset( $clean['header_text'] ) && ! self::is_light( $clean['header_text'] ) ) {
			$clean['header_text'] = '#ffffff';
			if ( self::contrast( $clean['header_bg'], '#ffffff' ) < 3 ) {
				$clean['header_bg'] = '#111111';
			}
		}

		return $clean;
	}

	/**
	 * Every theme mod apply() may change on the active theme — snapshotted
	 * before the first AI demo and restored when the demo is deleted.
	 *
	 * @return string[]
	 */
	public static function tracked_mods() {
		$kind = self::theme_kind();
		if ( '' === $kind ) {
			return array();
		}

		$mods = array( 'header-menu-style', 'header-layout-type', 'color-menu-background-scroll' );
		foreach ( self::COLOR_MODS[ $kind ] as $names ) {
			$mods = array_merge( $mods, $names );
		}
		if ( 'lite' === $kind ) {
			$mods[] = 'hero_enable';
		}

		return $mods;
	}

	/**
	 * Apply the sanitized choices as theme mods.
	 *
	 * @param array $theme Output of sanitize().
	 */
	public static function apply( array $theme ) {
		$kind = self::theme_kind();
		if ( '' === $kind ) {
			return;
		}

		// Lite renders its own hero image above the front page whenever it
		// is enabled — on top of the AI homepage's own hero.
		if ( 'lite' === $kind ) {
			set_theme_mod( 'hero_enable', false );
		}

		if ( isset( $theme['header_layout'] ) ) {
			set_theme_mod( 'header-menu-style', self::HEADER_LAYOUTS[ $theme['header_layout'] ] );
		}
		if ( isset( $theme['header_width'] ) ) {
			set_theme_mod( 'header-layout-type', 'full' === $theme['header_width'] ? 'wpz_layout_full' : 'wpz_layout_narrow' );
		}

		foreach ( self::COLOR_MODS[ $kind ] as $key => $names ) {
			if ( ! isset( $theme[ $key ] ) ) {
				continue;
			}
			foreach ( $names as $name ) {
				set_theme_mod( $name, $theme[ $key ] );
			}
		}

		// The sticky header keeps the bar color once the page scrolls.
		if ( isset( $theme['header_bg'] ) ) {
			list( $r, $g, $b ) = self::rgb( $theme['header_bg'] );
			set_theme_mod( 'color-menu-background-scroll', sprintf( 'rgba(%d,%d,%d,0.95)', $r, $g, $b ) );
		}
	}

	/**
	 * Whether a color reads as light (dark text belongs on it).
	 *
	 * @param string $hex Hex color.
	 * @return bool
	 */
	public static function is_light( $hex ) {
		return self::luminance( $hex ) > 0.4;
	}

	/**
	 * WCAG contrast ratio between two hex colors (1-21).
	 *
	 * @param string $a Hex color.
	 * @param string $b Hex color.
	 * @return float
	 */
	private static function contrast( $a, $b ) {
		$l1 = self::luminance( $a );
		$l2 = self::luminance( $b );

		return ( max( $l1, $l2 ) + 0.05 ) / ( min( $l1, $l2 ) + 0.05 );
	}

	/**
	 * WCAG relative luminance of a hex color (0-1).
	 *
	 * @param string $hex Hex color.
	 * @return float
	 */
	private static function luminance( $hex ) {
		$channels = array();
		foreach ( self::rgb( $hex ) as $value ) {
			$c          = $value / 255;
			$channels[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * RGB channels of a #rgb / #rrggbb color.
	 *
	 * @param string $hex Hex color.
	 * @return int[]
	 */
	private static function rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		return array_map( 'hexdec', str_split( str_pad( substr( $hex, 0, 6 ), 6, '0' ), 2 ) );
	}
}
