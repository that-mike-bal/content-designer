<?php
/**
 * User-defined styles — create and save your own look without writing a plugin.
 *
 * A saved style has exactly the same shape as a built-in preset (label,
 * description, prompt, guidance, references) and is merged into the registry
 * through the same `content_designer_style_presets` filter third-party
 * preset plugins use. Everything downstream — the picker, the Illustration
 * media type, settings validation — therefore treats user styles and built-ins
 * identically, with no special-casing.
 *
 * @package Content_Designer
 */

namespace Content_Designer;

defined( 'ABSPATH' ) || exit;

/**
 * Storage and registration for styles the user creates in the admin.
 */
class User_Styles {

	/**
	 * Option holding all user styles, keyed by id.
	 */
	const OPTION = 'content_designer_user_styles';

	/**
	 * Id prefix. Keeps user styles from ever colliding with a built-in or
	 * plugin-registered preset id.
	 */
	const PREFIX = 'custom-';

	/**
	 * Register the merge filter.
	 */
	public function __construct() {
		// Priority 20 so plugin-registered presets land first and can be duplicated.
		add_filter( 'content_designer_style_presets', array( __CLASS__, 'merge' ), 20 );
	}

	/**
	 * Every saved user style.
	 *
	 * @return array id => {label, description, prompt, guidance, references}.
	 */
	public static function all() {
		$styles = get_option( self::OPTION, array() );
		return is_array( $styles ) ? $styles : array();
	}

	/**
	 * Merge user styles into the preset registry.
	 *
	 * @param array $presets Registered presets.
	 * @return array
	 */
	public static function merge( $presets ) {
		foreach ( self::all() as $id => $style ) {
			$presets[ $id ] = $style;
		}
		return $presets;
	}

	/**
	 * Is this id a user-created style (rather than a built-in or plugin preset)?
	 *
	 * @param string $id Style id.
	 * @return bool
	 */
	public static function is_custom( $id ) {
		return 0 === strpos( (string) $id, self::PREFIX );
	}

	/**
	 * One saved style, or null.
	 *
	 * @param string $id Style id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Create or update a style. Returns the id it was stored under.
	 *
	 * @param string $id    Existing id, or '' to create a new one.
	 * @param array  $style {label, description, prompt, guidance, references}.
	 * @return string
	 */
	public static function save( $id, array $style ) {
		$all   = self::all();
		$label = sanitize_text_field( $style['label'] ?? '' );
		if ( '' === $label ) {
			$label = __( 'Untitled style', 'content-designer' );
		}

		$id = self::is_custom( $id ) && isset( $all[ $id ] ) ? $id : self::unique_id( $label );

		$all[ $id ] = array(
			'label'       => $label,
			'description' => sanitize_text_field( $style['description'] ?? '' ),
			'prompt'      => sanitize_textarea_field( $style['prompt'] ?? '' ),
			'guidance'    => sanitize_textarea_field( $style['guidance'] ?? '' ),
			'references'  => self::sanitize_references( $style['references'] ?? array() ),
		);

		update_option( self::OPTION, $all );
		return $id;
	}

	/**
	 * Delete a style. Built-ins and plugin presets are never deletable.
	 *
	 * @param string $id Style id.
	 * @return bool True if something was deleted.
	 */
	public static function delete( $id ) {
		$all = self::all();
		if ( ! self::is_custom( $id ) || ! isset( $all[ $id ] ) ) {
			return false;
		}
		unset( $all[ $id ] );
		update_option( self::OPTION, $all );
		return true;
	}

