/**
 * The page builder: a brief on the left, a live page canvas on the right.
 *
 * Picking a preset puts real catalog sections on the canvas at once.
 * Without a description, "Insert page" inserts them as shown (sample copy,
 * placeholder photos, no AI). With one, "Generate with AI" writes the copy
 * and picks photos, shows the result on the canvas, and inserting that is
 * instant. The canvas is always exactly what gets inserted.
 */

import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useMemo, useRef, useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { parse } from '@wordpress/blocks';
import { BlockPreview } from '@wordpress/block-editor';
import { Modal, Button, TextareaControl, TextControl, CheckboxControl, Spinner, Notice, Popover } from '@wordpress/components';

import { api, config } from './api';
import { PRESETS, ROLE_META, STYLES, composeRows, pickSection, toRow, availableRoles } from './presets';
import { rolesFor } from './matcher';

const BUILD_STEPS = [
	{ at: 0, label: __( 'Reading your description', 'inspiro-starter-sites' ) },
	{ at: 7, label: __( 'Writing the copy', 'inspiro-starter-sites' ) },
	{ at: 18, label: __( 'Finding photos', 'inspiro-starter-sites' ) },
];

const roleLabel = ( role ) => ( ROLE_META[ role ] ? ROLE_META[ role ].label : role );

/**
 * Line icons in the plugin's own style (20px grid, 1.5 stroke) — the AI demo
 * generator draws its icons the same way; no dashicons.
 */
const ICONS = {
	sparkle: <path d="M10 2.5l1.6 4.4 4.4 1.6-4.4 1.6L10 14.5l-1.6-4.4L4 8.5l4.4-1.6zM15.5 13l.7 1.8 1.8.7-1.8.7-.7 1.8-.7-1.8-1.8-.7 1.8-.7z" fill="currentColor" stroke="none" />,
	shuffle: <path d="M3 6h3.2c1.3 0 2.4.7 3 1.8l1.6 3.4c.6 1.1 1.7 1.8 3 1.8H17M3 14h3.2c1 0 1.9-.4 2.5-1.2M11.3 7.2c.6-.8 1.5-1.2 2.5-1.2H17M15 4l2 2-2 2M15 11l2 2-2 2" />,
	up: <path d="M5 12.5l5-5 5 5" />,
	down: <path d="M5 7.5l5 5 5-5" />,
	close: <path d="M5 5l10 10M15 5L5 15" />,
	plus: <path d="M10 4v12M4 10h12" />,
	check: <path d="M4.5 10.5l3.5 3.5 7.5-8" />,
};

function Icon( { name, size = 16 } ) {
	return (
		<svg className="iss-pb-icon" width={ size } height={ size } viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
			{ ICONS[ name ] }
		</svg>
	);
}

function IconButton( { icon, label, onClick, disabled } ) {
	return (
		<button type="button" className="iss-pb-iconbtn" onClick={ onClick } disabled={ disabled } aria-label={ label } title={ label }>
			<Icon name={ icon } />
		</button>
	);
}

/* -------------------------------------------------------------------------
 * Pieces
 * ---------------------------------------------------------------------- */

function Pills( { options, value, onChange, label } ) {
	return (
		<div className="iss-pb-pills" role="radiogroup" aria-label={ label }>
			{ options.map( ( o ) => (
				<button
					key={ o.value }
					type="button"
					role="radio"
					aria-checked={ value === o.value }
					className={ 'iss-pb-pill' + ( value === o.value ? ' is-active' : '' ) }
					onClick={ () => onChange( o.value ) }
					title={ o.hint || undefined }
				>
					{ o.label }
				</button>
			) ) }
		</div>
	);
}

function PresetGrid( { value, onPick } ) {
	return (
		<Pills
			label={ __( 'Page type', 'inspiro-starter-sites' ) }
			options={ PRESETS.map( ( p ) => ( { value: p.key, label: p.label } ) ) }
			value={ value }
			onChange={ ( key ) => onPick( PRESETS.find( ( p ) => p.key === key ) ) }
		/>
	);
}

function RoleToggles( { roles, selected, onToggle } ) {
	return (
		<div className="iss-pb-pills">
			{ roles.map( ( role ) => {
				const on = selected.includes( role );
				return (
					<button
						key={ role }
						type="button"
						className={ 'iss-pb-pill' + ( on ? ' is-active' : '' ) }
						aria-pressed={ on }
						title={ ROLE_META[ role ].hint }
						onClick={ () => onToggle( role ) }
					>
						{ roleLabel( role ) }
					</button>
				);
			} ) }
		</div>
	);
}

