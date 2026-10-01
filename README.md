# GDS Content Translation

WordPress plugin for Polylang Pro editorial workflows: translation status dashboard, one-click machine translation or source-language copy, block attribute translation rules, post ID sync, and internal link remapping.

Requires [Polylang Pro](https://polylang.pro/) with optional DeepL machine translation.

## Requirements

- PHP >= 8.0
- WordPress >= 6.2 (block notes counts need 6.9; older versions simply show none)
- Polylang Pro >= 3.3

## Installation

```bash
composer require generoi/gds-content-translation
wp plugin activate gds-content-translation
```

For local path development (before the package is on Packagist):

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "web/app/plugins/gds-content-translation",
      "options": { "symlink": true }
    }
  ],
  "require": {
    "generoi/gds-content-translation": "@dev"
  }
}
```

## Features

### Translation status admin

Polylang → **Content translation status**: one tab per translated post type, one row per post in the default language, one column per translation language. The title opens the original in the editor, with **View**, its open block notes and **Trash all** beside it.

**Trash all** moves the original and every translation of it to the trash in one go, after a confirmation that names the post and says how many translations go with it. Posts already in the trash are left as they are. If you may not delete every one of them, nothing is changed. The row then leaves the list, and the notice's **Undo** restores exactly the posts it trashed, each with the status it had before. Use it to clear out placeholder posts; nothing is deleted permanently.

The summary counts missing and not-proof-read translations per language. Each count is a filter: select it to show only those rows, select it again (or **Clear**) to show all. The title search matches what you type; only when nothing does, it tolerates a missing letter within a word. The column headings stay in view while scrolling, and below 600px each post becomes a card.

Two ways to create a missing translation:

- **AI translation** — machine-translates the source post with Polylang Pro's configured service (DeepL). Only shown when machine translation is enabled and a service is configured.
- **Copy original** — creates the translation as a verbatim copy of the source (default) language, with no machine translation involved. Needs no service, so it also works when DeepL is unconfigured or out of quota.

Both run through Polylang Pro's translation pipeline, so blocks, internal links, post IDs, terms and metas are remapped to the target language either way, and both create the translation as a **draft** and open it in the editor in a new tab. The cell shows **Creating…** and its buttons are disabled until the status screen reloads, which it does by itself when you come back to the tab. A second request for the same post and language while one is running is refused, so a double click cannot create two translations.

Edit links, View, AI translation and Copy original all open in a new tab, so the status screen stays where it was.

Each existing translation has:

- **Edit** and **View** — View opens the post on the site (its preview while it is unpublished). Not shown for post types without a front end, such as template parts.
- **Proof read** — a checkbox, saved at once. A failed save (an expired session, say) is reported next to it.
- **Trash** — moves that translation to the trash after a confirmation that names the post and language. Only translations: the source-language post is never trashed from here.

A trashed translation is shown as **In trash** (grey, not the red of Missing) and counted as missing. It offers:

- **Restore** — brings it back with the status it had before (not as a draft).
- **AI translation** / **Copy original** — creates a new one; the trashed post is unlinked from the source and stays in the trash. If creating fails, it is linked again, so it can still be restored.
- **Delete permanently** — deletes the trashed translation for good, after a confirmation naming the post and language. Only for a translation that is already in the trash; the cell then shows **Missing**.

Trash, Restore and Delete permanently come back to the same row with the search kept, and say what happened in a notice that names the post and language. After Trash the notice has an **Undo** link. Keyboard focus comes back to the cell the action was taken in, and screen readers announce the notice; after Trash all, whose row is gone, focus goes to the notice and its Undo. Notices from actions in other tabs are kept until a status screen shows them, not replaced.

Polylang keeps a trashed translation linked to its source, which is why it used to appear as translated.

Polylang → **Content translation settings**: choose which post types appear as tabs on the status screen. Unchecked types are hidden from the dashboard UI.

Programmatic exclusions still work via filter (always applied on top of saved settings):

```php
add_filter('gds_content_translation_excluded_post_types', function (array $postTypes): array {
    return array_merge($postTypes, ['shop_order']);
});
```

### Polylang block integration

The plugin registers Polylang Pro hooks and exposes **project-specific rules via WordPress filters**. Themes (or site-specific mu-plugins) declare which custom block attributes are translatable strings, which hold post/attachment IDs, and which hold internal URLs.

Built-in defaults (no theme code required):

- `core/query` handpicked posts (`query.include`) → sync post IDs
- `core/button` `url` attribute + `<a href>` in block HTML → rewrite internal links

## Configuring block rules (themes)

Add filters in your theme `app/filters.php` (or a small mu-plugin). The plugin merges your rules into Polylang’s native filters.

### 1. Translatable text attributes (DeepL / XLIFF)

Use for RichText and other string attributes stored in block JSON (not inner HTML).

Maps to Polylang’s `pll_blocks_rules_for_attributes`.

```php
add_filter('gds_content_translation_pll_blocks_rules_for_attributes', function (array $rules): array {
    return array_merge($rules, [
        'my-theme/hero' => [
            'heading' => true,
            'intro' => true,
        ],
        'my-theme/feature-list' => [
            'items' => [
                '*' => [
                    'title' => true,
                    'description' => true,
                ],
            ],
        ],
    ]);
});
```

### 2. Post / attachment ID sync

Use when a block stores a **numeric ID** that should point at the translated post or attachment after machine translation or content sync.

Maps to Polylang’s `pll_sync_block_rules_for_attributes`.

```php
add_filter('gds_content_translation_pll_sync_block_rules_for_attributes', function (array $rules): array {
    return array_merge($rules, [
        'my-theme/post-teaser' => [
            'post' => [
                'postId' => true,
            ],
        ],
        'my-theme/media-card' => [
            'attachment' => [
                'mediaId' => true,
            ],
        ],
    ]);
});
```

Types: `post`, `term`, `attachment`, `wp_block`.

### 3. Internal URL attributes

Use when a block stores a **URL string** (not an ID) in attributes — e.g. card blocks with a `url` field.

The plugin also rewrites `<a href>` inside block HTML (buttons, paragraphs). This filter adds attribute-based URLs.

```php
add_filter('gds_content_translation_link_url_attributes_by_block', function (array $attributesByBlock): array {
    return array_merge($attributesByBlock, [
        'my-theme/numbered-card' => ['url'],
        'my-theme/info-card' => ['url'],
    ]);
});
```

### 4. Post meta (custom fields)

Use for plain `register_post_meta()` / meta box values that are **frontend text** and should be machine-translated.

Maps to Polylang’s `pll_post_metas_to_export`. Keys are grouped by post type.

```php
add_filter('gds_content_translation_pll_post_metas_to_export', function (array $rulesByPostType): array {
    return array_merge($rulesByPostType, [
        'person' => [
            'person_job_title' => 1,
            'person_department' => 1,
            'person_sales_area' => 1,
        ],
    ]);
});
```

Use `1` for scalar string metas. Nested array metas use the same shape as Polylang’s export rules (sub-key => 1).

Keep locale-invariant metas (phone, email, attachment IDs) on `pll_copy_post_metas` sync only — do not list them here.

### 5. ACF fields

Polylang Pro handles ACF via field-level translation modes. Set defaults for fields that are not configured in the ACF UI:

```php
add_filter('gds_content_translation_acf_field_translations', function (array $modesByFieldName): array {
    return array_merge($modesByFieldName, [
        'material_file' => 'copy_once', // attachment ID
        'hero_intro' => 'translate',    // frontend text
    ]);
});
```

Modes: `translate`, `translate_once`, `copy_once`, `sync`, `ignore`.

## LOFS / GDS theme example

The [lofs](https://github.com/generoi/lofs) theme registers its `gds/*` blocks in `app/filters.php`:

```php
add_filter('gds_content_translation_pll_blocks_rules_for_attributes', function (array $rules): array {
    return array_merge($rules, [
        'gds/timeline' => ['tag' => true, 'heading' => true],
        'gds/check-list' => [
            'items' => ['*' => ['title' => true, 'description' => true]],
        ],
        // …
    ]);
});

add_filter('gds_content_translation_pll_sync_block_rules_for_attributes', function (array $rules): array {
    return array_merge($rules, [
        'gds/post-teaser' => ['post' => ['postId' => true]],
        'gds/media-card' => ['attachment' => ['mediaId' => true]],
    ]);
});

add_filter('gds_content_translation_link_url_attributes_by_block', function (array $attributesByBlock): array {
    return array_merge($attributesByBlock, [
        'gds/numbered-card' => ['url'],
        'gds/info-card' => ['url'],
    ]);
});
```

## When rules run

| Feature | When | Persisted? |
|---------|------|------------|
| Text attributes | Machine translation, XLIFF import, sync | Yes — saved in post content |
| Post meta text | Machine translation, XLIFF import | Yes — saved in post meta |
| ACF text fields | Machine translation, XLIFF import | Yes — saved in post meta |
| ID sync (`postId`, etc.) | Machine translation, Polylang content sync | Yes |
| Link remapping (sync hook) | Machine translation, Polylang content sync | Yes |
| Link remapping (render hook) | Every frontend block render | No — runtime fallback for old content |

The render hook skips links that are already in the page's language (read from the URL, no database), and caches each URL lookup until a post or term changes. Content created or synced through Polylang Pro already has its links rewritten, so a site whose content is clean can switch the render pass off:

```php
add_filter('gds_content_translation_translate_links_on_render', '__return_false');
```

If a translation does not exist for a linked post, IDs become `0` (teaser hidden) and URLs are left unchanged.

## Development

```bash
cd web/app/plugins/gds-content-translation
composer install
composer lint:fix
```

## Testing

Tests run against a real WordPress install with Polylang active, provided by
[`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)
(requires Docker).

```bash
npx @wordpress/env start
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/gds-content-translation vendor/bin/phpunit
npx @wordpress/env stop
```

`tests/Unit` covers the rule-merging filters, request parsing and notices,
`tests/Integration` covers block translation and the status screen's Delete
permanently and Trash all actions against configured `en` / `fi` languages.
Tests that need Polylang skip themselves when it is not installed.

`composer.json` sets `config.platform.php` to 8.3, the PHP version CI and
wp-env run, so a `composer update` on a newer PHP cannot pick dev packages they
cannot install. It does not change the plugin's own PHP requirement.

## Changelog

### 1.1.0

- **Delete permanently** for a translation in the trash, on the status screen.
- **Trash all** in the title cell: the original and all its translations to the trash in one go, with an Undo that restores each with its previous status.
- After Trash, Restore, Delete permanently and Undo, focus returns to the cell the action was taken in and the notice is announced. Several notices are kept instead of the last one replacing the others.
- Layout: Trash sits right after Proof read instead of at the column's far edge, language columns have more space on their right, Delete permanently no longer leaves a stray separator when it wraps, every cell is top-aligned, and Restore / Copy original stay 24px tall like the links beside them on narrow screens.
- Status screen: one query per kind of data instead of three per translation (760 → 86 queries on a 678-row tab with 225 translations), and markup a third the size (6.4 MB → 2.0 MB).
- Front end: the link fallback no longer looks up links that are already in the page's language, and caches the rest. A link to a default-language post on a translated page now resolves (Polylang limited the lookup to the page's language, so it never did).
- Creating a translation: validated before anything is written, refused while the same one is being created, and an error from Polylang Pro or a filter is reported as a notice instead of a 500. A trashed translation is linked again when creating its replacement fails.
- Saving normalised block IDs no longer strips JSON escapes and backslashes from the content.
- Layout: cells wrap inside their column instead of spilling into the next, the source column is folded into the title, sticky column headings, a stacked layout on phones, 24px buttons, screen-reader names that say which post and language each control is for.
- Proof-read celebration: one canvas and one animation loop for the page, so checking several rows in a row no longer janks.
- Notices name the post and language, and no longer reappear on reload.
- Crafted array query arguments no longer cause a 500 on Acorn sites.
- Assets are versioned by file, so an update is never served from a stale cache. The admin-bar stylesheet is gone (its selectors never matched).
- Requires WordPress 6.2 (`WP_HTML_Tag_Processor`).

## License

MIT
