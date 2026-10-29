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

Polylang → **Content translation status**: one tab per translated post type, one row per post in the default language, one column per translation language. The screen is a [DataViews](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-dataviews/) table that loads a page of rows at a time from the plugin's REST API, so a type with thousands of posts opens as fast as one with ten. The title opens the original in the editor, with **View**, its status when it is not published, and its open block notes beside it.

The table:

- **Search** matches titles; only when nothing matches what you typed, it tolerates a missing letter within a word. Searching, filtering, sorting and paging run on the server over every row.
- **Sorting** by title or date, **pages** of 20, 50, 100 or 200 rows, and **columns** that can be hidden (each language, the date) in the view options. Column choice, sorting and page size are remembered per post type; search, filters and page for the browser tab, so coming back from the editor shows the same rows.
- **Filters** per language: missing, not proof read, open AI review errors, AI review running. Within a language any of the chosen; across languages all of them.
- The **summary** above the table counts, per language, missing and not-proof-read translations, open AI review errors and running reviews. Each count is a filter: select it to show only those rows, select it again (or **Clear**) to show all.
- Below 600px each post becomes a card, each language labelled.

Two ways to create a missing translation, in its cell:

- **AI translation** — machine-translates the source post with Polylang Pro's configured service (DeepL). Only shown when machine translation is enabled and a service is configured. With the AI review on, it lands on the review page in the same tab.
- **Copy original** — creates the translation as a verbatim copy of the source (default) language, with no machine translation involved. Needs no service, so it also works when DeepL is unconfigured or out of quota.

Both run through Polylang Pro's translation pipeline, so blocks, internal links, post IDs, terms and metas are remapped to the target language either way, and both create the translation as a **draft**. Copy original (and AI translation without the review) opens it in the editor in a new tab; the cell shows **Creating…** until you come back to the status tab, which then reloads its rows. A second request for the same post and language while one is running is refused, so a double click cannot create two translations.

Each existing translation has:

- **Edit** and **View** — View opens the post on the site (its preview while it is unpublished). Not shown for post types without a front end, such as template parts.
- The **AI review** marker (see below), or **Run AI review** for a translation without one.
- **Proof read** — a checkbox, saved at once. A failed save (an expired session, say) is reported next to it.
- **Trash** — moves that translation to the trash after a confirmation that names the post and language, with **Undo** in the notice. Only translations: the source-language post is never trashed from a cell.

A trashed translation is shown as **In trash** (grey, not the red of Missing) and counted as missing. It offers **Restore** (with the status it had before, not as a draft), **AI translation** / **Copy original** (a new one; the trashed post is unlinked and stays in the trash, and is linked again if creating fails) and **Delete permanently** (after a confirmation). Polylang keeps a trashed translation linked to its source, which is why it used to appear as translated.

#### Bulk actions

