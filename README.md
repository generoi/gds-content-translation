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

When a translation is created with **AI translation**, Claude compares its **post content, ACF text fields** (text, textarea, WYSIWYG; repeater rows included), **title, excerpt and Yoast SEO texts** (SEO title, meta description, social and X titles and descriptions) with the original. Fields that are empty or still the same as the original are skipped, and Yoast variables such as `%%title%%` are kept in every suggestion. Other metas and terms are not reviewed, and Copy original is never reviewed. The translation itself only changes when an editor uses a suggestion, unless the suggestions are applied automatically (below).

What happens after the review of a new AI translation is chosen when translating: **AI translate** in a cell opens a *Start AI translation* menu, and the bulk **AI translation…** dialog has the same three choices (*How to AI translate*):

- **Translate, I’ll review**: an editor goes through them on the review page (as before).
- **Translate and fix automatically**: every remark with a suggestion is written into the translation, as **Use suggestion** does, and marked *Fixed: suggestion applied automatically*. A remark without a suggestion, or whose text changed in between, stays open. No revision is saved per suggestion.
- **Translate, fix, then check again**: as above, then the translation is reviewed once more; that second review's remarks are left to an editor, and its page says how many suggestions were applied before it.

The default is set under Content translation settings → *Default way to AI translate* (manual unless changed). The REST action takes it as `args.followup` (`manual`, `apply`, `apply_review`); a link to the old admin-post handler uses the default.

**Instructions for the review.** Content translation settings → *Instructions for the AI review* is sent with every review: required terms, names that stay untranslated, tone (for example “*Read more* is always *Läs mer* in Swedish, never *Läs vidare*”). When starting an AI translation, *Add instructions for the AI review (optional)* in the cell's menu and the bulk dialog adds instructions for those translations only (`args.instructions` on the REST action), kept for *Run the review again*. Claude reports a translation that does not follow them, and its suggestions follow them, so with *fix automatically* they end up in the translation. They do not reach DeepL (Polylang Pro's DeepL client takes none); the translation glossary below is the place for terms there. The review page shows the instructions it used under *About this review*. Each is limited to 4,000 characters.

