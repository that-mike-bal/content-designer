<?php
/**
 * Placement planner: the AI editorial pass.
 *
 * Reads the draft like an editor and proposes where media genuinely helps —
 * to conceptualize ideas and to pace long text — never as decoration. Encodes
 * the Leading Product content-design rules (cadence, ratio-by-role, variety).
 *
 * @package Launchy_Illustrator
 */

namespace Launchy_Illustrator;

use Launchy_Illustrator\AI\AI_Client;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Produces and validates a placement plan for a post.
 */
class Placement_Planner {

	const VALID_RATIOS = array( '1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9' );
	const PLAN_META    = '_launchy_plan';

	/**
	 * Build a placement plan for a post.
	 *
	 * @param \WP_Post $post Post.
	 * @param array    $scan Draft_Scanner::scan() result.
	 * @return array|WP_Error List of placement items.
	 */
	public function plan( \WP_Post $post, array $scan ) {
		$raw = AI_Client::generate_text( $this->build_prompt( $post, $scan ), true );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$data = $this->decode_json( $raw );
		if ( null === $data || empty( $data['placements'] ) || ! is_array( $data['placements'] ) ) {
			return new WP_Error( 'launchy_bad_plan', __( 'The editorial pass did not return a usable placement plan.', 'launchy-illustrator' ) );
		}

		$plan = $this->normalize( $data['placements'], $scan );

		/**
		 * Filter the validated placement plan before it is shown for approval.
		 *
		 * @param array    $plan Placement items.
		 * @param \WP_Post $post The post being illustrated.
		 * @param array    $scan The structural outline.
		 */
		$plan = apply_filters( 'launchy_illustrator_placement_plan', $plan, $post, $scan );

		update_post_meta( $post->ID, self::PLAN_META, $plan );

		return $plan;
	}

