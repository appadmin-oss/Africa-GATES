-- MIGRATION: 2026-06-06 Stakeholder Updates
-- Apply to existing Africa GATES MySQL databases.
-- Safe to re-run (IF NOT EXISTS / IF COLUMN NOT EXISTS guards throughout).

SET NAMES utf8mb4;

-- 1. Expand nominations table with new fields
ALTER TABLE gates_nominations
  ADD COLUMN IF NOT EXISTS nominee_state VARCHAR(100) DEFAULT NULL AFTER country_code,
  ADD COLUMN IF NOT EXISTS nominee_lga VARCHAR(100) DEFAULT NULL AFTER nominee_state,
  ADD COLUMN IF NOT EXISTS reference_url VARCHAR(400) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS reference_url_2 VARCHAR(400) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS reference_url_3 VARCHAR(400) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS nominator_phone VARCHAR(30) DEFAULT NULL AFTER nominator_email,
  ADD COLUMN IF NOT EXISTS nominator_location VARCHAR(200) DEFAULT NULL AFTER nominator_phone;

-- 2. Admin settings table
CREATE TABLE IF NOT EXISTS gates_admin_settings (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NOT NULL,
  `description` VARCHAR(300) DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO gates_admin_settings(setting_key, setting_value, description) VALUES
  ('donation_votes_per_1000', '5',    'Bonus votes awarded per ₦1,000 donated (admin configurable)'),
  ('donation_vote_enabled',   '1',    'Enable donation-based bonus votes — 1=yes, 0=no'),
  ('donation_vote_min_amount','1000', 'Minimum donation amount (₦) to qualify for bonus votes');

-- 3. Donations table
CREATE TABLE IF NOT EXISTS gates_donations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  donor_name VARCHAR(200) NOT NULL,
  donor_email VARCHAR(191) NOT NULL,
  donor_phone VARCHAR(30) DEFAULT NULL,
  donor_location VARCHAR(200) DEFAULT NULL,
  amount_naira INT UNSIGNED NOT NULL,
  tier VARCHAR(50) DEFAULT NULL,
  bonus_votes INT UNSIGNED NOT NULL DEFAULT 0,
  votes_used INT UNSIGNED NOT NULL DEFAULT 0,
  payment_ref VARCHAR(200) DEFAULT NULL,
  status ENUM('pending','confirmed','failed') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_donation_email(donor_email),
  KEY idx_donation_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Update Incorruptible award categories to stakeholder spec
-- First identify cycle 2 (Incorruptible Awards)
SET @cyc2 = (SELECT id FROM gates_award_cycles WHERE programme_id = 2 ORDER BY year DESC LIMIT 1);

-- Remove old categories for cycle 2 (safe — no votes reference categories directly)
DELETE FROM gates_award_categories WHERE cycle_id = @cyc2;

-- Insert correct stakeholder-specified categories
INSERT INTO gates_award_categories(cycle_id, slug, title, description, sort_order) VALUES
(@cyc2, 'teachers-choice',         "Teachers' Choice Award",          'Selected by educators based on character, honesty, discipline, and outstanding leadership.', 1),
(@cyc2, 'creative-change-maker',   'Creative Change-Maker Award',     'For using arts, music, writing, innovation, or creativity to promote positive values and social impact.', 2),
(@cyc2, 'volunteer-service',       'Volunteer Service Award',         'For outstanding participation in community service, charity, and humanitarian activities.', 3),
(@cyc2, 'young-peacemaker',        'Young Peacemaker Award',          'For promoting peace, unity, conflict resolution, and harmony among peers.', 4),
(@cyc2, 'parents-pride',           "Parent's Pride Award",            'For children whose conduct and achievements reflect exceptional upbringing and family values.', 5),
(@cyc2, 'most-improved-character', 'Most Improved Character Award',   'For remarkable growth and transformation in behavior, discipline, and personal values.', 6),
(@cyc2, 'incorruptible-child-of-year', 'Incorruptible Child of the Year', 'Grand Prize — exceptional honesty, integrity, courage, responsibility, and positive influence among peers.', 7);
