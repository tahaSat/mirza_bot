-- DiscountSell: category scope + multi product/panel values (JSON / legacy single).
-- Safe to run once. If code_category already exists, MySQL will error — that is OK.
-- Schema is also auto-migrated by discount_sell_ensure_schema() and table.php.

ALTER TABLE DiscountSell
  ADD COLUMN code_category TEXT NULL;

UPDATE DiscountSell SET code_category = 'all' WHERE code_category IS NULL OR code_category = '';

ALTER TABLE DiscountSell MODIFY code_product TEXT NULL;
ALTER TABLE DiscountSell MODIFY code_panel TEXT NULL;
ALTER TABLE DiscountSell MODIFY code_category TEXT NULL;
