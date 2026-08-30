<?php
/**
 * Guard checks for /discard.
 *
 *     studio wp eval-file wp-content/plugins/launchy-illustrator/tests/rest-discard.php
 *
 * This route deletes files. The checks that matter are the ones proving it
 * refuses to delete anything it did not create.
 *
 * @package Launchy_Illustrator
 */

// Ships with the plugin (deployments copy the repo verbatim), so it must never
// be reachable over HTTP. Under `wp eval-file` ABSPATH is already defined.
defined( 'ABSPATH' ) || exit;

use Launchy_Illustrator\Media_Manager;
use Launchy_Illustrator\Placement_Planner;
use Launchy_Illustrator\Settings;

$GLOBALS['launchy_failures'] = 0;
$GLOBALS['launchy_checks']   = 0;

/**
 * Assert equality and report.
 *
 * @param string $label    What is being checked.
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 */
function launchy_is( $label, $expected, $actual ) {
	$GLOBALS['launchy_checks'] ++;
	$ok = ( $expected === $actual );
	if ( ! $ok ) {
		$GLOBALS['launchy_failures'] ++;
	}
	printf(
		"%s %s%s\n",
		$ok ? 'PASS' : 'FAIL',
		$label,
		$ok ? '' : sprintf( "\n       expected: %s\n       actual:   %s", var_export( $expected, true ), var_export( $actual, true ) )
	);
}

/**
 * Fire a launchy/v1 request.
 *
 * @param string $route Route under the namespace.
 * @param array  $body  Body params.
 * @return WP_REST_Response
 */
function launchy_post( $route, array $body ) {
	$request = new WP_REST_Request( 'POST', '/launchy/v1' . $route );
	foreach ( $body as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
}

/**
 * A stand-in generated attachment, with or without our provenance meta.
 *
 * @param int  $parent   Parent post id.
 * @param bool $ours     Whether to stamp the provenance meta.
 * @param int  $meta_for Post id recorded in the provenance meta.
 * @return int Attachment id.
 */
function launchy_fake_attachment( $parent, $ours = true, $meta_for = null ) {
	$id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'Launchy fixture attachment',
			'post_status'    => 'inherit',
		),
		null,
		$parent
	);
	if ( $ours ) {
		update_post_meta(
			$id,
			Media_Manager::GENERATED_META,
			array(
				'post_id'      => null === $meta_for ? $parent : $meta_for,
				'placement_id' => 'p1',
				'role'         => 'concept',
				'variation'    => 1,
			)
		);
	}
	return $id;
}

rest_get_server();

$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admin[0] );

$post_id  = wp_insert_post( array( 'post_title' => 'Launchy discard fixture', 'post_status' => 'draft', 'post_content' => 'x' ) );
$other_id = wp_insert_post( array( 'post_title' => 'Launchy other fixture', 'post_status' => 'draft', 'post_content' => 'y' ) );

// ---------------------------------------------------------------------------
// Deletes what it generated for this post.
// ---------------------------------------------------------------------------
$mine = launchy_fake_attachment( $post_id );
$res  = launchy_post( '/discard', array( 'post_id' => $post_id, 'ids' => array( $mine ) ) );

launchy_is( '/discard route exists', 200, $res->get_status() );
launchy_is( 'reports one discarded', 1, $res->get_data()['discarded'] );
launchy_is( 'the attachment is gone', null, get_post( $mine ) );

// ---------------------------------------------------------------------------
// Refuses everything it did not generate for this post.
// ---------------------------------------------------------------------------
$foreign = launchy_fake_attachment( $other_id );
$res     = launchy_post( '/discard', array( 'post_id' => $post_id, 'ids' => array( $foreign ) ) );

launchy_is( 'an attachment generated for another post is not deleted', 0, $res->get_data()['discarded'] );
launchy_is( 'that attachment still exists', $foreign, (int) get_post( $foreign )->ID );

