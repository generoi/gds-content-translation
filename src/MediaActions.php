<?php

namespace GeneroWP\ContentTranslation;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What can be done about a file, image or form a translation still shows in
 * the original's language ({@see MediaCheck}): use another one, translate
 * an SVG's labels, have DeepL draft a document, or go back to the original.
 */
final class MediaActions
{
    public const actions = ['replace', 'restore', 'svg-translate', 'svg-labels', 'document-translate', 'document-use', 'document-discard', 'query-update'];

    /**
     * The items with what each offers, for the list.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function describe(int $postId, array $items): array
    {
        $pageJobs = DocumentTranslation::jobs($postId);
        $language = PLL()->model->post->get_language($postId);

        foreach ($items as $index => $item) {
            $actions = [];
            $attachmentId = (int) ($item['attachmentId'] ?? 0);

            // The page shows the original's pattern: changing it would change the
            // original. A linked page can still be translated.
            if (! empty($item['inOriginal']) && $item['kind'] !== 'page') {
                $item['actions'] = [];
                $items[$index] = $item;

                continue;
            }

            $jobs = ! empty($item['in']) ? DocumentTranslation::jobs((int) $item['in']) : $pageJobs;

            switch ($item['kind']) {
                case 'document':
                case 'image':
                case 'svg':
                    $actions[] = $item['state'] === 'translated' ? 'restore' : 'replace';
                    break;
                case 'form':
                case 'embed':
                case 'link':
                    $actions[] = 'replace';
                    break;
                case 'query':
                    // Posts translated since: the list can show them now.
                    if (array_filter((array) $item['posts'], static fn (array $post): bool => (int) $post['translationId'] > 0) !== []) {
                        $actions[] = 'query-update';
                    }

                    // The others are AI translated from the status screen's REST action.
                    if (MachineTranslation::isAvailable() && array_filter((array) $item['posts'], static fn (array $post): bool => (int) $post['translationId'] === 0 && (int) $post['id'] > 0) !== []) {
                        $actions[] = 'translate';
                    }
                    break;
                case 'page':
                    if (empty($item['translationId']) && MachineTranslation::isAvailable()) {
                        $actions[] = 'translate';
                    }
                    break;
            }

            if ($item['kind'] === 'svg' && $item['state'] === 'translated') {
                $labels = (array) get_post_meta($attachmentId, SvgTranslation::labelsMetaKey, true);
                $item['labels'] = array_map(static fn ($source, $translation): array => ['source' => (string) $source, 'translation' => (string) $translation], array_keys($labels), array_values($labels));
                $item['current'] = SvgTranslation::isCurrent($attachmentId);
                $actions[] = 'svg-labels';
            }

            if ($item['kind'] === 'svg' && $item['state'] !== 'translated' && SvgTranslation::isAvailable()) {
                $actions[] = 'svg-translate';
            }

            if ($item['kind'] === 'document' && $item['state'] !== 'translated' && $attachmentId > 0) {
                $job = $jobs[(string) $attachmentId] ?? null;

                if (is_array($job)) {
                    $draftId = (int) ($job['attachmentId'] ?? 0);
                    $item['job'] = [
                        'status' => (string) $job['status'],
                        'error' => (string) ($job['error'] ?? ''),
                        'secondsRemaining' => (int) ($job['secondsRemaining'] ?? 0),
                        'billed' => (int) ($job['billed'] ?? 0),
                        'draft' => $draftId > 0 ? ['id' => $draftId, 'title' => wp_basename((string) get_attached_file($draftId)), 'url' => (string) wp_get_attachment_url($draftId)] : null,
                    ];
                    $actions[] = 'document-use';
                    $actions[] = 'document-discard';
                } elseif (DocumentTranslation::isEnabled() && DocumentTranslation::supports((string) get_attached_file($attachmentId))) {
                    // A draft made for another translation in this language costs nothing.
                    $item['draftExists'] = $language && MediaCheck::copyOf($attachmentId, $language->slug) > 0;
                    $actions[] = 'document-translate';
                }
            }

            $item['actions'] = $actions;
            $items[$index] = $item;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $args  value: the new item, form or address; labels: an SVG's corrected labels.
     */
    public static function run(int $postId, string $key, string $action, array $args = []): true|WP_Error
    {
        $check = MediaCheck::get($postId);
        $item = $check ? (array_column($check['items'], null, 'key')[$key] ?? null) : null;

        if (! $item) {
            return new WP_Error('gds_ct_media_gone', __('The translation no longer shows this. Reload the page.', 'gds-content-translation'));
        }

        if (! empty($item['inOriginal'])) {
            return new WP_Error('gds_ct_media_original_pattern', __('This is in the original’s pattern: translate the pattern first.', 'gds-content-translation'));
        }

        // Only what the list offers for it now: not a paid DeepL draft again
        // from a tab opened before the file was translated.
        if (! in_array($action, (array) (self::describe($postId, [$item])[0]['actions'] ?? []), true)) {
            return new WP_Error('gds_ct_media_action', __('This can no longer be done here. Reload the page.', 'gds-content-translation'));
        }

        $attachmentId = (int) ($item['attachmentId'] ?? 0);
        $value = $args['value'] ?? '';
        $pageId = $postId;
        // What is in a pattern is changed there.
        $postId = (int) ($item['in'] ?? 0) ?: $postId;

        $result = match ($action) {
            'replace' => match ($item['kind']) {
                'form' => MediaReplace::form($postId, (int) $item['formId'], (int) $value),
                'embed', 'link' => MediaReplace::url($postId, (string) $item['url'], (string) $value),
                default => $attachmentId > 0
                    ? MediaReplace::attachment($postId, $attachmentId, (int) $value)
                    : new WP_Error('gds_ct_media_not_library', __('This file is not in the media library: change its link in the editor.', 'gds-content-translation')),
            },
            'restore' => MediaReplace::attachment($postId, $attachmentId, (int) ($item['copyOf'] ?? 0)),
            'svg-translate' => SvgTranslation::translateFor($postId, $attachmentId),
            'svg-labels' => SvgTranslation::translateFor($postId, $attachmentId, array_map('strval', (array) ($args['labels'] ?? []))),
            'document-translate' => DocumentTranslation::start($postId, $attachmentId),
            'document-use' => DocumentTranslation::useDraft($postId, $attachmentId),
            'document-discard' => DocumentTranslation::discard($postId, $attachmentId),
            'query-update' => MediaReplace::query($postId, (int) $item['index'], (array) $item['posts']),
            default => new WP_Error('gds_ct_media_action', __('Unknown action.', 'gds-content-translation')),
        };

        if ($postId !== $pageId) {
            MediaCheck::check($pageId);
        }

        return $result instanceof WP_Error ? $result : true;
    }

    /**
     * The forms an editor can choose instead, when Gravity Forms is active.
     *
     * @return list<array{id: int, title: string}>
     */
    public static function forms(): array
    {
        if (! class_exists('GFAPI')) {
            return [];
        }

        return array_values(array_map(
            static fn (array $form): array => ['id' => (int) $form['id'], 'title' => (string) $form['title']],
            (array) \GFAPI::get_forms(true, false, 'title')
        ));
    }
}
