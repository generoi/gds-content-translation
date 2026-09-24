<?php

namespace GeneroWP\ContentTranslation\Polylang;

use PLL_Language;
use WP_CLI;

/**
 * Rewrites internal links in saved content to the language of the post they
 * are in. For content translated before links were fixed on sync.
 */
class FixLinksCommand
{
    public function __construct(private BlockLinkTranslation $links) {}

    /**
     * Rewrite internal links in saved content to each post's own language.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : List the posts that would change without saving them.
     *
     * ## EXAMPLES
     *
     *     wp gds-content-translation fix-links --dry-run
     *
     * @param  string[]  $args
     * @param  array<string, string>  $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        $dryRun = (bool) WP_CLI\Utils\get_flag_value($assocArgs, 'dry-run', false);
        $changed = $this->fix($dryRun);

        foreach ($changed as $postId) {
            WP_CLI::log(sprintf('#%d %s', $postId, get_the_title($postId)));
        }

        WP_CLI::success(sprintf($dryRun ? '%d posts would change.' : '%d posts updated.', count($changed)));
    }

    /**
     * @return list<int> IDs of the posts whose content changed.
     */
    public function fix(bool $dryRun): array
    {
        $postIds = get_posts([
            'post_type' => array_values(PLL()->model->get_translated_post_types()),
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'lang' => '',
        ]);

        $changed = [];

        foreach ($postIds as $postId) {
            $language = PLL()->model->post->get_language($postId);
            $content = (string) get_post_field('post_content', $postId, 'raw');

            if (! $language instanceof PLL_Language || ! has_blocks($content)) {
                continue;
            }

            // Compare against a parse/serialize round trip, which can re-encode
            // block attributes without any link changing.
            $translated = $this->links->translateContent($content, $language);

            if ($translated === serialize_blocks(parse_blocks($content))) {
                continue;
            }

            $changed[] = $postId;

            if (! $dryRun) {
                wp_update_post(['ID' => $postId, 'post_content' => wp_slash($translated)]);
            }
        }

        return $changed;
    }
}
