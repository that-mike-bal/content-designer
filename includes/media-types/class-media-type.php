<?php
/**
 * Abstract media type — the extension point for new kinds of generated media.
 *
 * A media type owns: which roles it can fill (hero, banner, concept, spot),
 * how to assemble a generation prompt, which reference assets to pass, how to
 * generate, and how to render itself as block markup once placed.
 *
 * To add a new kind of media (diagrams, charts, video...), extend this class
 * and register it on the `content_designer_register_media_types` action.
 *
 * @package Content_Designer
 */

namespace Content_Designer\Media_Types;

use Content_Designer\AI\AI_Client;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for all generated media types.
 */
abstract class Media_Type {

	/**
	 * Unique slug, e.g. 'content-designer-illustration'.
	 *
	 * @return string
	 */
	abstract public function get_slug();

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	abstract public function get_label();

	/**
	 * Build the full generation prompt for one planned placement.
	 *
	 * @param array $placement {role, aspect_ratio, scene, ...}.
	 * @return string
	 */
	abstract public function build_prompt( array $placement );

	/**
	 * Roles this type can fill, with per-role defaults.
	 *
	 * @return array role => {ratio, resolution, align, variations}.
	 */
	public function get_roles() {
		return array(
			'hero'    => array(
				'ratio'      => '16:9',
				'resolution' => '2K',
				'align'      => 'full',
				'variations' => 2,
			),
			'banner'  => array(
				'ratio'      => '21:9',
				'resolution' => '2K',
				'align'      => 'full',
				'variations' => 1,
			),
			'concept' => array(
				'ratio'      => '4:3',
				'resolution' => '2K',
				'align'      => '',
				'variations' => 1,
			),
			'spot'    => array(
				'ratio'      => '1:1',
				'resolution' => '1K',
				'align'      => '',
				'variations' => 1,
			),
		);
	}

	/**
	 * Reference assets (URLs or attachment IDs) passed to the generator for
	 * visual consistency. Default: none.
	 *
	 * @return array
	 */
	public function get_references() {
		return array();
	}

	/**
	 * Editorial guidance injected into the placement-planner prompt so the
	 * planner knows what scenes suit this media type.
	 *
	 * @return string
	 */
	public function get_planner_guidance() {
		return '';
	}

	/**
	 * Generate one variation for a placement. Default implementation generates
	 * a still image via the AI client; override for video/audio/etc.
	 *
	 * @param array $placement Planned placement.
	 * @return array|WP_Error {bytes, mime}.
	 */
	public function generate( array $placement ) {
		$roles = $this->get_roles();
		$role  = $roles[ $placement['role'] ] ?? reset( $roles );

		return AI_Client::generate_image(
			$this->build_prompt( $placement ),
			array(
				'aspect_ratio' => $placement['aspect_ratio'] ?? $role['ratio'],
				'resolution'   => $placement['resolution'] ?? $role['resolution'],
				'references'   => $this->get_references(),
			)
		);
	}

	/**
	 * Build serialized block markup for a chosen attachment.
	 *
	 * @param int   $attachment_id Attachment.
	 * @param array $placement     Planned placement.
	 * @return string Serialized block.
	 */
	public function build_block( $attachment_id, array $placement ) {
		$roles = $this->get_roles();
		$align = $roles[ $placement['role'] ]['align'] ?? '';
		$url   = wp_get_attachment_image_url( $attachment_id, 'full' );
		$alt   = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		$attrs = array(
			'id'              => (int) $attachment_id,
			'sizeSlug'        => 'large',
			'linkDestination' => 'none',
		);
		if ( $align ) {
			$attrs['align'] = $align;
		}

		$figure_class = 'wp-block-image ' . ( $align ? 'align' . $align . ' ' : '' ) . 'size-large';
		$html         = sprintf(
			'<figure class="%s"><img src="%s" alt="%s" class="wp-image-%d"/></figure>',
			esc_attr( $figure_class ),
			esc_url( $url ),
			esc_attr( $alt ),
			(int) $attachment_id
		);

		$block = sprintf( "<!-- wp:image %s -->\n%s\n<!-- /wp:image -->", wp_json_encode( $attrs ), $html );

		/**
		 * Filter the block markup produced for a placed media item.
		 *
		 * @param string     $block         Serialized block.
		 * @param int        $attachment_id Attachment ID.
		 * @param array      $placement     Placement plan item.
		 * @param Media_Type $type          The media type instance.
		 */
		return apply_filters( 'content_designer_block_markup', $block, $attachment_id, $placement, $this );
	}
}
