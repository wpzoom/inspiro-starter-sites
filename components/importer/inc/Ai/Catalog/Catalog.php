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
	 * CSS every composed demo carries: Lite-sourced sections read the theme
	 * palette's primary/secondary slugs with Lite's meaning (near-black /
	 * accent), which Premium swaps — pin them per section to the palette.
	 *
	 * @param array $palette Role => hex.
	 * @return string
	 */
	public static function css( array $palette ) {
		return '.iss-ai-cs--lite{--wp--preset--color--primary:' . $palette['dark'] . ';--wp--preset--color--secondary:' . $palette['accent'] . ';--wp--preset--color--header-footer:' . $palette['dark'] . '}'
			// Themes color headings directly, so a heading inside a section that
			// sets a (light) text color would stay dark on a dark ground. Headings
			// and paragraphs without their own color follow the section's.
			. '.iss-ai-cs:where(.has-text-color) :where(h1,h2,h3,h4,h5,h6,p),.iss-ai-cs :where(.has-text-color) :where(h1,h2,h3,h4,h5,h6,p){color:inherit}'
			. '.iss-ai-cs .glass-button{-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px)}'
			// A call-to-action link at the end of the menu ("Book a class").
			. '.iss-ai-menu-cta>a{padding:.55em 1.2em!important;border-radius:999px;background:' . $palette['accent'] . ';color:#fff!important}'
			. '.iss-ai-menu-cta>a:hover{background:' . $palette['accentdark'] . '}';
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
