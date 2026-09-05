-- Idempotent schema preparation. Run before deploying the PHP changes or preflight.

SET @ddl = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'reagent_report'
          AND COLUMN_NAME = 'migrated_to_ads'
    ),
    'SELECT 1',
    'ALTER TABLE reagent_report ADD migrated_to_ads TINYINT(1) NOT NULL DEFAULT 0'
);
PREPARE migration_stmt FROM @ddl;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @ddl = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'reagent_report'
          AND COLUMN_NAME = 'ad_advertiser_id'
    ),
    'SELECT 1',
    'ALTER TABLE reagent_report ADD ad_advertiser_id INT UNSIGNED NULL'
);
PREPARE migration_stmt FROM @ddl;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
