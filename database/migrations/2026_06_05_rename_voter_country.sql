-- Phase 0 (Task C3): rename the mislabeled votes column.
-- The column always stored the NOMINEE's country (never the voter's), and was
-- written-only / never read. Rename it to reflect reality.
--
-- MySQL (production):
ALTER TABLE gates_votes CHANGE voter_country nominee_country CHAR(2) NULL;

-- SQLite (local/dev, 3.25+):
--   ALTER TABLE gates_votes RENAME COLUMN voter_country TO nominee_country;
-- For the zero-config local DB it is simplest to delete var/data/africa_gates.sqlite
-- and re-run `php database/setup-sqlite.php` (demo data, safe to reset).
