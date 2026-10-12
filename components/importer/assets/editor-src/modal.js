/**
 * The page builder modal: describe → plan → build & insert.
 */

import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useMemo, useRef, useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { parse, createBlock } from '@wordpress/blocks';
import { BlockPreview } from '@wordpress/block-editor';
import {
	Modal,
	Button,
	TextareaControl,
	TextControl,
	CheckboxControl,
	Spinner,
	Notice,
	Dashicon,
} from '@wordpress/components';

import { api, config } from './api';
import { suggest } from './matcher';

const PAGE_TYPES = [
	{ value: 'auto', label: __( 'Let the AI decide', 'inspiro-starter-sites' ) },
	{ value: 'home', label: __( 'Homepage', 'inspiro-starter-sites' ) },
	{ value: 'landing', label: __( 'Landing page', 'inspiro-starter-sites' ) },
	{ value: 'inner', label: __( 'Inner page', 'inspiro-starter-sites' ) },
	{ value: 'sections', label: __( 'Sections only', 'inspiro-starter-sites' ) },
];

const STYLES = [
	{ value: '', label: __( 'Any style', 'inspiro-starter-sites' ) },
	{ value: 'light', label: __( 'Light & airy', 'inspiro-starter-sites' ) },
	{ value: 'dark', label: __( 'Dark & moody', 'inspiro-starter-sites' ) },
	{ value: 'image', label: __( 'Image-led', 'inspiro-starter-sites' ) },
	{ value: 'minimal', label: __( 'Minimal', 'inspiro-starter-sites' ) },
];

const ROLE_LABELS = {
	hero: __( 'Hero', 'inspiro-starter-sites' ),
	'page-header': __( 'Page header', 'inspiro-starter-sites' ),
	about: __( 'About', 'inspiro-starter-sites' ),
	team: __( 'Team', 'inspiro-starter-sites' ),
	services: __( 'Services', 'inspiro-starter-sites' ),
	features: __( 'Features', 'inspiro-starter-sites' ),
	testimonials: __( 'Testimonials', 'inspiro-starter-sites' ),
	pricing: __( 'Pricing', 'inspiro-starter-sites' ),
	faq: __( 'FAQ', 'inspiro-starter-sites' ),
	cta: __( 'Call to action', 'inspiro-starter-sites' ),
	contact: __( 'Contact', 'inspiro-starter-sites' ),
	process: __( 'Process', 'inspiro-starter-sites' ),
	stats: __( 'Numbers', 'inspiro-starter-sites' ),
	portfolio: __( 'Portfolio', 'inspiro-starter-sites' ),
	gallery: __( 'Gallery', 'inspiro-starter-sites' ),
	blog: __( 'Blog', 'inspiro-starter-sites' ),
	logos: __( 'Logos', 'inspiro-starter-sites' ),
	menu: __( 'Menu', 'inspiro-starter-sites' ),
	events: __( 'Events', 'inspiro-starter-sites' ),
	intro: __( 'Intro', 'inspiro-starter-sites' ),
};

const roleLabel = ( role ) => ROLE_LABELS[ role ] || role;

/* -------------------------------------------------------------------------
 * Small pieces
 * ---------------------------------------------------------------------- */

function Chips( { options, value, onChange } ) {
	return (
		<div className="iss-pb-chips" role="group">
			{ options.map( ( opt ) => (
				<button
					key={ opt.value }
					type="button"
					className={ 'iss-pb-chip' + ( value === opt.value ? ' is-active' : '' ) }
					aria-pressed={ value === opt.value }
					onClick={ () => onChange( opt.value ) }
				>
					{ opt.label }
				</button>
			) ) }
		</div>
	);
}

