<?php
/**
 * Main plugin orchestrator.
 *
 * @package Launchy_Illustrator
 */

namespace Launchy_Illustrator;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Media type registry.
	 *
	 * @var Media_Types\Registry
	 */
	public $media_types;

	/**
	 * Get/boot the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook everything up.
	 */
	private function __construct() {
		$this->media_types = new Media_Types\Registry();

		// Not admin-only: user styles must reach the preset registry for REST
		// and ability calls too, not just the settings screen.
		new User_Styles();

		$abilities = new Abilities();

		add_action( 'init', array( $this, 'init' ) );
		add_action( 'rest_api_init', array( new REST_Controller(), 'register_routes' ) );
		// Categories register on an earlier, separate hook than the abilities.
		add_action( 'wp_abilities_api_categories_init', array( $abilities, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( $abilities, 'register' ) );

		if ( is_admin() ) {
			new Settings();
			new Admin\Page();
			new Admin\Editor_Panel();
			new Admin\Styles_UI();
			add_action( 'admin_init', array( $this, 'maybe_migrate' ) );
		}
	}

	/**
	 * Move legacy global overrides into a real, editable style.
	 *
	 * Before user styles existed the only way to customize the look was to fill
	 * in the three override fields, which silently replaced whatever preset was
	 * selected. Those fields are gone from the UI, so anyone who used them gets
	 * their art direction converted into a saved style instead of losing it.
	 */
	public function maybe_migrate() {
		if ( get_option( 'launchy_illustrator_db_version' ) ) {
			return;
		}

		User_Styles::migrate_legacy_overrides();
		update_option( 'launchy_illustrator_db_version', 2 );
	}

	/**
	 * Register built-in media types and translations.
	 */
	public function init() {
		load_plugin_textdomain( 'launchy-illustrator' );

		$this->media_types->register( new Media_Types\Illustration() );

		/**
		 * Register additional media types (charts, diagrams, video, ...).
		 *
		 * Each type extends Launchy_Illustrator\Media_Types\Media_Type and plugs
		 * into the same scan -> plan -> generate -> place pipeline.
		 *
		 * @param Media_Types\Registry $registry The media type registry.
		 */
		do_action( 'launchy_illustrator_register_media_types', $this->media_types );
	}
}
