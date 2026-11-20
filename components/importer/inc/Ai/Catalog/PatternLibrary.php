<?php
/**
 * The pattern library: ready-made block patterns with their own sample
 * content, browsed in the page builder and inserted as they are. No fields
 * and no AI — unlike catalog sections (see Catalog and SectionRenderer),
 * a pattern is static block markup; only its photos, its remote images,
 * the form it embeds and blocks this site lacks are swapped on insert.
 *
 * Patterns live on the provider (catalog/patterns/) and are cached here.
 *
 * @package Inspiro Starter Sites
 */

namespace Inspiro\Starter_Sites\Ai\Catalog;

use Inspiro\Starter_Sites\Ai\AiProxyClient;

defined( 'ABSPATH' ) || exit;

class PatternLibrary {

	const META_TRANSIENT = 'inspiro_starter_sites_ai_patterns_meta';
	const CACHE_OPTION   = 'inspiro_starter_sites_ai_patterns';

	/** A photo slot in pattern markup: orientation and slot number. */
	const PHOTO_TOKEN = '#https://pattern-photo\.invalid/(landscape|portrait|square)/(\d+)\.jpg#';

	/** @var array|null { version, patterns, licensed } */
	private static $meta = null;

	/**
	 * Metadata of every pattern: title, categories, tone, tier, needs,
	 * photos, thumb. Premium ones are included for every site.
	 *
	 * @return array { version, patterns: id => meta, licensed } (patterns is [] when unavailable)
	 */
	public static function meta() {
		if ( null !== self::$meta ) {
			return self::$meta;
		}

		$has_key = '' !== AiProxyClient::premium_license();
		$cached  = get_transient( self::META_TRANSIENT );
		if ( is_array( $cached ) && ! empty( $cached['patterns'] ) && isset( $cached['has_key'] ) && $cached['has_key'] === $has_key ) {
			self::$meta = $cached;
			return self::$meta;
		}

		$response = self::request( array() );
		if ( is_wp_error( $response ) || empty( $response['patterns'] ) ) {
			self::$meta = array(
				'version'  => '',
				'patterns' => array(),
				'licensed' => false,
			);
			return self::$meta;
		}

		$response['has_key'] = $has_key;
		// A key the server didn't accept (expired, or just renewed) is
		// re-checked soon, so the patterns unlock shortly after a renewal.
		set_transient( self::META_TRANSIENT, $response, $has_key && ! $response['licensed'] ? HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS );
		self::$meta = $response;

		return self::$meta;
	}

	/**
	 * Full patterns (markup, assets) for inserting, from the local cache
	 * or one request for the missing ones.
	 *
	 * @param string[] $ids Pattern ids.
	 * @return array id => pattern (unknown and locked ids are left out)
	 */
	public static function get( array $ids ) {
		$meta    = self::meta();
		$version = $meta['version'];

		$cache = get_option( self::CACHE_OPTION, array() );
		if ( ! is_array( $cache ) || ! isset( $cache['version'] ) || $cache['version'] !== $version ) {
			$cache = array(
				'version'  => $version,
				'patterns' => array(),
			);
		}

		$ids     = array_values( array_unique( array_map( 'sanitize_key', $ids ) ) );
		$missing = array_values( array_diff( $ids, array_keys( $cache['patterns'] ) ) );
		if ( $missing ) {
			$response = self::request( $missing );
			if ( ! is_wp_error( $response ) ) {
				foreach ( $response['patterns'] as $id => $pattern ) {
					if ( is_array( $pattern ) && ! empty( $pattern['markup'] ) ) {
						$cache['patterns'][ sanitize_key( (string) $id ) ] = $pattern;
					}
				}
				update_option( self::CACHE_OPTION, $cache, false );
			}
		}

		return array_intersect_key( $cache['patterns'], array_flip( $ids ) );
	}

