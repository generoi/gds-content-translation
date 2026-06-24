# GDS Content Translation

WordPress plugin for Polylang Pro editorial workflows: translation status dashboard, one-click machine translation, block attribute translation rules, post ID sync, and internal link remapping.

Requires [Polylang Pro](https://polylang.pro/) with optional DeepL machine translation.

## Requirements

- PHP >= 8.0
- WordPress >= 6.0
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

Polylang → **Content translation status**: overview of missing translations, proof-read flags, open block notes, and one-click machine translate per language.

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

If a translation does not exist for a linked post, IDs become `0` (teaser hidden) and URLs are left unchanged.

## Development

```bash
cd web/app/plugins/gds-content-translation
composer install
composer lint:fix
```

## License

MIT
