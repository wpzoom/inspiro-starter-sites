<?php
/**
 * Client for the section catalog of the catalog engine.
 *
 * The catalog lives on the AI server (wpzoom-api-key-provider,
 * WPZOOM_AI_Catalog): sections extracted from Inspiro's hand-built demos
 * with bin/catalog-extract.php. This site fetches the metadata of every
 * section (planning checks and the modal's preview) and the full markup
 * only of the sections a generation picked, and caches both by catalog
 * version.
 *
 * @package Inspiro Starter Sites
 */

namespace Inspiro\Starter_Sites\Ai\Catalog;

use Inspiro\Starter_Sites\Ai\AiProxyClient;

defined( 'ABSPATH' ) || exit;

class Catalog {

	const META_TRANSIENT = 'inspiro_starter_sites_ai_catalog_meta';
	const DOWN_TRANSIENT = 'inspiro_starter_sites_ai_catalog_down';
	const CACHE_OPTION   = 'inspiro_starter_sites_ai_catalog_sections';

	/** Blocks some sections need that only recent WordPress versions register. */
	const VERSIONED_BLOCKS = array( 'core/accordion', 'core/icon' );

	/** Button shapes the blueprint picks from => corner radius. */
	const BUTTON_SHAPES = array(
		'square'  => '0px',
		'soft'    => '4px',
		'rounded' => '10px',
		'pill'    => '999px',
	);

	/** @var array|null [ 'version' => ..., 'sections' => id => meta ] */
	private static $meta = null;

	/**
	 * Versioned blocks this WordPress registers (sections needing others
	 * are never offered).
	 *
	 * @return string[]
	 */
	public static function blocks() {
		$registry = \WP_Block_Type_Registry::get_instance();
		return array_values(
			array_filter(
				self::VERSIONED_BLOCKS,
				static function ( $block ) use ( $registry ) {
					return $registry->is_registered( $block );
				}
			)
		);
	}

	/**
	 * Cheap check for admin screens: false only right after the catalog
	 * failed to load (for ten minutes).
	 *
	 * @return bool
	 */
	public static function available() {
		return false === get_transient( self::DOWN_TRANSIENT );
	}

	/**
	 * Metadata of every section this site may use: role, fits, tone, tier,
	 * description, photos, items, needs.
	 *
	 * @return array id => meta ([] when unavailable)
	 */
	public static function meta() {
		if ( null !== self::$meta ) {
			return self::$meta['sections'];
		}

		$blocks = self::blocks();
		$cached = get_transient( self::META_TRANSIENT );
		if ( is_array( $cached ) && ! empty( $cached['sections'] ) && isset( $cached['blocks'] ) && $cached['blocks'] === $blocks
			&& ( ! isset( $cached['licensed'] ) || $cached['licensed'] === ( '' !== AiProxyClient::premium_license() ) ) ) {
			self::$meta = $cached;
			return self::$meta['sections'];
		}

		$response = self::request( array(), $blocks );
		if ( is_wp_error( $response ) || empty( $response['sections'] ) ) {
			set_transient( self::DOWN_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS );
			self::$meta = array(
				'version'  => '',
				'sections' => array(),
			);
			return array();
		}

		delete_transient( self::DOWN_TRANSIENT );
		$response['blocks']   = $blocks;
		$response['licensed'] = '' !== AiProxyClient::premium_license();
		set_transient( self::META_TRANSIENT, $response, 12 * HOUR_IN_SECONDS );
		self::$meta = $response;

		return self::$meta['sections'];
	}

	/**
	 * One section's metadata.
	 *
	 * @param string $id Section id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$meta = self::meta();
		return isset( $meta[ $id ] ) ? $meta[ $id ] : null;
	}

	/**
	 * Full sections (fields, markup, photo and asset maps) for rendering,
	 * from the local cache or one request for the missing ones.
	 *
	 * @param string[] $ids Section ids.
	 * @return array id => section (unknown ids are left out)
	 */
	public static function sections( array $ids ) {
		self::meta();
		$version = isset( self::$meta['version'] ) ? self::$meta['version'] : '';

		$cache = get_option( self::CACHE_OPTION, array() );
		if ( ! is_array( $cache ) || ! isset( $cache['version'] ) || $cache['version'] !== $version ) {
			$cache = array(
				'version'  => $version,
				'sections' => array(),
			);
		}

		$ids     = array_values( array_unique( array_map( 'sanitize_key', $ids ) ) );
		$missing = array_values( array_diff( $ids, array_keys( $cache['sections'] ) ) );
		if ( $missing ) {
			$response = self::request( $missing, self::blocks() );
			if ( ! is_wp_error( $response ) ) {
				$fetched = array();
				foreach ( $response['sections'] as $id => $section ) {
					if ( is_array( $section ) && ! empty( $section['markup'] ) ) {
						$fetched[ sanitize_key( (string) $id ) ] = $section;
					}
				}
				$cache['sections'] = $fetched + $cache['sections'];

				// Pages build in parallel: merge with what other builds cached
				// meanwhile instead of overwriting it.
				$stored = get_option( self::CACHE_OPTION, array() );
				if ( is_array( $stored ) && isset( $stored['version'] ) && $stored['version'] === $version && ! empty( $stored['sections'] ) ) {
					$cache['sections'] += $stored['sections'];
				}
				update_option( self::CACHE_OPTION, $cache, false );
			}
		}

		return array_intersect_key( $cache['sections'], array_flip( $ids ) );
	}

