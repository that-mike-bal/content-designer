<?php
/**
 * Draft scanner: turns post content into a structural outline the planner
 * (and the placement step) can reason about.
 *
 * Anchors are indexes into the post's top-level block array, so the same
 * outline drives both the editorial pass and the final insertion.
 *
 * @package Launchy_Illustrator
 */

namespace Launchy_Illustrator;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the section model of a post.
 */
class Draft_Scanner {

	/**
	 * Block types that already give the eye a rest (no art needed next to them).
	 *
	 * @var array
	 */
	const VISUAL_BREAKS = array(
		'core/image',
		'core/gallery',
		'core/video',
		'core/embed',
		'core/code',
		'core/pullquote',
		'core/table',
		'core/separator',
		'core/media-text',
		'core/cover',
	);

	/**
	 * Scan a post into an outline.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array {is_blocks, total_words, outline: [{index, ordinal, type, heading, level, words, is_visual_break, excerpt}]}
	 */
	public function scan( \WP_Post $post ) {
		return has_blocks( $post->post_content )
			? $this->scan_blocks( $post->post_content )
			: $this->scan_classic( $post->post_content );
	}

	/**
	 * Block-editor content.
	 *
	 * @param string $content Raw post content.
	 * @return array
	 */
	private function scan_blocks( $content ) {
		$blocks  = parse_blocks( $content );
		$outline = array();
		$total   = 0;

		foreach ( $blocks as $i => $block ) {
			// Skip whitespace-only freeform fillers but keep real classic chunks.
			if ( null === $block['blockName'] && '' === trim( $block['innerHTML'] ) ) {
				continue;
			}

			// Static text extraction — deliberately NOT render_block(), which
			// would execute dynamic blocks' render callbacks (side effects,
			// notices, slow scans) outside a proper front-end context.
			$text  = wp_strip_all_tags( self::block_text( $block ) );
			$words = str_word_count( $text );
			$entry = array(
				'index'           => $i,
				'ordinal'         => count( $outline ),
				'type'            => $block['blockName'] ?: 'classic',
				'heading'         => '',
				'level'           => 0,
				'words'           => $words,
				'is_visual_break' => in_array( $block['blockName'], self::VISUAL_BREAKS, true ),
				'excerpt'         => mb_substr( trim( $text ), 0, 160 ),
			);

			if ( 'core/heading' === $block['blockName'] ) {
				$entry['heading'] = trim( $text );
				$entry['level']   = isset( $block['attrs']['level'] ) ? (int) $block['attrs']['level'] : 2;
			}

			$total    += $words;
			$outline[] = $entry;
		}

		return array(
			'is_blocks'   => true,
			'total_words' => $total,
			'outline'     => $outline,
		);
	}

	/**
	 * Classic (non-block) content: treat double-newline paragraphs as
	 * pseudo-blocks so the same pipeline works.
	 *
	 * @param string $content Raw post content.
	 * @return array
	 */
	private function scan_classic( $content ) {
		$paragraphs = preg_split( '/\n\s*\n/', $content );
		$outline    = array();
		$total      = 0;

		foreach ( (array) $paragraphs as $i => $para ) {
			$text = wp_strip_all_tags( $para );
			if ( '' === trim( $text ) ) {
				continue;
			}
			$words   = str_word_count( $text );
			$total  += $words;
			$heading = preg_match( '/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $para, $m ) ? wp_strip_all_tags( $m[2] ) : '';

			$outline[] = array(
				'index'           => $i,
				'ordinal'         => count( $outline ),
				'type'            => $heading ? 'heading' : 'paragraph',
				'heading'         => $heading,
				'level'           => $heading ? (int) $m[1] : 0,
				'words'           => $words,
				'is_visual_break' => (bool) preg_match( '/<(img|video|iframe|pre|table)\b/i', $para ),
				'excerpt'         => mb_substr( trim( $text ), 0, 160 ),
			);
		}

		return array(
			'is_blocks'   => false,
			'total_words' => $total,
			'outline'     => $outline,
		);
	}

	/**
	 * Static text of a parsed block: its own markup plus nested inner blocks.
	 *
	 * @param array $block Parsed block.
	 * @return string
	 */
	private static function block_text( array $block ) {
		$text = (string) $block['innerHTML'];
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $inner ) {
			$text .= ' ' . self::block_text( $inner );
		}
		return $text;
	}

