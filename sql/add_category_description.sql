-- Optional category description shown in Telegram after selecting a category.
-- Safe to run once. If the column already exists, MySQL will error — that is OK.

ALTER TABLE category
  ADD COLUMN description TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL
  AFTER remark;
