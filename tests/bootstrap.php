<?php

/**
 * PHPUnit bootstrap file.
 */

// Composer autoloader must be loaded before WP_PHPUNIT__DIR is available.
require_once dirname(__DIR__).'/vendor/autoload.php';

// Give access to tests_add_filter() function.
require_once getenv('WP_PHPUNIT__DIR').'/includes/functions.php';

// Polylang needs PLL_ADMIN defined to initialize without pre-existing languages.
// Without this, Polylang skips init_context() and $GLOBALS['polylang'] is never set.
if (! defined('PLL_ADMIN')) {
    define('PLL_ADMIN', true);
}

/**
 * Polylang's directory name varies with how it was installed — `polylang` from
 * wordpress.org, `polylang-pro` for the licensed build, or the zip file name
 * when wp-env installs it from a URL.
 */
function gds_content_translation_locate_polylang(): ?string
{
    $pluginsDir = dirname(__DIR__, 2);

    $candidates = array_merge(
        glob($pluginsDir.'/polylang-pro*/polylang.php') ?: [],
        glob($pluginsDir.'/polylang*/polylang.php') ?: [],
    );

    return $candidates[0] ?? null;
}

/**
 * Load Polylang and this plugin in muplugins_loaded.
 *
 * wp-phpunit uses a fresh DB where no plugins are "activated".
 * We manually require plugin files so they initialize.
 */
tests_add_filter('muplugins_loaded', function () {
    if ($polylang = gds_content_translation_locate_polylang()) {
        require_once $polylang;
    }

    require dirname(__DIR__).'/gds-content-translation.php';
});

/**
 * Create the languages the test suite translates between.
 *
 * WP_UnitTestCase truncates every table after each test class, so this runs
 * again from PolylangTestCase for every test that needs languages.
 */
function gds_content_translation_configure_languages(): void
{
    if (! function_exists('PLL') || ! PLL() || ! isset(PLL()->model)) {
        return;
    }

    $model = PLL()->model;
    $model->clean_languages_cache();

    // Locale defaults (flag, text direction, ...) shipped with Polylang.
    $polylangDir = dirname((string) gds_content_translation_locate_polylang());
    $knownLanguages = [];

    foreach (['settings', 'src/settings', 'vendor/wpsyntex/polylang/settings'] as $directory) {
        $languagesFile = "{$polylangDir}/{$directory}/languages.php";

        if (file_exists($languagesFile)) {
            $knownLanguages = include $languagesFile;

            break;
        }
    }

    foreach ([
        ['name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'term_group' => 0],
        ['name' => 'Finnish', 'slug' => 'fi', 'locale' => 'fi', 'term_group' => 1],
    ] as $lang) {
        if ($model->get_language($lang['slug'])) {
            continue;
        }

        $defaults = $knownLanguages[$lang['locale']] ?? [];
        $model->add_language(array_merge($defaults, $lang));
    }

    $model->update_default_lang('en');
    $model->clean_languages_cache();
}

// Start up the WP testing environment.
require getenv('WP_PHPUNIT__DIR').'/includes/bootstrap.php';

gds_content_translation_configure_languages();
