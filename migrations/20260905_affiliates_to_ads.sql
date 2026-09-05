-- Move affiliate invitations registered through 2026-08-20 to Ads.
-- Run after deploying the matching PHP changes. MySQL 5.7+ / MariaDB 10.2+.
-- Keep the printed batch_id; it is required by the rollback script.

SET NAMES utf8mb4;
SET @migration_batch_id = UUID();
SET @migration_cutoff = '2026-08-21 00:00:00';

CREATE TABLE IF NOT EXISTS affiliate_ads_migration_backup (
    batch_id CHAR(36) NOT NULL,
    reagent_id INT UNSIGNED NOT NULL,
    invited_user_id BIGINT NOT NULL,
    referrer_id VARCHAR(30) NOT NULL,
    report_time VARCHAR(50) NOT NULL,
    get_gift TINYINT(1) NOT NULL,
    old_migrated_to_ads TINYINT(1) NOT NULL,
    old_ad_advertiser_id INT UNSIGNED NULL,
    old_user_affiliates VARCHAR(100) NULL,
    advertiser_id INT UNSIGNED NOT NULL,
    advertiser_preexisting TINYINT(1) NOT NULL,
    ad_join_preexisting TINYINT(1) NOT NULL,
    backed_up_at VARCHAR(50) NOT NULL,
    PRIMARY KEY (batch_id, reagent_id),
    KEY idx_aff_ads_backup_advertiser (batch_id, advertiser_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS affiliate_ads_migration_referrer_backup (
    batch_id CHAR(36) NOT NULL,
    referrer_id VARCHAR(30) NOT NULL,
    advertiser_id INT UNSIGNED NOT NULL,
    advertiser_preexisting TINYINT(1) NOT NULL,
    old_affiliatescount VARCHAR(100) NULL,
    old_join_count INT UNSIGNED NULL,
    PRIMARY KEY (batch_id, referrer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

DROP PROCEDURE IF EXISTS run_affiliate_ads_migration;
DELIMITER //
CREATE PROCEDURE run_affiliate_ads_migration()
BEGIN
DECLARE EXIT HANDLER FOR SQLEXCEPTION
BEGIN
    DROP TEMPORARY TABLE IF EXISTS tmp_affiliate_ads_target;
    ROLLBACK;
    RESIGNAL;
END;

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_affiliate_ads_target;
CREATE TEMPORARY TABLE tmp_affiliate_ads_target (
    reagent_id INT UNSIGNED NOT NULL PRIMARY KEY,
    invited_user_id BIGINT NOT NULL,
    referrer_id VARCHAR(30) NOT NULL,
    report_time VARCHAR(50) NOT NULL,
    get_gift TINYINT(1) NOT NULL,
    old_migrated_to_ads TINYINT(1) NOT NULL,
    old_ad_advertiser_id INT UNSIGNED NULL,
    old_user_affiliates VARCHAR(100) NULL,
    effective_time DATETIME NOT NULL,
    advertiser_id INT UNSIGNED NULL,
    advertiser_preexisting TINYINT(1) NOT NULL DEFAULT 0,
    ad_join_preexisting TINYINT(1) NOT NULL DEFAULT 0,
    KEY idx_tmp_aff_ads_referrer (referrer_id)
) ENGINE=InnoDB;

INSERT INTO tmp_affiliate_ads_target (
    reagent_id, invited_user_id, referrer_id, report_time, get_gift,
    old_migrated_to_ads, old_ad_advertiser_id, old_user_affiliates, effective_time
)
SELECT
    r.id,
    r.user_id,
    r.reagent,
    r.time,
    r.get_gift,
    COALESCE(r.migrated_to_ads, 0),
    r.ad_advertiser_id,
    invited.affiliates,
    COALESCE(
        STR_TO_DATE(REPLACE(LEFT(TRIM(r.time), 19), '-', '/'), '%Y/%m/%d %H:%i:%s'),
        STR_TO_DATE(REPLACE(LEFT(TRIM(r.time), 10), '-', '/'), '%Y/%m/%d')
    )
FROM reagent_report r
LEFT JOIN user invited ON invited.id = r.user_id
WHERE COALESCE(r.migrated_to_ads, 0) = 0
  AND IFNULL(r.reagent, '') != ''
  AND r.reagent != '0'
  AND COALESCE(
        STR_TO_DATE(REPLACE(LEFT(TRIM(r.time), 19), '-', '/'), '%Y/%m/%d %H:%i:%s'),
        STR_TO_DATE(REPLACE(LEFT(TRIM(r.time), 10), '-', '/'), '%Y/%m/%d')
      ) < @migration_cutoff;

-- Execution summary; use the separate preflight script before running this file.
SELECT
    @migration_batch_id AS batch_id,
    @migration_cutoff AS exclusive_cutoff,
    COUNT(*) AS invitations_to_move,
    COUNT(DISTINCT referrer_id) AS advertisers_to_create_or_reuse
FROM tmp_affiliate_ads_target;

SELECT COUNT(*) AS invalid_dates_not_migrated
FROM reagent_report r
LEFT JOIN user invited ON invited.id = r.user_id
WHERE COALESCE(r.migrated_to_ads, 0) = 0
  AND IFNULL(r.reagent, '') != ''
  AND r.reagent != '0'
  AND COALESCE(
        STR_TO_DATE(REPLACE(LEFT(TRIM(r.time), 19), '-', '/'), '%Y/%m/%d %H:%i:%s'),
        STR_TO_DATE(REPLACE(LEFT(TRIM(r.time), 10), '-', '/'), '%Y/%m/%d')
      ) IS NULL;

UPDATE tmp_affiliate_ads_target t
INNER JOIN ad_advertiser a ON a.source_user_id = t.referrer_id
SET t.advertiser_id = a.id,
    t.advertiser_preexisting = 1;

INSERT IGNORE INTO ad_advertiser (
    name, code, join_count, amount, started_at,
    payment_order_id, source_user_id, created_at
)
SELECT
    CASE
        WHEN NULLIF(TRIM(u.namecustom), '') IS NOT NULL AND LOWER(TRIM(u.namecustom)) != 'none'
            THEN TRIM(u.namecustom)
        WHEN NULLIF(TRIM(u.username), '') IS NOT NULL AND LOWER(TRIM(u.username)) != 'none'
            THEN CONCAT('@', TRIM(LEADING '@' FROM u.username))
        ELSE CONCAT('کاربر ', refs.referrer_id)
    END,
    CONCAT('M', SUBSTRING(SHA2(CONCAT(refs.referrer_id, @migration_batch_id), 256), 1, 15)),
    0,
    0,
    DATE_FORMAT(refs.started_at, '%Y/%m/%d'),
    NULL,
    refs.referrer_id,
    DATE_FORMAT(NOW(), '%Y/%m/%d %H:%i:%s')
FROM (
    SELECT referrer_id, MIN(effective_time) AS started_at
    FROM tmp_affiliate_ads_target
    WHERE advertiser_id IS NULL
    GROUP BY referrer_id
) refs
LEFT JOIN user u ON CAST(u.id AS CHAR) = refs.referrer_id;

UPDATE tmp_affiliate_ads_target t
INNER JOIN ad_advertiser a ON a.source_user_id = t.referrer_id
SET t.advertiser_id = a.id
WHERE t.advertiser_id IS NULL;

UPDATE tmp_affiliate_ads_target t
INNER JOIN ad_join j
    ON j.advertiser_id = t.advertiser_id
   AND CAST(j.user_id AS CHAR) = CAST(t.invited_user_id AS CHAR)
SET t.ad_join_preexisting = 1;

INSERT INTO affiliate_ads_migration_referrer_backup (
    batch_id, referrer_id, advertiser_id, advertiser_preexisting,
    old_affiliatescount, old_join_count
)
SELECT
    @migration_batch_id,
    t.referrer_id,
    t.advertiser_id,
    MAX(t.advertiser_preexisting),
    MAX(referrer.affiliatescount),
    MAX(a.join_count)
FROM tmp_affiliate_ads_target t
LEFT JOIN user referrer ON CAST(referrer.id AS CHAR) = t.referrer_id
INNER JOIN ad_advertiser a ON a.id = t.advertiser_id
GROUP BY t.referrer_id, t.advertiser_id;

INSERT INTO affiliate_ads_migration_backup (
    batch_id, reagent_id, invited_user_id, referrer_id, report_time, get_gift,
    old_migrated_to_ads, old_ad_advertiser_id, old_user_affiliates,
    advertiser_id, advertiser_preexisting, ad_join_preexisting, backed_up_at
)
SELECT
    @migration_batch_id, reagent_id, invited_user_id, referrer_id, report_time, get_gift,
    old_migrated_to_ads, old_ad_advertiser_id, old_user_affiliates,
    advertiser_id, advertiser_preexisting, ad_join_preexisting,
    DATE_FORMAT(NOW(), '%Y/%m/%d %H:%i:%s')
FROM tmp_affiliate_ads_target;

INSERT IGNORE INTO ad_join (advertiser_id, user_id, created_at)
SELECT advertiser_id, invited_user_id, DATE_FORMAT(effective_time, '%Y/%m/%d %H:%i:%s')
FROM tmp_affiliate_ads_target;

UPDATE reagent_report r
INNER JOIN tmp_affiliate_ads_target t ON t.reagent_id = r.id
SET r.migrated_to_ads = 1,
    r.ad_advertiser_id = t.advertiser_id;

UPDATE user invited
INNER JOIN tmp_affiliate_ads_target t ON t.invited_user_id = invited.id
SET invited.affiliates = '0'
WHERE CAST(invited.affiliates AS CHAR) = t.referrer_id;

UPDATE user referrer
INNER JOIN (
    SELECT affected.referrer_id, COUNT(DISTINCT active_invites.invited_user_id) AS active_count
    FROM (SELECT DISTINCT referrer_id FROM tmp_affiliate_ads_target) affected
    LEFT JOIN (
        SELECT CAST(reagent AS CHAR) AS referrer_id, CAST(user_id AS CHAR) AS invited_user_id
        FROM reagent_report
        WHERE COALESCE(migrated_to_ads, 0) = 0
          AND IFNULL(reagent, '') != ''
          AND reagent != '0'
        UNION
        SELECT CAST(affiliates AS CHAR), CAST(id AS CHAR)
        FROM user
        WHERE IFNULL(affiliates, '') != ''
          AND affiliates != '0'
    ) active_invites ON active_invites.referrer_id = affected.referrer_id
    GROUP BY affected.referrer_id
) counts ON CAST(referrer.id AS CHAR) = counts.referrer_id
SET referrer.affiliatescount = CAST(counts.active_count AS CHAR);

UPDATE ad_advertiser a
INNER JOIN (
    SELECT t.advertiser_id, COUNT(j.id) AS actual_count
    FROM (SELECT DISTINCT advertiser_id FROM tmp_affiliate_ads_target) t
    LEFT JOIN ad_join j ON j.advertiser_id = t.advertiser_id
    GROUP BY t.advertiser_id
) counts ON counts.advertiser_id = a.id
SET a.join_count = counts.actual_count;

IF EXISTS (
    SELECT 1
    FROM tmp_affiliate_ads_target t
    LEFT JOIN reagent_report r
        ON r.id = t.reagent_id
       AND r.migrated_to_ads = 1
       AND r.ad_advertiser_id = t.advertiser_id
    LEFT JOIN ad_join j
        ON j.advertiser_id = t.advertiser_id
       AND CAST(j.user_id AS CHAR) = CAST(t.invited_user_id AS CHAR)
    WHERE r.id IS NULL OR j.id IS NULL
) THEN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Affiliate-to-Ads validation failed; transaction rolled back';
END IF;

SELECT
    @migration_batch_id AS batch_id,
    (SELECT COUNT(*) FROM tmp_affiliate_ads_target) AS selected_invitations,
    (SELECT COUNT(*)
       FROM reagent_report r
       INNER JOIN tmp_affiliate_ads_target t ON t.reagent_id = r.id
      WHERE r.migrated_to_ads = 1
        AND r.ad_advertiser_id = t.advertiser_id) AS marked_invitations,
    (SELECT COUNT(*)
       FROM ad_join j
       INNER JOIN tmp_affiliate_ads_target t
          ON t.advertiser_id = j.advertiser_id
         AND CAST(t.invited_user_id AS CHAR) = CAST(j.user_id AS CHAR)) AS copied_invitations;

COMMIT;
DROP TEMPORARY TABLE IF EXISTS tmp_affiliate_ads_target;
END//
DELIMITER ;

CALL run_affiliate_ads_migration();
DROP PROCEDURE run_affiliate_ads_migration;
