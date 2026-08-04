<?php
/**
 * "Generate a demo with AI" feature (beta).
 *
 * Flow (client-orchestrated so each request stays short):
 *   1. ai_generate   — consume 1 quota unit, one Claude call returns a JSON
 *                      site plan (pages + sections + copy + image queries),
 *                      cached in a transient.
 *   2. ai_build_page — called once per page: fetches Pexels photos, sideloads
 *                      them into the media library, composes block markup,
 *                      inserts the page.
 *   3. ai_finalize   — nav menu + front page assignment, cleanup.
 *
 * @package Inspiro Starter Sites
 */

namespace Inspiro\Starter_Sites\Ai;

use Inspiro\Starter_Sites\Helpers;

defined( 'ABSPATH' ) || exit;

class AiDemoGenerator {

	const PLAN_TRANSIENT_PREFIX = 'inspiro_starter_sites_ai_plan_';
	const GENERATED_META_KEY    = '_inspiro_starter_sites_ai_demo';
	const DEMOS_OPTION          = 'inspiro_starter_sites_ai_demos';

	const MAX_PAGES             = 6;
	const MAX_SECTIONS_PER_PAGE = 8;

	/**
	 * Singleton instance.
	 *
	 * @var AiDemoGenerator|null
	 */
	private static $instance = null;

	/**
	 * @var AiProxyClient
	 */
	private $proxy;

