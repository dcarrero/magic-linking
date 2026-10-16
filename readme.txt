=== Magic Linking – Internal Links ===
Contributors: dcarrero
Tags: internal links, seo, link suggestions, orphan pages, broken links
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.22.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Internal link suggestions in the editor. Link in one click, check that nothing else changes, undo from History. Plus orphan and broken link reports.

== Description ==

Magic Linking suggests internal links while you write, adds the one you choose without touching anything else, and keeps a history so you can undo it. It also reports which of your entries are orphans, under-linked, over-linked or have broken internal links.

It works entirely on your server, with no account, no key and no limits. Artificial intelligence is optional: it only runs if you have connected an AI provider in WordPress (Settings > Connectors) and press *Improve with AI* in the panel.

**In the editor**

* **Outbound suggestions**: a Magic Linking panel in the block editor (and a box in the classic editor) proposes phrases of the text you are writing that could link to other entries of your site. Each suggestion shows the phrase with the anchor text highlighted in the editor, the destination and the reason.
* **One click to link**: press *Link* and the link is added in the editor itself, on that phrase only. It is part of your unsaved changes until you save, and you can undo it with the *Undo* button of the card or with the editor's own undo.
* **Inbound suggestions**: the same panel lists other entries whose text mentions the one you are editing and could link to it. Pressing *Link* writes the link in that other entry, see "How and when it changes your content".
* Only the entries and block types where a link can be inserted safely are proposed. Paragraphs and lists are always used; headings, quotes and tables are left alone unless you turn them on in Settings (or a developer allows them with a filter).
* **Dismiss**: a suggestion you do not want can be dismissed for good, from either side, and restored with *Undo*.
* **Improve with AI (optional)**: if your site has an AI connector (WordPress 7.0 or later, Settings > Connectors), the *Outbound* tab shows an *Improve with AI* button. One click sends the best 20 suggestions to your AI provider in a single request; it puts the best ones first, marks the ones it does not recommend and may propose a better anchor text (always a stretch of the same sentence, and only used if you accept it). It shows what that request cost. See "AI and your privacy".

**How and when it changes your content**

* Nothing is changed until you press *Link* on a suggestion. There are no automatic changes and no background rewriting.
* For the entry you have open, the link is inserted in the editor; saving is up to you.
* For inbound suggestions, the link is written into the other entry on the server. Before saving, Magic Linking checks that the rest of that entry stays exactly as it was: only the link is added. If the check fails, it writes nothing and tells you why. WordPress keeps a revision of the entry as usual.
* Every change is recorded in **History**, where you can undo it and redo it. Links you add from the editor panel are listed there too once you save the entry; Magic Linking only records them. Undo removes only that link; if the text around it was edited since, it tells you instead of guessing.
* An entry that is open in someone's editor is never modified from the server.
* Links already written in your content are never removed or rewritten, except to undo a link Magic Linking added or when you fix a broken link from its list.

**History**

* Every link Magic Linking changes is listed with who did it, when, where and the anchor text: links added from an inbound suggestion, links you add from the editor panel (they appear once you save the entry; Magic Linking only records them, and marks them as undone if you remove them and save) and fixes made from the broken links list.
* Changes are grouped by entry, newest first, with a date on each one. Filter by status, source or title, switch to a view by batch, and undo or redo one change, all the changes of an entry, or a whole batch.
* Undo and redo from the screen or with WP-CLI (`wp magic-linking history undo --post=<id>`). A large group is undone in the background.
* Export the history to CSV, one row per change and no row limit, from the **Export CSV** button (it exports what the current filters show) or with WP-CLI (`wp magic-linking history export --format=csv`). It includes the date, user, entry, destination, anchor text, source and, for links you added after "Improve with AI", whether the AI recommended them and with which model.
* The history is kept 90 days by default; you can choose 30 days, a year or forever.

**Report**

* **Orphan pages**: entries that no other entry links to.
* **Under-linked pages**: entries with some inbound links, but fewer than you consider enough (2 by default, you can change it).
* **Over-linked pages**: entries with more internal links than one for every 100 words (you can change that too). A short text may always have one link.
* **Broken internal links**: links to an entry that is in the trash, is a draft or private, or does not exist. The complete list, with the anchor text and the reason. Fix each one from the list: change its destination to another published entry, or remove the link and keep its text. Magic Linking checks that nothing else in the entry changes, and you can undo it from History.
* Numbers for every entry: inbound, internal outbound, external and broken links.
* Filters, search by title, sorting, pagination and **CSV export** of the view you are looking at.

