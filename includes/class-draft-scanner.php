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
	 * @return array {is_blocks, total_words, outline: [{index, type, heading, level, words, is_visual_break, excerpt}]}
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
	 * Human label for an anchor index ("Before §‘Heading’" / "block 12").
	 *
	 * @param array $scan         Result of scan().
	 * @param int   $anchor_index Anchor.
	 * @return string
	 */
	public static function anchor_label( array $scan, $anchor_index ) {
		$section = '';
		foreach ( $scan['outline'] as $entry ) {
			if ( $entry['index'] > $anchor_index ) {
				break;
			}
			if ( $entry['heading'] ) {
				$section = $entry['heading'];
			}
			if ( $entry['index'] === $anchor_index && $entry['heading'] ) {
				/* translators: %s: heading text */
				return sprintf( __( 'Before “%s”', 'launchy-illustrator' ), $entry['heading'] );
			}
		}

		if ( 0 === (int) $anchor_index ) {
			return __( 'Top of post', 'launchy-illustrator' );
		}

		return $section
			/* translators: 1: section heading, 2: block index */
			? sprintf( __( 'In “%1$s” (block %2$d)', 'launchy-illustrator' ), $section, $anchor_index )
			/* translators: %d: block index */
			: sprintf( __( 'Before block %d', 'launchy-illustrator' ), $anchor_index );
	}
}
