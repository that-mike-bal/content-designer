<?php
/**
 * Built-in style presets — NotebookLM-style "pick a look" starting points.
 *
 * A preset bundles a master style prompt, planner guidance, and (optionally)
 * reference images. The user picks one in Settings → Launchy Illustrator; the
 * "Master style prompt" field overrides the preset when filled in. Third-party
 * plugins add their own via the `launchy_illustrator_style_presets` filter
 * (see examples/launchy-style-preset.php).
 *
 * @package Launchy_Illustrator
 */

namespace Launchy_Illustrator;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of selectable illustration styles.
 */
class Style_Presets {

	const DEFAULT_PRESET = 'editorial-minimal';

	/**
	 * All registered presets.
	 *
	 * @return array id => {label, description, prompt, guidance, references}.
	 */
	public static function all() {
		$presets = array(

			'editorial-minimal'    => array(
				'label'       => __( 'Clean editorial', 'launchy-illustrator' ),
				'description' => __( 'Flat, modern editorial illustration. Neutral and versatile.', 'launchy-illustrator' ),
				'prompt'      => <<<'PROMPT'
# Role
You are an accomplished editorial illustrator with a consistent, recognizable style.

## Style
- Clean, flat, modern editorial illustration: bold simple shapes, a limited and
  harmonious palette, and one clear focal subject per image.
- Backgrounds stay simpler than the foreground so the subject always reads first.
- The image should feel hand-crafted — never photorealistic, never glossy 3D,
  never obviously AI-generated.

## Avoid
- Text baked into the image (unless a single bold word IS the point).
- Watermarks, borders, frames, or visible color swatches.
- Busy backgrounds that compete with the subject.
PROMPT
				,
				'guidance'    => 'A conceptual editorial illustration that acts out the section\'s core idea as a concrete visual metaphor. Describe the subject, the action, the emotion, any supporting elements, and the metaphor. Specificity beats adjectives; match the mood to the section\'s tone.',
			),

			'claymation'           => array(
				'label'       => __( 'Claymation', 'launchy-illustrator' ),
				'description' => __( 'Hand-sculpted stop-motion look: plasticine characters on miniature sets.', 'launchy-illustrator' ),
				'prompt'      => <<<'PROMPT'
# Role
You are a stop-motion studio's lead sculptor and set builder, photographing hand-made scenes.

## Style
- Everything looks physically sculpted from plasticine clay: softly rounded forms,
  gentle surface irregularities, the occasional visible thumbprint or tool mark.
- Sets are miniature dioramas built from clay, felt, cardboard, and wire — props
  included — photographed with warm, soft studio lighting and a shallow depth of
  field that keeps the subject crisp and melts the set behind it.
- A cohesive, slightly desaturated palette like real modeling clay; matte
  surfaces, never glossy.
- Charming, tactile, a little imperfect — that hand-made warmth is the style.

## Avoid
- Glossy CGI, smooth 3D renders, or plastic toy sheen — it must read as clay.
- Photorealistic humans, real-world photography, or 2D flat illustration.
- Text baked into the image; watermarks; visible color swatches.
PROMPT
				,
				'guidance'    => 'Stage each concept as a miniature stop-motion diorama: describe the clay character(s), their pose and expression, the tiny hand-built props and set pieces, and the camera angle (tabletop level, overhead, close-up). Physical metaphors work best — stacking, balancing, squashing, climbing.',
			),

			'paper-cut'            => array(
				'label'       => __( 'Paper cut art', 'launchy-illustrator' ),
				'description' => __( 'Layered cut-paper collage with crisp edges and stacked depth.', 'launchy-illustrator' ),
				'prompt'      => <<<'PROMPT'
# Role
You are a paper artist who builds illustrations entirely from layered, hand-cut paper.

## Style
- Every element is cut from colored card stock and layered: crisp cut edges,
  subtle soft shadows between layers giving real physical depth.
- Visible paper texture and grain; flat colors per layer — shading comes from
  stacking lighter and darker sheets, never from airbrushing.
- Compositions read like a shadow-box or pop-up book spread: background layers
  recede, the subject sits proud on the top layers.
- A disciplined palette of complementary paper colors; clean, decorative, precise.

## Avoid
- Gradients, painterly blending, glossy highlights — everything must look cut, not drawn.
- Photorealism or 3D renders; the depth comes from paper layers only.
- Text baked into the image; watermarks; visible color swatches.
PROMPT
				,
				'guidance'    => 'Compose each concept as a layered paper scene: name the subject on the top layer, what sits on the middle layers, and the backdrop layers behind. Silhouettes and symbolic shapes work beautifully — describe the metaphor as physical paper pieces interacting.',
			),

			'whiteboard'           => array(
				'label'       => __( 'Whiteboard marker', 'launchy-illustrator' ),
				'description' => __( 'Energetic hand-drawn dry-erase sketches with arrows and annotations.', 'launchy-illustrator' ),
				'prompt'      => <<<'PROMPT'
# Role
You are a brilliant visual facilitator sketching ideas live on a whiteboard.

## Style
- Confident hand-drawn dry-erase marker linework on a clean white board: quick,
  energetic strokes with natural line-weight variation and slight marker streaks.
- Mostly black marker for structure, plus at most two accent marker colors used
  deliberately for emphasis.
- The visual language of great whiteboarding: simple expressive characters,
  arrows, circles, underlines, small diagrams and labels that explain an idea at
  a glance.
- White background with generous negative space; it should feel like a photo of
  a really good whiteboard, minus the glare.

## Avoid
- Careful shading, painterly rendering, or photorealism — keep it fast and sketchy.
- More than two accent colors; filled backgrounds; heavy texture.
- Long sentences written out — a single circled word or short label is the maximum.
PROMPT
				,
				'guidance'    => 'Explain each concept the way a facilitator would sketch it: simple stick-adjacent characters, boxes-and-arrows flows, before/after panels, a big circled focal element. Describe what gets drawn, what gets an arrow, and what (single word) gets circled for emphasis.',
			),

			'retro-illustration'   => array(
				'label'       => __( 'Retro illustration', 'launchy-illustrator' ),
				'description' => __( 'Mid-century print style: textured inks, halftones, optimistic energy.', 'launchy-illustrator' ),
				'prompt'      => <<<'PROMPT'
# Role
You are a celebrated mid-century commercial illustrator whose prints defined the optimistic look of 1950s magazines.

## Style
- Flat, screen-printed feel: textured inks, visible halftone dots and slight
  registration offsets, as if pulled on a vintage press.
- A limited warm retro palette — think mustard, teal, tomato red, cream paper —
  with the paper tone showing through.
- Geometric simplification: characters and objects reduced to confident stylized
  shapes with minimal linework; atomic-age motifs (starbursts, boomerangs) used
  sparingly.
- Cheerful, optimistic, energetic compositions with a clear focal subject.

## Avoid
- Digital gradients, glossy rendering, photorealism, or modern 3D looks.
- Muddy or neon palettes; more than five ink colors.
- Text baked into the image; watermarks; visible color swatches.
PROMPT
				,
				'guidance'    => 'Stage concepts as upbeat mid-century advertising scenes: a stylized character demonstrating the idea with theatrical confidence, supporting props reduced to geometric shapes, and a composition that would sell the concept off a 1955 magazine page. Name the pose, the expression, and the era-appropriate props.',
			),

			'modern-tech'          => array(
				'label'       => __( 'Modern tech design', 'launchy-illustrator' ),
				'description' => __( 'Contemporary product-design illustration: flat vector, soft gradients, isometric moments.', 'launchy-illustrator' ),
				'prompt'      => <<<'PROMPT'
# Role
You are the design lead for a top-tier tech brand, producing the illustration system for its product site.

## Style
- Contemporary flat vector illustration with restrained soft gradients and
  clean geometric construction; occasional isometric or 2.5D elements for
  systems and structures.
- A cool, sophisticated palette (deep blues, violets, slate) with ONE vivid
  accent color used consistently for the focal point.
- Abstract-but-legible metaphors: devices, dashboards, nodes and connections,
  layered cards, flowing paths — rendered minimal and precise.
- Generous whitespace, strong grid-informed composition, crisp rounded shapes.

## Avoid
- Clip-art corporate people ("corporate Memphis") clichés and rubber-limbed figures.
- Photorealism, heavy textures, drop-shadow "sticker" looks, neon glow overload.
- Text baked into the image beyond tiny abstract UI hints; watermarks; swatches.
PROMPT
				,
				'guidance'    => 'Visualize concepts as elegant abstract systems: data flowing through pipelines, blocks assembling into structures, a highlighted node in a network, a dashboard moment. Describe the geometry, what the single accent color highlights, and the metaphor the shapes act out.',
			),

			'watercolor-storybook' => array(
				'label'       => __( 'Watercolor storybook', 'launchy-illustrator' ),
				'description' => __( 'Loose watercolor washes with ink lines — warm, whimsical, hand-painted.', 'launchy-illustrator' ),
				'prompt'      => <<<'PROMPT'
# Role
You are a beloved children's-book illustrator painting in watercolor and ink.

## Style
- Loose, luminous watercolor washes over confident hand-drawn ink outlines;
  colors bleed softly at the edges and pool the way real watercolor does.
- Visible cold-press paper texture; whites are unpainted paper, not digital white.
- A warm, gentle palette; soft ambient light; cozy, whimsical storybook mood.
- Characters and scenes are expressive and a little quirky — imperfection is charm.

## Avoid
- Digital-perfect edges, gradients, or vector flatness — it must read as paint.
- Photorealism, 3D renders, dark or dystopian moods.
- Text baked into the image; watermarks; visible color swatches.
PROMPT
				,
				'guidance'    => 'Tell each concept as a storybook moment: a charming character mid-action in a softly painted scene. Describe the character, the action, the emotion, and one or two supporting details worth painting — keep scenes gentle, warm, and narrative.',
			),
		);

		/**
		 * Register additional style presets (or modify the built-ins).
		 *
		 * Each entry: 'id' => array{label, description, prompt, guidance, references?}.
		 * 'references' is an optional array of image URLs / attachment IDs used
		 * for character/style consistency.
		 *
		 * @param array $presets Presets keyed by id.
		 */
		return apply_filters( 'launchy_illustrator_style_presets', $presets );
	}

	/**
	 * One preset by id, or the default preset if the id is unknown.
	 *
	 * @param string $id Preset id.
	 * @return array
	 */
	public static function get( $id ) {
		$all = self::all();
		if ( isset( $all[ $id ] ) ) {
			return $all[ $id ];
		}
		return isset( $all[ self::DEFAULT_PRESET ] ) ? $all[ self::DEFAULT_PRESET ] : reset( $all );
	}

	/**
	 * The currently selected preset (per settings).
	 *
	 * @return array
	 */
	public static function current() {
		return self::get( (string) Settings::get( 'style_preset' ) );
	}
}
