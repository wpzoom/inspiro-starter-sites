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

	var built     = false;
	var running   = false;
	var quota     = null; // { used, limit, remaining }
	var planState = null; // { plan_id, pages: [...] }

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
	 * Modal
	 * -------------------------------------------------------------- */

	function buildModal() {
		var ideas = '';
		$.each( config.ideas || [], function ( i, idea ) {
			ideas += '<button type="button" class="iss-ai-idea js-iss-ai-idea">' + esc( idea ) + '</button>';
		} );

		var html =
		'<div class="iss-ai-overlay js-iss-ai-overlay">' +
			'<div class="iss-ai-modal" role="dialog" aria-modal="true" aria-label="' + esc( t.title || '' ) + '">' +
				'<button type="button" class="iss-ai-close js-iss-ai-close" aria-label="' + esc( t.close || 'Close' ) + '">&times;</button>' +
				'<div class="iss-ai-header">' +
					'<h2>' + esc( t.title || '' ) + ' <span class="iss-ai-badge">' + esc( t.beta || 'Beta' ) + '</span></h2>' +
					'<p class="iss-ai-intro">' + esc( t.intro || '' ) + '</p>' +
				'</div>' +
				'<div class="iss-ai-body">' +

					// Step: input.
					'<div class="iss-ai-step iss-ai-step-input is-active" data-step="input">' +
						'<div class="iss-ai-replace-notice js-iss-ai-replace-notice" hidden>' +
							'<strong>' + esc( t.replace_title || '' ) + '</strong>' +
							'<p class="js-iss-ai-replace-text"></p>' +
							'<label class="iss-ai-replace-check"><input type="checkbox" class="js-iss-ai-replace" checked> <span>' + esc( t.replace_checkbox || '' ) + '</span></label>' +
							'<p class="iss-ai-replace-hint">' + esc( t.replace_keep_hint || '' ) + '</p>' +
						'</div>' +
						'<textarea class="iss-ai-textarea js-iss-ai-description" rows="4" maxlength="1200" placeholder="' + esc( t.placeholder || '' ) + '"></textarea>' +
						'<p class="iss-ai-ideas-label">' + esc( t.ideas_label || '' ) + '</p>' +
						'<div class="iss-ai-ideas">' + ideas + '</div>' +
						'<p class="iss-ai-error js-iss-ai-input-error" hidden></p>' +
					'</div>' +

					// Step: progress.
					'<div class="iss-ai-step iss-ai-step-progress" data-step="progress">' +
						'<div class="iss-ai-spinner" aria-hidden="true"></div>' +
						'<p class="iss-ai-progress-label js-iss-ai-progress-label"></p>' +
						'<div class="iss-ai-progress-bar"><span class="js-iss-ai-progress-fill"></span></div>' +
						'<p class="iss-ai-hint">' + esc( t.progress_hint || '' ) + '</p>' +
					'</div>' +

					// Step: success.
					'<div class="iss-ai-step iss-ai-step-success" data-step="success">' +
						'<div class="iss-ai-success-check" aria-hidden="true">&#10003;</div>' +
						'<h3 class="js-iss-ai-success-title">' + esc( t.success_title || '' ) + '</h3>' +
						'<p>' + esc( t.success_text || '' ) + '</p>' +
						'<ul class="iss-ai-page-list js-iss-ai-page-list"></ul>' +
						'<div class="iss-ai-actions">' +
							'<a href="' + esc( config.site_url || '#' ) + '" target="_blank" rel="noopener" class="button button-primary js-iss-ai-view-site">' + esc( t.view_site || '' ) + '</a>' +
							'<a href="' + esc( config.pages_url || '#' ) + '" class="button">' + esc( t.edit_pages || '' ) + '</a>' +
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
					'<span class="iss-ai-quota js-iss-ai-quota">' + esc( t.quota_loading || '' ) + '</span>' +
					'<button type="button" class="button button-primary iss-ai-generate-btn js-iss-ai-generate">' + esc( t.generate || '' ) + '</button>' +
				'</div>' +
			'</div>' +
		'</div>';

		$root.html( html );
		built = true;
	}

	function showStep( step ) {
		$root.find( '.iss-ai-step' ).removeClass( 'is-active' );
		$root.find( '.iss-ai-step[data-step="' + step + '"]' ).addClass( 'is-active' );
		$root.find( '.js-iss-ai-footer' ).toggle( 'input' === step );
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

	function refreshQuota() {
		var $quota = $root.find( '.js-iss-ai-quota' );
		$quota.text( t.quota_loading || '' );

		ajax( 'inspiro_starter_sites_ai_quota', {}, 20000 ).done( function ( response ) {
			if ( ! response || ! response.success || ! response.data ) {
				$quota.text( '' );
				return;
			}
			quota = response.data;
			renderQuota();
			renderReplaceNotice( response.data.previous );
		} ).fail( function () {
			$quota.text( '' );
		} );
	}

	// Prominent warning when a previously generated AI demo exists: it will
	// be deleted (edits included) unless the user unchecks the box.
	function renderReplaceNotice( previous ) {
		var $notice = $root.find( '.js-iss-ai-replace-notice' );

		if ( ! previous || ! previous.page_count ) {
			$notice.attr( 'hidden', 'hidden' );
			return;
		}

		var text = previous.site_title
			? sprintf( t.replace_notice || '', previous.site_title, previous.page_count )
			: sprintf( t.replace_notice_unnamed || '', previous.page_count );

		$notice.find( '.js-iss-ai-replace-text' ).text( text );
		$notice.removeAttr( 'hidden' );
	}

	function renderQuota() {
		var $quota = $root.find( '.js-iss-ai-quota' );
		if ( ! quota ) {
			$quota.text( '' );
			return;
		}
		if ( quota.remaining <= 0 ) {
			$quota.text( t.quota_none || '' ).addClass( 'is-exhausted' );
			$root.find( '.js-iss-ai-generate' ).prop( 'disabled', true );
		} else {
			$quota.text( sprintf( t.quota_left || '%1$s / %2$s', quota.used, quota.limit ) ).removeClass( 'is-exhausted' );
			$root.find( '.js-iss-ai-generate' ).prop( 'disabled', false );
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

	function startGeneration() {
		var description = $.trim( $root.find( '.js-iss-ai-description' ).val() || '' );
		var $inputError = $root.find( '.js-iss-ai-input-error' );

		if ( description.length < 12 ) {
			$inputError.text( t.too_short || '' ).removeAttr( 'hidden' );
			return;
		}
		$inputError.attr( 'hidden', 'hidden' );

		var $replace = $root.find( '.js-iss-ai-replace' );
		var replace  = ( ! $replace.length || $replace.closest( '.js-iss-ai-replace-notice' ).attr( 'hidden' ) || $replace.is( ':checked' ) ) ? '1' : '0';

		running = true;
		showStep( 'progress' );
		setProgress( t.step_plan || '', 0.08 );

		ajax( 'inspiro_starter_sites_ai_generate', { description: description, replace: replace }, 300000 )
			.done( function ( response ) {
				if ( ! response || ! response.success || ! response.data || ! response.data.plan_id ) {
					failWith( responseMessage( response ), responseDetail( response ) );
					return;
				}
				planState = response.data;
				buildNextPage( 0 );
			} )
			.fail( function ( xhr, textStatus ) {
				var response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
				failWith( responseMessage( response ), xhrDetail( xhr, textStatus ) );
			} );
	}

	function buildNextPage( index ) {
		var pages = planState.pages || [];
		var total = pages.length;

		if ( index >= total ) {
			finalize();
			return;
		}

		// Plan ≈ 40% of the perceived work; pages ≈ 50%; finalize ≈ 10%.
		var fraction = 0.4 + ( 0.5 * ( index / total ) );
		setProgress( sprintf( t.step_page || '', index + 1, total, pages[ index ].title ), fraction );

		// Each page build now includes its own AI design call (~30-60s).
		ajax( 'inspiro_starter_sites_ai_build_page', {
			plan_id:    planState.plan_id,
			page_index: index
		}, 300000 )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					// A single failed page shouldn't kill the run — note it and continue.
					setProgress( t.page_failed || '', fraction );
				}
				buildNextPage( index + 1 );
			} )
			.fail( function () {
				setProgress( t.page_failed || '', fraction );
				buildNextPage( index + 1 );
			} );
	}

	function finalize() {
		setProgress( t.step_finalize || '', 0.92 );

		ajax( 'inspiro_starter_sites_ai_finalize', { plan_id: planState.plan_id }, 60000 )
			.done( function ( response ) {
				running = false;

				if ( ! response || ! response.success || ! response.data ) {
					failWith( responseMessage( response ), responseDetail( response ) );
					return;
				}

				var $list = $root.find( '.js-iss-ai-page-list' ).empty();
				$.each( planState.pages || [], function ( i, page ) {
					$list.append( $( '<li>' ).text( page.title ) );
				} );

				if ( planState.site_title ) {
					$root.find( '.js-iss-ai-success-title' ).text( ( t.success_title || '' ) + ' — ' + planState.site_title );
				}
				if ( response.data.view_url ) {
					$root.find( '.js-iss-ai-view-site' ).attr( 'href', response.data.view_url );
				}

				setProgress( '', 1 );
				showStep( 'success' );
			} )
			.fail( function ( xhr, textStatus ) {
				var response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
				failWith( responseMessage( response ), xhrDetail( xhr, textStatus ) );
			} );
	}

	/* -----------------------------------------------------------------
	 * Events
	 * -------------------------------------------------------------- */

	$( document ).on( 'click', '.js-inspiro-starter-sites-ai-generate', function ( e ) {
		e.preventDefault();
		openModal();
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

	$root.on( 'click', '.js-iss-ai-idea', function () {
		$root.find( '.js-iss-ai-description' ).val( $( this ).text() ).trigger( 'focus' );
	} );

	$root.on( 'click', '.js-iss-ai-generate', function () {
		if ( ! running ) {
			startGeneration();
		}
	} );

	$root.on( 'click', '.js-iss-ai-retry', function () {
		if ( ! running ) {
			showStep( 'input' );
			refreshQuota();
		}
	} );
} );
