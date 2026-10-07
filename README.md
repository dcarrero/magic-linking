# 🔗 Magic Linking

[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org/)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL%20v2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress.org](https://img.shields.io/wordpress/plugin/v/magic-linking?label=wordpress.org)](https://wordpress.org/plugins/magic-linking/)

Internal linking for WordPress that works for free, uses the AI your site already has connected through **Settings → Connectors**, and never sells credits.

*Enlazado interno para WordPress que funciona gratis de verdad, con la IA que ya tiene tu WordPress y sin vender créditos.*

> **Status: version 0.10 ([0.9 is on wordpress.org](https://wordpress.org/plugins/magic-linking/)).** Link suggestions in the editor, one-click linking that verifies nothing else changes, and a history with undo and redo. It does not use AI yet; it works entirely on your server (see the [Roadmap](#-roadmap)).

## 🚀 Features

- **💡 Outbound suggestions** - In the block editor (sidebar panel) and in the classic editor (box): phrases of your text that can link to other entries, with the anchor highlighted in the text and the reason
- **🖱️ One-click linking** - The link is inserted in the editor itself, on that phrase only; you save when you want, and you can undo it from the card
- **📥 Inbound suggestions** - Other entries that mention the one you are editing; the link is written there, on the server, and the rest of that entry must stay identical byte for byte or nothing is written. WordPress keeps a revision
- **↩️ History with undo and redo** - Every link added from the server is listed; configurable retention (30, 90, 365 days or forever); large groups are undone in the background
- **🧭 Link report** - Orphan, under-linked and over-linked entries, with inbound, internal outbound, external and broken counts for every entry
- **🔎 Filters and search** - Filter by problem type, search by title, sort, paginate
- **📤 CSV export** - Export the exact view you are looking at
- **🩹 Broken internal links** - Complete list with anchor text, reason and a link to edit the source entry (trash, draft, private or non-existent targets)
- **⚡ Made for big sites** - The index and the analysis are built in the background with Action Scheduler (bundled); suggestions in milliseconds with thousands of entries
- **🌍 Multilingual** - Language per entry from WPML, Polylang or the site language; never suggests links between languages; English and Spanish included
- **⌨️ WP-CLI** - Index, status, reports and history from the terminal
- **🪶 Lightweight** - No front-end scripts, styles or queries; a single autoloaded option
- **🔒 Private** - No external services, no telemetry, no email requests, no cookies

### When it changes your content

Only when you press **Link** on a suggestion, and only by adding that link. It never edits an entry that is open in an editor, never rewrites links you already had and never links entries of different languages. Everything else (index, report, settings) only reads your content.

### What it does not do (yet)

- It does not use AI and does not call any external service. When AI arrives it will be only through the WordPress AI client (**Settings → Connectors**), optional and with a cost estimate first.
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
3. **History**: undo or redo any link added from the server.
4. **Report**: use the filters *Orphans*, *Under-linked*, *Over-linked* and *With broken links*; export to CSV.
5. **Broken links**: review each broken internal link and open its entry to fix it.
6. **Settings**: thresholds for under-linked and over-linked entries, history retention, full re-analysis (*Maintenance*) and what to do with the data when the plugin is deleted.

### WP-CLI

```bash
wp magic-linking index                                    # analyse the whole site
wp magic-linking status                                   # progress and totals
wp magic-linking report --filter=orphans --format=csv     # orphan pages as CSV
wp magic-linking broken                                   # broken internal links
wp magic-linking job list                                 # background jobs (also: pause, resume, cancel)
wp magic-linking history list                             # history (also: undo, redo, purge)
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
- 🔜 Optional AI through the WordPress AI client (**Settings → Connectors**), with cost estimate and a daily cap you set
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
No. It works entirely on your server and does not use AI in this version. When AI arrives it will be only through the WordPress AI client, never with keys of its own.

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
npm run plugin-check          # builds the ZIP and runs the official Plugin Check on it
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

### 0.10.0
- Link suggestions in the block editor and the classic editor, with one-click linking.
- Inbound suggestions, written on the server after verifying that nothing else in the entry changes.
- History with undo and redo, with configurable retention; `wp magic-linking history`.
- Persistent suggestions index built in the background (built automatically after updating from an earlier version).
- Cleaner anchor text: suggested phrases no longer start or end with words like "the", "of", "el" or "de".
- New hooks for developers. Contrast fix for secondary buttons in WordPress 6.9.

### 0.9.0
- First public version: report of orphan, under-linked and over-linked entries, complete list of broken internal links, CSV export, WP-CLI commands and settings. It never modifies content.
