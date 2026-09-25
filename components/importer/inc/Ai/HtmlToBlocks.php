<?php
/**
 * Deterministic converter: constrained AI-generated HTML → native Gutenberg
 * blocks.
 *
 * The AI writes pages in a small HTML dialect (sections, headings, paragraphs,
 * divs for layout, images as <img data-query>, buttons as <a class="btn...">)
 * plus one shared stylesheet scoped under .ai-demo. Because we control the
 * dialect, the mapping to core blocks is mechanical — unlike generic
 * HTML→block translators, nothing has to be guessed:
 *
 *   section        → wp:group (alignfull, classes preserved)
 *   div            → wp:group (classes preserved; layout via the AI's CSS)
 *   h1–h6          → wp:heading
 *   p              → wp:paragraph
 *   img / figure   → wp:image (Pexels photo sideloaded, data-query resolved)
 *   a.btn…         → wp:buttons / wp:button (consecutive buttons merged)
 *   ul / ol        → wp:list + wp:list-item
 *   blockquote     → wp:quote
 *   details        → wp:details
 *   hr             → wp:separator
 *   svg, unknown   → wp:html passthrough
 *
 * Optional per-element attributes map onto native block attributes:
 *   data-css                   → per-block custom CSS (style.css, WordPress 7.0+)
 *   section data-height        → cover minHeight; data-position → contentPosition
 *   background img data-overlay → cover overlay color
 *   ai-cols child data-width   → column width; wrapper data-valign → alignment
 *   div/section data-layout    → group layout: grid (data-columns = Max.
 *                                columns, data-min = Min. column width), flex
 *                                row or stack; data-gap → block gap
 *   child data-col-span / data-row-span / data-grow / data-basis
 *                              → grid spans and flex sizing of that child
 *   data-block='icon', <svg>   → core/icon showing the placeholder icon (the
 *                                AI places icons; the user picks the glyphs)
 *   data-block='html'          → core/html: a sanitized, self-contained snippet
 *                                (e.g. a marquee) with its own scoped <style>
 *   data-block='map'           → core/html: a map embed built from an address
 *
 * With the demo stylesheet available (options.css) the converter also reads
 * what that stylesheet computes (see CssCascade): CSS grids become native
 * grid groups, text that would fail contrast gets a native colour, and
 * buttons and icons follow the text alignment of their container.
 *
 * The visual design ships as a per-demo stylesheet (see AiDemoGenerator);
 * the blocks stay native and editable.
 *
 * @package Inspiro Starter Sites
 */

namespace Inspiro\Starter_Sites\Ai;

defined( 'ABSPATH' ) || exit;

class HtmlToBlocks {

	/**
	 * slug => URL map for internal links (href="#page:slug").
	 *
	 * @var array
	 */
	private $page_links = array();

	/**
	 * Resolves an image query to [ 'id' => attachment ID, 'url' => URL ]|null.
	 *
	 * @var callable
	 */
	private $image_resolver;

	/**
	 * Brand tokens from the plan (accent, accent_text, radius) — used to
	 * build native wp:button blocks so their colors are editable in the
	 * block UI instead of living in the stylesheet.
	 *
	 * @var array
	 */
	private $brand = array();

	/**
	 * block_css    — emit data-css as per-block custom CSS (needs WP 7.0+
	 *                and the edit_css capability, or core strips it on save);
	 * under_header — the page's header floats over its first section;
	 * icon         — the icon name every icon block shows ('' = no core/icon
	 *                block on this site: icons are dropped);
	 * css          — the demo stylesheet ('' = convert the HTML as written);
	 * html         — Custom HTML snippets and map embeds are allowed (needs
	 *                unfiltered_html, or WordPress strips them on save).
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * The demo stylesheet's cascade for the page being converted.
	 *
	 * @var CssCascade|null
	 */
	private $cascade = null;

	/**
	 * Grid containers lifted from the stylesheet into native grids: object
	 * id => [ 'count' => columns (0 = auto), 'spans' => per-track spans ].
	 *
	 * @var array
	 */
	private $lifted = array();

	/**
	 * Classes of those containers — their CSS grid rules are dropped when the
	 * page's stylesheet is printed (AiDemoGenerator::get_demo_css_for_post()).
	 *
	 * @var string[]
	 */
	private $lifted_classes = array();

	/**
	 * Whether the last converted page opens with a photo cover — the only
	 * first section a transparent header can float over.
	 *
	 * @var bool
	 */
	private $opens_with_cover = false;

	/**
	 * @param array    $page_links     slug => URL map.
	 * @param callable $image_resolver fn( string $query, string $orientation ): ?array
	 * @param array    $brand          [ 'accent' => hex, 'accent_text' => hex, 'radius' => css length ]
	 * @param array    $options        [ 'block_css' => bool, 'under_header' => bool, 'icon' => string, 'css' => string, 'html' => bool ]
	 */
	public function __construct( array $page_links, callable $image_resolver, array $brand = array(), array $options = array() ) {
		$this->page_links     = $page_links;
		$this->image_resolver = $image_resolver;
		$this->brand          = wp_parse_args(
			$brand,
			array(
				'accent'      => '#1d1d1f',
				'accent_text' => '#ffffff',
				'radius'      => '8px',
			)
		);
		$this->options        = wp_parse_args(
			$options,
			array(
				'block_css'    => false,
				'under_header' => false,
				'icon'         => '',
				'css'          => '',
				'html'         => false,
			)
		);
	}

	/**
	 * Classes of the grid containers the last conversion lifted from the
	 * stylesheet into native grid groups.
	 *
	 * @return string[]
	 */
	public function lifted_classes() {
		return $this->lifted_classes;
	}

	/**
	 * Whether the last converted page opens with a photo cover.
	 *
	 * @return bool
	 */
	public function opens_with_cover() {
		return $this->opens_with_cover;
	}

	/**
	 * Convert an AI HTML fragment into serialized block markup, wrapped in an
	 * .ai-demo group so the demo stylesheet can scope to it.
	 *
	 * @param string $html      AI-generated body HTML (sections only).
	 * @param string $page_slug Used for a per-page scope class.
	 * @return string Block markup ('' when nothing could be converted).
	 */
	public function convert( $html, $page_slug = '' ) {
		$html = trim( (string) $html );
		if ( '' === $html ) {
			return '';
		}

		// Headlines are one colour and one weight: unwrap the single-word
		// accent spans older prompts produced (<span class="ai-highlight">)
		// and strip any other inline styling inside h1-h6 — only <br> stays.
		$html = preg_replace( '#<span\b[^>]*\bai-highlight\b[^>]*>(.*?)</span>#is', '$1', $html );
		$html = preg_replace_callback(
			'#<(h[1-6])\b([^>]*)>(.*?)</\1>#is',
			static function ( $m ) {
				return '<' . $m[1] . $m[2] . '>' . strip_tags( $m[3], '<br>' ) . '</' . $m[1] . '>';
			},
			$html
		);

		$doc = new \DOMDocument();
		// The dialect is a fragment — wrap it so DOMDocument keeps structure.
		$wrapped = '<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>';

		libxml_use_internal_errors( true );
		$loaded = $doc->loadHTML( $wrapped, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();

		if ( ! $loaded ) {
			return '';
		}

		$this->sanitize_dom( $doc );

		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return '';
		}

		$this->lifted         = array();
		$this->lifted_classes = array();
		$this->cascade        = '' !== trim( (string) $this->options['css'] )
			? new CssCascade( $this->options['css'], array( 'iss-ai-demo', 'iss-ai-demo--' . sanitize_html_class( $page_slug ) ) )
			: null;

		// If the AI wrapped the whole page in a single demo div, unwrap it —
		// its children are the top-level sections.
		$root             = $body;
		$only             = null;
		$element_children = 0;
		foreach ( $body->childNodes as $n ) {
			if ( XML_ELEMENT_NODE === $n->nodeType ) {
				$element_children++;
				$only = $n;
			}
		}
		if ( 1 === $element_children && $only ) {
			$cls = ' ' . $this->classes( $only ) . ' ';
			if ( false !== strpos( $cls, ' iss-ai-demo ' ) || false !== strpos( $cls, ' ai-demo ' ) ) {
				$root = $only;
			}
		}

		$sections = $this->convert_children_blocks( $root, true );
		if ( ! $sections ) {
			return '';
		}

		$this->opens_with_cover = 0 === strpos( $sections[0], '<!-- wp:cover' );

		$classes = trim( 'iss-ai-demo' . ( $page_slug ? ' iss-ai-demo--' . sanitize_html_class( $page_slug ) : '' ) );

		// Each top-level section gets its own scope-class wrapper (all the
		// demo CSS is ".iss-ai-demo <x>" descendant rules) that mirrors the
		// section's earned alignment: full-bleed sections escape the content
		// column, plain ones stay inside it and keep the theme's responsive
		// side padding on small screens. One big alignfull wrapper would
		// force every section full-width and lose those gutters.
		$out = array();
		foreach ( $sections as $i => $block ) {
			$first   = strtok( $block, "\n" );
			$is_full = false !== strpos( $first, '"align":"full"' );

			// The transparent header floats over this section; the demo
			// stylesheet pushes the cover's content below it.
			$wrapper_classes = $classes;
			if ( 0 === $i && $this->opens_with_cover && $this->options['under_header'] ) {
				$wrapper_classes .= ' iss-ai-under-header';
			}

			$out[] = sprintf(
				"<!-- wp:group {%s\"className\":\"%s\",\"layout\":{\"type\":\"default\"}} -->\n<div class=\"wp-block-group %s%s\">\n%s\n</div>\n<!-- /wp:group -->",
				$is_full ? '"align":"full",' : '',
				esc_attr( $wrapper_classes ),
				$is_full ? 'alignfull ' : '',
				esc_attr( $wrapper_classes ),
				$block
			);
		}

		return implode( "\n\n", $out );
	}

	/* ---------------------------------------------------------------------
	 * DOM walking
	 * ------------------------------------------------------------------ */

	/**
	 * Convert an element's children into concatenated block markup, merging
	 * consecutive button anchors into one wp:buttons row.
	 *
	 * @param \DOMNode $parent
	 * @param bool     $top_level Body-level children: divs are treated as
	 *                            full-width sections (models sometimes write
	 *                            divs where the dialect says section).
	 * @return string
	 */
	private function convert_children( \DOMNode $parent, $top_level = false ) {
		return implode( "\n\n", $this->convert_children_blocks( $parent, $top_level ) );
	}

