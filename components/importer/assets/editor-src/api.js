/**
 * admin-ajax calls for the page builder.
 *
 * The plan and build handlers stream keep-alive whitespace before their
 * JSON (see StreamingResponse.php), so responses are read as text and
 * trimmed before parsing. Every call carries the plugin's AJAX nonce.
 */

const config = window.inspiroStarterSitesPageBuilder || {};

/**
 * POST an admin-ajax action.
 *
 * @param {string} action Action name without the wp_ajax_ prefix.
 * @param {Object} data   Fields; arrays of scalars go as name[], other
 *                        arrays and objects are JSON-encoded.
 * @return {Promise<Object>} The `data` of a wp_send_json_success payload.
 *                          Rejects with { code, message } on error.
 */
export async function ajax( action, data = {} ) {
	const body = new FormData();
	body.append( 'action', action );
	body.append( 'security', config.nonce || '' );
	Object.keys( data ).forEach( ( key ) => {
		const value = data[ key ];
		if ( Array.isArray( value ) && value.every( ( v ) => typeof v !== 'object' ) ) {
			value.forEach( ( v ) => body.append( key + '[]', v ) );
		} else if ( value !== null && typeof value === 'object' ) {
			body.append( key, JSON.stringify( value ) );
		} else if ( value !== undefined && value !== null ) {
			body.append( key, value );
		}
	} );

	let text;
	try {
		const response = await fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } );
		text = await response.text();
	} catch ( e ) {
		throw { code: 'network', message: e.message || 'Network error' };
	}

	let payload;
	try {
		payload = JSON.parse( text.trim() );
	} catch ( e ) {
		throw { code: 'parse', message: 'Unexpected response from the server.' };
	}

	if ( ! payload || ! payload.success ) {
		const err = ( payload && payload.data ) || {};
		throw { code: err.code || 'error', message: err.message || 'Something went wrong.' };
	}
	return payload.data;
}

export const api = {
	catalog: () => ajax( 'inspiro_starter_sites_ai_page_catalog' ),
	quota: () => ajax( 'inspiro_starter_sites_ai_page_quota' ),
	preview: ( ids, headlines = {} ) => ajax( 'inspiro_starter_sites_ai_page_preview', { ids, headlines } ),
	pool: () => ajax( 'inspiro_starter_sites_ai_page_pool' ),
	instant: ( ids, headlines, postId ) => ajax( 'inspiro_starter_sites_ai_page_instant', { ids, headlines, post_id: postId } ),
	plan: ( input ) => ajax( 'inspiro_starter_sites_ai_page_plan', input ),
	build: ( plan, postId ) => ajax( 'inspiro_starter_sites_ai_page_build', { plan, post_id: postId } ),
	connect: ( email, consent ) => ajax( 'inspiro_starter_sites_ai_connect', { email, consent: consent ? 1 : 0 } ),
	verify: ( code ) => ajax( 'inspiro_starter_sites_ai_verify', { code } ),
};

export { config };
