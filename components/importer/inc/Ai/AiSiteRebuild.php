<?php
/**
 * "Rebuild my website" (Inspiro Premium): read the customer's existing
 * website and rebuild it on an Inspiro design with its own words.
 *
 * Reading. SiteReader fetches the homepage and menu pages on this server;
 * the proxy task site-extract organizes their text into a profile that
 * points at the site's own text blocks by id; check_profile() then keeps
 * only the ids and facts (phone, email, address, opening hours, names,
 * prices) that really are in the text that was read, so nothing the model
 * made up can reach a page.
 *
 * Rebuilding. The demo generator's catalog pipeline runs with this class
 * as its content source (POST "source" = the profile's key): site-blueprint
 * maps catalog sections onto the old site's blocks, site-copy fills their
 * fields with references to those blocks, and resolve_copy() inserts the
 * referenced text verbatim and rejects any new wording that brings numbers
 * or words the old site doesn't have. The pages land as drafts (keep mode),
 * with the old site's own photos.
 *
 *   inspiro_starter_sites_ai_site_read — URL + ownership consent → profile
 *                                        summary for the modal's card.
 *
 * WP-CLI: wp inspiro-starter-sites-ai site-read <url> [--profile] [--fresh]
 *
 * @package Inspiro Starter Sites
 */

namespace Inspiro\Starter_Sites\Ai;

use Inspiro\Starter_Sites\Helpers;

defined( 'ABSPATH' ) || exit;

class AiSiteRebuild {

	/** Quota feature slug on the proxy, used once rebuilding is metered. */
	const FEATURE = 'site-rebuild';

	/** Transient prefix: the read site + profile, per URL, for a day. */
	const CACHE_PREFIX = 'iss_ai_site_';

	/** Option: who confirmed owning which site, and when (last 20). */
	const CONSENT_OPTION = 'inspiro_starter_sites_ai_rebuild_consent';

	/** Between blocks in the joined source text. */
	const SEP = "\x1E";

	/** Post meta on photos taken from the old site: their source URL. */
	const IMAGE_META_KEY = '_iss_site_image_url';

	/** New wording the copy may add: headings and labels of at most this many words. */
	const MAX_LABEL_WORDS = 6;

	/** Below this, there is too little text to rebuild from. */
	const MIN_WORDS = 60;

	/** @var AiProxyClient */
	private $proxy;

	/** Facts check_profile() removed, kept on the profile for review. */
	private $dropped = array();

	/** @var AiSiteRebuild|null */
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->proxy = new AiProxyClient();

