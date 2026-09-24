<?php
/**
 * A small CSS cascade resolver for the AI demo stylesheet.
 *
 * The AI writes each page as HTML against a stylesheet it wrote earlier.
 * Some of what that stylesheet decides has to become native block settings
 * to work well in WordPress, and some of it is wrong in ways the model does
 * not notice. This resolves, for an element of the AI's HTML, the values a
 * browser would compute from the stylesheet, so the converter can:
 *   - lift CSS grids into native grid groups (responsive and editable),
 *   - repair text whose colour fails contrast against its real background,
 *   - align buttons and icons with the text alignment around them.
 *
 * The scope is deliberately narrow: top-level rules only (media queries hold
 * the mobile variations of the same design), the selector grammar the dialect
 * produces (type, class, id, attribute, structural and logical
 * pseudo-classes, all four combinators), and the converter's own output
 * modelled on top of the AI DOM — cover sections, column wrappers, native
 * colours, the page wrapper. Whatever it cannot resolve comes back null, and
 * callers then leave the design alone.
 *
 * @package Inspiro Starter Sites
 */

namespace Inspiro\Starter_Sites\Ai;

defined( 'ABSPATH' ) || exit;

class CssCascade {

	/**
	 * Properties that inherit from the parent when nothing sets them.
	 */
	const INHERITED = array( 'color', 'text-align' );

	/**
	 * Theme defaults the stylesheet builds on (Inspiro body and heading ink,
	 * white page ground).
	 */
	const BODY_COLOR    = '#444444';
	const HEADING_COLOR = '#111111';
	const PAGE_COLOR    = '#ffffff';

	/**
	 * Mid-tone assumed for a photograph under a cover overlay.
	 */
	const PHOTO_TONE = array( 107, 107, 107, 1 );

	/**
	 * Declarations by property: prop => list of
	 * [ steps, specificity, source order, value, important, pseudo-element ].
	 *
	 * @var array
	 */
	private $by_prop = array();

	/**
	 * Classes of the page wrapper the converter adds around every section
	 * (the <body> of the AI DOM stands in for it).
	 *
	 * @var string[]
	 */
	private $root_classes = array();

	/**
	 * Memoized lookups, keyed by key().
	 *
	 * @var array
	 */
	private $memo = array();

	/**
	 * Every element used as a memo key, kept referenced: PHP creates DOM
	 * wrapper objects on access and frees them when unreferenced, so a
	 * spl_object_id() would otherwise be recycled for another element and
	 * serve it stale results.
	 *
	 * @var \DOMElement[]
	 */
	private $pinned = array();

	/**
	 * @param string   $css          The demo stylesheet.
	 * @param string[] $root_classes Classes of the page wrapper.
	 */
	public function __construct( $css, array $root_classes = array( 'iss-ai-demo' ) ) {
		$this->root_classes = array_fill_keys( $root_classes, true );
		$this->parse( (string) $css );
	}

	/* ---------------------------------------------------------------------
	 * Public queries
	 * ------------------------------------------------------------------ */

	/**
	 * The element's own cascaded value for a property (no inheritance),
	 * var() resolved. null when no rule sets it.
	 *
	 * @param \DOMElement $el
	 * @param string      $prop
	 * @return string|null
	 */
	public function value( \DOMElement $el, $prop ) {
		$value = $this->cascaded( $el, $prop );
		return null === $value ? null : $this->resolve_vars( $el, $value );
	}

	/**
	 * The computed value: cascaded, else inherited for inherited properties.
	 *
	 * @param \DOMElement $el
	 * @param string      $prop
	 * @return string|null
	 */
	public function computed( \DOMElement $el, $prop ) {
		$key = $this->key( $el ) . '|' . $prop;
		if ( array_key_exists( $key, $this->memo ) ) {
			return $this->memo[ $key ];
		}

		$is_custom = 0 === strpos( $prop, '--' );
		$value     = $this->own_value( $el, $prop );

		if ( 'inherit' === $value || ( null === $value && ( $is_custom || in_array( $prop, self::INHERITED, true ) ) ) ) {
			$parent = $this->parent( $el );
			$value  = $parent ? $this->computed( $parent, $prop ) : $this->initial( $prop );
		}

		if ( null !== $value && ! $is_custom ) {
			$value = $this->resolve_vars( $el, $value );
		}

		$this->memo[ $key ] = $value;
		return $value;
	}

	/**
	 * The element's text colour as RGBA, blended onto its background when
	 * translucent. null when it cannot be resolved.
	 *
	 * @param \DOMElement $el
	 * @return array|null
	 */
	public function text_color( \DOMElement $el ) {
		$value = $this->computed( $el, 'color' );
		$color = null === $value ? null : self::parse_color( $value );
		if ( ! $color ) {
			return null;
		}

		if ( $color[3] < 1 ) {
			$bg = $this->background( $el );
			if ( ! $bg ) {
				return null;
			}
			$color = self::blend( $color, $bg );
		}

		return $color;
	}

