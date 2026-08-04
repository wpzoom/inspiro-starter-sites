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
	const MAX_REVIEW_PAGES      = 5;
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
		add_action( 'wp_head', array( $this, 'print_demo_css' ), 100 );
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_editor_demo_css' ) );

		add_action( 'wp_ajax_inspiro_starter_sites_ai_quota', array( $this, 'ajax_quota' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_enhance_prompt', array( $this, 'ajax_enhance_prompt' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_suggest_pages', array( $this, 'ajax_suggest_pages' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_generate', array( $this, 'ajax_generate' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_build_page', array( $this, 'ajax_build_page' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_finalize', array( $this, 'ajax_finalize' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_delete', array( $this, 'ajax_delete' ) );
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
				'styles'     => array_map(
					static function ( $style ) {
						return $style['label'];
					},
					$this->style_options()
				),
				'typographies' => array_map(
					static function ( $typography ) {
						return $typography['label'];
					},
					$this->typography_options()
				),
				'palettes'   => array_map(
					static function ( $palette ) {
						return array(
							'label'  => $palette['label'],
							'colors' => $palette['colors'],
						);
					},
					$this->palette_options()
				),
				'fallback_pages' => array(
					array( 'slug' => 'home', 'title' => __( 'Home', 'inspiro-starter-sites' ) ),
					array( 'slug' => 'about', 'title' => __( 'About', 'inspiro-starter-sites' ) ),
					array( 'slug' => 'services', 'title' => __( 'Services', 'inspiro-starter-sites' ) ),
					array( 'slug' => 'contact', 'title' => __( 'Contact', 'inspiro-starter-sites' ) ),
				),
				'ideas'      => array(
					array(
						'icon'  => 'camera',
						'title' => __( 'Photography portfolio', 'inspiro-starter-sites' ),
						'text'  => __( 'A portfolio site for a wedding photographer based in Lisbon, with a moody, elegant style.', 'inspiro-starter-sites' ),
					),
					array(
						'icon'  => 'restaurant',
						'title' => __( 'Restaurant', 'inspiro-starter-sites' ),
						'text'  => __( 'A website for a small family-run Italian restaurant with a seasonal menu and cozy atmosphere.', 'inspiro-starter-sites' ),
					),
					array(
						'icon'  => 'wellness',
						'title' => __( 'Yoga studio', 'inspiro-starter-sites' ),
						'text'  => __( 'A yoga studio site offering morning classes, workshops, and private sessions.', 'inspiro-starter-sites' ),
					),
					array(
						'icon'  => 'architecture',
						'title' => __( 'Architecture firm', 'inspiro-starter-sites' ),
						'text'  => __( 'A site for an architecture firm specializing in sustainable residential design.', 'inspiro-starter-sites' ),
					),
					array(
						'icon'  => 'fitness',
						'title' => __( 'Fitness coach', 'inspiro-starter-sites' ),
						'text'  => __( 'A personal site for a fitness coach offering online training programs.', 'inspiro-starter-sites' ),
					),
					array(
						'icon'  => 'travel',
						'title' => __( 'Travel agency', 'inspiro-starter-sites' ),
						'text'  => __( 'A website for a boutique travel agency organizing hiking tours in the Alps.', 'inspiro-starter-sites' ),
					),
				),
				'texts'      => array(
					'title'            => __( 'Generate a demo with AI', 'inspiro-starter-sites' ),
					'beta'             => __( 'Beta', 'inspiro-starter-sites' ),
					'intro'            => __( 'Describe the website you need and AI will design and build a few pages for you in about a minute.', 'inspiro-starter-sites' ),
					'placeholder'      => __( 'e.g. A website for a small coffee roastery in Portland that sells beans online and hosts tasting events…', 'inspiro-starter-sites' ),
					'ideas_show'       => __( 'Need inspiration? View ideas', 'inspiro-starter-sites' ),
					'ideas_hide'       => __( 'Hide ideas', 'inspiro-starter-sites' ),
					'style_label'      => __( 'Design style', 'inspiro-starter-sites' ),
					'palette_label'    => __( 'Color palette', 'inspiro-starter-sites' ),
					'typography_label' => __( 'Typography', 'inspiro-starter-sites' ),
					'auto'             => __( 'Let AI decide', 'inspiro-starter-sites' ),
					'describe_label'   => __( 'Describe your website', 'inspiro-starter-sites' ),
					'step1'            => __( 'Describe & style', 'inspiro-starter-sites' ),
					'step1_hint'       => __( 'Tell the AI about the website and pick a look', 'inspiro-starter-sites' ),
					'step2'            => __( 'AI builds your demo', 'inspiro-starter-sites' ),
					'step2_hint'       => __( 'Design, copy, photos and pages — about a minute per page', 'inspiro-starter-sites' ),
					'step3'            => __( 'Review & edit', 'inspiro-starter-sites' ),
					'step3_hint'       => __( 'Open your new site or fine-tune pages in the editor', 'inspiro-starter-sites' ),
					'plan_item'        => __( 'Site design, copy & structure', 'inspiro-starter-sites' ),
					'portfolio_item'   => __( 'Portfolio plugin & sample projects', 'inspiro-starter-sites' ),
					'finalize_item'    => __( 'Menu, footer & homepage setup', 'inspiro-starter-sites' ),
					'continue'         => __( 'Continue', 'inspiro-starter-sites' ),
					'enhance'          => __( 'Enhance with AI', 'inspiro-starter-sites' ),
					'enhancing'        => __( 'Enhancing…', 'inspiro-starter-sites' ),
					'undo'             => __( 'Undo', 'inspiro-starter-sites' ),
					'suggesting'       => __( 'Suggesting pages…', 'inspiro-starter-sites' ),
					'plan_title'       => __( 'Review your pages', 'inspiro-starter-sites' ),
					'plan_hint'        => __( 'Suggested for your website. Rename, remove, or add your own (up to 5) — nothing is generated until you continue.', 'inspiro-starter-sites' ),
					'plan_add_ph'      => __( 'Add a page, e.g. Pricing…', 'inspiro-starter-sites' ),
					'plan_add'         => __( 'Add', 'inspiro-starter-sites' ),
					'plan_remove'      => __( 'Remove page', 'inspiro-starter-sites' ),
					'plan_min'         => __( 'Keep at least one page.', 'inspiro-starter-sites' ),
					/* translators: %s: maximum number of pages */
					'plan_max'         => __( 'Maximum %s pages.', 'inspiro-starter-sites' ),
					'build_pages'      => __( 'Build these pages', 'inspiro-starter-sites' ),
					'generate'         => __( 'Generate demo', 'inspiro-starter-sites' ),
					'generating'       => __( 'Generating…', 'inspiro-starter-sites' ),
					'quota_left'       => /* translators: %1$s: used, %2$s: limit */ __( '%1$s of %2$s free generations used', 'inspiro-starter-sites' ),
					'quota_none'       => __( 'You have used all your free AI generations for this site.', 'inspiro-starter-sites' ),
					'quota_loading'    => __( 'Checking available generations…', 'inspiro-starter-sites' ),
					'too_short'        => __( 'Please describe your website in a bit more detail (at least a short sentence).', 'inspiro-starter-sites' ),
					'replace_title'    => __( 'You already have an AI-generated demo', 'inspiro-starter-sites' ),
					/* translators: %1$s: previous demo name, %2$s: number of pages */
					'replace_notice'   => __( 'Generating a new demo will permanently delete the %2$s page(s) from “%1$s” — including any changes you made to them.', 'inspiro-starter-sites' ),
					/* translators: %s: number of pages */
					'replace_notice_unnamed' => __( 'Generating a new demo will permanently delete the %s previously generated AI page(s) — including any changes you made to them.', 'inspiro-starter-sites' ),
					'replace_checkbox' => __( 'Delete the previous AI demo when generating the new one', 'inspiro-starter-sites' ),
					'replace_keep_hint'=> __( 'Uncheck to keep the old pages — they will remain published alongside the new demo.', 'inspiro-starter-sites' ),
					'delete_now'       => __( 'Delete the AI demo now (without generating a new one)', 'inspiro-starter-sites' ),
					'delete_confirm'   => __( 'Permanently delete all AI-generated pages, their images, the demo menu and footer widgets? Content that existed before the AI demo is not affected. This cannot be undone.', 'inspiro-starter-sites' ),
					'deleting'         => __( 'Deleting…', 'inspiro-starter-sites' ),
					'step_plan'        => __( 'Designing your site structure and writing the copy…', 'inspiro-starter-sites' ),
					/* translators: %1$s: current page number, %2$s: total pages, %3$s: page title */
					'step_page'        => __( 'Creating page %1$s of %2$s: %3$s', 'inspiro-starter-sites' ),
					'step_finalize'    => __( 'Setting up navigation and homepage…', 'inspiro-starter-sites' ),
					'progress_hint'    => __( 'This usually takes about a minute. Please keep this tab open.', 'inspiro-starter-sites' ),
					'success_title'    => __( 'Your demo is ready!', 'inspiro-starter-sites' ),
					'success_text'     => __( 'The AI created the following pages, set up the menu, and assigned your new homepage.', 'inspiro-starter-sites' ),
					'view_site'        => __( 'View site', 'inspiro-starter-sites' ),
					'edit_pages'       => __( 'Edit pages', 'inspiro-starter-sites' ),
					'error_title'      => __( 'Something went wrong', 'inspiro-starter-sites' ),
					'error_generic'    => __( 'The AI service could not complete the request. Please try again in a moment.', 'inspiro-starter-sites' ),
					'try_again'        => __( 'Try again', 'inspiro-starter-sites' ),
					'close'            => __( 'Close', 'inspiro-starter-sites' ),
					'page_failed'      => __( 'One of the pages could not be created, continuing with the rest…', 'inspiro-starter-sites' ),
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
	 * AJAX: delete all AI-generated demo content
	 * ------------------------------------------------------------------ */

	public function ajax_delete() {
		Helpers::verify_ajax_call();

		$counts = $this->delete_ai_demos( '' );

		flush_rewrite_rules();

		wp_send_json_success(
			array(
				'pages'       => $counts['pages'],
				'attachments' => $counts['attachments'],
				'message'     => sprintf(
					/* translators: %1$d: pages deleted, %2$d: media files deleted */
					esc_html__( 'AI demo removed: %1$d page(s) and %2$d media file(s) deleted, menu and footer widgets cleaned up.', 'inspiro-starter-sites' ),
					$counts['pages'],
					$counts['attachments']
				),
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

		$style_options   = $this->style_options();
		$palette_options = $this->palette_options();

		$typography_options = $this->typography_options();

		$style      = isset( $_POST['style'] ) ? sanitize_key( wp_unslash( $_POST['style'] ) ) : '';
		$palette    = isset( $_POST['palette'] ) ? sanitize_key( wp_unslash( $_POST['palette'] ) ) : '';
		$typography = isset( $_POST['typography'] ) ? sanitize_key( wp_unslash( $_POST['typography'] ) ) : '';

		$style      = isset( $style_options[ $style ] ) ? $style : '';
		$palette    = isset( $palette_options[ $palette ] ) ? $palette : '';
		$typography = isset( $typography_options[ $typography ] ) ? $typography : '';

		// Pages the user approved in the review step (before this call).
		$approved_raw   = isset( $_POST['pages'] ) ? json_decode( wp_unslash( $_POST['pages'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitized
		$approved_pages = $this->sanitize_review_pages( $approved_raw );

		$plan = $this->proxy->claude_task_json(
			'demo-plan',
			array(
				'description'    => $description,
				'style'          => $style,
				'typography'     => $typography,
				'palette_colors' => $palette ? $palette_options[ $palette ]['colors'] : array(),
				'use_theme_var'  => $palette && ! empty( $palette_options[ $palette ]['theme_var'] ),
				'pages'          => $approved_pages,
				'font_families'  => array_keys( $this->font_whitelist() ),
			),
			array( $stream, 'tick' )
		);

		if ( ! is_wp_error( $plan ) ) {
			$plan = $this->sanitize_plan( $plan, $approved_pages );
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

		// Load the chosen Google Fonts locally (GDPR-safe) via the theme's
		// WPTT webfont loader and bake the @font-face rules into the demo
		// stylesheet. Skipped gracefully when the theme/loader is absent —
		// the AI CSS always declares fallback stacks.
		if ( function_exists( 'wptt_get_webfont_styles' ) && ! empty( $plan['fonts'] ) ) {
			$whitelist = $this->font_whitelist();
			$specs     = array();
			foreach ( array_unique( array_values( $plan['fonts'] ) ) as $family ) {
				if ( isset( $whitelist[ $family ] ) ) {
					$specs[] = 'family=' . $whitelist[ $family ];
				}
			}
			if ( $specs ) {
				$font_css = wptt_get_webfont_styles( 'https://fonts.googleapis.com/css2?' . implode( '&', $specs ) . '&display=swap' );
				$stream->tick();
				if ( is_string( $font_css ) && '' !== trim( $font_css ) && false === strpos( $font_css, '<' ) ) {
					// Kept separate from the design CSS so the per-page AI
					// calls don't waste tokens re-reading @font-face rules.
					$plan['font_css'] = $font_css;
				}
			}
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
				'portfolio'  => array(
					'needed'        => ! empty( $plan['portfolio']['needed'] ) && ! empty( $plan['portfolio']['items'] ),
					'plugin_active' => post_type_exists( 'portfolio_item' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * AJAX: enhance a short description into a vivid brief
	 * ------------------------------------------------------------------ */

	/**
	 * A small, fast Claude call that expands a thin description ("website
	 * for video portfolio") into a rich demo brief with a fictional name,
	 * location, style and specifics. Free (no quota) and optional — the
	 * result just replaces the textarea content, still fully editable.
	 */
	public function ajax_enhance_prompt() {
		Helpers::verify_ajax_call();

		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$description = trim( mb_substr( $description, 0, 1200 ) );

		if ( mb_strlen( $description ) < 5 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Type a few words about the website first.', 'inspiro-starter-sites' ) ) );
		}

		$text = $this->proxy->claude_task( 'demo-enhance', array( 'description' => $description ) );

		if ( is_wp_error( $text ) ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Could not enhance the description right now.', 'inspiro-starter-sites' ),
					'detail'  => $text->get_error_code(),
				)
			);
		}

		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
		$text = trim( $text, "\"\u{201C}\u{201D} " );
		$text = mb_substr( $text, 0, 1200 );

		if ( mb_strlen( $text ) < 20 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Could not enhance the description right now.', 'inspiro-starter-sites' ) ) );
		}

		wp_send_json_success( array( 'description' => $text ) );
	}

	/* ---------------------------------------------------------------------
	 * AJAX: quick page suggestion (before the expensive generation)
	 * ------------------------------------------------------------------ */

	/**
	 * A small, fast Claude call proposing 1-5 pages for the description —
	 * shown to the user for review BEFORE the expensive plan+CSS generation.
	 * Stateless and free (no quota consumed).
	 */
	public function ajax_suggest_pages() {
		Helpers::verify_ajax_call();

		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$description = trim( mb_substr( $description, 0, 1200 ) );

		if ( mb_strlen( $description ) < 12 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please describe your website in a bit more detail.', 'inspiro-starter-sites' ) ) );
		}

		$result = $this->proxy->claude_task_json(
			'demo-pages',
			array(
				'description' => $description,
				'max_pages'   => self::MAX_REVIEW_PAGES,
			)
		);

		if ( is_wp_error( $result ) || empty( $result['pages'] ) || ! is_array( $result['pages'] ) ) {
			$detail = is_wp_error( $result ) ? $result->get_error_message() : 'empty';
			wp_send_json_error(
				array(
					'code'    => 'suggest_failed',
					'message' => esc_html__( 'Could not suggest pages right now.', 'inspiro-starter-sites' ),
					'detail'  => $detail,
				)
			);
		}

		$pages = $this->sanitize_review_pages( $result['pages'] );

		if ( ! $pages ) {
			wp_send_json_error( array( 'code' => 'suggest_failed', 'message' => esc_html__( 'Could not suggest pages right now.', 'inspiro-starter-sites' ) ) );
		}

		wp_send_json_success( array( 'pages' => $pages ) );
	}

	/**
	 * Sanitize a user/AI-provided page list into unique, capped page entries.
	 *
	 * @param array $raw    Entries: [ 'slug' => ?, 'title' => ..., 'brief' => ? ].
	 * @return array[] Clean pages (slug, title, brief).
	 */
	private function sanitize_review_pages( $raw ) {
		$clean      = array();
		$used_slugs = array();

		if ( ! is_array( $raw ) ) {
			return $clean;
		}

		foreach ( array_slice( $raw, 0, self::MAX_REVIEW_PAGES ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$title = mb_substr( sanitize_text_field( (string) ( isset( $entry['title'] ) ? $entry['title'] : '' ) ), 0, 120 );
			$slug  = sanitize_title( (string) ( isset( $entry['slug'] ) ? $entry['slug'] : '' ) );
			$brief = mb_substr( sanitize_text_field( (string) ( isset( $entry['brief'] ) ? $entry['brief'] : '' ) ), 0, 800 );

			if ( '' === $title ) {
				continue;
			}
			if ( '' === $slug ) {
				$slug = sanitize_title( $title );
			}
			if ( '' === $slug ) {
				continue;
			}

			$base = $slug;
			$n    = 2;
			while ( isset( $used_slugs[ $slug ] ) ) {
				$slug = $base . '-' . $n;
				$n++;
			}
			$used_slugs[ $slug ] = true;

			$clean[] = array(
				'slug'  => $slug,
				'title' => $title,
				'brief' => $brief ? $brief : sprintf( 'A "%s" page for this site — pick fitting sections and write matching copy.', $title ),
			);
		}

		return $clean;
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

		// The page build includes a Claude call (~30-60s) plus image
		// sideloads — stream keep-alive bytes throughout.
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

		// One Claude call designs this page as HTML against the shared
		// stylesheet generated in the plan step (prompt assembled server-side).
		$html = $this->proxy->claude_task(
			'demo-page',
			array(
				'description'      => $state['description'],
				'site_title'       => $state['plan']['site_title'],
				'tagline'          => $state['plan']['tagline'],
				'css'              => $state['plan']['css'],
				'page'             => $page,
				'pages'            => array_map(
					static function ( $p ) {
						return array(
							'slug'  => $p['slug'],
							'title' => $p['title'],
						);
					},
					$state['plan']['pages']
				),
				'portfolio_needed' => ! empty( $state['plan']['portfolio']['needed'] ) && ! empty( $state['plan']['portfolio']['items'] ),
			),
			array( $stream, 'tick' )
		);

		if ( is_wp_error( $html ) ) {
			$stream->finish_error(
				array(
					'message' => $html->get_error_message(),
					'detail'  => $html->get_error_code(),
				)
			);
		}

		$html = preg_replace( '/^```(?:html)?\s*|\s*```$/s', '', trim( $html ) );

		// Permalinks for internal links: pages that don't exist yet get their
		// pretty-permalink guess; ajax_finalize() rewrites collisions.
		$page_links = array();
		foreach ( $state['plan']['pages'] as $p ) {
			$page_links[ $p['slug'] ] = ( 'home' === $p['slug'] ) ? home_url( '/' ) : home_url( '/' . $p['slug'] . '/' );
		}

		// Convert the AI HTML into native blocks; <img data-query> tags are
		// resolved to sideloaded Pexels photos as they're encountered.
		$generator = $this; // For PHP 7.4 closure clarity.
		$resolver  = function ( $query, $orientation ) use ( $generator, &$state, $plan_id, $stream ) {
			$images = $generator->resolve_images( $query, 1, $state['used_photo_ids'], $state['plan']['site_title'], $plan_id, array( $stream, 'tick' ), $orientation );
			if ( ! $images ) {
				return null;
			}
			$state['used_photo_ids'][] = $images[0]['photo_id'];
			$stream->tick();
			return $images[0];
		};

		$brand     = isset( $state['plan']['brand'] ) ? $state['plan']['brand'] : array();
		$converter = new HtmlToBlocks( $page_links, $resolver, $brand );
		$content   = $converter->convert( $html, $page['slug'] );

		if ( '' === $content ) {
			$stream->finish_error( array( 'message' => esc_html__( 'The AI returned an unusable page design. Please try again.', 'inspiro-starter-sites' ) ) );
		}

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

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore
		}

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

		// Portfolio-item creation sideloads several photos — stream
		// keep-alive bytes so short server idle timeouts survive it.
		$stream = new StreamingResponse();
		$stream->begin();

		$this->create_portfolio_items( $state, $plan_id, $stream );

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

		// Footer content goes into the theme's real footer widget areas —
		// generated pages never carry their own footer.
		$footer_widget_ids = $this->populate_footer_widgets( $state['plan'] );

		// Record the generation so the content can be identified (and cleaned
		// up) later. Post meta on each page carries the same plan ID.
		$demos = get_option( self::DEMOS_OPTION, array() );
		$demos = is_array( $demos ) ? $demos : array();

		$demos[ $plan_id ] = array(
			'site_title'  => $state['plan']['site_title'],
			'description' => $state['description'],
			'css'         => trim( ( ! empty( $state['plan']['font_css'] ) ? $state['plan']['font_css'] . "\n" : '' ) . ( isset( $state['plan']['css'] ) ? $state['plan']['css'] : '' ) ),
			'pages'       => array_values( $created_pages ),
			'menu_id'     => $menu_id && ! is_wp_error( $menu_id ) ? (int) $menu_id : 0,
			'widgets'     => $footer_widget_ids,
			'created_at'  => current_time( 'mysql' ),
		);

		update_option( self::DEMOS_OPTION, $demos, false );

		delete_transient( self::PLAN_TRANSIENT_PREFIX . $plan_id );

		// Fresh permalinks for the new pages.
		flush_rewrite_rules();

		$stream->finish_success(
			array(
				'view_url'  => home_url( '/' ),
				'pages_url' => admin_url( 'edit.php?post_type=page' ),
			)
		);
	}

	/**
	 * Create the plan's portfolio items (WPZOOM Portfolio plugin CPT) with
	 * sideloaded featured images. No-op when the plan doesn't need a
	 * portfolio or the plugin isn't active.
	 *
	 * @param array             $state   Plan state (used_photo_ids updated).
	 * @param string            $plan_id Plan ID (cleanup meta tag).
	 * @param StreamingResponse $stream  Keep-alive.
	 */
	private function create_portfolio_items( array &$state, $plan_id, StreamingResponse $stream ) {
		$portfolio = isset( $state['plan']['portfolio'] ) ? $state['plan']['portfolio'] : array();

		if ( empty( $portfolio['needed'] ) || empty( $portfolio['items'] ) || ! post_type_exists( 'portfolio_item' ) ) {
			return;
		}

		foreach ( $portfolio['items'] as $item ) {
			$post_id = wp_insert_post(
				wp_slash(
					array(
						'post_type'   => 'portfolio_item',
						'post_status' => 'publish',
						'post_title'  => $item['title'],
						'meta_input'  => array(
							self::GENERATED_META_KEY => $plan_id,
						),
					)
				)
			);

			if ( is_wp_error( $post_id ) || ! $post_id ) {
				continue;
			}

			if ( ! empty( $item['image_query'] ) ) {
				$images = $this->resolve_images( $item['image_query'], 1, $state['used_photo_ids'], $state['plan']['site_title'], $plan_id, array( $stream, 'tick' ) );
				if ( $images ) {
					set_post_thumbnail( $post_id, $images[0]['id'] );
					$state['used_photo_ids'][] = $images[0]['photo_id'];
				}
			}

			$stream->tick();
		}

		set_transient( self::PLAN_TRANSIENT_PREFIX . $plan_id, $state, HOUR_IN_SECONDS );
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Fill the theme's footer widget areas (footer_1 / footer_2) with two
	 * block widgets built from the plan's footer content: an about blurb and
	 * the contact details. Returns the created widget IDs for cleanup.
	 *
	 * @param array $plan Sanitized plan.
	 * @return string[] Widget IDs (e.g. [ 'block-7', 'block-8' ]).
	 */
	private function populate_footer_widgets( array $plan ) {
		$footer = isset( $plan['footer'] ) && is_array( $plan['footer'] ) ? $plan['footer'] : array();

		$widgets = array();

		if ( ! empty( $footer['about'] ) ) {
			$widgets['footer_1'] = sprintf(
				"<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">%s</h3>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->",
				esc_html( $plan['site_title'] ),
				esc_html( $footer['about'] )
			);
		}

		$contact_lines = array();
		foreach ( array( 'email', 'phone', 'address' ) as $key ) {
			if ( ! empty( $footer[ $key ] ) ) {
				$contact_lines[] = esc_html( $footer[ $key ] );
			}
		}
		if ( $contact_lines ) {
			$heading = ! empty( $footer['contact_heading'] ) ? $footer['contact_heading'] : __( 'Contact', 'inspiro-starter-sites' );

			$widgets['footer_2'] = sprintf(
				"<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">%s</h3>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->",
				esc_html( $heading ),
				implode( '<br>', $contact_lines )
			);
		}

		if ( ! $widgets ) {
			return array();
		}

		$instances = get_option( 'widget_block', array() );
		$instances = is_array( $instances ) ? $instances : array();

		$next = 2;
		foreach ( array_keys( $instances ) as $key ) {
			if ( is_numeric( $key ) && (int) $key >= $next ) {
				$next = (int) $key + 1;
			}
		}

		$sidebars = wp_get_sidebars_widgets();
		$created  = array();

		foreach ( $widgets as $sidebar_id => $content ) {
			$instances[ $next ] = array( 'content' => $content );
			$widget_id          = 'block-' . $next;
			$created[]          = $widget_id;

			// The AI demo owns these footer areas — replace their contents.
			$sidebars[ $sidebar_id ] = array( $widget_id );
			$next++;
		}

		$instances['_multiwidget'] = 1;
		update_option( 'widget_block', $instances );
		wp_set_sidebars_widgets( $sidebars );

		return $created;
	}

	/**
	 * Permanently delete previously AI-generated content (any plan except
	 * $keep_plan_id — pass '' to delete everything).
	 *
	 * Strictly scoped: only posts (pages/attachments) carrying the
	 * generated-demo meta key, only widget IDs recorded by a generation, and
	 * — on a full wipe — only the recorded demo menus and the front-page
	 * assignment when it points at a deleted page. Pre-existing content is
	 * never touched.
	 *
	 * @param string $keep_plan_id Plan ID of a generation in progress, or ''.
	 * @return array [ 'pages' => int, 'attachments' => int ]
	 */
	private function delete_ai_demos( $keep_plan_id = '' ) {
		$meta_query = array(
			array(
				'key'     => self::GENERATED_META_KEY,
				'compare' => 'EXISTS',
			),
		);
		if ( '' !== $keep_plan_id ) {
			$meta_query = array(
				array(
					'key'     => self::GENERATED_META_KEY,
					'value'   => $keep_plan_id,
					'compare' => '!=',
				),
			);
		}

		$post_ids = get_posts(
			array(
				'post_type'   => array( 'page', 'attachment', 'portfolio_item' ),
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		$counts        = array(
			'pages'       => 0,
			'attachments' => 0,
		);
		$front_page_id = (int) get_option( 'page_on_front' );
		$front_deleted = false;

		foreach ( $post_ids as $post_id ) {
			$type = get_post_type( $post_id );
			if ( (int) $post_id === $front_page_id ) {
				$front_deleted = true;
			}
			if ( wp_delete_post( $post_id, true ) ) {
				$counts[ 'attachment' === $type ? 'attachments' : 'pages' ]++;
			}
		}

		// Collect record data before dropping the records.
		$demos             = get_option( self::DEMOS_OPTION, array() );
		$demos             = is_array( $demos ) ? $demos : array();
		$remove_widget_ids = array();
		$remove_menu_ids   = array();
		$remove_plan_ids   = array();

		foreach ( $demos as $record_plan_id => $record ) {
			if ( $record_plan_id === $keep_plan_id ) {
				continue;
			}
			$remove_plan_ids[] = $record_plan_id;
			if ( ! empty( $record['widgets'] ) && is_array( $record['widgets'] ) ) {
				$remove_widget_ids = array_merge( $remove_widget_ids, $record['widgets'] );
			}
			if ( ! empty( $record['menu_id'] ) ) {
				$remove_menu_ids[] = (int) $record['menu_id'];
			}
		}

		// Footer widgets created by the deleted demos.
		if ( $remove_widget_ids ) {
			$instances = get_option( 'widget_block', array() );
			$instances = is_array( $instances ) ? $instances : array();
			foreach ( $remove_widget_ids as $widget_id ) {
				$number = (int) str_replace( 'block-', '', $widget_id );
				unset( $instances[ $number ] );
			}
			update_option( 'widget_block', $instances );

			$sidebars = wp_get_sidebars_widgets();
			foreach ( $sidebars as $sidebar_id => $widget_ids ) {
				if ( is_array( $widget_ids ) ) {
					$sidebars[ $sidebar_id ] = array_values( array_diff( $widget_ids, $remove_widget_ids ) );
				}
			}
			wp_set_sidebars_widgets( $sidebars );
		}

		// Leftover mid-generation state.
		foreach ( $remove_plan_ids as $plan ) {
			delete_transient( self::PLAN_TRANSIENT_PREFIX . $plan );
		}

		$demos = array_intersect_key( $demos, array( $keep_plan_id => true ) );
		update_option( self::DEMOS_OPTION, $demos, false );

		// Full wipe: also remove the demo menus and fix dangling settings.
		// (On a replace, ajax_finalize() reassigns the menu and front page.)
		if ( '' === $keep_plan_id ) {
			$fallback_menu = wp_get_nav_menu_object( esc_html__( 'AI Demo Menu', 'inspiro-starter-sites' ) );
			if ( $fallback_menu ) {
				$remove_menu_ids[] = (int) $fallback_menu->term_id;
			}
			foreach ( array_unique( $remove_menu_ids ) as $menu_id ) {
				if ( wp_get_nav_menu_object( $menu_id ) ) {
					wp_delete_nav_menu( $menu_id );
				}
			}

			// Unset menu locations that now point at deleted menus.
			$locations = get_theme_mod( 'nav_menu_locations', array() );
			if ( is_array( $locations ) ) {
				$changed = false;
				foreach ( $locations as $location => $menu_id ) {
					if ( $menu_id && ! wp_get_nav_menu_object( $menu_id ) ) {
						unset( $locations[ $location ] );
						$changed = true;
					}
				}
				if ( $changed ) {
					set_theme_mod( 'nav_menu_locations', $locations );
				}
			}

			// Front page pointed at a deleted AI page — back to latest posts.
			if ( $front_deleted ) {
				update_option( 'show_on_front', 'posts' );
				update_option( 'page_on_front', 0 );
			}
		}

		return $counts;
	}

	/**
	 * Back-compat wrapper used by the replace flow.
	 *
	 * @param string $keep_plan_id Plan ID of the generation in progress.
	 */
	private function delete_previous_ai_demos( $keep_plan_id ) {
		$this->delete_ai_demos( $keep_plan_id );
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
	 * Search Pexels once and sideload up to $count unused photos.
	 *
	 * @param string        $query      English search phrase.
	 * @param int           $count      Photos needed.
	 * @param array         $used_ids   Pexels photo IDs already used in this plan.
	 * @param string        $site_title Used for the attachment description.
	 * @param string        $plan_id     Plan ID stamped on attachments for cleanup.
	 * @param callable|null $heartbeat   Keep-alive tick between downloads.
	 * @param string        $orientation landscape|portrait|square.
	 * @return array[] Zero or more [ 'id' => attachment ID, 'url' => URL, 'photo_id' => pexels ID ]
	 */
	private function resolve_images( $query, $count, array $used_ids, $site_title, $plan_id = '', $heartbeat = null, $orientation = 'landscape' ) {
		$count  = max( 1, (int) $count );
		$photos = $this->proxy->pexels_photos( $query, $count + 4, $orientation );
		$images = array();

		foreach ( $photos as $photo ) {
			if ( count( $images ) >= $count ) {
				break;
			}
			if ( in_array( $photo['id'], $used_ids, true ) ) {
				continue;
			}

			$image = $this->sideload_photo( $photo, $query, $site_title, $plan_id );
			if ( $image ) {
				$images[]   = $image;
				$used_ids[] = $image['photo_id'];
			}
			if ( $heartbeat ) {
				call_user_func( $heartbeat );
			}
		}

		return $images;
	}

	/**
	 * Sideload one Pexels photo into the media library.
	 *
	 * @param array  $photo      [ 'id' => pexels ID, 'url' => remote URL ]
	 * @param string $query      Search phrase (for the attachment description).
	 * @param string $site_title Used for the attachment description.
	 * @param string $plan_id    Plan ID stamped on the attachment for cleanup.
	 * @return array|null [ 'id' => attachment ID, 'url' => URL, 'photo_id' => pexels ID ]
	 */
	private function sideload_photo( array $photo, $query, $site_title, $plan_id = '' ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( $photo['url'], 0, sanitize_text_field( $site_title . ' — ' . $query ), 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			return null;
		}

		$url = wp_get_attachment_image_url( $attachment_id, 'full' );
		if ( ! $url ) {
			return null;
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

	/**
	 * Validate and sanitize the AI-returned plan into a trusted structure.
	 *
	 * @param array $plan     Raw decoded plan.
	 * @param array $approved User-approved pages — when present, they are
	 *                        authoritative for slugs/titles/order; the AI's
	 *                        expanded briefs are merged in by slug.
	 * @return array|\WP_Error
	 */
	private function sanitize_plan( $plan, $approved = array() ) {
		if ( empty( $plan['pages'] ) || ! is_array( $plan['pages'] ) ) {
			return new \WP_Error( 'ai_invalid_plan', esc_html__( 'The AI returned an unusable site plan. Please try again.', 'inspiro-starter-sites' ) );
		}

		$clean_text = static function ( $value, $max = 500 ) {
			return mb_substr( sanitize_text_field( wp_strip_all_tags( (string) $value ) ), 0, $max );
		};

		$clean = array(
			'site_title' => $clean_text( isset( $plan['site_title'] ) ? $plan['site_title'] : '', 120 ),
			'tagline'    => $clean_text( isset( $plan['tagline'] ) ? $plan['tagline'] : '', 200 ),
			'css'        => $this->sanitize_css( isset( $plan['css'] ) ? $plan['css'] : '' ),
			'footer'     => array(),
			'pages'      => array(),
		);

		if ( ! empty( $plan['footer'] ) && is_array( $plan['footer'] ) ) {
			foreach ( array( 'about', 'contact_heading', 'email', 'phone', 'address' ) as $key ) {
				if ( isset( $plan['footer'][ $key ] ) ) {
					$clean['footer'][ $key ] = $clean_text( $plan['footer'][ $key ], 300 );
				}
			}
		}

		// Fonts: whitelist-validated (loaded locally via the theme's WPTT
		// webfont loader at generate time).
		$whitelist      = $this->font_whitelist();
		$raw_fonts      = ( ! empty( $plan['fonts'] ) && is_array( $plan['fonts'] ) ) ? $plan['fonts'] : array();
		$display        = isset( $raw_fonts['display'] ) ? trim( (string) $raw_fonts['display'] ) : '';
		$body           = isset( $raw_fonts['body'] ) ? trim( (string) $raw_fonts['body'] ) : '';
		$clean['fonts'] = array(
			'display' => isset( $whitelist[ $display ] ) ? $display : 'Inter Tight',
			'body'    => isset( $whitelist[ $body ] ) ? $body : 'Inter',
		);

		// Portfolio: items shown via the WPZOOM Portfolio plugin's block.
		$clean['portfolio'] = array(
			'needed' => ! empty( $plan['portfolio']['needed'] ),
			'items'  => array(),
		);
		if ( $clean['portfolio']['needed'] && ! empty( $plan['portfolio']['items'] ) && is_array( $plan['portfolio']['items'] ) ) {
			foreach ( array_slice( $plan['portfolio']['items'], 0, 8 ) as $item ) {
				if ( ! is_array( $item ) || empty( $item['title'] ) ) {
					continue;
				}
				$clean['portfolio']['items'][] = array(
					'title'       => $clean_text( $item['title'], 120 ),
					'image_query' => $clean_text( isset( $item['image_query'] ) ? $item['image_query'] : '', 80 ),
				);
			}
		}

		// Brand tokens for native buttons/accents (validated, with fallbacks).
		$raw_brand      = ( ! empty( $plan['brand'] ) && is_array( $plan['brand'] ) ) ? $plan['brand'] : array();
		$accent         = isset( $raw_brand['accent'] ) ? sanitize_hex_color( trim( (string) $raw_brand['accent'] ) ) : '';
		$accent_text    = isset( $raw_brand['accent_text'] ) ? sanitize_hex_color( trim( (string) $raw_brand['accent_text'] ) ) : '';
		$radius         = isset( $raw_brand['radius'] ) && preg_match( '/^\d{1,3}(px|rem|em|%)$/', trim( (string) $raw_brand['radius'] ) ) ? trim( (string) $raw_brand['radius'] ) : '';
		$clean['brand'] = array(
			'accent'      => $accent ? $accent : '#1d1d1f',
			'accent_text' => $accent_text ? $accent_text : '#ffffff',
			'radius'      => $radius ? $radius : '8px',
		);

		if ( '' === $clean['css'] ) {
			return new \WP_Error( 'ai_invalid_plan', esc_html__( 'The AI returned an unusable site design. Please try again.', 'inspiro-starter-sites' ) );
		}

		$seen_slugs = array();

		foreach ( array_slice( $plan['pages'], 0, self::MAX_PAGES ) as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}

			$slug  = sanitize_title( isset( $page['slug'] ) ? (string) $page['slug'] : '' );
			$title = $clean_text( isset( $page['title'] ) ? $page['title'] : '', 120 );
			$brief = $clean_text( isset( $page['brief'] ) ? $page['brief'] : '', 800 );

			if ( '' === $slug || '' === $title || isset( $seen_slugs[ $slug ] ) ) {
				continue;
			}
			$seen_slugs[ $slug ] = true;

			$clean['pages'][] = array(
				'slug'  => $slug,
				'title' => $title,
				'brief' => $brief,
			);
		}

		// User-approved pages are authoritative: keep their slugs, titles and
		// order exactly; take the AI's expanded briefs where it provided them.
		if ( $approved ) {
			$ai_briefs = array();
			foreach ( $clean['pages'] as $page ) {
				if ( '' !== $page['brief'] ) {
					$ai_briefs[ $page['slug'] ] = $page['brief'];
				}
			}

			$clean['pages'] = array();
			foreach ( $approved as $page ) {
				$clean['pages'][] = array(
					'slug'  => $page['slug'],
					'title' => $page['title'],
					'brief' => isset( $ai_briefs[ $page['slug'] ] ) ? $ai_briefs[ $page['slug'] ] : $page['brief'],
				);
			}
		}

		if ( empty( $clean['pages'] ) ) {
			return new \WP_Error( 'ai_invalid_plan', esc_html__( 'The AI returned an unusable site plan. Please try again.', 'inspiro-starter-sites' ) );
		}

		if ( '' === $clean['site_title'] ) {
			$clean['site_title'] = esc_html__( 'AI Demo', 'inspiro-starter-sites' );
		}

		return $clean;
	}

	/**
	 * Sanitize the AI stylesheet. Valid CSS never needs '<', '>' outside
	 * selectors' combinators, or backslash escapes for our use — stripping
	 * '<' and '\' neutralizes markup/escape injection while keeping the
	 * design intact ('>' combinators are preserved).
	 *
	 * @param string $css
	 * @return string
	 */
	private function sanitize_css( $css ) {
		$css = (string) $css;
		$css = str_replace( array( '<', '\\' ), '', $css );
		$css = preg_replace( '/@import[^;]*;?/i', '', $css );
		$css = preg_replace( '/url\s*\(/i', 'noop(', $css );
		$css = trim( mb_substr( $css, 0, 60000 ) );

		// Must actually be scoped to the demo wrapper.
		if ( false === strpos( $css, '.iss-ai-demo' ) ) {
			return '';
		}

		// Bridge rules appended after the AI CSS:
		// - neutralize theme styles that interfere inside the demo scope;
		// - buttons must never inherit the AI's generic link underline;
		// - a baseline vertical rhythm so missing AI rules can't leave
		//   elements glued together (AI selectors are more specific and win).
		$css .= "\n.iss-ai-demo figure{margin:0}.iss-ai-demo img{height:auto;max-width:100%}.iss-ai-demo .wp-block-image{margin:0}.iss-ai-demo .wp-block-image img{width:100%}"
			. ".iss-ai-demo .wp-block-button__link{text-decoration:none}"
			. ".iss-ai-demo .wp-block-buttons{display:flex;flex-wrap:wrap;gap:.75rem}"
			. ".iss-ai-demo h1,.iss-ai-demo h2,.iss-ai-demo h3,.iss-ai-demo h4{margin-top:0;margin-bottom:.5em}"
			. ".iss-ai-demo p{margin-top:0;margin-bottom:1em}"
			. ".iss-ai-demo p:last-child,.iss-ai-demo h2:last-child,.iss-ai-demo h3:last-child{margin-bottom:0}";

		return $css;
	}

	/**
	 * The demo stylesheet for a generated page (finalized demo or, mid-
	 * generation, the plan transient).
	 *
	 * @param int $post_id Page ID.
	 * @return string CSS or ''.
	 */
	private function get_demo_css_for_post( $post_id ) {
		$plan_id = get_post_meta( $post_id, self::GENERATED_META_KEY, true );
		if ( ! $plan_id ) {
			return '';
		}

		$demos = get_option( self::DEMOS_OPTION, array() );
		if ( is_array( $demos ) && ! empty( $demos[ $plan_id ]['css'] ) ) {
			return $demos[ $plan_id ]['css'];
		}

		$state = $this->get_plan_state( $plan_id );
		if ( ! $state || empty( $state['plan']['css'] ) ) {
			return '';
		}
		$font_css = ! empty( $state['plan']['font_css'] ) ? $state['plan']['font_css'] . "\n" : '';
		return $font_css . $state['plan']['css'];
	}

	/**
	 * Print the active AI demo stylesheet on generated pages (front end).
	 */
	public function print_demo_css() {
		if ( ! is_singular( 'page' ) ) {
			return;
		}

		$css = $this->get_demo_css_for_post( get_queried_object_id() );
		if ( '' === $css ) {
			return;
		}

		echo "\n<style id=\"inspiro-starter-sites-ai-css\">\n" . $css . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- sanitized in sanitize_css().
	}

	/**
	 * Load the demo stylesheet into the block editor canvas so generated
	 * pages preview correctly while editing. enqueue_block_assets styles are
	 * copied into the editor iframe by core.
	 */
	public function enqueue_editor_demo_css() {
		if ( ! is_admin() ) {
			return; // Front end is handled by print_demo_css().
		}

		$post = get_post();
		if ( ! $post || 'page' !== $post->post_type ) {
			return;
		}

		$css = $this->get_demo_css_for_post( $post->ID );
		if ( '' === $css ) {
			return;
		}

		wp_register_style( 'inspiro-starter-sites-ai-demo', false, array(), INSPIRO_STARTER_SITES_VERSION );
		wp_enqueue_style( 'inspiro-starter-sites-ai-demo' );
		wp_add_inline_style( 'inspiro-starter-sites-ai-demo', $css );
	}

	/**
	 * Google Fonts the AI may use, with per-family safe css2 weight specs.
	 * Curated from the WPZOOM premium starter sites' typography (Inter/DM
	 * Sans/Onest/Syne territory) — loaded locally via the theme's WPTT
	 * webfont loader, never from Google's servers.
	 *
	 * @return string[] family name => css2 family spec.
	 */
	private function font_whitelist() {
		return array(
			'Inter'            => 'Inter:wght@400;500;600;700',
			'Inter Tight'      => 'Inter+Tight:wght@400;500;600;700',
			'DM Sans'          => 'DM+Sans:wght@400;500;700',
			'Onest'            => 'Onest:wght@400;500;600;700',
			'Jost'             => 'Jost:wght@400;500;600;700',
			'Epilogue'         => 'Epilogue:wght@400;500;600;700',
			'Montserrat'       => 'Montserrat:wght@400;500;600;700',
			'Poppins'          => 'Poppins:wght@400;500;600;700',
			'Raleway'          => 'Raleway:wght@400;500;600;700',
			'Manrope'          => 'Manrope:wght@400;500;600;700',
			'Sora'             => 'Sora:wght@400;500;600;700',
			'Space Grotesk'    => 'Space+Grotesk:wght@400;500;600;700',
			'Syne'             => 'Syne:wght@500;600;700;800',
			'Archivo'          => 'Archivo:wght@400;500;600;700',
			'Instrument Serif' => 'Instrument+Serif:ital@0;1',
			'Bitter'           => 'Bitter:wght@400;500;600;700',
			'Fraunces'         => 'Fraunces:wght@400;500;600;700',
			'Playfair Display' => 'Playfair+Display:wght@400;500;600;700',
		);
	}

	/**
	 * User-selectable typography directions: slug => [ label, prompt ].
	 *
	 * @return array[]
	 */
	private function typography_options() {
		return array(
			'modern'       => array(
				'label'  => __( 'Modern Sans', 'inspiro-starter-sites' ),
			),
			'geometric'    => array(
				'label'  => __( 'Geometric', 'inspiro-starter-sites' ),
			),
			'expressive'   => array(
				'label'  => __( 'Expressive', 'inspiro-starter-sites' ),
			),
			'serif-accent' => array(
				'label'  => __( 'Serif Accent', 'inspiro-starter-sites' ),
			),
			'classic'      => array(
				'label'  => __( 'Classic Serif', 'inspiro-starter-sites' ),
			),
		);
	}

	/**
	 * User-selectable design styles: slug => [ label, prompt instruction ].
	 *
	 * @return array[]
	 */
	private function style_options() {
		return array(
			'minimal'   => array(
				'label'  => __( 'Minimal', 'inspiro-starter-sites' ),
			),
			'editorial' => array(
				'label'  => __( 'Editorial', 'inspiro-starter-sites' ),
			),
			'bold'      => array(
				'label'  => __( 'Big Type', 'inspiro-starter-sites' ),
			),
			'luxury'    => array(
				'label'  => __( 'Luxury', 'inspiro-starter-sites' ),
			),
			'corporate' => array(
				'label'  => __( 'Corporate', 'inspiro-starter-sites' ),
			),
			'playful'   => array(
				'label'  => __( 'Playful', 'inspiro-starter-sites' ),
			),
			'retro'     => array(
				'label'  => __( 'Retro', 'inspiro-starter-sites' ),
			),
			'dark'      => array(
				'label'  => __( 'Dark', 'inspiro-starter-sites' ),
			),
		);
	}

	/**
	 * User-selectable color palettes: slug => [ label, colors, theme_var ].
	 *
	 * The theme's own customizer palettes (inspiro_get_color_palettes) are
	 * listed first; the active one is bound to the theme's live CSS variable
	 * (--inspiro-primary-color) so a later customizer palette change restyles
	 * the demo automatically. AI-suggested palettes follow.
	 *
	 * @return array[]
	 */
	private function palette_options() {
		$palettes = array();

		if ( function_exists( 'inspiro_get_color_palettes' ) ) {
			$theme_palettes = inspiro_get_color_palettes();
			$active         = get_theme_mod( 'colorscheme', 'default' );

			foreach ( (array) $theme_palettes as $palette_id => $palette ) {
				if ( empty( $palette['colors'] ) || ! is_array( $palette['colors'] ) ) {
					continue;
				}

				$colors  = array_values(
					array_filter(
						array(
							isset( $palette['colors']['primary'] ) ? $palette['colors']['primary'] : '',
							isset( $palette['colors']['tertiary'] ) ? $palette['colors']['tertiary'] : '',
							isset( $palette['colors']['secondary'] ) ? $palette['colors']['secondary'] : '',
						)
					)
				);
				if ( ! $colors ) {
					continue;
				}

				$is_active = ( $palette_id === $active );
				$label     = isset( $palette['label'] ) ? $palette['label'] : $palette_id;

				$palettes[ 'theme-' . sanitize_key( $palette_id ) ] = array(
					'label'     => $is_active
						/* translators: %s: theme palette name */
						? sprintf( __( 'Theme: %s (current)', 'inspiro-starter-sites' ), $label )
						/* translators: %s: theme palette name */
						: sprintf( __( 'Theme: %s', 'inspiro-starter-sites' ), $label ),
					'colors'    => $colors,
					// Only the ACTIVE palette matches the live CSS variable.
					'theme_var' => $is_active,
				);
			}
		}

		return $palettes + array(
			'warm'   => array(
				'label'  => __( 'Warm earth', 'inspiro-starter-sites' ),
				'colors' => array( '#C4580A', '#2B1D12', '#FAF3E7' ),
			),
			'ocean'  => array(
				'label'  => __( 'Ocean', 'inspiro-starter-sites' ),
				'colors' => array( '#0E2A3A', '#1B7F79', '#F2EFE6' ),
			),
			'forest' => array(
				'label'  => __( 'Forest', 'inspiro-starter-sites' ),
				'colors' => array( '#1F3D2B', '#9CB49A', '#F4F1E8' ),
			),
			'berry'  => array(
				'label'  => __( 'Berry', 'inspiro-starter-sites' ),
				'colors' => array( '#5B2333', '#C9A227', '#F6EEEA' ),
			),
			'mono'   => array(
				'label'  => __( 'Monochrome', 'inspiro-starter-sites' ),
				'colors' => array( '#111111', '#666666', '#F5F5F5' ),
			),
			'pastel' => array(
				'label'  => __( 'Pastel', 'inspiro-starter-sites' ),
				'colors' => array( '#A3B18A', '#E8C4C4', '#FDF8F0' ),
			),
		);
	}
}
