<?php
/**
 * Main plugin orchestrator.
 *
 * @package Content_Designer
 */

namespace Content_Designer;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together.
 */
final class Plugin {

	/**
	 * Schema version of the data this build expects.
	 *
	 * 2 = user styles exist. 3 = options and post meta carry the plugin's
	 * post-rename names. 4 = stored model IDs point at models Google still
	 * serves.
	 *
	 * @var int
	 */
	const DB_VERSION = 4;

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
		}

		// Not admin-only: a REST or ability call can be the first thing that
		// touches a freshly renamed install, and it needs the data adopted too.
		add_action( 'init', array( $this, 'maybe_migrate' ), 1 );
	}

	/**
	 * Run whichever one-time migrations this install has not had yet.
	 *
	 * Three steps, in dependency order. Adopting the pre-2.0 data comes first
	 * because the other two read the settings row, which does not exist under
	 * its new name until that runs. Then legacy global overrides become a real,
	 * editable style — before user styles existed, the only way to customize the
	 * look was three override fields that silently replaced the selected preset,
	 * and those fields are gone from the UI. Last, stored model IDs that Google
	 * has retired are pointed at their replacements.
	 */
	public function maybe_migrate() {
		$version = (int) get_option( 'content_designer_db_version', 0 );

		if ( $version >= self::DB_VERSION ) {
			return;
		}

		// Has to come first: the steps below read and rewrite the settings row,
		// which on a pre-2.0 install does not exist under its new name yet.
		if ( 0 === $version ) {
			$version = self::adopt_pre_rename_data();
		}

		if ( $version < 2 ) {
			User_Styles::migrate_legacy_overrides();
		}

		if ( $version < 4 ) {
			Settings::migrate_retired_models();
		}

		update_option( 'content_designer_db_version', self::DB_VERSION );
	}

	/**
	 * Carry a pre-2.0 install over to the renamed options and meta keys.
	 *
	 * Up to 1.1.1 the plugin was called Launchy Illustrator and everything it
	 * stored was prefixed accordingly. The rename would otherwise read as a
	 * factory reset: settings gone, saved styles gone, generated attachments no
	 * longer recognized as cleanup candidates.
	 *
	 * The old rows are copied, not moved, so downgrading to 1.x still finds its
	 * own data. Post meta is renamed in place, because both versions look for
	 * exactly one key and a duplicate would double-count.
	 *
	 * @return int The schema version the old install had reached, or 0 when
	 *             there was nothing to adopt.
	 */
	private static function adopt_pre_rename_data() {
		global $wpdb;

		$legacy_version  = get_option( 'launchy_illustrator_db_version' );
		$legacy_settings = get_option( 'launchy_illustrator_settings' );
		$legacy_styles   = get_option( 'launchy_illustrator_user_styles' );

		if ( false === $legacy_version && false === $legacy_settings && false === $legacy_styles ) {
			return 0;
		}

		if ( false !== $legacy_settings && false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, $legacy_settings );
		}

		if ( false !== $legacy_styles && false === get_option( User_Styles::OPTION ) ) {
			add_option( User_Styles::OPTION, $legacy_styles );
		}

		$meta_keys = array(
			'_launchy_plan'      => Placement_Planner::PLAN_META,
			'_launchy_generated' => Media_Manager::GENERATED_META,
		);

		foreach ( $meta_keys as $old => $new ) {
			$wpdb->update( $wpdb->postmeta, array( 'meta_key' => $new ), array( 'meta_key' => $old ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		// A 1.x install that never ran its own migration still predates user
		// styles, so report 1 rather than 0 to keep that step queued.
		return max( 1, (int) $legacy_version );
	}

	/**
	 * Register built-in media types and translations.
	 */
	public function init() {
		load_plugin_textdomain( 'content-designer' );

		$this->media_types->register( new Media_Types\Illustration() );

		/**
		 * Register additional media types (charts, diagrams, video, ...).
		 *
		 * Each type extends Content_Designer\Media_Types\Media_Type and plugs
		 * into the same scan -> plan -> generate -> place pipeline.
		 *
		 * @param Media_Types\Registry $registry The media type registry.
		 */
		do_action( 'content_designer_register_media_types', $this->media_types );
	}
}
