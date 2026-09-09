-- One-shot: lock invoice.auto_renew default to 0 and turn it off on every existing invoice.
-- Safe to skip if table.php has already applied the same change (COLUMN_DEFAULT is then '0').

ALTER TABLE invoice MODIFY auto_renew VARCHAR(10) NULL DEFAULT '0';
UPDATE invoice SET auto_renew = '0';
