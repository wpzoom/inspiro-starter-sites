<?php
/**
 * Renders one catalog section: the demo's own block markup with the
 * planner's copy, sideloaded photos, the site palette and the display
 * heading weight swapped in for the extractor's sentinels
 * (see bin/catalog-extract.php for the sentinel format).
 *
 * Output is native block markup that stays valid in the editor: only text,
 * attribute values that mirror each other in the block comment and the
 * saved HTML, and whole repeatable items are ever changed.
 *
 * @package Inspiro Starter Sites
 */

namespace Inspiro\Starter_Sites\Ai\Catalog;

defined( 'ABSPATH' ) || exit;

class SectionRenderer {

	const ID_BASE = 900000000;

	/** Blocks that disappear when their text is empty. */
	const TEXT_BLOCKS = array( 'core/heading', 'core/paragraph', 'core/button', 'core/list-item', 'core/accordion-heading' );

	/** Containers that disappear when every child was pruned. */
	const EMPTY_DROP = array( 'core/columns', 'core/buttons', 'core/list', 'core/gallery', 'core/social-links', 'core/accordion' );

	/** @var array role => hex */
	private $palette;

	/** @var string display heading weight, '' keeps the demo's */
	private $weight;

	/** @var array page slug => URL */
	private $links;

	/** @var callable ( string $query, string $orientation ) : array{id:int,url:string}|null */
	private $resolve_image;

	/** @var callable|null ( string $url ) : array{id:int,url:string}|null — local copy of a demo asset */
	private $resolve_asset;

	/** @var string query used when the planner gave none */
	private $fallback_query;

	/** @var int WPZOOM Forms form ID, 0 drops the form block */
	private $form_id;

	/** @var array per-render state */
	private $flat   = array();
	private $counts = array();

	/**
	 * @param array $args {
	 *     @type array    $palette        Role => hex, see Catalog::palette().
	 *     @type string   $weight         Display heading weight (300-800) or ''.
	 *     @type array    $links          Page slug => URL.
	 *     @type callable $resolve_image  Photo resolver.
	 *     @type string   $fallback_query Photo query when a slot has none.
	 *     @type int      $form_id        Form for contact sections.
	 * }
	 */
	public function __construct( array $args ) {
		$this->palette        = isset( $args['palette'] ) ? $args['palette'] : Catalog::palette( '#0bb4aa' );
		$this->weight         = isset( $args['weight'] ) && preg_match( '/^[1-9]00$/', (string) $args['weight'] ) ? (string) $args['weight'] : '';
		$this->links          = isset( $args['links'] ) ? $args['links'] : array();
		$this->resolve_image  = isset( $args['resolve_image'] ) ? $args['resolve_image'] : null;
		$this->resolve_asset  = isset( $args['resolve_asset'] ) ? $args['resolve_asset'] : null;
		$this->fallback_query = isset( $args['fallback_query'] ) ? (string) $args['fallback_query'] : 'modern interior';
		$this->form_id        = isset( $args['form_id'] ) ? (int) $args['form_id'] : 0;
	}

	/**
	 * @param array $section Catalog entry (fields, markup, ids, source_theme).
	 * @param array $content Planner content keyed by field name.
	 * @return string Block markup ('' when nothing renders).
	 */
	public function render( array $section, array $content ) {
		$this->flat   = array();
		$this->counts = array();
		$this->flatten( $section['fields'], $content, '' );

		$blocks = array();
		foreach ( parse_blocks( $section['markup'] ) as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$block = $this->prune( $block );
			if ( null !== $block ) {
				$blocks[] = $block;
			}
		}
		if ( ! $blocks ) {
			return '';
		}

		$this->wrap_class( $blocks[0], isset( $section['source_theme'] ) ? $section['source_theme'] : 'lite' );

		$markup = serialize_blocks( $blocks );
		$images = $this->resolve_images( $section, $markup );
		$assets = $this->resolve_assets( $section, $markup );

		return $this->substitute( $markup, $section, $images, $assets );
	}

	/* ------------------------------------------------------------------
	 * Content
	 * ---------------------------------------------------------------- */

