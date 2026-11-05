<?php
/**
 * Settings: API key, models, style prompt, references, behavior.
 *
 * @package Content_Designer
 */

namespace Content_Designer;

defined( 'ABSPATH' ) || exit;

/**
 * Settings API integration + typed access to options.
 */
class Settings {

	const OPTION = 'content_designer_settings';

	/**
	 * Hook in.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Add the settings screen under Settings.
	 */
	public function register_menu() {
		add_options_page(
			__( 'Content Designer', 'content-designer' ),
			__( 'Content Designer', 'content-designer' ),
			'manage_options',
			'content-designer-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Defaults for every setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'gemini_api_key'    => '',
			'text_model'        => 'gemini-3.8-flash',
			'image_model'       => 'gemini-nano-banana-2.1',
			'use_wp_ai_client'  => true,
			'style_preset'      => Style_Presets::DEFAULT_PRESET,
			'style_prompt'      => '', // Empty = the selected preset drives the style.
			'planner_guidance'  => '', // Empty = generic editorial-illustration guidance.
			'reference_images'  => '',
			'hero_variations'   => 2,
			'inline_variations' => 1,
			'hero_in_content'   => false,
			'cleanup_variants'  => true,
		);
	}

	/**
	 * Point stored model settings at models that still answer.
	 *
	 * Google retires model IDs on a published schedule, and a saved setting
	 * wins over defaults() in get(), so changing the defaults alone would
	 * leave an existing site calling a model that is gone. Only the exact
	 * retired IDs are rewritten -- any other value is somebody's deliberate
	 * choice and is left alone.
	 *
	 * @return void
	 */
	public static function migrate_retired_models() {
		$map = array(
			// 2.5 Flash reached its retirement date on 2026-10-20; 3.6 and 3.7
			// Flash are also past deprecation. Google names 3.8 Flash as the
			// replacement for all three.
			'gemini-2.5-flash'       => 'gemini-3.8-flash',
			'gemini-3.6-flash'       => 'gemini-3.8-flash',
			'gemini-3.7-flash'       => 'gemini-3.8-flash',
			// The original Nano Banana is legacy and Nano Banana 2 was
			// deprecated on 2026-10-06, both in favor of Nano Banana 2.1.
			'gemini-2.5-flash-image' => 'gemini-nano-banana-2.1',
			'gemini-3.1-flash-image' => 'gemini-nano-banana-2.1',
		);

		$opts    = (array) get_option( self::OPTION, array() );
		$changed = false;

		foreach ( array( 'text_model', 'image_model' ) as $key ) {
			$current = isset( $opts[ $key ] ) ? (string) $opts[ $key ] : '';
			if ( isset( $map[ $current ] ) ) {
				$opts[ $key ] = $map[ $current ];
				$changed      = true;
			}
		}

		if ( $changed ) {
			update_option( self::OPTION, $opts );
		}
	}

	/**
	 * Read one setting with defaults applied.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$opts = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		return isset( $opts[ $key ] ) ? $opts[ $key ] : null;
	}

	/**
	 * Write one setting, leaving the rest untouched.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value New value.
	 */
	public static function update( $key, $value ) {
		$opts         = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$opts[ $key ] = $value;
		update_option( self::OPTION, $opts );
	}

	/**
	 * Resolve the Gemini API key and the source it came from. Constants win
	 * over the stored option so the key can live in wp-config.php instead of
	 * the database.
	 *
	 * api_key() and api_key_source() both read through here, so the settings
	 * screen can never name a different source than the one actually used.
	 *
	 * @return array Source slug ('' when nothing is set) and the key.
	 */
	private static function resolve_api_key() {
		if ( defined( 'CONTENT_DESIGNER_GEMINI_API_KEY' ) && CONTENT_DESIGNER_GEMINI_API_KEY ) {
			return array( 'constant', (string) CONTENT_DESIGNER_GEMINI_API_KEY );
		}
		if ( defined( 'LAUNCHY_ILLUSTRATOR_GEMINI_API_KEY' ) && LAUNCHY_ILLUSTRATOR_GEMINI_API_KEY ) {
			// Pre-2.0 name. Still honored so a rename does not take a working
			// site's key away, but the settings screen asks for it to be moved.
			return array( 'constant_legacy', (string) LAUNCHY_ILLUSTRATOR_GEMINI_API_KEY );
		}
		if ( defined( 'GEMINI_API_KEY' ) && GEMINI_API_KEY ) {
			return array( 'constant_generic', (string) GEMINI_API_KEY );
		}
		$env = getenv( 'GEMINI_API_KEY' );
		if ( $env ) {
			return array( 'environment', (string) $env );
		}
		$stored = (string) self::get( 'gemini_api_key' );

		return array( $stored ? 'option' : '', $stored );
	}

