/**
 * The variation review modal.
 *
 * The sidebar is about 280px wide, which is enough to run a job and watch it,
 * and not enough to compare two hero variations. Core's Modal brings the focus
 * trap, escape handling and labelling with it.
 *
 * The modal is a view onto the run, not a separate flow: the run keeps going
 * whether it is open or closed, and closing it cancels nothing.
 */
( function ( wp ) {
	'use strict';

	window.launchy = window.launchy || {};

	const { createElement: h, Fragment } = wp.element;
	const { Modal, Button, Spinner } = wp.components;
	const { __, sprintf } = wp.i18n;

	const store = window.launchy.store;

	/**
	 * Aspect-ratio CSS for a placeholder, so the layout does not jump when the
	 * real image lands.
	 *
	 * @param {string} ratio Aspect ratio like "16:9".
	 * @return {Object} Style object.
	 */
	function ratioStyle( ratio ) {
		const parts = String( ratio || '4:3' ).split( ':' );
		const w = parseFloat( parts[ 0 ] ) || 4;
		const hh = parseFloat( parts[ 1 ] ) || 3;
		return { aspectRatio: w + ' / ' + hh };
	}

	/**
	 * One generated image with its keep/reject controls.
	 *
	 * @param {Object} props Component props.
	 * @return {Object} Element.
	 */
	function Shot( props ) {
		const { placement, shot, chosen } = props;
		const isChosen = chosen === Number( shot.attachment_id );

		return h(
			'div',
			{ className: 'launchy-shot' + ( isChosen ? ' is-chosen' : '' ) },
			h( 'img', {
				src: shot.url,
				alt: placement.alt_text || '',
				style: ratioStyle( placement.aspect_ratio ),
			} ),
			h(
				'div',
				{ className: 'launchy-shot__actions' },
				h(
					Button,
					{
						variant: isChosen ? 'primary' : 'secondary',
						disabled: isChosen,
						onClick: function () {
							store.keep( placement.id, shot );
						},
					},
					isChosen ? __( 'Placed', 'launchy-illustrator' ) : __( 'Keep', 'launchy-illustrator' )
				),
				! isChosen
					? h(
							Button,
							{
								variant: 'tertiary',
								isDestructive: true,
								onClick: function () {
									store.reject( placement.id, shot );
								},
							},
							__( 'Reject', 'launchy-illustrator' )
					  )
					: null
			)
		);
	}

	/**
	 * One placement's row: its direction and every variation so far.
	 *
	 * @param {Object} props Component props.
	 * @return {Object} Element.
	 */
	function Row( props ) {
		const { placement, run } = props;
		const shots = run.results[ placement.id ] || [];
		const pending = run.status === 'generating' && ! shots.length;

		return h(
			'section',
			{ className: 'launchy-row' },
			h(
				'header',
				{ className: 'launchy-row__head' },
				h( 'h3', null, placement.role ),
				h( 'span', { className: 'launchy-row__anchor' }, placement.anchor_label )
			),
			h( 'p', { className: 'launchy-row__scene' }, placement.scene ),
			h(
				'div',
				{ className: 'launchy-row__shots' },
				shots.map( function ( shot ) {
					return h( Shot, {
						key: shot.attachment_id,
						placement,
						shot,
						chosen: run.chosen[ placement.id ],
					} );
				} ),
				// A placeholder at the planned crop: this represents work
				// genuinely in flight, not a staged animation.
				pending
					? h(
							'div',
							{
								className: 'launchy-shot is-pending',
								style: ratioStyle( placement.aspect_ratio ),
							},
							h( Spinner, null )
					  )
					: null,
				! shots.length && ! pending
					? h(
							'p',
							{ className: 'launchy-row__empty' },
							__( 'Nothing kept for this placement.', 'launchy-illustrator' )
					  )
					: null
			)
		);
	}

	/**
	 * The modal itself.
	 *
	 * @param {Object} props Component props.
	 * @return {Object} Element.
	 */
	function ReviewModal( props ) {
		const { run } = props;

		if ( ! run.modalOpen ) {
			return null;
		}

		const approved = run.plan.filter( function ( placement ) {
			return placement.approved;
		} );

		return h(
			Modal,
			{
				title: __( 'Review the images', 'launchy-illustrator' ),
				className: 'launchy-modal',
				onRequestClose: function () {
					store.setModalOpen( false );
				},
			},
			h(
				'p',
				{ className: 'launchy-modal__progress', 'aria-live': 'polite' },
				run.status === 'generating'
					? sprintf(
							/* translators: 1: images finished, 2: images total */
							__( '%1$d of %2$d generated. Keep them as they arrive — the rest are still rendering.', 'launchy-illustrator' ),
							run.progress.done,
							run.progress.total
					  )
					: run.log
			),
			approved.map( function ( placement ) {
				return h( Row, { key: placement.id, placement, run } );
			} ),
			h(
				'div',
				{ className: 'launchy-modal__actions' },
				run.status === 'generating'
					? h(
							Button,
							{
								variant: 'tertiary',
								isDestructive: true,
								onClick: store.cancel,
							},
							__( 'Cancel run', 'launchy-illustrator' )
					  )
					: null,
				h(
					Button,
					{
						variant: 'primary',
						onClick: function () {
							store.setModalOpen( false );
							if ( run.status === 'complete' ) {
								store.finish();
							}
						},
					},
					run.status === 'complete'
						? __( 'Done', 'launchy-illustrator' )
						: __( 'Close', 'launchy-illustrator' )
				)
			)
		);
	}

	window.launchy.ReviewModal = ReviewModal;
} )( window.wp );
