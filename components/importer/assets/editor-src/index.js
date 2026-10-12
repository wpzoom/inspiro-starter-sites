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

function App() {
	const [ open, setOpen ] = useState( false );

	useEffect( () => {
		if ( config.autoOpen ) {
			setOpen( true );
		}
	}, [] );

	return (
		<>
			<Button
				className="iss-pb-toolbar-button"
				variant="secondary"
				size="compact"
				icon="superhero"
				onClick={ () => setOpen( true ) }
			>
				{ __( 'Generate with AI', 'inspiro-starter-sites' ) }
			</Button>
			{ open && <PageBuilderModal onClose={ () => setOpen( false ) } /> }
		</>
	);
}

const MOUNT_ID = 'inspiro-starter-sites-page-builder-mount';

/**
 * The header toolbar renders after the editor boots; keep looking until
 * it is there (and come back if the editor re-renders it).
 */
function mount() {
	if ( document.getElementById( MOUNT_ID ) ) {
		return true;
	}
	const toolbar = document.querySelector( '.editor-header__toolbar, .edit-post-header-toolbar, .editor-header__center' );
	if ( ! toolbar ) {
		return false;
	}
	const node = document.createElement( 'div' );
	node.id = MOUNT_ID;
	node.className = 'iss-pb-mount';
	// After the document tools (inserter, undo, redo, list view), like other
	// plugins' header buttons; appended last when that group is not there.
	const tools = toolbar.querySelector( '.editor-document-tools, .edit-post-header-toolbar__left' );
	if ( tools && tools.parentNode === toolbar ) {
		tools.insertAdjacentElement( 'afterend', node );
	} else {
		toolbar.appendChild( node );
	}
	createRoot( node ).render( <App /> );
	return true;
}

domReady( () => {
	if ( mount() ) {
		return;
	}
	const observer = new MutationObserver( () => {
		if ( mount() ) {
			observer.disconnect();
		}
	} );
	observer.observe( document.body, { childList: true, subtree: true } );
	setTimeout( () => observer.disconnect(), 30000 );
} );
