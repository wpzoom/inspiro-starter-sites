jQuery( function ( $ ) {
	'use strict';

	$( '.js-wpzi-notice-wrapper' ).appendTo( '.js-wpzi-admin-notices-container' );
	if ( $( '.js-wpzi-auto-start-manual-import' ).length ) {
		startImport( false );
	}

	/**
	 * Dont allow to  uncheck the required plugins.
	 */
	$( '.wpzi-install-plugins-content-content .plugin-item.plugin-item--required input[type=checkbox]' ).on( 'click', function( event ) {
		event.preventDefault();

		return false;
	} );

	/**
	 * Install all plugins before importing event.
	 */
	$( '.js-wpzi-install-plugins' ).on( 'click', function( event ) {
		event.preventDefault();
		var $button = $( this );

		if ( $button.hasClass( 'wpzi-button-disabled' ) ) {
			return false;
		}

		var pluginsToInstall = $( '.wpzi-install-plugins-content-content .plugin-item input[type=checkbox]' ).serializeArray();

		if ( pluginsToInstall.length === 0 ) {
			return false;
		}

		$button.addClass( 'wpzi-button-disabled' );

		installPluginsAjaxCall( pluginsToInstall, 0, $button, false, false );
	} );

	/**
	 * Install plugins before importing event.
	 */
	$( '.js-wpzi-install-plugins-before-import' ).on( 'click', function( event ) {
		event.preventDefault();

		var $button = $( this );

		if ( $button.hasClass( 'wpzi-button-disabled' ) ) {
			return false;
		}

		var pluginsToInstall = $( '.wpzi-install-plugins-content-content .plugin-item:not(.plugin-item--disabled) input[type=checkbox]' ).serializeArray();

		if ( pluginsToInstall.length === 0 ) {
			startImport( getUrlParameter( 'import' ) );

			return false;
		}

		$button.addClass( 'wpzi-button-disabled' );

		installPluginsAjaxCall( pluginsToInstall, 0, $button, true, false );
	} );


	/**
	 * Import the created content.
	 */
	$( '.js-wpzi-create-content' ).on( 'click', function( event ) {
		event.preventDefault();

		var $button = $( this );

		if ( $button.hasClass( 'wpzi-button-disabled' ) ) {
			return false;
		}

		var itemsToImport = $( '.wpzi-create-content-content .content-item input[type=checkbox]' ).serializeArray();

		if ( itemsToImport.length === 0 ) {
			return false;
		}

		$button.addClass( 'wpzi-button-disabled' );

		createDemoContentAjaxCall( itemsToImport, 0, $button );
	} );

	/**
	 * Update "plugins to be installed" notice on Create Demo Content page.
	 */
	$( document ).on( 'change', '.wpzi--create-content .content-item input[type=checkbox]', function( event ) {
		var $checkboxes = $( '.wpzi--create-content .content-item input[type=checkbox]' ),
			$missingPluginNotice = $( '.js-wpzi-create-content-install-plugins-notice' ),
			missingPlugins = [];

		$checkboxes.each( function() {
			var $checkbox = $( this );
			if ( $checkbox.is( ':checked' ) ) {
				missingPlugins = missingPlugins.concat( getMissingPluginNamesFromImportContentPageItem( $checkbox.data( 'plugins' ) ) );
			}
		} );

		missingPlugins = missingPlugins.filter( onlyUnique ).join( ', ' );

		if ( missingPlugins.length > 0 ) {
			$missingPluginNotice.find( '.js-wpzi-create-content-install-plugins-list' ).text( missingPlugins );
			$missingPluginNotice.show();
		} else {
			$missingPluginNotice.find( '.js-wpzi-create-content-install-plugins-list' ).text( '' );
			$missingPluginNotice.hide();
		}
	} );

	/**
	 * The main AJAX call, which executes the import process.
	 *
	 * @param FormData data The data to be passed to the AJAX call.
	 */
	function ajaxCall( data ) {
		$.ajax({
			method:      'POST',
			url:         wpzi.ajax_url,
			data:        data,
			contentType: false,
			processData: false,
			beforeSend:  function() {
				$( '.js-wpzi-install-plugins-content' ).hide();
				$( '.js-wpzi-importing' ).show();
			}
		})
		.done( function( response ) {
			if ( 'undefined' !== typeof response.status && 'newAJAX' === response.status ) {
				ajaxCall( data );
			}
			else if ( 'undefined' !== typeof response.status && 'customizerAJAX' === response.status ) {
				// Fix for data.set and data.delete, which they are not supported in some browsers.
				var newData = new FormData();
				newData.append( 'action', 'wpzi_import_customizer_data' );
				newData.append( 'security', wpzi.ajax_nonce );

				// Set the wp_customize=on only if the plugin filter is set to true.
				if ( true === wpzi.wp_customize_on ) {
					newData.append( 'wp_customize', 'on' );
				}

				ajaxCall( newData );
			}
			else if ( 'undefined' !== typeof response.status && 'afterAllImportAJAX' === response.status ) {
				// Fix for data.set and data.delete, which they are not supported in some browsers.
				var newData = new FormData();
				newData.append( 'action', 'wpzi_after_import_data' );
				newData.append( 'security', wpzi.ajax_nonce );
				ajaxCall( newData );
			}
			else if ( 'undefined' !== typeof response.message ) {
				$( '.js-wpzi-ajax-response' ).append( response.message );

				if ( 'undefined' !== typeof response.title ) {
					$( '.js-wpzi-ajax-response-title' ).html( response.title );
				}

				if ( 'undefined' !== typeof response.subtitle ) {
					$( '.js-wpzi-ajax-response-subtitle' ).html( response.subtitle );
				}

				$( '.js-wpzi-importing' ).hide();
				$( '.js-wpzi-imported' ).show();

				$( document ).trigger( 'wpziImportComplete' );
			}
			else {
				$( '.js-wpzi-ajax-response' ).append( '<img class="wpzi-imported-content-imported wpzi-imported-content-imported--error" src="' + wpzi.plugin_url + 'assets/images/error.svg" alt="' + wpzi.texts.import_failed + '"><p>' + response + '</p>' );
				$( '.js-wpzi-ajax-response-title' ).html( wpzi.texts.import_failed );
				$( '.js-wpzi-ajax-response-subtitle' ).html( '<p>' + wpzi.texts.import_failed_subtitle + '</p>' );
				$( '.js-wpzi-importing' ).hide();
				$( '.js-wpzi-imported' ).show();
			}
		})
		.fail( function( error ) {
			$( '.js-wpzi-ajax-response' ).append( '<img class="wpzi-imported-content-imported wpzi-imported-content-imported--error" src="' + wpzi.plugin_url + 'assets/images/error.svg" alt="' + wpzi.texts.import_failed + '"><p>Error: ' + error.statusText + ' (' + error.status + ')' + '</p>' );
			$( '.js-wpzi-ajax-response-title' ).html( wpzi.texts.import_failed );
			$( '.js-wpzi-ajax-response-subtitle' ).html( '<p>' + wpzi.texts.import_failed_subtitle + '</p>' );
			$( '.js-wpzi-importing' ).hide();
			$( '.js-wpzi-imported' ).show();
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

		wpzi.missing_plugins.forEach( function( plugin ) {
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
			url:         wpzi.ajax_url,
			data:        {
				action: 'wpzi_install_plugin',
				security: wpzi.ajax_nonce,
				slug: slug,
			},
			beforeSend:  function() {
				var $currentPluginItem = $( '.plugin-item-' + slug );
				$currentPluginItem.find( '.js-wpzi-plugin-item-info' ).empty();
				$currentPluginItem.find( '.js-wpzi-plugin-item-error' ).empty();
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

					$currentPluginItem.find( '.js-wpzi-plugin-item-error' ).append( response.data );
					$currentPluginItem.find( 'input[type=checkbox]' ).prop( 'checked', false );
					pluginInstallFailed = true;
				}
			})
			.fail( function( error ) {
				var $currentPluginItem = $( '.plugin-item-' + slug );
				$currentPluginItem.removeClass( 'plugin-item--loading' );
				$currentPluginItem.find( '.js-wpzi-plugin-item-error' ).append( '<p>' + error.statusText + ' (' + error.status + ')</p>' );
				pluginInstallFailed = true;
			})
			.always( function() {
				counter++;

				if ( counter === plugins.length ) {
					if ( runImport ) {
						if ( ! pluginInstallFailed ) {
							startImport( getUrlParameter( 'import' ) );
						} else {
							alert( wpzi.texts.plugin_install_failed );
						}
					}

					$button.removeClass( 'wpzi-button-disabled' );
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
			url:         wpzi.ajax_url,
			data:        {
				action: 'wpzi_import_created_content',
				security: wpzi.ajax_nonce,
				slug: slug,
			},
			beforeSend:  function() {
				var $currentItem = $( '.content-item-' + slug );
				$currentItem.find( '.js-wpzi-content-item-info' ).empty();
				$currentItem.find( '.js-wpzi-content-item-error' ).empty();
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
					$currentItem.find( '.js-wpzi-content-item-info' ).append( '<p>' + wpzi.texts.successful_import + '</p>' );
				} else {
					$currentItem.find( '.js-wpzi-content-item-error' ).append( '<p>' + response.data + '</p>' );
				}
			})
			.fail( function( error ) {
				var $currentItem = $( '.content-item-' + slug );
				$currentItem.removeClass( 'content-item--loading' );
				$currentItem.find( '.js-wpzi-content-item-error' ).append( '<p>' + error.statusText + ' (' + error.status + ')</p>' );
			})
			.always( function( response ) {
				if ( response.data && response.data.refresh ) {
					return;
				}

				counter++;

				if ( counter === items.length ) {
					$button.removeClass( 'wpzi-button-disabled' );
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
	 * Files for the manual import have already been uploaded in the '.js-wpzi-start-manual-import' event above.
	 */
	function startImport( selected ) {
		// Prepare data for the AJAX call
		var data = new FormData();
		data.append( 'action', 'wpzi_import_demo_data' );
		data.append( 'security', wpzi.ajax_nonce );

		if ( selected ) {
			data.append( 'selected', selected );
		}

		// AJAX call to import everything (content, widgets, before/after setup)
		ajaxCall( data );
	}
} );
