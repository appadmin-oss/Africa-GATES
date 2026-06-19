-- ═══════════════════════════════════════════════════════════════════════════
--  ⚠ DEPRECATED — DO NOT USE FOR NEW INSTALLS ⚠
--  This single-file installer has drifted from the application code (several
--  table shapes here — gates_cache, gates_cpi_history, gates_profiles,
--  gates_nominees — no longer match what the code reads/writes, and it lacks
--  the 2026 integrity tables). The CANONICAL installer is:
--        php bin/console db:migrate            (builds schema from the
--        php bin/console db:migrate --seed-admin   schema.sql/admin/community trio)
--  followed by the idempotent catch-up scripts in database/migrations/.
--  Kept only for reference; reconcile from the trio before ever running it.
-- ═══════════════════════════════════════════════════════════════════════════
--  AFRICA GATES — COMPLETE INSTALL (legacy)
--  Single file. Run this once in phpMyAdmin (or mysql CLI) on a fresh or
--  existing database. Every statement is idempotent (CREATE TABLE IF NOT
--  EXISTS, INSERT IGNORE) so it is safe to re-run.
--
--  Tested on: MySQL 5.7 · MySQL 8.x · MariaDB 10.3+
--
--  After importing, create your first admin (NO default password ships):
--    php bin/console admin:create admin@afrovanguard.org.ng "Afrovanguard Admin" 'YOUR-STRONG-PASSWORD' --role=superadmin
--  Then log in at: https://yourdomain.com/admin/login
-- ═══════════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET time_zone = '+00:00';