	/**
	 * Library request. DEVELOPMENT: with INSPIRO_STARTER_SITES_AI_LOCAL_PROMPTS
	 * pointing at a local provider checkout, its patterns are read directly.
	 */
	private static function request( array $ids ) {
		if ( defined( 'INSPIRO_STARTER_SITES_AI_LOCAL_PROMPTS' ) && INSPIRO_STARTER_SITES_AI_LOCAL_PROMPTS ) {
			$file = trailingslashit( (string) INSPIRO_STARTER_SITES_AI_LOCAL_PROMPTS ) . 'wpzoom-ai-catalog.php';
			// Another plugin may define a class of the same name (WPZOOM AI
			// Connect does): then the provider's file cannot be loaded here.
			if ( is_readable( $file ) && ! class_exists( 'WPZOOM_AI_Catalog', false ) ) {
				require_once $file;
			}
			if ( is_callable( array( 'WPZOOM_AI_Catalog', 'patterns_response' ) ) ) {
				$licensed = '' !== AiProxyClient::premium_license();
				return \WPZOOM_AI_Catalog::patterns_response( $licensed, $ids ) + array( 'licensed' => $licensed );
			}
		}

		$proxy = new AiProxyClient();
		return $proxy->patterns( $ids );
	}

	/**
	 * A pattern as block markup ready for this site.
	 *
	 * @param array $pattern Full pattern (see get()).
	 * @param array $args {
	 *     @type callable $resolve_image ( string $query, string $orientation ) : array{id:int,url:string}|null
	 *     @type callable $resolve_asset ( string $url ) : array{id:int,url:string}|null — local copy of a remote image
	 *     @type int      $form_id       WPZOOM Forms form for patterns that embed one.
	 *     @type bool     $icon_block    Whether outermost/icon-block is registered here.
	 * }
	 * @return string
	 */
	public static function render( array $pattern, array $args ) {
		$markup = (string) $pattern['markup'];

		// Photos: one of the site's placeholder photos per slot.
		$resolve = isset( $args['resolve_image'] ) && is_callable( $args['resolve_image'] ) ? $args['resolve_image'] : null;
		$photos  = array();
		$markup  = preg_replace_callback(
			self::PHOTO_TOKEN,
			static function ( $m ) use ( &$photos, $resolve, $pattern ) {
				if ( ! isset( $photos[ $m[0] ] ) ) {
					$image           = $resolve ? call_user_func( $resolve, (string) $pattern['title'] . ' ' . $m[2], $m[1] ) : null;
					$photos[ $m[0] ] = is_array( $image ) && ! empty( $image['url'] ) ? esc_url_raw( $image['url'] ) : '';
				}
				return $photos[ $m[0] ];
			},
			$markup
		);

		// Remote images the pattern ships with (logos, a map): a local copy,
		// so the page doesn't hotlink the demo server.
		$resolve_asset = isset( $args['resolve_asset'] ) && is_callable( $args['resolve_asset'] ) ? $args['resolve_asset'] : null;
		foreach ( $resolve_asset && ! empty( $pattern['assets'] ) ? (array) $pattern['assets'] : array() as $url ) {
			$local = call_user_func( $resolve_asset, (string) $url );
			if ( is_array( $local ) && ! empty( $local['url'] ) ) {
				$markup = str_replace( array( $url, str_replace( '/', '\/', $url ) ), esc_url_raw( $local['url'] ), $markup );
			}
		}

		// The form block points at one of this site's forms.
		$form_id = isset( $args['form_id'] ) ? (int) $args['form_id'] : 0;
		if ( $form_id ) {
			$markup = preg_replace( '#<!-- wp:wpzoom-forms/form-block\s+/-->#', '<!-- wp:wpzoom-forms/form-block {"formId":"' . $form_id . '"} /-->', $markup );
		}

		// Without the Icon Block plugin its blocks would show as unsupported
		// in the editor: their saved SVG is kept as Custom HTML instead.
		if ( empty( $args['icon_block'] ) ) {
			$markup = preg_replace(
				'#<!-- wp:outermost/icon-block(?:\s+\{.*?\})?\s*-->\s*(.*?)\s*<!-- /wp:outermost/icon-block -->#s',
				"<!-- wp:html -->\n$1\n<!-- /wp:html -->",
				$markup
			);
		}

		return (string) $markup;
	}
}
