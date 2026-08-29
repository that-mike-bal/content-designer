<?php
/**
 * The built-in media type: AI illustrations in the site's own configured style.
 *
 * Everything brand-specific lives in settings (Settings → Launchy Illustrator):
 * the master style prompt, the planner guidance, and the character/style
 * reference images. Ship your own mascot, palette, and art direction there —
 * or load a preset (see examples/launchy-style-preset.php).
 *
 * @package Launchy_Illustrator
 */

namespace Launchy_Illustrator\Media_Types;

use Launchy_Illustrator\Settings;
use Launchy_Illustrator\Style_Presets;

defined( 'ABSPATH' ) || exit;

/**
 * Generic, settings-driven illustration type.
 */
class Illustration extends Media_Type {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'illustration';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label() {
		return __( 'AI illustration', 'launchy-illustrator' );
	}

	/**
	 * User-set references win; otherwise the active preset's (e.g. a mascot
	 * preset shipping canonical character references).
	 *
	 * {@inheritdoc}
	 */
	public function get_references() {
		$refs = Settings::reference_images();
		if ( ! $refs ) {
			$preset = Style_Presets::current();
			$refs   = isset( $preset['references'] ) ? (array) $preset['references'] : array();
		}
		return $refs;
	}

	/**
	 * Guidance injected into the editorial planner so it writes scenes that
	 * suit the configured style. Resolution order: the settings override, then
	 * the active preset, then a neutral default. Filterable.
	 *
	 * {@inheritdoc}
	 */
	public function get_planner_guidance() {
		$guidance = trim( (string) Settings::get( 'planner_guidance' ) );
		if ( '' === $guidance ) {
			$preset   = Style_Presets::current();
			$guidance = isset( $preset['guidance'] ) ? trim( (string) $preset['guidance'] ) : '';
		}
		if ( '' === $guidance ) {
			$guidance = 'A conceptual editorial illustration that acts out the section\'s core idea as a concrete '
				. 'visual metaphor. Describe the subject, the action, the emotion, any supporting elements, and '
				. 'the metaphor. Specificity beats adjectives; match the mood to the section\'s tone.';
		}

		/**
		 * Filter the planner guidance for the illustration media type.
		 *
		 * @param string $guidance Guidance text injected into the planning prompt.
		 */
		return apply_filters( 'launchy_illustrator_planner_guidance', $guidance );
	}

	/**
	 * Assemble: style block + scene + format note. Order matters.
	 *
	 * {@inheritdoc}
	 */
	public function build_prompt( array $placement ) {
		// Custom prompt overrides the preset; preset overrides the fallback.
		$style = trim( (string) Settings::get( 'style_prompt' ) );
		if ( '' === $style ) {
			$preset = Style_Presets::current();
			$style  = isset( $preset['prompt'] ) ? trim( (string) $preset['prompt'] ) : '';
		}
		if ( '' === $style ) {
			$style = self::default_style_prompt();
		}

		/**
		 * Filter the master style block.
		 *
		 * @param string $style     Style prompt.
		 * @param array  $placement Placement being generated.
		 */
		$style = apply_filters( 'launchy_illustrator_style_prompt', $style, $placement );

		$format = sprintf(
			'## Format
Compose for a %1$s aspect ratio image used as the post\'s %2$s. %3$s',
			$placement['aspect_ratio'],
			$placement['role'],
			isset( $placement['composition'] ) ? $placement['composition'] : ''
		);

		return $style . "\n\n## The Scene\n" . $placement['scene'] . "\n\n" . $format;
	}

	/**
	 * Fallback art direction used when no style prompt is configured yet.
	 * Deliberately neutral — the real value comes from the user's own style
	 * prompt in Settings → Launchy Illustrator.
	 *
	 * @return string
	 */
	public static function default_style_prompt() {
		return <<<'PROMPT'
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
PROMPT;
	}
}