	/** Planner content → path-keyed scalars ("t:items.2.title") + collection counts. */
	private function flatten( array $fields, $content, $prefix ) {
		$content = is_array( $content ) ? $content : array();

		foreach ( $fields as $name => $field ) {
			$path  = $prefix . $name;
			$value = isset( $content[ $name ] ) ? $content[ $name ] : null;

			switch ( isset( $field['type'] ) ? $field['type'] : '' ) {
				case 'text':
					$this->flat[ 't:' . $path ] = is_scalar( $value ) ? $this->clean( $value ) : '';
					break;

				case 'button':
					$label = is_array( $value ) ? ( isset( $value['label'] ) ? $value['label'] : ( isset( $value['text'] ) ? $value['text'] : '' ) ) : $value;
					$href  = is_array( $value ) && isset( $value['href'] ) ? $value['href'] : '';
					$this->flat[ 't:' . $path ] = is_scalar( $label ) ? $this->clean( $label ) : '';
					$this->flat[ 'h:' . $path ] = is_scalar( $href ) ? trim( (string) $href ) : '';
					break;

				case 'image':
					$this->image_value( $path, $value, isset( $field['orientation'] ) ? $field['orientation'] : 'landscape' );
					break;

				case 'items':
					$list = is_array( $value ) ? array_values( array_filter( $value, 'is_array' ) ) : array();
					$list = array_slice( $list, 0, isset( $field['max'] ) ? (int) $field['max'] : count( $list ) );
					$this->counts[ $path ] = count( $list );
					foreach ( $list as $k => $item ) {
						$this->flatten( isset( $field['fields'] ) ? $field['fields'] : array(), $item, $path . '.' . $k . '.' );
					}
					break;

				case 'list':
					$list = is_array( $value ) ? array_values( array_filter( array_map( array( $this, 'clean' ), array_filter( $value, 'is_scalar' ) ), 'strlen' ) ) : array();
					$list = array_slice( $list, 0, isset( $field['max'] ) ? (int) $field['max'] : count( $list ) );
					$this->counts[ $path ] = count( $list );
					foreach ( $list as $k => $text ) {
						$this->flat[ 't:' . $path . '.' . $k ] = $text;
					}
					break;

				case 'gallery':
					$list = is_array( $value ) ? array_values( $value ) : array();
					$list = array_slice( $list, 0, isset( $field['max'] ) ? (int) $field['max'] : count( $list ) );
					if ( ! $list && isset( $field['min'] ) ) {
						$list = array_fill( 0, (int) $field['min'], '' );
					}
					$this->counts[ $path ] = count( $list );
					foreach ( $list as $k => $item ) {
						$this->image_value( $path . '.' . $k, $item, isset( $field['orientation'] ) ? $field['orientation'] : 'landscape' );
					}
					break;
			}
		}
	}

	private function image_value( $path, $value, $orientation ) {
		$query = is_array( $value ) ? ( isset( $value['query'] ) ? $value['query'] : '' ) : $value;
		$query = is_scalar( $query ) ? $this->clean( $query ) : '';
		$alt   = is_array( $value ) && isset( $value['alt'] ) && is_scalar( $value['alt'] ) ? $this->clean( $value['alt'] ) : $query;

		$this->flat[ 'q:' . $path ] = $query;
		$this->flat[ 'o:' . $path ] = in_array( $orientation, array( 'landscape', 'portrait', 'square' ), true ) ? $orientation : 'landscape';
		$this->flat[ 'a:' . $path ] = $alt;
	}

	/** Plain copy: no markup, no sentinel look-alikes; "\n" survives as a deliberate line break. */
	public function clean( $text ) {
		$text = wp_strip_all_tags( str_replace( array( '\\n', '\n' ), "\n", (string) $text ) );
		$text = str_replace( '%%', '%', $text );
		$text = preg_replace( '/[ \t\r\x{00A0}]+/u', ' ', $text );
		$text = preg_replace( '/ *\n+ */u', "\n", (string) $text );
		return trim( (string) $text );
	}

	/* ------------------------------------------------------------------
	 * Structure
	 * ---------------------------------------------------------------- */

