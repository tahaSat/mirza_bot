-- Roll back one completed affiliates-to-ads migration batch.
-- Replace the value below with the batch_id printed by the migration.

SET NAMES utf8mb4;
SET @migration_batch_id = 'REPLACE-WITH-BATCH-ID';

SET @ddl = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'affiliate_ads_migration_backup'
          AND COLUMN_NAME = 'reagent_preexisting'
    ),
    'SELECT 1',
    'ALTER TABLE affiliate_ads_migration_backup ADD reagent_preexisting TINYINT(1) NOT NULL DEFAULT 1'
);
PREPARE rollback_stmt FROM @ddl;
EXECUTE rollback_stmt;
DEALLOCATE PREPARE rollback_stmt;

DROP PROCEDURE IF EXISTS rollback_affiliate_ads_migration;
DELIMITER //
CREATE PROCEDURE rollback_affiliate_ads_migration()
BEGIN
DECLARE EXIT HANDLER FOR SQLEXCEPTION
BEGIN
    DROP TEMPORARY TABLE IF EXISTS tmp_affiliate_ads_rollback;
    DROP TEMPORARY TABLE IF EXISTS tmp_affiliate_ads_referrer_rollback;
    ROLLBACK;
    RESIGNAL;
END;

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_affiliate_ads_rollback;
CREATE TEMPORARY TABLE tmp_affiliate_ads_rollback AS
SELECT *
FROM affiliate_ads_migration_backup
WHERE batch_id = @migration_batch_id;

DROP TEMPORARY TABLE IF EXISTS tmp_affiliate_ads_referrer_rollback;
CREATE TEMPORARY TABLE tmp_affiliate_ads_referrer_rollback AS
SELECT *
FROM affiliate_ads_migration_referrer_backup
WHERE batch_id = @migration_batch_id;

IF NOT EXISTS (SELECT 1 FROM tmp_affiliate_ads_rollback) THEN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Migration batch not found; rollback aborted';
END IF;

SELECT
    @migration_batch_id AS batch_id,
    (SELECT COUNT(*) FROM tmp_affiliate_ads_rollback) AS invitations_to_restore,
    (SELECT COUNT(*) FROM tmp_affiliate_ads_referrer_rollback) AS referrers_to_restore;

IF EXISTS (
    SELECT 1
    FROM user invited
    INNER JOIN tmp_affiliate_ads_rollback b ON b.invited_user_id = invited.id
    WHERE NOT (
        CAST(invited.affiliates AS CHAR) <=>
        CASE
            WHEN CAST(COALESCE(b.old_user_affiliates, '0') AS CHAR) = b.referrer_id THEN '0'
            ELSE CAST(COALESCE(b.old_user_affiliates, '0') AS CHAR)
        END
    )
) THEN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'New affiliate relationships detected; rollback aborted';
END IF;

UPDATE reagent_report r
INNER JOIN tmp_affiliate_ads_rollback b ON b.reagent_id = r.id
SET r.migrated_to_ads = b.old_migrated_to_ads,
    r.ad_advertiser_id = b.old_ad_advertiser_id,
    r.get_gift = b.get_gift,
    r.time = b.report_time,
    r.reagent = b.referrer_id
WHERE COALESCE(b.reagent_preexisting, 1) = 1;

UPDATE user invited
INNER JOIN tmp_affiliate_ads_rollback b ON b.invited_user_id = invited.id
SET invited.affiliates = COALESCE(b.old_user_affiliates, '0');

DELETE r
FROM reagent_report r
INNER JOIN tmp_affiliate_ads_rollback b ON b.reagent_id = r.id
WHERE COALESCE(b.reagent_preexisting, 1) = 0;

DELETE j
FROM ad_join j
INNER JOIN tmp_affiliate_ads_rollback b
    ON b.advertiser_id = j.advertiser_id
   AND CAST(b.invited_user_id AS CHAR) = CAST(j.user_id AS CHAR)
WHERE b.ad_join_preexisting = 0;

DELETE a
FROM ad_advertiser a
INNER JOIN tmp_affiliate_ads_referrer_rollback b ON b.advertiser_id = a.id
LEFT JOIN ad_join j ON j.advertiser_id = a.id
WHERE b.advertiser_preexisting = 0
  AND j.id IS NULL;

UPDATE ad_advertiser a
INNER JOIN tmp_affiliate_ads_referrer_rollback b ON b.advertiser_id = a.id
LEFT JOIN (
    SELECT advertiser_id, COUNT(*) AS actual_count
    FROM ad_join
    GROUP BY advertiser_id
) counts ON counts.advertiser_id = a.id
SET a.join_count = COALESCE(counts.actual_count, 0)
;

UPDATE user referrer
INNER JOIN tmp_affiliate_ads_referrer_rollback b
    ON CAST(referrer.id AS CHAR) = b.referrer_id
SET referrer.affiliatescount = COALESCE(b.old_affiliatescount, '0');

SELECT
    @migration_batch_id AS batch_id,
    (SELECT COUNT(*)
       FROM tmp_affiliate_ads_rollback b
       LEFT JOIN reagent_report r ON r.id = b.reagent_id
      WHERE (COALESCE(b.reagent_preexisting, 1) = 1
             AND r.migrated_to_ads = b.old_migrated_to_ads
             AND (r.ad_advertiser_id <=> b.old_ad_advertiser_id))
         OR (COALESCE(b.reagent_preexisting, 1) = 0 AND r.id IS NULL)) AS restored_invitations,
    (SELECT COUNT(*) FROM tmp_affiliate_ads_rollback) AS expected_invitations;

IF (
    SELECT COUNT(*)
    FROM tmp_affiliate_ads_rollback b
    LEFT JOIN reagent_report r ON r.id = b.reagent_id
    WHERE (COALESCE(b.reagent_preexisting, 1) = 1
           AND r.migrated_to_ads = b.old_migrated_to_ads
           AND (r.ad_advertiser_id <=> b.old_ad_advertiser_id))
       OR (COALESCE(b.reagent_preexisting, 1) = 0 AND r.id IS NULL)
) != (SELECT COUNT(*) FROM tmp_affiliate_ads_rollback) THEN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Rollback validation failed; transaction rolled back';
END IF;

COMMIT;
DROP TEMPORARY TABLE IF EXISTS tmp_affiliate_ads_rollback;
DROP TEMPORARY TABLE IF EXISTS tmp_affiliate_ads_referrer_rollback;
END//
DELIMITER ;

CALL rollback_affiliate_ads_migration();
DROP PROCEDURE rollback_affiliate_ads_migration;