Select rows (the checkbox per row, or the header's for the page) and pick an action under the table; each is also in the row's own actions menu. Each asks first, in a dialog that says how many translations it touches:

- **AI translation…** into one language or all the languages the rows are missing. The translations are created **in the background, one at a time**, so a request never times out: a panel above the table shows how many are done, which post is being translated, a progress bar, and per post what failed and why; the cells show **Queued for AI translation** and **Translating…**. You can leave the screen meanwhile. **Cancel N queued** drops the ones not started; **Dismiss** clears the panel once all are done. More than ten translations need an extra confirmation (they use the DeepL quota). Each is created exactly as the cell's AI translation is, as the user who queued it: the same checks, the same per-post and language lock, and the AI review queued after it when that is on.
- **Run AI review…** on the rows' translations in one or all languages (skipping one that is being reviewed or that someone has open in the editor).
- **Mark proof read…** / **Unmark proof read…**, in one or all languages.
- **Trash all…** / **Move to trash…**: the originals with all their translations, after a confirmation that names the post (or counts the posts) and its translations. Posts already in the trash are left as they are; if you may not delete every post of a row, that row is not changed at all. The notice's **Undo** restores exactly the posts the action trashed, each with its previous status. Nothing is deleted permanently.

Capabilities are checked per post on the server for every action, whatever the screen offered.

How the background queue runs: each post and language is a job, stored as a meta of the original (`_gds_ct_job_<language>`). A non-blocking request to `admin-ajax.php` (authorised by a token made with the site's salts, valid ten minutes) starts a worker, which translates jobs for about 20 seconds (`gds_content_translation_bulk_budget`) and then hands over to a fresh request until the queue is empty. Only one worker runs at a time. A WP-Cron event a minute later is the fallback, and the screen's progress poll starts a worker itself when jobs have waited 45 seconds with none running (a host whose loopback requests fail). A job left running by a worker that died is tried again, up to three times. Finished jobs are forgotten after a day, or when dismissed.

The admin-post links of the previous screen (`gds_ct_trash_translation`, `gds_ct_restore_translation`, `gds_ct_delete_translation`, `gds_ct_trash_row`, `gds_ct_restore_row`, and `gds_ct_machine_translate` for creating) still work and come back with a notice; the screen and the links share the same code (`TranslationActions`, `MachineTranslation::create()`).

REST API (`gds-content-translation/v1`, for users who can `manage_options`):

| Route | |
|-------|-|
| `GET /rows?post_type=page&page=1&per_page=50&search=&orderby=title\|date&order=asc\|desc&filters[en][]=missing` | One page of rows, `total`, `totalPages` and the summary of the whole type. |
| `POST /actions/{action}` `{ids, args}` | `proofread` (`args.proofread`), `review`, `translate`, `trash`, `restore` (`args.posts`: row ID → post IDs), `trash-translation`, `restore-translation`, `delete-translation`. `ids` are default-language posts, `args.languages` the languages (all when empty). A result per row and a `message`. |
| `GET /jobs`, `DELETE /jobs?which=queued\|finished` | The bulk AI translation queue. |

Polylang → **Content translation settings**: choose which post types appear as tabs on the status screen. Unchecked types are hidden from the dashboard UI.

Programmatic exclusions still work via filter (always applied on top of saved settings):

```php
add_filter('gds_content_translation_excluded_post_types', function (array $postTypes): array {
    return array_merge($postTypes, ['shop_order']);
});
```

### AI review of machine translations

When a translation is created with **AI translation**, Claude compares its **post content and ACF text fields** (text, textarea, WYSIWYG; repeater rows included) with the original. Title, excerpt, other metas and terms are not reviewed, and Copy original is never reviewed. The translation itself only changes when an editor uses a suggestion.

- The review runs **in the background** once the translation is saved, so the editor opens without waiting. A non-blocking request to `admin-ajax.php` (authorised by a one-time token) starts it at once; a WP-Cron event two minutes later is the fallback. A review that has not finished after 10 minutes shows as *did not finish*.
- Original and translation are paired with Polylang Pro's own translation walker, top-level block by top-level block, repeated texts included. Texts in blocks that were added, removed or moved count as *not matched* and are not reviewed. Claude gets numbered `{id, source, translation}` pairs plus the page title and both languages.
- The status screen cell shows **AI review: 3 errors · 5 to check**, **AI review: all handled**, **AI review running…**, **AI review failed** or **did not finish**, and **· edited since** once the translation has changed after the review. Rows with a review running reload by themselves until it is done.
- At most three reviews run at once (`gds_content_translation_audit_parallel`); more wait their turn, each finished review starting the next, so a bulk **Run AI review** on many translations does not start a PHP process per translation.
- The review page shows the original, the translation and the remark side by side, with *open remarks*, *all remarks* or *all texts*. Each remark can be marked **Resolved** or **Dismissed** (and reopened); the cell counts only open remarks. A text that changed after the review is marked *Text changed since the review*. **Run the review again** reviews the current text; earlier remark states start over.
- Remarks are written in the translation's language by default (settable to the original's or the site's admin language). Remarks whose suggestion is the current text, or that point at no text, are dropped.

Link URLs are no longer translated: Polylang Pro marks the `href` of buttons and linked images as text, which made DeepL translate internal paths (`/ohjeet/` → `/instruktioner/`) and broke the links. The source URL is kept and the link rewriting below points it at the target language's page. To translate link URLs as text again: `add_filter('gds_content_translation_translate_link_urls', '__return_true');`

Setup:

1. Create an API key in the [Anthropic Console](https://console.anthropic.com/) (one per site, with a monthly spend limit).
2. Set it in the site environment, e.g. `.env`: `ANTHROPIC_API_KEY=sk-ant-...`. It is looked up in the same order as gds-assistant does: the `ANTHROPIC_API_KEY` environment variable, then a constant with that name, then the WordPress Connectors option `connectors_ai_anthropic_api_key`. A site that already has the key for gds-assistant needs nothing more.
3. Polylang → **Content translation settings** → *AI review of machine translations*: on by default when a key is found. Choose the model (Claude Sonnet 5.5 by default) and the language of the remarks there.

The call goes through the WordPress HTTP API; the plugin adds no Composer dependencies.

```php
// Turn the review off for a site regardless of the setting.
add_filter('gds_content_translation_audit_enabled', '__return_false');

// Use another model than the one chosen in the settings.
add_filter('gds_content_translation_audit_model', fn () => 'claude-opus-5-5');
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

The status list is a React app (TypeScript, `resources/`) built with [`@wordpress/scripts`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/) into `build/`. WordPress' own React and `wp-*` packages are used from WordPress (externalised by the dependency extraction plugin, listed in `build/index.asset.php`); `@wordpress/dataviews`, which WordPress does not ship, is bundled. The AI review page and the settings stay server-rendered PHP, with `assets/admin.js` and `assets/admin.css`.

```bash
npm ci
npm run start       # watch while developing
npm run lint:js     # ESLint
npm run lint:types  # tsc
npm run build       # production build into build/
```

**`build/` is committed.** Sites install the plugin from GitHub or Packagist without a build step, so the built files have to be in the repository: run `npm run build` and commit `build/` with every change to `resources/`. CI rebuilds and fails when `build/` does not match the sources. The sources and Node configuration are `export-ignore`d (`.gitattributes`), so they stay out of the package Composer installs.

## Testing

Tests run against a real WordPress install with Polylang active, provided by
[`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)
(requires Docker).

```bash
npx @wordpress/env start
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/gds-content-translation vendor/bin/phpunit
npx @wordpress/env stop
```

`tests/Unit` covers the rule-merging filters, request parsing, notices and the
summary counts, `tests/Integration` covers block translation, the status screen's
REST API (rows, search, filters, paging, every action and its permission checks),
the bulk translation queue and its worker (with the machine translation faked),
the admin-post Delete permanently and Trash all actions, and the AI review (with
the Claude API faked) against configured `en` / `fi` languages.
Tests that need Polylang skip themselves when it is not installed.

`composer.json` sets `config.platform.php` to 8.3, the PHP version CI and
wp-env run, so a `composer update` on a newer PHP cannot pick dev packages they
cannot install. It does not change the plugin's own PHP requirement.

## Changelog

### Unreleased

- **Status screen redesign** after three rounds of UI/UX review: one 41px line per row, no layout shift (CLS 0), language names in the admin's own language (Finnish/English/Swedish, or suomi/englanti/ruotsi), one line of filter chips, a status chip (Draft/Published, tick when proofread) and one AI badge per translation, everything else in a ⋯ menu shown on hover or focus, cards with the languages side by side on phones, no browser confirm dialogs.
- **AI translate stays on the status screen**: the cell shows Translating…, then the translation and its AI review, without leaving the screen.
- **AI translations are published when their original is**, and the patterns they use always (a draft pattern shows nothing on a page). Copy original still creates drafts. Off under Content translation settings → AI translations.
- **The AI review covers ACF text fields**, with Use suggestion writing back to the field.

- **The status screen is rebuilt with DataViews**, replacing the server-rendered table: rows load a page at a time from a REST API that searches (with the one-missing-letter tolerance), filters and sorts every row on the server, so large sites open quickly. Sorting by title or date, page sizes, hideable columns, filters per language (missing, not proof read, open AI errors, review running), the summary counts as filters, a card layout on phones. Every cell action of the old screen stays; Trash, Restore and Delete permanently no longer reload the page, and Trash has Undo in a notice.
- **Bulk actions** on selected rows: AI translation into one or all missing languages, created in the background one at a time with a progress panel, per-cell state and per-post errors; Run AI review; Mark / Unmark proof read; Trash all with one Undo for the batch. Each asks first; capabilities are checked per post on the server.
- At most three AI reviews run at once; more wait their turn.
- `build/` (the built app) is committed and shipped; see Development.
- **AI review of machine translations**: after an AI translation is saved, Claude compares its post content with the original in the background. The status screen shows open errors and other remarks per translation and links to a review page with original, translation and remark side by side, where remarks are resolved or dismissed and the review is run again. Needs `ANTHROPIC_API_KEY`; settings under Content translation settings.
- Link URLs of buttons and linked images are no longer sent to machine translation, which broke internal links (and `#anchors`).

### 1.1.1

- **Trash all** moves to its own column at the far right of each row, in a muted colour that turns red on hover or focus, away from View and the per-language actions.

### 1.1.0

- **Delete permanently** for a translation in the trash, on the status screen.
- **Trash all** at the far right of the row: the original and all its translations to the trash in one go, with an Undo that restores each with its previous status.
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
