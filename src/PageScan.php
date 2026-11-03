<?php

namespace GeneroWP\ContentTranslation;

use PLL_Language;
use Throwable;
use WP_Error;
use WP_Http;
use WP_Http_Cookie;
use WP_Post;
use WP_Session_Tokens;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * "Check the page for Finnish": finds what a translated page still shows in
 * the original's language, or in another one, by looking at the page as a
 * visitor gets it rather than at the post: the theme's header and footer,
 * menus, patterns, image alts, the title and sharing tags.
 *
 * Only on demand, as it costs a Claude call: an editor asks for it once they
 * think a translation is done, after seeing what it would cost. The page is
 * fetched from the site ({@see fetch()}), its texts read ({@see PageText}),
 * and Claude names the ones in the wrong language. Each finding gets a likely
 * source, looked up on the site where it can be: the post's content, a
 * synced pattern, a template part or theme file, an image's alt in the media
 * library, a Polylang string, a menu.
 *
 * The check runs in the background, one page at a time: a worker started by
 * a non-blocking request to admin-ajax.php takes the queued pages oldest
 * first, as {@see BulkTranslation} does, with a WP-Cron event as the
 * fallback. The result is kept on the translation with when it was made and
 * a hash of the post, so an old one says the page has changed since.
 */
final class PageScan
{
    /** This request's worker place, when it has one. */
    private static string $owner = '';

    /** The last result, on the translation. */
    public const metaKey = '_gds_ct_page_scan';

    /**
     * Where the check is: "queued:<time>:<user>", "running:<time>:<user>",
     * "done:<findings>" or "failed". One query for the status screen.
     */
    public const statusMetaKey = '_gds_ct_page_scan_status';

    /** Added to the address fetched, so the page is not served from a cache and shows no admin bar. */
    public const fetchParam = 'gds_ct_page_check';

    public const sourceTypes = ['content', 'pattern', 'template', 'theme', 'media', 'string', 'menu', 'script', 'other'];

    /** Texts per Claude call; a long page is checked in several. */
    private const chunkSize = 80;

    /** Room for one answer, thinking included. */
    private const maxTokens = 16000;

    /**
     * Characters per token, for the estimate: Finnish, Swedish and the JSON
     * around the texts take more tokens than English prose, so this is low.
     */
    private const charsPerToken = 3;

    private const ajaxAction = 'gds_ct_run_page_scan';

    private const cronHook = 'gds_content_translation_run_page_scan';

    private const workerOption = 'gds_ct_page_scan_worker';

    private const spawnedOption = 'gds_ct_page_scan_spawned';

    /** Seconds after which a check or a worker that has not moved on counts as dead. */
    private const staleAfter = 600;

    /** Seconds a check may wait before a look at it starts a worker itself. */
    private const stallAfter = 45;

    /** Most theme files read when looking for where a text comes from. */
    private const themeFilesMax = 1500;

    /** @var array<string, string>|null Theme file (relative path) => content, read once per check. */
    private ?array $themeFiles = null;

    /** @var array<string, array<string, mixed>>|null Polylang's registered strings. */
    private ?array $strings = null;

    public static function init(): void
    {
        $scan = new self;
        add_action('wp_ajax_'.self::ajaxAction, [$scan, 'handleBackgroundRequest']);
        add_action('wp_ajax_nopriv_'.self::ajaxAction, [$scan, 'handleBackgroundRequest']);
        add_action(self::cronHook, [self::class, 'work']);
        // The fetched page as a visitor sees it, without the admin bar of the editor whose preview it is.
        add_filter('show_admin_bar', static fn ($show) => isset($_GET[self::fetchParam]) ? false : $show);
    }

    public static function isAvailable(): bool
    {
        return TranslationAudit::isEnabled();
    }

    /**
     * The original of a translation; 0 for a post in the default language.
     */
    public static function sourceIdOf(int $postId): int
    {
        $default = pll_default_language('slug');
        $sourceId = $default ? (int) pll_get_post($postId, $default) : 0;

        return $sourceId !== $postId ? $sourceId : 0;
    }

    /**
     * Where the page is fetched: the permalink once published, as anyone
     * sees it; else its preview, fetched as the editor who asked.
     *
     * @return array{url: string, preview: bool}|WP_Error
     */
    public static function pageUrl(int $postId): array|WP_Error
    {
        $post = get_post($postId);

        if (! $post || ! is_post_type_viewable($post->post_type)) {
            return new WP_Error('gds_ct_scan_no_page', __('This translation has no page of its own on the site.', 'gds-content-translation'));
        }

        $preview = $post->post_status !== 'publish';
        $url = $preview ? get_preview_post_link($post) : get_permalink($post);

        if (! is_string($url) || $url === '') {
            return new WP_Error('gds_ct_scan_no_page', __('This translation has no page of its own on the site.', 'gds-content-translation'));
        }

        return ['url' => $url, 'preview' => $preview];
    }