	/**
	 * The opaque colour the element's text sits on: its own background or the
	 * nearest painted ancestor's, translucent layers blended in. null when a
	 * background cannot be resolved (e.g. a colour function it doesn't know).
	 *
	 * @param \DOMElement $el
	 * @return array|null
	 */
	public function background( \DOMElement $el ) {
		$key = $this->key( $el ) . '|@bg';
		if ( array_key_exists( $key, $this->memo ) ) {
			return $this->memo[ $key ];
		}

		$layers = array();
		$base   = null;
		for ( $n = $el; $n; $n = $this->parent( $n ) ) {
			$own = $this->own_background( $n );
			if ( false === $own ) {
				return $this->memo[ $key ] = null;
			}
			if ( $own ) {
				if ( $own[3] >= 0.99 ) {
					$base = $own;
					break;
				}
				$layers[] = $own;
			}
		}

		$color = $base ? $base : self::parse_color( self::PAGE_COLOR );
		foreach ( array_reverse( $layers ) as $layer ) {
			$color = self::blend( $layer, $color );
		}

		return $this->memo[ $key ] = $color;
	}

	/**
	 * A stable identity for an element during this conversion (see $pinned).
	 *
	 * @param \DOMElement $el
	 * @return int
	 */
	public function key( \DOMElement $el ) {
		$id                  = spl_object_id( $el );
		$this->pinned[ $id ] = $el;
		return $id;
	}

	/* ---------------------------------------------------------------------
	 * Colour helpers
	 * ------------------------------------------------------------------ */

	/**
	 * WCAG contrast ratio of two RGBA colours.
	 *
	 * @param array $a
	 * @param array $b
	 * @return float
	 */
	public static function contrast( array $a, array $b ) {
		$l1 = self::luminance( $a );
		$l2 = self::luminance( $b );

		return ( max( $l1, $l2 ) + 0.05 ) / ( min( $l1, $l2 ) + 0.05 );
	}

