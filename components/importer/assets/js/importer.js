jQuery( function ( $ ) {
	'use strict';

	/**
	 * ---------------------------------------
	 * ------------- DOM Ready ---------------
	 * ---------------------------------------
	 */

	// Move the admin notices inside the appropriate div.
	$( '.js-inspiro-starter-sites-notice-wrapper' ).appendTo( '.js-inspiro-starter-sites-admin-notices-container' );

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
} );