	/**
	 * Drop items beyond the planner's count and text blocks it left empty;
	 * containers emptied by that go too. Returns null to drop the block.
	 */
	private function prune( array $block ) {
		$name = (string) $block['blockName'];

		if ( isset( $block['attrs']['metadata']['issItem'] ) ) {
			$path = (string) $block['attrs']['metadata']['issItem'];
			$dot  = strrpos( $path, '.' );
			$coll = substr( $path, 0, $dot );
			if ( (int) substr( $path, $dot + 1 ) >= ( isset( $this->counts[ $coll ] ) ? $this->counts[ $coll ] : 0 ) ) {
				return null;
			}
			unset( $block['attrs']['metadata']['issItem'] );
			if ( empty( $block['attrs']['metadata'] ) ) {
				unset( $block['attrs']['metadata'] );
			}
		}

		if ( in_array( $name, self::TEXT_BLOCKS, true ) && preg_match( '/%%iss:t:([^%]+)%%/', (string) $block['innerHTML'], $m ) ) {
			if ( '' === ( isset( $this->flat[ 't:' . $m[1] ] ) ? $this->flat[ 't:' . $m[1] ] : '' ) ) {
				return null;
			}
		}

		if ( 'wpzoom-forms/form-block' === $name && ! $this->form_id ) {
			return null;
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			$kept    = array();
			$content = array();
			$i       = 0;
			foreach ( $block['innerContent'] as $chunk ) {
				if ( is_string( $chunk ) ) {
					$content[] = $chunk;
					continue;
				}
				$child = isset( $block['innerBlocks'][ $i ] ) ? $this->prune( $block['innerBlocks'][ $i ] ) : null;
				$i++;
				if ( null !== $child ) {
					$kept[]    = $child;
					$content[] = null;
				}
			}
			$block['innerBlocks']  = $kept;
			$block['innerContent'] = $content;
			if ( ! $kept && in_array( $name, self::EMPTY_DROP, true ) ) {
				return null;
			}
		}

		return $block;
	}

	/** Mark the section root so the palette's preset overrides apply inside it. */
	private function wrap_class( array &$block, $source_theme ) {
		$classes = 'iss-ai-cs iss-ai-cs--' . sanitize_html_class( $source_theme );

		$block['attrs']['className'] = trim( ( isset( $block['attrs']['className'] ) ? $block['attrs']['className'] . ' ' : '' ) . $classes );

		if ( isset( $block['innerContent'][0] ) && is_string( $block['innerContent'][0] ) ) {
			$block['innerContent'][0] = preg_replace( '/^(\s*<[a-z0-9]+\b[^>]*?\bclass=")([^"]*)"/', '$1$2 ' . $classes . '"', $block['innerContent'][0], 1 );
		}
	}

	/* ------------------------------------------------------------------
	 * Photos and sentinels
	 * ---------------------------------------------------------------- */

	/** Resolve every photo slot still present after pruning. */
	private function resolve_images( array $section, $markup ) {
		$images = array();
		if ( ! is_callable( $this->resolve_image ) || empty( $section['ids'] ) ) {
			return $images;
		}
		foreach ( $section['ids'] as $path ) {
			if ( false === strpos( $markup, '%%iss:u:' . $path . '%%' ) ) {
				continue;
			}
			$query = isset( $this->flat[ 'q:' . $path ] ) && '' !== $this->flat[ 'q:' . $path ] ? $this->flat[ 'q:' . $path ] : $this->fallback_query;
			$image = call_user_func( $this->resolve_image, $query, isset( $this->flat[ 'o:' . $path ] ) ? $this->flat[ 'o:' . $path ] : 'landscape' );
			if ( is_array( $image ) && ! empty( $image['url'] ) ) {
				$images[ $path ] = array(
					'id'  => isset( $image['id'] ) ? (int) $image['id'] : 0,
					'url' => (string) $image['url'],
				);
			}
		}
		return $images;
	}

	/** Local copies of the demo's static assets (icons, logos) still present. */
	private function resolve_assets( array $section, $markup ) {
		$assets = array();
		foreach ( isset( $section['assets'] ) ? (array) $section['assets'] : array() as $k => $url ) {
			if ( false === strpos( $markup, '%%iss:s:' . $k . '%%' ) ) {
				continue;
			}
			$local = is_callable( $this->resolve_asset ) ? call_user_func( $this->resolve_asset, $url ) : null;
			// Without a local copy the demo file itself is used.
			$assets[ $k ] = is_array( $local ) && ! empty( $local['url'] ) ? $local : array(
				'id'  => 0,
				'url' => $url,
			);
		}
		return $assets;
	}

