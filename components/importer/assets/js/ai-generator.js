/**
 * "Generate a demo with AI" modal + orchestration.
 *
 * Flow: describe site → ai_generate (site plan, ~30-90s) → ai_build_page per
 * page (Pexels images + block markup + wp_insert_post) → ai_finalize (menu +
 * front page). Each request is short so shared hosts don't time out.
 */
/* global jQuery, inspiro_starter_sites_ai */
jQuery( function ( $ ) {
	'use strict';

	var config = ( typeof inspiro_starter_sites_ai !== 'undefined' ) ? inspiro_starter_sites_ai : null;
	if ( ! config ) {
		return;
	}

	var t     = config.texts || {};
	var $root = $( '.js-iss-ai-root' );
	if ( ! $root.length ) {
		return;
	}

	// Premium page tools (add / regenerate a page) need the premium theme
	// AND an active license — mirrored server-side.
	var pageToolsAvailable = !! ( config.is_premium_theme && config.has_license );

	var built     = false;
	var running   = false;
	var quota     = null; // { connected, email, used, limit, remaining }
	var connected = false; // Email registration with the WPZOOM AI server.
	var planState = null; // { plan_id, pages: [...] }

	// Post-generation survey answers (reset for every run).
	var feedbackRating = 0;
	var feedbackKept   = '';

	function esc( s ) {
		return $( '<div>' ).text( s == null ? '' : String( s ) ).html();
	}

	function sprintf( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i    = 0;
		return String( str ).replace( /%(\d+)\$s|%s/g, function ( match, num ) {
			var index = num ? parseInt( num, 10 ) - 1 : i++;
			return typeof args[ index ] !== 'undefined' ? args[ index ] : match;
		} );
	}

	function ajax( action, data, timeout ) {
		return $.ajax( {
			url:      config.ajax_url,
			type:     'POST',
			dataType: 'json', // Responses may carry leading keep-alive whitespace — still valid JSON.
			timeout:  timeout || 60000,
			data:     $.extend( {
				action:   action,
				security: config.ajax_nonce
			}, data || {} )
		} );
	}

	// Technical detail string for a failed XHR — shown under the error
	// message so problems can actually be diagnosed and reported.
	function xhrDetail( xhr, textStatus ) {
		var parts = [];
		if ( xhr && xhr.status ) {
			parts.push( 'HTTP ' + xhr.status + ( xhr.statusText ? ' ' + xhr.statusText : '' ) );
		}
		if ( textStatus && 'error' !== textStatus ) {
			parts.push( textStatus ); // e.g. "timeout", "parsererror"
		}
		if ( xhr && ! xhr.responseJSON && xhr.responseText ) {
			parts.push( $.trim( xhr.responseText ).slice( 0, 300 ) );
		}
		return parts.join( ' — ' );
	}

	/* -----------------------------------------------------------------
	 * Feedback survey
	 * -------------------------------------------------------------- */

	function feedbackStars() {
		var out = '';
		for ( var i = 1; i <= 5; i++ ) {
			out += '<button type="button" class="iss-ai-star js-iss-ai-star" data-value="' + i + '" ' +
				'aria-label="' + esc( sprintf( t.feedback_star || '%s', i ) ) + '">&#9733;</button>';
		}
		return out;
	}

	function feedbackChoices() {
		var choices = [
			[ 'kept', t.feedback_kept ],
			[ 'undecided', t.feedback_undecided ],
			[ 'discarded', t.feedback_discarded ]
		];
		var out = '';

		for ( var i = 0; i < choices.length; i++ ) {
			out += '<button type="button" class="iss-ai-feedback__choice js-iss-ai-keep-choice" ' +
				'data-value="' + choices[ i ][ 0 ] + '">' + esc( choices[ i ][ 1 ] || '' ) + '</button>';
		}
		return out;
	}

	/* -----------------------------------------------------------------
	 * Modal
	 * -------------------------------------------------------------- */

	// Simple stroke icons for the idea cards (currentColor, 20x20 viewBox).
	var IDEA_ICONS = {
		camera:       '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="2" y="6" width="16" height="11" rx="2"/><circle cx="10" cy="11.5" r="3.2"/><path d="M7 6l1.2-2h3.6L13 6"/></svg>',
		video:        '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="2" y="5.5" width="11" height="9" rx="2"/><path d="M13 9l5-2.5v7L13 11"/></svg>',
		film:         '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="8.5" width="14" height="8" rx="1.5"/><path d="M3.2 8.3L4.5 4.5l12.8 1.9-.8 2.1M7.5 5l1.6 3M11.5 5.6l1.6 3"/></svg>',
		pen:          '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M3 17l1-4L14.5 2.5a1.4 1.4 0 012 0l1 1a1.4 1.4 0 010 2L7 16z"/><path d="M12 5l3 3"/></svg>',
		briefcase:    '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="2.5" y="6.5" width="15" height="10" rx="2"/><path d="M7 6.5V5a2 2 0 012-2h2a2 2 0 012 2v1.5M2.5 11h15"/></svg>',
		restaurant:   '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M6 2v7M4 2v4a2 2 0 004 0V2M6 9v9"/><path d="M13 2c1.8 0 3 1.7 3 4s-1.2 4-3 4v8"/></svg>',
		wellness:     '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M10 3c1.8 1.6 2.8 3.6 2.8 5.7 0 2-1 3.9-2.8 5.3-1.8-1.4-2.8-3.3-2.8-5.3C7.2 6.6 8.2 4.6 10 3z"/><path d="M3.5 9.5c2.4.4 4.3 1.6 5.4 3.6M16.5 9.5c-2.4.4-4.3 1.6-5.4 3.6M10 14v3.5"/></svg>',
		architecture: '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M3 17h14M4 17V7l6-4 6 4v10"/><path d="M8 17v-4h4v4M8 8h1M11 8h1"/></svg>',
		fitness:      '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M2 10h2M16 10h2M6 6v8M14 6v8M6 10h8"/><rect x="4.4" y="7" width="1.6" height="6" rx="0.5"/><rect x="14" y="7" width="1.6" height="6" rx="0.5"/></svg>',
		travel:       '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M2 16l5.5-9 3.5 5.5L13 9l5 7z"/><circle cx="14.5" cy="4.5" r="1.5"/></svg>'
	};


	/* -----------------------------------------------------------------
	 * Art-direction wireframes
	 *
	 * Each recipe declares a hero shape and a section shape in PHP; these
	 * draw them from a handful of spans so a new recipe gets a preview
	 * without a screenshot that would drift from the prompt behind it.
	 * -------------------------------------------------------------- */

	function wfLine( mods ) {
		return '<span class="iss-ai-wf-line ' + mods + '"></span>';
	}

	function wfHead( mods ) {
		return '<span class="iss-ai-wf-head ' + mods + '"></span>';
	}

	function wfPhoto( mods ) {
		return '<span class="iss-ai-wf-photo ' + ( mods || '' ) + '"></span>';
	}

	// A call to action. It carries the recipe's own corner radius, which is
	// often the quickest way to tell two otherwise similar directions apart.
	function wfBtn( mods ) {
		return '<span class="iss-ai-wf-btn ' + ( mods || '' ) + '"></span>';
	}

	function wfRepeat( markup, times ) {
		var out = '';
		while ( times-- > 0 ) {
			out += markup;
		}
		return out;
	}

	// Every preview opens with the same header, so a card reads as a page
	// rather than as loose bars, and the tone, accent and corner radius are
	// all legible before the eye reaches the hero.
	function wfNav() {
		return '<span class="iss-ai-wf-nav">' +
			'<span class="iss-ai-wf-logo"></span>' +
			'<span class="iss-ai-wf-navlinks">' + wfRepeat( '<span></span>', 3 ) + '</span>' +
			wfBtn( 'is-sm' ) +
		'</span>';
	}

	var WF_HERO = {
		// Photo cover, headline sitting over it bottom-left.
		cover: function () {
			return wfPhoto( 'is-fill' ) +
				'<span class="iss-ai-wf-over">' + wfLine( 'is-eyebrow is-w25' ) + wfHead( 'is-w70' ) + wfLine( 'is-w45' ) + wfBtn( 'is-light' ) + '</span>';
		},
		// The same cover, but washed in the accent instead of plain black.
		duotone: function () {
			return wfPhoto( 'is-fill is-wash' ) +
				'<span class="iss-ai-wf-over">' + wfLine( 'is-eyebrow is-w25' ) + wfHead( 'is-xl is-w80' ) + wfBtn( 'is-light' ) + '</span>';
		},
		// Copy left, one tall photograph right.
		split: function () {
			return '<span class="iss-ai-wf-copy">' + wfLine( 'is-eyebrow is-w40' ) + wfHead( 'is-w90' ) + wfHead( 'is-w60' ) + wfLine( 'is-w80' ) + wfBtn( '' ) + '</span>' +
				wfPhoto( 'is-tall' );
		},
		// A split whose photograph breaks the section boundary.
		overlap: function () {
			return '<span class="iss-ai-wf-copy is-outdent">' + wfHead( 'is-w95' ) + wfHead( 'is-w60' ) + wfLine( 'is-w75' ) + wfBtn( '' ) + '</span>' +
				wfPhoto( 'is-tall is-break' );
		},
		// No photograph at all — oversized type on a flat ground.
		type: function () {
			return wfLine( 'is-eyebrow is-w25' ) + wfHead( 'is-xl is-w95' ) + wfHead( 'is-xl is-w70' ) + wfLine( 'is-w50' ) + wfBtn( '' ) +
				'<span class="iss-ai-wf-rule"></span>';
		},
		// Centred masthead with the photograph stacked below the type.
		stack: function () {
			return wfLine( 'is-eyebrow is-w20' ) + wfHead( 'is-w60' ) + wfLine( 'is-w45' ) + wfBtn( '' ) + wfPhoto( 'is-inset' );
		},
		// Centred and framed top and bottom by hairline rules.
		centered: function () {
			return '<span class="iss-ai-wf-rule"></span>' + wfLine( 'is-eyebrow is-w20' ) + wfHead( 'is-w55' ) + wfLine( 'is-w40' ) + wfBtn( '' ) +
				'<span class="iss-ai-wf-rule"></span>';
		},
		// The photography opens the page; the title follows underneath.
		gallery: function () {
			return '<span class="iss-ai-wf-gal">' + wfRepeat( wfPhoto( '' ), 4 ) + '</span>' + wfHead( 'is-w55' ) + wfLine( 'is-w40' );
		},
		// Narrow meta stack on the left, headline on the right.
		meta: function () {
			return '<span class="iss-ai-wf-metacol">' + wfRepeat( wfLine( 'is-eyebrow is-w80' ), 3 ) + '</span>' +
				'<span class="iss-ai-wf-copy">' + wfHead( 'is-w90' ) + wfHead( 'is-w60' ) + wfLine( 'is-w80' ) + wfBtn( '' ) + '</span>';
		},
		// A tile cluster of uneven weights rather than a banner.
		bento: function () {
			return '<span class="iss-ai-wf-tile is-photo"></span>' +
				'<span class="iss-ai-wf-stack">' +
					'<span class="iss-ai-wf-tile">' + wfHead( 'is-w70' ) + wfLine( 'is-w90' ) + wfBtn( '' ) + '</span>' +
					'<span class="iss-ai-wf-tile is-accent"><span class="iss-ai-wf-stat"></span></span>' +
				'</span>';
		},
		// Dense display headline, solid colour band, ticker row.
		poster: function () {
			return wfHead( 'is-xl is-w95' ) + wfHead( 'is-xl is-w75' ) +
				'<span class="iss-ai-wf-fill"></span>' +
				'<span class="iss-ai-wf-ticker">' + wfRepeat( '<span></span>', 5 ) + '</span>';
		}
	};

	var WF_BODY = {
		// Numbered editorial rows on hairline rules.
		rows: function () {
			return wfRepeat( '<span class="iss-ai-wf-row"><span class="iss-ai-wf-num"></span>' + wfLine( 'is-w70' ) + '</span>', 3 );
		},
		// Two-up media and text.
		pairs: function () {
			return wfPhoto( '' ) + '<span class="iss-ai-wf-copy">' + wfLine( 'is-w90' ) + wfLine( 'is-w60' ) + '</span>';
		},
		// Three equal filled cards.
		cards: function () {
			return wfRepeat( '<span class="iss-ai-wf-card">' + wfLine( 'is-w70' ) + wfLine( 'is-w90' ) + '</span>', 3 );
		},
		// Bento: one wide tile beside two narrower ones.
		tiles: function () {
			return '<span class="iss-ai-wf-tile is-wide">' + wfLine( 'is-w40' ) + '</span>' +
				'<span class="iss-ai-wf-tile">' + wfLine( 'is-w70' ) + '</span>' +
				'<span class="iss-ai-wf-tile is-accent"></span>';
		},
		// Four hard-bordered cells reading as one grid.
		grid4: function () {
			return wfRepeat( '<span class="iss-ai-wf-cell">' + wfLine( 'is-eyebrow is-w50' ) + wfLine( 'is-w80' ) + '</span>', 4 );
		},
		// Text columns divided by vertical rules — no boxes.
		ruled: function () {
			return wfRepeat( '<span class="iss-ai-wf-col">' + wfLine( 'is-w80' ) + wfLine( 'is-w60' ) + '</span>', 3 );
		}
	};

	// The "let AI choose" card has no layout to show, since its whole point is
	// that the layout isn't decided yet — so it greys one out behind the mark
	// rather than sitting empty beside a dozen drawn previews.
	var WF_AUTO = '<span class="iss-ai-wf iss-ai-wf--auto" aria-hidden="true">' +
		'<span class="iss-ai-wf-ghost">' +
			wfNav() +
			'<span class="iss-ai-wf-hero is-split">' + WF_HERO.split() + '</span>' +
			'<span class="iss-ai-wf-band is-cards">' + WF_BODY.cards() + '</span>' +
		'</span>' +
		'<span class="iss-ai-wf-spark">' +
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">' +
				'<path d="M11 3.5l2.1 5.4 5.4 2.1-5.4 2.1L11 18.5 8.9 13.1 3.5 11l5.4-2.1z"/>' +
				'<path d="M18 15l.9 2.1 2.1.9-2.1.9-.9 2.1-.9-2.1-2.1-.9 2.1-.9z"/>' +
			'</svg>' +
		'</span>' +
	'</span>';

	// Draw one recipe's layout system in its own tone, corner radius and
	// accent. Values come from the localized catalog, but are validated here
	// anyway since they land in a style attribute.
	function artPreview( preview ) {
		var hero = WF_HERO[ preview.hero ] ? preview.hero : 'split';
		var body = WF_BODY[ preview.body ] ? preview.body : 'pairs';
		var tone = /^[a-z]+$/.test( String( preview.tone || '' ) ) ? preview.tone : 'light';
		var vars = '';

		if ( /^#[0-9a-fA-F]{3,8}$/.test( String( preview.accent || '' ) ) ) {
			vars += '--iss-wf-accent:' + preview.accent + ';';
		}

		// The recipe's real corner radius, scaled to the wireframe.
		var radius = parseInt( preview.radius, 10 );
		if ( ! isNaN( radius ) ) {
			vars += '--iss-wf-radius:' + Math.min( 7, Math.round( radius / 3 ) ) + 'px;';
		}

		return '<span class="iss-ai-wf iss-ai-wf--' + tone + '" style="' + vars + '" aria-hidden="true">' +
			wfNav() +
			'<span class="iss-ai-wf-hero is-' + hero + '">' + WF_HERO[ hero ]() + '</span>' +
			'<span class="iss-ai-wf-band is-' + body + '">' + WF_BODY[ body ]() + '</span>' +
		'</span>';
	}

	function buildModal() {
		var ideas = '';
		$.each( config.ideas || [], function ( i, idea ) {
			// Back-compat: ideas may be plain strings or {icon,title,text}.
			if ( typeof idea === 'string' ) {
				ideas += '<button type="button" class="iss-ai-idea js-iss-ai-idea" data-text="' + esc( idea ) + '">' + esc( idea ) + '</button>';
				return;
			}
			ideas += '<button type="button" class="iss-ai-idea js-iss-ai-idea" data-text="' + esc( idea.text || '' ) + '">' +
				'<span class="iss-ai-idea-icon">' + ( IDEA_ICONS[ idea.icon ] || IDEA_ICONS.camera ) + '</span>' +
				'<span class="iss-ai-idea-body"><strong>' + esc( idea.title || '' ) + '</strong><em>' + esc( idea.text || '' ) + '</em></span>' +
			'</button>';
		} );

		// Design style / typography / palette chips ("" = let the AI decide).
		// Style chips preview their art direction through the label's own
		// typography; typography chips carry an "Ag" specimen in the actual
		// display font (loaded as a local webfont).
		var styleChips = '<button type="button" class="iss-ai-chip is-active" data-value="">' + esc( t.auto || '' ) + '</button>';
		$.each( config.styles || {}, function ( slug, label ) {
			styleChips += '<button type="button" class="iss-ai-chip iss-ai-chip--style iss-ai-style-' + esc( slug ) + '" data-value="' + esc( slug ) + '">' + esc( label ) + '</button>';
		} );

		var typographyChips = '<button type="button" class="iss-ai-chip is-active" data-value="">' + esc( t.auto || '' ) + '</button>';
		$.each( config.typographies || {}, function ( slug, label ) {
			var sample = 'serif-accent' === slug ? '<em>A</em>g' : 'Ag';
			typographyChips += '<button type="button" class="iss-ai-chip iss-ai-chip--type" data-value="' + esc( slug ) + '">' +
				'<span class="iss-ai-type-sample iss-ai-font-' + esc( slug ) + '" aria-hidden="true">' + sample + '</span>' +
				'<span>' + esc( label ) + '</span>' +
			'</button>';
		} );

		// Generation-mode cards. The Creative mode is Premium: it renders
		// locked and is unlocked by the quota response, whose `licensed` flag
		// the proxy decides after verifying the Inspiro license.
		var designLevelCards = '';
		$.each( config.design_levels || {}, function ( slug, level ) {
			var isPro    = !! level.pro;
			var isActive = 'standard' === slug;
			designLevelCards += '<button type="button" role="radio" aria-checked="' + ( isActive ? 'true' : 'false' ) + '"' +
				' class="iss-ai-level-card' +
				( isActive ? ' is-active' : '' ) +
				( isPro ? ' iss-ai-level-card--pro is-locked' : '' ) +
				'" data-value="' + esc( slug ) + '">' +
				'<span class="iss-ai-level-mark" aria-hidden="true"></span>' +
				'<span class="iss-ai-level-body">' +
					'<span class="iss-ai-level-name">' + esc( level.label || '' ) +
						( isPro ? '<span class="iss-ai-level-badge">' + esc( t.design_level_badge || '' ) + '</span>' : '' ) +
					'</span>' +
					'<span class="iss-ai-level-desc">' + esc( level.hint || '' ) + '</span>' +
				'</span>' +
			'</button>';
		} );

		var paletteChips = '<button type="button" class="iss-ai-chip is-active" data-value="">' + esc( t.auto || '' ) + '</button>';
		$.each( config.palettes || {}, function ( slug, palette ) {
			var swatches = '';
			$.each( palette.colors || [], function ( i, color ) {
				if ( /^#[0-9a-fA-F]{3,8}$/.test( color ) ) {
					swatches += '<span class="iss-ai-swatch" style="background:' + color + '"></span>';
				}
			} );
			paletteChips += '<button type="button" class="iss-ai-chip iss-ai-chip--palette" data-value="' + esc( slug ) + '">' + swatches + '<span>' + esc( palette.label ) + '</span></button>';
		} );


		// Art-direction cards (Premium): "let AI choose" first — the default and
		// what the proxy does on its own — then one card per recipe.
		var artCards = '';
		$.each( config.art_directions || {}, function ( slug, art ) {
			if ( ! artCards ) {
				artCards = '<button type="button" class="iss-ai-art-card is-active" data-value="" data-styles="">' +
					WF_AUTO +
					'<span class="iss-ai-art-name">' + esc( t.art_auto || '' ) + '</span>' +
					'<span class="iss-ai-art-desc">' + esc( t.art_auto_hint || '' ) + '</span>' +
				'</button>';
			}

			artCards += '<button type="button" class="iss-ai-art-card" data-value="' + esc( slug ) + '"' +
				' data-styles="' + esc( ( art.styles || [] ).join( ' ' ) ) + '">' +
				artPreview( art.preview || {} ) +
				'<span class="iss-ai-art-name">' + esc( art.label || '' ) + '</span>' +
				'<span class="iss-ai-art-desc">' + esc( art.hint || '' ) + '</span>' +
			'</button>';
		} );

		// The style chips stay on the describe step; the art-direction grid
		// gets its own step after it (see the art step below).
		var lookFields =
			'<div class="iss-ai-field-columns">' +
				'<div class="iss-ai-field">' +
					'<p class="iss-ai-field-label">' + esc( t.style_label || '' ) + '</p>' +
					'<div class="iss-ai-chips js-iss-ai-style">' + styleChips + '</div>' +
				'</div>' +
			'</div>' +
			'<p class="iss-ai-field-label">' + esc( t.typography_label || '' ) + '</p>' +
			'<div class="iss-ai-chips js-iss-ai-typography">' + typographyChips + '</div>' +
			'<p class="iss-ai-field-label">' + esc( t.palette_label || '' ) + '</p>' +
			'<div class="iss-ai-chips iss-ai-chips--grid js-iss-ai-palette">' + paletteChips + '</div>';

		var steps =
			'<ol class="iss-ai-steps js-iss-ai-steps">' +
				'<li data-step-num="1"><span class="iss-ai-step-num">1</span><span class="iss-ai-step-text"><strong>' + esc( t.step1 || '' ) + '</strong><em>' + esc( t.step1_hint || '' ) + '</em></span></li>' +
				'<li data-step-num="2"><span class="iss-ai-step-num">2</span><span class="iss-ai-step-text"><strong>' + esc( t.step2 || '' ) + '</strong><em>' + esc( t.step2_hint || '' ) + '</em></span></li>' +
				'<li data-step-num="3"><span class="iss-ai-step-num">3</span><span class="iss-ai-step-text"><strong>' + esc( t.step3 || '' ) + '</strong><em>' + esc( t.step3_hint || '' ) + '</em></span></li>' +
			'</ol>';

		var html =
		'<div class="iss-ai-overlay js-iss-ai-overlay">' +
			'<div class="iss-ai-modal iss-ai-modal--xl" role="dialog" aria-modal="true" aria-label="' + esc( t.title || '' ) + '">' +
				'<button type="button" class="iss-ai-close js-iss-ai-close" aria-label="' + esc( t.close || 'Close' ) + '">&times;</button>' +

				'<aside class="iss-ai-sidebar">' +
					'<h2>' + esc( t.title || '' ) + ' <span class="iss-ai-badge">' + esc( t.beta || 'Beta' ) + '</span></h2>' +
					'<p class="iss-ai-intro">' + esc( t.intro || '' ) + '</p>' +
					steps +
					'<div class="iss-ai-sidebar-footer">' +
						'<div class="iss-ai-upsell js-iss-ai-upsell" hidden>' +
							( config.is_premium_theme ?
								'<p>' + esc( t.activate_text || '' ) + '</p>' +
								'<a href="' + esc( config.license_url || '#' ) + '" class="iss-ai-upsell-btn">' + esc( t.activate_button || '' ) + '</a>'
							:
								'<p>' + esc( t.upsell_text || '' ) + '</p>' +
								'<a href="' + esc( config.upgrade_url || '#' ) + '" target="_blank" rel="noopener" class="iss-ai-upsell-btn">' + esc( t.upsell_button || '' ) + '</a>'
							) +
						'</div>' +
						'<span class="iss-ai-connected-email js-iss-ai-connected-email" hidden></span>' +
						'<span class="iss-ai-quota js-iss-ai-quota">' + esc( t.quota_loading || '' ) + '</span>' +
						'<button type="button" class="iss-ai-link-btn iss-ai-disconnect js-iss-ai-disconnect" hidden>' + esc( t.disconnect || '' ) + '</button>' +
					'</div>' +
				'</aside>' +

				'<div class="iss-ai-main">' +
					'<div class="iss-ai-body">' +

						// Step: connect — email registration with the WPZOOM AI
						// server, required before any free generation. Two
						// modes: email entry, then 6-digit code verification.
						'<div class="iss-ai-step iss-ai-step-connect" data-step="connect">' +
							'<div class="iss-ai-connect-card">' +
								'<div class="js-iss-ai-connect-mode-email">' +
									'<h3>' + esc( t.connect_title || '' ) + '</h3>' +
									'<p class="iss-ai-connect-text">' + esc( t.connect_text || '' ) + '</p>' +
									'<div class="iss-ai-connect-row">' +
										'<input type="email" class="iss-ai-connect-email js-iss-ai-connect-email" placeholder="' + esc( t.connect_email_ph || '' ) + '" autocomplete="email">' +
										'<button type="button" class="button button-primary js-iss-ai-connect">' + esc( t.connect_button || '' ) + '</button>' +
									'</div>' +
									'<label class="iss-ai-connect-consent"><input type="checkbox" class="js-iss-ai-connect-consent" checked> <span>' + esc( t.connect_consent || '' ) + '</span></label>' +
									'<p class="iss-ai-connect-privacy"><a href="https://www.wpzoom.com/privacy-policy/" target="_blank" rel="noopener">' + esc( t.connect_privacy || '' ) + '</a></p>' +
								'</div>' +
								'<div class="js-iss-ai-connect-mode-verify" hidden>' +
									'<h3>' + esc( t.verify_title || '' ) + '</h3>' +
									'<p class="iss-ai-connect-text js-iss-ai-verify-text"></p>' +
									'<div class="iss-ai-connect-row">' +
										'<input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" class="iss-ai-connect-email iss-ai-verify-code js-iss-ai-verify-code" placeholder="' + esc( t.verify_code_ph || '' ) + '" autocomplete="one-time-code">' +
										'<button type="button" class="button button-primary js-iss-ai-verify">' + esc( t.verify_button || '' ) + '</button>' +
									'</div>' +
									'<p class="iss-ai-verify-links">' +
										'<button type="button" class="iss-ai-link-btn js-iss-ai-resend">' + esc( t.resend_code || '' ) + '</button>' +
										'<button type="button" class="iss-ai-link-btn js-iss-ai-change-email">' + esc( t.change_email || '' ) + '</button>' +
									'</p>' +
									'<p class="iss-ai-verify-note js-iss-ai-verify-note" hidden></p>' +
								'</div>' +
								'<p class="iss-ai-error js-iss-ai-connect-error" hidden></p>' +
							'</div>' +
						'</div>' +

						// Step: input.
						'<div class="iss-ai-step iss-ai-step-input is-active" data-step="input">' +
							'<div class="iss-ai-replace-notice js-iss-ai-replace-notice" hidden>' +
								'<strong>' + esc( t.replace_title || '' ) + '</strong>' +
								'<p class="js-iss-ai-replace-text"></p>' +
								'<label class="iss-ai-replace-check"><input type="checkbox" class="js-iss-ai-replace" checked> <span>' + esc( t.replace_checkbox || '' ) + '</span></label>' +
								'<p class="iss-ai-replace-hint">' + esc( t.replace_keep_hint || '' ) + '</p>' +
								'<div class="iss-ai-replace-actions">' +
									'<button type="button" class="iss-ai-mini-btn iss-ai-mini-btn--danger js-iss-ai-delete">' + esc( t.delete_now || '' ) + '</button>' +
									'<button type="button" class="iss-ai-mini-btn js-iss-ai-edit-css">' + esc( t.edit_css_link || '' ) + '</button>' +
									'<button type="button" class="iss-ai-mini-btn js-iss-ai-add-page' + ( pageToolsAvailable ? '' : ' is-locked' ) + '">' + ( pageToolsAvailable ? '' : '&#128274; ' ) + esc( t.add_page_link || '' ) + '</button>' +
									'<button type="button" class="iss-ai-mini-btn js-iss-ai-regen-page' + ( pageToolsAvailable ? '' : ' is-locked' ) + '">' + ( pageToolsAvailable ? '' : '&#128274; ' ) + esc( t.regen_page_link || '' ) + '</button>' +
								'</div>' +
								'<p class="iss-ai-premium-upsell js-iss-ai-page-upsell" hidden>' +
									'<span class="js-iss-ai-page-upsell-text"></span> ' +
									'<a class="js-iss-ai-page-upsell-link" href="#" target="_blank" rel="noopener"></a>' +
								'</p>' +
							'</div>' +
							'<p class="iss-ai-delete-result js-iss-ai-delete-result" hidden></p>' +

							'<p class="iss-ai-field-label">' + esc( t.describe_label || '' ) + '</p>' +
							'<textarea class="iss-ai-textarea js-iss-ai-description" rows="4" maxlength="1200" placeholder="' + esc( t.placeholder || '' ) + '"></textarea>' +
							'<div class="iss-ai-enhance-row">' +
								'<button type="button" class="iss-ai-ideas-toggle js-iss-ai-ideas-toggle" aria-expanded="false">&#128161; ' + esc( t.ideas_show || '' ) + '</button>' +
								'<span class="iss-ai-enhance-spacer"></span>' +
								'<button type="button" class="iss-ai-enhance-undo js-iss-ai-enhance-undo" hidden>' + esc( t.undo || '' ) + '</button>' +
								'<button type="button" class="iss-ai-enhance js-iss-ai-enhance">&#10024; ' + esc( t.enhance || '' ) + '</button>' +
							'</div>' +
							'<div class="iss-ai-ideas js-iss-ai-ideas" hidden>' + ideas + '</div>' +

							lookFields +

							// Generation mode comes last: it is the one setting
							// that trades time for design variety, so it reads as
							// the final call before generating.
							( designLevelCards ?
								'<p class="iss-ai-field-label">' + esc( t.design_level_label || '' ) + '</p>' +
								'<div class="iss-ai-level-cards js-iss-ai-design-level" role="radiogroup" aria-label="' + esc( t.design_level_label || '' ) + '">' + designLevelCards + '</div>' +
								'<p class="iss-ai-level-lock js-iss-ai-level-lock" hidden>' +
									'<a href="' + esc( ( config.is_premium_theme ? ( config.license_url || '#' ) : ( config.upgrade_url || '#' ) ) ) + '"' +
									( config.is_premium_theme ? '' : ' target="_blank" rel="noopener"' ) + '>' +
									esc( t.design_level_lock || '' ) +
								'</a></p>'
							: '' ) +

							'<p class="iss-ai-error js-iss-ai-input-error" hidden></p>' +
						'</div>' +


						// Step: art direction (Premium). Skipped entirely unless the
						// site is licensed AND on the Creative level — those are the
						// only runs the proxy actually applies a recipe to.
						'<div class="iss-ai-step iss-ai-step-art" data-step="art">' +
							'<h3 class="iss-ai-plan-title">' + esc( t.art_title || '' ) + '</h3>' +
							'<p class="iss-ai-plan-hint">' + esc( t.art_hint || '' ) + '</p>' +
							'<div class="iss-ai-art-grid js-iss-ai-art-grid">' + artCards + '</div>' +
						'</div>' +

						// Step: plan review.
						'<div class="iss-ai-step iss-ai-step-plan" data-step="plan">' +
							'<h3 class="iss-ai-plan-title">' + esc( t.plan_title || '' ) + '</h3>' +
							'<p class="iss-ai-plan-hint">' + esc( t.plan_hint || '' ) + '</p>' +
							'<ul class="iss-ai-plan-list js-iss-ai-plan-list"></ul>' +
							'<div class="iss-ai-plan-add">' +
								'<input type="text" class="iss-ai-plan-add-input js-iss-ai-plan-add-input" maxlength="60" placeholder="' + esc( t.plan_add_ph || '' ) + '">' +
								'<button type="button" class="button js-iss-ai-plan-add">' + esc( t.plan_add || '' ) + '</button>' +
							'</div>' +
							'<p class="iss-ai-error js-iss-ai-plan-error" hidden></p>' +
						'</div>' +

						// Step: progress.
						'<div class="iss-ai-step iss-ai-step-progress" data-step="progress">' +
							'<div class="iss-ai-spinner" aria-hidden="true"></div>' +
							'<p class="iss-ai-progress-label js-iss-ai-progress-label"></p>' +
							'<div class="iss-ai-progress-bar"><span class="js-iss-ai-progress-fill"></span></div>' +
							'<ul class="iss-ai-progress-pages js-iss-ai-progress-pages"></ul>' +
							'<p class="iss-ai-hint">' + esc( t.progress_hint || '' ) + '</p>' +
						'</div>' +

						// Step: success.
						'<div class="iss-ai-step iss-ai-step-success" data-step="success">' +
							'<div class="iss-ai-success-check" aria-hidden="true">' +
								'<svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
									'<path d="M5 12.5L9.5 17L19 7" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>' +
								'</svg>' +
							'</div>' +
							'<h3 class="js-iss-ai-success-title">' + esc( t.success_title || '' ) + '</h3>' +
							'<p>' + esc( t.success_text || '' ) + '</p>' +
							'<ul class="iss-ai-page-list js-iss-ai-page-list"></ul>' +
							'<div class="iss-ai-actions">' +
								'<a href="' + esc( config.site_url || '#' ) + '" target="_blank" rel="noopener" class="button button-primary js-iss-ai-view-site">' + esc( t.view_site || '' ) + '</a>' +
								'<a href="' + esc( config.pages_url || '#' ) + '" class="button">' + esc( t.edit_pages || '' ) + '</a>' +
							'</div>' +

							// Survey. Every answer is optional — Send stays
							// disabled until at least one of them is given.
							'<div class="iss-ai-feedback js-iss-ai-feedback">' +
								'<button type="button" class="iss-ai-feedback__dismiss js-iss-ai-feedback-skip" aria-label="' + esc( t.feedback_close || '' ) + '">&times;</button>' +
								'<h4>' + esc( t.feedback_title || '' ) + '</h4>' +
								'<p class="iss-ai-feedback__hint">' + esc( t.feedback_hint || '' ) + '</p>' +
								'<div class="iss-ai-feedback__form js-iss-ai-feedback-form">' +
									'<div class="iss-ai-feedback__field">' +
										'<span class="iss-ai-feedback__label">' + esc( t.feedback_rating || '' ) + '</span>' +
										'<div class="iss-ai-stars js-iss-ai-stars">' + feedbackStars() + '</div>' +
									'</div>' +
									'<div class="iss-ai-feedback__field">' +
										'<span class="iss-ai-feedback__label">' + esc( t.feedback_keep || '' ) + '</span>' +
										'<div class="iss-ai-feedback__choices">' + feedbackChoices() + '</div>' +
									'</div>' +
									'<div class="iss-ai-feedback__row iss-ai-feedback__row--split">' +
										'<div class="iss-ai-feedback__field">' +
											'<span class="iss-ai-feedback__label">' + esc( t.feedback_missing || '' ) + '</span>' +
											'<textarea class="iss-ai-feedback__text js-iss-ai-feedback-missing" rows="2" placeholder="' + esc( t.feedback_missing_ph || '' ) + '"></textarea>' +
										'</div>' +
										'<div class="iss-ai-feedback__field">' +
											'<span class="iss-ai-feedback__label">' + esc( t.feedback_comment || '' ) + '</span>' +
											'<textarea class="iss-ai-feedback__text js-iss-ai-feedback-comment" rows="2" placeholder="' + esc( t.feedback_comment_ph || '' ) + '"></textarea>' +
										'</div>' +
									'</div>' +
									'<div class="iss-ai-feedback__actions">' +
										'<button type="button" class="button button-primary js-iss-ai-feedback-send" disabled>' + esc( t.feedback_send || '' ) + '</button>' +
										'<button type="button" class="button-link iss-ai-feedback__skip js-iss-ai-feedback-skip">' + esc( t.feedback_skip || '' ) + '</button>' +
									'</div>' +
								'</div>' +
								'<p class="iss-ai-feedback__thanks js-iss-ai-feedback-thanks" hidden>' + esc( t.feedback_thanks || '' ) + '</p>' +
							'</div>' +
						'</div>' +

						// Step: CSS editor for the active demo.
						'<div class="iss-ai-step iss-ai-step-css" data-step="css">' +
							'<h3>' + esc( t.edit_css_title || '' ) + '</h3>' +
							'<p class="iss-ai-css-intro js-iss-ai-css-intro"></p>' +
							'<textarea class="iss-ai-css-editor js-iss-ai-css-editor" spellcheck="false" rows="18"></textarea>' +
							'<p class="iss-ai-css-result js-iss-ai-css-result" hidden></p>' +
							'<div class="iss-ai-actions">' +
								'<button type="button" class="button button-primary js-iss-ai-css-save">' + esc( t.edit_css_save || '' ) + '</button>' +
								'<button type="button" class="button js-iss-ai-css-back">' + esc( t.back || '' ) + '</button>' +
							'</div>' +
						'</div>' +

						// Step: add / regenerate a single page (Premium).
						'<div class="iss-ai-step iss-ai-step-pagetools" data-step="pagetools">' +
							'<h3 class="js-iss-ai-pt-title"></h3>' +
							'<p class="iss-ai-css-intro js-iss-ai-pt-intro"></p>' +
							'<div class="js-iss-ai-pt-form">' +
								'<div class="js-iss-ai-pt-add">' +
									'<p class="iss-ai-field-label">' + esc( t.add_page_label || '' ) + '</p>' +
									'<input type="text" class="iss-ai-input js-iss-ai-pt-page-title" maxlength="80" placeholder="' + esc( t.add_page_ph || '' ) + '" />' +
									'<p class="iss-ai-field-label">' + esc( t.add_page_details || '' ) + '</p>' +
									'<textarea class="iss-ai-textarea js-iss-ai-pt-details" rows="3" maxlength="500"></textarea>' +
								'</div>' +
								'<div class="js-iss-ai-pt-regen">' +
									'<p class="iss-ai-field-label">' + esc( t.regen_label || '' ) + '</p>' +
									'<select class="iss-ai-input js-iss-ai-pt-page-select"></select>' +
									'<p class="iss-ai-field-label">' + esc( t.regen_mode_label || '' ) + '</p>' +
									'<div class="iss-ai-mode-choice js-iss-ai-pt-mode">' +
										'<label class="iss-ai-mode-option is-active"><input type="radio" name="iss_ai_regen_mode" value="replace" checked> ' +
											'<strong>' + esc( t.regen_mode_replace || '' ) + '</strong><span>' + esc( t.regen_mode_replace_hint || '' ) + '</span></label>' +
										'<label class="iss-ai-mode-option"><input type="radio" name="iss_ai_regen_mode" value="append"> ' +
											'<strong>' + esc( t.regen_mode_append || '' ) + '</strong><span>' + esc( t.regen_mode_append_hint || '' ) + '</span></label>' +
									'</div>' +
									'<p class="iss-ai-field-label js-iss-ai-pt-feedback-label">' + esc( t.regen_feedback || '' ) + '</p>' +
									'<textarea class="iss-ai-textarea js-iss-ai-pt-feedback" rows="3" maxlength="500"></textarea>' +
								'</div>' +
							'</div>' +
							'<div class="iss-ai-pt-working js-iss-ai-pt-working" hidden>' +
								'<span class="spinner is-active"></span> ' + esc( t.page_working || '' ) +
							'</div>' +
							'<p class="iss-ai-css-result js-iss-ai-pt-result" hidden></p>' +
							'<div class="iss-ai-actions">' +
								'<button type="button" class="button button-primary js-iss-ai-pt-go"></button>' +
								'<a class="button js-iss-ai-pt-view" target="_blank" rel="noopener" hidden>' + esc( t.view_page || '' ) + '</a>' +
								'<a class="button js-iss-ai-pt-edit" hidden>' + esc( t.edit_page || '' ) + '</a>' +
								'<button type="button" class="button js-iss-ai-pt-back">' + esc( t.back || '' ) + '</button>' +
							'</div>' +
						'</div>' +

						// Step: error.
						'<div class="iss-ai-step iss-ai-step-error" data-step="error">' +
							'<h3>' + esc( t.error_title || '' ) + '</h3>' +
							'<p class="js-iss-ai-error-message"></p>' +
							'<p class="iss-ai-error-detail js-iss-ai-error-detail" hidden></p>' +
							'<div class="iss-ai-actions">' +
								'<button type="button" class="button button-primary js-iss-ai-retry">' + esc( t.try_again || '' ) + '</button>' +
								'<button type="button" class="button js-iss-ai-close">' + esc( t.close || '' ) + '</button>' +
							'</div>' +
						'</div>' +

					'</div>' +
					'<div class="iss-ai-footer js-iss-ai-footer">' +
						'<button type="button" class="button iss-ai-back-btn js-iss-ai-art-back" style="display:none">' + esc( t.back || '' ) + '</button>' +
						'<button type="button" class="button button-primary iss-ai-generate-btn js-iss-ai-generate">' + esc( t['continue'] || '' ) + '</button>' +
						'<button type="button" class="button button-primary iss-ai-generate-btn js-iss-ai-art-next" style="display:none">' + esc( t['continue'] || '' ) + '</button>' +
						'<button type="button" class="button button-primary iss-ai-generate-btn js-iss-ai-build" style="display:none">' + esc( t.generate || '' ) + '</button>' +
					'</div>' +
				'</div>' +
			'</div>' +
		'</div>';

		$root.html( html );

		// Portal the modal to <body>: the demo page wraps its content in
		// <div class="... plugins">, which pulls wp-admin's list-table CSS
		// (e.g. ".plugins p { margin: 0 4px }") onto everything inside.
		// Outside that subtree, page-context admin rules can't reach us.
		// Delegated handlers are bound on $root, so they move with it.
		$root.appendTo( document.body );

		built = true;
	}

	function showStep( step ) {
		$root.find( '.iss-ai-step' ).removeClass( 'is-active' );
		$root.find( '.iss-ai-step[data-step="' + step + '"]' ).addClass( 'is-active' );
		$root.find( '.js-iss-ai-footer' ).toggle( 'input' === step || 'art' === step || 'plan' === step );
		$root.find( '.js-iss-ai-generate' ).toggle( 'input' === step );
		$root.find( '.js-iss-ai-art-next' ).toggle( 'art' === step );
		$root.find( '.js-iss-ai-art-back' ).toggle( 'art' === step );
		$root.find( '.js-iss-ai-build' ).toggle( 'plan' === step );

		// Sidebar step indicator: everything before the build → 1, progress → 2,
		// done → 3. The art step is a detour inside step 1, not a step of its own.
		var current = ( 'progress' === step ) ? 2 : ( ( 'success' === step || 'error' === step ) ? 3 : 1 );
		$root.find( '.js-iss-ai-steps li' ).each( function () {
			var num = parseInt( $( this ).attr( 'data-step-num' ), 10 );
			$( this )
				.toggleClass( 'is-current', num === current )
				.toggleClass( 'is-done', num < current );
		} );
	}

	/* -----------------------------------------------------------------
	 * Plan review step
	 * -------------------------------------------------------------- */

	var MAX_REVIEW_PAGES = 5;

	function renderPlanReview() {
		var $list = $root.find( '.js-iss-ai-plan-list' ).empty();

		$.each( planState.pages || [], function ( i, page ) {
			$list.append( planRow( page.slug, page.title ) );
		} );

		planReviewState();
	}

	function planRow( slug, title ) {
		return $( '<li class="iss-ai-plan-row">' )
			.attr( 'data-slug', slug || '' )
			.append( $( '<input type="text" class="iss-ai-plan-input" maxlength="60">' ).val( title ) )
			.append(
				$( '<button type="button" class="iss-ai-plan-remove js-iss-ai-plan-remove">&times;</button>' )
					.attr( 'aria-label', t.plan_remove || '' )
			);
	}

	// Enforce 1..MAX pages: hide the add row at the cap, disable build at 0.
	function planReviewState() {
		var count = $root.find( '.iss-ai-plan-row' ).length;
		$root.find( '.iss-ai-plan-add' ).toggle( count < MAX_REVIEW_PAGES );
		$root.find( '.js-iss-ai-build' ).prop( 'disabled', 0 === count );

		var $error = $root.find( '.js-iss-ai-plan-error' );
		if ( 0 === count ) {
			$error.text( t.plan_min || '' ).removeAttr( 'hidden' );
		} else {
			$error.attr( 'hidden', 'hidden' );
		}
	}

	function collectPlanPages() {
		var pages = [];
		$root.find( '.iss-ai-plan-row' ).each( function () {
			var title = $.trim( $( this ).find( '.iss-ai-plan-input' ).val() || '' );
			var slug  = $( this ).attr( 'data-slug' ) || '';
			if ( title ) {
				pages.push( {
					slug:  slug,
					title: title,
					brief: suggestBriefs[ slug ] || ''
				} );
			}
		} );
		return pages;
	}

	/* -----------------------------------------------------------------
	 * Live build checklist (progress step)
	 * -------------------------------------------------------------- */

	function renderProgressPages() {
		var $list = $root.find( '.js-iss-ai-progress-pages' ).empty();

		$list.append( $( '<li>' ).attr( 'data-item', 'plan' ).addClass( 'is-done' ).text( t.plan_item || '' ) );
		if ( planState.portfolio && planState.portfolio.needed ) {
			$list.append( $( '<li>' ).attr( 'data-item', 'portfolio' ).text( t.portfolio_item || '' ) );
		}
		if ( planState.forms && planState.forms.needed && ! planState.forms.plugin_active ) {
			$list.append( $( '<li>' ).attr( 'data-item', 'forms' ).text( t.forms_item || '' ) );
		}
		$.each( planState.pages || [], function ( i, page ) {
			$list.append( $( '<li>' ).attr( 'data-item', 'page-' + i ).text( page.title ) );
		} );
		$list.append( $( '<li>' ).attr( 'data-item', 'finalize' ).text( t.finalize_item || '' ) );
	}

	function markProgressItem( item, state ) {
		$root.find( '.js-iss-ai-progress-pages li[data-item="' + item + '"]' )
			.removeClass( 'is-active is-done' )
			.addClass( state );
	}

	function openModal() {
		if ( ! built ) {
			buildModal();
		}
		showStep( 'input' );
		$root.removeAttr( 'hidden' ).addClass( 'is-open' );
		$( 'body' ).addClass( 'iss-ai-open' );
		refreshQuota();
	}

	function closeModal() {
		if ( running ) {
			return; // Don't allow closing mid-generation.
		}
		$root.removeClass( 'is-open' ).attr( 'hidden', 'hidden' );
		$( 'body' ).removeClass( 'iss-ai-open' );
	}

	/* -----------------------------------------------------------------
	 * Quota
	 * -------------------------------------------------------------- */

	// Set once the user picks a generation mode themselves, so a later quota
	// refresh never overrides their choice with the licensed default.
	var designLevelTouched = false;

	// Single-select across the mode cards, keeping aria-checked in step with
	// the visual state for the radiogroup.
	function selectDesignLevel( $card ) {
		if ( ! $card || ! $card.length ) {
			return;
		}
		$card.closest( '.iss-ai-level-cards' ).find( '.iss-ai-level-card' )
			.removeClass( 'is-active' )
			.attr( 'aria-checked', 'false' );
		$card.addClass( 'is-active' ).attr( 'aria-checked', 'true' );
	}

	// Reflect the server's licensing decision on the generation-mode cards.
	// Purely cosmetic: the proxy re-verifies the license on every task call,
	// so unlocking this by hand still yields standard output.
	function renderDesignLevels() {
		var $group = $root.find( '.js-iss-ai-design-level' );
		if ( ! $group.length ) {
			return;
		}

		var licensed = !! ( quota && quota.licensed );
		var $pro     = $group.find( '.iss-ai-level-card--pro' );

		$pro.toggleClass( 'is-locked', ! licensed );
		$root.find( '.js-iss-ai-level-lock' ).attr( 'hidden', licensed ? 'hidden' : null );

		if ( licensed && ! designLevelTouched ) {
			// Licence holders get the better output unless they say otherwise.
			selectDesignLevel( $pro.first() );
		} else if ( ! licensed && $pro.hasClass( 'is-active' ) ) {
			selectDesignLevel( $group.find( '.iss-ai-level-card' ).not( $pro ).first() );
		}
	}

	/* -----------------------------------------------------------------
	 * Art direction (Premium)
	 * -------------------------------------------------------------- */

	// Single-select across the art-direction cards.
	function selectArtDirection( $card ) {
		if ( ! $card || ! $card.length ) {
			return;
		}
		$card.closest( '.iss-ai-art-grid' ).find( '.iss-ai-art-card' ).removeClass( 'is-active' );
		$card.addClass( 'is-active' );
	}

	function currentDesignLevel() {
		return $root.find( '.js-iss-ai-design-level .iss-ai-level-card.is-active' ).attr( 'data-value' ) || 'standard';
	}

	function currentArtDirection() {
		return $root.find( '.js-iss-ai-art-grid .iss-ai-art-card.is-active' ).attr( 'data-value' ) || '';
	}

	// Whether the picker gets a page of its own between describing the site
	// and reviewing the pages. Premium-only, and only for Creative runs — the
	// proxy ignores a recipe on any other request, so showing the step there
	// would offer a choice that gets discarded.
	function hasArtStep() {
		return !! $root.find( '.js-iss-ai-art-grid .iss-ai-art-card' ).length &&
			!! ( quota && quota.licensed ) &&
			'pro' === currentDesignLevel();
	}

	// A pin can't outlive the license that allows it: if the server says the
	// site isn't licensed, drop back to "let AI choose" so what the modal
	// shows matches what the proxy will really do.
	function renderArtDirection() {
		if ( ! ( quota && quota.licensed ) && currentArtDirection() ) {
			selectArtDirection( $root.find( '.js-iss-ai-art-grid .iss-ai-art-card' ).first() );
		}
	}

	// De-emphasise the directions that don't suit the chosen design style.
	// They stay selectable — the affinity is a hint, not a rule.
	function syncArtDirectionStyles() {
		var style = $root.find( '.js-iss-ai-style .iss-ai-chip.is-active' ).attr( 'data-value' ) || '';

		$root.find( '.js-iss-ai-art-grid .iss-ai-art-card' ).each( function () {
			var $card  = $( this );
			var styles = $card.attr( 'data-styles' ) || '';
			$card.toggleClass( 'is-off-style', !! style && !! styles && -1 === $.inArray( style, styles.split( ' ' ) ) );
		} );
	}

	function refreshQuota() {
		var $quota = $root.find( '.js-iss-ai-quota' );
		$quota.text( t.quota_loading || '' );

		ajax( 'inspiro_starter_sites_ai_quota', {}, 20000 ).done( function ( response ) {
			if ( ! response || ! response.success || ! response.data ) {
				$quota.text( '' );
				return;
			}
			applyQuotaResponse( response.data );
		} ).fail( function () {
			$quota.text( '' );
		} );
	}

	// Shared for the quota check and the connect call (both return the same
	// shape). Not connected yet → swap the generator for the email step.
	function applyQuotaResponse( data ) {
		quota     = data;
		connected = !! data.connected;

		// The regenerate picker's page list is refreshed with every state
		// fetch (the modal re-fetches on open), so a demo generated in this
		// same session is available without a page reload.
		if ( data.demo_pages ) {
			config.demo_pages = data.demo_pages;
		}

		renderQuota();
		renderDesignLevels();
		renderArtDirection();
		renderReplaceNotice( data.previous, data.classic );

		if ( ! connected ) {
			showStep( 'connect' );
		}
	}

	// Status chip in the importer-page hero: shows the existing AI demo and
	// opens the modal (where manage/delete lives) when clicked.
	function renderHeroExisting( previous ) {
		var $chip = $( '.js-iss-ai-hero-existing' );
		if ( ! $chip.length ) {
			return;
		}
		if ( previous && previous.page_count ) {
			$chip.find( '.js-iss-ai-hero-existing-title' ).text( previous.site_title || t.demo_active || '' );
			$chip.removeAttr( 'hidden' );
		} else {
			$chip.attr( 'hidden', 'hidden' );
		}
	}

	// Prominent warning when a previous demo exists — an AI-generated one OR
	// a classic starter site imported via this plugin or the premium theme's
	// importer. Either will be deleted (edits included) unless unchecked.
	function renderReplaceNotice( previous, classic ) {
		var $notice = $root.find( '.js-iss-ai-replace-notice' );

		renderHeroExisting( previous );

		var title = '';
		var text  = '';

		if ( previous && previous.page_count ) {
			title = t.replace_title || '';
			text  = previous.site_title
				? sprintf( t.replace_notice || '', previous.site_title, previous.page_count )
				: sprintf( t.replace_notice_unnamed || '', previous.page_count );
		} else if ( classic ) {
			title = t.replace_title_classic || '';
			text  = classic.title
				? sprintf( t.replace_notice_classic || '', classic.title )
				: ( t.replace_notice_classic_unnamed || '' );
		}

		if ( ! text ) {
			$notice.attr( 'hidden', 'hidden' );
			return;
		}

		$notice.find( 'strong' ).first().text( title );
		$notice.find( '.js-iss-ai-replace-text' ).text( text );
		// Both actions operate on an AI demo — hide them when the warning is
		// about a classic starter-site import instead.
		$notice.find( '.iss-ai-replace-actions' ).toggle( !! ( previous && previous.page_count ) );
		$notice.removeAttr( 'hidden' );
	}

	function renderQuota() {
		var $quota      = $root.find( '.js-iss-ai-quota' );
		var $email      = $root.find( '.js-iss-ai-connected-email' );
		var $disconnect = $root.find( '.js-iss-ai-disconnect' );

		if ( ! quota || false === quota.connected ) {
			$quota.text( '' );
			$email.attr( 'hidden', 'hidden' ).text( '' );
			$disconnect.attr( 'hidden', 'hidden' );
			return;
		}

		if ( quota.email ) {
			$email.text( sprintf( t.connected_as || '%s', quota.email ) ).removeAttr( 'hidden' );
			$disconnect.removeAttr( 'hidden' );
		} else {
			$email.attr( 'hidden', 'hidden' ).text( '' );
			$disconnect.attr( 'hidden', 'hidden' );
		}

		var $upsell = $root.find( '.js-iss-ai-upsell' );

		if ( quota.remaining <= 0 ) {
			$quota.text( t.quota_none || '' ).addClass( 'is-exhausted' );
			$root.find( '.js-iss-ai-generate' ).prop( 'disabled', true );
			// Free users get the premium upsell; verified license holders
			// just see the plain limit message.
			if ( quota.licensed ) {
				$upsell.attr( 'hidden', 'hidden' );
			} else {
				$upsell.removeAttr( 'hidden' );
			}
		} else {
			$quota.text( sprintf( t.quota_left || '%1$s / %2$s', quota.used, quota.limit ) ).removeClass( 'is-exhausted' );
			$root.find( '.js-iss-ai-generate' ).prop( 'disabled', false );
			$upsell.attr( 'hidden', 'hidden' );
		}
	}

	/* -----------------------------------------------------------------
	 * Generation pipeline
	 * -------------------------------------------------------------- */

	function setProgress( label, fraction ) {
		$root.find( '.js-iss-ai-progress-label' ).text( label );
		$root.find( '.js-iss-ai-progress-fill' ).css( 'width', Math.round( fraction * 100 ) + '%' );
	}

	function failWith( message, detail ) {
		running = false;
		$root.find( '.js-iss-ai-error-message' ).text( message || t.error_generic || '' );

		var $detail = $root.find( '.js-iss-ai-error-detail' );
		if ( detail ) {
			$detail.text( detail ).removeAttr( 'hidden' );
		} else {
			$detail.attr( 'hidden', 'hidden' );
		}

		showStep( 'error' );
	}

	function responseMessage( response ) {
		if ( response && response.data && response.data.message ) {
			return response.data.message;
		}
		return t.error_generic || '';
	}

	function responseDetail( response ) {
		return ( response && response.data && response.data.detail ) ? String( response.data.detail ) : '';
	}

	// Step 1 → 2: a small, fast AI call proposes pages for review BEFORE any
	// expensive generation (no quota consumed). Falls back to a generic page
	// list if the suggestion service hiccups.
	var suggestBriefs = {};

	// The description gate lives on the describe step, but the art step now
	// sits between it and the suggestion call — so both continue buttons run
	// it, and neither can leave the description behind.
	function validateDescription() {
		var description = $.trim( $root.find( '.js-iss-ai-description' ).val() || '' );
		var $error      = $root.find( '.js-iss-ai-input-error' );

		if ( description.length < 12 ) {
			$error.text( t.too_short || '' ).removeAttr( 'hidden' );
			return '';
		}

		$error.attr( 'hidden', 'hidden' );
		return description;
	}

	// $button is whichever continue the user actually clicked, so the
	// "Suggesting…" label lands on a button they can see.
	function suggestPages( $button ) {
		var description = validateDescription();
		if ( ! description ) {
			return;
		}

		$button.prop( 'disabled', true ).text( t.suggesting || '' );

		function proceed( pages ) {
			$button.prop( 'disabled', false ).text( t['continue'] || '' );
			suggestBriefs = {};
			$.each( pages, function ( i, page ) {
				if ( page.slug && page.brief ) {
					suggestBriefs[ page.slug ] = page.brief;
				}
			} );
			planState = { pages: pages };
			renderPlanReview();
			showStep( 'plan' );
		}

		ajax( 'inspiro_starter_sites_ai_suggest_pages', { description: description }, 90000 )
			.done( function ( response ) {
				if ( response && response.success && response.data && response.data.pages && response.data.pages.length ) {
					proceed( response.data.pages );
				} else {
					// Suggestion hiccup — a sensible default list still lets
					// the user shape the site before generating.
					proceed( config.fallback_pages || [] );
				}
			} )
			.fail( function () {
				proceed( config.fallback_pages || [] );
			} );
	}

	// Step 2 → 3: the real generation, constrained to the approved pages.
	function startGeneration() {
		var pages = collectPlanPages();
		if ( ! pages.length ) {
			planReviewState();
			return;
		}

		var description = $.trim( $root.find( '.js-iss-ai-description' ).val() || '' );
		var level       = currentDesignLevel();
		var $replace    = $root.find( '.js-iss-ai-replace' );
		var replace     = ( ! $replace.length || $replace.closest( '.js-iss-ai-replace-notice' ).attr( 'hidden' ) || $replace.is( ':checked' ) ) ? '1' : '0';

		running = true;
		showStep( 'progress' );
		// Clear the previous run's checklist — it would otherwise linger
		// through the whole plan-design phase on a repeat generation.
		$root.find( '.js-iss-ai-progress-pages' ).empty();
		setProgress( t.step_plan || '', 0.08 );

		ajax( 'inspiro_starter_sites_ai_generate', {
			description: description,
			replace:     replace,
			style:       $root.find( '.js-iss-ai-style .iss-ai-chip.is-active' ).attr( 'data-value' ) || '',
			palette:     $root.find( '.js-iss-ai-palette .iss-ai-chip.is-active' ).attr( 'data-value' ) || '',
			typography:  $root.find( '.js-iss-ai-typography .iss-ai-chip.is-active' ).attr( 'data-value' ) || '',
			design_level: level,
			// Only Creative runs use a recipe, so don't send a pin the proxy
			// would discard — the plan then records what it actually built to.
			art_direction: 'pro' === level ? currentArtDirection() : '',
			// A fresh seed per run, so re-generating the same description picks
			// a different art direction instead of rebuilding the same site.
			variant_seed: Math.random().toString( 36 ).slice( 2, 12 ) + Date.now().toString( 36 ).slice( -6 ),
			pages:       JSON.stringify( pages )
		}, 300000 )
			.done( function ( response ) {
				if ( response && ! response.success && response.data && 'registration_required' === response.data.code ) {
					// Server no longer recognizes our registration — re-ask for
					// the email instead of showing a dead-end error.
					running   = false;
					connected = false;
					showStep( 'connect' );
					return;
				}
				if ( ! response || ! response.success || ! response.data || ! response.data.plan_id ) {
					failWith( responseMessage( response ), responseDetail( response ) );
					return;
				}
				planState = response.data;
				renderProgressPages();
				ensurePortfolioPlugin( function () {
					ensureFormsPlugin( buildAllPages );
				} );
			} )
			.fail( function ( xhr, textStatus ) {
				var response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
				failWith( responseMessage( response ), xhrDetail( xhr, textStatus ) );
			} );
	}

	// Install/activate the WPZOOM Portfolio plugin (via the importer's
	// existing installer endpoint) when the plan needs it. Failures don't
	// stop the run — the portfolio block simply won't be inserted.
	function ensurePortfolioPlugin( next ) {
		var portfolio = planState.portfolio || {};

		if ( ! portfolio.needed ) {
			next();
			return;
		}
		if ( portfolio.plugin_active ) {
			markProgressItem( 'portfolio', 'is-done' );
			next();
			return;
		}

		markProgressItem( 'portfolio', 'is-active' );

		ajax( 'inspiro_starter_sites_install_plugin', { slug: 'wpzoom-portfolio' }, 120000 )
			.always( function () {
				markProgressItem( 'portfolio', 'is-done' );
				next();
			} );
	}

	// Install/activate WPZOOM Forms in the background when the demo has a
	// contact page — activation seeds the default form the contact page
	// embeds. Failures don't stop the run; the form is simply omitted.
	function ensureFormsPlugin( next ) {
		var forms = planState.forms || {};

		if ( ! forms.needed || forms.plugin_active ) {
			next();
			return;
		}

		markProgressItem( 'forms', 'is-active' );

		ajax( 'inspiro_starter_sites_install_plugin', { slug: 'wpzoom-forms' }, 120000 )
			.always( function () {
				markProgressItem( 'forms', 'is-done' );
				next();
			} );
	}

	// Pages build CONCURRENTLY (each server build owns its own state slot, so
	// parallel requests can't clobber each other) — 4 sequential ~45s AI calls
	// become ~2 rounds. Failures don't kill the run; finalize works with
	// whatever pages made it.
	function buildAllPages() {
		var pages = planState.pages || [];
		var total = pages.length;
		var CONCURRENCY = Math.min( 3, total );
		var next = 0;
		var done = 0;
		var active = 0;

		function updateProgress() {
			// Plan ≈ 40% of the perceived work; pages ≈ 50%; finalize ≈ 10%.
			var fraction = 0.4 + ( 0.5 * ( done / total ) );
			setProgress( sprintf( t.step_pages || '', done, total ), fraction );
		}

		function launchNext() {
			if ( next >= total ) {
				if ( 0 === active ) {
					finalize();
				}
				return;
			}

			var index = next++;
			active++;
			markProgressItem( 'page-' + index, 'is-active' );

			// Each page build includes its own AI design call (~30-60s).
			ajax( 'inspiro_starter_sites_ai_build_page', {
				plan_id:    planState.plan_id,
				page_index: index
			}, 300000 )
				.always( function ( response ) {
					if ( ! response || ! response.success ) {
						setProgress( t.page_failed || '', 0.4 + ( 0.5 * ( done / total ) ) );
					}
					markProgressItem( 'page-' + index, 'is-done' );
					done++;
					active--;
					updateProgress();
					launchNext();
				} );
		}

		updateProgress();
		for ( var i = 0; i < CONCURRENCY; i++ ) {
			launchNext();
		}
	}

	function finalize() {
		setProgress( t.step_finalize || '', 0.92 );
		markProgressItem( 'finalize', 'is-active' );

		ajax( 'inspiro_starter_sites_ai_finalize', { plan_id: planState.plan_id }, 60000 )
			.done( function ( response ) {
				running = false;

				if ( ! response || ! response.success || ! response.data ) {
					failWith( responseMessage( response ), responseDetail( response ) );
					return;
				}

				var $list = $root.find( '.js-iss-ai-page-list' ).empty();
				$.each( planState.pages || [], function ( i, page ) {
					$list.append(
						$( '<li>' ).append(
							$( '<span>' ).addClass( 'iss-ai-page-pill' )
								.append( $( '<span>' ).addClass( 'iss-ai-page-pill__check' ).attr( 'aria-hidden', 'true' ).html( '&#10003;' ) )
								.append( $( '<span>' ).text( page.title ) )
						)
					);
				} );

				if ( planState.site_title ) {
					$root.find( '.js-iss-ai-success-title' ).text( ( t.success_title || '' ) + ' — ' + planState.site_title );
				}
				if ( response.data.demo_pages ) {
					config.demo_pages = response.data.demo_pages;
				}
				if ( response.data.view_url ) {
					$root.find( '.js-iss-ai-view-site' ).attr( 'href', response.data.view_url );
				}

				setProgress( '', 1 );
				resetFeedback();
				showStep( 'success' );
				renderHeroExisting( { site_title: planState.site_title || '', page_count: ( planState.pages || [] ).length } );
			} )
			.fail( function ( xhr, textStatus ) {
				var response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
				failWith( responseMessage( response ), xhrDetail( xhr, textStatus ) );
			} );
	}

	/* -----------------------------------------------------------------
	 * Feedback survey events
	 * -------------------------------------------------------------- */

	// A second run in the same modal session gets a blank survey about the
	// demo it just produced, not the leftovers of the previous one.
	function resetFeedback() {
		var $panel = $root.find( '.js-iss-ai-feedback' ).show();

		feedbackRating = 0;
		feedbackKept   = '';

		$panel.find( '.js-iss-ai-star' ).removeClass( 'is-on' );
		$panel.find( '.js-iss-ai-keep-choice' ).removeClass( 'is-active' );
		$panel.find( '.iss-ai-feedback__text' ).val( '' );
		$panel.find( '.js-iss-ai-feedback-thanks' ).attr( 'hidden', 'hidden' );
		$panel.find( '.js-iss-ai-feedback-form' ).show();
		$panel.find( '.js-iss-ai-feedback-send' ).prop( 'disabled', true ).text( t.feedback_send || '' );
	}

	function syncFeedbackSend() {
		var $panel  = $root.find( '.js-iss-ai-feedback' );
		var answered = feedbackRating > 0 ||
			'' !== feedbackKept ||
			'' !== $.trim( $panel.find( '.js-iss-ai-feedback-missing' ).val() || '' ) ||
			'' !== $.trim( $panel.find( '.js-iss-ai-feedback-comment' ).val() || '' );

		$panel.find( '.js-iss-ai-feedback-send' ).prop( 'disabled', ! answered );
	}

	$root.on( 'click', '.js-iss-ai-star', function () {
		feedbackRating = parseInt( $( this ).attr( 'data-value' ), 10 ) || 0;

		$root.find( '.js-iss-ai-star' ).each( function () {
			$( this ).toggleClass( 'is-on', ( parseInt( $( this ).attr( 'data-value' ), 10 ) || 0 ) <= feedbackRating );
		} );
		syncFeedbackSend();
	} );

	$root.on( 'click', '.js-iss-ai-keep-choice', function () {
		var value = $( this ).attr( 'data-value' );

		// Clicking the active choice again clears it — nothing is required.
		feedbackKept = ( feedbackKept === value ) ? '' : value;

		$root.find( '.js-iss-ai-keep-choice' ).removeClass( 'is-active' );
		if ( '' !== feedbackKept ) {
			$( this ).addClass( 'is-active' );
		}
		syncFeedbackSend();
	} );

	$root.on( 'input', '.iss-ai-feedback__text', syncFeedbackSend );

	$root.on( 'click', '.js-iss-ai-feedback-skip', function () {
		$root.find( '.js-iss-ai-feedback' ).slideUp( 150 );
	} );

	$root.on( 'click', '.js-iss-ai-feedback-send', function () {
		var $button = $( this );
		var $panel  = $root.find( '.js-iss-ai-feedback' );

		if ( $button.prop( 'disabled' ) || ! planState || ! planState.plan_id ) {
			return;
		}

		$button.prop( 'disabled', true ).text( t.feedback_sending || '' );

		ajax( 'inspiro_starter_sites_ai_feedback', {
			plan_id: planState.plan_id,
			rating:  feedbackRating,
			kept:    feedbackKept,
			missing: $.trim( $panel.find( '.js-iss-ai-feedback-missing' ).val() || '' ),
			comment: $.trim( $panel.find( '.js-iss-ai-feedback-comment' ).val() || '' )
		}, 20000 )
			.always( function () {
				// The user has already given their answer; a failed round trip
				// is ours to worry about, not theirs. Thank them either way.
				$panel.find( '.js-iss-ai-feedback-form' ).slideUp( 150 );
				$panel.find( '.js-iss-ai-feedback-thanks' ).removeAttr( 'hidden' );
			} );
	} );

	/* -----------------------------------------------------------------
	 * Events
	 * -------------------------------------------------------------- */

	$( document ).on( 'click', '.js-inspiro-starter-sites-ai-generate', function ( e ) {
		e.preventDefault();
		openModal();

		// The hero's prompt box hands its text to the modal's description, so
		// typing there flows straight into step 1.
		var heroText = $.trim( $( '.js-iss-ai-hero-input' ).val() || '' );
		if ( heroText ) {
			$root.find( '.js-iss-ai-description' ).val( heroText );
		}
	} );

	// Enter (without Shift) in the hero prompt = open the generator.
	$( document ).on( 'keydown', '.js-iss-ai-hero-input', function ( e ) {
		if ( 'Enter' === e.key && ! e.shiftKey ) {
			e.preventDefault();
			$( '.js-inspiro-starter-sites-ai-generate' ).first().trigger( 'click' );
		}
	} );

	// Hero "View ideas": open the modal (with any typed text carried over)
	// and expand its ideas panel right away.
	$( document ).on( 'click', '.js-iss-ai-hero-ideas', function ( e ) {
		e.preventDefault();
		$( '.js-inspiro-starter-sites-ai-generate' ).first().trigger( 'click' );

		var $ideas = $root.find( '.js-iss-ai-ideas' );
		if ( $ideas.attr( 'hidden' ) ) {
			$root.find( '.js-iss-ai-ideas-toggle' ).trigger( 'click' );
		}
	} );

	$root.on( 'click', '.js-iss-ai-close, .js-iss-ai-overlay', function ( e ) {
		if ( e.target === this ) {
			closeModal();
		}
	} );

	$( document ).on( 'keyup', function ( e ) {
		if ( 27 === e.keyCode && $root.hasClass( 'is-open' ) ) {
			closeModal();
		}
	} );

	// Ideas are opt-in: hidden until "View ideas" is clicked, and collapsed
	// again once one is picked — describing OR picking, never both.
	function toggleIdeas( show ) {
		var $ideas  = $root.find( '.js-iss-ai-ideas' );
		var $toggle = $root.find( '.js-iss-ai-ideas-toggle' );

		if ( show ) {
			$ideas.removeAttr( 'hidden' );
			$toggle.attr( 'aria-expanded', 'true' ).html( '&#128161; ' + esc( t.ideas_hide || '' ) );
		} else {
			$ideas.attr( 'hidden', 'hidden' );
			$toggle.attr( 'aria-expanded', 'false' ).html( '&#128161; ' + esc( t.ideas_show || '' ) );
		}
	}

	$root.on( 'click', '.js-iss-ai-ideas-toggle', function () {
		toggleIdeas( $root.find( '.js-iss-ai-ideas' ).attr( 'hidden' ) !== undefined );
	} );

	$root.on( 'click', '.js-iss-ai-idea', function () {
		var text = $( this ).attr( 'data-text' ) || $( this ).text();
		$root.find( '.js-iss-ai-description' ).val( text ).trigger( 'focus' );
		toggleIdeas( false );
	} );

	// Standalone "delete the AI demo" — meta-scoped server-side, so only
	// AI-generated pages/media/menu/widgets are removed.
	$root.on( 'click', '.js-iss-ai-delete', function () {
		if ( running || ! window.confirm( t.delete_confirm || '' ) ) {
			return;
		}

		var $button = $( this ).prop( 'disabled', true ).text( t.deleting || '' );
		var $result = $root.find( '.js-iss-ai-delete-result' );

		ajax( 'inspiro_starter_sites_ai_delete', {}, 120000 )
			.done( function ( response ) {
				if ( response && response.success && response.data ) {
					$result.text( response.data.message || '' ).removeAttr( 'hidden' );
					$root.find( '.js-iss-ai-replace-notice' ).attr( 'hidden', 'hidden' );
					renderHeroExisting( null );
					config.demo_pages = [];
				} else {
					$result.text( responseMessage( response ) ).removeAttr( 'hidden' );
				}
			} )
			.fail( function ( xhr, textStatus ) {
				$result.text( xhrDetail( xhr, textStatus ) || t.error_generic || '' ).removeAttr( 'hidden' );
			} )
			.always( function () {
				$button.prop( 'disabled', false ).text( t.delete_now || '' );
			} );
	} );

	// WordPress' CodeMirror instance for the stylesheet, created on first use.
	var cssEditor = null;

	// Read the stylesheet from CodeMirror when it is active, the raw textarea
	// otherwise (syntax highlighting can be switched off per user profile).
	function cssEditorValue() {
		return cssEditor ? cssEditor.codemirror.getValue() : ( $root.find( '.js-iss-ai-css-editor' ).val() || '' );
	}

	function cssEditorSetValue( css ) {
		$root.find( '.js-iss-ai-css-editor' ).val( css );

		if ( cssEditor ) {
			cssEditor.codemirror.setValue( css );
			return;
		}

		if ( config.code_editor && window.wp && wp.codeEditor ) {
			cssEditor = wp.codeEditor.initialize( $root.find( '.js-iss-ai-css-editor' )[ 0 ], config.code_editor );
		}
	}

	// View / edit the active demo's stylesheet. Handy for tweaking a color or
	// a spacing value without opening every page in the editor.
	$root.on( 'click', '.js-iss-ai-edit-css', function () {
		var $button = $( this ).prop( 'disabled', true );

		ajax( 'inspiro_starter_sites_ai_get_css', {}, 30000 )
			.done( function ( response ) {
				if ( ! response || ! response.success || ! response.data ) {
					window.alert( responseMessage( response ) );
					return;
				}
				$root.find( '.js-iss-ai-css-intro' ).text(
					sprintf( t.edit_css_intro || '', response.data.site_title || '' )
				);
				$root.find( '.js-iss-ai-css-result' ).attr( 'hidden', 'hidden' );
				showStep( 'css' );

				// Initialize/populate after the step is visible: CodeMirror
				// measures the textarea, and gets it wrong while hidden.
				cssEditorSetValue( response.data.css || '' );
				if ( cssEditor ) {
					cssEditor.codemirror.refresh();
				}
			} )
			.fail( function () {
				window.alert( t.error_generic || '' );
			} )
			.always( function () {
				$button.prop( 'disabled', false );
			} );
	} );

	$root.on( 'click', '.js-iss-ai-css-save', function () {
		var $button = $( this ).prop( 'disabled', true ).text( t.saving || '' );
		var $result = $root.find( '.js-iss-ai-css-result' );

		ajax( 'inspiro_starter_sites_ai_save_css', {
			css: cssEditorValue()
		}, 30000 )
			.done( function ( response ) {
				var ok = !! ( response && response.success && response.data );
				$result
					.toggleClass( 'is-error', ! ok )
					.text( ok ? response.data.message : responseMessage( response ) )
					.removeAttr( 'hidden' );
			} )
			.fail( function ( xhr, textStatus ) {
				$result.addClass( 'is-error' )
					.text( xhrDetail( xhr, textStatus ) || t.error_generic || '' )
					.removeAttr( 'hidden' );
			} )
			.always( function () {
				$button.prop( 'disabled', false ).text( t.edit_css_save || '' );
			} );
	} );

	$root.on( 'click', '.js-iss-ai-css-back', function () {
		showStep( 'input' );
	} );

	/* -----------------------------------------------------------------
	 * Add / regenerate a single page (Premium; locked upsell on Lite)
	 * -------------------------------------------------------------- */

	var pageToolsMode = 'add';

	function openPageTools( mode ) {
		// Locked: Lite gets the upgrade pitch, premium-without-license the
		// activation nudge — the tools never open either way (the server
		// enforces the same two gates).
		if ( ! pageToolsAvailable ) {
			var $upsell = $root.find( '.js-iss-ai-page-upsell' );

			if ( ! config.is_premium_theme ) {
				$upsell.find( '.js-iss-ai-page-upsell-text' ).text( t.premium_upsell || '' );
				$upsell.find( '.js-iss-ai-page-upsell-link' ).attr( 'href', config.upgrade_url || '#' ).text( t.premium_cta || '' );
			} else {
				$upsell.find( '.js-iss-ai-page-upsell-text' ).text( t.license_upsell || '' );
				$upsell.find( '.js-iss-ai-page-upsell-link' ).attr( 'href', config.license_url || '#' ).text( t.license_cta || '' );
			}

			$upsell.removeAttr( 'hidden' );
			return;
		}

		pageToolsMode = mode;

		if ( 'regen' === mode ) {
			var $select = $root.find( '.js-iss-ai-pt-page-select' ).empty();
			$.each( config.demo_pages || [], function ( i, p ) {
				$select.append( $( '<option>' ).val( p.id ).text( p.title ) );
			} );
		}

		$root.find( '.js-iss-ai-pt-title' ).text( 'add' === mode ? ( t.add_page_title || '' ) : ( t.regen_title || '' ) );
		$root.find( '.js-iss-ai-pt-intro' ).text( 'add' === mode ? ( t.add_page_intro || '' ) : ( t.regen_intro || '' ) );
		$root.find( '.js-iss-ai-pt-add' ).toggle( 'add' === mode );
		$root.find( '.js-iss-ai-pt-regen' ).toggle( 'regen' === mode );
		$root.find( '.js-iss-ai-pt-form' ).show();
		$root.find( '.js-iss-ai-pt-working' ).attr( 'hidden', 'hidden' );
		$root.find( '.js-iss-ai-pt-result' ).attr( 'hidden', 'hidden' ).removeClass( 'is-error' );
		$root.find( '.js-iss-ai-pt-view, .js-iss-ai-pt-edit' ).attr( 'hidden', 'hidden' );
		$root.find( '.js-iss-ai-pt-go' )
			.prop( 'disabled', false )
			.text( 'add' === mode ? ( t.add_page_go || '' ) : ( t.regen_go || '' ) )
			.show();

		if ( 'regen' === mode ) {
			// Fresh open always starts in "replace" mode.
			$root.find( 'input[name="iss_ai_regen_mode"][value="replace"]' ).prop( 'checked', true );
			syncRegenMode();
		}

		showStep( 'pagetools' );
	}

	$root.on( 'click', '.js-iss-ai-add-page', function () {
		openPageTools( 'add' );
	} );

	$root.on( 'click', '.js-iss-ai-regen-page', function () {
		openPageTools( 'regen' );
	} );

	$root.on( 'click', '.js-iss-ai-pt-back', function () {
		showStep( 'input' );
	} );

	// Regenerate mode switch: the feedback field's label, placeholder and
	// button change meaning between "replace" and "append".
	function syncRegenMode() {
		var mode = $root.find( 'input[name="iss_ai_regen_mode"]:checked' ).val() || 'replace';
		var isAppend = 'append' === mode;

		$root.find( '.iss-ai-mode-option' ).each( function () {
			$( this ).toggleClass( 'is-active', $( this ).find( 'input' ).prop( 'checked' ) );
		} );
		$root.find( '.js-iss-ai-pt-feedback-label' ).text( isAppend ? ( t.append_describe || '' ) : ( t.regen_feedback || '' ) );
		$root.find( '.js-iss-ai-pt-feedback' ).attr( 'placeholder', isAppend ? ( t.append_ph || '' ) : '' );
		$root.find( '.js-iss-ai-pt-intro' ).text( isAppend ? ( t.append_intro || '' ) : ( t.regen_intro || '' ) );
		if ( 'regen' === pageToolsMode ) {
			$root.find( '.js-iss-ai-pt-go' ).text( isAppend ? ( t.append_go || '' ) : ( t.regen_go || '' ) );
		}
	}

	$root.on( 'change', 'input[name="iss_ai_regen_mode"]', syncRegenMode );

	$root.on( 'click', '.js-iss-ai-pt-go', function () {
		var $go     = $( this );
		var $result = $root.find( '.js-iss-ai-pt-result' );
		var action, data;

		if ( 'add' === pageToolsMode ) {
			var title = $.trim( $root.find( '.js-iss-ai-pt-page-title' ).val() || '' );
			if ( ! title ) {
				$root.find( '.js-iss-ai-pt-page-title' ).focus();
				return;
			}
			action = 'inspiro_starter_sites_ai_add_page';
			data   = { title: title, details: $.trim( $root.find( '.js-iss-ai-pt-details' ).val() || '' ) };
		} else {
			var pageId   = $root.find( '.js-iss-ai-pt-page-select' ).val();
			var mode     = $root.find( 'input[name="iss_ai_regen_mode"]:checked' ).val() || 'replace';
			var feedback = $.trim( $root.find( '.js-iss-ai-pt-feedback' ).val() || '' );
			if ( ! pageId ) {
				return;
			}
			// In append mode the description is what gets built — required.
			if ( 'append' === mode && ! feedback ) {
				$root.find( '.js-iss-ai-pt-feedback' ).focus();
				return;
			}
			action = 'inspiro_starter_sites_ai_regenerate_page';
			data   = { page_id: pageId, mode: mode, feedback: feedback };
		}

		$go.prop( 'disabled', true ).hide();
		$root.find( '.js-iss-ai-pt-form' ).hide();
		$root.find( '.js-iss-ai-pt-working' ).removeAttr( 'hidden' );
		$result.attr( 'hidden', 'hidden' ).removeClass( 'is-error' );

		ajax( action, data, 180000 )
			.done( function ( response ) {
				$root.find( '.js-iss-ai-pt-working' ).attr( 'hidden', 'hidden' );

				if ( ! response || ! response.success || ! response.data ) {
					$root.find( '.js-iss-ai-pt-form' ).show();
					$go.prop( 'disabled', false ).show();
					$result.addClass( 'is-error' ).text( responseMessage( response ) ).removeAttr( 'hidden' );
					return;
				}

				$result.text( sprintf( t.page_done || '', response.data.title || '' ) ).removeAttr( 'hidden' );
				$root.find( '.js-iss-ai-pt-view' ).attr( 'href', response.data.view_url || '#' ).removeAttr( 'hidden' );
				$root.find( '.js-iss-ai-pt-edit' ).attr( 'href', response.data.edit_url || '#' ).removeAttr( 'hidden' );

				// New pages become regenerable immediately.
				if ( 'add' === pageToolsMode && response.data.page_id ) {
					config.demo_pages = ( config.demo_pages || [] ).concat( [ { id: response.data.page_id, title: response.data.title || '' } ] );
				}
			} )
			.fail( function ( xhr, textStatus ) {
				$root.find( '.js-iss-ai-pt-working' ).attr( 'hidden', 'hidden' );
				$root.find( '.js-iss-ai-pt-form' ).show();
				$go.prop( 'disabled', false ).show();
				$result.addClass( 'is-error' ).text( xhrDetail( xhr, textStatus ) || t.error_generic || '' ).removeAttr( 'hidden' );
			} );
	} );

	// "Enhance with AI": expand a thin description into a vivid brief
	// (small free call); Undo restores what the user had typed.
	var enhanceUndoValue = null;

	$root.on( 'click', '.js-iss-ai-enhance', function () {
		var $button   = $( this );
		var $textarea = $root.find( '.js-iss-ai-description' );
		var $error    = $root.find( '.js-iss-ai-input-error' );
		var current   = $.trim( $textarea.val() || '' );

		if ( current.length < 5 || $button.prop( 'disabled' ) ) {
			return;
		}
		$error.attr( 'hidden', 'hidden' );
		$button.prop( 'disabled', true ).text( t.enhancing || '' );

		ajax( 'inspiro_starter_sites_ai_enhance_prompt', { description: current }, 60000 )
			.done( function ( response ) {
				if ( response && response.success && response.data && response.data.description ) {
					enhanceUndoValue = current;
					$textarea.val( response.data.description ).trigger( 'focus' );
					$root.find( '.js-iss-ai-enhance-undo' ).removeAttr( 'hidden' );
				} else {
					$error.text( responseMessage( response ) ).removeAttr( 'hidden' );
				}
			} )
			.fail( function ( xhr, textStatus ) {
				$error.text( xhrDetail( xhr, textStatus ) || t.error_generic || '' ).removeAttr( 'hidden' );
			} )
			.always( function () {
				$button.prop( 'disabled', false ).html( '&#10024; ' + esc( t.enhance || '' ) );
			} );
	} );

	$root.on( 'click', '.js-iss-ai-enhance-undo', function () {
		if ( null !== enhanceUndoValue ) {
			$root.find( '.js-iss-ai-description' ).val( enhanceUndoValue ).trigger( 'focus' );
			enhanceUndoValue = null;
		}
		$( this ).attr( 'hidden', 'hidden' );
	} );

	// Style / typography / palette chips — single-select per group.
	$root.on( 'click', '.iss-ai-chip', function () {
		var $chip = $( this );

		$chip.closest( '.iss-ai-chips' ).find( '.iss-ai-chip' ).removeClass( 'is-active' );
		$chip.addClass( 'is-active' );

		if ( $chip.closest( '.js-iss-ai-style' ).length ) {
			syncArtDirectionStyles();
		}
	} );

	// Generation-mode cards. A locked Premium card is not selectable; the note
	// beneath the cards carries the upgrade / activate link.
	$root.on( 'click', '.iss-ai-level-card', function () {
		var $card = $( this );
		if ( $card.hasClass( 'is-locked' ) ) {
			return;
		}

		designLevelTouched = true;
		selectDesignLevel( $card );
	} );

	$root.on( 'click', '.iss-ai-art-card', function () {
		selectArtDirection( $( this ) );
	} );

	$root.on( 'click', '.js-iss-ai-generate', function () {
		if ( running || ! validateDescription() ) {
			return;
		}

		// Premium runs get the art-direction page in between; everyone else
		// goes straight to the page suggestion, exactly as before.
		if ( hasArtStep() ) {
			syncArtDirectionStyles();
			showStep( 'art' );
			return;
		}

		suggestPages( $( this ) );
	} );

	$root.on( 'click', '.js-iss-ai-art-next', function () {
		if ( ! running ) {
			suggestPages( $( this ) );
		}
	} );

	$root.on( 'click', '.js-iss-ai-art-back', function () {
		if ( ! running ) {
			showStep( 'input' );
		}
	} );

	$root.on( 'click', '.js-iss-ai-build', function () {
		if ( ! running ) {
			startGeneration();
		}
	} );

	$root.on( 'click', '.js-iss-ai-plan-remove', function () {
		$( this ).closest( '.iss-ai-plan-row' ).remove();
		planReviewState();
	} );

	function addPlanPage() {
		var $input = $root.find( '.js-iss-ai-plan-add-input' );
		var title  = $.trim( $input.val() || '' );
		if ( ! title || $root.find( '.iss-ai-plan-row' ).length >= MAX_REVIEW_PAGES ) {
			return;
		}
		$root.find( '.js-iss-ai-plan-list' ).append( planRow( '', title ) );
		$input.val( '' ).trigger( 'focus' );
		planReviewState();
	}

	$root.on( 'click', '.js-iss-ai-plan-add', addPlanPage );

	$root.on( 'keydown', '.js-iss-ai-plan-add-input', function ( e ) {
		if ( 'Enter' === e.key ) {
			e.preventDefault();
			addPlanPage();
		}
	} );

	$root.on( 'click', '.js-iss-ai-retry', function () {
		if ( ! running ) {
			showStep( 'input' );
			refreshQuota();
		}
	} );

	/* -----------------------------------------------------------------
	 * Connect (email registration + 6-digit verification)
	 * -------------------------------------------------------------- */

	var pendingEmail   = '';
	var pendingConsent = false;

	function showConnectMode( mode ) {
		$root.find( '.js-iss-ai-connect-mode-email' ).attr( 'hidden', 'hidden' );
		$root.find( '.js-iss-ai-connect-mode-verify' ).attr( 'hidden', 'hidden' );
		$root.find( '.js-iss-ai-connect-mode-' + mode ).removeAttr( 'hidden' );
		$root.find( '.js-iss-ai-connect-error' ).attr( 'hidden', 'hidden' );
		$root.find( '.js-iss-ai-verify-note' ).attr( 'hidden', 'hidden' );
	}

	function connectError( message ) {
		$root.find( '.js-iss-ai-connect-error' ).text( message || t.error_generic || '' ).removeAttr( 'hidden' );
	}

	function submitConnect( isResend ) {
		var $button = $root.find( isResend ? '.js-iss-ai-resend' : '.js-iss-ai-connect' );
		var $error  = $root.find( '.js-iss-ai-connect-error' );
		var email   = isResend ? pendingEmail : $.trim( $root.find( '.js-iss-ai-connect-email' ).val() || '' );
		var consent = isResend ? pendingConsent : $root.find( '.js-iss-ai-connect-consent' ).is( ':checked' );

		// Light client-side check; the server validates for real.
		if ( ! email || email.indexOf( '@' ) < 1 || email.indexOf( '.' ) < 0 ) {
			connectError( t.connect_invalid || '' );
			return;
		}
		$error.attr( 'hidden', 'hidden' );
		$root.find( '.js-iss-ai-verify-note' ).attr( 'hidden', 'hidden' );
		$button.prop( 'disabled', true );
		if ( ! isResend ) {
			$button.text( t.connecting || '' );
		}

		ajax( 'inspiro_starter_sites_ai_connect', {
			email:   email,
			consent: consent ? '1' : ''
		}, 30000 )
			.done( function ( response ) {
				$button.prop( 'disabled', false );
				if ( ! isResend ) {
					$button.text( t.connect_button || '' );
				}
				if ( ! response || ! response.success || ! response.data ) {
					connectError( responseMessage( response ) );
					return;
				}

				// Verification pending: swap to the code entry.
				if ( response.data.pending ) {
					pendingEmail   = response.data.email || email;
					pendingConsent = consent;
					$root.find( '.js-iss-ai-verify-text' ).text( sprintf( t.verify_text || '%s', pendingEmail ) );
					showConnectMode( 'verify' );
					if ( isResend ) {
						$root.find( '.js-iss-ai-verify-note' ).text( t.code_sent || '' ).removeAttr( 'hidden' );
					} else {
						$root.find( '.js-iss-ai-verify-code' ).val( '' ).trigger( 'focus' );
					}
					return;
				}

				applyQuotaResponse( response.data );
				if ( response.data.connected ) {
					showStep( 'input' );
				}
			} )
			.fail( function () {
				$button.prop( 'disabled', false );
				if ( ! isResend ) {
					$button.text( t.connect_button || '' );
				}
				connectError( t.error_generic || '' );
			} );
	}

	function submitVerify() {
		var $button = $root.find( '.js-iss-ai-verify' );
		var code    = ( $root.find( '.js-iss-ai-verify-code' ).val() || '' ).replace( /\D/g, '' );

		if ( 6 !== code.length ) {
			connectError( t.verify_invalid || '' );
			return;
		}
		$root.find( '.js-iss-ai-connect-error' ).attr( 'hidden', 'hidden' );
		$button.prop( 'disabled', true ).text( t.verifying || '' );

		ajax( 'inspiro_starter_sites_ai_verify', { code: code }, 30000 )
			.done( function ( response ) {
				$button.prop( 'disabled', false ).text( t.verify_button || '' );
				if ( ! response || ! response.success || ! response.data ) {
					connectError( responseMessage( response ) );
					return;
				}
				applyQuotaResponse( response.data );
				if ( response.data.connected ) {
					showConnectMode( 'email' );
					showStep( 'input' );
				}
			} )
			.fail( function () {
				$button.prop( 'disabled', false ).text( t.verify_button || '' );
				connectError( t.error_generic || '' );
			} );
	}

	$root.on( 'click', '.js-iss-ai-connect', function () {
		submitConnect( false );
	} );

	$root.on( 'click', '.js-iss-ai-verify', submitVerify );

	$root.on( 'click', '.js-iss-ai-resend', function () {
		submitConnect( true );
	} );

	$root.on( 'click', '.js-iss-ai-change-email', function () {
		showConnectMode( 'email' );
		$root.find( '.js-iss-ai-connect-email' ).trigger( 'focus' );
	} );

	$root.on( 'click', '.js-iss-ai-disconnect', function () {
		if ( running ) {
			return; // A build in flight still needs the registration.
		}
		if ( ! window.confirm( t.disconnect_confirm || '' ) ) {
			return;
		}
		var $btn = $( this ).prop( 'disabled', true );

		ajax( 'inspiro_starter_sites_ai_disconnect', {}, 20000 )
			.done( function ( response ) {
				if ( response && response.success ) {
					quota     = null;
					connected = false;
					renderQuota();
					showConnectMode( 'email' );
					$root.find( '.js-iss-ai-connect-email' ).val( '' );
					showStep( 'connect' );
					$root.find( '.js-iss-ai-connect-email' ).trigger( 'focus' );
				}
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	$root.on( 'keydown', '.js-iss-ai-connect-email', function ( e ) {
		if ( 'Enter' === e.key ) {
			e.preventDefault();
			submitConnect( false );
		}
	} );

	$root.on( 'keydown', '.js-iss-ai-verify-code', function ( e ) {
		if ( 'Enter' === e.key ) {
			e.preventDefault();
			submitVerify();
		}
	} );
} );