- The review runs **in the background** once the translation is saved, so the editor opens without waiting. A non-blocking request to `admin-ajax.php` (authorised by a one-time token) starts it at once; a WP-Cron event two minutes later is the fallback. A review that has not finished after 10 minutes shows as *did not finish*.
- Original and translation are paired with Polylang Pro's own translation walker, top-level block by top-level block, repeated texts included. Texts in blocks that were added, removed or moved count as *not matched* and are not reviewed. Claude gets numbered `{id, source, translation}` pairs plus the page title and both languages.
- The status screen cell shows **AI review: 3 errors · 5 to check**, **AI review: all handled**, **AI review running…**, **AI review failed** or **did not finish**, and **· edited since** once the translation has changed after the review. Rows with a review running reload by themselves until it is done.
- At most three reviews run at once (`gds_content_translation_audit_parallel`); more wait their turn, each finished review starting the next, so a bulk **Run AI review** on many translations does not start a PHP process per translation. The WP-Cron fallback waits for a free place too. A review can't be started while one is already running.
- A review with *fix automatically* counts as running until its suggestions are in, so the status screen keeps asking and never shows the remarks they fix.
- *Edited since* also shows when the translation was saved while the review ran.
- Soft hyphens an editor put in a word (wp-gutenberg-shy's `is-shy-character` span, `&shy;`) are taken out of what DeepL and Claude get, as they split the word in two.
- The review page puts the remarks first: a **Mark as proofread** line, then a DataViews table with the original, the translation (with the suggestion's changes marked in it) and the remark side by side. The counts above it filter by severity; *Open*, *Handled* and *Whole page* switch between open remarks, handled ones and every text. Search, a filter by kind, and bulk actions (**Use suggestion**, **Mark as fixed**, **Not an issue**, **Reopen**) work on the selection; **Edit…** writes a text by hand. The summary and the review's details with **Run the review again** are folded away above the table; the links that still lead to the original's pages are in the list of files above it (below). A text that changed after the review is marked *Changed since the review* and can only be marked fixed. The table loads from `GET /gds-content-translation/v1/reviews/{id}` and changes remarks through `POST /reviews/{id}/remarks`.
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

### Soft hyphens in long words

Finnish and Swedish compounds (*pilariperustukset*, *terrassbrädorna*) overflow headings and buttons on narrow screens. When an AI translation is created, the long words (12 letters or more, `gds_content_translation_hyphenation_min_length`) of its headings (`h1`–`h6`) and button links get a few soft hyphens where the language may break them (*Plint·grunder*, *Kontakt·formulär*), by TeX's hyphenation patterns ([vanderlee/syllable](https://github.com/vanderlee/phpSyllable)): about one every eight letters, never leaving fewer than four letters on a side.

- They are written as [wp-gutenberg-shy](https://github.com/generoi/wp-gutenberg-shy) writes them (`<span class="is-shy-character">&shy;</span>`): an editor sees them in the block editor and can move or remove them, and that plugin shows each as a plain soft hyphen on the page. Without it, the span still works as a soft hyphen.
- A word that has a soft hyphen already is the editor's and is left as it is; body text, code and words in capitals are left alone.
- DeepL and Claude get the text without them. A used suggestion keeps them in the words it did not change, and a text that had them gets them in its new words too.
- **Add soft hyphens…** on the status screen (a row's menu, or bulk) adds them to originals and to translations made before: the originals and their translations, only the originals, or only one language's translations. A post someone has open in the editor is left out.
- Content translation settings → *Soft hyphens*, on by default; `gds_content_translation_hyphenation` filters it. Off when vanderlee/syllable is not installed (the plugin installed without Composer).

### Check the page for text in the original's language

The AI review compares the post's own texts. A page shows more: the theme's header and footer, menus, synced patterns, image alt texts, the title and the texts of shared links. **Check the page for Finnish…** (a translation's cell menu, its AI review page, and the bulk action **Check the pages for Finnish…**) reads the page as a visitor gets it and has Claude list what is still in the original's language or another one. Run it when a translation is done, to find what was missed.

- **Only on demand**: it costs a Claude call per page and never runs on save. A dialog first says how many texts and characters would be sent and the estimated price at the review model's rate; the bulk action totals it over the selection. Checks run in the background, one page at a time.
- **What is read**: visible text (not scripts or styles), `alt`, `title`, `aria-label`, `aria-description`, `placeholder`, button values, the title, meta description and Open Graph/Twitter tags, JSON-LD strings and inline SVG texts, grouped by where they are (header, menu, content, footer). Product, brand and proper names and the glossary's approved translations are fine. Text a script adds after the page loads is not seen.
- **Drafts** are checked through their preview, fetched by the site itself with a five-minute login of the editor who asked, ended at once; published pages as anyone sees them.
- **Each finding** says where it is, its likely source (the page's content, a synced pattern, a template part or theme file, an image's alt text in the media library, a Polylang string, a menu) with a link to fix it there, and a suggestion. Results are kept on the translation and say *The page has changed since this check* once it has.
- Prices are in `TranslationAudit::modelPrices()`, US dollars per million tokens; `gds_content_translation_claude_prices` filters them.

REST: `GET /scans/{id}`, `GET /scans/{id}/estimate`, `POST /scans/{id}`, and the action `page-scan` (`args.languages`, `args.estimate`).

### Translation glossary

Polylang → **Translation glossary** keeps the terms with the translation they must have: product names, technical words, fixed phrases. Terms are per language pair by language code (`EN` → `SV`), as DeepL names them, so two markets in one language share them. One database table per site (`{prefix}gds_ct_glossary`), created on the first request after an update.

- **The screen**: a DataViews table of the terms, searched and filtered by language, with *In use*, *Suggested*, *Drafts* and *Rejected*. Terms are added and edited in a dialog, with a note; several at once are approved, moved to drafts, rejected or deleted. A source with two approved translations is marked as a conflict.
- **CSV import and export** in DeepL's format: no header, `"source","target","EN","SV"`, UTF-8 (a byte order mark is fine). *Add and update* adds new terms and gives an approved term the file's translation of it; *Replace* first removes the approved and draft terms of the language pairs in the file. A two-column file takes the language chosen. The export is the same format.
- **DeepL**: approved terms are sent to DeepL as one multilingual glossary (DeepL's v3 API) with a dictionary per language pair, within a minute of a change and before the next AI translation; *Send to DeepL now* sends at once. The new glossary is created first, and the old one is deleted 15 minutes later, after the requests still using it. A failed send is tried again after 5 minutes, then less often, up to an hour. Polylang Pro's DeepL client has no glossary setting, so the glossary's ID is added to its `/v2/translate` requests through `http_request_args`, for the language pairs the glossary has. DeepL uses a glossary only for exact matches of a term.
- **AI review**: the approved terms found in the page (whole words, any case, at most 150, longer terms first) are sent with the review as required translations. A different translation is a terminology remark; inflected forms are fine, and suggestions use the glossary. *About this review* says how many terms were checked.
- **Suggestions from the review**: a remark about how a term should be translated across the site carries the term, and it becomes a suggestion on the glossary screen, with the text it was seen in and its page. Seen on another page, or used with **Use suggestion**, it counts one more (*Seen 3 times*).
- **Suggestions from proofreading**: the texts of an AI translation are kept when it is created. When it is marked proofread, the texts an editor changed are compared with them in the background (WP-Cron), and Claude names the term changes (*offert* became *offertförfrågan*) as suggestions. Once per translation; needs the Anthropic key. Off with `add_filter('gds_content_translation_glossary_learn', '__return_false');`.
- Accepting a suggestion rejects the other approved translations of its source; a rejected suggestion is not suggested again.
- The **AI translate** menu and the bulk dialog say whether the language has a glossary (*Glossary in use: 509 terms for Swedish* or *No glossary for Swedish*), with links to edit it or replace it with a CSV. The glossary screen takes `&target_lang=SV`, `&status=suggested` and `&import=1` in its URL.

REST, under `gds-content-translation/v1`: `GET|POST /glossary`, `POST /glossary/{id}`, `POST /glossary/bulk`, `POST /glossary/import`, `GET|POST /glossary/sync`. The DeepL key is Polylang Pro's (`gds_content_translation_deepl_api_key` filters it).

### Files, images and forms still in the original language

Machine translation translates the page's text. What the page shows besides its text stays the original's: the PDF behind *Download*, the labels in an illustration, the form, the video. The translation then reads as finished. Each translation is checked for those when it or its original is saved; the pages showing a saved synced pattern or a changed form are checked again in the background, a batch at a time. The whole site is checked in the background after an update (WP-Cron, 25 translations a run; the status screen says *Checking files and images: 120 of 340* meanwhile).

What is found, in the content's blocks and HTML and the featured image:

- **Files** from the media library or the uploads folder: PDF, Word, Excel, PowerPoint, OpenDocument, CSV, ZIP and the like.
- **SVG images with text**: their `<text>`, `<title>` and `<desc>`, shown as found. Text without letters (*28 × 120*) is no text to translate.
- **Images with words in them**: Claude looks at each file once (its *large* size, from the AI review's key and model, while the AI review is on) and lists the words a reader would need in their own language, not logos, product names or codes. The answer is kept until the file changes. An image Claude could not check (over 3.5 MB, unreadable, or the check failed) is listed as not checked, with the reason; a failed check is tried again later, waiting longer each time.
- **Gravity Forms forms** (block or shortcode): shown as written in the form.
- **Embeds** (YouTube, Vimeo…).
- **Links to other sites** that look like the original's language: its country domain (`.fi`, not for English) or a `/fi/`, `?lang=fi` address, unless the address is for the translation's language (`/sv/`).
- **Synced patterns** the page shows are read too: what is in one is listed with the pattern, and an action changes the translation's pattern, never the original's. The same file on the page and in a pattern is one item, changed in all of the translation's own places.
- **Query loops** whose chosen posts (`include`) or parent page have no translation in the language, or are another language's: Polylang makes such a post 0, and the section shows fewer posts or none. The item lists the posts, with *AI translate*, and *Update the list* once they are translated.
- **Links to the site's pages** with no published translation in the language, also in synced patterns: they lead to the original's page until one is published, and the item then goes by itself (a translation's save, publish or deletion checks again the pages that link its original).
- **Texts still to be written** in the translation or its original: `[TÄYTÄ]`, `[ATT FYLLA I]`, `TODO`, `TBD`, `XXX`, *lorem ipsum*, words in capitals in brackets (not a short abbreviation such as `[PDF]`). Only visible text is read; `gds_content_translation_placeholder_patterns` filters the patterns.

Only what the original shows too counts for files, forms and addresses (query loops, links to pages and texts to write count either way): a translation that links its own PDF, shows another image or another form has handled it. Files are compared by file, not by media library item, so Polylang's media translation (one item per language, one file) is handled too. Media blocks are found by their `id`, `mediaId`, `ids` and `…ImageId`/`…MediaId` attributes; `gds_content_translation_media_items` filters what is found for a post.

- **Status screen**: the cell shows *2 in Finnish*, opening the list in a dialog; the cell's menu has *Files, images and forms* to open it any time. *Files still in Finnish* is a count per language above the table and a filter.
- **AI review page**: the list is above the remarks, open while something is.
- Each item says what it is and what to do, links to the file, form or address, and can be marked **Fine as it is** (a PDF that only exists in Finnish) or **Count again**.

What can be done about each, in the translation only (saved as a revision):

- **Use another file / image** (the media library's dialog), **show another form**, **change the address** of a link or embed (only where it is the whole address). Block attributes (`id`, `mediaId`, `href`…), every image size's address, `wp-image-{id}` classes (in the blocks that show the file) and Gravity Forms shortcodes are changed; the featured image too, unless Polylang syncs featured images between translations (it would change the original's too).
- **SVGs: translate the labels.** Their `<text>` (lines in `<tspan>`s read as one), `<title>`, `<desc>` and alt text go to DeepL with the glossary and are written into a copy of the file per language (`diagram-en.svg`, a media library item that knows the file it translates). The translation shows the copy; *Edit the labels…* corrects them (in every translation showing the copy), *Use the original again* undoes it. One copy per file and language: a second page with the same illustration uses it without DeepL. When the original file changes, *Update the copy* makes it again, and only new labels go to DeepL. **After an AI translation this is done at once** (*Content translation settings → Illustrations*, on by default; `gds_content_translation_svg_auto` filters it).
- **Documents: a DeepL draft**, when *Content translation settings → Documents* allows it (off by default). PDF, Word, PowerPoint and Excel files are translated one at a time, after a dialog says what it costs: DeepL bills every file as at least 50,000 characters, about €1 on DeepL API Pro (`gds_content_translation_deepl_price_per_million`, default 20), or that much of the free plan's characters, with what is left of the plan this period. DeepL translates in the background (WP-Cron polls every 10 seconds, up to 30 minutes); the draft is saved as a copy (`guide-en.pdf`) and used only when someone chooses **Use this file** after looking at it. A draft made for one translation is used again for others in the language, at no cost.
- **Forms**: how much of the form is translated into the language, with a link to Form translations (below). A form translated into the language counts as handled.

REST, under `gds-content-translation/v1`: `GET /media/{id}`, `POST /media/{id}` `{keys, ok}`, `POST /media/{id}/action` `{key, action, value, labels}` with the actions `replace`, `restore`, `svg-translate`, `svg-labels`, `document-translate`, `document-use`, `document-discard`.

### Form translations

Polylang → **Form translations** (with Gravity Forms): one form per purpose, its texts translated. A form is written once, in the default language; its title, description, labels, placeholders, descriptions, choices, sub-labels, buttons, error messages, HTML and consent texts, page names, confirmation messages and the notifications sent to the visitor (to an email field of the form) are kept per language. Notifications to the site's own people stay as written, and what a form submits (choice values) stays the same, so entries stay in one list. Each entry records its language (a *Language* column in the entries).

- **The screen** lists every form with how much of it is translated into each language. A form's texts in a language are a table: the original, what it is (*“Email” – placeholder*), its translation to edit.
- **Translate missing texts with DeepL**, with the glossary; texts with HTML are sent as HTML. After an AI translation of a page with a form, the form's missing texts in that language are translated at once.
- **Check with the AI review**: Claude checks the translations against the originals and the glossary terms found in them, and each remark comes with the whole corrected text: *Use suggestion* or *Not an issue*.
- Texts are stored by their original (`gds_content_translation_form_{id}` options): a changed label is a new text without a translation, shown in its original until translated, and the pages showing the form count it again.
- On the site, `gform_pre_render`, `gform_pre_validation` and `gform_pre_submission_filter` give the form its texts in the page's language; a hidden field carries the language through an AJAX submission, and `gform_notification` translates the visitor's email. On an AJAX submission Gravity Forms' own messages (*This field is required.*) are in the form's language too.
- Page and redirect confirmations lead to the page's translation, and the links in messages and the visitor's email to the translated page, and to a translated copy of a file once someone has used it.
- Merge tags (`{all_fields}`, `{Name:1}`…) are never sent to DeepL as text.

REST: `GET /forms`, `GET|POST /forms/{form}/{lang}` `{translations}`, `POST /forms/{form}/{lang}/translate|review|remark` `{source, use}`.

### String translations

Polylang → **String translations**: what the site shows besides its pages, one language at a time, in three tabs.

- **Texts**: Polylang's string translations: the site title, Yoast's titles, widgets, ACF labels, `pll_register_string()` and the theme's and plugins' texts Theme Translation for Polylang finds (not WordPress' own). A theme's texts need a translation in the default language too. Missing ones are translated by DeepL with the glossary (a theme's text from its default-language translation when it has one, else DeepL detects its language), placeholders (`%s`, `%%title%%`) kept; formats and addresses are kept as they are. **Check with the AI review** as for forms. Saved where Polylang's own Strings translations screen keeps them.
- **URL slugs**: Polylang Pro's translated slugs (post type and taxonomy bases, archives, author, search, page numbers). DeepL suggests URL-safe slugs; they are saved after a confirmation that lists the old and new addresses, which flushes the rewrite rules. Translate slugs before the site goes live, or add redirects.
- **Image alt texts** (when Polylang does not translate media): an alt text per language (meta `_gds_content_translation_alt_{slug}`), shown on that language's pages where the image's alt text is the media library's; a block's own alt text and an empty (decorative) one stay. DeepL translates them from the media library's, all missing ones or per image, also from the files list.

Filters: `gds_content_translation_strings_excluded_groups`, `gds_content_translation_strings_kept`, `gds_content_translation_strings_scan_theme`, `gds_content_translation_string_is_gettext`, `gds_content_translation_image_alts`. REST: `GET|POST /strings/{lang}`, `POST /strings/{lang}/translate|slugs|review|remark`, `GET|POST /alts/{lang}`, `POST /alts/{lang}/translate`.

### Polylang block integration

The plugin registers Polylang Pro hooks and exposes **project-specific rules via WordPress filters**. Themes (or site-specific mu-plugins) declare which custom block attributes are translatable strings, which hold post/attachment IDs, and which hold internal URLs.

Built-in defaults (no theme code required):

- `core/query` handpicked posts (`query.include`, `exclude`), `parents` and `taxQuery` term IDs → sync to the translations (a parent or term without a translation is kept, as 0 would list every post)
- `core/button` `url` attribute + `<a href>` in block HTML → rewrite internal links

## Configuring block rules (themes)

Add filters in your theme `app/filters.php` (or a small mu-plugin). The plugin merges your rules into Polylang’s native filters.

**Check block texts.** Content translation settings → *Block texts* → *Check block texts* lists the string attributes of the theme's and plugins' blocks that have no rule in `pll_blocks_rules_for_attributes` and whose value a block on some page shows as text: *These block texts are not translated: gds/foo → buttonText (on 3 pages, for example “Read more”)*. It renders up to 30 pages per block type, on request, and keeps the result a week.

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

The status list is a React app (TypeScript, `resources/`) built with [`@wordpress/scripts`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/) into `build/`. WordPress' own React and `wp-*` packages are used from WordPress (externalised by the dependency extraction plugin, listed in `build/index.asset.php`); `@wordpress/dataviews`, which WordPress does not ship, is bundled. The same bundle renders the AI review page's table of remarks; the rest of that page and the settings stay server-rendered PHP, with `assets/admin.js` and `assets/admin.css`.

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

### 1.6.2

- **AI review of long pages**: a page reviewed in parts lost its run while the review showed its progress, so the finished review was taken for a replaced one and dropped, and the page stayed *AI review running…* (with *Translate and fix automatically*, nothing was applied). It now finishes.
- **Code in what DeepL translates**: a `<script>` or `<style>` in a custom HTML block or a form's HTML field is no longer sent to DeepL, which turned its quotes typographic and broke it. Form translations keep the original's scripts and styles; `wp_kses_post()` used to leave a script's code on the page as text.
- **Scripts and styles of custom HTML blocks**: Polylang Pro runs every translated piece through `wp_kses_post()`, which took a custom HTML block's `<script>` and `<style>` tags out and left their code on the page as text. After an AI translation, the original's code goes back where kses left its remains; the translated text around it stays.
- **Line breaks**: DeepL's comma or semicolon at the start of a line after a `<br>` (the lines of an address read as a list) is taken out when the original has none.

### 1.6.1

- **Soft hyphens in long words**: AI translations get soft hyphens in the long words of their headings and buttons, where the language may break them, written as wp-gutenberg-shy writes them, so editors see them in the block editor. **Add soft hyphens…** on the status screen adds them to originals and older translations. Words with a soft hyphen are left as they are; a used suggestion keeps them and gets them in its new words.
- Unchecking *Illustrations* in the settings for the first time now sticks.

### 1.6.0

- **Check the page for Finnish…**: an on-demand check of a translation's public page (its preview while a draft) for text a visitor still sees or hears in the original's language: the theme's header and footer, menus, image alt texts, the title and sharing tags, structured data, illustrations. Each finding says where it is, its likely source with a link to fix it, and a suggestion. In the cell menu, on the AI review page and as a bulk action; the cost is shown first, and checks run in the background one page at a time.
- **String translations** (Polylang → String translations): Polylang's strings (site title, Yoast, widgets, the theme's texts) per language, translated by DeepL with the glossary, edited and checked by the AI review; **translated URL slugs** with DeepL suggestions, saved after a confirmation; **image alt texts per language** when Polylang does not translate media.
- **More is flagged per translation**: query loops whose chosen posts have no translation (empty sections), links to pages with no translation (on the status screen now, not only folded on the review page), texts still to be written (`[TÄYTÄ]`, `TODO`, *lorem ipsum*), and images Claude could not check. Query loop parents, excluded posts and terms are remapped to the translations.
- **Block texts check** in the settings: block texts of the theme and plugins that have no translation rule.
- **The AI review covers the title, the excerpt and the Yoast SEO texts.**
- **Review fixes**: *edited since* shows an edit saved while the review ran; a review can't start while one runs, and notes of another run no longer close remarks; a long suggestion shows as it is when too different to mark the changes; the WP-Cron fallback respects the limit on reviews at once.
- **Sturdier queues**: AI translation and bulk-queue locks belong to their owner and are renewed during long translations; a bulk job blocked by another translation waits a minute; the replaced DeepL glossary is deleted 15 minutes later; a failed glossary send is retried less often, not during every translation; an endless retry when DeepL had no dictionary for a pair is fixed.
- **Files and forms**: saving a synced pattern or form no longer rechecks every page in the save request (in the background now); a file both on the page and in a pattern changes in all of the translation's own places; replacing a file changes its ID only in the blocks that show it; a synced featured image is not changed (it would change the original's); Gravity Forms' own messages are in the visitor's language on AJAX submissions, and merge tags are kept as they are; a document DeepL is still translating can't be discarded.
- **Soft hyphens** an editor put in a word are taken out of what DeepL and Claude get.
- **Screens**: focus stays in the cell after AI translate and its end is announced; `?search=` in the address opens the list searched; a refresh no longer undoes a change just made; unsaved form translations survive the browser's Back and a reload.
- Generic examples in the settings and docs instead of a client's terms.
- The site is checked again in the background after the update.

### 1.5.2

- **The status screen no longer shows fixed remarks as errors** after an AI translation set to fix automatically: the review counts as running until its suggestions are in (and, with *check again*, until the second review), so the screen keeps asking.
- **AI review fixes**:
  - Automatic fixes are saved as the editor who asked. In the background, WordPress otherwise filtered the whole translation's HTML (an embed's iframe, an inline SVG).
  - A second remark on the same text no longer undoes the first one's fix.
  - Remark changes made at the same moment no longer overwrite each other.
  - On a long page, a part's remarks are only about the texts it sent.
  - Remarks can't be changed while a new review runs.
- **Image and post IDs in blocks are kept** when their object has no language (media, when Polylang does not translate media). They were removed.
- **Files, images and forms**:
  - A link is replaced only where it is the whole address.
  - A paid DeepL document is not sent twice, also from two translations at once.
  - A short DeepL outage no longer fails a document job.
  - Only the actions the list offers can be run.
  - SVG `aria-label`s are translated too.
  - DeepL gets at most 50 texts a request (long choice lists).
  - Images are checked only when the AI review is on.
- **Forms**:
  - Page and redirect confirmations, and the links in messages and in the visitor's email, lead to the visitor's language: the translated page, and a translated file once someone has used it.
  - Saving checks the form and language first.
- **Screens**:
  - Unsaved form translations are no longer lost when a remark is used, or when you leave the page.
  - The files list stays open while its last item is fixed, and runs one action at a time.
  - Polling goes on after a failed request.
  - Bulk queue errors are shown.
  - Notices on the review page show once.

### 1.5.1

- **Files, images and forms in synced patterns**: the check looked only at a page's own content, so a form, SVG or file in a synced pattern the page shows was neither listed nor translated (the form on a home page in a pattern stayed in Finnish). They are now listed with the pattern they are in, and the actions and the automatic SVG and form translation change the translation's pattern. When the page still shows the original's pattern, the list says to translate the pattern first. Saving a pattern rechecks the pages that show it, and the whole site is checked again after the update.

### 1.5.0

- **Files, images and forms still in the original language**: each translation is checked for the PDFs, SVG labels, images with words in them (Claude looks), Gravity Forms forms, embeds and links to other sites it still shares with its original. The status screen counts and filters them (*2 in Finnish*), the AI review page lists them, and each can be marked fine as it is. The whole site is checked in the background after the update.
- **Fix them from the list**: use another file, image, form or address in the translation; translate an SVG's labels into a copy of the file (automatically after an AI translation), and correct them; have DeepL draft a PDF or Office file, after a dialog says what it costs (optional, off by default).
- **Form translations** (Polylang → Form translations): Gravity Forms forms' texts per language, translated by DeepL with the glossary, checked by the AI review and edited; shown in the page's language, with the language recorded on each entry.

### 1.4.1

- **Every AI remark is one click**: a remark has to come with a suggestion. Remarks without one, which were the model concluding there is nothing to change ("not an error", "no change needed"), are no longer shown, and the review is told to leave them out.
- **Nested blocks in the review**: Polylang's internal "Polylang placeholder do not modify" shows as *[nested block]*. A suggestion that would drop a nested block is not offered, and Edit… keeps them (it lost the placeholder's `translate` attribute before, which deleted the nested blocks).

### 1.4.0

- **Translation glossary** (Polylang → Translation glossary): terms with their required translation per language, imported and exported as DeepL's CSV and edited in the admin.
- **DeepL uses the glossary**: approved terms are sent to DeepL as a glossary and added to Polylang Pro's translate requests.
- **The AI review checks the glossary**: the page's terms go to Claude as required translations.
- **The glossary learns**: terms the AI review settles and terms editors change before marking a translation proofread become suggestions to accept or reject.

### 1.3.0

- **Instructions for the AI review**: site-wide in the settings, and optionally per translation when AI translating. Claude reports what does not follow them and follows them in its suggestions; the review page shows them.
- **Choose what happens after the AI review** when AI translating, in the cell's menu and the bulk dialog: review the remarks manually, apply every suggestion automatically, or apply them and review once more. The default is a new setting.
- The bulk translation and AI review workers load Polylang's admin side (`pll_ajax_backend`). As front-end AJAX requests they had no current language, and copying a translation's media failed with "Attempt to read property "locale" on null" where warnings become exceptions (Acorn with `WP_DEBUG`).

### 1.2.0

- **Status screen redesign** after three rounds of UI/UX review: one 41px line per row, no layout shift (CLS 0), language names in the admin's own language (Finnish/English/Swedish, or suomi/englanti/ruotsi), one line of filter chips, a status chip (Draft/Published, tick when proofread) and one AI badge per translation, everything else in a ⋯ menu shown on hover or focus, cards with the languages side by side on phones, no browser confirm dialogs.
- **AI translate stays on the status screen**: the cell shows Translating…, then the translation and its AI review, without leaving the screen.
- **AI translations are published when their original is**, and the patterns they use always (a draft pattern shows nothing on a page). Copy original still creates drafts. Off under Content translation settings → AI translations.
- **The AI review covers ACF text fields**, with Use suggestion writing back to the field.
- **The AI review page is rebuilt around its remarks**: a DataViews table with severity counts as filters, Open / Handled / Whole page, search, bulk Use suggestion / Mark as fixed / Not an issue / Reopen, and an Edit… dialog, all without reloading. The suggestion's changes show inside the translation. The summary, review details and links to untranslated pages are folded away. Reviews stored before texts kept their place in the content can have their suggestions used too.

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
