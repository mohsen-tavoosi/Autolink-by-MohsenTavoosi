# Tests

## 1. Engine tests (no WordPress)
`php tests/run-vectors.php` runs `shared-vectors.json` (the rules every port must follow) and `plugin-vectors.json` (half-space switch, URL safety, tie rules, DOM details).

## 2. WordPress integration tests
Run them only on a throw-away site, they change plugin records.

1. A WordPress site with the plugin active, posts `post-a`, `post-b`, `post-e`, `door` (type post), `page-c` (type page) and a category with slug `door-cat` whose description contains `درب اتوماتیک`. Pretty permalinks on.
2. `python3 tests/e2e_wordpress.py http://localhost:8088 admin password` drives the real admin forms over HTTP (needs `pip install requests`).
3. `php tests/cli_wordpress.php /path/to/wordpress` tests the upgrade from the old table, hooks, uninstall.

Not covered by these tests: Elementor, WPBakery, Toolset, WooCommerce itself, page caches and CDNs, MySQL/MariaDB. Test those on a real site with `docs/LIVE-TEST-CHECKLIST.md`.

## 2.2.0
- `php tests/run-vectors.php` engine vectors (shared, plugin, feature-vectors.json).
- `php tests/hooks-unit.php` whole-page processing with WordPress stand-ins.
- `php tests/admin-smoke.php` settings screen and search endpoint with WordPress stand-ins.
