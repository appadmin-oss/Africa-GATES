-- ═══════════════════════════════════════════════════════════════════════
--  Africa GATES — Live Server Fix Migration
--  Safe to run in cPanel phpMyAdmin on MySQL 5.7, MySQL 8, MariaDB 10.x
--  Run this ONCE on any database that was set up before 2026-06-06.
-- ═══════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ───────────────────────────────────────────────────────────────────────
-- STEP 1 — Create gates_admin_settings (safe: CREATE TABLE IF NOT EXISTS
--          is supported on all MySQL/MariaDB versions)
-- ───────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS gates_admin_settings (
  `setting_key`   VARCHAR(100) NOT NULL,
  `setting_value` TEXT         NOT NULL,
  `description`   VARCHAR(300) DEFAULT NULL,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed defaults — INSERT IGNORE skips rows that are already there
INSERT IGNORE INTO gates_admin_settings (setting_key, setting_value, description) VALUES
  ('donation_votes_per_1000', '5',    'Bonus votes per ₦1,000 donated'),
  ('donation_vote_enabled',   '1',    '1 = enabled, 0 = disabled'),
  ('donation_vote_min_amount','1000', 'Minimum donation (₦) to earn votes');

-- ───────────────────────────────────────────────────────────────────────
-- STEP 2 — Create gates_donations
-- ───────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS gates_donations (
  id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  donor_name    VARCHAR(200)     NOT NULL,
  donor_email   VARCHAR(191)     NOT NULL,
  donor_phone   VARCHAR(30)      DEFAULT NULL,
  donor_location VARCHAR(200)    DEFAULT NULL,
  amount_naira  INT UNSIGNED     NOT NULL,
  tier          VARCHAR(50)      DEFAULT NULL,
  bonus_votes   INT UNSIGNED     NOT NULL DEFAULT 0,
  votes_used    INT UNSIGNED     NOT NULL DEFAULT 0,
  payment_ref   VARCHAR(200)     DEFAULT NULL,
  status        ENUM('pending','confirmed','failed') NOT NULL DEFAULT 'pending',
  created_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_donation_email  (donor_email),
  KEY idx_donation_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────
-- STEP 3 — Add new columns to gates_nominations
--
--  ADD COLUMN IF NOT EXISTS requires MySQL 8.0+ / MariaDB 10.3.3+.
--  The stored procedure below uses INFORMATION_SCHEMA to check first,
--  so it works on MySQL 5.6, 5.7, 8.x and all MariaDB versions.
-- ───────────────────────────────────────────────────────────────────────
DROP PROCEDURE IF EXISTS afg_migrate_nominations;

DELIMITER //
CREATE PROCEDURE afg_migrate_nominations()
BEGIN
  DECLARE db_name VARCHAR(200) DEFAULT DATABASE();

  -- nominee_state
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = db_name AND TABLE_NAME = 'gates_nominations'
    AND COLUMN_NAME = 'nominee_state'
  ) THEN
    ALTER TABLE gates_nominations
      ADD COLUMN nominee_state VARCHAR(100) DEFAULT NULL AFTER country_code;
  END IF;

  -- nominee_lga
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = db_name AND TABLE_NAME = 'gates_nominations'
    AND COLUMN_NAME = 'nominee_lga'
  ) THEN
    ALTER TABLE gates_nominations
      ADD COLUMN nominee_lga VARCHAR(100) DEFAULT NULL AFTER nominee_state;
  END IF;

  -- reference_url (may not have existed at all)
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = db_name AND TABLE_NAME = 'gates_nominations'
    AND COLUMN_NAME = 'reference_url'
  ) THEN
    ALTER TABLE gates_nominations
      ADD COLUMN reference_url VARCHAR(400) DEFAULT NULL;
  END IF;

  -- reference_url_2
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = db_name AND TABLE_NAME = 'gates_nominations'
    AND COLUMN_NAME = 'reference_url_2'
  ) THEN
    ALTER TABLE gates_nominations
      ADD COLUMN reference_url_2 VARCHAR(400) DEFAULT NULL;
  END IF;

  -- reference_url_3
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = db_name AND TABLE_NAME = 'gates_nominations'
    AND COLUMN_NAME = 'reference_url_3'
  ) THEN
    ALTER TABLE gates_nominations
      ADD COLUMN reference_url_3 VARCHAR(400) DEFAULT NULL;
  END IF;

  -- nominator_phone
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = db_name AND TABLE_NAME = 'gates_nominations'
    AND COLUMN_NAME = 'nominator_phone'
  ) THEN
    ALTER TABLE gates_nominations
      ADD COLUMN nominator_phone VARCHAR(30) DEFAULT NULL AFTER nominator_email;
  END IF;

  -- nominator_location
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = db_name AND TABLE_NAME = 'gates_nominations'
    AND COLUMN_NAME = 'nominator_location'
  ) THEN
    ALTER TABLE gates_nominations
      ADD COLUMN nominator_location VARCHAR(200) DEFAULT NULL AFTER nominator_phone;
  END IF;

END //
DELIMITER ;

CALL afg_migrate_nominations();
DROP PROCEDURE IF EXISTS afg_migrate_nominations;

-- ───────────────────────────────────────────────────────────────────────
-- STEP 4 — Update award categories to stakeholder spec
-- ───────────────────────────────────────────────────────────────────────

