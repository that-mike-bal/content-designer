<?php
/**
 * REST surface checks for /outline and content-aware scanning.
 *
 *     studio wp eval-file wp-content/plugins/content-designer/tests/rest-outline.php
 *
 * Exercises everything that does NOT cost an AI call. The editorial pass in
 * /scan is deliberately not invoked here — it bills per run.
 *
 * @package Content_Designer
 */

// Ships with the plugin (deployments copy the repo verbatim), so it must never
// be reachable over HTTP. Under `wp eval-file` ABSPATH is already defined.
defined( 'ABSPATH' ) || exit;

use Content_Designer\Placement_Planner;

$GLOBALS['content_designer_failures'] = 0;
$GLOBALS['content_designer_checks']   = 0;

/**
 * Assert equality and report.
 *
 * @param string $label    What is being checked.
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 */
function content_designer_is( $label, $expected, $actual ) {
	$GLOBALS['content_designer_checks'] ++;
	$ok = ( $expected === $actual );
	if ( ! $ok ) {
		$GLOBALS['content_designer_failures'] ++;
	}
	printf(
		"%s %s%s\n",
		$ok ? 'PASS' : 'FAIL',
		$label,
		$ok ? '' : sprintf( "\n       expected: %s\n       actual:   %s", var_export( $expected, true ), var_export( $actual, true ) )
	);
}

/**
 * Fire a content-designer/v1 request and return the response.
 *
 * @param string $route Route under the namespace.
 * @param array  $body  Body params.
 * @return WP_REST_Response
 */
function content_designer_post( $route, array $body ) {
	$request = new WP_REST_Request( 'POST', '/content-designer/v1' . $route );
	foreach ( $body as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
}

rest_get_server();

$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admin[0] );

$stored = implode(
	"\n\n",
	array(
		"<!-- wp:paragraph -->\n<p>Stored intro.</p>\n<!-- /wp:paragraph -->",
		"<!-- wp:heading -->\n<h2>Stored Heading</h2>\n<!-- /wp:heading -->",
	)
);

$post_id = wp_insert_post(
	array(
		'post_title'   => 'Content Designer outline fixture',
		'post_status'  => 'draft',
		'post_content' => $stored,
	)
);

// ---------------------------------------------------------------------------
// No content param: reads the stored post.
// ---------------------------------------------------------------------------
$res  = content_designer_post( '/outline', array( 'post_id' => $post_id ) );
$data = $res->get_data();

content_designer_is( '/outline succeeds on a stored draft', 200, $res->get_status() );
content_designer_is( 'stored outline has both blocks', 2, $data['block_count'] );
content_designer_is( 'stored heading is reported', array( 'Stored Heading' ), $data['sections'] );
content_designer_is( 'outline entries carry ordinals', array( 0, 1 ), wp_list_pluck( $data['outline'], 'ordinal' ) );

// ---------------------------------------------------------------------------
// With content: reflects what was sent, and does not touch the stored post.
// ---------------------------------------------------------------------------
$edited = implode(
	"\n\n",
	array(
		"<!-- wp:paragraph -->\n<p>Unsaved intro.</p>\n<!-- /wp:paragraph -->",
		"<!-- wp:heading -->\n<h2>Unsaved Heading</h2>\n<!-- /wp:heading -->",
		"<!-- wp:paragraph -->\n<p>A third block that only exists in the editor.</p>\n<!-- /wp:paragraph -->",
	)
);

$res  = content_designer_post( '/outline', array( 'post_id' => $post_id, 'content' => $edited ) );
$data = $res->get_data();

content_designer_is( '/outline accepts submitted content', 200, $res->get_status() );
content_designer_is( 'submitted content drives the outline', 3, $data['block_count'] );
content_designer_is( 'submitted heading is reported', array( 'Unsaved Heading' ), $data['sections'] );
content_designer_is( 'block delimiters survive — content parsed as blocks, not stripped', 'core/heading', $data['outline'][1]['type'] );
content_designer_is( 'the stored post is untouched', $stored, get_post( $post_id )->post_content );

// ---------------------------------------------------------------------------
// /outline runs no editorial pass — the planner is what writes PLAN_META.
// ---------------------------------------------------------------------------
content_designer_is( 'no plan meta written by /outline', '', get_post_meta( $post_id, Placement_Planner::PLAN_META, true ) );

// ---------------------------------------------------------------------------
// Guards.
// ---------------------------------------------------------------------------
$res = content_designer_post( '/outline', array( 'post_id' => $post_id, 'content' => str_repeat( 'x', 2097153 ) ) );
content_designer_is( 'oversized content is refused', 413, $res->get_status() );
content_designer_is( 'oversized content names the reason', 'content_designer_content_too_large', $res->get_data()['code'] );

$res = content_designer_post( '/outline', array( 'post_id' => $post_id, 'content' => '   ' ) );
content_designer_is( 'empty content is refused, not silently empty', 400, $res->get_status() );
content_designer_is( 'empty content names the reason', 'content_designer_empty_post', $res->get_data()['code'] );

// 403 rather than 404: the permission callback runs first and cannot grant
// edit_post on a post that does not exist. Refusing without confirming absence
// is the better answer anyway — a 404 here would be an existence oracle.
$res = content_designer_post( '/outline', array( 'post_id' => 99999999 ) );
content_designer_is( 'a missing post is refused without confirming it is missing', 403, $res->get_status() );

// ---------------------------------------------------------------------------
// Permissions.
// ---------------------------------------------------------------------------
$subscriber = wp_insert_user(
	array(
		'user_login' => 'content_designer_fixture_sub_' . wp_rand( 1000, 9999 ),
		'user_pass'  => wp_generate_password(),
		'role'       => 'subscriber',
	)
);
wp_set_current_user( $subscriber );

$res = content_designer_post( '/outline', array( 'post_id' => $post_id ) );
content_designer_is( 'a subscriber cannot outline someone else\'s draft', 403, $res->get_status() );

$res = content_designer_post( '/scan', array( 'post_id' => $post_id ) );
content_designer_is( 'a subscriber cannot scan either', 403, $res->get_status() );

// ---------------------------------------------------------------------------
wp_set_current_user( (int) $admin[0] );
wp_delete_post( $post_id, true );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $subscriber );

printf( "\n%d checks, %d failure(s)\n", $GLOBALS['content_designer_checks'], $GLOBALS['content_designer_failures'] );
exit( $GLOBALS['content_designer_failures'] ? 1 : 0 );
