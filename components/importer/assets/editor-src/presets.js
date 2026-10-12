/**
 * Page presets, section roles and the local section picker.
 *
 * People are bad at writing prompts: a preset gives them a complete page
 * in two clicks, and the picker turns a list of roles into real catalog
 * sections instantly — before the AI has said a word.
 */

import { __ } from '@wordpress/i18n';

export const ROLE_META = {
	hero: { label: __( 'Hero', 'inspiro-starter-sites' ), icon: 'cover-image', hint: __( 'Big opening with a headline and a photo', 'inspiro-starter-sites' ) },
	'page-header': { label: __( 'Page header', 'inspiro-starter-sites' ), icon: 'heading', hint: __( 'Title and intro line', 'inspiro-starter-sites' ) },
	intro: { label: __( 'Intro', 'inspiro-starter-sites' ), icon: 'editor-paragraph', hint: __( 'A short statement', 'inspiro-starter-sites' ) },
	about: { label: __( 'Story', 'inspiro-starter-sites' ), icon: 'book', hint: __( 'Who you are and why', 'inspiro-starter-sites' ) },
	team: { label: __( 'Team', 'inspiro-starter-sites' ), icon: 'groups', hint: __( 'People with photos and roles', 'inspiro-starter-sites' ) },
	services: { label: __( 'Services', 'inspiro-starter-sites' ), icon: 'hammer', hint: __( 'What you offer', 'inspiro-starter-sites' ) },
	features: { label: __( 'Features / values', 'inspiro-starter-sites' ), icon: 'star-filled', hint: __( 'Benefits or principles', 'inspiro-starter-sites' ) },
	process: { label: __( 'How it works', 'inspiro-starter-sites' ), icon: 'editor-ol', hint: __( 'Numbered steps', 'inspiro-starter-sites' ) },
	stats: { label: __( 'Numbers', 'inspiro-starter-sites' ), icon: 'chart-bar', hint: __( 'Figures that impress', 'inspiro-starter-sites' ) },
	testimonials: { label: __( 'Testimonials', 'inspiro-starter-sites' ), icon: 'format-quote', hint: __( 'What customers say', 'inspiro-starter-sites' ) },
	pricing: { label: __( 'Pricing', 'inspiro-starter-sites' ), icon: 'tag', hint: __( 'Plans and prices', 'inspiro-starter-sites' ) },
	faq: { label: __( 'FAQ', 'inspiro-starter-sites' ), icon: 'editor-help', hint: __( 'Questions and answers', 'inspiro-starter-sites' ) },
	logos: { label: __( 'Logos', 'inspiro-starter-sites' ), icon: 'awards', hint: __( 'Clients or press', 'inspiro-starter-sites' ) },
	portfolio: { label: __( 'Portfolio', 'inspiro-starter-sites' ), icon: 'portfolio', hint: __( 'Your work', 'inspiro-starter-sites' ) },
	gallery: { label: __( 'Gallery', 'inspiro-starter-sites' ), icon: 'format-gallery', hint: __( 'A grid of photos', 'inspiro-starter-sites' ) },
	menu: { label: __( 'Menu', 'inspiro-starter-sites' ), icon: 'food', hint: __( 'Dishes and prices', 'inspiro-starter-sites' ) },
	events: { label: __( 'Events', 'inspiro-starter-sites' ), icon: 'calendar-alt', hint: __( 'Dates and classes', 'inspiro-starter-sites' ) },
	blog: { label: __( 'Latest posts', 'inspiro-starter-sites' ), icon: 'admin-post', hint: __( 'From your blog', 'inspiro-starter-sites' ) },
	contact: { label: __( 'Contact', 'inspiro-starter-sites' ), icon: 'email-alt', hint: __( 'Details and a form', 'inspiro-starter-sites' ) },
	cta: { label: __( 'Call to action', 'inspiro-starter-sites' ), icon: 'megaphone', hint: __( 'One clear next step', 'inspiro-starter-sites' ) },
};

export const ROLE_ORDER = Object.keys( ROLE_META );

