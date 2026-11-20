/**
 * The pattern library: browse ready-made block patterns by category, look
 * at one up close and insert it with its own sample content. No AI.
 *
 * Two sources, shown in this order:
 *  - the active theme's own block patterns, which the editor already has
 *    (free with the theme, previewed live);
 *  - the library on the provider (see PatternLibrary.php), with a
 *    thumbnail each. Premium ones are listed for every site and shown
 *    locked without a license.
 */

import { __, _n, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useMemo, useRef } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { parse } from '@wordpress/blocks';
import { BlockPreview } from '@wordpress/block-editor';
import { Button, Notice, Spinner, SearchControl } from '@wordpress/components';

import { api, config } from './api';

const CATEGORY_LABELS = {
	about: __( 'About', 'inspiro-starter-sites' ),
	contact: __( 'Contact', 'inspiro-starter-sites' ),
	cta: __( 'Call to action', 'inspiro-starter-sites' ),
	ecommerce: __( 'Ecommerce', 'inspiro-starter-sites' ),
	faq: __( 'FAQ', 'inspiro-starter-sites' ),
	features: __( 'Features', 'inspiro-starter-sites' ),
	forms: __( 'Forms', 'inspiro-starter-sites' ),
	gallery: __( 'Gallery', 'inspiro-starter-sites' ),
	hero: __( 'Hero', 'inspiro-starter-sites' ),
	logo: __( 'Logos', 'inspiro-starter-sites' ),
	newsletter: __( 'Newsletter', 'inspiro-starter-sites' ),
	pages: __( 'Full pages', 'inspiro-starter-sites' ),
	portfolio: __( 'Portfolio', 'inspiro-starter-sites' ),
	posts: __( 'Posts', 'inspiro-starter-sites' ),
	pricing: __( 'Pricing', 'inspiro-starter-sites' ),
	services: __( 'Services', 'inspiro-starter-sites' ),
	social: __( 'Social', 'inspiro-starter-sites' ),
	team: __( 'Team', 'inspiro-starter-sites' ),
	testimonials: __( 'Testimonials', 'inspiro-starter-sites' ),
	text: __( 'Text', 'inspiro-starter-sites' ),
	video: __( 'Video', 'inspiro-starter-sites' ),
};

// WordPress and theme pattern categories → the library's own.
const THEME_CATEGORIES = {
	about: 'about',
	banner: 'hero',
	banners: 'hero',
	header: 'hero',
	'call-to-action': 'cta',
	contact: 'contact',
	featured: 'features',
	gallery: 'gallery',
	pages: 'pages',
	portfolio: 'portfolio',
	posts: 'posts',
	query: 'posts',
	pricing: 'pricing',
	services: 'services',
	team: 'team',
	testimonials: 'testimonials',
	text: 'text',
	videos: 'video',
};

// Fallback for theme patterns without a known category, by title.
const TITLE_CATEGORIES = [
	[ /home\s?page/i, 'pages' ],
	[ /team/i, 'team' ],
	[ /about/i, 'about' ],
	[ /service/i, 'services' ],
	[ /pric/i, 'pricing' ],
	[ /testimonial|review/i, 'testimonials' ],
	[ /contact/i, 'contact' ],
	[ /portfolio|case study|project/i, 'portfolio' ],
	[ /hero|cover|banner|video background/i, 'hero' ],
	[ /call to action|cta|newsletter/i, 'cta' ],
	[ /post|blog|news/i, 'posts' ],
	[ /gallery/i, 'gallery' ],
];

const NEED_LABELS = {
	'wpzoom-forms': __( 'the WPZOOM Forms plugin with at least one form', 'inspiro-starter-sites' ),
	'video-popup': __( 'the Video Popup Block by WPZOOM plugin', 'inspiro-starter-sites' ),
	posts: __( 'at least one published post', 'inspiro-starter-sites' ),
};

const TONES = [
	{ value: '', label: __( 'All', 'inspiro-starter-sites' ) },
	{ value: 'light', label: __( 'Light', 'inspiro-starter-sites' ) },
	{ value: 'dark', label: __( 'Dark', 'inspiro-starter-sites' ) },
];

const categoryLabel = ( slug ) => CATEGORY_LABELS[ slug ] || slug.charAt( 0 ).toUpperCase() + slug.slice( 1 );

const THEME_NAMES = new Set( config.themePatterns || [] );

