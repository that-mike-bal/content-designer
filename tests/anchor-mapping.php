<?php
/**
 * Anchor mapping fixture check.
 *
 * The plugin has no PHPUnit harness, and the anchor contract is the one piece
 * of pure logic where a silent error misplaces every image. This script
 * exercises it against a real WordPress runtime with no new dependencies:
 *
 *     studio wp eval-file wp-content/plugins/launchy-illustrator/tests/anchor-mapping.php
 *
 * Prints one line per assertion and exits non-zero on any failure.
 *
 * @package Launchy_Illustrator
 */

// This ships with the plugin (deployments copy the repo verbatim), so it must
// never be reachable over HTTP. Under `wp eval-file` ABSPATH is already defined.
defined( 'ABSPATH' ) || exit;

use Launchy_Illustrator\Draft_Scanner;
use Launchy_Illustrator\REST_Controller;

// Counters live in $GLOBALS rather than file scope: WP-CLI's eval-file includes
// this file inside a function, so its top-level variables are NOT globals and a
// `global $failures` in the helper below would silently address a different
// variable — reporting every run as green regardless of failures.
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

$block = function ( $name, $inner ) {
	return "<!-- wp:{$name} -->\n{$inner}\n<!-- /wp:{$name} -->";
};

$scanner = new Draft_Scanner();

// ---------------------------------------------------------------------------
// Block content: paragraph / heading / paragraph.
// ---------------------------------------------------------------------------
$content = implode(
	"\n\n",
	array(
		$block( 'paragraph', '<p>One</p>' ),
		$block( 'heading', '<h2>Two</h2>' ),
		$block( 'paragraph', '<p>Three</p>' ),
	)
);

$post = new WP_Post( (object) array( 'post_content' => $content, 'ID' => 0 ) );
$scan = $scanner->scan( $post );

launchy_is( 'three authored blocks are kept', 3, count( $scan['outline'] ) );
launchy_is( 'raw parse_blocks indexes skip the whitespace blocks', array( 0, 2, 4 ), wp_list_pluck( $scan['outline'], 'index' ) );
launchy_is( 'ordinals are contiguous and editor-shaped', array( 0, 1, 2 ), wp_list_pluck( $scan['outline'], 'ordinal' ) );
launchy_is( 'parse_blocks really does emit more entries than the editor shows', 5, count( parse_blocks( $content ) ) );

launchy_is( 'anchor 0 (hero, top of post) resolves to ordinal 0', 0, Draft_Scanner::anchor_ordinal( $scan, 0 ) );
launchy_is( 'anchor 2 (the heading) resolves to ordinal 1', 1, Draft_Scanner::anchor_ordinal( $scan, 2 ) );
launchy_is( 'anchor 1 (a skipped whitespace block) resolves forward to ordinal 1', 1, Draft_Scanner::anchor_ordinal( $scan, 1 ) );
launchy_is( 'anchor 4 (last block) resolves to ordinal 2', 2, Draft_Scanner::anchor_ordinal( $scan, 4 ) );
launchy_is( 'anchor past the end means append', 3, Draft_Scanner::anchor_ordinal( $scan, 99 ) );

// ---------------------------------------------------------------------------
// Labels describe where the image actually lands, and never a block number.
// ---------------------------------------------------------------------------
launchy_is( 'anchor 0 reads as the top of the post', 'Top of post', Draft_Scanner::anchor_label( $scan, 0 ) );
launchy_is( 'an anchor on a heading names that heading', 'Before “Two”', Draft_Scanner::anchor_label( $scan, 2 ) );
launchy_is(
	'an anchor on a skipped whitespace block still names the heading it precedes',
	'Before “Two”',
	Draft_Scanner::anchor_label( $scan, 1 )
);
launchy_is(
	'a paragraph inside a section names the section',
	'In “Two”',
	Draft_Scanner::anchor_label( $scan, 4 )
);
launchy_is( 'an anchor past the end reads as the end', 'At the end of the post', Draft_Scanner::anchor_label( $scan, 99 ) );
launchy_is(
	'no label mentions a block number',
	false,
	(bool) preg_match( '/block \\d/i', implode( ' ', array_map( static function ( $i ) use ( $scan ) {
		return Draft_Scanner::anchor_label( $scan, $i );
	}, range( 0, 6 ) ) ) )
);

// A textless block before any heading has nothing to quote.
$img      = "<!-- wp:paragraph -->\n<p>Intro.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"x.png\" alt=\"\"/></figure>\n<!-- /wp:image -->";
$img_post = new WP_Post( (object) array( 'post_content' => $img, 'ID' => 0 ) );
$img_scan = $scanner->scan( $img_post );

