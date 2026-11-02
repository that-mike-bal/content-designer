<?php
/**
 * Direct Gemini REST client (generativelanguage.googleapis.com) using the
 * WordPress HTTP API. PHP port of the skill's scripts/generate_image.py.
 *
 * @package Launchy_Illustrator
 */

namespace Launchy_Illustrator\AI;

use Launchy_Illustrator\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around models/{model}:generateContent.
 */
class Gemini_Http {

	const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

	/**
	 * Text generation, optionally requesting a JSON response.
	 *
	 * @param string $prompt    Prompt text.
	 * @param string $model     Model id.
	 * @param bool   $want_json Request application/json output.
	 * @return string|WP_Error
	 */
	public static function generate_text( $prompt, $model, $want_json = true ) {
		$body = array(
			'contents' => array(
				array( 'parts' => array( array( 'text' => $prompt ) ) ),
			),
		);

		if ( $want_json ) {
			$body['generationConfig'] = array( 'responseMimeType' => 'application/json' );
		}

		$response = self::request( $model, $body, 90 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$text = '';
		foreach ( self::parts( $response ) as $part ) {
			if ( isset( $part['text'] ) ) {
				$text .= $part['text'];
			}
		}

		if ( '' === trim( $text ) ) {
			return new WP_Error( 'launchy_empty_response', __( 'The text model returned no content.', 'launchy-illustrator' ) );
		}

		return $text;
	}

	/**
	 * Image generation with reference images for character consistency.
	 *
	 * @param string $prompt Assembled prompt.
	 * @param array  $args   {model, aspect_ratio, resolution, references}.
	 * @return array|WP_Error {bytes, mime}.
	 */
	public static function generate_image( $prompt, array $args ) {
		// Belt-and-suspenders: restate the crop in the prompt text so the model
		// composes for it even if the API config is ignored (mirrors the skill).
		$parts = array(
			array(
				'text' => trim( $prompt ) . "\n\n[Output format: compose for a {$args['aspect_ratio']} aspect ratio image.]",
			),
		);

		$refs = array_slice( (array) $args['references'], 0, 14 );
		foreach ( $refs as $ref ) {
			$inline = self::load_reference( $ref );
			if ( is_wp_error( $inline ) ) {
				return $inline;
			}
			$parts[] = array( 'inline_data' => $inline );
		}

		$image_config = array( 'aspectRatio' => $args['aspect_ratio'] );
		// Every image model since the original Nano Banana honors imageSize.
		// Matching that one exception, rather than the string "gemini-3",
		// keeps later model names working -- gemini-nano-banana-2.1 included.
		if ( false === strpos( $args['model'], 'gemini-2.5' ) ) {
			$image_config['imageSize'] = $args['resolution'];
		}

		$body = array(
			'contents'         => array( array( 'parts' => $parts ) ),
			'generationConfig' => array(
				'responseModalities' => array( 'IMAGE' ),
				'imageConfig'        => $image_config,
			),
		);

		$response = self::request( $args['model'], $body, 180 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		foreach ( self::parts( $response ) as $part ) {
			$inline = $part['inlineData'] ?? ( $part['inline_data'] ?? null );
			if ( $inline && ! empty( $inline['data'] ) ) {
				$bytes = base64_decode( $inline['data'] );
				if ( false !== $bytes ) {
					return array(
						'bytes' => $bytes,
						'mime'  => $inline['mimeType'] ?? ( $inline['mime_type'] ?? 'image/png' ),
					);
				}
			}
		}

		// Surface any text the model returned (often a refusal/explanation).
		$said = '';
		foreach ( self::parts( $response ) as $part ) {
			if ( isset( $part['text'] ) ) {
				$said .= $part['text'];
			}
		}

		return new WP_Error(
			'launchy_no_image',
			$said
				? sprintf( /* translators: %s: model message */ __( 'No image returned. Model said: %s', 'launchy-illustrator' ), $said )
				: __( 'No image returned by the model.', 'launchy-illustrator' )
		);
	}

	/**
	 * POST to generateContent.
	 *
	 * @param string $model   Model id.
	 * @param array  $body    Request body.
	 * @param int    $timeout Seconds.
	 * @return array|WP_Error Decoded JSON.
	 */
	private static function request( $model, array $body, $timeout ) {
		$key = Settings::api_key();
		if ( ! $key ) {
			return new WP_Error(
				'launchy_no_api_key',
				__( 'No Gemini API key configured. Add it under Settings → Launchy Illustrator, or define LAUNCHY_ILLUSTRATOR_GEMINI_API_KEY in wp-config.php.', 'launchy-illustrator' )
			);
		}

		$response = wp_remote_post(
			sprintf( self::ENDPOINT, rawurlencode( $model ) ),
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = $json['error']['message'] ?? wp_remote_retrieve_response_message( $response );
			return new WP_Error( 'launchy_gemini_http', sprintf( 'Gemini API error (%d): %s', $code, $message ) );
		}

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'launchy_bad_json', __( 'Unexpected response from the Gemini API.', 'launchy-illustrator' ) );
		}

		return $json;
	}