	/**
	 * Catalog request. DEVELOPMENT: with INSPIRO_STARTER_SITES_AI_LOCAL_PROMPTS
	 * pointing at a local provider checkout, its catalog is read directly.
	 */
	private static function request( array $ids, array $blocks ) {
		if ( defined( 'INSPIRO_STARTER_SITES_AI_LOCAL_PROMPTS' ) && INSPIRO_STARTER_SITES_AI_LOCAL_PROMPTS ) {
			$file = trailingslashit( (string) INSPIRO_STARTER_SITES_AI_LOCAL_PROMPTS ) . 'wpzoom-ai-catalog.php';
			if ( is_readable( $file ) ) {
				require_once $file;
				return \WPZOOM_AI_Catalog::response( '' !== AiProxyClient::premium_license(), $blocks, $ids );
			}
		}

		$proxy = new AiProxyClient();
		return $proxy->catalog( $ids, $blocks );
	}

	/* ------------------------------------------------------------------
	 * Palette
	 * ---------------------------------------------------------------- */

	/**
	 * Every palette role the sections use, derived from one accent.
	 *
	 * @param string $accent  Brand color.
	 * @param string $dark    Dark ground/ink ('' = accent-tinted near-black).
	 * @param string $surface Light tinted ground ('' = accent-tinted near-white).
	 * @return array role => hex
	 */
	public static function palette( $accent, $dark = '', $surface = '' ) {
		$accent = sanitize_hex_color( $accent ) ? strtolower( $accent ) : '#0bb4aa';
		$dark   = sanitize_hex_color( $dark ) ? strtolower( $dark ) : self::mix( $accent, '#0e0e10', 0.9 );

		return array(
			'accent'      => $accent,
			'accentdark'  => self::mix( $accent, '#000000', 0.5 ),
			'accentlight' => self::mix( $accent, '#ffffff', 0.6 ),
			'surface'     => sanitize_hex_color( $surface ) ? strtolower( $surface ) : self::mix( $accent, '#ffffff', 0.93 ),
			'dark'        => $dark,
		);
	}

	/**
	 * The site's button style from the blueprint: one shape, letter case and
	 * weight for every button of the demo, whichever sections it uses.
	 *
	 * @param mixed $raw Model output ({shape, case, weight}).
	 * @return array{shape:string,case:string,weight:string}
	 */
	public static function button_style( $raw ) {
		$raw    = is_array( $raw ) ? $raw : array();
		$shape  = isset( $raw['shape'] ) ? sanitize_key( (string) $raw['shape'] ) : '';
		$case   = isset( $raw['case'] ) ? sanitize_key( (string) $raw['case'] ) : '';
		$weight = isset( $raw['weight'] ) ? (string) $raw['weight'] : '';

		return array(
			'shape'  => isset( self::BUTTON_SHAPES[ $shape ] ) ? $shape : 'soft',
			'case'   => 'uppercase' === $case ? 'uppercase' : 'normal',
			'weight' => in_array( $weight, array( '400', '500', '600', '700' ), true ) ? $weight : '500',
		);
	}