$stranger = launchy_fake_attachment( $post_id, false );
$res      = launchy_post( '/discard', array( 'post_id' => $post_id, 'ids' => array( $stranger ) ) );

launchy_is( 'an ordinary media upload is never deleted', 0, $res->get_data()['discarded'] );
launchy_is( 'that upload still exists', $stranger, (int) get_post( $stranger )->ID );

// Provenance meta claiming a different post than the request.
$spoofed = launchy_fake_attachment( $post_id, true, $other_id );
$res     = launchy_post( '/discard', array( 'post_id' => $post_id, 'ids' => array( $spoofed ) ) );

launchy_is( 'provenance pointing at another post is not honoured', 0, $res->get_data()['discarded'] );
launchy_is( 'that attachment still exists', $spoofed, (int) get_post( $spoofed )->ID );

// ---------------------------------------------------------------------------
// Junk input.
// ---------------------------------------------------------------------------
$res = launchy_post( '/discard', array( 'post_id' => $post_id, 'ids' => array( 99999999, 'not-a-number', 0, -5 ) ) );
launchy_is( 'junk ids are skipped without error', 200, $res->get_status() );
launchy_is( 'nothing was discarded from junk', 0, $res->get_data()['discarded'] );

// ---------------------------------------------------------------------------
// clear_plan.
// ---------------------------------------------------------------------------
update_post_meta( $post_id, Placement_Planner::PLAN_META, array( array( 'id' => 'p1' ) ) );

$res = launchy_post( '/discard', array( 'post_id' => $post_id, 'ids' => array() ) );
launchy_is( 'an empty run succeeds', 200, $res->get_status() );
launchy_is( 'plan meta survives without clear_plan', 1, count( get_post_meta( $post_id, Placement_Planner::PLAN_META, true ) ) );

$res = launchy_post( '/discard', array( 'post_id' => $post_id, 'ids' => array(), 'clear_plan' => true ) );
launchy_is( 'clear_plan removes the stored plan', '', get_post_meta( $post_id, Placement_Planner::PLAN_META, true ) );

// ---------------------------------------------------------------------------
// cleanup_variants off: keep everything.
// ---------------------------------------------------------------------------
$settings = (array) get_option( Settings::OPTION, array() );
update_option( Settings::OPTION, array_merge( $settings, array( 'cleanup_variants' => false ) ) );

$kept = launchy_fake_attachment( $post_id );
$res  = launchy_post( '/discard', array( 'post_id' => $post_id, 'ids' => array( $kept ) ) );

launchy_is( 'with cleanup off nothing is deleted', 0, $res->get_data()['discarded'] );
launchy_is( 'the attachment survives cleanup being off', $kept, (int) get_post( $kept )->ID );

update_option( Settings::OPTION, $settings );

// ---------------------------------------------------------------------------
// Permissions.
// ---------------------------------------------------------------------------
$subscriber = wp_insert_user(
	array(
		'user_login' => 'launchy_discard_sub_' . wp_rand( 1000, 9999 ),
		'user_pass'  => wp_generate_password(),
		'role'       => 'subscriber',
	)
);
$victim = launchy_fake_attachment( $post_id );
wp_set_current_user( $subscriber );

$res = launchy_post( '/discard', array( 'post_id' => $post_id, 'ids' => array( $victim ) ) );
launchy_is( 'a subscriber cannot discard', 403, $res->get_status() );
launchy_is( 'the attachment survives the attempt', $victim, (int) get_post( $victim )->ID );

// ---------------------------------------------------------------------------
wp_set_current_user( (int) $admin[0] );
foreach ( array( $foreign, $stranger, $spoofed, $kept, $victim ) as $id ) {
	wp_delete_attachment( $id, true );
}
wp_delete_post( $post_id, true );
wp_delete_post( $other_id, true );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $subscriber );

printf( "\n%d checks, %d failure(s)\n", $GLOBALS['launchy_checks'], $GLOBALS['launchy_failures'] );
exit( $GLOBALS['launchy_failures'] ? 1 : 0 );
