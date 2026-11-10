# MT Smart Link Automation

WordPress plugin that adds internal links to your keywords **while the page is shown**. It never changes your posts or the database content. Turn it off and every link is gone.

By [Mohsen Tavoosi](https://mohsentavoosi.com).

- General rules of automatic internal linking (any site, any platform): https://mohsentavoosi.com/en/p-en/automate-internal-links-en/
- WordPress guide for this plugin: https://mohsentavoosi.com/en/p-en/wordpress-auto-internal-links-en/
- Drafts of the pages for the author's site (Persian): [docs/site](docs/site)
- Persian documentation in this repo: [docs/DOCUMENTATION.fa.md](docs/DOCUMENTATION.fa.md)

## Features

- Up to 20 keywords per link, one target URL per record
- Whole-word, case-insensitive matching, longest keyword wins across all records
- Limits per page and per paragraph (paragraphs by `<p>`/`<br>` or by headings)
- Existing links respected: right link kept, wrong link corrected on screen, longer link text left alone
- No self links, optional restriction to chosen posts
- **Half-space (ZWNJ) switch**, on by default: `درب اتوماتیک` = `درب‌اتوماتیک`
- Post type and taxonomy selection, WooCommerce short and full description, term descriptions
- Block editor, classic editor, Elementor, WPBakery, Toolset
- JSON import and export, master on/off switch, translation ready

## Install

Copy the `mt-smart-link-automation` folder into `wp-content/plugins/` (or upload the release ZIP) and activate it. Requires WordPress 5.8+ and PHP 7.4+.

## Development

```bash
php tests/run-vectors.php          # engine tests, no WordPress needed
python3 tools/make-pot.py          # rebuild the translation template
```

The WordPress integration tests (`tests/e2e_wordpress.py`, `tests/cli_wordpress.php`) need a throw-away site. See [tests/README.md](tests/README.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