		add_action( 'wp_ajax_inspiro_starter_sites_ai_site_read', array( $this, 'ajax_read' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'inspiro-starter-sites-ai site-read', array( $this, 'cli_read' ) );
		}
	}

	/**
	 * Whether the modal offers it: an Inspiro Premium license, and (until
	 * the rebuild itself ships) the feature switched on for testing.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) apply_filters( 'inspiro_starter_sites_ai_rebuild_enabled', defined( 'INSPIRO_STARTER_SITES_AI_REBUILD' ) && INSPIRO_STARTER_SITES_AI_REBUILD );
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	public function ajax_read() {
		Helpers::verify_ajax_call();

		if ( '' === AiProxyClient::premium_license() ) {
			wp_send_json_error(
				array(
					'code'    => 'license_required',
					'message' => esc_html__( 'Rebuilding an existing website needs Inspiro Premium with an active license.', 'inspiro-starter-sites' ),
				)
			);
		}

		$url     = isset( $_POST['url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['url'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$consent = isset( $_POST['consent'] ) && '1' === $_POST['consent'];
		$fresh   = isset( $_POST['fresh'] ) && '1' === $_POST['fresh'];

		if ( ! $consent ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please confirm that you own this website or may reuse its content.', 'inspiro-starter-sites' ) ) );
		}

		// Up to six page fetches plus one AI call: keep the connection alive.
		$stream = new StreamingResponse();
		$stream->begin();

		$this->log_consent( $url );

		$result = $this->read( $url, array( $stream, 'tick' ), $fresh );
		if ( is_wp_error( $result ) ) {
			$stream->finish_error(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
		}

		$stream->finish_success( $this->summary( $result ) );
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------ */

	/**
	 * Read a site into a checked profile (cached for a day per URL).
	 *
	 * @param string        $url       Address as typed.
	 * @param callable|null $heartbeat Keep-alive tick.
	 * @param bool          $fresh     Ignore the cache.
	 * @return array|\WP_Error [ 'key', 'site', 'profile' ]
	 */
	public function read( $url, $heartbeat = null, $fresh = false ) {
		$key = self::CACHE_PREFIX . md5( strtolower( preg_replace( '#^https?://(www\.)?|/+$#i', '', trim( (string) $url ) ) ) );

		if ( ! $fresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) && ! empty( $cached['profile'] ) ) {
				return $cached;
			}
		}

		$site = ( new SiteReader() )->read( $url, $heartbeat );
		if ( is_wp_error( $site ) ) {
			return $site;
		}

		if ( $site['words'] < self::MIN_WORDS ) {
			return new \WP_Error(
				'site_thin',
				__( 'We could read almost no text on this website. It is probably built with JavaScript that only runs in a browser (common with Wix and some Jimdo or Squarespace sites).', 'inspiro-starter-sites' )
			);
		}

		$profile = $this->proxy->claude_task_json( 'site-extract', $this->task_vars( $site ), $heartbeat );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$result = array(
			'key'     => $key,
			'site'    => $site,
			'profile' => $this->check_profile( $profile, $site ),
		);

		set_transient( $key, $result, DAY_IN_SECONDS );

		return $result;
	}

	/** The site as the task's numbered blocks. */
	private function task_vars( array $site ) {
		$rows = static function ( array $blocks ) {
			return array_map(
				static function ( $block ) {
					return array( $block['id'], $block['type'], $block['text'] );
				},
				$blocks
			);
		};

		$pages = array();
		foreach ( $site['pages'] as $page ) {
			$pages[] = array(
				'id'          => 'p' . $page['index'],
				'label'       => $page['label'],
				'title'       => $page['title'],
				'description' => $page['description'],
				'blocks'      => $rows( $page['blocks'] ),
			);
		}

		return array(
			'url'       => $site['url'],
			'lang'      => $site['lang'],
			'site_name' => $site['site_name'],
			'pages'     => $pages,
			'footer'    => $rows( $site['footer'] ),
			'hints'     => array(
				'phones' => $site['contact']['phones'],
				'emails' => $site['contact']['emails'],
				'jsonld' => $site['contact']['jsonld'],
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Checking the profile against the text that was read
	 * ------------------------------------------------------------------ */

	/**
	 * Keep only what the site really says: block ids that exist, and facts
	 * found in the text (or, for phone and email, in its links). Everything
	 * else is dropped and the gaps are recomputed from what is left.
	 *
	 * @param array $raw  The model's profile.
	 * @param array $site SiteReader result.
	 * @return array
	 */
	public function check_profile( array $raw, array $site ) {
		$this->dropped = array();
		$blocks        = array();
		foreach ( $site['footer'] as $block ) {
			$blocks[ $block['id'] ] = $block['text'];
		}
		$pages = array();
		foreach ( $site['pages'] as $page ) {
			$pages[ 'p' . $page['index'] ] = $page;
			foreach ( $page['blocks'] as $block ) {
				$blocks[ $block['id'] ] = $block['text'];
			}
		}

		// Blocks apart (record separator), so a fact never matches across two.
		$all   = implode( self::SEP, $blocks );
		$hints = implode( "\n", array_merge( $site['contact']['phones'], $site['contact']['emails'], array_map( 'wp_json_encode', $site['contact']['jsonld'] ) ) );
		$used  = array();

		$business = isset( $raw['business'] ) && is_array( $raw['business'] ) ? $raw['business'] : array();
		$contact  = isset( $raw['contact'] ) && is_array( $raw['contact'] ) ? $raw['contact'] : array();

		// The name often stands only in the page titles ("Weinbiet | Weinbiet Manufaktur eG").
		$titles = implode( self::SEP, array_merge( array_column( $site['pages'], 'title' ), array_column( $site['pages'], 'description' ) ) );
		$name   = $this->fact( isset( $business['name'] ) ? $business['name'] : '', $all . self::SEP . $hints . self::SEP . $site['site_name'] . self::SEP . $titles );

		$profile = array(
			'language'     => isset( $raw['language'] ) && preg_match( '/^[a-z]{2}$/', (string) $raw['language'] ) ? (string) $raw['language'] : substr( (string) $site['lang'], 0, 2 ),
			'business'     => array(
				'name'        => $name,
				'kind'        => isset( $business['kind'] ) ? sanitize_text_field( (string) $business['kind'] ) : '',
				'tagline_ref' => $this->ref( isset( $business['tagline_ref'] ) ? $business['tagline_ref'] : '', $blocks ),
				'summary'     => isset( $business['summary'] ) ? sanitize_text_field( (string) $business['summary'] ) : '',
			),
			'contact'      => array(
				'phones'     => $this->phones( isset( $contact['phones'] ) ? (array) $contact['phones'] : array(), $all . "\n" . $hints ),
				'emails'     => $this->emails( isset( $contact['emails'] ) ? (array) $contact['emails'] : array(), $all . "\n" . $hints ),
				'address'    => $this->fact( isset( $contact['address'] ) ? $contact['address'] : '', $all, true ),
				// Hours lines are copied whole: a word-by-word match would let
				// "Montag: 10 bis 18 Uhr" pass on numbers found anywhere near.
				'hours'      => array_values( array_filter( array_map(
					function ( $line ) use ( $all ) {
						return $this->fact( $line, $all );
					},
					isset( $contact['hours'] ) ? array_slice( (array) $contact['hours'], 0, 14 ) : array()
				) ) ),
				'hours_refs' => $this->refs( isset( $contact['hours_refs'] ) ? (array) $contact['hours_refs'] : array(), $blocks, $used, false ),
				'social'     => $site['social'],
			),
			'locations'    => array(),
			'pages'        => array(),
			'offerings'    => array(),
			'testimonials' => array(),
			'team'         => array(),
			'drop'         => $this->refs( isset( $raw['drop'] ) ? (array) $raw['drop'] : array(), $blocks, $used, false ),
			'legal'        => $site['legal'],
			'logo'         => $site['logo'],
			'images'       => array_values( array_filter( $site['images'], static function ( $image ) {
				return ! empty( $image['own'] );
			} ) ),
		);

		foreach ( isset( $raw['locations'] ) ? array_slice( (array) $raw['locations'], 0, 6 ) : array() as $location ) {
			if ( ! is_array( $location ) ) {
				continue;
			}
			$address = $this->fact( isset( $location['address'] ) ? $location['address'] : '', $all, true );
			if ( '' !== $address ) {
				$profile['locations'][] = array(
					'name'    => $this->fact( isset( $location['name'] ) ? $location['name'] : '', $all ),
					'address' => $address,
					'phone'   => implode( '', $this->phones( isset( $location['phone'] ) ? array( $location['phone'] ) : array(), $all . "\n" . $hints ) ),
					'hours'   => array_values( array_filter( array_map(
						function ( $line ) use ( $all ) {
							return $this->fact( $line, $all );
						},
						isset( $location['hours'] ) ? array_slice( (array) $location['hours'], 0, 14 ) : array()
					) ) ),
				);
			}
		}

		// Several locations and no main one named: the first location is it.
		if ( '' === $profile['contact']['address'] && $profile['locations'] ) {
			$main = $profile['locations'][0];
			$profile['contact']['address'] = $main['address'];
			if ( ! $profile['contact']['hours'] ) {
				$profile['contact']['hours'] = $main['hours'];
			}
		}

		// Sections: ids that exist, each once, never a dropped one.
		$dropped = array_flip( $profile['drop'] );
		foreach ( isset( $raw['pages'] ) ? (array) $raw['pages'] : array() as $entry ) {
			$id = is_array( $entry ) && isset( $entry['page'] ) ? (string) $entry['page'] : '';
			if ( ! isset( $pages[ $id ] ) ) {
				continue;
			}
			$sections = array();
			foreach ( isset( $entry['sections'] ) ? (array) $entry['sections'] : array() as $section ) {
				if ( ! is_array( $section ) ) {
					continue;
				}
				$heading = $this->ref( isset( $section['heading'] ) ? $section['heading'] : '', $blocks );
				if ( '' !== $heading && ( isset( $used[ $heading ] ) || isset( $dropped[ $heading ] ) ) ) {
					$heading = '';
				}
				if ( '' !== $heading ) {
					$used[ $heading ] = true;
				}
				$refs = array_values( array_diff( $this->refs( isset( $section['refs'] ) ? (array) $section['refs'] : array(), $blocks, $used, true ), $profile['drop'] ) );
				if ( '' === $heading && ! $refs ) {
					continue;
				}
				$sections[] = array(
					'kind'    => isset( $section['kind'] ) ? sanitize_key( (string) $section['kind'] ) : 'other',
					'heading' => $heading,
					'refs'    => $refs,
				);
			}
			$profile['pages'][] = array(
				'page'     => $id,
				'url'      => $pages[ $id ]['url'],
				'label'    => $pages[ $id ]['label'],
				'purpose'  => isset( $entry['purpose'] ) ? sanitize_key( (string) $entry['purpose'] ) : 'other',
				'keep'     => ! isset( $entry['keep'] ) || false !== $entry['keep'],
				'sections' => $sections,
			);
		}

		// A shop lists name, price and unit price as separate blocks: an
		// offering's facts must be in its block or the two after it.
		$order = array_keys( $blocks );
		$index = array_flip( $order );
		foreach ( isset( $raw['offerings'] ) ? array_slice( (array) $raw['offerings'], 0, 24 ) : array() as $item ) {
			$ref = is_array( $item ) ? $this->ref( isset( $item['ref'] ) ? $item['ref'] : '', $blocks ) : '';
			if ( '' === $ref ) {
				continue;
			}
			$near = array();
			foreach ( array_slice( $order, $index[ $ref ], 3 ) as $id ) {
				$near[] = $blocks[ $id ];
			}
			$near = implode( self::SEP, $near );
			$name = $this->fact( isset( $item['name'] ) ? $item['name'] : '', $near );
			if ( '' !== $name ) {
				$profile['offerings'][] = array(
					'name'  => $name,
					'price' => $this->fact( isset( $item['price'] ) ? $item['price'] : '', $near ),
					'ref'   => $ref,
				);
			}
		}

		foreach ( isset( $raw['testimonials'] ) ? array_slice( (array) $raw['testimonials'], 0, 12 ) : array() as $item ) {
			$ref = is_array( $item ) ? $this->ref( isset( $item['ref'] ) ? $item['ref'] : '', $blocks ) : '';
			if ( '' !== $ref ) {
				$profile['testimonials'][] = array(
					'ref'    => $ref,
					'author' => $this->fact( isset( $item['author'] ) ? $item['author'] : '', $all ),
				);
			}
		}

		foreach ( isset( $raw['team'] ) ? array_slice( (array) $raw['team'], 0, 20 ) : array() as $person ) {
			$name = is_array( $person ) ? $this->fact( isset( $person['name'] ) ? $person['name'] : '', $all ) : '';
			if ( '' !== $name ) {
				$profile['team'][] = array(
					'name' => $name,
					'role' => $this->fact( isset( $person['role'] ) ? $person['role'] : '', $all ),
					'ref'  => $this->ref( isset( $person['ref'] ) ? $person['ref'] : '', $blocks ),
				);
			}
		}

		$gaps = array();
		foreach ( array( 'phone' => 'phones', 'email' => 'emails', 'address' => 'address', 'hours' => 'hours' ) as $gap => $field ) {
			if ( empty( $profile['contact'][ $field ] ) ) {
				$gaps[] = $gap;
			}
		}
		$profile['gaps']    = $gaps;
		$profile['dropped'] = array_values( array_unique( $this->dropped ) );

		return $profile;
	}

	/**
	 * A fact as the model copied it, kept only when the source holds it:
	 * the same characters once spacing and dash styles are ignored, or —
	 * with $tokens, for an address or hours line the model joined from
	 * several lines — every word and number of it within three neighboring
	 * blocks.
	 *
	 * @param mixed  $value  Model output.
	 * @param string $source Text it must come from.
	 * @param bool   $tokens Accept a match of all its words.
	 * @return string '' when not found.
	 */
	private function fact( $value, $source, $tokens = false ) {
		$value = is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
		if ( '' === $value || mb_strlen( $value ) > 300 ) {
			return '';
		}
		// The task shows a line break inside a block as " / ", and the model
		// copies it that way: match against both spellings of the source.
		$needle = $this->squash( $value );
		foreach ( array( $source, str_replace( "\n", ' / ', (string) $source ) ) as $spelling ) {
			if ( false !== mb_strpos( $this->squash( $spelling ), $needle ) ) {
				return $value;
			}
		}
		if ( ! $tokens ) {
			$this->dropped[] = $value;
			return '';
		}
		preg_match_all( '/[\p{L}\p{N}]+/u', mb_strtolower( $value ), $words );
		$words = array_filter(
			$words[0],
			static function ( $word ) {
				return mb_strlen( $word ) > 1 || ctype_digit( $word );
			}
		);
		if ( ! $words ) {
			$this->dropped[] = $value;
			return '';
		}
		$parts = explode( self::SEP, (string) $source );
		foreach ( array_keys( $parts ) as $i ) {
			$window = $this->squash( implode( ' ', array_slice( $parts, $i, 3 ) ) );
			foreach ( $words as $word ) {
				if ( false === mb_strpos( $window, $word ) ) {
					continue 2;
				}
			}
			return $value;
		}
		$this->dropped[] = $value;
		return '';
	}

	/** Lowercase, no whitespace, one dash style: "Fr : 15–21" ≈ "fr:15-21". */
	private function squash( $text ) {
		$text = str_replace( array( "\xe2\x80\x93", "\xe2\x80\x94", "\xe2\x80\x92", "\xe2\x88\x92" ), '-', (string) $text );
		return mb_strtolower( preg_replace( '/[\s\x{00A0}\x{202F}]+/u', '', $text ) );
	}

	/** Phone numbers whose digits appear in the source (a tel: link counts). */
	private function phones( array $values, $source ) {
		$found = array();
		foreach ( array_slice( $values, 0, 6 ) as $value ) {
			$value = is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
			// The number, not its label ("Tel.: 06181-50 89 990").
			$value = preg_replace( '/^(tel(efon)?|phone|telephone|fon|t|call)\b\.?\s*:?\s*/i', '', $value );
			$plain = preg_replace( '/\D/', '', $value );
			if ( strlen( $plain ) < 5 ) {
				continue;
			}
			// The digits of each source line run together, so "06181 20215" matches 0618120215.
			$hit = false;
			foreach ( preg_split( '/[\n\x1E]/', $source ) as $line ) {
				if ( false !== strpos( preg_replace( '/\D/', '', $line ), $plain ) ) {
					$hit = true;
					break;
				}
			}
			if ( $hit ) {
				$found[] = $value;
			} else {
				$this->dropped[] = $value;
			}
		}
		return array_values( array_unique( $found ) );
	}

	private function emails( array $values, $source ) {
		$found = array();
		foreach ( array_slice( $values, 0, 6 ) as $value ) {
			$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
			if ( is_email( $value ) && false !== stripos( $source, $value ) ) {
				$found[] = $value;
			} elseif ( '' !== $value ) {
				$this->dropped[] = $value;
			}
		}
		return array_values( array_unique( $found ) );
	}

	private function ref( $id, array $blocks ) {
		$id = is_scalar( $id ) ? (string) $id : '';
		return isset( $blocks[ $id ] ) ? $id : '';
	}

	/**
	 * @param array $ids    Model ids.
	 * @param array $blocks Known blocks.
	 * @param array $used   Ids taken by earlier sections (updated when $claim).
	 * @param bool  $claim  Take the ids, so no later section repeats them.
	 * @return string[]
	 */
	private function refs( array $ids, array $blocks, array &$used, $claim ) {
		$out = array();
		foreach ( $ids as $id ) {
			$id = $this->ref( $id, $blocks );
			if ( '' === $id || isset( $used[ $id ] ) || in_array( $id, $out, true ) ) {
				continue;
			}
			$out[] = $id;
			if ( $claim ) {
				$used[ $id ] = true;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Rebuilding: inputs for the demo generator's catalog pipeline
	 * ------------------------------------------------------------------ */

	/**
	 * A read site by its key (see read()), or null once the day-long cache
	 * expired — the modal then reads the site again.
	 *
	 * @param string $key Cache key.
	 * @return array|null
	 */
	public function load( $key ) {
		$key    = sanitize_key( (string) $key );
		$result = 0 === strpos( $key, self::CACHE_PREFIX ) ? get_transient( $key ) : false;
		return is_array( $result ) && ! empty( $result['profile'] ) ? $result : null;
	}

	/** One line about the business, standing in for the demo description. */
	public function describe( array $result ) {
		$business = $result['profile']['business'];
		return trim( sprintf( 'Rebuild of %s — %s, %s. %s', $result['site']['host'], $business['name'], $business['kind'], $business['summary'] ) );
	}

	/**
	 * The pages to rebuild, as the generator's review step sends them:
	 * the customer's list when given (phase 3 UI), else every kept page of
	 * the profile in menu order, homepage first, at most $max.
	 *
	 * @param mixed $raw    Pages posted by the modal, or null.
	 * @param array $result read() result.
	 * @param int   $max    Most pages.
	 * @return array [ [ slug, title, brief, source ] ]
	 */
	public function approved_pages( $raw, array $result, $max = 5 ) {
		$by_source = array();
		foreach ( $result['profile']['pages'] as $page ) {
			$by_source[ $page['page'] ] = $page;
		}

		$wanted = array();
		if ( is_array( $raw ) && $raw ) {
			foreach ( $raw as $entry ) {
				if ( is_array( $entry ) && isset( $entry['source'], $by_source[ (string) $entry['source'] ] ) ) {
					$wanted[] = array(
						'source' => (string) $entry['source'],
						'title'  => isset( $entry['title'] ) ? sanitize_text_field( (string) $entry['title'] ) : '',
					);
				}
			}
		} else {
			foreach ( $by_source as $id => $page ) {
				if ( $page['keep'] && $page['sections'] && ! in_array( $page['purpose'], array( 'news', 'jobs' ), true ) ) {
					$wanted[] = array(
						'source' => $id,
						'title'  => $page['label'],
					);
				}
			}
		}

		// The old homepage becomes the new one, first.
		usort(
			$wanted,
			static function ( $a, $b ) {
				return ( 'p0' === $b['source'] ) <=> ( 'p0' === $a['source'] );
			}
		);

		$pages = array();
		$slugs = array();
		foreach ( array_slice( $wanted, 0, $max ) as $entry ) {
			$title = mb_substr( '' !== $entry['title'] ? $entry['title'] : $by_source[ $entry['source'] ]['label'], 0, 120 );
			$slug  = 'p0' === $entry['source'] ? 'home' : sanitize_title( $title );
			$slug  = '' === $slug ? 'page-' . substr( $entry['source'], 1 ) : $slug;
			$base  = $slug;
			for ( $n = 2; isset( $slugs[ $slug ] ); $n++ ) {
				$slug = $base . '-' . $n;
			}
			$slugs[ $slug ] = true;
			$pages[]        = array(
				'slug'   => $slug,
				'title'  => $title,
				'brief'  => 'Rebuilt from the "' . $by_source[ $entry['source'] ]['label'] . '" page of ' . $result['site']['host'] . '.',
				'source' => $entry['source'],
			);
		}
		return $pages;
	}

	/**
	 * Variables of the site-blueprint task (the demo generator adds its
	 * style, font and catalog variables).
	 *
	 * @param array $result   read() result.
	 * @param array $approved approved_pages().
	 * @return array
	 */
	public function blueprint_vars( array $result, array $approved ) {
		$blocks  = $this->blocks_of( $result );
		$types   = $this->types_of( $result );
		$profile = $result['profile'];
		$dropped = array_flip( $profile['drop'] );

		$content = array();
		foreach ( $profile['pages'] as $page ) {
			$sections = array();
			$covered  = array();
			foreach ( $page['sections'] as $section ) {
				$ids = array_merge( '' !== $section['heading'] ? array( $section['heading'] ) : array(), $section['refs'] );
				$sections[] = array(
					'kind'   => $section['kind'],
					'blocks' => $this->rows( $ids, $blocks, $types ),
				);
				$covered += array_flip( $ids );
			}
			// Blocks the profile left in no section and didn't drop still belong to the page.
			$rest = array();
			foreach ( array_keys( $blocks ) as $id ) {
				if ( 0 === strpos( $id, $page['page'] . '.' ) && ! isset( $covered[ $id ] ) && ! isset( $dropped[ $id ] ) ) {
					$rest[] = $id;
				}
			}
			if ( $rest ) {
				$sections[] = array(
					'kind'   => 'other',
					'blocks' => $this->rows( $rest, $blocks, $types ),
				);
			}
			$content[] = array(
				'id'       => $page['page'],
				'label'    => $page['label'],
				'sections' => $sections,
			);
		}

		$footer = array();
		foreach ( $result['site']['footer'] as $block ) {
			if ( ! isset( $dropped[ $block['id'] ] ) ) {
				$footer[] = array( $block['id'], $block['type'], $block['text'] );
			}
		}

		return array(
			'site'    => $this->site_facts( $result ),
			'pages'   => array_map(
				static function ( $page ) {
					return array_intersect_key( $page, array_flip( array( 'slug', 'title', 'source' ) ) );
				},
				$approved
			),
			'content' => $content,
			'footer'  => $footer,
			'photos'  => count( $profile['images'] ),
		);
	}

	/**
	 * The sanitized blueprint, made the old site's: its exact name and
	 * slogan, its contact facts in the footer, and per page the source page
	 * and per section the block ids it shows (checked against the read).
	 *
	 * @param array $plan     sanitize_blueprint() result.
	 * @param array $raw      The model's blueprint.
	 * @param array $result   read() result.
	 * @param array $approved approved_pages().
	 * @return array
	 */
	public function apply_plan( array $plan, array $raw, array $result, array $approved ) {
		$profile = $result['profile'];
		$blocks  = $this->blocks_of( $result );

		$plan['site_title'] = '' !== $profile['business']['name'] ? $profile['business']['name'] : $plan['site_title'];
		$tagline            = $profile['business']['tagline_ref'];
		$raw_tagline        = isset( $raw['tagline'] ) ? $this->fact( $raw['tagline'], implode( self::SEP, $blocks ) ) : '';
		$plan['tagline']    = '' !== $tagline ? $blocks[ $tagline ] : $raw_tagline;

		// The footer shows the old site's own contact details, never the model's.
		$plan['footer'] = array(
			'about'           => '',
			'contact_heading' => '',
			'email'           => (string) reset( $profile['contact']['emails'] ),
			'phone'           => (string) reset( $profile['contact']['phones'] ),
			'address'         => $profile['contact']['address'],
			'social'          => array_keys( $profile['contact']['social'] ),
		);
		$plan['portfolio'] = array(
			'needed' => false,
			'items'  => array(),
		);
		$plan['blog']      = array( 'posts' => array() );

		$raw_pages = array();
		foreach ( isset( $raw['pages'] ) && is_array( $raw['pages'] ) ? $raw['pages'] : array() as $page ) {
			if ( is_array( $page ) && ! empty( $page['slug'] ) ) {
				$raw_pages[ sanitize_title( (string) $page['slug'] ) ] = isset( $page['sections'] ) && is_array( $page['sections'] ) ? $page['sections'] : array();
			}
		}
		$sources = wp_list_pluck( $approved, 'source', 'slug' );

		foreach ( $plan['pages'] as $i => $page ) {
			$plan['pages'][ $i ]['source'] = isset( $sources[ $page['slug'] ] ) ? $sources[ $page['slug'] ] : '';
			$refs_by_id                    = array();
			foreach ( isset( $raw_pages[ $page['slug'] ] ) ? $raw_pages[ $page['slug'] ] : array() as $section ) {
				if ( is_array( $section ) && ! empty( $section['id'] ) ) {
					$used = array();
					$refs_by_id[ sanitize_key( (string) $section['id'] ) ] = $this->refs( isset( $section['source'] ) ? (array) $section['source'] : array(), $blocks, $used, false );
				}
			}
			foreach ( $page['sections'] as $k => $section ) {
				$plan['pages'][ $i ]['sections'][ $k ]['refs'] = isset( $refs_by_id[ $section['id'] ] ) ? $refs_by_id[ $section['id'] ] : array();
			}
		}

		$plan['rebuild'] = array(
			'key'     => $result['key'],
			'url'     => $result['site']['url'],
			'missing' => $this->uncovered( $plan, $result ),
		);

		return $plan;
	}

	/**
	 * Content blocks of the rebuilt pages' sources that no section shows
	 * (news teasers and dropped boilerplate aside): what the plan left out.
	 *
	 * @return string[] Block ids.
	 */
	private function uncovered( array $plan, array $result ) {
		$shown = array();
		foreach ( $plan['pages'] as $page ) {
			foreach ( $page['sections'] as $section ) {
				$shown += array_flip( isset( $section['refs'] ) ? $section['refs'] : array() );
			}
		}
		$skip = array_flip( $result['profile']['drop'] );
		foreach ( $result['profile']['pages'] as $page ) {
			foreach ( $page['sections'] as $section ) {
				if ( 'news' === $section['kind'] ) {
					$skip += array_flip( array_merge( array( $section['heading'] ), $section['refs'] ) );
				}
			}
		}

		$missing = array();
		foreach ( $plan['pages'] as $page ) {
			foreach ( $this->blocks_of( $result ) as $id => $text ) {
				if ( '' !== $page['source'] && 0 === strpos( $id, $page['source'] . '.' ) && ! isset( $shown[ $id ] ) && ! isset( $skip[ $id ] ) ) {
					$missing[] = $id;
				}
			}
		}
		return $missing;
	}

	/**
	 * Variables of one page's site-copy task.
	 *
	 * @param array $plan     Plan.
	 * @param array $page     Plan page.
	 * @param array $sections [ [ id, headline, refs ] ] of the page.
	 * @param array $result   read() result.
	 * @return array
	 */
	public function copy_vars( array $plan, array $page, array $sections, array $result ) {
		$blocks = $this->blocks_of( $result );
		$types  = $this->types_of( $result );
		$used   = array();
		foreach ( $sections as $section ) {
			foreach ( isset( $section['refs'] ) ? $section['refs'] : array() as $id ) {
				$used[ $id ] = array( $types[ $id ], $blocks[ $id ] );
			}
		}

		$photos = array();
		foreach ( $result['profile']['images'] as $n => $image ) {
			$photos[] = array(
				'n'    => $n + 1,
				// Most sites leave alt text empty; the file name often says enough.
				'alt'  => '' !== $image['alt'] ? $image['alt'] : $this->file_words( $image['url'] ),
				'page' => isset( $image['page'] ) && '' !== $image['page'] ? $image['page'] : 'Home',
			);
		}

		return array(
			'site'     => $this->site_facts( $result ),
			'language' => isset( $plan['language'] ) ? $plan['language'] : '',
			'pages'    => array_map(
				static function ( $p ) {
					return array(
						'slug'  => $p['slug'],
						'title' => $p['title'],
					);
				},
				$plan['pages']
			),
			'page'     => array(
				'slug'  => $page['slug'],
				'title' => $page['title'],
			),
			'sections' => array_map(
				static function ( $section ) {
					return array(
						'id'       => $section['id'],
						'headline' => $section['headline'],
						'source'   => isset( $section['refs'] ) ? $section['refs'] : array(),
					);
				},
				$sections
			),
			'blocks'   => $used,
			'photos'   => $photos,
		);
	}

	/**
	 * The copy made safe to render: {"ref"} values replaced by the old
	 * site's text as written, photo numbers by the old site's photos, and
	 * any new wording kept only when it is a short heading or label whose
	 * numbers and words come from the old site. Everything else is left out
	 * (the renderer drops empty fields) and listed in $report.
	 *
	 * @param array $content_by_id Copy by section id.
	 * @param array $full          Catalog sections (fields schema) by id.
	 * @param array $sections      The page's planned sections.
	 * @param array $result        read() result.
	 * @param array $report        Fidelity report (updated).
	 * @return array Copy by section id.
	 */
	public function resolve_copy( array $content_by_id, array $full, array $sections, array $result, array &$report ) {
		$report += array(
			'fields'   => 0,
			'refs'     => 0,
			'exact'    => 0,
			'labels'   => array(),
			'rejected' => array(),
			'leftover' => array(), // section id => block ids its layout didn't show
		);

		$this->source = $this->source_text( $result );
		$this->photos = count( $result['profile']['images'] );
		$types        = $this->types_of( $result );
		// Photo slots the copy left empty take the old site's photos in turn,
		// starting at a different one on every page.
		$this->cursor = $this->photos ? abs( crc32( wp_json_encode( wp_list_pluck( $sections, 'id' ) ) ) ) % $this->photos : 0;

		$out = array();
		foreach ( $sections as $section ) {
			$id = $section['id'];
			if ( ! isset( $full[ $id ] ) ) {
				continue;
			}
			$fields = isset( $content_by_id[ $id ] ) && is_array( $content_by_id[ $id ] ) ? $content_by_id[ $id ] : array();

			// The planned headline is the section's heading (it follows the
			// same rules), unless the "heading" is a long statement paragraph.
			$schema = $full[ $id ]['fields'];
			if ( '' !== $section['headline'] && isset( $schema['heading'] ) && ( empty( $schema['heading']['words'] ) || (int) $schema['heading']['words'] <= 16 ) ) {
				$fields['heading'] = $section['headline'];
			}

			$out[ $id ] = $this->resolve_fields( $schema, $fields, $id, $report );

			// The section's own blocks whose text landed in none of its fields
			// (a field holding all of a block, or at least half of it, shows it).
			$values = array();
			array_walk_recursive(
				$out[ $id ],
				function ( $value ) use ( &$values ) {
					if ( is_string( $value ) && '' !== $value && 0 !== strpos( $value, 'own:' ) ) {
						$values[] = $this->squash( $value );
					}
				}
			);
			$left = array();
			foreach ( isset( $section['refs'] ) ? $section['refs'] : array() as $ref ) {
				$text = isset( $this->source['blocks'][ $ref ] ) ? $this->squash( $this->source['blocks'][ $ref ] ) : '';
				// A second heading under one the section already shows would
				// stand alone: only text is carried over.
				if ( '' === $text || ( ! empty( $out[ $id ]['heading'] ) && preg_match( '/^h[1-6]$/', $types[ $ref ] ) ) ) {
					continue;
				}
				$shown = false;
				foreach ( $values as $value ) {
					if ( false !== mb_strpos( $value, $text ) || ( mb_strlen( $value ) * 2 >= mb_strlen( $text ) && false !== mb_strpos( $text, $value ) ) ) {
						$shown = true;
						break;
					}
				}
				if ( ! $shown ) {
					$left[] = $ref;
				}
			}
			if ( $left ) {
				$report['leftover'][ $id ] = $left;
			}
		}
		return $out;
	}

	/**
	 * Old-site blocks a section's layout had no place for, as a plain
	 * group right after it: the owner's text is never silently lost.
	 *
	 * @param string[] $ids    Block ids.
	 * @param array    $result read() result.
	 * @return string Block markup ('' for none).
	 */
	public function leftover_blocks( array $ids, array $result ) {
		$blocks = $this->blocks_of( $result );
		$types  = $this->types_of( $result );
		$inner  = '';
		$items  = array();

		$flush = static function () use ( &$items, &$inner ) {
			if ( $items ) {
				$inner .= "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . implode( '', $items ) . "</ul>\n<!-- /wp:list -->\n\n";
				$items  = array();
			}
		};
		foreach ( $ids as $id ) {
			if ( ! isset( $blocks[ $id ] ) ) {
				continue;
			}
			$html = nl2br( esc_html( $blocks[ $id ] ), false );
			if ( 'li' === $types[ $id ] ) {
				$items[] = "<!-- wp:list-item -->\n<li>" . $html . "</li>\n<!-- /wp:list-item -->";
				continue;
			}
			$flush();
			$inner .= preg_match( '/^h[1-6]$/', $types[ $id ] )
				? "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">" . $html . "</h3>\n<!-- /wp:heading -->\n\n"
				: "<!-- wp:paragraph -->\n<p>" . $html . "</p>\n<!-- /wp:paragraph -->\n\n";
		}
		$flush();

		if ( '' === $inner ) {
			return '';
		}
		return "<!-- wp:group {\"align\":\"full\",\"className\":\"iss-ai-rebuild-extra\",\"style\":{\"spacing\":{\"padding\":{\"top\":\"48px\",\"bottom\":\"48px\"}}},\"layout\":{\"type\":\"constrained\"}} -->\n"
			. "<div class=\"wp-block-group alignfull iss-ai-rebuild-extra\" style=\"padding-top:48px;padding-bottom:48px\">"
			. $inner
			. "</div>\n<!-- /wp:group -->\n\n";
	}

	/** @var array Squashed source text and word prefixes (resolve_copy()). */
	private $source = array();

	/** @var int Number of the old site's photos. */
	private $photos = 0;

	/** @var int Next of the old site's photos for an empty photo slot. */
	private $cursor = 0;

	private function resolve_fields( array $schema, $values, $path, array &$report ) {
		$values = is_array( $values ) ? $values : array();
		$out    = array();
		foreach ( $schema as $name => $field ) {
			if ( ! isset( $values[ $name ] ) ) {
				// The layout's photo slots keep the site's own photos when it has some.
				if ( isset( $field['type'] ) && 'image' === $field['type'] && $this->photos ) {
					$out[ $name ] = $this->next_photo();
				} elseif ( isset( $field['type'] ) && 'gallery' === $field['type'] && $this->photos ) {
					$out[ $name ] = array_map( array( $this, 'next_photo' ), array_fill( 0, min( $this->photos, max( 1, isset( $field['min'] ) ? (int) $field['min'] : 1 ) ), null ) );
				}
				continue;
			}
			$value = $values[ $name ];
			$where = $path . '.' . $name;
			switch ( isset( $field['type'] ) ? $field['type'] : 'text' ) {
				case 'text':
					$text = $this->resolve_text( $value, $where, $report, false );
					if ( '' !== $text ) {
						$out[ $name ] = $text;
					}
					break;

				case 'button':
					$label = is_array( $value ) ? ( isset( $value['label'] ) ? $value['label'] : '' ) : $value;
					$label = $this->resolve_text( $label, $where, $report, true );
					if ( '' !== $label ) {
						$out[ $name ] = array(
							'label' => $label,
							'href'  => $this->resolve_href( is_array( $value ) && isset( $value['href'] ) ? $value['href'] : '' ),
						);
					}
					break;

				case 'image':
					$out[ $name ] = $this->resolve_photo( $value );
					break;

				case 'gallery':
					$out[ $name ] = array_map( array( $this, 'resolve_photo' ), is_array( $value ) ? array_values( $value ) : array() );
					break;

				case 'list':
					$list = array();
					foreach ( is_array( $value ) ? $value : array() as $k => $item ) {
						$text = $this->resolve_text( $item, $where . '.' . $k, $report, false );
						if ( '' !== $text ) {
							$list[] = $text;
						}
					}
					$out[ $name ] = $list;
					break;

				case 'items':
					$items = array();
					foreach ( is_array( $value ) ? array_values( $value ) : array() as $k => $item ) {
						$item = $this->resolve_fields( isset( $field['fields'] ) ? (array) $field['fields'] : array(), $item, $where . '.' . $k, $report );
						// An item whose words were all rejected would show the layout empty.
						if ( $this->has_text( $item ) ) {
							$items[] = $item;
						}
					}
					$out[ $name ] = $items;
					break;
			}
		}
		return $out;
	}

	/**
	 * One text value: a reference, the old site's words copied exactly, or a
	 * short new heading or label. '' when rejected.
	 */
	private function resolve_text( $value, $where, array &$report, $button ) {
		$report['fields']++;

		// A bare id ("p4.2") where {"ref": "p4.2"} belongs.
		if ( is_string( $value ) && preg_match( '/^\s*(p\d{1,2}|f)\.\d{1,4}\s*$/', $value ) ) {
			$value = array( 'ref' => trim( $value ) );
		}

		if ( is_array( $value ) && isset( $value['ref'] ) ) {
			$texts = array();
			foreach ( (array) $value['ref'] as $id ) {
				if ( isset( $this->source['blocks'][ (string) $id ] ) ) {
					$texts[] = $this->source['blocks'][ (string) $id ];
				}
			}
			if ( $texts ) {
				$report['refs']++;
				return implode( "\n", $texts );
			}
			// The model sometimes puts the text itself where the id belongs:
			// check it like any copied text below.
			$value = is_scalar( $value['ref'] ) ? $value['ref'] : '';
			$report['fields']--;
			return $this->resolve_text( $value, $where, $report, $button );
		}

		$text = is_scalar( $value ) ? trim( preg_replace( '/[ \t]+/u', ' ', wp_strip_all_tags( str_replace( array( '\\n', '\n' ), "\n", (string) $value ) ) ) ) : '';
		// A link where text belongs ("tel:06353 / 74 97"): the number or address it holds.
		$text = trim( preg_replace( '/^(tel|mailto):\s*/i', '', $text ) );
		if ( '' === $text ) {
			return '';
		}

		// The old site's own words, copied.
		$needle = $this->squash( $text );
		foreach ( $this->source['squashed'] as $haystack ) {
			if ( false !== mb_strpos( $haystack, $needle ) ) {
				$report['exact']++;
				return $text;
			}
		}

		// New wording: only a short heading or label without any number (a
		// number makes it a fact — a time, price, year or address — and facts
		// pass only as exact copies, above), built from the old site's words
		// (a button label may be any short call to action, which states no fact).
		$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $words ) <= ( $button ? 4 : self::MAX_LABEL_WORDS ) && mb_strlen( $text ) <= 70 && ! preg_match( '/\d/', $text ) && ( $button || $this->words_known( $text ) ) ) {
			$report['labels'][] = $text;
			return $text;
		}

		$report['rejected'][] = $where . ': ' . mb_substr( $text, 0, 160 );
		return '';
	}

	/**
	 * At least half the words of four letters or more appear on the old site
	 * (by their first five letters). A fragment without such words ("Di-Sa",
	 * "24/7") is fact-like and passes only as an exact copy, never here.
	 */
	private function words_known( $text ) {
		preg_match_all( '/\p{L}{4,}/u', mb_strtolower( $text ), $words );
		if ( ! $words[0] ) {
			return false;
		}
		$known = 0;
		foreach ( $words[0] as $word ) {
			if ( isset( $this->source['prefixes'][ mb_substr( $word, 0, 5 ) ] ) ) {
				$known++;
			}
		}
		return $known * 2 >= count( $words[0] );
	}

	private function resolve_href( $href ) {
		$href = is_scalar( $href ) ? trim( (string) $href ) : '';
		if ( preg_match( '/^#page:[a-z0-9-]+$/', $href ) ) {
			return $href;
		}
		if ( 0 === stripos( $href, 'tel:' ) && in_array( preg_replace( '/\D/', '', $href ), $this->source['phones'], true ) ) {
			return 'tel:' . preg_replace( '/[^\d+]/', '', substr( $href, 4 ) );
		}
		if ( 0 === stripos( $href, 'mailto:' ) && false !== mb_strpos( $this->source['squashed'][0], strtolower( substr( $href, 7 ) ) ) ) {
			return $href;
		}
		return '#page:home';
	}

	/**
	 * {"photo": n} → "own:n" for image_resolver(). A stock query only when
	 * the old site has no photos; otherwise the next of its own.
	 */
	private function resolve_photo( $value ) {
		if ( is_array( $value ) && isset( $value['photo'] ) && (int) $value['photo'] >= 1 && (int) $value['photo'] <= $this->photos ) {
			return 'own:' . (int) $value['photo'];
		}
		$query = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( preg_match( '/^own:(\d+)$/', $query ) ) {
			return $query;
		}
		return $this->photos ? $this->next_photo() : trim( preg_replace( '/^stock:\s*/i', '', $query ) );
	}

	private function next_photo( $unused = null ) {
		return 'own:' . ( ( $this->cursor++ % $this->photos ) + 1 );
	}

	private function has_text( array $item ) {
		foreach ( $item as $value ) {
			if ( ( is_string( $value ) && '' !== $value && 0 !== strpos( $value, 'own:' ) ) || ( is_array( $value ) && isset( $value['label'] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Everything the old site says, prepared for the checks above. */
	private function source_text( array $result ) {
		$blocks  = $this->blocks_of( $result );
		$profile = $result['profile'];
		$facts   = array_merge(
			array( $profile['business']['name'], $profile['contact']['address'] ),
			$profile['contact']['phones'],
			$profile['contact']['emails'],
			$profile['contact']['hours'],
			array_column( $result['site']['pages'], 'title' )
		);
		foreach ( $profile['locations'] as $location ) {
			$facts = array_merge( $facts, array( $location['name'], $location['address'], $location['phone'] ), $location['hours'] );
		}
		$all = implode( self::SEP, array_merge( $blocks, $facts ) );

		preg_match_all( '/\p{L}{4,}/u', mb_strtolower( $all ), $words );
		$prefixes = array();
		foreach ( $words[0] as $word ) {
			$prefixes[ mb_substr( $word, 0, 5 ) ] = true;
		}

		$phones = $profile['contact']['phones'];
		foreach ( $profile['locations'] as $location ) {
			$phones[] = $location['phone'];
		}

		return array(
			'blocks'   => $blocks,
			// The site's phone numbers as digits, for tel: links.
			'phones'   => array_values( array_filter( array_map( static function ( $phone ) {
				return preg_replace( '/\D/', '', (string) $phone );
			}, $phones ) ) ),
			'squashed' => array( $this->squash( $all ), $this->squash( str_replace( "\n", ' / ', $all ) ) ),
			'prefixes' => $prefixes,
		);
	}

	/**
	 * Photo resolver for the renderer: "own:n" sideloads the old site's
	 * photo n (once per site), anything else goes to $fallback (stock).
	 *
	 * @param array    $result   read() result.
	 * @param string   $plan_id  Generation (cleanup tag).
	 * @param callable $fallback Stock photo resolver.
	 * @return callable
	 */
	public function image_resolver( array $result, $plan_id, $fallback ) {
		$images = $result['profile']['images'];
		return function ( $query, $orientation ) use ( $images, $plan_id, $fallback ) {
			if ( preg_match( '/^own:(\d+)$/', (string) $query, $m ) && isset( $images[ (int) $m[1] - 1 ] ) ) {
				$image = $this->sideload_own( $images[ (int) $m[1] - 1 ], $plan_id );
				if ( $image ) {
					return $image;
				}
			}
			$query = preg_match( '/^own:/', (string) $query ) ? '' : (string) $query;
			return '' !== $query ? call_user_func( $fallback, $query, $orientation ) : null;
		};
	}

	/**
	 * Copy one of the old site's photos into the media library, once.
	 *
	 * @param array  $image   [ url, alt ].
	 * @param string $plan_id Generation (cleanup tag).
	 * @return array|null [ 'id', 'url', 'photo_id' ]
	 */
	private function sideload_own( array $image, $plan_id ) {
		$found = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => self::IMAGE_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $image['url'], // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( $found ) {
			return array(
				'id'       => (int) $found[0],
				'url'      => (string) wp_get_attachment_url( $found[0] ),
				'photo_id' => 'site:' . md5( $image['url'] ),
			);
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$tmp = download_url( $image['url'], 30 );
		if ( is_wp_error( $tmp ) ) {
			return null;
		}
		$name = sanitize_file_name( wp_basename( (string) wp_parse_url( $image['url'], PHP_URL_PATH ) ) );
		if ( ! preg_match( '/\.(jpe?g|png|webp)$/i', $name ) ) {
			$name .= '.jpg';
		}

		// Shown at full size like the stock photos: no extra crops to generate.
		add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 100 );
		$id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			0,
			'' !== $image['alt'] ? $image['alt'] : null
		);
		remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 100 );

		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return null;
		}
		update_post_meta( $id, self::IMAGE_META_KEY, $image['url'] );
		update_post_meta( $id, AiDemoGenerator::GENERATED_META_KEY, $plan_id );
		if ( '' !== $image['alt'] ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $image['alt'] );
		}

		return array(
			'id'       => (int) $id,
			'url'      => (string) wp_get_attachment_url( $id ),
			'photo_id' => 'site:' . md5( $image['url'] ),
		);
	}

	/** "PAKET_GIES_weiss-scaled.jpg" → "PAKET GIES weiss". */
	private function file_words( $url ) {
		$name = pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_FILENAME );
		$name = preg_replace( '/-(scaled|\d+x\d+|e\d{10,})$|-\d{1,2}$/i', '', rawurldecode( $name ) );
		$name = trim( preg_replace( '/[\s_\-]+/', ' ', preg_replace( '/\b(img|dsc|image|photo|foto|bild)?\d{4,}\b/i', '', $name ) ) );
		return mb_substr( $name, 0, 80 );
	}

	/** The business facts as the tasks receive them (all checked by check_profile()). */
	private function site_facts( array $result ) {
		$profile = $result['profile'];
		return array(
			'name'        => $profile['business']['name'],
			'kind'        => $profile['business']['kind'],
			'language'    => $profile['language'],
			'summary'     => $profile['business']['summary'],
			'brand_color' => sanitize_hex_color( (string) $result['site']['theme_color'] ),
			'address'     => $profile['contact']['address'],
			'phones'      => $profile['contact']['phones'],
			'emails'      => $profile['contact']['emails'],
			'hours'       => $profile['contact']['hours'],
			'locations'   => $profile['locations'],
		);
	}

	/**
	 * The blocks the rebuild works with: block_index() with each run of
	 * opening-hours lines merged into its first block (lines kept), so the
	 * week is placed as one piece and never split across a layout.
	 *
	 * @param array $result read() result.
	 * @return array id => text
	 */
	private function blocks_of( array $result ) {
		$blocks = $this->block_index( $result['site'] );
		foreach ( $this->hours_runs( $result ) as $run ) {
			$lines = array();
			foreach ( $run as $id ) {
				$lines[] = $blocks[ $id ];
				unset( $blocks[ $id ] );
			}
			$blocks[ $run[0] ] = implode( "\n", $lines );
		}
		return $blocks;
	}

	private function types_of( array $result ) {
		$types = $this->block_types( $result['site'] );
		foreach ( $this->hours_runs( $result ) as $run ) {
			$types[ $run[0] ] = 'p';
		}
		return $types;
	}

	/**
	 * Runs of two or more consecutive blocks holding opening hours (the
	 * profile's hours blocks and its "hours" sections, headings aside).
	 *
	 * @return array[] Lists of block ids, in page order.
	 */
	private function hours_runs( array $result ) {
		$ids = $result['profile']['contact']['hours_refs'];
		foreach ( $result['profile']['pages'] as $page ) {
			foreach ( $page['sections'] as $section ) {
				if ( 'hours' === $section['kind'] ) {
					$ids = array_merge( $ids, $section['refs'] );
				}
			}
		}
		$known = $this->block_index( $result['site'] );
		$ids   = array_values( array_unique( array_filter( $ids, static function ( $id ) use ( $known ) {
			return isset( $known[ $id ] );
		} ) ) );
		usort(
			$ids,
			static function ( $a, $b ) {
				list( $pa, $na ) = explode( '.', $a );
				list( $pb, $nb ) = explode( '.', $b );
				return $pa === $pb ? (int) $na <=> (int) $nb : strcmp( $pa, $pb );
			}
		);

		$runs = array();
		$run  = array();
		foreach ( $ids as $id ) {
			$last = $run ? end( $run ) : '';
			list( $page, $n ) = explode( '.', $id );
			if ( $run && ( 0 !== strpos( $last, $page . '.' ) || (int) substr( $last, strlen( $page ) + 1 ) + 1 !== (int) $n ) ) {
				$runs[] = $run;
				$run    = array();
			}
			$run[] = $id;
		}
		$runs[] = $run;

		return array_values( array_filter( $runs, static function ( $run ) {
			return count( $run ) > 1;
		} ) );
	}

	/** Block id => text, footer and pages. */
	private function block_index( array $site ) {
		$blocks = array();
		foreach ( $site['footer'] as $block ) {
			$blocks[ $block['id'] ] = $block['text'];
		}
		foreach ( $site['pages'] as $page ) {
			foreach ( $page['blocks'] as $block ) {
				$blocks[ $block['id'] ] = $block['text'];
			}
		}
		return $blocks;
	}

	private function block_types( array $site ) {
		$types = array();
		foreach ( array_merge( $site['footer'], ...array_column( $site['pages'], 'blocks' ) ) as $block ) {
			$types[ $block['id'] ] = $block['type'];
		}
		return $types;
	}

	/** [ id, type, text ] rows of the given blocks. */
	private function rows( array $ids, array $blocks, array $types ) {
		$rows = array();
		foreach ( $ids as $id ) {
			if ( isset( $blocks[ $id ] ) ) {
				$rows[] = array( $id, $types[ $id ], $blocks[ $id ] );
			}
		}
		return $rows;
	}

	/* ---------------------------------------------------------------------
	 * Summary card
	 * ------------------------------------------------------------------ */

	/**
	 * What the modal's card shows the customer to confirm the read.
	 *
	 * @param array $result read() result.
	 * @return array
	 */
	public function summary( array $result ) {
		$site    = $result['site'];
		$profile = $result['profile'];

		$pages = array();
		foreach ( $profile['pages'] as $page ) {
			$pages[] = array(
				'label'    => $page['label'],
				'url'      => $page['url'],
				'purpose'  => $page['purpose'],
				'keep'     => $page['keep'],
				'sections' => count( $page['sections'] ),
			);
		}

		return array(
			'key'          => $result['key'],
			'url'          => $site['url'],
			'host'         => $site['host'],
			'language'     => $profile['language'],
			'name'         => $profile['business']['name'],
			'kind'         => $profile['business']['kind'],
			'summary'      => $profile['business']['summary'],
			'logo'         => $profile['logo'],
			'phones'       => $profile['contact']['phones'],
			'emails'       => $profile['contact']['emails'],
			'address'      => $profile['contact']['address'],
			'hours'        => $profile['contact']['hours'],
			'locations'    => $profile['locations'],
			'social'       => array_keys( $profile['contact']['social'] ),
			'pages'        => $pages,
			'legal'        => array_column( $profile['legal'], 'label' ),
			'counts'       => array(
				'photos'       => count( $profile['images'] ),
				'offerings'    => count( $profile['offerings'] ),
				'testimonials' => count( $profile['testimonials'] ),
				'team'         => count( $profile['team'] ),
				'words'        => (int) $site['words'],
			),
			'gaps'         => $profile['gaps'],
			'thin'         => ! empty( $site['thin'] ),
			'skipped'      => count( $site['skipped'] ),
			'read_at'      => (int) $site['read_at'],
		);
	}

	private function log_consent( $url ) {
		$log   = get_option( self::CONSENT_OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'url'  => esc_url_raw( $url ),
			'user' => get_current_user_id(),
			'time' => time(),
		);
		update_option( self::CONSENT_OPTION, array_slice( $log, -20 ), false );
	}

	/* ---------------------------------------------------------------------
	 * WP-CLI
	 * ------------------------------------------------------------------ */

	/**
	 * Read a website into a rebuild profile.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : The website address.
	 *
	 * [--fresh]
	 * : Ignore the day-long cache.
	 *
	 * [--profile]
	 * : Print the whole checked profile instead of the summary.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function cli_read( $args, $assoc_args ) {
		$result = $this->read( $args[0], null, ! empty( $assoc_args['fresh'] ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_code() . ': ' . $result->get_error_message() );
		}
		// Not --json: WP-CLI reads that as --format=json.
		$data = ! empty( $assoc_args['profile'] ) ? $result['profile'] : $this->summary( $result );
		\WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}
}
