<?php
/**
 * EXAMPLE — adding a new media type.
 *
 * Drop this into a small companion plugin (or your theme's functions.php) to
 * teach Content Designer a brand-new kind of supporting media. The planner,
 * generator UI, REST endpoints, and abilities all pick it up automatically —
 * you only describe the type.
 *
 * This stub shows a "concept diagram" type that reuses the image pipeline with
 * a different art direction and no character references. Swap build_prompt()
 * and generate() for charts, infographics, or even video by overriding the
 * generate() method to call a different model.
 *
 * @package Content_Designer
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'content_designer_register_media_types',
	static function ( $registry ) {
		if ( ! class_exists( '\Content_Designer\Media_Types\Media_Type' ) ) {
			return;
		}

		/**
		 * Minimal concept-diagram media type.
		 */
		$type = new class() extends \Content_Designer\Media_Types\Media_Type {

			public function get_slug() {
				return 'concept-diagram';
			}

			public function get_label() {
				return __( 'Concept diagram', 'content-designer' );
			}

			public function get_planner_guidance() {
				return 'A clean, minimal explanatory diagram (boxes, arrows, a simple flow) in the '
					. 'brand green/ink/paper palette — NO mascot. Use for sections that explain a '
					. 'system, pipeline, or relationship that a labeled diagram clarifies better than '
					. 'a cartoon. Describe the nodes and the flow between them.';
			}

			public function get_roles() {
				// Diagrams want wide, roomy crops.
				return array(
					'concept' => array(
						'ratio'      => '16:9',
						'resolution' => '2K',
						'align'      => 'full',
						'variations' => 1,
					),
				);
			}

			public function build_prompt( array $placement ) {
				return "Minimal flat vector concept diagram, 1930s print aesthetic, "
					. "emerald green (#5AA96B) / near-black ink (#14161B) / warm paper white palette, "
					. "clean labeled boxes and arrows, generous negative space, no mascot, no photoreal shading.\n\n"
					. "## The diagram\n" . $placement['scene'] . "\n\n"
					. "## Format\nCompose for a {$placement['aspect_ratio']} crop. {$placement['composition']}";
			}
		};

		$registry->register( $type );
	}
);
