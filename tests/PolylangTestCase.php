<?php

namespace GeneroWP\ContentTranslation\Tests;

use WP_UnitTestCase;

/**
 * Base test case for tests that need configured Polylang languages and translations.
 */
class PolylangTestCase extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pll_set_post_language') || ! function_exists('PLL')) {
            $this->markTestSkipped('Polylang is not active.');
        }

        gds_content_translation_configure_languages();

        // Language prefixes and post slugs only show up in permalinks when
        // pretty permalinks are on.
        $this->set_permalink_structure('/%postname%/');
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function createPost(string $languageSlug, array $args = []): int
    {
        $postId = (int) self::factory()->post->create($args);

        pll_set_post_language($postId, $languageSlug);

        return $postId;
    }

    /**
     * Create a post in the default language plus its translation.
     *
     * @param  array<string, mixed>  $args  Applied to both posts, with `post_name` suffixed per language.
     * @return array<string, int> Language slug => post ID.
     */
    protected function createTranslatedPost(array $args = []): array
    {
        $ids = [];

        foreach (['en', 'fi'] as $languageSlug) {
            $languageArgs = $args;

            if (isset($languageArgs['post_name'])) {
                $languageArgs['post_name'] = "{$languageArgs['post_name']}-{$languageSlug}";
            }

            $ids[$languageSlug] = $this->createPost($languageSlug, $languageArgs);
        }

        pll_save_post_translations($ids);

        return $ids;
    }

    protected function setCurrentLanguage(string $languageSlug): void
    {
        $language = PLL()->model->get_language($languageSlug);

        $this->assertNotFalse($language, "Language {$languageSlug} is not configured.");

        PLL()->curlang = $language;
    }
}
