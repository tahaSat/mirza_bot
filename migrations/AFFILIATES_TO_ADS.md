# اجرای مهاجرت Affiliates به Ads

این مهاجرت فقط دعوت‌های ثبت‌شده تا پایان `2026-08-20` را منتقل می‌کند. پورسانت‌های قبلی و `invoice.refral` تغییر نمی‌کنند.

ابتدا از دیتابیس بکاپ بگیرید و ربات را موقتاً در حالت توسعه قرار دهید. سپس در مسیر پروژه:

```bash
mysql -h DB_HOST -u DB_USER -p DB_NAME < migrations/20260905_affiliates_to_ads_schema.sql
mysql -h DB_HOST -u DB_USER -p DB_NAME < migrations/20260905_affiliates_to_ads_preflight.sql
```

خروجی preflight را بررسی کنید. `recorded_invitations_to_move` تعداد دعوت‌هایی است که منتقل می‌شود. روابط `missing_report_relationships_not_migrated` زمان ثبت دعوت قابل‌اعتماد ندارند و برای جلوگیری از انتقال اشتباه خودکار جابه‌جا نمی‌شوند. رکوردهای بخش آخر نیز تاریخ معتبر ندارند و منتقل نمی‌شوند.

پس از آپلود کدهای PHP جدید، مهاجرت اصلی را اجرا کنید:

```bash
mysql -h DB_HOST -u DB_USER -p DB_NAME < migrations/20260905_affiliates_to_ads.sql
```

مقدار `batch_id` خروجی را نگه دارید. سه عدد `selected_invitations`، `marked_invitations` و `copied_invitations` باید برابر باشند. در صورت خطای اعتبارسنجی، تراکنش خودکار rollback می‌شود.

برای بازگشت، ابتدا مقدار `REPLACE-WITH-BATCH-ID` را در فایل rollback با همان `batch_id` جایگزین و سپس اجرا کنید:

```bash
mysql -h DB_HOST -u DB_USER -p DB_NAME < migrations/20260905_affiliates_to_ads_rollback.sql
```

اگر بعد از مهاجرت، affiliate یک کاربر تغییر کرده باشد، rollback برای جلوگیری از بازنویسی رابطه جدید متوقف می‌شود.
