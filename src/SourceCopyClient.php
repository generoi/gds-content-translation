<?php

namespace GeneroWP\ContentTranslation;

use PLL_Language;
use Translations;
use WP_Error;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Clients\Client_Interface;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Machine translation client which hands back the source strings untouched.
 *
 * Lets a translation be created as a verbatim copy of the source language while
 * still running through Polylang Pro's translation pipeline, so blocks, post IDs,
 * internal links, terms and metas are remapped exactly as they are for a real
 * machine translation.
 */
class SourceCopyClient implements Client_Interface
{
    public function translate(Translations $translations, PLL_Language $target_language, $source_language = null)
    {
        foreach ($translations->entries as $entry) {
            $entry->translations = [$entry->singular];
        }

        return $translations;
    }

    public function is_api_key_valid(): WP_Error
    {
        return new WP_Error;
    }

    public function get_usage()
    {
        return [];
    }
}
