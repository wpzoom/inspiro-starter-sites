<?php
/**
 * Composes Gutenberg block markup from the AI site-plan section data.
 *
 * The AI returns structured section JSON (type + copy + image queries); this
 * class owns all markup, so the output is always valid core-block HTML no
 * matter what the model produced. Section text is escaped here.
 *
 * @package Inspiro Starter Sites
 */

namespace Inspiro\Starter_Sites\Ai;

defined( 'ABSPATH' ) || exit;

class BlockComposer {

	/**
	 * Map of page slug => permalink used to resolve section button links.
	 *
	 * @var array
	 */
	private $page_links = array();

	/**
	 * @param array $page_links slug => URL map for button links.
	 */
	public function __construct( array $page_links = array() ) {
		$this->page_links = $page_links;
	}

	/**
	 * Compose a full page's post_content from its sections.
	 *
	 * @param array $sections Sanitized section arrays (with resolved 'image').
	 * @param array $page     Page data (slug, title).
	 * @return string Block markup.
	 */
	public function compose_page( array $sections, array $page ) {
		$blocks    = array();
		$is_first  = true;
		$has_hero  = ! empty( $sections ) && isset( $sections[0]['type'] ) && 'hero' === $sections[0]['type'];

		// Pages without a hero get a page-header section built from the page
		// title (the theme template hides the native title).
		if ( ! $has_hero && ! empty( $page['title'] ) ) {
			$blocks[] = $this->page_header( $page['title'] );
		}

		foreach ( $sections as $section ) {
			// Designed template from the section library first (real layouts
			// ported from inspiro-patterns); generic markup as fallback.
			$markup = SectionLibrary::render( $section, $this->page_links );

			if ( null === $markup ) {
				// Generic renderers expect a single 'image'.
				if ( empty( $section['image'] ) && ! empty( $section['images'][0] ) ) {
					$section['image'] = $section['images'][0];
				}

				$type   = isset( $section['type'] ) ? $section['type'] : '';
				$method = 'section_' . $type;

				if ( method_exists( $this, $method ) ) {
					$markup = $this->$method( $section, $is_first );
				}
			}

			if ( $markup ) {
				$blocks[] = $markup;
			}
			$is_first = false;
		}

		return implode( "\n\n", $blocks );
	}

	/* ---------------------------------------------------------------------
	 * Sections
	 * ------------------------------------------------------------------ */

