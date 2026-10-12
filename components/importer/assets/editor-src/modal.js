/**
 * The page builder: a brief on the left, a live page canvas on the right.
 *
 * Picking a preset puts real catalog sections on the canvas at once; the
 * AI then refines them (headlines, briefs, better picks) in the background.
 * The canvas is the plan: swap, reorder, remove and add sections there,
 * then build and insert.
 */

import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useMemo, useRef, useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { parse } from '@wordpress/blocks';
import { BlockPreview } from '@wordpress/block-editor';
import { Modal, Button, TextareaControl, TextControl, CheckboxControl, Spinner, Notice, Dashicon, Popover } from '@wordpress/components';

import { api, config } from './api';
import { PRESETS, ROLE_META, STYLES, composeRows, pickSection, toRow, availableRoles } from './presets';
import { rolesFor } from './matcher';

const BUILD_STEPS = [
	{ at: 0, label: __( 'Writing the copy', 'inspiro-starter-sites' ) },
	{ at: 18, label: __( 'Finding photos', 'inspiro-starter-sites' ) },
	{ at: 40, label: __( 'Laying out the sections', 'inspiro-starter-sites' ) },
];

const roleLabel = ( role ) => ( ROLE_META[ role ] ? ROLE_META[ role ].label : role );
const roleIcon = ( role ) => ( ROLE_META[ role ] ? ROLE_META[ role ].icon : 'layout' );

/* -------------------------------------------------------------------------
 * Pieces
 * ---------------------------------------------------------------------- */

function PresetGrid( { value, onPick } ) {
	return (
		<div className="iss-pb-presets" role="radiogroup" aria-label={ __( 'Page type', 'inspiro-starter-sites' ) }>
			{ PRESETS.map( ( p ) => (
				<button
					key={ p.key }
					type="button"
					role="radio"
					aria-checked={ value === p.key }
					className={ 'iss-pb-preset' + ( value === p.key ? ' is-active' : '' ) }
					onClick={ () => onPick( p ) }
				>
					<Dashicon icon={ p.icon } />
					<span>{ p.label }</span>
				</button>
			) ) }
		</div>
	);
}

function RoleToggles( { roles, selected, onToggle } ) {
	return (
		<div className="iss-pb-roles">
			{ roles.map( ( role ) => {
				const on = selected.includes( role );
				return (
					<button
						key={ role }
						type="button"
						className={ 'iss-pb-role' + ( on ? ' is-on' : '' ) }
						aria-pressed={ on }
						title={ ROLE_META[ role ].hint }
						onClick={ () => onToggle( role ) }
					>
						<Dashicon icon={ roleIcon( role ) } size={ 16 } />
						{ roleLabel( role ) }
					</button>
				);
			} ) }
		</div>
	);
}

function StyleSwatches( { value, onChange } ) {
	return (
		<div className="iss-pb-styles" role="radiogroup" aria-label={ __( 'Style', 'inspiro-starter-sites' ) }>
			{ STYLES.map( ( s ) => (
				<button
					key={ s.value || 'any' }
					type="button"
					role="radio"
					aria-checked={ value === s.value }
					className={ 'iss-pb-style iss-pb-style--' + ( s.value || 'any' ) + ( value === s.value ? ' is-active' : '' ) }
					onClick={ () => onChange( s.value ) }
					title={ s.hint }
				>
					<span className="iss-pb-style__art" aria-hidden="true">
						<i /><i /><i />
					</span>
					<span className="iss-pb-style__label">{ s.label }</span>
				</button>
			) ) }
		</div>
	);
}