	/**
	 * CSS every composed demo carries: sections read the theme palette's
	 * primary/secondary slugs with their source theme's meaning (Lite:
	 * near-black / accent; Premium swaps them) — pin them per section to the
	 * demo palette so a section looks the same on either theme.
	 *
	 * @param array $palette Role => hex.
	 * @param array $buttons Button style, see button_style().
	 * @return string
	 */
	public static function css( array $palette, array $buttons = array() ) {
		$buttons = self::button_style( $buttons );
		$caps    = 'uppercase' === $buttons['case'];
		$btn     = '.iss-ai-cs .wp-block-button.iss-btn>.wp-block-button__link';

		return '.iss-ai-cs--lite{--wp--preset--color--primary:' . $palette['dark'] . ';--wp--preset--color--secondary:' . $palette['accent'] . ';--wp--preset--color--header-footer:' . $palette['dark'] . '}'
			. '.iss-ai-cs--premium{--wp--preset--color--primary:' . $palette['accent'] . ';--wp--preset--color--secondary:' . $palette['dark'] . ';--wp--preset--color--header-footer:' . $palette['dark'] . ';--wp--preset--color--brown:' . $palette['accent'] . ';--wp--preset--color--green:' . $palette['accent'] . '}'
			// Themes color headings directly, so a heading inside a section that
			// sets a (light) text color would stay dark on a dark ground. Headings
			// and paragraphs without their own color follow the section's.
			. '.iss-ai-cs:where(.has-text-color) :where(h1,h2,h3,h4,h5,h6,p),.iss-ai-cs :where(.has-text-color) :where(h1,h2,h3,h4,h5,h6,p){color:inherit}'
			// One button style for the whole site (SectionRenderer strips the
			// demos' own): edit these to restyle every button at once.
			. ':root{--iss-btn-radius:' . self::BUTTON_SHAPES[ $buttons['shape'] ] . ';--iss-btn-weight:' . $buttons['weight']
			. ';--iss-btn-case:' . ( $caps ? 'uppercase' : 'none' ) . ';--iss-btn-tracking:' . ( $caps ? '.08em' : '0' )
			. ';--iss-btn-size:' . ( $caps ? '.8125rem' : '1rem' ) . ';--iss-btn-fill:' . $palette['accent'] . ';--iss-btn-fill-hover:' . $palette['accentdark']
			. ';--iss-btn-ink:' . $palette['dark'] . '}'
			. $btn . '{display:inline-block;padding:.9em 1.9em;border:2px solid transparent;border-radius:var(--iss-btn-radius);background:none;box-shadow:none;'
			. 'font-size:var(--iss-btn-size);font-weight:var(--iss-btn-weight);line-height:1.2;text-transform:var(--iss-btn-case);letter-spacing:var(--iss-btn-tracking);text-decoration:none}'
			// Filled: the call to action, in the brand color on any ground.
			. '.iss-ai-cs .wp-block-button.iss-btn.is-style-fill>.wp-block-button__link{background:var(--iss-btn-fill);border-color:var(--iss-btn-fill);color:#fff}'
			. '.iss-ai-cs .wp-block-button.iss-btn.is-style-fill>.wp-block-button__link:hover{background:var(--iss-btn-fill-hover);border-color:var(--iss-btn-fill-hover);color:#fff}'
			// Outline: the secondary action, in the ground's ink.
			. '.iss-ai-cs .wp-block-button.iss-btn.is-style-outline>.wp-block-button__link{border-color:currentColor;color:var(--iss-btn-ink)}'
			. '.iss-ai-cs .wp-block-button.iss-btn.is-style-outline>.wp-block-button__link:hover{background:var(--iss-btn-ink);border-color:var(--iss-btn-ink);color:#fff!important}'
			. '.iss-ai-cs .wp-block-button.iss-btn.iss-btn-on-dark.is-style-outline>.wp-block-button__link{color:#fff}'
			. '.iss-ai-cs .wp-block-button.iss-btn.iss-btn-on-dark.is-style-outline>.wp-block-button__link:hover{background:#fff;border-color:#fff;color:var(--iss-btn-ink)!important}'
			// Text link: "Read more" under cards and lists.
			. '.iss-ai-cs .wp-block-button.iss-btn.iss-btn-link>.wp-block-button__link{padding:0 0 .2em;border:0;border-bottom:2px solid currentColor;border-radius:0;color:var(--iss-btn-fill)}'
			. '.iss-ai-cs .wp-block-button.iss-btn.iss-btn-link>.wp-block-button__link:hover{background:none;color:var(--iss-btn-fill-hover)}'
			. '.iss-ai-cs .wp-block-button.iss-btn.iss-btn-on-dark.iss-btn-link>.wp-block-button__link,.iss-ai-cs .wp-block-button.iss-btn.iss-btn-on-dark.iss-btn-link>.wp-block-button__link:hover{color:#fff}'
			// A call-to-action link at the end of the menu ("Book a class").
			. '.iss-ai-menu-cta>a{padding:.55em 1.2em!important;border-radius:var(--iss-btn-radius);background:var(--iss-btn-fill);color:#fff!important;font-weight:var(--iss-btn-weight);text-transform:var(--iss-btn-case);letter-spacing:var(--iss-btn-tracking)}'
			. '.iss-ai-menu-cta>a:hover{background:var(--iss-btn-fill-hover)}';
	}

	/**
	 * Mix two hex colors.
	 *
	 * @param string $a Color A.
	 * @param string $b Color B.
	 * @param float  $t Weight of B (0-1).
	 * @return string
	 */
	public static function mix( $a, $b, $t ) {
		$a   = ltrim( $a, '#' );
		$b   = ltrim( $b, '#' );
		$out = '#';
		for ( $i = 0; $i < 3; $i++ ) {
			$ca   = hexdec( substr( $a, $i * 2, 2 ) );
			$cb   = hexdec( substr( $b, $i * 2, 2 ) );
			$out .= str_pad( dechex( (int) round( $ca + ( $cb - $ca ) * $t ) ), 2, '0', STR_PAD_LEFT );
		}
		return $out;
	}
}
