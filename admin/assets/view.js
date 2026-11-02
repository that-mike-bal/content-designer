/**
 * Content Designer — Interactivity API store.
 *
 * Drives the Tools → Illustrate screen: pick a draft, review the AI placement
 * plan, generate variations, choose finalists, place into the draft. All state
 * lives in the `content-designer` interactivity store; the server seeds the
 * initial state and REST root/nonce.
 *
 * Implementation notes:
 * - Store actions that await use function* generators (the runtime's scope
 *   handling only supports sync generators, NOT async functions/generators).
 * - `request` is a plain module-level async helper — it only touches global
 *   state, never getContext()/getElement(), so it needs no scope restoration.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

/**
 * REST helper. Throws with the server's message on any non-2xx response.
 *
 * @param {string} path Path under the content-designer/v1 root, e.g. '/scan'.
 * @param {Object} body JSON body; omit for GET.
 * @return {Promise<any>} Parsed JSON.
 */
const request = async ( path, body ) => {
	const res = await fetch( state.restRoot + path, {
		method: body ? 'POST' : 'GET',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': state.nonce,
		},
		body: body ? JSON.stringify( body ) : undefined,
	} );
	let data = null;
	try {
		data = await res.json();
	} catch ( e ) {
		// Non-JSON response (proxy error page, fatal, ...).
	}
	if ( ! res.ok ) {
		throw new Error(
			( data && data.message ) || `Request failed (HTTP ${ res.status })`
		);
	}
	return data;
};

/**
 * Generate all variations for one placement, replacing (and orphaning) any
 * previous results for it. Module-level sync generator so store actions can
 * `yield*` it — its yields bubble up to the runtime's generator runner.
 *
 * @param {Object} item Placement plan item.
 */
function* generateFor( item ) {
	// Previous shots for this placement become discard candidates.
	for ( const old of state.results[ item.id ] || [] ) {
		state.orphans.push( old.attachment_id );
	}
	const shots = [];
	for ( let v = 1; v <= ( item.variations || 1 ); v++ ) {
		const data = yield request( '/generate', {
			post_id: state.postId,
			placement: item,
			variation: v,
		} );
		shots.push( data );
	}
	state.results[ item.id ] = shots;
	if ( shots.length ) {
		state.chosen[ item.id ] = shots[ 0 ].attachment_id;
	}
}

const { state, actions } = store( 'content-designer', {
	state: {
		// --- step flags (computed) ---
		get isPick() {
			return state.step === 'pick';
		},
		get isPlan() {
			return state.step === 'plan';
		},
		get isGenerate() {
			return state.step === 'generate';
		},
		get isDone() {
			return state.step === 'done';
		},
		get hasDrafts() {
			return state.drafts.length > 0;
		},
		// Drafts filtered by the search box (root region context).
		get visibleDrafts() {
			const ctx = getContext();
			const q = ( ctx.search || '' ).toLowerCase();
			return q
				? state.drafts.filter( ( d ) =>
						d.title.toLowerCase().includes( q )
				  )
				: state.drafts;
		},
		// Approved placements only (used in the generate step).
		get approvedPlan() {
			return state.plan.filter( ( p ) => p.approved );
		},
		// Variations generated for the placement in loop context.
		get shotsFor() {
			const { item } = getContext();
			return state.results[ item.id ] || [];
		},
		// Whether the shot in context is the chosen one for its placement.
		get isChosen() {
			const { item, shot } = getContext();
			return state.chosen[ item.id ] === shot.attachment_id;
		},
	},

	actions: {
		setSearch( event ) {
			getContext().search = event.target.value;
		},

		// STEP 1 → 2: scan a draft into a plan.
		*scan() {
			const id = parseInt( getElement().ref.dataset.id, 10 );
			state.error = '';
			state.busy = true;
			state.busyLabel = 'Reading the draft like an editor…';
			try {
				const data = yield request( '/scan', { post_id: id } );
				state.postId = id;
				state.postTitle = data.post.title;
				state.plan = data.plan.map( ( p ) => ( { ...p } ) );
				state.results = {};
				state.chosen = {};
				state.orphans = [];
				state.step = 'plan';
			} catch ( e ) {
				state.error = e.message;
			} finally {
				state.busy = false;
			}
		},

		toggleApproved() {
			const id = getElement().ref.dataset.id;
			const item = state.plan.find( ( p ) => p.id === id );
			if ( item ) {
				item.approved = ! item.approved;
			}
		},

		editScene( event ) {
			const id = getElement().ref.dataset.id;
			const item = state.plan.find( ( p ) => p.id === id );
			if ( item ) {
				item.scene = event.target.value;
			}
		},

		// STEP 2 → 3: generate every approved placement's variations.
		*generateAll() {
			state.error = '';
			state.busy = true;
			const approved = state.plan.filter( ( p ) => p.approved );
			let n = 0;
			try {
				for ( const item of approved ) {
					n++;
					state.busyLabel = `Generating ${ item.role } (${ n }/${ approved.length })…`;
					yield* generateFor( item );
				}
				state.step = 'generate';
			} catch ( e ) {
				state.error = e.message;
			} finally {
				state.busy = false;
			}
		},

		*regenerate() {
			const id = getElement().ref.dataset.id;
			const item = state.plan.find( ( p ) => p.id === id );
			if ( ! item ) {
				return;
			}
			state.error = '';
			state.busy = true;
			state.busyLabel = `Regenerating ${ item.role }…`;
			try {
				yield* generateFor( item );
			} catch ( e ) {
				state.error = e.message;
			} finally {
				state.busy = false;
			}
		},

		choose() {
			const { pid, aid } = getElement().ref.dataset;
			state.chosen[ pid ] = parseInt( aid, 10 );
		},

		backToPlan() {
			state.step = 'plan';
		},

		// STEP 3 → 4: place chosen images; discard everything superseded —
		// unchosen variations, replaced (orphaned) generations, and shots of
		// placements that were un-approved after generating.
		*place() {
			state.error = '';
			state.busy = true;
			state.busyLabel = 'Placing images into the draft…';
			try {
				const selections = [];
				const discard = [ ...state.orphans ];
				for ( const item of state.plan ) {
					const shots = state.results[ item.id ] || [];
					const chosen = state.chosen[ item.id ];
					for ( const shot of shots ) {
						if ( item.approved && shot.attachment_id === chosen ) {
							selections.push( {
								placement: item,
								attachment_id: chosen,
							} );
						} else {
							discard.push( shot.attachment_id );
						}
					}
				}
				const data = yield request( '/place', {
					post_id: state.postId,
					selections,
					discard,
				} );
				state.summary = data;
				state.orphans = [];
				state.step = 'done';
			} catch ( e ) {
				state.error = e.message;
			} finally {
				state.busy = false;
			}
		},

		reset() {
			state.step = 'pick';
			state.plan = [];
			state.results = {};
			state.chosen = {};
			state.orphans = [];
			state.error = '';
		},
	},

	callbacks: {
		// Load the draft list on first paint.
		*init() {
			state.busy = true;
			state.busyLabel = 'Loading drafts…';
			try {
				const data = yield request( '/drafts' );
				state.drafts = Array.isArray( data ) ? data : [];
			} catch ( e ) {
				state.drafts = [];
				state.error = 'Could not load drafts: ' + e.message;
			} finally {
				state.busy = false;
			}
		},
	},
} );