	private function substitute( $markup, array $section, array $images, array $assets = array() ) {
		$markup = preg_replace_callback(
			'/%%iss:([a-z]):([^%]*)%%/',
			function ( $m ) use ( $images, $assets ) {
				$key = $m[2];
				switch ( $m[1] ) {
					case 't':
						return str_replace( "\n", '<br>', esc_html( isset( $this->flat[ 't:' . $key ] ) ? $this->flat[ 't:' . $key ] : '' ) );
					case 'h':
						return esc_url( $this->href( isset( $this->flat[ 'h:' . $key ] ) ? $this->flat[ 'h:' . $key ] : '' ) );
					case 'u':
						return isset( $images[ $key ] ) ? esc_url( $images[ $key ]['url'] ) : '';
					case 's':
						return isset( $assets[ $key ] ) ? esc_url( $assets[ $key ]['url'] ) : '';
					case 'a':
						return esc_attr( isset( $this->flat[ 'a:' . $key ] ) ? $this->flat[ 'a:' . $key ] : '' );
					case 'j':
						return $this->json_fragment( isset( $this->flat[ 'a:' . $key ] ) ? $this->flat[ 'a:' . $key ] : '' );
					case 'c':
						return $this->color( $key );
					case 'w':
						return '' !== $this->weight ? $this->weight : $key;
				}
				return '';
			},
			$markup
		);

		// Numeric sentinels: photo IDs (9000000NN), asset IDs (9000005NN), the contact form.
		$ids    = isset( $section['ids'] ) ? $section['ids'] : array();
		$markup = preg_replace_callback(
			'/\b(900000\d{3}|899999999)\b/',
			function ( $m ) use ( $ids, $images, $assets ) {
				$n = (int) $m[1];
				if ( self::ID_BASE - 1 === $n ) {
					return (string) $this->form_id;
				}
				if ( $n >= self::ID_BASE + 500 ) {
					$k = $n - self::ID_BASE - 500;
					return isset( $assets[ $k ] ) ? (string) (int) $assets[ $k ]['id'] : '0';
				}
				if ( isset( $ids[ $m[1] ] ) && isset( $images[ $ids[ $m[1] ] ] ) ) {
					return (string) $images[ $ids[ $m[1] ] ]['id'];
				}
				return $m[0];
			},
			$markup
		);

		return $this->drop_zero_ids( $markup );
	}

	/**
	 * A file without a media-library copy has no attachment ID. Core's save()
	 * emits no wp-image-N class for ID 0, so both traces must go or the
	 * editor reports the block as invalid.
	 */
	private function drop_zero_ids( $markup ) {
		$markup = preg_replace( '/"id":0,|,"id":0(?=})/', '', $markup );
		$markup = preg_replace( '/\sclass="wp-image-0"/', '', $markup );
		return preg_replace( '/(class="[^"]*?)\s?\bwp-image-0\b/', '$1', $markup );
	}

	private function href( $href ) {
		if ( preg_match( '/^#page:([a-z0-9-]+)$/', $href, $m ) ) {
			return isset( $this->links[ $m[1] ] ) ? $this->links[ $m[1] ] : home_url( '/' );
		}
		if ( preg_match( '#^(https?:)?//#', $href ) || 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) ) {
			return $href;
		}
		// No target given: a call to action points at the contact page.
		return isset( $this->links['contact'] ) ? $this->links['contact'] : '#';
	}

	private function color( $spec ) {
		$parts = explode( ':', $spec );
		$hex   = isset( $this->palette[ $parts[0] ] ) ? $this->palette[ $parts[0] ] : '#111111';
		return $hex . ( isset( $parts[1] ) && preg_match( '/^[0-9a-f]{2}$/', $parts[1] ) ? $parts[1] : '' );
	}

	/** A string as it appears inside serialized block attributes (escaped exactly like core does). */
	private function json_fragment( $text ) {
		$json = serialize_block_attributes( array( 'v' => (string) $text ) );
		return substr( $json, 6, -2 );
	}
}
