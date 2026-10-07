=== Magic Linking – Internal Links ===
Contributors: dcarrero
Tags: internal links, seo, link suggestions, orphan pages, broken links
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.10.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Internal link suggestions in the editor. Link in one click, check that nothing else changes, undo from History. Plus orphan and broken link reports.

== Description ==

Magic Linking suggests internal links while you write, adds the one you choose without touching anything else, and keeps a history so you can undo it. It also reports which of your entries are orphans, under-linked, over-linked or have broken internal links.

It works entirely on your server, with no artificial intelligence, no account, no key, no external service and no limits.

**In the editor**

* **Outbound suggestions**: a Magic Linking panel in the block editor (and a box in the classic editor) proposes phrases of the text you are writing that could link to other entries of your site. Each suggestion shows the phrase with the anchor text highlighted in the editor, the destination and the reason.
* **One click to link**: press *Link* and the link is added in the editor itself, on that phrase only. It is part of your unsaved changes until you save, and you can undo it with the *Undo* button of the card or with the editor's own undo.
* **Inbound suggestions**: the same panel lists other entries whose text mentions the one you are editing and could link to it. Pressing *Link* writes the link in that other entry, see "How and when it changes your content".
* Only the entries and block types where a link can be inserted safely are proposed. Headings, quotes and tables are left alone unless a developer allows them with a filter.

**How and when it changes your content**

* Nothing is changed until you press *Link* on a suggestion. There are no automatic changes and no background rewriting.
* For the entry you have open, the link is inserted in the editor; saving is up to you.
* For inbound suggestions, the link is written into the other entry on the server. Before saving, Magic Linking checks that the rest of that entry stays exactly as it was: only the link is added. If the check fails, it writes nothing and tells you why. WordPress keeps a revision of the entry as usual.
* Every change is recorded in **History**, where you can undo it and redo it. Undo removes only that link; if the text around it was edited since, it tells you instead of guessing.
* An entry that is open in someone's editor is never modified from the server.
* Links already written in your content are never removed or rewritten, except to undo a link Magic Linking added.

**History**

* Every link added from an inbound suggestion is listed with who added it, when, where and the anchor text.
* Undo and redo from the screen or with WP-CLI. A large group is undone in the background.
* The history is kept 90 days by default; you can choose 30 days, a year or forever.

**Report**

* **Orphan pages**: entries that no other entry links to.
* **Under-linked pages**: entries with some inbound links, but fewer than you consider enough (2 by default, you can change it).
* **Over-linked pages**: entries with more internal links than one for every 100 words (you can change that too). A short text may always have one link.
* **Broken internal links**: links to an entry that is in the trash, is a draft or private, or does not exist. The complete list, with the anchor text, the reason and a link to edit the entry.
* Numbers for every entry: inbound, internal outbound, external and broken links.
* Filters, search by title, sorting, pagination and **CSV export** of the view you are looking at.

**Also**

* **WP-CLI**: analyse the site, print reports and manage the history from the terminal.
* **Multilingual**: the language of each entry comes from WPML, Polylang or, if you use neither, from the site language. Suggestions never link entries of different languages.
* **Made for big sites**: the analysis and the index of suggestions are built in the background in small batches with Action Scheduler (bundled), and every entry is updated when you save it. Suggestions for an entry are calculated in a few milliseconds even with thousands of entries.
* **Light**: no scripts, no styles and no database queries from this plugin on your public pages.
* **Private**: no external services, no telemetry, no cookies, no email requests, no notices outside its own screens.

**What it does not do (in this version)**

* It does not use artificial intelligence. When it does, it will be only through the AI client and the *Connectors* screen that WordPress itself provides, never with keys or credit of its own, and always optional.
* It does not check links over the network: a link is "broken" when it points to an address on your own site that leads nowhere, according to WordPress. Links to other sites are counted, not checked.
* It does not suggest or add links automatically while you are away. Every link is a click of yours.

**How links are counted**

* Only the links written inside the content of each entry are counted. Links in menus, widgets, theme templates or blocks that are pulled from somewhere else are not, so your home page or a page that is only linked from the menu can appear as an orphan.
* Content that a page builder keeps outside the normal post content is not read. If your builder saves rendered HTML in the post content, it is read.
* Inbound = how many different entries link to this one, counting only links that work. Outbound = internal links to other pages of your site; every appearance counts. A link to the same entry, to a file (uploads, PDF, images) or to `mailto:` and `tel:` addresses is not counted.
* Category, tag, author, date and search pages, the home page and feeds are valid destinations and are never reported as broken.
* Tracking parameters (`utm_*`, `gclid`, `fbclid`…) and anchors (`#…`) are ignored when matching a link to an entry.

