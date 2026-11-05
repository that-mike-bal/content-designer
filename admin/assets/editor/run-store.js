/**
 * Run state for the editor panel.
 *
 * State lives here rather than in component state because a run must outlive
 * the modal being opened and closed, and the sidebar being collapsed. Progress
 * is reported as it actually happens: the outline is a real fast endpoint, and
 * images appear one at a time because each /generate call returns one image.
 * Nothing is staged or faked.
 *
 * States: idle → outlining → planning → reviewing → generating → complete,
 * with cancelled and failed as exits. A failure returns to reviewing so a bad
 * image never costs the editorial plan that was already approved.
 */
( function ( wp ) {
	'use strict';

	window.contentDesigner = window.contentDesigner || {};

	const { select, dispatch } = wp.data;
	const { __, sprintf } = wp.i18n;
	const apiFetch = wp.apiFetch;

	const config = window.contentDesigner || {};

	const initial = {
		status: 'idle',
		log: '',
		postTitle: '',
		totalWords: 0,
		plan: [],
		results: {},
		chosen: {},
		discard: [],
		modalOpen: false,
		progress: { done: 0, total: 0 },
	};

	let state = Object.assign( {}, initial );
	let controller = null;
	const listeners = new Set();

	/**
	 * Merge a patch into state and notify subscribers.
	 *
	 * @param {Object} patch Partial state.
	 */
	function set( patch ) {
		state = Object.assign( {}, state, patch );
		listeners.forEach( function ( listener ) {
			listener();
		} );
	}

	/**
	 * @return {Object} Current state.
	 */
	function getState() {
		return state;
	}

	/**
	 * @param {Function} listener Called on every change.
	 * @return {Function} Unsubscribe.
	 */
	function subscribe( listener ) {
		listeners.add( listener );
		return function () {
			listeners.delete( listener );
		};
	}

	/**
	 * POST to the plugin's namespace. apiFetch carries the REST nonce.
	 *
	 * @param {string} path Route under the namespace.
	 * @param {Object} data Body.
	 * @return {Promise<any>}
	 */
	function post( path, data ) {
		return apiFetch( {
			path: '/' + config.namespace + path,
			method: 'POST',
			data,
			signal: controller ? controller.signal : undefined,
		} );
	}

	/**
	 * Surface an error where editor errors normally appear.
	 *
	 * @param {string} message Message.
	 */
	function notifyError( message ) {
		dispatch( 'core/notices' ).createErrorNotice( message, {
			id: 'content-designer-error',
			type: 'snackbar',
		} );
	}

	/**
	 * @param {string} message Message.
	 */
	function notify( message ) {
		dispatch( 'core/notices' ).createInfoNotice( message, {
			id: 'content-designer-info',
			type: 'snackbar',
		} );
	}

	/**
	 * True when the run was deliberately stopped rather than broken.
	 *
	 * @param {Error} error Caught error.
	 * @return {boolean}
	 */
	function isAbort( error ) {
		return error && ( error.name === 'AbortError' || state.status === 'cancelled' );
	}

	/**
	 * Scan the document currently on screen, unsaved edits included.
	 */
	async function startRun() {
		controller = new AbortController();

		const editor = select( 'core/editor' );
		const postId = editor.getCurrentPostId();
		const content = editor.getEditedPostContent();

		set(
			Object.assign( {}, initial, {
				status: 'outlining',
				log: __( 'Reading the post…', 'content-designer' ),
				modalOpen: false,
			} )
		);

		try {
			const outline = await post( '/outline', { post_id: postId, content } );

			set( {
				status: 'planning',
				totalWords: outline.total_words,
				postTitle: outline.post.title,
				log: sprintf(
					/* translators: 1: word count, 2: block count */
					__( 'Read %1$d words across %2$d blocks. Deciding where art earns its place…', 'content-designer' ),
					outline.total_words,
					outline.block_count
				),
			} );

			const scan = await post( '/scan', { post_id: postId, content } );

			// Pin anchors to block identities now, while the document still
			// matches what was scanned.
			const plan = window.contentDesigner.insert.resolveAnchors(
				scan.plan.map( function ( placement ) {
					return Object.assign( {}, placement, { approved: true } );
				} )
			);

			set( {
				status: 'reviewing',
				plan,
				log: sprintf(
					/* translators: %d: number of proposed placements */
					__( 'Proposed %d placements.', 'content-designer' ),
					plan.length
				),
			} );
		} catch ( error ) {
			if ( isAbort( error ) ) {
				return;
			}
			set( { status: 'idle', log: '' } );
			notifyError( error.message || __( 'The scan failed.', 'content-designer' ) );
		}
	}

	/**
	 * Generate every approved placement, one image per request.
	 *
	 * Sequential on purpose: it bounds what a cancel wastes, keeps the progress
	 * narrative honest, and avoids stacking multi-second generations on the host.
	 */
	async function generateApproved() {
		const approved = state.plan.filter( function ( placement ) {
			return placement.approved;
		} );

		const total = approved.reduce( function ( sum, placement ) {
			return sum + ( placement.variations || 1 );
		}, 0 );

		controller = new AbortController();
		set( {
			status: 'generating',
			modalOpen: true,
			progress: { done: 0, total },
		} );

		const postId = select( 'core/editor' ).getCurrentPostId();
		let done = 0;

		for ( const placement of approved ) {
			for ( let v = 1; v <= ( placement.variations || 1 ); v++ ) {
				if ( state.status === 'cancelled' ) {
					return;
				}

				set( {
					log: sprintf(
						/* translators: 1: placement role, 2: current image, 3: total images */
						__( 'Generating %1$s (%2$d of %3$d)…', 'content-designer' ),
						placement.role,
						done + 1,
						total
					),
				} );

				try {
					const shot = await post( '/generate', {
						post_id: postId,
						placement,
						variation: v,
					} );

					const results = Object.assign( {}, state.results );
					results[ placement.id ] = ( results[ placement.id ] || [] ).concat( shot );
					done++;
					set( { results, progress: { done, total } } );
				} catch ( error ) {
					if ( isAbort( error ) ) {
						return;
					}
					// One bad image must not cost the approved plan.
					notifyError(
						sprintf(
							/* translators: 1: placement role, 2: error message */
							__( 'Could not generate the %1$s image: %2$s', 'content-designer' ),
							placement.role,
							error.message || ''
						)
					);
				}
			}
		}

		set( {
			status: 'complete',
			log: __( 'Done. Keep the ones that work.', 'content-designer' ),
		} );
	}

	/**
	 * Keep an image: insert it now, while later images are still generating.
	 *
	 * @param {string} placementId Placement id.
	 * @param {Object} shot        {attachment_id, url, full}.
	 */
	function keep( placementId, shot ) {
		const placement = state.plan.find( function ( item ) {
			return item.id === placementId;
		} );

		if ( ! placement ) {
			return;
		}

		const outcome = window.contentDesigner.insert.insertKept( placement, shot );

		if ( outcome.anchorMissing ) {
			notify(
				sprintf(
					/* translators: %s: placement role */
					__( 'The block the %s image was anchored to is gone, so it was added at the end.', 'content-designer' ),
					placement.role
				)
			);
		}

		const chosen = Object.assign( {}, state.chosen );
		chosen[ placementId ] = Number( shot.attachment_id );

		// Every other variation of this placement is now superseded.
		const discard = state.discard.concat(
			( state.results[ placementId ] || [] )
				.map( function ( other ) {
					return Number( other.attachment_id );
				} )
				.filter( function ( id ) {
					return id !== Number( shot.attachment_id );
				} )
		);

		set( { chosen, discard } );
	}

	/**
	 * Reject an image — it is never inserted and gets cleaned up at the end.
	 *
	 * @param {string} placementId Placement id.
	 * @param {Object} shot        {attachment_id}.
	 */
	function reject( placementId, shot ) {
		const id = Number( shot.attachment_id );
		const results = Object.assign( {}, state.results );

		results[ placementId ] = ( results[ placementId ] || [] ).filter( function ( other ) {
			return Number( other.attachment_id ) !== id;
		} );

		set( {
			results,
			discard: state.discard.concat( [ id ] ),
		} );
	}

	/**
	 * Toggle a placement's approval before anything is generated.
	 *
	 * @param {string} placementId Placement id.
	 */
	function toggleApproved( placementId ) {
		set( {
			plan: state.plan.map( function ( item ) {
				return item.id === placementId
					? Object.assign( {}, item, { approved: ! item.approved } )
					: item;
			} ),
		} );
	}

	/**
	 * Edit a scene before it costs pixels.
	 *
	 * @param {string} placementId Placement id.
	 * @param {string} scene       New scene text.
	 */
	function editScene( placementId, scene ) {
		set( {
			plan: state.plan.map( function ( item ) {
				return item.id === placementId ? Object.assign( {}, item, { scene } ) : item;
			} ),
		} );
	}

	/**
	 * Everything generated that is not in the document.
	 *
	 * Explicit rejections are only part of it. Images that were generated but
	 * never reviewed — the normal case when a run is cancelled, or finished
	 * without opening the modal — are just as superseded, and would otherwise
	 * be left orphaned in the media library.
	 *
	 * @return {Array} Attachment ids.
	 */
	function supersededIds() {
		const ids = state.discard.slice();

		Object.keys( state.results ).forEach( function ( placementId ) {
			( state.results[ placementId ] || [] ).forEach( function ( shot ) {
				const id = Number( shot.attachment_id );

				if ( state.chosen[ placementId ] !== id && ids.indexOf( id ) === -1 ) {
					ids.push( id );
				}
			} );
		} );

		return ids;
	}

	/**
	 * Send everything superseded to the server and clear the stored plan.
	 *
	 * @param {boolean} clearPlan Whether to drop the stored plan meta.
	 */
	async function flushDiscard( clearPlan ) {
		const ids = supersededIds();

		if ( ! ids.length && ! clearPlan ) {
			return;
		}

		try {
			// Deliberately not on the run's AbortController — cleanup must
			// still happen when the run itself was cancelled.
			await apiFetch( {
				path: '/' + config.namespace + '/discard',
				method: 'POST',
				data: {
					post_id: select( 'core/editor' ).getCurrentPostId(),
					ids,
					clear_plan: !! clearPlan,
				},
			} );
			set( { discard: [] } );
		} catch ( error ) {
			// Cleanup failing is not worth interrupting the author over; the
			// attachments remain in the library and can be removed by hand.
			window.console.warn( 'Content Designer: cleanup failed', error );
		}
	}

	/**
	 * Stop the run. Already-inserted images stay — they are the author's now.
	 */
	function cancel() {
		set( { status: 'cancelled', log: __( 'Cancelled.', 'content-designer' ) } );

		if ( controller ) {
			controller.abort();
		}

		flushDiscard( true ).then( function () {
			set( { status: 'idle', log: '', plan: [], results: {}, modalOpen: false } );
		} );
	}

	/**
	 * Finish a completed run: tidy up and return to idle.
	 */
	function finish() {
		flushDiscard( true ).then( function () {
			set(
				Object.assign( {}, initial, {
					log: __( 'Images placed. Save the post when you are ready.', 'content-designer' ),
				} )
			);
		} );
	}

	/**
	 * @param {boolean} open Whether the review modal is open.
	 */
	function setModalOpen( open ) {
		set( { modalOpen: !! open } );
	}

	window.contentDesigner.store = {
		getState,
		subscribe,
		startRun,
		generateApproved,
		toggleApproved,
		editScene,
		keep,
		reject,
		cancel,
		finish,
		setModalOpen,
	};
} )( window.wp );
