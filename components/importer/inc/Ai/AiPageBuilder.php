<?php
/**
 * AI page builder: one page (or a few sections) composed from the Inspiro
 * section catalog, for the block editor.
 *
 * The demo generator builds whole sites; this builds a single page the
 * user describes, on the site they already have. Same engine — the proxy
 * plans the page from the catalog (task page-blueprint), writes the copy
 * (page-copy), and SectionRenderer turns catalog markup, copy and photos
 * into native block markup — but the result goes back to the editor as
 * blocks to insert, never as a new post of its own.
 *
 * Tiering is the demo generator's: an email registration (Inspiro Lite)
 * with the free quota (feature "page-generate" on the proxy) and the lite
 * catalog, or an active Inspiro Premium license with the Premium catalog
 * and the licensed quota.
 *
 * Three AJAX actions back the editor modal:
 *   inspiro_starter_sites_ai_page_catalog  — the section index (metadata)
 *                                            for the suggestion strip.
 *   inspiro_starter_sites_ai_page_plan     — prompt → plan (sections with
 *                                            headline + brief) for review.
 *   inspiro_starter_sites_ai_page_build    — approved plan → block markup.
 *
 * @package Inspiro Starter Sites
 */

namespace Inspiro\Starter_Sites\Ai;

use Inspiro\Starter_Sites\Helpers;

defined( 'ABSPATH' ) || exit;

class AiPageBuilder {

	/** Quota feature slug on the proxy (free and "-licensed" limits). */
	const FEATURE = 'page-generate';

	/** Post meta: this page holds AI-built catalog sections (needs the catalog CSS). */
	const PAGE_META_KEY = '_inspiro_starter_sites_ai_page';

	/** Attachment meta: Pexels photo id, same key the demo generator uses. */
	const PHOTO_META_KEY = '_iss_pexels_photo_id';

	/** Option: the site's catalog CSS and the accent it was built from. */
	const CSS_OPTION = 'inspiro_starter_sites_ai_page_css';

	/** Most unique photo downloads per build; later slots reuse earlier photos. */
	const MAX_PHOTOS = 12;

	/** Section needs this site can satisfy, resolved once per request. */
	private $has = null;

	/** @var AiProxyClient */
	private $proxy;

	/** @var AiPageBuilder|null */
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->proxy = new AiProxyClient();

