-- AFRICA GATES — SCHEMA v3  MySQL 8.0+
SET NAMES utf8mb4; SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS gates_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(191) NOT NULL, display_name VARCHAR(200) NOT NULL,
  profile_type ENUM('individual','business','organisation') NOT NULL DEFAULT 'individual',
  category VARCHAR(100) NOT NULL DEFAULT '', bio TEXT,
  email VARCHAR(191) NOT NULL, phone VARCHAR(30) DEFAULT NULL,
  website VARCHAR(300) DEFAULT NULL, instagram_handle VARCHAR(120) DEFAULT NULL,
  twitter_handle VARCHAR(120) DEFAULT NULL,
  country_code CHAR(2) NOT NULL DEFAULT 'NG',
  region ENUM('west','east','north','south','central') NOT NULL DEFAULT 'west',
  location_city VARCHAR(100) DEFAULT NULL,
  latitude DECIMAL(10,7) DEFAULT NULL, longitude DECIMAL(10,7) DEFAULT NULL,
  avatar_path VARCHAR(400) DEFAULT NULL, cover_path VARCHAR(400) DEFAULT NULL,
  gallery_paths JSON DEFAULT NULL, achievements JSON DEFAULT NULL, tags JSON DEFAULT NULL,
  cpi_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  cpi_tier ENUM('diamond','platinum','gold','silver','bronze','unranked') NOT NULL DEFAULT 'unranked',
  cpi_last_computed TIMESTAMP DEFAULT NULL,
  verification_tier ENUM('none','basic','verified','premium') NOT NULL DEFAULT 'none',
  status ENUM('pending','approved','suspended','rejected') NOT NULL DEFAULT 'pending',
  completeness_pct TINYINT UNSIGNED NOT NULL DEFAULT 0,
  view_count INT UNSIGNED NOT NULL DEFAULT 0,
  registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_slug(slug), UNIQUE KEY uq_email(email),
  KEY idx_status(status), KEY idx_country(country_code), KEY idx_region(region),
  KEY idx_cpi_score(cpi_score DESC), KEY idx_cpi_tier(cpi_tier),
  FULLTEXT KEY ft_search(display_name,bio,category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_award_programmes (
  id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT, slug VARCHAR(100) NOT NULL,
  title VARCHAR(200) NOT NULL, subtitle VARCHAR(300) DEFAULT NULL, description TEXT,
  scope ENUM('continental','regional','national') NOT NULL DEFAULT 'continental',
  cover_path VARCHAR(400) DEFAULT NULL, icon_emoji VARCHAR(20) DEFAULT '🏆',
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), UNIQUE KEY uq_slug(slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_award_cycles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, programme_id TINYINT UNSIGNED NOT NULL,
  year YEAR NOT NULL, edition_label VARCHAR(100) DEFAULT NULL,
  status ENUM('upcoming','nominations','voting','judging','results','archived') NOT NULL DEFAULT 'upcoming',
  nominations_open DATETIME DEFAULT NULL, nominations_close DATETIME DEFAULT NULL,
  voting_open DATETIME DEFAULT NULL, voting_close DATETIME DEFAULT NULL,
  results_date DATETIME DEFAULT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_prog_year(programme_id,year),
  CONSTRAINT fk_cycle_prog FOREIGN KEY(programme_id) REFERENCES gates_award_programmes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_award_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, cycle_id BIGINT UNSIGNED NOT NULL,
  slug VARCHAR(191) NOT NULL, title VARCHAR(200) NOT NULL,
  description TEXT, sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY(id), UNIQUE KEY uq_cycle_slug(cycle_id,slug),
  CONSTRAINT fk_cat_cycle FOREIGN KEY(cycle_id) REFERENCES gates_award_cycles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_nominees (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, category_id BIGINT UNSIGNED NOT NULL,
  profile_id BIGINT UNSIGNED DEFAULT NULL, name VARCHAR(200) NOT NULL,
  tagline VARCHAR(300) DEFAULT NULL, photo_path VARCHAR(400) DEFAULT NULL,
  country_code CHAR(2) DEFAULT NULL, vote_count INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('pending','approved','winner','runner_up') NOT NULL DEFAULT 'pending',
  nominated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_category(category_id), KEY idx_votes(vote_count DESC),
  CONSTRAINT fk_nominee_cat FOREIGN KEY(category_id) REFERENCES gates_award_categories(id) ON DELETE CASCADE,
  CONSTRAINT fk_nominee_profile FOREIGN KEY(profile_id) REFERENCES gates_profiles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_votes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, nominee_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL, voter_email_hash VARCHAR(64) NOT NULL,
  otp_token_id BIGINT UNSIGNED DEFAULT NULL, nominee_country CHAR(2) DEFAULT NULL,
  ip_hash VARCHAR(64) DEFAULT NULL,
  device_hash VARCHAR(64) DEFAULT NULL,
  idempotency_key VARCHAR(80) DEFAULT NULL,
  vote_type ENUM('standard','bonus','paid') NOT NULL DEFAULT 'standard',
  weight SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  donation_id BIGINT UNSIGNED DEFAULT NULL,
  risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  fraud_flag TINYINT(1) NOT NULL DEFAULT 0,
  voted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), UNIQUE KEY uq_one_vote(voter_email_hash,category_id),
  UNIQUE KEY uq_votes_idem(idempotency_key),
  KEY idx_nominee(nominee_id), KEY idx_voted_at(voted_at), KEY idx_votes_device(device_hash),
  CONSTRAINT fk_vote_nominee FOREIGN KEY(nominee_id) REFERENCES gates_nominees(id) ON DELETE CASCADE,
  CONSTRAINT fk_vote_cat FOREIGN KEY(category_id) REFERENCES gates_award_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Integrity & operations tables (mirror of the SQLite schema; see migrations/ for
-- driver-aware catch-up scripts that add these to already-deployed databases).
CREATE TABLE IF NOT EXISTS gates_vote_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cycle_id BIGINT UNSIGNED NOT NULL, nominee_id BIGINT UNSIGNED NOT NULL,
  vote_count INT UNSIGNED NOT NULL, judge_score DECIMAL(5,2) DEFAULT NULL,
  cpi_score INT UNSIGNED NOT NULL DEFAULT 0,
  snapshot_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  prev_hash VARCHAR(64) DEFAULT NULL, hash VARCHAR(64) DEFAULT NULL,
  PRIMARY KEY (id), KEY idx_snap_cycle (cycle_id), KEY idx_snap_nominee (nominee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gates_cycle_transitions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, cycle_id BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(20) DEFAULT NULL, to_status VARCHAR(20) NOT NULL,
  reason VARCHAR(200) DEFAULT NULL, actor VARCHAR(80) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_cyctrans_cycle (cycle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gates_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, type VARCHAR(80) NOT NULL,
  payload JSON DEFAULT NULL, status ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  run_after TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, locked_at TIMESTAMP NULL DEFAULT NULL,
  last_error VARCHAR(500) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_jobs_due (status, run_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gates_rule_sets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, scope VARCHAR(20) NOT NULL DEFAULT 'global',
  scope_id BIGINT UNSIGNED DEFAULT NULL, rules JSON NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_rule_scope (scope, scope_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gates_collusion_findings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind ENUM('shared_device','shared_ip','timing_burst') NOT NULL,
  category_id BIGINT UNSIGNED DEFAULT NULL, nominee_id BIGINT UNSIGNED NOT NULL,
  shared_key VARCHAR(120) NOT NULL, vote_count INT UNSIGNED NOT NULL DEFAULT 0,
  distinct_voters INT UNSIGNED NOT NULL DEFAULT 0, risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  explanation VARCHAR(255) DEFAULT NULL,
  status ENUM('open','reviewed','dismissed','actioned') NOT NULL DEFAULT 'open',
  first_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_collusion (kind, nominee_id, shared_key),
  KEY idx_collusion_status (status), KEY idx_collusion_nominee (nominee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Analytics + integrity tables (parity with the SQLite schema; services wrap
-- these in try/catch, so without them the fraud audit trail + analytics silently
-- no-op on a fresh MySQL deploy).
CREATE TABLE IF NOT EXISTS gates_fraud_scores (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, vote_id BIGINT UNSIGNED DEFAULT NULL,
  email_hash VARCHAR(64) NOT NULL, ip_hash VARCHAR(64) DEFAULT NULL, device_hash VARCHAR(64) DEFAULT NULL,
  risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0, signals JSON DEFAULT NULL,
  decision ENUM('allow','monitor','flag','block') NOT NULL DEFAULT 'allow',
  reviewed TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_fraud_email (email_hash), KEY idx_fraud_score (risk_score), KEY idx_fraud_decision (decision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gates_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(80) NOT NULL,
  actor_type ENUM('voter','nominator','admin','judge','system') NOT NULL DEFAULT 'system',
  actor_hash VARCHAR(64) DEFAULT NULL, subject_type VARCHAR(40) DEFAULT NULL, subject_id BIGINT UNSIGNED DEFAULT NULL,
  payload JSON DEFAULT NULL, ip_hash VARCHAR(64) DEFAULT NULL, device_hash VARCHAR(64) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_event_name (name), KEY idx_event_created (created_at), KEY idx_event_subject (subject_type, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gates_funnel_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, session_id VARCHAR(128) NOT NULL, step VARCHAR(40) NOT NULL,
  nominee_id BIGINT UNSIGNED DEFAULT NULL, award_id BIGINT UNSIGNED DEFAULT NULL,
  device_hash VARCHAR(64) DEFAULT NULL, ip_hash VARCHAR(64) DEFAULT NULL, meta JSON DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_funnel_session (session_id), KEY idx_funnel_step (step), KEY idx_funnel_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gates_vote_milestones (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, nominee_id BIGINT UNSIGNED NOT NULL, milestone INT UNSIGNED NOT NULL,
  notified TINYINT(1) NOT NULL DEFAULT 0, achieved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_milestone (nominee_id, milestone),
  CONSTRAINT fk_milestone_nominee FOREIGN KEY (nominee_id) REFERENCES gates_nominees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gates_nomination_drafts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, session_key VARCHAR(128) NOT NULL,
  payload TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_draft_session (session_key), KEY idx_draft_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gates_otp_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, email_hash VARCHAR(64) NOT NULL,
  token_hash VARCHAR(64) NOT NULL, purpose VARCHAR(30) NOT NULL DEFAULT 'vote',
  nominee_id BIGINT UNSIGNED DEFAULT NULL, award_id TINYINT UNSIGNED DEFAULT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0, is_used TINYINT(1) NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_email_purpose(email_hash,purpose), KEY idx_expires(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_nominations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cycle_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED DEFAULT NULL,
  nominee_name VARCHAR(200) NOT NULL,
  nominee_email VARCHAR(191) DEFAULT NULL,
  country_code CHAR(2) DEFAULT NULL,
  nominee_state VARCHAR(100) DEFAULT NULL,
  nominee_lga VARCHAR(100) DEFAULT NULL,
  reason TEXT,
  reference_url VARCHAR(400) DEFAULT NULL,
  reference_url_2 VARCHAR(400) DEFAULT NULL,
  reference_url_3 VARCHAR(400) DEFAULT NULL,
  nominator_name VARCHAR(200) NOT NULL,
  nominator_email VARCHAR(191) NOT NULL,
  nominator_phone VARCHAR(30) DEFAULT NULL,
  nominator_location VARCHAR(200) DEFAULT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  ip_hash VARCHAR(64) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_cycle(cycle_id), KEY idx_status(status),
  CONSTRAINT fk_nom_cycle FOREIGN KEY(cycle_id) REFERENCES gates_award_cycles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_legacy_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, slug VARCHAR(191) NOT NULL,
  title VARCHAR(200) NOT NULL, tagline VARCHAR(300) DEFAULT NULL,
  event_date DATE NOT NULL, location VARCHAR(200) DEFAULT NULL,
  cover_path VARCHAR(400) DEFAULT NULL, gallery_paths JSON DEFAULT NULL,
  video_url VARCHAR(400) DEFAULT NULL, excerpt TEXT, full_content LONGTEXT,
  attendee_count INT UNSIGNED NOT NULL DEFAULT 0, award_count INT UNSIGNED NOT NULL DEFAULT 0,
  highlight_reel JSON DEFAULT NULL, icon VARCHAR(10) DEFAULT '🏆',
  is_published TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), UNIQUE KEY uq_slug(slug), KEY idx_published(is_published), KEY idx_date(event_date DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_opportunities (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, slug VARCHAR(191) NOT NULL,
  title VARCHAR(200) NOT NULL,
  opportunity_type ENUM('grant','mentorship','training','job','fellowship','competition') NOT NULL DEFAULT 'grant',
  scope VARCHAR(100) DEFAULT 'Pan-African', provider VARCHAR(200) NOT NULL,
  description TEXT, eligibility TEXT, value VARCHAR(200) DEFAULT NULL,
  deadline DATE DEFAULT NULL, apply_url VARCHAR(400) DEFAULT NULL,
  min_cpi_tier ENUM('bronze','silver','gold','platinum','diamond') DEFAULT NULL,
  status ENUM('active','closed','draft') NOT NULL DEFAULT 'active', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), UNIQUE KEY uq_slug(slug), KEY idx_status(status), KEY idx_deadline(deadline)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_rate_limits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, fingerprint VARCHAR(64) NOT NULL,
  action VARCHAR(50) NOT NULL, hit_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  window_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), UNIQUE KEY uq_fp_action(fingerprint,action), KEY idx_window(window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_cache (
  cache_key VARCHAR(191) NOT NULL, payload LONGTEXT NOT NULL,
  tags VARCHAR(500) DEFAULT NULL, expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(cache_key), KEY idx_expires(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_cpi_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, profile_id BIGINT UNSIGNED NOT NULL,
  cpi_score SMALLINT UNSIGNED NOT NULL, cpi_tier VARCHAR(20) NOT NULL,
  computed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_profile(profile_id), KEY idx_computed(computed_at),
  CONSTRAINT fk_cpi_profile FOREIGN KEY(profile_id) REFERENCES gates_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_partner_enquiries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, org_name VARCHAR(200) NOT NULL,
  contact_name VARCHAR(200) NOT NULL, contact_email VARCHAR(191) NOT NULL,
  contact_phone VARCHAR(30) DEFAULT NULL, partnership_type VARCHAR(100) DEFAULT NULL,
  message TEXT, status ENUM('new','in_review','converted','closed') NOT NULL DEFAULT 'new',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_cron_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, job_name VARCHAR(100) NOT NULL,
  status ENUM('success','error') NOT NULL, message TEXT, runtime_ms INT UNSIGNED DEFAULT NULL,
  ran_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_job(job_name), KEY idx_ran_at(ran_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Newsletter signups (Phase 0 / Task D4)
CREATE TABLE IF NOT EXISTS gates_newsletter (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email_hash CHAR(64) NOT NULL,
  email VARCHAR(255) NOT NULL,
  ip_hash CHAR(64) DEFAULT NULL,
  source VARCHAR(50) DEFAULT NULL,
  subscribed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unsubscribed_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY(id), UNIQUE KEY uq_newsletter_email_hash(email_hash),
  KEY idx_newsletter_subscribed(subscribed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin-configurable key/value settings
CREATE TABLE IF NOT EXISTS gates_admin_settings (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NOT NULL,
  `description` VARCHAR(300) DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Donation records + bonus-vote tracking
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

-- Migration helper: add new columns to existing installations
-- (safe to run even if columns already exist — IF NOT EXISTS guard)
ALTER TABLE gates_nominations
  ADD COLUMN IF NOT EXISTS nominee_state VARCHAR(100) DEFAULT NULL AFTER country_code,
  ADD COLUMN IF NOT EXISTS nominee_lga VARCHAR(100) DEFAULT NULL AFTER nominee_state,
  ADD COLUMN IF NOT EXISTS reference_url VARCHAR(400) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS reference_url_2 VARCHAR(400) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS reference_url_3 VARCHAR(400) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS nominator_phone VARCHAR(30) DEFAULT NULL AFTER nominator_email,
  ADD COLUMN IF NOT EXISTS nominator_location VARCHAR(200) DEFAULT NULL AFTER nominator_phone;

SET FOREIGN_KEY_CHECKS=1;

-- ─── Site events (public calendar; distinct from gates_events analytics log) ───
CREATE TABLE IF NOT EXISTS gates_site_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(160) NOT NULL,
  title VARCHAR(200) NOT NULL,
  tagline VARCHAR(255) NULL,
  description TEXT NULL,
  location VARCHAR(160) NULL,
  venue VARCHAR(200) NULL,
  event_date DATETIME NOT NULL,
  end_date DATETIME NULL,
  cover_image VARCHAR(500) NULL,
  rsvp_url VARCHAR(500) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'published',
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_site_events_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Blog posts ───
CREATE TABLE IF NOT EXISTS gates_posts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(160) NOT NULL,
  title VARCHAR(220) NOT NULL,
  excerpt VARCHAR(400) NULL,
  body MEDIUMTEXT NULL,
  cover_image VARCHAR(500) NULL,
  author VARCHAR(120) NULL,
  tag VARCHAR(60) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'published',
  published_at DATETIME NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_posts_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
