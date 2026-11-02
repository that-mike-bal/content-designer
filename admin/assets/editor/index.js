/**
 * Registers the Illustrate sidebar in the post editor.
 *
 * PluginSidebar comes from @wordpress/editor (wp.editor). The wp.editPost
 * exports of these slots were deprecated in WordPress 6.6 and must not be used.
 */
( function ( wp ) {
	'use strict';

	const { registerPlugin } = wp.plugins;
	const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editor;
	const { createElement: h, Fragment } = wp.element;
	const { PanelBody, Button, Notice, ExternalLink } = wp.components;
	const { __ } = wp.i18n;

	const store = window.contentDesigner.store;
	const useRunState = window.contentDesigner.useRunState;
	const PlacementCards = window.contentDesigner.PlacementCards;
	const ReviewModal = window.contentDesigner.ReviewModal;

	const config = window.contentDesigner || {};

	const NAME = 'content-designer';
	const TITLE = __( 'Illustrate', 'content-designer' );

	/**
	 * Which control the run offers right now.
	 *
	 * @param {Object} props Component props.
	 * @return {Object} Element.
	 */
	function Controls( props ) {
		const { run } = props;
		const busy = run.status === 'outlining' || run.status === 'planning';

		if ( busy ) {
			return h(
				Button,
				{ variant: 'secondary', onClick: store.cancel },
				__( 'Cancel', 'content-designer' )
			);
		}

		if ( run.status === 'reviewing' ) {
			const count = run.plan.filter( function ( placement ) {
				return placement.approved;
			} ).length;

			return h(
				Fragment,
				null,
				h(
					Button,
					{
						variant: 'primary',
						disabled: ! count,
						onClick: store.generateApproved,
					},
					count === 1
						? __( 'Generate 1 image', 'content-designer' )
						: // translators: %d: number of images to generate.
						  wp.i18n.sprintf( __( 'Generate %d images', 'content-designer' ), count )
				),
				h( Button, { variant: 'tertiary', onClick: store.cancel }, __( 'Discard plan', 'content-designer' ) )
			);
		}

		if ( run.status === 'generating' || run.status === 'complete' ) {
			return h(
				Fragment,
				null,
				h(
					Button,
					{
						variant: 'primary',
						onClick: function () {
							store.setModalOpen( true );
						},
					},
					__( 'Review images', 'content-designer' )
				),
				run.status === 'complete'
					? h( Button, { variant: 'tertiary', onClick: store.finish }, __( 'Finish', 'content-designer' ) )
					: h( Button, { variant: 'tertiary', isDestructive: true, onClick: store.cancel }, __( 'Cancel run', 'content-designer' ) )
			);
		}

		return h(
			Button,
			{
				variant: 'primary',
				disabled: ! config.hasApiKey,
				onClick: store.startRun,
			},
			__( 'Scan this post', 'content-designer' )
		);
	}

	/**
	 * The sidebar body.
	 *
	 * @return {Object} Element.
	 */
	function Panel() {
		const run = useRunState();

		return h(
			Fragment,
			null,
			h(
				PanelBody,
				{ className: 'content-designer-panel' },
				! config.hasApiKey
					? h(
							Notice,
							{ status: 'warning', isDismissible: false },
							__( 'No image API key is configured yet, so nothing can be generated.', 'content-designer' ),
							' ',
							h( ExternalLink, { href: config.settingsUrl }, __( 'Add one in settings', 'content-designer' ) )
					  )
					: null,
				h(
					'p',
					{ className: 'content-designer-panel__style' },
					__( 'Style:', 'content-designer' ),
					' ',
					h( 'strong', null, config.styleLabel ),
					' · ',
					h( ExternalLink, { href: config.settingsUrl }, __( 'Change', 'content-designer' ) )
				),
				h(
					'p',
					{
						className: 'content-designer-panel__log',
						'aria-live': 'polite',
						role: 'status',
					},
					run.log
				),
				h( 'div', { className: 'content-designer-panel__actions' }, h( Controls, { run } ) )
			),
			h( PlacementCards, { run } ),
			h( ReviewModal, { run } )
		);
	}

	registerPlugin( NAME, {
		render: function () {
			return h(
				Fragment,
				null,
				h( PluginSidebarMoreMenuItem, { target: NAME, icon: 'format-image' }, TITLE ),
				h(
					PluginSidebar,
					{ name: NAME, title: TITLE, icon: 'format-image' },
					h( Panel, null )
				)
			);
		},
	} );
} )( window.wp );
