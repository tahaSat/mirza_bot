-- Read-only preview for the affiliates-to-ads migration.
-- Run 20260905_affiliates_to_ads_schema.sql first, then review all result sets.

SET @migration_cutoff = '2026-08-21 00:00:00';

SELECT
    @migration_cutoff AS exclusive_cutoff,
    COUNT(*) AS recorded_invitations_to_move,
    COUNT(DISTINCT r.reagent) AS advertisers_to_create_or_reuse
FROM reagent_report r
LEFT JOIN user invited ON invited.id = r.user_id
WHERE COALESCE(r.migrated_to_ads, 0) = 0
  AND IFNULL(r.reagent, '') != ''
  AND r.reagent != '0'
  AND COALESCE(
        STR_TO_DATE(REPLACE(LEFT(TRIM(r.time), 19), '-', '/'), '%Y/%m/%d %H:%i:%s'),
        STR_TO_DATE(REPLACE(LEFT(TRIM(r.time), 10), '-', '/'), '%Y/%m/%d')
      ) < @migration_cutoff;

SELECT
    r.reagent AS referrer_id,
    COALESCE(NULLIF(u.namecustom, ''), NULLIF(u.username, ''), CONCAT('کاربر ', r.reagent)) AS advertiser_name,
    COUNT(*) AS invitations_to_move
FROM reagent_report r
LEFT JOIN user invited ON invited.id = r.user_id
LEFT JOIN user u ON CAST(u.id AS CHAR) = CAST(r.reagent AS CHAR)
WHERE COALESCE(r.migrated_to_ads, 0) = 0
  AND IFNULL(r.reagent, '') != ''
  AND r.reagent != '0'
  AND COALESCE(
        STR_TO_DATE(REPLACE(LEFT(TRIM(r.time), 19), '-', '/'), '%Y/%m/%d %H:%i:%s'),
        STR_TO_DATE(REPLACE(LEFT(TRIM(r.time), 10), '-', '/'), '%Y/%m/%d')
      ) < @migration_cutoff
GROUP BY r.reagent, advertiser_name
ORDER BY invitations_to_move DESC;

SELECT
    COUNT(*) AS missing_report_relationships_not_migrated,
    COUNT(DISTINCT invited.affiliates) AS affected_referrers
FROM user invited
WHERE IFNULL(invited.affiliates, '') != ''
  AND invited.affiliates != '0'
  AND TRIM(invited.register) REGEXP '^[0-9]{10}([0-9]{3})?$'
  AND CASE
        WHEN LENGTH(TRIM(invited.register)) = 10
            THEN FROM_UNIXTIME(CAST(invited.register AS UNSIGNED))
        ELSE FROM_UNIXTIME(FLOOR(CAST(invited.register AS UNSIGNED) / 1000))
      END < @migration_cutoff
  AND NOT EXISTS (
      SELECT 1 FROM reagent_report r WHERE r.user_id = invited.id
  );

SELECT r.id, r.user_id, r.reagent, r.time, invited.register
FROM reagent_report r
LEFT JOIN user invited ON invited.id = r.user_id
WHERE COALESCE(r.migrated_to_ads, 0) = 0
  AND IFNULL(r.reagent, '') != ''
  AND r.reagent != '0'
  AND COALESCE(
        STR_TO_DATE(REPLACE(LEFT(TRIM(r.time), 19), '-', '/'), '%Y/%m/%d %H:%i:%s'),
        STR_TO_DATE(REPLACE(LEFT(TRIM(r.time), 10), '-', '/'), '%Y/%m/%d')
      ) IS NULL
ORDER BY r.id;
