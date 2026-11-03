<?php

namespace GeneroWP\ContentTranslation\Tests\Integration;

use GeneroWP\ContentTranslation\BlockRulesCheck;
use GeneroWP\ContentTranslation\ContentGaps;
use GeneroWP\ContentTranslation\MediaCheck;
use GeneroWP\ContentTranslation\Polylang\BlockPostIdTranslation;
use GeneroWP\ContentTranslation\Settings;
use GeneroWP\ContentTranslation\Tests\RestTestCase;

/**
 * What a translation may still show wrong besides files: query loops whose
 * chosen posts are not translated, links to pages without a translation,
 * texts still to be written; and block texts without a translation rule.
 */
class ContentGapsTest extends RestTestCase
{
    protected function tearDown(): void
    {
        if (\WP_Block_Type_Registry::get_instance()->is_registered('gds-test/cta')) {
            unregister_block_type('gds-test/cta');
        }

        BlockRulesCheck::forget();
        BlockRulesCheck::flushRules();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function queryBlock(array $query): string
    {
        return '<!-- wp:query '.wp_json_encode(['queryId' => 1, 'query' => $query + ['postType' => 'post', 'orderBy' => 'include']]).' --><div class="wp-block-query"></div><!-- /wp:query -->';
    }

    /**
     * @return array<string, array<string, mixed>> Key => item.
     */
    private function items(int $postId): array
    {
        return array_column(MediaCheck::check($postId)['items'], null, 'key');
    }

    /**
     * @return array<string, int>
     */
    private function translated(string $source, string $translation): array
    {
        $ids = $this->createTranslatedPost(['post_content' => $source]);
        wp_update_post(['ID' => $ids['fi'], 'post_content' => $translation]);

        return $ids;
    }

    public function test_a_query_loop_lists_the_chosen_posts_it_does_not_show(): void
    {
        $shown = $this->createTranslatedPost(['post_title' => 'Shown']);
        $missing = $this->createPost('en', ['post_title' => 'Not translated']);
        $parent = $this->createPost('en', ['post_title' => 'Parent', 'post_type' => 'page']);

        $ids = $this->translated(
            $this->queryBlock(['include' => [$shown['en'], $missing], 'parents' => [$parent]]),
            // Polylang made the post without a translation 0; the parent stayed the original's.
            $this->queryBlock(['include' => [$shown['fi'], 0], 'parents' => [$parent]])
        );

        $item = $this->items($ids['fi'])['query:0:0'];

        $this->assertSame('query', $item['kind']);
        $this->assertSame('open', $item['state']);
        $this->assertSame([[$missing, 'include', 'Not translated'], [$parent, 'parents', 'Parent']], array_map(static fn (array $post): array => [$post['id'], $post['field'], $post['title']], $item['posts']));
        $this->assertSame(1, MediaCheck::get($ids['fi'])['open'], 'One item per list.');

        // The original's own loop is fine, and so is a loop of this language's posts.
        $clean = $this->translated($this->queryBlock(['include' => [$shown['en']]]), $this->queryBlock(['include' => [$shown['fi']]]));
        $this->assertArrayNotHasKey('query:0:0', $this->items($clean['fi']));
    }

    public function test_a_chosen_post_translated_since_is_shown_once_the_list_is_updated(): void
    {
        $missing = $this->createPost('en', ['post_title' => 'Later']);
        $ids = $this->translated($this->queryBlock(['include' => [$missing]]), $this->queryBlock(['include' => [0]]));
        MediaCheck::check($ids['fi']);

        $this->assertNotContains('query-update', $this->request('GET', '/media/'.$ids['fi'])->get_data()['items'][0]['actions'], 'Nothing to show yet.');

        // Its translation is saved: the page that waits for it is checked again.
        $translation = $this->createPost('fi', ['post_title' => 'Myöhemmin']);
        pll_save_post_translations(['en' => $missing, 'fi' => $translation]);
        MediaCheck::onSave($translation, get_post($translation));
        MediaCheck::checkSaved();

        $item = MediaCheck::get($ids['fi'])['items'][0];
        $this->assertSame($translation, $item['posts'][0]['translationId']);

        $data = $this->request('GET', '/media/'.$ids['fi'])->get_data();
        $this->assertContains('query-update', $data['items'][0]['actions']);

        $response = $this->request('POST', '/media/'.$ids['fi'].'/action', ['key' => 'query:0:0', 'action' => 'query-update']);
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $this->assertSame([$translation], parse_blocks(get_post_field('post_content', $ids['fi']))[0]['attrs']['query']['include']);
        $this->assertSame([], MediaCheck::get($ids['fi'])['items']);
    }

    public function test_query_parents_and_terms_are_remapped_to_the_translations(): void
    {
        $parent = $this->createTranslatedPost(['post_type' => 'page', 'post_title' => 'Products']);
        $lonely = $this->createPost('en', ['post_type' => 'page']);
        $chosen = $this->createTranslatedPost();
        $terms = ['en' => self::factory()->category->create(), 'fi' => self::factory()->category->create()];
        pll_set_term_language($terms['en'], 'en');
        pll_set_term_language($terms['fi'], 'fi');
        pll_save_term_translations($terms);
        $untranslatedTerm = self::factory()->category->create();
        pll_set_term_language($untranslatedTerm, 'en');

        $block = static fn (array $query): array => ['blockName' => 'core/query', 'attrs' => ['query' => $query], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []];
        $translate = static fn (array $query): array => BlockPostIdTranslation::translateBlocks([$block($query)], 'fi')[0]['attrs']['query'];

        $query = $translate([
            'include' => [$chosen['en'], $lonely],
            'parents' => [$parent['en'], $lonely],
            'taxQuery' => ['category' => [$terms['en'], $untranslatedTerm]],
        ]);
        $this->assertSame([$chosen['fi'], 0], $query['include'], 'A chosen post without a translation becomes 0, as Polylang makes it.');
        $this->assertSame([$parent['fi'], $lonely], $query['parents'], 'A parent without a translation stays: 0 would list every top-level post.');
        $this->assertSame([$terms['fi'], $untranslatedTerm], $query['taxQuery']['category']);

        // The format of WordPress 7.0.
        $query = $translate(['taxQuery' => ['include' => ['category' => [$terms['en']]], 'exclude' => ['category' => [$terms['en']]]]]);
        $this->assertSame(['include' => ['category' => [$terms['fi']]], 'exclude' => ['category' => [$terms['fi']]]], $query['taxQuery']);

        // A saved translation is normalized.
        $page = $this->createPost('fi', ['post_content' => serialize_blocks([$block(['parents' => [$parent['en']]])])]);
        BlockPostIdTranslation::normalizePostContent($page);
        $this->assertSame([$parent['fi']], parse_blocks(get_post_field('post_content', $page))[0]['attrs']['query']['parents']);
    }

    public function test_a_link_to_a_page_without_a_translation_is_listed_until_it_has_one(): void
    {
        $target = $this->createPost('en', ['post_type' => 'page', 'post_title' => 'Contact', 'post_name' => 'contact']);
        $link = sprintf('<!-- wp:paragraph --><p><a href="%s">Contact us</a></p><!-- /wp:paragraph -->', get_permalink($target));
        $ids = $this->translated($link, $link);

        $item = $this->items($ids['fi'])['page:'.$target];
        $this->assertSame('page', $item['kind']);
        $this->assertSame('Contact', $item['title']);
        $this->assertSame($target, $item['sourceId']);
        $this->assertSame(1, MediaCheck::get($ids['fi'])['open']);

        // A draft translation: visitors still get the original's page.
        $translation = $this->createPost('fi', ['post_type' => 'page', 'post_title' => 'Yhteystiedot', 'post_status' => 'draft']);
        pll_save_post_translations(['en' => $target, 'fi' => $translation]);
        MediaCheck::onSave($translation, get_post($translation));
        MediaCheck::checkSaved();
        $this->assertSame($translation, array_column(MediaCheck::get($ids['fi'])['items'], null, 'key')['page:'.$target]['translationId']);

        // Published: Polylang points the link at it, and the item goes by itself.
        wp_update_post(['ID' => $translation, 'post_status' => 'publish']);
        MediaCheck::checkSaved();
        $this->assertSame([], MediaCheck::get($ids['fi'])['items']);

        // Deleted for good: the link leads to the original's page again.
        wp_delete_post($translation, true);
        MediaCheck::checkSaved();
        $this->assertSame(['page:'.$target], array_column(MediaCheck::get($ids['fi'])['items'], 'key'));
    }

    public function test_a_link_in_a_synced_pattern_is_listed(): void
    {
        if (! pll_is_translated_post_type('wp_block')) {
            $this->markTestSkipped('Patterns are not translated without Polylang Pro.');
        }

        $target = $this->createPost('en', ['post_type' => 'page', 'post_title' => 'Prices', 'post_name' => 'prices']);
        $html = sprintf('<!-- wp:paragraph --><p><a href="%s">Prices</a></p><!-- /wp:paragraph -->', wp_make_link_relative((string) get_permalink($target)));
        $pattern = $this->createPost('fi', ['post_type' => 'wp_block', 'post_title' => 'Hinnat', 'post_content' => $html]);
        $ids = $this->translated('', sprintf('<!-- wp:block {"ref":%d} /-->', $pattern));

        $item = $this->items($ids['fi'])['page:'.$target];
        $this->assertSame($pattern, $item['in']);
        $this->assertSame('Hinnat', $item['inTitle']);
    }

    public function test_placeholders_are_found_in_the_visible_text_only(): void
    {
        $this->assertSame(['[ATT FYLLA I]'], ContentGaps::placeholders('Svar: [ATT FYLLA I]'));
        $this->assertSame(['[TÄYTÄ TÄHÄN]'], ContentGaps::placeholders('[TÄYTÄ TÄHÄN] hinta'));
        $this->assertSame(['[TÄYTÄ]'], ContentGaps::placeholders('Hinta [TÄYTÄ] euroa'));
        $this->assertSame(['[TÄYTETTÄVÄ]'], ContentGaps::placeholders('[TÄYTETTÄVÄ]'));
        $this->assertSame(['[Fill in the price]'], ContentGaps::placeholders('From [Fill in the price].'));
        $this->assertSame(['TODO', 'TBD'], ContentGaps::placeholders('TODO: check. Date TBD.'));
        $this->assertSame(['Lorem ipsum'], ContentGaps::placeholders('Lorem ipsum dolor sit amet'));

        // Abbreviations and ordinary words.
        foreach (['Download [PDF]', 'The EU [EU] rules', 'Note [1]', 'Todo on hyvä', 'XXL size', 'A to-do list', 'Mikä pilarimalli [UUSI]'] as $text) {
            $this->assertSame([], ContentGaps::placeholders($text), $text);
        }

        $block = parse_blocks('<!-- wp:paragraph {"className":"TODO"} --><p class="TODO" data-x="[ATT FYLLA I]" style="--x:TODO">Ready</p><!-- /wp:paragraph -->')[0];
        $this->assertSame('Ready', ContentGaps::visibleText($block));

        $code = parse_blocks('<!-- wp:code --><pre class="wp-block-code"><code>// TODO</code></pre><!-- /wp:code -->')[0];
        $this->assertSame('', ContentGaps::visibleText($code));
    }

    public function test_a_placeholder_in_the_translation_or_its_original_is_listed(): void
    {
        $faq = static fn (string $answer): string => '<!-- wp:paragraph --><p>'.$answer.'</p><!-- /wp:paragraph -->';
        $ids = $this->translated($faq('[TÄYTÄ]'), $faq('[ATT FYLLA I]'));

        $items = $this->items($ids['fi']);
        $this->assertSame(['[ATT FYLLA I]'], $items['placeholder:[att fylla i]']['texts']);
        $this->assertFalse($items['placeholder:[att fylla i]']['inSource']);
        $this->assertSame(['[TÄYTÄ]'], $items['placeholder:[täytä]']['sourceTexts']);
        $this->assertTrue($items['placeholder:[täytä]']['inSource'], 'Only the original has it.');
        $this->assertSame(2, MediaCheck::get($ids['fi'])['open']);

        // Written: gone. Marked fine: not counted.
        wp_update_post(['ID' => $ids['fi'], 'post_content' => $faq('Svaret.')]);
        MediaCheck::setOk($ids['fi'], ['placeholder:[täytä]'], true);
        $this->assertSame(0, MediaCheck::get($ids['fi'])['open']);
    }

    public function test_sites_add_placeholder_patterns(): void
    {
        add_filter('gds_content_translation_placeholder_patterns', static fn (array $patterns): array => [...$patterns, '/\{\{[^}]+\}\}/']);

        $this->assertSame(['{{price}}'], ContentGaps::placeholders('From {{price}}'));
    }

    public function test_the_block_rules_check_lists_shown_texts_without_a_rule(): void
    {
        register_block_type('gds-test/cta', [
            'attributes' => [
                'buttonText' => ['type' => 'string'],
                'variant' => ['type' => 'string'],
                'label' => ['type' => 'string'],
            ],
            'render_callback' => static fn (array $attrs): string => sprintf(
                '<a class="cta cta--%s" aria-label="%s">%s</a>',
                esc_attr($attrs['variant'] ?? ''),
                esc_attr($attrs['label'] ?? ''),
                esc_html($attrs['buttonText'] ?? '')
            ),
        ]);
        $block = '<!-- wp:gds-test/cta {"buttonText":"Order now","variant":"primary large","label":"Order the product"} /-->';
        $this->createPost('en', ['post_content' => $block]);
        $this->createPost('en', ['post_content' => $block]);

        $result = BlockRulesCheck::run();
        $this->assertSame([['block' => 'gds-test/cta', 'title' => 'gds-test/cta', 'attribute' => 'buttonText', 'pages' => 2, 'example' => 'Order now']], $result['missing'], 'Class names and attributes are not text.');
        $this->assertSame($result, BlockRulesCheck::cached());

        // With a rule, it is translated.
        add_filter('gds_content_translation_pll_blocks_rules_for_attributes', static fn (array $rules): array => $rules + ['gds-test/cta' => ['buttonText' => true]]);
        BlockRulesCheck::flushRules();
        $this->assertSame([], BlockRulesCheck::run()['missing']);
    }

    public function test_the_settings_page_shows_the_block_rules_check(): void
    {
        set_current_screen('dashboard');
        BlockRulesCheck::run();

        ob_start();
        (new Settings)->renderPage();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Block texts', $html);
        $this->assertStringContainsString('gds_ct_block_rules_check', $html);
    }
}
