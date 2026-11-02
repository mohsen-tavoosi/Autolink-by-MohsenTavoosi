# Contributing

Thanks for helping. Documentation: https://mohsentavoosi.com/wordpress-auto-internal-links (general rules: https://mohsentavoosi.com/automate-internal-links)

1. Open an issue first for anything bigger than a small fix.
2. Fork, create a branch, keep one change per pull request.
3. Behaviour of the engine is defined by test vectors. A bug fix starts with a new vector in `tests/plugin-vectors.json` (or `tests/shared-vectors.json` for a rule that every port must follow), then the fix.
4. Before you push run:
   - `php tests/run-vectors.php` must print all passed
   - `phpcs --standard=phpcs.xml.dist` must be clean (WordPress Coding Standards)
5. Never write post content, term descriptions or builder data from the plugin. Links exist only in the output.
6. Every admin action needs a capability check (`manage_options`), a nonce, sanitised input and escaped output.
7. Do not mark something as tested unless you ran it. Say what you ran in the pull request.

By contributing you agree your work is licensed under GPL-2.0-or-later.
