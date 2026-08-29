<?php
/**
 * Media placer: inserts chosen media into the post at the planned anchors and
 * sets the featured image. Never touches post status.
 *
 * @package Launchy_Illustrator
 */

namespace Launchy_Illustrator;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Final placement step.
 */
class Media_Placer {

	/**
	 * Place selections into a post.
	 *
	 * @param \WP_Post $post       Post.
	 * @param array    $selections [{placement, attachment_id}].
	 * @return array|WP_Error {placed: int, featured: int|null}.
	 */
	public function place( \WP_Post $post, array $selections ) {
		$registry = Plugin::instance()->media_types;
		$featured = null;
		$inserts  = array();

		foreach ( $selections as $selection ) {
			$placement     = $selection['placement'];
			$attachment_id = (int) $selection['attachment_id'];
			$type          = $registry->get( $placement['media_type'] );

			if ( ! $type || ! wp_attachment_is_image( $attachment_id ) ) {
				continue;
			}

			if ( 'hero' === $placement['role'] ) {
				$featured = $attachment_id;
				if ( ! Settings::get( 'hero_in_content' ) ) {
					continue; // Featured image only; theme renders it.
				}
			}

			$inserts[] = array(
				'anchor' => (int) $placement['anchor_index'],
				'pos'    => count( $inserts ), // Original order, to keep same-anchor inserts stable.
				'block'  => $type->build_block( $attachment_id, $placement ),
			);
		}

		$content = $post->post_content;

		if ( $inserts ) {
			$content = has_blocks( $content )
				? $this->insert_into_blocks( $content, $inserts )
				: $this->insert_into_classic( $content, $inserts );
		}

		// wp_update_post() expects slashed data; without wp_slash() the unicode
		// escape sequences serialize_blocks() emits in block attribute JSON
		// (a double hyphen becomes backslash-u002d twice) lose their
		// backslashes on save and existing blocks' attributes break.
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => $content,
					// No post_status: placement never publishes.
				)
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $featured ) {
			set_post_thumbnail( $post->ID, $featured );
		}

		return array(
			'placed'   => count( $inserts ),
			'featured' => $featured,
		);
	}

	/**
	 * Splice serialized image blocks into block content before the anchor
	 * indexes. Inserts run bottom-up so earlier indexes stay valid.
	 *
	 * @param string $content Raw block content.
	 * @param array  $inserts [{anchor, block}].
	 * @return string
	 */
	private function insert_into_blocks( $content, array $inserts ) {
		$blocks = parse_blocks( $content );

		// Bottom-up so earlier indexes stay valid; for equal anchors, splice in
		// reverse selection order so the final document keeps selection order.
		// (Also sidesteps usort() being unstable before PHP 8.0.)
		usort(
			$inserts,
			static function ( $a, $b ) {
				if ( $a['anchor'] === $b['anchor'] ) {
					return $b['pos'] <=> $a['pos'];
				}
				return $b['anchor'] <=> $a['anchor'];
			}
		);

		foreach ( $inserts as $insert ) {
			$parsed = parse_blocks( $insert['block'] );
			$image  = null;
			foreach ( $parsed as $candidate ) {
				if ( null !== $candidate['blockName'] ) {
					$image = $candidate;
					break;
				}
			}
			if ( ! $image ) {
				continue;
			}

			$at = max( 0, min( count( $blocks ), $insert['anchor'] ) );
			array_splice( $blocks, $at, 0, array( $image ) );
		}

		return serialize_blocks( $blocks );
	}

	/**
	 * Classic content fallback: anchors are paragraph indexes (matching
	 * Draft_Scanner::scan_classic), images inserted as plain figures.
	 *
	 * @param string $content Raw content.
	 * @param array  $inserts [{anchor, block}].
	 * @return string
	 */
	private function insert_into_classic( $content, array $inserts ) {
		$paragraphs = preg_split( '/\n\s*\n/', $content );

		// Bottom-up so earlier indexes stay valid; for equal anchors, splice in
		// reverse selection order so the final document keeps selection order.
		// (Also sidesteps usort() being unstable before PHP 8.0.)
		usort(
			$inserts,
			static function ( $a, $b ) {
				if ( $a['anchor'] === $b['anchor'] ) {
					return $b['pos'] <=> $a['pos'];
				}
				return $b['anchor'] <=> $a['anchor'];
			}
		);

		foreach ( $inserts as $insert ) {
			// Strip block comments for classic content; keep the figure HTML.
			$html = trim( preg_replace( '/<!--.*?-->/s', '', $insert['block'] ) );
			$at   = max( 0, min( count( $paragraphs ), $insert['anchor'] ) );
			array_splice( $paragraphs, $at, 0, array( $html ) );
		}

		return implode( "\n\n", $paragraphs );
	}
}