export const PRESETS = [
	{
		key: 'about',
		label: __( 'About', 'inspiro-starter-sites' ),
		icon: 'book',
		pageType: 'inner',
		roles: [ 'page-header', 'about', 'team', 'features', 'cta' ],
		placeholder: __( 'Our story since 2015, the team of four, what we believe in, and an invitation to visit.', 'inspiro-starter-sites' ),
	},
	{
		key: 'services',
		label: __( 'Services', 'inspiro-starter-sites' ),
		icon: 'hammer',
		pageType: 'inner',
		roles: [ 'page-header', 'services', 'process', 'testimonials', 'cta' ],
		placeholder: __( 'Three services with short descriptions, how we work in four steps, what clients say, and how to get in touch.', 'inspiro-starter-sites' ),
	},
	{
		key: 'pricing',
		label: __( 'Pricing', 'inspiro-starter-sites' ),
		icon: 'tag',
		pageType: 'inner',
		roles: [ 'page-header', 'pricing', 'testimonials', 'faq', 'cta' ],
		placeholder: __( 'Three plans (Starter, Pro, Business), what members say, common questions, and a free trial.', 'inspiro-starter-sites' ),
	},
	{
		key: 'contact',
		label: __( 'Contact', 'inspiro-starter-sites' ),
		icon: 'email-alt',
		pageType: 'inner',
		roles: [ 'page-header', 'contact', 'faq' ],
		placeholder: __( 'Address, hours, phone and email, a contact form, and answers to questions people ask before visiting.', 'inspiro-starter-sites' ),
	},
	{
		key: 'portfolio',
		label: __( 'Portfolio', 'inspiro-starter-sites' ),
		icon: 'portfolio',
		pageType: 'inner',
		roles: [ 'page-header', 'portfolio', 'process', 'testimonials', 'cta' ],
		placeholder: __( 'Our recent projects, how a project runs from brief to delivery, client feedback, and a way to start one.', 'inspiro-starter-sites' ),
	},
	{
		key: 'faq',
		label: __( 'FAQ', 'inspiro-starter-sites' ),
		icon: 'editor-help',
		pageType: 'inner',
		roles: [ 'page-header', 'faq', 'cta' ],
		placeholder: __( 'The eight questions customers ask most, grouped simply, and a way to ask one that is missing.', 'inspiro-starter-sites' ),
	},
	{
		key: 'landing',
		label: __( 'Landing page', 'inspiro-starter-sites' ),
		icon: 'megaphone',
		pageType: 'landing',
		roles: [ 'hero', 'features', 'stats', 'testimonials', 'pricing', 'cta' ],
		placeholder: __( 'A landing page for our spring workshop: who it is for, what you learn, the results so far, prices, and sign-up.', 'inspiro-starter-sites' ),
	},
	{
		key: 'home',
		label: __( 'Homepage', 'inspiro-starter-sites' ),
		icon: 'admin-home',
		pageType: 'home',
		roles: [ 'hero', 'intro', 'services', 'about', 'testimonials', 'cta' ],
		placeholder: __( 'Homepage for a family coffee roastery: what we do, why it is special, a taste of the story, and a visit.', 'inspiro-starter-sites' ),
	},
	{
		key: 'custom',
		label: __( 'Blank', 'inspiro-starter-sites' ),
		icon: 'edit',
		pageType: 'auto',
		roles: [],
		placeholder: __( 'Describe the page or section you need, and pick the sections below.', 'inspiro-starter-sites' ),
	},
];

export const STYLES = [
	{ value: '', label: __( 'Balanced', 'inspiro-starter-sites' ), hint: __( 'A mix the designers chose', 'inspiro-starter-sites' ) },
	{ value: 'light', label: __( 'Light', 'inspiro-starter-sites' ), hint: __( 'Airy, lots of white', 'inspiro-starter-sites' ) },
	{ value: 'dark', label: __( 'Dark', 'inspiro-starter-sites' ), hint: __( 'Moody, high contrast', 'inspiro-starter-sites' ) },
	{ value: 'image', label: __( 'Photo-led', 'inspiro-starter-sites' ), hint: __( 'Big pictures carry it', 'inspiro-starter-sites' ) },
	{ value: 'minimal', label: __( 'Minimal', 'inspiro-starter-sites' ), hint: __( 'Type first, few photos', 'inspiro-starter-sites' ) },
];

const DARK_TONES = [ 'dark', 'image' ];

/**
 * Hand-picked first choices per role: general-purpose sections that read
 * well for most businesses. Niche layouts (category tiles, a band's crew
 * shot, a release list) stay reachable through "Try another layout" but
 * never open a page by default. Order matters: earlier is preferred.
 */
const PREFERRED = {
	'page-header': [ 'header-title-image', 'header-tint-centered', 'header-photo-text', 'header-title-only', 'header-photo-simple', 'header-dark-type' ],
	hero: [ 'hero-split-photo', 'hero-photo-centered', 'hero-split-rounded-photo', 'hero-photo-classic', 'hero-photo-boxed', 'hero-type-statement' ],
	intro: [ 'intro-statement', 'intro-welcome-paragraph' ],
	about: [ 'about-photo-checklist', 'about-story-staggered', 'about-text-two-photos', 'about-two-photos', 'about-story-stats', 'about-history-zigzag', 'about-editorial-rows', 'about-mission-photo' ],
	team: [ 'team-round-avatars', 'team-cards', 'team-grid', 'team-dark' ],
	services: [ 'services-icon-cards', 'services-photo-cards', 'services-photo-gradient-cards', 'services-accordion-photo', 'services-expandable', 'services-icon-grid' ],
	features: [ 'features-icon-cards', 'features-photo-checklist', 'features-values-numbered', 'features-photo-list', 'features-dark-icon-cards', 'features-values' ],
	process: [ 'process-numbered', 'process-ruled-steps', 'process-photo-steps', 'process-step-cards' ],
	stats: [ 'stats-light-ruled', 'stats-dark-row', 'stats-photo-bar', 'stats-dark-figures' ],
	testimonials: [ 'testimonials-avatar-list', 'testimonial-centered-avatar', 'testimonials-quote-columns', 'testimonials-dark', 'testimonials-tint-pair' ],
	pricing: [ 'pricing-three-cards', 'pricing-highlight', 'pricing-tint', 'pricing-dark-tiers' ],
	faq: [ 'faq-split', 'faq-tint-split', 'faq-centered-minimal', 'faq-boxed' ],
	contact: [ 'contact-details-form', 'contact-info-cards', 'contact-form-photo', 'contact-details-strip' ],
	cta: [ 'cta-accent-centered', 'cta-photo-boxed', 'cta-accent-split', 'cta-dark-left-simple', 'cta-photo-bold' ],
	portfolio: [ 'portfolio-staggered-photos', 'portfolio-caption-grid', 'projects-dark-cards', 'portfolio-rounded' ],
};

