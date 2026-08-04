<?php
/**
 * Library of designed section templates for the AI demo generator.
 *
 * Templates live in templates/*.php — each returns an array with metadata
 * (type, variant, slots, image/item requirements) and Gutenberg block markup
 * containing {{token}} placeholders. They are ported from the WPZOOM
 * inspiro-patterns designs, so generated demos use real, varied layouts while
 * the AI only supplies copy and image search queries.
 *
 * Rendering is strict: if a section's data doesn't satisfy the template's
 * requirements (item count, resolved images), render() returns null and the
 * caller falls back to BlockComposer's generic markup — a generation can
 * never fail because of a template mismatch.
 *
 * @package Inspiro Starter Sites
 */

namespace Inspiro\Starter_Sites\Ai;

defined( 'ABSPATH' ) || exit;

class SectionLibrary {

	/**
	 * Loaded templates, keyed "type/variant".
	 *
	 * @var array[]|null
	 */
	private static $templates = null;

	/**
	 * Load (and cache) all template files.
	 *
	 * @return array[] Keyed by "type/variant".
	 */
	public static function all() {
		if ( null !== self::$templates ) {
			return self::$templates;
		}

		self::$templates = array();

		$files = glob( __DIR__ . '/templates/*.php' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				$template = include $file;
				if ( ! is_array( $template ) || empty( $template['type'] ) || empty( $template['variant'] ) || empty( $template['markup'] ) ) {
					continue;
				}
				self::$templates[ $template['type'] . '/' . $template['variant'] ] = $template;
			}
		}

		/**
		 * Filter the loaded AI section templates (e.g. to add or remove designs).
		 *
		 * @param array[] $templates Keyed by "type/variant".
		 */
		self::$templates = apply_filters( 'inspiro_starter_sites/ai_section_templates', self::$templates );

		return self::$templates;
	}

	/**
	 * Find a template for a section's type + variant.
	 *
	 * @param string $type
	 * @param string $variant
	 * @return array|null
	 */
	public static function match( $type, $variant ) {
		if ( '' === $type || '' === $variant ) {
			return null;
		}
		$all = self::all();
		$key = $type . '/' . $variant;

		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * How many images a section needs and which search query to use —
	 * template-aware (a photo-cards template needs 3, a gallery 4, and
	 * portrait templates override the query).
	 *
	 * @param array $section Sanitized section data.
	 * @return array [ 'count' => int, 'query' => string ]
	 */
	public static function image_requirements( array $section ) {
		$template = self::match(
			isset( $section['type'] ) ? $section['type'] : '',
			isset( $section['variant'] ) ? $section['variant'] : ''
		);

		$query = isset( $section['image_query'] ) ? $section['image_query'] : '';

		if ( $template ) {
			if ( ! empty( $template['image_query'] ) ) {
				$query = $template['image_query'];
			}
			return array(
				'count' => (int) $template['images'],
				'query' => $query,
			);
		}

		return array(
			'count' => ( '' !== $query ) ? 1 : 0,
			'query' => $query,
		);
	}

	/**
	 * Human-readable catalog of available designs, embedded into the AI
	 * prompt so the model can pick variants.
	 *
	 * @return string One line per template.
	 */
	public static function prompt_catalog() {
		$lines = array();

		foreach ( self::all() as $key => $template ) {
			$fields = array();

			foreach ( (array) $template['slots'] as $slot ) {
				$fields[] = $slot;
			}
			if ( in_array( 'button_text', (array) $template['slots'], true ) ) {
				$fields[] = 'button_page';
			}
			if ( ! empty( $template['images'] ) && empty( $template['image_query'] ) ) {
				$fields[] = 'image_query';
			}

			$line = '  ' . $key . ' — ' . $template['description'] . ' (fields: ' . implode( ', ', $fields );

			if ( ! empty( $template['items'] ) ) {
				$line .= '; exactly ' . (int) $template['items'] . ' items of {' . implode( ', ', (array) $template['item_fields'] ) . '}';
			}
			$line .= ')';

			$lines[] = $line;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Render a section through its template.
	 *
	 * @param array $section    Sanitized section (with resolved 'images').
	 * @param array $page_links slug => URL map for button links.
	 * @return string|null Markup, or null when the template can't be
	 *                     satisfied (caller should use the generic fallback).
	 */
	public static function render( array $section, array $page_links ) {
		$template = self::match(
			isset( $section['type'] ) ? $section['type'] : '',
			isset( $section['variant'] ) ? $section['variant'] : ''
		);

		if ( ! $template ) {
			return null;
		}

		$images = ( ! empty( $section['images'] ) && is_array( $section['images'] ) ) ? array_values( $section['images'] ) : array();
		if ( count( $images ) < (int) $template['images'] ) {
			return null;
		}

		$required_items = (int) $template['items'];
		$items          = ( ! empty( $section['items'] ) && is_array( $section['items'] ) ) ? array_values( $section['items'] ) : array();
		if ( count( $items ) < $required_items ) {
			return null;
		}
		$items = array_slice( $items, 0, max( $required_items, 0 ) );

		$defaults      = isset( $template['defaults'] ) ? (array) $template['defaults'] : array();
		$replacements  = array();

		// Text slots.
		foreach ( array( 'heading', 'text', 'intro', 'button_text' ) as $slot ) {
			$value = isset( $section[ $slot ] ) ? $section[ $slot ] : '';
			if ( '' === $value && isset( $defaults[ $slot ] ) ) {
				$value = $defaults[ $slot ];
			}
			$replacements[ '{{' . $slot . '}}' ] = esc_html( $value );
		}

		// Button link.
		$slug = isset( $section['button_page'] ) ? sanitize_title( (string) $section['button_page'] ) : '';
		$url  = ( $slug && isset( $page_links[ $slug ] ) ) ? $page_links[ $slug ] : '#';

		$replacements['{{button_url}}'] = esc_url( $url );

		// Images.
		foreach ( $images as $i => $image ) {
			$replacements[ '{{image_' . ( $i + 1 ) . '}}' ] = esc_url( $image['url'] );
		}

		// Repeatable items. FAQ sections arrive as {question, answer} — alias
		// them onto the generic {title, text} tokens.
		foreach ( $items as $i => $item ) {
			if ( ! isset( $item['title'] ) && isset( $item['question'] ) ) {
				$item['title'] = $item['question'];
			}
			if ( ! isset( $item['text'] ) && isset( $item['answer'] ) ) {
				$item['text'] = $item['answer'];
			}
			foreach ( array( 'title', 'text', 'price', 'meta' ) as $field ) {
				$value = isset( $item[ $field ] ) ? $item[ $field ] : '';
				$replacements[ '{{item_' . ( $i + 1 ) . '_' . $field . '}}' ] = esc_html( $value );
			}
		}

		$markup = strtr( $template['markup'], $replacements );

		// Strip any tokens the data didn't cover so no literal {{...}} leaks
		// into the page.
		$markup = preg_replace( '/\{\{[a-z0-9_]+\}\}/', '', $markup );

		return $markup;
	}
}
