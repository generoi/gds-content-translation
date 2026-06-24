<?php

/*
Plugin Name:  GDS Content Translation
Plugin URI:   https://genero.fi
Description:  Polylang translation workflow: block attribute rules, internal link remapping, translation status admin, and machine translation helpers.
Version:      1.0.0
Requires at least: 6.0
Requires PHP: 8.0
Author:       Genero
Author URI:   https://genero.fi/
License:      MIT License
License URI:  http://opensource.org/licenses/MIT
Text Domain:  gds-content-translation
*/

use GeneroWP\ContentTranslation\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

define('GDS_CONTENT_TRANSLATION_VERSION', '1.0.0');
define('GDS_CONTENT_TRANSLATION_FILE', __FILE__);
define('GDS_CONTENT_TRANSLATION_PATH', __DIR__);
define('GDS_CONTENT_TRANSLATION_META_KEY', '_content_translation_status_proofread');

if (file_exists($composer = __DIR__.'/vendor/autoload.php')) {
    require_once $composer;
}

Plugin::getInstance();