function preferredRank( section ) {
	const list = PREFERRED[ section.role ] || [];
	const k = list.indexOf( section.id );
	return k === -1 ? 100 : k;
}

/**
 * How well a section's tone suits a style (higher is better).
 */
function styleScore( section, style ) {
	const tone = section.tone || 'light';
	switch ( style ) {
		case 'light':
			return tone === 'light' || tone === 'tint' ? 2 : tone === 'accent' ? 1 : 0;
		case 'dark':
			return tone === 'dark' ? 2 : tone === 'image' || tone === 'accent' ? 1 : 0;
		case 'image':
			return tone === 'image' ? 2 : section.photos > 0 ? 1 : 0;
		case 'minimal':
			return section.photos === 0 ? 2 : tone === 'light' ? 1 : 0;
		default:
			return 1;
	}
}

/**
 * Pick one catalog section for a role, varied by a seed and avoiding
 * sections already on the page and a dark section after a dark one.
 *
 * @param {string}   role      Role.
 * @param {Object[]} index     Catalog index.
 * @param {Object}   opts      { pageType, style, seed, exclude, prevTone }
 * @return {Object|null} Section.
 */
export function pickSection( role, index, opts = {} ) {
	const { pageType = 'inner', style = '', seed = 0, exclude = [], prevTone = '' } = opts;
	const home = pageType === 'home' || pageType === 'landing';
	let pool = index.filter( ( s ) => s.role === role && s.available && ! exclude.includes( s.id ) )
		.filter( ( s ) => ! ( ( s.fits === 'home' && ! home ) || ( s.fits === 'inner' && home ) ) );
	if ( ! pool.length ) {
		return null;
	}
	// Best style fit first, then the curated order, then stable order. The
	// seed walks the whole list, so "Try another layout" cycles through
	// every option, best ones first.
	pool = pool.sort( ( a, b ) => styleScore( b, style ) - styleScore( a, style ) || preferredRank( a ) - preferredRank( b ) || a.id.localeCompare( b.id ) );
	let choice = pool[ seed % pool.length ];
	const best = pool.filter( ( s ) => styleScore( s, style ) === styleScore( pool[ 0 ], style ) );
	if ( DARK_TONES.includes( prevTone ) && DARK_TONES.includes( choice.tone ) ) {
		const lighter = best.find( ( s ) => ! DARK_TONES.includes( s.tone ) ) || pool.find( ( s ) => ! DARK_TONES.includes( s.tone ) );
		if ( lighter ) {
			choice = lighter;
		}
	}
	return choice;
}

/**
 * Turn an ordered list of roles into section rows, keeping rows the user
 * already has for roles that stay selected.
 *
 * @param {string[]} roles    Roles in page order.
 * @param {Object[]} index    Catalog index.
 * @param {Object[]} current  Existing rows.
 * @param {Object}   opts     { pageType, style, seeds }
 * @return {Object[]} Rows: { id, role, tone, description, photos, headline, brief }
 */
export function composeRows( roles, index, current = [], opts = {} ) {
	const { pageType = 'inner', style = '', seeds = {} } = opts;
	const rows = [];
	const used = [];
	roles.forEach( ( role ) => {
		const kept = current.find( ( r ) => r.role === role && ! used.includes( r.id ) );
		const prevTone = rows.length ? rows[ rows.length - 1 ].tone : '';
		let section = kept && index.some( ( s ) => s.id === kept.id ) ? kept : null;
		if ( ! section ) {
			const picked = pickSection( role, index, { pageType, style, seed: seeds[ role ] || 0, exclude: used, prevTone } );
			section = picked ? toRow( picked ) : null;
		}
		if ( section ) {
			rows.push( section );
			used.push( section.id );
		}
	} );
	return rows;
}

export function toRow( section, extra = {} ) {
	return {
		id: section.id,
		role: section.role,
		tone: section.tone,
		tier: section.tier,
		description: section.description,
		photos: section.photos,
		headline: '',
		brief: '',
		...extra,
	};
}

/**
 * Roles the catalog can serve on this site, in canonical order.
 */
export function availableRoles( index ) {
	const present = new Set( index.filter( ( s ) => s.available ).map( ( s ) => s.role ) );
	return ROLE_ORDER.filter( ( r ) => present.has( r ) );
}