**Roadmap**

Next: several phrases per destination and adjustable anchor text, automatic rules, fixing broken links one by one and, optionally, help from the AI connectors that WordPress already provides. None of that is in this version.

== Installation ==

1. Install the plugin and activate it.
2. Go to **Magic Linking** in the admin sidebar and press **Analyze my site**. Nothing is analysed until you do it. The first time it also builds the index used for suggestions, in the background.
3. Open any post or page. In the block editor, open the **Magic Linking** panel from the top bar (or the three dots menu); in the classic editor, use the **Magic Linking** box.
4. Read the suggestions and press **Link** on the ones you like.
5. For the site-wide picture, use the filters *Orphans*, *Under-linked*, *Over-linked* and *With broken links* in the report, or open *Broken links* to fix them one by one.

From the terminal: `wp magic-linking index` analyses the site and `wp magic-linking report --filter=orphans --format=csv` prints the orphan pages as CSV.

== Frequently Asked Questions ==

= Does it change my posts or pages? =

Only when you press *Link* on a suggestion, and only by adding that link.

* Outbound suggestions (for the entry you are editing): the link is inserted in the editor itself. You save the entry when you want, as with any other change.
* Inbound suggestions (links from other entries to this one): the link is written into the other entry on the server. The rest of that entry must stay identical, byte for byte, or nothing is written. WordPress creates a revision, and the change is listed in History, where you can undo it.
* It never modifies an entry that is open in an editor, and it never edits, removes or rewrites links you already had.

Everything else (the report, the index, the settings) only reads your content and writes to its own tables.

= How do I undo a link? =

Right after adding it, press *Undo* on the card. Later, open **Magic Linking > History** and undo it there. You can also redo it. If the text around the link has been edited since, Magic Linking tells you instead of changing anything it cannot be sure about.

= Does it need AI, an API key or an account? =

No. It works entirely on your server, with no key, no account and no credit. It does not use artificial intelligence in this version.

= Does it send my content anywhere? =

No. It does not connect to any external service.

= Why does the panel say there are no suggestions? =

The suggestions come from the index of your site, which is built in the background after you press *Analyze my site*. Until it is ready the panel says so. Also, suggestions need text: a very short entry has little to suggest, and entries in another language, drafts, private entries and password-protected ones are never proposed as destinations.

= Why can't I link from this phrase? =

Links are only inserted in paragraphs, lists and similar blocks, and never inside an existing link. A developer can allow more block types with `magiclinking_insertable_blocks`.

= Does it work in the classic editor? =

Yes, with a Magic Linking box. Links are inserted in the *Visual* tab; in *Text* the box explains how to switch.

= Why does my home page (or another page) appear as an orphan? =

Because it is not linked from the content of any other entry. If it is linked from your menu, the menu is not counted. See "How links are counted".

= Why is a link not listed as broken if the page is really gone? =

A link is reported when it points to an entry in the trash, unpublished or private, or to an address of your site that WordPress does not recognise. If a redirect plugin sends the old address elsewhere, the link may still work for your visitors. Developers can tell Magic Linking about redirects with the `magiclinking_resolve_link` filter.

= Does it work with WPML or Polylang? =

Yes. It records the language of every entry, never suggests a link between two languages and, when WPML or Polylang is active (or the analysis finds more than one language), shows it in the report, lets you filter by it and adds it to the CSV. On a single-language site those columns are hidden.

= How long does the first analysis take? =

A few seconds for a small site; thousands of entries per minute on ordinary hosting. It runs in the background; you can keep working and watch the progress on the screen.

= The analysis does not move. =

It needs Action Scheduler to run, which WordPress triggers with WP-Cron. On sites with very little traffic, use a system cron, or run `wp action-scheduler run --group=magic-linking`, or analyse directly from the terminal with `wp magic-linking index`.

= How do I analyze only what changed? =

Press "Analyze changes" in the report (or run `wp magic-linking index`). It goes through every entry but skips the ones whose content has not changed since the last analysis, and tells you how many were new or modified. Entries are also analysed again automatically when you save them.

= How do I analyze the whole site again from scratch? =

Go to Magic Linking > Settings, open the "Maintenance" section and press "Analyze everything again from scratch" (or run `wp magic-linking index --force`). It asks you to confirm and then runs in the background, even for entries that have not changed. It never changes your content.

= What happens when I delete the plugin? =

By default your data is kept. If you tick "Delete all Magic Linking data when the plugin is deleted" in the settings, its tables (index, links, history), processes, settings and metadata are removed. The links already written in your content stay where they are; your content is never touched.

= Is it multisite compatible? =