		add_action( 'wp_ajax_inspiro_starter_sites_ai_page_catalog', array( $this, 'ajax_catalog' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_page_quota', array( $this, 'ajax_quota' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_page_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_page_plan', array( $this, 'ajax_plan' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_page_build', array( $this, 'ajax_build' ) );

		// The editor modal and its entry points.
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'admin_footer-edit.php', array( $this, 'pages_screen_button' ) );

		// Catalog sections carry the demos' own button and color rules: the
		// pages that hold them get that stylesheet, on the front end and in
		// the editor, like the generated demo pages do.
		add_action( 'wp_head', array( $this, 'print_css' ), 101 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_css' ), 20 );
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * The section index for the editor: metadata of every section this site
	 * can use, plus the Premium ones it cannot (shown locked with the
	 * upgrade prompt), the quota state and the site's palette.
	 */
	public function ajax_catalog() {
		Helpers::verify_ajax_call();

		$sections = $this->catalog_index();
		if ( ! $sections ) {
			wp_send_json_error( array( 'message' => esc_html__( 'The section catalog is unavailable. Please try again later.', 'inspiro-starter-sites' ) ) );
		}

		wp_send_json_success(
			array(
				'sections' => $sections,
				'licensed' => '' !== AiProxyClient::premium_license(),
				'premium'  => AiDemoGenerator::is_premium_inspiro(),
				'has'      => $this->site_has(),
			)
		);
	}

	/**
	 * Connection and page-quota state for the modal's header card. Mirrors
	 * the demo generator's quota card, for the page-generate feature.
	 */
	public function ajax_quota() {
		Helpers::verify_ajax_call();

		$payload = array(
			'connected' => false,
			'email'     => $this->proxy->connected_email(),
			'used'      => 0,
			'limit'     => 0,
			'remaining' => 0,
			'licensed'  => false,
		);
		if ( ! $this->proxy->is_connected() && '' === AiProxyClient::premium_license() ) {
			wp_send_json_success( $payload );
		}
		$quota = $this->proxy->quota( 'check', self::FEATURE );
		if ( is_wp_error( $quota ) ) {
			if ( 'ai_registration_required' === $quota->get_error_code() ) {
				$this->proxy->disconnect();
				$payload['email'] = '';
				wp_send_json_success( $payload );
			}
			wp_send_json_error( array( 'message' => $quota->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				'connected' => true,
				'email'     => $this->proxy->connected_email(),
				'used'      => isset( $quota['used'] ) ? (int) $quota['used'] : 0,
				'limit'     => isset( $quota['limit'] ) ? (int) $quota['limit'] : 0,
				'remaining' => isset( $quota['remaining'] ) ? (int) $quota['remaining'] : 0,
				'licensed'  => ! empty( $quota['licensed'] ),
			)
		);
	}

	/**
	 * Preview markup for plan rows: each section rendered with the demo's
	 * own example copy and placeholder photos, for BlockPreview in the
	 * editor. Nothing is sideloaded or metered.
	 */
	public function ajax_preview() {
		Helpers::verify_ajax_call();

		$ids = isset( $_POST['ids'] ) ? $this->clean_ids( (array) wp_unslash( $_POST['ids'] ), 12 ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by clean_ids().
		if ( ! $ids ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Nothing to preview.', 'inspiro-starter-sites' ) ) );
		}

		wp_send_json_success(
			array(
				'markup' => $this->preview_markup( $ids ),
				'css'    => $this->css(),
			)
		);
	}

	/**
	 * Section id => block markup rendered with example content.
	 *
	 * @param string[] $ids Catalog ids.
	 * @return array
	 */
	public function preview_markup( array $ids ) {
		$full = Catalog\Catalog::sections( $ids );
		if ( ! $full ) {
			return array();
		}
		$renderer = new Catalog\SectionRenderer(
			array(
				'palette'        => $this->palette(),
				'links'          => array( 'home' => home_url( '/' ) ),
				'resolve_image'  => static function ( $query, $orientation ) {
					$size = 'portrait' === $orientation ? '800/1000' : ( 'square' === $orientation ? '900/900' : '1200/800' );
					return array(
						'id'  => 0,
						'url' => 'https://picsum.photos/seed/' . substr( md5( (string) $query ), 0, 10 ) . '/' . $size,
					);
				},
				'fallback_query' => 'placeholder',
				'form_id'        => $this->first_form_id(),
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			if ( isset( $full[ $id ] ) ) {
				$out[ $id ] = $renderer->render( $full[ $id ], $this->example_content( (array) $full[ $id ]['fields'] ) );
			}
		}
		return $out;
	}

	/**
	 * The demo's original copy for a section's fields, in the shape the
	 * renderer expects from the copywriter.
	 *
	 * @param array $fields Catalog field definitions.
	 * @return array
	 */
	private function example_content( array $fields ) {
		$content = array();
		foreach ( $fields as $name => $f ) {
			$type = isset( $f['type'] ) ? $f['type'] : 'text';
			switch ( $type ) {
				case 'text':
					$content[ $name ] = isset( $f['example'] ) ? (string) $f['example'] : '';
					break;
				case 'button':
					$content[ $name ] = array(
						'label' => isset( $f['example'] ) ? (string) $f['example'] : '',
						'href'  => '#',
					);
					break;
				case 'image':
					$content[ $name ] = 'placeholder ' . $name;
					break;
				case 'list':
					$content[ $name ] = isset( $f['example'] ) ? (array) $f['example'] : array();
					break;
				case 'gallery':
					$count            = isset( $f['min'] ) ? max( 1, (int) $f['min'] ) : 3;
					$content[ $name ] = array_map( static function ( $i ) { return 'placeholder ' . $i; }, range( 1, $count ) );
					break;
				case 'items':
					$count = isset( $f['count'] ) ? (int) $f['count'] : ( isset( $f['min'] ) ? (int) $f['min'] : 3 );
					$count = max( isset( $f['min'] ) ? (int) $f['min'] : 1, min( isset( $f['max'] ) ? (int) $f['max'] : 6, $count ) );
					$item  = $this->example_content( isset( $f['fields'] ) ? (array) $f['fields'] : array() );
					$content[ $name ] = array_fill( 0, max( 1, $count ), $item );
					break;
			}
		}
		return $content;
	}

	/**
	 * Prompt → plan. Cheap (one short proxy call), so it is not metered: the
	 * quota is spent by the build.
	 */
	public function ajax_plan() {
		Helpers::verify_ajax_call();

		$input = array(
			'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			'page_type'   => isset( $_POST['page_type'] ) ? sanitize_key( wp_unslash( $_POST['page_type'] ) ) : 'auto',
			'style'       => isset( $_POST['style'] ) ? sanitize_key( wp_unslash( $_POST['style'] ) ) : '',
			'pinned'      => isset( $_POST['pinned'] ) ? (array) wp_unslash( $_POST['pinned'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in plan().
			'avoid'       => isset( $_POST['avoid'] ) ? (array) wp_unslash( $_POST['avoid'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in plan().
		);

		if ( mb_strlen( trim( $input['description'] ) ) < 3 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please describe the page you want.', 'inspiro-starter-sites' ) ) );
		}

		$stream = new StreamingResponse();
		$stream->begin();

		$plan = $this->plan( $input, array( $stream, 'tick' ) );
		if ( is_wp_error( $plan ) ) {
			$stream->finish_error( $this->error_payload( $plan ) );
		}
		$stream->finish_success( $plan );
	}

	/**
	 * Approved plan → block markup. Metered: one unit of the page quota is
	 * reserved first and refunded when the build fails.
	 */
	public function ajax_build() {
		Helpers::verify_ajax_call();
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 320 ); // phpcs:ignore
		}

		$plan = isset( $_POST['plan'] ) ? json_decode( wp_unslash( $_POST['plan'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in clean_plan().
		if ( ! is_array( $plan ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'The page plan is missing. Please plan the page again.', 'inspiro-starter-sites' ) ) );
		}
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			$post_id = 0;
		}

		// Reserve one generation before doing the expensive work. Fail closed:
		// if the quota service is unreachable, so is the generation service.
		$quota = $this->proxy->quota( 'consume', self::FEATURE );
		if ( is_wp_error( $quota ) ) {
			if ( 'ai_registration_required' === $quota->get_error_code() ) {
				$this->proxy->disconnect();
				wp_send_json_error(
					array(
						'code'    => 'registration_required',
						'message' => esc_html__( 'Please connect with your email first.', 'inspiro-starter-sites' ),
					)
				);
			}
			wp_send_json_error( array( 'message' => esc_html__( 'The AI service is currently unreachable. Please try again later.', 'inspiro-starter-sites' ) ) );
		}
		if ( empty( $quota['allowed'] ) ) {
			wp_send_json_error(
				array(
					'code'    => 'quota_exhausted',
					'message' => ! empty( $quota['licensed'] )
						? esc_html__( 'You have used all the AI page generations included with your license.', 'inspiro-starter-sites' )
						: esc_html__( 'You have used all your free AI page generations for this site.', 'inspiro-starter-sites' ),
				)
			);
		}

		$stream = new StreamingResponse();
		$stream->begin();

		try {
			$result = $this->build( $plan, array( $stream, 'tick' ) );
		} catch ( \Throwable $e ) {
			$result = new \WP_Error( 'exception', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			// Give the unit back: nothing usable reached the user.
			$this->proxy->quota( 'refund', self::FEATURE );
			if ( 'exception' === $result->get_error_code() ) {
				error_log( '[inspiro-starter-sites AI] page build failed with exception: ' . $result->get_error_message() ); // phpcs:ignore
				$stream->finish_error( array( 'message' => esc_html__( 'Something went wrong while building the page. Your generation was not used — please try again.', 'inspiro-starter-sites' ) ) );
			}
			$stream->finish_error( $this->error_payload( $result ) );
		}

		if ( $post_id ) {
			$this->mark_page( $post_id );
		}

		$result['quota'] = array(
			'used'      => isset( $quota['used'] ) ? (int) $quota['used'] : 0,
			'limit'     => isset( $quota['limit'] ) ? (int) $quota['limit'] : 0,
			'remaining' => isset( $quota['remaining'] ) ? (int) $quota['remaining'] : 0,
			'licensed'  => ! empty( $quota['licensed'] ),
		);
		$stream->finish_success( $result );
	}

	/* ---------------------------------------------------------------------
	 * Core (AJAX-free, so it can be driven from tests and WP-CLI)
	 * ------------------------------------------------------------------ */

	/**
	 * Plan a page from a description.
	 *
	 * @param array         $input     description, page_type, style, pinned (ids), avoid (ids).
	 * @param callable|null $heartbeat Keep-alive tick.
	 * @return array|\WP_Error { page_type, language, title, requirements, sections: [ { id, headline, brief, role, tone, tier, description, photos } ] }
	 */
	public function plan( array $input, $heartbeat = null ) {
		$description = trim( mb_substr( (string) ( isset( $input['description'] ) ? $input['description'] : '' ), 0, 3000 ) );
		if ( mb_strlen( $description ) < 3 ) {
			return new \WP_Error( 'ai_invalid_input', __( 'Please describe the page you want.', 'inspiro-starter-sites' ) );
		}

		// Free sites plan with their email registration, licensed sites with
		// the license; with neither, the proxy would refuse anyway — send the
		// user to the connect step before spending a round trip.
		if ( '' === AiProxyClient::premium_license() && '' === (string) get_option( AiProxyClient::SITE_KEY_OPTION, '' ) ) {
			return new \WP_Error( 'ai_registration_required', __( 'Connect with your email to use free AI generations.', 'inspiro-starter-sites' ) );
		}

		$meta = Catalog\Catalog::meta();
		if ( ! $meta ) {
			return new \WP_Error( 'ai_catalog_unavailable', __( 'The section catalog is unavailable. Please try again later.', 'inspiro-starter-sites' ) );
		}

		$page_type = isset( $input['page_type'] ) ? sanitize_key( (string) $input['page_type'] ) : 'auto';
		if ( ! in_array( $page_type, array( 'auto', 'home', 'landing', 'inner', 'sections' ), true ) ) {
			$page_type = 'auto';
		}

		$vars = array(
			'description'      => $description,
			'page_type'        => $page_type,
			'style'            => isset( $input['style'] ) ? sanitize_key( (string) $input['style'] ) : '',
			'pinned'           => $this->clean_ids( isset( $input['pinned'] ) ? $input['pinned'] : array(), 8 ),
			'avoid'            => $this->clean_ids( isset( $input['avoid'] ) ? $input['avoid'] : array(), 30 ),
			'blocks'           => Catalog\Catalog::blocks(),
			'has'              => $this->site_has(),
			'site_title'       => get_bloginfo( 'name' ),
			'tagline'          => get_bloginfo( 'description' ),
			'business_profile' => $this->business_profile(),
			'locale'           => get_locale(),
		);

		$raw = $this->proxy->claude_task_json( 'page-blueprint', $vars, $heartbeat );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$resolved = isset( $raw['page_type'] ) ? sanitize_key( (string) $raw['page_type'] ) : $page_type;
		if ( ! in_array( $resolved, array( 'home', 'landing', 'inner', 'sections' ), true ) ) {
			$resolved = 'auto' === $page_type ? 'inner' : $page_type;
		}

		$sections = $this->clean_sections( isset( $raw['sections'] ) ? $raw['sections'] : array(), $resolved, $meta );
		if ( ! $sections ) {
			return new \WP_Error( 'ai_invalid_plan', __( 'The AI returned an unusable page plan. Please try again.', 'inspiro-starter-sites' ) );
		}

		$requirements = array();
		foreach ( array_slice( isset( $raw['requirements'] ) && is_array( $raw['requirements'] ) ? $raw['requirements'] : array(), 0, 20 ) as $req ) {
			$req = trim( mb_substr( sanitize_text_field( (string) $req ), 0, 200 ) );
			// A pinned section id echoed back is not a requirement the user wrote.
			if ( '' !== $req && ! isset( $meta[ sanitize_key( $req ) ] ) && ! preg_match( '/^[a-z0-9]+(-[a-z0-9]+)+$/', $req ) ) {
				$requirements[] = $req;
			}
		}

		return array(
			'description'  => $description,
			'page_type'    => $resolved,
			'language'     => preg_replace( '/[^A-Za-z ()\-]/', '', mb_substr( (string) ( isset( $raw['language'] ) ? $raw['language'] : '' ), 0, 40 ) ),
			'title'        => trim( mb_substr( sanitize_text_field( (string) ( isset( $raw['title'] ) ? $raw['title'] : '' ) ), 0, 80 ) ),
			'requirements' => $requirements,
			'sections'     => $sections,
		);
	}

	/**
	 * Build an approved plan into block markup.
	 *
	 * @param array         $plan      As returned by plan(), possibly edited (sections reordered, swapped, removed).
	 * @param callable|null $heartbeat Keep-alive tick.
	 * @return array|\WP_Error { markup, css, title, images: [ { id, url } ], sections: [ id ] }
	 */
	public function build( array $plan, $heartbeat = null ) {
		$plan = $this->clean_plan( $plan );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$tick = is_callable( $heartbeat ) ? $heartbeat : static function () {};

		// Full sections (markup, fields) from the catalog cache or one fetch.
		$ids  = wp_list_pluck( $plan['sections'], 'id' );
		$full = Catalog\Catalog::sections( $ids );
		if ( ! $full ) {
			return new \WP_Error( 'ai_catalog_unavailable', __( 'The section catalog is unavailable. Please try again later.', 'inspiro-starter-sites' ) );
		}
		call_user_func( $tick );

		// Copy: one JSON line per section; sections still missing get one
		// targeted retry.
		$schemas = array();
		foreach ( $plan['sections'] as $item ) {
			if ( isset( $full[ $item['id'] ] ) ) {
				$schemas[] = array(
					'id'       => $item['id'],
					'headline' => $item['headline'],
					'brief'    => $item['brief'],
				);
			}
		}
		if ( ! $schemas ) {
			return new \WP_Error( 'ai_invalid_plan', __( 'None of the planned sections are available. Please plan the page again.', 'inspiro-starter-sites' ) );
		}

		$copy_vars = array(
			'description'      => $plan['description'],
			'business_profile' => $this->business_profile(),
			'site_title'       => get_bloginfo( 'name' ),
			'tagline'          => get_bloginfo( 'description' ),
			'language'         => $plan['language'],
			'requirements'     => $plan['requirements'],
			'page'             => array( 'title' => '' !== $plan['title'] ? $plan['title'] : __( 'Page', 'inspiro-starter-sites' ) ),
			'pages'            => $this->site_pages(),
			'sections'         => $schemas,
		);

		$content_by_id = array();
		for ( $attempt = 0; $attempt < 2 && count( $content_by_id ) < count( $schemas ); $attempt++ ) {
			if ( $attempt ) {
				$copy_vars['sections'] = array_values(
					array_filter(
						$schemas,
						static function ( $schema ) use ( $content_by_id ) {
							return ! isset( $content_by_id[ $schema['id'] ] );
						}
					)
				);
			}
			$text = $this->proxy->claude_task( 'page-copy', $copy_vars, $heartbeat );
			if ( is_wp_error( $text ) ) {
				if ( ! $content_by_id && $attempt ) {
					return $text;
				}
				continue;
			}
			$content_by_id += $this->parse_copy( $text );
		}
		if ( ! $content_by_id ) {
			return new \WP_Error( 'ai_parse_error', __( 'The AI returned a malformed response. Please try again.', 'inspiro-starter-sites' ) );
		}

		// Render with the site's palette, this site's pages as link targets
		// and photos sideloaded into the media library.
		$images   = array();
		$renderer = new Catalog\SectionRenderer(
			array(
				'palette'        => $this->palette(),
				'links'          => $this->page_links(),
				'resolve_image'  => $this->image_resolver( $images, $tick ),
				'resolve_asset'  => array( $this, 'catalog_asset' ),
				'fallback_query' => get_bloginfo( 'name' ) . ' ' . $plan['title'],
				'form_id'        => $this->first_form_id(),
			)
		);

		$markup   = '';
		$rendered = array();
		foreach ( $plan['sections'] as $item ) {
			if ( ! isset( $full[ $item['id'] ] ) ) {
				continue;
			}
			$section = $full[ $item['id'] ];
			$fields  = isset( $content_by_id[ $item['id'] ] ) ? $content_by_id[ $item['id'] ] : array();
			// The planned headline is what the user approved in the plan —
			// unless the "heading" is a long statement set in display type.
			if ( '' !== $item['headline'] && isset( $section['fields']['heading'] )
				&& ( empty( $section['fields']['heading']['words'] ) || (int) $section['fields']['heading']['words'] <= 16 ) ) {
				$fields['heading'] = $item['headline'];
			}
			$piece = $renderer->render( $section, $fields );
			if ( '' !== trim( $piece ) ) {
				$markup    .= $piece . "\n\n";
				$rendered[] = $item['id'];
			}
			call_user_func( $tick );
		}

		if ( '' === trim( $markup ) ) {
			return new \WP_Error( 'ai_render_failed', __( 'The AI returned an unusable page design. Please try again.', 'inspiro-starter-sites' ) );
		}

		return array(
			'title'    => $plan['title'],
			'markup'   => trim( $markup ),
			'css'      => $this->css(),
			'images'   => $images,
			'sections' => $rendered,
		);
	}

	/* ---------------------------------------------------------------------
	 * Catalog
	 * ------------------------------------------------------------------ */

	/**
	 * Every section this site may plan with, plus the Premium ones it may
	 * not (locked), as the editor needs them: id, role, tone, fits, tier,
	 * description, photos, items, and `available` / `locked` flags.
	 *
	 * @return array[]
	 */
	public function catalog_index() {
		$meta = Catalog\Catalog::meta();
		if ( ! $meta ) {
			return array();
		}
		$has      = $this->site_has();
		$licensed = '' !== AiProxyClient::premium_license();
		$out      = array();
		foreach ( $meta as $id => $section ) {
			$needs  = isset( $section['needs'] ) ? (array) $section['needs'] : array();
			$locked = ! $licensed && isset( $section['tier'] ) && 'premium' === $section['tier'];
			$out[]  = array(
				'id'          => $id,
				'role'        => $section['role'],
				'fits'        => isset( $section['fits'] ) ? $section['fits'] : 'any',
				'tone'        => isset( $section['tone'] ) ? $section['tone'] : 'light',
				'tier'        => isset( $section['tier'] ) ? $section['tier'] : 'lite',
				'description' => isset( $section['description'] ) ? $section['description'] : '',
				'photos'      => isset( $section['photos'] ) ? (int) $section['photos'] : 0,
				'items'       => isset( $section['items'] ) ? (int) $section['items'] : 0,
				'needs'       => array_values( $needs ),
				'locked'      => $locked,
				'available'   => ! $locked && ! array_diff( $needs, $has ),
			);
		}
		return $out;
	}

	/**
	 * Section needs this site satisfies (see the proxy catalog's `needs`).
	 *
	 * @return string[]
	 */
	public function site_has() {
		if ( null !== $this->has ) {
			return $this->has;
		}
		$has = array();
		$counts = wp_count_posts( 'post' );
		if ( $counts && ! empty( $counts->publish ) ) {
			$has[] = 'posts';
		}
		if ( $this->first_form_id() ) {
			$has[] = 'wpzoom-forms';
		}
		if ( post_type_exists( 'portfolio_item' ) ) {
			$has[] = 'wpzoom-portfolio';
		}
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( 'outermost/icon-block' ) ) {
			$has[] = 'icon-block';
		}
		$this->has = $has;
		return $has;
	}

	/**
	 * Keep the plan's sections that exist, fit the page type, are unlocked
	 * for this site and whose needs it meets; no repeats; at most ten.
	 *
	 * @param array  $list      Raw sections from the proxy or the editor.
	 * @param string $page_type home|landing|inner|sections.
	 * @param array  $meta      Catalog metadata (id => section).
	 * @return array[] [ { id, headline, brief, role, tone, tier, description, photos } ]
	 */
	private function clean_sections( $list, $page_type, array $meta ) {
		$home = in_array( $page_type, array( 'home', 'landing' ), true );
		$has  = $this->site_has();
		$out  = array();
		$seen = array();
		foreach ( array_slice( is_array( $list ) ? $list : array(), 0, 12 ) as $item ) {
			$id = sanitize_key( (string) ( is_array( $item ) ? ( isset( $item['id'] ) ? $item['id'] : '' ) : $item ) );
			if ( '' === $id || isset( $seen[ $id ] ) || ! isset( $meta[ $id ] ) ) {
				continue;
			}
			$section = $meta[ $id ];
			$fits    = isset( $section['fits'] ) ? $section['fits'] : 'any';
			if ( ( 'home' === $fits && ! $home ) || ( 'inner' === $fits && $home ) ) {
				continue;
			}
			if ( array_diff( isset( $section['needs'] ) ? (array) $section['needs'] : array(), $has ) ) {
				continue;
			}
			// One contact form per page.
			if ( in_array( 'wpzoom-forms', (array) ( isset( $section['needs'] ) ? $section['needs'] : array() ), true ) ) {
				if ( ! empty( $has_form ) ) {
					continue;
				}
				$has_form = true;
			}
			// One opener, in first position only.
			if ( in_array( $section['role'], array( 'hero', 'page-header' ), true ) && $out ) {
				continue;
			}
			$seen[ $id ] = true;
			$out[]       = array(
				'id'          => $id,
				'headline'    => is_array( $item ) && isset( $item['headline'] ) ? trim( mb_substr( sanitize_text_field( (string) $item['headline'] ), 0, 160 ) ) : '',
				'brief'       => is_array( $item ) && isset( $item['brief'] ) ? trim( mb_substr( sanitize_text_field( (string) $item['brief'] ), 0, 300 ) ) : '',
				'role'        => $section['role'],
				'tone'        => isset( $section['tone'] ) ? $section['tone'] : 'light',
				'tier'        => isset( $section['tier'] ) ? $section['tier'] : 'lite',
				'description' => isset( $section['description'] ) ? $section['description'] : '',
				'photos'      => isset( $section['photos'] ) ? (int) $section['photos'] : 0,
			);
		}
		return array_slice( $out, 0, 10 );
	}

	/**
	 * A plan coming back from the editor: the same cleaning as a fresh one.
	 *
	 * @param array $plan Plan.
	 * @return array|\WP_Error
	 */
	private function clean_plan( array $plan ) {
		$meta = Catalog\Catalog::meta();
		if ( ! $meta ) {
			return new \WP_Error( 'ai_catalog_unavailable', __( 'The section catalog is unavailable. Please try again later.', 'inspiro-starter-sites' ) );
		}
		$page_type = isset( $plan['page_type'] ) ? sanitize_key( (string) $plan['page_type'] ) : 'inner';
		if ( ! in_array( $page_type, array( 'home', 'landing', 'inner', 'sections' ), true ) ) {
			$page_type = 'inner';
		}
		$sections = $this->clean_sections( isset( $plan['sections'] ) ? $plan['sections'] : array(), $page_type, $meta );
		if ( ! $sections ) {
			return new \WP_Error( 'ai_invalid_plan', __( 'The page plan has no usable sections. Please plan the page again.', 'inspiro-starter-sites' ) );
		}
		$requirements = array();
		foreach ( array_slice( isset( $plan['requirements'] ) && is_array( $plan['requirements'] ) ? $plan['requirements'] : array(), 0, 20 ) as $req ) {
			$req = trim( mb_substr( sanitize_text_field( (string) $req ), 0, 200 ) );
			if ( '' !== $req ) {
				$requirements[] = $req;
			}
		}
		return array(
			'description'  => trim( mb_substr( sanitize_textarea_field( (string) ( isset( $plan['description'] ) ? $plan['description'] : '' ) ), 0, 3000 ) ),
			'page_type'    => $page_type,
			'language'     => preg_replace( '/[^A-Za-z ()\-]/', '', mb_substr( (string) ( isset( $plan['language'] ) ? $plan['language'] : '' ), 0, 40 ) ),
			'title'        => trim( mb_substr( sanitize_text_field( (string) ( isset( $plan['title'] ) ? $plan['title'] : '' ) ), 0, 80 ) ),
			'requirements' => $requirements,
			'sections'     => $sections,
		);
	}

	private function clean_ids( $list, $max ) {
		$out = array();
		foreach ( array_slice( is_array( $list ) ? $list : array(), 0, $max ) as $id ) {
			$id = sanitize_key( (string) ( is_array( $id ) ? ( isset( $id['id'] ) ? $id['id'] : '' ) : $id ) );
			if ( '' !== $id ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Section contents from a copy call: JSON Lines (one {"id", ...fields}
	 * per line) or the wrapped form {"sections":[{"id","content"}]}.
	 *
	 * @param string $text Model output.
	 * @return array id => fields
	 */
	private function parse_copy( $text ) {
		$out  = array();
		$text = preg_replace( '/^```(?:json)?\s*|\s*```$/s', '', trim( (string) $text ) );

		$whole = json_decode( $text, true );
		if ( is_array( $whole ) && isset( $whole['sections'] ) && is_array( $whole['sections'] ) ) {
			foreach ( $whole['sections'] as $entry ) {
				if ( is_array( $entry ) && ! empty( $entry['id'] ) ) {
					$fields = isset( $entry['content'] ) && is_array( $entry['content'] ) ? $entry['content'] : $entry;
					unset( $fields['id'] );
					$out[ sanitize_key( (string) $entry['id'] ) ] = $fields;
				}
			}
			return $out;
		}

		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$line = rtrim( trim( $line ), ',' );
			if ( '' === $line || '{' !== $line[0] ) {
				continue;
			}
			$entry = $this->proxy->decode_object( $line );
			if ( is_array( $entry ) && ! empty( $entry['id'] ) ) {
				$id = sanitize_key( (string) $entry['id'] );
				unset( $entry['id'] );
				if ( ! isset( $out[ $id ] ) ) {
					$out[ $id ] = isset( $entry['content'] ) && is_array( $entry['content'] ) ? $entry['content'] : $entry;
				}
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Site context: palette, CSS, links, profile
	 * ------------------------------------------------------------------ */

	/**
	 * The theme's accent color: Inspiro Premium's "color-accent" mod or
	 * Lite's color scheme, falling back to the demos' teal.
	 *
	 * @return string Hex.
	 */
	public function accent() {
		if ( AiDemoGenerator::is_premium_inspiro() ) {
			$hex = get_theme_mod( 'color-accent', '#0bb4aa' );
		} else {
			$hex = function_exists( 'inspiro_get_theme_mod' ) ? inspiro_get_theme_mod( 'colorscheme_hex' ) : get_theme_mod( 'colorscheme_hex', '' );
		}
		$hex = sanitize_hex_color( (string) $hex );
		return $hex ? strtolower( $hex ) : '#0bb4aa';
	}

	private function palette() {
		return Catalog\Catalog::palette( $this->accent() );
	}

	/**
	 * The catalog stylesheet for this site, rebuilt when the accent changes.
	 *
	 * @return string
	 */
	public function css() {
		$accent = $this->accent();
		$stored = get_option( self::CSS_OPTION, array() );
		if ( is_array( $stored ) && isset( $stored['accent'], $stored['css'] ) && $stored['accent'] === $accent && '' !== $stored['css'] ) {
			return (string) $stored['css'];
		}
		$css = Catalog\Catalog::css( Catalog\Catalog::palette( $accent ) );
		update_option(
			self::CSS_OPTION,
			array(
				'accent' => $accent,
				'css'    => $css,
			),
			false
		);
		return $css;
	}

	/**
	 * Remember that a post holds AI-built sections, so it gets the catalog CSS.
	 *
	 * @param int $post_id Post ID.
	 */
	public function mark_page( $post_id ) {
		update_post_meta( (int) $post_id, self::PAGE_META_KEY, 1 );
	}

	public function print_css() {
		if ( ! is_singular() || ! get_post_meta( get_queried_object_id(), self::PAGE_META_KEY, true ) ) {
			return;
		}
		// Demo pages already print this stylesheet under their own id.
		if ( get_post_meta( get_queried_object_id(), AiDemoGenerator::GENERATED_META_KEY, true ) ) {
			return;
		}
		echo "\n<style id=\"inspiro-starter-sites-ai-page-css\">\n" . wp_strip_all_tags( $this->css() ) . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- generated from hex colors and fixed rules.
	}

	public function enqueue_editor_css() {
		$post = get_post();
		if ( ! $post || ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
			return;
		}
		if ( ! get_post_meta( $post->ID, self::PAGE_META_KEY, true ) ) {
			return;
		}
		wp_register_style( 'inspiro-starter-sites-ai-page', false, array(), INSPIRO_STARTER_SITES_VERSION );
		wp_enqueue_style( 'inspiro-starter-sites-ai-page' );
		wp_add_inline_style( 'inspiro-starter-sites-ai-page', $this->css() );
	}

	/**
	 * The site's published pages, for button links in the copy.
	 *
	 * @return array[] [ { slug, title } ]
	 */
	private function site_pages() {
		$out   = array();
		$front = (int) get_option( 'page_on_front' );
		$pages = get_pages(
			array(
				'post_status' => 'publish',
				'number'      => 40,
				'sort_column' => 'menu_order,post_title',
			)
		);
		$out[] = array(
			'slug'  => 'home',
			'title' => __( 'Home', 'inspiro-starter-sites' ),
		);
		foreach ( (array) $pages as $page ) {
			if ( (int) $page->ID === $front || '' === $page->post_name ) {
				continue;
			}
			$out[] = array(
				'slug'  => $page->post_name,
				'title' => $page->post_title,
			);
		}
		return $out;
	}

	/** Page slug => URL, for the renderer's "#page:<slug>" button targets. */
	private function page_links() {
		$links = array( 'home' => home_url( '/' ) );
		foreach ( $this->site_pages() as $page ) {
			if ( 'home' !== $page['slug'] ) {
				$page_obj = get_page_by_path( $page['slug'] );
				$links[ $page['slug'] ] = $page_obj ? get_permalink( $page_obj ) : home_url( '/' . $page['slug'] . '/' );
			}
		}
		return $links;
	}

	/**
	 * The site-wide business profile, saved by the AI settings; reused by
	 * every page so the user describes the business once.
	 *
	 * @return string
	 */
	public function business_profile() {
		$profile = get_option( 'inspiro_starter_sites_ai_business_profile', '' );
		/**
		 * Filter the business profile sent with every page build.
		 *
		 * @param string $profile Free text.
		 */
		return trim( (string) apply_filters( 'inspiro_starter_sites/ai_business_profile', $profile ) );
	}

	/* ---------------------------------------------------------------------
	 * Photos and assets
	 * ------------------------------------------------------------------ */

	/**
	 * Photo resolver for one build: unique Pexels downloads up to the
	 * budget, then reuse of the photos already downloaded.
	 *
	 * @param array    $images Filled with [ id, url, photo_id ] per download.
	 * @param callable $tick   Keep-alive.
	 * @return callable ( $query, $orientation ) => [ id, url ] | null
	 */
	private function image_resolver( array &$images, $tick ) {
		$used   = array();
		$cursor = 0;
		return function ( $query, $orientation ) use ( &$images, &$used, &$cursor, $tick ) {
			if ( count( $images ) >= self::MAX_PHOTOS ) {
				return $images ? $images[ $cursor++ % count( $images ) ] : null;
			}
			$photos = $this->proxy->pexels_photos( $query, 4, $orientation );
			call_user_func( $tick );
			foreach ( is_array( $photos ) ? $photos : array() as $photo ) {
				if ( empty( $photo['url'] ) || in_array( (int) $photo['id'], $used, true ) ) {
					continue;
				}
				$image = $this->sideload_photo( $photo, $query );
				call_user_func( $tick );
				if ( $image ) {
					$used[]   = $image['photo_id'];
					$images[] = $image;
					return $image;
				}
			}
			return $images ? $images[ $cursor++ % count( $images ) ] : null;
		};
	}

	/**
	 * Sideload one Pexels photo into the media library.
	 *
	 * @param array  $photo Pexels photo (id, url, alt).
	 * @param string $query Search phrase, for the attachment title.
	 * @return array|null [ id, url, photo_id ]
	 */
	private function sideload_photo( array $photo, $query ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		// Content images render at `full`; the registered crop sizes are
		// never displayed, so skip generating them (~1s per image).
		add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 100 );
		$attachment_id = media_sideload_image( esc_url_raw( (string) $photo['url'] ), 0, sanitize_text_field( get_bloginfo( 'name' ) . ' — ' . $query ), 'id' );
		remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 100 );
		if ( is_wp_error( $attachment_id ) ) {
			return null;
		}
		$url = wp_get_attachment_image_url( $attachment_id, 'full' );
		if ( ! $url ) {
			return null;
		}
		update_post_meta( $attachment_id, self::PHOTO_META_KEY, (int) $photo['id'] );
		if ( ! empty( $photo['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $photo['alt'] ) );
		}
		return array(
			'id'       => (int) $attachment_id,
			'url'      => $url,
			'photo_id' => (int) $photo['id'],
		);
	}

	/**
	 * A demo's static asset (icon, logo) copied into this site's media
	 * library once and reused; shares the demo generator's map.
	 *
	 * @param string $url Source URL on the demo server.
	 * @return array|null [ id, url ]
	 */
	public function catalog_asset( $url ) {
		$map = get_option( 'inspiro_starter_sites_ai_catalog_assets', array() );
		$map = is_array( $map ) ? $map : array();
		if ( isset( $map[ $url ] ) && 'attachment' === get_post_type( (int) $map[ $url ] ) ) {
			return array(
				'id'  => (int) $map[ $url ],
				'url' => wp_get_attachment_url( (int) $map[ $url ] ),
			);
		}
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$id = media_sideload_image( esc_url_raw( $url ), 0, null, 'id' );
		if ( is_wp_error( $id ) ) {
			return null;
		}
		$map[ $url ] = (int) $id;
		update_option( 'inspiro_starter_sites_ai_catalog_assets', $map, false );
		return array(
			'id'  => (int) $id,
			'url' => wp_get_attachment_url( $id ),
		);
	}

	/** The form contact sections embed: WPZOOM Forms seeds one on activation. */
	private function first_form_id() {
		if ( ! post_type_exists( 'wpzf-form' ) ) {
			return 0;
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
		return $forms ? (int) $forms[0] : 0;
	}

	/* ---------------------------------------------------------------------
	 * Editor integration
	 * ------------------------------------------------------------------ */

	/**
	 * The modal script, for pages and posts in the block editor.
	 */
	public function enqueue_editor_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, array( 'page', 'post' ), true ) || ! current_user_can( 'import' ) ) {
			return;
		}
		$dir   = INSPIRO_STARTER_SITES_PATH . 'components/importer/assets/editor/';
		$url   = INSPIRO_STARTER_SITES_URL . 'components/importer/assets/editor/';
		$asset = file_exists( $dir . 'index.asset.php' ) ? include $dir . 'index.asset.php' : array( 'dependencies' => array(), 'version' => INSPIRO_STARTER_SITES_VERSION );
		if ( ! file_exists( $dir . 'index.js' ) ) {
			return;
		}

		wp_enqueue_script( 'inspiro-starter-sites-ai-page-builder', $url . 'index.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'inspiro-starter-sites-ai-page-builder', 'inspiro-starter-sites' );
		if ( file_exists( $dir . 'style-index.css' ) ) {
			wp_enqueue_style( 'inspiro-starter-sites-ai-page-builder', $url . 'style-index.css', array( 'wp-components' ), $asset['version'] );
		}

		$post = get_post();
		wp_localize_script(
			'inspiro-starter-sites-ai-page-builder',
			'inspiroStarterSitesPageBuilder',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'inspiro-starter-sites-ajax-verification' ),
				'postId'       => $post ? (int) $post->ID : 0,
				'postType'     => $screen->post_type,
				// ?iss_ai=1 on post-new.php (the "New page with AI" action) opens the modal at once.
				'autoOpen'     => ! empty( $_GET['iss_ai'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'premiumTheme' => AiDemoGenerator::is_premium_inspiro(),
				'hasLicense'   => '' !== AiProxyClient::premium_license(),
				'connected'    => $this->proxy->is_connected() || '' !== AiProxyClient::premium_license(),
				'email'        => $this->proxy->connected_email(),
				'siteName'     => get_bloginfo( 'name' ),
				'accent'       => $this->accent(),
				'upgradeUrl'   => 'https://www.wpzoom.com/themes/inspiro-lite/upgrade/?utm_source=wpadmin&utm_medium=ai-page&utm_campaign=ai-quota-upsell',
				'licenseUrl'   => admin_url( 'admin.php?page=wpzoom_license#license' ),
				'examples'     => array(
					__( 'About page: our story since 2015, the team of four, our values, and a call to visit us.', 'inspiro-starter-sites' ),
					__( 'Services page: three services with short descriptions, how we work in four steps, client testimonials, and a contact section.', 'inspiro-starter-sites' ),
					__( 'Pricing page: three membership plans, what members say, frequently asked questions, and a free trial call to action.', 'inspiro-starter-sites' ),
					__( 'A testimonials section with three quotes and a call to action.', 'inspiro-starter-sites' ),
				),
			)
		);
	}

	/**
	 * "New page with AI" next to "Add New Page" on the Pages screen: a draft
	 * opens in the editor with the modal already up.
	 */
	public function pages_screen_button() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'page' !== $screen->post_type || ! current_user_can( 'import' ) || ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		$url   = esc_url( admin_url( 'post-new.php?post_type=page&iss_ai=1' ) );
		$label = esc_js( __( 'New page with AI', 'inspiro-starter-sites' ) );
		echo '<script>document.addEventListener("DOMContentLoaded",function(){var a=document.querySelector(".wrap .page-title-action");if(!a)return;var b=document.createElement("a");b.className="page-title-action";b.href="' . $url . '";b.textContent="' . $label . '";a.insertAdjacentElement("afterend",b);});</script>' . "\n";
	}

	/* ---------------------------------------------------------------------
	 * Errors
	 * ------------------------------------------------------------------ */

	/**
	 * A proxy or plan error as the editor expects it: message plus a code
	 * the UI can act on (registration_required → connect step, etc.).
	 *
	 * @param \WP_Error $error Error.
	 * @return array
	 */
	private function error_payload( \WP_Error $error ) {
		$code = $error->get_error_code();
		if ( 'ai_registration_required' === $code ) {
			$this->proxy->disconnect();
			$code = 'registration_required';
		}
		return array(
			'code'    => $code,
			'message' => $error->get_error_message(),
		);
	}
}