	/**
	 * The Gemini API key in effect, or '' when none is configured.
	 *
	 * @return string
	 */
	public static function api_key() {
		$resolved = self::resolve_api_key();

		return $resolved[1];
	}

	/**
	 * Which source api_key() is reading from: 'constant', 'constant_legacy',
	 * 'constant_generic', 'environment', 'option', or '' when nothing is
	 * configured anywhere.
	 *
	 * @return string
	 */
	public static function api_key_source() {
		$resolved = self::resolve_api_key();

		return $resolved[0];
	}

	/**
	 * A sentence naming where the key in effect comes from. Shown on the
	 * settings screen and in the Illustrate screen's no-key notice.
	 *
	 * @return string
	 */
	public static function api_key_source_label() {
		switch ( self::api_key_source() ) {
			case 'constant':
				return __( 'In use: the CONTENT_DESIGNER_GEMINI_API_KEY constant in wp-config.php. Anything saved in this field is ignored.', 'content-designer' );
			case 'constant_legacy':
				return __( 'In use: the LAUNCHY_ILLUSTRATOR_GEMINI_API_KEY constant in wp-config.php — the pre-2.0 name. It still works, but rename it to CONTENT_DESIGNER_GEMINI_API_KEY; support for the old name will go away. Anything saved in this field is ignored.', 'content-designer' );
			case 'constant_generic':
				return __( 'In use: the GEMINI_API_KEY constant in wp-config.php. Anything saved in this field is ignored.', 'content-designer' );
			case 'environment':
				return __( 'In use: the GEMINI_API_KEY environment variable. Anything saved in this field is ignored.', 'content-designer' );
			case 'option':
				return __( 'In use: the key saved on this screen.', 'content-designer' );
			default:
				return __( 'No Gemini API key is configured anywhere, so generation will fail. Save one here, or define CONTENT_DESIGNER_GEMINI_API_KEY in wp-config.php above the "stop editing" line — below that line it is defined too late for plugins to see.', 'content-designer' );
		}
	}

	/**
	 * Reference images as a clean array of URLs / attachment IDs.
	 *
	 * @return array
	 */
	public static function reference_images() {
		$lines = preg_split( '/[\r\n]+/', (string) self::get( 'reference_images' ) );
		$refs  = array_values( array_filter( array_map( 'trim', (array) $lines ) ) );

		/**
		 * Filter the character reference images passed to the image model.
		 *
		 * @param array $refs URLs or attachment IDs.
		 */
		return apply_filters( 'content_designer_reference_images', $refs );
	}

	/**
	 * Register the settings screen (Settings > Content Designer).
	 */
	public function register() {
		register_setting(
			'content_designer',
			self::OPTION,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);

		add_settings_section( 'content_designer_main', __( 'AI Generation', 'content-designer' ), '__return_empty_string', 'content_designer' );

		$fields = array(
			'gemini_api_key'    => array( __( 'Gemini API key', 'content-designer' ), 'password', __( 'Your own Google Gemini API key — get one free at https://aistudio.google.com/apikey. Leave this empty if you define CONTENT_DESIGNER_GEMINI_API_KEY in wp-config.php, which is recommended: it keeps the key out of the database.', 'content-designer' ) ),
			'text_model'        => array( __( 'Text model (editorial pass)', 'content-designer' ), 'text', __( 'Default is Gemini 3.8 Flash (gemini-3.8-flash).', 'content-designer' ) ),
			'image_model'       => array( __( 'Image model', 'content-designer' ), 'text', __( 'Default is Nano Banana 2.1 (gemini-nano-banana-2.1).', 'content-designer' ) ),
			'use_wp_ai_client'  => array( __( 'Prefer the WordPress AI Client for text when available', 'content-designer' ), 'checkbox', __( 'Image generation always calls Gemini directly so reference images and aspect ratios are honored.', 'content-designer' ) ),
			'style_preset'      => array( __( 'Style preset', 'content-designer' ), 'select', __( 'Pick a ready-made look. Plugins can add more presets (e.g. a brand mascot preset).', 'content-designer' ) ),
			'hero_variations'   => array( __( 'Variations for hero images', 'content-designer' ), 'number', '' ),
			'inline_variations' => array( __( 'Variations for other images', 'content-designer' ), 'number', '' ),
			'hero_in_content'   => array( __( 'Also insert the hero into the post body', 'content-designer' ), 'checkbox', __( 'The hero is always set as the featured image. Enable if your theme does not render featured images.', 'content-designer' ) ),
			'cleanup_variants'  => array( __( 'Delete unchosen variations after placement', 'content-designer' ), 'checkbox', '' ),
		);

		foreach ( $fields as $key => $def ) {
			add_settings_field(
				$key,
				$def[0],
				array( $this, 'render_field' ),
				'content_designer',
				'content_designer_main',
				array(
					'key'       => $key,
					'type'      => $def[1],
					'help'      => $def[2],
					'label_for' => 'content_designer_' . $key,
				)
			);
		}
	}

