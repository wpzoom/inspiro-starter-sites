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
				<svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
					<path d="M10 2.5l1.6 4.4 4.4 1.6-4.4 1.6L10 14.5l-1.6-4.4L4 8.5l4.4-1.6zM15.5 13l.7 1.8 1.8.7-1.8.7-.7 1.8-.7-1.8-1.8-.7 1.8-.7z" fill="currentColor" />
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