function StyleSwatches( { value, onChange } ) {
	return (
		<Pills
			label={ __( 'Style', 'inspiro-starter-sites' ) }
			options={ STYLES.map( ( st ) => ( { value: st.value, label: st.label, hint: st.hint } ) ) }
			value={ value }
			onChange={ onChange }
		/>
	);
}

function Frame( { row, markup, css, index, count, generated, busy, failed, onSwap, onMove, onRemove, onAddAfter } ) {
	const blocks = useMemo( () => ( markup ? parse( markup ) : [] ), [ markup ] );
	return (
		<div className={ 'iss-pb-frame iss-pb-frame--' + ( row.tone || 'light' ) + ( generated ? ' is-generated' : '' ) }>
			<div className="iss-pb-frame__bar">
				<span className="iss-pb-frame__role">{ roleLabel( row.role ) }</span>
				{ generated ? (
					<span className="iss-pb-frame__chip"><Icon name="sparkle" size={ 12 } />{ __( 'Written for you', 'inspiro-starter-sites' ) }</span>
				) : (
					<span className="iss-pb-frame__desc">{ row.description }</span>
				) }
				<span className="iss-pb-frame__tools">
					<IconButton icon="shuffle" label={ __( 'Try another layout', 'inspiro-starter-sites' ) } onClick={ onSwap } disabled={ busy } />
					<IconButton icon="up" label={ __( 'Move up', 'inspiro-starter-sites' ) } onClick={ () => onMove( -1 ) } disabled={ busy || index === 0 } />
					<IconButton icon="down" label={ __( 'Move down', 'inspiro-starter-sites' ) } onClick={ () => onMove( 1 ) } disabled={ busy || index === count - 1 } />
					<IconButton icon="close" label={ __( 'Remove', 'inspiro-starter-sites' ) } onClick={ onRemove } disabled={ busy || count <= 1 } />
				</span>
			</div>
			<div className="iss-pb-frame__preview">
				{ markup ? (
					<BlockPreview blocks={ blocks } viewportWidth={ 1280 } additionalStyles={ css ? [ { css } ] : [] } />
				) : failed ? (
					<div className="iss-pb-nopreview">
						<span>{ row.description }</span>
					</div>
				) : (
					<div className="iss-pb-skeleton"><span /><span /><span /></div>
				) }
			</div>
			<AddBetween onAdd={ onAddAfter } disabled={ busy } />
		</div>
	);
}

function AddBetween( { onAdd, disabled, roles } ) {
	const [ open, setOpen ] = useState( false );
	const ref = useRef();
	return (
		<div className="iss-pb-addbetween">
			<button type="button" ref={ ref } className="iss-pb-addbetween__btn" onClick={ () => setOpen( ! open ) } disabled={ disabled } aria-label={ __( 'Add a section here', 'inspiro-starter-sites' ) }>
				<Icon name="plus" size={ 14 } />
				<span>{ __( 'Add section', 'inspiro-starter-sites' ) }</span>
			</button>
			{ open && (
				<Popover anchor={ ref.current } placement="bottom" onClose={ () => setOpen( false ) } className="iss-pb-rolepop">
					<div className="iss-pb-rolepop__grid">
						{ ( roles || window.issPbRoles || [] ).map( ( role ) => (
							<button key={ role } type="button" className="iss-pb-pill" onClick={ () => { setOpen( false ); onAdd( role ); } }>
								{ roleLabel( role ) }
							</button>
						) ) }
					</div>
				</Popover>
			) }
		</div>
	);
}