	/**
	 * Same conversion, but returning the top-level blocks as an array so
	 * convert() can wrap each section individually.
	 *
	 * @param \DOMNode $parent
	 * @param bool     $top_level
	 * @return string[]
	 */
	private function convert_children_blocks( \DOMNode $parent, $top_level = false ) {
		$blocks         = array();
		$pending_buttons = array();

		$flush_buttons = function () use ( &$pending_buttons, &$blocks ) {
			if ( $pending_buttons ) {
				$blocks[]        = $this->buttons_block( $pending_buttons );
				$pending_buttons = array();
			}
		};

		foreach ( iterator_to_array( $parent->childNodes ) as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType ) {
				$text = trim( $node->textContent );
				if ( '' !== $text ) {
					$flush_buttons();
					$blocks[] = sprintf( "<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->", esc_html( $text ) );
				}
				continue;
			}

			if ( XML_ELEMENT_NODE !== $node->nodeType ) {
				continue;
			}

			if ( 'a' === $node->nodeName && $this->is_button( $node ) ) {
				$pending_buttons[] = $node;
				continue;
			}

			$flush_buttons();

			if ( $top_level && in_array( $node->nodeName, array( 'div', 'aside' ), true ) && '' === trim( $node->getAttribute( 'data-block' ) ) ) {
				$block = $this->group_block( $node, true );
			} else {
				$block = $this->convert_element( $node );
			}

			if ( '' !== $block ) {
				$blocks[] = $block;
			}
		}

		$flush_buttons();

