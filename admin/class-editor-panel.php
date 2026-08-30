<?php
/**
 * The Illustrate panel inside the post editor.
 *
 * A second surface over the same pipeline as Tools → Illustrate, added so
 * illustrating never means leaving the post. The important difference is where
 * the result lands: this surface never calls /place. It inserts blocks through
 * the editor's own data stores, so unsaved work survives and every insertion is
 * an undo step.
 *
 * No build step by design — the plugin deploys straight from its repository, so
 * the scripts are plain classic scripts written against the `wp.*` globals with
 * a real dependency graph, rather than a bundled module tree.
 *
 * @package Launchy_Illustrator
 */

namespace Launchy_Illustrator\Admin;

use Launchy_Illustrator\Plugin;
use Launchy_Illustrator\REST_Controller;
use Launchy_Illustrator\Settings;
use Launchy_Illustrator\Style_Presets;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and loads the editor-side assets.
 */
class Editor_Panel {

	const HANDLE = 'launchy-illustrator-editor';

	/**
	 * Hook in.
	 */
	public function __construct() {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Post types the panel appears on.
	 *
	 * Both `editor` and `thumbnail` support are required: the hero role sets a
	 * featured image, so a post type without thumbnails could only ever use half
	 * the feature.
	 *
	 * @return array Post type names.
	 */
	public static function supported_post_types() {
		$types = array();

		foreach ( get_post_types( array( 'show_in_rest' => true ), 'names' ) as $type ) {
			if ( post_type_supports( $type, 'editor' ) && post_type_supports( $type, 'thumbnail' ) ) {
				$types[] = $type;
			}
		}

		/**
		 * Filter which post types get the Illustrate panel.
		 *
		 * @param array $types Post type names.
		 */
		return (array) apply_filters( 'launchy_illustrator_editor_post_types', $types );
	}

	/**
	 * Whether the current admin screen should get the panel.
	 *
	 * `enqueue_block_editor_assets` also fires for the site editor and the
	 * widget editor, neither of which has a post to illustrate.
	 *
	 * @return bool
	 */
	private function is_supported_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return $screen
			&& method_exists( $screen, 'is_block_editor' )
			&& $screen->is_block_editor()
			&& $screen->post_type
			&& in_array( $screen->post_type, self::supported_post_types(), true );
	}

	/**
	 * Register and enqueue the editor assets.
	 */
	public function enqueue() {
		if ( ! $this->is_supported_screen() ) {
			return;
		}

		// Generation writes to the media library. Without that capability the
		// REST layer would refuse every call, so show nothing rather than a
		// panel that can only fail.
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$base = LAUNCHY_ILLUSTRATOR_URL . 'admin/assets/editor/';
		$ver  = LAUNCHY_ILLUSTRATOR_VERSION;

		wp_enqueue_style( self::HANDLE, $base . 'editor.css', array( 'wp-components' ), $ver );

		// Classic scripts with an explicit dependency graph: script modules
		// cannot depend on classic scripts, and every wp.* global these need is
		// a classic script.
		foreach ( $this->scripts() as $handle => $script ) {
			wp_register_script( $handle, $base . $script['file'], $script['deps'], $ver, true );
			wp_set_script_translations( $handle, 'launchy-illustrator' );
		}

		wp_add_inline_script(
			'launchy-editor-insert',
			'window.launchyIllustrator = ' . wp_json_encode( $this->bootstrap() ) . ';',
			'before'
		);

		// Enqueuing the top of the graph pulls the rest in dependency order.
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * The script graph, in dependency order.
	 *
	 * @return array handle => {file, deps}.
	 */
	private function scripts() {
		return array(
			'launchy-editor-insert' => array(
				'file' => 'insert.js',
				'deps' => array( 'wp-blocks', 'wp-data', 'wp-i18n' ),
			),
			'launchy-editor-store'  => array(
				'file' => 'run-store.js',
				'deps' => array( 'wp-api-fetch', 'wp-data', 'wp-i18n', 'launchy-editor-insert' ),
			),
			'launchy-editor-cards'  => array(
				'file' => 'placement-cards.js',
				'deps' => array( 'wp-element', 'wp-components', 'wp-i18n', 'launchy-editor-store' ),
			),
			'launchy-editor-modal'  => array(
				'file' => 'review-modal.js',
				'deps' => array( 'wp-element', 'wp-components', 'wp-i18n', 'launchy-editor-store' ),
			),
			self::HANDLE            => array(
				'file' => 'index.js',
				'deps' => array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-i18n', 'wp-notices', 'launchy-editor-cards', 'launchy-editor-modal' ),
			),
		);
	}

	/**
	 * Configuration the scripts need at boot.
	 *
	 * Never includes the API key — only whether one is configured.
	 *
	 * @return array
	 */
	private function bootstrap() {
		$custom = '' !== trim( (string) Settings::get( 'style_prompt' ) );

		$media_types = array();
		foreach ( Plugin::instance()->media_types->all() as $slug => $type ) {
			$media_types[ $slug ] = $type->get_label();
		}

		return array(
			'namespace'     => REST_Controller::NS,
			'hasApiKey'     => (bool) Settings::api_key(),
			'styleLabel'    => $custom ? __( 'Custom style prompt', 'launchy-illustrator' ) : Style_Presets::current()['label'],
			'settingsUrl'   => admin_url( 'options-general.php?page=launchy-illustrator-settings' ),
			'heroInContent' => (bool) Settings::get( 'hero_in_content' ),
			'mediaTypes'    => $media_types,
		);
	}
}