**Also**

* **WP-CLI**: analyse the site, print reports and manage the history from the terminal.
* **Multilingual**: the language of each entry comes from WPML, Polylang or, if you use neither, from the site language. Suggestions never link entries of different languages.
* **Made for big sites**: the analysis and the index of suggestions are built in the background in small batches with Action Scheduler (bundled), and every entry is updated when you save it. Suggestions for an entry are calculated in a few milliseconds even with thousands of entries.
* **Light**: no scripts, no styles and no database queries from this plugin on your public pages.
* **Private**: no telemetry, no cookies, no email requests, no notices outside its own screens, and nothing leaves your server unless you press *Improve with AI* with an AI connector (see "AI and your privacy").

**What it does not do (in this version)**

* It does not need artificial intelligence. Everything works without it. When you use it, it is only through the AI client and the *Connectors* screen that WordPress itself provides, never with keys or credit of its own.
* It does not check links over the network: a link is "broken" when it points to an address on your own site that leads nowhere, according to WordPress. Links to other sites are counted, not checked.
* It does not suggest or add links automatically while you are away. Every link is a click of yours.

**How links are counted**

* Only the links written inside the content of each entry are counted. Links in menus, widgets, theme templates or blocks that are pulled from somewhere else are not, so your home page or a page that is only linked from the menu can appear as an orphan.
* Content that a page builder keeps outside the normal post content is not read. If your builder saves rendered HTML in the post content, it is read.
* Inbound = how many different entries link to this one, counting only links that work. Outbound = internal links to other pages of your site; every appearance counts. A link to the same entry, to a file (uploads, PDF, images) or to `mailto:` and `tel:` addresses is not counted.
* Category, tag, author, date and search pages, the home page and feeds are valid destinations and are never reported as broken.
* Tracking parameters (`utm_*`, `gclid`, `fbclid`…) and anchors (`#…`) are ignored when matching a link to an entry.

**AI and your privacy**

* Magic Linking has no AI of its own and asks for no key. It uses the AI client and the *Connectors* screen of WordPress 7.0 or later. On WordPress 6.9 the AI options are turned off and the plugin works as usual.
* Nothing is sent until you press *Improve with AI*. Finding out whether a connector exists does not call any model.
* What is sent, in that one request: the sentence of the entry you are editing with the anchor text marked, and for each of the 20 suggestions the title and a short summary (about 200 tokens) of the destination, made of its title, headings and the beginning of its text. Only clean text: no metadata, authors, email addresses or comments. Private, password-protected and draft entries are never sent as destinations.
* Where it goes: to the AI provider you configured in Settings > Connectors. If your connector is a local model (for example Ollama), the text does not leave your server.
* You set a daily limit of tokens (2,000,000 by default; 0 turns AI off). When it is reached, AI pauses until the next day and the suggestions use the built-in engine. Settings > AI shows the connector, the limit and what was used in the last 30 days. Costs are estimates with public list prices; your provider is the one that charges you.

**Roadmap**

Next: several phrases per destination and adjustable anchor text, automatic rules and, with the AI connectors that WordPress already provides, suggestions by meaning (when WordPress offers embeddings).

== Installation ==

1. Install the plugin and activate it.
2. Go to **Magic Linking** in the admin sidebar and press **Analyze my site**. Nothing is analysed until you do it. The first time it also builds the index used for suggestions, in the background.
3. Open any post or page. In the block editor, open the **Magic Linking** panel from the top bar (or the three dots menu); in the classic editor, use the **Magic Linking** box.
4. Read the suggestions and press **Link** on the ones you like.
5. For the site-wide picture, use the filters *Orphans*, *Under-linked*, *Over-linked* and *With broken links* in the report, or open *Broken links* to change the destination of each one or remove it.

From the terminal: `wp magic-linking index` analyses the site and `wp magic-linking report --filter=orphans --format=csv` prints the orphan pages as CSV.

== Frequently Asked Questions ==

= Does it change my posts or pages? =

Only when you press *Link* on a suggestion, and only by adding that link.