	/**
	 * Sanitize the settings array.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$out      = self::defaults();
		$existing = wp_parse_args( (array) get_option( self::OPTION, array() ), $out );
		$input    = (array) $input;

		$out['gemini_api_key'] = isset( $input['gemini_api_key'] ) && '' !== trim( $input['gemini_api_key'] )
			? sanitize_text_field( $input['gemini_api_key'] )
			: $existing['gemini_api_key'];

		$out['text_model']        = sanitize_text_field( $input['text_model'] ?? $out['text_model'] );
		$out['image_model']       = sanitize_text_field( $input['image_model'] ?? $out['image_model'] );
		$out['use_wp_ai_client']  = ! empty( $input['use_wp_ai_client'] );

		$preset               = sanitize_key( $input['style_preset'] ?? '' );
		$out['style_preset']  = array_key_exists( $preset, Style_Presets::all() ) ? $preset : Style_Presets::DEFAULT_PRESET;

		// No longer rendered as fields — user styles replaced them. Preserve any
		// legacy values so an old override keeps working until it is migrated.
		$out['style_prompt']      = isset( $input['style_prompt'] ) ? sanitize_textarea_field( $input['style_prompt'] ) : $existing['style_prompt'];
		$out['planner_guidance']  = isset( $input['planner_guidance'] ) ? sanitize_textarea_field( $input['planner_guidance'] ) : $existing['planner_guidance'];
		$out['reference_images']  = isset( $input['reference_images'] ) ? sanitize_textarea_field( $input['reference_images'] ) : $existing['reference_images'];
		$out['hero_variations']   = max( 1, min( 4, absint( $input['hero_variations'] ?? 2 ) ) );
		$out['inline_variations'] = max( 1, min( 4, absint( $input['inline_variations'] ?? 1 ) ) );
		$out['hero_in_content']   = ! empty( $input['hero_in_content'] );
		$out['cleanup_variants']  = ! empty( $input['cleanup_variants'] );

		return $out;
	}

	/**
	 * Render a single field.
	 *
	 * @param array $args Field args.
	 */
	public function render_field( $args ) {
		$key   = $args['key'];
		$value = self::get( $key );
		$name  = esc_attr( self::OPTION . '[' . $key . ']' );
		$id    = esc_attr( 'content_designer_' . $key );

		switch ( $args['type'] ) {
			case 'select':
				printf( '<select id="%s" name="%s">', $id, $name );
				foreach ( Style_Presets::all() as $preset_id => $preset ) {
					printf(
						'<option value="%s" %s>%s — %s</option>',
						esc_attr( $preset_id ),
						selected( $value, $preset_id, false ),
						esc_html( $preset['label'] ),
						esc_html( $preset['description'] )
					);
				}
				echo '</select>';
				break;
			case 'checkbox':
				printf( '<input type="checkbox" id="%s" name="%s" value="1" %s />', $id, $name, checked( (bool) $value, true, false ) );
				break;
			case 'textarea':
				printf( '<textarea id="%s" name="%s" rows="6" class="large-text code">%s</textarea>', $id, $name, esc_textarea( (string) $value ) );
				break;
			case 'number':
				printf( '<input type="number" min="1" max="4" id="%s" name="%s" value="%s" class="small-text" />', $id, $name, esc_attr( (string) $value ) );
				break;
			case 'password':
				printf( '<input type="password" id="%s" name="%s" value="" autocomplete="new-password" class="regular-text" placeholder="%s" />', $id, $name, $value ? esc_attr__( '(saved — enter to replace)', 'content-designer' ) : '' );
				break;
			default:
				printf( '<input type="text" id="%s" name="%s" value="%s" class="regular-text" />', $id, $name, esc_attr( (string) $value ) );
		}

		if ( ! empty( $args['help'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['help'] ) );
		}

		if ( 'gemini_api_key' === $key ) {
			if ( '' === self::api_key_source() ) {
				printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( self::api_key_source_label() ) );
			} else {
				printf( '<p class="description"><strong>%s</strong></p>', esc_html( self::api_key_source_label() ) );
			}
		}
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Content Designer', 'content-designer' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'content_designer' );
				do_settings_sections( 'content_designer' );
				submit_button();
				?>
			</form>
			<?php
			/**
			 * Render extra UI below the settings form (the style editor).
			 */
			do_action( 'content_designer_after_settings_form' );
			?>
		</div>
		<?php
	}
}
