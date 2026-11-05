<?php
/**
 * Content Designer — optional wp-config.php constants.
 *
 * Copy whichever of these you want into your site's wp-config.php, ABOVE the
 * line that says "That's all, stop editing! Happy publishing." None of these
 * are required — everything can also be set on the Settings → Content Designer
 * screen — but defining the API key here keeps it out of the database, and
 * therefore out of every backup and database export.
 *
 * @package Content_Designer
 */

// Your own Google Gemini API key. Preferred over the stored setting.
// Get one at https://aistudio.google.com/apikey
define( 'CONTENT_DESIGNER_GEMINI_API_KEY', 'YOUR_GEMINI_API_KEY_HERE' );

// A plain GEMINI_API_KEY constant (or environment variable) is also honored,
// which is handy when other tooling on the host already defines one.

// The text and image models are set on the Settings → Content Designer screen
// (defaults: gemini-3.8-flash for planning, gemini-nano-banana-2.1
// "Nano Banana 2.1" for images).
