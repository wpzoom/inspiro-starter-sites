/**
 * AI page builder — block editor entry point.
 *
 * Mounts a "Generate with AI" button in the editor header and opens the
 * page builder modal. ?iss_ai=1 (the "New page with AI" action on the
 * Pages screen) opens the modal as soon as the editor is ready.
 */

import { __ } from '@wordpress/i18n';
import { createRoot, useState, useEffect } from '@wordpress/element';
import { Button } from '@wordpress/components';
import domReady from '@wordpress/dom-ready';

import PageBuilderModal from './modal';
import { config } from './api';
import './style.scss';

// The button can be re-mounted when the editor re-renders its header;
// the "New page with AI" auto-open must still happen only once.
let autoOpened = false;

function App() {
	const [ open, setOpen ] = useState( false );

	useEffect( () => {
		if ( config.autoOpen && ! autoOpened ) {
			autoOpened = true;
			setOpen( true );
		}
	}, [] );

	return (
		<>
			<Button
				className="iss-pb-toolbar-button"
				onClick={ () => setOpen( true ) }
			>
				{ /* The Inspiro mark, like the Inspiro Patterns button beside it. */ }
				<svg width="20" height="20" viewBox="0 0 32 32" fill="none" aria-hidden="true" focusable="false">
					<path fillRule="evenodd" clipRule="evenodd" d="M16 32C24.8366 32 32 24.8366 32 16C32 7.16344 24.8366 0 16 0C7.16344 0 0 7.16344 0 16C0 24.8366 7.16344 32 16 32ZM13.4982 7.17576C13.4982 5.78333 14.627 4.65455 16.0194 4.65455H18.2885C18.4277 4.65455 18.5406 4.76742 18.5406 4.90667V9.39303C18.5406 9.47618 18.4962 9.55301 18.4242 9.59458L13.8473 12.2371C13.6921 12.3267 13.4982 12.2147 13.4982 12.0355V7.17576ZM13.6145 16.7383L18.1915 14.0958C18.3467 14.0062 18.5406 14.1182 18.5406 14.2974V24.8242C18.5406 26.2167 17.4118 27.3455 16.0194 27.3455H13.7503C13.6111 27.3455 13.4982 27.2326 13.4982 27.0933V16.9399C13.4982 16.8567 13.5425 16.7799 13.6145 16.7383Z" fill="currentColor" />
				</svg>
				{ __( 'Generate with AI', 'inspiro-starter-sites' ) }
			</Button>
			{ open && <PageBuilderModal onClose={ () => setOpen( false ) } /> }
		</>
	);
}

const MOUNT_ID = 'inspiro-starter-sites-page-builder-mount';

// One node and one React root for the page's lifetime.
let node = null;
let scheduled = false;

/**
 * Put the button at the end of the header's left tool group (after the
 * inserter, undo/redo and list view), where the Inspiro Patterns button
 * sits too. The editor re-renders that group now and then and drops
 * foreign nodes; the same, already-rendered node is put back, so the
 * button never re-paints (a fresh root would render a frame later and
 * blink).
 */
function attach() {
	scheduled = false;
	const group = document.querySelector( '.editor-document-tools__left, .edit-post-header-toolbar__left' );
	if ( ! group ) {
		return;
	}
	if ( ! node ) {
		node = document.createElement( 'div' );
		node.id = MOUNT_ID;
		node.className = 'iss-pb-mount';
		group.appendChild( node );
		createRoot( node ).render( <App /> );
		return;
	}
	if ( node.parentNode !== group ) {
		group.appendChild( node );
	}
}

// Mutations come in bursts while the editor boots or re-renders: check at
// most once per ~frame. (A timer, not requestAnimationFrame, which never
// fires in a background tab.)
function schedule() {
	if ( scheduled ) {
		return;
	}
	if ( node && node.isConnected && node.parentNode && node.parentNode.matches( '.editor-document-tools__left, .edit-post-header-toolbar__left' ) ) {
		return;
	}
	scheduled = true;
	window.setTimeout( attach, 16 );
}

domReady( () => {
	attach();
	new MutationObserver( schedule ).observe( document.body, { childList: true, subtree: true } );
} );
