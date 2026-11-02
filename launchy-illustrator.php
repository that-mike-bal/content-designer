<?php
/**
 * Plugin Name:       Launchy Illustrator
 * Plugin URI:        https://mikebal.com
 * Description:       AI media director for Leading Product. Scans a draft, plans ideal media placements, generates on-brand Launchy illustrations with Gemini ("Nano Banana"), and places them into the post. Built on the WordPress Abilities API, AI Client, and Interactivity API.
 * Version:           1.1.1
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Author:            Mike Bal
 * Author URI:        https://mikebal.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       launchy-illustrator
 */

defined( 'ABSPATH' ) || exit;

define( 'LAUNCHY_ILLUSTRATOR_VERSION', '1.1.1' );
define( 'LAUNCHY_ILLUSTRATOR_FILE', __FILE__ );
define( 'LAUNCHY_ILLUSTRATOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'LAUNCHY_ILLUSTRATOR_URL', plugin_dir_url( __FILE__ ) );

/**
 * PSR-4-ish autoloader for the Launchy_Illustrator namespace.
 *
 * Launchy_Illustrator\AI\Gemini_Http  -> includes/ai/class-gemini-http.php
 * Launchy_Illustrator\Admin\Page      -> admin/class-page.php
 */
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'Launchy_Illustrator\\' ) ) {
			return;
		}

		$parts = explode( '\\', substr( $class, strlen( 'Launchy_Illustrator\\' ) ) );
		$file  = 'class-' . str_replace( '_', '-', strtolower( array_pop( $parts ) ) ) . '.php';
		$sub   = strtolower( str_replace( '_', '-', implode( '/', $parts ) ) );
		$base  = ( 0 === strpos( $sub, 'admin' ) ) ? '' : 'includes/';
		$path  = LAUNCHY_ILLUSTRATOR_DIR . $base . ( $sub ? $sub . '/' : '' ) . $file;

		if ( file_exists( $path ) ) {
			require $path;
		}
	}
);

// Optional Composer autoloader (brings in wordpress/wp-ai-client when installed).
if ( file_exists( LAUNCHY_ILLUSTRATOR_DIR . 'vendor/autoload.php' ) ) {
	require LAUNCHY_ILLUSTRATOR_DIR . 'vendor/autoload.php';
}

add_action( 'plugins_loaded', array( 'Launchy_Illustrator\\Plugin', 'instance' ) );
