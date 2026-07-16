-- Move custom volume/time sell settings onto categories.
-- Safe to run multiple times only if you wrap each ADD in checks; otherwise run once.

ALTER TABLE category
  ADD COLUMN customvolume TEXT NULL,
  ADD COLUMN pricecustomvolume TEXT NULL,
  ADD COLUMN pricecustomtime TEXT NULL,
  ADD COLUMN mainvolume TEXT NULL,
  ADD COLUMN maxvolume TEXT NULL,
  ADD COLUMN maintime TEXT NULL,
  ADD COLUMN maxtime TEXT NULL;

UPDATE category SET customvolume = '{"f":"0","n":"0","n2":"0"}' WHERE customvolume IS NULL OR customvolume = '';
UPDATE category SET pricecustomvolume = '{"f":"4000","n":"4000","n2":"4000"}' WHERE pricecustomvolume IS NULL OR pricecustomvolume = '';
UPDATE category SET pricecustomtime = '{"f":"4000","n":"4000","n2":"4000"}' WHERE pricecustomtime IS NULL OR pricecustomtime = '';
UPDATE category SET mainvolume = '{"f":"1","n":"1","n2":"1"}' WHERE mainvolume IS NULL OR mainvolume = '';
UPDATE category SET maxvolume = '{"f":"1000","n":"1000","n2":"1000"}' WHERE maxvolume IS NULL OR maxvolume = '';
UPDATE category SET maintime = '{"f":"1","n":"1","n2":"1"}' WHERE maintime IS NULL OR maintime = '';
UPDATE category SET maxtime = '{"f":"365","n":"365","n2":"365"}' WHERE maxtime IS NULL OR maxtime = '';
