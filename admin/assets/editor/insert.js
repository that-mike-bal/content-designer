/**
 * Anchor resolution and block insertion for the editor panel.
 *
 * The subtle part is anchors. A placement's `anchor_index` is a raw
 * parse_blocks() offset, and that array contains a null-name freeform block for
 * the whitespace between every pair of real blocks — entries the editor never
 * shows. `anchor_ordinal` is the position among the blocks the scan kept, which
 * is the set getBlocks() returns, so that is what resolves here.
 *
 * Ordinals are resolved to clientIds once, immediately after the scan. A run
 * takes minutes and the author keeps writing during it; ordinals shift when
 * blocks are added or removed, clientIds do not. Position is then resolved
 * late, at the moment of insertion.
 */
( function ( wp ) {
	'use strict';

	window.launchy = window.launchy || {};

	const { select, dispatch } = wp.data;
	const { createBlock } = wp.blocks;

	/**
	 * Alignment per role, mirroring Media_Type::get_roles() so both surfaces
	 * produce equivalent blocks for the same placement.
	 */
	const ROLE_ALIGN = {
		hero: 'full',
		banner: 'full',
		concept: '',
		spot: '',
	};

	/**
	 * Pin each placement to a block identity that survives editing.
	 *
	 * @param {Array} plan Placements from /scan.
	 * @return {Array} The same placements, each with an anchorClientId.
	 */
	function resolveAnchors( plan ) {
		const blocks = select( 'core/block-editor' ).getBlocks();

		return plan.map( function ( placement ) {
			const ordinal = Number( placement.anchor_ordinal );
			const target = blocks[ ordinal ];

			return Object.assign( {}, placement, {
				// null means "past the end of the document" — append on insert.
				anchorClientId: target ? target.clientId : null,
			} );
		} );
	}

	/**
	 * Where this placement's image should go right now.
	 *
	 * @param {Object} placement Placement with an anchorClientId.
	 * @return {Object} {index, missing} — index undefined means append.
	 */
	function currentPosition( placement ) {
		if ( ! placement.anchorClientId ) {
			return { index: undefined, missing: false };
		}

		const index = select( 'core/block-editor' ).getBlockIndex(
			placement.anchorClientId
		);

		// -1: the anchor block was deleted while the run was in flight. Append
		// rather than guess at where it used to be, and say so.
		if ( index === -1 ) {
			return { index: undefined, missing: true };
		}

		return { index, missing: false };
	}

	/**
	 * Insert a kept image, and set the featured image for a hero.
	 *
	 * @param {Object} placement    Placement with an anchorClientId.
	 * @param {Object} attachment   {attachment_id, full}.
	 * @return {Object} {inserted, featured, anchorMissing}.
	 */
	function insertKept( placement, attachment ) {
		const id = Number( attachment.attachment_id );
		const config = window.launchyIllustrator || {};
		const isHero = placement.role === 'hero';
		let featured = false;

		if ( isHero ) {
			// Always the featured image, whether or not it also goes inline.
			dispatch( 'core/editor' ).editPost( { featured_media: id } );
			featured = true;

			if ( ! config.heroInContent ) {
				return { inserted: false, featured, anchorMissing: false };
			}
		}

		const align = ROLE_ALIGN[ placement.role ] || '';
		const attrs = {
			id,
			sizeSlug: 'large',
			linkDestination: 'none',
			url: attachment.full || attachment.url,
			alt: placement.alt_text || '',
		};

		if ( align ) {
			attrs.align = align;
		}

		const position = currentPosition( placement );

		// One insertBlocks call per image, so each is its own undo step rather
		// than the whole run collapsing into one.
		dispatch( 'core/block-editor' ).insertBlocks(
			createBlock( 'core/image', attrs ),
			position.index,
			undefined,
			false
		);

		return {
			inserted: true,
			featured,
			anchorMissing: position.missing,
		};
	}

	window.launchy.insert = {
		resolveAnchors,
		insertKept,
		currentPosition,
	};
} )( window.wp );
