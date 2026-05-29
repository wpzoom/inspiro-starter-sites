jQuery( function ( $ ) {
	'use strict';

	/**
	 * ---------------------------------------
	 * ------------- DOM Ready ---------------
	 * ---------------------------------------
	 */

	// Move the admin notices inside the appropriate div.
	$( '.js-inspiro-starter-sites-notice-wrapper' ).appendTo( '.js-inspiro-starter-sites-admin-notices-container' );

	// Filter the free starter sites by editor type + category. State for the
	// two filter rows lives on `demoFilterState` so each handler can read both.
	var demoFilterState  = { type: 'all', category: 'all' };
	var FILTER_OUT_MS    = 320;
	var FILTER_STAGGER   = 22;

	function applyDemoFilters() {
		var $items = $( '.step-choose-design > form > ul > li[data-type]' );

		// First pass: figure out which items match the active filters.
		var toShow = [];
		var toHide = [];
		$items.each( function () {
			var $item     = $( this );
			var rawType   = String( $item.attr( 'data-type' ) || '' );
			var itemTypes = rawType ? rawType.split( /\s+/ ) : [];
			var rawCats   = String( $item.attr( 'data-categories' ) || '' );
			var itemCats  = rawCats ? rawCats.split( /\s+/ ) : [];
			var typeMatch = 'all' === demoFilterState.type || itemTypes.indexOf( demoFilterState.type ) !== -1;
			var catMatch  = 'all' === demoFilterState.category || itemCats.indexOf( demoFilterState.category ) !== -1;

			if ( typeMatch && catMatch ) {
				// When filtering by a specific editor, flip grouped cards to that variant.
				if ( 'all' !== demoFilterState.type && itemTypes.length > 1 ) {
					switchCardVariant( $item, demoFilterState.type );
				}
				toShow.push( $item );
			} else {
				toHide.push( $item );
			}
		} );

		// Fade-out pass — already-hidden items skip the transition.
		$.each( toHide, function ( _, $item ) {
			if ( 'none' === $item.css( 'display' ) ) {
				return;
			}
			$item.addClass( 'is-filtering-out' );
		} );

		// After the fade-out finishes, collapse out-of-view items and then
		// stagger the fade-in for the new set so the grid feels like it
		// shuffles in instead of popping.
		window.setTimeout( function () {
			$.each( toHide, function ( _, $item ) {
				if ( $item.hasClass( 'is-filtering-out' ) ) {
					$item.css( 'display', 'none' );
				}
			} );

			$.each( toShow, function ( index, $item ) {
				var wasHidden = 'none' === $item.css( 'display' );
				$item.removeClass( 'is-filtering-out' );

				if ( wasHidden ) {
					$item.addClass( 'is-filtering-in' ).css( 'display', '' );
					// Two rAFs guarantee the browser paints the starting state
					// before we transition to the resting state.
					window.requestAnimationFrame( function () {
						window.requestAnimationFrame( function () {
							window.setTimeout( function () {
								$item.removeClass( 'is-filtering-in' );
							}, index * FILTER_STAGGER );
						} );
					} );
				}
			} );
		}, toHide.length ? FILTER_OUT_MS : 0 );

		// Refresh visible counts on category buttons to reflect the active editor type.
		var typeKey = 'count-' + ( demoFilterState.type === 'all' ? 'all' : demoFilterState.type );
		$( '.inspiro-starter-sites-demo-category' ).each( function () {
			var $btn   = $( this );
			var $count = $btn.find( '.inspiro-starter-sites-demo-category__count' );
			if ( ! $count.length ) {
				return;
			}
			var value = parseInt( $count.attr( 'data-' + typeKey ), 10 );
			if ( isNaN( value ) ) {
				value = 0;
			}
			$count.text( value );

			// Hide categories that have zero items under the active type, except the "all" pill.
			if ( 'all' !== $btn.data( 'category' ) ) {
				$btn.closest( 'li' ).toggleClass( 'is-cat-hidden', value <= 0 );
			}
		} );
	}

	$( '.inspiro-starter-sites-demo-filter' ).on( 'click', '.inspiro-starter-sites-demo-filter-btn', function () {
		var $btn = $( this );
		$btn.siblings( '.inspiro-starter-sites-demo-filter-btn' )
			.removeClass( 'is-active' )
			.attr( 'aria-pressed', 'false' );
		$btn.addClass( 'is-active' ).attr( 'aria-pressed', 'true' );

		demoFilterState.type = String( $btn.data( 'filter' ) );
		applyDemoFilters();
	} );

	$( '.inspiro-starter-sites-demo-categories' ).on( 'click', '.inspiro-starter-sites-demo-category', function () {
		var $btn = $( this );
		$btn.closest( '.inspiro-starter-sites-demo-categories' )
			.find( '.inspiro-starter-sites-demo-category' )
			.removeClass( 'is-active' )
			.attr( 'aria-pressed', 'false' );
		$btn.addClass( 'is-active' ).attr( 'aria-pressed', 'true' );

		demoFilterState.category = String( $btn.data( 'category' ) );
		applyDemoFilters();
	} );

	/**
	 * Switch a grouped demo card to a given editor variant (blocks/elementor),
	 * updating the toggle state, thumbnail, "View Demo" link, New badge and the
	 * active Import button.
	 */
	function switchCardVariant( $card, variant ) {
		var $btn = $card.find( '.inspiro-starter-sites-demo-variant-btn[data-variant="' + variant + '"]' );
		if ( ! $btn.length || $btn.hasClass( 'is-active' ) ) {
			return;
		}

		$card.find( '.inspiro-starter-sites-demo-variant-btn' )
			.removeClass( 'is-active' )
			.attr( 'aria-pressed', 'false' );
		$btn.addClass( 'is-active' ).attr( 'aria-pressed', 'true' );

		var img = $btn.data( 'img' );
		if ( img ) {
			$card.find( '.js-inspiro-starter-sites-variant-thumb' ).css( 'background-image', "url('" + img + "')" );
		}

		var preview = $btn.data( 'preview' );
		if ( preview ) {
			$card.find( '.js-inspiro-starter-sites-variant-preview' ).attr( 'href', preview );
		}

		$card.find( '.js-inspiro-starter-sites-variant-new' ).toggle( String( $btn.data( 'is-new' ) ) === '1' );

		$card.find( '.js-inspiro-starter-sites-variant-action' )
			.hide()
			.filter( '[data-variant="' + variant + '"]' )
			.show();

		$card.attr( 'data-active-variant', variant );
	}

	$( '.step-choose-design' ).on( 'click', '.inspiro-starter-sites-demo-variant-btn', function () {
		switchCardVariant( $( this ).closest( 'li' ), String( $( this ).data( 'variant' ) ) );
	} );

	/**
	 * ---------------------------------------
	 * ------------- Events ------------------
	 * ---------------------------------------
	 */

    // Initialize tabs with proper configuration
    $("#tabs").tabs({
        beforeLoad: function(event, ui) {
            // Prevent AJAX loading of tab content
            return false;
        },
        create: function(event, ui) {
            // Handle notice dismissal links
            $('.notice-dismiss, .tgmpa-dismiss').on('click', function(e) {
                e.preventDefault();
                var $link = $(this);
                var href = $link.attr('href');

                // Make AJAX request instead of page reload
                $.get(href, function(response) {
                    $link.closest('.notice').fadeOut();
                });
            });
        }
    });

    // Prevent default action for external links in tabs
    $('.wpz-onboard_tab a[href^="http"], .wpz-onboard_tab a[href*="admin.php"]').on('click', function(e) {
        e.stopPropagation();
    });

	/**
	 * Prevent a required plugin checkbox from changeing state.
	 */
	$( '.inspiro-starter-sites-install-plugins-content-content .plugin-item.plugin-item--required input[type=checkbox]' ).on( 'click', function( event ) {
		event.preventDefault();

		return false;
	} );

	/**
	 * Install plugins event.
	 */
	$( '.js-inspiro-starter-sites-install-plugins' ).on( 'click', function( event ) {
		event.preventDefault();

		var $button = $( this );

		if ( $button.hasClass( 'inspiro-starter-sites-button-disabled' ) ) {
			return false;
		}

		var pluginsToInstall = $( '.inspiro-starter-sites-install-plugins-content-content .plugin-item input[type=checkbox]' ).serializeArray();

		if ( pluginsToInstall.length === 0 ) {
			return false;
		}

		$button.addClass( 'inspiro-starter-sites-button-disabled' );

		installPluginsAjaxCall( pluginsToInstall, 0, $button, false, false );
	} );

	/**
	 * Install plugins before importing event.
	 */
	$( '.js-inspiro-starter-sites-install-plugins-before-import' ).on( 'click', function( event ) {
		event.preventDefault();

		var $button = $( this );

		if ( $button.hasClass( 'inspiro-starter-sites-button-disabled' ) ) {
			return false;
		}

		//Handle the Install Theme section
		var themeContainer = $('.inspiro-starter-sites-install-theme-content');

		//Handle plugins that are required for the selected demo content.
		var pluginsToInstall = $( '.inspiro-starter-sites-install-plugins-content-content .plugin-item:not(.plugin-item--disabled) input[type=checkbox]' ).serializeArray();

		//If there is a theme to install, check if it is selected.
		if ( typeof themeContainer !== 'undefined' && themeContainer.length > 0 ) {
			
			var themeToInstall = themeContainer.find('input[type=checkbox]:checked').val();
			var themeItem = themeContainer.find('.theme-item');
			
			if ( typeof themeToInstall !== 'undefined'  && themeToInstall.length > 0 ) {

				// Step 1: Activate the theme
				$.ajax({
					method: 'POST',
					url: inspiro_starter_sites.ajax_url,
					data: {
						action: 'handle_inspiro_theme',
						security: inspiro_starter_sites.ajax_nonce,
					},
					beforeSend: function() {
						themeItem.addClass( 'theme-item--loading' );
					}
				} ).done(function( response ) {
					themeItem.removeClass( 'theme-item--loading' );

					if ( response.success ) {

						themeItem.addClass( 'theme-item--active' );
						themeItem.find( 'input[type=checkbox]' ).prop( 'disabled', true );

						initPluginInstall( $button, pluginsToInstall );
						console.log('Theme activated successfully.');
						return false;
			
					} else {
						console.log( response.data || 'Failed to activate theme.' );
						$button.removeClass( 'inspiro-starter-sites-button-disabled' );
					}
				} )
				.fail( function( error ) {
					console.log( 'Error: ' + error.statusText + ' (' + error.status + ')' );
					initPluginInstall( $button, pluginsToInstall );
				} );

			}
			else {
				// If there is no theme selected, proceed with the plugin installation.
				initPluginInstall( $button, pluginsToInstall );				
			}
		}
		else {
			//If there is no theme to install, proceed with the plugin installation.
			initPluginInstall( $button, pluginsToInstall );
		}
	} );

	/**
	 * Initiate the Plugin installation process.
	 */
	function initPluginInstall( $button,  pluginsToInstall ) {
		
		if ( pluginsToInstall.length === 0 ) {
			startImport( getUrlParameter( 'import' ) );
			return false;
		}

		$button.addClass( 'inspiro-starter-sites-button-disabled' );
		installPluginsAjaxCall( pluginsToInstall, 0, $button, true, false );
	}

	/**
	 * Update "plugins to be installed" notice on Create Demo Content page.
	 */
	$( document ).on( 'change', '.inspiro-starter-sites--create-content .content-item input[type=checkbox]', function( event ) {
		var $checkboxes = $( '.inspiro-starter-sites--create-content .content-item input[type=checkbox]' ),
			$missingPluginNotice = $( '.js-inspiro-starter-sites-create-content-install-plugins-notice' ),
			missingPlugins = [];

		$checkboxes.each( function() {
			var $checkbox = $( this );
			if ( $checkbox.is( ':checked' ) ) {
				missingPlugins = missingPlugins.concat( getMissingPluginNamesFromImportContentPageItem( $checkbox.data( 'plugins' ) ) );
			}
		} );

		missingPlugins = missingPlugins.filter( onlyUnique ).join( ', ' );

		if ( missingPlugins.length > 0 ) {
			$missingPluginNotice.find( '.js-inspiro-starter-sites-create-content-install-plugins-list' ).text( missingPlugins );
			$missingPluginNotice.show();
		} else {
			$missingPluginNotice.find( '.js-inspiro-starter-sites-create-content-install-plugins-list' ).text( '' );
			$missingPluginNotice.hide();
		}
	} );

	/**
	 * Delete the imported content.
	 */
	$('.js-inspiro-starter-sites-delete-imported-demo').on('click', function (event) {
		event.preventDefault();

		var $button = $(this);

		if ( $button.hasClass( 'inspiro-starter-sites-button-disabled' ) ) {
			return false;
		}

		$button.addClass('inspiro-starter-sites-button-disabled');
		// AJAX call to delete the imported content.

		$.ajax({
			method: 'POST',
			url: inspiro_starter_sites.ajax_url,
			data: {
				action: 'inspiro_starter_sites_delete_imported_demo',
				security: inspiro_starter_sites.ajax_nonce,
			},
			beforeSend:  function() {
				$( '.js-inspiro-starter-sites-delete-imported-content' ).hide();
				$( '.js-inspiro-starter-sites-deleting' ).show();
			}
		})
		.done(function (response) {
			if (response.success) {
				$( '.js-inspiro-starter-sites-deleting' ).hide();
				$( '.js-inspiro-starter-sites-deleted' ).show();
			} else {
				console.log(response.data || 'Failed to delete imported content.');
				$( '.js-inspiro-starter-sites-deleting' ).hide();
				$( '.js-inspiro-starter-sites-deleted' ).show();
			}
		})

	} );	


	/**
	 * Grid Layout categories navigation.
	 */
	(function () {
		// Cache selector to all items
		var $items = $( '.js-inspiro-starter-sites-gl-item-container' ).find( '.js-inspiro-starter-sites-gl-item' ),
			fadeoutClass = 'inspiro-starter-sites-is-fadeout',
			fadeinClass = 'inspiro-starter-sites-is-fadein',
			animationDuration = 200;

		// Hide all items.
		var fadeOut = function () {
			var dfd = jQuery.Deferred();

			$items
				.addClass( fadeoutClass );

			setTimeout( function() {
				$items
					.removeClass( fadeoutClass )
					.hide();

				dfd.resolve();
			}, animationDuration );

			return dfd.promise();
		};

		var fadeIn = function ( category, dfd ) {
			var filter = category ? '[data-categories*="' + category + '"]' : 'div';

			if ( 'all' === category ) {
				filter = 'div';
			}

			$items
				.filter( filter )
				.show()
				.addClass( 'inspiro-starter-sites-is-fadein' );

			setTimeout( function() {
				$items
					.removeClass( fadeinClass );

				dfd.resolve();
			}, animationDuration );
		};

		var animate = function ( category ) {
			var dfd = jQuery.Deferred();

			var promise = fadeOut();

			promise.done( function () {
				fadeIn( category, dfd );
			} );

			return dfd;
		};

		$( '.js-inspiro-starter-sites-nav-link' ).on( 'click', function( event ) {
			event.preventDefault();

			// Remove 'active' class from the previous nav list items.
			$( this ).parent().siblings().removeClass( 'active' );

			// Add the 'active' class to this nav list item.
			$( this ).parent().addClass( 'active' );

			var category = this.hash.slice(1);

			// show/hide the right items, based on category selected
			var $container = $( '.js-inspiro-starter-sites-gl-item-container' );
			$container.css( 'min-width', $container.outerHeight() );

			var promise = animate( category );

			promise.done( function () {
				$container.removeAttr( 'style' );
			} );
		} );
	}());


	/**
	 * Grid Layout search functionality.
	 */
	$( '.js-inspiro-starter-sites-gl-search' ).on( 'keyup', function( event ) {
		if ( 0 < $(this).val().length ) {
			// Hide all items.
			$( '.js-inspiro-starter-sites-gl-item-container' ).find( '.js-inspiro-starter-sites-gl-item' ).hide();

			// Show just the ones that have a match on the import name.
			$( '.js-inspiro-starter-sites-gl-item-container' ).find( '.js-inspiro-starter-sites-gl-item[data-name*="' + $(this).val().toLowerCase() + '"]' ).show();
		}
		else {
			$( '.js-inspiro-starter-sites-gl-item-container' ).find( '.js-inspiro-starter-sites-gl-item' ).show();
		}
	} );

	/**
	 * ---------------------------------------
	 * --------Helper functions --------------
	 * ---------------------------------------
	 */

	/**
	 * The main AJAX call, which executes the import process.
	 *
	 * @param FormData data The data to be passed to the AJAX call.
	 */
	function ajaxCall( data ) {
		$.ajax({
			method:      'POST',
			url:         inspiro_starter_sites.ajax_url,
			data:        data,
			contentType: false,
			processData: false,
			beforeSend:  function() {
				$( '.js-inspiro-starter-sites-install-plugins-content' ).hide();
				$( '.js-inspiro-starter-sites-importing' ).show();
			}
		})
		.done( function( response ) {
			if ( 'undefined' !== typeof response.status && 'newAJAX' === response.status ) {
				ajaxCall( data );
			}
			else if ( 'undefined' !== typeof response.status && 'customizerAJAX' === response.status ) {
				// Fix for data.set and data.delete, which they are not supported in some browsers.
				var newData = new FormData();
				newData.append( 'action', 'inspiro_starter_sites_import_customizer_data' );
				newData.append( 'security', inspiro_starter_sites.ajax_nonce );

				// Set the wp_customize=on only if the plugin filter is set to true.
				if ( true === inspiro_starter_sites.wp_customize_on ) {
					newData.append( 'wp_customize', 'on' );
				}

				ajaxCall( newData );
			}
			else if ( 'undefined' !== typeof response.status && 'afterAllImportAJAX' === response.status ) {
				// Fix for data.set and data.delete, which they are not supported in some browsers.
				var newData = new FormData();
				newData.append( 'action', 'inspiro_starter_sites_after_import_data' );
				newData.append( 'security', inspiro_starter_sites.ajax_nonce );
				ajaxCall( newData );
			}
			else if ( 'undefined' !== typeof response.message ) {
				$( '.js-inspiro-starter-sites-ajax-complete-response' ).append( response.message );

				if ( 'undefined' !== typeof response.title ) {
					$( '.js-inspiro-starter-sites-ajax-response-title' ).html( response.title );
				}

				if ( 'undefined' !== typeof response.subtitle ) {
					$( '.js-inspiro-starter-sites-ajax-response-subtitle' ).html( response.subtitle );
				}

				$( '.js-inspiro-starter-sites-importing' ).hide();
				$( '.js-inspiro-starter-sites-imported' ).show();

				// Trigger custom event, when inspiro_starter_sites import is complete.
				$( document ).trigger( 'issImportComplete' );
			}
			else {
				$( '.js-inspiro-starter-sites-ajax-complete-response' ).append( '<img class="inspiro-starter-sites-imported-content-imported inspiro-starter-sites-imported-content-imported--error" src="' + inspiro_starter_sites.plugin_url + 'assets/images/error.svg" alt="' + inspiro_starter_sites.texts.import_failed + '"><p>' + response + '</p>' );
				$( '.js-inspiro-starter-sites-ajax-response-title' ).html( inspiro_starter_sites.texts.import_failed );
				$( '.js-inspiro-starter-sites-ajax-response-subtitle' ).html( '<p>' + inspiro_starter_sites.texts.import_failed_subtitle + '</p>' );
				$( '.js-inspiro-starter-sites-importing' ).hide();
				$( '.js-inspiro-starter-sites-imported' ).show();
			}
		})
		.fail( function( error ) {
			$( '.js-inspiro-starter-sites-ajax-complete-response' ).append( '<img class="inspiro-starter-sites-imported-content-imported inspiro-starter-sites-imported-content-imported--error" src="' + inspiro_starter_sites.plugin_url + 'assets/images/error.svg" alt="' + inspiro_starter_sites.texts.import_failed + '"><p>Error: ' + error.statusText + ' (' + error.status + ')' + '</p>' );
			$( '.js-inspiro-starter-sites-ajax-response-title' ).html( inspiro_starter_sites.texts.import_failed );
			$( '.js-inspiro-starter-sites-ajax-response-subtitle' ).html( '<p>' + inspiro_starter_sites.texts.import_failed_subtitle + '</p>' );
			$( '.js-inspiro-starter-sites-importing' ).hide();
			$( '.js-inspiro-starter-sites-imported' ).show();
		});
	}

	/**
	 * Get the missing required plugin names for the Create Demo Content "plugins to install" notice.
	 *
	 * @param requiredPluginSlugs
	 *
	 * @returns {[]}
	 */
	function getMissingPluginNamesFromImportContentPageItem( requiredPluginSlugs ) {
		var requiredPluginSlugs = requiredPluginSlugs.split( ',' ),
			pluginList = [];

		inspiro_starter_sites.missing_plugins.forEach( function( plugin ) {
			if ( requiredPluginSlugs.indexOf( plugin.slug ) !== -1 ) {
				pluginList.push( plugin.name )
			}
		} );

		return pluginList;
	}

	/**
	 * Unique array helper function.
	 *
	 * @param value
	 * @param index
	 * @param self
	 *
	 * @returns {boolean}
	 */
	function onlyUnique( value, index, self ) {
		return self.indexOf( value ) === index;
	}

	/**
	 * The AJAX call for installing selected plugins.
	 *
	 * @param {Object[]} plugins             The array of plugin objects with name and value pairs.
	 * @param {int}      counter             The index of the plugin to import from the list above.
	 * @param {Object}   $button             jQuery object of the submit button.
	 * @param {bool}     runImport           If the import should be run after plugin installation.
	 * @param {bool}     pluginInstallFailed If there were any failed plugin installs.
	 */
	function installPluginsAjaxCall( plugins, counter, $button , runImport, pluginInstallFailed ) {
		var plugin = plugins[ counter ],
			slug = plugin.name;

		$.ajax({
			method:      'POST',
			url:         inspiro_starter_sites.ajax_url,
			data:        {
				action: 'inspiro_starter_sites_install_plugin',
				security: inspiro_starter_sites.ajax_nonce,
				slug: slug,
			},
			beforeSend:  function() {
				var $currentPluginItem = $( '.plugin-item-' + slug );
				$currentPluginItem.find( '.js-inspiro-starter-sites-plugin-item-info' ).empty();
				$currentPluginItem.find( '.js-inspiro-starter-sites-plugin-item-error' ).empty();
				$currentPluginItem.addClass( 'plugin-item--loading' );
			}
		})
			.done( function( response ) {
				var $currentPluginItem = $( '.plugin-item-' + slug );

				$currentPluginItem.removeClass( 'plugin-item--loading' );

				if ( response.success ) {
					$currentPluginItem.addClass( 'plugin-item--active' );
					$currentPluginItem.find( 'input[type=checkbox]' ).prop( 'disabled', true );
				} else {

					if ( -1 === response.data.indexOf( '<p>' ) ) {
						response.data = '<p>' + response.data + '</p>';
					}

					$currentPluginItem.find( '.js-inspiro-starter-sites-plugin-item-error' ).append( response.data );
					$currentPluginItem.find( 'input[type=checkbox]' ).prop( 'checked', false );
					pluginInstallFailed = true;
				}
			})
			.fail( function( error ) {
				var $currentPluginItem = $( '.plugin-item-' + slug );
				$currentPluginItem.removeClass( 'plugin-item--loading' );
				$currentPluginItem.find( '.js-inspiro-starter-sites-plugin-item-error' ).append( '<p>' + error.statusText + ' (' + error.status + ')</p>' );
				pluginInstallFailed = true;
			})
			.always( function() {
				counter++;

				if ( counter === plugins.length ) {
					if ( runImport ) {
						if ( ! pluginInstallFailed ) {
							startImport( getUrlParameter( 'import' ) );
						} else {
							alert( inspiro_starter_sites.texts.plugin_install_failed );
						}
					}

					$button.removeClass( 'inspiro-starter-sites-button-disabled' );
				} else {
					installPluginsAjaxCall( plugins, counter, $button, runImport, pluginInstallFailed );
				}
			} );
	}

	/**
	 * The AJAX call for importing content on the create demo content page.
	 *
	 * @param {Object[]} items The array of content item objects with name and value pairs.
	 * @param {int}      counter The index of the plugin to import from the list above.
	 * @param {Object}   $button jQuery object of the submit button.
	 */
	function createDemoContentAjaxCall( items, counter, $button ) {
		var item = items[ counter ],
			slug = item.name;

		$.ajax({
			method:      'POST',
			url:         inspiro_starter_sites.ajax_url,
			data:        {
				action: 'inspiro_starter_sites_import_created_content',
				security: inspiro_starter_sites.ajax_nonce,
				slug: slug,
			},
			beforeSend:  function() {
				var $currentItem = $( '.content-item-' + slug );
				$currentItem.find( '.js-inspiro-starter-sites-content-item-info' ).empty();
				$currentItem.find( '.js-inspiro-starter-sites-content-item-error' ).empty();
				$currentItem.addClass( 'content-item--loading' );
			}
		})
			.done( function( response ) {
				if ( response.data && response.data.refresh ) {
					createDemoContentAjaxCall( items, counter, $button );
					return;
				}

				var $currentItem = $( '.content-item-' + slug );

				$currentItem.removeClass( 'content-item--loading' );

				if ( response.success ) {
					$currentItem.find( '.js-inspiro-starter-sites-content-item-info' ).append( '<p>' + inspiro_starter_sites.texts.successful_import + '</p>' );
				} else {
					$currentItem.find( '.js-inspiro-starter-sites-content-item-error' ).append( '<p>' + response.data + '</p>' );
				}
			})
			.fail( function( error ) {
				var $currentItem = $( '.content-item-' + slug );
				$currentItem.removeClass( 'content-item--loading' );
				$currentItem.find( '.js-inspiro-starter-sites-content-item-error' ).append( '<p>' + error.statusText + ' (' + error.status + ')</p>' );
			})
			.always( function( response ) {
				if ( response.data && response.data.refresh ) {
					return;
				}

				counter++;

				if ( counter === items.length ) {
					$button.removeClass( 'inspiro-starter-sites-button-disabled' );
				} else {
					createDemoContentAjaxCall( items, counter, $button );
				}
			} );
	}


	/**
	 * Get the parameter value from the URL.
	 *
	 * @param param
	 * @returns {boolean|string}
	 */
	function getUrlParameter( param ) {
		var sPageURL = window.location.search.substring( 1 ),
			sURLVariables = sPageURL.split( '&' ),
			sParameterName,
			i;

		for ( i = 0; i < sURLVariables.length; i++ ) {
			sParameterName = sURLVariables[ i ].split( '=' );

			if ( sParameterName[0] === param ) {
				return typeof sParameterName[1] === undefined ? true : decodeURIComponent( sParameterName[1] );
			}
		}

		return false;
	}

	/**
	 * Run the main import with a selected predefined demo or with manual files (selected = false).
	 *
	 * Files for the manual import have already been uploaded in the '.js-inspiro-starter-sites-start-manual-import' event above.
	 */
	function startImport( selected ) {
		// Prepare data for the AJAX call
		var data = new FormData();
		data.append( 'action', 'inspiro_starter_sites_import_demo_data' );
		data.append( 'security', inspiro_starter_sites.ajax_nonce );

		if ( selected ) {
			data.append( 'selected', selected );
		}

		// AJAX call to import everything (content, widgets, before/after setup)
		ajaxCall( data );
	}

	/**
	 * ---------------------------------------
	 * ----- "Suggest a new demo" feedback ---
	 * ---------------------------------------
	 *
	 * A small multi-step survey modal. Submits to a remote collector (primary)
	 * and keeps a local WP-option backup. Mirrors the Inspiro theme survey.
	 */
	(function () {
		var config = ( typeof inspiro_starter_sites !== 'undefined' && inspiro_starter_sites.feedback ) ? inspiro_starter_sites.feedback : null;
		if ( ! config ) {
			return;
		}

		var t          = config.texts || {};
		var categories = config.categories || {};
		var site       = config.site || {};
		var $root      = $( '.js-inspiro-starter-sites-feedback-root' );
		if ( ! $root.length ) {
			return;
		}

		var TOTAL_STEPS = 4;
		var currentStep = 1;
		var built       = false;

		function esc( s ) {
			return $( '<div>' ).text( s == null ? '' : String( s ) ).html();
		}

		function buildModal() {
			var npsButtons = '';
			var i;
			for ( i = 0; i <= 10; i++ ) {
				npsButtons += '<button type="button" class="iss-feedback-nps-btn" data-score="' + i + '">' + i + '</button>';
			}

			var categoryItems = '';
			$.each( categories, function ( slug, label ) {
				categoryItems += '<label class="iss-feedback-check">' +
					'<input type="checkbox" name="iss-feedback-category" value="' + esc( slug ) + '"> ' +
					'<span>' + esc( label ) + '</span></label>';
			} );

			var html =
			'<div class="iss-feedback-overlay js-iss-feedback-overlay">' +
				'<div class="iss-feedback-modal" role="dialog" aria-modal="true" aria-label="' + esc( t.title || '' ) + '">' +
					'<button type="button" class="iss-feedback-close js-iss-feedback-close" aria-label="' + esc( t.close || 'Close' ) + '">&times;</button>' +
					'<div class="iss-feedback-header">' +
						'<h2>' + esc( t.title || '' ) + '</h2>' +
						'<p class="iss-feedback-intro">' + esc( t.intro || '' ) + '</p>' +
						'<div class="iss-feedback-progress"><span class="js-iss-feedback-progress-bar"></span></div>' +
					'</div>' +
					'<div class="iss-feedback-body">' +
						'<div class="iss-feedback-step is-active" data-step="1">' +
							'<h3>' + esc( t.q_builder || '' ) + '</h3>' +
							'<label class="iss-feedback-radio"><input type="radio" name="iss-feedback-builder" value="gutenberg"> <span>' + esc( t.builder_gutenberg || '' ) + '</span></label>' +
							'<label class="iss-feedback-radio"><input type="radio" name="iss-feedback-builder" value="elementor"> <span>' + esc( t.builder_elementor || '' ) + '</span></label>' +
							'<label class="iss-feedback-radio"><input type="radio" name="iss-feedback-builder" value="other"> <span>' + esc( t.builder_other || '' ) + '</span></label>' +
							'<input type="text" class="iss-feedback-text js-iss-feedback-builder-other" placeholder="' + esc( t.builder_other_ph || '' ) + '" style="display:none;">' +
						'</div>' +
						'<div class="iss-feedback-step" data-step="2">' +
							'<h3>' + esc( t.q_satisfaction || '' ) + '</h3>' +
							'<div class="iss-feedback-nps js-iss-feedback-nps">' + npsButtons + '</div>' +
							'<div class="iss-feedback-nps-legend"><span>' + esc( t.nps_low || '' ) + '</span><span>' + esc( t.nps_high || '' ) + '</span></div>' +
						'</div>' +
						'<div class="iss-feedback-step" data-step="3">' +
							'<h3>' + esc( t.q_categories || '' ) + '</h3>' +
							'<p class="iss-feedback-hint">' + esc( t.categories_hint || '' ) + '</p>' +
							'<div class="iss-feedback-checks">' + categoryItems +
								'<label class="iss-feedback-check"><input type="checkbox" name="iss-feedback-category" value="other" class="js-iss-feedback-category-other-toggle"> <span>' + esc( t.category_other || '' ) + '</span></label>' +
							'</div>' +
							'<input type="text" class="iss-feedback-text js-iss-feedback-category-other" placeholder="' + esc( t.category_other_ph || '' ) + '" style="display:none;">' +
						'</div>' +
						'<div class="iss-feedback-step" data-step="4">' +
							'<h3>' + esc( t.q_notify || '' ) + '</h3>' +
							'<input type="email" class="iss-feedback-text js-iss-feedback-email" placeholder="' + esc( t.notify_ph || '' ) + '">' +
							'<p class="iss-feedback-hint">' + esc( t.notify_hint || '' ) + '</p>' +
						'</div>' +
						'<div class="iss-feedback-step iss-feedback-success" data-step="success">' +
							'<div class="iss-feedback-success-check">&#10003;</div>' +
							'<h3>' + esc( t.thanks_title || '' ) + '</h3>' +
							'<p>' + esc( t.thanks_text || '' ) + '</p>' +
						'</div>' +
					'</div>' +
					'<div class="iss-feedback-footer">' +
						'<button type="button" class="button iss-feedback-back js-iss-feedback-back">' + esc( t.back || 'Back' ) + '</button>' +
						'<span class="iss-feedback-step-indicator js-iss-feedback-indicator"></span>' +
						'<button type="button" class="button button-primary iss-feedback-next js-iss-feedback-next">' + esc( t.next || 'Next' ) + '</button>' +
						'<button type="button" class="button iss-feedback-skip js-iss-feedback-skip" style="display:none;">' + esc( t.skip || 'Skip' ) + '</button>' +
						'<button type="button" class="button button-primary iss-feedback-submit js-iss-feedback-submit" style="display:none;">' + esc( t.submit || 'Send' ) + '</button>' +
					'</div>' +
				'</div>' +
			'</div>';

			$root.html( html );
			built = true;
		}

		function showStep( step ) {
			currentStep = step;
			$root.find( '.iss-feedback-step' ).removeClass( 'is-active' );
			$root.find( '.iss-feedback-step[data-step="' + step + '"]' ).addClass( 'is-active' );

			var pct = ( step / TOTAL_STEPS ) * 100;
			$root.find( '.js-iss-feedback-progress-bar' ).css( 'width', pct + '%' );
			$root.find( '.js-iss-feedback-indicator' ).text( step + ' / ' + TOTAL_STEPS );

			// Only the final (email) step submits. It offers two actions:
			// "Subscribe & Send" (with email) and "Skip" (without email).
			var isLast = step === TOTAL_STEPS;

			$root.find( '.js-iss-feedback-back' ).css( 'visibility', step > 1 ? 'visible' : 'hidden' );
			$root.find( '.js-iss-feedback-next' ).toggle( ! isLast );
			$root.find( '.js-iss-feedback-submit' ).toggle( isLast );
			$root.find( '.js-iss-feedback-skip' ).toggle( isLast );
		}

		function openModal() {
			if ( ! built ) {
				buildModal();
			}
			showStep( 1 );
			$root.removeAttr( 'hidden' ).addClass( 'is-open' );
			$( 'body' ).addClass( 'iss-feedback-open' );
		}

		function closeModal() {
			$root.removeClass( 'is-open' ).attr( 'hidden', 'hidden' );
			$( 'body' ).removeClass( 'iss-feedback-open' );
		}

		function collectData() {
			var builder      = $root.find( 'input[name="iss-feedback-builder"]:checked' ).val() || '';
			var builderOther = $.trim( $root.find( '.js-iss-feedback-builder-other' ).val() || '' );
			var score        = $root.find( '.iss-feedback-nps-btn.is-active' ).attr( 'data-score' );
			var cats         = [];
			$root.find( 'input[name="iss-feedback-category"]:checked' ).each( function () {
				cats.push( $( this ).val() );
			} );
			var catOther = $.trim( $root.find( '.js-iss-feedback-category-other' ).val() || '' );
			var email    = $.trim( $root.find( '.js-iss-feedback-email' ).val() || '' );

			return {
				type:                       'demo_feedback',
				page_builder:               builder,
				page_builder_other:         builderOther,
				satisfaction_score:         ( typeof score !== 'undefined' ) ? score : '',
				requested_categories:       cats.join( ', ' ),
				requested_categories_other: catOther,
				email:                      email,
				domain:                     site.domain || '',
				hostname:                   site.hostname || '',
				plugin_version:             site.plugin_version || '',
				wp_version:                 site.wp_version || '',
				php_version:                site.php_version || '',
				language:                   site.language || '',
				user_agent:                 navigator.userAgent || '',
				referrer:                   document.referrer || '',
				submitted_at:               new Date().toISOString()
			};
		}

		function submitFeedback( includeEmail ) {
			var data = collectData();
			if ( ! includeEmail ) {
				data.email = '';
			}

			// (a) Remote collection (primary). FormData avoids a CORS preflight.
			if ( config.endpoint ) {
				var fd = new FormData();
				var k;
				for ( k in data ) {
					if ( data.hasOwnProperty( k ) ) {
						fd.append( k, data[ k ] );
					}
				}
				$.ajax( {
					url:         config.endpoint,
					type:        'POST',
					data:        fd,
					processData: false,
					contentType: false,
					timeout:     6000
				} );
			}

			// (b) Local backup via WP AJAX (fire and forget).
			if ( typeof inspiro_starter_sites !== 'undefined' && inspiro_starter_sites.ajax_url ) {
				$.post( inspiro_starter_sites.ajax_url, {
					action:   'inspiro_starter_sites_demo_feedback',
					security: inspiro_starter_sites.ajax_nonce,
					payload:  JSON.stringify( data )
				} );
			}

			// Show the thank-you screen.
			$root.find( '.iss-feedback-step' ).removeClass( 'is-active' );
			$root.find( '.iss-feedback-step[data-step="success"]' ).addClass( 'is-active' );
			$root.find( '.iss-feedback-footer' ).hide();
		}

		$( document ).on( 'click', '.js-inspiro-starter-sites-suggest-demo', function ( e ) {
			e.preventDefault();
			openModal();
		} );

		$root.on( 'click', '.js-iss-feedback-close, .js-iss-feedback-overlay', function ( e ) {
			if ( e.target === this ) {
				closeModal();
			}
		} );

		$( document ).on( 'keyup', function ( e ) {
			if ( 27 === e.keyCode && $root.hasClass( 'is-open' ) ) {
				closeModal();
			}
		} );

		$root.on( 'click', '.js-iss-feedback-next', function () {
			if ( currentStep < TOTAL_STEPS ) {
				showStep( currentStep + 1 );
			}
		} );

		$root.on( 'click', '.js-iss-feedback-back', function () {
			if ( currentStep > 1 ) {
				showStep( currentStep - 1 );
			}
		} );

		// "Subscribe & Send" — submit with the email; "Skip" — submit without it.
		$root.on( 'click', '.js-iss-feedback-submit', function () {
			submitFeedback( true );
		} );

		$root.on( 'click', '.js-iss-feedback-skip', function () {
			submitFeedback( false );
		} );

		// Page builder "Other" free-text toggle.
		$root.on( 'change', 'input[name="iss-feedback-builder"]', function () {
			$root.find( '.js-iss-feedback-builder-other' ).toggle( 'other' === $( this ).val() );
		} );

		// NPS selection.
		$root.on( 'click', '.iss-feedback-nps-btn', function () {
			$root.find( '.iss-feedback-nps-btn' ).removeClass( 'is-active' );
			$( this ).addClass( 'is-active' );
		} );

		// Category "Other" free-text toggle.
		$root.on( 'change', '.js-iss-feedback-category-other-toggle', function () {
			$root.find( '.js-iss-feedback-category-other' ).toggle( $( this ).is( ':checked' ) );
		} );
	}());
} );
