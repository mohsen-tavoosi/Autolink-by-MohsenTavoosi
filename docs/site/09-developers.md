---
slug: wordpress-auto-internal-links/developers
parent: wordpress-auto-internal-links
seo_title: راهنمای توسعه دهنده افزونه لینک سازی داخلی خودکار وردپرس - Mohsen Tavoosi
h1: راهنمای توسعه دهنده افزونه لینک سازی داخلی خودکار
meta_description: فیلتر ها، گزینه ها، جدول دیتابیس و تست های افزونه Autolink by MohsenTavoosi برای توسعه دهنده ها.
status: پیش نویس. قبل از انتشار با دانش به روز محسن آپدیت شود.
---

افزونه متن باز هست و کدش در [گیت هاب](https://github.com/mohsen-tavoosi/Autolink-by-MohsenTavoosi) قرار داره. هر کسی می تونه استفاده کنه، تست بنویسه یا پیشنهاد بده. مشارکت شما خوشحالم می کنه!

## فیلتر ها

- `mtal_whole_page`. مقدار پیش فرض true هست. برگردوندن false پردازش کل صفحه رو خاموش می کنه و هر بخش محتوا جدا پردازش میشه، مثل نسخه 2.1. فقط برای عیب یابی استفاده کنید، چون سقف ها و ترتیب کلمه ها دیگه کل صفحه رو نمی بینند.

## گزینه ها در جدول options

پیشوند همه چیز `mtal_` هست. نسخه های قبل از 2.2.0 پیشوند `ail_` داشتند و با اولین اجرای نسخه جدید، جدول و گزینه ها خودکار به نام جدید منتقل میشن.

| گزینه | پیش فرض | کار |
|---|---|---|
| mtal_enabled | 1 | کلید خاموشی کلی |
| mtal_zwnj_as_space | 1 | نیم فاصله مثل فاصله |
| mtal_diversity | 1 | Smart link diversity |
| mtal_unlock_capped | 0 | باز کردن قفل بعد از سقف |
| mtal_short_inside | 0 | لینک یک باره عبارت کوتاه |
| mtal_front_page | 1 | پردازش صفحه نخست |
| mtal_exclude_posts | خالی | شناسه پست های استثنا |
| mtal_exclude_terms | خالی | شناسه ترم های استثنا |
| mtal_post_types | همه عمومی ها | نوع پست های پردازش شونده |
| mtal_taxonomies | خالی | تکسونومی های پردازش شونده |
| mtal_delete_on_uninstall | 0 | حذف داده با حذف افزونه |

## دیتابیس

رکوردها در جدول `{prefix}mtal_keywords` هستند. افزونه هیچ وقت محتوای پست ها رو تغییر نمیده.

## جستجوی عنوان در پنل

درخواست AJAX با نام `mtal_search` و nonce جدا. فقط مدیر سایت دسترسی داره.

## تست ها

پوشه tests در مخزن شامل بردارهای تست موتور (`run-vectors.php`)، تست پردازش صفحه (`hooks-unit.php`) و تست پنل (`admin-smoke.php`) هست. بردارهای مشترک در `shared-vectors.json` هستند تا هر پورت دیگه، مثل نسخه برای پلتفرم های دیگر، همون جواب ها رو بده. تست روی وردپرس واقعی هم در `LIVE-TEST-CHECKLIST` توضیح داده شده.

قوانین پشت این بردارها در [آموزش لینک سازی داخلی خودکار](https://mohsentavoosi.com/automate-internal-links) هست.

## چطوری مشارکت کنم؟

فایل CONTRIBUTING در مخزن رو بخونید. اول یک Issue بازکنید، بعد Fork و یک تغییر در هر Pull Request.
