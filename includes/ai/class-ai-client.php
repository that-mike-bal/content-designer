<?php
/**
 * Provider adapter.
 *
 * Text (the editorial pass) prefers the WordPress AI Client when present —
 * wp_ai_client_prompt() ships in core from WP 7.0 and as the wordpress/wp-ai-client
 * package before that — and falls back to calling Gemini directly.
 *
 * Images always call Gemini directly: character consistency depends on passing
 * reference images and exact aspect ratios, which the generic client does not
 * yet expose uniformly across providers.
 *
 * @package Content_Designer
 */

namespace Content_Designer\AI;

use Content_Designer\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Uniform generate_text() / generate_image() facade.
 */
class AI_Client {

	/**
	 * Generate text (used for the placement-planning editorial pass).
	 *
	 * @param string $prompt    Full prompt.
	 * @param bool   $want_json Whether the caller expects a JSON document back.
	 * @return string|WP_Error
	 */
	public static function generate_text( $prompt, $want_json = true ) {
		if ( Settings::get( 'use_wp_ai_client' ) && function_exists( 'wp_ai_client_prompt' ) ) {
			try {
				$result = wp_ai_client_prompt( $prompt )->generate_text();
				if ( ! is_wp_error( $result ) && is_string( $result ) && '' !== trim( $result ) ) {
					return $result;
				}
			} catch ( \Throwable $e ) {
				// Fall through to the direct Gemini call below.
			}
		}

		return Gemini_Http::generate_text( $prompt, (string) Settings::get( 'text_model' ), $want_json );
	}

	/**
	 * Generate a single image.
	 *
	 * @param string $prompt Assembled prompt (style block + scene + format note).
	 * @param array  $args   {aspect_ratio, resolution, references, model?}.
	 * @return array|WP_Error {bytes, mime} on success.
	 */
	public static function generate_image( $prompt, array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'model'        => (string) Settings::get( 'image_model' ),
				'aspect_ratio' => '1:1',
				'resolution'   => '2K',
				'references'   => array(),
			)
		);

		return Gemini_Http::generate_image( $prompt, $args );
	}
}