function SectionCard( { section, pinned, onToggle, compact } ) {
	const locked = section.locked;
	const unavailable = ! locked && ! section.available;
	return (
		<button
			type="button"
			className={
				'iss-pb-card' +
				( pinned ? ' is-pinned' : '' ) +
				( locked ? ' is-locked' : '' ) +
				( unavailable ? ' is-unavailable' : '' ) +
				( compact ? ' is-compact' : '' )
			}
			onClick={ () => ! locked && ! unavailable && onToggle( section.id ) }
			title={ locked ? __( 'Included with Inspiro Premium', 'inspiro-starter-sites' ) : section.description }
			aria-pressed={ pinned }
		>
			<span className={ 'iss-pb-card__tone iss-pb-card__tone--' + section.tone } aria-hidden="true" />
			<span className="iss-pb-card__body">
				<span className="iss-pb-card__role">
					{ roleLabel( section.role ) }
					{ locked && <Dashicon icon="lock" size={ 14 } /> }
				</span>
				<span className="iss-pb-card__desc">{ section.description }</span>
			</span>
			{ pinned && <Dashicon icon="yes" className="iss-pb-card__check" /> }
		</button>
	);
}

function QuotaCard( { quota, onConnect } ) {
	if ( ! quota ) {
		return null;
	}
	if ( ! quota.connected ) {
		return (
			<div className="iss-pb-quota iss-pb-quota--connect">
				<span>{ __( 'Connect with your email to use free AI page generations.', 'inspiro-starter-sites' ) }</span>
				<Button variant="secondary" size="compact" onClick={ onConnect }>
					{ __( 'Connect', 'inspiro-starter-sites' ) }
				</Button>
			</div>
		);
	}
	const exhausted = quota.limit > 0 && quota.remaining <= 0;
	return (
		<div className={ 'iss-pb-quota' + ( exhausted ? ' is-exhausted' : '' ) }>
			<span>
				{ sprintf(
					/* translators: 1: used, 2: limit */
					__( '%1$d of %2$d AI page generations used', 'inspiro-starter-sites' ),
					quota.used,
					quota.limit
				) }
				{ quota.licensed ? ' · ' + __( 'Inspiro Premium', 'inspiro-starter-sites' ) : '' }
			</span>
			{ exhausted && <Upsell quota={ quota } /> }
		</div>
	);
}

function Upsell( { quota } ) {
	if ( quota && quota.licensed ) {
		return <span className="iss-pb-upsell">{ __( 'You have used all the page generations included with your license.', 'inspiro-starter-sites' ) }</span>;
	}
	if ( config.premiumTheme && ! config.hasLicense ) {
		return (
			<span className="iss-pb-upsell">
				{ __( 'Activate your Inspiro Premium license for more generations.', 'inspiro-starter-sites' ) }{ ' ' }
				<a href={ config.licenseUrl }>{ __( 'Activate license', 'inspiro-starter-sites' ) }</a>
			</span>
		);
	}
	return (
		<span className="iss-pb-upsell">
			{ __( 'Want to generate more? Inspiro Premium includes extra AI generations and the full section library.', 'inspiro-starter-sites' ) }{ ' ' }
			<a href={ config.upgradeUrl } target="_blank" rel="noreferrer">{ __( 'Upgrade to Inspiro Premium', 'inspiro-starter-sites' ) }</a>
		</span>
	);
}

function ConnectForm( { onDone, onCancel } ) {
	const [ email, setEmail ] = useState( config.email || '' );
	const [ consent, setConsent ] = useState( false );
	const [ code, setCode ] = useState( '' );
	const [ pending, setPending ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const submit = async () => {
		setBusy( true );
		setError( '' );
		try {
			if ( pending ) {
				await api.verify( code );
				onDone();
			} else {
				const res = await api.connect( email, consent );
				if ( res.pending ) {
					setPending( true );
				} else {
					onDone();
				}
			}
		} catch ( e ) {
			setError( e.message );
		}
		setBusy( false );
	};

	return (
		<div className="iss-pb-connect">
			<h3>{ __( 'Connect to the WPZOOM AI service', 'inspiro-starter-sites' ) }</h3>
			<p>{ __( 'Free AI page generations are tied to your email. We send a one-time code to confirm it.', 'inspiro-starter-sites' ) }</p>
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! pending ? (
				<>
					<TextControl label={ __( 'Email address', 'inspiro-starter-sites' ) } type="email" value={ email } onChange={ setEmail } />
					<CheckboxControl
						label={ __( 'Send me occasional tips and updates from WPZOOM (optional)', 'inspiro-starter-sites' ) }
						checked={ consent }
						onChange={ setConsent }
					/>
				</>
			) : (
				<TextControl
					label={ sprintf( /* translators: email */ __( 'Enter the 6-digit code sent to %s', 'inspiro-starter-sites' ), email ) }
					value={ code }
					onChange={ setCode }
					inputMode="numeric"
				/>
			) }
			<div className="iss-pb-actions">
				<Button variant="primary" onClick={ submit } isBusy={ busy } disabled={ busy || ( pending ? code.length < 6 : ! email ) }>
					{ pending ? __( 'Confirm', 'inspiro-starter-sites' ) : __( 'Send code', 'inspiro-starter-sites' ) }
				</Button>
				<Button variant="tertiary" onClick={ onCancel }>{ __( 'Back', 'inspiro-starter-sites' ) }</Button>
			</div>
		</div>
	);
}

