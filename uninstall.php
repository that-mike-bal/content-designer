<?php
/**
 * Uninstall: remove plugin options and cached reference downloads.
 * Generated attachments and any media already placed in posts are intentionally
 * left in place — they belong to the user's content.
 *
 * @package Launchy_Illustrator
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'launchy_illustrator_settings' );

// Clear cached reference images.
$uploads = wp_upload_dir();
$dir     = trailingslashit( $uploads['basedir'] ) . 'launchy-illustrator';
if ( is_dir( $dir ) ) {
	$files = glob( $dir . '/refs/*' );
	if ( $files ) {
		array_map( 'unlink', $files );
	}
	@rmdir( $dir . '/refs' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	@rmdir( $dir );           // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

// Remove leftover planning meta.
delete_post_meta_by_key( '_launchy_plan' );
