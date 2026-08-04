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
		add_action( 'wp_head', array( $this, 'print_demo_css' ), 100 );

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
		// stylesheet generated in the plan step.
		$html = $this->proxy->claude_text(
			$this->page_system_prompt(),
			$this->page_prompt( $state, $index ),
			9000,
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

		$converter = new HtmlToBlocks( $page_links, $resolver );
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
			'css'         => isset( $state['plan']['css'] ) ? $state['plan']['css'] : '',
			'pages'       => array_values( $created_pages ),
			'menu_id'     => $menu_id && ! is_wp_error( $menu_id ) ? (int) $menu_id : 0,
			'widgets'     => $footer_widget_ids,
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

		// Remove footer widgets created by the demos being deleted, then drop
		// their stored records.
		$demos = get_option( self::DEMOS_OPTION, array() );
		if ( is_array( $demos ) && $demos ) {
			$remove_widget_ids = array();
			foreach ( $demos as $record_plan_id => $record ) {
				if ( $record_plan_id !== $keep_plan_id && ! empty( $record['widgets'] ) && is_array( $record['widgets'] ) ) {
					$remove_widget_ids = array_merge( $remove_widget_ids, $record['widgets'] );
				}
			}

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
	 * Shared design-quality directive for both prompts.
	 *
	 * @return string
	 */
	private function design_directive() {
		return 'NEVER use generic AI-generated aesthetics: overused font stacks, cliched color schemes (particularly purple gradients on white or dark backgrounds), predictable centered-hero-then-three-cards layouts, or cookie-cutter design without context-specific character. Commit to a distinctive art direction that fits the specific business: a real palette, expressive display typography, generous whitespace, and cohesive rhythm across sections.';
	}

	/**
	 * System prompt for the plan call.
	 *
	 * @return string
	 */
	private function system_prompt() {
		return 'You are an award-winning web designer and copywriter who creates WordPress demo websites. '
			. $this->design_directive()
			. ' Return only valid JSON with no markdown formatting.';
	}

	/**
	 * System prompt for the per-page HTML call.
	 *
	 * @return string
	 */
	private function page_system_prompt() {
		return 'You are an award-winning web designer building one page of a demo website. '
			. $this->design_directive()
			. ' Return only raw HTML with no markdown fences and no commentary.';
	}

	/**
	 * User prompt for the plan call.
	 *
	 * @param string $description User's site description.
	 * @return string
	 */
	private function plan_prompt( $description ) {
		return "Design a complete demo website for the following request:\n\n"
			. '"' . $description . '"' . "\n\n"
			. "Return a site plan plus the site's full stylesheet. Rules:\n"
			. "- 3 to 5 pages. The FIRST page is always the homepage with slug \"home\". Pick inner pages that fit the business (about, services, menu, portfolio, contact...).\n"
			. "- Each page gets a \"brief\": 2-4 sentences describing its sections, layout ideas and content angle. Make pages structurally DIFFERENT from each other.\n"
			. "- LANGUAGE: write ALL copy (site title, tagline, briefs, page names) in the language the request itself is WRITTEN in, unless it explicitly asks for another language. The business's location, nationality or cuisine does NOT change the language: an English request about a roastery in Porto or an Italian restaurant gets ENGLISH copy and English page names.\n"
			. "- \"css\" is the complete design system for the whole site. Requirements:\n"
			. "  * EVERY selector is scoped under .ai-demo (e.g. \".ai-demo .hero { ... }\"). Style element defaults too (.ai-demo h2, .ai-demo p...).\n"
			. "  * CSS custom properties on .ai-demo for the palette and fonts.\n"
			. "  * A distinctive art direction for THIS business: real palette, expressive display typography from widely-available system font stacks (e.g. Georgia, 'Iowan Old Style', 'Avenir Next', Futura, 'Gill Sans', Palatino, ui-serif, ui-rounded...), fluid type and section padding with clamp().\n"
			. "  * Must define: .kicker (eyebrow label), .btn (solid button) and .btn.btn-outline, a responsive grid (.grid.cols-2, .cols-3, .cols-4 via CSS grid, collapsing on mobile with @media), .card styles, and dark + light section variants (e.g. .section--dark).\n"
			. "  * Style images (border-radius etc.) and vary section backgrounds so the site has rhythm.\n"
			. "  * Gradients, shadows, borders, CSS-only transitions are welcome. NO url(), NO @import, NO external fonts, NO JavaScript, NO double-quote characters anywhere in the CSS (use single quotes).\n"
			. "  * Roughly 150-250 lines.\n"
			. "- \"footer\": short content for the theme's footer widget areas (the theme renders the site footer — pages must NOT contain their own): about = 1-2 sentence blurb about the business, contact_heading = a short localized heading like Contact, plus fictional email, phone, address.\n"
			. "- Never include a literal double-quote character (\") inside any JSON string value; in CSS use single quotes, in copy use curly quotes.\n"
			. "- Return ONLY compact JSON matching exactly this shape:\n"
			. '{"site_title":"...","tagline":"...","css":".ai-demo{...} .ai-demo .hero{...}","footer":{"about":"...","contact_heading":"...","email":"...","phone":"...","address":"..."},"pages":[{"slug":"home","title":"Home","brief":"..."}]}';
	}

	/**
	 * User prompt for a single page's HTML.
	 *
	 * @param array $state Plan state.
	 * @param int   $index Page index.
	 * @return string
	 */
	private function page_prompt( array $state, $index ) {
		$plan  = $state['plan'];
		$page  = $plan['pages'][ $index ];
		$pages = array();
		foreach ( $plan['pages'] as $p ) {
			$pages[] = $p['slug'] . ' (' . $p['title'] . ')';
		}

		return 'Build the "' . $page['title'] . '" page (slug: ' . $page['slug'] . ') of the demo site "' . $plan['site_title'] . '" — ' . $plan['tagline'] . ".\n\n"
			. 'Original request: "' . $state['description'] . "\"\n"
			. 'Site pages (for internal links): ' . implode( ', ', $pages ) . "\n"
			. 'Page brief: ' . $page['brief'] . "\n\n"
			. "The site's stylesheet is below. Build the page WITH these classes (plus semantic extra classes only if the stylesheet defines them):\n\n"
			. $plan['css'] . "\n\n"
			. "Write the page BODY as HTML in exactly this dialect:\n"
			. "- Allowed elements: <section>, <div>, <h1>-<h6>, <p>, <span>, <img>, <a>, <ul>/<ol>/<li>, <blockquote>, <details>/<summary>, <figure>/<figcaption>, <hr>, inline <strong>/<em>/<br>, and small decorative inline <svg> icons (fill='currentColor', no scripts).\n"
			. "- Structure: 4 to 7 top-level <section> elements with meaningful classes from the stylesheet. Exactly ONE <h1> on the page, in the first section.\n"
			. "- Images: NEVER use src. Write <img data-query='english photo search phrase' data-orientation='landscape' alt='...'> (or data-orientation='portrait' for people/tall crops). 2-6 images where the design calls for them; queries specific and photogenic, no brand names.\n"
			. "- Buttons: <a class='btn' href='#page:contact'>Label</a> (or class='btn btn-outline'). ALL internal links use href='#page:slug' with a slug from the page list above.\n"
			. "- Do NOT include a site header, logo, navigation menu/bar, or site footer — the WordPress theme already renders those around your content. Start with the page's first content section and end with its last content section. Never use <header>, <nav> or <footer> elements.\n"
			. "- NO <style>, NO <script>, NO style= attributes, NO src attributes, NO external resources.\n"
			. "- Copy: demo-quality and concise — headings punchy, paragraphs 1-3 short sentences, realistic fictional details. Write in the language the original request is WRITTEN in (the business's location or cuisine does not change the language).\n"
			. "- Return ONLY the HTML.";
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
		if ( false === strpos( $css, '.ai-demo' ) ) {
			return '';
		}

		return $css;
	}

	/**
	 * Print the active AI demo stylesheet on generated pages.
	 */
	public function print_demo_css() {
		if ( ! is_singular( 'page' ) ) {
			return;
		}

		$plan_id = get_post_meta( get_queried_object_id(), self::GENERATED_META_KEY, true );
		if ( ! $plan_id ) {
			return;
		}

		$css   = '';
		$demos = get_option( self::DEMOS_OPTION, array() );

		if ( is_array( $demos ) && ! empty( $demos[ $plan_id ]['css'] ) ) {
			$css = $demos[ $plan_id ]['css'];
		} else {
			// Mid-generation preview (not finalized yet) — read the transient.
			$state = $this->get_plan_state( $plan_id );
			if ( $state && ! empty( $state['plan']['css'] ) ) {
				$css = $state['plan']['css'];
			}
		}

		if ( '' === $css ) {
			return;
		}

		echo "\n<style id=\"inspiro-starter-sites-ai-css\">\n" . $css . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- sanitized in sanitize_css().
	}
}