	/**
	 * WCAG relative luminance.
	 *
	 * @param array $c RGBA.
	 * @return float
	 */
	public static function luminance( array $c ) {
		$channels = array();
		foreach ( array( $c[0], $c[1], $c[2] ) as $v ) {
			$v          = $v / 255;
			$channels[] = $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
		}

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * Hex string of an RGBA colour (alpha dropped).
	 *
	 * @param array $c
	 * @return string
	 */
	public static function hex( array $c ) {
		return sprintf( '#%02x%02x%02x', (int) round( $c[0] ), (int) round( $c[1] ), (int) round( $c[2] ) );
	}

	/**
	 * Parse a CSS colour: hex, rgb[a](), hsl[a](), white/black/transparent.
	 *
	 * @param string $value
	 * @return array|null [ r, g, b, a ]
	 */
	public static function parse_color( $value ) {
		$value = strtolower( trim( (string) $value ) );

		$named = array(
			'transparent' => array( 0, 0, 0, 0 ),
			'white'       => array( 255, 255, 255, 1 ),
			'black'       => array( 0, 0, 0, 1 ),
		);
		if ( isset( $named[ $value ] ) ) {
			return $named[ $value ];
		}

		if ( preg_match( '/^#([0-9a-f]{3,8})$/', $value, $m ) ) {
			$hex = $m[1];
			if ( 3 === strlen( $hex ) || 4 === strlen( $hex ) ) {
				$hex = preg_replace( '/(.)/', '$1$1', $hex );
			}
			if ( 6 !== strlen( $hex ) && 8 !== strlen( $hex ) ) {
				return null;
			}
			return array(
				hexdec( substr( $hex, 0, 2 ) ),
				hexdec( substr( $hex, 2, 2 ) ),
				hexdec( substr( $hex, 4, 2 ) ),
				8 === strlen( $hex ) ? hexdec( substr( $hex, 6, 2 ) ) / 255 : 1,
			);
		}

		if ( preg_match( '/^(rgba?|hsla?)\(([^)]*)\)$/', $value, $m ) ) {
			$parts = preg_split( '/[\s,\/]+/', trim( $m[2] ) );
			if ( count( $parts ) < 3 ) {
				return null;
			}
			$alpha = isset( $parts[3] ) ? ( '%' === substr( $parts[3], -1 ) ? (float) $parts[3] / 100 : (float) $parts[3] ) : 1;

			if ( 0 === strpos( $m[1], 'rgb' ) ) {
				$rgb = array();
				foreach ( array_slice( $parts, 0, 3 ) as $p ) {
					$rgb[] = '%' === substr( $p, -1 ) ? (float) $p * 2.55 : (float) $p;
				}
				return array( $rgb[0], $rgb[1], $rgb[2], $alpha );
			}

			$h = fmod( (float) $parts[0], 360 ) / 360;
			$s = (float) $parts[1] / 100;
			$l = (float) $parts[2] / 100;
			$q = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
			$p = 2 * $l - $q;
			$f = static function ( $t ) use ( $p, $q ) {
				$t = $t < 0 ? $t + 1 : ( $t > 1 ? $t - 1 : $t );
				if ( $t < 1 / 6 ) {
					return $p + ( $q - $p ) * 6 * $t;
				}
				if ( $t < 1 / 2 ) {
					return $q;
				}
				if ( $t < 2 / 3 ) {
					return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6;
				}
				return $p;
			};
			return array( $f( $h + 1 / 3 ) * 255, $f( $h ) * 255, $f( $h - 1 / 3 ) * 255, $alpha );
		}

		return null;
	}

	/**
	 * Composite a translucent colour over an opaque one.
	 *
	 * @param array $top
	 * @param array $under
	 * @return array
	 */
	public static function blend( array $top, array $under ) {
		$a = $top[3];
		return array(
			$top[0] * $a + $under[0] * ( 1 - $a ),
			$top[1] * $a + $under[1] * ( 1 - $a ),
			$top[2] * $a + $under[2] * ( 1 - $a ),
			1,
		);
	}

	/* ---------------------------------------------------------------------
	 * Stylesheet clean-up
	 * ------------------------------------------------------------------ */

	/**
	 * Drop the grid layout declarations of grids the converter turned into
	 * native grid groups, so WordPress' responsive grid (and the user's edits
	 * to it) are what render. A rule is affected when any of its selectors
	 * names one of the lifted classes: track, placement and span properties
	 * go from all of them; display:grid and gaps only where the lifted class
	 * is the rule's subject (the container itself).
	 *
	 * @param string   $css     Stylesheet.
	 * @param string[] $classes Classes of the lifted grid containers.
	 * @return string
	 */
	public static function strip_grid( $css, array $classes ) {
		$classes = array_filter( array_map( 'sanitize_html_class', $classes ) );
		if ( ! $classes ) {
			return $css;
		}

		$class_re = '/\.(?:' . implode( '|', array_map( 'preg_quote', $classes ) ) . ')(?![\w-])/';

		return preg_replace_callback(
			'/([^{}]+)\{([^{}]*)\}/',
			static function ( $m ) use ( $class_re ) {
				$selectors = $m[1];
				if ( ! preg_match( $class_re, $selectors ) ) {
					return $m[0];
				}

				// Is a lifted class in the subject compound of any selector?
				$is_container = false;
				foreach ( explode( ',', $selectors ) as $selector ) {
					$compounds = preg_split( '/\s*[\s>+~]\s*/', trim( $selector ) );
					if ( preg_match( $class_re, (string) end( $compounds ) ) ) {
						$is_container = true;
					}
				}

				$kept = array();
				foreach ( explode( ';', $m[2] ) as $decl ) {
					$prop = strtolower( trim( (string) strstr( $decl, ':', true ) ) );
					if ( '' === $prop ) {
						if ( '' !== trim( $decl ) ) {
							$kept[] = $decl;
						}
						continue;
					}
					if ( preg_match( '/^grid-(template|auto|column|row|area)/', $prop ) || 'grid' === $prop ) {
						continue;
					}
					if ( $is_container && ( in_array( $prop, array( 'gap', 'row-gap', 'column-gap', 'grid-gap' ), true )
						|| ( 'display' === $prop && preg_match( '/grid/i', $decl ) ) ) ) {
						continue;
					}
					$kept[] = $decl;
				}

				return $m[1] . '{' . implode( ';', $kept ) . '}';
			},
			$css
		);
	}

	/* ---------------------------------------------------------------------
	 * Element model (the converter's output on top of the AI DOM)
	 * ------------------------------------------------------------------ */

	/**
	 * Parent element, or null above the page wrapper.
	 *
	 * @param \DOMElement $el
	 * @return \DOMElement|null
	 */
	private function parent( \DOMElement $el ) {
		$p = $el->parentNode;
		return ( $p instanceof \DOMElement && 'html' !== $p->nodeName ) ? $p : null;
	}

	/**
	 * The parent as rendered: a cover's content sits in an inner container
	 * and every ai-cols child in its own column, so ">" never reaches them.
	 *
	 * @param \DOMElement $el
	 * @return \DOMElement|null
	 */
	private function rendered_parent( \DOMElement $el ) {
		$p = $this->parent( $el );
		if ( ! $p || $this->is_cover( $p ) || preg_match( '/\bai-cols-[2-4]\b/', $p->getAttribute( 'class' ) ) ) {
			return null;
		}
		return $p;
	}

	/**
	 * Whether an element becomes a cover block (the converter marks it
	 * before removing the background image).
	 *
	 * @param \DOMElement $el
	 * @return bool
	 */
	private function is_cover( \DOMElement $el ) {
		if ( $el->hasAttribute( 'data-iss-cover' ) ) {
			return true;
		}
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				return 'img' === $child->nodeName && 'background' === trim( $child->getAttribute( 'data-role' ) );
			}
		}
		return false;
	}

	/**
	 * Class set of an element, including the classes the converter's output
	 * carries (page wrapper, native colours, cover).
	 *
	 * @param \DOMElement $el
	 * @return array class => true
	 */
	private function classes( \DOMElement $el ) {
		if ( 'body' === $el->nodeName ) {
			return $this->root_classes;
		}

		$key = $this->key( $el ) . '|@class';
		if ( isset( $this->memo[ $key ] ) ) {
			return $this->memo[ $key ];
		}

		$classes = array_fill_keys( preg_split( '/\s+/', trim( $el->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY ), true );
		if ( sanitize_hex_color( trim( $el->getAttribute( 'data-bg' ) ) ) ) {
			$classes['has-background'] = true;
			$classes['has-text-color'] = true;
		}
		if ( $this->is_cover( $el ) ) {
			$classes['wp-block-cover'] = true;
		}

		return $this->memo[ $key ] = $classes;
	}

	/**
	 * A value set on the element itself: native colours first (inline
	 * styles beat every rule), then the cascade, then theme defaults that
	 * apply to the element directly (heading ink, cover text).
	 *
	 * @param \DOMElement $el
	 * @param string      $prop
	 * @return string|null
	 */
	private function own_value( \DOMElement $el, $prop ) {
		if ( 'color' === $prop ) {
			$bg   = sanitize_hex_color( trim( $el->getAttribute( 'data-bg' ) ) );
			$text = sanitize_hex_color( trim( $el->getAttribute( 'data-text' ) ) );
			if ( $text ) {
				return $text;
			}
			if ( $bg ) {
				// Same derivation as HtmlToBlocks::group_block().
				$c = self::parse_color( $bg );
				return ( 299 * $c[0] + 587 * $c[1] + 114 * $c[2] ) / 1000 < 128 ? '#ffffff' : '#111111';
			}
		}

		$value = $this->cascaded( $el, $prop );
		if ( null !== $value ) {
			return $value;
		}

		if ( 'color' === $prop ) {
			if ( $this->is_cover( $el ) ) {
				return '#ffffff';
			}
			if ( preg_match( '/^h[1-6]$/', $el->nodeName ) ) {
				return self::HEADING_COLOR;
			}
		}

		return null;
	}

	/**
	 * Initial value at the top of the tree.
	 *
	 * @param string $prop
	 * @return string|null
	 */
	private function initial( $prop ) {
		switch ( $prop ) {
			case 'color':
				return self::BODY_COLOR;
			case 'text-align':
				return 'left';
		}
		return null;
	}

	/**
	 * The element's own background: native colour, cover overlay, or the
	 * cascade (background, background-color, background-image — a gradient
	 * counts as the average of its stops — on the element or its ::before).
	 *
	 * @param \DOMElement $el
	 * @return array|null|false RGBA, null when none, false when unresolvable.
	 */
	private function own_background( \DOMElement $el ) {
		$native = sanitize_hex_color( trim( $el->getAttribute( 'data-bg' ) ) );
		if ( $native ) {
			return self::parse_color( $native );
		}

		if ( $this->is_cover( $el ) ) {
			$overlay = sanitize_hex_color( trim( $el->getAttribute( 'data-iss-cover-overlay' ) ) );
			$dim     = $el->hasAttribute( 'data-iss-cover-dim' ) ? (int) $el->getAttribute( 'data-iss-cover-dim' ) : 40;
			$top     = self::parse_color( $overlay ? $overlay : '#000000' );
			$top[3]  = max( 0, min( 100, $dim ) ) / 100;
			return self::blend( $top, self::PHOTO_TONE );
		}

		foreach ( array( '', 'before' ) as $pseudo ) {
			$winner = $this->cascaded_any( $el, array( 'background', 'background-color', 'background-image' ), $pseudo );
			if ( null === $winner ) {
				continue;
			}

			$value = strtolower( $this->resolve_vars( $el, $winner ) );
			if ( preg_match( '/^(none|transparent|initial|inherit|unset)$/', trim( $value ) ) ) {
				continue;
			}

			preg_match_all( '/#[0-9a-f]{3,8}\b|(?:rgba?|hsla?)\([^)]*\)|\b(?:white|black|transparent)\b/', $value, $m );
			if ( ! $m[0] ) {
				// A photo (url(), neutralized to noop( by the sanitizer) is no
				// solid colour; any other function is one we don't model.
				return ( false === strpos( $value, 'url' ) && false === strpos( $value, 'noop' ) ) ? false : null;
			}

			$stops = array();
			foreach ( $m[0] as $token ) {
				$c = self::parse_color( $token );
				if ( $c ) {
					$stops[] = $c;
				}
			}
			if ( ! $stops ) {
				return false;
			}
			if ( false === strpos( $value, 'gradient' ) ) {
				return $stops[0];
			}

			$avg = array( 0, 0, 0, 0 );
			foreach ( $stops as $c ) {
				foreach ( array( 0, 1, 2, 3 ) as $i ) {
					$avg[ $i ] += $c[ $i ] / count( $stops );
				}
			}
			return $avg;
		}

		return null;
	}

	/* ---------------------------------------------------------------------
	 * Cascade
	 * ------------------------------------------------------------------ */

	/**
	 * Winning raw value of a property for the element (or its pseudo-element).
	 *
	 * @param \DOMElement $el
	 * @param string      $prop
	 * @param string      $pseudo '' or 'before'/'after'.
	 * @return string|null
	 */
	private function cascaded( \DOMElement $el, $prop, $pseudo = '' ) {
		$winner = $this->winner( $el, array( $prop ), $pseudo );
		return $winner ? $winner[1] : null;
	}

	/**
	 * Winning raw value among several properties that override each other
	 * (a shorthand and its longhands).
	 *
	 * @param \DOMElement $el
	 * @param string[]    $props
	 * @param string      $pseudo
	 * @return string|null
	 */
	private function cascaded_any( \DOMElement $el, array $props, $pseudo = '' ) {
		$winner = $this->winner( $el, $props, $pseudo );
		return $winner ? $winner[1] : null;
	}

	/**
	 * @param \DOMElement $el
	 * @param string[]    $props
	 * @param string      $pseudo
	 * @return array|null [ rank, value ]
	 */
	private function winner( \DOMElement $el, array $props, $pseudo ) {
		$best = null;
		foreach ( $props as $prop ) {
			if ( empty( $this->by_prop[ $prop ] ) ) {
				continue;
			}
			foreach ( $this->by_prop[ $prop ] as $entry ) {
				list( $steps, $spec, $order, $value, $important, $pseudo_el ) = $entry;
				if ( $pseudo_el !== $pseudo ) {
					continue;
				}
				$rank = array( $important ? 1 : 0, $spec, $order );
				if ( $best && $rank <= $best[0] ) {
					continue;
				}
				if ( $this->matches( $steps, $el ) ) {
					$best = array( $rank, $value );
				}
			}
		}
		return $best;
	}

	/**
	 * Replace var(--x, fallback) references with the element's values.
	 *
	 * @param \DOMElement $el
	 * @param string      $value
	 * @return string
	 */
	private function resolve_vars( \DOMElement $el, $value ) {
		for ( $guard = 0; false !== strpos( $value, 'var(' ) && $guard < 8; $guard++ ) {
			$value = preg_replace_callback(
				'/var\(\s*(--[\w-]+)\s*(?:,\s*((?:[^()]|\([^()]*\))*))?\)/',
				function ( $m ) use ( $el ) {
					$v = $this->computed( $el, $m[1] );
					return null !== $v ? $v : ( isset( $m[2] ) ? trim( $m[2] ) : '' );
				},
				$value
			);
		}
		return $value;
	}

	/* ---------------------------------------------------------------------
	 * Selector matching
	 * ------------------------------------------------------------------ */

	/**
	 * @param array       $steps Parsed compound selectors, left to right.
	 * @param \DOMElement $el
	 * @return bool
	 */
	private function matches( array $steps, \DOMElement $el ) {
		$last = count( $steps ) - 1;
		return $this->compound_matches( $steps[ $last ], $el ) && $this->match_left( $steps, $last, $el );
	}

	/**
	 * @param array       $steps
	 * @param int         $i  Index of the step $el matched.
	 * @param \DOMElement $el
	 * @return bool
	 */
	private function match_left( array $steps, $i, \DOMElement $el ) {
		if ( 0 === $i ) {
			return true;
		}

		$prev = $steps[ $i - 1 ];
		switch ( $steps[ $i ]['comb'] ) {
			case '>':
				$p = $this->rendered_parent( $el );
				return $p && $this->compound_matches( $prev, $p ) && $this->match_left( $steps, $i - 1, $p );

			case '+':
				$s = $this->sibling( $el );
				return $s && $this->compound_matches( $prev, $s ) && $this->match_left( $steps, $i - 1, $s );

			case '~':
				for ( $s = $this->sibling( $el ); $s; $s = $this->sibling( $s ) ) {
					if ( $this->compound_matches( $prev, $s ) && $this->match_left( $steps, $i - 1, $s ) ) {
						return true;
					}
				}
				return false;

			default:
				for ( $p = $this->parent( $el ); $p; $p = $this->parent( $p ) ) {
					if ( $this->compound_matches( $prev, $p ) && $this->match_left( $steps, $i - 1, $p ) ) {
						return true;
					}
				}
				return false;
		}
	}

	/**
	 * Previous element sibling.
	 *
	 * @param \DOMElement $el
	 * @return \DOMElement|null
	 */
	private function sibling( \DOMElement $el ) {
		for ( $s = $el->previousSibling; $s; $s = $s->previousSibling ) {
			if ( $s instanceof \DOMElement ) {
				return $s;
			}
		}
		return null;
	}

	/**
	 * @param array       $c  Compound.
	 * @param \DOMElement $el
	 * @return bool
	 */
	private function compound_matches( array $c, \DOMElement $el ) {
		$tag = 'body' === $el->nodeName ? 'div' : strtolower( $el->nodeName );
		if ( '' !== $c['tag'] && $c['tag'] !== $tag ) {
			return false;
		}

		foreach ( $c['ids'] as $id ) {
			if ( $el->getAttribute( 'id' ) !== $id ) {
				return false;
			}
		}

		if ( $c['classes'] ) {
			$classes = $this->classes( $el );
			foreach ( $c['classes'] as $class ) {
				if ( ! isset( $classes[ $class ] ) ) {
					return false;
				}
			}
		}

		foreach ( $c['attrs'] as $attr ) {
			list( $name, $op, $val ) = $attr;
			if ( ! $el->hasAttribute( $name ) ) {
				return false;
			}
			$actual = $el->getAttribute( $name );
			$ok     = true;
			switch ( $op ) {
				case '=':
					$ok = $actual === $val;
					break;
				case '~=':
					$ok = in_array( $val, preg_split( '/\s+/', $actual ), true );
					break;
				case '^=':
					$ok = '' !== $val && 0 === strpos( $actual, $val );
					break;
				case '$=':
					$ok = '' !== $val && substr( $actual, -strlen( $val ) ) === $val;
					break;
				case '*=':
					$ok = '' !== $val && false !== strpos( $actual, $val );
					break;
			}
			if ( ! $ok ) {
				return false;
			}
		}

		foreach ( $c['pseudos'] as $pseudo ) {
			if ( ! $this->pseudo_matches( $pseudo[0], $pseudo[1], $el ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Static and logical pseudo-classes; interactive ones (:hover, :focus…)
	 * never match — they are not the resting state being checked.
	 *
	 * @param string       $name
	 * @param string|array $arg
	 * @param \DOMElement  $el
	 * @return bool
	 */
	private function pseudo_matches( $name, $arg, \DOMElement $el ) {
		switch ( $name ) {
			case 'is':
			case 'where':
			case 'matches':
				foreach ( $arg as $steps ) {
					if ( $this->matches( $steps, $el ) ) {
						return true;
					}
				}
				return false;

			case 'not':
				foreach ( $arg as $steps ) {
					if ( $this->matches( $steps, $el ) ) {
						return false;
					}
				}
				return true;

			case 'first-child':
				return 1 === $this->position( $el, false, false );
			case 'last-child':
				return 1 === $this->position( $el, false, true );
			case 'only-child':
				return 1 === $this->position( $el, false, false ) && 1 === $this->position( $el, false, true );
			case 'first-of-type':
				return 1 === $this->position( $el, true, false );
			case 'last-of-type':
				return 1 === $this->position( $el, true, true );
			case 'nth-child':
				return $this->nth( (string) $arg, $this->position( $el, false, false ) );
			case 'nth-last-child':
				return $this->nth( (string) $arg, $this->position( $el, false, true ) );
			case 'nth-of-type':
				return $this->nth( (string) $arg, $this->position( $el, true, false ) );
			case 'nth-last-of-type':
				return $this->nth( (string) $arg, $this->position( $el, true, true ) );

			case 'root':
				return 'html' === $el->nodeName;
			case 'empty':
				foreach ( $el->childNodes as $child ) {
					if ( $child instanceof \DOMElement || '' !== trim( $child->textContent ) ) {
						return false;
					}
				}
				return true;
			case 'link':
			case 'any-link':
				return 'a' === $el->nodeName && $el->hasAttribute( 'href' );
		}

		return false;
	}

	/**
	 * 1-based position among element siblings.
	 *
	 * @param \DOMElement $el
	 * @param bool        $of_type Count only same-tag siblings.
	 * @param bool        $from_end
	 * @return int
	 */
	private function position( \DOMElement $el, $of_type, $from_end ) {
		$n = 1;
		for ( $s = $from_end ? $el->nextSibling : $el->previousSibling; $s; $s = $from_end ? $s->nextSibling : $s->previousSibling ) {
			if ( $s instanceof \DOMElement && ( ! $of_type || $s->nodeName === $el->nodeName ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * An+B match.
	 *
	 * @param string $arg
	 * @param int    $index
	 * @return bool
	 */
	private function nth( $arg, $index ) {
		$arg = strtolower( str_replace( ' ', '', $arg ) );
		if ( 'odd' === $arg ) {
			$arg = '2n+1';
		} elseif ( 'even' === $arg ) {
			$arg = '2n';
		}

		if ( preg_match( '/^([+-]?\d+)$/', $arg, $m ) ) {
			return $index === (int) $m[1];
		}
		if ( ! preg_match( '/^([+-]?\d*)n([+-]\d+)?$/', $arg, $m ) ) {
			return false;
		}

		$a = '' === $m[1] || '+' === $m[1] ? 1 : ( '-' === $m[1] ? -1 : (int) $m[1] );
		$b = isset( $m[2] ) ? (int) $m[2] : 0;
		if ( 0 === $a ) {
			return $index === $b;
		}
		$k = ( $index - $b ) / $a;
		return $k >= 0 && floor( $k ) === $k;
	}

	/* ---------------------------------------------------------------------
	 * Parsing
	 * ------------------------------------------------------------------ */

	/**
	 * Index the stylesheet's top-level rules by property.
	 *
	 * @param string $css
	 */
	private function parse( $css ) {
		$css   = preg_replace( '#/\*.*?\*/#s', '', $css );
		$len   = strlen( $css );
		$pos   = 0;
		$order = 0;

		while ( $pos < $len ) {
			$open = strpos( $css, '{', $pos );
			if ( false === $open ) {
				break;
			}

			$depth = 1;
			$i     = $open + 1;
			while ( $i < $len && $depth > 0 ) {
				if ( '{' === $css[ $i ] ) {
					$depth++;
				} elseif ( '}' === $css[ $i ] ) {
					$depth--;
				}
				$i++;
			}

			$prelude = trim( substr( $css, $pos, $open - $pos ) );
			$body    = substr( $css, $open + 1, max( 0, $i - $open - 2 ) );
			$pos     = $i;

			// At-rules (media queries, keyframes, font faces) are skipped.
			if ( '' === $prelude || '@' === $prelude[0] ) {
				continue;
			}

			$decls = $this->declarations( $body );
			if ( ! $decls ) {
				continue;
			}

			foreach ( $this->split_list( $prelude ) as $selector ) {
				$parsed = $this->parse_selector( $selector );
				if ( null === $parsed ) {
					continue;
				}
				foreach ( $decls as $prop => $decl ) {
					$this->by_prop[ $prop ][] = array( $parsed['steps'], $parsed['spec'], $order, $decl[0], $decl[1], $parsed['pseudo'] );
				}
			}
			$order++;
		}
	}

	/**
	 * @param string $body
	 * @return array prop => [ value, important ]
	 */
	private function declarations( $body ) {
		$out = array();
		foreach ( explode( ';', $body ) as $decl ) {
			$colon = strpos( $decl, ':' );
			if ( false === $colon ) {
				continue;
			}
			$prop  = trim( substr( $decl, 0, $colon ) );
			$prop  = 0 === strpos( $prop, '--' ) ? $prop : strtolower( $prop );
			$value = trim( substr( $decl, $colon + 1 ) );
			if ( '' === $prop || '' === $value ) {
				continue;
			}
			$important = (bool) preg_match( '/\s*!important\s*$/i', $value );
			if ( $important ) {
				$value = trim( preg_replace( '/\s*!important\s*$/i', '', $value ) );
			}
			$out[ $prop ] = array( $value, $important );
		}
		return $out;
	}

	/**
	 * Split a comma-separated list outside parentheses.
	 *
	 * @param string $list
	 * @return string[]
	 */
	private function split_list( $list ) {
		$parts = array();
		$depth = 0;
		$cur   = '';
		$len   = strlen( $list );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $list[ $i ];
			if ( '(' === $ch ) {
				$depth++;
			} elseif ( ')' === $ch ) {
				$depth--;
			} elseif ( ',' === $ch && 0 === $depth ) {
				$parts[] = trim( $cur );
				$cur     = '';
				continue;
			}
			$cur .= $ch;
		}
		$parts[] = trim( $cur );
		return array_values( array_filter( $parts, 'strlen' ) );
	}

	/**
	 * @param string $selector
	 * @return array|null [ steps, spec, pseudo ] — null when unsupported.
	 */
	private function parse_selector( $selector ) {
		$s      = trim( $selector );
		$len    = strlen( $s );
		$i      = 0;
		$steps  = array();
		$spec   = 0;
		$pseudo = '';

		while ( $i < $len ) {
			$ws = false;
			while ( $i < $len && ctype_space( $s[ $i ] ) ) {
				$i++;
				$ws = true;
			}
			if ( $i >= $len ) {
				break;
			}

			$comb = null;
			if ( in_array( $s[ $i ], array( '>', '+', '~' ), true ) ) {
				$comb = $s[ $i ];
				$i++;
				while ( $i < $len && ctype_space( $s[ $i ] ) ) {
					$i++;
				}
			} elseif ( $ws && $steps ) {
				$comb = ' ';
			}

			if ( '' !== $pseudo ) {
				return null; // Nothing may follow a pseudo-element.
			}

			$compound = $this->parse_compound( $s, $i );
			if ( null === $compound ) {
				return null;
			}

			$compound['comb'] = $steps ? ( null === $comb ? ' ' : $comb ) : null;
			$spec            += $compound['spec'];
			$pseudo           = $compound['pseudo_el'];
			$steps[]          = $compound;
		}

		return $steps ? array( 'steps' => $steps, 'spec' => $spec, 'pseudo' => $pseudo ) : null;
	}

	/**
	 * @param string $s
	 * @param int    $i Position, advanced past the compound.
	 * @return array|null
	 */
	private function parse_compound( $s, &$i ) {
		$len   = strlen( $s );
		$start = $i;
		$c     = array(
			'tag'       => '',
			'classes'   => array(),
			'ids'       => array(),
			'attrs'     => array(),
			'pseudos'   => array(),
			'pseudo_el' => '',
			'spec'      => 0,
		);

		if ( $i < $len && '*' === $s[ $i ] ) {
			$i++;
		} elseif ( preg_match( '/\G[a-zA-Z][a-zA-Z0-9-]*/', $s, $m, 0, $i ) ) {
			$c['tag']   = strtolower( $m[0] );
			$c['spec'] += 1;
			$i         += strlen( $m[0] );
		}

		while ( $i < $len ) {
			$ch = $s[ $i ];

			if ( '.' === $ch && preg_match( '/\G\.(-?[_a-zA-Z][\w-]*)/', $s, $m, 0, $i ) ) {
				$c['classes'][] = $m[1];
				$c['spec']     += 100;
				$i             += strlen( $m[0] );
			} elseif ( '#' === $ch && preg_match( '/\G#(-?[_a-zA-Z][\w-]*)/', $s, $m, 0, $i ) ) {
				$c['ids'][] = $m[1];
				$c['spec'] += 10000;
				$i         += strlen( $m[0] );
			} elseif ( '[' === $ch ) {
				$end = strpos( $s, ']', $i );
				if ( false === $end || ! preg_match( '/^\s*([\w-]+)\s*(?:([~^$*]?=)\s*[\'"]?([^\'"]*)[\'"]?)?\s*$/', substr( $s, $i + 1, $end - $i - 1 ), $m ) ) {
					return null;
				}
				$c['attrs'][] = array( $m[1], isset( $m[2] ) ? $m[2] : '', isset( $m[3] ) ? $m[3] : '' );
				$c['spec']   += 100;
				$i            = $end + 1;
			} elseif ( ':' === $ch && preg_match( '/\G(::?)([a-zA-Z-]+)/', $s, $m, 0, $i ) ) {
				$name = strtolower( $m[2] );
				$i   += strlen( $m[0] );

				$arg = null;
				if ( $i < $len && '(' === $s[ $i ] ) {
					$depth = 1;
					$j     = $i + 1;
					while ( $j < $len && $depth > 0 ) {
						if ( '(' === $s[ $j ] ) {
							$depth++;
						} elseif ( ')' === $s[ $j ] ) {
							$depth--;
						}
						$j++;
					}
					$arg = substr( $s, $i + 1, $j - $i - 2 );
					$i   = $j;
				}

				if ( '::' === $m[1] || in_array( $name, array( 'before', 'after', 'first-line', 'first-letter' ), true ) ) {
					$c['pseudo_el'] = in_array( $name, array( 'before', 'after' ), true ) ? $name : 'other';
					$c['spec']     += 1;
				} elseif ( in_array( $name, array( 'is', 'where', 'not', 'matches' ), true ) ) {
					$list = array();
					$max  = 0;
					foreach ( $this->split_list( (string) $arg ) as $sub ) {
						$parsed = $this->parse_selector( $sub );
						if ( null === $parsed || '' !== $parsed['pseudo'] ) {
							return null;
						}
						$list[] = $parsed['steps'];
						$max    = max( $max, $parsed['spec'] );
					}
					$c['pseudos'][] = array( $name, $list );
					if ( 'where' !== $name ) {
						$c['spec'] += $max;
					}
				} else {
					$c['pseudos'][] = array( $name, $arg );
					$c['spec']     += 100;
				}
			} else {
				break;
			}
		}

		return $i > $start ? $c : null;
	}
}
