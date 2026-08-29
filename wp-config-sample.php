<?php
/**
 * Launchy Illustrator — optional wp-config.php constants.
 *
 * Copy whichever of these you want into your site's wp-config.php, ABOVE the
 * line that says "That's all, stop editing! Happy publishing." None of these
 * are required — everything can also be set on the Settings → Launchy
 * Illustrator screen — but defining the API key here keeps it out of the
 * database and out of version control.
 *
 * @package Launchy_Illustrator
 */

// Your Google Gemini API key. Preferred over the stored setting.
// Get one at https://aistudio.google.com/apikey
define( 'LAUNCHY_ILLUSTRATOR_GEMINI_API_KEY', 'YOUR_GEMINI_API_KEY_HERE' );

// The text and image models are set on the Settings → Launchy Illustrator
// screen (defaults: gemini-2.5-flash for planning, gemini-2.5-flash-image
// "Nano Banana" for images).
