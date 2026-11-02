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