	/**
	 * Client-resolvable position for an anchor.
	 *
	 * `index` is a raw parse_blocks() offset, and the parser emits a null-name
	 * freeform block for the whitespace between every pair of real blocks. The
	 * block editor never shows those, so its top-level block array is both
	 * shorter and differently indexed. `ordinal` is the position among the
	 * entries this scan kept — the same set the editor exposes — so it is the
	 * anchor the editor can actually resolve.
	 *
	 * The planner's anchor can land on one of those skipped whitespace blocks,
	 * so resolve to the first kept entry at or after it. That preserves the
	 * documented "insert BEFORE this item" meaning.
	 *
	 * @param array $scan         Result of scan().
	 * @param int   $anchor_index Raw anchor index.
	 * @return int Ordinal, or the kept-entry count to mean "append at the end".
	 */
	public static function anchor_ordinal( array $scan, $anchor_index ) {
		foreach ( $scan['outline'] as $entry ) {
			if ( (int) $entry['index'] >= (int) $anchor_index ) {
				return (int) $entry['ordinal'];
			}
		}

		return count( $scan['outline'] );
	}

	/**
	 * Human label for where an image will land.
	 *
	 * Describes the block the image is inserted BEFORE — resolved with the same
	 * first-kept-entry-at-or-after rule as anchor_ordinal(), so the label always
	 * agrees with the actual insertion point.
	 *
	 * Deliberately names text rather than block numbers. The only index the
	 * planner produces is a raw parse_blocks() offset, which counts whitespace
	 * blocks nobody can see; showing it in the editor, where the author can
	 * count blocks themselves, invites them to trust a number that will not
	 * match. A heading or an opening phrase is both truthful and easier to place.
	 *
	 * @param array $scan         Result of scan().
	 * @param int   $anchor_index Anchor.
	 * @return string
	 */
	public static function anchor_label( array $scan, $anchor_index ) {
		$anchor_index = (int) $anchor_index;
		$target       = null;
		$section      = '';

		foreach ( $scan['outline'] as $entry ) {
			if ( (int) $entry['index'] >= $anchor_index ) {
				$target = $entry;
				break;
			}

			// Headings passed on the way are the section the anchor sits in.
			if ( '' !== $entry['heading'] ) {
				$section = $entry['heading'];
			}
		}

		if ( null === $target ) {
			return __( 'At the end of the post', 'launchy-illustrator' );
		}

		if ( 0 === (int) $target['ordinal'] ) {
			return __( 'Top of post', 'launchy-illustrator' );
		}

		if ( '' !== $target['heading'] ) {
			/* translators: %s: heading text */
			return sprintf( __( 'Before “%s”', 'launchy-illustrator' ), $target['heading'] );
		}

		if ( '' !== $section ) {
			/* translators: %s: section heading */
			return sprintf( __( 'In “%s”', 'launchy-illustrator' ), $section );
		}

		$excerpt = trim( (string) $target['excerpt'] );

		if ( '' !== $excerpt ) {
			/* translators: %s: opening words of the block the image sits above */
			return sprintf( __( 'Before “%s”', 'launchy-illustrator' ), wp_trim_words( $excerpt, 6, '…' ) );
		}

		// Textless blocks — an image, a separator, a spacer — have nothing to
		// quote. Name the block instead of rendering an empty pair of quotes.
		$type = trim( str_replace( array( 'core/', '-' ), array( '', ' ' ), (string) $target['type'] ) );

		if ( '' === $type || 'classic' === $type ) {
			return __( 'Near the top of the post', 'launchy-illustrator' );
		}

		/* translators: %s: block type, e.g. "image" or "separator" */
		return sprintf( __( 'Before the %s', 'launchy-illustrator' ), $type );
	}
}
