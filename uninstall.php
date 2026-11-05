<?php
/**
 * Uninstall: remove plugin options, planning meta, and cached reference
 * downloads. Generated attachments and any media already placed in posts are
 * intentionally left in place — they belong to the user's content.
 *
 * Both the current names and the pre-2.0 ones are cleaned up, so uninstalling
 * after an upgrade does not strand the old rows in the database.
 *
 * @package Content_Designer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$options = array(
	'content_designer_settings',
	'content_designer_user_styles',
	'content_designer_db_version',
	// Pre-2.0, when the plugin was called Launchy Illustrator.
	'launchy_illustrator_settings',
	'launchy_illustrator_user_styles',
	'launchy_illustrator_db_version',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Clear cached reference images.
$uploads = wp_upload_dir();

foreach ( array( 'content-designer', 'launchy-illustrator' ) as $cache_dir ) {
	$dir = trailingslashit( $uploads['basedir'] ) . $cache_dir;
	if ( ! is_dir( $dir ) ) {
		continue;
	}

	$files = glob( $dir . '/refs/*' );
	if ( $files ) {
		array_map( 'unlink', $files );
	}
	@rmdir( $dir . '/refs' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	@rmdir( $dir );           // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

// Remove leftover planning and provenance meta.
foreach ( array( '_content_designer_plan', '_content_designer_generated', '_launchy_plan', '_launchy_generated' ) as $meta_key ) {
	delete_post_meta_by_key( $meta_key );
}