    /**
     * The page's HTML, from the site itself.
     *
     * A draft is not public: its preview is fetched with a login cookie of
     * the editor who asked, made for this one request (a session of five
     * minutes, ended at once) and sent only to the site's own address,
     * redirects included.
     *
     * @return array{url: string, preview: bool, html: string}|WP_Error
     */
    /**
     * The site's own address: its scheme, host and port.
     */
    private static function isSiteAddress(string $url): bool
    {
        $site = wp_parse_url(home_url());
        $asked = wp_parse_url($url);

        if (! is_array($site) || ! is_array($asked)) {
            return false;
        }

        $scheme = static fn (array $parts): string => strtolower((string) ($parts['scheme'] ?? ''));
        $port = static fn (array $parts): int => (int) ($parts['port'] ?? ($scheme($parts) === 'https' ? 443 : 80));

        return $scheme($asked) === $scheme($site)
            && strtolower((string) ($asked['host'] ?? '')) === strtolower((string) ($site['host'] ?? ''))
            && $port($asked) === $port($site);
    }

    public static function fetch(int $postId, int $userId): array|WP_Error
    {
        $page = self::pageUrl($postId);

        if ($page instanceof WP_Error) {
            return $page;
        }

        $url = add_query_arg(self::fetchParam, '1', $page['url']);
        $offSite = new WP_Error('gds_ct_scan_host', __('The page is not on this site’s address, so it cannot be checked.', 'gds-content-translation'));

        if (! self::isSiteAddress($url)) {
            return $offSite;
        }

        $cookies = [];
        $session = null;
        $token = '';

        if ($page['preview']) {
            if ($userId <= 0 || ! user_can($userId, 'edit_post', $postId)) {
                return new WP_Error('gds_ct_scan_draft', __('The translation is not published, and only an editor of it can check its preview.', 'gds-content-translation'));
            }

            $expiration = time() + 5 * MINUTE_IN_SECONDS;
            $session = WP_Session_Tokens::get_instance($userId);
            $token = $session->create($expiration);
            $cookies[] = new WP_Http_Cookie([
                'name' => LOGGED_IN_COOKIE,
                'value' => wp_generate_auth_cookie($userId, $expiration, 'logged_in', $token),
                'domain' => (string) wp_parse_url(home_url(), PHP_URL_HOST),
            ]);
        }

        try {
            // Redirects are followed here, each checked: the editor's login
            // goes to the site's own address only, never where a redirect
            // (a domain move's, say) points.
            for ($hop = 0; ; $hop++) {
                $response = wp_remote_get($url, [
                    'timeout' => 30,
                    'redirection' => 0,
                    // As WordPress' own loopback requests: the site may not trust its own certificate.
                    'sslverify' => apply_filters('https_local_ssl_verify', false),
                    'cookies' => $cookies,
                    'limit_response_size' => 8 * MB_IN_BYTES,
                    'headers' => ['Accept' => 'text/html'],
                ]);
                $status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
                $location = is_wp_error($response) ? '' : (string) wp_remote_retrieve_header($response, 'location');

                if ($status < 300 || $status >= 400 || $location === '' || $hop >= 3) {
                    break;
                }

                $url = WP_Http::make_absolute_url($location, $url);

                if (! self::isSiteAddress($url)) {
                    return $offSite;
                }
            }
        } finally {
            if ($session) {
                $session->destroy($token);
            }
        }

        if (is_wp_error($response)) {
            /* translators: %s: error message */
            return new WP_Error('gds_ct_scan_fetch', sprintf(__('The page could not be loaded: %s', 'gds-content-translation'), $response->get_error_message()));
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            /* translators: %d: HTTP status code, e.g. 404 */
            return new WP_Error('gds_ct_scan_fetch', sprintf(__('The page could not be loaded (HTTP %d).', 'gds-content-translation'), $code));
        }

        return ['url' => $page['url'], 'preview' => $page['preview'], 'html' => (string) wp_remote_retrieve_body($response)];
    }

    /**
     * What would be sent and what it would cost, without sending it.
     *
     * @return array<string, mixed>|WP_Error
     */
    public static function estimate(int $postId, int $userId): array|WP_Error
    {
        $languages = self::languages($postId);

        if ($languages instanceof WP_Error) {
            return $languages;
        }

        $page = self::fetch($postId, $userId);

        if ($page instanceof WP_Error) {
            return $page;
        }

        $items = PageText::extract($page['html']);
        $previous = self::get($postId);

        return self::cost(self::prompts($postId, $items, $languages[0], $languages[1]), count($items)) + [
            'url' => $page['url'],
            'preview' => $page['preview'],
            // The same texts as the last check found: checking again finds the same.
            'unchanged' => $previous && ($previous['pageHash'] ?? '') === self::pageHash($items),
        ];
    }

