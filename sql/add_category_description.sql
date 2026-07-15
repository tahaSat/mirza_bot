-- Add optional description to product categories.
-- When set, the Telegram bot shows this text instead of the default
-- "🛍️ لطفاً سرویسی که می‌خواهید خریداری کنید را انتخاب کنید!" message
-- after a category is selected.

ALTER TABLE category
  ADD COLUMN description TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL
  AFTER remark;