		return $blocks;
	}

	/**
	 * Convert one element into block markup.
	 *
	 * @param \DOMElement $el
	 * @return string
	 */
	private function convert_element( $el ) {
		// Icons, snippets and maps may be written on any element.
		switch ( trim( $el->getAttribute( 'data-block' ) ) ) {
			case 'icon':
				return $this->icon_block( $el );
			case 'html':
				return $this->snippet_block( $el );
			case 'map':
				return $this->map_block( $el );
		}

		switch ( $el->nodeName ) {
			case 'section':
			case 'article':
			case 'main':
				// A section whose first image is marked as background becomes
				// a native cover block — the image crops to the section
				// instead of rendering at its full (possibly huge) size.
				$bg = $this->find_background_image( $el );
				if ( $bg ) {
					return $this->cover_block( $el, $bg );
				}
				return $this->group_block( $el, true );

			case 'header':
			case 'footer':
			case 'nav':
				// The theme renders the site header/nav/footer — an AI-drawn
				// one would duplicate them. Dropped defensively (the prompt
				// forbids them too).
				return '';

			case 'div':
			case 'aside':
				switch ( trim( $el->getAttribute( 'data-block' ) ) ) {
					case 'portfolio':
						// Placeholder for the WPZOOM Portfolio grid block.
						return \WP_Block_Type_Registry::get_instance()->is_registered( 'wpzoom-blocks/portfolio' )
							? '<!-- wp:wpzoom-blocks/portfolio /-->'
							: '';
					case 'recent-posts':
						return $this->recent_posts_block( $el );
					case 'social':
						return self::social_links_markup( explode( ',', (string) $el->getAttribute( 'data-networks' ) ) );
					case 'gallery':
						return $this->gallery_block( $el );
					case 'contact-form':
						return $this->contact_form_block();
				}
				// ai-cols-N wrappers become native columns blocks.
				if ( preg_match( '/\bai-cols-([2-4])\b/', $el->getAttribute( 'class' ), $m ) ) {
					return $this->columns_block( $el, (int) $m[1] );
				}
				$bg = $this->find_background_image( $el );
				if ( $bg ) {
					// A photo tile in a grid or column stays inside its cell.
					return $this->cover_block( $el, $bg, false );
				}
				return $this->group_block( $el, false );

			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				return $this->heading_block( $el );

			case 'p':
				return $this->paragraph_block( $el );

			case 'span':
			case 'strong':
			case 'em':
			case 'b':
			case 'i':
			case 'mark':
				// Stray block-level inline element (kickers, big stat
				// numbers) — wrap as a paragraph, keeping the class.
				return $this->paragraph_block( $el, $this->classes( $el ) );

			case 'img':
				return $this->image_block( $el, '' );

			case 'figure':
				return $this->figure_block( $el );

			case 'ul':
			case 'ol':
				return $this->list_block( $el );

			case 'blockquote':
				return $this->quote_block( $el );

			case 'details':
				return $this->details_block( $el );

			case 'hr':
				// Classes preserved: .ai-rule hairline dividers are a core
				// editorial device in the design system.
				$hr_classes = $this->classes( $el );
				$hr_attrs   = $this->with_element_styles( $hr_classes ? array( 'className' => $hr_classes ) : array(), $el );
				return sprintf(
					"<!-- wp:separator%s -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity%s\"/>\n<!-- /wp:separator -->",
					$hr_attrs ? ' ' . serialize_block_attributes( $hr_attrs ) : '',
					( $hr_classes ? ' ' . esc_attr( $hr_classes ) : '' ) . $this->css_class( $hr_attrs )
				);

			case 'a':
				// Non-button block-level link — paragraph wrapping it.
				return sprintf(
					"<!-- wp:paragraph -->\n<p><a href=\"%s\">%s</a></p>\n<!-- /wp:paragraph -->",
					esc_url( $this->resolve_href( $el->getAttribute( 'href' ) ) ),
					$this->inline_html( $el )
				);

			case 'style':
			case 'script':
				return '';

			default:
				// A hand-drawn <svg> icon becomes the same editable placeholder
				// icon as data-block='icon' where the site has the Icon block.
				if ( 'svg' === $el->nodeName && '' !== $this->options['icon'] ) {
					return $this->icon_block( $el );
				}
				// Anything exotic — passthrough as a custom HTML block.
				return $this->html_block( $el );
		}
	}

	/* ---------------------------------------------------------------------
	 * Block builders
	 * ------------------------------------------------------------------ */

	private function group_block( $el, $full_width ) {
		// The converter adds the demo wrapper itself — if the AI also wrapped
		// the page in one, unwrap it instead of nesting (its children are
		// top-level sections).
		$classes = $this->classes( $el );
		if ( false !== strpos( ' ' . $classes . ' ', ' iss-ai-demo ' ) || false !== strpos( ' ' . $classes . ' ', ' ai-demo ' ) ) {
			return $this->convert_children( $el, true );
		}

		// data-layout: a native grid / row / stack (layout CSS is generated
		// by WordPress at render time, nothing goes into the saved markup).
		// Otherwise a CSS grid from the stylesheet is lifted into a native
		// one. Decided before the children convert: their spans depend on it.
		$layout = $this->group_layout( $el );
		if ( ! $layout ) {
			$layout = $this->lift_grid( $el );
		}

		$inner = $this->convert_children( $el );
		if ( '' === trim( $inner ) ) {
			return '';
		}

		$attrs = array( 'layout' => $layout ? $layout['layout'] : array( 'type' => 'default' ) );
		if ( $layout && '' !== $layout['gap'] ) {
			$attrs['style'] = array( 'spacing' => array( 'blockGap' => $layout['gap'] ) );
		}
		$class = 'wp-block-group';
		$style = '';

		// data-bg / data-text become native block colors — editable in the
		// block UI, and inline styles that no theme rule can override.
		$bg   = sanitize_hex_color( trim( $el->getAttribute( 'data-bg' ) ) );
		$text = sanitize_hex_color( trim( $el->getAttribute( 'data-text' ) ) );

		// Full-bleed is earned, not default: only sections with a painted
		// background (solid via data-bg, or gradient/photo declared via
		// data-full / an ai-full class) span the viewport. Everything else
		// gets no alignment and is centered at the content width by the
		// theme — plain content should sit in the main column.
		$is_full = $full_width && (
			$bg
			|| '1' === trim( $el->getAttribute( 'data-full' ) )
			|| false !== strpos( ' ' . $classes . ' ', ' ai-full ' )
		);

		if ( $is_full ) {
			$attrs = array( 'align' => 'full' ) + $attrs;
			$class .= ' alignfull';
		}
		if ( $classes ) {
			$attrs['className'] = $classes;
			$class             .= ' ' . $classes;
		}

		// A painted section must always declare a text color: the demo CSS's
		// contrast safety net keys off .has-text-color, so when the AI omits
		// data-text we derive black/white from the background's luminance.
		if ( $bg && ! $text ) {
			$hex = ltrim( $bg, '#' );
			if ( 3 === strlen( $hex ) ) {
				$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
			}
			$rgb  = sscanf( $hex, '%02x%02x%02x' );
			$luma = ( 299 * $rgb[0] + 587 * $rgb[1] + 114 * $rgb[2] ) / 1000;
			$text = $luma < 128 ? '#ffffff' : '#111111';
		}

		if ( $bg || $text ) {
			$attrs['style']['color'] = array();
			if ( $bg ) {
				$attrs['style']['color']['background'] = $bg;
				$class .= ' has-background';
				$style .= 'background-color:' . $bg . ';';
			}
			if ( $text ) {
				$attrs['style']['color']['text'] = $text;
				$class .= ' has-text-color';
				$style .= 'color:' . $text . ';';
			}
		}

		$attrs  = $this->with_element_styles( $attrs, $el );
		$class .= $this->css_class( $attrs );

		return sprintf(
			"<!-- wp:group %s -->\n<div class=\"%s\"%s>%s</div>\n<!-- /wp:group -->",
			serialize_block_attributes( $attrs ),
			esc_attr( $class ),
			$style ? ' style="' . esc_attr( rtrim( $style, ';' ) ) . '"' : '',
			"\n" . $inner . "\n"
		);
	}

	private function heading_block( $el ) {
		$level   = (int) substr( $el->nodeName, 1 );
		$classes = $this->classes( $el );
		$attrs   = array();
		$class   = 'wp-block-heading';

		if ( 2 !== $level ) {
			$attrs['level'] = $level;
		}
		if ( $classes ) {
			$attrs['className'] = $classes;
			$class             .= ' ' . $classes;
		}
		$attrs  = $this->with_element_styles( $attrs, $el );
		$class .= $this->css_class( $attrs );

		// Headings up to h3 are large text (WCAG 3:1).
		$fix = $this->contrast_fix( $el, $level <= 3 );
		if ( '' !== $fix ) {
			$attrs['style']['color']['text'] = $fix;
			$class                          .= ' has-text-color';
		}

		return sprintf(
			"<!-- wp:heading%s -->\n<h%d class=\"%s\"%s>%s</h%d>\n<!-- /wp:heading -->",
			$attrs ? ' ' . serialize_block_attributes( $attrs ) : '',
			$level,
			esc_attr( $class ),
			'' !== $fix ? ' style="color:' . esc_attr( $fix ) . '"' : '',
			$this->inline_html( $el ),
			$level
		);
	}

	private function paragraph_block( $el, $force_classes = '' ) {
		$classes = $force_classes ? $force_classes : $this->classes( $el );
		$attrs   = $this->with_element_styles( $classes ? array( 'className' => $classes ) : array(), $el );
		$class   = trim( $classes . $this->css_class( $attrs ) );
		$inner   = $this->inline_html( $el );

		if ( '' === trim( wp_strip_all_tags( $inner ) ) ) {
			return '';
		}

		$fix = $this->contrast_fix( $el );
		if ( '' !== $fix ) {
			$attrs['style']['color']['text'] = $fix;
			$class                           = trim( $class . ' has-text-color' );
		}

		return sprintf(
			"<!-- wp:paragraph%s -->\n<p%s%s>%s</p>\n<!-- /wp:paragraph -->",
			$attrs ? ' ' . serialize_block_attributes( $attrs ) : '',
			$class ? ' class="' . esc_attr( $class ) . '"' : '',
			'' !== $fix ? ' style="color:' . esc_attr( $fix ) . '"' : '',
			$inner
		);
	}

	/**
	 * <div data-block='recent-posts' data-count='3'> → native Query Loop grid
	 * of the site's real posts (featured image, date, title, excerpt). Fully
	 * dynamic: it always shows whatever the user publishes later.
	 *
	 * @param \DOMElement $el
	 * @return string
	 */
	private function recent_posts_block( $el ) {
		$count   = (int) $el->getAttribute( 'data-count' );
		$count   = max( 2, min( 6, $count ? $count : 3 ) );
		$columns = min( 3, $count );

		$query_attrs = array(
			'query' => array(
				'perPage'  => $count,
				'pages'    => 0,
				'offset'   => 0,
				'postType' => 'post',
				'order'    => 'desc',
				'orderBy'  => 'date',
				'inherit'  => false,
			),
		);

		return '<!-- wp:query ' . wp_json_encode( $query_attrs ) . " -->\n"
			. '<div class="wp-block-query">'
			. '<!-- wp:post-template {"layout":{"type":"grid","columnCount":' . $columns . '}} -->' . "\n"
			. '<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"3/2"} /-->' . "\n"
			. '<!-- wp:post-date {"isLink":false,"fontSize":"small"} /-->' . "\n"
			. '<!-- wp:post-title {"level":3,"isLink":true} /-->' . "\n"
			. '<!-- wp:post-excerpt {"moreText":"","excerptLength":18} /-->' . "\n"
			. "<!-- /wp:post-template -->\n"
			. "<!-- wp:query-no-results -->\n"
			. '<!-- wp:paragraph --><p>' . esc_html__( 'Fresh posts are coming soon — check back shortly.', 'inspiro-starter-sites' ) . "</p><!-- /wp:paragraph -->\n"
			. "<!-- /wp:query-no-results --></div>\n"
			. '<!-- /wp:query -->';
	}

	/**
	 * Native icon block (core/icon, WordPress 7.0+). It always shows the
	 * placeholder icon — the AI decides where icons go and how they look,
	 * never which glyph; the user swaps each one in the editor. Everything
	 * is a native attribute: data-size (glyph size), data-color (icon color),
	 * data-bg + data-radius + data-padding (a tinted badge), data-align.
	 * Dynamic block: the comment is the whole saved markup, so there is
	 * nothing for the editor to invalidate.
	 *
	 * @param \DOMElement $el
	 * @return string '' when the site has no icon block.
	 */
	private function icon_block( $el ) {
		if ( '' === $this->options['icon'] ) {
			return '';
		}

		$size    = $this->css_length( $el->getAttribute( 'data-size' ), array( 'px' => array( 12, 200 ), 'rem' => array( 0.75, 12 ), 'em' => array( 0.75, 12 ) ) );
		$size    = '' !== $size ? $size : '40px';
		$padding = $this->css_length( $el->getAttribute( 'data-padding' ), array( 'px' => array( 0, 80 ), 'rem' => array( 0, 5 ), 'em' => array( 0, 5 ) ) );

		// The block's width includes its padding (core styles the svg with
		// box-sizing: border-box), while data-size is the glyph itself — a
		// 28px icon in a 14px-padded badge is a 56px block, not a 0px glyph.
		$width = $size;
		if ( '' !== $padding && '0' !== $padding ) {
			preg_match( '/^([\d.]+)(\D+)$/', $size, $s );
			preg_match( '/^([\d.]+)(\D+)$/', $padding, $p );
			$width = $s[2] === $p[2]
				? ( (float) $s[1] + 2 * (float) $p[1] ) . $s[2]
				: 'calc(' . $size . ' + ' . $padding . ' * 2)';
		}

		$attrs = array(
			'icon'  => $this->options['icon'],
			'style' => array( 'dimensions' => array( 'width' => $width ) ),
		);

		$color = sanitize_hex_color( trim( $el->getAttribute( 'data-color' ) ) );
		$bg    = sanitize_hex_color( trim( $el->getAttribute( 'data-bg' ) ) );
		if ( $color ) {
			$attrs['style']['color']['text'] = $color;
		}
		if ( $bg ) {
			$attrs['style']['color']['background'] = $bg;
		}

		$radius = $this->css_length( $el->getAttribute( 'data-radius' ), array( 'px' => array( 0, 999 ), '%' => array( 0, 50 ), 'rem' => array( 0, 10 ) ) );
		if ( '' !== $radius ) {
			$attrs['style']['border']['radius'] = $radius;
		}

		if ( '' !== $padding ) {
			$attrs['style']['spacing']['padding'] = array(
				'top'    => $padding,
				'right'  => $padding,
				'bottom' => $padding,
				'left'   => $padding,
			);
		}

		$align = strtolower( trim( $el->getAttribute( 'data-align' ) ) );
		if ( ! in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
			// No explicit alignment: follow the text around it.
			$align = $this->context_alignment( $el );
		}
		if ( '' !== $align ) {
			$attrs['align'] = $align;
		}

		$classes = $this->classes( $el );
		if ( $classes ) {
			$attrs['className'] = $classes;
		}

		return '<!-- wp:icon ' . serialize_block_attributes( $this->with_element_styles( $attrs, $el ) ) . ' /-->';
	}

	/**
	 * Native social icon buttons for a list of network slugs. Shared with the
	 * footer-widget builder (hence static). Demo URLs point at the networks'
	 * home pages so the icons are functional-looking but never a dead 404.
	 *
	 * @param string[] $networks Requested network slugs.
	 * @return string '' when nothing valid was requested.
	 */
	public static function social_links_markup( array $networks ) {
		$whitelist = array(
			'instagram' => 'https://instagram.com',
			'facebook'  => 'https://facebook.com',
			'x'         => 'https://x.com',
			'twitter'   => 'https://x.com',
			'youtube'   => 'https://youtube.com',
			'linkedin'  => 'https://linkedin.com',
			'tiktok'    => 'https://tiktok.com',
			'pinterest' => 'https://pinterest.com',
			'vimeo'     => 'https://vimeo.com',
		);

		$items = array();
		$seen  = array();
		foreach ( $networks as $network ) {
			$network = strtolower( trim( (string) $network ) );
			$service = 'twitter' === $network ? 'x' : $network;
			if ( ! isset( $whitelist[ $network ] ) || isset( $seen[ $service ] ) ) {
				continue;
			}
			$seen[ $service ] = true;
			$items[]          = sprintf(
				'<!-- wp:social-link {"url":"%s","service":"%s"} /-->',
				esc_url( $whitelist[ $network ] ),
				esc_attr( $service )
			);
			if ( count( $items ) >= 5 ) {
				break;
			}
		}

		if ( ! $items ) {
			return '';
		}

		// currentColor makes the icons inherit the surrounding text color —
		// light in the theme's dark footer, dark on light content pages.
		// Without an icon color, logos-only falls back to BRAND colors and
		// the X logo (black) disappears on the dark footer. Core colors each
		// icon from iconColorValue; the list itself gets no style — save()
		// never writes one, so it would fail block validation.
		return '<!-- wp:social-links {"iconColorValue":"currentColor","className":"is-style-logos-only","style":{"spacing":{"blockGap":{"left":"18px"}}}} -->' . "\n"
			. '<ul class="wp-block-social-links has-icon-color is-style-logos-only">' . implode( '', $items ) . "</ul>\n"
			. '<!-- /wp:social-links -->';
	}

	/**
	 * <div data-block='gallery'><img data-query='...'>…</div> → native
	 * gallery block of sideloaded photos (3-column, cropped).
	 *
	 * @param \DOMElement $el
	 * @return string
	 */
	private function gallery_block( $el ) {
		$images = array();

		foreach ( $el->getElementsByTagName( 'img' ) as $img ) {
			if ( count( $images ) >= 8 ) {
				break;
			}
			$query = trim( $img->getAttribute( 'data-query' ) );
			if ( '' === $query ) {
				continue;
			}
			$image = call_user_func( $this->image_resolver, $query, 'landscape' );
			if ( $image ) {
				$image['alt'] = trim( $img->getAttribute( 'alt' ) );
				$images[]     = $image;
			}
		}

		if ( count( $images ) < 2 ) {
			return '';
		}

		$columns = min( 3, count( $images ) );
		$inner   = '';
		foreach ( $images as $image ) {
			$inner .= sprintf(
				"<!-- wp:image {\"id\":%d,\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"%s\" alt=\"%s\" class=\"wp-image-%d\"/></figure>\n<!-- /wp:image -->\n\n",
				(int) $image['id'],
				esc_url( $image['url'] ),
				esc_attr( $image['alt'] ),
				(int) $image['id']
			);
		}

		$attrs = $this->with_element_styles(
			array(
				'columns' => $columns,
				'linkTo'  => 'none',
			),
			$el
		);

		return '<!-- wp:gallery ' . serialize_block_attributes( $attrs ) . ' -->' . "\n"
			. '<figure class="wp-block-gallery has-nested-images columns-' . $columns . ' is-cropped' . $this->css_class( $attrs ) . '">' . "\n"
			. trim( $inner ) . "\n"
			. "</figure>\n"
			. '<!-- /wp:gallery -->';
	}

	/**
	 * <div data-block='contact-form'> → the WPZOOM Forms block bound to the
	 * first published form (the plugin seeds an example form on activation).
	 * Empty when the plugin/form isn't available — the block REQUIRES a valid
	 * formId (a bare block renders an admin-facing error instead).
	 *
	 * @return string
	 */
	private function contact_form_block() {
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'wpzoom-forms/form-block' ) ) {
			return '';
		}

		$forms = get_posts(
			array(
				'post_type'   => 'wpzf-form',
				'post_status' => 'publish',
				'numberposts' => 1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'fields'      => 'ids',
			)
		);

		if ( ! $forms ) {
			return '';
		}

		// formId is declared as a STRING attribute (default '-1') — a numeric
		// value fails schema validation, gets dropped at render, and the
		// block falls back to 'form not found (ID: -1)'.
		return '<!-- wp:wpzoom-forms/form-block {"formId":"' . (int) $forms[0] . '"} /-->';
	}

	private function image_block( $el, $caption ) {
		$query       = trim( $el->getAttribute( 'data-query' ) );
		$orientation = trim( $el->getAttribute( 'data-orientation' ) );
		$alt         = trim( $el->getAttribute( 'alt' ) );
		$classes     = $this->classes( $el );

		if ( '' === $query ) {
			return '';
		}

		$image = call_user_func( $this->image_resolver, $query, $orientation ? $orientation : 'landscape' );
		if ( ! $image ) {
			return '';
		}

		$attrs = array(
			'id'       => (int) $image['id'],
			'sizeSlug' => 'full',
		);
		$class     = 'wp-block-image size-full';
		$img_style = '';

		// data-aspect crops the image to a fitting ratio via the native
		// image-block attributes — a raw portrait photo can never render
		// as a giant full-height column again.
		$aspect = $this->aspect_ratio( $el );
		if ( $aspect ) {
			$attrs['aspectRatio'] = $aspect;
			$attrs['scale']       = 'cover';
			$img_style            = ' style="aspect-ratio:' . esc_attr( $aspect ) . ';object-fit:cover"';
		}

		if ( $classes ) {
			$attrs['className'] = $classes;
			$class             .= ' ' . $classes;
		}
		$attrs  = $this->with_element_styles( $attrs, $el );
		$class .= $this->css_class( $attrs );

		$caption_html = '' !== $caption ? sprintf( '<figcaption class="wp-element-caption">%s</figcaption>', $caption ) : '';

		return sprintf(
			"<!-- wp:image %s -->\n<figure class=\"%s\"><img src=\"%s\" alt=\"%s\" class=\"wp-image-%d\"%s/>%s</figure>\n<!-- /wp:image -->",
			serialize_block_attributes( $attrs ),
			esc_attr( $class ),
			esc_url( $image['url'] ),
			esc_attr( $alt ),
			(int) $image['id'],
			$img_style,
			$caption_html
		);
	}

	/**
	 * Normalized aspect ratio from data-aspect ("16-9" or "16/9" → "16/9").
	 *
	 * @param \DOMElement $el
	 * @return string '' when absent/invalid.
	 */
	private function aspect_ratio( $el ) {
		$raw = trim( $el->getAttribute( 'data-aspect' ) );
		if ( preg_match( '/^(\d{1,2})[\/-](\d{1,2})$/', $raw, $m ) ) {
			return $m[1] . '/' . $m[2];
		}
		return '';
	}

	/**
	 * First element-child image marked as a section background.
	 *
	 * @param \DOMElement $el
	 * @return \DOMElement|null
	 */
	private function find_background_image( $el ) {
		foreach ( $el->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}
			if ( 'img' === $child->nodeName && 'background' === trim( $child->getAttribute( 'data-role' ) ) ) {
				return $child;
			}
		}
		return null;
	}

	/**
	 * Native cover block: the marked image becomes a cropped background, the
	 * section's remaining children the inner content. Sections are full-bleed
	 * (a photo background is a painted background); nested tiles are not —
	 * alignfull inside a grid cell or column would fight the theme's
	 * full-width rules.
	 *
	 * @param \DOMElement $el   Section element.
	 * @param \DOMElement $bg   Background <img data-role="background">.
	 * @param bool        $full Span the viewport.
	 * @return string
	 */
	private function cover_block( $el, $bg, $full = true ) {
		$query = trim( $bg->getAttribute( 'data-query' ) );
		$image = $query ? call_user_func( $this->image_resolver, $query, trim( $bg->getAttribute( 'data-orientation' ) ) ? trim( $bg->getAttribute( 'data-orientation' ) ) : 'landscape' ) : null;

		$dim = (int) $bg->getAttribute( 'data-dim' );
		$dim = ( $dim >= 0 && $dim <= 90 ) ? (int) ( round( $dim / 10 ) * 10 ) : 40;

		// A brand-colored overlay (duotone wash) instead of black.
		$overlay = sanitize_hex_color( trim( $bg->getAttribute( 'data-overlay' ) ) );
		$overlay = $overlay ? $overlay : '#000000';

		// Remove the background image before converting the inner content —
		// leaving a note of what the content sits on for the cascade.
		$el->removeChild( $bg );

		if ( ! $image ) {
			return $this->group_block( $el, $full );
		}

		$el->setAttribute( 'data-iss-cover', '1' );
		$el->setAttribute( 'data-iss-cover-dim', (string) $dim );
		$el->setAttribute( 'data-iss-cover-overlay', $overlay );

		$inner = $this->convert_children( $el );

		$classes = $this->classes( $el );
		$attrs   = array(
			'url'                => $image['url'],
			'id'                 => (int) $image['id'],
			'dimRatio'           => $dim,
			'customOverlayColor' => $overlay,
			'isUserOverlayColor' => true,
			'layout'             => array( 'type' => 'default' ),
		);
		$class = 'wp-block-cover';
		if ( $full ) {
			$attrs['align'] = 'full';
			$class         .= ' alignfull';
		}
		$style = '';

		// Native height: stays editable in the block UI and beats the
		// stylesheet, so a requested full-screen hero is always full-screen.
		$height = $this->min_height( $el );
		if ( $height ) {
			$attrs['minHeight']     = $height[0];
			$attrs['minHeightUnit'] = $height[1];
			$style                  = ' style="min-height:' . $height[0] . $height[1] . '"';
		}

		$position = $this->content_position( $el );
		if ( $position ) {
			$attrs['contentPosition'] = $position;
			$class                   .= ' has-custom-content-position is-position-' . str_replace( ' ', '-', $position );
		}

		$alt = trim( $bg->getAttribute( 'alt' ) );
		if ( '' !== $alt ) {
			$attrs['alt'] = $alt;
		}

		if ( $classes ) {
			$attrs['className'] = $classes;
			$class             .= ' ' . $classes;
		}
		$attrs  = $this->with_element_styles( $attrs, $el );
		$class .= $this->css_class( $attrs );

		// Core's save() omits the dim class for its default ratio (50).
		$dim_class = 50 === $dim ? '' : ' has-background-dim-' . $dim;

		return sprintf(
			"<!-- wp:cover %s -->\n<div class=\"%s\"%s><span aria-hidden=\"true\" class=\"wp-block-cover__background%s has-background-dim\" style=\"background-color:%s\"></span><img class=\"wp-block-cover__image-background wp-image-%d\" alt=\"%s\" src=\"%s\" data-object-fit=\"cover\"/><div class=\"wp-block-cover__inner-container\">%s</div></div>\n<!-- /wp:cover -->",
			serialize_block_attributes( $attrs ),
			esc_attr( $class ),
			$style,
			$dim_class,
			esc_attr( $overlay ),
			(int) $image['id'],
			esc_attr( $alt ),
			esc_url( $image['url'] ),
			"\n" . $inner . "\n"
		);
	}

	/**
	 * Native columns blocks from an ai-cols-N wrapper. Children are CHUNKED
	 * into rows of N — nine cards in an ai-cols-3 become three stacked
	 * columns blocks of three, never one nine-column row.
	 *
	 * @param \DOMElement $el
	 * @param int         $per_row Columns per row (2-4).
	 * @return string
	 */
	private function columns_block( $el, $per_row = 3 ) {
		$per_row = max( 2, min( 4, (int) $per_row ) );
		$columns = array();

		foreach ( iterator_to_array( $el->childNodes ) as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}
			// A special element (map, contact form, gallery…) as a column keeps
			// its block; a plain div is the column's content group.
			$inner = ( ( 'div' === $child->nodeName || 'aside' === $child->nodeName ) && '' === trim( $child->getAttribute( 'data-block' ) ) )
				? $this->group_block( $child, false )
				: $this->convert_element( $child );

			if ( '' !== $inner ) {
				// data-width → native column width (asymmetric splits).
				$width     = $this->column_width( $child );
				$columns[] = sprintf(
					"<!-- wp:column%s -->\n<div class=\"wp-block-column\"%s>%s</div>\n<!-- /wp:column -->",
					$width ? ' ' . serialize_block_attributes( array( 'width' => $width ) ) : '',
					$width ? ' style="flex-basis:' . esc_attr( $width ) . '"' : '',
					"\n" . $inner . "\n"
				);
			}
		}

		if ( ! $columns ) {
			return '';
		}

		// Strip the grid utility classes — core columns handle the layout.
		$classes = trim( preg_replace( '/\bai-(grid|cols-\d)\b/', '', $this->classes( $el ) ) );
		$classes = preg_replace( '/\s+/', ' ', $classes );

		$attrs = array();
		$class = 'wp-block-columns';

		$valign = strtolower( trim( $el->getAttribute( 'data-valign' ) ) );
		if ( in_array( $valign, array( 'top', 'center', 'bottom' ), true ) ) {
			$attrs['verticalAlignment'] = $valign;
			$class                     .= ' are-vertically-aligned-' . $valign;
		}

		if ( $classes ) {
			$attrs['className'] = $classes;
			$class             .= ' ' . $classes;
		}
		$attrs  = $this->with_element_styles( $attrs, $el );
		$class .= $this->css_class( $attrs );

		$rows = array();
		foreach ( array_chunk( $columns, $per_row ) as $chunk ) {
			$rows[] = sprintf(
				"<!-- wp:columns%s -->\n<div class=\"%s\">%s</div>\n<!-- /wp:columns -->",
				$attrs ? ' ' . serialize_block_attributes( $attrs ) : '',
				esc_attr( $class ),
				"\n" . implode( "\n\n", $chunk ) . "\n"
			);
		}

		return implode( "\n\n", $rows );
	}

	private function figure_block( $el ) {
		$img     = null;
		$caption = '';

		foreach ( $el->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}
			if ( 'img' === $child->nodeName ) {
				$img = $child;
			} elseif ( 'figcaption' === $child->nodeName ) {
				$caption = $this->inline_html( $child );
			}
		}

		if ( ! $img ) {
			return $this->html_block( $el );
		}

		// Carry the figure's classes onto the image block.
		$figure_classes = $this->classes( $el );
		if ( $figure_classes && ! $img->getAttribute( 'class' ) ) {
			$img->setAttribute( 'class', $figure_classes );
		}
		foreach ( array( 'data-css', 'data-col-span', 'data-row-span', 'data-grow', 'data-basis' ) as $name ) {
			if ( $el->hasAttribute( $name ) && ! $img->hasAttribute( $name ) ) {
				$img->setAttribute( $name, $el->getAttribute( $name ) );
			}
		}

		return $this->image_block( $img, $caption );
	}

	private function buttons_block( array $anchors ) {
		// Native wp:button blocks, colored via the plan's brand tokens as
		// block-level attributes (inline styles beat the theme's
		// .wp-block-button__link defaults, and the colors are editable in
		// the block UI). No custom className: the AI stylesheet must never
		// style buttons, or the theme/AI double-styling returns.
		$accent = $this->brand['accent'];
		$text   = $this->brand['accent_text'];
		$radius = $this->brand['radius'];

		// The container the buttons sit in: its text alignment and surface.
		$context = $anchors[0]->parentNode instanceof \DOMElement ? $anchors[0]->parentNode : null;
		$justify = $context ? $this->context_alignment( $anchors[0] ) : '';
		$surface = ( $context && $this->cascade ) ? $this->cascade->background( $context ) : null;

		// Brand buttons must stay visible on the surface: a filled accent
		// button on an accent-coloured tile swaps to its light variant, an
		// outline button whose accent label would vanish takes a readable ink.
		$outline_ink = $accent;
		if ( $surface ) {
			$accent_rgba = CssCascade::parse_color( $accent );
			if ( $accent_rgba && CssCascade::contrast( $accent_rgba, $surface ) < 1.6 ) {
				list( $accent, $text ) = array( $text, $accent );
			}
			if ( $accent_rgba && CssCascade::contrast( $accent_rgba, $surface ) < 3 ) {
				$outline_ink = $this->readable_on( $surface );
			}
		}

		$buttons = array();

		foreach ( $anchors as $a ) {
			$is_outline = false !== strpos( $a->getAttribute( 'class' ), 'outline' );
			$href       = esc_url( $this->resolve_href( $a->getAttribute( 'href' ) ) );
			$label      = $this->inline_html( $a );

			if ( $is_outline ) {
				$attrs = array(
					'className' => 'is-style-outline',
					'style'     => array(
						'border' => array( 'radius' => $radius ),
						'color'  => array( 'text' => $outline_ink ),
					),
				);
				$buttons[] = sprintf(
					"<!-- wp:button %s -->\n<div class=\"wp-block-button is-style-outline\"><a class=\"wp-block-button__link has-text-color wp-element-button\" style=\"border-radius:%s;color:%s\" href=\"%s\">%s</a></div>\n<!-- /wp:button -->",
					wp_json_encode( $attrs ),
					esc_attr( $radius ),
					esc_attr( $outline_ink ),
					$href,
					$label
				);
			} else {
				$attrs = array(
					'style' => array(
						'border' => array( 'radius' => $radius ),
						'color'  => array(
							'background' => $accent,
							'text'       => $text,
						),
					),
				);
				$buttons[] = sprintf(
					"<!-- wp:button %s -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link has-text-color has-background wp-element-button\" style=\"border-radius:%s;color:%s;background-color:%s\" href=\"%s\">%s</a></div>\n<!-- /wp:button -->",
					wp_json_encode( $attrs ),
					esc_attr( $radius ),
					esc_attr( $text ),
					esc_attr( $accent ),
					$href,
					$label
				);
			}
		}

		// Centred or right-aligned text around the buttons: justify the row
		// the same way (a Buttons block is a flex row — text-align does not
		// move it). The classes are rendered by WordPress, not saved.
		return sprintf(
			"<!-- wp:buttons%s -->\n<div class=\"wp-block-buttons\">%s</div>\n<!-- /wp:buttons -->",
			'' !== $justify ? ' ' . serialize_block_attributes( array( 'layout' => array( 'type' => 'flex', 'justifyContent' => $justify ) ) ) : '',
			implode( "\n\n", $buttons )
		);
	}

	private function list_block( $el ) {
		$ordered = 'ol' === $el->nodeName;
		$items   = array();

		foreach ( $el->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType && 'li' === $child->nodeName ) {
				$items[] = sprintf( "<!-- wp:list-item -->\n<li>%s</li>\n<!-- /wp:list-item -->", $this->inline_html( $child ) );
			}
		}

		if ( ! $items ) {
			return '';
		}

		$tag       = $ordered ? 'ol' : 'ul';
		$attrs     = $this->with_element_styles( $ordered ? array( 'ordered' => true ) : array(), $el );
		$css_class = $this->css_class( $attrs );

		// Items share one colour: check the first one.
		$first = null;
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'li' === $child->nodeName ) {
				$first = $child;
				break;
			}
		}
		$fix = $first ? $this->contrast_fix( $first ) : '';
		if ( '' !== $fix ) {
			$attrs['style']['color']['text'] = $fix;
			$css_class                      .= ' has-text-color';
		}

		return sprintf(
			"<!-- wp:list%s -->\n<%s class=\"wp-block-list%s\"%s>%s</%s>\n<!-- /wp:list -->",
			$attrs ? ' ' . serialize_block_attributes( $attrs ) : '',
			$tag,
			$css_class,
			'' !== $fix ? ' style="color:' . esc_attr( $fix ) . '"' : '',
			"\n" . implode( "\n\n", $items ) . "\n",
			$tag
		);
	}

	private function quote_block( $el ) {
		$cite  = '';
		$paras = array();

		foreach ( $el->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				$text = trim( $child->textContent );
				if ( '' !== $text ) {
					$paras[] = $this->quote_paragraph( esc_html( $text ), $this->contrast_fix( $el ) );
				}
				continue;
			}
			if ( 'cite' === $child->nodeName || 'footer' === $child->nodeName ) {
				$cite = sprintf( '<cite>%s</cite>', $this->inline_html( $child ) );
			} elseif ( 'p' === $child->nodeName ) {
				$paras[] = $this->quote_paragraph( $this->inline_html( $child ), $this->contrast_fix( $child ) );
			}
		}

		if ( ! $paras ) {
			return '';
		}

		$attrs = $this->with_element_styles( array(), $el );

		return sprintf(
			"<!-- wp:quote%s -->\n<blockquote class=\"wp-block-quote%s\">%s%s</blockquote>\n<!-- /wp:quote -->",
			$attrs ? ' ' . serialize_block_attributes( $attrs ) : '',
			$this->css_class( $attrs ),
			implode( "\n\n", $paras ),
			$cite
		);
	}

	/**
	 * A paragraph inside a quote, with a native colour when its text would
	 * fail contrast (quote paragraphs pick up the stylesheet's generic p
	 * colour even when the quote itself was restyled for a dark tile).
	 *
	 * @param string $inner Inline HTML.
	 * @param string $fix   Text colour or ''.
	 * @return string
	 */
	private function quote_paragraph( $inner, $fix ) {
		if ( '' === $fix ) {
			return sprintf( "<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->", $inner );
		}
		return sprintf(
			"<!-- wp:paragraph %s -->\n<p class=\"has-text-color\" style=\"color:%s\">%s</p>\n<!-- /wp:paragraph -->",
			serialize_block_attributes( array( 'style' => array( 'color' => array( 'text' => $fix ) ) ) ),
			esc_attr( $fix ),
			$inner
		);
	}

	private function details_block( $el ) {
		$summary = '';
		$inner   = array();

		foreach ( iterator_to_array( $el->childNodes ) as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType && 'summary' === $child->nodeName ) {
				$summary = $this->inline_html( $child );
				continue;
			}
			if ( XML_ELEMENT_NODE === $child->nodeType && 'p' === $child->nodeName ) {
				$inner[] = sprintf( "<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->", $this->inline_html( $child ) );
			} elseif ( '' !== trim( $child->textContent ) ) {
				$inner[] = sprintf( "<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->", esc_html( trim( $child->textContent ) ) );
			}
		}

		return sprintf(
			"<!-- wp:details -->\n<details class=\"wp-block-details\"><summary>%s</summary>%s</details>\n<!-- /wp:details -->",
			$summary,
			implode( "\n\n", $inner )
		);
	}

	private function html_block( $el ) {
		// Internal links first: kses strips the unknown "page:" scheme.
		$html = trim( $this->kses_snippet( $this->resolve_page_hrefs( $el->ownerDocument->saveHTML( $el ) ) ) );
		if ( '' === $html ) {
			return '';
		}
		return sprintf( "<!-- wp:html -->\n%s\n<!-- /wp:html -->", $html );
	}

	/**
	 * <div data-block='html'> → a Custom HTML block for the rare element no
	 * native block expresses (a scrolling marquee, an animated strip). The
	 * markup is reduced to an allowlist (no scripts, iframes, forms or event
	 * handlers), its <style> is scoped to the demo and to ai- classes, and
	 * its <img data-query> photos resolve to real media like any image.
	 *
	 * @param \DOMElement $el
	 * @return string '' when snippets are not allowed or nothing is left.
	 */
	private function snippet_block( $el ) {
		if ( empty( $this->options['html'] ) ) {
			return '';
		}

		// Styles are handled apart: kses would print their text as content.
		$css = '';
		foreach ( iterator_to_array( $el->getElementsByTagName( 'style' ) ) as $style ) {
			$css .= $this->snippet_css( $style->textContent );
			$style->parentNode->removeChild( $style );
		}

		foreach ( iterator_to_array( $el->getElementsByTagName( 'img' ) ) as $img ) {
			$query       = trim( $img->getAttribute( 'data-query' ) );
			$orientation = trim( $img->getAttribute( 'data-orientation' ) );
			$image       = '' !== $query ? call_user_func( $this->image_resolver, $query, $orientation ? $orientation : 'landscape' ) : null;
			if ( ! $image ) {
				$img->parentNode->removeChild( $img );
				continue;
			}
			$img->setAttribute( 'src', $image['url'] );
			$img->setAttribute( 'loading', 'lazy' );
		}

		$html = '';
		foreach ( $el->childNodes as $child ) {
			$html .= $el->ownerDocument->saveHTML( $child );
		}
		$html = trim( $this->kses_snippet( $this->resolve_page_hrefs( $html ) ) );

		if ( '' === $html ) {
			return '';
		}

		return sprintf(
			"<!-- wp:html -->\n%s<div class=\"%s\">%s</div>\n<!-- /wp:html -->",
			'' !== $css ? '<style>' . $css . '</style>' : '',
			esc_attr( trim( 'iss-ai-html ' . $this->classes( $el ) ) ),
			$html
		);
	}

	/**
	 * <div data-block='map' data-address='…'> → a Custom HTML block with a
	 * map of that address. The AI never writes the iframe: it is built here
	 * from the address alone (Google Maps' keyless embed by default; the
	 * inspiro_starter_sites/ai_map_embed_url filter swaps the provider).
	 *
	 * @param \DOMElement $el
	 * @return string
	 */
	private function map_block( $el ) {
		if ( empty( $this->options['html'] ) ) {
			return '';
		}

		$address = mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $el->getAttribute( 'data-address' ) ) ) ), 0, 200 );
		if ( '' === $address ) {
			return '';
		}

		$zoom = (int) $el->getAttribute( 'data-zoom' );
		$zoom = ( $zoom >= 3 && $zoom <= 20 ) ? $zoom : 14;

		$height = 420;
		if ( preg_match( '/^(\d{3})(?:px)?$/', trim( $el->getAttribute( 'data-height' ) ), $m ) && (int) $m[1] >= 200 && (int) $m[1] <= 800 ) {
			$height = (int) $m[1];
		}

		$url = 'https://maps.google.com/maps?q=' . rawurlencode( $address ) . '&z=' . $zoom . '&output=embed';
		$url = (string) apply_filters( 'inspiro_starter_sites/ai_map_embed_url', $url, $address, $zoom );

		return sprintf(
			"<!-- wp:html -->\n<div class=\"%s\"><iframe src=\"%s\" title=\"%s\" width=\"100%%\" height=\"%d\" style=\"border:0;display:block;width:100%%\" loading=\"lazy\" referrerpolicy=\"no-referrer-when-downgrade\" allowfullscreen></iframe></div>\n<!-- /wp:html -->",
			esc_attr( trim( 'iss-ai-map ' . $this->classes( $el ) ) ),
			esc_url( $url ),
			/* translators: %s: address shown on the map */
			esc_attr( sprintf( __( 'Map of %s', 'inspiro-starter-sites' ), $address ) ),
			$height
		);
	}

	/**
	 * Custom HTML allowlist: structure, text, tables, links, images and
	 * inline SVG — with classes, ARIA and (safecss-filtered) inline styles.
	 * No scripts, iframes, forms, media players or data-/event attributes.
	 *
	 * @param string $html
	 * @return string
	 */
	private function kses_snippet( $html ) {
		$global = array(
			'class'       => true,
			'style'       => true,
			'title'       => true,
			'role'        => true,
			'aria-hidden' => true,
			'aria-label'  => true,
		);

		$tags = array();
		foreach ( array( 'div', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'strong', 'em', 'b', 'i', 'small', 'br', 'hr', 'mark', 'sup', 'sub', 'time', 'figure', 'figcaption', 'blockquote', 'cite', 'details', 'summary', 'table', 'caption', 'thead', 'tbody', 'tr', 'th', 'td' ) as $tag ) {
			$tags[ $tag ] = $global;
		}
		$tags['a']   = $global + array( 'href' => true );
		$tags['img'] = $global + array(
			'src'     => true,
			'alt'     => true,
			'width'   => true,
			'height'  => true,
			'loading' => true,
		);

		$svg = $global;
		foreach ( array( 'viewbox', 'xmlns', 'fill', 'fill-rule', 'clip-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'width', 'height', 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'points', 'transform', 'opacity' ) as $attr ) {
			$svg[ $attr ] = true;
		}
		foreach ( array( 'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon' ) as $tag ) {
			$tags[ $tag ] = $svg;
		}

		return wp_kses( $html, $tags, array( 'http', 'https', 'mailto', 'tel' ) );
	}

	/**
	 * A snippet's own stylesheet, made safe and local: @import and any
	 * declaration with url(), expression() or similar are dropped (the rest
	 * of the stylesheet stays); every rule scoped to the demo pages and to selectors that
	 * name an ai- class, so a snippet can never restyle the rest of the page;
	 * @media/@supports kept, @keyframes kept as-is, other at-rules dropped;
	 * position:fixed demoted to absolute.
	 *
	 * @param string $css
	 * @return string '' when nothing safe is left or braces don't balance.
	 */
	private function snippet_css( $css ) {
		$css = str_replace( array( '<', '\\' ), '', (string) $css );
		$css = preg_replace( '#/\*.*?\*/#s', '', $css );
		$css = preg_replace( '/@import[^;{}]*;?/i', '', $css );
		$css = preg_replace( '/[^;{}]*(?:url\s*\(|expression\s*\(|javascript:|behavior\s*:|-moz-binding)[^;{}]*;?/i', '', $css );
		$css = preg_replace( '/position\s*:\s*fixed/i', 'position:absolute', $css );

		$scoped = $this->scope_css( $css );
		return null === $scoped ? '' : $scoped;
	}

	/**
	 * @param string $css
	 * @return string|null Scoped rules, or null when braces don't balance.
	 */
	private function scope_css( $css ) {
		$out = '';
		$pos = 0;
		$len = strlen( $css );

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
			if ( $depth > 0 ) {
				return null;
			}

			$prelude = trim( substr( $css, $pos, $open - $pos ), " \t\n\r;" );
			$body    = substr( $css, $open + 1, $i - $open - 2 );
			$pos     = $i;

			if ( '' === $prelude ) {
				continue;
			}

			if ( '@' === $prelude[0] ) {
				if ( preg_match( '/^@(?:-webkit-)?keyframes\s+[\w-]+$/i', $prelude ) ) {
					$out .= $prelude . '{' . $body . '}';
				} elseif ( preg_match( '/^@(?:media|supports)\b/i', $prelude ) ) {
					$inner = $this->scope_css( $body );
					if ( null === $inner ) {
						return null;
					}
					if ( '' !== $inner ) {
						$out .= $prelude . '{' . $inner . '}';
					}
				}
				continue;
			}

			// Nested rules aren't supported; emptied rules aren't worth keeping.
			if ( false !== strpos( $body, '{' ) || '' === trim( $body, " 	

;" ) ) {
				continue;
			}

			$selectors = array();
			foreach ( explode( ',', $prelude ) as $selector ) {
				$selector = trim( preg_replace( '/^\.iss-ai-demo(?![\w-])\s*/', '', trim( $selector ) ) );
				if ( '' !== $selector && preg_match( '/\.ai-[\w-]+/', $selector ) ) {
					$selectors[] = '.iss-ai-demo ' . $selector;
				}
			}
			if ( $selectors ) {
				$out .= implode( ',', $selectors ) . '{' . $body . '}';
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Remove scripts, event handlers, and javascript: URLs from the DOM.
	 */
	private function sanitize_dom( \DOMDocument $doc ) {
		$xpath = new \DOMXPath( $doc );

		foreach ( array( 'script', 'iframe', 'object', 'embed', 'form', 'link', 'meta', 'base', 'style' ) as $tag ) {
			foreach ( iterator_to_array( $doc->getElementsByTagName( $tag ) ) as $node ) {
				// A Custom HTML snippet keeps its own <style>; snippet_block()
				// sanitizes and scopes it.
				if ( 'style' === $tag && ! empty( $this->options['html'] ) && $xpath->query( 'ancestor::*[@data-block="html"]', $node )->length ) {
					continue;
				}
				$node->parentNode->removeChild( $node );
			}
		}

		foreach ( $xpath->query( '//@*' ) as $attr ) {
			$name = strtolower( $attr->name );
			if ( 0 === strpos( $name, 'on' ) ) {
				$attr->ownerElement->removeAttribute( $attr->name );
			} elseif ( in_array( $name, array( 'href', 'src', 'xlink:href' ), true )
				&& preg_match( '/^\s*(javascript|data:text\/html|vbscript)/i', $attr->value ) ) {
				$attr->ownerElement->removeAttribute( $attr->name );
			}
		}
	}

	/**
	 * Inner HTML of an element with only inline formatting kept.
	 */
	private function inline_html( $el ) {
		$html = '';
		foreach ( $el->childNodes as $child ) {
			$html .= $el->ownerDocument->saveHTML( $child );
		}

		$html = wp_kses(
			$html,
			array(
				'strong' => array(),
				'b'      => array(),
				'em'     => array(),
				'i'      => array(),
				'br'     => array(),
				'span'   => array( 'class' => true ),
				'mark'   => array( 'class' => true ),
				'a'      => array(
					'href'  => true,
					'class' => true,
				),
				'sup'    => array(),
				'sub'    => array(),
			)
		);

		// Resolve internal #page: links that survived inside inline markup.
		return $this->resolve_page_hrefs( trim( $html ) );
	}

	/**
	 * Resolve internal href="#page:slug" links inside an HTML string.
	 *
	 * @param string $html
	 * @return string
	 */
	private function resolve_page_hrefs( $html ) {
		return preg_replace_callback(
			'/href="#page:([a-z0-9-]+)"/',
			function ( $m ) {
				return 'href="' . esc_url( $this->resolve_href( '#page:' . $m[1] ) ) . '"';
			},
			$html
		);
	}

	/**
	 * Add the element's per-block styles to its block attributes: data-css as
	 * custom CSS (style.css) and its place in a grid/flex parent
	 * (style.layout). Neither is written into the saved markup — WordPress
	 * renders both — apart from the has-custom-css class (see css_class()).
	 *
	 * @param array       $attrs Block attributes.
	 * @param \DOMElement $el    Source element.
	 * @return array
	 */
	private function with_element_styles( array $attrs, $el ) {
		$css   = $this->block_css( $el );
		$child = $this->child_layout( $el );
		if ( '' === $css && ! $child ) {
			return $attrs;
		}

		$attrs['style'] = isset( $attrs['style'] ) && is_array( $attrs['style'] ) ? $attrs['style'] : array();
		if ( '' !== $css ) {
			$attrs['style']['css'] = $css;
		}
		if ( $child ) {
			$attrs['style']['layout'] = $child;
		}

		return $attrs;
	}

	/**
	 * Native group layout from data-layout:
	 *   grid  — data-columns (Max. columns, 1-6) and data-min (Min. column
	 *           width). A minimum is always set: it is what makes the grid
	 *           responsive (fewer columns on small screens, and core un-spans
	 *           spanned children there); a bare column count never reflows.
	 *   flex  — a row (alias: row): data-justify, data-align, data-wrap.
	 *   stack — a vertical flex: data-justify, data-align.
	 * data-gap on any of them becomes the block gap.
	 *
	 * @param \DOMElement $el
	 * @return array|null [ 'layout' => array, 'gap' => string ] or null.
	 */
	private function group_layout( $el ) {
		$type = strtolower( trim( $el->getAttribute( 'data-layout' ) ) );
		if ( ! in_array( $type, array( 'grid', 'flex', 'row', 'stack' ), true ) ) {
			return null;
		}

		if ( 'grid' === $type ) {
			$columns = (int) $el->getAttribute( 'data-columns' );
			$minimum = $this->css_length( $el->getAttribute( 'data-min' ), array( 'rem' => array( 4, 40 ), 'em' => array( 4, 40 ), 'px' => array( 64, 640 ) ) );
			$layout  = array(
				'type'               => 'grid',
				'columnCount'        => max( 1, min( 6, $columns ? $columns : 3 ) ),
				'minimumColumnWidth' => '' !== $minimum ? $minimum : '200px',
			);
		} else {
			$vertical = 'stack' === $type;
			$layout   = array( 'type' => 'flex' );
			if ( $vertical ) {
				$layout['orientation'] = 'vertical';
			}

			$justify = strtolower( trim( $el->getAttribute( 'data-justify' ) ) );
			if ( in_array( $justify, $vertical ? array( 'left', 'center', 'right', 'stretch' ) : array( 'left', 'center', 'right', 'space-between' ), true ) ) {
				$layout['justifyContent'] = $justify;
			}

			$align = strtolower( trim( $el->getAttribute( 'data-align' ) ) );
			if ( in_array( $align, $vertical ? array( 'top', 'center', 'bottom', 'space-between' ) : array( 'top', 'center', 'bottom', 'stretch' ), true ) ) {
				$layout['verticalAlignment'] = $align;
			}

			// Rows wrap by default, so they never overflow a phone screen.
			if ( ! $vertical && 'nowrap' === strtolower( trim( $el->getAttribute( 'data-wrap' ) ) ) ) {
				$layout['flexWrap'] = 'nowrap';
			}
		}

		return array(
			'layout' => $layout,
			'gap'    => $this->css_length( $el->getAttribute( 'data-gap' ), array( 'rem' => array( 0, 8 ), 'em' => array( 0, 8 ), 'px' => array( 0, 128 ) ) ),
		);
	}

	/**
	 * An element's place in a grid/flex parent: data-col-span and
	 * data-row-span (2-6) for grid cells, data-grow='1' (fill the remaining
	 * space) or data-basis (fixed width) for flex children.
	 *
	 * @param \DOMElement $el
	 * @return array Child layout values (empty when none).
	 */
	private function child_layout( $el ) {
		$layout = array();

		$column_span = (int) $el->getAttribute( 'data-col-span' );
		$row_span    = (int) $el->getAttribute( 'data-row-span' );
		// In a grid lifted from the stylesheet, the stylesheet's placement
		// (grid-column / grid-row, or an uneven track list like 2fr 1fr)
		// becomes the native span. A figure's image stands in for the figure.
		$cell   = ( 'img' === $el->nodeName && $el->parentNode instanceof \DOMElement && 'figure' === $el->parentNode->nodeName ) ? $el->parentNode : $el;
		$parent = $cell->parentNode;
		if ( $this->cascade && $parent instanceof \DOMElement && isset( $this->lifted[ $this->cascade->key( $parent ) ] ) ) {
			$grid = $this->lifted[ $this->cascade->key( $parent ) ];
			if ( $column_span < 2 ) {
				$column_span = $this->grid_span( $this->cascade->value( $cell, 'grid-column' ), $this->cascade->value( $cell, 'grid-column-end' ), $grid['count'] );
				if ( $column_span < 2 && $grid['spans'] ) {
					$index = 0;
					for ( $s = $cell->previousSibling; $s; $s = $s->previousSibling ) {
						if ( $s instanceof \DOMElement ) {
							$index++;
						}
					}
					$column_span = $grid['spans'][ $index % count( $grid['spans'] ) ];
				}
			}
			if ( $row_span < 2 ) {
				$row_span = $this->grid_span( $this->cascade->value( $cell, 'grid-row' ), $this->cascade->value( $cell, 'grid-row-end' ), 0 );
			}
		}

		if ( $column_span >= 2 ) {
			$layout['columnSpan'] = min( 6, $column_span );
		}
		if ( $row_span >= 2 ) {
			$layout['rowSpan'] = min( 6, $row_span );
		}

		if ( '1' === trim( $el->getAttribute( 'data-grow' ) ) ) {
			$layout['selfStretch'] = 'fill';
		} else {
			$basis = $this->css_length( $el->getAttribute( 'data-basis' ), array( 'px' => array( 40, 960 ), 'rem' => array( 3, 60 ), '%' => array( 5, 95 ) ) );
			if ( '' !== $basis ) {
				$layout['selfStretch'] = 'fixed';
				$layout['flexSize']    = $basis;
			}
		}

		return $layout;
	}

	/**
	 * A CSS grid the stylesheet puts on this element, lifted into a native
	 * grid layout: fixed columns (repeat(3, 1fr), 1fr 1fr 1fr) become Max.
	 * columns with a minimum width, so the grid reflows on small screens;
	 * repeat(auto-fill, minmax(X, 1fr)) becomes a Min. column width; whole-
	 * number fr ratios (2fr 1fr) become a finer grid with spans. Anything
	 * else (fixed or fractional tracks) stays CSS.
	 *
	 * @param \DOMElement $el
	 * @return array|null [ 'layout' => array, 'gap' => string ] or null.
	 */
	private function lift_grid( $el ) {
		$classes = $this->classes( $el );
		if ( ! $this->cascade || '' === $classes ) {
			return null;
		}

		$display = strtolower( trim( (string) $this->cascade->value( $el, 'display' ) ) );
		if ( 'grid' !== $display && 'inline-grid' !== $display ) {
			return null;
		}

		$tracks = $this->grid_tracks( (string) $this->cascade->value( $el, 'grid-template-columns' ) );
		if ( ! $tracks ) {
			return null;
		}

		$layout = array( 'type' => 'grid' );
		if ( isset( $tracks['min'] ) ) {
			$layout['minimumColumnWidth'] = $tracks['min'];
		} else {
			$layout['columnCount']        = $tracks['count'];
			$layout['minimumColumnWidth'] = '200px';
		}

		// Native block gaps are plain lengths (no clamp()).
		$gap = '';
		foreach ( array( 'gap', 'grid-gap', 'column-gap' ) as $prop ) {
			$value = $this->cascade->value( $el, $prop );
			if ( null !== $value ) {
				$parts = preg_split( '/\s+/', trim( $value ) );
				$gap   = $this->css_length( $parts[0], array( 'px' => array( 0, 128 ), 'rem' => array( 0, 8 ), 'em' => array( 0, 8 ) ) );
				break;
			}
		}

		$this->lifted[ $this->cascade->key( $el ) ] = array(
			'count' => isset( $layout['columnCount'] ) ? $layout['columnCount'] : 0,
			'spans' => isset( $tracks['spans'] ) ? $tracks['spans'] : array(),
		);
		$this->lifted_classes = array_values( array_unique( array_merge( $this->lifted_classes, explode( ' ', $classes ) ) ) );

		return array(
			'layout' => $layout,
			'gap'    => $gap,
		);
	}

	/**
	 * Parse grid-template-columns into a native grid shape.
	 *
	 * @param string $value
	 * @return array|null [ 'count' => int, 'spans' => int[] ] or [ 'min' => length ].
	 */
	private function grid_tracks( $value ) {
		$value = strtolower( trim( $value ) );
		if ( '' === $value ) {
			return null;
		}

		if ( preg_match( '/^repeat\(\s*auto-(?:fill|fit)\s*,\s*minmax\(\s*(?:min\(\s*)?([\d.]+(?:px|rem|em))/', $value, $m ) ) {
			$min = $this->css_length( $m[1], array( 'px' => array( 64, 640 ), 'rem' => array( 4, 40 ), 'em' => array( 4, 40 ) ) );
			return '' !== $min ? array( 'min' => $min ) : null;
		}

		if ( preg_match( '/^repeat\(\s*(\d+)\s*,/', $value, $m ) ) {
			$count = (int) $m[1];
			return ( $count >= 2 && $count <= 6 ) ? array( 'count' => $count ) : null;
		}

		$tracks = preg_split( '/\s+(?![^()]*\))/', $value );
		$count  = count( $tracks );
		if ( $count < 2 || $count > 6 ) {
			return null;
		}
		if ( 1 === count( array_unique( $tracks ) ) ) {
			return array( 'count' => $count );
		}

		$units = array();
		foreach ( $tracks as $track ) {
			if ( ! preg_match( '/^(\d+(?:\.\d+)?)fr$/', $track, $m ) ) {
				return null;
			}
			$units[] = (float) $m[1];
		}

		$smallest = min( $units );
		$spans    = array();
		foreach ( $units as $unit ) {
			$ratio = $unit / $smallest;
			if ( abs( $ratio - round( $ratio ) ) > 0.15 ) {
				return null;
			}
			$spans[] = (int) round( $ratio );
		}

		return array_sum( $spans ) <= 6 ? array( 'count' => array_sum( $spans ), 'spans' => $spans ) : null;
	}

	/**
	 * A span from grid-column / grid-row ("span 2", "1 / 3", "2 / span 2",
	 * "1 / -1" with a known column count).
	 *
	 * @param string|null $value Shorthand value.
	 * @param string|null $end   The -end longhand's value.
	 * @param int         $count Column count for "/ -1" (0 = unknown).
	 * @return int 0 when none.
	 */
	private function grid_span( $value, $end, $count ) {
		foreach ( array( $value, $end ) as $v ) {
			if ( preg_match( '/span\s+(\d+)/', strtolower( (string) $v ), $m ) ) {
				return (int) $m[1];
			}
		}

		if ( preg_match( '/^\s*(\d+)\s*\/\s*(-?\d+)\s*$/', (string) $value, $m ) ) {
			$start = (int) $m[1];
			$stop  = (int) $m[2];
			if ( $stop < 0 ) {
				$stop = $count ? $count + 2 + $stop : 0;
			}
			if ( $start > 0 && $stop > $start ) {
				return $stop - $start;
			}
		}

		return 0;
	}

	/**
	 * A native text colour for an element whose text would fail contrast
	 * (WCAG AA) against the background it really sits on — the stylesheet's
	 * own colours stay wherever they read. The replacement is the page's ink
	 * or white, whichever reads better.
	 *
	 * @param \DOMElement $el
	 * @param bool        $large Large text (3:1) instead of body text (4.5:1).
	 * @return string Hex colour, or '' when readable or unknown.
	 */
	private function contrast_fix( $el, $large = false ) {
		if ( ! $this->cascade ) {
			return '';
		}

		$fg = $this->cascade->text_color( $el );
		$bg = $this->cascade->background( $el );
		if ( ! $fg || ! $bg || CssCascade::contrast( $fg, $bg ) >= ( $large ? 3 : 4.5 ) ) {
			return '';
		}

		return $this->readable_on( $bg, $el );
	}

	/**
	 * The page's ink or white — whichever reads better on a background.
	 *
	 * @param array            $bg RGBA.
	 * @param \DOMElement|null $el Any element of the page (for its ink).
	 * @return string Hex colour.
	 */
	private function readable_on( array $bg, $el = null ) {
		$ink  = '#111111';
		$body = $el ? $el->ownerDocument->getElementsByTagName( 'body' )->item( 0 ) : null;
		if ( $body && $this->cascade ) {
			$root = $this->cascade->text_color( $body );
			if ( $root && CssCascade::luminance( $root ) < 0.05 ) {
				$ink = CssCascade::hex( $root );
			}
		}

		$white = CssCascade::parse_color( '#ffffff' );
		$dark  = CssCascade::parse_color( $ink );

		return CssCascade::contrast( $white, $bg ) >= CssCascade::contrast( $dark, $bg ) ? '#ffffff' : $ink;
	}

	/**
	 * Horizontal alignment an element should follow from its container:
	 * 'center' or 'right' where the text around it is centred or right-
	 * aligned, '' otherwise — and always '' inside native row/stack/grid
	 * groups, which align their children themselves.
	 *
	 * @param \DOMElement $el
	 * @return string
	 */
	private function context_alignment( $el ) {
		$parent = $el->parentNode;
		if ( ! $this->cascade || ! $parent instanceof \DOMElement ) {
			return '';
		}
		if ( $this->group_layout( $parent ) || isset( $this->lifted[ $this->cascade->key( $parent ) ] ) ) {
			return '';
		}

		$align = strtolower( trim( (string) $this->cascade->computed( $parent, 'text-align' ) ) );
		if ( 'center' === $align ) {
			return 'center';
		}
		return in_array( $align, array( 'right', 'end' ), true ) ? 'right' : '';
	}

	/**
	 * A CSS length within per-unit bounds ("2rem", "240px", "30%"); a bare
	 * "0" is accepted when 0 is in range. rem/em come back as px: every
	 * length here becomes a native block attribute, which renders against the
	 * theme's root font size (10px in Inspiro Premium), while the AI writes
	 * rem for the usual 16px — a 12rem grid column would otherwise shrink to
	 * 120px and never collapse on phones.
	 *
	 * @param string $raw    Attribute value.
	 * @param array  $bounds Unit => [ min, max ] (checked in the unit given).
	 * @return string '' when absent/invalid.
	 */
	private function css_length( $raw, array $bounds ) {
		$raw = strtolower( trim( (string) $raw ) );
		if ( '0' === $raw ) {
			foreach ( $bounds as $range ) {
				if ( 0 >= $range[0] ) {
					return '0';
				}
			}
			return '';
		}

		if ( ! preg_match( '/^(\d{1,4}(?:\.\d{1,2})?)(px|rem|em|%)$/', $raw, $m ) || ! isset( $bounds[ $m[2] ] ) ) {
			return '';
		}

		$value = (float) $m[1];

		if ( $value < $bounds[ $m[2] ][0] || $value > $bounds[ $m[2] ][1] ) {
			return '';
		}

		return in_array( $m[2], array( 'rem', 'em' ), true ) ? round( $value * 16, 2 ) . 'px' : $m[1] . $m[2];
	}

	/**
	 * The class the editor's customCSS block support adds to a block's saved
	 * root element whenever style.css is set (addSaveProps). Without it in the
	 * markup, block validation fails: "Block contains unexpected or invalid
	 * content".
	 *
	 * @param array $attrs Block attributes.
	 * @return string ' has-custom-css' or ''.
	 */
	private function css_class( array $attrs ) {
		return isset( $attrs['style']['css'] ) && '' !== trim( (string) $attrs['style']['css'] ) ? ' has-custom-css' : '';
	}

	/**
	 * An element's data-css reduced to what core's block CSS parser
	 * (WP_Theme_JSON::process_blocks_custom_css) handles: the block's own
	 * declarations FIRST, then one-level "& selector { … }" rules. That
	 * parser splits on "&" and cannot nest, so anything else — at-rules,
	 * deeper nesting, declarations after a nested rule — is dropped here.
	 * url(), quotes, escapes and markup never pass.
	 *
	 * @param \DOMElement $el
	 * @return string '' when disabled or nothing valid remains.
	 */
	private function block_css( $el ) {
		if ( empty( $this->options['block_css'] ) || ! $el->hasAttribute( 'data-css' ) ) {
			return '';
		}

		$css = str_replace( array( '"', "'", '\\', '<' ), '', (string) $el->getAttribute( 'data-css' ) );
		if ( '' === trim( $css ) || preg_match( '/@|url\s*\(|expression\s*\(|javascript:|-moz-binding|behavior\s*:/i', $css ) ) {
			return '';
		}

		$rules = array();
		$css   = preg_replace_callback(
			'/&([^{}&]*)\{([^{}]*)\}/',
			function ( $m ) use ( &$rules ) {
				// A leading space makes a descendant selector (& img); none
				// appends to the block itself (&:hover, &.is-x).
				$selector = rtrim( preg_replace( '/\s+/', ' ', $m[1] ) );
				$body     = $this->css_declarations( $m[2] );
				if ( '' !== $body && '' !== trim( $selector ) && preg_match( '/^[\w\s\-.:#>+~*\[\]=(),]+$/', $selector ) ) {
					$rules[] = '&' . $selector . '{' . $body . '}';
				}
				return '';
			},
			$css
		);

		$own = $this->css_declarations( str_replace( array( '{', '}', '&' ), '', $css ) );

		$out = trim( $own . ' ' . implode( ' ', $rules ) );

		return strlen( $out ) <= 2000 ? $out : '';
	}

	/**
	 * Keep only well-formed "property: value" declarations.
	 *
	 * @param string $css Declaration list.
	 * @return string "prop:value;prop:value;" or ''.
	 */
	private function css_declarations( $css ) {
		$out = '';
		foreach ( explode( ';', (string) $css ) as $declaration ) {
			if ( preg_match( '/^\s*(-{0,2}[a-zA-Z][a-zA-Z0-9-]*)\s*:\s*([^:;{}]+?)\s*$/', $declaration, $m ) ) {
				$out .= strtolower( $m[1] ) . ':' . $m[2] . ';';
			}
		}
		return $out;
	}

	/**
	 * Cover min-height from data-height ("100vh", "85vh", "560px").
	 *
	 * @param \DOMElement $el Section element.
	 * @return array|null [ number, unit ]
	 */
	private function min_height( $el ) {
		if ( ! preg_match( '/^(\d{2,4})(vh|px)$/', strtolower( trim( $el->getAttribute( 'data-height' ) ) ), $m ) ) {
			return null;
		}

		$value = (int) $m[1];
		$valid = 'vh' === $m[2] ? ( $value >= 30 && $value <= 100 ) : ( $value >= 200 && $value <= 1400 );

		return $valid ? array( $value, $m[2] ) : null;
	}

	/**
	 * Cover content position from data-position ("bottom left", "center
	 * right", "bottom" …). The default centre returns '' — no attribute.
	 *
	 * @param \DOMElement $el Section element.
	 * @return string
	 */
	private function content_position( $el ) {
		$words = preg_split( '/[\s\-]+/', strtolower( str_replace( 'centre', 'center', trim( $el->getAttribute( 'data-position' ) ) ) ) );
		$y     = 'center';
		$x     = 'center';

		foreach ( $words as $word ) {
			if ( in_array( $word, array( 'top', 'bottom' ), true ) ) {
				$y = $word;
			} elseif ( in_array( $word, array( 'left', 'right' ), true ) ) {
				$x = $word;
			}
		}

		$position = $y . ' ' . $x;

		return 'center center' === $position ? '' : $position;
	}

	/**
	 * Column width from a column child's data-width ("60%").
	 *
	 * @param \DOMElement $el Column child element.
	 * @return string '' when absent/invalid.
	 */
	private function column_width( $el ) {
		if ( ! preg_match( '/^(\d{1,2}(?:\.\d{1,2})?)%$/', trim( $el->getAttribute( 'data-width' ) ), $m ) ) {
			return '';
		}

		$value = (float) $m[1];

		return ( $value >= 10 && $value <= 90 ) ? $m[1] . '%' : '';
	}

	/**
	 * Is this anchor a button (per the dialect: class contains btn/button)?
	 *
	 * @param \DOMElement $el
	 * @return bool
	 */
	private function is_button( $el ) {
		$class = ' ' . strtolower( $el->getAttribute( 'class' ) ) . ' ';
		return false !== strpos( $class, 'ai-btn' )
			|| false !== strpos( $class, ' btn ' )
			|| false !== strpos( $class, ' btn-' )
			|| false !== strpos( $class, ' button ' );
	}

	/**
	 * Sanitized class attribute string.
	 */
	private function classes( $el ) {
		$raw = trim( $el->getAttribute( 'class' ) );
		if ( '' === $raw ) {
			return '';
		}
		$classes = array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', $raw ) ) );
		return implode( ' ', $classes );
	}

	/**
	 * Map internal links (#page:slug) to page URLs.
	 */
	private function resolve_href( $href ) {
		$href = trim( (string) $href );

		if ( preg_match( '/^#page:([a-z0-9-]+)$/i', $href, $m ) ) {
			$slug = strtolower( $m[1] );
			if ( 'home' === $slug ) {
				return isset( $this->page_links['home'] ) ? $this->page_links['home'] : home_url( '/' );
			}
			// A page the user removed in the plan review (or the AI invented)
			// must not become a 404 link.
			return isset( $this->page_links[ $slug ] ) ? $this->page_links[ $slug ] : '#';
		}

		if ( '' === $href ) {
			return '#';
		}

		return $href;
	}
}