    /**
     * The estimate of some prompts: characters and tokens sent, a guess at
     * the answer's tokens, and the price at the model's.
     *
     * @param  list<array{system: string, user: string, texts: int}>  $prompts
     * @return array{texts: int, calls: int, characters: int, tokens: int, outputTokens: int, price: float, model: string, modelLabel: string}
     */
    public static function cost(array $prompts, int $texts): array
    {
        $characters = 0;
        $outputTokens = 0;

        foreach ($prompts as $prompt) {
            $characters += mb_strlen($prompt['system']) + mb_strlen($prompt['user']);
            // Thinking and a finding for some of the texts: a generous guess.
            $outputTokens += min(self::maxTokens, 500 + 20 * $prompt['texts']);
        }

        $tokens = (int) ceil($characters / self::charsPerToken);
        $model = TranslationAudit::model();

        return [
            'texts' => $texts,
            'calls' => count($prompts),
            'characters' => $characters,
            'tokens' => $tokens,
            'outputTokens' => $outputTokens,
            'price' => TranslationAudit::price($model, $tokens, $outputTokens),
            'model' => $model,
            'modelLabel' => TranslationAudit::models()[$model] ?? $model,
        ];
    }

    /**
     * The calls a check makes: the same system prompt, the texts in parts.
     *
     * @param  list<array{text: string, where: list<string>, kinds: list<string>}>  $items
     * @return list<array{system: string, user: string, texts: int}>
     */
    public static function prompts(int $postId, array $items, PLL_Language $source, PLL_Language $target): array
    {
        if ($items === []) {
            return [];
        }

        $allowed = Glossary::targetTermsIn(implode("\n", array_column($items, 'text')), Glossary::code($source), Glossary::code($target));
        $numbered = [];

        foreach ($items as $index => $item) {
            $numbered[] = ['id' => $index + 1, 'text' => $item['text'], 'where' => implode(', ', $item['where'])];
        }

        $prompts = [];

        foreach (array_chunk($numbered, self::chunkSize) as $chunk) {
            $prompts[] = [
                'system' => self::systemPrompt(),
                'user' => (string) wp_json_encode(array_filter([
                    'page_title' => get_the_title($postId),
                    'page_language' => sprintf('%s (%s)', $target->name, $target->locale),
                    'original_language' => sprintf('%s (%s)', $source->name, $source->locale),
                    'allowed_as_they_are' => $allowed ?: null,
                    'texts' => $chunk,
                ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'texts' => count($chunk),
            ];
        }

        return $prompts;
    }

    private static function systemPrompt(): string
    {
        return <<<'PROMPT'
You check a translated web page for text a visitor sees in the wrong language.

You get the texts of one page, numbered, with where each is (head: title, description and sharing tags; header, nav, main, aside, footer; body: outside those). Report the texts that are not in the page language: mostly ones left in the original language, also any other language.

Not findings: product, brand and company names, people's and place names, codes, model numbers, units, and the terms under allowed_as_they_are. A text that is the same word in both languages is fine. A text that mixes languages is a finding when a visitor needs a translation of part of it.

For each finding: the text's id, the language it is in, where it most likely comes from (content: the page's own text; pattern: a reusable block; template: the theme's header or footer; media: an image's alt text; string: a site-wide setting such as the site title or SEO texts; menu; script; other), and the text translated into the page language as the suggestion, as short as the original. When every text is in the page language, return no findings.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'findings' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'language' => ['type' => 'string'],
                            'source' => ['type' => 'string', 'enum' => self::sourceTypes],
                            'suggestion' => ['type' => 'string'],
                        ],
                        'required' => ['id', 'language', 'source', 'suggestion'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['findings'],
            'additionalProperties' => false,
        ];
    }

    /**
     * The original's language and the translation's.
     *
     * @return array{0: PLL_Language, 1: PLL_Language}|WP_Error
     */
    private static function languages(int $postId): array|WP_Error
    {
        $sourceId = self::sourceIdOf($postId);
        $source = $sourceId ? PLL()->model->post->get_language($sourceId) : false;
        $target = PLL()->model->post->get_language($postId);

        if (! $source || ! $target || ! get_post($postId)) {
            return new WP_Error('gds_ct_not_translation', __('This is not a translation.', 'gds-content-translation'));
        }

        return [$source, $target];
    }

    /**
     * Check a translation's page now. Never throws: a failure is a result
     * with the reason.
     *
     * @param  callable(string, string, array<string, mixed>): (array<string, mixed>|WP_Error)|null  $ask  Asks Claude; tests may replace it.
     * @return array<string, mixed>
     */
    public function run(int $postId, int $userId, ?callable $ask = null): array
    {
        $result = [
            'status' => 'failed',
            'createdAt' => time(),
            'requestedBy' => $userId,
            'model' => TranslationAudit::model(),
            'contentHash' => self::contentHash($postId),
            'pageHash' => '',
            'url' => '',
            'preview' => false,
            'texts' => 0,
            'regions' => [],
            'estimate' => null,
            'findings' => [],
            'error' => '',
        ];

        $languages = self::languages($postId);
        $page = $languages instanceof WP_Error ? $languages : self::fetch($postId, $userId);

        if ($page instanceof WP_Error) {
            return array_merge($result, ['error' => $page->get_error_message()]);
        }

        $items = PageText::extract($page['html']);
        $prompts = self::prompts($postId, $items, $languages[0], $languages[1]);
        $result = array_merge($result, [
            'url' => $page['url'],
            'preview' => $page['preview'],
            'pageHash' => self::pageHash($items),
            'texts' => count($items),
            'regions' => PageText::countByRegion($items),
            'estimate' => self::cost($prompts, count($items)),
        ]);

        $audit = new TranslationAudit;
        $ask ??= static fn (string $system, string $user, array $schema) => $audit->ask($system, $user, $schema, self::maxTokens);
        $found = [];

        foreach ($prompts as $prompt) {
            $answer = $ask($prompt['system'], $prompt['user'], self::schema());

            if ($answer instanceof WP_Error) {
                return array_merge($result, ['error' => $answer->get_error_message()]);
            }

            foreach ((array) ($answer['findings'] ?? []) as $finding) {
                $index = is_array($finding) ? (int) ($finding['id'] ?? 0) - 1 : -1;

                // Only texts that were sent, once each.
                if (isset($items[$index]) && ! isset($found[$index])) {
                    $found[$index] = $finding;
                }
            }
        }

        ksort($found);
        $findings = [];

        foreach ($found as $index => $finding) {
            $item = $items[$index];
            $guess = in_array($finding['source'] ?? '', self::sourceTypes, true) ? $finding['source'] : 'other';

            $findings[] = [
                'id' => count($findings) + 1,
                'text' => $item['text'],
                'where' => $item['where'],
                'kinds' => $item['kinds'],
                'language' => sanitize_text_field((string) ($finding['language'] ?? '')),
                'suggestion' => sanitize_text_field((string) ($finding['suggestion'] ?? '')),
                'source' => $this->locate($postId, $item, $guess),
            ];
        }

        return array_merge($result, ['status' => 'done', 'findings' => $findings]);
    }

    /**
     * Where a text most likely comes from, and where to fix it: looked up on
     * the site first, Claude's guess when nothing is found.
     *
     * @param  array{text: string, where: list<string>, kinds: list<string>}  $item
     * @return array{type: string, label: string, url: string}
     */
    public function locate(int $postId, array $item, string $guess = 'other'): array
    {
        $text = $item['text'];
        $post = get_post($postId);
        $editUrl = (string) get_edit_post_link($postId, 'raw');

        if ($post && in_array('head', $item['where'], true)) {
            foreach (['_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_opengraph-title', '_yoast_wpseo_opengraph-description'] as $key) {
                if (self::contains((string) get_post_meta($postId, $key, true), $text)) {
                    return ['type' => 'content', 'label' => __('The page’s SEO title or description', 'gds-content-translation'), 'url' => $editUrl];
                }
            }
        }

        if ($post && $this->inPost($post, $text)) {
            return ['type' => 'content', 'label' => __('The page’s content', 'gds-content-translation'), 'url' => $editUrl];
        }

        $attachmentId = $this->attachmentWithAlt($text);

        if ($attachmentId > 0) {
            return [
                'type' => 'media',
                /* translators: %s: file name */
                'label' => sprintf(__('The alt text of “%s” in the media library', 'gds-content-translation'), get_the_title($attachmentId) ?: basename((string) get_attached_file($attachmentId))),
                'url' => admin_url('upload.php?item='.$attachmentId),
            ];
        }

        foreach ([
            'wp_block' => ['pattern', __('The synced pattern “%s”', 'gds-content-translation')],
            'wp_navigation' => ['menu', __('The navigation menu “%s”', 'gds-content-translation')],
            'wp_template_part' => ['template', __('The template part “%s” (edited in the site editor)', 'gds-content-translation')],
        ] as $postType => [$type, $label]) {
            $found = $this->postOfTypeWith($postType, $text, $post);

            if ($found) {
                return ['type' => $type, 'label' => sprintf($label, get_the_title($found) ?: $found->post_name), 'url' => (string) get_edit_post_link($found->ID, 'raw')];
            }
        }

        $menu = $this->menuWith($text);

        if ($menu) {
            return $menu;
        }

        $string = $this->polylangString($text);

        if ($string) {
            return $string;
        }

        $file = $this->themeFileWith($text);

        if ($file !== '') {
            $part = str_starts_with($file, 'parts/');

            return [
                'type' => $part ? 'template' : 'theme',
                'label' => $part
                    /* translators: %s: file path in the theme, e.g. parts/footer.html */
                    ? sprintf(__('The theme’s template part %s', 'gds-content-translation'), $file)
                    /* translators: %s: file path in the theme */
                    : sprintf(__('The theme file %s', 'gds-content-translation'), $file),
                // A block theme's part can be changed in the site editor; a file, only in the theme.
                'url' => $part && wp_is_block_theme() ? admin_url('site-editor.php?postType=wp_template_part') : '',
            ];
        }

        $labels = [
            'content' => __('Probably the page’s content', 'gds-content-translation'),
            'pattern' => __('Probably a pattern', 'gds-content-translation'),
            'template' => __('Probably the theme’s header or footer', 'gds-content-translation'),
            'theme' => __('Probably the theme', 'gds-content-translation'),
            'media' => __('Probably an image’s alt text', 'gds-content-translation'),
            'string' => __('Probably a site-wide text (Polylang strings)', 'gds-content-translation'),
            'menu' => __('Probably a menu', 'gds-content-translation'),
            'script' => __('Probably added by a script', 'gds-content-translation'),
            'other' => __('Not found on the site: a plugin or the theme, probably', 'gds-content-translation'),
        ];

        return ['type' => $guess, 'label' => $labels[$guess] ?? $labels['other'], 'url' => $guess === 'content' ? $editUrl : ''];
    }

    /**
     * Whether a text is the translation's own: its content (texts and
     * attributes), title, excerpt or ACF text fields.
     */
    private function inPost(WP_Post $post, string $text): bool
    {
        $haystacks = [$post->post_title, $post->post_excerpt, $post->post_content, wp_strip_all_tags($post->post_content)];
        $sourceId = self::sourceIdOf((int) $post->ID);

        if ($sourceId) {
            foreach (TranslationAudit::pairAcf($sourceId, (int) $post->ID) as $field) {
                $haystacks[] = wp_strip_all_tags($field['translation']);
            }
        }

        foreach ($haystacks as $haystack) {
            if (self::contains($haystack, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a text is in another, as whole words, in any case.
     */
    private static function contains(string $haystack, string $text): bool
    {
        if ($haystack === '' || $text === '') {
            return false;
        }

        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($text, '/').'(?![\p{L}\p{N}])/iu', PageText::normalize($haystack)) === 1;
    }

    private function attachmentWithAlt(string $text): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_image_alt' AND TRIM(meta_value) = %s ORDER BY post_id DESC LIMIT 1",
            $text
        ));
    }

    /**
     * A post of a type (synced pattern, navigation, template part) whose
     * content has the text; one the page's content refers to comes first.
     */
    private function postOfTypeWith(string $postType, string $text, ?WP_Post $page): ?WP_Post
    {
        global $wpdb;

        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND (post_content LIKE %s OR post_content LIKE %s) ORDER BY ID DESC LIMIT 20",
            $postType,
            '%'.$wpdb->esc_like($text).'%',
            '%'.$wpdb->esc_like(esc_html($text)).'%'
        )));

        if ($ids === []) {
            return null;
        }

        foreach ($ids as $id) {
            if ($page && str_contains($page->post_content, '"ref":'.$id)) {
                return get_post($id);
            }
        }

        return get_post($ids[0]);
    }

    /**
     * A classic menu's item with the text as its title.
     *
     * @return array{type: string, label: string, url: string}|null
     */
    private function menuWith(string $text): ?array
    {
        global $wpdb;

        $itemId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'nav_menu_item' AND post_title = %s LIMIT 1",
            $text
        ));
        $menus = $itemId ? wp_get_object_terms($itemId, 'nav_menu') : [];
        $menu = is_array($menus) ? ($menus[0] ?? null) : null;

        if (! $menu) {
            return null;
        }

        return [
            'type' => 'menu',
            /* translators: %s: menu name */
            'label' => sprintf(__('The menu “%s”', 'gds-content-translation'), $menu->name),
            'url' => admin_url('nav-menus.php?action=edit&menu='.(int) $menu->term_id),
        ];
    }

    /**
     * A string registered with Polylang (the site title, Yoast's texts, a
     * theme's) that is the text: shown in the original when it has no
     * translation.
     *
     * @return array{type: string, label: string, url: string}|null
     */
    private function polylangString(string $text): ?array
    {
        if ($this->strings === null) {
            $this->strings = [];

            if (class_exists('PLL_Admin_Strings')) {
                try {
                    $this->strings = (array) \PLL_Admin_Strings::get_strings();
                } catch (Throwable) {
                    $this->strings = [];
                }
            }

            foreach (['blogname' => get_bloginfo('name'), 'blogdescription' => get_bloginfo('description')] as $name => $value) {
                $this->strings[] = ['name' => $name, 'string' => $value, 'context' => 'WordPress'];
            }
        }

        $needle = mb_strtolower($text);

        foreach ($this->strings as $string) {
            if (is_array($string) && is_string($string['string'] ?? null) && mb_strtolower(PageText::normalize(wp_strip_all_tags($string['string']))) === $needle) {
                $context = (string) ($string['context'] ?? '');

                return [
                    'type' => 'string',
                    /* translators: %s: string group, e.g. Yoast SEO */
                    'label' => $context !== '' ? sprintf(__('A Polylang string (%s)', 'gds-content-translation'), $context) : __('A Polylang string', 'gds-content-translation'),
                    'url' => add_query_arg(array_filter(['page' => 'mlang_strings', 's' => $text, 'group' => $context]), admin_url('admin.php')),
                ];
            }
        }

        return null;
    }

    /**
     * The theme file the text is written in, as a path in the theme.
     */
    private function themeFileWith(string $text): string
    {
        if ($this->themeFiles === null) {
            $this->themeFiles = self::readThemeFiles();
        }

        foreach ([$text, esc_html($text), htmlspecialchars($text, ENT_QUOTES)] as $needle) {
            foreach ($this->themeFiles as $path => $content) {
                if (str_contains($content, $needle)) {
                    return $path;
                }
            }
        }

        return '';
    }

    /**
     * The theme's templates, parts, patterns and PHP files, child theme
     * first; not its dependencies or build output.
     *
     * @return array<string, string>
     */
    private static function readThemeFiles(): array
    {
        $files = [];
        $roots = array_unique([get_stylesheet_directory(), get_template_directory()]);

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file): bool => ! ($file->isDir() && in_array($file->getFilename(), ['node_modules', 'vendor', 'build', 'dist', '.git', 'tests'], true))
            ));

            foreach ($iterator as $file) {
                if (count($files) >= self::themeFilesMax) {
                    break 2;
                }

                if ($file instanceof \SplFileInfo && $file->isFile() && in_array($file->getExtension(), ['html', 'php', 'json'], true) && $file->getSize() < 512 * KB_IN_BYTES) {
                    $path = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
                    $files[$path] ??= (string) file_get_contents($file->getPathname());
                }
            }
        }

        // Template parts and templates before the rest: they are what the page shows around its content.
        uksort($files, static fn (string $a, string $b): int => [! str_starts_with($a, 'parts/'), ! str_starts_with($a, 'templates/'), $a] <=> [! str_starts_with($b, 'parts/'), ! str_starts_with($b, 'templates/'), $b]);

        return $files;
    }

    /**
     * What the check saw of the post, to tell later that it changed.
     */
    public static function contentHash(int $postId): string
    {
        $post = get_post($postId);

        return $post ? md5(TranslationAudit::contentHash($postId).'|'.$post->post_title.'|'.$post->post_excerpt) : '';
    }

    /**
     * @param  list<array{text: string, where: list<string>, kinds: list<string>}>  $items
     */
    private static function pageHash(array $items): string
    {
        return md5((string) wp_json_encode($items));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(int $postId): ?array
    {
        $result = get_post_meta($postId, self::metaKey, true);

        return is_array($result) ? $result : null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private static function store(int $postId, array $result): void
    {
        update_post_meta($postId, self::metaKey, wp_slash($result));
        update_post_meta($postId, self::statusMetaKey, $result['status'] === 'done' ? 'done:'.count($result['findings']) : 'failed');
    }

    /**
     * @return array{status: string, found: int, since: int, userId: int}
     */
    public static function parseStatus(string $value): array
    {
        $parts = explode(':', $value);
        $status = ['status' => 'none', 'found' => 0, 'since' => 0, 'userId' => 0];

        if ($parts[0] === 'done') {
            return array_merge($status, ['status' => 'done', 'found' => (int) ($parts[1] ?? 0)]);
        }

        if ($parts[0] === 'queued' || $parts[0] === 'running') {
            $since = (int) ($parts[1] ?? 0);
            // One waiting its turn behind a long bulk run is not stuck that soon.
            $stuck = time() - $since > ($parts[0] === 'queued' ? self::staleAfter * 6 : self::staleAfter);

            return array_merge($status, ['status' => $stuck ? 'stuck' : $parts[0], 'since' => $since, 'userId' => (int) ($parts[2] ?? 0)]);
        }

        return $parts[0] === 'failed' ? array_merge($status, ['status' => 'failed']) : $status;
    }

    /**
     * Status per translation, for the status screen.
     *
     * @param  list<int>  $postIds
     * @return array<int, array{status: string, found: int, since: int, userId: int}>
     */
    public static function statuses(array $postIds): array
    {
        global $wpdb;

        $statuses = [];

        foreach (array_chunk(array_values(array_unique(array_filter(array_map('intval', $postIds)))), 1000) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ({$placeholders})",
                self::statusMetaKey,
                ...$chunk
            ));

            foreach ($rows as $row) {
                $statuses[(int) $row->post_id] = self::parseStatus((string) $row->meta_value);
            }
        }

        return $statuses;
    }

    /**
     * @return array{status: string, found: int, since: int, userId: int}
     */
    public static function status(int $postId): array
    {
        return self::parseStatus((string) get_post_meta($postId, self::statusMetaKey, true));
    }

    /**
     * Queue a check of a translation's page and start the worker. One that
     * is queued or running already is left as it is.
     */
    public static function queue(int $postId, int $userId): bool
    {
        if (in_array(self::status($postId)['status'], ['queued', 'running'], true)) {
            return false;
        }

        update_post_meta($postId, self::statusMetaKey, sprintf('queued:%d:%d', time(), $userId));
        self::schedule();
        // A bulk run queues many at once: one request starts the worker, not one per page.
        self::spawn(! self::workerAlive() && time() - (int) get_option(self::spawnedOption, 0) >= 2);

        return true;
    }

    /**
     * What the screens show of a translation's check: the last result, and
     * whether one is on its way.
     *
     * @return array<string, mixed>
     */
    public static function data(int $postId): array
    {
        $status = self::status($postId);

        // Waiting with no worker running: the background request did not get through.
        if ($status['status'] === 'queued' && time() - $status['since'] > self::stallAfter && ! self::workerAlive()) {
            self::spawn(true);
        }

        $result = self::get($postId);
        // Every key, also of a result kept from a check that never finished.
        $result = $result ? array_merge(self::emptyResult(), $result) : null;
        $languages = self::languages($postId);
        $source = $languages instanceof WP_Error ? null : $languages[0];
        $target = $languages instanceof WP_Error ? null : $languages[1];

        return [
            'postId' => $postId,
            'status' => $status['status'],
            'available' => self::isAvailable(),
            'sourceLanguage' => $source ? StatusList::languageName($source) : '',
            'targetLanguage' => $target ? StatusList::languageName($target) : '',
            'published' => get_post_status($postId) === 'publish',
            'result' => $result ? [
                'status' => (string) $result['status'],
                'createdAt' => (int) $result['createdAt'],
                'url' => (string) $result['url'],
                'preview' => (bool) $result['preview'],
                'model' => TranslationAudit::models()[$result['model']] ?? (string) $result['model'],
                'texts' => (int) $result['texts'],
                'regions' => (array) $result['regions'],
                'estimate' => $result['estimate'],
                'error' => (string) $result['error'],
                // The post changed since: the page may have too.
                'changed' => $result['contentHash'] !== '' && $result['contentHash'] !== self::contentHash($postId),
                'findings' => array_values((array) $result['findings']),
            ] : null,
        ];
    }

    /**
     * Start a worker in the background, unless one runs or was started a
     * moment ago.
     */
    public static function spawn(bool $force = false): void
    {
        if (! $force && (self::workerAlive() || time() - (int) get_option(self::spawnedOption, 0) < 10)) {
            return;
        }

        update_option(self::spawnedOption, time(), false);
        $time = (string) time();

        wp_remote_post(admin_url('admin-ajax.php'), [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'body' => [
                'action' => self::ajaxAction,
                'pll_ajax_backend' => 1,
                'time' => $time,
                'token' => self::token($time),
            ],
        ]);
    }

    /**
     * The background request spawn() sends. Needs no login: the token, made
     * with the site's salts and valid for ten minutes, is the authorisation,
     * and all it can do is work through what editors queued.
     */
    public function handleBackgroundRequest(): void
    {
        $time = isset($_POST['time']) && is_string($_POST['time']) ? sanitize_text_field(wp_unslash($_POST['time'])) : '';
        $token = isset($_POST['token']) && is_string($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';

        if ($time === '' || $token === '' || abs(time() - (int) $time) > 10 * MINUTE_IN_SECONDS || ! hash_equals(self::token($time), $token)) {
            wp_die('', '', 403);
        }

        ignore_user_abort(true);
        self::work();

        wp_die();
    }

    /**
     * Check the queued pages one at a time, oldest first, for a while; then
     * hand over to a fresh request when there are more.
     *
     * @param  callable|null  $ask  Asks Claude; tests replace it.
     */
    public static function work(?float $budget = null, ?callable $ask = null): void
    {
        // Another one works, or died holding the place: cron asks again.
        if (! self::acquireWorker()) {
            self::schedule();

            return;
        }

        $budget ??= (float) apply_filters('gds_content_translation_page_scan_budget', 20.0);
        $deadline = microtime(true) + $budget;
        $scan = new self;
        $more = false;
        $ran = 0;

        try {
            while (($postId = self::next()) > 0) {
                if ($ran > 0 && microtime(true) >= $deadline) {
                    $more = true;
                    break;
                }

                $scan->runQueued($postId, $ask);
                self::touchWorker();
                $ran++;
            }
        } finally {
            self::releaseWorker();
        }

        if ($more) {
            self::schedule();
            self::spawn(true);
        }
    }

    private function runQueued(int $postId, ?callable $ask): void
    {
        $raw = (string) get_post_meta($postId, self::statusMetaKey, true);
        $status = self::parseStatus($raw);

        // Queued, also one that waited long behind a bulk run (shown as stuck):
        // left as it is, the worker would get it back for good.
        if (! str_starts_with($raw, 'queued:')) {
            return;
        }

        update_post_meta($postId, self::statusMetaKey, sprintf('running:%d:%d', time(), $status['userId']));

        // A page of many texts takes several Claude calls: the worker and the
        // check say they are alive meanwhile, or another worker would take
        // the place and fail the check.
        $lastBeat = time();
        $heartbeat = static function () use ($postId, $status, &$lastBeat): void {
            if (time() - $lastBeat >= 30) {
                $lastBeat = time();
                self::touchWorker();
                update_post_meta($postId, self::statusMetaKey, sprintf('running:%d:%d', time(), $status['userId']));
            }
        };
        add_action('http_api_debug', $heartbeat);

        if (function_exists('set_time_limit')) {
            set_time_limit(5 * MINUTE_IN_SECONDS);
        }

        $previousUser = get_current_user_id();
        wp_set_current_user($status['userId']);

        try {
            $result = $this->run($postId, $status['userId'], $ask);
        } catch (Throwable $e) {
            error_log(sprintf('[gds-content-translation] The page check of post %d failed: %s', $postId, $e));
            $result = array_merge(self::emptyResult(), ['status' => 'failed', 'requestedBy' => $status['userId'], 'error' => $e->getMessage()]);
        } finally {
            remove_action('http_api_debug', $heartbeat);
            wp_set_current_user($previousUser);
        }

        self::store($postId, $result);
    }

    /**
     * The check that has waited longest. One left running by a worker that
     * died fails, with the reason.
     */
    private static function next(): int
    {
        global $wpdb;

        $running = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
            self::statusMetaKey,
            $wpdb->esc_like('running:').'%'
        ));

        foreach ($running as $row) {
            if (self::parseStatus((string) $row->meta_value)['status'] === 'stuck') {
                $postId = (int) $row->post_id;
                $result = self::get($postId) ?? self::emptyResult();
                self::store($postId, array_merge($result, ['status' => 'failed', 'createdAt' => time(), 'error' => __('The check did not finish.', 'gds-content-translation')]));
            }
        }

        // Unix times have ten digits until 2286, so text order is time order.
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s ORDER BY meta_value ASC LIMIT 1",
            self::statusMetaKey,
            $wpdb->esc_like('queued:').'%'
        ));
    }

    /**
     * A result with nothing in it, for a check that failed before it had one.
     *
     * @return array<string, mixed>
     */
    private static function emptyResult(): array
    {
        return ['status' => 'failed', 'createdAt' => time(), 'requestedBy' => 0, 'model' => TranslationAudit::model(), 'contentHash' => '', 'pageHash' => '', 'url' => '', 'preview' => false, 'texts' => 0, 'regions' => [], 'estimate' => null, 'findings' => [], 'error' => ''];
    }

    private static function schedule(): void
    {
        if (! wp_next_scheduled(self::cronHook)) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::cronHook);
        }
    }

    private static function token(string $time): string
    {
        return wp_hash(self::ajaxAction.'|'.$time);
    }

    /**
     * One worker at a time, as {@see BulkTranslation}'s: the INSERT creates
     * the row or fails on the unique option name.
     */
    private static function acquireWorker(): bool
    {
        global $wpdb;

        // "<time>:<owner>": renewed and released only by its owner, not by a
        // worker whose place was taken over.
        self::$owner = wp_generate_password(16, false);
        $insert = static fn () => (int) $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            self::workerOption,
            time().':'.self::$owner
        ));

        if ($insert() === 1) {
            return true;
        }

        $value = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::workerOption));
        $touchedAt = (int) strtok($value, ':');

        if ($touchedAt > 0 && time() - $touchedAt < self::staleAfter) {
            self::$owner = '';

            return false;
        }

        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::workerOption, $value));

        if ($insert() === 1) {
            return true;
        }

        self::$owner = '';

        return false;
    }

    private static function touchWorker(): void
    {
        global $wpdb;

        if (self::$owner !== '') {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s",
                time().':'.self::$owner,
                self::workerOption,
                '%:'.$wpdb->esc_like(self::$owner)
            ));
        }
    }

    private static function releaseWorker(): void
    {
        global $wpdb;

        if (self::$owner !== '') {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
                self::workerOption,
                '%:'.$wpdb->esc_like(self::$owner)
            ));
            self::$owner = '';
        }
    }

    private static function workerAlive(): bool
    {
        global $wpdb;

        $touchedAt = (int) strtok((string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::workerOption)), ':');

        return $touchedAt > 0 && time() - $touchedAt < self::staleAfter;
    }
}