	/**
	 * Full-width cover hero with heading, text, and button.
	 */
	private function section_hero( array $s, $is_first = true ) {
		$heading = $this->esc( $s, 'heading' );
		$text    = $this->esc( $s, 'text' );
		$button  = $this->button_block( $s, true );

		$inner = sprintf(
			'<!-- wp:heading {"textAlign":"center","level":1,"style":{"typography":{"fontSize":"3.4rem"}},"textColor":"white"} -->
<h1 class="wp-block-heading has-text-align-center has-white-color has-text-color" style="font-size:3.4rem">%s</h1>
<!-- /wp:heading -->',
			$heading
		);

		if ( $text ) {
			$inner .= sprintf(
				'

<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"1.25rem"}},"textColor":"white"} -->
<p class="has-text-align-center has-white-color has-text-color" style="font-size:1.25rem">%s</p>
<!-- /wp:paragraph -->',
				$text
			);
		}

		if ( $button ) {
			$inner .= "\n\n" . $button;
		}

		$image = isset( $s['image'] ) && is_array( $s['image'] ) ? $s['image'] : null;

		if ( $image ) {
			return sprintf(
				'<!-- wp:cover {"url":"%1$s","id":%2$d,"dimRatio":50,"overlayColor":"black","isUserOverlayColor":true,"minHeight":80,"minHeightUnit":"vh","align":"full","layout":{"type":"constrained"}} -->
<div class="wp-block-cover alignfull" style="min-height:80vh"><span aria-hidden="true" class="wp-block-cover__background has-black-background-color has-background-dim-50 has-background-dim"></span><img class="wp-block-cover__image-background wp-image-%2$d" alt="" src="%1$s" data-object-fit="cover"/><div class="wp-block-cover__inner-container">%3$s</div></div>
<!-- /wp:cover -->',
				esc_url( $image['url'] ),
				(int) $image['id'],
				$inner
			);
		}

		// No image available — solid dark cover.
		return sprintf(
			'<!-- wp:cover {"overlayColor":"black","isUserOverlayColor":true,"dimRatio":100,"minHeight":70,"minHeightUnit":"vh","align":"full","layout":{"type":"constrained"}} -->
<div class="wp-block-cover alignfull" style="min-height:70vh"><span aria-hidden="true" class="wp-block-cover__background has-black-background-color has-background-dim-100 has-background-dim"></span><div class="wp-block-cover__inner-container">%s</div></div>
<!-- /wp:cover -->',
			$inner
		);
	}

	/**
	 * Heading + paragraphs.
	 */
	private function section_text( array $s ) {
		$heading = $this->esc( $s, 'heading' );
		$paras   = array();

		if ( ! empty( $s['paragraphs'] ) && is_array( $s['paragraphs'] ) ) {
			foreach ( $s['paragraphs'] as $p ) {
				$p = esc_html( wp_strip_all_tags( (string) $p ) );
				if ( '' !== $p ) {
					$paras[] = sprintf( "<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->", $p );
				}
			}
		}

		$inner = '';
		if ( $heading ) {
			$inner .= sprintf( "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">%s</h2>\n<!-- /wp:heading -->", $heading );
		}
		if ( $paras ) {
			$inner .= ( $inner ? "\n\n" : '' ) . implode( "\n\n", $paras );
		}

		return $this->group( $inner );
	}

	/**
	 * Centered heading/intro + a row of columns.
	 */
	private function section_features( array $s ) {
		$heading = $this->esc( $s, 'heading' );
		$intro   = $this->esc( $s, 'intro' );
		$items   = ( ! empty( $s['items'] ) && is_array( $s['items'] ) ) ? array_slice( $s['items'], 0, 4 ) : array();

		if ( ! $items ) {
			return '';
		}

		$columns = array();
		foreach ( $items as $item ) {
			$title = esc_html( wp_strip_all_tags( isset( $item['title'] ) ? (string) $item['title'] : '' ) );
			$text  = esc_html( wp_strip_all_tags( isset( $item['text'] ) ? (string) $item['text'] : '' ) );

			$columns[] = sprintf(
				'<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">%s</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>%s</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->',
				$title,
				$text
			);
		}

		$inner = '';
		if ( $heading ) {
			$inner .= sprintf( "<!-- wp:heading {\"textAlign\":\"center\"} -->\n<h2 class=\"wp-block-heading has-text-align-center\">%s</h2>\n<!-- /wp:heading -->\n\n", $heading );
		}
		if ( $intro ) {
			$inner .= sprintf( "<!-- wp:paragraph {\"align\":\"center\"} -->\n<p class=\"has-text-align-center\">%s</p>\n<!-- /wp:paragraph -->\n\n", $intro );
		}

		$inner .= sprintf(
			'<!-- wp:columns {"align":"wide"} -->
<div class="wp-block-columns alignwide">%s</div>
<!-- /wp:columns -->',
			implode( "\n\n", $columns )
		);

		return $this->group( $inner );
	}

	/**
	 * Media & text section.
	 */
	private function section_media_text( array $s ) {
		$heading  = $this->esc( $s, 'heading' );
		$text     = $this->esc( $s, 'text' );
		$button   = $this->button_block( $s, false );
		$position = ( isset( $s['media_position'] ) && 'right' === $s['media_position'] ) ? 'right' : 'left';
		$image    = isset( $s['image'] ) && is_array( $s['image'] ) ? $s['image'] : null;

		$inner = '';
		if ( $heading ) {
			$inner .= sprintf( "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">%s</h2>\n<!-- /wp:heading -->", $heading );
		}
		if ( $text ) {
			$inner .= ( $inner ? "\n\n" : '' ) . sprintf( "<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->", $text );
		}
		if ( $button ) {
			$inner .= ( $inner ? "\n\n" : '' ) . $button;
		}

		if ( ! $image ) {
			// No photo found — degrade to a plain text section.
			return $this->group( $inner );
		}

		$attrs   = array(
			'mediaId'   => (int) $image['id'],
			'mediaType' => 'image',
		);
		$classes = 'wp-block-media-text is-stacked-on-mobile';
		if ( 'right' === $position ) {
			$attrs['mediaPosition'] = 'right';
			$classes               .= ' has-media-on-the-right';
		}

		$markup = sprintf(
			'<!-- wp:media-text %1$s -->
<div class="%2$s"><figure class="wp-block-media-text__media"><img src="%3$s" alt="" class="wp-image-%4$d size-full"/></figure><div class="wp-block-media-text__content">%5$s</div></div>
<!-- /wp:media-text -->',
			wp_json_encode( $attrs ),
			$classes,
			esc_url( $image['url'] ),
			(int) $image['id'],
			$inner
		);

		return $this->group( $markup, 'wide' );
	}

	/**
	 * Pull quote.
	 */
	private function section_quote( array $s ) {
		$text   = $this->esc( $s, 'text' );
		$author = $this->esc( $s, 'author' );

		if ( ! $text ) {
			return '';
		}

		$cite  = $author ? sprintf( '<cite>%s</cite>', $author ) : '';
		$inner = sprintf(
			'<!-- wp:quote {"align":"center","style":{"typography":{"fontSize":"1.5rem"}}} -->
<blockquote class="wp-block-quote has-text-align-center" style="font-size:1.5rem"><!-- wp:paragraph -->
<p>%s</p>
<!-- /wp:paragraph -->%s</blockquote>
<!-- /wp:quote -->',
			$text,
			$cite
		);

		return $this->group( $inner );
	}

	/**
	 * FAQ list using details blocks.
	 */
	private function section_faq( array $s ) {
		$heading = $this->esc( $s, 'heading' );
		$items   = ( ! empty( $s['items'] ) && is_array( $s['items'] ) ) ? array_slice( $s['items'], 0, 6 ) : array();

		if ( ! $items ) {
			return '';
		}

		$details = array();
		foreach ( $items as $item ) {
			$q = esc_html( wp_strip_all_tags( isset( $item['question'] ) ? (string) $item['question'] : '' ) );
			$a = esc_html( wp_strip_all_tags( isset( $item['answer'] ) ? (string) $item['answer'] : '' ) );
			if ( '' === $q ) {
				continue;
			}
			$details[] = sprintf(
				'<!-- wp:details -->
<details class="wp-block-details"><summary>%s</summary><!-- wp:paragraph -->
<p>%s</p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->',
				$q,
				$a
			);
		}

		$inner = '';
		if ( $heading ) {
			$inner .= sprintf( "<!-- wp:heading {\"textAlign\":\"center\"} -->\n<h2 class=\"wp-block-heading has-text-align-center\">%s</h2>\n<!-- /wp:heading -->\n\n", $heading );
		}
		$inner .= implode( "\n\n", $details );

		return $this->group( $inner );
	}

	/**
	 * Full-width call-to-action band.
	 */
	private function section_cta( array $s ) {
		$heading = $this->esc( $s, 'heading' );
		$text    = $this->esc( $s, 'text' );
		$button  = $this->button_block( $s, true );

		$inner = '';
		if ( $heading ) {
			$inner .= sprintf(
				"<!-- wp:heading {\"textAlign\":\"center\",\"textColor\":\"white\"} -->\n<h2 class=\"wp-block-heading has-text-align-center has-white-color has-text-color\">%s</h2>\n<!-- /wp:heading -->",
				$heading
			);
		}
		if ( $text ) {
			$inner .= ( $inner ? "\n\n" : '' ) . sprintf(
				"<!-- wp:paragraph {\"align\":\"center\",\"textColor\":\"white\"} -->\n<p class=\"has-text-align-center has-white-color has-text-color\">%s</p>\n<!-- /wp:paragraph -->",
				$text
			);
		}
		if ( $button ) {
			$inner .= ( $inner ? "\n\n" : '' ) . $button;
		}

		return sprintf(
			'<!-- wp:group {"align":"full","style":{"color":{"background":"#101014"},"spacing":{"padding":{"top":"4.5rem","bottom":"4.5rem","left":"1.5rem","right":"1.5rem"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-background" style="background-color:#101014;padding-top:4.5rem;padding-right:1.5rem;padding-bottom:4.5rem;padding-left:1.5rem">%s</div>
<!-- /wp:group -->',
			$inner
		);
	}

	/**
	 * Contact details section.
	 */
	private function section_contact( array $s ) {
		$heading = $this->esc( $s, 'heading' );
		$text    = $this->esc( $s, 'text' );

		$inner = '';
		if ( $heading ) {
			$inner .= sprintf( "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">%s</h2>\n<!-- /wp:heading -->", $heading );
		}
		if ( $text ) {
			$inner .= ( $inner ? "\n\n" : '' ) . sprintf( "<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->", $text );
		}

		$lines = array();
		foreach ( array( 'email', 'phone', 'address' ) as $key ) {
			$value = $this->esc( $s, $key );
			if ( $value ) {
				$lines[] = sprintf( '<strong>%s:</strong> %s', esc_html( $this->contact_label( $key ) ), $value );
			}
		}

		if ( $lines ) {
			$inner .= ( $inner ? "\n\n" : '' ) . sprintf(
				"<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->",
				implode( '<br>', $lines )
			);
		}

		return $this->group( $inner );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Page-header band for pages that don't start with a hero.
	 */
	private function page_header( $title ) {
		return sprintf(
			'<!-- wp:group {"align":"full","style":{"color":{"background":"#f6f6f4"},"spacing":{"padding":{"top":"4rem","bottom":"4rem","left":"1.5rem","right":"1.5rem"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-background" style="background-color:#f6f6f4;padding-top:4rem;padding-right:1.5rem;padding-bottom:4rem;padding-left:1.5rem"><!-- wp:heading {"textAlign":"center","level":1} -->
<h1 class="wp-block-heading has-text-align-center">%s</h1>
<!-- /wp:heading --></div>
<!-- /wp:group -->',
			esc_html( wp_strip_all_tags( (string) $title ) )
		);
	}

	/**
	 * Standard constrained group wrapper with vertical padding.
	 *
	 * @param string $inner Inner block markup.
	 * @param string $align '' or 'wide'.
	 */
	private function group( $inner, $align = '' ) {
		if ( '' === trim( $inner ) ) {
			return '';
		}

		$attrs = array(
			'style'  => array(
				'spacing' => array(
					'padding' => array(
						'top'    => '3.5rem',
						'bottom' => '3.5rem',
						'left'   => '1.5rem',
						'right'  => '1.5rem',
					),
				),
			),
			'layout' => array( 'type' => 'constrained' ),
		);
		$class = 'wp-block-group';
		if ( 'wide' === $align ) {
			$attrs = array( 'align' => 'wide' ) + $attrs;
			$class .= ' alignwide';
		}

		return sprintf(
			'<!-- wp:group %1$s -->
<div class="%2$s" style="padding-top:3.5rem;padding-right:1.5rem;padding-bottom:3.5rem;padding-left:1.5rem">%3$s</div>
<!-- /wp:group -->',
			wp_json_encode( $attrs ),
			$class,
			$inner
		);
	}

	/**
	 * Buttons block from a section's button_text / button_page keys.
	 *
	 * @param array $s        Section.
	 * @param bool  $centered Center the button row.
	 * @return string Empty string when the section has no button.
	 */
	private function button_block( array $s, $centered = false ) {
		$label = $this->esc( $s, 'button_text' );
		if ( ! $label ) {
			return '';
		}

		$slug = isset( $s['button_page'] ) ? sanitize_title( (string) $s['button_page'] ) : '';
		$url  = ( $slug && isset( $this->page_links[ $slug ] ) ) ? $this->page_links[ $slug ] : '#';

		$wrapper_attrs = $centered ? ' {"layout":{"type":"flex","justifyContent":"center"}}' : '';

		return sprintf(
			'<!-- wp:buttons%1$s -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="%2$s">%3$s</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->',
			$wrapper_attrs,
			esc_url( $url ),
			$label
		);
	}

	/**
	 * Escaped plain-text value from a section key.
	 */
	private function esc( array $s, $key ) {
		return isset( $s[ $key ] ) ? esc_html( wp_strip_all_tags( (string) $s[ $key ] ) ) : '';
	}

	/**
	 * Translated label for contact detail lines.
	 */
	private function contact_label( $key ) {
		switch ( $key ) {
			case 'email':
				return __( 'Email', 'inspiro-starter-sites' );
			case 'phone':
				return __( 'Phone', 'inspiro-starter-sites' );
			case 'address':
				return __( 'Address', 'inspiro-starter-sites' );
		}
		return ucfirst( $key );
	}
}
