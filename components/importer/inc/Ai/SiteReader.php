<?php
/**
 * Reads an existing website for "Rebuild my website": the homepage and the
 * pages its main menu links to, cleaned into numbered text blocks (p1.4)
 * the AI can point at instead of retyping, plus the contact details,
 * images, logo and legal pages found on the way.
 *
 * Runs on the customer's own server, one page at a time, within fixed
 * limits: wp_safe_remote_get() (no private or local addresses), robots.txt
 * honored for every page fetched, and a user agent that says who we are.
 *
 * @package Inspiro Starter Sites
 */

namespace Inspiro\Starter_Sites\Ai;

defined( 'ABSPATH' ) || exit;

class SiteReader {

	/** Homepage + up to five menu pages. */
	const MAX_PAGES = 6;

	/** Per response; larger pages are cut off. */
	const MAX_BYTES = 2097152;

	/** Seconds per request. */
	const TIMEOUT = 10;

	const MAX_IMAGES = 40;

	/** Text sent to the AI: per page and in all (about 12k tokens). */
	const MAX_PAGE_CHARS  = 12000;
	const MAX_TOTAL_CHARS = 48000;

	/** Under this many words in all, the site most likely renders its text with JavaScript. */
	const THIN_WORDS = 300;

	/** Legal pages are linked as they are, never rebuilt. */
	const LEGAL_PATTERN = '/impressum|imprint|datenschutz|privacy|privacidad|privacit|agb|terms|conditions|cookie|legal|disclaimer|mentions|colofon|widerruf/i';

	const SOCIAL_HOSTS = array(
		'facebook.com'  => 'facebook',
		'instagram.com' => 'instagram',
		'x.com'         => 'x',
		'twitter.com'   => 'x',
		'youtube.com'   => 'youtube',
		'linkedin.com'  => 'linkedin',
		'tiktok.com'    => 'tiktok',
		'pinterest.com' => 'pinterest',
		'vimeo.com'     => 'vimeo',
	);

	/** Site builders' image CDNs: their files are the owner's own images. */
	const IMAGE_CDNS = '/(^|\.)(jimcdn\.com|jimstatic\.com|wixstatic\.com|squarespace-cdn\.com|sqspcdn\.com|website-files\.com|wp\.com|cloudfront\.net|ionos\.com|ionos\.de|mywebsite-editor\.com|strato\.de|webnode\.page|webnodecdn\.com|godaddysites\.com|img1\.wsimg\.com|cdn\.shopify\.com)$/i';

	const BLOCK_TAGS = array(
		'address', 'article', 'aside', 'blockquote', 'body', 'caption', 'dd', 'details', 'div', 'dl', 'dt',
		'fieldset', 'figcaption', 'figure', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup',
		'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'summary', 'table', 'tbody', 'td', 'tfoot', 'th',
		'thead', 'tr', 'ul',
	);

	/** Footer containers of themes that don't use <footer>: the outermost one. */
	const FOOTER_QUERY = '//*[(contains(concat(" ", normalize-space(@class), " "), " footer ") or contains(@class, "site-footer") or contains(translate(@id, "FOOTER", "footer"), "footer")) and not(ancestor::*[contains(concat(" ", normalize-space(@class), " "), " footer ") or contains(@class, "site-footer") or contains(translate(@id, "FOOTER", "footer"), "footer")])]';

	/** Never text: dropped before the walk. */
	const DROP_TAGS = array( 'script', 'style', 'noscript', 'svg', 'iframe', 'template', 'form', 'select', 'input', 'textarea', 'canvas', 'video', 'audio', 'object', 'embed', 'dialog' );

	/** Host the site lives on, without www (redirects decide it). */
	private $host = '';

	/** robots.txt Disallow rules (regexes) per origin. */
	private $robots = array();

	/** Normalized text => true: a block already taken from an earlier page or the footer. */
	private $seen = array();

	private $images = array();
	private $phones = array();
	private $emails = array();
	private $social = array();
	private $jsonld = array();
	private $maps   = array();
	private $chars  = 0;
	private $truncated = false;

	/** Menu label of the page being read (images remember where they were). */
	private $current = '';

