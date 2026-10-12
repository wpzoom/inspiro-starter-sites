/**
 * Suggestions while typing: a local keyword → catalog role map, so the
 * strip under the prompt reacts on every keystroke without a request.
 */

const ROLE_KEYWORDS = {
	hero: [ 'hero', 'banner', 'welcome', 'landing', 'homepage', 'home page', 'headline' ],
	'page-header': [ 'page header', 'page title', 'intro header' ],
	about: [ 'about', 'our story', 'story', 'history', 'mission', 'who we are', 'founder', 'since' ],
	team: [ 'team', 'staff', 'people', 'crew', 'team members', 'instructors', 'doctors', 'our people', 'meet the' ],
	services: [ 'service', 'what we do', 'offer', 'solutions', 'treatments', 'packages', 'wholesale', 'training' ],
	features: [ 'feature', 'benefit', 'why choose', 'why us', 'advantages', 'values', 'what we believe', 'highlights' ],
	testimonials: [ 'testimonial', 'review', 'what people say', 'what clients say', 'what members say', 'feedback', 'quotes', 'customer stories' ],
	pricing: [ 'pricing', 'price', 'plan', 'membership', 'subscription', 'tiers', 'rates', 'packages' ],
	faq: [ 'faq', 'question', 'frequently asked', 'q&a', 'answers' ],
	cta: [ 'call to action', 'cta', 'book', 'get started', 'sign up', 'subscribe', 'visit', 'get in touch', 'contact us', 'trial', 'free trial' ],
	contact: [ 'contact', 'get in touch', 'reach us', 'address', 'opening hours', 'email us', 'phone', 'location', 'map' ],
	process: [ 'process', 'how it works', 'how we work', 'steps', 'workflow', 'approach', 'method' ],
	stats: [ 'stats', 'numbers', 'figures', 'milestones', 'results', 'achievements', 'in numbers', 'years of experience' ],
	portfolio: [ 'portfolio', 'projects', 'work', 'case studies', 'our work', 'showcase' ],
	gallery: [ 'gallery', 'photos', 'pictures', 'images', 'instagram' ],
	blog: [ 'blog', 'news', 'articles', 'posts', 'journal', 'latest' ],
	logos: [ 'logos', 'clients', 'partners', 'brands', 'featured in', 'as seen', 'trusted by' ],
	menu: [ 'menu', 'dishes', 'drinks', 'wine list', 'cocktails', 'breakfast', 'lunch', 'dinner' ],
	events: [ 'event', 'schedule', 'calendar', 'classes', 'workshops', 'tours', 'upcoming', 'dates' ],
	intro: [ 'introduction', 'intro', 'overview', 'summary', 'statement', 'manifesto' ],
};

/**
 * Roles the prompt mentions, most-mentioned first.
 *
 * @param {string} text Prompt.
 * @return {string[]} Roles.
 */
export function rolesFor( text ) {
	const lower = ( text || '' ).toLowerCase();
	const scores = {};
	Object.keys( ROLE_KEYWORDS ).forEach( ( role ) => {
		ROLE_KEYWORDS[ role ].forEach( ( keyword ) => {
			const idx = lower.indexOf( keyword );
			if ( idx !== -1 ) {
				// Longer keywords are more specific; earlier mentions matter a bit more.
				scores[ role ] = ( scores[ role ] || 0 ) + keyword.length + ( idx < 60 ? 2 : 0 );
			}
		} );
	} );
	return Object.keys( scores ).sort( ( a, b ) => scores[ b ] - scores[ a ] );
}

/**
 * Catalog sections to suggest for a prompt: a few per mentioned role,
 * available ones first, locked Premium ones after (as upgrade cards).
 *
 * @param {string}   text    Prompt.
 * @param {Object[]} index   Catalog index (from the catalog action).
 * @param {string[]} pinned  Ids already pinned (listed first, kept).
 * @param {number}   limit   Max cards.
 * @return {Object[]} Sections.
 */
export function suggest( text, index, pinned = [], limit = 10 ) {
	const roles = rolesFor( text );
	const out = [];
	const seen = {};
	pinned.forEach( ( id ) => {
		const s = index.find( ( x ) => x.id === id );
		if ( s && ! seen[ id ] ) {
			out.push( s );
			seen[ id ] = true;
		}
	} );
	const perRole = roles.length > 4 ? 2 : 3;
	roles.forEach( ( role ) => {
		const candidates = index
			.filter( ( s ) => s.role === role && ! seen[ s.id ] )
			.sort( ( a, b ) => Number( a.locked ) - Number( b.locked ) || Number( b.available ) - Number( a.available ) );
		candidates.slice( 0, perRole ).forEach( ( s ) => {
			out.push( s );
			seen[ s.id ] = true;
		} );
	} );
	return out.slice( 0, Math.max( limit, pinned.length ) );
}

export { ROLE_KEYWORDS };