	/**
	 * @return AiDemoGenerator
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->proxy = new AiProxyClient();

		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ), 20 );

		add_action( 'wp_ajax_inspiro_starter_sites_ai_quota', array( $this, 'ajax_quota' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_generate', array( $this, 'ajax_generate' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_build_page', array( $this, 'ajax_build_page' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_finalize', array( $this, 'ajax_finalize' ) );
	}

	/**
	 * Feature switch.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'inspiro_starter_sites/ai_enabled', true );
	}

	/**
	 * Enqueue the AI generator assets on the demo-importer admin page.
	 *
	 * Runs after the importer's own enqueue (priority 20) and piggybacks on
	 * the same page detection: if the importer CSS is queued, we're on the
	 * right screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function admin_enqueue_scripts( $hook ) {
		if ( ! wp_style_is( 'inspiro-starter-sites-importer-css', 'enqueued' ) ) {
			return;
		}

		$js_path  = INSPIRO_STARTER_SITES_PATH . 'components/importer/assets/js/ai-generator.js';
		$css_path = INSPIRO_STARTER_SITES_PATH . 'components/importer/assets/css/ai-generator.css';
		$js_ver   = file_exists( $js_path ) ? filemtime( $js_path ) : INSPIRO_STARTER_SITES_VERSION;
		$css_ver  = file_exists( $css_path ) ? filemtime( $css_path ) : INSPIRO_STARTER_SITES_VERSION;

		wp_enqueue_script(
			'inspiro-starter-sites-ai-generator-js',
			INSPIRO_STARTER_SITES_URL . 'components/importer/assets/js/ai-generator.js',
			array( 'jquery' ),
			$js_ver,
			true
		);

		wp_enqueue_style(
			'inspiro-starter-sites-ai-generator-css',
			INSPIRO_STARTER_SITES_URL . 'components/importer/assets/css/ai-generator.css',
			array( 'inspiro-starter-sites-importer-css' ),
			$css_ver
		);

		wp_localize_script(
			'inspiro-starter-sites-ai-generator-js',
			'inspiro_starter_sites_ai',
			array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'ajax_nonce' => wp_create_nonce( 'inspiro-starter-sites-ajax-verification' ),
				'pages_url'  => admin_url( 'edit.php?post_type=page' ),
				'site_url'   => home_url( '/' ),
				'ideas'      => array(
					esc_html__( 'A portfolio site for a wedding photographer based in Lisbon, with a moody, elegant style.', 'inspiro-starter-sites' ),
					esc_html__( 'A website for a small family-run Italian restaurant with a seasonal menu and cozy atmosphere.', 'inspiro-starter-sites' ),
					esc_html__( 'A yoga studio site offering morning classes, workshops, and private sessions.', 'inspiro-starter-sites' ),
					esc_html__( 'A site for an architecture firm specializing in sustainable residential design.', 'inspiro-starter-sites' ),
					esc_html__( 'A personal site for a fitness coach offering online training programs.', 'inspiro-starter-sites' ),
					esc_html__( 'A website for a boutique travel agency organizing hiking tours in the Alps.', 'inspiro-starter-sites' ),
				),
				'texts'      => array(
					'title'            => esc_html__( 'Generate a demo with AI', 'inspiro-starter-sites' ),
					'beta'             => esc_html__( 'Beta', 'inspiro-starter-sites' ),
					'intro'            => esc_html__( 'Describe the website you need and AI will design and build a few pages for you in about a minute.', 'inspiro-starter-sites' ),
					'placeholder'      => esc_html__( 'e.g. A website for a small coffee roastery in Portland that sells beans online and hosts tasting events…', 'inspiro-starter-sites' ),
					'ideas_label'      => esc_html__( 'Need inspiration? Try one of these:', 'inspiro-starter-sites' ),
					'generate'         => esc_html__( 'Generate demo', 'inspiro-starter-sites' ),
					'generating'       => esc_html__( 'Generating…', 'inspiro-starter-sites' ),
					'quota_left'       => /* translators: %1$s: used, %2$s: limit */ esc_html__( '%1$s of %2$s free generations used', 'inspiro-starter-sites' ),
					'quota_none'       => esc_html__( 'You have used all your free AI generations for this site.', 'inspiro-starter-sites' ),
					'quota_loading'    => esc_html__( 'Checking available generations…', 'inspiro-starter-sites' ),
					'too_short'        => esc_html__( 'Please describe your website in a bit more detail (at least a short sentence).', 'inspiro-starter-sites' ),
					'replace_title'    => esc_html__( 'You already have an AI-generated demo', 'inspiro-starter-sites' ),
					/* translators: %1$s: previous demo name, %2$s: number of pages */
					'replace_notice'   => esc_html__( 'Generating a new demo will permanently delete the %2$s page(s) from “%1$s” — including any changes you made to them.', 'inspiro-starter-sites' ),
					/* translators: %s: number of pages */
					'replace_notice_unnamed' => esc_html__( 'Generating a new demo will permanently delete the %s previously generated AI page(s) — including any changes you made to them.', 'inspiro-starter-sites' ),
					'replace_checkbox' => esc_html__( 'Delete the previous AI demo when generating the new one', 'inspiro-starter-sites' ),
					'replace_keep_hint'=> esc_html__( 'Uncheck to keep the old pages — they will remain published alongside the new demo.', 'inspiro-starter-sites' ),
					'step_plan'        => esc_html__( 'Designing your site structure and writing the copy…', 'inspiro-starter-sites' ),
					/* translators: %1$s: current page number, %2$s: total pages, %3$s: page title */
					'step_page'        => esc_html__( 'Creating page %1$s of %2$s: %3$s', 'inspiro-starter-sites' ),
					'step_finalize'    => esc_html__( 'Setting up navigation and homepage…', 'inspiro-starter-sites' ),
					'progress_hint'    => esc_html__( 'This usually takes about a minute. Please keep this tab open.', 'inspiro-starter-sites' ),
					'success_title'    => esc_html__( 'Your demo is ready!', 'inspiro-starter-sites' ),
					'success_text'     => esc_html__( 'The AI created the following pages, set up the menu, and assigned your new homepage.', 'inspiro-starter-sites' ),
					'view_site'        => esc_html__( 'View site', 'inspiro-starter-sites' ),
					'edit_pages'       => esc_html__( 'Edit pages', 'inspiro-starter-sites' ),
					'error_title'      => esc_html__( 'Something went wrong', 'inspiro-starter-sites' ),
					'error_generic'    => esc_html__( 'The AI service could not complete the request. Please try again in a moment.', 'inspiro-starter-sites' ),
					'try_again'        => esc_html__( 'Try again', 'inspiro-starter-sites' ),
					'close'            => esc_html__( 'Close', 'inspiro-starter-sites' ),
					'page_failed'      => esc_html__( 'One of the pages could not be created, continuing with the rest…', 'inspiro-starter-sites' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * AJAX: quota check
	 * ------------------------------------------------------------------ */

	public function ajax_quota() {
		Helpers::verify_ajax_call();

		$quota = $this->proxy->quota( 'check' );

		if ( is_wp_error( $quota ) ) {
			wp_send_json_error( array( 'message' => $quota->get_error_message() ) );
		}

		// Tell the UI about a previously generated demo so it can warn the
		// user that generating a new one replaces it.
		$previous       = null;
		$previous_pages = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => self::GENERATED_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		if ( $previous_pages ) {
			$demos = get_option( self::DEMOS_OPTION, array() );
			$last  = ( is_array( $demos ) && $demos ) ? end( $demos ) : array();

			$previous = array(
				'site_title' => isset( $last['site_title'] ) ? $last['site_title'] : '',
				'page_count' => count( $previous_pages ),
			);
		}

		wp_send_json_success(
			array(
				'used'      => isset( $quota['used'] ) ? (int) $quota['used'] : 0,
				'limit'     => isset( $quota['limit'] ) ? (int) $quota['limit'] : 0,
				'remaining' => isset( $quota['remaining'] ) ? (int) $quota['remaining'] : 0,
				'previous'  => $previous,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * AJAX: generate the site plan
	 * ------------------------------------------------------------------ */

	public function ajax_generate() {
		Helpers::verify_ajax_call();

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 320 ); // phpcs:ignore
		}

		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$description = trim( $description );

		if ( mb_strlen( $description ) < 12 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please describe your website in a bit more detail.', 'inspiro-starter-sites' ) ) );
		}
		if ( mb_strlen( $description ) > 1200 ) {
			$description = mb_substr( $description, 0, 1200 );
		}

		// Reserve one generation before doing the expensive work. Fail closed:
		// if the quota service is unreachable, so is the generation service.
		$quota = $this->proxy->quota( 'consume' );

		if ( is_wp_error( $quota ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'The AI service is currently unreachable. Please try again later.', 'inspiro-starter-sites' ) ) );
		}

		if ( empty( $quota['allowed'] ) ) {
			wp_send_json_error(
				array(
					'code'    => 'quota_exhausted',
					'message' => esc_html__( 'You have used all your free AI generations for this site.', 'inspiro-starter-sites' ),
				)
			);
		}

		// The Claude call runs 40-90s. Stream keep-alive bytes while waiting
		// so web servers with short idle timeouts (Apache FastCGI: 30s) don't
		// kill this request. From here on, errors go through $stream.
		$stream = new StreamingResponse();
		$stream->begin();

		$plan = $this->proxy->claude_json(
			$this->system_prompt(),
			$this->plan_prompt( $description ),
			12000,
			array( $stream, 'tick' )
		);

		if ( ! is_wp_error( $plan ) ) {
			$plan = $this->sanitize_plan( $plan );
		}

		if ( is_wp_error( $plan ) ) {
			// The reserved unit wasn't used — give it back.
			$this->proxy->quota( 'refund' );
			$stream->finish_error(
				array(
					'message' => $plan->get_error_message(),
					'detail'  => $plan->get_error_code(),
				)
			);
		}

		$plan_id = 'ai' . strtolower( wp_generate_password( 12, false, false ) );

		// Whether to delete the previous AI demo before building the new one
		// (the UI shows an explicit, checked-by-default warning checkbox).
		$replace = ! isset( $_POST['replace'] ) || '0' !== $_POST['replace'];

		set_transient(
			self::PLAN_TRANSIENT_PREFIX . $plan_id,
			array(
				'description'    => $description,
				'plan'           => $plan,
				'replace'        => $replace,
				'created_pages'  => array(), // page index => post ID
				'used_photo_ids' => array(),
				'created_at'     => time(),
			),
			HOUR_IN_SECONDS
		);

		$stream->finish_success(
			array(
				'plan_id'    => $plan_id,
				'site_title' => $plan['site_title'],
				'tagline'    => $plan['tagline'],
				'pages'      => array_map(
					static function ( $page ) {
						return array(
							'slug'  => $page['slug'],
							'title' => $page['title'],
						);
					},
					$plan['pages']
				),
				'remaining'  => isset( $quota['remaining'] ) ? (int) $quota['remaining'] : null,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * AJAX: build a single page
	 * ------------------------------------------------------------------ */

	public function ajax_build_page() {
		Helpers::verify_ajax_call();

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore
		}

		$plan_id = isset( $_POST['plan_id'] ) ? sanitize_key( wp_unslash( $_POST['plan_id'] ) ) : '';
		$index   = isset( $_POST['page_index'] ) ? (int) $_POST['page_index'] : -1;

		$state = $this->get_plan_state( $plan_id );
		if ( ! $state || ! isset( $state['plan']['pages'][ $index ] ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'This generation session has expired. Please start again.', 'inspiro-starter-sites' ) ) );
		}

		// Idempotency: if this page was already built (e.g. a retried request),
		// return the existing result instead of duplicating it.
		if ( isset( $state['created_pages'][ $index ] ) ) {
			$existing = (int) $state['created_pages'][ $index ];
			wp_send_json_success(
				array(
					'page_id'  => $existing,
					'edit_url' => get_edit_post_link( $existing, 'raw' ),
				)
			);
		}

		// Image search + sideloads can exceed short server idle timeouts on
		// slow connections — stream keep-alive bytes between operations.
		$stream = new StreamingResponse();
		$stream->begin();

		// The previous AI demo is deleted only now — after the new plan has
		// been generated successfully — so a failed generation never destroys
		// the existing demo. Runs once, before the first page is built.
		if ( 0 === $index && ! empty( $state['replace'] ) ) {
			$this->delete_previous_ai_demos( $plan_id );
			$stream->tick();
		}

		$page = $state['plan']['pages'][ $index ];

		// Resolve section images: Pexels search via proxy + sideload to the
		// media library. Failures degrade to image-less sections.
		$sections = array();
		foreach ( $page['sections'] as $section ) {
			if ( ! empty( $section['image_query'] ) ) {
				$image = $this->resolve_image( $section['image_query'], $state['used_photo_ids'], $state['plan']['site_title'], $plan_id );
				if ( $image ) {
					$section['image']         = $image;
					$state['used_photo_ids'][] = $image['photo_id'];
				}
				$stream->tick();
			}
			$sections[] = $section;
		}

		// Permalinks for button targets: pages that don't exist yet get their
		// pretty-permalink guess, which resolves correctly once created.
		$page_links = array();
		foreach ( $state['plan']['pages'] as $p ) {
			$page_links[ $p['slug'] ] = ( 'home' === $p['slug'] ) ? home_url( '/' ) : home_url( '/' . $p['slug'] . '/' );
		}

		$composer = new BlockComposer( $page_links );
		$content  = $composer->compose_page( $sections, $page );

		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $page['title'],
					'post_name'    => $page['slug'],
					'post_content' => $content,
					'menu_order'   => $index,
					'meta_input'   => array(
						self::GENERATED_META_KEY => $plan_id,
						'_wp_page_template'      => 'page-templates/full-width-no-title.php',
					),
				)
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			$stream->finish_error( array( 'message' => $post_id->get_error_message() ) );
		}

		$state['created_pages'][ $index ] = (int) $post_id;
		set_transient( self::PLAN_TRANSIENT_PREFIX . $plan_id, $state, HOUR_IN_SECONDS );

		$stream->finish_success(
			array(
				'page_id'  => (int) $post_id,
				'edit_url' => get_edit_post_link( $post_id, 'raw' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * AJAX: finalize (menu, front page, cleanup)
	 * ------------------------------------------------------------------ */

	public function ajax_finalize() {
		Helpers::verify_ajax_call();

		$plan_id = isset( $_POST['plan_id'] ) ? sanitize_key( wp_unslash( $_POST['plan_id'] ) ) : '';
		$state   = $this->get_plan_state( $plan_id );

		if ( ! $state ) {
			wp_send_json_error( array( 'message' => esc_html__( 'This generation session has expired.', 'inspiro-starter-sites' ) ) );
		}

		$pages         = $state['plan']['pages'];
		$created_pages = $state['created_pages'];

		if ( empty( $created_pages ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'No pages were created.', 'inspiro-starter-sites' ) ) );
		}

		ksort( $created_pages );

		// Button links were composed from pretty-permalink guesses
		// (home_url('/slug/')). If WP had to suffix a slug on insert (an
		// existing page already used it), the guess is wrong — rewrite the
		// guessed URLs to the actual permalinks.
		$url_map = array();
		foreach ( $pages as $i => $p ) {
			if ( 'home' === $p['slug'] || ! isset( $created_pages[ $i ] ) ) {
				continue; // 'home' guesses home_url('/'), which stays correct.
			}
			$guessed = home_url( '/' . $p['slug'] . '/' );
			$actual  = get_permalink( $created_pages[ $i ] );
			if ( $actual && $guessed !== $actual ) {
				$url_map[ $guessed ] = $actual;
			}
		}

		if ( $url_map ) {
			foreach ( $created_pages as $page_id ) {
				$post = get_post( $page_id );
				if ( ! $post ) {
					continue;
				}
				$updated = str_replace( array_keys( $url_map ), array_values( $url_map ), $post->post_content );
				if ( $updated !== $post->post_content ) {
					wp_update_post(
						wp_slash(
							array(
								'ID'           => $page_id,
								'post_content' => $updated,
							)
						)
					);
				}
			}
		}

		// Navigation menu with the generated pages, assigned to the theme's
		// primary location (same behavior as a regular demo import).
		$menu_name = esc_html__( 'AI Demo Menu', 'inspiro-starter-sites' );
		$menu      = wp_get_nav_menu_object( $menu_name );
		$menu_id   = $menu ? (int) $menu->term_id : (int) wp_create_nav_menu( $menu_name );

		if ( $menu_id && ! is_wp_error( $menu_id ) ) {
			// Clear items from a previous generation so the menu isn't duplicated.
			$existing_items = wp_get_nav_menu_items( $menu_id );
			if ( is_array( $existing_items ) ) {
				foreach ( $existing_items as $item ) {
					wp_delete_post( $item->ID, true );
				}
			}

			foreach ( $created_pages as $page_index => $page_id ) {
				wp_update_nav_menu_item(
					$menu_id,
					0,
					array(
						'menu-item-object-id' => (int) $page_id,
						'menu-item-object'    => 'page',
						'menu-item-type'      => 'post_type',
						'menu-item-status'    => 'publish',
						'menu-item-title'     => isset( $pages[ $page_index ]['title'] ) ? $pages[ $page_index ]['title'] : get_the_title( $page_id ),
					)
				);
			}

			$locations            = get_theme_mod( 'nav_menu_locations', array() );
			$locations            = is_array( $locations ) ? $locations : array();
			$locations['primary'] = $menu_id;
			set_theme_mod( 'nav_menu_locations', $locations );
		}

		// Homepage = the page the plan flagged as 'home' (fallback: first page).
		$front_index = 0;
		foreach ( $pages as $i => $p ) {
			if ( 'home' === $p['slug'] ) {
				$front_index = $i;
				break;
			}
		}
		$front_id = isset( $created_pages[ $front_index ] ) ? (int) $created_pages[ $front_index ] : (int) reset( $created_pages );

		if ( $front_id ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $front_id );
		}

		// Record the generation so the content can be identified (and cleaned
		// up) later. Post meta on each page carries the same plan ID.
		$demos = get_option( self::DEMOS_OPTION, array() );
		$demos = is_array( $demos ) ? $demos : array();

		$demos[ $plan_id ] = array(
			'site_title'  => $state['plan']['site_title'],
			'description' => $state['description'],
			'pages'       => array_values( $created_pages ),
			'menu_id'     => $menu_id && ! is_wp_error( $menu_id ) ? (int) $menu_id : 0,
			'created_at'  => current_time( 'mysql' ),
		);

		update_option( self::DEMOS_OPTION, $demos, false );

		delete_transient( self::PLAN_TRANSIENT_PREFIX . $plan_id );

		// Fresh permalinks for the new pages.
		flush_rewrite_rules();

		wp_send_json_success(
			array(
				'view_url'  => home_url( '/' ),
				'pages_url' => admin_url( 'edit.php?post_type=page' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Permanently delete all previously AI-generated content: pages and the
	 * sideloaded attachments carrying the generated-demo meta key (any plan
	 * except the one currently being built), plus their option records.
	 *
	 * @param string $keep_plan_id Plan ID of the generation in progress.
	 */
	private function delete_previous_ai_demos( $keep_plan_id ) {
		$post_ids = get_posts(
			array(
				'post_type'   => array( 'page', 'attachment' ),
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => self::GENERATED_META_KEY,
						'value'   => $keep_plan_id,
						'compare' => '!=',
					),
				),
			)
		);

		foreach ( $post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		// Drop the stored records of the deleted demos.
		$demos = get_option( self::DEMOS_OPTION, array() );
		if ( is_array( $demos ) && $demos ) {
			$demos = array_intersect_key( $demos, array( $keep_plan_id => true ) );
			update_option( self::DEMOS_OPTION, $demos, false );
		}
	}

	/**
	 * Load and validate a plan transient.
	 *
	 * @param string $plan_id
	 * @return array|null
	 */
	private function get_plan_state( $plan_id ) {
		if ( '' === $plan_id ) {
			return null;
		}
		$state = get_transient( self::PLAN_TRANSIENT_PREFIX . $plan_id );
		return ( is_array( $state ) && ! empty( $state['plan']['pages'] ) ) ? $state : null;
	}

	/**
	 * Search Pexels and sideload the first unused photo into the media library.
	 *
	 * @param string $query      English search phrase.
	 * @param array  $used_ids   Pexels photo IDs already used in this plan.
	 * @param string $site_title Used for the attachment description.
	 * @param string $plan_id    Plan ID stamped on the attachment for cleanup.
	 * @return array|null [ 'id' => attachment ID, 'url' => URL, 'photo_id' => pexels ID ]
	 */
	private function resolve_image( $query, array $used_ids, $site_title, $plan_id = '' ) {
		$photos = $this->proxy->pexels_photos( $query, 5 );

		foreach ( $photos as $photo ) {
			if ( in_array( $photo['id'], $used_ids, true ) ) {
				continue;
			}

			if ( ! function_exists( 'media_sideload_image' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			$attachment_id = media_sideload_image( $photo['url'], 0, sanitize_text_field( $site_title . ' — ' . $query ), 'id' );

			if ( is_wp_error( $attachment_id ) ) {
				continue;
			}

			$url = wp_get_attachment_image_url( $attachment_id, 'full' );
			if ( ! $url ) {
				continue;
			}

			if ( $plan_id ) {
				update_post_meta( $attachment_id, self::GENERATED_META_KEY, $plan_id );
			}

			return array(
				'id'       => (int) $attachment_id,
				'url'      => $url,
				'photo_id' => (int) $photo['id'],
			);
		}

		return null;
	}

	/**
	 * System prompt for the plan call.
	 *
	 * @return string
	 */
	private function system_prompt() {
		return 'You are a senior web designer and copywriter who creates WordPress demo websites. Return only valid JSON with no markdown formatting.';
	}

	/**
	 * User prompt for the plan call.
	 *
	 * @param string $description User's site description.
	 * @return string
	 */
	private function plan_prompt( $description ) {
		return "Create a complete demo website plan for the following request:\n\n"
			. '"' . $description . '"' . "\n\n"
			. "Rules:\n"
			. "- 3 to 5 pages. The FIRST page is always the homepage with slug \"home\".\n"
			. "- Pick inner pages that fit the business (e.g. about, services, menu, portfolio, contact).\n"
			. "- Write all visible copy in the language the request itself is WRITTEN in, unless it explicitly asks for another language. The nationality or cuisine of the business does NOT change the language: an English request about an Italian restaurant gets English copy.\n"
			. "- Demo-quality copy: headings max 8 words, paragraphs 1-3 short sentences. Invent realistic but fictional business details (name, email, phone, address).\n"
			. "- image_query values: short English photo search phrases (2-4 words), specific and photogenic, no brand names, no text-heavy subjects.\n"
			. "- button_page must be the slug of one of the pages in this plan.\n"
			. "- Each page has 3 to 6 sections. The homepage starts with a \"hero\" section; use \"hero\" only on the homepage.\n"
			. "- Available section types and their fields:\n"
			. "  hero: heading, text, button_text, button_page, image_query\n"
			. "  text: heading, paragraphs (array of 1-3 strings)\n"
			. "  features: heading, intro (optional), items (exactly 3 of {title, text})\n"
			. "  media_text: heading, text, media_position (\"left\" or \"right\"), image_query, optional button_text + button_page\n"
			. "  quote: text, author\n"
			. "  faq: heading, items (3-5 of {question, answer})\n"
			. "  cta: heading, text, button_text, button_page\n"
			. "  contact: heading, text, email, phone, address\n"
			. "- Vary the section types across pages; the contact page must include a \"contact\" section.\n"
			. "- Never include a literal double-quote character (\") inside any string value; use curly quotes instead.\n"
			. "- Return ONLY compact JSON matching exactly this shape:\n"
			. '{"site_title":"...","tagline":"...","pages":[{"slug":"home","title":"Home","sections":[{"type":"hero","heading":"...","text":"...","button_text":"...","button_page":"contact","image_query":"..."}]}]}';
	}

	/**
	 * Validate and sanitize the AI-returned plan into a trusted structure.
	 *
	 * @param array $plan Raw decoded plan.
	 * @return array|\WP_Error
	 */
	private function sanitize_plan( $plan ) {
		if ( empty( $plan['pages'] ) || ! is_array( $plan['pages'] ) ) {
			return new \WP_Error( 'ai_invalid_plan', esc_html__( 'The AI returned an unusable site plan. Please try again.', 'inspiro-starter-sites' ) );
		}

		$clean_text = static function ( $value, $max = 500 ) {
			return mb_substr( sanitize_text_field( wp_strip_all_tags( (string) $value ) ), 0, $max );
		};

		$allowed_types = array( 'hero', 'text', 'features', 'media_text', 'quote', 'faq', 'cta', 'contact' );

		$clean = array(
			'site_title' => $clean_text( isset( $plan['site_title'] ) ? $plan['site_title'] : '', 120 ),
			'tagline'    => $clean_text( isset( $plan['tagline'] ) ? $plan['tagline'] : '', 200 ),
			'pages'      => array(),
		);

		$seen_slugs = array();

		foreach ( array_slice( $plan['pages'], 0, self::MAX_PAGES ) as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}

			$slug  = sanitize_title( isset( $page['slug'] ) ? (string) $page['slug'] : '' );
			$title = $clean_text( isset( $page['title'] ) ? $page['title'] : '', 120 );

			if ( '' === $slug || '' === $title || isset( $seen_slugs[ $slug ] ) ) {
				continue;
			}
			$seen_slugs[ $slug ] = true;

			$sections = array();
			$raw_sections = ( ! empty( $page['sections'] ) && is_array( $page['sections'] ) ) ? $page['sections'] : array();

			foreach ( array_slice( $raw_sections, 0, self::MAX_SECTIONS_PER_PAGE ) as $section ) {
				if ( ! is_array( $section ) || empty( $section['type'] ) || ! in_array( $section['type'], $allowed_types, true ) ) {
					continue;
				}

				$s = array( 'type' => $section['type'] );

				foreach ( array( 'heading', 'text', 'intro', 'button_text', 'author', 'email', 'phone', 'address' ) as $key ) {
					if ( isset( $section[ $key ] ) ) {
						$s[ $key ] = $clean_text( $section[ $key ] );
					}
				}

				if ( isset( $section['button_page'] ) ) {
					$s['button_page'] = sanitize_title( (string) $section['button_page'] );
				}
				if ( isset( $section['media_position'] ) ) {
					$s['media_position'] = ( 'right' === $section['media_position'] ) ? 'right' : 'left';
				}
				if ( isset( $section['image_query'] ) ) {
					$s['image_query'] = $clean_text( $section['image_query'], 80 );
				}
				if ( ! empty( $section['paragraphs'] ) && is_array( $section['paragraphs'] ) ) {
					$s['paragraphs'] = array_map( $clean_text, array_slice( $section['paragraphs'], 0, 4 ) );
				}
				if ( ! empty( $section['items'] ) && is_array( $section['items'] ) ) {
					$s['items'] = array();
					foreach ( array_slice( $section['items'], 0, 6 ) as $item ) {
						if ( ! is_array( $item ) ) {
							continue;
						}
						$clean_item = array();
						foreach ( array( 'title', 'text', 'question', 'answer' ) as $key ) {
							if ( isset( $item[ $key ] ) ) {
								$clean_item[ $key ] = $clean_text( $item[ $key ] );
							}
						}
						if ( $clean_item ) {
							$s['items'][] = $clean_item;
						}
					}
				}

				$sections[] = $s;
			}

			if ( ! $sections ) {
				continue;
			}

			$clean['pages'][] = array(
				'slug'     => $slug,
				'title'    => $title,
				'sections' => $sections,
			);
		}

		if ( empty( $clean['pages'] ) ) {
			return new \WP_Error( 'ai_invalid_plan', esc_html__( 'The AI returned an unusable site plan. Please try again.', 'inspiro-starter-sites' ) );
		}

		if ( '' === $clean['site_title'] ) {
			$clean['site_title'] = esc_html__( 'AI Demo', 'inspiro-starter-sites' );
		}

		return $clean;
	}
}