/** A theme pattern in the library's shape. */
function fromTheme( pattern ) {
	const title = pattern.title || pattern.name;
	const categories = [];
	( pattern.categories || [] ).forEach( ( slug ) => {
		const mapped = THEME_CATEGORIES[ slug ];
		if ( mapped && ! categories.includes( mapped ) ) {
			categories.push( mapped );
		}
	} );
	if ( /faq/i.test( title ) && ! categories.includes( 'faq' ) ) {
		categories.unshift( 'faq' );
	}
	// A theme may file its patterns under categories of its own: then the
	// pattern's name says where it belongs.
	if ( ! categories.length ) {
		const guess = TITLE_CATEGORIES.find( ( [ test ] ) => test.test( title ) );
		if ( guess ) {
			categories.push( guess[ 1 ] );
		}
	}
	const content = pattern.content || '';
	// The ground of the pattern's outermost block decides light or dark.
	const opener = content.slice( 0, 700 );
	const dark = /\(dark\)/i.test( title ) || /"backgroundColor":"(?:black|primary|secondary|header-footer|foreground)"|"background":"#(?:0|1)[0-9a-f]{5}"|"background":"#(?:0|1)[0-9a-f]{2}"/i.test( opener );
	return {
		id: 'theme:' + pattern.name,
		source: 'theme',
		title,
		categories,
		tone: dark ? 'dark' : 'light',
		thumb: '',
		content,
		viewportWidth: pattern.viewportWidth || pattern.viewport_width || 1280,
		locked: false,
		missing: [],
	};
}

// The library is the same for the whole editor session: reopening the
// modal shows it at once.
let cache = null;

function LockIcon() {
	return (
		<svg width="12" height="12" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
			<rect x="4.5" y="9" width="11" height="8" rx="2" />
			<path d="M7 9V6.5a3 3 0 016 0V9" />
		</svg>
	);
}

function Upsell() {
	const activate = config.premiumTheme && ! config.hasLicense;
	return (
		<div className="iss-pb-upsell">
			<p>
				{ activate
					? __( 'Activate your Inspiro Premium license to insert the Premium patterns.', 'inspiro-starter-sites' )
					: __( 'The Premium patterns are part of Inspiro Premium — along with all premium features, starter sites and priority support.', 'inspiro-starter-sites' ) }
			</p>
			<a className="iss-pb-upsell__btn" href={ activate ? config.licenseUrl : config.upgradeUrl } target={ activate ? undefined : '_blank' } rel="noopener noreferrer">
				{ activate ? __( 'Activate your license', 'inspiro-starter-sites' ) : __( 'Upgrade to Inspiro Premium', 'inspiro-starter-sites' ) }
			</a>
		</div>
	);
}

/**
 * A live preview of a theme pattern. In a card it renders once the card
 * scrolls into view (a page-long pattern is a lot of blocks).
 */
function LivePreview( { pattern, lazy } ) {
	const ref = useRef();
	const [ show, setShow ] = useState( ! lazy );

	useEffect( () => {
		if ( show || ! ref.current ) {
			return;
		}
		if ( ! window.IntersectionObserver ) {
			setShow( true );
			return;
		}
		const observer = new window.IntersectionObserver( ( entries ) => {
			if ( entries.some( ( e ) => e.isIntersecting ) ) {
				setShow( true );
				observer.disconnect();
			}
		}, { rootMargin: '300px' } );
		observer.observe( ref.current );
		return () => observer.disconnect();
	}, [ show ] );

	const blocks = useMemo( () => ( show ? parse( pattern.content ) : [] ), [ show, pattern.content ] );

	return (
		<div className="iss-pb-pattern__live" ref={ ref }>
			{ show && blocks.length > 0 && <BlockPreview blocks={ blocks } viewportWidth={ pattern.viewportWidth } /> }
		</div>
	);
}