Each site of a network has its own report, history and settings.

= Which hooks can developers use? =

* `magiclinking_link_inserted`: action that runs after a link has been inserted from the server.
* `magiclinking_batch_undone`: action that runs after a group of links has been undone.
* `magiclinking_insertable_blocks`: filter for the block types where links can be inserted.
* `magiclinking_post_lock_timeout`: filter for the seconds to wait when another insertion or undo is writing the same entry.
* `magiclinking_undo_sync_limit`: filter for how many changes are undone at once before the rest goes to the background.
* `magiclinking_post_language`: set the language of an entry.
* `magiclinking_internal_hosts`: extra hosts that count as your site.
* `magiclinking_resolve_link`: say where an internal address leads.
* `magiclinking_post_html`: add HTML that a builder keeps outside the content.
* `magiclinking_is_multilingual`: tell the plugin whether the site is multilingual.
* `magiclinking_batch_max_entries` and `magiclinking_batch_time_budget`: size and time of each background batch.

== Screenshots ==

1. Outbound suggestions in the block editor: each card shows the destination and the reason, and the anchor text is highlighted in the text.
2. A link added with one click, with the Undo button on the card.
3. Inbound suggestions: other entries that could link to the one you are editing.
4. History: every link added from the server, with undo and redo.
5. The report: totals for analyzed entries, orphans, internal links and broken links, with filters, search and sorting.
6. Settings: content types, thresholds, history retention and maintenance.

== Support and documentation ==

Documentation and news: https://magiclinking.com

Questions and bug reports: use the support forum of this plugin on WordPress.org.

== External services ==

Magic Linking does not connect to any external service.

== Privacy ==

Magic Linking does not collect, store or send personal data. It stores, in your database, the addresses and anchor texts of the internal links found in your own content, a word index of your entries used for suggestions, counts per entry and a history of the links it has added (which user, when, which entry and the text of the paragraph that changed, so it can be undone). Suggestions you dismiss are not stored. It does not set cookies.

== Third-party libraries ==

* Action Scheduler (WooCommerce) 4.2.x, GPL-3.0-or-later, bundled in `vendor/woocommerce/action-scheduler/`. Used to run the analysis and the index in the background.

== Changelog ==

= 0.10.0 =
* New: link suggestions in the block editor and in the classic editor. A Magic Linking panel proposes phrases of your text that can link to other entries, with the anchor highlighted and the reason.
* New: add a link with one click. In the editor it is inserted in the editor itself, only on that phrase.
* New: inbound suggestions: link to the entry you are editing from other entries that mention it. The link is written in the other entry after checking that nothing else in it changes, and WordPress keeps a revision.
* New: History with undo and redo for every link added from the server, with a configurable retention (30 days, 90 days, a year or forever). Large groups are undone in the background.
* New: the index used for suggestions is stored in the database and built in the background. After updating from an earlier version it is built automatically, without you having to analyze again; no suggestions are shown until it is ready.
* Improved: cleaner anchor text. Suggested phrases no longer start or end with small words such as "the", "of", "el" or "de", and the reasons shown with each suggestion are cleaner too.
* New: WP-CLI `wp magic-linking history` (list, undo, redo and purge).
* New: new filters and actions for developers (see the FAQ).
* Improved: contrast of the secondary buttons in WordPress 6.9.

= 0.9.2 =
* Table schema rewritten without callable interpolation inside SQL strings (the generated SQL is identical).
* The package no longer contains development folders (such as `.claude`); the build now fails if any hidden or development file gets into the ZIP.
* Requirements (PHP 8.1, WordPress 6.9, mbstring) are checked before anything else loads. On activation without them the plugin deactivates itself with an explanatory message instead of failing.
* Added unit tests for the schema statements.

= 0.9.1 =
* Readme: the bundled Action Scheduler is 4.2.x (the third-party libraries section still said 3.9.x).
* No functional changes.

= 0.9.0 =
* First public version: "Analyze changes" (skips unchanged entries) and "Analyze everything again from scratch", report of orphan, under-linked and over-linked entries, complete list of broken internal links, CSV export, WP-CLI commands and settings. It never modifies content.
* Requires WordPress 6.9 or later and bundles Action Scheduler 4.2.0.

== Upgrade Notice ==

= 0.10.0 =
Adds link suggestions in the editor. After updating, the suggestions index is built in the background; it may take a few minutes on a big site. The plugin can now add links, always when you press Link and with Undo in History. The update does not touch your content.

= 0.9.2 =
Table schema rewritten without callable interpolation, package without development folders and clearer requirement checks on activation. No changes to your data.

= 0.9.1 =
Documentation fix only. No functional changes.
