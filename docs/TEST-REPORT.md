# گزارش تست نسخه 2.2.0 (۲۴ اکتبر ۲۰۲۶)

## اجرا شده و پاس شده
- **بردارهای موتور، ۷۸ از ۷۸.** ۴۰ بردار مشترک، ۲۶ بردار مخصوص افزونه و ۱۲ بردار تازه‌ی 2.2.0 (قفل عبارت بلند، تنوع لینک، دو تیک تازه، پردازش کل صفحه). دستور اجرا `php tests/run-vectors.php`.
- **تست واحد پردازش کل صفحه، ۱۲ از ۱۲.** با جایگزین‌های کوچک توابع وردپرس، نه وردپرس واقعی. دستور اجرا `php tests/hooks-unit.php`.
- **تست دود صفحه‌ی تنظیمات و جستجو، ۸ از ۸.** با جایگزین‌های کوچک وردپرس. دستور اجرا `php tests/admin-smoke.php`.
- بررسی نحو PHP همه‌ی فایل‌ها و نحو JS بدون خطا.

## اجرا نشده
- **روی وردپرس واقعی اجرا نشد.** در این محیط اجرای کد وردپرسِ دانلودشده مجاز نبود. تست‌های سرتاسری 2.1.0 (`e2e_wordpress.py` و `cli_wordpress.php`) برای 2.2.0 دوباره اجرا نشده‌اند.
- بافر کل صفحه با افزونه‌های کش، المنتور (به‌خصوص کش المان‌ها) و قالب‌های واقعی تست نشده.
- استاندارد کدنویسی وردپرس (phpcs) برای فایل‌های تغییرکرده دوباره اجرا نشده.

## تغییر عمدی رفتار
- بردار R07-03 به‌روز شد. با تنوع لینک، در یک رکورد کلمه‌ی شماره‌ی ۱ لینک اول را می‌گیرد و جای عبارت بلندتر قفل می‌ماند.
- بردار R21-02 که در 2.1.0 رد می‌شد، با اصلاح دوبار escape شدن `&amp;` پاس شد.

## ریسک
- اگر قالب یا افزونه‌ای خروجی را بیرون از بافر چاپ کند، بخش‌هایش لینک نمی‌گیرند ولی خراب هم نمی‌شوند. فیلتر `mtal_whole_page` با مقدار false رفتار را به حالت تکه‌به‌تکه‌ی 2.1.0 برمی‌گرداند.


## 2 Nov 2026, rename to mtal_ prefix

- Engine vectors 78/78, page processing 12/12, settings screen checks all PASS, migration unit test 7/7 (old table renamed with rows, options copied, filled new table never overwritten, fresh install changes nothing).
- phpcs with WordPress-Extra and the prefix rule `mtal`: 0 errors, 0 warnings.
- Not run: real WordPress with MySQL (the table rename was tested with a stand-in), Elementor, page cache plugins, Plugin Check plugin.


## 2 Nov 2026, real WordPress and Plugin Check

- Official Plugin Check plugin (WordPress 7.1 with SQLite): 0 errors after fixing the direct-access guard in two include files. 62 warnings remain, all for direct queries on the plugin's own table `{prefix}mtal_keywords` with prepared values. They are false positives.
- Found and fixed with a real WordPress run: after the prefix change the admin form handlers and the title search AJAX were still registered under the old `ail_` names, so the forms would not have reached them. New static test `tests/action-names.php` (25 checks) compares every form action, script action, hook and nonce. It fails on the old code (17 failures) and passes now.
- Real WordPress checks that passed: all 6 hooks registered, a record saved through admin-post with nonce (row stored, redirect `kw_saved`), a front-end page request shows the links with no leftover markers (0 `ail-s:` in the source), front page returns HTTP 200.
- Still not run: MySQL (table rename), Elementor, WooCommerce, page cache plugins.

## 2 Nov 2026, version 2.2.1
- All five automated suites pass: 78/78 engine vectors, 12/12 whole-page, 7/7 migration, admin smoke, 25/25 action-name consistency. CI green on commit 5d27e5b.
- phpcs WordPress-Extra: 0 findings.
- Official Plugin Check on the 2.2.1 zip: 0 errors, 0 warnings. The same check on 2.2.0 gave 62 database warnings, all on the plugin's own table; they are now marked with a reason in the code. A control run on the 2.2.0 files still showed the 62, so the check is sensitive. The owner also ran the WordPress.org version of the check: no errors.
- Real WordPress 7.1 with SQLite: all 6 admin hooks registered, a record saved through admin-post with its nonce, front-end output linked without leftover markers.
- Still not run: real MySQL, Elementor, WooCommerce, page-cache plugins (see LIVE-TEST-CHECKLIST.md).
