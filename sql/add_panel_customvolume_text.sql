-- Optional custom-service button label shown next to categories in the buy flow.
-- Safe to run once. If the column already exists, MySQL will error — that is OK.

ALTER TABLE marzban_panel
  ADD COLUMN customvolume_text VARCHAR(200) NULL
  AFTER customvolume;
