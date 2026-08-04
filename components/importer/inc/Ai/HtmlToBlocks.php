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
	 * @param array    $page_links     slug => URL map.
	 * @param callable $image_resolver fn( string $query, string $orientation ): ?array
	 */
	public function __construct( array $page_links, callable $image_resolver ) {
		$this->page_links     = $page_links;
		$this->image_resolver = $image_resolver;
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

		$blocks = $this->convert_children( $body );
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
	 * @return string
	 */
	private function convert_children( \DOMNode $parent ) {
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

			$block = $this->convert_element( $node );
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
				// Stray block-level span (e.g. a kicker) — treat as paragraph.
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
		$inner = $this->convert_children( $el );
		if ( '' === trim( $inner ) ) {
			return '';
		}

		$classes = $this->classes( $el );
		$attrs   = array( 'layout' => array( 'type' => 'default' ) );
		$class   = 'wp-block-group';

		if ( $full_width ) {
			$attrs = array( 'align' => 'full' ) + $attrs;
			$class .= ' alignfull';
		}
		if ( $classes ) {
			$attrs['className'] = $classes;
			$class             .= ' ' . $classes;
		}

		return sprintf(
			"<!-- wp:group %s -->\n<div class=\"%s\">%s</div>\n<!-- /wp:group -->",
			wp_json_encode( $attrs ),
			esc_attr( $class ),
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
		$class = 'wp-block-image size-full';
		if ( $classes ) {
			$attrs['className'] = $classes;
			$class             .= ' ' . $classes;
		}

		$caption_html = '' !== $caption ? sprintf( '<figcaption class="wp-element-caption">%s</figcaption>', $caption ) : '';

		return sprintf(
			"<!-- wp:image %s -->\n<figure class=\"%s\"><img src=\"%s\" alt=\"%s\" class=\"wp-image-%d\"/>%s</figure>\n<!-- /wp:image -->",
			wp_json_encode( $attrs ),
			esc_attr( $class ),
			esc_url( $image['url'] ),
			esc_attr( $alt ),
			(int) $image['id'],
			$caption_html
		);
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
		// Buttons are kept as verbatim anchors inside a Custom HTML block —
		// NOT wp:button. The theme styles both `.btn` and
		// `.wp-block-button__link`, which visually collides with the AI's
		// button design; a bare anchor is styled only by the demo stylesheet,
		// exactly as the AI designed it.
		$buttons = array();

		foreach ( $anchors as $a ) {
			$classes = $this->classes( $a );

			$buttons[] = sprintf(
				'<a class="%s" href="%s">%s</a>',
				esc_attr( $classes ),
				esc_url( $this->resolve_href( $a->getAttribute( 'href' ) ) ),
				$this->inline_html( $a )
			);
		}

		return sprintf( "<!-- wp:html -->\n%s\n<!-- /wp:html -->", implode( "\n", $buttons ) );
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
			return isset( $this->page_links[ $slug ] ) ? $this->page_links[ $slug ] : home_url( '/' . $slug . '/' );
		}

		if ( '' === $href ) {
			return '#';
		}

		return $href;
	}
}