	/**
	 * Flatten candidate content parts from a response.
	 *
	 * @param array $response Decoded response.
	 * @return array
	 */
	private static function parts( array $response ) {
		$out = array();
		foreach ( (array) ( $response['candidates'] ?? array() ) as $candidate ) {
			foreach ( (array) ( $candidate['content']['parts'] ?? array() ) as $part ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	/**
	 * Load a reference image (attachment ID, local path under uploads, or URL)
	 * as inline_data. Remote URLs are cached in the uploads dir.
	 *
	 * @param string|int $ref Reference.
	 * @return array|WP_Error {mime_type, data}.
	 */
	private static function load_reference( $ref ) {
		// Attachment ID.
		if ( is_numeric( $ref ) ) {
			$path = get_attached_file( (int) $ref );
			if ( ! $path || ! file_exists( $path ) ) {
				return new WP_Error( 'launchy_bad_reference', sprintf( 'Reference attachment %d not found.', (int) $ref ) );
			}
			return self::inline_from_file( $path );
		}

		$ref = (string) $ref;

		// Remote URL: download once, cache in uploads/launchy-illustrator/refs.
		if ( preg_match( '#^https?://#i', $ref ) ) {
			$uploads = wp_upload_dir();
			$dir     = trailingslashit( $uploads['basedir'] ) . 'launchy-illustrator/refs';
			wp_mkdir_p( $dir );

			$ext   = strtolower( pathinfo( wp_parse_url( $ref, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			$ext   = in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ? $ext : 'png';
			$cache = $dir . '/' . md5( $ref ) . '.' . $ext;

			if ( ! file_exists( $cache ) ) {
				// wp_safe_remote_get blocks requests to loopback/private hosts.
				$response = wp_safe_remote_get( $ref, array( 'timeout' => 30 ) );
				if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
					return new WP_Error( 'launchy_bad_reference', sprintf( 'Could not download reference %s.', esc_url_raw( $ref ) ) );
				}
				file_put_contents( $cache, wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}

			return self::inline_from_file( $cache );
		}

		return new WP_Error( 'launchy_bad_reference', sprintf( 'Unsupported reference: %s', $ref ) );
	}

	/**
	 * Build inline_data from a local file.
	 *
	 * @param string $path File path.
	 * @return array|WP_Error
	 */
	private static function inline_from_file( $path ) {
		$mime = wp_check_filetype( $path )['type'];
		if ( ! $mime || 0 !== strpos( $mime, 'image/' ) ) {
			return new WP_Error( 'launchy_bad_reference', sprintf( '%s is not an image.', basename( $path ) ) );
		}

		return array(
			'mime_type' => $mime,
			'data'      => base64_encode( file_get_contents( $path ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions
		);
	}
}