-- 4a. Choral / Carol Awards (programme slug = 'carol')
DROP PROCEDURE IF EXISTS afg_fix_choral_cats;
DELIMITER //
CREATE PROCEDURE afg_fix_choral_cats()
BEGIN
  DECLARE cyc INT DEFAULT NULL;
  SELECT c.id INTO cyc
  FROM gates_award_cycles c
  JOIN gates_award_programmes p ON c.programme_id = p.id
  WHERE p.slug = 'carol'
  ORDER BY c.year DESC LIMIT 1;

  IF cyc IS NOT NULL THEN
    DELETE FROM gates_award_categories WHERE cycle_id = cyc;
    INSERT INTO gates_award_categories (cycle_id, slug, title, description, sort_order) VALUES
      (cyc, 'most-influential-choir',  'Most Influential Choir',  'Awarded to the choir whose music, ministry, and presence has had the greatest impact on audiences and communities.', 1),
      (cyc, 'best-contemporary-choir','Best Contemporary Choir', 'Celebrating a choir that excels in modern choral styles, blending tradition with fresh, current expression.', 2),
      (cyc, 'most-artistic-choir',    'Most Artistic Choir',     'Recognising outstanding creativity, originality, and artistic presentation in choral performance.', 3),
      (cyc, 'most-creative-choir',    'Most Creative Choir',     'Honouring the choir that brings the most inventive arrangements, concepts, and stage presence.', 4),
      (cyc, 'best-dressed-choir',     'Best Dressed Choir',      'Celebrating the choir whose attire, coordination, and visual presentation best complements their performance.', 5);
  END IF;
END //
DELIMITER ;
CALL afg_fix_choral_cats();
DROP PROCEDURE IF EXISTS afg_fix_choral_cats;

-- 4b. Incorruptible Child Awards (programme slug = 'incorruptible')
DROP PROCEDURE IF EXISTS afg_fix_incorruptible_cats;
DELIMITER //
CREATE PROCEDURE afg_fix_incorruptible_cats()
BEGIN
  DECLARE cyc INT DEFAULT NULL;
  SELECT c.id INTO cyc
  FROM gates_award_cycles c
  JOIN gates_award_programmes p ON c.programme_id = p.id
  WHERE p.slug = 'incorruptible'
  ORDER BY c.year DESC LIMIT 1;

  IF cyc IS NOT NULL THEN
    DELETE FROM gates_award_categories WHERE cycle_id = cyc;
    INSERT INTO gates_award_categories (cycle_id, slug, title, description, sort_order) VALUES
      (cyc, 'teachers-choice',        "Teachers' Choice Award",        'Selected by educators based on character, honesty, discipline, and leadership.', 1),
      (cyc, 'creative-change-maker',  'Creative Change-Maker Award',   'For using arts, music, writing, innovation, or creativity to promote positive values and social impact.', 2),
      (cyc, 'volunteer-service',      'Volunteer Service Award',       'For outstanding participation in community service, charity, and humanitarian activities.', 3),
      (cyc, 'young-peacemaker',       'Young Peacemaker Award',        'For promoting peace, unity, conflict resolution, and harmony among peers.', 4),
      (cyc, 'parents-pride',          "Parent's Pride Award",          'For children whose conduct and achievements reflect exceptional upbringing and family values.', 5),
      (cyc, 'most-improved-character','Most Improved Character Award', 'For remarkable growth and transformation in behavior, discipline, and personal values.', 6);
  END IF;
END //
DELIMITER ;
CALL afg_fix_incorruptible_cats();
DROP PROCEDURE IF EXISTS afg_fix_incorruptible_cats;

-- 4c. Business Excellence Awards (programme slug = 'business')
DROP PROCEDURE IF EXISTS afg_fix_business_cats;
DELIMITER //
CREATE PROCEDURE afg_fix_business_cats()
BEGIN
  DECLARE cyc INT DEFAULT NULL;
  SELECT c.id INTO cyc
  FROM gates_award_cycles c
  JOIN gates_award_programmes p ON c.programme_id = p.id
  WHERE p.slug = 'business'
  ORDER BY c.year DESC LIMIT 1;

  IF cyc IS NOT NULL THEN
    DELETE FROM gates_award_categories WHERE cycle_id = cyc;
    INSERT INTO gates_award_categories (cycle_id, slug, title, description, sort_order) VALUES
      (cyc, 'entrepreneur-of-year',   'Entrepreneur of the Year',                       'Recognizing an individual who has shown exceptional vision, leadership, and innovation in their business.', 1),
      (cyc, 'small-business-champion','Small Business Champion',                        'Highlighting a small business that has made a significant impact in its industry and community.', 2),
      (cyc, 'innovative-startup',     'Innovative Start-up',                            'Celebrating a start-up that has shown creativity, adaptability, and a pioneering spirit.', 3),
      (cyc, 'csr-advocate',           'Corporate Social Responsibility (CSR) Advocate', 'Honoring a business that has demonstrated a strong commitment to social responsibility and community development.', 4),
      (cyc, 'tech-pioneer',           'Tech Pioneer',                                   'Recognizing an individual or business at the forefront of technological innovation in the community.', 5),
      (cyc, 'sustainable-business',   'Sustainable Business Leader',                    'Celebrating a business in Alimosho that prioritizes environmental sustainability and ethical practices.', 6),
      (cyc, 'customer-service',       'Customer Service Excellence',                    'Highlighting a business that consistently goes above and beyond in serving its customers.', 7);
  END IF;
END //
DELIMITER ;
CALL afg_fix_business_cats();
DROP PROCEDURE IF EXISTS afg_fix_business_cats;

SET FOREIGN_KEY_CHECKS = 1;

-- ═══════════════════════════════════════════════════════════════════════
--  Migration complete. Verify with:
--    SHOW COLUMNS FROM gates_nominations;
--    SELECT * FROM gates_admin_settings;
--    SELECT title FROM gates_award_categories ORDER BY cycle_id, sort_order;
-- ═══════════════════════════════════════════════════════════════════════