* Outbound suggestions (for the entry you are editing): the link is inserted in the editor itself. You save the entry when you want, as with any other change.
* Inbound suggestions (links from other entries to this one): the link is written into the other entry on the server. The rest of that entry must stay identical, byte for byte, or nothing is written. WordPress creates a revision, and the change is listed in History, where you can undo it.
* It never modifies an entry that is open in an editor, and it only edits, removes or rewrites a link you already had when you ask for it from the broken links list (change its destination or remove it, keeping its text), with the same check and undo.

Everything else (the report, the index, the settings, the broken links list until you fix one) only reads your content and writes to its own tables.

= How do I undo a link? =

Right after adding it, press *Undo* on the card. Later, open **Magic Linking > History** and undo it there. You can also redo it. If the text around the link has been edited since, Magic Linking tells you instead of changing anything it cannot be sure about.

= Does it need AI, an API key or an account? =

No. It works entirely on your server, with no key, no account and no credit. AI is an optional extra: if you connect a provider in Settings > Connectors (WordPress 7.0 or later), the *Improve with AI* button appears. Magic Linking never asks for an API key of its own and does not sell credit; your provider charges you for what you use.

= Does it send my content anywhere? =

Not by itself. The only time text leaves your server is when you press *Improve with AI*, and then it goes to the AI provider you set up in Settings > Connectors, as described in "AI and your privacy" and "External services". With a local model such as Ollama it does not leave your server.

= How much does *Improve with AI* cost? =

The button shows an estimate before you press it and the real number of tokens afterwards. A request uses a few thousand tokens, which with a small model costs a fraction of a cent. You can set a daily token limit in Settings > AI. A local model costs nothing.

= Why is there no AI button? =

Because the site has no AI connector (add one in Settings > Connectors), WordPress is older than 7.0, the daily limit in Settings > AI is 0, or the site blocks AI requests.

= Why does the panel say there are no suggestions? =

The suggestions come from the index of your site, which is built in the background after you press *Analyze my site*. Until it is ready the panel says so. Also, suggestions need text: a very short entry has little to suggest, and entries in another language, drafts, private entries and password-protected ones are never proposed as destinations.

= Why can't I link from this phrase? =

Links are only inserted in paragraphs and lists, and never inside an existing link. Headings, quotes and tables are off by default: turn them on in Magic Linking > Settings > Links > Where links can be inserted. A developer can allow more block types with `magiclinking_insertable_blocks`.

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

Go to Magic Linking > Settings, open the General tab, find the "Maintenance" section and press "Analyze everything again from scratch" (or run `wp magic-linking index --force`). It asks you to confirm and then runs in the background, even for entries that have not changed. It never changes your content.

= What happens when I delete the plugin? =

By default your data is kept. If you tick "Delete all Magic Linking data when the plugin is deleted" in the settings, its tables (index, links, history), processes, settings and metadata are removed. The links already written in your content stay where they are; your content is never touched.

= Is it multisite compatible? =

Each site of a network has its own report, history and settings.

= Which hooks can developers use? =

* `magiclinking_link_inserted`: action that runs after a link has been inserted from the server.
* `magiclinking_batch_undone`: action that runs after a group of links has been undone.
* `magiclinking_link_fixed`: action that runs after a broken link has been fixed (destination changed or link removed), or that fix has been redone.
* `magiclinking_insertable_blocks`: filter for the block types where links can be inserted.
* `magiclinking_post_lock_timeout`: filter for the seconds to wait when another insertion or undo is writing the same entry.
* `magiclinking_undo_sync_limit`: filter for how many changes are undone at once before the rest goes to the background.
* `magiclinking_post_language`: set the language of an entry.
* `magiclinking_internal_hosts`: extra hosts that count as your site.
* `magiclinking_resolve_link`: say where an internal address leads.
* `magiclinking_post_html`: add HTML that a builder keeps outside the content.
* `magiclinking_before_ai_request`: filter to veto or change a request to the AI connector before it is sent (return false to veto). The WordPress filter `wp_ai_client_prevent_prompt` is respected too.
* `magiclinking_local_ai_providers`: filter for the AI providers that count as local (the text does not leave your server).
* `magiclinking_is_multilingual`: tell the plugin whether the site is multilingual.
* `magiclinking_batch_max_entries` and `magiclinking_batch_time_budget`: size and time of each background batch.

