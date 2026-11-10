# Changelog

## 2.2.2
- Rename: the plugin is now "MT Smart Link Automation". Slug, folder, main file and text domain are `mt-smart-link-automation`. Records, settings and files exported under earlier names keep working (WordPress.org review, 5 Nov 2026).
- Fix: the plugin no longer opens an output buffer. Whole-page processing uses the WordPress 6.9 filter `wp_template_enhancement_output_buffer` (registered only on front-end HTML pages). On WordPress before 6.9 every fragment is processed on its own, as in 2.1. The filter `mtal_whole_page` still turns it off.
- Change: WooCommerce category descriptions are processed with the filter `woocommerce_taxonomy_archive_description_raw` (WooCommerce 6.7 and later) instead of capturing the output of an action. The text is processed on its own, because WooCommerce formats it right after the filter.
- Change: the plugin description now starts with the author's name.
- Dev: export file name is `mt-smart-link-automation-export-<date>.json`.

## 2.2.1
- Fix: the admin_post and AJAX hooks for save, delete, import, export and title search were still registered under the old `ail_` names after the prefix change, so those buttons did nothing. They are now `mtal_`. A new test (`tests/action-names.php`) compares form actions, hooks, JavaScript and nonces and runs in CI.
- Dev: Plugin Check findings on the plugin's own table are marked with a reason. No change in behavior.
- Dev: export file name is `autolink-export-<date>.json` (renamed in 2.2.2).

## 2.2.0
- Rename: the plugin was renamed from "Auto Internal Links" (this name was replaced again in 2.2.2).
- Change: the code prefix is now `mtal_` / `MTAL_` (was `ail_`), the table is `{prefix}mtal_keywords`. On the first load the old table is renamed and the old options are copied, then removed. Import still accepts files exported under the old name and prefix.
- New: links to the author's two guides in the plugin list and on the settings screen (admin only, nothing on the front end).
- New: long-phrase lock. A claimed spot of a longer keyword is never used by a shorter keyword, even when the longer one reached its page or paragraph limit (owner decision, 2026-10-24).
- New: smart link diversity (`mtal_diversity`, on by default). Per record, keyword order decides which keyword gets each link, round by round.
- New: `mtal_unlock_capped` (off): a long phrase at its limit frees its spot.
- New: `mtal_short_inside` (off): a short phrase with no link yet on the page links once inside a long phrase without a link.
- New: whole-page processing. Fragments are marked, all fragments of a post or term are processed together at the end of the page. Filter `mtal_whole_page` returns false to go back to per-fragment processing.
- New: `mtal_front_page` switch, `mtal_exclude_posts` and `mtal_exclude_terms` with AJAX title search (`wp_ajax_mtal_search`).
- New: record "only on these pages" uses the same search picker (stored as before, comma list of IDs).
- Change: plugin vector R07-03 updated for smart diversity.
- Fix: entity-encoded `&amp;` in a stored target URL was escaped twice (shared vector R21-02).

## 2.1.0
- New: half-space (ZWNJ) switch, on by default. A space and a half-space inside a keyword are the same.
- New: post type and taxonomy selection (R18), term descriptions, WooCommerce short description and category description.
- New: WPBakery, Toolset and block editor coverage, guard against double processing on Elementor pages (R19).
- New: URL safety rule (http, https or a single-slash path) in forms, import and output (R21).
- New: uninstall option (off by default), update-safe database upgrade, translation template (R22, R23).
- Fix: longest keyword wins across all records and both paragraph modes (R07), ties by record id (R24).
- Fix: separate counters for every post on archive pages (R15).
- Fix: Import validates the whole file first, refuses files over 1 MB, reports the reason for each skip.
- Fix: "maximum 20 keywords" message could never appear.
- Fix: migration no longer drops the old column unless every row was copied.
- Fix: URL with `&` was escaped twice.
- Fix: anchors without href, `mailto:` and `#` links are never touched.

## 2.0.0
- Multiple keywords per record, paragraph limits, Import and Export.
