<?php
/**
 * Catalog extractor — DEV TOOL. bin/ is excluded from the release zip.
 *
 * Turns the top-level sections of Inspiro demo exports (WXR) into catalog
 * candidates for the AI demo generator's catalog engine: the demo's own
 * block markup with placeholders ("sentinels") where the generator puts
 * text, photos, links, palette colors and display-heading weights, plus a
 * field schema the planner fills. The original copy stays in the schema as
 * an example of length and tone.
 *
 * Usage (MAMP WP-CLI, from the Lite install):
 *   wp eval-file bin/catalog-extract.php <out-dir> <lite|premium> <demo.xml> [<demo.xml> ...]
 *
 * Writes <out-dir>/candidates/*.json plus <out-dir>/summary.tsv. Review the
 * candidates, list the good ones in bin/catalog-curation.json with a role,
 * placement and description, and run bin/catalog-build.php, which writes
 * them into the AI server's catalog (wpzoom-api-key-provider/catalog/).
 *
 * Sentinels (resolved by Catalog\SectionRenderer):
 *   %%iss:t:PATH%%   text (HTML context)
 *   %%iss:h:PATH%%   button link
 *   %%iss:u:PATH%%   photo URL
 *   %%iss:a:PATH%%   photo alt text (HTML attribute)
 *   %%iss:j:PATH%%   photo alt text (JSON attribute)
 *   9000000NN        photo attachment ID (numeric, so block JSON stays typed)
 *   %%iss:c:ROLE%%   palette color, optionally %%iss:c:ROLE:ALPHA%%
 *   %%iss:w:600%%    display-heading font weight (demo weight as fallback)
 *   metadata.issItem = "items.3"  marks a repeatable item for pruning
 *
 * @package Inspiro Starter Sites
 */

defined( 'ABSPATH' ) || exit;

final class ISS_Catalog_Extractor {

	const ID_BASE = 900000000;

	/** Non-core blocks a section may contain => plugin slug it needs. */
	const EXTRA_BLOCKS = array(
		'outermost/icon-block'    => 'icon-block',
		'wpzoom-blocks/portfolio' => 'wpzoom-portfolio',
		'wpzoom-forms/form-block' => 'wpzoom-forms',
	);

	/** Core blocks that make a section unusable as a template. */
	const REJECT = array(
		'core/html', 'core/shortcode', 'core/embed', 'core/video', 'core/audio', 'core/navigation',
		'core/site-logo', 'core/site-title', 'core/site-tagline', 'core/template-part', 'core/block',
		'core/freeform', 'core/file', 'core/legacy-widget', 'core/rss', 'core/search', 'core/loginout',
		'core/calendar', 'core/archives', 'core/categories', 'core/tag-cloud', 'core/latest-comments',
		'core/post-content', 'core/pattern', 'core/table', 'core/missing', 'core/more', 'core/nextpage',
	);

	/** Blocks that exist only in recent WordPress versions (checked against the registry at runtime). */
	const VERSIONED = array( 'core/accordion', 'core/icon' );

	/** Containers that can be the root of a repeatable item. */
	const ITEM_ROOTS = array( 'core/group', 'core/column', 'core/cover', 'core/media-text', 'core/details', 'core/accordion-item', 'core/image', 'core/quote' );

	/** Lite palette slugs whose meaning differs per theme (resolved per source via CSS variables). */
	const LITE_PRESETS = array( 'primary' => 'dark', 'secondary' => 'accent', 'header-footer' => 'dark' );

	private $source;
	private $fields;
	private $ids;
	private $assets;
	private $needs;
	private $requires;
	private $reject;
	private $next_id;
	private $counts;

	/** Section-scope heading slots in document order: [ name, size in px ]. */
	private $headings;

	public function __construct( $source ) {
		$this->source = $source;
	}

	/* ------------------------------------------------------------------
	 * Files and sections
	 * ---------------------------------------------------------------- */

	public function extract_file( $file ) {
		$demo = preg_replace( '/\.xml$/', '', basename( $file ) );
		$xml  = simplexml_load_file( $file, 'SimpleXMLElement', LIBXML_NOCDATA );
		if ( ! $xml ) {
			return array();
		}
		$ns  = $xml->getNamespaces( true );
		$out = array();

		foreach ( $xml->channel->item as $item ) {
			$wp = $item->children( $ns['wp'] );
			if ( 'page' !== (string) $wp->post_type || 'publish' !== (string) $wp->status ) {
				continue;
			}
			$template = '';
			foreach ( $wp->postmeta as $meta ) {
				if ( '_wp_page_template' === (string) $meta->meta_key ) {
					$template = (string) $meta->meta_value;
				}
			}
			$blocks = array_values(
				array_filter(
					parse_blocks( (string) $item->children( $ns['content'] )->encoded ),
					static function ( $b ) {
						return ! empty( $b['blockName'] );
					}
				)
			);
			foreach ( $blocks as $i => $block ) {
				$candidate           = $this->extract_section( $block );
				$candidate['source'] = array(
					'demo'     => $demo,
					'page'     => (string) $item->title,
					'template' => $template,
					'index'    => $i,
					'of'       => count( $blocks ),
				);
				$out[] = $candidate;
			}
		}

		return $out;
	}

