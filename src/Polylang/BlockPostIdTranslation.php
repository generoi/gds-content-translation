<?php

namespace GeneroWP\ContentTranslation\Polylang;

use PLL_Language;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Remaps block attributes that store post/attachment/term IDs to the correct translation.
 *
 * Runs during machine translation (via Polylang sync rules), when loading the block editor
 * (REST), and on frontend render — so the editor does not need a manual reload.
 */
class BlockPostIdTranslation
{
    public function __construct()
    {
        add_filter('pll_translate_blocks_with_context', [$this, 'translateBlocksDuringSync'], 10, 3);
        add_filter('render_block_data', [$this, 'translateBlockOnRender'], 10, 1);
        add_action('init', [$this, 'registerRestPrepareFilters'], 100);
    }

    public function registerRestPrepareFilters(): void
    {
        if (! function_exists('pll_is_translated_post_type')) {
            return;
        }

        foreach (get_post_types() as $postType) {
            if (! pll_is_translated_post_type($postType)) {
                continue;
            }

            add_filter("rest_prepare_{$postType}", [$this, 'preparePostForEditor'], 20, 3);
        }
    }

    /**
     * @return array<string, array<string, string[]>>
     */
    public static function postIdAttributesByBlock(): array
    {
        $defaults = [
            'woocommerce/single-product' => [
                'post' => ['productId'],
            ],
            'woocommerce/featured-product' => [
                'post' => ['productId'],
                'attachment' => ['mediaId'],
            ],
            'gds/post-teaser' => [
                'post' => ['postId'],
            ],
        ];

        /**
         * Block attributes that store object IDs (post, attachment, term).
         *
         * @param  array<string, array<string, string[]>>  $attributesByBlock
         */
        return apply_filters('gds_content_translation_post_id_attributes_by_block', $defaults);
    }

    /**
     * @param  array[]  $blocks
     * @return array[]
     */
    public function translateBlocksDuringSync(array $blocks, PLL_Language $targetLanguage, $sourcePost): array
    {
        $editedPostId = $sourcePost instanceof WP_Post ? (int) $sourcePost->ID : 0;

        return self::translateBlocks($blocks, $targetLanguage->slug, $editedPostId);
    }

    /**
     * @param  array<string, mixed>  $parsedBlock
     * @return array<string, mixed>
     */
    public function translateBlockOnRender(array $parsedBlock): array
    {
        $languageSlug = $this->resolveLanguageSlug();

        if ($languageSlug === null) {
            return $parsedBlock;
        }

        $blocks = self::translateBlocks([$parsedBlock], $languageSlug, $this->resolveEditedPostId());

        return $blocks[0] ?? $parsedBlock;
    }

    /**
     * @param  WP_REST_Response  $response
     * @return WP_REST_Response
     */
    public function preparePostForEditor($response, WP_Post $post, WP_REST_Request $request)
    {
        if (($request->get_param('context') ?? '') !== 'edit') {
            return $response;
        }

        if (! isset($response->data['content']['raw']) || ! is_string($response->data['content']['raw'])) {
            return $response;
        }

        $languageSlug = function_exists('pll_get_post_language') ? pll_get_post_language($post->ID) : null;

        if (! is_string($languageSlug) || $languageSlug === '') {
            return $response;
        }

        $blocks = parse_blocks($response->data['content']['raw']);
        $blocks = self::translateBlocks($blocks, $languageSlug, (int) $post->ID);
        $response->data['content']['raw'] = serialize_blocks($blocks);

        return $response;
    }

    /**
     * @param  array[]  $blocks
     * @return array[]
     */
    public static function translateBlocks(array $blocks, string $languageSlug, int $editedPostId = 0): array
    {
        foreach ($blocks as $index => $block) {
            if (! is_array($block)) {
                continue;
            }

            $blocks[$index] = self::translateBlock($block, $languageSlug, $editedPostId);

            if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $blocks[$index]['innerBlocks'] = self::translateBlocks(
                    $blocks[$index]['innerBlocks'],
                    $languageSlug,
                    $editedPostId
                );
            }
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function translateBlock(array $block, string $languageSlug, int $editedPostId): array
    {
        $blockName = $block['blockName'] ?? '';
        $rulesByType = self::postIdAttributesByBlock();

        if (! is_string($blockName) || ! isset($rulesByType[$blockName]) || empty($block['attrs'])) {
            return $block;
        }

        foreach ($rulesByType[$blockName] as $objectType => $attributeNames) {
            foreach ($attributeNames as $attributeName) {
                if (! isset($block['attrs'][$attributeName])) {
                    continue;
                }

                $value = $block['attrs'][$attributeName];
                $translatedId = self::translateObjectId($value, $objectType, $languageSlug);

                if ($translatedId === null) {
                    continue;
                }

                if ($translatedId <= 0) {
                    unset($block['attrs'][$attributeName]);

                    continue;
                }

                if (
                    $editedPostId > 0
                    && $blockName === 'woocommerce/single-product'
                    && $attributeName === 'productId'
                    && get_post_type($editedPostId) === 'product'
                    && $translatedId === $editedPostId
                ) {
                    unset($block['attrs'][$attributeName]);

                    continue;
                }

                $block['attrs'][$attributeName] = $translatedId;
            }
        }

        return $block;
    }

    /**
     * @param  mixed  $value
     */
    private static function translateObjectId($value, string $objectType, string $languageSlug): ?int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        $objectId = (int) $value;

        if ($objectType === 'post' && function_exists('pll_get_post')) {
            return (int) pll_get_post($objectId, $languageSlug);
        }

        if ($objectType === 'attachment' && function_exists('pll_get_post')) {
            return (int) pll_get_post($objectId, $languageSlug);
        }

        if ($objectType === 'term' && function_exists('pll_get_term')) {
            return (int) pll_get_term($objectId, $languageSlug);
        }

        return null;
    }

    /**
     * Normalize and persist block post IDs on a saved translation.
     */
    public static function normalizePostContent(int $postId): bool
    {
        $post = get_post($postId);

        if (! $post instanceof WP_Post || $post->post_content === '') {
            return false;
        }

        $languageSlug = function_exists('pll_get_post_language') ? pll_get_post_language($postId) : null;

        if (! is_string($languageSlug) || $languageSlug === '') {
            return false;
        }

        $blocks = parse_blocks($post->post_content);
        $normalized = serialize_blocks(self::translateBlocks($blocks, $languageSlug, $postId));

        if ($normalized === $post->post_content) {
            return false;
        }

        wp_update_post([
            'ID' => $postId,
            'post_content' => $normalized,
        ]);

        return true;
    }

    private function resolveLanguageSlug(): ?string
    {
        $editedPostId = $this->resolveEditedPostId();

        if ($editedPostId > 0 && function_exists('pll_get_post_language')) {
            $slug = pll_get_post_language($editedPostId);

            if (is_string($slug) && $slug !== '') {
                return $slug;
            }
        }

        if (! is_admin() && function_exists('pll_current_language')) {
            $slug = pll_current_language();

            if (is_string($slug) && $slug !== '') {
                return $slug;
            }
        }

        return null;
    }

    private function resolveEditedPostId(): int
    {
        if (isset($_GET['post'])) {
            return (int) $_GET['post'];
        }

        global $post;

        if ($post instanceof WP_Post) {
            return (int) $post->ID;
        }

        return 0;
    }
}
