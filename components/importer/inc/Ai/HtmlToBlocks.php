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
	 * under_header — the page's header floats over its first section.
	 *
	 * @var array
	 */
	private $options = array();

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
	 * @param array    $options        [ 'block_css' => bool, 'under_header' => bool ]
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
			)
		);
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

			if ( $top_level && in_array( $node->nodeName, array( 'div', 'aside' ), true ) ) {
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
				// svg and anything exotic — passthrough as a custom HTML block.
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

		$inner = $this->convert_children( $el );
		if ( '' === trim( $inner ) ) {
			return '';
		}

		// data-layout: a native grid / row / stack (layout CSS is generated
		// by WordPress at render time, nothing goes into the saved markup).
		$layout = $this->group_layout( $el );
		$attrs  = array( 'layout' => $layout ? $layout['layout'] : array( 'type' => 'default' ) );
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

		return sprintf(
			"<!-- wp:heading%s -->\n<h%d class=\"%s\">%s</h%d>\n<!-- /wp:heading -->",
			$attrs ? ' ' . serialize_block_attributes( $attrs ) : '',
			$level,
			esc_attr( $class ),
			$this->inline_html( $el ),
			$level
		);
	}

	private function paragraph_block( $el, $force_classes = '' ) {
		$classes = $force_classes ? $force_classes : $this->classes( $el );
		$attrs   = $this->with_element_styles( $classes ? array( 'className' => $classes ) : array(), $el );
		$class   = trim( $classes . $this->css_class( $attrs ) );
		$attrs   = $attrs ? ' ' . serialize_block_attributes( $attrs ) : '';
		$class   = $class ? ' class="' . esc_attr( $class ) . '"' : '';
		$inner   = $this->inline_html( $el );

		if ( '' === trim( wp_strip_all_tags( $inner ) ) ) {
			return '';
		}

		return sprintf( "<!-- wp:paragraph%s -->\n<p%s>%s</p>\n<!-- /wp:paragraph -->", $attrs, $class, $inner );
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

		// Remove the background image before converting the inner content.
		$el->removeChild( $bg );

		if ( ! $image ) {
			return $this->group_block( $el, $full );
		}

		$inner = $this->convert_children( $el );

		$dim = (int) $bg->getAttribute( 'data-dim' );
		$dim = ( $dim >= 0 && $dim <= 90 ) ? (int) ( round( $dim / 10 ) * 10 ) : 40;

		// A brand-colored overlay (duotone wash) instead of black.
		$overlay = sanitize_hex_color( trim( $bg->getAttribute( 'data-overlay' ) ) );
		$overlay = $overlay ? $overlay : '#000000';

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
			$inner = ( 'div' === $child->nodeName || 'aside' === $child->nodeName )
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
						'color'  => array( 'text' => $accent ),
					),
				);
				$buttons[] = sprintf(
					"<!-- wp:button %s -->\n<div class=\"wp-block-button is-style-outline\"><a class=\"wp-block-button__link has-text-color wp-element-button\" style=\"border-radius:%s;color:%s\" href=\"%s\">%s</a></div>\n<!-- /wp:button -->",
					wp_json_encode( $attrs ),
					esc_attr( $radius ),
					esc_attr( $accent ),
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

		return sprintf(
			"<!-- wp:buttons -->\n<div class=\"wp-block-buttons\">%s</div>\n<!-- /wp:buttons -->",
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

		$tag   = $ordered ? 'ol' : 'ul';
		$attrs     = $this->with_element_styles( $ordered ? array( 'ordered' => true ) : array(), $el );
		$css_class = $this->css_class( $attrs );
		$attrs     = $attrs ? ' ' . serialize_block_attributes( $attrs ) : '';

		return sprintf(
			"<!-- wp:list%s -->\n<%s class=\"wp-block-list%s\">%s</%s>\n<!-- /wp:list -->",
			$attrs,
			$tag,
			$css_class,
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
					$paras[] = sprintf( "<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->", esc_html( $text ) );
				}
				continue;
			}
			if ( 'cite' === $child->nodeName || 'footer' === $child->nodeName ) {
				$cite = sprintf( '<cite>%s</cite>', $this->inline_html( $child ) );
			} elseif ( 'p' === $child->nodeName ) {
				$paras[] = sprintf( "<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->", $this->inline_html( $child ) );
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
		$html = $el->ownerDocument->saveHTML( $el );
		if ( '' === trim( $html ) ) {
			return '';
		}
		return sprintf( "<!-- wp:html -->\n%s\n<!-- /wp:html -->", $html );
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
		return preg_replace_callback(
			'/href="#page:([a-z0-9-]+)"/',
			function ( $m ) {
				return 'href="' . esc_url( $this->resolve_href( '#page:' . $m[1] ) ) . '"';
			},
			trim( $html )
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
				'minimumColumnWidth' => '' !== $minimum ? $minimum : '12rem',
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
	 * A CSS length within per-unit bounds ("2rem", "240px", "30%"); a bare
	 * "0" is accepted when 0 is in range.
	 *
	 * @param string $raw    Attribute value.
	 * @param array  $bounds Unit => [ min, max ].
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

		return ( $value >= $bounds[ $m[2] ][0] && $value <= $bounds[ $m[2] ][1] ) ? $m[1] . $m[2] : '';
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