	public function extract_section( array $block ) {
		$this->fields   = array();
		$this->ids      = array();
		$this->assets   = array();
		$this->needs    = array();
		$this->requires = array();
		$this->reject   = '';
		$this->next_id  = 1;
		$this->counts   = array( 'text' => 0, 'image' => 0, 'items' => 0 );
		$this->headings = array();

		$original = serialize_block( $block );
		$preview  = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( render_block( $block ) ) ) );

		$this->scan( $block );
		if ( '' === $this->reject && in_array( $block['blockName'], array( 'core/spacer', 'core/separator', 'core/buttons', 'core/paragraph', 'core/heading' ), true ) ) {
			$this->reject = 'not a section (' . $block['blockName'] . ')';
		}
		if ( '' === $this->reject && '' === trim( $preview ) && ! $this->has_photo( $block ) ) {
			$this->reject = 'empty';
		}
		if ( '' !== $this->reject ) {
			return array(
				'rejected' => $this->reject,
				'preview'  => mb_substr( $preview, 0, 160 ),
				'hash'     => md5( $original ),
			);
		}

		$scope = array(
			'prefix'  => '',
			'item'    => false,
			'counter' => array(),
			'heading' => false,
			'fields'  => array(),
		);
		$this->walk( $block, $scope );
		$this->fields = $scope['fields'];

		$markup = serialize_block( $block );
		$markup = $this->name_main_heading( $markup );
		$markup = $this->map_colors( $markup );
		// Lite demos are authored against a 16px root, Premium demos against
		// Premium's 10px root. Absolute px renders the same on both themes
		// (attrs and inline styles alike).
		$root   = 'premium' === $this->source ? 10 : 16;
		$markup = preg_replace_callback(
			'/(?<![\w.-])(\d*\.?\d+)rem\b/',
			static function ( $m ) use ( $root ) {
				return rtrim( rtrim( number_format( (float) $m[1] * $root, 2, '.', '' ), '0' ), '.' ) . 'px';
			},
			$markup
		);

		return array(
			'fields'   => $this->fields,
			'markup'   => $markup,
			'ids'      => $this->ids,
			'assets'   => $this->assets,
			'needs'    => array_values( array_unique( $this->needs ) ),
			'requires' => array_values( array_unique( $this->requires ) ),
			'stats'    => $this->counts,
			'tone'     => $this->tone( $block ),
			'align'    => isset( $block['attrs']['align'] ) ? $block['attrs']['align'] : '',
			'top'      => $block['blockName'],
			'name'     => isset( $block['attrs']['metadata']['name'] ) ? $block['attrs']['metadata']['name'] : '',
			'preview'  => mb_substr( $preview, 0, 300 ),
			// Same structure + same styling = same section, whatever its copy.
			'hash'     => md5( preg_replace( '/%%iss:[a-z]:[^%]*%%|900000\d\d\d/', 'X', $markup ) ),
		);
	}

	/* ------------------------------------------------------------------
	 * Pass 1: reject / needs
	 * ---------------------------------------------------------------- */

	private function scan( array $block ) {
		$name = (string) $block['blockName'];
		if ( '' !== $name ) {
			if ( in_array( $name, self::REJECT, true ) ) {
				$this->reject = 'block ' . $name;
				return;
			}
			if ( 0 !== strpos( $name, 'core/' ) ) {
				if ( ! isset( self::EXTRA_BLOCKS[ $name ] ) ) {
					$this->reject = 'block ' . $name;
					return;
				}
				$this->needs[] = self::EXTRA_BLOCKS[ $name ];
			}
			foreach ( self::VERSIONED as $versioned ) {
				if ( 0 === strpos( $name, $versioned ) ) {
					$this->requires[] = $versioned;
				}
			}
			if ( 'core/cover' === $name ) {
				$a = $block['attrs'];
				if ( ! empty( $a['useFeaturedImage'] ) || ( isset( $a['backgroundType'] ) && ! in_array( $a['backgroundType'], array( 'image', 'video' ), true ) ) ) {
					$this->reject = 'cover featured/embed';
					return;
				}
			}
			if ( 'core/media-text' === $name && isset( $block['attrs']['mediaType'] ) && 'video' === $block['attrs']['mediaType'] ) {
				$this->reject = 'media-text video';
				return;
			}
			if ( in_array( $name, array( 'core/query', 'core/latest-posts' ), true ) ) {
				$this->needs[] = 'posts';
			}
		}
		foreach ( $block['innerBlocks'] as $child ) {
			$this->scan( $child );
			if ( '' !== $this->reject ) {
				return;
			}
		}
	}

	private function has_photo( array $block ) {
		if ( in_array( $block['blockName'], array( 'core/image', 'core/cover', 'core/media-text', 'core/gallery' ), true ) ) {
			return true;
		}
		foreach ( $block['innerBlocks'] as $child ) {
			if ( $this->has_photo( $child ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------
	 * Pass 2: templatize
	 * ---------------------------------------------------------------- */

	/**
	 * Walk a block, replacing content with sentinels and recording fields
	 * into $scope. Detects repeated children as collections.
	 */
	private function walk( array &$block, array &$scope ) {
		$name = (string) $block['blockName'];

		switch ( $name ) {
			case 'core/heading':
				$this->text_slot( $block, $scope, $this->is_value( $block ) ? 'value' : 'heading' );
				$this->weight_token( $block );
				return;

			case 'core/paragraph':
				$text = $this->plain( $block['innerHTML'] );
				$kind = $this->is_value( $block ) ? 'value' : ( ( ! $scope['heading'] && $this->words( $text ) <= 6 ) ? 'eyebrow' : 'text' );
				$this->text_slot( $block, $scope, $kind );
				return;

			case 'core/button':
				$this->button_slot( $block, $scope );
				return;

			case 'core/image':
				$this->image_slot( $block, $scope );
				return;

			case 'core/cover':
				if ( isset( $block['attrs']['backgroundType'] ) && 'video' === $block['attrs']['backgroundType'] ) {
					$this->video_to_image( $block );
				}
				if ( ! empty( $block['attrs']['url'] ) ) {
					$this->image_slot( $block, $scope );
				}
				break;

			case 'core/media-text':
				$this->image_slot( $block, $scope );
				break;

			case 'core/details':
				$this->summary_slot( $block, $scope );
				break;

			case 'core/accordion-heading':
				$this->accordion_title_slot( $block, $scope );
				return;

			case 'core/pullquote':
				$this->pullquote_slots( $block, $scope );
				return;

			case 'core/quote':
				$this->quote_slots( $block, $scope );
				return;

			case 'core/list':
				$this->list_collection( $block, $scope );
				return;

			case 'core/gallery':
				$this->gallery_collection( $block, $scope );
				return;

			case 'core/social-link':
				// Demo profiles point at WPZOOM's accounts: use the network's
				// home page until the user fills in their own profile.
				if ( ! empty( $block['attrs']['service'] ) ) {
					$block['attrs']['url'] = '#';
				}
				return;

			case 'wpzoom-forms/form-block':
				// The Forms block declares formId as a string: a number is discarded
				// by attribute validation and the block shows "form not found".
				$block['attrs']['formId'] = (string) ( self::ID_BASE - 1 );
				return;
		}

		if ( empty( $block['innerBlocks'] ) ) {
			return;
		}

		// Collections: runs of 2+ identical sibling containers (never inside an item).
		$handled = array();
		if ( ! $scope['item'] ) {
			foreach ( $this->find_runs( $block['innerBlocks'] ) as $run ) {
				$this->collection( $block, $run, $scope );
				foreach ( $run as $idx ) {
					$handled[ $idx ] = true;
				}
			}
		}

		foreach ( $block['innerBlocks'] as $idx => &$child ) {
			if ( isset( $handled[ $idx ] ) || empty( $child['blockName'] ) ) {
				continue;
			}
			$this->walk( $child, $scope );
		}
		unset( $child );
	}

	/** Runs of consecutive identical item-worthy siblings (indexes). */
	private function find_runs( array $children ) {
		$named = array();
		foreach ( $children as $idx => $child ) {
			if ( ! empty( $child['blockName'] ) ) {
				$named[] = $idx;
			}
		}
		$runs = array();
		$i    = 0;
		$n    = count( $named );
		while ( $i < $n ) {
			$first = $children[ $named[ $i ] ];
			$sig   = $this->sig( $first );
			$j     = $i + 1;
			while ( $j < $n && $this->sig( $children[ $named[ $j ] ] ) === $sig ) {
				$j++;
			}
			if ( $j - $i >= 2 && ( $this->item_worthy( $first ) || $this->is_column_row( $first ) ) ) {
				$runs[] = array_slice( $named, $i, $j - $i );
			}
			$i = $j;
		}
		return $runs;
	}

	private function sig( array $block ) {
		$kids = array();
		foreach ( $block['innerBlocks'] as $child ) {
			if ( ! empty( $child['blockName'] ) ) {
				$kids[] = $this->sig( $child );
			}
		}
		return $block['blockName'] . ( $kids ? '(' . implode( ',', $kids ) . ')' : '' );
	}

	private function item_worthy( array $block ) {
		if ( ! in_array( $block['blockName'], self::ITEM_ROOTS, true ) ) {
			return false;
		}
		return '' !== trim( wp_strip_all_tags( render_block( $block ) ) ) || $this->has_photo( $block );
	}

	/** A core/columns row whose columns are all identical (and worth itemizing). */
	private function is_column_row( array $block ) {
		if ( 'core/columns' !== $block['blockName'] || count( $block['innerBlocks'] ) < 2 ) {
			return false;
		}
		$sig = null;
		foreach ( $block['innerBlocks'] as $column ) {
			$s = $this->sig( $column );
			if ( null !== $sig && $s !== $sig ) {
				return false;
			}
			$sig = $s;
		}
		return $this->item_worthy( $block['innerBlocks'][0] );
	}

	/**
	 * Turn a run of siblings into a collection. A run of identical column
	 * rows becomes ONE collection of all their columns, row by row.
	 */
	private function collection( array &$parent, array $run, array &$scope ) {
		$rows  = $this->is_column_row( $parent['innerBlocks'][ $run[0] ] );
		$base  = $this->next_name( $scope, 'items' );
		$path  = $scope['prefix'] . $base;
		$items = array();

		if ( $rows ) {
			foreach ( $run as $idx ) {
				foreach ( $parent['innerBlocks'][ $idx ]['innerBlocks'] as $c => $unused ) {
					$items[] = array( $idx, $c );
				}
			}
		} else {
			foreach ( $run as $idx ) {
				$items[] = array( $idx, null );
			}
		}

		$schema = null;
		foreach ( $items as $k => $ref ) {
			if ( null === $ref[1] ) {
				$item = &$parent['innerBlocks'][ $ref[0] ];
			} else {
				$item = &$parent['innerBlocks'][ $ref[0] ]['innerBlocks'][ $ref[1] ];
			}
			$item['attrs']['metadata']['issItem'] = $path . '.' . $k;

			$item_scope = array(
				'prefix'  => $path . '.' . $k . '.',
				'item'    => true,
				'counter' => array(),
				'heading' => false,
				'fields'  => array(),
			);
			$this->walk_item( $item, $item_scope );
			if ( null === $schema ) {
				$schema = $item_scope['fields'];
			}
			unset( $item );
		}

		$count = count( $items );
		$this->counts['items']++;
		$scope['fields'][ $base ] = array(
			'type'   => 'items',
			'count'  => $count,
			'min'    => $count >= 4 ? (int) ceil( $count / 2 ) : $count,
			'max'    => $count,
			'fields' => $schema ? $schema : array(),
		);
	}

	/** Walk an item root: its own slot (image/cover) plus its children. */
	private function walk_item( array &$item, array &$scope ) {
		$this->walk( $item, $scope );
	}

	private function list_collection( array &$block, array &$scope ) {
		$base  = $this->next_name( $scope, 'list' );
		$path  = $scope['prefix'] . $base;
		$k     = 0;
		$words = array();
		$ex    = array();
		foreach ( $block['innerBlocks'] as &$li ) {
			if ( 'core/list-item' !== $li['blockName'] || ! empty( $li['innerBlocks'] ) ) {
				continue;
			}
			$text = $this->plain( $li['innerHTML'] );
			if ( '' === $text ) {
				continue;
			}
			$li['attrs']['metadata']['issItem'] = $path . '.' . $k;
			$this->replace_inner( $li, '/^(\s*<li\b[^>]*>)(.*)(<\/li>\s*)$/s', '%%iss:t:' . $path . '.' . $k . '%%' );
			$words[] = $this->words( $text );
			if ( count( $ex ) < 3 ) {
				$ex[] = $text;
			}
			$k++;
			$this->counts['text']++;
		}
		unset( $li );
		if ( $k ) {
			$scope['fields'][ $base ] = array(
				'type'    => 'list',
				'count'   => $k,
				'min'     => min( $k, 2 ),
				'max'     => $k,
				'words'   => (int) round( array_sum( $words ) / count( $words ) ),
				'example' => $ex,
			);
		}
	}

	private function gallery_collection( array &$block, array &$scope ) {
		$base = $this->next_name( $scope, 'gallery' );
		$path = $scope['prefix'] . $base;
		$k    = 0;
		foreach ( $block['innerBlocks'] as &$img ) {
			if ( 'core/image' !== $img['blockName'] ) {
				continue;
			}
			$img['attrs']['metadata']['issItem'] = $path . '.' . $k;
			$this->photo( $img, $path . '.' . $k );
			$k++;
		}
		unset( $img );
		$scope['fields'][ $base ] = array(
			'type'  => 'gallery',
			'count' => $k,
			'min'   => min( $k, 3 ),
			'max'   => $k,
		);
	}

	/* ------------------------------------------------------------------
	 * Slots
	 * ---------------------------------------------------------------- */

	private function text_slot( array &$block, array &$scope, $kind ) {
		$html = isset( $block['innerContent'][0] ) ? (string) $block['innerContent'][0] : '';
		$text = $this->plain( $html );
		if ( '' === $text || ! preg_match( '/[\p{L}\p{N}]/u', $text ) ) {
			return; // empty or decorative (→, ✦)
		}
		// Index numerals (01, 02, 1.) stay: they remain correct after pruning.
		if ( preg_match( '/^\(?0?\d{1,2}[.)]?$/', $text ) ) {
			return;
		}
		if ( 'heading' === $kind ) {
			$scope['heading'] = true;
		}
		$name = $this->field_name( $scope, $kind );
		$path = $scope['prefix'] . $name;
		$tag  = 'core/heading' === $block['blockName'] ? 'h[1-6]' : 'p';
		if ( 'heading' === $kind && ! $scope['item'] ) {
			$this->headings[] = array( $name, $this->size_px( $block ) );
		}
		if ( $this->replace_inner( $block, '/^(\s*<(' . $tag . ')\b[^>]*>)(.*)(<\/\2>\s*)$/s', '%%iss:t:' . $path . '%%', 3 ) ) {
			$scope['fields'][ $name ] = array(
				'type'    => 'text',
				'words'   => $this->words( $text ),
				'example' => mb_substr( $text, 0, 220 ),
			);
			$this->counts['text']++;
		}
	}

	private function button_slot( array &$block, array &$scope ) {
		$html = (string) $block['innerContent'][0];
		if ( ! preg_match( '/<(a|button)\b([^>]*)>(.*?)<\/\1>/s', $html, $m ) ) {
			return;
		}
		$text = $this->plain( $m[3] );
		if ( '' === $text ) {
			return;
		}
		$name = $this->field_name( $scope, 'button' );
		$path = $scope['prefix'] . $name;
		$open = '<' . $m[1] . $m[2] . '>';
		if ( 'a' === $m[1] ) {
			$open = preg_replace( '/\s(href|target|rel)="[^"]*"/', '', $open );
			$open = preg_replace( '/^<a\b/', '<a href="%%iss:h:' . $path . '%%"', $open );
		}
		$new = str_replace( $m[0], $open . '%%iss:t:' . $path . '%%</' . $m[1] . '>', $html );
		$block['innerContent'][0] = $new;
		$block['innerHTML']       = $new;
		unset( $block['attrs']['url'], $block['attrs']['linkTarget'], $block['attrs']['rel'] );
		$scope['fields'][ $name ] = array(
			'type'    => 'button',
			'words'   => $this->words( $text ),
			'example' => $text,
		);
		$this->counts['text']++;
	}

	private function summary_slot( array &$block, array &$scope ) {
		$html = (string) $block['innerContent'][0];
		if ( ! preg_match( '/<summary\b[^>]*>(.*?)<\/summary>/s', $html, $m ) ) {
			return;
		}
		$text = $this->plain( $m[1] );
		if ( '' === $text ) {
			return;
		}
		$scope['heading'] = true;
		$name             = $this->field_name( $scope, 'heading' );
		$path             = $scope['prefix'] . $name;
		$new              = str_replace( $m[0], str_replace( $m[1], '%%iss:t:' . $path . '%%', $m[0] ), $html );
		$block['innerContent'][0] = $new;
		$block['innerHTML']       = str_replace( $m[0], str_replace( $m[1], '%%iss:t:' . $path . '%%', $m[0] ), $block['innerHTML'] );
		$scope['fields'][ $name ] = array(
			'type'    => 'text',
			'words'   => $this->words( $text ),
			'example' => $text,
		);
		$this->counts['text']++;
	}

	private function accordion_title_slot( array &$block, array &$scope ) {
		$html = (string) $block['innerContent'][0];
		if ( ! preg_match( '/(<span class="wp-block-accordion-heading__toggle-title">)(.*?)(<\/span>)/s', $html, $m ) ) {
			return;
		}
		$text = $this->plain( $m[2] );
		if ( '' === $text ) {
			return;
		}
		$scope['heading'] = true;
		$name             = $this->field_name( $scope, 'heading' );
		$path             = $scope['prefix'] . $name;
		$new              = str_replace( $m[0], $m[1] . '%%iss:t:' . $path . '%%' . $m[3], $html );
		$block['innerContent'][0] = $new;
		$block['innerHTML']       = $new;
		$scope['fields'][ $name ] = array(
			'type'    => 'text',
			'words'   => $this->words( $text ),
			'example' => $text,
		);
		$this->counts['text']++;
	}

	private function pullquote_slots( array &$block, array &$scope ) {
		$html = (string) $block['innerContent'][0];
		foreach ( array( 'quote' => '/(<p\b[^>]*>)(.*?)(<\/p>)/s', 'cite' => '/(<cite\b[^>]*>)(.*?)(<\/cite>)/s' ) as $kind => $re ) {
			if ( preg_match( $re, $html, $m ) && '' !== $this->plain( $m[2] ) ) {
				$name = $this->field_name( $scope, $kind );
				$path = $scope['prefix'] . $name;
				$html = str_replace( $m[0], $m[1] . '%%iss:t:' . $path . '%%' . $m[3], $html );
				$scope['fields'][ $name ] = array(
					'type'    => 'text',
					'words'   => $this->words( $this->plain( $m[2] ) ),
					'example' => $this->plain( $m[2] ),
				);
				$this->counts['text']++;
			}
		}
		$block['innerContent'][0] = $html;
		$block['innerHTML']       = $html;
	}

	private function quote_slots( array &$block, array &$scope ) {
		foreach ( $block['innerBlocks'] as &$child ) {
			if ( 'core/paragraph' === $child['blockName'] ) {
				$this->text_slot( $child, $scope, 'quote' );
			} else {
				$this->walk( $child, $scope );
			}
		}
		unset( $child );
		$last = count( $block['innerContent'] ) - 1;
		if ( $last >= 0 && is_string( $block['innerContent'][ $last ] ) && preg_match( '/(<cite\b[^>]*>)(.*?)(<\/cite>)/s', $block['innerContent'][ $last ], $m ) && '' !== $this->plain( $m[2] ) ) {
			$name = $this->field_name( $scope, 'cite' );
			$path = $scope['prefix'] . $name;
			$block['innerContent'][ $last ] = str_replace( $m[0], $m[1] . '%%iss:t:' . $path . '%%' . $m[3], $block['innerContent'][ $last ] );
			$scope['fields'][ $name ]       = array(
				'type'    => 'text',
				'words'   => $this->words( $this->plain( $m[2] ) ),
				'example' => $this->plain( $m[2] ),
			);
			$this->counts['text']++;
		}
	}

	/**
	 * A video-background cover becomes a photo cover. The <img> replaces the
	 * <video> in place: core's save() emits the media element first, then the
	 * overlay span, then the inner container, so the block stays valid.
	 */
	private function video_to_image( array &$block ) {
		$a    = &$block['attrs'];
		$html = (string) $block['innerContent'][0];
		if ( ! preg_match( '/<video\b[^>]*>(?:\s*<\/video>)?/s', $html, $m ) ) {
			unset( $a );
			return;
		}
		$pos = preg_match( '/data-object-position="([^"]*)"/', $m[0], $pm ) ? $pm[1] : '';
		$id  = isset( $a['id'] ) ? (int) $a['id'] : 0;
		$img = '<img class="wp-block-cover__image-background' . ( $id ? ' wp-image-' . $id : '' ) . ' size-full" alt="" src="' . esc_url( isset( $a['url'] ) ? $a['url'] : '' ) . '"'
			. ( '' !== $pos ? ' style="object-position:' . $pos . '"' : '' )
			. ' data-object-fit="cover"'
			. ( '' !== $pos ? ' data-object-position="' . $pos . '"' : '' )
			. '/>';
		$html = str_replace( $m[0], $img, $html );
		$block['innerContent'][0] = $html;
		$block['innerHTML']       = $html;
		unset( $a['backgroundType'], $a['poster'] );
		$a['sizeSlug'] = 'full';
		unset( $a );
	}

	/** Photos: image, cover and media-text. Small or SVG images are kept as static assets. */
	private function image_slot( array &$block, array &$scope ) {
		$name = $block['blockName'];
		if ( 'core/image' === $name && $this->is_asset( $block ) ) {
			$this->asset( $block );
			return;
		}
		$field = $this->field_name( $scope, 'image' );
		$path  = $scope['prefix'] . $field;
		$this->photo( $block, $path );
		$scope['fields'][ $field ] = array(
			'type'        => 'image',
			'orientation' => $this->orientation( $block, $scope ),
		);
		if ( 'core/image' === $name ) {
			$this->caption_slot( $block, $scope );
		}
	}

	/**
	 * A static asset (icon, logo): kept as designed, but its URL and ID get
	 * sentinels so the renderer can point them at a copy in the site's own
	 * media library instead of the demo server.
	 */
	private function asset( array &$block ) {
		$html = (string) $block['innerContent'][0];
		if ( ! preg_match( '/<img\b[^>]*\bsrc="([^"]+)"/', $html, $m ) ) {
			return;
		}
		$k = count( $this->assets );
		$this->assets[ $k ] = $m[1];
		$id = self::ID_BASE + 500 + $k;

		$html = preg_replace( '/(<img\b[^>]*\bsrc=")[^"]*(")/', '${1}%%iss:s:' . $k . '%%$2', $html, 1 );
		$html = preg_replace( '/<a\b[^>]*>\s*(<img\b[^>]*>)\s*<\/a>/s', '$1', $html );
		if ( preg_match( '/\bwp-image-\d+\b/', $html ) && isset( $block['attrs']['id'] ) ) {
			$html                  = preg_replace( '/\bwp-image-\d+\b/', 'wp-image-' . $id, $html );
			$block['attrs']['id'] = $id;
		}
		unset( $block['attrs']['href'], $block['attrs']['linkDestination'], $block['attrs']['linkTarget'], $block['attrs']['rel'], $block['attrs']['linkClass'] );
		$block['innerContent'][0] = $html;
		$block['innerHTML']       = $html;
	}

	/** Swap one photo's URL, ID and alt for sentinels, keeping the block valid. */
	private function photo( array &$block, $path ) {
		$id   = self::ID_BASE + $this->next_id++;
		$this->ids[ (string) $id ] = $path;
		$this->counts['image']++;

		$html = (string) $block['innerContent'][0];
		$name = $block['blockName'];

		// Links around images point at the demo site: unwrap them.
		if ( 'core/image' === $name ) {
			$html = preg_replace( '/<a\b[^>]*>\s*(<img\b[^>]*>)\s*<\/a>/s', '$1', $html );
			unset( $block['attrs']['href'], $block['attrs']['linkDestination'], $block['attrs']['linkTarget'], $block['attrs']['rel'], $block['attrs']['linkClass'] );
		}
		if ( 'core/media-text' === $name ) {
			unset( $block['attrs']['mediaLink'], $block['attrs']['linkDestination'], $block['attrs']['href'] );
			$html = preg_replace( '/<a\b[^>]*>\s*(<img\b[^>]*>)\s*<\/a>/s', '$1', $html );
		}

		// src / background-image url
		$html = preg_replace( '/(<img\b[^>]*\bsrc=")[^"]*(")/', '${1}%%iss:u:' . $path . '%%$2', $html, 1 );
		$html = preg_replace( '/background-image:url\([^)]*\)/', 'background-image:url(%%iss:u:' . $path . '%%)', $html, 1 );
		// alt
		if ( preg_match( '/<img\b[^>]*\balt="/', $html ) ) {
			$html = preg_replace( '/(<img\b[^>]*\balt=")[^"]*(")/', '${1}%%iss:a:' . $path . '%%$2', $html, 1 );
		}
		// wp-image-N
		$html = preg_replace( '/\bwp-image-\d+\b/', 'wp-image-' . $id, $html );

		$block['innerContent'][0] = $html;
		$block['innerHTML']       = $html;

		// Attributes must stay consistent with the saved HTML, or the editor
		// reports the block as invalid: IDs only where a wp-image-N class
		// exists, alt only where the markup has an alt attribute.
		$a        = &$block['attrs'];
		$has_img  = (bool) preg_match( '/\bwp-image-' . $id . '\b/', $html );
		$has_alt  = (bool) preg_match( '/<img\b[^>]*\balt="/', $html );
		if ( 'core/media-text' === $name ) {
			if ( isset( $a['mediaId'] ) && $has_img ) {
				$a['mediaId'] = $id;
			}
			if ( isset( $a['mediaUrl'] ) ) {
				$a['mediaUrl'] = '%%iss:u:' . $path . '%%';
			}
		} else {
			if ( isset( $a['id'] ) && $has_img ) {
				$a['id'] = $id;
			}
			if ( isset( $a['url'] ) ) {
				$a['url'] = '%%iss:u:' . $path . '%%';
			}
			if ( 'core/cover' === $name && $has_alt ) {
				$a['alt'] = '%%iss:j:' . $path . '%%';
			}
		}
		unset( $a );
	}

	private function caption_slot( array &$block, array &$scope ) {
		$html = (string) $block['innerContent'][0];
		if ( preg_match( '/(<figcaption\b[^>]*>)(.*?)(<\/figcaption>)/s', $html, $m ) && '' !== $this->plain( $m[2] ) ) {
			$name = $this->field_name( $scope, 'caption' );
			$path = $scope['prefix'] . $name;
			$new  = str_replace( $m[0], $m[1] . '%%iss:t:' . $path . '%%' . $m[3], $html );
			$block['innerContent'][0] = $new;
			$block['innerHTML']       = $new;
			$scope['fields'][ $name ] = array(
				'type'    => 'text',
				'words'   => $this->words( $this->plain( $m[2] ) ),
				'example' => $this->plain( $m[2] ),
			);
		}
	}

	private function is_asset( array $block ) {
		$html = (string) $block['innerContent'][0];
		if ( preg_match( '/\bsrc="[^"]+\.svg(\?[^"]*)?"/i', $html ) ) {
			return true;
		}
		$small = false;
		foreach ( array( 'width', 'height' ) as $dim ) {
			if ( isset( $block['attrs'][ $dim ] ) && (int) $block['attrs'][ $dim ] > 0 && (int) $block['attrs'][ $dim ] <= 160 ) {
				$small = true;
			}
		}
		if ( ! $small ) {
			return false;
		}
		// Small and round = an avatar: a photo slot. Anything else small is an icon or logo.
		$class  = isset( $block['attrs']['className'] ) ? (string) $block['attrs']['className'] : '';
		$radius = isset( $block['attrs']['style']['border']['radius'] ) ? (string) wp_json_encode( $block['attrs']['style']['border']['radius'] ) : '';
		return false === strpos( $class, 'is-style-rounded' ) && ! preg_match( '/(50|100)%|999|9999/', $radius );
	}

	private function orientation( array $block, array $scope ) {
		$a = $block['attrs'];
		if ( ! empty( $a['aspectRatio'] ) && 'auto' !== $a['aspectRatio'] ) {
			$parts = array_map( 'floatval', explode( '/', str_replace( ':', '/', (string) $a['aspectRatio'] ) ) );
			$ratio = isset( $parts[1] ) && $parts[1] > 0 ? $parts[0] / $parts[1] : $parts[0];
			return $ratio < 0.95 ? 'portrait' : ( $ratio > 1.05 ? 'landscape' : 'square' );
		}
		if ( 'core/cover' === $block['blockName'] && $scope['item'] && isset( $a['minHeight'] ) && (int) $a['minHeight'] >= 420 ) {
			return 'portrait';
		}
		if ( isset( $a['height'] ) && (int) $a['height'] >= 480 ) {
			return 'portrait';
		}
		return 'landscape';
	}

	/**
	 * The planner writes one headline per section into "heading", so that
	 * name must belong to the section's BIGGEST heading. Demos often put a
	 * small heading-styled label above it ("Why choose us"); that becomes
	 * "kicker", and any other heading keeps a numbered name.
	 */
	private function name_main_heading( $markup ) {
		if ( count( $this->headings ) < 2 ) {
			return $markup;
		}
		$main = 0;
		foreach ( $this->headings as $i => $h ) {
			if ( $h[1] > $this->headings[ $main ][1] + 1 ) {
				$main = $i;
			}
		}
		if ( 0 === $main ) {
			return $markup;
		}

		$rename = array();
		$n      = 2;
		foreach ( $this->headings as $i => $h ) {
			if ( $i === $main ) {
				$rename[ $h[0] ] = 'heading';
			} elseif ( $i < $main && ! in_array( 'kicker', $rename, true ) ) {
				$rename[ $h[0] ] = 'kicker';
			} else {
				$rename[ $h[0] ] = 'heading' . $n++;
			}
		}

		// Two passes through placeholders so heading ↔ heading2 swaps can't collide.
		foreach ( $rename as $from => $to ) {
			$markup = str_replace( '%%iss:t:' . $from . '%%', '%%iss:t:@' . $to . '%%', $markup );
		}
		$markup = str_replace( '%%iss:t:@', '%%iss:t:', $markup );

		$fields = array();
		foreach ( $this->fields as $name => $field ) {
			$fields[ isset( $rename[ $name ] ) ? $rename[ $name ] : $name ] = $field;
		}
		$this->fields = $fields;

		return $markup;
	}

	/** Rendered size of a heading in px (preset, literal, clamp maximum, or level default). */
	private function size_px( array $block ) {
		$a       = $block['attrs'];
		$presets = array( 'x-small' => 14, 'small' => 16, 'medium' => 20, 'large' => 36, 'x-large' => 50, 'xx-large' => 72, 'max-36' => 36, 'max-48' => 48, 'max-60' => 60, 'max-72' => 72 );
		if ( isset( $a['fontSize'] ) && isset( $presets[ $a['fontSize'] ] ) ) {
			return $presets[ $a['fontSize'] ];
		}
		if ( isset( $a['style']['typography']['fontSize'] ) ) {
			preg_match_all( '/(\d+(?:\.\d+)?)(px|rem|em)/', (string) $a['style']['typography']['fontSize'], $m, PREG_SET_ORDER );
			$max = 0;
			foreach ( $m as $v ) {
				$max = max( $max, 'px' === $v[2] ? (float) $v[1] : (float) $v[1] * 16 );
			}
			if ( $max ) {
				return $max;
			}
		}
		$level = isset( $a['level'] ) ? (int) $a['level'] : 2;
		$by    = array( 1 => 48, 2 => 40, 3 => 28, 4 => 22, 5 => 18, 6 => 16 );
		return isset( $by[ $level ] ) ? $by[ $level ] : 20;
	}

	/* ------------------------------------------------------------------
	 * Display-heading weight and palette colors
	 * ---------------------------------------------------------------- */

	/** Big headings with an explicit weight take the site's display weight. */
	private function weight_token( array &$block ) {
		$w = isset( $block['attrs']['style']['typography']['fontWeight'] ) ? (string) $block['attrs']['style']['typography']['fontWeight'] : '';
		if ( ! preg_match( '/^[5-9]00$/', $w ) || ! $this->is_display( $block ) ) {
			return;
		}
		$block['attrs']['style']['typography']['fontWeight'] = '%%iss:w:' . $w . '%%';
		$html = preg_replace( '/font-weight:\s*' . $w . '/', 'font-weight:%%iss:w:' . $w . '%%', (string) $block['innerContent'][0], 1 );
		$block['innerContent'][0] = $html;
		$block['innerHTML']       = $html;
	}

	private function is_display( array $block ) {
		$a = $block['attrs'];
		if ( isset( $a['fontSize'] ) && in_array( $a['fontSize'], array( 'large', 'x-large', 'xx-large', 'max-36', 'max-48', 'max-60', 'max-72' ), true ) ) {
			return true;
		}
		if ( isset( $a['style']['typography']['fontSize'] ) ) {
			$size = (string) $a['style']['typography']['fontSize'];
			// Section and hero headlines only: card titles keep the demo's weight.
			if ( preg_match( '/^(\d+(?:\.\d+)?)px$/', $size, $m ) ) {
				return (float) $m[1] >= 36;
			}
			if ( preg_match( '/^(\d+(?:\.\d+)?)(rem|em)$/', $size, $m ) ) {
				return (float) $m[1] >= 2.25;
			}
			return false !== strpos( $size, 'clamp' ) || false !== strpos( $size, 'vw' );
		}
		return isset( $a['level'] ) ? (int) $a['level'] <= 2 : true; // h2 default
	}

	/** Chromatic and near-black colors become palette roles; neutral greys and whites stay. */
	private function map_colors( $markup ) {
		$markup = preg_replace_callback(
			'/#([0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b/',
			function ( $m ) {
				$hex   = strtolower( $m[1] );
				$alpha = '';
				if ( 3 === strlen( $hex ) ) {
					$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
				} elseif ( 8 === strlen( $hex ) ) {
					$alpha = substr( $hex, 6, 2 );
					$hex   = substr( $hex, 0, 6 );
				}
				$role = $this->classify( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
				if ( ! $role || ( '' !== $alpha && 'dark' === $role ) ) {
					return $m[0];
				}
				return '%%iss:c:' . $role . ( '' !== $alpha ? ':' . $alpha : '' ) . '%%';
			},
			$markup
		);

		return preg_replace_callback(
			'/rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([\d.]+)\s*)?\)/',
			function ( $m ) {
				$role = $this->classify( (int) $m[1], (int) $m[2], (int) $m[3] );
				if ( ! $role ) {
					return $m[0];
				}
				$alpha = isset( $m[4] ) && '' !== $m[4] ? (float) $m[4] : 1.0;
				if ( $alpha < 1 && 'dark' === $role ) {
					return $m[0];
				}
				return '%%iss:c:' . $role . ( $alpha < 1 ? ':' . str_pad( dechex( (int) round( $alpha * 255 ) ), 2, '0', STR_PAD_LEFT ) : '' ) . '%%';
			},
			$markup
		);
	}

	/**
	 * Palette role of one color, or null to keep it.
	 *   dark        neutral near-blacks (ink and dark sections)
	 *   accentdark  deep brand colors (dark green, navy)
	 *   accent      the brand color
	 *   accentlight light brand tints that carry dark text
	 *   surface     tinted near-white section grounds
	 */
	private function classify( $r, $g, $b ) {
		$r      = $r / 255.0;
		$g      = $g / 255.0;
		$b      = $b / 255.0;
		$max    = max( $r, $g, $b );
		$min    = min( $r, $g, $b );
		$l      = ( $max + $min ) / 2;
		$chroma = $max - $min;
		$den    = 1 - abs( 2 * $l - 1 );
		$s      = ( $chroma < 1e-9 || $den < 1e-9 ) ? 0.0 : $chroma / $den;

		if ( $l > 0.85 ) {
			return ( $s >= 0.25 && $chroma >= 0.03 ) ? 'surface' : null;
		}
		if ( $chroma < 0.1 ) {
			return $l < 0.24 ? 'dark' : null;
		}
		if ( $l < 0.2 ) {
			return 'accentdark';
		}
		return $l > 0.6 ? 'accentlight' : 'accent';
	}

	private function tone( array $block ) {
		if ( 'core/cover' === $block['blockName'] ) {
			return ! empty( $block['attrs']['url'] ) ? 'image' : 'dark';
		}
		$a  = $block['attrs'];
		$bg = isset( $a['style']['color']['background'] ) ? (string) $a['style']['color']['background'] : '';
		if ( isset( $a['backgroundColor'] ) ) {
			$bg = in_array( $a['backgroundColor'], array( 'primary', 'header-footer', 'foreground' ), true ) ? '#101010' : ( 'secondary' === $a['backgroundColor'] ? '#0bb4aa' : '#f5f5f5' );
		}
		if ( preg_match( '/^#([0-9a-f]{6})/i', $bg, $m ) ) {
			$role = $this->classify( hexdec( substr( $m[1], 0, 2 ) ), hexdec( substr( $m[1], 2, 2 ) ), hexdec( substr( $m[1], 4, 2 ) ) );
			if ( in_array( $role, array( 'dark', 'accentdark' ), true ) ) {
				return 'dark';
			}
			if ( 'accent' === $role ) {
				return 'accent';
			}
			if ( 'surface' === $role || ( null === $role && strtolower( $bg ) !== '#ffffff' ) ) {
				return 'tint';
			}
		}
		// A full-width wrapper whose first child is a photo cover reads as an image section.
		foreach ( $block['innerBlocks'] as $child ) {
			if ( ! empty( $child['blockName'] ) ) {
				return ( 'core/cover' === $child['blockName'] && ! empty( $child['attrs']['url'] ) ) ? 'image' : 'light';
			}
		}
		return 'light';
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	/** Field names per scope: heading/title, eyebrow/meta, text, value, button, image... */
	private function field_name( array &$scope, $kind ) {
		if ( $scope['item'] ) {
			$map  = array( 'heading' => 'title', 'eyebrow' => 'meta' );
			$kind = isset( $map[ $kind ] ) ? $map[ $kind ] : $kind;
		}
		return $this->next_name( $scope, $kind );
	}

	private function next_name( array &$scope, $base ) {
		$scope['counter'][ $base ] = isset( $scope['counter'][ $base ] ) ? $scope['counter'][ $base ] + 1 : 1;
		return 1 === $scope['counter'][ $base ] ? $base : $base . $scope['counter'][ $base ];
	}

	private function replace_inner( array &$block, $re, $sentinel, $group = 2 ) {
		$html = isset( $block['innerContent'][0] ) ? (string) $block['innerContent'][0] : '';
		if ( ! preg_match( $re, $html, $m ) ) {
			return false;
		}
		// Rebuild: every group before the text group, the sentinel, every group after.
		$out = '';
		for ( $g = 1; $g < count( $m ); $g++ ) {
			if ( 3 === $group && 2 === $g ) {
				continue; // the tag-name backreference group, already inside group 1
			}
			$out .= ( $g === $group ) ? $sentinel : $m[ $g ];
		}
		$block['innerContent'][0] = $out;
		$block['innerHTML']       = $out;
		return true;
	}

	private function is_value( array $block ) {
		$text = $this->plain( $block['innerHTML'] );
		return mb_strlen( $text ) <= 14 && preg_match( '/\d/', $text ) && preg_match( '/^[~≈+\-$€£]?\d/u', $text );
	}

	private function plain( $html ) {
		// Byte-wise trim() would cut UTF-8 sequences ending in 0xA0 (à); trim
		// whitespace, NBSP and zero-width spaces as characters instead.
		$html = preg_replace( '/<br\s*\/?>/i', "\n", (string) $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/[ \t\r\x{00A0}\x{200B}]+/u', ' ', $text );
		$text = preg_replace( '/ *\n[\s\x{00A0}]*/u', "\n", (string) $text );
		return trim( (string) $text );
	}

	private function words( $text ) {
		$parts = preg_split( '/\s+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $parts ) ? count( $parts ) : 0;
	}
}

/* ----------------------------------------------------------------------
 * CLI entry
 * -------------------------------------------------------------------- */

if ( isset( $args ) && count( $args ) >= 3 ) {
	$out_dir = rtrim( $args[0], '/' );
	$source  = $args[1];
	$files   = array_slice( $args, 2 );
	wp_mkdir_p( $out_dir . '/candidates' );
	array_map( 'unlink', glob( $out_dir . '/candidates/*.json' ) );

	$extractor = new ISS_Catalog_Extractor( $source );
	$seen      = array();
	$rows      = array( "id\tdemo\tpage\tidx\tstatus\ttone\ttop\tname\ttext\timg\titems\tneeds\tfields\tpreview" );
	$stats     = array( 'sections' => 0, 'rejected' => 0, 'duplicate' => 0, 'kept' => 0 );

	foreach ( $files as $file ) {
		foreach ( $extractor->extract_file( $file ) as $c ) {
			$stats['sections']++;
			$s    = $c['source'];
			$id   = sanitize_title( $s['demo'] . '-' . $s['page'] . '-' . $s['index'] );
			$base = array( $id, $s['demo'], $s['page'], $s['index'] );
			if ( isset( $c['rejected'] ) ) {
				$stats['rejected']++;
				$rows[] = implode( "\t", array_merge( $base, array( 'rejected: ' . $c['rejected'], '', '', '', '', '', '', '', '', $c['preview'] ) ) );
				continue;
			}
			if ( isset( $seen[ $c['hash'] ] ) ) {
				$stats['duplicate']++;
				$rows[] = implode( "\t", array_merge( $base, array( 'duplicate of ' . $seen[ $c['hash'] ], '', '', '', '', '', '', '', '', '' ) ) );
				continue;
			}
			$seen[ $c['hash'] ] = $id;
			$stats['kept']++;
			$c['id']     = $id;
			$c['source_theme'] = $source;
			file_put_contents( $out_dir . '/candidates/' . $id . '.json', wp_json_encode( $c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			$rows[] = implode(
				"\t",
				array_merge(
					$base,
					array( 'ok', $c['tone'], $c['top'], $c['name'], $c['stats']['text'], $c['stats']['image'], $c['stats']['items'], implode( ',', $c['needs'] ), implode( ',', array_keys( $c['fields'] ) ), mb_substr( $c['preview'], 0, 90 ) )
				)
			);
		}
	}
	file_put_contents( $out_dir . '/summary.tsv', implode( "\n", $rows ) . "\n" );
	echo wp_json_encode( $stats ), "\n";
}