function PatternCard( { pattern, showFree, onOpen } ) {
	const onKey = ( event ) => {
		if ( 'Enter' === event.key || ' ' === event.key ) {
			event.preventDefault();
			onOpen();
		}
	};
	return (
		<div role="button" tabIndex={ 0 } className={ 'iss-pb-pattern iss-pb-pattern--' + pattern.tone } onClick={ onOpen } onKeyDown={ onKey }>
			<span className="iss-pb-pattern__thumb">
				{ 'theme' === pattern.source
					? <LivePreview pattern={ pattern } lazy />
					: ( pattern.thumb && <img src={ pattern.thumb } alt="" loading="lazy" decoding="async" /> ) }
			</span>
			<span className="iss-pb-pattern__title">
				{ pattern.title }
				{ pattern.locked && <span className="iss-pb-pattern__lock"><LockIcon />{ __( 'Premium', 'inspiro-starter-sites' ) }</span> }
				{ showFree && 'theme' === pattern.source && <span className="iss-pb-pattern__free">{ __( 'Free', 'inspiro-starter-sites' ) }</span> }
			</span>
		</div>
	);
}

/**
 * @param {Object}   props
 * @param {Object}   props.tabs     The modal's mode switch, shown in the side header.
 * @param {Function} props.onInsert ( { markup }, pattern ) => void — puts the blocks in the editor.
 * @param {Function} props.onBusy   ( bool ) => void — the modal must not close mid-insert.
 */
