=== Magic Linking ===
Contributors: dcarrero
Tags: internal links, orphan pages, broken links, seo, report
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.9.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find orphan pages, under-linked and over-linked entries and broken internal links. It never changes your content.

== Description ==

Magic Linking reads the internal links that are already written in your posts and pages and gives you a clear report:

* **Orphan pages**: entries that no other entry links to.
* **Under-linked pages**: entries with some inbound links, but fewer than you consider enough (2 by default, you can change it).
* **Over-linked pages**: entries with more internal links than one for every 100 words (you can change that too). A short text may always have one link.
* **Broken internal links**: links to an entry that is in the trash, is a draft or private, or does not exist. The complete list, with the anchor text, the reason and a link to edit the entry.
* Numbers for every entry: inbound, internal outbound, external and broken links.
* Filters, search by title, sorting, pagination and **CSV export** of the view you are looking at.
* The same report from the terminal with WP-CLI.

**What it does not do (in this version)**

* It does not modify any content. Not a single link is added, changed or removed.
* It does not use artificial intelligence and does not call any external service.
* It does not check links over the network: a link is "broken" when it points to an address on your own site that leads nowhere, according to WordPress. Links to other sites are counted, not checked.
* It does not show notices anywhere except on its own screen, does not ask for your email, does not send any data out of your site and does not track you.

**How links are counted**

* Only the links written inside the content of each entry are counted. Links in menus, widgets, theme templates or blocks that are pulled from somewhere else are not, so your home page or a page that is only linked from the menu can appear as an orphan.
* Content that a page builder keeps outside the normal post content is not read. If your builder saves rendered HTML in the post content, it is read.
* Inbound = how many different entries link to this one, counting only links that work. Outbound = internal links to other pages of your site; every appearance counts. A link to the same entry, to a file (uploads, PDF, images) or to `mailto:` and `tel:` addresses is not counted.
* Category, tag, author, date and search pages, the home page and feeds are valid destinations and are never reported as broken.
* Tracking parameters (`utm_*`, `gclid`, `fbclid`…) and anchors (`#…`) are ignored when matching a link to an entry.
* The language of each entry comes from WPML, Polylang or, if you use neither, from the language of the site. The language column, the language filter and the language column of the CSV only appear if WPML or Polylang is active, or if your entries are in more than one language.

**Made for big sites**

The analysis runs in the background in small batches with Action Scheduler (bundled), can be paused, resumed and cancelled, and every entry is analysed again when you save it. The front end of your site is never touched: no scripts, no styles and no database queries from this plugin on your public pages.

**Roadmap**

Later versions will add link suggestions in the editor, safe insertion of links with undo, automatic rules and, if you want it, help from the AI connectors that WordPress already provides. Nothing of that is in this version, and nothing in the plugin is locked or "coming soon".

== Installation ==

1. Install the plugin and activate it.
2. Go to **Magic Linking** in the admin sidebar and press **Analyze my site**. Nothing is analysed until you do it.
3. Read the report. Use the filters *Orphans*, *Under-linked*, *Over-linked* and *With broken links*, or open *Broken links* to fix them one by one.

From the terminal: `wp magic-linking index` analyses the site and `wp magic-linking report --filter=orphans --format=csv` prints the orphan pages as CSV.

== Frequently Asked Questions ==

= Does it change my posts or pages? =

No. It only reads them. The only things it writes are its own tables in your database.

= Does it need AI, an API key or an account? =

No. It works entirely on your server, with no key, no account and no credit.

= Why does my home page (or another page) appear as an orphan? =

Because it is not linked from the content of any other entry. If it is linked from your menu, the menu is not counted. See "How links are counted".

= Why is a link not listed as broken if the page is really gone? =

A link is reported when it points to an entry in the trash, unpublished or private, or to an address of your site that WordPress does not recognise. If a redirect plugin sends the old address elsewhere, the link may still work for your visitors. Developers can tell Magic Linking about redirects with the `magiclinking_resolve_link` filter.

= Does it work with WPML or Polylang? =

Yes. It records the language of every entry and, when WPML or Polylang is active (or the analysis finds more than one language), shows it in the report, lets you filter by it and adds it to the CSV. On a single-language site those columns are hidden.

= How long does the first analysis take? =

A few seconds for a small site; thousands of entries per minute on ordinary hosting. It runs in the background; you can keep working and watch the progress on the screen.

= The analysis does not move. =

It needs Action Scheduler to run, which WordPress triggers with WP-Cron. On sites with very little traffic, use a system cron, or run `wp action-scheduler run --group=magic-linking`, or analyse directly from the terminal with `wp magic-linking index`.

= How do I analyze only what changed? =

Press "Analyze changes" in the report (or run `wp magic-linking index`). It goes through every entry but skips the ones whose content has not changed since the last analysis, and tells you how many were new or modified. Entries are also analysed again automatically when you save them.

= How do I analyze the whole site again from scratch? =

Go to Magic Linking > Settings, open the "Maintenance" section and press "Analyze everything again from scratch" (or run `wp magic-linking index --force`). It asks you to confirm and then runs in the background, even for entries that have not changed. It never changes your content.

= What happens when I delete the plugin? =

By default your data is kept. If you tick "Delete all Magic Linking data when the plugin is deleted" in the settings, its tables and settings are removed. Your content is never touched.

= Is it multisite compatible? =

Each site of a network has its own report and settings.

= Which hooks can developers use? =

`magiclinking_post_language` (set the language of an entry), `magiclinking_internal_hosts` (extra hosts that count as your site), `magiclinking_resolve_link` (say where an internal address leads) and `magiclinking_post_html` (add HTML that a builder keeps outside the content).

== Screenshots ==

1. The report: totals for analyzed entries, orphans, internal links and broken links, with filters, search and sorting.
2. The Orphans filter: entries that no other entry links to, with quick Edit and View actions on each row.
3. Broken internal links: the anchor text, the reason it is broken and the entry to edit, with CSV export.
4. Settings and maintenance: choose the content types, adjust the thresholds and analyze everything again from scratch.

== Support and documentation ==

Documentation and news: https://magiclinking.com

Questions and bug reports: use the support forum of this plugin on WordPress.org.

== External services ==

Magic Linking does not connect to any external service.

== Privacy ==

Magic Linking does not collect, store or send personal data. It stores, in your database, the addresses and anchor texts of the internal links found in your own content, and counts per entry. It does not set cookies.

== Third-party libraries ==

* Action Scheduler (WooCommerce) 4.2.x, GPL-3.0-or-later, bundled in `vendor/woocommerce/action-scheduler/`. Used to run the analysis in the background.

== Changelog ==

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

= 0.9.2 =
Table schema rewritten without callable interpolation, package without development folders and clearer requirement checks on activation. No changes to your data.

= 0.9.1 =
Documentation fix only. No functional changes.
