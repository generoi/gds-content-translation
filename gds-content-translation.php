<?php

/*
Plugin Name:  GDS Content Translation
Plugin URI:   https://genero.fi
Description:  Polylang translation workflow: block attribute rules, internal link remapping, translation status admin, and machine translation helpers.
Version:      1.1.0
Requires at least: 6.2
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

define('GDS_CONTENT_TRANSLATION_VERSION', '1.1.0');
define('GDS_CONTENT_TRANSLATION_FILE', __FILE__);
define('GDS_CONTENT_TRANSLATION_PATH', __DIR__);
define('GDS_CONTENT_TRANSLATION_META_KEY', '_content_translation_status_proofread');

if (file_exists($composer = __DIR__.'/vendor/autoload.php')) {
    require_once $composer;
}

// Installed without its own vendor/ and outside a site-wide composer install
// (a source zip, say): load the classes from src/ directly rather than fatal.
if (! class_exists(Plugin::class)) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'GeneroWP\\ContentTranslation\\';

        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

        if (is_file($file)) {
            require_once $file;
        }
    });
}

Plugin::getInstance();