export default function PatternLibrary( { tabs, onInsert, onBusy } ) {
	const [ data, setData ] = useState( cache );
	const [ failed, setFailed ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ source, setSource ] = useState( '' ); // '' = both, 'theme', 'library'
	const [ category, setCategory ] = useState( '' );
	const [ tone, setTone ] = useState( '' );
	const [ selected, setSelected ] = useState( null );
	const [ inserting, setInserting ] = useState( false );

	// The theme's patterns come from the editor's own store.
	const registered = useSelect( ( select ) => {
		const core = select( 'core' );
		const list = core.getBlockPatterns ? core.getBlockPatterns() : null;
		if ( list && list.length ) {
			return list;
		}
		return select( 'core/block-editor' ).getSettings().__experimentalBlockPatterns || list || [];
	}, [] );

	const themePatterns = useMemo(
		() => ( registered || [] ).filter( ( p ) => THEME_NAMES.has( p.name ) && false !== p.inserter && p.content ).map( fromTheme ),
		[ registered ]
	);

	useEffect( () => {
		if ( data || failed ) {
			return;
		}
		let alive = true;
		api.patterns()
			.then( ( res ) => {
				cache = res;
				if ( alive ) {
					setData( res );
				}
			} )
			.catch( ( e ) => {
				// The theme's patterns still work without the library.
				if ( alive ) {
					setFailed( true );
					setError( e.message );
				}
			} );
		return () => {
			alive = false;
		};
	}, [ data, failed ] );

	const library = useMemo( () => ( data ? data.patterns.map( ( p ) => ( { ...p, source: 'library' } ) ) : [] ), [ data ] );
	const ready = !! data || failed;
	const locked = library.some( ( p ) => p.locked );

	// Search, source and tone narrow the counts too, so a category never
	// promises more than it shows.
	const narrowed = useMemo( () => {
		const q = query.trim().toLowerCase();
		return [ ...themePatterns, ...library ].filter( ( p ) =>
			( ! source || p.source === source ) &&
			( ! tone || p.tone === tone ) &&
			( ! q || p.title.toLowerCase().includes( q ) || p.categories.some( ( c ) => categoryLabel( c ).toLowerCase().includes( q ) ) )
		);
	}, [ themePatterns, library, query, source, tone ] );

	const categories = useMemo( () => {
		const counts = {};
		narrowed.forEach( ( p ) => p.categories.forEach( ( c ) => {
			counts[ c ] = ( counts[ c ] || 0 ) + 1;
		} ) );
		return Object.keys( counts )
			.sort( ( a, b ) => categoryLabel( a ).localeCompare( categoryLabel( b ) ) )
			.map( ( slug ) => ( { slug, count: counts[ slug ] } ) );
	}, [ narrowed ] );

	const visible = useMemo( () => ( category ? narrowed.filter( ( p ) => p.categories.includes( category ) ) : narrowed ), [ narrowed, category ] );

	const groups = [
		{
			key: 'theme',
			title: config.themeName
				? sprintf( /* translators: %s: theme name */ __( 'Included with %s', 'inspiro-starter-sites' ), config.themeName )
				: __( 'Included with your theme', 'inspiro-starter-sites' ),
			note: locked ? __( 'Free', 'inspiro-starter-sites' ) : '',
			items: visible.filter( ( p ) => 'theme' === p.source ),
		},
		{
			key: 'library',
			title: __( 'Inspiro Premium patterns', 'inspiro-starter-sites' ),
			note: '',
			items: visible.filter( ( p ) => 'library' === p.source ),
		},
	].filter( ( g ) => g.items.length );

	const position = selected ? visible.findIndex( ( p ) => p.id === selected.id ) : -1;
	const open = ( pattern ) => {
		setSelected( pattern );
		setError( '' );
	};
	const step = ( dir ) => {
		const next = visible[ position + dir ];
		if ( next ) {
			open( next );
		}
	};
	const pick = ( setter, value ) => {
		setter( value );
		setSelected( null );
	};

	const insert = ( pattern ) => {
		if ( inserting || pattern.locked || pattern.missing.length ) {
			return;
		}
		setInserting( true );
		onBusy( true );
		setError( '' );
		if ( 'theme' === pattern.source ) {
			// Already in the editor: nothing to fetch.
			onInsert( { markup: pattern.content }, pattern );
			return;
		}
		api.pattern( [ pattern.id ], config.postId )
			.then( ( res ) => onInsert( res, pattern ) )
			.catch( ( e ) => {
				setError( e.message );
				setInserting( false );
				onBusy( false );
			} );
	};

	const total = themePatterns.length + library.length;

	return (
		<>
			<aside className="iss-pb-side iss-pb-side--patterns">
				<header className="iss-pb-side__head">
					<h2>{ __( 'Pattern library', 'inspiro-starter-sites' ) }</h2>
					<p>{ __( 'Ready-made sections designed for Inspiro. Pick one and it goes into your page with sample text and photos you can replace.', 'inspiro-starter-sites' ) }</p>
				</header>
				{ tabs }
				<section className="iss-pb-block iss-pb-patterns__search">
					<SearchControl
						value={ query }
						onChange={ ( value ) => pick( setQuery, value ) }
						placeholder={ __( 'Search patterns', 'inspiro-starter-sites' ) }
						__nextHasNoMarginBottom
					/>
				</section>
				{ themePatterns.length > 0 && library.length > 0 && (
					<div className="iss-pb-patterns__sources" role="radiogroup" aria-label={ __( 'Which patterns to show', 'inspiro-starter-sites' ) }>
						<button type="button" role="radio" aria-checked={ ! source } className={ ! source ? 'is-active' : '' } onClick={ () => pick( setSource, '' ) }>
							{ __( 'All', 'inspiro-starter-sites' ) }
						</button>
						<button type="button" role="radio" aria-checked={ 'theme' === source } className={ 'theme' === source ? 'is-active' : '' } onClick={ () => pick( setSource, 'theme' ) }>
							{ locked ? __( 'Free', 'inspiro-starter-sites' ) : __( 'Theme', 'inspiro-starter-sites' ) }
						</button>
						<button type="button" role="radio" aria-checked={ 'library' === source } className={ 'library' === source ? 'is-active' : '' } onClick={ () => pick( setSource, 'library' ) }>
							{ __( 'Premium', 'inspiro-starter-sites' ) }
						</button>
					</div>
				) }
				<nav className="iss-pb-patterns__cats" aria-label={ __( 'Pattern categories', 'inspiro-starter-sites' ) }>
					<button type="button" className={ 'iss-pb-patterns__cat' + ( ! category ? ' is-active' : '' ) } onClick={ () => pick( setCategory, '' ) }>
						<span>{ __( 'All patterns', 'inspiro-starter-sites' ) }</span>
						<b>{ narrowed.length }</b>
					</button>
					{ categories.map( ( c ) => (
						<button key={ c.slug } type="button" className={ 'iss-pb-patterns__cat' + ( category === c.slug ? ' is-active' : '' ) } onClick={ () => pick( setCategory, c.slug ) }>
							<span>{ categoryLabel( c.slug ) }</span>
							<b>{ c.count }</b>
						</button>
					) ) }
				</nav>
				{ locked && (
					<div className="iss-pb-side__foot">
						<Upsell />
					</div>
				) }
			</aside>

			<main className="iss-pb-canvas iss-pb-patterns">
				{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }

				{ ! ready && ! total && (
					<div className="iss-pb-patterns__loading"><Spinner /></div>
				) }

				{ ( ready || total > 0 ) && ! selected && (
					<>
						<div className="iss-pb-patterns__bar">
							<span className="iss-pb-patterns__count">
								{ sprintf( /* translators: %d: number of patterns */ _n( '%d pattern', '%d patterns', visible.length, 'inspiro-starter-sites' ), visible.length ) }
								{ ! ready && <Spinner /> }
							</span>
							<div className="iss-pb-patterns__tones" role="radiogroup" aria-label={ __( 'Background', 'inspiro-starter-sites' ) }>
								{ TONES.map( ( t ) => (
									<button key={ t.value } type="button" role="radio" aria-checked={ tone === t.value } className={ tone === t.value ? 'is-active' : '' } onClick={ () => setTone( t.value ) }>
										{ t.label }
									</button>
								) ) }
							</div>
						</div>
						{ ! visible.length && ready && (
							<p className="iss-pb-patterns__none">{ __( 'No patterns match. Try another word or category.', 'inspiro-starter-sites' ) }</p>
						) }
						{ groups.map( ( group ) => (
							<section key={ group.key } className="iss-pb-patterns__group">
								{ ( groups.length > 1 || 'theme' === group.key ) && (
									<h3 className="iss-pb-patterns__grouptitle">
										{ group.title }
										{ group.note && <span className="iss-pb-pattern__free">{ group.note }</span> }
										<small>{ group.items.length }</small>
									</h3>
								) }
								<div className="iss-pb-patterns__grid">
									{ group.items.map( ( p ) => (
										<PatternCard key={ p.id } pattern={ p } showFree={ locked } onOpen={ () => open( p ) } />
									) ) }
								</div>
							</section>
						) ) }
					</>
				) }

				{ selected && (
					<div className="iss-pb-patterns__detail">
						<div className="iss-pb-patterns__detailbar">
							<Button variant="tertiary" className="iss-pb-patterns__back" onClick={ () => setSelected( null ) } disabled={ inserting }>
								{ __( '← All patterns', 'inspiro-starter-sites' ) }
							</Button>
							<div className="iss-pb-patterns__name">
								<h2>{ selected.title }</h2>
								<p>
									{ [
										'theme' === selected.source
											? ( config.themeName ? sprintf( /* translators: %s: theme name */ __( 'Included with %s', 'inspiro-starter-sites' ), config.themeName ) : __( 'Included with your theme', 'inspiro-starter-sites' ) )
											: __( 'Inspiro Premium', 'inspiro-starter-sites' ),
										...selected.categories.map( categoryLabel ),
									].join( ' · ' ) }
								</p>
							</div>
							<div className="iss-pb-patterns__actions">
								<Button variant="tertiary" onClick={ () => step( -1 ) } disabled={ inserting || position <= 0 } aria-label={ __( 'Previous pattern', 'inspiro-starter-sites' ) }>‹</Button>
								<Button variant="tertiary" onClick={ () => step( 1 ) } disabled={ inserting || position < 0 || position >= visible.length - 1 } aria-label={ __( 'Next pattern', 'inspiro-starter-sites' ) }>›</Button>
								{ selected.locked ? (
									<span className="iss-pb-pattern__lock iss-pb-pattern__lock--large"><LockIcon />{ __( 'Premium', 'inspiro-starter-sites' ) }</span>
								) : (
									<Button variant="primary" onClick={ () => insert( selected ) } isBusy={ inserting } disabled={ inserting || selected.missing.length > 0 }>
										{ inserting ? __( 'Inserting…', 'inspiro-starter-sites' ) : __( 'Insert pattern', 'inspiro-starter-sites' ) }
									</Button>
								) }
							</div>
						</div>
						{ ! selected.locked && selected.missing.length > 0 && (
							<Notice status="warning" isDismissible={ false }>
								{ sprintf( /* translators: %s: what the site is missing, e.g. "the WPZOOM Forms plugin" */ __( 'This pattern needs %s.', 'inspiro-starter-sites' ), selected.missing.map( ( n ) => NEED_LABELS[ n ] || n ).join( ', ' ) ) }
							</Notice>
						) }
						<div className={ 'iss-pb-patterns__large iss-pb-pattern--' + selected.tone }>
							{ 'theme' === selected.source
								? <LivePreview key={ selected.id } pattern={ selected } />
								: ( selected.thumb && <img key={ selected.id } src={ selected.thumb } alt={ selected.title } /> ) }
						</div>
					</div>
				) }
			</main>
		</>
	);
}
