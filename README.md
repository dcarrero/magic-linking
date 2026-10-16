# 🔗 Magic Linking

[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org/)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL%20v2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress.org](https://img.shields.io/wordpress/plugin/v/magic-linking?label=wordpress.org)](https://wordpress.org/plugins/magic-linking/)

Internal linking for WordPress that works for free, uses the AI your site already has connected through **Settings → Connectors**, and never sells credits.

*Enlazado interno para WordPress que funciona gratis de verdad, con la IA que ya tiene tu WordPress y sin vender créditos.*

> **Status: version 0.20 ([0.11 is the latest on wordpress.org](https://wordpress.org/plugins/magic-linking/)).** Link suggestions in the editor, one-click linking that verifies nothing else changes, and a history with undo and redo. It works entirely on your server; AI is optional and comes only from the connector your site already has (see the [Roadmap](#-roadmap)).

## 🚀 Features

- **💡 Outbound suggestions** - In the block editor (sidebar panel) and in the classic editor (box): phrases of your text that can link to other entries, with the anchor highlighted in the text and the reason
- **🖱️ One-click linking** - The link is inserted in the editor itself, on that phrase only; you save when you want, and you can undo it from the card
- **📥 Inbound suggestions** - Other entries that mention the one you are editing; the link is written there, on the server, and the rest of that entry must stay identical byte for byte or nothing is written. WordPress keeps a revision
- **↩️ History with undo and redo** - Every link Magic Linking changes (server, editor panel and broken-link fixes) is listed, grouped by entry with dates and filters, undo or redo one change, an entry or a batch; configurable retention (30, 90, 365 days or forever); large groups are undone in the background
- **🧭 Link report** - Orphan, under-linked and over-linked entries, with inbound, internal outbound, external and broken counts for every entry
- **🔎 Filters and search** - Filter by problem type, search by title, sort, paginate
- **📤 CSV export** - Export the exact view you are looking at
- **🩹 Broken internal links** - Complete list with anchor text, reason and a link to edit the source entry (trash, draft, private or non-existent targets); fix each one by changing its destination or removing the link, with undo
- **⚡ Made for big sites** - The index and the analysis are built in the background with Action Scheduler (bundled); suggestions in milliseconds with thousands of entries
- **🌍 Multilingual** - Language per entry from WPML, Polylang or the site language; never suggests links between languages; English and Spanish included
- **⌨️ WP-CLI** - Index, status, reports and history from the terminal
- **🪶 Lightweight** - No front-end scripts, styles or queries; a single autoloaded option
- **🔒 Private** - No external services, no telemetry, no email requests, no cookies

### When it changes your content

Only when you press **Link** on a suggestion, and only by adding that link. It never edits an entry that is open in an editor, never rewrites links you already had and never links entries of different languages. Everything else (index, report, settings) only reads your content.

### What it does not do (yet)

- It does not need AI and has no servers or keys of its own. If your site has an AI connector (**Settings → Connectors**, WordPress 7.0+), the **Improve with AI** button in the editor panel sends the best 20 suggestions to your provider in one request, only when you press it, with a cost estimate first and a daily token cap you set. Semantic suggestions (embeddings) wait for WordPress 7.2.
- It does not check links over the network: a link is "broken" when it points to an address of your own site that leads nowhere, according to WordPress. Links to other sites are counted, not checked.

## 🛠️ Installation

### From wordpress.org

Go to **Plugins → Add New**, search for **Magic Linking** and click **Install Now**, or download it from [wordpress.org/plugins/magic-linking](https://wordpress.org/plugins/magic-linking/).

### From a ZIP

1. Download the ZIP (`npm run zip` builds `dist/magic-linking.zip`) or a release from [GitHub](https://github.com/dcarrero/magic-linking).
2. Go to **Plugins → Add New → Upload Plugin**, choose the ZIP and activate.

### Manual installation

1. Upload the `magic-linking` folder to `/wp-content/plugins/`.
2. Activate it through the **Plugins** menu.

### From source

```bash
cd wp-content/plugins/
git clone https://github.com/dcarrero/magic-linking.git
```

See [Development](#-development) to build the assets.

## 📋 Requirements

- WordPress 6.9+ (7.0+ for the future AI features)
- PHP 8.1+

## ⚙️ Usage

1. Open **Magic Linking** in the admin sidebar and press **Analyse my site**. Nothing is analysed until you do it; the first time it also builds the suggestions index in the background.
2. **Editor**: open a post or page and the **Magic Linking** panel (block editor) or box (classic). Press **Link** on the suggestions you like.
3. **History**: undo or redo any change, by entry or by batch.
4. **Report**: use the filters *Orphans*, *Under-linked*, *Over-linked* and *With broken links*; export to CSV.
5. **Broken links**: review each broken internal link and fix it: change its destination, remove the link or open its entry.
6. **Settings**: thresholds for under-linked and over-linked entries, history retention, full re-analysis (*Maintenance*) and what to do with the data when the plugin is deleted.

### WP-CLI

```bash
wp magic-linking index                                    # analyse the whole site
wp magic-linking status                                   # progress and totals
wp magic-linking report --filter=orphans --format=csv     # orphan pages as CSV
wp magic-linking broken                                   # broken internal links
wp magic-linking job list                                 # background jobs (also: pause, resume, cancel)
wp magic-linking history list                             # history (also: undo, redo, purge; undo|redo --post=<id>)
```

### How links are counted

- Only links written in the content of each entry are counted. Menus, widgets and theme templates are not, so a page linked only from the menu can appear as an orphan.
- Inbound = different entries that link to this one (only links that work). Outbound = internal links to other pages of your site.
- Category, tag, author, date and search pages, the home page and feeds are valid destinations and are never reported as broken.
- Tracking parameters (`utm_*`, `gclid`, `fbclid`...) and anchors (`#...`) are ignored when matching a link to an entry.

## 🗺️ Roadmap

Legend: ✅ done · 🔜 planned. No dates and no prices; the order can change.

### Free version

- ✅ Orphan, under-linked and over-linked report with filters, search and CSV export
- ✅ Complete list of broken internal links
- ✅ Background analysis with pause/resume/cancel and WP-CLI
- ✅ Language per entry (WPML, Polylang) and English/Spanish interface
- ✅ Outbound and inbound link suggestions in the editor (block editor and classic), with the reason for each one
- ✅ One-click insertion touching only the affected block, verified, with history and **undo / redo**
- 🔜 Several phrases per destination and adjustable anchor text
- 🔜 Automatic rules (phrase → URL), unlimited, applied on save
- 🔜 Remove or change broken links one by one
- ✅ Optional AI through the WordPress AI client (**Settings → Connectors**): *Improve with AI* reorders the suggestions, flags the ones it does not recommend and may propose a better anchor, with cost estimate and a daily cap you set
- 🔜 Suggestions by meaning (embeddings) when WordPress 7.2 provides them
- 🔜 Pillar content (own flag, Yoast or Rank Math) and Ignore / Consider / Require filters by post type, category and tag
- 🔜 Your own target phrases per entry

### Pro (later)

A separate add-on that hooks into the free plugin without unlocking or expanding anything it already does. It adds:

- 🔜 **In bulk:** link many entries at once with a full preview and a single undo; apply rules to existing entries; fix broken links in bulk
- 🔜 **Automation:** site-wide background processes, HTTP checking of links (also external), suggested replacements
- 🔜 **Connections:** page builders, Search Console, other sites, agents (Abilities API)
- 🔜 Advanced history and audit

## ❓ FAQ

**Does it change my posts or pages?**
Only when you press *Link* on a suggestion. Outbound: the link is inserted in the editor and you save when you want. Inbound: the link is written into the other entry on the server, verifying that the rest of it stays identical, with a WordPress revision and undo in History. It never touches an entry that is open in an editor.

**Does it need AI, an API key or an account?**
No. It works entirely on your server. AI is an optional extra through the WordPress AI client (**Settings → Connectors**), never with keys of its own; the text is only sent when you press *Improve with AI*, to the provider you configured (nothing leaves your server with a local model such as Ollama).

**Why does my home page appear as an orphan?**
Because no other entry links to it from its content. Menu links are not counted.

**The analysis does not move.**
It needs Action Scheduler, which WordPress triggers with WP-Cron. On low-traffic sites use a system cron, run `wp action-scheduler run --group=magic-linking`, or use `wp magic-linking index`.

**What happens when I delete the plugin?**
Your data is kept by default. Tick the option in the settings to remove its tables (index, links, history), processes, settings and metadata. The links already in your content stay; your content is never touched.

**Which hooks can developers use?**
`magiclinking_link_inserted`, `magiclinking_batch_undone`, `magiclinking_insertable_blocks`, `magiclinking_post_lock_timeout`, `magiclinking_undo_sync_limit`, `magiclinking_post_language`, `magiclinking_internal_hosts`, `magiclinking_resolve_link`, `magiclinking_post_html`, `magiclinking_is_multilingual`, `magiclinking_batch_max_entries` and `magiclinking_batch_time_budget`.

## 🔧 Development

Requires PHP 8.1+, Composer, Node 20+ and Docker.

```bash
composer install
npm install && npm run build
npx wp-env start              # http://localhost:8888 (admin / password)

composer test                 # unit tests (no WordPress)
composer test:integration     # integration tests inside wp-env
composer lint                 # PHPCS (WordPress Coding Standards) + PHPStan
npm run plugin-check          # builds the ZIP, runs the official Plugin Check on it and fails on any ERROR
npm run zip                   # dist/magic-linking.zip
```

## 👤 Author

**Color Vivo Internet - David Carrero Fernandez-Baillo**

- 🌐 https://colorvivo.com
- 🌐 https://carrero.es
- GitHub: [@dcarrero](https://github.com/dcarrero)

## 🤝 Contributing

Issues and pull requests are welcome at [dcarrero/magic-linking](https://github.com/dcarrero/magic-linking).

## 📄 License

[GPL-2.0-or-later](LICENSE).

## 📝 Changelog

### Unreleased
- Link inside the Classic block of the block editor (`core/freeform`): the link is inserted on the block's raw HTML string (only `<a …>` and `</a>`, verified before writing, rest byte for byte) with the same rules as the server; new failure reasons (`unsupported`, `crosses_format`, `unsafe`) replace the misleading "the text has changed".
- History titles are plain text (no HTML entities), also in the CSV export and the WP-CLI export.

### 0.21.1
- "Improve with AI" uses exactly the chosen model (`using_model()`), records the model that answered and only picks models that declare the request's requirements; fixes OpenRouter sending requests to other models.
- OpenRouter model IDs (`provider/model`, `:batch`, `:free`…) recognized for picking and pricing; batch variants never used and rejected in Settings.
- Automatic choice prefers cheap, fast models; "Recommended" group with prices in Settings > AI.
- Last failed AI request shown in Settings > AI (keys and quoted text redacted).
- Cloud timeout up to 50 s (within PHP's limit); progress text in the editor panel.

### 0.21.0
- Optional "Improve with AI" in the editor panel through the WordPress AI client (WordPress 7.0+, Settings > Connectors): reorders the best suggestions in one request, flags the ones it does not recommend and may propose a shorter anchor from the same sentence.
- Token estimate before each request, daily token limit checked before sending, 10 requests per minute; local models (Ollama) shown as free.
- Settings > AI: connector capabilities, preferred model, daily token limit (0 turns it off) and usage per model (no prompt text stored).
- Falls back to the suggestions without AI if the provider does not answer.
- Package without JavaScript tests or the `.pot` template.

### 0.20.0
- Fix a broken internal link from the list: change its destination or remove the link keeping its text, with undo from History.
- History records the links you add from the editor panel (schema 5; the update runs by itself) and marks them undone if you remove them and save.
- History more compact and grouped by entry, with dates, filters, "Undo all" / "Redo all" per entry and a view by batch; `wp magic-linking history undo|redo --post=<id>`.
- Settings in tabs (General, Suggestions, Links, History and data) with a sticky save bar; more compact editor panel after linking or dismissing.
- History shows where each link came from: "Editor panel", "Another entry's panel" or "Broken links".
- Translations up to date in all six languages.

### 0.11.0
- Dismiss a suggestion for good, with undo; targets dismissed from more than 10 published entries rank slightly lower.
- Bundled translations: Spanish (Spain), French, German, Italian and Portuguese (Brazil and Portugal).
- Translations up to date in all six languages.
- Setup assistant for new sites, and new settings: where links can go (headings, quotes, tables), open in a new tab, extra `rel`, suggestions shown, ignored addresses and words.
- Changed: links in quotes are now off by default. Before, a paragraph inside a quote block could receive an automatic link; now it only does if you turn on "Quotes" in Settings.

### 0.10.0
- Link suggestions in the block editor and the classic editor, with one-click linking.
- Inbound suggestions, written on the server after verifying that nothing else in the entry changes.
- History with undo and redo, with configurable retention; `wp magic-linking history`.
- Persistent suggestions index built in the background (built automatically after updating from an earlier version).
- Cleaner anchor text: suggested phrases no longer start or end with words like "the", "of", "el" or "de".
- New hooks for developers. Contrast fix for secondary buttons in WordPress 6.9.

### 0.9.0
- First public version: report of orphan, under-linked and over-linked entries, complete list of broken internal links, CSV export, WP-CLI commands and settings. It never modifies content.
