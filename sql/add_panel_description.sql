-- Optional description shown in Telegram after selecting a panel/location,
-- when the bot asks the user to pick a category.
-- Safe to run once. If the column already exists, MySQL will error — that is OK.

ALTER TABLE marzban_panel
  ADD COLUMN description TEXT NULL;
