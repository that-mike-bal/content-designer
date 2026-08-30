/**
 * The placement list in the sidebar.
 *
 * One card per proposed placement, showing where it lands, why, and what has
 * happened to it so far. Before generation the scene is editable — changing
 * direction is cheap on paper and expensive as pixels.
 */
( function ( wp ) {
	'use strict';

	window.launchy = window.launchy || {};

	const { createElement: h, Fragment, useState, useEffect } = wp.element;
	const { CheckboxControl, TextareaControl, Spinner } = wp.components;
	const { __ } = wp.i18n;

	const store = window.launchy.store;

	/**
	 * Subscribe a component to run state.
	 *
	 * @return {Object} Current run state.
	 */
	function useRunState() {
		const [ , force ] = useState( 0 );

		useEffect( function () {
			return store.subscribe( function () {
				force( function ( n ) {
					return n + 1;
				} );
			} );
		}, [] );

		return store.getState();
	}

	/**
	 * What has happened to one placement.
	 *
	 * @param {Object} props Component props.
	 * @return {Object} Element.
	 */
	function Status( props ) {
		const { placement, run } = props;
		const shots = run.results[ placement.id ] || [];
		const isChosen = !! run.chosen[ placement.id ];

		if ( isChosen ) {
			return h( 'span', { className: 'launchy-card__status is-placed' }, __( 'Placed', 'launchy-illustrator' ) );
		}

		if ( shots.length ) {
			return h(
				'span',
				{ className: 'launchy-card__status is-ready' },
				__( 'Ready to review', 'launchy-illustrator' )
			);
		}

		if ( run.status === 'generating' && placement.approved ) {
			return h(
				'span',
				{ className: 'launchy-card__status is-working' },
				h( Spinner, null ),
				__( 'Generating…', 'launchy-illustrator' )
			);
		}

		if ( ! placement.approved ) {
			return h( 'span', { className: 'launchy-card__status' }, __( 'Skipped', 'launchy-illustrator' ) );
		}

		return null;
	}

	/**
	 * One placement card.
	 *
	 * @param {Object} props Component props.
	 * @return {Object} Element.
	 */
	function Card( props ) {
		const { placement, run } = props;
		const editable = run.status === 'reviewing';

		return h(
			'li',
			{
				className:
					'launchy-card' + ( placement.approved ? '' : ' is-skipped' ),
			},
			h(
				'div',
				{ className: 'launchy-card__head' },
				h( 'span', { className: 'launchy-card__role' }, placement.role ),
				h( 'span', { className: 'launchy-card__ratio' }, placement.aspect_ratio ),
				h( Status, { placement, run } )
			),
			h( 'p', { className: 'launchy-card__anchor' }, placement.anchor_label ),
			editable
				? h( CheckboxControl, {
						__nextHasNoMarginBottom: true,
						label: __( 'Include this image', 'launchy-illustrator' ),
						checked: !! placement.approved,
						onChange: function () {
							store.toggleApproved( placement.id );
						},
				  } )
				: null,
			editable && placement.approved
				? h( TextareaControl, {
						__nextHasNoMarginBottom: true,
						label: __( 'Scene', 'launchy-illustrator' ),
						help: placement.rationale,
						rows: 4,
						value: placement.scene,
						onChange: function ( value ) {
							store.editScene( placement.id, value );
						},
				  } )
				: null,
			! editable
				? h( 'p', { className: 'launchy-card__scene' }, placement.scene )
				: null
		);
	}

	/**
	 * The whole list.
	 *
	 * @param {Object} props Component props.
	 * @return {Object} Element.
	 */
	function PlacementCards( props ) {
		const { run } = props;

		if ( ! run.plan.length ) {
			return null;
		}

		return h(
			Fragment,
			null,
			h(
				'ul',
				{ className: 'launchy-cards' },
				run.plan.map( function ( placement ) {
					return h( Card, { key: placement.id, placement, run } );
				} )
			)
		);
	}

	window.launchy.useRunState = useRunState;
	window.launchy.PlacementCards = PlacementCards;
} )( window.wp );
