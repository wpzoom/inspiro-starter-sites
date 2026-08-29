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
		add_action( 'admin_footer', array( $this, 'premium_dashboard_hero' ) );
		add_action( 'wp_head', array( $this, 'print_demo_css' ), 100 );
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_editor_demo_css' ) );

		add_action( 'wp_ajax_inspiro_starter_sites_ai_quota', array( $this, 'ajax_quota' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_connect', array( $this, 'ajax_connect' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_verify', array( $this, 'ajax_verify' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_disconnect', array( $this, 'ajax_disconnect' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_enhance_prompt', array( $this, 'ajax_enhance_prompt' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_suggest_pages', array( $this, 'ajax_suggest_pages' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_generate', array( $this, 'ajax_generate' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_build_page', array( $this, 'ajax_build_page' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_finalize', array( $this, 'ajax_finalize' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_delete', array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_feedback', array( $this, 'ajax_feedback' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_get_css', array( $this, 'ajax_get_css' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_save_css', array( $this, 'ajax_save_css' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_add_page', array( $this, 'ajax_add_ai_page' ) );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_regenerate_page', array( $this, 'ajax_regenerate_ai_page' ) );

		// Mirror of the pre-import starter-content warning: when an AI demo
		// exists, warn before a CLASSIC demo import that it won't remove the
		// AI content (priority 6 = right after the starter-content notice).
		add_action( 'inspiro_starter_sites_admin_page', array( $this, 'render_import_over_ai_notice' ), 6 );
		add_action( 'wp_ajax_inspiro_starter_sites_ai_dismiss_import_notice', array( $this, 'ajax_dismiss_import_notice' ) );
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
	 * Whether the active theme is Inspiro PREMIUM specifically. The WPZOOM
	 * framework class alone is not enough — it exists in every classic WPZOOM
	 * premium theme, and the AI generator is built for Inspiro's markup and
	 * theme mods only.
	 *
	 * @return bool
	 */
	public static function is_premium_inspiro() {
		if ( ! class_exists( 'WPZOOM' ) ) {
			return false;
		}

		// Parent theme, so an Inspiro child theme still qualifies.
		$template = get_template();
		if ( 'inspiro' === $template ) {
			return true;
		}

		// Renamed theme directory: fall back to the declared theme name.
		return 'inspiro premium' === strtolower( trim( (string) wp_get_theme( $template )->get( 'Name' ) ) );
	}

	/**
	 * Whether the WPZOOM Portfolio grid block can actually be rendered.
	 *
	 * The registered block — not post_type_exists( 'portfolio_item' ) — is the
	 * real test: the Inspiro Premium theme registers the portfolio CPT and
	 * taxonomy itself, so the post type exists on every premium site whether
	 * or not the plugin is installed. Gating on the CPT made the generator
	 * report the plugin as already active, skip installing it, tell the AI to
	 * place <div data-block='portfolio'></div>, and then silently drop that
	 * grid in HtmlToBlocks — leaving a work page with an intro and a CTA and
	 * nothing between them.
	 *
	 * @return bool
	 */
	private static function portfolio_grid_available() {
		return \WP_Block_Type_Registry::get_instance()->is_registered( 'wpzoom-blocks/portfolio' );
	}

	/**
	 * Whether $hook is the premium theme's WPZOOM dashboard page — where the
	 * premium framework renders its own demo importer. The AI hero injects
	 * itself there so premium users keep the generator without any theme
	 * changes.
	 *
	 * @param string $hook Admin page hook suffix.
	 * @return bool
	 */
	private function is_premium_dashboard( $hook ) {
		return self::is_premium_inspiro() && false !== strpos( (string) $hook, 'wpzoom_license' );
	}

	/**
	 * The prompt-first AI hero (kicker, headline, prompt box, badges, status
	 * chip). Rendered inline on the plugin's importer page and injected into
	 * the premium theme's demo-importer tab.
	 */
	public function render_hero() {
		$previous = $this->previous_demo_info();
		?>
		<div class="inspiro-starter-sites-ai-hero">
			<button type="button" class="inspiro-starter-sites-ai-hero__existing js-iss-ai-hero-existing js-inspiro-starter-sites-ai-generate"<?php echo $previous ? '' : ' hidden'; ?> title="<?php esc_attr_e( 'Manage or delete your generated demo', 'inspiro-starter-sites' ); ?>">
				<svg class="inspiro-starter-sites-ai-hero__existing-gear" aria-hidden="true" width="13" height="13" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M18 12h-2.18c-.17.7-.44 1.35-.81 1.93l1.54 1.54-2.1 2.1-1.54-1.54c-.58.36-1.23.63-1.93.81V19H8v-2.18c-.7-.18-1.35-.45-1.93-.81l-1.54 1.54-2.12-2.12 1.54-1.54c-.36-.58-.63-1.23-.81-1.93H1V9.03h2.17c.16-.7.44-1.35.8-1.94L2.43 5.55l2.1-2.1 1.54 1.54c.58-.37 1.24-.64 1.93-.81V2h3v2.18c.68.17 1.32.44 1.9.8l1.56-1.53 2.12 2.12-1.54 1.54c.36.59.64 1.24.82 1.94H18V12zm-8.5 1.5c1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3 1.34 3 3 3z" fill="currentColor"/></svg>
				<?php esc_html_e( 'AI demo:', 'inspiro-starter-sites' ); ?>
				<strong class="js-iss-ai-hero-existing-title"><?php echo esc_html( $previous && '' !== $previous['site_title'] ? $previous['site_title'] : __( 'active', 'inspiro-starter-sites' ) ); ?></strong>
			</button>
			<p class="inspiro-starter-sites-ai-hero__kicker">
				<span aria-hidden="true">&#10024;</span>
				<?php esc_html_e( 'AI Demo Generator', 'inspiro-starter-sites' ); ?>
				<span class="inspiro-starter-sites-ai-hero__beta"><?php esc_html_e( 'Beta', 'inspiro-starter-sites' ); ?></span>
			</p>
			<h2 class="inspiro-starter-sites-ai-hero__title"><?php esc_html_e( 'What website do you need?', 'inspiro-starter-sites' ); ?></h2>
			<p class="inspiro-starter-sites-ai-hero__sub"><?php esc_html_e( 'Describe it — AI designs and builds a complete demo with pages, photos, menu and colors in about two minutes.', 'inspiro-starter-sites' ); ?></p>

			<div class="inspiro-starter-sites-ai-hero__prompt">
				<textarea class="inspiro-starter-sites-ai-hero__input js-iss-ai-hero-input" rows="3" maxlength="1200" placeholder="<?php esc_attr_e( 'e.g. A website for a small coffee roastery in Portland that sells beans online and hosts tasting events…', 'inspiro-starter-sites' ); ?>"></textarea>
				<div class="inspiro-starter-sites-ai-hero__actions">
					<button type="button" class="inspiro-starter-sites-ai-hero__ideas js-iss-ai-hero-ideas">
						<span aria-hidden="true">&#128161;</span> <?php esc_html_e( 'Need inspiration? View ideas', 'inspiro-starter-sites' ); ?>
					</button>
					<button type="button" class="inspiro-starter-sites-ai-hero__button js-inspiro-starter-sites-ai-generate">
						<?php esc_html_e( 'Generate demo', 'inspiro-starter-sites' ); ?> <span aria-hidden="true">&rarr;</span>
					</button>
				</div>
			</div>

			<ul class="inspiro-starter-sites-ai-hero__badges">
				<li><?php esc_html_e( 'Free generations included', 'inspiro-starter-sites' ); ?></li>
				<li><?php esc_html_e( 'Ready in about 2 minutes', 'inspiro-starter-sites' ); ?></li>
				<li><?php esc_html_e( '100% editable blocks', 'inspiro-starter-sites' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * On the premium theme's dashboard: print the hero (hidden) + the modal
	 * root, then move the hero into the top of the framework's demo-importer
	 * tab. Pure plugin-side — no premium theme changes required.
	 */
	public function premium_dashboard_hero() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! $this->is_premium_dashboard( $screen->id ) || ! self::is_enabled() ) {
			return;
		}
		?>
		<div class="iss-ai-root js-iss-ai-root" hidden></div>
		<div class="js-iss-ai-premium-hero" hidden>
			<?php $this->render_hero(); ?>
			<?php $this->render_import_over_ai_notice(); ?>
		</div>
		<script>
		jQuery( function ( $ ) {
			var $tab  = $( '.wpz-onboard_content-main-demo-importer' );
			var $hero = $( '.js-iss-ai-premium-hero' );
			if ( ! $tab.length || ! $hero.length ) {
				return;
			}
			var $header = $tab.find( '.wpz-onboard_header' ).first();
			if ( $header.length ) {
				$hero.insertAfter( $header );
			} else {
				$tab.prepend( $hero );
			}
			$hero.removeAttr( 'hidden' );
		} );
		</script>
		<?php
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
		$on_plugin_page  = wp_style_is( 'inspiro-starter-sites-importer-css', 'enqueued' );
		$on_premium_page = $this->is_premium_dashboard( $hook );

		if ( ! $on_plugin_page && ! $on_premium_page ) {
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
			// On the premium theme's dashboard the plugin importer stylesheet
			// isn't registered — the AI stylesheet is self-contained anyway.
			$on_plugin_page ? array( 'inspiro-starter-sites-importer-css' ) : array(),
			$css_ver
		);

		// Real webfont samples for the typography chips ("Ag" previews).
		// Served locally through the theme's WPTT loader when available
		// (GDPR-safe, same pipeline the generated demos use).
		$preview_fonts = 'https://fonts.googleapis.com/css2?family=Inter+Tight:wght@600&family=Poppins:wght@600&family=Syne:wght@700&family=Instrument+Serif:ital@0;1&family=Playfair+Display:wght@600&display=swap';
		if ( function_exists( 'wptt_get_webfont_styles' ) ) {
			wp_add_inline_style( 'inspiro-starter-sites-ai-generator-css', wptt_get_webfont_styles( $preview_fonts ) );
		} else {
			wp_enqueue_style( 'inspiro-starter-sites-ai-preview-fonts', $preview_fonts, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}

		// WordPress' own CSS editor (CodeMirror) for the demo stylesheet —
		// same syntax highlighting and linting as Customizer → Additional CSS.
		// Returns false when the user disabled syntax highlighting in their
		// profile, in which case the plain textarea is used as-is.
		$code_editor = wp_enqueue_code_editor( array( 'type' => 'text/css' ) );

		wp_localize_script(
			'inspiro-starter-sites-ai-generator-js',
			'inspiro_starter_sites_ai',
			array(
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'ajax_nonce'  => wp_create_nonce( 'inspiro-starter-sites-ajax-verification' ),
				'code_editor' => $code_editor ? $code_editor : null,
				'pages_url'   => admin_url( 'edit.php?post_type=page' ),
				'site_url'    => home_url( '/' ),
				'upgrade_url' => 'https://www.wpzoom.com/themes/inspiro-lite/upgrade/?utm_source=wpadmin&utm_medium=ai-demo&utm_campaign=ai-quota-upsell',
				// Premium theme without an activated license: the exhausted-
				// quota card asks to activate instead of upselling.
				'is_premium_theme' => self::is_premium_inspiro(),
				// Premium page tools also require an ACTIVE license.
				'has_license'      => '' !== AiProxyClient::premium_license(),
				// Pages of the active demo, for the regenerate picker (the
				// posts page has no AI design to rebuild).
				'demo_pages'       => $this->demo_pages_for_picker(),
				'license_url'      => admin_url( 'admin.php?page=wpzoom_license#license' ),
				'styles'     => array_map(
					static function ( $style ) {
						return $style['label'];
					},
					$this->style_options()
				),
				'design_levels' => array_map(
					static function ( $level ) {
						return array(
							'label' => $level['label'],
							'hint'  => $level['hint'],
							'pro'   => ! empty( $level['pro'] ),
						);
					},
					$this->design_level_options()
				),
				// Premium art-direction picker. Mirrors the proxy's recipe catalog
				// by slug; the display copy lives here so the grid renders without a
				// round trip.
				'art_directions' => $this->art_direction_options(),
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
						'icon'  => 'video',
						'title' => __( 'Video production studio', 'inspiro-starter-sites' ),
						'text'  => __( 'A site for a video production studio in Berlin creating brand films and commercials, with a bold showreel-first design.', 'inspiro-starter-sites' ),
					),
					array(
						'icon'  => 'film',
						'title' => __( 'Wedding videographer', 'inspiro-starter-sites' ),
						'text'  => __( 'A cinematic portfolio for a wedding videographer filming elopements across Tuscany.', 'inspiro-starter-sites' ),
					),
					array(
						'icon'  => 'pen',
						'title' => __( 'Creative agency', 'inspiro-starter-sites' ),
						'text'  => __( 'A portfolio site for a small design studio crafting brand identities for tech startups.', 'inspiro-starter-sites' ),
					),
					array(
						'icon'  => 'briefcase',
						'title' => __( 'Business consulting', 'inspiro-starter-sites' ),
						'text'  => __( 'A professional site for a consulting firm helping mid-size companies modernize their operations.', 'inspiro-starter-sites' ),
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
					'design_level_label' => __( 'How should the AI design it?', 'inspiro-starter-sites' ),
					'design_level_badge' => __( 'Premium', 'inspiro-starter-sites' ),
					'design_level_lock'  => __( 'Included with Inspiro Premium', 'inspiro-starter-sites' ),
					'art_title'          => __( 'Choose an art direction', 'inspiro-starter-sites' ),
					'art_hint'           => __( 'Choose the layout system the AI builds to — every one is a different site, not a different color.', 'inspiro-starter-sites' ),
					'art_auto'           => __( 'Let AI choose', 'inspiro-starter-sites' ),
					'art_auto_hint'      => __( 'The AI picks the direction that suits your business — and picks differently each time you generate.', 'inspiro-starter-sites' ),
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
					'forms_item'       => __( 'Contact form (WPZOOM Forms)', 'inspiro-starter-sites' ),
					'finalize_item'    => __( 'Menu, footer & homepage setup', 'inspiro-starter-sites' ),
					'continue'         => __( 'Continue', 'inspiro-starter-sites' ),
					'back'             => __( 'Back', 'inspiro-starter-sites' ),
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
					'replace_checkbox' => __( 'Delete the previous demo when generating the new one', 'inspiro-starter-sites' ),
					'replace_keep_hint'=> __( 'Uncheck to keep the old content — it will remain published alongside the new demo.', 'inspiro-starter-sites' ),
					'replace_title_classic' => __( 'You already have an imported demo', 'inspiro-starter-sites' ),
					/* translators: %s: imported demo name */
					'replace_notice_classic' => __( 'The previously imported “%s” demo was detected. Generating an AI demo will permanently delete its content (pages, posts, images, menus) — including any changes you made.', 'inspiro-starter-sites' ),
					'replace_notice_classic_unnamed' => __( 'A previously imported starter site was detected. Generating an AI demo will permanently delete its content (pages, posts, images, menus) — including any changes you made.', 'inspiro-starter-sites' ),
					'delete_now'       => __( 'Delete demo now', 'inspiro-starter-sites' ),
					'delete_confirm'   => __( 'Permanently delete all AI-generated pages, their images, the demo menu and footer widgets? Content that existed before the AI demo is not affected. This cannot be undone.', 'inspiro-starter-sites' ),
					'deleting'         => __( 'Deleting…', 'inspiro-starter-sites' ),
					// Plain "&" — the modal's JS escapes strings before insertion.
					'edit_css_link'    => __( 'View & edit CSS', 'inspiro-starter-sites' ),
					'edit_css_title'   => __( 'Demo stylesheet', 'inspiro-starter-sites' ),
					/* translators: %s: demo site title */
					'edit_css_intro'   => __( 'This stylesheet gives “%s” its design. It only applies to the AI-generated pages, so changes here never affect the rest of your site. Every rule must stay scoped to .iss-ai-demo.', 'inspiro-starter-sites' ),
					'edit_css_save'    => __( 'Save stylesheet', 'inspiro-starter-sites' ),
					'saving'           => __( 'Saving…', 'inspiro-starter-sites' ),
					'back'             => __( 'Back', 'inspiro-starter-sites' ),
					'add_page_link'    => __( 'Add AI page', 'inspiro-starter-sites' ),
					'regen_page_link'  => __( 'Regenerate a page', 'inspiro-starter-sites' ),
					'add_page_title'   => __( 'Add a page with AI', 'inspiro-starter-sites' ),
					'add_page_intro'   => __( 'The new page is designed with your demo\'s existing style and added to the menu.', 'inspiro-starter-sites' ),
					'add_page_label'   => __( 'Page title', 'inspiro-starter-sites' ),
					'add_page_ph'      => __( 'e.g. Pricing', 'inspiro-starter-sites' ),
					'add_page_details' => __( 'What should be on it? (optional)', 'inspiro-starter-sites' ),
					'add_page_go'      => __( 'Generate page', 'inspiro-starter-sites' ),
					'regen_title'      => __( 'Regenerate a page', 'inspiro-starter-sites' ),
					'regen_intro'      => __( 'A fresh take on the page — same purpose, new layout and imagery. The current design is replaced.', 'inspiro-starter-sites' ),
					'regen_label'      => __( 'Which page?', 'inspiro-starter-sites' ),
					'regen_feedback'   => __( 'What would you like different? (optional)', 'inspiro-starter-sites' ),
					'regen_go'         => __( 'Regenerate page', 'inspiro-starter-sites' ),
					'regen_mode_label' => __( 'How?', 'inspiro-starter-sites' ),
					'regen_mode_replace'      => __( 'Replace the page', 'inspiro-starter-sites' ),
					'regen_mode_replace_hint' => __( 'A fresh design replaces the current layout.', 'inspiro-starter-sites' ),
					'regen_mode_append'       => __( 'Add to the page', 'inspiro-starter-sites' ),
					'regen_mode_append_hint'  => __( 'Keep the current layout and add new sections below it.', 'inspiro-starter-sites' ),
					'append_intro'     => __( 'Your current layout stays. Describe what to add, and AI designs new sections in your demo\'s style, added at the bottom of the page.', 'inspiro-starter-sites' ),
					'append_describe'  => __( 'What should be added?', 'inspiro-starter-sites' ),
					'append_ph'        => __( 'e.g. A section with our opening hours and a map, and a short FAQ', 'inspiro-starter-sites' ),
					'append_go'        => __( 'Add to page', 'inspiro-starter-sites' ),
					'page_working'     => __( 'Designing the page — this takes about half a minute…', 'inspiro-starter-sites' ),
					/* translators: %s: page title */
					'page_done'        => __( '“%s” is ready.', 'inspiro-starter-sites' ),
					'view_page'        => __( 'View page', 'inspiro-starter-sites' ),
					'edit_page'        => __( 'Edit page', 'inspiro-starter-sites' ),
					'premium_feature'  => __( 'Included with Inspiro Premium', 'inspiro-starter-sites' ),
					'premium_upsell'   => __( 'Adding and regenerating single pages is included with Inspiro Premium — along with 50+ starter sites and more AI generations.', 'inspiro-starter-sites' ),
					'premium_cta'      => __( 'Upgrade to Inspiro Premium →', 'inspiro-starter-sites' ),
					'license_upsell'   => __( 'These AI page tools are included with an active Inspiro Premium license.', 'inspiro-starter-sites' ),
					'license_cta'      => __( 'Activate your license →', 'inspiro-starter-sites' ),
					'step_plan'        => __( 'Designing your site structure and writing the copy…', 'inspiro-starter-sites' ),
					/* translators: %1$s: current page number, %2$s: total pages, %3$s: page title */
					'step_page'        => __( 'Creating page %1$s of %2$s: %3$s', 'inspiro-starter-sites' ),
					/* translators: %1$s: pages completed, %2$s: total pages */
					'step_pages'       => __( 'Designing your pages — %1$s of %2$s ready…', 'inspiro-starter-sites' ),
					'step_finalize'    => __( 'Setting up navigation and homepage…', 'inspiro-starter-sites' ),
					'progress_hint'    => __( 'This usually takes about a minute. Please keep this tab open.', 'inspiro-starter-sites' ),
					'success_title'    => __( 'Your demo is ready!', 'inspiro-starter-sites' ),
					'success_text'     => __( 'The AI created the following pages, set up the menu, and assigned your new homepage.', 'inspiro-starter-sites' ),
					'view_site'        => __( 'View site', 'inspiro-starter-sites' ),
					'edit_pages'       => __( 'Edit pages', 'inspiro-starter-sites' ),
					'feedback_title'   => __( 'How did the AI do?', 'inspiro-starter-sites' ),
					'feedback_hint'    => __( 'A few quick answers go straight to the team building this feature.', 'inspiro-starter-sites' ),
					'feedback_rating'  => __( 'How happy are you with the result?', 'inspiro-starter-sites' ),
					/* translators: %s: number of stars */
					'feedback_star'    => __( '%s out of 5', 'inspiro-starter-sites' ),
					'feedback_keep'    => __( 'Will you keep this design?', 'inspiro-starter-sites' ),
					'feedback_kept'    => __( 'Keeping it', 'inspiro-starter-sites' ),
					'feedback_undecided' => __( 'Not sure yet', 'inspiro-starter-sites' ),
					'feedback_discarded' => __( 'Starting over', 'inspiro-starter-sites' ),
					'feedback_missing' => __( 'What was missing or not quite right?', 'inspiro-starter-sites' ),
					'feedback_missing_ph' => __( 'e.g. no pricing section, the photos did not match my business…', 'inspiro-starter-sites' ),
					'feedback_comment' => __( 'Anything else? (optional)', 'inspiro-starter-sites' ),
					'feedback_comment_ph' => __( 'Anything else you would like us to know…', 'inspiro-starter-sites' ),
					'feedback_send'    => __( 'Send feedback', 'inspiro-starter-sites' ),
					'feedback_sending' => __( 'Sending…', 'inspiro-starter-sites' ),
					'feedback_thanks'  => __( 'Thank you — this really helps us improve it.', 'inspiro-starter-sites' ),
					'feedback_skip'    => __( 'No thanks', 'inspiro-starter-sites' ),
					'feedback_close'   => __( 'Dismiss feedback', 'inspiro-starter-sites' ),
					'error_title'      => __( 'Something went wrong', 'inspiro-starter-sites' ),
					'error_generic'    => __( 'The AI service could not complete the request. Please try again in a moment.', 'inspiro-starter-sites' ),
					'try_again'        => __( 'Try again', 'inspiro-starter-sites' ),
					'close'            => __( 'Close', 'inspiro-starter-sites' ),
					'page_failed'      => __( 'One of the pages could not be created, continuing with the rest…', 'inspiro-starter-sites' ),
					'connect_title'    => __( 'Activate free AI generations', 'inspiro-starter-sites' ),
					'connect_text'     => __( 'Enter your email to connect this site to the WPZOOM AI service and unlock your free demo generations.', 'inspiro-starter-sites' ),
					'connect_email_ph' => __( 'you@example.com', 'inspiro-starter-sites' ),
					'connect_consent'  => __( 'Send me occasional WPZOOM news, tips and special offers', 'inspiro-starter-sites' ),
					'connect_privacy'  => __( 'Privacy Policy', 'inspiro-starter-sites' ),
					'connect_button'   => __( 'Connect & start', 'inspiro-starter-sites' ),
					'connecting'       => __( 'Connecting…', 'inspiro-starter-sites' ),
					'connect_invalid'  => __( 'Please enter a valid email address.', 'inspiro-starter-sites' ),
					/* translators: %s: connected email address */
					'connected_as'     => __( 'Connected as %s', 'inspiro-starter-sites' ),
					'verify_title'     => __( 'Check your inbox', 'inspiro-starter-sites' ),
					/* translators: %s: email address the code was sent to */
					'verify_text'      => __( 'We sent a 6-digit code to %s. Enter it below to activate your free generations.', 'inspiro-starter-sites' ),
					'verify_code_ph'   => __( '6-digit code', 'inspiro-starter-sites' ),
					'verify_button'    => __( 'Verify & start', 'inspiro-starter-sites' ),
					'verifying'        => __( 'Verifying…', 'inspiro-starter-sites' ),
					'verify_invalid'   => __( 'Please enter the 6-digit code from the email.', 'inspiro-starter-sites' ),
					'resend_code'      => __( 'Resend code', 'inspiro-starter-sites' ),
					'code_sent'        => __( 'A new code is on its way.', 'inspiro-starter-sites' ),
					'change_email'     => __( 'Use a different email', 'inspiro-starter-sites' ),
					'disconnect'       => __( 'Disconnect', 'inspiro-starter-sites' ),
					'disconnect_confirm' => __( 'Disconnect this site from the WPZOOM AI service? Your free generations stay linked to your email, so you can reconnect anytime.', 'inspiro-starter-sites' ),
					'demo_active'      => __( 'active', 'inspiro-starter-sites' ),
					'upsell_text'      => __( 'Want to generate more? Inspiro Premium includes extra AI generations — plus all premium features, starter sites and priority support.', 'inspiro-starter-sites' ),
					'upsell_button'    => __( 'Upgrade to Inspiro Premium', 'inspiro-starter-sites' ),
					'activate_text'    => __( 'Activate your Inspiro Premium license to unlock extra AI generations.', 'inspiro-starter-sites' ),
					'activate_button'  => __( 'Activate your license', 'inspiro-starter-sites' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * AJAX: quota check
	 * ------------------------------------------------------------------ */

	public function ajax_quota() {
		Helpers::verify_ajax_call();

		// Free generations require an email registration with the WPZOOM AI
		// server first — without one, tell the UI to show the connect step
		// instead of quota numbers.
		if ( ! $this->proxy->is_connected() ) {
			wp_send_json_success( array_merge( $this->quota_payload( null ), array( 'previous' => $this->previous_demo_info(), 'classic' => $this->classic_demo_info(), 'demo_pages' => $this->demo_pages_for_picker() ) ) );
		}

		$quota = $this->proxy->quota( 'check' );

		if ( is_wp_error( $quota ) ) {
			if ( 'ai_registration_required' === $quota->get_error_code() ) {
				// The server no longer recognizes our key (e.g. wiped data) —
				// forget it so the user can re-connect.
				$this->proxy->disconnect();
				wp_send_json_success( array_merge( $this->quota_payload( null ), array( 'previous' => $this->previous_demo_info(), 'classic' => $this->classic_demo_info(), 'demo_pages' => $this->demo_pages_for_picker() ) ) );
			}
			wp_send_json_error( array( 'message' => $quota->get_error_message() ) );
		}

		wp_send_json_success( array_merge( $this->quota_payload( $quota ), array( 'previous' => $this->previous_demo_info(), 'classic' => $this->classic_demo_info(), 'demo_pages' => $this->demo_pages_for_picker() ) ) );
	}

	/**
	 * Shared quota/connection response shape for ajax_quota + ajax_connect.
	 *
	 * @param array|null $quota Server quota data, or null when not connected.
	 * @return array
	 */
	private function quota_payload( $quota ) {
		return array(
			'connected' => null !== $quota,
			'email'     => $this->proxy->connected_email(),
			'used'      => isset( $quota['used'] ) ? (int) $quota['used'] : 0,
			'limit'     => isset( $quota['limit'] ) ? (int) $quota['limit'] : 0,
			'remaining' => isset( $quota['remaining'] ) ? (int) $quota['remaining'] : 0,
			'licensed'  => ! empty( $quota['licensed'] ),
		);
	}

	/**
	 * Info about a previously generated demo so the UI can warn the user
	 * that generating a new one replaces it. Public: the importer page's
	 * hero renders an "AI demo active" chip from it.
	 *
	 * @return array|null
	 */
	/**
	 * Warning shown above the classic demo grid when an AI-generated demo
	 * exists: importing a starter site will NOT remove the AI content, so
	 * offer one-click deletion first. Dismissal is remembered per AI demo —
	 * generating a new one brings the notice back.
	 */
	public function render_import_over_ai_notice() {
		$previous = $this->previous_demo_info();
		if ( ! $previous || empty( $previous['page_count'] ) ) {
			return;
		}

		$demos          = get_option( self::DEMOS_OPTION, array() );
		$latest_plan_id = is_array( $demos ) && $demos ? (string) array_key_last( $demos ) : 'unknown';

		if ( get_user_meta( get_current_user_id(), 'inspiro_ai_import_notice_dismissed', true ) === $latest_plan_id ) {
			return;
		}

		$title = '' !== $previous['site_title']
			/* translators: %s: AI demo site title */
			? sprintf( __( 'You have an AI-generated demo: “%s”', 'inspiro-starter-sites' ), $previous['site_title'] )
			: __( 'You have an AI-generated demo', 'inspiro-starter-sites' );
		?>
		<div class="notice notice-warning inspiro-ai-import-notice" style="margin: 20px 0; padding: 15px; border-left: 4px solid #ffba00;">
			<h3 style="margin-top: 0;"><?php echo esc_html( $title ); ?></h3>
			<p>
				<?php
				printf(
					/* translators: %d: number of AI-generated pages */
					esc_html__( 'Importing a starter site below will NOT remove your AI-generated demo — its %d page(s), menu and homepage setting would remain and collide with the imported demo. We recommend deleting the AI demo first.', 'inspiro-starter-sites' ),
					(int) $previous['page_count']
				);
				?>
			</p>
			<p>
				<button type="button" class="button button-primary js-inspiro-ai-import-notice-delete">
					<?php esc_html_e( 'Delete AI Demo', 'inspiro-starter-sites' ); ?>
				</button>
				<button type="button" class="button js-inspiro-ai-import-notice-keep" style="margin-left: 8px;">
					<?php esc_html_e( 'Keep It & Continue', 'inspiro-starter-sites' ); ?>
				</button>
				<span class="spinner" style="float: none; margin: 0 10px;"></span>
				<span class="js-inspiro-ai-import-notice-result"></span>
			</p>
		</div>
		<script>
		jQuery( function ( $ ) {
			var $notice = $( '.inspiro-ai-import-notice' );
			var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'inspiro-starter-sites-ajax-verification' ) ); ?>;

			$notice.on( 'click', '.js-inspiro-ai-import-notice-delete', function () {
				var $btn = $( this );
				if ( $btn.prop( 'disabled' ) ) {
					return;
				}
				$btn.prop( 'disabled', true );
				$notice.find( '.spinner' ).addClass( 'is-active' );

				$.post( ajaxurl, { action: 'inspiro_starter_sites_ai_delete', security: nonce } )
					.done( function ( res ) {
						if ( res && res.success ) {
							window.location.reload();
						} else {
							$btn.prop( 'disabled', false );
							$notice.find( '.spinner' ).removeClass( 'is-active' );
							$notice.find( '.js-inspiro-ai-import-notice-result' ).text( ( res && res.data && res.data.message ) || <?php echo wp_json_encode( __( 'Something went wrong. Please try again.', 'inspiro-starter-sites' ) ); ?> );
						}
					} )
					.fail( function () {
						$btn.prop( 'disabled', false );
						$notice.find( '.spinner' ).removeClass( 'is-active' );
						$notice.find( '.js-inspiro-ai-import-notice-result' ).text( <?php echo wp_json_encode( __( 'Something went wrong. Please try again.', 'inspiro-starter-sites' ) ); ?> );
					} );
			} );

			$notice.on( 'click', '.js-inspiro-ai-import-notice-keep', function () {
				$notice.slideUp( 150 );
				$.post( ajaxurl, { action: 'inspiro_starter_sites_ai_dismiss_import_notice', security: nonce } );
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Remember the "Keep It & Continue" dismissal for the current AI demo.
	 */
	public function ajax_dismiss_import_notice() {
		Helpers::verify_ajax_call();

		$demos          = get_option( self::DEMOS_OPTION, array() );
		$latest_plan_id = is_array( $demos ) && $demos ? (string) array_key_last( $demos ) : 'unknown';

		update_user_meta( get_current_user_id(), 'inspiro_ai_import_notice_dismissed', $latest_plan_id );
		wp_send_json_success();
	}

	/**
	 * Map the plan's display/body families onto the theme's typography
	 * options. Both Inspiro Lite and Premium use the same mod names, and both
	 * ship the same Google-font catalogue the AI picks from — so a family the
	 * AI chose is almost always selectable in the Customizer too.
	 *
	 * Families the theme doesn't know are skipped; those keep working through
	 * the @font-face rules baked into the demo stylesheet.
	 *
	 * @param array $plan Sanitized plan.
	 * @return string[] Families handed over to the theme.
	 */
	private function apply_demo_fonts( array $plan ) {
		$fonts = isset( $plan['fonts'] ) && is_array( $plan['fonts'] ) ? $plan['fonts'] : array();
		if ( ! $fonts ) {
			return array();
		}

		$display = isset( $fonts['display'] ) ? (string) $fonts['display'] : '';
		$body    = isset( $fonts['body'] ) ? (string) $fonts['body'] : '';

		$applied = array();

		if ( '' !== $display && $this->theme_knows_font( $display ) ) {
			set_theme_mod( 'headings-font-family', $display );
			$applied[] = $display;
		}

		if ( '' !== $body && $this->theme_knows_font( $body ) ) {
			set_theme_mod( 'body-font-family', $body );
			$applied[] = $body;
		}

		return array_unique( $applied );
	}

	/**
	 * Whether the active theme offers this family in its own font catalogue
	 * (and can therefore load it itself).
	 *
	 * @param string $family Font family name, e.g. "DM Sans".
	 * @return bool
	 */
	private function theme_knows_font( $family ) {
		if ( ! class_exists( 'Inspiro_Font_Family_Manager' ) ) {
			return false;
		}

		$fonts = \Inspiro_Font_Family_Manager::get_google_fonts();

		return is_array( $fonts ) && isset( $fonts[ $family ] );
	}

	/**
	 * A previously imported CLASSIC demo (starter site), from either this
	 * plugin's importer or the premium theme framework's demo importer.
	 * Premium detection goes by its tracking meta, not theme mods — theme
	 * mods are per-theme and vanish from view after a theme switch while
	 * the imported content remains.
	 *
	 * @return array|null [ 'title' => string, 'source' => string ] or null.
	 */
	public function classic_demo_info() {
		$plugin_demo = (string) get_option( 'inspiro_starter_sites_imported_demo_id', '' );
		if ( '' !== $plugin_demo ) {
			return array(
				'title'  => ucwords( str_replace( array( '-', '_' ), ' ', $plugin_demo ) ),
				'source' => 'starter-sites',
			);
		}

		$premium_posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_wpzoom_demo_importer_imported_post', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( $premium_posts ) {
			$design = (string) get_theme_mod( 'wpz_demo_imported', '' );

			return array(
				'title'  => $design ? ucwords( str_replace( array( '-', '_' ), ' ', $design ) ) : '',
				'source' => 'premium',
			);
		}

		return null;
	}

	/**
	 * Delete a previously imported classic demo — the counterpart of
	 * delete_previous_ai_demos() for starter-site imports. Runs only in the
	 * replace flow, after the user saw the explicit warning checkbox.
	 */
	private function delete_classic_demo() {
		// This plugin's importer: reuse its own full cleanup (posts, forms,
		// terms, widgets, customizer leftovers, demo marker).
		if ( get_option( 'inspiro_starter_sites_imported_demo_id' )
			&& class_exists( '\Inspiro\Starter_Sites\InspiroStarterSitesImporter' ) ) {
			$importer = \Inspiro\Starter_Sites\InspiroStarterSitesImporter::get_instance();
			if ( method_exists( $importer, 'delete_imported_demo' ) ) {
				$importer->delete_imported_demo();
			}
		}

		// Premium framework importer: delete its tracked content directly.
		$premium_posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_wpzoom_demo_importer_imported_post', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $premium_posts as $post_id ) {
			if ( 'elementor_library' === get_post_type( $post_id ) ) {
				continue;
			}
			wp_delete_post( $post_id, true );
		}

		$premium_forms = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_wpzoom_demo_importer_imported_wp_forms', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $premium_forms as $form_id ) {
			wp_delete_post( $form_id, true );
		}

		$premium_terms = get_terms(
			array(
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_wpzoom_demo_importer_imported_term',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		if ( ! is_wp_error( $premium_terms ) ) {
			foreach ( $premium_terms as $term_id ) {
				$term = get_term( $term_id );
				if ( $term && ! is_wp_error( $term ) ) {
					wp_delete_term( $term_id, $term->taxonomy );
				}
			}
		}

		if ( $premium_posts ) {
			remove_theme_mod( 'wpz_demo_imported' );
			remove_theme_mod( 'wpz_demo_imported_timestamp' );
		}
	}

	public function previous_demo_info() {
		$previous_pages = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => self::GENERATED_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		if ( ! $previous_pages ) {
			return null;
		}

		$demos = get_option( self::DEMOS_OPTION, array() );
		$last  = ( is_array( $demos ) && $demos ) ? end( $demos ) : array();

		return array(
			'site_title' => isset( $last['site_title'] ) ? $last['site_title'] : '',
			'page_count' => count( $previous_pages ),
		);
	}

	/* ---------------------------------------------------------------------
	 * AJAX: connect (email registration with the WPZOOM AI server)
	 * ------------------------------------------------------------------ */

	public function ajax_connect() {
		Helpers::verify_ajax_call();

		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$consent = ! empty( $_POST['consent'] );

		if ( '' === $email || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please enter a valid email address.', 'inspiro-starter-sites' ) ) );
		}

		$result = $this->proxy->connect( $email, $consent );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Server requires email verification: a 6-digit code was sent, the
		// UI should show the code entry.
		if ( ! empty( $result['pending'] ) ) {
			wp_send_json_success(
				array(
					'connected' => false,
					'pending'   => true,
					'email'     => $result['email'],
				)
			);
		}

		// Return the quota right away so the UI can swap the connect card for
		// the generator without a second round trip.
		$quota = $this->proxy->quota( 'check' );

		wp_send_json_success( array_merge(
			$this->quota_payload( is_wp_error( $quota ) ? array() : $quota ),
			array( 'previous' => $this->previous_demo_info(), 'classic' => $this->classic_demo_info(), 'demo_pages' => $this->demo_pages_for_picker() )
		) );
	}

	/* ---------------------------------------------------------------------
	 * AJAX: disconnect (forget the local registration)
	 * ------------------------------------------------------------------ */

	public function ajax_disconnect() {
		Helpers::verify_ajax_call();

		// Local-only: the server keeps the registration, so reconnecting with
		// the same email is instant and its quota history is preserved. A new
		// email goes through code verification like any email change.
		$this->proxy->disconnect();

		wp_send_json_success( array( 'connected' => false ) );
	}

	/* ---------------------------------------------------------------------
	 * AJAX: verify (6-digit email confirmation code)
	 * ------------------------------------------------------------------ */

	public function ajax_verify() {
		Helpers::verify_ajax_call();

		$code = isset( $_POST['code'] ) ? preg_replace( '/\D/', '', wp_unslash( $_POST['code'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 6 !== strlen( $code ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please enter the 6-digit code from the email.', 'inspiro-starter-sites' ) ) );
		}

		$result = $this->proxy->verify( $code );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$quota = $this->proxy->quota( 'check' );

		wp_send_json_success( array_merge(
			$this->quota_payload( is_wp_error( $quota ) ? array() : $quota ),
			array( 'previous' => $this->previous_demo_info(), 'classic' => $this->classic_demo_info(), 'demo_pages' => $this->demo_pages_for_picker() )
		) );
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
	 * AJAX: post-generation feedback survey
	 * ------------------------------------------------------------------ */

	/**
	 * Forward one survey submission to the WPZOOM AI server. The run's
	 * context (description, art direction, page count…) is attached here
	 * from the local demo record rather than trusted from the browser.
	 *
	 * Failures are reported as success to the UI on purpose: the user has
	 * already given us their answer, and there is nothing they can do about
	 * a proxy hiccup — an error card in place of "thank you" would only make
	 * the survey look broken.
	 */
	public function ajax_feedback() {
		Helpers::verify_ajax_call();

		$plan_id = isset( $_POST['plan_id'] ) ? sanitize_key( wp_unslash( $_POST['plan_id'] ) ) : '';
		if ( '' === $plan_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Missing generation reference.', 'inspiro-starter-sites' ) ) );
		}

		$demos = get_option( self::DEMOS_OPTION, array() );
		$demo  = ( is_array( $demos ) && isset( $demos[ $plan_id ] ) ) ? $demos[ $plan_id ] : array();

		$context = isset( $demo['context'] ) && is_array( $demo['context'] ) ? $demo['context'] : array();
		// The typed description is the single most useful column when reading
		// answers — it says what the user was actually trying to build.
		$context['industry'] = isset( $demo['description'] ) ? substr( (string) $demo['description'], 0, 500 ) : '';

		$result = $this->proxy->feedback(
			array(
				'plan_id' => $plan_id,
				'stage'   => 'post-generate',
				'rating'  => isset( $_POST['rating'] ) ? (int) $_POST['rating'] : 0,
				'kept'    => isset( $_POST['kept'] ) ? sanitize_key( wp_unslash( $_POST['kept'] ) ) : '',
				'missing' => isset( $_POST['missing'] ) ? sanitize_textarea_field( wp_unslash( $_POST['missing'] ) ) : '',
				'comment' => isset( $_POST['comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['comment'] ) ) : '',
				'context' => $context,
			)
		);

		// Remember locally that this demo was surveyed, so a later follow-up
		// prompt can skip the people who already answered.
		if ( ! is_wp_error( $result ) && isset( $demos[ $plan_id ] ) ) {
			$demos[ $plan_id ]['feedback_at'] = current_time( 'mysql' );
			update_option( self::DEMOS_OPTION, $demos, false );
		}

		wp_send_json_success( array( 'sent' => ! is_wp_error( $result ) ) );
	}

	/* ---------------------------------------------------------------------
	 * AJAX: read / write the current demo's stylesheet
	 * ------------------------------------------------------------------ */

	/**
	 * The active demo's CSS, for the "Edit CSS" modal.
	 */
	public function ajax_get_css() {
		Helpers::verify_ajax_call();

		$demo = $this->latest_demo();

		if ( ! $demo ) {
			wp_send_json_error( array( 'message' => esc_html__( 'No AI demo found on this site.', 'inspiro-starter-sites' ) ) );
		}

		$record = $this->split_font_css( $demo['plan_id'], $demo['record'] );

		wp_send_json_success(
			array(
				'plan_id'    => $demo['plan_id'],
				'site_title' => $record['site_title'],
				// Readable in the editor; stored and served minified.
				'css'        => $this->beautify_css( $record['css'] ),
			)
		);
	}

	/**
	 * Expand the stored (minified) stylesheet into a readable, indented form
	 * for the editor. Quoted strings are protected so separators inside them
	 * are never treated as syntax.
	 *
	 * @param string $css Minified CSS.
	 * @return string Pretty-printed CSS.
	 */
	private function beautify_css( $css ) {
		$css = (string) $css;
		if ( '' === trim( $css ) ) {
			return '';
		}

		list( $css, $strings ) = $this->mask_css_strings( $css );

		// One declaration/selector per line.
		$css = preg_replace( '/\s*\{\s*/', " {\n", $css );
		$css = preg_replace( '/\s*;\s*/', ";\n", $css );
		$css = preg_replace( '/\s*\}\s*/', "\n}\n", $css );
		$css = preg_replace( '/,\s*(?=[^{}]*\{)/', ",\n", $css ); // selector lists

		$out   = array();
		$depth = 0;
		foreach ( preg_split( '/\n/', $css ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( 0 === strpos( $line, '}' ) ) {
				$depth = max( 0, $depth - 1 );
			}

			// Space after the property colon — declarations only, so pseudo
			// selectors (":where(", "a:hover") keep their exact syntax.
			if ( '{' !== substr( $line, -1 ) && '}' !== $line ) {
				$line = preg_replace( '/^([^:\s]+):(?!\s)/', '$1: ', $line );
			}

			$out[] = str_repeat( '    ', $depth ) . $line;
			if ( '' !== $line && '{' === substr( $line, -1 ) ) {
				$depth++;
			}
			// Blank line after each closing brace at the top level.
			if ( '}' === $line && 0 === $depth ) {
				$out[] = '';
			}
		}

		return $this->unmask_css_strings( trim( implode( "\n", $out ) ), $strings );
	}

	/**
	 * Shrink the stylesheet back down for storage and front-end output.
	 * Deliberately conservative: whitespace around +, - and * is preserved so
	 * calc()/clamp() expressions keep working.
	 *
	 * @param string $css Pretty CSS.
	 * @return string Minified CSS.
	 */
	private function minify_css( $css ) {
		list( $css, $strings ) = $this->mask_css_strings( (string) $css );

		$css = preg_replace( '!/\*.*?\*/!s', '', $css );          // comments
		$css = preg_replace( '/\s+/', ' ', $css );                 // whitespace runs
		$css = preg_replace( '/\s*([{};:,>])\s*/', '$1', $css );   // around separators
		$css = str_replace( ';}', '}', $css );                     // trailing semicolons

		return $this->unmask_css_strings( trim( $css ), $strings );
	}

	/**
	 * Replace quoted strings with placeholders so whitespace/​separator
	 * rewriting can't corrupt their contents.
	 *
	 * @param string $css CSS.
	 * @return array [ masked CSS, extracted strings ]
	 */
	private function mask_css_strings( $css ) {
		$strings = array();

		$css = preg_replace_callback(
			'/"[^"]*"|\'[^\']*\'/',
			static function ( $m ) use ( &$strings ) {
				$strings[] = $m[0];
				return '@@ISSSTR' . ( count( $strings ) - 1 ) . '@@';
			},
			$css
		);

		return array( (string) $css, $strings );
	}

	/**
	 * @param string   $css     Masked CSS.
	 * @param string[] $strings Extracted strings.
	 * @return string
	 */
	private function unmask_css_strings( $css, array $strings ) {
		foreach ( $strings as $i => $string ) {
			$css = str_replace( '@@ISSSTR' . $i . '@@', $string, $css );
		}

		return $css;
	}

	/**
	 * Demos generated before the split kept the @font-face rules inside
	 * 'css'. Move them into 'font_css' once, so the editor only ever shows —
	 * and re-saves — the design rules. Font URLs must never pass through
	 * sanitize_css(), which rewrites url() as an SSRF guard.
	 *
	 * @param string $plan_id Demo ID.
	 * @param array  $record  Stored record.
	 * @return array Record with 'css' free of @font-face rules.
	 */
	private function split_font_css( $plan_id, array $record ) {
		$css = isset( $record['css'] ) ? (string) $record['css'] : '';

		if ( isset( $record['font_css'] ) || false === stripos( $css, '@font-face' ) ) {
			return $record;
		}

		$fonts = array();
		$design = preg_replace_callback(
			'/@font-face\s*\{[^}]*\}/i',
			static function ( $m ) use ( &$fonts ) {
				$fonts[] = $m[0];
				return '';
			},
			$css
		);

		// Comment markers left behind by the extracted blocks.
		$design = preg_replace( '/\/\*[^*]*\*\/\s*(?=\n)/', '', (string) $design );
		$design = trim( preg_replace( '/\n{3,}/', "\n\n", (string) $design ) );

		$record['font_css'] = implode( "\n", $fonts );
		$record['css']      = $design;

		$demos = get_option( self::DEMOS_OPTION, array() );
		if ( isset( $demos[ $plan_id ] ) ) {
			$demos[ $plan_id ]['font_css'] = $record['font_css'];
			$demos[ $plan_id ]['css']      = $record['css'];
			update_option( self::DEMOS_OPTION, $demos, false );
		}

		return $record;
	}

	/**
	 * Save an edited stylesheet back onto the active demo.
	 */
	public function ajax_save_css() {
		Helpers::verify_ajax_call();

		$demo = $this->latest_demo();

		if ( ! $demo ) {
			wp_send_json_error( array( 'message' => esc_html__( 'No AI demo found on this site.', 'inspiro-starter-sites' ) ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_css() handles it.
		$css = isset( $_POST['css'] ) ? wp_unslash( $_POST['css'] ) : '';

		// No bridge rules re-appended: the stored stylesheet already has them.
		// Stored minified — the editor re-expands it on the next open.
		$clean = $this->minify_css( $this->sanitize_css( $css, false ) );

		if ( '' === $clean ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'The stylesheet must contain at least one .iss-ai-demo rule, so it only affects your generated pages.', 'inspiro-starter-sites' ),
				)
			);
		}

		$demos = get_option( self::DEMOS_OPTION, array() );

		$demos[ $demo['plan_id'] ]['css'] = $clean;
		update_option( self::DEMOS_OPTION, $demos, false );

		wp_send_json_success(
			array(
				'css'     => $clean,
				'message' => esc_html__( 'Stylesheet saved.', 'inspiro-starter-sites' ),
			)
		);
	}

	/**
	 * The active demo's regenerable pages, for the picker in the modal.
	 *
	 * @return array[] [ [ 'id' => int, 'title' => string ], … ]
	 */
	private function demo_pages_for_picker() {
		$demo = $this->latest_demo();
		if ( ! $demo || empty( $demo['record']['pages'] ) ) {
			return array();
		}

		$posts_page = (int) get_option( 'page_for_posts' );
		$out        = array();
		foreach ( (array) $demo['record']['pages'] as $pid ) {
			$pid = (int) $pid;
			if ( $pid === $posts_page || 'page' !== get_post_type( $pid ) ) {
				continue;
			}
			$out[] = array(
				'id'    => $pid,
				'title' => get_the_title( $pid ),
			);
		}

		return $out;
	}

	/**
	 * The most recently generated demo record.
	 *
	 * @return array|null [ 'plan_id' => string, 'record' => array ] or null.
	 */
	private function latest_demo() {
		$demos = get_option( self::DEMOS_OPTION, array() );

		if ( ! is_array( $demos ) || ! $demos ) {
			return null;
		}

		$plan_id = (string) array_key_last( $demos );

		return array(
			'plan_id' => $plan_id,
			'record'  => $demos[ $plan_id ],
		);
	}

	/* ---------------------------------------------------------------------
	 * AJAX: add / regenerate a single page of the active demo
	 * (Inspiro Premium only — surfaced as an upsell on Lite)
	 * ------------------------------------------------------------------ */

	/**
	 * Both single-page operations are Premium perks. On Lite the buttons are
	 * shown locked; a direct request answers with the upsell code so the UI
	 * can never be scripted around meaningfully.
	 */
	private function require_premium_theme() {
		if ( ! self::is_premium_inspiro() ) {
			wp_send_json_error(
				array(
					'code'    => 'premium_required',
					'message' => esc_html__( 'Adding and regenerating single pages is included with Inspiro Premium.', 'inspiro-starter-sites' ),
				)
			);
		}

		// Premium perk = ACTIVE license, consistent with the generation
		// limits (premium_license() also requires the premium theme).
		if ( '' === AiProxyClient::premium_license() ) {
			wp_send_json_error(
				array(
					'code'    => 'license_required',
					'message' => esc_html__( 'Activate your Inspiro Premium license to add or regenerate pages.', 'inspiro-starter-sites' ),
				)
			);
		}
	}

	/**
	 * Generate ONE additional page for the active demo.
	 */
	public function ajax_add_ai_page() {
		Helpers::verify_ajax_call();
		$this->require_premium_theme();

		$demo = $this->latest_demo();
		if ( ! $demo ) {
			wp_send_json_error( array( 'message' => esc_html__( 'No AI demo found on this site.', 'inspiro-starter-sites' ) ) );
		}

		$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$details = isset( $_POST['details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['details'] ) ) : '';

		if ( '' === $title || mb_strlen( $title ) > 80 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please enter a page title (up to 80 characters).', 'inspiro-starter-sites' ) ) );
		}

		$slug = sanitize_title( $title );
		if ( '' === $slug ) {
			$slug = 'page';
		}

		$page = array(
			'title' => $title,
			'slug'  => $slug,
			'brief' => '' !== $details
				? mb_substr( $details, 0, 500 )
				/* translators: %s: page title */
				: sprintf( __( 'A "%s" page that fits this site naturally.', 'inspiro-starter-sites' ), $title ),
		);

		$this->generate_single_page( $demo['plan_id'], $demo['record'], $page, 0 );
	}

	/**
	 * Rebuild ONE existing page of the active demo, optionally steered by the
	 * user's feedback.
	 */
	public function ajax_regenerate_ai_page() {
		Helpers::verify_ajax_call();
		$this->require_premium_theme();

		$demo = $this->latest_demo();
		if ( ! $demo ) {
			wp_send_json_error( array( 'message' => esc_html__( 'No AI demo found on this site.', 'inspiro-starter-sites' ) ) );
		}

		$page_id  = isset( $_POST['page_id'] ) ? (int) $_POST['page_id'] : 0;
		$feedback = isset( $_POST['feedback'] ) ? sanitize_textarea_field( wp_unslash( $_POST['feedback'] ) ) : '';

		$post = $page_id ? get_post( $page_id ) : null;
		if ( ! $post
			|| 'page' !== $post->post_type
			|| get_post_meta( $page_id, self::GENERATED_META_KEY, true ) !== $demo['plan_id'] ) {
			wp_send_json_error( array( 'message' => esc_html__( 'That page does not belong to the current AI demo.', 'inspiro-starter-sites' ) ) );
		}

		// The blog page is a plain container for the posts page — there is no
		// designed layout to regenerate.
		if ( (int) get_option( 'page_for_posts' ) === $page_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'The Blog page displays your latest posts and has no AI design to regenerate.', 'inspiro-starter-sites' ) ) );
		}

		// 'replace' rebuilds the whole page; 'append' keeps the current layout
		// and adds new sections below it.
		$mode   = isset( $_POST['mode'] ) && 'append' === $_POST['mode'] ? 'append' : 'replace';
		$append = 'append' === $mode;

		if ( $append ) {
			// In append mode the feedback IS the brief — it says what to add.
			if ( '' === $feedback ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Describe the section(s) you want to add to this page.', 'inspiro-starter-sites' ) ) );
			}
			$brief = mb_substr( $feedback, 0, 500 );
		} else {
			$brief = sprintf(
				/* translators: %s: page title */
				__( 'Redesign the "%s" page of this site with a fresh take — same purpose, different layout and imagery.', 'inspiro-starter-sites' ),
				$post->post_title
			);
			if ( '' !== $feedback ) {
				$brief .= ' ' . sprintf(
					/* translators: %s: user feedback */
					__( 'The user asked for this revision: %s', 'inspiro-starter-sites' ),
					mb_substr( $feedback, 0, 400 )
				);
			}
		}

		$page = array(
			'title' => $post->post_title,
			'slug'  => $post->post_name,
			'brief' => $brief,
		);

		$this->generate_single_page( $demo['plan_id'], $demo['record'], $page, $page_id, $append );
	}

	/**
	 * The shared single-page pipeline: one demo-page Claude call against the
	 * stored demo context, image resolution with the demo's existing photos
	 * as the de-dup list and reuse pool, block conversion, then insert (new
	 * page + menu item) or content replace (regenerate). Streams keep-alive
	 * bytes and never returns.
	 *
	 * @param string $plan_id          Active demo ID.
	 * @param array  $record           Stored demo record.
	 * @param array  $page             [ title, slug, brief ].
	 * @param int    $existing_page_id Page to replace, 0 to add a new one.
	 * @param bool   $append           With an existing page: keep its content
	 *                                 and add the new sections below it.
	 */
	private function generate_single_page( $plan_id, array $record, array $page, $existing_page_id = 0, $append = false ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore
		}

		$record = $this->split_font_css( $plan_id, $record );

		if ( empty( $record['css'] ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'The demo stylesheet could not be found.', 'inspiro-starter-sites' ) ) );
		}

		$stream = new StreamingResponse();
		$stream->begin();

		// The site's page list: existing demo pages plus, for additions, the
		// new page — the AI uses it for internal links.
		$page_ids   = isset( $record['pages'] ) ? array_map( 'intval', (array) $record['pages'] ) : array();
		$pages_list = array();
		$page_links = array();
		foreach ( $page_ids as $pid ) {
			$p = get_post( $pid );
			if ( ! $p ) {
				continue;
			}
			$pages_list[]                  = array(
				'slug'  => $p->post_name,
				'title' => $p->post_title,
			);
			$page_links[ $p->post_name ] = ( (int) get_option( 'page_on_front' ) === $pid ) ? home_url( '/' ) : get_permalink( $pid );
		}
		if ( ! $existing_page_id ) {
			$pages_list[]                  = array(
				'slug'  => $page['slug'],
				'title' => $page['title'],
			);
			$page_links[ $page['slug'] ] = home_url( '/' . $page['slug'] . '/' );
		}

		$html = $this->proxy->claude_task(
			'demo-page',
			array(
				'description'      => isset( $record['description'] ) ? $record['description'] : '',
				'site_title'       => isset( $record['site_title'] ) ? $record['site_title'] : '',
				'tagline'          => isset( $record['tagline'] ) ? $record['tagline'] : '',
				'language'         => isset( $record['language'] ) ? $record['language'] : '',
				'css'              => $record['css'],
				'page'             => $page,
				'pages'            => $pages_list,
				'portfolio_needed' => ! empty( $record['portfolio'] ) && self::portfolio_grid_available(),
				'posts_feed'       => ! empty( $record['posts'] ),
				'has_contact_form' => post_type_exists( 'wpzf-form' ),
				// Sections-only output (no hero, no h1) that continues the page.
				'append'           => $append && $existing_page_id ? 1 : 0,
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

		// Append hygiene, independent of the prompt version: appended sections
		// continue an existing page, so a page-level <h1> becomes an <h2>
		// (there is already one h1 on the page).
		if ( $append && $existing_page_id ) {
			$html = preg_replace( '/<h1(\s[^>]*)?>/i', '<h2$1>', $html );
			$html = preg_replace( '/<\/h1>/i', '</h2>', $html );
		}

		// De-dup list + reuse pool from the demo's existing attachments (the
		// Pexels ID is stamped on each at sideload time).
		$used_photo_ids = array();
		$image_pool     = array();
		$attachments    = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::GENERATED_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $plan_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $attachments as $att_id ) {
			$photo_id = (int) get_post_meta( $att_id, '_iss_pexels_photo_id', true );
			$att_url  = wp_get_attachment_image_url( $att_id, 'full' );
			if ( $photo_id ) {
				$used_photo_ids[] = $photo_id;
			}
			if ( $att_url ) {
				$image_pool[] = array(
					'id'       => (int) $att_id,
					'url'      => $att_url,
					'photo_id' => $photo_id,
				);
			}
		}

		// Per-operation download budget: single pages need only a handful of
		// fresh photos; overflow reuses the demo's existing imagery. The
		// counter option matches the orphan-sweep pattern (…_imgs).
		$op_id        = $plan_id . 'op' . substr( md5( uniqid( '', true ) ), 0, 6 );
		$max_images   = max( 2, (int) apply_filters( 'inspiro_starter_sites/ai_max_images_single_page', 6 ) );
		$generator    = $this;
		$site_title   = isset( $record['site_title'] ) ? $record['site_title'] : '';
		$reuse_cursor = 0;
		$last_reused  = 0;
		$resolver     = function ( $query, $orientation ) use ( $generator, &$used_photo_ids, &$image_pool, &$reuse_cursor, &$last_reused, $max_images, $op_id, $plan_id, $site_title, $stream ) {
			if ( $generator->reserve_image_download( $op_id ) > $max_images ) {
				if ( ! $image_pool ) {
					return null;
				}
				$image = $image_pool[ $reuse_cursor % count( $image_pool ) ];
				$reuse_cursor++;
				if ( count( $image_pool ) > 1 && (int) $image['id'] === $last_reused ) {
					$image = $image_pool[ $reuse_cursor % count( $image_pool ) ];
					$reuse_cursor++;
				}
				$last_reused = (int) $image['id'];
				return $image;
			}

			$images = $generator->resolve_images( $query, 1, $used_photo_ids, $site_title, $plan_id, array( $stream, 'tick' ), $orientation );
			if ( ! $images ) {
				return null;
			}
			$used_photo_ids[] = $images[0]['photo_id'];
			$image_pool[]     = $images[0];
			$last_reused      = (int) $images[0]['id'];
			$stream->tick();
			return $images[0];
		};

		$brand     = isset( $record['brand'] ) && is_array( $record['brand'] ) ? $record['brand'] : array();
		$converter = new HtmlToBlocks( $page_links, $resolver, $brand );
		$content   = $converter->convert( $html, $page['slug'] );

		delete_option( self::PLAN_TRANSIENT_PREFIX . $op_id . '_imgs' );

		if ( '' === $content ) {
			$stream->finish_error( array( 'message' => esc_html__( 'The AI returned an unusable page design. Please try again.', 'inspiro-starter-sites' ) ) );
		}

		if ( $existing_page_id ) {
			if ( $append ) {
				// Keep the current layout; the new per-section wrappers slot
				// in below it as ordinary sibling blocks.
				$current = (string) get_post_field( 'post_content', $existing_page_id, 'raw' );
				$content = rtrim( $current ) . "\n\n" . $content;
			}

			$result = wp_update_post(
				wp_slash(
					array(
						'ID'           => $existing_page_id,
						'post_content' => $content,
					)
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				$stream->finish_error( array( 'message' => $result->get_error_message() ) );
			}
			$page_id = $existing_page_id;
		} else {
			$page_id = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_title'   => $page['title'],
						'post_name'    => $page['slug'],
						'post_content' => $content,
						'menu_order'   => count( $page_ids ),
						'meta_input'   => array(
							self::GENERATED_META_KEY => $plan_id,
							'_wp_page_template'      => 'page-templates/full-width-no-title.php',
						),
					)
				),
				true
			);
			if ( is_wp_error( $page_id ) ) {
				$stream->finish_error( array( 'message' => $page_id->get_error_message() ) );
			}

			// Into the demo's menu and its record, so delete/replace flows
			// keep covering the new page.
			$menu_id = isset( $record['menu_id'] ) ? (int) $record['menu_id'] : 0;
			if ( $menu_id && wp_get_nav_menu_object( $menu_id ) ) {
				wp_update_nav_menu_item(
					$menu_id,
					0,
					array(
						'menu-item-object-id' => (int) $page_id,
						'menu-item-object'    => 'page',
						'menu-item-type'      => 'post_type',
						'menu-item-status'    => 'publish',
						'menu-item-title'     => $page['title'],
					)
				);
			}

			$demos = get_option( self::DEMOS_OPTION, array() );
			if ( isset( $demos[ $plan_id ] ) ) {
				$demos[ $plan_id ]['pages'][] = (int) $page_id;
				update_option( self::DEMOS_OPTION, $demos, false );
			}
		}

		$stream->finish_success(
			array(
				'page_id'  => (int) $page_id,
				'title'    => get_the_title( $page_id ),
				'view_url' => get_permalink( $page_id ),
				'edit_url' => get_edit_post_link( $page_id, 'raw' ),
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
						? esc_html__( 'You have used all the AI generations included with your license.', 'inspiro-starter-sites' )
						: esc_html__( 'You have used all your free AI generations for this site.', 'inspiro-starter-sites' ),
				)
			);
		}

		// The Claude call runs 40-90s. Stream keep-alive bytes while waiting
		// so web servers with short idle timeouts (Apache FastCGI: 30s) don't
		// kill this request. From here on, errors go through $stream.
		$stream = new StreamingResponse();
		$stream->begin();

		try {
			$this->generate_pipeline( $stream, $quota, $description );
		} catch ( \Throwable $e ) {
			// An unexpected fatal after the reserve would silently burn one
			// of the user's free generations — give the unit back first.
			$this->proxy->quota( 'refund' );
			error_log( '[inspiro-starter-sites AI] generation failed with exception: ' . $e->getMessage() ); // phpcs:ignore
			$stream->finish_error(
				array(
					'message' => esc_html__( 'Something went wrong during generation. Your free generation was not used — please try again.', 'inspiro-starter-sites' ),
					'detail'  => 'exception',
				)
			);
		}
	}

	/**
	 * The generation flow after the quota reserve: design plan → sample
	 * cleanup → plan transient → response. Runs inside ajax_generate()'s
	 * try/catch so any uncaught error refunds the reserved unit.
	 *
	 * @param StreamingResponse $stream      Committed streaming response.
	 * @param array             $quota       Quota reservation (for 'remaining').
	 * @param string            $description The user's demo description.
	 */
	private function generate_pipeline( StreamingResponse $stream, array $quota, $description ) {
		$style_options   = $this->style_options();
		$palette_options = $this->palette_options();

		$typography_options   = $this->typography_options();
		$design_level_options = $this->design_level_options();
		$art_options          = $this->art_direction_options();

		$style      = isset( $_POST['style'] ) ? sanitize_key( wp_unslash( $_POST['style'] ) ) : '';
		$palette    = isset( $_POST['palette'] ) ? sanitize_key( wp_unslash( $_POST['palette'] ) ) : '';
		$typography = isset( $_POST['typography'] ) ? sanitize_key( wp_unslash( $_POST['typography'] ) ) : '';

		$style      = isset( $style_options[ $style ] ) ? $style : '';
		$palette    = isset( $palette_options[ $palette ] ) ? $palette : '';
		$typography = isset( $typography_options[ $typography ] ) ? $typography : '';

		// Design level. Whitelisted here for the prompt vars, but the PROXY is
		// the gate: it re-verifies the Inspiro license and silently downgrades
		// a 'pro' request from an unlicensed site.
		$design_level = isset( $_POST['design_level'] ) ? sanitize_key( wp_unslash( $_POST['design_level'] ) ) : '';
		$design_level = isset( $design_level_options[ $design_level ] ) ? $design_level : 'standard';

		// Art direction the user pinned in the Premium picker ('' = let the model
		// choose from the proxy's seeded shortlist, which is the default). Only
		// the slug travels; the proxy owns the recipe itself and ignores a pin
		// from an unlicensed site, exactly like the design level above.
		$art_direction = isset( $_POST['art_direction'] ) ? sanitize_key( wp_unslash( $_POST['art_direction'] ) ) : '';
		$art_direction = isset( $art_options[ $art_direction ] ) ? $art_direction : '';

		// Re-running the same description must not rebuild the same site: the
		// seed decides which art-direction recipes the model gets to choose
		// between. Generated here when the browser didn't send one.
		$variant_seed = isset( $_POST['variant_seed'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_POST['variant_seed'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$variant_seed = substr( $variant_seed, 0, 32 );
		if ( '' === $variant_seed ) {
			$variant_seed = strtolower( wp_generate_password( 12, false, false ) );
		}

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
				// The theme's LIVE accent variable differs per theme: Lite
				// exposes --inspiro-primary-color, Premium --color-accent.
				'theme_css_var'  => self::is_premium_inspiro() ? '--color-accent' : '--inspiro-primary-color',
				'pages'          => $approved_pages,
				'font_families'  => array_keys( $this->font_whitelist() ),
				'design_level'   => $design_level,
				'art_direction'  => $art_direction,
				'variant_seed'   => $variant_seed,
			),
			array( $stream, 'tick' )
		);

		if ( ! is_wp_error( $plan ) ) {
			$plan = $this->sanitize_plan( $plan, $approved_pages );
		}

		// A pinned art direction is authoritative for the page builds — the model
		// echoes the slug back in the plan, but a miss would silently drop the
		// user's choice for every page after this one.
		if ( ! is_wp_error( $plan ) && '' !== $art_direction ) {
			$plan['art_direction'] = $art_direction;
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
				// Families the theme can load itself are handed to its
				// typography options in finalize() — no need to duplicate
				// ~25KB of @font-face rules inside the demo stylesheet.
				if ( $this->theme_knows_font( $family ) ) {
					continue;
				}
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

		// Sweep image-budget counters orphaned by generations that died before
		// finalize. They are raw options (needed for the atomic increment),
		// not transients, so they never expire on their own. Safe here: the
		// new plan_id doesn't exist yet and finished demos already deleted
		// theirs in finalize.
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::PLAN_TRANSIENT_PREFIX ) . '%' . $wpdb->esc_like( '_imgs' )
			)
		);

		// Sample-content and previous-demo cleanup happen NOW — after the plan
		// succeeded (a failed generation never deletes anything) and before
		// the page builds, which run in parallel and in no guaranteed order.
		Helpers::delete_default_posts();
		if ( $replace ) {
			$this->delete_previous_ai_demos( $plan_id );
			$this->delete_classic_demo();
		}
		$stream->tick();

		set_transient(
			self::PLAN_TRANSIENT_PREFIX . $plan_id,
			array(
				'description'    => $description,
				'plan'           => $plan,
				'palette'        => $palette, // chosen palette slug ('' = AI decides)
				'design_level'   => $design_level,
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
					'plugin_active' => self::portfolio_grid_available(),
				),
				'forms'      => array(
					'needed'        => ! empty( $plan['contact_form_needed'] ),
					'plugin_active' => post_type_exists( 'wpzf-form' ),
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

		// Page builds run CONCURRENTLY, so each build owns a per-index result
		// transient instead of read-modify-writing the shared state (which
		// would lose updates under parallel requests). Finalize merges them.

		// Idempotency: if this page was already built (e.g. a retried request),
		// return the existing result instead of duplicating it.
		$existing = get_transient( self::PLAN_TRANSIENT_PREFIX . $plan_id . '_p' . $index );
		if ( is_array( $existing ) && ! empty( $existing['page_id'] ) ) {
			wp_send_json_success(
				array(
					'page_id'  => (int) $existing['page_id'],
					'edit_url' => get_edit_post_link( (int) $existing['page_id'], 'raw' ),
				)
			);
		}

		// Seed photo de-duplication and the reuse pool from every page already
		// built (or being built) so parallel pages rarely pick the same
		// Pexels photo — and can REUSE each other's photos once the
		// per-generation download budget is spent.
		$image_pool = array();
		foreach ( array_keys( $state['plan']['pages'] ) as $i ) {
			$result = get_transient( self::PLAN_TRANSIENT_PREFIX . $plan_id . '_p' . $i );
			if ( ! is_array( $result ) ) {
				continue;
			}
			if ( ! empty( $result['photo_ids'] ) ) {
				$state['used_photo_ids'] = array_merge( $state['used_photo_ids'], $result['photo_ids'] );
			}
			if ( ! empty( $result['images'] ) && is_array( $result['images'] ) ) {
				$image_pool = array_merge( $image_pool, $result['images'] );
			}
		}
		$state['used_photo_ids'] = array_values( array_unique( $state['used_photo_ids'] ) );

		// The page build includes a Claude call (~30-60s) plus image
		// sideloads — stream keep-alive bytes throughout.
		$stream = new StreamingResponse();
		$stream->begin();

		$page = $state['plan']['pages'][ $index ];

		// A blog page is never a designed static page: it becomes the real
		// WordPress posts page (assigned in finalize) plus a few actual posts.
		if ( ! empty( $page['is_blog'] ) ) {
			$this->build_blog_page( $state, $plan_id, $index, $page, $stream );
		}

		// One Claude call designs this page as HTML against the shared
		// stylesheet generated in the plan step (prompt assembled server-side).
		$html = $this->proxy->claude_task(
			'demo-page',
			array(
				'description'      => $state['description'],
				'site_title'       => $state['plan']['site_title'],
				'tagline'          => $state['plan']['tagline'],
				'language'         => isset( $state['plan']['language'] ) ? $state['plan']['language'] : '',
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
				// Checked at build time, like the contact form below: the
				// WPZOOM Portfolio plugin installs right before this step.
				'portfolio_needed' => ! empty( $state['plan']['portfolio']['needed'] ) && ! empty( $state['plan']['portfolio']['items'] ) && self::portfolio_grid_available(),
				// The site has blog/news posts → pages may embed the native
				// recent-posts Query Loop instead of faking article lists.
				'posts_feed'       => ! empty( $state['plan']['blog']['needed'] ),
				// A real WPZOOM Forms form can be placed on the contact page
				// (checked at build time — the plugin installs right before).
				'has_contact_form' => ! empty( $state['plan']['contact_form_needed'] ) && post_type_exists( 'wpzf-form' ),
				// Premium: the art direction the plan committed to. The proxy
				// re-resolves the slug and re-verifies the license, so a tampered
				// value simply yields the standard page prompt.
				'design_level'     => isset( $state['design_level'] ) ? $state['design_level'] : 'standard',
				'art_direction'    => isset( $state['plan']['art_direction'] ) ? $state['plan']['art_direction'] : '',
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
		// A per-generation UNIQUE-download budget bounds Pexels usage (same
		// approach as the premium framework's wpzoom_ai_max_images): once the
		// budget is spent, images REUSE already-sideloaded photos instead of
		// downloading new ones — pages stay image-led at zero extra cost.
		$max_images   = max( 3, (int) apply_filters( 'inspiro_starter_sites/ai_max_images', 20 ) );
		$generator    = $this; // For PHP 7.4 closure clarity.
		$build_images = array();
		$reuse_cursor = 0;
		$last_reused  = 0;
		$resolver     = function ( $query, $orientation ) use ( $generator, &$state, &$build_images, &$image_pool, &$reuse_cursor, &$last_reused, $max_images, $plan_id, $stream ) {
			// Atomic shared download counter (a plain transient read-then-
			// write under-counts badly when three builds race it).
			if ( $generator->reserve_image_download( $plan_id ) > $max_images ) {
				if ( ! $image_pool ) {
					return null;
				}
				// Reuse an already-sideloaded photo — and never the same one
				// twice in a row, so adjacent cards can't show duplicates.
				$image = $image_pool[ $reuse_cursor % count( $image_pool ) ];
				$reuse_cursor++;
				if ( count( $image_pool ) > 1 && (int) $image['id'] === $last_reused ) {
					$image = $image_pool[ $reuse_cursor % count( $image_pool ) ];
					$reuse_cursor++;
				}
				$last_reused = (int) $image['id'];
				return $image;
			}

			$images = $generator->resolve_images( $query, 1, $state['used_photo_ids'], $state['plan']['site_title'], $plan_id, array( $stream, 'tick' ), $orientation );
			if ( ! $images ) {
				return null;
			}
			$state['used_photo_ids'][] = $images[0]['photo_id'];
			$build_images[]            = $images[0];
			$image_pool[]              = $images[0];
			$last_reused               = (int) $images[0]['id'];
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

		set_transient(
			self::PLAN_TRANSIENT_PREFIX . $plan_id . '_p' . $index,
			array(
				'page_id'   => (int) $post_id,
				'photo_ids' => wp_list_pluck( $build_images, 'photo_id' ),
				'images'    => $build_images,
			),
			HOUR_IN_SECONDS
		);

		$stream->finish_success(
			array(
				'page_id'  => (int) $post_id,
				'edit_url' => get_edit_post_link( $post_id, 'raw' ),
			)
		);
	}

	/**
	 * Build the blog: an empty page (assigned as the WordPress posts page in
	 * finalize) and a few dummy posts — no AI call. Titles, excerpts and
	 * image queries come from the plan's "blog" field when present (the plan
	 * call already produced them at no extra cost), with generic fallbacks;
	 * bodies are placeholder copy the user is expected to replace. Streams
	 * its own success/error response and never returns.
	 *
	 * @param array             $state   Plan state.
	 * @param string            $plan_id Plan ID (cleanup meta tag).
	 * @param int               $index   Page index in the plan.
	 * @param array             $page    The blog page entry (slug/title).
	 * @param StreamingResponse $stream  Keep-alive responder.
	 */
	private function build_blog_page( array $state, $plan_id, $index, array $page, StreamingResponse $stream ) {
		// The page itself stays empty — WordPress renders the post listing
		// here once it is assigned as the posts page.
		$page_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $page['title'],
					'post_name'    => $page['slug'],
					'post_content' => '',
					'menu_order'   => $index,
					'meta_input'   => array(
						self::GENERATED_META_KEY => $plan_id,
					),
				)
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			$stream->finish_error( array( 'message' => $page_id->get_error_message() ) );
		}

		$post_ids = $this->create_dummy_posts( $state, $plan_id, $stream );

		set_transient(
			self::PLAN_TRANSIENT_PREFIX . $plan_id . '_p' . $index,
			array(
				'page_id'  => (int) $page_id,
				'post_ids' => $post_ids,
			),
			HOUR_IN_SECONDS
		);

		$stream->finish_success(
			array(
				'page_id'  => (int) $page_id,
				'edit_url' => get_edit_post_link( $page_id, 'raw' ),
			)
		);
	}

	/**
	 * Create the demo's dummy blog posts (plan-provided titles/teasers with
	 * generic fallbacks, placeholder bodies + shared placeholder thumbnail).
	 * Called from the blog-page build, and from finalize for demos whose
	 * news-style pages use the recent-posts Query Loop without a dedicated
	 * blog page. Idempotent per generation via $state['created_posts'].
	 *
	 * @param array             $state   Plan state (created_posts updated).
	 * @param string            $plan_id Plan ID (cleanup meta tag).
	 * @param StreamingResponse $stream  Keep-alive responder.
	 * @return int[] Created post IDs.
	 */
	private function create_dummy_posts( array &$state, $plan_id, StreamingResponse $stream ) {
		if ( ! empty( $state['created_posts'] ) ) {
			return $state['created_posts'];
		}

		$briefs = isset( $state['plan']['blog']['posts'] ) && is_array( $state['plan']['blog']['posts'] )
			? $state['plan']['blog']['posts']
			: array();

		// Generic fallbacks when the plan didn't propose posts.
		$fallbacks = array(
			array(
				'title' => esc_html__( 'Welcome to our new website', 'inspiro-starter-sites' ),
				'topic' => '',
			),
			array(
				'title' => esc_html__( 'Behind the scenes', 'inspiro-starter-sites' ),
				'topic' => '',
			),
			array(
				'title' => esc_html__( 'News and updates', 'inspiro-starter-sites' ),
				'topic' => '',
			),
		);

		$briefs = array_slice( array_merge( $briefs, array_slice( $fallbacks, count( $briefs ) ) ), 0, 4 );

		// One shared placeholder thumbnail for every post — a single download
		// instead of per-post photo searches keeps this step near-instant.
		$thumb_id = $this->sideload_placeholder_image( $plan_id, $state['plan']['site_title'] );
		$stream->tick();

		$bodies   = $this->placeholder_post_bodies();
		$created  = array();
		$days_ago = 2;

		foreach ( $briefs as $i => $brief ) {
			$post_id = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => 'post',
						'post_status'  => 'publish',
						'post_title'   => $brief['title'],
						'post_excerpt' => $brief['topic'],
						'post_content' => $bodies[ $i % count( $bodies ) ],
						// Spread publish dates into the recent past so the
						// blog reads as alive, not generated in one second.
						'post_date'    => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days_ago * DAY_IN_SECONDS ),
						'meta_input'   => array(
							self::GENERATED_META_KEY => $plan_id,
						),
					)
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				continue;
			}
			$days_ago += wp_rand( 5, 11 );

			if ( $thumb_id ) {
				set_post_thumbnail( $post_id, $thumb_id );
			}

			$created[] = (int) $post_id;
			$stream->tick();
		}

		$state['created_posts'] = $created;

		return $created;
	}

	/**
	 * Atomically reserve one image-download slot for a generation and return
	 * the reservation number (1-based). A raw option row with a relative
	 * UPDATE — a read-then-write transient under-counts badly when parallel
	 * page builds race it (measured: 11 downloads against a cap of 5).
	 *
	 * @param string $plan_id Plan ID.
	 * @return int
	 */
	private function reserve_image_download( $plan_id ) {
		global $wpdb;

		$option  = self::PLAN_TRANSIENT_PREFIX . $plan_id . '_imgs';
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s", $option ) );

		if ( ! $updated ) {
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')", $option ) );
			if ( $inserted ) {
				return 1;
			}
			// Lost the creation race — count ourselves in now.
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s", $option ) );
		}

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
	}

	/**
	 * Sideload the shared placeholder image used as the dummy posts'
	 * featured image. Tagged with the plan ID so the delete flow removes it.
	 *
	 * @param string $plan_id    Plan ID (cleanup meta tag).
	 * @param string $site_title For the attachment description.
	 * @return int Attachment ID (0 on failure — posts just go without).
	 */
	private function sideload_placeholder_image( $plan_id, $site_title ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		/**
		 * Filter the placeholder image URL used for demo blog post thumbnails.
		 *
		 * @param string $url
		 */
		$url = apply_filters( 'inspiro_starter_sites/ai_placeholder_image', 'https://ai.wpzoom.com/img/placeholder.png' );

		$attachment_id = media_sideload_image(
			$url,
			0,
			sprintf(
				/* translators: %s: generated demo site title */
				esc_html__( 'Placeholder image — %s', 'inspiro-starter-sites' ),
				$site_title
			),
			'id'
		);

		if ( is_wp_error( $attachment_id ) ) {
			return 0;
		}

		update_post_meta( $attachment_id, self::GENERATED_META_KEY, $plan_id );

		return (int) $attachment_id;
	}

	/**
	 * Placeholder post bodies (native block markup, three varied structures).
	 * Deliberately generic filler the user replaces with real writing.
	 *
	 * @return string[]
	 */
	private function placeholder_post_bodies() {
		$p = static function ( $text ) {
			return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
		};
		$h2 = static function ( $text ) {
			return '<!-- wp:heading --><h2 class="wp-block-heading">' . $text . '</h2><!-- /wp:heading -->';
		};

		$lorem1 = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Integer posuere erat a ante venenatis dapibus posuere velit aliquet. Cras justo odio, dapibus ac facilisis in, egestas eget quam.';
		$lorem2 = 'Vestibulum id ligula porta felis euismod semper. Maecenas faucibus mollis interdum. Donec ullamcorper nulla non metus auctor fringilla, sed posuere consectetur est at lobortis.';
		$lorem3 = 'Curabitur blandit tempus porttitor. Nullam quis risus eget urna mollis ornare vel eu leo. Aenean lacinia bibendum nulla sed consectetur.';

		$list =
			'<!-- wp:list --><ul class="wp-block-list">' .
				'<!-- wp:list-item --><li>Cras mattis consectetur purus sit amet fermentum.</li><!-- /wp:list-item -->' .
				'<!-- wp:list-item --><li>Donec sed odio dui, non porta gravida at eget metus.</li><!-- /wp:list-item -->' .
				'<!-- wp:list-item --><li>Nulla vitae elit libero, a pharetra augue.</li><!-- /wp:list-item -->' .
			'</ul><!-- /wp:list -->';

		$quote =
			'<!-- wp:quote --><blockquote class="wp-block-quote">' .
				'<!-- wp:paragraph --><p>Etiam porta sem malesuada magna mollis euismod. Sed posuere consectetur est at lobortis.</p><!-- /wp:paragraph -->' .
			'</blockquote><!-- /wp:quote -->';

		return array(
			implode( "\n\n", array( $p( $lorem1 ), $p( $lorem2 ), $h2( 'Duis mollis est non commodo' ), $p( $lorem3 ), $list, $p( $lorem2 ) ) ),
			implode( "\n\n", array( $p( $lorem2 ), $quote, $p( $lorem1 ), $h2( 'Aenean eu leo quam' ), $p( $lorem3 ) ) ),
			implode( "\n\n", array( $p( $lorem3 ), $p( $lorem1 ), $h2( 'Morbi leo risus porta' ), $list, $p( $lorem2 ) ) ),
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

		$pages = $state['plan']['pages'];

		// Merge the per-index build results (pages build in parallel, each
		// writing its own transient) into the classic state shape.
		$created_pages = isset( $state['created_pages'] ) ? $state['created_pages'] : array();
		$created_posts = isset( $state['created_posts'] ) ? $state['created_posts'] : array();

		foreach ( array_keys( $pages ) as $i ) {
			$result = get_transient( self::PLAN_TRANSIENT_PREFIX . $plan_id . '_p' . $i );
			if ( ! is_array( $result ) || empty( $result['page_id'] ) ) {
				continue;
			}
			$created_pages[ $i ] = (int) $result['page_id'];
			if ( ! empty( $result['photo_ids'] ) && is_array( $result['photo_ids'] ) ) {
				$state['used_photo_ids'] = array_merge( $state['used_photo_ids'], $result['photo_ids'] );
			}
			if ( ! empty( $result['post_ids'] ) && is_array( $result['post_ids'] ) ) {
				$created_posts = array_merge( $created_posts, array_map( 'intval', $result['post_ids'] ) );
			}
		}

		$state['used_photo_ids'] = array_values( array_unique( $state['used_photo_ids'] ) );
		$state['created_posts']  = array_values( array_unique( $created_posts ) );

		if ( empty( $created_pages ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'No pages were created.', 'inspiro-starter-sites' ) ) );
		}

		// Portfolio-item creation sideloads several photos — stream
		// keep-alive bytes so short server idle timeouts survive it.
		$stream = new StreamingResponse();
		$stream->begin();

		$this->create_portfolio_items( $state, $plan_id, $stream );

		// Demos with a news/blog facet but no dedicated Blog page still need
		// the posts their recent-posts Query Loops display. Idempotent — a
		// built blog page already created them.
		if ( ! empty( $state['plan']['blog']['needed'] ) ) {
			$this->create_dummy_posts( $state, $plan_id, $stream );
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

		// Blog page → the real WordPress posts page, so the post listing
		// renders there natively (the page itself is an empty shell).
		foreach ( $pages as $i => $p ) {
			if ( ! empty( $p['is_blog'] ) && isset( $created_pages[ $i ] ) ) {
				update_option( 'page_for_posts', (int) $created_pages[ $i ] );
				break;
			}
		}

		// A previously imported classic demo leaves a `layout-{demo}` body
		// class via this option, and its demo-specific theme CSS would bleed
		// into the AI design — the AI demo owns the site's look from here.
		delete_option( 'inspiro_demo_layout' );

		// Align the THEME's own accent with the demo so native theme UI
		// (links, buttons, hovers) matches the generated design. The pre-AI
		// customizer values are preserved once — the first generation ever —
		// and restored when the AI demo is fully deleted.
		if ( false === get_option( 'inspiro_starter_sites_ai_prev_colors', false ) ) {
			update_option(
				'inspiro_starter_sites_ai_prev_colors',
				array(
					// Lite mods.
					'colorscheme'          => get_theme_mod( 'colorscheme', false ),
					'color_palette'        => get_theme_mod( 'color_palette', false ),
					'colorscheme_hex'      => get_theme_mod( 'colorscheme_hex', false ),
					// Premium (WPZOOM framework) mods.
					'color-palettes'       => get_theme_mod( 'color-palettes', false ),
					'color-accent'         => get_theme_mod( 'color-accent', false ),
					// Typography (same mod names in both themes).
					'body-font-family'     => get_theme_mod( 'body-font-family', false ),
					'headings-font-family' => get_theme_mod( 'headings-font-family', false ),
				),
				false
			);
		}

		// Hand the demo's fonts to the THEME's typography options instead of
		// only shipping @font-face rules inside the demo stylesheet: the theme
		// then loads them locally (GDPR-safe, cached as a real stylesheet),
		// applies them site-wide — header, footer, blog — and the user can
		// change them under Appearance → Customize → Typography.
		$this->apply_demo_fonts( $state['plan'] );

		$picked     = isset( $state['palette'] ) ? (string) $state['palette'] : '';
		$accent     = isset( $state['plan']['brand']['accent'] ) ? sanitize_hex_color( $state['plan']['brand']['accent'] ) : '';
		$is_premium = self::is_premium_inspiro();

		if ( 0 === strpos( $picked, 'theme-' ) ) {
			// A theme palette was picked: make it the site's active palette.
			set_theme_mod( $is_premium ? 'color-palettes' : 'color_palette', substr( $picked, 6 ) );
		} elseif ( $accent ) {
			// Custom palette or AI-chosen colors: the demo's accent becomes
			// the theme's accent color.
			if ( $is_premium ) {
				set_theme_mod( 'color-accent', $accent );
			} else {
				set_theme_mod( 'colorscheme', 'custom' );
				set_theme_mod( 'colorscheme_hex', $accent );
			}
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
			// What the run was built from. Kept on the record (the plan
			// transient is deleted a few lines below) so a later feedback
			// submission can be read against the demo it is about.
			'context'     => array(
				'design_level'  => isset( $state['design_level'] ) ? $state['design_level'] : '',
				'palette'       => isset( $state['palette'] ) ? $state['palette'] : '',
				'art_direction' => isset( $state['plan']['art_direction'] ) ? $state['plan']['art_direction'] : '',
				'page_count'    => count( $created_pages ),
				'premium'       => class_exists( 'WPZOOM' ),
			),
			// Context for post-finalize page operations (add / regenerate).
			'tagline'     => isset( $state['plan']['tagline'] ) ? $state['plan']['tagline'] : '',
			'language'    => isset( $state['plan']['language'] ) ? $state['plan']['language'] : '',
			'brand'       => isset( $state['plan']['brand'] ) ? $state['plan']['brand'] : array(),
			// Kept apart so the CSS editor can round-trip the design rules
			// without re-sanitizing (and mangling) the font-file URLs.
			'css'         => isset( $state['plan']['css'] ) ? trim( $state['plan']['css'] ) : '',
			'font_css'    => ! empty( $state['plan']['font_css'] ) ? trim( $state['plan']['font_css'] ) : '',
			'pages'       => array_values( $created_pages ),
			'posts'       => isset( $state['created_posts'] ) ? array_values( $state['created_posts'] ) : array(),
			// Whether this demo shows its work through the portfolio grid, so
			// a page regenerated later keeps the grid instead of losing it.
			'portfolio'   => ! empty( $state['plan']['portfolio']['needed'] ) && ! empty( $state['plan']['portfolio']['items'] ),
			'menu_id'     => $menu_id && ! is_wp_error( $menu_id ) ? (int) $menu_id : 0,
			'widgets'     => $footer_widget_ids,
			'created_at'  => current_time( 'mysql' ),
		);

		update_option( self::DEMOS_OPTION, $demos, false );

		delete_transient( self::PLAN_TRANSIENT_PREFIX . $plan_id );
		delete_option( self::PLAN_TRANSIENT_PREFIX . $plan_id . '_imgs' );
		foreach ( array_keys( $pages ) as $i ) {
			delete_transient( self::PLAN_TRANSIENT_PREFIX . $plan_id . '_p' . $i );
		}

		// Fresh permalinks for the new pages.
		flush_rewrite_rules();

		$stream->finish_success(
			array(
				'view_url'   => home_url( '/' ),
				'pages_url'  => admin_url( 'edit.php?post_type=page' ),
				// So the regenerate picker is populated even if the modal is
				// never closed between generation and page tools.
				'demo_pages' => $this->demo_pages_for_picker(),
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

		if ( empty( $portfolio['needed'] ) || empty( $portfolio['items'] ) || ! post_type_exists( 'portfolio_item' ) || ! self::portfolio_grid_available() ) {
			return;
		}

		// Portfolio categories: ensure a term per plan category, so the grid
		// block's filter bar (on by default) becomes functional. Only terms
		// this generation CREATES get the cleanup meta tag — a pre-existing
		// category with the same name is reused and never deleted later.
		$term_ids = array();
		if ( taxonomy_exists( 'portfolio' ) ) {
			foreach ( $portfolio['items'] as $item ) {
				$category = isset( $item['category'] ) ? trim( $item['category'] ) : '';
				if ( '' === $category || isset( $term_ids[ $category ] ) ) {
					continue;
				}

				$existing = term_exists( $category, 'portfolio' );
				if ( $existing ) {
					$term_ids[ $category ] = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
					continue;
				}

				$term = wp_insert_term( $category, 'portfolio' );
				if ( ! is_wp_error( $term ) ) {
					$term_ids[ $category ] = (int) $term['term_id'];
					add_term_meta( (int) $term['term_id'], self::GENERATED_META_KEY, $plan_id );
				}
			}
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

			if ( ! empty( $item['category'] ) && isset( $term_ids[ trim( $item['category'] ) ] ) ) {
				wp_set_object_terms( $post_id, array( $term_ids[ trim( $item['category'] ) ] ), 'portfolio' );
			}

			if ( ! empty( $item['image_query'] ) ) {
				// Featured images: the portfolio grid renders registered crop
				// sizes, so these DO generate thumbnails.
				$images = $this->resolve_images( $item['image_query'], 1, $state['used_photo_ids'], $state['plan']['site_title'], $plan_id, array( $stream, 'tick' ), 'landscape', true );
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

		// Widget titles are h2: it's the only heading level the shipped
		// theme's footer styles fully cover (light color on the dark footer).
		if ( ! empty( $footer['about'] ) ) {
			$widgets['footer_1'] = sprintf(
				"<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">%s</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->",
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

		// Native social icon buttons under the contact details.
		$social_markup = ! empty( $footer['social'] ) && is_array( $footer['social'] )
			? HtmlToBlocks::social_links_markup( $footer['social'] )
			: '';

		if ( $contact_lines || $social_markup ) {
			$heading = ! empty( $footer['contact_heading'] ) ? $footer['contact_heading'] : __( 'Contact', 'inspiro-starter-sites' );

			$content = sprintf(
				"<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">%s</h2>\n<!-- /wp:heading -->",
				esc_html( $heading )
			);
			if ( $contact_lines ) {
				$content .= sprintf( "\n\n<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->", implode( '<br>', $contact_lines ) );
			}
			if ( $social_markup ) {
				$content .= "\n\n" . $social_markup;
			}

			$widgets['footer_2'] = $content;
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
				'post_type'   => array( 'page', 'post', 'attachment', 'portfolio_item' ),
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		$counts             = array(
			'pages'       => 0,
			'attachments' => 0,
		);
		$front_page_id      = (int) get_option( 'page_on_front' );
		$front_deleted      = false;
		$posts_page_id      = (int) get_option( 'page_for_posts' );
		$posts_page_deleted = false;

		foreach ( $post_ids as $post_id ) {
			$type = get_post_type( $post_id );
			if ( (int) $post_id === $front_page_id ) {
				$front_deleted = true;
			}
			if ( (int) $post_id === $posts_page_id ) {
				$posts_page_deleted = true;
			}
			if ( wp_delete_post( $post_id, true ) ) {
				$counts[ 'attachment' === $type ? 'attachments' : 'pages' ]++;
			}
		}

		// The posts page pointed at a deleted AI blog page. Safe to reset even
		// mid-replace: the new demo's finalize reassigns it when needed.
		if ( $posts_page_deleted ) {
			update_option( 'page_for_posts', 0 );
		}

		// Portfolio categories these demos created (term meta carries the plan
		// ID — pre-existing categories were never tagged, so never deleted).
		if ( taxonomy_exists( 'portfolio' ) ) {
			$term_ids = get_terms(
				array(
					'taxonomy'   => 'portfolio',
					'hide_empty' => false,
					'fields'     => 'ids',
					'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			if ( is_array( $term_ids ) ) {
				foreach ( $term_ids as $term_id ) {
					wp_delete_term( (int) $term_id, 'portfolio' );
				}
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

		// Leftover mid-generation state (including per-page build results).
		foreach ( $remove_plan_ids as $plan ) {
			delete_transient( self::PLAN_TRANSIENT_PREFIX . $plan );
			delete_option( self::PLAN_TRANSIENT_PREFIX . $plan . '_imgs' );
			for ( $i = 0; $i < 8; $i++ ) {
				delete_transient( self::PLAN_TRANSIENT_PREFIX . $plan . '_p' . $i );
			}
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

			// Restore the customizer colors and fonts from before the first AI demo.
			$prev_colors = get_option( 'inspiro_starter_sites_ai_prev_colors' );
			if ( is_array( $prev_colors ) ) {
				foreach ( array( 'colorscheme', 'color_palette', 'colorscheme_hex', 'color-palettes', 'color-accent', 'body-font-family', 'headings-font-family' ) as $mod ) {
					if ( array_key_exists( $mod, $prev_colors ) && false !== $prev_colors[ $mod ] ) {
						set_theme_mod( $mod, $prev_colors[ $mod ] );
					} else {
						remove_theme_mod( $mod );
					}
				}
			}
			delete_option( 'inspiro_starter_sites_ai_prev_colors' );
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
	private function resolve_images( $query, $count, array $used_ids, $site_title, $plan_id = '', $heartbeat = null, $orientation = 'landscape', $generate_sizes = false ) {
		$count  = max( 1, (int) $count );
		$photos = $this->proxy->pexels_photos( $query, $count + 4, $orientation );
		$images = array();

		// Content images render at `full` (already web-sized: Pexels w=1920),
		// so the 17 registered thumbnail sizes this install would generate are
		// never displayed — skipping them saves ~1s per image. Featured images
		// (portfolio grid, blog thumbnails) pass $generate_sizes = true since
		// their blocks render registered crop sizes.
		if ( ! $generate_sizes ) {
			add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 100 );
		}

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

		if ( ! $generate_sizes ) {
			remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 100 );
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

		$t0            = microtime( true );
		$attachment_id = media_sideload_image( $photo['url'], 0, sanitize_text_field( $site_title . ' — ' . $query ), 'id' );
		AiProxyClient::log_timing( 'sideload:' . $query, $t0 );

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

		// The source photo ID makes the attachment re-fetchable and lets
		// post-finalize operations (add/regenerate page, future export)
		// rebuild the de-duplication list and reuse pool.
		update_post_meta( $attachment_id, '_iss_pexels_photo_id', (int) $photo['id'] );

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
			'language'   => $clean_text( isset( $plan['language'] ) ? $plan['language'] : '', 40 ),
			'css'        => $this->sanitize_css( isset( $plan['css'] ) ? $plan['css'] : '' ),
			'footer'     => array(),
			'pages'      => array(),
			// Opaque slug naming the art-direction recipe this plan committed
			// to (Premium only, '' otherwise). The page builds hand it back to
			// the proxy, which owns the catalog — the client never needs it.
			'art_direction' => isset( $plan['art_direction'] ) ? substr( sanitize_key( (string) $plan['art_direction'] ), 0, 40 ) : '',
		);

		if ( ! empty( $plan['footer'] ) && is_array( $plan['footer'] ) ) {
			foreach ( array( 'about', 'contact_heading', 'email', 'phone', 'address' ) as $key ) {
				if ( isset( $plan['footer'][ $key ] ) ) {
					$clean['footer'][ $key ] = $clean_text( $plan['footer'][ $key ], 300 );
				}
			}

			// Social networks for the footer icons (whitelist-validated).
			$clean['footer']['social'] = array();
			if ( ! empty( $plan['footer']['social'] ) && is_array( $plan['footer']['social'] ) ) {
				$allowed = array( 'instagram', 'facebook', 'x', 'twitter', 'youtube', 'linkedin', 'tiktok', 'pinterest', 'vimeo' );
				foreach ( array_slice( $plan['footer']['social'], 0, 5 ) as $network ) {
					$network = strtolower( sanitize_key( (string) $network ) );
					if ( in_array( $network, $allowed, true ) ) {
						$clean['footer']['social'][] = $network;
					}
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
					'category'    => $clean_text( isset( $item['category'] ) ? $item['category'] : '', 40 ),
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

		// Blog/news handling, two flavors:
		//  - an EXACT "Blog" page becomes the real WordPress posts page
		//    (empty shell, assigned in finalize) — is_blog;
		//  - any other news-style page (News, Local News, Journal, Stories…)
		//    stays a DESIGNED page whose post listing is a native Query Loop
		//    — posts_feed. Both flavors get real dummy posts generated.
		$has_blog_facet = false;
		foreach ( $clean['pages'] as $i => $page ) {
			if ( ! $has_blog_facet && ( 'blog' === $page['slug'] || preg_match( '/^blog$/i', $page['title'] ) ) ) {
				$clean['pages'][ $i ]['is_blog'] = true;
				$has_blog_facet                  = true;
				continue;
			}
			if ( preg_match( '/(^|[-\s])(blog|news|journal|articles?|stories)([-\s]|$)/i', $page['slug'] . ' ' . $page['title'] ) ) {
				$clean['pages'][ $i ]['posts_feed'] = true;
				$has_blog_facet                     = true;
			}
		}

		// Contact page → the demo gets a real WPZOOM Forms contact form
		// (plugin installed in the background, like the portfolio).
		$clean['contact_form_needed'] = false;
		foreach ( $clean['pages'] as $page ) {
			if ( preg_match( '/contact|kontakt/i', $page['slug'] . ' ' . $page['title'] ) ) {
				$clean['contact_form_needed'] = true;
				break;
			}
		}

		$clean['blog'] = array(
			'needed' => $has_blog_facet,
			'posts'  => array(),
		);
		if ( ! empty( $plan['blog']['posts'] ) && is_array( $plan['blog']['posts'] ) ) {
			foreach ( array_slice( $plan['blog']['posts'], 0, 4 ) as $post ) {
				if ( ! is_array( $post ) || empty( $post['title'] ) ) {
					continue;
				}
				$clean['blog']['posts'][] = array(
					'title' => $clean_text( $post['title'], 150 ),
					'topic' => $clean_text( isset( $post['topic'] ) ? $post['topic'] : '', 300 ),
				);
			}
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
	private function sanitize_css( $css, $with_bridge = true ) {
		$css = (string) $css;
		$css = str_replace( array( '<', '\\' ), '', $css );
		$css = preg_replace( '/@import[^;]*;?/i', '', $css );
		$css = preg_replace( '/url\s*\(/i', 'noop(', $css );
		$css = trim( mb_substr( $css, 0, 60000 ) );

		// Must actually be scoped to the demo wrapper.
		if ( false === strpos( $css, '.iss-ai-demo' ) ) {
			return '';
		}

		// Editing an existing stylesheet: it already carries the bridge rules
		// below, so adding them again would duplicate them on every save.
		if ( ! $with_bridge ) {
			return $css;
		}

		// Prepended BEFORE the AI CSS at matched (0,1,0) specificity: the
		// premium theme blanket-centers group children (".wp-block-group >
		// :where(:not(.alignleft)...)  { margin: auto }"), which centers any
		// max-width element the AI meant to left-align. This tie-breaks the
		// theme rule by source order (our style block prints later in
		// wp_head), while the AI CSS below still wins over it the same way,
		// so AI-authored centering (e.g. .ai-container) is preserved.
		$css = '.iss-ai-demo :where(.wp-block-group) > :where(:not(.alignleft):not(.alignright):not(.alignfull)),'
			. ':where(.wp-block-group).iss-ai-demo > :where(:not(.alignleft):not(.alignright):not(.alignfull))'
			. '{margin-left:0;margin-right:0}'
			. "\n" . $css;

		// Bridge rules appended after the AI CSS:
		// - neutralize theme styles that interfere inside the demo scope;
		// - buttons must never inherit the AI's generic link underline;
		// - a baseline vertical rhythm so missing AI rules can't leave
		//   elements glued together (AI selectors are more specific and win);
		// - restore native paddings that an AI-written universal reset
		//   (".iss-ai-demo * { padding:0 }") would strip from core blocks —
		//   these class selectors outrank the wildcard.
		$css .= "\n.iss-ai-demo.iss-ai-demo{padding-top:0;padding-bottom:0}"
			. ".iss-ai-demo.iss-ai-demo .ai-container{max-width:1200px;padding-left:0;padding-right:0}"
			. ".iss-ai-demo.iss-ai-demo .wp-block-separator{width:100%;max-width:none;margin-left:0;margin-right:0}"
			. ".iss-ai-demo figure{margin:0}.iss-ai-demo img{height:auto;max-width:100%}.iss-ai-demo .wp-block-image{margin:0}.iss-ai-demo .wp-block-image img{width:100%}"
			. ".iss-ai-demo .wp-block-button__link{text-decoration:none;padding:calc(0.667em + 2px) calc(1.333em + 2px)}"
			. ".iss-ai-demo .wp-block-buttons{display:flex;flex-wrap:wrap;gap:.75rem}"
			. ".iss-ai-demo ul.wp-block-list,.iss-ai-demo ol.wp-block-list{padding-left:1.4em;margin-bottom:1em}"
			. ".iss-ai-demo .wp-block-quote{padding-left:1.2em}"
			. ".iss-ai-demo .ai-card{margin-block-start:0}"
			. ".iss-ai-demo h1,.iss-ai-demo h2,.iss-ai-demo h3,.iss-ai-demo h4{margin-top:0;margin-bottom:.5em}"
			. ".iss-ai-demo p{margin-top:0;margin-bottom:1em}"
			. ".iss-ai-demo p:last-child,.iss-ai-demo h2:last-child,.iss-ai-demo h3:last-child{margin-bottom:0}"
			// Contrast safety net: inside a group that declares a text color,
			// bare headings/paragraphs inherit it. At (0,1,1) this out-orders
			// the AI's base element rules (same specificity, later source) but
			// loses to any section-scoped AI override like ".ai-section-dark
			// h2" — so it only kicks in where the AI forgot one and the base
			// ink color would vanish against a dark section background.
			. ".iss-ai-demo :where(.has-text-color) h1,.iss-ai-demo :where(.has-text-color) h2,.iss-ai-demo :where(.has-text-color) h3,"
			. ".iss-ai-demo :where(.has-text-color) h4,.iss-ai-demo :where(.has-text-color) h5,.iss-ai-demo :where(.has-text-color) h6,"
			. ".iss-ai-demo :where(.has-text-color) p{color:inherit}";

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
			// @font-face rules are stored apart from the editable design CSS
			// (older records keep both in 'css' — harmless, they just render
			// as one block).
			$fonts = ! empty( $demos[ $plan_id ]['font_css'] ) ? $demos[ $plan_id ]['font_css'] . "\n" : '';

			return $this->scale_css_for_theme( $fonts . $demos[ $plan_id ]['css'] );
		}

		$state = $this->get_plan_state( $plan_id );
		if ( ! $state || empty( $state['plan']['css'] ) ) {
			return '';
		}
		$font_css = ! empty( $state['plan']['font_css'] ) ? $state['plan']['font_css'] . "\n" : '';
		return $this->scale_css_for_theme( $font_css . $state['plan']['css'] );
	}

	/**
	 * The premium theme sets html { font-size: 10px }, so every rem in the
	 * AI stylesheet (authored against Lite's 16px root) renders at 62.5% of
	 * its intended size. Scale rem values at RENDER time — theme-aware, and
	 * still correct if the user later switches themes.
	 *
	 * @param string $css Demo stylesheet.
	 * @return string
	 */
	private function scale_css_for_theme( $css ) {
		if ( ! self::is_premium_inspiro() ) {
			return $css;
		}

		return preg_replace_callback(
			'/(\d*\.?\d+)rem\b/',
			static function ( $m ) {
				$scaled = (float) $m[1] * 1.6;
				return rtrim( rtrim( number_format( $scaled, 3, '.', '' ), '0' ), '.' ) . 'rem';
			},
			(string) $css
		);
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
	 * User-selectable generation modes: slug => [ label, hint, pro ].
	 *
	 * 'pro' unlocks the proxy's art-direction recipe library — concrete,
	 * mutually distinct design specs that replace the generic prompt defaults
	 * responsible for every generated site arriving as the same photo-cover
	 * hero plus three equal cards. The proxy verifies the Inspiro license
	 * itself and silently downgrades an unlicensed request, so this list only
	 * drives the UI — it is not the gate.
	 *
	 * @return array[]
	 */
	private function design_level_options() {
		return array(
			'standard' => array(
				'label' => __( 'Balanced', 'inspiro-starter-sites' ),
				'hint'  => __( 'Faster, built on the clean layouts Inspiro demos are known for.', 'inspiro-starter-sites' ),
				'pro'   => false,
			),
			'pro'      => array(
				'label' => __( 'Creative', 'inspiro-starter-sites' ),
				'hint'  => __( 'Slower, more creative — a distinct art direction, layout and type for every site.', 'inspiro-starter-sites' ),
				'pro'   => true,
			),
		);
	}

	/**
	 * The Premium art-direction catalog, for the picker in the modal.
	 *
	 * Display data ONLY. The recipes themselves — the layout, surface and
	 * prohibition specs the prompt is actually built from — live on the AI
	 * proxy, which resolves the slug and re-verifies the license; the client
	 * never sees them and cannot pin one without a Premium license.
	 *
	 * Keys must match the proxy catalog in wpzoom-ai-recipes.php. A slug the
	 * proxy no longer knows is ignored server-side, so a stale entry here
	 * degrades to 'let the AI choose' rather than failing a generation.
	 *
	 * Per entry:
	 *   label   — name shown on the card.
	 *   hint    — one sentence describing what the direction actually does.
	 *   styles  — design styles this direction suits; the others are
	 *             de-emphasised once the user picks a style, never hidden.
	 *   preview — drives the CSS wireframe on the card: hero shape, body
	 *             shape, corner radius, page tone, representative accent.
	 *
	 * @return array[]
	 */
	private function art_direction_options() {
		return array(
			'cover-editorial' => array(
				'label'   => __( 'Cover editorial', 'inspiro-starter-sites' ),
				'hint'    => __( 'A full-bleed photo cover with the headline set bottom-left like a magazine, then editorial index rows divided by hairline rules.', 'inspiro-starter-sites' ),
				'styles'  => array( 'editorial', 'luxury', 'minimal', 'corporate', 'dark' ),
				'preview' => array(
					'hero'   => 'cover',
					'body'   => 'rows',
					'radius' => '0px',
					'tone'   => 'light',
					'accent' => '#c2410c',
				),
			),

			'split-asymmetric' => array(
				'label'   => __( 'Asymmetric split', 'inspiro-starter-sites' ),
				'hint'    => __( 'Copy on the left, one tall photograph on the right, then pairs that alternate direction down the page. Outlined, never filled.', 'inspiro-starter-sites' ),
				'styles'  => array( 'minimal', 'corporate', 'editorial', 'luxury' ),
				'preview' => array(
					'hero'   => 'split',
					'body'   => 'pairs',
					'radius' => '4px',
					'tone'   => 'paper',
					'accent' => '#57534e',
				),
			),

			'type-first' => array(
				'label'   => __( 'Type first', 'inspiro-starter-sites' ),
				'hint'    => __( 'No photograph in the hero at all — an oversized headline on a flat tint, with the first image arriving in the section below.', 'inspiro-starter-sites' ),
				'styles'  => array( 'minimal', 'bold', 'editorial', 'corporate' ),
				'preview' => array(
					'hero'   => 'type',
					'body'   => 'rows',
					'radius' => '0px',
					'tone'   => 'light',
					'accent' => '#2563eb',
				),
			),

			'stacked-editorial' => array(
				'label'   => __( 'Stacked editorial', 'inspiro-starter-sites' ),
				'hint'    => __( 'A centred masthead with a wide photograph directly beneath it, then unhurried media-and-text rows. Reads like a long-form article.', 'inspiro-starter-sites' ),
				'styles'  => array( 'editorial', 'luxury', 'retro' ),
				'preview' => array(
					'hero'   => 'stack',
					'body'   => 'pairs',
					'radius' => '2px',
					'tone'   => 'paper',
					'accent' => '#7c6a58',
				),
			),

			'bento-tiles' => array(
				'label'   => __( 'Bento tiles', 'inspiro-starter-sites' ),
				'hint'    => __( 'A cluster of rounded filled tiles instead of a banner, with tile weights that change from row to row.', 'inspiro-starter-sites' ),
				'styles'  => array( 'playful', 'corporate', 'bold', 'dark' ),
				'preview' => array(
					'hero'   => 'bento',
					'body'   => 'tiles',
					'radius' => '16px',
					'tone'   => 'light',
					'accent' => '#0ea5e9',
				),
			),

			'poster-brutal' => array(
				'label'   => __( 'Poster', 'inspiro-starter-sites' ),
				'hint'    => __( 'A dense uppercase display headline, a solid colour band, a ticker row, and four hard-bordered cells. Nothing rounded, nothing soft.', 'inspiro-starter-sites' ),
				'styles'  => array( 'bold', 'playful', 'retro', 'dark' ),
				'preview' => array(
					'hero'   => 'poster',
					'body'   => 'grid4',
					'radius' => '0px',
					'tone'   => 'light',
					'accent' => '#ff4d2d',
				),
			),

			'soft-rounded' => array(
				'label'   => __( 'Soft rounded', 'inspiro-starter-sites' ),
				'hint'    => __( 'Heavily rounded photography sitting as an object on the page, tinted cards with no borders, and pill-shaped buttons.', 'inspiro-starter-sites' ),
				'styles'  => array( 'playful', 'corporate' ),
				'preview' => array(
					'hero'   => 'split',
					'body'   => 'cards',
					'radius' => '24px',
					'tone'   => 'light',
					'accent' => '#14b8a6',
				),
			),

			'duotone-band' => array(
				'label'   => __( 'Duotone bands', 'inspiro-starter-sites' ),
				'hint'    => __( 'A photo cover washed in the accent colour, then full-bleed bands alternating deep accent, near-black and near-white.', 'inspiro-starter-sites' ),
				'styles'  => array( 'bold', 'dark', 'editorial' ),
				'preview' => array(
					'hero'   => 'duotone',
					'body'   => 'pairs',
					'radius' => '0px',
					'tone'   => 'accent',
					'accent' => '#1e3a8a',
				),
			),

			'gallery-led' => array(
				'label'   => __( 'Gallery led', 'inspiro-starter-sites' ),
				'hint'    => __( 'The photography is the hero: a gallery opens the page and the title sits underneath it. Quiet type, no painted colour.', 'inspiro-starter-sites' ),
				'styles'  => array( 'minimal', 'editorial', 'luxury', 'dark' ),
				'preview' => array(
					'hero'   => 'gallery',
					'body'   => 'pairs',
					'radius' => '2px',
					'tone'   => 'light',
					'accent' => '#6b7280',
				),
			),

			'centered-classic' => array(
				'label'   => __( 'Centered classic', 'inspiro-starter-sites' ),
				'hint'    => __( 'A centred hero framed above and below by hairline rules, then three text columns divided by vertical rules. Restrained throughout.', 'inspiro-starter-sites' ),
				'styles'  => array( 'luxury', 'editorial', 'retro', 'minimal' ),
				'preview' => array(
					'hero'   => 'centered',
					'body'   => 'ruled',
					'radius' => '0px',
					'tone'   => 'ivory',
					'accent' => '#8a6d3b',
				),
			),

			'sidebar-index' => array(
				'label'   => __( 'Sidebar index', 'inspiro-starter-sites' ),
				'hint'    => __( 'A narrow meta column beside the headline, then rows numbered 01, 02, 03 down the left edge. Structured by alignment alone.', 'inspiro-starter-sites' ),
				'styles'  => array( 'minimal', 'corporate', 'editorial', 'bold', 'dark' ),
				'preview' => array(
					'hero'   => 'meta',
					'body'   => 'rows',
					'radius' => '0px',
					'tone'   => 'light',
					'accent' => '#2563eb',
				),
			),

			'overlap-offset' => array(
				'label'   => __( 'Overlap offset', 'inspiro-starter-sites' ),
				'hint'    => __( 'The hero photograph deliberately breaks its section boundary, and paired items sit nudged up and down so no row reads flat.', 'inspiro-starter-sites' ),
				'styles'  => array( 'playful', 'editorial', 'bold' ),
				'preview' => array(
					'hero'   => 'overlap',
					'body'   => 'pairs',
					'radius' => '12px',
					'tone'   => 'light',
					'accent' => '#f97316',
				),
			),

			'mono-contrast' => array(
				'label'   => __( 'Mono contrast', 'inspiro-starter-sites' ),
				'hint'    => __( 'Near-black end to end with one luminous accent, a light-weight oversized headline, and cells divided by dim hairline rules.', 'inspiro-starter-sites' ),
				'styles'  => array( 'dark', 'bold', 'minimal' ),
				'preview' => array(
					'hero'   => 'type',
					'body'   => 'grid4',
					'radius' => '0px',
					'tone'   => 'dark',
					'accent' => '#a3e635',
				),
			),

			'warm-editorial' => array(
				'label'   => __( 'Warm editorial', 'inspiro-starter-sites' ),
				'hint'    => __( 'Cream paper, deep brown ink, and an italic serif accent word inside an otherwise plain headline. No pure white, no pure black.', 'inspiro-starter-sites' ),
				'styles'  => array( 'retro', 'luxury', 'editorial', 'playful' ),
				'preview' => array(
					'hero'   => 'split',
					'body'   => 'pairs',
					'radius' => '4px',
					'tone'   => 'warm',
					'accent' => '#a1522d',
				),
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

		$theme_palettes = array();
		$active         = '';

		if ( function_exists( 'inspiro_get_color_palettes' ) ) {
			// Inspiro Lite customizer palettes; the selection lives in
			// `color_palette` (`colorscheme` is the light/dark/custom radio).
			$theme_palettes = inspiro_get_color_palettes();
			$active         = get_theme_mod( 'color_palette', 'default' );
		} elseif ( function_exists( 'wpzoom_get_color_palettes' ) ) {
			// Inspiro Premium (WPZOOM framework) Global Color Palettes.
			$theme_palettes = wpzoom_get_color_palettes();
			$active         = get_theme_mod( 'color-palettes', 'default' );
		}

		if ( $theme_palettes ) {

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
					// Any theme palette may bind to the live CSS variable:
					// finalize activates the picked palette in the customizer,
					// so the variable always matches by the time pages render.
					'theme_var' => true,
				);
			}
		}

		return $palettes + array(
			'electric' => array(
				'label'  => __( 'Electric Blue', 'inspiro-starter-sites' ),
				'colors' => array( '#2E5BFF', '#0B1220', '#F4F7FF' ),
			),
			'lime'     => array(
				'label'  => __( 'Acid Lime', 'inspiro-starter-sites' ),
				'colors' => array( '#D8F34E', '#101010', '#FAFAF6' ),
			),
			'coral'    => array(
				'label'  => __( 'Coral Pop', 'inspiro-starter-sites' ),
				'colors' => array( '#FF5A36', '#1E1B18', '#FFF6F1' ),
			),
			'magenta'  => array(
				'label'  => __( 'Magenta', 'inspiro-starter-sites' ),
				'colors' => array( '#F0257E', '#1A1023', '#FFF3F9' ),
			),
			'violet'   => array(
				'label'  => __( 'Violet', 'inspiro-starter-sites' ),
				'colors' => array( '#7C3AED', '#140E24', '#F7F4FF' ),
			),
			'sunshine' => array(
				'label'  => __( 'Sunshine', 'inspiro-starter-sites' ),
				'colors' => array( '#FFC300', '#191400', '#FFFDF2' ),
			),
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