function ConnectPanel( { onDone } ) {
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
			<p className="iss-pb-kicker"><Icon name="sparkle" size={ 14 } />{ __( 'AI page builder', 'inspiro-starter-sites' ) }</p>
			<h2>{ __( 'Build pages with AI, free', 'inspiro-starter-sites' ) }</h2>
			<p>{ __( 'Your free page generations are tied to your email. We send a one-time code to confirm it, then you are in.', 'inspiro-starter-sites' ) }</p>
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! pending ? (
				<>
					<TextControl label={ __( 'Email address', 'inspiro-starter-sites' ) } type="email" value={ email } onChange={ setEmail } __nextHasNoMarginBottom />
					<CheckboxControl label={ __( 'Send me occasional tips from WPZOOM (optional)', 'inspiro-starter-sites' ) } checked={ consent } onChange={ setConsent } __nextHasNoMarginBottom />
				</>
			) : (
				<TextControl label={ sprintf( /* translators: email */ __( 'Enter the 6-digit code sent to %s', 'inspiro-starter-sites' ), email ) } value={ code } onChange={ setCode } inputMode="numeric" __nextHasNoMarginBottom />
			) }
			<Button variant="primary" size="large" onClick={ submit } isBusy={ busy } disabled={ busy || ( pending ? code.length < 6 : ! email ) }>
				{ pending ? __( 'Confirm', 'inspiro-starter-sites' ) : __( 'Send code', 'inspiro-starter-sites' ) }
			</Button>
		</div>
	);
}

function QuotaLine( { quota } ) {
	if ( ! quota || ! quota.connected ) {
		return null;
	}
	const exhausted = quota.limit > 0 && quota.remaining <= 0;
	if ( ! exhausted ) {
		return null;
	}
	return (
		<div className="iss-pb-quotaline is-exhausted">
			<Upsell quota={ quota } />
		</div>
	);
}

function Upsell( { quota } ) {
	if ( quota && quota.licensed ) {
		return <span>{ __( 'You have used all the page generations included with your license.', 'inspiro-starter-sites' ) }</span>;
	}
	if ( config.premiumTheme && ! config.hasLicense ) {
		return (
			<span>
				{ __( 'Out of free generations.', 'inspiro-starter-sites' ) }{ ' ' }
				<a href={ config.licenseUrl }>{ __( 'Activate your Inspiro Premium license', 'inspiro-starter-sites' ) }</a>{ ' ' }
				{ __( 'for more.', 'inspiro-starter-sites' ) }
			</span>
		);
	}
	return (
		<span>
			{ __( 'Out of free generations.', 'inspiro-starter-sites' ) }{ ' ' }
			<a href={ config.upgradeUrl } target="_blank" rel="noreferrer">{ __( 'Upgrade to Inspiro Premium', 'inspiro-starter-sites' ) }</a>{ ' ' }
			{ __( 'for more generations and the full section library.', 'inspiro-starter-sites' ) }
		</span>
	);
}

function BuildProgress( { startedAt } ) {
	const [ now, setNow ] = useState( Date.now() );
	useEffect( () => {
		const t = setInterval( () => setNow( Date.now() ), 500 );
		return () => clearInterval( t );
	}, [] );
	const elapsed = ( now - startedAt ) / 1000;
	const pct = Math.min( 92, Math.round( 100 * ( 1 - Math.exp( -elapsed / 35 ) ) ) );
	const stepIndex = BUILD_STEPS.reduce( ( acc, s, i ) => ( elapsed >= s.at ? i : acc ), 0 );
	return (
		<div className="iss-pb-progress">
			<div className="iss-pb-progress__bar"><span style={ { width: pct + '%' } } /></div>
			<ol className="iss-pb-progress__steps">
				{ BUILD_STEPS.map( ( s, i ) => (
					<li key={ s.label } className={ i < stepIndex ? 'is-done' : i === stepIndex ? 'is-active' : '' }>
						{ i < stepIndex ? <Icon name="check" size={ 14 } /> : i === stepIndex ? <Spinner /> : <span className="iss-pb-progress__dot" /> }
						{ s.label }
					</li>
				) ) }
			</ol>
			<p>{ __( 'Usually about half a minute. You will see the page here before anything is inserted.', 'inspiro-starter-sites' ) }</p>
		</div>
	);
}

/* -------------------------------------------------------------------------
 * The builder
 * ---------------------------------------------------------------------- */