-- ───────────────────────────────────────────────────────────────────────────
-- PART 1 — CORE TABLES
-- ───────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS gates_profiles (
  id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(191)     NOT NULL,
  display_name  VARCHAR(200)     NOT NULL,
  tagline       VARCHAR(300)     DEFAULT NULL,
  bio           TEXT,
  country_code  CHAR(2)          DEFAULT NULL,
  state         VARCHAR(100)     DEFAULT NULL,
  city          VARCHAR(100)     DEFAULT NULL,
  email         VARCHAR(191)     DEFAULT NULL,
  phone         VARCHAR(30)      DEFAULT NULL,
  website       VARCHAR(400)     DEFAULT NULL,
  twitter       VARCHAR(100)     DEFAULT NULL,
  instagram     VARCHAR(100)     DEFAULT NULL,
  linkedin      VARCHAR(200)     DEFAULT NULL,
  avatar_path   VARCHAR(400)     DEFAULT NULL,
  cover_path    VARCHAR(400)     DEFAULT NULL,
  category      VARCHAR(100)     DEFAULT NULL,
  tags          TEXT,
  cpi_score     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  cpi_tier      ENUM('bronze','silver','gold','platinum','diamond') NOT NULL DEFAULT 'bronze',
  vote_count    INT UNSIGNED     NOT NULL DEFAULT 0,
  status        ENUM('pending','active','suspended') NOT NULL DEFAULT 'pending',
  verified_at   TIMESTAMP        NULL DEFAULT NULL,
  created_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_profile_slug (slug),
  KEY idx_profile_country (country_code),
  KEY idx_profile_cpi     (cpi_score),
  KEY idx_profile_status  (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_award_programmes (
  id          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(60)      NOT NULL,
  title       VARCHAR(150)     NOT NULL,
  description TEXT,
  icon_emoji  VARCHAR(10)      DEFAULT NULL,
  cover_path  VARCHAR(400)     DEFAULT NULL,
  is_active   TINYINT(1)       NOT NULL DEFAULT 1,
  sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prog_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_award_cycles (
  id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  programme_id TINYINT UNSIGNED NOT NULL,
  year         SMALLINT UNSIGNED NOT NULL,
  status       ENUM('draft','nominations','verification','voting','judging','closed') NOT NULL DEFAULT 'draft',
  opens_at     TIMESTAMP        NULL DEFAULT NULL,
  voting_opens TIMESTAMP        NULL DEFAULT NULL,
  voting_close TIMESTAMP        NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cycle_prog_year (programme_id, year),
  KEY idx_cycle_status (status),
  CONSTRAINT fk_cycle_prog FOREIGN KEY (programme_id) REFERENCES gates_award_programmes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_award_categories (
  id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  cycle_id    BIGINT UNSIGNED  NOT NULL,
  slug        VARCHAR(100)     NOT NULL,
  title       VARCHAR(200)     NOT NULL,
  description TEXT,
  sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cat_cycle_slug (cycle_id, slug),
  KEY idx_cat_cycle (cycle_id),
  CONSTRAINT fk_cat_cycle FOREIGN KEY (cycle_id) REFERENCES gates_award_cycles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_nominees (
  id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  cycle_id     BIGINT UNSIGNED  NOT NULL,
  category_id  BIGINT UNSIGNED  DEFAULT NULL,
  profile_id   BIGINT UNSIGNED  DEFAULT NULL,
  name         VARCHAR(200)     NOT NULL,
  tagline      VARCHAR(300)     DEFAULT NULL,
  bio          TEXT,
  country_code CHAR(2)          DEFAULT NULL,
  avatar_path  VARCHAR(400)     DEFAULT NULL,
  vote_count   INT UNSIGNED     NOT NULL DEFAULT 0,
  judge_score  DECIMAL(6,3)     DEFAULT NULL,
  cpi_score    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status       ENUM('pending','approved','rejected','withdrawn') NOT NULL DEFAULT 'pending',
  approved_at  TIMESTAMP        NULL DEFAULT NULL,
  created_at   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_nom_cycle    (cycle_id),
  KEY idx_nom_category (category_id),
  KEY idx_nom_status   (status),
  CONSTRAINT fk_nom_cycle    FOREIGN KEY (cycle_id)    REFERENCES gates_award_cycles(id)      ON DELETE CASCADE,
  CONSTRAINT fk_nom_category FOREIGN KEY (category_id) REFERENCES gates_award_categories(id)  ON DELETE SET NULL,
  CONSTRAINT fk_nom_profile  FOREIGN KEY (profile_id)  REFERENCES gates_profiles(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_votes (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nominee_id        BIGINT UNSIGNED NOT NULL,
  category_id       BIGINT UNSIGNED NOT NULL,
  voter_email_hash  VARCHAR(64)     NOT NULL,
  otp_token_id      BIGINT UNSIGNED DEFAULT NULL,
  nominee_country   CHAR(2)         DEFAULT NULL,
  voter_country     CHAR(2)         DEFAULT NULL,
  ip_hash           VARCHAR(64)     DEFAULT NULL,
  device_hash       VARCHAR(64)     DEFAULT NULL,
  risk_score        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  fraud_flag        TINYINT(1)      NOT NULL DEFAULT 0,
  voted_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vote (voter_email_hash, category_id),
  KEY idx_vote_nominee   (nominee_id),
  KEY idx_vote_category  (category_id),
  KEY idx_vote_voted_at  (voted_at),
  KEY idx_vote_fraud     (fraud_flag),
  CONSTRAINT fk_vote_nominee  FOREIGN KEY (nominee_id)   REFERENCES gates_nominees(id)         ON DELETE CASCADE,
  CONSTRAINT fk_vote_category FOREIGN KEY (category_id)  REFERENCES gates_award_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_otp_tokens (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email_hash  VARCHAR(64)     NOT NULL,
  token_hash  VARCHAR(64)     NOT NULL,
  purpose     VARCHAR(30)     NOT NULL DEFAULT 'vote',
  nominee_id  BIGINT UNSIGNED DEFAULT NULL,
  award_id    TINYINT UNSIGNED DEFAULT NULL,
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  is_used     TINYINT(1)      NOT NULL DEFAULT 0,
  expires_at  DATETIME        NOT NULL,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_otp_email   (email_hash),
  KEY idx_otp_purpose (purpose),
  KEY idx_otp_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_nominations (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cycle_id           BIGINT UNSIGNED NOT NULL,
  category_id        BIGINT UNSIGNED DEFAULT NULL,
  nominee_name       VARCHAR(200)    NOT NULL,
  nominee_email      VARCHAR(191)    DEFAULT NULL,
  country_code       CHAR(2)         DEFAULT NULL,
  nominee_state      VARCHAR(100)    DEFAULT NULL,
  nominee_lga        VARCHAR(100)    DEFAULT NULL,
  reason             TEXT,
  reference_url      VARCHAR(400)    DEFAULT NULL,
  reference_url_2    VARCHAR(400)    DEFAULT NULL,
  reference_url_3    VARCHAR(400)    DEFAULT NULL,
  nominator_name     VARCHAR(200)    NOT NULL,
  nominator_email    VARCHAR(191)    NOT NULL,
  nominator_phone    VARCHAR(30)     DEFAULT NULL,
  nominator_location VARCHAR(200)    DEFAULT NULL,
  status             ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  ip_hash            VARCHAR(64)     DEFAULT NULL,
  created_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_nom_cycle  (cycle_id),
  KEY idx_nom_status (status),
  CONSTRAINT fk_nomination_cycle FOREIGN KEY (cycle_id) REFERENCES gates_award_cycles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_legacy_events (
  id             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  slug           VARCHAR(191)     NOT NULL,
  title          VARCHAR(250)     NOT NULL,
  tagline        VARCHAR(300)     DEFAULT NULL,
  description    TEXT,
  event_date     DATE             NOT NULL,
  location       VARCHAR(250)     DEFAULT NULL,
  cover_path     VARCHAR(400)     DEFAULT NULL,
  attendee_count INT UNSIGNED     DEFAULT NULL,
  award_count    SMALLINT UNSIGNED DEFAULT NULL,
  icon           VARCHAR(10)      DEFAULT NULL,
  is_published   TINYINT(1)       NOT NULL DEFAULT 1,
  created_at     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_legacy_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_opportunities (
  id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  slug         VARCHAR(191)     NOT NULL,
  title        VARCHAR(250)     NOT NULL,
  organisation VARCHAR(200)     DEFAULT NULL,
  country_code CHAR(2)          DEFAULT NULL,
  type         ENUM('grant','fellowship','accelerator','scholarship','program','award','other') NOT NULL DEFAULT 'other',
  deadline     DATE             DEFAULT NULL,
  amount       VARCHAR(100)     DEFAULT NULL,
  summary      TEXT,
  apply_url    VARCHAR(500)     DEFAULT NULL,
  tags         VARCHAR(500)     DEFAULT NULL,
  is_featured  TINYINT(1)       NOT NULL DEFAULT 0,
  is_published TINYINT(1)       NOT NULL DEFAULT 1,
  created_at   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_opp_slug (slug),
  KEY idx_opp_deadline (deadline),
  KEY idx_opp_type     (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_rate_limits (
  fingerprint VARCHAR(128)    NOT NULL,
  action      VARCHAR(60)     NOT NULL,
  hit_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  window_start TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (fingerprint, action),
  KEY idx_rl_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_cache (
  cache_key  VARCHAR(191)    NOT NULL,
  value      MEDIUMTEXT,
  expires_at INT UNSIGNED    NOT NULL,
  PRIMARY KEY (cache_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_cpi_history (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nominee_id BIGINT UNSIGNED NOT NULL,
  cpi_score  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  vote_count INT UNSIGNED     NOT NULL DEFAULT 0,
  calculated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cpi_nominee (nominee_id),
  KEY idx_cpi_date    (calculated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_partner_enquiries (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_name         VARCHAR(200)    NOT NULL,
  contact_name     VARCHAR(200)    NOT NULL,
  contact_email    VARCHAR(191)    NOT NULL,
  contact_phone    VARCHAR(30)     DEFAULT NULL,
  partnership_type VARCHAR(50)     DEFAULT NULL,
  message          TEXT,
  status           ENUM('new','reviewing','closed','rejected') NOT NULL DEFAULT 'new',
  created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_partner_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_cron_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_name   VARCHAR(60)     NOT NULL,
  status     ENUM('success','error','skipped') NOT NULL DEFAULT 'success',
  message    VARCHAR(500)    DEFAULT NULL,
  runtime_ms INT UNSIGNED    DEFAULT NULL,
  ran_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cron_job (job_name),
  KEY idx_cron_ran (ran_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_newsletter (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email_hash      VARCHAR(64)     NOT NULL,
  source          VARCHAR(60)     DEFAULT NULL,
  subscribed_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unsubscribed_at TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_newsletter_email_hash (email_hash),
  KEY idx_newsletter_subscribed (subscribed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_admin_settings (
  setting_key   VARCHAR(100) NOT NULL,
  setting_value TEXT         NOT NULL,
  description   VARCHAR(300) DEFAULT NULL,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_donations (
  id             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  donor_name     VARCHAR(200)     NOT NULL,
  donor_email    VARCHAR(191)     NOT NULL,
  donor_phone    VARCHAR(30)      DEFAULT NULL,
  donor_location VARCHAR(200)     DEFAULT NULL,
  amount_naira   INT UNSIGNED     NOT NULL,
  tier           VARCHAR(50)      DEFAULT NULL,
  bonus_votes    INT UNSIGNED     NOT NULL DEFAULT 0,
  votes_used     INT UNSIGNED     NOT NULL DEFAULT 0,
  payment_ref    VARCHAR(200)     DEFAULT NULL,
  status         ENUM('pending','confirmed','failed') NOT NULL DEFAULT 'pending',
  created_at     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_donation_email  (donor_email),
  KEY idx_donation_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- PART 2 — ADMIN / AUTH / JUDGE TABLES
-- ───────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS gates_admins (
  id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  email           VARCHAR(191)     NOT NULL,
  password_hash   VARCHAR(255)     DEFAULT NULL,
  name            VARCHAR(200)     NOT NULL,
  role            ENUM('superadmin','admin','editor','judge','viewer') NOT NULL DEFAULT 'editor',
  avatar_path     VARCHAR(400)     DEFAULT NULL,
  is_active       TINYINT(1)       NOT NULL DEFAULT 1,
  last_login_at   TIMESTAMP        NULL DEFAULT NULL,
  last_login_ip   VARCHAR(64)      DEFAULT NULL,
  failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until    DATETIME         DEFAULT NULL,
  created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_email (email),
  KEY idx_admins_role   (role),
  KEY idx_admins_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_magic_links (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email       VARCHAR(191)    NOT NULL,
  token_hash  VARCHAR(128)    NOT NULL,
  purpose     ENUM('admin_login','password_reset') NOT NULL DEFAULT 'admin_login',
  expires_at  DATETIME        NOT NULL,
  used_at     TIMESTAMP       NULL DEFAULT NULL,
  ip_hash     VARCHAR(64)     DEFAULT NULL,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_magic_token (token_hash),
  KEY idx_magic_email (email),
  KEY idx_magic_exp   (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id    BIGINT UNSIGNED DEFAULT NULL,
  action      VARCHAR(100)    NOT NULL,
  target_type VARCHAR(50)     DEFAULT NULL,
  target_id   BIGINT UNSIGNED DEFAULT NULL,
  meta        TEXT,
  ip_hash     VARCHAR(64)     DEFAULT NULL,
  ua          VARCHAR(250)    DEFAULT NULL,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_admin   (admin_id),
  KEY idx_audit_action  (action),
  KEY idx_audit_created (created_at),
  CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES gates_admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_judges (
  id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  admin_id      BIGINT UNSIGNED  DEFAULT NULL,
  name          VARCHAR(200)     NOT NULL,
  email         VARCHAR(191)     NOT NULL,
  title         VARCHAR(200)     DEFAULT NULL,
  organisation  VARCHAR(200)     DEFAULT NULL,
  bio           TEXT,
  avatar_path   VARCHAR(400)     DEFAULT NULL,
  country_code  CHAR(2)          DEFAULT NULL,
  programme_ids VARCHAR(500)     DEFAULT NULL,
  is_active     TINYINT(1)       NOT NULL DEFAULT 1,
  created_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_judge_email (email),
  KEY idx_judges_active (is_active),
  CONSTRAINT fk_judge_admin FOREIGN KEY (admin_id) REFERENCES gates_admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_judge_scores (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  judge_id    BIGINT UNSIGNED NOT NULL,
  nominee_id  BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  score       TINYINT         NOT NULL,
  notes       TEXT,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_jscore (judge_id, nominee_id),
  KEY idx_jscore_judge   (judge_id),
  KEY idx_jscore_nominee (nominee_id),
  CONSTRAINT fk_jscore_judge    FOREIGN KEY (judge_id)    REFERENCES gates_judges(id)           ON DELETE CASCADE,
  CONSTRAINT fk_jscore_nominee  FOREIGN KEY (nominee_id)  REFERENCES gates_nominees(id)         ON DELETE CASCADE,
  CONSTRAINT fk_jscore_category FOREIGN KEY (category_id) REFERENCES gates_award_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_settings (
  key_name   VARCHAR(100) NOT NULL,
  value      TEXT,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (key_name),
  CONSTRAINT fk_setting_admin FOREIGN KEY (updated_by) REFERENCES gates_admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_uploads (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uploader_id      BIGINT UNSIGNED DEFAULT NULL,
  uploader_type    ENUM('admin','public','system') NOT NULL DEFAULT 'admin',
  path             VARCHAR(500)    NOT NULL,
  mime             VARCHAR(60)     DEFAULT NULL,
  size_bytes       INT UNSIGNED    DEFAULT NULL,
  width            INT UNSIGNED    DEFAULT NULL,
  height           INT UNSIGNED    DEFAULT NULL,
  alt              VARCHAR(250)    DEFAULT NULL,
  attached_to_type VARCHAR(50)     DEFAULT NULL,
  attached_to_id   BIGINT UNSIGNED DEFAULT NULL,
  created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_uploads_attached (attached_to_type, attached_to_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- PART 3 — COMMUNITY + JUDGING RUBRIC TABLES
-- ───────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS gates_judge_criteria (
  id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  programme_id TINYINT UNSIGNED DEFAULT NULL,
  slug        VARCHAR(60)      NOT NULL,
  label       VARCHAR(120)     NOT NULL,
  description TEXT,
  weight      TINYINT UNSIGNED NOT NULL DEFAULT 25,
  sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  is_active   TINYINT(1)       NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_crit_prog (programme_id),
  CONSTRAINT fk_crit_prog FOREIGN KEY (programme_id) REFERENCES gates_award_programmes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_judge_criteria_scores (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  judge_id     BIGINT UNSIGNED NOT NULL,
  nominee_id   BIGINT UNSIGNED NOT NULL,
  category_id  BIGINT UNSIGNED NOT NULL,
  criterion_id BIGINT UNSIGNED NOT NULL,
  score        TINYINT         NOT NULL,
  created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_jcrit (judge_id, nominee_id, criterion_id),
  KEY idx_jcrit_nominee (nominee_id),
  KEY idx_jcrit_judge   (judge_id),
  CONSTRAINT fk_jcrit_judge    FOREIGN KEY (judge_id)     REFERENCES gates_judges(id)           ON DELETE CASCADE,
  CONSTRAINT fk_jcrit_nominee  FOREIGN KEY (nominee_id)   REFERENCES gates_nominees(id)         ON DELETE CASCADE,
  CONSTRAINT fk_jcrit_category FOREIGN KEY (category_id)  REFERENCES gates_award_categories(id) ON DELETE CASCADE,
  CONSTRAINT fk_jcrit_crit     FOREIGN KEY (criterion_id) REFERENCES gates_judge_criteria(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_judge_notes (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  judge_id     BIGINT UNSIGNED NOT NULL,
  nominee_id   BIGINT UNSIGNED NOT NULL,
  notes        TEXT,
  submitted_at TIMESTAMP       NULL DEFAULT NULL,
  updated_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_jnote (judge_id, nominee_id),
  CONSTRAINT fk_jnote_judge   FOREIGN KEY (judge_id)   REFERENCES gates_judges(id)   ON DELETE CASCADE,
  CONSTRAINT fk_jnote_nominee FOREIGN KEY (nominee_id) REFERENCES gates_nominees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_comments (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_type       ENUM('profile','legacy','thread','nominee') NOT NULL,
  target_id         BIGINT UNSIGNED NOT NULL,
  parent_id         BIGINT UNSIGNED DEFAULT NULL,
  author_name       VARCHAR(200)    NOT NULL,
  author_email      VARCHAR(191)    DEFAULT NULL,
  author_email_hash VARCHAR(64)     DEFAULT NULL,
  body              TEXT            NOT NULL,
  status            ENUM('approved','quarantined','rejected','deleted') NOT NULL DEFAULT 'approved',
  ai_score          DECIMAL(4,3)    DEFAULT NULL,
  ai_reason         VARCHAR(500)    DEFAULT NULL,
  ip_hash           VARCHAR(64)     DEFAULT NULL,
  created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_comments_target  (target_type, target_id),
  KEY idx_comments_status  (status),
  KEY idx_comments_created (created_at),
  CONSTRAINT fk_comment_parent FOREIGN KEY (parent_id) REFERENCES gates_comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_cheers (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_type ENUM('profile','nominee','comment','thread') NOT NULL,
  target_id   BIGINT UNSIGNED NOT NULL,
  fp          VARCHAR(64)     NOT NULL,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cheer (target_type, target_id, fp),
  KEY idx_cheers_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_activity (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind         ENUM('vote','nomination','register','comment','cheer','winner','legacy','opportunity') NOT NULL,
  actor_label  VARCHAR(200)    DEFAULT NULL,
  target_type  VARCHAR(50)     DEFAULT NULL,
  target_id    BIGINT UNSIGNED DEFAULT NULL,
  target_label VARCHAR(250)    DEFAULT NULL,
  meta         TEXT,
  is_public    TINYINT(1)      NOT NULL DEFAULT 1,
  created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_activity_created (created_at),
  KEY idx_activity_kind    (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_threads (
  id                 BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  programme_id       TINYINT UNSIGNED DEFAULT NULL,
  slug               VARCHAR(191)     NOT NULL,
  title              VARCHAR(250)     NOT NULL,
  body               TEXT,
  author_name        VARCHAR(200)     NOT NULL,
  author_email_hash  VARCHAR(64)      NOT NULL,
  status             ENUM('approved','quarantined','rejected','deleted','locked') NOT NULL DEFAULT 'approved',
  ai_score           DECIMAL(4,3)     DEFAULT NULL,
  reply_count        INT UNSIGNED     NOT NULL DEFAULT 0,
  cheer_count        INT UNSIGNED     NOT NULL DEFAULT 0,
  last_activity      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_pinned          TINYINT(1)       NOT NULL DEFAULT 0,
  created_at         TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_thread_slug (slug),
  KEY idx_threads_programme (programme_id),
  KEY idx_threads_activity  (last_activity),
  KEY idx_threads_status    (status),
  CONSTRAINT fk_thread_prog FOREIGN KEY (programme_id) REFERENCES gates_award_programmes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_moderation_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_type VARCHAR(50)     NOT NULL,
  target_id   BIGINT UNSIGNED NOT NULL,
  provider    VARCHAR(30)     NOT NULL DEFAULT 'heuristic',
  decision    ENUM('allow','quarantine','reject') NOT NULL,
  score       DECIMAL(4,3)    DEFAULT NULL,
  reason      VARCHAR(500)    DEFAULT NULL,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_modlog_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- PART 4 — MIGRATION: new nomination columns (MySQL 5.7 compatible)
-- Stored-procedure approach works on MySQL 5.6/5.7/8 and all MariaDB versions.
-- ───────────────────────────────────────────────────────────────────────────

DROP PROCEDURE IF EXISTS afg_migrate;
DELIMITER //
CREATE PROCEDURE afg_migrate()
BEGIN
  DECLARE db VARCHAR(200) DEFAULT DATABASE();

  IF NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=db AND TABLE_NAME='gates_nominations' AND COLUMN_NAME='nominee_state') THEN
    ALTER TABLE gates_nominations ADD COLUMN nominee_state VARCHAR(100) DEFAULT NULL AFTER country_code;
  END IF;
  IF NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=db AND TABLE_NAME='gates_nominations' AND COLUMN_NAME='nominee_lga') THEN
    ALTER TABLE gates_nominations ADD COLUMN nominee_lga VARCHAR(100) DEFAULT NULL AFTER nominee_state;
  END IF;
  IF NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=db AND TABLE_NAME='gates_nominations' AND COLUMN_NAME='reference_url') THEN
    ALTER TABLE gates_nominations ADD COLUMN reference_url VARCHAR(400) DEFAULT NULL;
  END IF;
  IF NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=db AND TABLE_NAME='gates_nominations' AND COLUMN_NAME='reference_url_2') THEN
    ALTER TABLE gates_nominations ADD COLUMN reference_url_2 VARCHAR(400) DEFAULT NULL;
  END IF;
  IF NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=db AND TABLE_NAME='gates_nominations' AND COLUMN_NAME='reference_url_3') THEN
    ALTER TABLE gates_nominations ADD COLUMN reference_url_3 VARCHAR(400) DEFAULT NULL;
  END IF;
  IF NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=db AND TABLE_NAME='gates_nominations' AND COLUMN_NAME='nominator_phone') THEN
    ALTER TABLE gates_nominations ADD COLUMN nominator_phone VARCHAR(30) DEFAULT NULL AFTER nominator_email;
  END IF;
  IF NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=db AND TABLE_NAME='gates_nominations' AND COLUMN_NAME='nominator_location') THEN
    ALTER TABLE gates_nominations ADD COLUMN nominator_location VARCHAR(200) DEFAULT NULL AFTER nominator_phone;
  END IF;
  -- Add device_hash and risk columns to votes table
  IF NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=db AND TABLE_NAME='gates_votes' AND COLUMN_NAME='device_hash') THEN
    ALTER TABLE gates_votes ADD COLUMN device_hash VARCHAR(64) DEFAULT NULL;
  END IF;
  IF NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=db AND TABLE_NAME='gates_votes' AND COLUMN_NAME='risk_score') THEN
    ALTER TABLE gates_votes ADD COLUMN risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0;
  END IF;
  IF NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=db AND TABLE_NAME='gates_votes' AND COLUMN_NAME='fraud_flag') THEN
    ALTER TABLE gates_votes ADD COLUMN fraud_flag TINYINT(1) NOT NULL DEFAULT 0;
  END IF;
END //
DELIMITER ;
CALL afg_migrate();
DROP PROCEDURE IF EXISTS afg_migrate;

-- ───────────────────────────────────────────────────────────────────────────
-- PART 5 — SEED DATA
-- ───────────────────────────────────────────────────────────────────────────

-- Award Programmes
INSERT IGNORE INTO gates_award_programmes (id,slug,title,description,icon_emoji,is_active,sort_order) VALUES
(1,'principals',    'Principals Awards',         'Recognising exceptional school principals across Alimosho and beyond.',              '🎓', 1, 1),
(2,'incorruptible', 'Incorruptible Child Awards','Celebrating integrity, honesty and values-driven young excellence.',                '⭐', 1, 2),
(3,'carol',         'Choral (Carol) Awards',     'Choral excellence and spiritual expression across African communities.',             '🎶', 1, 3),
(4,'business',      'Business Excellence Awards','Recognising outstanding businesses in Alimosho — min. 5 staff, physical office.',    '🏢', 1, 4);

-- Award Cycles (2026)
INSERT IGNORE INTO gates_award_cycles (id,programme_id,year,status) VALUES
(1,1,2026,'nominations'),
(2,2,2026,'nominations'),
(3,3,2026,'nominations'),
(4,4,2026,'nominations');

-- Categories — Principals
INSERT IGNORE INTO gates_award_categories (cycle_id,slug,title,description,sort_order) VALUES
(1,'academic-excellence',  'Academic Excellence',      'Recognising principals who have shown outstanding dedication to improving academic standards and performance.',2, 1),
(1,'community-engagement', 'Community Engagement',     'Honoring principals who have made significant contributions to the community by actively engaging local residents.',2, 2),
(1,'innovation-education', 'Innovation in Education',  'Celebrating those who have pioneered innovative teaching methods and educational practices.',2, 3),
(1,'leadership-mentorship','Leadership and Mentorship','Acknowledging principals who displayed exceptional leadership skills and acted as mentors for students and staff.',2, 4),
(1,'social-development',   'Social Development and Impact','Highlighting the impact of principals on the social and overall development of the Alimosho community.',2, 5);

-- Categories — Incorruptible Child
INSERT IGNORE INTO gates_award_categories (cycle_id,slug,title,description,sort_order) VALUES
(2,'teachers-choice',        "Teachers' Choice Award",       'Selected by educators based on character, honesty, discipline, and leadership.',1),
(2,'creative-change-maker',  'Creative Change-Maker Award',  'For using arts, music, writing, innovation, or creativity to promote positive values and social impact.',2),
(2,'volunteer-service',      'Volunteer Service Award',      'For outstanding participation in community service, charity, and humanitarian activities.',3),
(2,'young-peacemaker',       'Young Peacemaker Award',       'For promoting peace, unity, conflict resolution, and harmony among peers.',4),
(2,'parents-pride',          "Parent's Pride Award",         'For children whose conduct and achievements reflect exceptional upbringing and family values.',5),
(2,'most-improved-character','Most Improved Character Award','For remarkable growth and transformation in behavior, discipline, and personal values.',6);

-- Categories — Choral
INSERT IGNORE INTO gates_award_categories (cycle_id,slug,title,description,sort_order) VALUES
(3,'most-influential-choir', 'Most Influential Choir', 'Awarded to the choir whose music, ministry, and presence has had the greatest impact on audiences and communities.',1),
(3,'best-contemporary-choir','Best Contemporary Choir','Celebrating a choir that excels in modern choral styles, blending tradition with fresh, current expression.',2),
(3,'most-artistic-choir',    'Most Artistic Choir',    'Recognising outstanding creativity, originality, and artistic presentation in choral performance.',3),
(3,'most-creative-choir',    'Most Creative Choir',    'Honouring the choir that brings the most inventive arrangements, concepts, and stage presence.',4),
(3,'best-dressed-choir',     'Best Dressed Choir',     'Celebrating the choir whose attire, coordination, and visual presentation best complements their performance.',5);

-- Categories — Business
INSERT IGNORE INTO gates_award_categories (cycle_id,slug,title,description,sort_order) VALUES
(4,'entrepreneur-of-year',   'Entrepreneur of the Year',                      'Recognizing an individual who has shown exceptional vision, leadership, and innovation in their business.',1),
(4,'small-business-champion','Small Business Champion',                        'Highlighting a small business that has made a significant impact in its industry and community.',2),
(4,'innovative-startup',     'Innovative Start-up',                           'Celebrating a start-up that has shown creativity, adaptability, and a pioneering spirit.',3),
(4,'csr-advocate',           'CSR Advocate',                                  'Honoring a business with strong commitment to social responsibility and community development.',4),
(4,'tech-pioneer',           'Tech Pioneer',                                  'Recognizing an individual or business at the forefront of technological innovation in the community.',5),
(4,'sustainable-business',   'Sustainable Business Leader',                   'Celebrating a business in Alimosho that prioritizes environmental sustainability and ethical practices.',6),
(4,'customer-service',       'Customer Service Excellence',                   'Highlighting a business that consistently goes above and beyond in serving its customers.',7);

-- Admin accounts: NO default credentials ship with this installer (a committed
-- default password is a takeover risk). Create the first superadmin explicitly
-- after import — bcrypt-hashed, with your own strong password:
--   php bin/console admin:create admin@afrovanguard.org.ng "Afrovanguard Admin" 'YOUR-STRONG-PASSWORD' --role=superadmin
-- Add editors/judges the same way using --role=editor / --role=judge.

-- Site settings
INSERT IGNORE INTO gates_settings (key_name,value) VALUES
('announce_text','Nominations open — 2026 Cycle · live in Nigeria · building toward 54 nations'),
('announce_url', '/nominate'),
('announce_cta',  'Nominate now →');

-- Admin-configurable settings
INSERT IGNORE INTO gates_admin_settings (setting_key,setting_value,description) VALUES
('donation_votes_per_1000','5',    'Bonus votes awarded per ₦1,000 donated'),
('donation_vote_enabled',  '1',    '1 = enabled, 0 = disabled'),
('donation_vote_min_amount','1000','Minimum donation (₦) to earn bonus votes');

-- Judge scoring rubric (4 criteria, 25% each)
INSERT IGNORE INTO gates_judge_criteria (programme_id,slug,label,description,weight,sort_order,is_active) VALUES
(NULL,'impact',     'Impact',     'Measurable difference made for the community or industry.',    25,1,1),
(NULL,'originality','Originality','Inventiveness, creativity, novelty of approach.',               25,2,1),
(NULL,'reach',      'Reach',      'Breadth of influence — local, regional, continental, global.',  25,3,1),
(NULL,'integrity',  'Integrity',  'Consistency of values, ethics, and accountability.',             25,4,1);

SET FOREIGN_KEY_CHECKS = 1;

-- ═══════════════════════════════════════════════════════════════════════════
--  DONE. Verify with:
--    SHOW TABLES;
--    SELECT COUNT(*) FROM gates_award_categories;
--    SELECT email, role FROM gates_admins;
-- ═══════════════════════════════════════════════════════════════════════════

-- ═══════════════════════════════════════════════════════════════════════════
-- PART 6 — ENTERPRISE FEATURE TABLES
-- ═══════════════════════════════════════════════════════════════════════════

-- Structured event log (all major platform actions)
CREATE TABLE IF NOT EXISTS gates_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(80)     NOT NULL,
  actor_type  ENUM('voter','nominator','admin','judge','system') NOT NULL DEFAULT 'system',
  actor_hash  VARCHAR(64)     DEFAULT NULL,
  subject_type VARCHAR(50)    DEFAULT NULL,
  subject_id  BIGINT UNSIGNED DEFAULT NULL,
  payload     JSON            DEFAULT NULL,
  ip_hash     VARCHAR(64)     DEFAULT NULL,
  device_hash VARCHAR(64)     DEFAULT NULL,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_event_name    (name),
  KEY idx_event_created (created_at),
  KEY idx_event_subject (subject_type, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fraud risk scores per vote/OTP request
CREATE TABLE IF NOT EXISTS gates_fraud_scores (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vote_id     BIGINT UNSIGNED DEFAULT NULL,
  email_hash  VARCHAR(64)     NOT NULL,
  ip_hash     VARCHAR(64)     DEFAULT NULL,
  device_hash VARCHAR(64)     DEFAULT NULL,
  risk_score  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  signals     JSON            DEFAULT NULL,
  decision    ENUM('allow','monitor','flag','block') NOT NULL DEFAULT 'allow',
  reviewed    TINYINT(1)      NOT NULL DEFAULT 0,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fraud_email    (email_hash),
  KEY idx_fraud_score    (risk_score),
  KEY idx_fraud_decision (decision),
  CONSTRAINT fk_fraud_vote FOREIGN KEY (vote_id) REFERENCES gates_votes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversion funnel tracking
CREATE TABLE IF NOT EXISTS gates_funnel_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id  VARCHAR(64)     NOT NULL,
  step        ENUM(
    'nominee_view','vote_button_click','otp_requested','otp_delivered',
    'otp_verified','vote_cast','vote_shared',
    'nominate_start','nominate_step2','nominate_step3','nominate_submitted',
    'register_start','register_submitted'
  )           NOT NULL,
  nominee_id  BIGINT UNSIGNED DEFAULT NULL,
  award_id    TINYINT UNSIGNED DEFAULT NULL,
  device_hash VARCHAR(64)     DEFAULT NULL,
  ip_hash     VARCHAR(64)     DEFAULT NULL,
  meta        JSON            DEFAULT NULL,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_funnel_session (session_id),
  KEY idx_funnel_step    (step),
  KEY idx_funnel_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vote milestones per nominee
CREATE TABLE IF NOT EXISTS gates_vote_milestones (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nominee_id  BIGINT UNSIGNED NOT NULL,
  milestone   INT UNSIGNED    NOT NULL,
  notified    TINYINT(1)      NOT NULL DEFAULT 0,
  achieved_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_milestone (nominee_id, milestone),
  CONSTRAINT fk_milestone_nominee FOREIGN KEY (nominee_id) REFERENCES gates_nominees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nomination drafts (auto-save)
CREATE TABLE IF NOT EXISTS gates_nomination_drafts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_key VARCHAR(64)     NOT NULL,
  payload     JSON            NOT NULL,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_draft_session (session_key),
  KEY idx_draft_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nominee vote snapshots (immutable audit record at voting close)
CREATE TABLE IF NOT EXISTS gates_vote_snapshots (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cycle_id    BIGINT UNSIGNED NOT NULL,
  nominee_id  BIGINT UNSIGNED NOT NULL,
  vote_count  INT UNSIGNED    NOT NULL,
  judge_score DECIMAL(6,3)    DEFAULT NULL,
  cpi_score   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  snapshot_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  hash        VARCHAR(64)     DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_snap_cycle   (cycle_id),
  KEY idx_snap_nominee (nominee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Migration: add nominator_country, nominator_state, nominator_lga
-- (safe on existing databases — stored proc checks first)
DROP PROCEDURE IF EXISTS afg_migrate_nominator_fields;
DELIMITER //
CREATE PROCEDURE afg_migrate_nominator_fields()
BEGIN
  DECLARE db VARCHAR(200) DEFAULT DATABASE();
  IF NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=db AND TABLE_NAME='gates_nominations' AND COLUMN_NAME='nominator_country') THEN
    ALTER TABLE gates_nominations ADD COLUMN nominator_country CHAR(2) DEFAULT NULL AFTER nominator_location;
  END IF;
  IF NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=db AND TABLE_NAME='gates_nominations' AND COLUMN_NAME='nominator_state') THEN
    ALTER TABLE gates_nominations ADD COLUMN nominator_state VARCHAR(100) DEFAULT NULL AFTER nominator_country;
  END IF;
  IF NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=db AND TABLE_NAME='gates_nominations' AND COLUMN_NAME='nominator_lga') THEN
    ALTER TABLE gates_nominations ADD COLUMN nominator_lga VARCHAR(100) DEFAULT NULL AFTER nominator_state;
  END IF;
END //
DELIMITER ;
CALL afg_migrate_nominator_fields();
DROP PROCEDURE IF EXISTS afg_migrate_nominator_fields;
