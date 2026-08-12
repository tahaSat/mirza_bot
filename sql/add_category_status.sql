-- Category active/inactive status for purchase flow visibility.
-- Safe to run once. If the column already exists, MySQL will error — that is OK.

ALTER TABLE category
  ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'
  AFTER remark;

UPDATE category SET status = 'active' WHERE status IS NULL OR status = '';