function Frame( { row, markup, css, index, count, planned, busy, failed, onSwap, onMove, onRemove, onHeadline, onAddAfter } ) {
	const blocks = useMemo( () => ( markup ? parse( markup ) : [] ), [ markup ] );
	return (
		<div className={ 'iss-pb-frame iss-pb-frame--' + ( row.tone || 'light' ) }>
			<div className="iss-pb-frame__bar">
				<span className="iss-pb-frame__role">
					<Dashicon icon={ roleIcon( row.role ) } size={ 14 } />
					{ roleLabel( row.role ) }
				</span>
				{ planned ? (
					<input
						type="text"
						className="iss-pb-frame__headline"
						value={ row.headline }
						placeholder={ __( 'Headline', 'inspiro-starter-sites' ) }
						onChange={ ( e ) => onHeadline( e.target.value ) }
						disabled={ busy }
						aria-label={ __( 'Section headline', 'inspiro-starter-sites' ) }
					/>
				) : (
					<span className="iss-pb-frame__desc">{ row.description }</span>
				) }
				<span className="iss-pb-frame__tools">
					<Button icon="randomize" size="small" label={ __( 'Try another layout', 'inspiro-starter-sites' ) } onClick={ onSwap } disabled={ busy } />
					<Button icon="arrow-up-alt2" size="small" label={ __( 'Move up', 'inspiro-starter-sites' ) } onClick={ () => onMove( -1 ) } disabled={ busy || index === 0 } />
					<Button icon="arrow-down-alt2" size="small" label={ __( 'Move down', 'inspiro-starter-sites' ) } onClick={ () => onMove( 1 ) } disabled={ busy || index === count - 1 } />
					<Button icon="no-alt" size="small" label={ __( 'Remove', 'inspiro-starter-sites' ) } onClick={ onRemove } disabled={ busy || count <= 1 } />
				</span>
			</div>
			<div className="iss-pb-frame__preview">
				{ markup ? (
					<BlockPreview blocks={ blocks } viewportWidth={ 1280 } additionalStyles={ css ? [ { css } ] : [] } />
				) : failed ? (
					<div className="iss-pb-nopreview">
						<Dashicon icon={ roleIcon( row.role ) } size={ 24 } />
						<span>{ row.description }</span>
					</div>
				) : (
					<div className="iss-pb-skeleton"><span /><span /><span /></div>
				) }
			</div>
			{ planned && row.brief && <p className="iss-pb-frame__brief">{ row.brief }</p> }
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
				<Dashicon icon="plus-alt2" size={ 14 } />
				<span>{ __( 'Add section', 'inspiro-starter-sites' ) }</span>
			</button>
			{ open && (
				<Popover anchor={ ref.current } placement="bottom" onClose={ () => setOpen( false ) } className="iss-pb-rolepop">
					<div className="iss-pb-rolepop__grid">
						{ ( roles || window.issPbRoles || [] ).map( ( role ) => (
							<button key={ role } type="button" className="iss-pb-role" onClick={ () => { setOpen( false ); onAdd( role ); } }>
								<Dashicon icon={ roleIcon( role ) } size={ 16 } />
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
			<Dashicon icon="superhero" size={ 40 } />
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
						{ i < stepIndex ? <Dashicon icon="yes" size={ 14 } /> : i === stepIndex ? <Spinner /> : <span className="iss-pb-progress__dot" /> }
						{ s.label }
					</li>
				) ) }
			</ol>
			<p>{ __( 'Usually under a minute. The page lands in your editor as blocks you can edit.', 'inspiro-starter-sites' ) }</p>
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
	const [ error, setError ] = useState( '' );
	const canvasRef = useRef();

	const { insertBlocks, replaceBlocks } = useDispatch( 'core/block-editor' );
	const { editPost } = useDispatch( 'core/editor' );
	const { createSuccessNotice } = useDispatch( 'core/notices' );
	const { blocks, title } = useSelect( ( select ) => ( {
		blocks: select( 'core/block-editor' ).getBlocks(),
		title: select( 'core/editor' ).getEditedPostAttribute( 'title' ),
	} ), [] );

	const busy = ai.status === 'working' || building > 0;
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
		setAi( ( a ) => ( a.status === 'done' ? { ...a, status: 'stale' } : a ) );
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
		setAi( ( a ) => ( a.status === 'done' ? { ...a, status: 'stale' } : a ) );
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

	const markStale = () => setAi( ( a ) => ( a.status === 'done' ? { ...a, status: 'stale' } : a ) );

	const swap = ( i ) => {
		const row = rows[ i ];
		const seed = ( seeds[ row.role ] || 0 ) + 1;
		const picked = pickSection( row.role, index, { pageType, style, seed, exclude: rows.map( ( r ) => r.id ), prevTone: i > 0 ? rows[ i - 1 ].tone : '' } );
		if ( ! picked ) {
			return;
		}
		setSeeds( { ...seeds, [ row.role ]: seed } );
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
		markStale();
	};
	const setHeadline = ( i, headline ) => setRows( rows.map( ( r, k ) => ( k === i ? { ...r, headline } : r ) ) );
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
		markStale();
		loadPreviews( [ row ] );
	};

	/* AI --------------------------------------------------------------- */

	// The placeholder is an example, never the user's facts: with no brief,
	// say only what kind of page this is and whose site it is.
	const describe = () => {
		let text = brief.trim();
		if ( ! text ) {
			const site = config.siteName || __( 'this website', 'inspiro-starter-sites' );
			const kind = preset && preset.key !== 'custom' ? preset.label : __( 'A', 'inspiro-starter-sites' );
			text = sprintf( /* translators: 1: page kind, 2: site name */ __( '%1$s page for %2$s.', 'inspiro-starter-sites' ), kind, site );
			if ( config.tagline ) {
				text += ' ' + config.tagline;
			}
		}
		const wanted = rows.map( ( r ) => roleLabel( r.role ) ).join( ', ' );
		return wanted ? text + '\n\n' + sprintf( /* translators: section list */ __( 'Sections, in order: %s.', 'inspiro-starter-sites' ), wanted ) : text;
	};

	const refine = async () => {
		if ( ! rows.length && ! brief.trim() ) {
			return null;
		}
		setAi( ( a ) => ( { ...a, status: 'working' } ) );
		setError( '' );
		try {
			const plan = await api.plan( { description: describe(), page_type: pageType, style, pinned: rows.map( ( r ) => r.id ), avoid: [] } );
			const next = plan.sections.map( ( s ) => ( { ...s } ) );
			setRows( next );
			setRoles( next.map( ( r ) => r.role ).filter( ( v, k, a ) => a.indexOf( v ) === k ) );
			// The section list sent for ordering comes back as "requirements"
			// ("Testimonials", "Call to action"): keep only the user's wishes.
			const labels = Object.keys( ROLE_META ).flatMap( ( r ) => [ r, ROLE_META[ r ].label ] ).map( ( l ) => l.toLowerCase().replace( /[^a-z]/g, '' ) );
			const wishes = ( plan.requirements || [] ).filter( ( q ) => ! labels.includes( q.toLowerCase().replace( /[^a-z]/g, '' ) ) && ! /^sections/i.test( q ) );
			setAi( { status: 'done', title: plan.title, requirements: wishes, language: plan.language, pageType: plan.page_type } );
			const headlines = {};
			next.forEach( ( r ) => {
				if ( r.headline ) {
					headlines[ r.id ] = r.headline;
				}
			} );
			// Headlines change the preview: refresh the rows that got one,
			// and fetch any section the AI swapped in.
			loadPreviews( next, headlines );
			return { ...plan, sections: next };
		} catch ( e ) {
			setAi( ( a ) => ( { ...a, status: a.title ? 'stale' : 'idle' } ) );
			handleError( e );
			return null;
		}
	};

	const planObject = ( current ) => ( {
		description: describe(),
		page_type: ai.pageType || ( pageType === 'auto' ? 'inner' : pageType ),
		language: ai.language || '',
		title: ai.title || ( preset && preset.key !== 'custom' ? preset.label : '' ),
		requirements: ai.requirements || [],
		sections: current,
	} );

	const build = async () => {
		setError( '' );
		let current = rows;
		let plan = null;
		if ( ai.status !== 'done' && ai.status !== 'stale' ) {
			// First pass: let the AI write headlines and briefs before building.
			plan = await refine();
			if ( ! plan ) {
				return;
			}
			current = plan.sections;
		}
		setBuilding( Date.now() );
		try {
			const result = await api.build( plan ? plan : planObject( current ), config.postId );
			insertResult( result );
			if ( result.quota ) {
				setQuota( ( q ) => ( { ...( q || {} ), connected: true, ...result.quota } ) );
			}
			createSuccessNotice(
				sprintf( /* translators: section count */ __( 'Your page is in: %d sections. Review the copy and photos, then publish.', 'inspiro-starter-sites' ), ( result.sections || [] ).length ),
				{ type: 'snackbar' }
			);
			onClose();
		} catch ( e ) {
			handleError( e );
		}
		setBuilding( 0 );
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
			throw { code: 'empty', message: __( 'The AI returned no usable blocks.', 'inspiro-starter-sites' ) };
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
	const planned = ai.status === 'done' || ai.status === 'stale';

	const left = quota && quota.connected && quota.limit > 0 ? quota.remaining : null;

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
			<header className="iss-pb-top">
				<span className="iss-pb-top__logo" aria-hidden="true"><Dashicon icon="superhero" /></span>
				<div className="iss-pb-top__title">
					<strong>{ __( 'AI page builder', 'inspiro-starter-sites' ) }</strong>
					<span>{ __( 'Pages composed from Inspiro\'s designed sections', 'inspiro-starter-sites' ) }</span>
				</div>
				{ left !== null && (
					<span className={ 'iss-pb-top__pill' + ( left <= 0 ? ' is-out' : '' ) }>
						{ sprintf( /* translators: generations left */ __( '%d generations left', 'inspiro-starter-sites' ), left ) }
					</span>
				) }
				<Button icon="no-alt" label={ __( 'Close', 'inspiro-starter-sites' ) } onClick={ onClose } disabled={ busy } />
			</header>
			{ ! connected ? (
				<ConnectPanel onDone={ () => { setConnected( true ); } } />
			) : (
				<div className="iss-pb-layout">
					<aside className="iss-pb-side">
						<section className="iss-pb-block">
							<h3 className="iss-pb-h">{ __( '1. What kind of page?', 'inspiro-starter-sites' ) }</h3>
							<PresetGrid value={ preset ? preset.key : '' } onPick={ pickPreset } />
						</section>

						{ preset && (
							<>
								<section className="iss-pb-block">
									<h3 className="iss-pb-h">
										{ __( '2. Tell the AI about it', 'inspiro-starter-sites' ) }
										<small>{ __( 'Optional, but the more specific, the better the copy.', 'inspiro-starter-sites' ) }</small>
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
										{ __( '3. Sections', 'inspiro-starter-sites' ) }
										<small>{ __( 'Toggle what belongs on the page. Reorder on the canvas.', 'inspiro-starter-sites' ) }</small>
									</h3>
									<RoleToggles roles={ allRoles } selected={ roles } onToggle={ toggleRole } />
								</section>

								<section className="iss-pb-block">
									<h3 className="iss-pb-h">{ __( '4. Style', 'inspiro-starter-sites' ) }</h3>
									<StyleSwatches value={ style } onChange={ changeStyle } />
								</section>
							</>
						) }

						<div className="iss-pb-side__foot">
							{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
							<QuotaLine quota={ quota } />
							<div className="iss-pb-cta">
								<Button
									variant="primary"
									size="large"
									onClick={ build }
									isBusy={ building > 0 }
									disabled={ busy || exhausted || ! rows.length }
								>
									{ building ? __( 'Building…', 'inspiro-starter-sites' ) : __( 'Build & insert page', 'inspiro-starter-sites' ) }
								</Button>
								<Button
									variant="secondary"
									size="large"
									onClick={ refine }
									isBusy={ ai.status === 'working' }
									disabled={ busy || ! rows.length }
								>
									{ ai.status === 'working'
										? __( 'Thinking…', 'inspiro-starter-sites' )
										: planned
											? __( 'Rethink with AI', 'inspiro-starter-sites' )
											: __( 'Refine with AI', 'inspiro-starter-sites' ) }
								</Button>
							</div>
							<p className="iss-pb-muted">
								{ planned
									? __( 'Headlines are the AI\'s; edit them on the canvas. Build writes the full copy and adds photos.', 'inspiro-starter-sites' )
									: __( 'Refine lets the AI pick headlines and tune the sections before you build.', 'inspiro-starter-sites' ) }
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
								{ ai.status !== 'idle' && (
									<div className={ 'iss-pb-aibar iss-pb-aibar--' + ai.status }>
										{ ai.status === 'working' && <><Spinner />{ __( 'The AI is reading your brief and tuning the page…', 'inspiro-starter-sites' ) }</> }
										{ ai.status === 'done' && ai.requirements.length > 0 && <><Dashicon icon="yes-alt" size={ 16 } />{ __( 'Understood:', 'inspiro-starter-sites' ) } { ai.requirements.join( ' · ' ) }</> }
										{ ai.status === 'stale' && <><Dashicon icon="info-outline" size={ 16 } />{ __( 'You changed the page since the AI pass. Build as is, or rethink.', 'inspiro-starter-sites' ) }</> }
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
										markup={ previews[ row.id ] }
										css={ css }
										index={ i }
										count={ rows.length }
										planned={ planned }
										busy={ busy }
										failed={ !! failed[ row.id ] }
										onSwap={ () => swap( i ) }
										onMove={ ( dir ) => move( i, dir ) }
										onRemove={ () => remove( i ) }
										onHeadline={ ( v ) => setHeadline( i, v ) }
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