	/**
	 * Read the site.
	 *
	 * @param string        $url       Address as the customer typed it.
	 * @param callable|null $heartbeat Called between requests (keep-alive).
	 * @return array|\WP_Error
	 */
	public function read( $url, $heartbeat = null ) {
		$url = $this->normalize_url( $url );
		if ( '' === $url ) {
			return new \WP_Error( 'site_url', __( 'Please enter a valid website address, e.g. example.com.', 'inspiro-starter-sites' ) );
		}

		$home = $this->fetch( $url );
		if ( is_wp_error( $home ) ) {
			return $home;
		}

		// A redirect decides the real host (example.com → www.example.de).
		$this->host = $this->bare_host( $home['url'] );

		$doc   = $this->dom( $home['body'] );
		$xpath = new \DOMXPath( $doc );
		$base  = $this->base_url( $xpath, $home['url'] );

		$site = array(
			'url'         => $home['url'],
			'host'        => $this->host,
			'lang'        => $this->attr( $xpath, '//html/@lang' ),
			'site_name'   => $this->attr( $xpath, '//meta[@property="og:site_name"]/@content' ),
			'theme_color' => $this->attr( $xpath, '//meta[@name="theme-color"]/@content' ),
			'logo'        => $this->logo( $xpath, $base ),
			'icon'        => $this->absolute( $this->attr( $xpath, '//link[contains(concat(" ", normalize-space(@rel), " "), " icon ")]/@href' ), $base ),
			'pages'       => array(),
			'footer'      => array(),
			'legal'       => array(),
			'skipped'     => array(),
		);

		$links = $this->menu_links( $xpath, $base, $home['url'] );
		$site['legal'] = $links['legal'];

		// JSON-LD first: reading the footer drops every <script>.
		$this->collect_meta( $xpath, $base );

		// The footer repeats on every page: read it once, from the homepage.
		$site['footer'] = $this->footer_blocks( $xpath, $base );
		$site['pages'][] = $this->page( 0, $home['url'], __( 'Home', 'inspiro-starter-sites' ), $doc, $xpath, $base );

		// Best candidates first. A page with nothing new (its text was all on
		// earlier pages) gives its slot to the next one, within a few extra
		// requests.
		$fetches = 0;
		foreach ( $this->rank_pages( $links['pages'] ) as $link ) {
			if ( count( $site['pages'] ) >= self::MAX_PAGES || ++$fetches > self::MAX_PAGES + 2 ) {
				break;
			}
			if ( $heartbeat ) {
				call_user_func( $heartbeat );
			}
			$page = $this->fetch( $link['url'] );
			if ( is_wp_error( $page ) ) {
				$site['skipped'][] = array( 'url' => $link['url'], 'reason' => $page->get_error_message() );
				continue;
			}
			// Followed a redirect off the site (a booking platform, a shop).
			if ( $this->bare_host( $page['url'] ) !== $this->host ) {
				$site['skipped'][] = array( 'url' => $link['url'], 'reason' => 'external' );
				continue;
			}
			$page_doc   = $this->dom( $page['body'] );
			$page_xpath = new \DOMXPath( $page_doc );
			$read       = $this->page( count( $site['pages'] ), $page['url'], $link['label'], $page_doc, $page_xpath, $this->base_url( $page_xpath, $page['url'] ) );
			if ( ! $read['blocks'] ) {
				$site['skipped'][] = array( 'url' => $link['url'], 'reason' => 'empty' );
				continue;
			}
			$read['order']   = $link['order'];
			$site['pages'][] = $read;
		}

		// Back to menu order (block ids stay as read).
		usort(
			$site['pages'],
			static function ( $a, $b ) {
				return ( isset( $a['order'] ) ? $a['order'] : -1 ) <=> ( isset( $b['order'] ) ? $b['order'] : -1 );
			}
		);

		$words = 0;
		foreach ( array_merge( $site['footer'], ...array_column( $site['pages'], 'blocks' ) ) as $block ) {
			$words += count( preg_split( '/\s+/u', $block['text'], -1, PREG_SPLIT_NO_EMPTY ) );
		}

		$site['contact'] = array(
			'phones' => array_values( array_unique( $this->phones ) ),
			'emails' => array_values( array_unique( $this->emails ) ),
			'jsonld' => $this->jsonld,
			'maps'   => array_values( array_unique( $this->maps ) ),
		);
		$site['social']    = $this->social;
		$site['images']    = array_values( $this->images );
		$site['words']     = $words;
		$site['thin']      = $words < self::THIN_WORDS;
		$site['truncated'] = $this->truncated;
		$site['read_at']   = time();

		return $site;
	}

	/* ---------------------------------------------------------------------
	 * Fetching
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $url Absolute URL.
	 * @return array|\WP_Error [ 'url' => final URL, 'body' => UTF-8 HTML ]
	 */
	private function fetch( $url ) {
		if ( ! $this->allowed( $url ) ) {
			return new \WP_Error( 'site_robots', __( 'This website asks tools like ours not to read this page (robots.txt).', 'inspiro-starter-sites' ) );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 5,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => $this->user_agent(),
				'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'site_unreachable', __( 'The website could not be reached. Please check the address and try again.', 'inspiro-starter-sites' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			/* translators: %d: HTTP status code */
			return new \WP_Error( 'site_http', sprintf( __( 'The website answered with an error (HTTP %d).', 'inspiro-starter-sites' ), $code ) );
		}

		$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		if ( '' !== $type && false === stripos( $type, 'html' ) ) {
			return new \WP_Error( 'site_type', __( 'That address is not a web page.', 'inspiro-starter-sites' ) );
		}

		$final = $url;
		if ( isset( $response['http_response'] ) && is_object( $response['http_response'] ) && method_exists( $response['http_response'], 'get_response_object' ) ) {
			$final = (string) $response['http_response']->get_response_object()->url;
		}

		// The redirect target's own robots.txt applies too.
		if ( $final !== $url && ! $this->allowed( $final ) ) {
			return new \WP_Error( 'site_robots', __( 'This website asks tools like ours not to read this page (robots.txt).', 'inspiro-starter-sites' ) );
		}

		return array(
			'url'  => $final,
			'body' => $this->to_utf8( (string) wp_remote_retrieve_body( $response ), $type ),
		);
	}

	private function user_agent() {
		return 'InspiroStarterSites/' . ( defined( 'INSPIRO_STARTER_SITES_VERSION' ) ? INSPIRO_STARTER_SITES_VERSION : '1' ) . ' (+https://www.wpzoom.com/)';
	}

