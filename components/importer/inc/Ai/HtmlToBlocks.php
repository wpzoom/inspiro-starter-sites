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
	 * @param array    $page_links     slug => URL map.
	 * @param callable $image_resolver fn( string $query, string $orientation ): ?array
	 * @param array    $brand          [ 'accent' => hex, 'accent_text' => hex, 'radius' => css length ]
	 */
	public function __construct( array $page_links, callable $image_resolver, array $brand = array() ) {
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

		$blocks = $this->convert_children( $body, true );
		if ( '' === trim( $blocks ) ) {
			return '';
		}

		$classes = trim( 'iss-ai-demo' . ( $page_slug ? ' iss-ai-demo--' . sanitize_html_class( $page_slug ) : '' ) );

		return sprintf(
			"<!-- wp:group {\"align\":\"full\",\"className\":\"%s\",\"layout\":{\"type\":\"default\"}} -->\n<div class=\"wp-block-group alignfull %s\">%s</div>\n<!-- /wp:group -->",
			esc_attr( $classes ),
			esc_attr( $classes ),
			"\n" . $blocks . "\n"
		);
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

		return implode( "\n\n", $blocks );
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
				// Placeholder for the WPZOOM Portfolio grid block.
				if ( 'portfolio' === trim( $el->getAttribute( 'data-block' ) ) ) {
					return \WP_Block_Type_Registry::get_instance()->is_registered( 'wpzoom-blocks/portfolio' )
						? '<!-- wp:wpzoom-blocks/portfolio /-->'
						: '';
				}
				// ai-cols-N wrappers become native columns blocks.
				if ( preg_match( '/\bai-cols-([2-4])\b/', $el->getAttribute( 'class' ), $m ) ) {
					return $this->columns_block( $el, (int) $m[1] );
				}
				$bg = $this->find_background_image( $el );
				if ( $bg ) {
					return $this->cover_block( $el, $bg );
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
				return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";

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

		$attrs = array( 'layout' => array( 'type' => 'default' ) );
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

		if ( $bg || $text ) {
			$attrs['style'] = array( 'color' => array() );
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

		return sprintf(
			"<!-- wp:group %s -->\n<div class=\"%s\"%s>%s</div>\n<!-- /wp:group -->",
			wp_json_encode( $attrs ),
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

		return sprintf(
			"<!-- wp:heading%s -->\n<h%d class=\"%s\">%s</h%d>\n<!-- /wp:heading -->",
			$attrs ? ' ' . wp_json_encode( $attrs ) : '',
			$level,
			esc_attr( $class ),
			$this->inline_html( $el ),
			$level
		);
	}

	private function paragraph_block( $el, $force_classes = '' ) {
		$classes = $force_classes ? $force_classes : $this->classes( $el );
		$attrs   = $classes ? ' ' . wp_json_encode( array( 'className' => $classes ) ) : '';
		$class   = $classes ? ' class="' . esc_attr( $classes ) . '"' : '';
		$inner   = $this->inline_html( $el );

		if ( '' === trim( wp_strip_all_tags( $inner ) ) ) {
			return '';
		}

		return sprintf( "<!-- wp:paragraph%s -->\n<p%s>%s</p>\n<!-- /wp:paragraph -->", $attrs, $class, $inner );
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

		$caption_html = '' !== $caption ? sprintf( '<figcaption class="wp-element-caption">%s</figcaption>', $caption ) : '';

		return sprintf(
			"<!-- wp:image %s -->\n<figure class=\"%s\"><img src=\"%s\" alt=\"%s\" class=\"wp-image-%d\"%s/>%s</figure>\n<!-- /wp:image -->",
			wp_json_encode( $attrs ),
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
	 * section's remaining children the inner content. Always full-bleed
	 * (a photo background is a painted background).
	 *
	 * @param \DOMElement $el Section element.
	 * @param \DOMElement $bg Background <img data-role="background">.
	 * @return string
	 */
	private function cover_block( $el, $bg ) {
		$query = trim( $bg->getAttribute( 'data-query' ) );
		$image = $query ? call_user_func( $this->image_resolver, $query, trim( $bg->getAttribute( 'data-orientation' ) ) ? trim( $bg->getAttribute( 'data-orientation' ) ) : 'landscape' ) : null;

		// Remove the background image before converting the inner content.
		$el->removeChild( $bg );

		if ( ! $image ) {
			return $this->group_block( $el, true );
		}

		$inner = $this->convert_children( $el );

		$dim = (int) $bg->getAttribute( 'data-dim' );
		$dim = ( $dim >= 0 && $dim <= 90 ) ? (int) ( round( $dim / 10 ) * 10 ) : 40;

		$classes = $this->classes( $el );
		$attrs   = array(
			'url'                => $image['url'],
			'id'                 => (int) $image['id'],
			'dimRatio'           => $dim,
			'customOverlayColor' => '#000000',
			'isUserOverlayColor' => true,
			'align'              => 'full',
			'layout'             => array( 'type' => 'default' ),
		);
		$class = 'wp-block-cover alignfull';
		if ( $classes ) {
			$attrs['className'] = $classes;
			$class             .= ' ' . $classes;
		}

		return sprintf(
			"<!-- wp:cover %s -->\n<div class=\"%s\"><span aria-hidden=\"true\" class=\"wp-block-cover__background has-background-dim-%d has-background-dim\" style=\"background-color:#000000\"></span><img class=\"wp-block-cover__image-background wp-image-%d\" alt=\"%s\" src=\"%s\" data-object-fit=\"cover\"/><div class=\"wp-block-cover__inner-container\">%s</div></div>\n<!-- /wp:cover -->",
			wp_json_encode( $attrs ),
			esc_attr( $class ),
			$dim,
			(int) $image['id'],
			esc_attr( trim( $bg->getAttribute( 'alt' ) ) ),
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
				$columns[] = sprintf(
					"<!-- wp:column -->\n<div class=\"wp-block-column\">%s</div>\n<!-- /wp:column -->",
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
		if ( $classes ) {
			$attrs['className'] = $classes;
			$class             .= ' ' . $classes;
		}

		$rows = array();
		foreach ( array_chunk( $columns, $per_row ) as $chunk ) {
			$rows[] = sprintf(
				"<!-- wp:columns%s -->\n<div class=\"%s\">%s</div>\n<!-- /wp:columns -->",
				$attrs ? ' ' . wp_json_encode( $attrs ) : '',
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
		$attrs = $ordered ? ' {"ordered":true}' : '';

		return sprintf(
			"<!-- wp:list%s -->\n<%s class=\"wp-block-list\">%s</%s>\n<!-- /wp:list -->",
			$attrs,
			$tag,
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

		return sprintf(
			"<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">%s%s</blockquote>\n<!-- /wp:quote -->",
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