function RowPreview( { markup, css } ) {
	const blocks = useMemo( () => ( markup ? parse( markup ) : [] ), [ markup ] );
	if ( ! markup ) {
		return <div className="iss-pb-row__preview is-loading"><Spinner /></div>;
	}
	return (
		<div className="iss-pb-row__preview">
			<BlockPreview blocks={ blocks } viewportWidth={ 1280 } additionalStyles={ css ? [ { css } ] : [] } />
		</div>
	);
}

/* -------------------------------------------------------------------------
 * The modal
 * ---------------------------------------------------------------------- */

export default function PageBuilderModal( { onClose } ) {
	const [ step, setStep ] = useState( 'describe' ); // describe | connect | plan | building
	const [ description, setDescription ] = useState( '' );
	const [ pageType, setPageType ] = useState( 'auto' );
	const [ style, setStyle ] = useState( '' );
	const [ pinned, setPinned ] = useState( [] );
	const [ avoid, setAvoid ] = useState( [] );
	const [ index, setIndex ] = useState( [] );
	const [ quota, setQuota ] = useState( null );
	const [ plan, setPlan ] = useState( null );
	const [ previews, setPreviews ] = useState( {} );
	const [ previewCss, setPreviewCss ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ progress, setProgress ] = useState( '' );
	const returnTo = useRef( 'describe' );

	const { insertBlocks, replaceBlocks } = useDispatch( 'core/block-editor' );
	const { editPost } = useDispatch( 'core/editor' );
	const { createSuccessNotice } = useDispatch( 'core/notices' );
	const { blocks, title } = useSelect( ( select ) => ( {
		blocks: select( 'core/block-editor' ).getBlocks(),
		title: select( 'core/editor' ).getEditedPostAttribute( 'title' ),
	} ), [] );

	// Catalog index and quota, once.
	useEffect( () => {
		api.catalog().then( ( data ) => setIndex( data.sections || [] ) ).catch( ( e ) => setError( e.message ) );
		refreshQuota();
	}, [] );

	const refreshQuota = useCallback( () => {
		api.quota().then( setQuota ).catch( () => setQuota( null ) );
	}, [] );

	const suggestions = useMemo( () => suggest( description, index, pinned ), [ description, index, pinned ] );

	const togglePin = ( id ) => setPinned( ( list ) => ( list.includes( id ) ? list.filter( ( x ) => x !== id ) : [ ...list, id ] ) );

	const loadPreviews = useCallback( ( ids ) => {
		const missing = ids.filter( ( id ) => ! previews[ id ] );
		if ( ! missing.length ) {
			return;
		}
		api.preview( missing ).then( ( data ) => {
			setPreviews( ( prev ) => ( { ...prev, ...( data.markup || {} ) } ) );
			if ( data.css ) {
				setPreviewCss( data.css );
			}
		} ).catch( () => {} );
	}, [ previews ] );

	const handleError = ( e, from ) => {
		if ( e.code === 'registration_required' ) {
			returnTo.current = from;
			setStep( 'connect' );
			return;
		}
		if ( e.code === 'quota_exhausted' ) {
			refreshQuota();
		}
		setError( e.message );
	};

	const doPlan = async ( extraAvoid = [] ) => {
		setBusy( true );
		setError( '' );
		setProgress( __( 'Planning your page…', 'inspiro-starter-sites' ) );
		const avoidIds = [ ...avoid, ...extraAvoid ];
		try {
			const result = await api.plan( { description, page_type: pageType, style, pinned, avoid: avoidIds } );
			setPlan( result );
			setAvoid( avoidIds );
			setStep( 'plan' );
			loadPreviews( result.sections.map( ( s ) => s.id ) );
		} catch ( e ) {
			handleError( e, 'describe' );
		}
		setBusy( false );
		setProgress( '' );
	};

	const doBuild = async () => {
		setBusy( true );
		setError( '' );
		setStep( 'building' );
		setProgress( __( 'Writing the copy and finding photos… this takes about a minute.', 'inspiro-starter-sites' ) );
		try {
			const result = await api.build( plan, config.postId );
			insertResult( result );
			if ( result.quota ) {
				setQuota( ( q ) => ( { ...( q || {} ), connected: true, ...result.quota } ) );
			}
			createSuccessNotice(
				sprintf(
					/* translators: number of sections */
					__( 'Page inserted: %d sections. Review the copy and photos, then publish.', 'inspiro-starter-sites' ),
					( result.sections || [] ).length
				),
				{ type: 'snackbar' }
			);
			onClose();
		} catch ( e ) {
			setStep( 'plan' );
			handleError( e, 'plan' );
		}
		setBusy( false );
		setProgress( '' );
	};

	const insertResult = ( result ) => {
		const newBlocks = parse( result.markup || '' );
		if ( ! newBlocks.length ) {
			throw { code: 'empty', message: __( 'The AI returned no usable blocks.', 'inspiro-starter-sites' ) };
		}
		injectCss( result.css );
		const isEmptyEditor = blocks.length === 0 || ( blocks.length === 1 && blocks[ 0 ].name === 'core/paragraph' && ! ( blocks[ 0 ].attributes.content || '' ).toString().trim() );
		if ( isEmptyEditor && blocks.length ) {
			replaceBlocks( blocks.map( ( b ) => b.clientId ), newBlocks );
		} else {
			insertBlocks( newBlocks );
		}
		if ( ! ( title || '' ).trim() && result.title ) {
			editPost( { title: result.title } );
		}
	};

	/* Plan editing ------------------------------------------------------ */

	const updateSections = ( fn ) => setPlan( ( p ) => ( { ...p, sections: fn( p.sections ) } ) );
	const move = ( i, dir ) => updateSections( ( list ) => {
		const j = i + dir;
		if ( j < 0 || j >= list.length ) {
			return list;
		}
		const copy = [ ...list ];
		[ copy[ i ], copy[ j ] ] = [ copy[ j ], copy[ i ] ];
		return copy;
	} );
	const remove = ( i ) => updateSections( ( list ) => list.filter( ( _, k ) => k !== i ) );
	const setHeadline = ( i, headline ) => updateSections( ( list ) => list.map( ( s, k ) => ( k === i ? { ...s, headline } : s ) ) );
	const swap = ( i ) => updateSections( ( list ) => {
		const current = list[ i ];
		const used = list.map( ( s ) => s.id );
		const pool = index.filter( ( s ) => s.role === current.role && s.available && ! used.includes( s.id ) );
		if ( ! pool.length ) {
			return list;
		}
		const next = pool[ ( pool.findIndex( ( s ) => s.id > current.id ) + pool.length ) % pool.length ] || pool[ 0 ];
		loadPreviews( [ next.id ] );
		return list.map( ( s, k ) => ( k === i ? { ...next, headline: s.headline, brief: s.brief } : s ) );
	} );
	const add = ( section ) => {
		updateSections( ( list ) => {
			if ( list.some( ( s ) => s.id === section.id ) ) {
				return list;
			}
			const row = { ...section, headline: '', brief: '' };
			if ( section.role === 'hero' || section.role === 'page-header' ) {
				const rest = list.filter( ( s ) => s.role !== 'hero' && s.role !== 'page-header' );
				return [ row, ...rest ];
			}
			return [ ...list, row ];
		} );
		loadPreviews( [ section.id ] );
		setSearch( '' );
	};

	const searchResults = useMemo( () => {
		const q = search.trim().toLowerCase();
		if ( ! q ) {
			return [];
		}
		const used = plan ? plan.sections.map( ( s ) => s.id ) : [];
		return index
			.filter( ( s ) => ! used.includes( s.id ) && ( roleLabel( s.role ).toLowerCase().includes( q ) || s.role.includes( q ) || s.description.toLowerCase().includes( q ) || s.id.includes( q ) ) )
			.slice( 0, 12 );
	}, [ search, index, plan ] );

	/* Render ------------------------------------------------------------ */

	const exhausted = quota && quota.connected && quota.limit > 0 && quota.remaining <= 0;

	return (
		<Modal
			title={ __( 'Generate a page with AI', 'inspiro-starter-sites' ) }
			onRequestClose={ busy ? () => {} : onClose }
			className="iss-pb-modal"
			shouldCloseOnClickOutside={ false }
		>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice>
			) }

			{ step === 'connect' && (
				<ConnectForm
					onDone={ () => {
						refreshQuota();
						setStep( returnTo.current );
					} }
					onCancel={ () => setStep( returnTo.current ) }
				/>
			) }

			{ step === 'describe' && (
				<div className="iss-pb-describe">
					<QuotaCard quota={ quota } onConnect={ () => { returnTo.current = 'describe'; setStep( 'connect' ); } } />
					<TextareaControl
						label={ __( 'What page do you need?', 'inspiro-starter-sites' ) }
						help={ __( 'Name the sections you want and what they should say. Mention the business, the audience, and any counts (three plans, a team of four).', 'inspiro-starter-sites' ) }
						value={ description }
						onChange={ setDescription }
						rows={ 4 }
						placeholder={ ( config.examples || [] )[ 0 ] || '' }
					/>
					{ ! description && ( config.examples || [] ).length > 0 && (
						<div className="iss-pb-examples">
							<span>{ __( 'Try:', 'inspiro-starter-sites' ) }</span>
							{ config.examples.map( ( ex, i ) => (
								<button key={ i } type="button" className="iss-pb-example" onClick={ () => setDescription( ex ) }>{ ex }</button>
							) ) }
						</div>
					) }
					<div className="iss-pb-options">
						<div>
							<span className="iss-pb-label">{ __( 'Page type', 'inspiro-starter-sites' ) }</span>
							<Chips options={ PAGE_TYPES } value={ pageType } onChange={ setPageType } />
						</div>
						<div>
							<span className="iss-pb-label">{ __( 'Style', 'inspiro-starter-sites' ) }</span>
							<Chips options={ STYLES } value={ style } onChange={ setStyle } />
						</div>
					</div>
					{ suggestions.length > 0 && (
						<div className="iss-pb-suggest">
							<span className="iss-pb-label">
								{ __( 'Sections that fit your description', 'inspiro-starter-sites' ) }
								<small>{ __( 'Click to pin one; the AI builds the plan around pinned sections.', 'inspiro-starter-sites' ) }</small>
							</span>
							<div className="iss-pb-strip">
								{ suggestions.map( ( s ) => (
									<SectionCard key={ s.id } section={ s } pinned={ pinned.includes( s.id ) } onToggle={ togglePin } />
								) ) }
							</div>
						</div>
					) }
					<div className="iss-pb-actions">
						<Button variant="primary" onClick={ () => doPlan() } isBusy={ busy } disabled={ busy || description.trim().length < 3 }>
							{ busy ? progress : __( 'Plan the page', 'inspiro-starter-sites' ) }
						</Button>
						<Button variant="tertiary" onClick={ onClose } disabled={ busy }>{ __( 'Cancel', 'inspiro-starter-sites' ) }</Button>
					</div>
				</div>
			) }

			{ ( step === 'plan' || step === 'building' ) && plan && (
				<div className="iss-pb-plan">
					<div className="iss-pb-plan__head">
						<div>
							<h3>{ plan.title || __( 'Your page', 'inspiro-starter-sites' ) }</h3>
							{ plan.requirements && plan.requirements.length > 0 && (
								<p className="iss-pb-reqs">
									{ __( 'Understood:', 'inspiro-starter-sites' ) } { plan.requirements.join( ' · ' ) }
								</p>
							) }
						</div>
						<Button variant="secondary" size="compact" onClick={ () => doPlan( plan.sections.map( ( s ) => s.id ) ) } disabled={ busy }>
							{ __( 'Try a different plan', 'inspiro-starter-sites' ) }
						</Button>
					</div>

					<ol className="iss-pb-rows">
						{ plan.sections.map( ( s, i ) => (
							<li key={ s.id } className="iss-pb-row">
								<RowPreview markup={ previews[ s.id ] } css={ previewCss } />
								<div className="iss-pb-row__body">
									<span className="iss-pb-row__role">{ roleLabel( s.role ) }<em>{ s.tone }</em></span>
									<TextControl
										className="iss-pb-row__headline"
										value={ s.headline }
										onChange={ ( v ) => setHeadline( i, v ) }
										placeholder={ __( 'Headline', 'inspiro-starter-sites' ) }
										disabled={ busy }
									/>
									<p className="iss-pb-row__brief">{ s.brief || s.description }</p>
								</div>
								<div className="iss-pb-row__actions">
									<Button icon="randomize" label={ __( 'Swap for another layout', 'inspiro-starter-sites' ) } onClick={ () => swap( i ) } disabled={ busy } />
									<Button icon="arrow-up-alt2" label={ __( 'Move up', 'inspiro-starter-sites' ) } onClick={ () => move( i, -1 ) } disabled={ busy || i === 0 } />
									<Button icon="arrow-down-alt2" label={ __( 'Move down', 'inspiro-starter-sites' ) } onClick={ () => move( i, 1 ) } disabled={ busy || i === plan.sections.length - 1 } />
									<Button icon="no-alt" label={ __( 'Remove', 'inspiro-starter-sites' ) } onClick={ () => remove( i ) } disabled={ busy || plan.sections.length <= 1 } />
								</div>
							</li>
						) ) }
					</ol>

					<div className="iss-pb-add">
						<TextControl
							placeholder={ __( 'Add a section: type a role (team, pricing, FAQ…) or a word from its description', 'inspiro-starter-sites' ) }
							value={ search }
							onChange={ setSearch }
							disabled={ busy }
						/>
						{ searchResults.length > 0 && (
							<div className="iss-pb-strip">
								{ searchResults.map( ( s ) => (
									<SectionCard key={ s.id } section={ s } pinned={ false } onToggle={ () => add( s ) } compact />
								) ) }
							</div>
						) }
					</div>

					<QuotaCard quota={ quota } onConnect={ () => { returnTo.current = 'plan'; setStep( 'connect' ); } } />

					<div className="iss-pb-actions">
						<Button variant="primary" onClick={ doBuild } isBusy={ busy } disabled={ busy || exhausted || ! plan.sections.length }>
							{ busy ? progress : __( 'Build and insert this page', 'inspiro-starter-sites' ) }
						</Button>
						<Button variant="tertiary" onClick={ () => setStep( 'describe' ) } disabled={ busy }>{ __( 'Back', 'inspiro-starter-sites' ) }</Button>
					</div>
				</div>
			) }
		</Modal>
	);
}

/**
 * Put the catalog stylesheet into the editor (the page and the editor
 * canvas iframe), so the inserted sections look right before reload.
 *
 * @param {string} css Stylesheet.
 */
function injectCss( css ) {
	if ( ! css ) {
		return;
	}
	const docs = [ document ];
	const frame = document.querySelector( 'iframe[name="editor-canvas"]' );
	if ( frame && frame.contentDocument ) {
		docs.push( frame.contentDocument );
	}
	docs.forEach( ( doc ) => {
		let el = doc.getElementById( 'inspiro-starter-sites-ai-page-inline-css' );
		if ( ! el ) {
			el = doc.createElement( 'style' );
			el.id = 'inspiro-starter-sites-ai-page-inline-css';
			doc.head.appendChild( el );
		}
		el.textContent = css;
	} );
}

export { createBlock };
