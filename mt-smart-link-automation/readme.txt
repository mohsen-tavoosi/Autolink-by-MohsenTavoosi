=== MT Smart Link Automation ===
Contributors: mohsentavoosiseo
Tags: internal links, seo, automatic links, keywords, elementor
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mohsen Tavoosi's automatic internal links. Adds links to your keywords while the page is shown. Your posts are never changed.

== Description ==

[Mohsen Tavoosi](https://mohsentavoosi.com), an SEO practitioner since 2012, built MT Smart Link Automation from his teaching on automatic internal linking. The rules inside it (longest keyword first, limits per page and paragraph, keyword order) are the ones he teaches.

* [General rules of automatic internal linking](https://mohsentavoosi.com/en/p-en/automate-internal-links-en/) - the principles for any site and platform.
* [WordPress guide](https://mohsentavoosi.com/en/p-en/wordpress-auto-internal-links-en/) - how to set up and use this plugin.

You list the keywords and the page each group should link to. The plugin adds the links when a page is rendered. Nothing is saved into your posts, so you can turn it off at any time and every link disappears.

**What it does**

* Several keywords per link (up to 20), all pointing to one URL.
* Whole-word matching, not case sensitive. A longer keyword wins over a shorter one and keeps its place, even when it reached its limit.
* Smart link diversity (on by default): each record links its keywords in their saved order, so links spread over all your keywords.
* On WordPress 6.9 and later the whole page is processed at once, so limits and keyword order count every widget and block of the page together. Older versions process each part of the page on its own.
* Exclude any post, page, product or term by searching its title. Front page on or off.
* Limit per page and per paragraph for each record. Paragraphs can be detected by `<p>` and `<br>` or by the text between headings.
* Existing links are respected. A link with the exact keyword text and the right URL is kept. One with the wrong URL is corrected on screen. Longer link texts and unrelated links are left alone.
* A page never links to itself. A record can be limited to chosen posts.
* Works with the block editor, the classic editor, Elementor, WPBakery, Toolset, WooCommerce (full and short description) and term descriptions (category, tag, product category, custom taxonomies).
* **Half-space (ZWNJ) switch.** On by default. A space and a half-space inside a keyword are the same, in your keywords and in the page text, so you write each keyword once. Turn it off for languages where they differ.
* Import and export as JSON for backups and moving between sites.
* Master on/off switch without deactivating the plugin.
* The admin screen is in English and ready for translation.

**Privacy.** The plugin does not collect data, set cookies or contact other sites.

== Installation ==

1. Upload the plugin or install it from the Plugins screen, then activate it.
2. Open **Internal Links** in the admin menu.
3. Choose post types and taxonomies, then add your records.
4. If you use a page cache or CDN, clear it after changing records.

== Documentation ==

* General rules: https://mohsentavoosi.com/en/p-en/automate-internal-links-en/
* WordPress guide: https://mohsentavoosi.com/en/p-en/wordpress-auto-internal-links-en/

== Frequently Asked Questions ==

= Does it change my posts? =
No. Links exist only in the page output. Deactivating the plugin or turning the master switch off removes them all.

= What does the half-space switch do? =
With it on, `درب اتوماتیک` and `درب‌اتوماتیک` are the same keyword. A half-space at the edge of a word still blocks a partial match, so `کتاب` is not linked inside `کتاب‌ها`.

= Do I need to clear my cache? =
Yes, page caches and CDNs keep the old HTML. Clear them after you change records.

= What happens when I delete the plugin? =
Your records are kept unless you ticked "Delete all plugin data when the plugin is deleted".

== Changelog ==

= 2.2.2 =
* Rename: the plugin is now MT Smart Link Automation (slug `mt-smart-link-automation`). Records, settings and files exported under earlier names keep working.
* Fix: the plugin no longer opens an output buffer. The whole page is processed with the WordPress 6.9 filter `wp_template_enhancement_output_buffer`. Older WordPress versions process each part of the page on its own.
* Change: WooCommerce category descriptions are processed with the WooCommerce filter `woocommerce_taxonomy_archive_description_raw` (WooCommerce 6.7 and later).

= 2.2.1 =
* Fix: Save, Delete, Import, Export and the title search in the settings screen used internal names from before the rename. They now work.
* Dev: database queries on the plugin's own table are marked for Plugin Check. No change in behavior.

= 2.2.0 =
* Change: internal names changed from `ail_` to `mtal_`. Your records and settings move automatically on the first load. Export a backup before updating.
* New: a longer keyword always keeps its place, even after it reached its limit. A shorter keyword is never linked inside it.
* New: "Smart link diversity order by keywords per link" (on by default). Link 1 goes to keyword 1, the next to the first keyword not linked yet on the page, up to keyword 20, then it starts again.
* New: option to unlock longer phrases after their page limit (off by default).
* New: option to link a shorter phrase once inside a longer one when it has no link yet on the page (off by default).
* New: the whole page is processed in one pass. Only content is changed, never menus, header or footer.
* New: front page switch, and an exclusion list with title search for posts, pages, products and terms.
* New: "Only on these pages" in a record is picked by title search instead of typing IDs.
* Change: the old "longer keyword first inside one record" order is replaced by smart link diversity. Turn it off to link in text order.
* Fix: a target URL saved with `&amp;` is no longer escaped twice.

= 2.1.0 =
* New: half-space (ZWNJ) switch, on by default.
* New: choose post types and taxonomies. Term descriptions and WooCommerce short descriptions are supported.
* New: WPBakery, Toolset and block editor coverage through the content filter, with protection against double processing on Elementor pages.
* New: URL safety check (http, https or a path starting with one slash).
* New: uninstall option, update-safe database upgrade, translation support.
* Fix: the longest keyword now wins across all records and paragraph modes.
* Fix: every post gets its own counters on archive pages.
* Fix: Import validates the whole file before it replaces anything, and refuses files over 1 MB.
* Fix: the "maximum 20 keywords" message now appears.
* Fix: links with `&` in the URL are no longer double escaped.

= 2.0.0 =
* Multiple keywords per record, paragraph limits, Import and Export.

== Upgrade Notice ==

= 2.2.2 =
New name and slug, and no output buffer opened by the plugin. Records and settings are kept. Clear your page cache after the update.

= 2.2.1 =
Fixes Save, Delete, Import, Export and search in the settings screen. Update recommended.

= 2.2.0 =
Export a backup first. Smart link diversity is on by default and can change which keyword of a record gets the link. Clear your page cache after the update.

= 2.1.0 =
Export a backup first (Internal Links, Import / Export). The half-space switch is on by default.