	/**
	 * Copy any preset — built-in, plugin-registered, or custom — into a new
	 * editable user style. This is how the built-in looks become starting
	 * points rather than fixed choices.
	 *
	 * @param string $source_id Preset id to copy.
	 * @return string|false New style id, or false if the source is unknown.
	 */
	public static function duplicate( $source_id ) {
		$presets = Style_Presets::all();
		if ( ! isset( $presets[ $source_id ] ) ) {
			return false;
		}
		$source = $presets[ $source_id ];

		return self::save(
			'',
			array(
				/* translators: %s: name of the style being copied. */
				'label'       => sprintf( __( '%s (copy)', 'content-designer' ), $source['label'] ?? $source_id ),
				'description' => $source['description'] ?? '',
				'prompt'      => $source['prompt'] ?? '',
				'guidance'    => $source['guidance'] ?? '',
				'references'  => $source['references'] ?? array(),
			)
		);
	}

	/**
	 * Convert the legacy global overrides into a real saved style.
	 *
	 * The old override fields replaced parts of the selected preset piecemeal:
	 * a style prompt replaced the preset's prompt, references replaced its
	 * references, and so on. To preserve the exact look someone was already
	 * getting, snapshot the *effective* configuration — override where set,
	 * preset where not — into one style and select it.
	 *
	 * @return string New style id, or '' if there was nothing to migrate.
	 */
	public static function migrate_legacy_overrides() {
		$prompt   = trim( (string) Settings::get( 'style_prompt' ) );
		$guidance = trim( (string) Settings::get( 'planner_guidance' ) );
		$refs     = Settings::reference_images();

		if ( '' === $prompt && '' === $guidance && ! $refs ) {
			return '';
		}

		// Fill the gaps from whatever preset was active, so the effective
		// result is byte-for-byte what the user was getting before.
		$preset = Style_Presets::current();
		if ( '' === $prompt ) {
			$prompt = trim( (string) ( $preset['prompt'] ?? '' ) );
		}
		if ( '' === $guidance ) {
			$guidance = trim( (string) ( $preset['guidance'] ?? '' ) );
		}
		if ( ! $refs ) {
			$refs = (array) ( $preset['references'] ?? array() );
		}

		$id = self::save(
			'',
			array(
				'label'       => __( 'My style', 'content-designer' ),
				'description' => __( 'Migrated from your custom style settings.', 'content-designer' ),
				'prompt'      => $prompt,
				'guidance'    => $guidance,
				'references'  => $refs,
			)
		);

		Settings::update( 'style_preset', $id );
		Settings::update( 'style_prompt', '' );
		Settings::update( 'planner_guidance', '' );
		Settings::update( 'reference_images', '' );

		return $id;
	}

	/**
	 * A collision-free id derived from the label.
	 *
	 * @param string $label Style label.
	 * @return string
	 */
	public static function unique_id( $label ) {
		// sanitize_title, not sanitize_key: the latter strips spaces entirely and
		// would turn "Launchy (mikebal.com)" into "launchymikebalcom".
		$base = sanitize_title( $label );
		if ( '' === $base ) {
			$base = 'style';
		}

		$taken = Style_Presets::all();
		$id    = self::PREFIX . $base;
		$n     = 2;
		while ( isset( $taken[ $id ] ) ) {
			$id = self::PREFIX . $base . '-' . $n;
			++$n;
		}
		return $id;
	}

	/**
	 * References may be attachment IDs or URLs — Gemini_Http::load_reference()
	 * accepts both. Anything else is dropped.
	 *
	 * @param array|string $refs Raw references.
	 * @return array
	 */
	public static function sanitize_references( $refs ) {
		if ( is_string( $refs ) ) {
			$refs = preg_split( '/[\r\n]+/', $refs );
		}

		$out = array();
		foreach ( (array) $refs as $ref ) {
			$ref = trim( (string) $ref );
			if ( '' === $ref ) {
				continue;
			}
			if ( is_numeric( $ref ) ) {
				$out[] = (int) $ref;
			} elseif ( preg_match( '#^https?://#i', $ref ) ) {
				$out[] = esc_url_raw( $ref );
			}
		}
		return array_values( array_unique( $out, SORT_REGULAR ) );
	}
}