	/**
	 * The editorial prompt: content-design rules + media type guidance +
	 * outline + draft text, asking for strict JSON.
	 *
	 * @param \WP_Post $post Post.
	 * @param array    $scan Outline.
	 * @return string
	 */
	private function build_prompt( \WP_Post $post, array $scan ) {
		$registry = Plugin::instance()->media_types;
		$types    = array();
		foreach ( $registry->all() as $slug => $type ) {
			$types[] = sprintf( "- \"%s\" (%s): %s", $slug, $type->get_label(), $type->get_planner_guidance() );
		}

		$outline_lines = array();
		foreach ( $scan['outline'] as $entry ) {
			$outline_lines[] = sprintf(
				'[%d] %s%s — %d words%s%s',
				$entry['index'],
				$entry['type'],
				$entry['heading'] ? ' "' . $entry['heading'] . '"' : '',
				$entry['words'],
				$entry['is_visual_break'] ? ' (EXISTING VISUAL BREAK)' : '',
				$entry['excerpt'] && ! $entry['heading'] ? ' — ' . $entry['excerpt'] : ''
			);
		}

		$text = wp_strip_all_tags( $post->post_content );
		if ( mb_strlen( $text ) > 16000 ) {
			$text = mb_substr( $text, 0, 16000 ) . "\n[...truncated...]";
		}

		$publication = get_bloginfo( 'name' );

		return <<<PROMPT
You are the art director / content editor for "{$publication}". Read the draft below like an editor and produce a MEDIA PLACEMENT PLAN: where supporting media genuinely helps, never decoration.

## Editorial rules
Media does one of two jobs (sometimes both):
1. CONCEPTUALIZE — make an abstract idea concrete (a named concept, framework, or metaphor a picture makes stickier).
2. PACE — break up long unbroken runs of text (roughly 300+ words without a visual break).

Place media when: a section introduces a named concept/metaphor; there is a long unbroken text run; it is a natural act break (new major section); or it is the top of the post (the hero).
Skip media when: the text is already broken up by a code block, screenshot, list, or pull-quote; two illustrations would sit back-to-back with little text between; or a real screenshot would serve better than mascot art.

Cadence: roughly one image per 250–450 words. A typical essay gets 3–6 images TOTAL including the hero. Exactly ONE hero per post, anchored at index 0. The first image after the hero should land within the first screenful. Never pad to hit a count.

Roles and crops:
- "hero" → 16:9 (or 3:2), the featured/share image that sets tone.
- "banner" → 21:9 or 16:9, a wide section-break "breather"; the subject small in the scene, generous negative space.
- "concept" → 4:3 or 1:1, the workhorse concept illustration; room for a small supporting cast.
- "spot" → 1:1, a little gag or reaction beat beside text.

VARIETY (critical): across the post, vary aspect ratio (never the same crop twice in a row), camera distance (wide establishing / medium / tight close-up), pose & energy (running, climbing, slumping, celebrating, puzzling — matched to the section's emotional tone), and composition (centered, rule-of-thirds, entering from an edge, negative space). Consistency is for the character and palette; variety is for staging.

## Available media types
{$this->lines( $types )}

## Draft outline (anchor indexes in [brackets]; total {$scan['total_words']} words)
{$this->lines( $outline_lines )}

## Draft text
{$text}

## Output
Return ONLY a JSON object, no prose, shaped exactly like:
{
  "placements": [
    {
      "media_type": "illustration",
      "role": "hero",
      "anchor_index": 0,
      "aspect_ratio": "16:9",
      "job": "conceptualize",
      "scene": "Concrete, specific scene direction: pose, action, expression, supporting cast, metaphor. Specificity beats adjectives.",
      "composition": "Camera distance and framing note so the artist composes for the crop (e.g. 'wide shot, Launchy left-of-center, open sky for the title').",
      "alt_text": "One-sentence description of the scene for accessibility.",
      "rationale": "One sentence on why media helps HERE."
    }
  ]
}
"anchor_index" means the image is inserted BEFORE the outline item with that index. Choose section boundaries (before a heading, or right after a section's opening), never mid-thought.
PROMPT;
	}

	/**
	 * Join lines helper (heredoc-friendly).
	 *
	 * @param array $lines Lines.
	 * @return string
	 */
	private function lines( array $lines ) {
		return implode( "\n", $lines );
	}

	/**
	 * Decode the model's JSON (tolerating markdown fences).
	 *
	 * @param string $raw Raw model output.
	 * @return array|null
	 */
	private function decode_json( $raw ) {
		$raw  = trim( $raw );
		$raw  = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', $raw );
		$data = json_decode( $raw, true );

		if ( null === $data && preg_match( '/\{.*\}/s', $raw, $m ) ) {
			$data = json_decode( $m[0], true );
		}

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Validate and normalize the plan: known media types, valid ratios and
	 * anchors, exactly one hero, sane defaults, stable ids.
	 *
	 * @param array $placements Raw placements from the model.
	 * @param array $scan       Outline.
	 * @return array
	 */
	private function normalize( array $placements, array $scan ) {
		$registry  = Plugin::instance()->media_types;
		$max_index = 0;
		foreach ( $scan['outline'] as $entry ) {
			$max_index = max( $max_index, $entry['index'] + 1 );
		}

		$plan     = array();
		$has_hero = false;
		$n        = 0;

		foreach ( $placements as $item ) {
			if ( empty( $item['scene'] ) || ! is_array( $item ) ) {
				continue;
			}

			$slug = sanitize_key( $item['media_type'] ?? 'illustration' );
			$type = $registry->get( $slug );
			if ( ! $type ) {
				$slug = 'illustration';
				$type = $registry->get( $slug );
				if ( ! $type ) {
					continue;
				}
			}

			$roles = $type->get_roles();
			$role  = sanitize_key( $item['role'] ?? 'concept' );
			if ( ! isset( $roles[ $role ] ) ) {
				$role = 'concept';
			}

			// Single hero rule: first hero wins, extras become concepts.
			if ( 'hero' === $role ) {
				if ( $has_hero ) {
					$role = 'concept';
				} else {
					$has_hero = true;
				}
			}

			$ratio = (string) ( $item['aspect_ratio'] ?? '' );
			if ( ! in_array( $ratio, self::VALID_RATIOS, true ) ) {
				$ratio = $roles[ $role ]['ratio'];
			}

			// Clamp once; the stored anchor and its human label must agree.
			$anchor = max( 0, min( $max_index, (int) ( $item['anchor_index'] ?? 0 ) ) );

			$n ++;
			$plan[] = array(
				'id'           => 'p' . $n,
				'media_type'   => $slug,
				'role'         => $role,
				'anchor_index' => $anchor,
				'aspect_ratio' => $ratio,
				'resolution'   => $roles[ $role ]['resolution'],
				'job'          => in_array( $item['job'] ?? '', array( 'conceptualize', 'pace', 'both' ), true ) ? $item['job'] : 'conceptualize',
				'scene'        => sanitize_textarea_field( $item['scene'] ),
				'composition'  => sanitize_textarea_field( $item['composition'] ?? '' ),
				'alt_text'     => sanitize_text_field( $item['alt_text'] ?? wp_trim_words( $item['scene'], 20 ) ),
				'rationale'    => sanitize_text_field( $item['rationale'] ?? '' ),
				'anchor_label' => Draft_Scanner::anchor_label( $scan, $anchor ),
				'variations'   => 'hero' === $role ? (int) Settings::get( 'hero_variations' ) : (int) Settings::get( 'inline_variations' ),
				'approved'     => true,
			);
		}

		// Keep the plan in document order; hero first at the same anchor.
		usort(
			$plan,
			static function ( $a, $b ) {
				if ( $a['anchor_index'] === $b['anchor_index'] ) {
					return ( 'hero' === $b['role'] ) <=> ( 'hero' === $a['role'] );
				}
				return $a['anchor_index'] <=> $b['anchor_index'];
			}
		);

		return $plan;
	}
}