export default function PageBuilderModal( { onClose } ) {
	const [ connected, setConnected ] = useState( !! config.connected );
	const [ index, setIndex ] = useState( [] );
	const [ quota, setQuota ] = useState( null );
	const [ preset, setPreset ] = useState( null );
	const [ brief, setBrief ] = useState( '' );
	const [ roles, setRoles ] = useState( [] );
	const [ style, setStyle ] = useState( '' );
	const [ rows, setRows ] = useState( [] );
	const [ seeds, setSeeds ] = useState( {} );
	const [ previews, setPreviews ] = useState( {} );
	const [ css, setCss ] = useState( '' );
	const [ ai, setAi ] = useState( { status: 'idle', title: '', requirements: [], language: '', pageType: '' } );
	const [ building, setBuilding ] = useState( 0 );
	const [ inserting, setInserting ] = useState( false );
	const [ generated, setGenerated ] = useState( {} ); // section id => markup written by the AI
	const [ genBrief, setGenBrief ] = useState( null ); // the description that markup was written from
	const [ error, setError ] = useState( '' );
	const canvasRef = useRef();

	const { insertBlocks, replaceBlocks } = useDispatch( 'core/block-editor' );
	const { editPost } = useDispatch( 'core/editor' );
	const { createSuccessNotice } = useDispatch( 'core/notices' );
	const { blocks, title } = useSelect( ( select ) => ( {
		blocks: select( 'core/block-editor' ).getBlocks(),
		title: select( 'core/editor' ).getEditedPostAttribute( 'title' ),
	} ), [] );

	const busy = ai.status === 'working' || building > 0 || inserting;
	const pageType = preset ? preset.pageType : 'auto';
	const allRoles = useMemo( () => availableRoles( index ), [ index ] );
	window.issPbRoles = allRoles;

	/* Data ------------------------------------------------------------- */

	const refreshQuota = useCallback( () => api.quota().then( setQuota ).catch( () => {} ), [] );

	useEffect( () => {
		if ( ! connected ) {
			return;
		}
		api.catalog().then( ( data ) => setIndex( data.sections || [] ) ).catch( ( e ) => setError( e.message ) );
		refreshQuota();
		// Import the generic placeholder photos now, so inserting without AI
		// doesn't wait for downloads (a no-op once the site has them).
		api.pool().catch( () => {} );
	}, [ connected ] );

	// Previews: deduped across calls, missing ones retried (the server
	// fetches uncached sections from the AI proxy, which can be slow or
	// fail on a cold cache), and given up on after a few tries.
	const previewsRef = useRef( {} );
	const requested = useRef( new Set() );
	const attempts = useRef( {} );
	const [ failed, setFailed ] = useState( {} );

	const loadPreviews = useCallback( ( list, headlines = {} ) => {
		const ids = list
			.map( ( r ) => ( typeof r === 'string' ? r : r.id ) )
			.filter( ( id, k, a ) => a.indexOf( id ) === k )
			.filter( ( id ) => headlines[ id ] || ( ! previewsRef.current[ id ] && ! requested.current.has( id ) ) );
		if ( ! ids.length ) {
			return;
		}
		ids.forEach( ( id ) => requested.current.add( id ) );

		const retry = ( missing ) => {
			const again = missing.filter( ( id ) => {
				requested.current.delete( id );
				attempts.current[ id ] = ( attempts.current[ id ] || 0 ) + 1;
				return attempts.current[ id ] < 3;
			} );
			const gaveUp = missing.filter( ( id ) => ! again.includes( id ) );
			if ( gaveUp.length ) {
				setFailed( ( prev ) => ( { ...prev, ...Object.fromEntries( gaveUp.map( ( id ) => [ id, true ] ) ) } ) );
			}
			if ( again.length ) {
				setTimeout( () => loadPreviews( again ), 1200 * attempts.current[ again[ 0 ] ] );
			}
		};

		api.preview( ids, headlines ).then( ( data ) => {
			const markup = data.markup && ! Array.isArray( data.markup ) ? data.markup : {};
			previewsRef.current = { ...previewsRef.current, ...markup };
			setPreviews( previewsRef.current );
			if ( data.css ) {
				setCss( data.css );
			}
			const missing = ids.filter( ( id ) => ! markup[ id ] );
			if ( missing.length ) {
				retry( missing );
			}
		} ).catch( () => retry( ids ) );
	}, [] );

	// Warm-up: the first section of every role each preset would show, so
	// clicking a preset paints real sections immediately.
	useEffect( () => {
		if ( ! index.length ) {
			return;
		}
		const ids = [];
		PRESETS.forEach( ( p ) => {
			composeRows( p.roles, index, [], { pageType: p.pageType, style: '' } ).forEach( ( r ) => ids.push( r.id ) );
		} );
		const unique = ids.filter( ( id, k ) => ids.indexOf( id ) === k );
		// A few requests of moderate size rather than one large one.
		for ( let k = 0; k < unique.length; k += 8 ) {
			loadPreviews( unique.slice( k, k + 8 ) );
		}
	}, [ index ] );

	/* Brief → canvas (instant) ---------------------------------------- */

	// Until the AI writes headlines, the opening section previews with the
	// page's name rather than the demo's ("About us" on a Services page).
	const openerHeadlines = ( list, p = preset ) => {
		const out = {};
		if ( p && p.key !== 'custom' && list.length && ( list[ 0 ].role === 'page-header' || list[ 0 ].role === 'hero' ) && ! list[ 0 ].headline ) {
			out[ list[ 0 ].id ] = p.role === 'hero' ? config.siteName || p.label : p.label;
		}
		return out;
	};

	const applyRoles = ( nextRoles, nextStyle = style, nextPreset = preset ) => {
		const next = composeRows( nextRoles, index, rows, { pageType: nextPreset ? nextPreset.pageType : 'auto', style: nextStyle, seeds } );
		setRows( next );
				loadPreviews( next );
		const named = openerHeadlines( next, nextPreset );
		if ( Object.keys( named ).length ) {
			loadPreviews( Object.keys( named ), named );
		}
	};

	const pickPreset = ( p ) => {
		setPreset( p );
		setRoles( p.roles );
		applyRoles( p.roles, style, p );
	};

	const toggleRole = ( role ) => {
		const next = roles.includes( role ) ? roles.filter( ( r ) => r !== role ) : [ ...roles, role ].sort( ( a, b ) => allRoles.indexOf( a ) - allRoles.indexOf( b ) );
		setRoles( next );
		applyRoles( next );
	};

	const changeStyle = ( value ) => {
		setStyle( value );
		// Re-pick every section for the new mood, keeping the order.
		const next = composeRows( rows.map( ( r ) => r.role ), index, [], { pageType, style: value, seeds } );
		setRows( next );
				loadPreviews( next );
	};

	// Typing roles into the brief ("team", "faq") adds them to the page.
	const onBrief = ( text ) => {
		setBrief( text );
		if ( ! preset ) {
			return;
		}
		const mentioned = rolesFor( text ).filter( ( r ) => allRoles.includes( r ) && ! roles.includes( r ) && r !== 'hero' && r !== 'page-header' );
		if ( mentioned.length ) {
			const next = [ ...roles, ...mentioned ];
			setRoles( next );
			applyRoles( next );
		}
	};

	/* Canvas edits ----------------------------------------------------- */

	const swap = ( i ) => {
		const row = rows[ i ];
		const seed = ( seeds[ row.role ] || 0 ) + 1;
		const picked = pickSection( row.role, index, { pageType, style, seed, exclude: rows.map( ( r ) => r.id ), prevTone: i > 0 ? rows[ i - 1 ].tone : '' } );
		if ( ! picked ) {
			return;
		}
		setSeeds( { ...seeds, [ row.role ]: seed } );
		// A new layout has no AI copy yet: it shows (and inserts) sample text.
		setGenerated( ( g ) => {
			const copy = { ...g };
			delete copy[ row.id ];
			return copy;
		} );
		const next = rows.map( ( r, k ) => ( k === i ? toRow( picked, { headline: r.headline, brief: r.brief } ) : r ) );
		setRows( next );
		loadPreviews( [ next[ i ] ], next[ i ].headline ? { [ next[ i ].id ]: next[ i ].headline } : {} );
	};
	const move = ( i, dir ) => {
		const j = i + dir;
		if ( j < 0 || j >= rows.length ) {
			return;
		}
		const next = [ ...rows ];
		[ next[ i ], next[ j ] ] = [ next[ j ], next[ i ] ];
		setRows( next );
	};
	const remove = ( i ) => {
		const next = rows.filter( ( _, k ) => k !== i );
		setRows( next );
		setRoles( next.map( ( r ) => r.role ).filter( ( v, k, a ) => a.indexOf( v ) === k ) );
	};
	const addAfter = ( i, role ) => {
		const picked = pickSection( role, index, { pageType, style, seed: seeds[ role ] || 0, exclude: rows.map( ( r ) => r.id ), prevTone: i >= 0 ? rows[ i ].tone : '' } );
		if ( ! picked ) {
			setError( __( 'No section of that kind is available on this site.', 'inspiro-starter-sites' ) );
			return;
		}
		const row = toRow( picked );
		const next = [ ...rows ];
		next.splice( i + 1, 0, row );
		setRows( next );
		if ( ! roles.includes( role ) ) {
			setRoles( [ ...roles, role ] );
		}
		loadPreviews( [ row ] );
	};

	/* AI and insert ---------------------------------------------------- */

	// Only what the user wrote: the placeholder is an example, not their facts.
	const describe = () => {
		const text = brief.trim();
		const wanted = rows.map( ( r ) => roleLabel( r.role ) ).join( ', ' );
		return wanted ? text + '\n\n' + sprintf( /* translators: section list */ __( 'Sections, in order: %s.', 'inspiro-starter-sites' ), wanted ) : text;
	};

	const hasBrief = brief.trim().length > 0;
	const generatedNow = ai.status === 'done' && genBrief === brief.trim();
	const needsAI = hasBrief && ! generatedNow;

	/**
	 * Generate with AI: plan around the canvas sections, write the copy and
	 * find photos, then show the result on the canvas. Nothing is inserted
	 * yet; inserting what the canvas shows is instant.
	 */
	const generate = async () => {
		setError( '' );
		setAi( ( a ) => ( { ...a, status: 'working' } ) );
		setBuilding( Date.now() );
		try {
			const plan = await api.plan( { description: describe(), page_type: pageType, style, pinned: rows.map( ( r ) => r.id ), avoid: [] } );
			const result = await api.build( plan, config.postId );
			const next = ( result.plan && result.plan.sections ? result.plan.sections : plan.sections ).map( ( r ) => ( { ...r } ) );
			setRows( next );
			setRoles( next.map( ( r ) => r.role ).filter( ( v, k, a ) => a.indexOf( v ) === k ) );
			setGenerated( result.parts || {} );
			setGenBrief( brief.trim() );
			if ( result.css ) {
				setCss( result.css );
			}
			// The section list sent for ordering comes back as "requirements"
			// ("Testimonials", "Call to action"): keep only the user's wishes.
			const labels = Object.keys( ROLE_META ).flatMap( ( r ) => [ r, ROLE_META[ r ].label ] ).map( ( l ) => l.toLowerCase().replace( /[^a-z]/g, '' ) );
			const bare = ( q ) => q.toLowerCase().replace( /\bsections?\b/g, '' ).replace( /[^a-z]/g, '' );
			const wishes = ( plan.requirements || [] ).filter( ( q ) => ! labels.includes( bare( q ) ) && ! /^sections/i.test( q ) );
			setAi( { status: 'done', title: result.title || plan.title, requirements: wishes, language: plan.language, pageType: plan.page_type } );
			if ( result.quota ) {
				setQuota( ( q ) => ( { ...( q || {} ), connected: true, ...result.quota } ) );
			}
			loadPreviews( next );
			if ( canvasRef.current ) {
				canvasRef.current.scrollTop = 0;
			}
		} catch ( e ) {
			setAi( ( a ) => ( { ...a, status: a.title ? 'done' : 'idle' } ) );
			handleError( e );
		}
		setBuilding( 0 );
	};

	/**
	 * Insert exactly what the canvas shows: sections the AI wrote as they
	 * are, every other section with its sample copy and the site's
	 * placeholder photos.
	 */
	const insert = async () => {
		setError( '' );
		setInserting( true );
		try {
			const plain = rows.filter( ( r ) => ! generated[ r.id ] );
			let parts = {};
			let sheet = css;
			if ( plain.length ) {
				const headlines = { ...openerHeadlines( plain ) };
				plain.forEach( ( r ) => {
					if ( r.headline ) {
						headlines[ r.id ] = r.headline;
					}
				} );
				const res = await api.instant( plain.map( ( r ) => r.id ), headlines, config.postId );
				parts = res.parts || {};
				sheet = res.css || sheet;
			}
			const markup = rows.map( ( r ) => generated[ r.id ] || parts[ r.id ] || '' ).filter( Boolean ).join( '\n\n' );
			insertResult( {
				markup,
				css: sheet,
				title: ai.title || ( preset && preset.key !== 'custom' ? preset.label : '' ),
			} );
			createSuccessNotice(
				sprintf( /* translators: section count */ __( 'Page inserted: %d sections. Edit any text or photo right in the editor.', 'inspiro-starter-sites' ), rows.length ),
				{ type: 'snackbar' }
			);
			onClose();
		} catch ( e ) {
			handleError( e );
		}
		setInserting( false );
	};

	const handleError = ( e ) => {
		if ( e.code === 'registration_required' ) {
			setConnected( false );
			return;
		}
		if ( e.code === 'quota_exhausted' ) {
			refreshQuota();
		}
		setError( e.message );
	};

	const insertResult = ( result ) => {
		const newBlocks = parse( result.markup || '' );
		if ( ! newBlocks.length ) {
			throw { code: 'empty', message: __( 'There was nothing to insert. Please try again.', 'inspiro-starter-sites' ) };
		}
		injectCss( result.css );
		const emptyEditor = blocks.length === 0 || ( blocks.length === 1 && blocks[ 0 ].name === 'core/paragraph' && ! ( blocks[ 0 ].attributes.content || '' ).toString().trim() );
		if ( emptyEditor && blocks.length ) {
			replaceBlocks( blocks.map( ( b ) => b.clientId ), newBlocks );
		} else {
			insertBlocks( newBlocks );
		}
		if ( ! ( title || '' ).trim() && result.title ) {
			editPost( { title: result.title } );
		}
	};

	/* Render ----------------------------------------------------------- */

	const exhausted = quota && quota.connected && quota.limit > 0 && quota.remaining <= 0;


	return (
		<Modal
			title={ __( 'Generate a page with AI', 'inspiro-starter-sites' ) }
			onRequestClose={ busy ? () => {} : onClose }
			className="iss-pb-modal"
			isFullScreen
			__experimentalHideHeader
			shouldCloseOnClickOutside={ false }
		>
			<div className="iss-pb-shell">
			<button type="button" className="iss-pb-close" onClick={ onClose } disabled={ busy } aria-label={ __( 'Close', 'inspiro-starter-sites' ) }>
				<Icon name="close" size={ 18 } />
			</button>
			{ ! connected ? (
				<ConnectPanel onDone={ () => { setConnected( true ); } } />
			) : (
				<div className="iss-pb-layout">
					<aside className="iss-pb-side">
							<header className="iss-pb-side__head">
								<h2>
									{ __( 'Generate a page with AI', 'inspiro-starter-sites' ) }
									<span className="iss-pb-badge">{ __( 'Beta', 'inspiro-starter-sites' ) }</span>
								</h2>
								<p>{ __( 'Pick a page type and the sections you want. Describe your business and AI writes the copy and finds photos.', 'inspiro-starter-sites' ) }</p>
							</header>
						<section className="iss-pb-block">
							<h3 className="iss-pb-h">{ __( 'Page type', 'inspiro-starter-sites' ) }</h3>
							<PresetGrid value={ preset ? preset.key : '' } onPick={ pickPreset } />
						</section>

						{ preset && (
							<>
								<section className="iss-pb-block">
									<h3 className="iss-pb-h">
										{ __( 'Describe your page', 'inspiro-starter-sites' ) }
										<small>{ __( 'Optional. Leave it empty to insert the sections with sample text.', 'inspiro-starter-sites' ) }</small>
									</h3>
									<TextareaControl
										value={ brief }
										onChange={ onBrief }
										rows={ 4 }
										placeholder={ preset.placeholder }
										__nextHasNoMarginBottom
									/>
									{ config.siteName && (
										<p className="iss-pb-muted">
											{ sprintf( /* translators: site name */ __( 'Written for %s, in your site language.', 'inspiro-starter-sites' ), config.siteName ) }
										</p>
									) }
								</section>

								<section className="iss-pb-block">
									<h3 className="iss-pb-h">
										{ __( 'Sections', 'inspiro-starter-sites' ) }
										<small>{ __( 'Reorder, swap or remove them on the preview.', 'inspiro-starter-sites' ) }</small>
									</h3>
									<RoleToggles roles={ allRoles } selected={ roles } onToggle={ toggleRole } />
								</section>

								<section className="iss-pb-block">
									<h3 className="iss-pb-h">{ __( 'Style', 'inspiro-starter-sites' ) }</h3>
									<StyleSwatches value={ style } onChange={ changeStyle } />
								</section>
							</>
						) }

						<div className="iss-pb-side__foot">
							{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
							<QuotaLine quota={ quota } />
								{ quota && quota.connected && quota.limit > 0 && (
									<p className="iss-pb-quota">
										{ quota.email ? sprintf( /* translators: email */ __( 'Connected as %s', 'inspiro-starter-sites' ), quota.email ) : null }
										{ quota.email ? <br /> : null }
										{ sprintf( /* translators: 1: used, 2: limit */ __( '%1$d of %2$d AI page generations used', 'inspiro-starter-sites' ), quota.used, quota.limit ) }
									</p>
								) }
							<div className="iss-pb-cta">
								<Button
									variant="primary"
									size="large"
									onClick={ needsAI ? generate : insert }
									isBusy={ building > 0 || inserting }
									disabled={ busy || ! rows.length || ( needsAI && exhausted ) }
								>
									{ building
										? __( 'Generating…', 'inspiro-starter-sites' )
										: inserting
											? __( 'Inserting…', 'inspiro-starter-sites' )
											: needsAI
												? ( ai.status === 'done' ? __( 'Update with AI', 'inspiro-starter-sites' ) : __( 'Generate with AI', 'inspiro-starter-sites' ) )
												: __( 'Insert page', 'inspiro-starter-sites' ) }
								</Button>
							</div>
							{ needsAI && rows.length > 0 && (
								<Button variant="link" className="iss-pb-alt" onClick={ insert } disabled={ busy }>
									{ ai.status === 'done' ? __( 'Insert the page as shown', 'inspiro-starter-sites' ) : __( 'Insert without AI (sample text)', 'inspiro-starter-sites' ) }
								</Button>
							) }
							<p className="iss-pb-muted">
								{ ! hasBrief
									? __( 'Inserts these sections right away, with sample text and photos you can replace. Describe your page above to have the AI write it for you.', 'inspiro-starter-sites' )
									: needsAI
										? __( 'The AI writes your copy and picks photos, in about half a minute. You see the result here before anything is inserted.', 'inspiro-starter-sites' )
										: __( 'What you see is what gets inserted. You can still change sections, or edit text and photos in the editor afterwards.', 'inspiro-starter-sites' ) }
							</p>
						</div>
					</aside>

					<main className="iss-pb-canvas" ref={ canvasRef }>
						{ building > 0 && <BuildProgress startedAt={ building } /> }
						{ ! rows.length ? (
							<div className="iss-pb-empty">
								<div className="iss-pb-empty__art"><i /><i /><i /><i /></div>
								<h2>{ __( 'Pick a page type to start', 'inspiro-starter-sites' ) }</h2>
								<p>{ __( 'A complete page appears here instantly, built from sections designed for Inspiro. Then change anything you like.', 'inspiro-starter-sites' ) }</p>
							</div>
						) : (
							<div className={ 'iss-pb-page' + ( building ? ' is-building' : '' ) }>
								{ ai.status === 'done' && (
									<div className={ 'iss-pb-aibar iss-pb-aibar--' + ( generatedNow ? 'done' : 'stale' ) }>
										{ generatedNow ? (
											<><Icon name="sparkle" size={ 14 } />{ ai.requirements.length ? __( 'Written from your description:', 'inspiro-starter-sites' ) + ' ' + ai.requirements.join( ' · ' ) : __( 'Written from your description.', 'inspiro-starter-sites' ) }</>
										) : (
											<>{ __( 'Your description changed. Update with AI to rewrite the page, or insert it as shown.', 'inspiro-starter-sites' ) }</>
										) }
									</div>
								) }
								{ ai.title && <h2 className="iss-pb-page__title">{ ai.title }</h2> }
								<div className="iss-pb-addbetween iss-pb-addbetween--top">
									<AddBetween onAdd={ ( role ) => addAfter( -1, role ) } disabled={ busy } roles={ allRoles } />
								</div>
								{ rows.map( ( row, i ) => (
									<Frame
										key={ row.id + i }
										row={ row }
										markup={ generated[ row.id ] || previews[ row.id ] }
										css={ css }
										index={ i }
										count={ rows.length }
										generated={ !! generated[ row.id ] }
										busy={ busy }
										failed={ !! failed[ row.id ] }
										onSwap={ () => swap( i ) }
										onMove={ ( dir ) => move( i, dir ) }
										onRemove={ () => remove( i ) }
										onAddAfter={ ( role ) => addAfter( i, role ) }
									/>
								) ) }
							</div>
						) }
					</main>
				</div>
			) }
			</div>
		</Modal>
	);
}

/**
 * Put the catalog stylesheet into the editor (the page and the editor
 * canvas iframe), so the inserted sections look right before reload.
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