== Screenshots ==

1. Outbound suggestions in the block editor: each card shows the destination and the reason, and the anchor text is highlighted in the text.
2. A link added with one click, with the Undo button on the card.
3. Inbound suggestions: other entries that could link to the one you are editing.
4. History: changes grouped by entry, with date, filters, and undo and redo.
5. The report: totals for analyzed entries, orphans, internal links and broken links, with filters, search and sorting.
6. Settings in tabs (General, Suggestions, Links, AI, History and data) with a save bar that stays in view.

== Support and documentation ==

Documentation and news: https://magiclinking.com

Questions and bug reports: use the support forum of this plugin on WordPress.org.

== External services ==

Magic Linking does not connect to any external service by itself and has no servers of its own. It has no API keys, no accounts and no telemetry.

The only exception is the optional **Improve with AI** button, which exists only if you have connected an AI provider to WordPress yourself. Magic Linking does not call any provider directly: it uses the AI client that WordPress 7.0 and later provide (`wp_ai_client_prompt()`), which sends the request to the provider you configured on the **Settings > Connectors** screen of your site (`wp-admin/options-connectors.php`), for example OpenAI, Google, Anthropic or a local Ollama.

* **What is sent**: the sentence of the entry you are editing, with the anchor text marked, and, for each of the best 20 suggestions, the title and a summary of about 200 tokens of the destination entry (its title, headings and the start of its text). Only clean text. No metadata, authors, email addresses, comments or private fields. Private, password-protected and draft entries are never sent as destinations. The entry you are editing is sent only because you pressed the button.
* **When**: only when you press *Improve with AI*, once per press. Never in the background, never on page load and never on the public side of your site.
* **To whom**: to the provider you configured in Settings > Connectors. Their terms and privacy policy apply to that request, for example OpenAI (https://openai.com/policies), Google (https://policies.google.com) or Anthropic (https://www.anthropic.com/legal). If you use a local model, nothing leaves your server.
* **Control**: you choose the provider, you can set a daily token limit (0 turns it off) in Magic Linking > Settings > AI, and a site can veto requests with the `wp_ai_client_prevent_prompt` filter.

Magic Linking stores the number of tokens used, the provider and the model of each request (not the text) to show your usage in Settings > AI.

== Privacy ==

Magic Linking does not collect personal data and sends nothing anywhere on its own. It stores, in your database, the addresses and anchor texts of the internal links found in your own content, a word index of your entries used for suggestions, counts per entry and a history of the links it has added or fixed, including those you add from the editor panel (which user, when, which entry and the text of the paragraph that changed, so it can be undone). The pairs (entry, target) of the suggestions you dismiss, with the user and the date, so they are not proposed again. It does not set cookies. For the optional AI button it also stores, for each request, the date, the user, the provider, the model, the tokens used and an estimated cost, never the text that was sent; and, for an hour, the result of checking what your AI connector can do. See "External services" for what is sent when you press that button.

== Third-party libraries ==

* Action Scheduler (WooCommerce) 4.2.x, GPL-3.0-or-later, bundled in `vendor/woocommerce/action-scheduler/`. Used to run the analysis and the index in the background.

== Changelog ==

= 0.22.1 =
* Fixed: in an entry whose whole content is a Classic block inside the block editor, the Link button now adds the link (it said "the text has changed"). Only the link is added: the rest of the block's HTML stays byte for byte as it was. When the sentence is somewhere the panel cannot link (a heading, quote or table you have not allowed, or a shortcode), it now says so instead of claiming the text changed.
* Fixed: History shows the titles of entries as text (no "&#8230;" or "&amp;"), also in the CSV export.
* New: History marks the links the AI recommended ("Editor panel · AI"), with the model and whether its anchor text was used, and you can filter by them. This adds three columns to the history table; the update runs by itself.
* New: export the History to CSV (button and `wp magic-linking history export`), one row per change, with the filters you have on screen.
* Improved: the download package is smaller.

= 0.21.1 =
* Fixed: "Improve with AI" now uses exactly the model you choose. Before, with some providers such as OpenRouter, WordPress could silently send the request to another model and it ran out of time.
* Fixed: model names from OpenRouter (such as "openai/gpt-4.1-mini") are recognized, so the automatic choice and the cost estimate work. Batch-only variants are never used.
* Improved: the automatic choice now prefers small, cheap and fast models, and the model list in Settings > AI shows a "Recommended" group first, with prices.
* Improved: Settings > AI shows the reason of the last failed AI request, without keys or text from your entries.
* Improved: cloud providers get more time to answer (up to 50 seconds), and the editor panel says how long it may take.

= 0.21.0 =
* New: optional "Improve with AI" button in the editor panel. If your site has an AI connector (WordPress 7.0 or later, Settings > Connectors), one click sends the best suggestions to your AI provider in a single request: it puts the best ones first, marks the ones it does not recommend and may propose a shorter, better anchor text taken from the same sentence, only used if you accept it. Without a connector everything works as before.
* New: before each request the button shows the provider, the model and an estimate of the tokens; local models such as Ollama are shown as free. A daily token limit (Settings > AI) is checked before anything is sent, and requests are limited to 10 per minute.
* New: Settings > AI shows what your connector can do, lets you choose the model and the daily token limit (0 turns AI off), and lists the tokens used and the estimated cost per model. Only the date, user, provider, model, tokens and cost are stored, never the text sent.
* New: if the AI does not answer, the panel says so and keeps the suggestions without AI.
* Improved: the package no longer includes the JavaScript tests or the translation template.

= 0.20.0 =
* New: fix a broken internal link from the list, one by one: change its destination to another published entry, or remove the link and keep its text. The rest of the entry is verified unchanged, and you can undo it from History.
* New: History also records the links you add from the editor panel, once you save the entry, and marks them as undone if you remove them and save. This adds two columns to the history table; the update runs by itself.
* Improved: History is more compact and grouped by entry, with the date of every change, filters, "Undo all" and "Redo all" per entry (in the background when there are many) and a view by batch.
* New: WP-CLI `wp magic-linking history undo|redo --post=<id>`.
* Improved: Settings are organized in tabs (General, Suggestions, Links, History and data) with a save bar that stays in view.
* Improved: the editor panel is more compact after you link or dismiss a suggestion; its title no longer repeats the count, which is still announced to screen readers.
* Improved: History says where each link came from: "Editor panel", "Another entry's panel" or "Broken links".
* Improved: translations are up to date in all six bundled languages.

= 0.11.0 =
* New: dismiss a suggestion for good. A dismissed pair (entry, target) is not proposed again, in either direction, and you can undo it. A target dismissed from more than 10 published entries is ranked slightly lower across the site.
* New: bundled translations into Spanish (Spain), French, German, Italian and Portuguese (Brazil and Portugal). Translations approved on translate.wordpress.org take precedence when available.
* Improved: translations are complete in all six bundled languages.
* New: setup assistant for new sites, and new settings: where links can go (headings, quotes, tables), open links in a new tab, extra rel values, suggestions shown, ignored addresses and words.
* Changed: links in quotes are now off by default, as in the new settings. Before, a paragraph inside a quote block could receive an automatic link; now it only does if you turn on "Quotes" in Settings.

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

= 0.22.1 =
The Link button now works in entries made of a single Classic block in the block editor. History now marks the links the AI recommended and can be exported to CSV. The update adds three columns to the history table by itself and does not touch your content.

= 0.21.1 =
Fixes "Improve with AI" with OpenRouter and other providers with many models: the chosen model is used exactly, cheaper models are recommended and the reason of a failure is shown. The update does not touch your content.

= 0.21.0 =
Adds an optional "Improve with AI" button that uses the AI connector of WordPress 7.0 or later, with a token estimate and a daily limit checked before anything is sent. Nothing changes if you have no connector. The update does not touch your content.

= 0.20.0 =
History now also records links added from the editor panel (two new columns in its table, updated automatically), and broken links can be fixed one by one. The update does not touch your content.

= 0.10.0 =
Adds link suggestions in the editor. After updating, the suggestions index is built in the background; it may take a few minutes on a big site. The plugin can now add links, always when you press Link and with Undo in History. The update does not touch your content.

= 0.9.2 =
Table schema rewritten without callable interpolation, package without development folders and clearer requirement checks on activation. No changes to your data.

= 0.9.1 =
Documentation fix only. No functional changes.
