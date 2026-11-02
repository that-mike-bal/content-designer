<?php
/**
 * Plugin Name:       Content Designer
 * Plugin URI:        https://mikebal.com
 * Description:       An AI media director for your drafts. Reads a post like an editor, plans where a visual genuinely earns its place, generates it in your own configured style with Google Gemini, and places it — never publishing. Built on the WordPress Abilities API, AI Client, and Interactivity API.
 * Version:           2.0.0
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Author:            Mike Bal
 * Author URI:        https://mikebal.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       content-designer
 */

defined( 'ABSPATH' ) || exit;

define( 'CONTENT_DESIGNER_VERSION', '2.0.0' );
define( 'CONTENT_DESIGNER_FILE', __FILE__ );
define( 'CONTENT_DESIGNER_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONTENT_DESIGNER_URL', plugin_dir_url( __FILE__ ) );

/**
 * PSR-4-ish autoloader for the Content_Designer namespace.
 *
 * Content_Designer\AI\Gemini_Http  -> includes/ai/class-gemini-http.php
 * Content_Designer\Admin\Page      -> admin/class-page.php
 */
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'Content_Designer\\' ) ) {
			return;
		}

		$parts = explode( '\\', substr( $class, strlen( 'Content_Designer\\' ) ) );
		$file  = 'class-' . str_replace( '_', '-', strtolower( array_pop( $parts ) ) ) . '.php';
		$sub   = strtolower( str_replace( '_', '-', implode( '/', $parts ) ) );
		$base  = ( 0 === strpos( $sub, 'admin' ) ) ? '' : 'includes/';
		$path  = CONTENT_DESIGNER_DIR . $base . ( $sub ? $sub . '/' : '' ) . $file;

		if ( file_exists( $path ) ) {
			require $path;
		}
	}
);

// Optional Composer autoloader (brings in wordpress/wp-ai-client when installed).
if ( file_exists( CONTENT_DESIGNER_DIR . 'vendor/autoload.php' ) ) {
	require CONTENT_DESIGNER_DIR . 'vendor/autoload.php';
}

add_action( 'plugins_loaded', array( 'Content_Designer\\Plugin', 'instance' ) );