launchy_is( 'a textless block is named, not quoted empty', 'Before the image', Draft_Scanner::anchor_label( $img_scan, 1 ) );
launchy_is( 'no label is an empty pair of quotes', false, false !== strpos( Draft_Scanner::anchor_label( $img_scan, 1 ), '“”' ) );

// A paragraph before any heading is described by its opening words.
$lead      = "<!-- wp:paragraph -->\n<p>Opening paragraph.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Second paragraph carries the anchor and runs on well past the trim point.</p>\n<!-- /wp:paragraph -->";
$lead_post = new WP_Post( (object) array( 'post_content' => $lead, 'ID' => 0 ) );
$lead_scan = $scanner->scan( $lead_post );

launchy_is(
	'a paragraph with no preceding heading is named by its opening words',
	'Before “Second paragraph carries the anchor and…”',
	Draft_Scanner::anchor_label( $lead_scan, 2 )
);

// ---------------------------------------------------------------------------
// A leading non-empty classic chunk is kept and owns ordinal 0.
// ---------------------------------------------------------------------------
$mixed      = "<p>Legacy intro paragraph.</p>\n\n" . $block( 'heading', '<h2>Section</h2>' );
$mixed_post = new WP_Post( (object) array( 'post_content' => $mixed, 'ID' => 0 ) );
$mixed_scan = $scanner->scan( $mixed_post );

launchy_is( 'a non-empty freeform chunk is kept, not skipped', 2, count( $mixed_scan['outline'] ) );
launchy_is( 'the freeform chunk owns ordinal 0', 0, $mixed_scan['outline'][0]['ordinal'] );
launchy_is( 'its type is reported as classic', 'classic', $mixed_scan['outline'][0]['type'] );

// ---------------------------------------------------------------------------
// Classic (non-block) content: index and ordinal agree.
// ---------------------------------------------------------------------------
$classic      = "<p>One</p>\n\n<h2>Two</h2>\n\n<p>Three</p>";
$classic_post = new WP_Post( (object) array( 'post_content' => $classic, 'ID' => 0 ) );
$classic_scan = $scanner->scan( $classic_post );

launchy_is( 'classic content is not treated as blocks', false, $classic_scan['is_blocks'] );
launchy_is(
	'classic index and ordinal agree',
	wp_list_pluck( $classic_scan['outline'], 'index' ),
	wp_list_pluck( $classic_scan['outline'], 'ordinal' )
);
launchy_is( 'classic anchor resolves', 1, Draft_Scanner::anchor_ordinal( $classic_scan, 1 ) );

// ---------------------------------------------------------------------------
// Empty content.
// ---------------------------------------------------------------------------
$empty_post = new WP_Post( (object) array( 'post_content' => '', 'ID' => 0 ) );
$empty_scan = $scanner->scan( $empty_post );

launchy_is( 'empty content yields an empty outline', 0, count( $empty_scan['outline'] ) );
launchy_is( 'anchor resolution on an empty outline is 0, not a warning', 0, Draft_Scanner::anchor_ordinal( $empty_scan, 3 ) );

// ---------------------------------------------------------------------------
// Round trip through the REST sanitizer.
// ---------------------------------------------------------------------------
$sanitize = new ReflectionMethod( REST_Controller::class, 'sanitize_placement' );
$sanitize->setAccessible( true );
$controller = new REST_Controller();

$round = $sanitize->invoke(
	$controller,
	array(
		'id'             => 'p1',
		'media_type'     => 'illustration',
		'role'           => 'concept',
		'anchor_index'   => 4,
		'anchor_ordinal' => 2,
		'aspect_ratio'   => '4:3',
		'scene'          => 'A test scene.',
	)
);

launchy_is( 'sanitize_placement preserves anchor_ordinal', 2, $round['anchor_ordinal'] );
launchy_is( 'sanitize_placement preserves anchor_index', 4, $round['anchor_index'] );

$legacy = $sanitize->invoke(
	$controller,
	array(
		'media_type'   => 'illustration',
		'role'         => 'concept',
		'anchor_index' => 3,
		'scene'        => 'A legacy placement with no ordinal.',
	)
);

launchy_is( 'a legacy placement falls back to anchor_index', 3, $legacy['anchor_ordinal'] );

// ---------------------------------------------------------------------------
printf( "\n%d checks, %d failure(s)\n", $GLOBALS['launchy_checks'], $GLOBALS['launchy_failures'] );
exit( $GLOBALS['launchy_failures'] ? 1 : 0 );