	/**
	 * robots.txt check: the rules for our user agent, else for "*".
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	private function allowed( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return false;
		}
		$origin = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		if ( ! isset( $this->robots[ $origin ] ) ) {
			$this->robots[ $origin ] = $this->robots_rules( $origin );
		}

		$path = ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		foreach ( $this->robots[ $origin ] as $rule ) {
			if ( preg_match( $rule, $path ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string $origin scheme://host.
	 * @return string[] Disallow rules as regexes.
	 */
	private function robots_rules( $origin ) {
		$response = wp_safe_remote_get(
			$origin . '/robots.txt',
			array(
				'timeout'             => 5,
				'redirection'         => 2,
				'limit_response_size' => 262144,
				'user-agent'          => $this->user_agent(),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$groups  = array(); // agent => disallow paths
		$agents  = array();
		$in_rule = false;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) wp_remote_retrieve_body( $response ) ) as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );
			if ( ! preg_match( '/^([a-z-]+)\s*:\s*(.*)$/i', $line, $m ) ) {
				continue;
			}
			$field = strtolower( $m[1] );
			$value = trim( $m[2] );
			if ( 'user-agent' === $field ) {
				// Consecutive User-agent lines share the rules that follow.
				if ( $in_rule ) {
					$agents  = array();
					$in_rule = false;
				}
				$agents[] = strtolower( $value );
				continue;
			}
			if ( 'disallow' === $field || 'allow' === $field ) {
				$in_rule = true;
				if ( 'disallow' === $field && '' !== $value ) {
					foreach ( $agents as $agent ) {
						$groups[ $agent ][] = $value;
					}
				}
			}
		}

		$paths = array();
		foreach ( $groups as $agent => $rules ) {
			if ( false !== strpos( $agent, 'inspirostartersites' ) ) {
				$paths = $rules;
				break;
			}
		}
		if ( ! $paths && isset( $groups['*'] ) ) {
			$paths = $groups['*'];
		}

		return array_map(
			static function ( $path ) {
				$end   = '$' === substr( $path, -1 );
				$regex = str_replace( '\*', '.*', preg_quote( rtrim( $path, '$' ), '#' ) );
				return '#^' . $regex . ( $end ? '$' : '' ) . '#';
			},
			$paths
		);
	}

	/* ---------------------------------------------------------------------
	 * Links
	 * ------------------------------------------------------------------ */

	/**
	 * The pages the main menu links to, in menu order, and the legal pages.
	 * Falls back to every same-site link on the homepage when the menu is
	 * not marked up as one.
	 *
	 * @return array [ 'pages' => [ [url, label] ], 'legal' => [ [url, label] ] ]
	 */
	private function menu_links( \DOMXPath $xpath, $base, $home ) {
		$menus = '//nav//a[@href] | //*[@role="navigation"]//a[@href] | //header//a[@href]'
			. ' | //*[contains(translate(@class, "MENU", "menu"), "menu") or contains(translate(@id, "MENU", "menu"), "menu")]//a[@href]';

		$pages = $this->links( $xpath->query( $menus ), $base, $home );
		if ( count( $pages['pages'] ) < 2 ) {
			$all   = $this->links( $xpath->query( '//body//a[@href]' ), $base, $home );
			$pages = array(
				'pages' => array_slice( $all['pages'], 0, 12 ),
				'legal' => $all['legal'],
			);
		}

		// Legal links usually sit in the footer, outside the menu.
		$footer = $this->links( $xpath->query( '//footer//a[@href] | //*[@role="contentinfo"]//a[@href]' ), $base, $home );
		$legal  = array();
		foreach ( array_merge( $pages['legal'], $footer['legal'] ) as $link ) {
			$legal[ $link['url'] ] = $link;
		}
		$pages['legal'] = array_values( $legal );

		return $pages;
	}

	/**
	 * The menu pages worth reading, best first: the ones that carry the
	 * business (contact, about, hours, services, menu card, prices, events)
	 * before shop categories and products, and never account, cart or
	 * language-switch links.
	 *
	 * @param array $links [ [url, label] ] in menu order.
	 * @return array [ [url, label, order] ]
	 */
	private function rank_pages( array $links ) {
		$scored = array();
		foreach ( $links as $order => $link ) {
			$path  = strtolower( rawurldecode( (string) wp_parse_url( $link['url'], PHP_URL_PATH ) . '?' . (string) wp_parse_url( $link['url'], PHP_URL_QUERY ) ) );
			$text  = $path . ' ' . mb_strtolower( $link['label'] );
			if ( preg_match( '#cart|warenkorb|checkout|kasse|account|konto|login|logout|anmelden|einloggen|regist|wishlist|merkzettel|newsletter|/author/|/tag/|/feed|add-to-cart|/search|suche#', $text )
				|| preg_match( '#^/[a-z]{2}(-[a-z]{2})?/?\?$#', $path )
				|| '' === trim( $link['label'] ) || preg_match( '/^[\d.,\s€$£%]+$/u', $link['label'] ) ) {
				continue;
			}
			$score = 0;
			if ( preg_match( '/kontakt|contact|öffnungszeit|oeffnungszeit|opening|hours|anfahrt|standort|location|find-us|visit/u', $text ) ) {
				$score += 4;
			}
			if ( preg_match( '/about|über|ueber|uber-uns|wir\b|team|geschichte|history|story|philosoph|tradition|familie|family|leitbild|chi-siamo|qui-sommes|nosotros/u', $text ) ) {
				$score += 3;
			}
			if ( preg_match( '/speisekarte|menu|karte|leistung|service|angebot|preis|price|pricing|tarif|event|veranstaltung|termin|weinprobe|tasting|galerie|gallery|faq|kurse|classes|praxis|behandlung/u', $text ) ) {
				$score += 2;
			}
			if ( preg_match( '#/shop\b|/collections?/|/products?/|/produkt|product-category|/kategorie/|/category/|online-shop#', $path ) ) {
				$score -= 5;
			}
			if ( substr_count( trim( strtok( $path, '?' ), '/' ), '/' ) >= 2 ) {
				$score -= 1;
			}
			if ( mb_strlen( $link['label'] ) > 40 ) {
				$score -= 2;
			}
			$scored[] = array( 'score' => $score, 'order' => $order, 'link' => $link );
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'] ?: $a['order'] <=> $b['order'];
			}
		);
		return array_map(
			static function ( $item ) {
				return $item['link'] + array( 'order' => $item['order'] );
			},
			$scored
		);
	}

	private function links( $nodes, $base, $home ) {
		$pages = array();
		$legal = array();
		$home  = $this->link_key( $home );

		foreach ( $nodes ? $nodes : array() as $a ) {
			$href = trim( (string) $a->getAttribute( 'href' ) );
			if ( '' === $href || preg_match( '/^(#|mailto:|tel:|javascript:|data:)/i', $href ) ) {
				continue;
			}
			$url = $this->absolute( $href, $base );
			if ( '' === $url || $this->bare_host( $url ) !== $this->host ) {
				continue;
			}
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( preg_match( '/\.(pdf|jpe?g|png|gif|webp|svg|zip|docx?|xlsx?|pptx?|mp4|mp3|ics)$/i', $path ) ) {
				continue;
			}
			$key = $this->link_key( $url );
			if ( $key === $home ) {
				continue;
			}
			$label = $this->clean( $a->textContent );
			if ( '' === $label ) {
				$label = $this->clean( (string) $a->getAttribute( 'title' ) . ' ' . (string) $a->getAttribute( 'aria-label' ) );
			}
			$link = array(
				'url'   => strtok( $url, '#' ),
				// A menu item's first line ("Marylebone", not its address under it).
				'label' => mb_substr( strtok( $label, "\n" ), 0, 60 ),
			);
			if ( preg_match( self::LEGAL_PATTERN, $path . ' ' . $label ) ) {
				$legal[ $key ] = $link;
			} elseif ( ! isset( $pages[ $key ] ) ) {
				$pages[ $key ] = $link;
			}
		}

		return array(
			'pages' => array_values( $pages ),
			'legal' => array_values( $legal ),
		);
	}

	/** Same page regardless of scheme, www, trailing slash or #fragment. */
	private function link_key( $url ) {
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';
		$path  = preg_replace( '#/(index|default)\.(html?|php)$#i', '', $path );
		return $this->bare_host( $url ) . $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	/* ---------------------------------------------------------------------
	 * Pages
	 * ------------------------------------------------------------------ */

	/**
	 * One page as numbered text blocks.
	 *
	 * @return array
	 */
	private function page( $index, $url, $label, \DOMDocument $doc, \DOMXPath $xpath, $base ) {
		$this->current = $label;
		$this->collect_meta( $xpath, $base );

		$title = $this->clean( $this->attr( $xpath, '//title' ) );
		$desc  = $this->clean( $this->attr( $xpath, '//meta[@name="description"]/@content' ) );

		// Only the page's own content: the site header, menus, sidebars and
		// footer (read once, from the homepage) are left out.
		$this->remove( $xpath, '//header[not(ancestor::main) and not(ancestor::article)] | //nav | //*[@role="navigation"] | //*[@role="banner"] | //aside | //footer | //*[@role="contentinfo"] | ' . self::FOOTER_QUERY );

		$root = $this->content_root( $xpath );
		$out  = array();
		if ( $root ) {
			$this->walk( $root, $base, $out );
		}

		$blocks = array();
		$chars  = 0;
		foreach ( $out as $block ) {
			$len = mb_strlen( $block['text'] );
			if ( $chars + $len > self::MAX_PAGE_CHARS || $this->chars + $len > self::MAX_TOTAL_CHARS ) {
				$this->truncated = true;
				break;
			}
			$chars       += $len;
			$this->chars += $len;
			$blocks[]     = array(
				'id'   => 'p' . $index . '.' . ( count( $blocks ) + 1 ),
				'type' => $block['type'],
				'text' => $block['text'],
			);
		}

		return array(
			'index'       => $index,
			'url'         => $url,
			'label'       => $label,
			'title'       => $title,
			'description' => $desc,
			'blocks'      => $blocks,
		);
	}

	/**
	 * The homepage footer as f.N blocks (contact, hours and address often
	 * live only there). Read before page() removes it.
	 */
	private function footer_blocks( \DOMXPath $xpath, $base ) {
		$nodes = $xpath->query( '//footer[not(ancestor::article)] | //*[@role="contentinfo"]' );
		if ( ! $nodes || ! $nodes->length ) {
			$nodes = $xpath->query( self::FOOTER_QUERY );
		}
		if ( ! $nodes || ! $nodes->length ) {
			return array();
		}

		$footer = $nodes->item( $nodes->length - 1 );
		$this->drop_noise( $xpath );
		$this->drop_menus( $xpath );
		$out    = array();
		if ( $this->attached( $footer ) ) {
			$this->walk( $footer, $base, $out );
		}

		$blocks = array();
		foreach ( array_slice( $out, 0, 60 ) as $block ) {
			$this->chars += mb_strlen( $block['text'] );
			$blocks[]     = array(
				'id'   => 'f.' . ( count( $blocks ) + 1 ),
				'type' => $block['type'],
				'text' => $block['text'],
			);
		}
		return $blocks;
	}

	/** <main>, else the only <article>, else <body>. */
	private function content_root( \DOMXPath $xpath ) {
		$this->drop_noise( $xpath );
		$this->drop_menus( $xpath );
		foreach ( array( '//main', '//*[@role="main"]' ) as $query ) {
			$nodes = $xpath->query( $query );
			if ( $nodes && $nodes->length ) {
				return $nodes->item( 0 );
			}
		}
		$articles = $xpath->query( '//article' );
		if ( $articles && 1 === $articles->length ) {
			return $articles->item( 0 );
		}
		$body = $xpath->query( '//body' );
		return $body && $body->length ? $body->item( 0 ) : null;
	}

	/** Code, forms, hidden elements, cookie banners and menus. */
	private function drop_noise( \DOMXPath $xpath ) {
		$this->remove( $xpath, '//' . implode( ' | //', self::DROP_TAGS ) );
		// Buttons that are controls ("Menu", "Add to cart"); a button holding
		// headings or paragraphs is content (menu apps wrap each dish in one).
		$this->remove( $xpath, '//button[not(.//h1 | .//h2 | .//h3 | .//h4 | .//h5 | .//h6 | .//p | .//li)]' );
		$this->remove( $xpath, '//*[contains(concat(" ", normalize-space(@class), " "), " screen-reader-text ") or contains(@class, "skip-link") or contains(concat(" ", normalize-space(@class), " "), " sr-only ") or contains(@class, "visually-hidden")]' );
		$this->remove( $xpath, '//*[@hidden] | //*[@aria-hidden="true"] | //*[contains(translate(@style, "DISPLAYNONE ", "displaynone"), "display:none")]' );
		$this->remove( $xpath, '//*[contains(translate(@id, "COKIENSTGDPR", "cokienstgdpr"), "cookie") or contains(translate(@class, "COKIENSTGDPR", "cokienstgdpr"), "cookie") or contains(translate(@id, "COKIENSTGDPR", "cokienstgdpr"), "consent") or contains(translate(@class, "COKIENSTGDPR", "cokienstgdpr"), "consent") or contains(@class, "gdpr") or contains(@id, "gdpr") or contains(@class, "cmplz") or contains(@id, "BorlabsCookie")]' );
	}

	/**
	 * Menus of themes that don't use <nav>: a container named nav/menu, or
	 * a list of three or more items, whose text is mostly link text. A menu
	 * card of dishes (text, not links) stays.
	 */
	private function drop_menus( \DOMXPath $xpath ) {
		$query = '//*[contains(translate(@class, "NAVMEU", "navmeu"), "nav") or contains(translate(@class, "NAVMEU", "navmeu"), "menu") or contains(translate(@id, "NAVMEU", "navmeu"), "nav") or contains(translate(@id, "NAVMEU", "navmeu"), "menu")] | //ul[count(li) >= 3] | //ol[count(li) >= 3]';
		foreach ( iterator_to_array( $xpath->query( $query ) ) as $node ) {
			if ( ! $this->attached( $node ) ) {
				continue;
			}
			$total = mb_strlen( $this->clean( $node->textContent ) );
			if ( ! $total || $total > 2000 ) {
				continue;
			}
			$linked = 0;
			foreach ( $xpath->query( './/a', $node ) as $a ) {
				$linked += mb_strlen( $this->clean( $a->textContent ) );
			}
			if ( $linked / $total >= 0.6 ) {
				$node->parentNode->removeChild( $node );
			}
		}
	}

	/** Still in the document (an ancestor may have been removed). */
	private function attached( $node ) {
		while ( $node && ! $node instanceof \DOMDocument ) {
			$node = $node->parentNode;
		}
		return (bool) $node;
	}

	/**
	 * Flatten an element into text blocks in reading order. A block is the
	 * text of an element with no block-level children (page builders put
	 * text in nested divs and spans), a table row, or the loose text between
	 * a container's block children.
	 */
	private function walk( \DOMNode $node, $base, array &$out ) {
		$run = '';
		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
				$run .= $child->nodeValue;
				continue;
			}
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}

			$tag = strtolower( $child->nodeName );
			$this->collect_background( $child, $base );

			if ( 'img' === $tag || 'picture' === $tag ) {
				$this->collect_images( $child, $base );
				continue;
			}
			if ( 'br' === $tag ) {
				$run .= "\n";
				continue;
			}
			if ( 'a' === $tag ) {
				$this->collect_contact_link( $child, $base );
			}

			if ( ! in_array( $tag, self::BLOCK_TAGS, true ) && ! $this->has_block_child( $child ) ) {
				$this->collect_images( $child, $base );
				foreach ( ( new \DOMXPath( $child->ownerDocument ) )->query( './/a[@href]', $child ) as $a ) {
					$this->collect_contact_link( $a, $base );
				}
				$run .= $this->inline_text( $child );
				continue;
			}

			$this->emit( $run, $node, $out );
			$run = '';

			if ( 'tr' === $tag ) {
				$cells = array();
				foreach ( $child->childNodes as $cell ) {
					if ( XML_ELEMENT_NODE === $cell->nodeType ) {
						$cells[] = $this->clean( $this->inline_text( $cell ) );
					}
				}
				$this->push( implode( ' | ', array_filter( $cells, 'strlen' ) ), 'row', $out );
				$this->collect_images( $child, $base );
				continue;
			}
			if ( preg_match( '/^h[1-6]$/', $tag ) ) {
				$this->push( $this->clean( $this->inline_text( $child ) ), $tag, $out );
				continue;
			}

			$this->walk( $child, $base, $out );
		}
		$this->emit( $run, $node, $out );
	}

	/** Loose text collected inside $parent, typed by its element. */
	private function emit( $run, \DOMNode $parent, array &$out ) {
		$tag  = strtolower( $parent->nodeName );
		$type = array(
			'li'         => 'li',
			'blockquote' => 'quote',
			'figcaption' => 'caption',
			'dt'         => 'term',
			'address'    => 'address',
		);
		$this->push( $this->clean( $run ), isset( $type[ $tag ] ) ? $type[ $tag ] : 'p', $out );
	}

	private function push( $text, $type, array &$out ) {
		if ( mb_strlen( $text ) < 2 ) {
			return;
		}
		$this->collect_text_contacts( $text );

		// Text already taken from an earlier page or the footer (a repeated
		// call-to-action strip, the opening hours box) is kept once.
		$key = mb_strtolower( preg_replace( '/\W+/u', '', $text ) );
		if ( '' === $key || isset( $this->seen[ $key ] ) ) {
			return;
		}
		$this->seen[ $key ] = true;

		$out[] = array(
			'type' => $type,
			'text' => $text,
		);
	}

	private function has_block_child( \DOMElement $el ) {
		foreach ( ( new \DOMXPath( $el->ownerDocument ) )->query( './/*', $el ) as $desc ) {
			if ( in_array( strtolower( $desc->nodeName ), self::BLOCK_TAGS, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Text of an inline element, <br> kept as a line break. */
	private function inline_text( \DOMNode $node ) {
		$text = '';
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType ) {
				$text .= 'br' === strtolower( $child->nodeName ) ? "\n" : $this->inline_text( $child );
			} elseif ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
				$text .= $child->nodeValue;
			}
		}
		return $text;
	}

	/** Whitespace collapsed per line, empty lines dropped. */
	private function clean( $text ) {
		$text  = str_replace( array( "\xc2\xa0", "\xe2\x80\x8b" ), array( ' ', '' ), (string) $text );
		$lines = array();
		foreach ( preg_split( '/\n/', $text ) as $line ) {
			$line = trim( preg_replace( '/\s+/u', ' ', $line ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return implode( "\n", $lines );
	}

	/* ---------------------------------------------------------------------
	 * Contact details, images, logo
	 * ------------------------------------------------------------------ */

	/** JSON-LD business data and social profiles, once per page. */
	private function collect_meta( \DOMXPath $xpath, $base ) {
		foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $script ) {
			$data = json_decode( trim( $script->textContent ), true );
			if ( is_array( $data ) ) {
				$this->collect_jsonld( $data );
			}
		}
		foreach ( $xpath->query( '//a[@href]' ) as $a ) {
			$this->collect_contact_link( $a, $base );
		}
	}

	/** Business-like JSON-LD nodes: anything with an address, phone or opening hours. */
	private function collect_jsonld( array $data ) {
		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			foreach ( $data['@graph'] as $node ) {
				if ( is_array( $node ) ) {
					$this->collect_jsonld( $node );
				}
			}
			return;
		}
		if ( isset( $data[0] ) ) {
			foreach ( $data as $node ) {
				if ( is_array( $node ) ) {
					$this->collect_jsonld( $node );
				}
			}
			return;
		}
		if ( empty( $data['address'] ) && empty( $data['telephone'] ) && empty( $data['openingHours'] ) && empty( $data['openingHoursSpecification'] ) ) {
			return;
		}

		$node = array();
		foreach ( array( 'name', 'telephone', 'email', 'priceRange' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				$node[ $key ] = $this->clean( $data[ $key ] );
			}
		}
		if ( isset( $data['@type'] ) ) {
			$node['type'] = is_array( $data['@type'] ) ? implode( ', ', $data['@type'] ) : (string) $data['@type'];
		}
		if ( isset( $data['address'] ) ) {
			$address = $data['address'];
			if ( is_array( $address ) ) {
				$address = implode( ', ', array_filter( array( $address['streetAddress'] ?? '', trim( ( $address['postalCode'] ?? '' ) . ' ' . ( $address['addressLocality'] ?? '' ) ) ) ) );
			}
			$node['address'] = $this->clean( (string) $address );
		}
		if ( isset( $data['openingHours'] ) ) {
			$node['openingHours'] = array_map( 'strval', (array) $data['openingHours'] );
		}
		if ( isset( $data['openingHoursSpecification'] ) && is_array( $data['openingHoursSpecification'] ) ) {
			$specs = isset( $data['openingHoursSpecification'][0] ) ? $data['openingHoursSpecification'] : array( $data['openingHoursSpecification'] );
			foreach ( $specs as $spec ) {
				if ( is_array( $spec ) ) {
					$days = isset( $spec['dayOfWeek'] ) ? implode( ', ', array_map( static function ( $d ) {
						return preg_replace( '#^https?://schema\.org/#', '', (string) $d );
					}, (array) $spec['dayOfWeek'] ) ) : '';
					$node['openingHours'][] = trim( $days . ' ' . ( $spec['opens'] ?? '' ) . '-' . ( $spec['closes'] ?? '' ) );
				}
			}
		}
		if ( isset( $data['sameAs'] ) ) {
			foreach ( (array) $data['sameAs'] as $url ) {
				$this->collect_social( (string) $url );
			}
		}
		if ( $node && count( $this->jsonld ) < 5 && ! in_array( $node, $this->jsonld, true ) ) {
			$this->jsonld[] = $node;
		}
	}

	private function collect_contact_link( \DOMElement $a, $base ) {
		$href = trim( (string) $a->getAttribute( 'href' ) );
		if ( 0 === stripos( $href, 'tel:' ) ) {
			$phone = $this->clean( rawurldecode( substr( $href, 4 ) ) );
			if ( preg_match( '/\d{5,}/', preg_replace( '/\D/', '', $phone ) ) ) {
				$this->phones[] = $phone;
			}
		} elseif ( 0 === stripos( $href, 'mailto:' ) ) {
			$email = strtolower( strtok( rawurldecode( substr( $href, 7 ) ), '?' ) );
			if ( is_email( $email ) ) {
				$this->emails[] = $email;
			}
		} else {
			$url = $this->absolute( $href, $base );
			$this->collect_social( $url );
			$this->collect_maps( $url );
		}
	}

	/**
	 * The address in a Google Maps link ("…/maps/search/?api=1&query=Str.+
	 * Mitropolit+Petru+Movilă+14"): sites built from menu apps and widgets
	 * often state their address only there.
	 */
	private function collect_maps( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || ! preg_match( '/(^|\.)google\.[a-z.]+$/i', $parts['host'] ) || empty( $parts['path'] ) || 0 !== strpos( $parts['path'], '/maps' ) || empty( $parts['query'] ) ) {
			return;
		}
		parse_str( $parts['query'], $query );
		foreach ( array( 'query', 'q', 'destination', 'daddr' ) as $key ) {
			$address = isset( $query[ $key ] ) && is_string( $query[ $key ] ) ? $this->clean( $query[ $key ] ) : '';
			// An address, not coordinates or a place id.
			if ( '' !== $address && preg_match( '/\p{L}{3,}/u', $address ) && mb_strlen( $address ) <= 160 ) {
				$this->maps[] = $address;
				return;
			}
		}
	}

	private function collect_text_contacts( $text ) {
		if ( preg_match_all( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,24}/i', $text, $m ) ) {
			foreach ( $m[0] as $email ) {
				if ( is_email( $email ) ) {
					$this->emails[] = strtolower( $email );
				}
			}
		}
	}

	private function collect_social( $url ) {
		$host = $this->bare_host( $url );
		if ( isset( self::SOCIAL_HOSTS[ $host ] ) && ! isset( $this->social[ self::SOCIAL_HOSTS[ $host ] ] ) ) {
			$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
			// A profile, not the network's home page or a share button.
			if ( '' !== $path && ! preg_match( '#^(sharer|share|intent|dialog|plugins)#i', $path ) ) {
				$this->social[ self::SOCIAL_HOSTS[ $host ] ] = esc_url_raw( $url );
			}
		}
	}

	/** <img> and <picture> sources inside $node (or $node itself). */
	private function collect_images( \DOMNode $node, $base ) {
		if ( count( $this->images ) >= self::MAX_IMAGES || ! $node instanceof \DOMElement ) {
			return;
		}
		$imgs = 'img' === strtolower( $node->nodeName ) ? array( $node ) : iterator_to_array( ( new \DOMXPath( $node->ownerDocument ) )->query( './/img', $node ) );
		foreach ( $imgs as $img ) {
			$src = '';
			foreach ( array( 'data-src', 'data-lazy-src', 'data-original', 'src' ) as $attr ) {
				$value = trim( (string) $img->getAttribute( $attr ) );
				if ( '' !== $value && 0 !== strpos( $value, 'data:' ) ) {
					$src = $value;
					break;
				}
			}
			$srcset = (string) ( $img->getAttribute( 'data-srcset' ) ? $img->getAttribute( 'data-srcset' ) : $img->getAttribute( 'srcset' ) );
			if ( '' !== $srcset ) {
				$best = $this->largest_in_srcset( $srcset );
				$src  = '' !== $best ? $best : $src;
			}
			$width  = (int) $img->getAttribute( 'width' );
			$height = (int) $img->getAttribute( 'height' );
			// Icons, spacers and tracking pixels.
			if ( ( $width && $width < 120 ) || ( $height && $height < 120 ) ) {
				continue;
			}
			$this->add_image( $src, $this->clean( (string) $img->getAttribute( 'alt' ) ), $base );
		}
	}

	/** Page builders' hero and gallery images live in inline background styles. */
	private function collect_background( \DOMElement $el, $base ) {
		$style = (string) $el->getAttribute( 'style' );
		if ( '' !== $style && preg_match( '/background(?:-image)?\s*:[^;]*url\(\s*[\'"]?([^\'")]+)/i', $style, $m ) ) {
			$this->add_image( html_entity_decode( $m[1] ), '', $base );
		}
	}

	private function add_image( $src, $alt, $base ) {
		if ( count( $this->images ) >= self::MAX_IMAGES ) {
			return;
		}
		$url = $this->absolute( $src, $base );
		if ( '' === $url || preg_match( '/\.(svg|gif|ico)(\?|$)/i', $url ) ) {
			return;
		}
		$host = $this->bare_host( $url );
		$key  = $host . (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( isset( $this->images[ $key ] ) ) {
			return;
		}
		$this->images[ $key ] = array(
			'url'  => $url,
			'alt'  => mb_substr( $alt, 0, 140 ),
			'page' => $this->current,
			// Only the owner's own files are reused; anything else
			// (a stock-library hotlink, an embedded widget) is skipped later.
			'own' => $host === $this->host || (bool) preg_match( self::IMAGE_CDNS, $host ),
		);
	}

	private function largest_in_srcset( $srcset ) {
		$best  = '';
		$width = 0;
		foreach ( explode( ',', $srcset ) as $candidate ) {
			$parts = preg_split( '/\s+/', trim( $candidate ) );
			$w     = isset( $parts[1] ) ? (int) $parts[1] : 1;
			if ( '' !== $parts[0] && $w >= $width ) {
				$best  = $parts[0];
				$width = $w;
			}
		}
		return $best;
	}

	/** An image in the header marked as the logo, else the header's first image. */
	private function logo( \DOMXPath $xpath, $base ) {
		$queries = array(
			'//header//img[contains(translate(concat(@class, " ", @id, " ", @alt, " ", @src), "LOGO", "logo"), "logo")]',
			'//*[contains(translate(concat(@class, " ", @id), "LOGO", "logo"), "logo")]//img',
			'//header//img',
		);
		foreach ( $queries as $query ) {
			$nodes = $xpath->query( $query );
			if ( $nodes && $nodes->length ) {
				$img = $nodes->item( 0 );
				$src = (string) ( $img->getAttribute( 'data-src' ) ? $img->getAttribute( 'data-src' ) : $img->getAttribute( 'src' ) );
				if ( '' !== $src && 0 !== strpos( $src, 'data:' ) ) {
					return $this->absolute( $src, $base );
				}
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function dom( $html ) {
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		// The hint makes DOMDocument read the bytes as UTF-8, not Latin-1.
		$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$this->decode_cf_emails( $doc );
		return $doc;
	}

	/**
	 * Cloudflare's email protection shows "[email protected]" and keeps the
	 * address XOR-encoded in data-cfemail or the link's #fragment: put the
	 * real address back, as text and as a mailto: link.
	 */
	private function decode_cf_emails( \DOMDocument $doc ) {
		$xpath = new \DOMXPath( $doc );
		foreach ( iterator_to_array( $xpath->query( '//*[@data-cfemail] | //a[contains(@href, "/cdn-cgi/l/email-protection#")]' ) ) as $node ) {
			$hex   = $node->hasAttribute( 'data-cfemail' ) ? $node->getAttribute( 'data-cfemail' ) : substr( strrchr( $node->getAttribute( 'href' ), '#' ), 1 );
			$email = $this->cf_decode( $hex );
			if ( '' === $email ) {
				continue;
			}
			if ( 'a' === strtolower( $node->nodeName ) ) {
				$node->setAttribute( 'href', 'mailto:' . $email );
				if ( false !== stripos( $node->textContent, 'protected' ) && ! $xpath->query( './/*[@data-cfemail]', $node )->length ) {
					$node->nodeValue = $email;
				}
			} else {
				$node->nodeValue = $email;
			}
		}
	}

	private function cf_decode( $hex ) {
		if ( ! preg_match( '/^(?:[0-9a-f]{2}){2,}$/i', (string) $hex ) ) {
			return '';
		}
		$key   = hexdec( substr( $hex, 0, 2 ) );
		$email = '';
		for ( $i = 2, $n = strlen( $hex ); $i < $n; $i += 2 ) {
			$email .= chr( hexdec( substr( $hex, $i, 2 ) ) ^ $key );
		}
		return is_email( $email ) ? $email : '';
	}

	private function to_utf8( $html, $content_type ) {
		$charset = '';
		if ( preg_match( '/charset=([\w-]+)/i', $content_type, $m ) ) {
			$charset = $m[1];
		} elseif ( preg_match( '/<meta[^>]+charset=["\']?([\w-]+)/i', substr( $html, 0, 4096 ), $m ) ) {
			$charset = $m[1];
		}
		$charset = strtoupper( $charset );
		if ( '' !== $charset && 'UTF-8' !== $charset && 'UTF8' !== $charset && function_exists( 'mb_convert_encoding' ) ) {
			$converted = @mb_convert_encoding( $html, 'UTF-8', $charset ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_string( $converted ) && '' !== $converted ) {
				return $converted;
			}
		}
		return $html;
	}

	private function remove( \DOMXPath $xpath, $query ) {
		$nodes = $xpath->query( $query );
		foreach ( $nodes ? iterator_to_array( $nodes ) : array() as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
	}

	private function attr( \DOMXPath $xpath, $query ) {
		$nodes = $xpath->query( $query );
		return $nodes && $nodes->length ? trim( (string) $nodes->item( 0 )->nodeValue ) : '';
	}

	private function base_url( \DOMXPath $xpath, $url ) {
		$base = $this->attr( $xpath, '//base/@href' );
		return '' !== $base ? $this->absolute( $base, $url ) : $url;
	}

	private function absolute( $url, $base ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( wp_parse_url( $base, PHP_URL_SCHEME ) ?: 'https' ) . ':' . $url;
		}
		$url = \WP_Http::make_absolute_url( $url, $base );
		return preg_match( '#^https?://#i', $url ) ? $url : '';
	}

	private function normalize_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}
		$url  = esc_url_raw( $url, array( 'http', 'https' ) );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return false !== strpos( $host, '.' ) ? $url : '';
	}

	/** Host without "www.", lowercase. */
	private function bare_host( $url ) {
		return preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
	}
}
