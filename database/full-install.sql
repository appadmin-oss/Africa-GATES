-- ============================================================================
-- Africa GATES — full idempotent install/upgrade for MySQL & MariaDB.
-- Import via phpMyAdmin (Import tab). SAFE on an existing database: it only
-- creates MISSING tables and adds MISSING columns — it never drops data.
-- Safe to run more than once.
-- ============================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = '';

-- ---------- 1. Tables (created only if absent) ----------
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
  terms MEDIUMTEXT,
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
  organic_vote_count INT UNSIGNED NOT NULL DEFAULT 0,
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
  voter_name VARCHAR(120) DEFAULT NULL,
  voter_phone VARCHAR(40) DEFAULT NULL,
  vote_type ENUM('standard','bonus','paid') NOT NULL DEFAULT 'standard',
  weight SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  donation_id BIGINT UNSIGNED DEFAULT NULL,
  risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  fraud_flag TINYINT(1) NOT NULL DEFAULT 0,
  voted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), UNIQUE KEY uq_one_vote(voter_email_hash,category_id),
  UNIQUE KEY uq_votes_idem(voter_email_hash,idempotency_key),
  KEY idx_nominee(nominee_id), KEY idx_voted_at(voted_at), KEY idx_votes_device(device_hash),
  CONSTRAINT fk_vote_nominee FOREIGN KEY(nominee_id) REFERENCES gates_nominees(id) ON DELETE CASCADE,
  CONSTRAINT fk_vote_cat FOREIGN KEY(category_id) REFERENCES gates_award_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  nominator_country CHAR(2) DEFAULT NULL,
  nominator_state VARCHAR(100) DEFAULT NULL,
  nominator_lga VARCHAR(100) DEFAULT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  ip_hash VARCHAR(64) DEFAULT NULL,
  device_fp VARCHAR(64) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_cycle(cycle_id), KEY idx_status(status), KEY idx_nominations_device(device_fp),
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
CREATE TABLE IF NOT EXISTS gates_admin_settings (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NOT NULL,
  `description` VARCHAR(300) DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  capacity INT UNSIGNED DEFAULT NULL,
  price_naira INT UNSIGNED DEFAULT NULL,
  schedule TEXT NULL,
  map_embed VARCHAR(500) NULL,
  ticket_tiers TEXT NULL,
  early_bird_text VARCHAR(255) NULL,
  early_bird_deadline DATETIME NULL,
  early_bird_url VARCHAR(500) NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_site_events_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_event_registrations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(40) NULL,
  ip_hash VARCHAR(64) NULL,
  amount_naira INT DEFAULT 0,
  reference VARCHAR(80) DEFAULT NULL,
  tier VARCHAR(80) DEFAULT NULL,
  user_id BIGINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_evreg_event_email (event_id, email),
  KEY idx_evreg_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_form_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  purpose VARCHAR(16) NOT NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  email_hash VARCHAR(64) DEFAULT NULL,
  token_hash VARCHAR(64) NOT NULL,
  payload TEXT,
  is_used TINYINT(1) NOT NULL DEFAULT 0,
  used_at TIMESTAMP NULL DEFAULT NULL,
  expires_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_formtok (token_hash),
  KEY idx_formtok_subject (purpose, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_forms (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_key VARCHAR(80) NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  schema_json MEDIUMTEXT NOT NULL,
  submit_message TEXT,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_form_key (form_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_form_submissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_id BIGINT UNSIGNED NOT NULL,
  form_key VARCHAR(80) NOT NULL,
  data_json MEDIUMTEXT NOT NULL,
  ip_hash VARCHAR(64) DEFAULT NULL,
  user_id BIGINT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_formsub_form (form_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(191) NOT NULL,
  phone VARCHAR(40) DEFAULT NULL,
  password_hash VARCHAR(255) DEFAULT NULL,
  points INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT NULL,
  last_login_at TIMESTAMP NULL DEFAULT NULL,
  last_login_ip VARCHAR(64) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_points_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  delta INT NOT NULL,
  reason VARCHAR(40) NOT NULL,
  ref_type VARCHAR(40) DEFAULT NULL,
  ref_id VARCHAR(80) DEFAULT NULL,
  balance_after INT NOT NULL DEFAULT 0,
  note VARCHAR(200) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ledger_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_posts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(160) NOT NULL,
  title VARCHAR(220) NOT NULL,
  excerpt VARCHAR(400) NULL,
  body MEDIUMTEXT NULL,
  cover_image VARCHAR(500) NULL,
  audio_path VARCHAR(500) NULL,
  author VARCHAR(120) NULL,
  tag VARCHAR(60) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'published',
  published_at DATETIME NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_posts_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_admins (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(191) NOT NULL,
  password_hash VARCHAR(255) DEFAULT NULL,
  name VARCHAR(200) NOT NULL,
  role ENUM('superadmin','admin','editor','moderator','judge','viewer') NOT NULL DEFAULT 'editor',
  avatar_path VARCHAR(400) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at TIMESTAMP NULL DEFAULT NULL,
  last_login_ip VARCHAR(64) DEFAULT NULL,
  failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_email (email),
  KEY idx_admins_role (role),
  KEY idx_admins_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_magic_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(191) NOT NULL,
  token_hash VARCHAR(128) NOT NULL,
  purpose ENUM('admin_login','password_reset') NOT NULL DEFAULT 'admin_login',
  expires_at DATETIME NOT NULL,
  used_at TIMESTAMP NULL DEFAULT NULL,
  ip_hash VARCHAR(64) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_magic_token (token_hash),
  KEY idx_magic_email (email),
  KEY idx_magic_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id BIGINT UNSIGNED DEFAULT NULL,
  action VARCHAR(100) NOT NULL,
  target_type VARCHAR(50) DEFAULT NULL,
  target_id BIGINT UNSIGNED DEFAULT NULL,
  meta TEXT,
  ip_hash VARCHAR(64) DEFAULT NULL,
  ua VARCHAR(250) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_admin (admin_id),
  KEY idx_audit_action (action),
  KEY idx_audit_created (created_at),
  CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES gates_admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_judges (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id BIGINT UNSIGNED DEFAULT NULL,
  name VARCHAR(200) NOT NULL,
  email VARCHAR(191) NOT NULL,
  title VARCHAR(200) DEFAULT NULL,
  organisation VARCHAR(200) DEFAULT NULL,
  bio TEXT,
  avatar_path VARCHAR(400) DEFAULT NULL,
  country_code CHAR(2) DEFAULT NULL,
  programme_ids VARCHAR(500) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_judge_email (email),
  KEY idx_judges_active (is_active),
  CONSTRAINT fk_judge_admin FOREIGN KEY (admin_id) REFERENCES gates_admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_judge_scores (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  judge_id BIGINT UNSIGNED NOT NULL,
  nominee_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  score TINYINT NOT NULL,
  notes TEXT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_jscore (judge_id, nominee_id),
  KEY idx_jscore_judge (judge_id),
  KEY idx_jscore_nominee (nominee_id),
  CONSTRAINT fk_jscore_judge    FOREIGN KEY (judge_id)    REFERENCES gates_judges(id)           ON DELETE CASCADE,
  CONSTRAINT fk_jscore_nominee  FOREIGN KEY (nominee_id)  REFERENCES gates_nominees(id)         ON DELETE CASCADE,
  CONSTRAINT fk_jscore_category FOREIGN KEY (category_id) REFERENCES gates_award_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_settings (
  key_name VARCHAR(100) NOT NULL,
  value TEXT,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (key_name),
  CONSTRAINT fk_setting_admin FOREIGN KEY (updated_by) REFERENCES gates_admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_uploads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uploader_id BIGINT UNSIGNED DEFAULT NULL,
  uploader_type ENUM('admin','public','system') NOT NULL DEFAULT 'admin',
  path VARCHAR(500) NOT NULL,
  mime VARCHAR(60) DEFAULT NULL,
  size_bytes INT UNSIGNED DEFAULT NULL,
  width INT UNSIGNED DEFAULT NULL,
  height INT UNSIGNED DEFAULT NULL,
  alt VARCHAR(250) DEFAULT NULL,
  attached_to_type VARCHAR(50) DEFAULT NULL,
  attached_to_id BIGINT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_uploads_attached (attached_to_type, attached_to_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_webhooks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  url VARCHAR(500) NOT NULL,
  secret VARCHAR(120) NOT NULL,
  events TEXT NOT NULL,
  description VARCHAR(200) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_status INT DEFAULT NULL,
  last_event_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_webhook_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_webhook_deliveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  webhook_id BIGINT UNSIGNED NOT NULL,
  event VARCHAR(60) NOT NULL,
  status_code INT DEFAULT NULL,
  ok TINYINT(1) NOT NULL DEFAULT 0,
  error VARCHAR(300) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_delivery_hook (webhook_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_judge_criteria (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  programme_id TINYINT UNSIGNED DEFAULT NULL,
  slug VARCHAR(60) NOT NULL,
  label VARCHAR(120) NOT NULL,
  description TEXT,
  weight TINYINT UNSIGNED NOT NULL DEFAULT 25,
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_crit_prog (programme_id),
  CONSTRAINT fk_crit_prog FOREIGN KEY (programme_id) REFERENCES gates_award_programmes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_judge_criteria_scores (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  judge_id BIGINT UNSIGNED NOT NULL,
  nominee_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  criterion_id BIGINT UNSIGNED NOT NULL,
  score TINYINT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_jcrit (judge_id, nominee_id, criterion_id),
  KEY idx_jcrit_nominee (nominee_id),
  KEY idx_jcrit_judge (judge_id),
  CONSTRAINT fk_jcrit_judge    FOREIGN KEY (judge_id)     REFERENCES gates_judges(id)           ON DELETE CASCADE,
  CONSTRAINT fk_jcrit_nominee  FOREIGN KEY (nominee_id)   REFERENCES gates_nominees(id)         ON DELETE CASCADE,
  CONSTRAINT fk_jcrit_category FOREIGN KEY (category_id)  REFERENCES gates_award_categories(id) ON DELETE CASCADE,
  CONSTRAINT fk_jcrit_crit     FOREIGN KEY (criterion_id) REFERENCES gates_judge_criteria(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_judge_notes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  judge_id BIGINT UNSIGNED NOT NULL,
  nominee_id BIGINT UNSIGNED NOT NULL,
  notes TEXT,
  submitted_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_jnote (judge_id, nominee_id),
  CONSTRAINT fk_jnote_judge   FOREIGN KEY (judge_id)   REFERENCES gates_judges(id)   ON DELETE CASCADE,
  CONSTRAINT fk_jnote_nominee FOREIGN KEY (nominee_id) REFERENCES gates_nominees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_judge_coi (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  judge_id BIGINT UNSIGNED NOT NULL,
  programme_id BIGINT UNSIGNED NOT NULL,
  reason VARCHAR(500) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_judge_coi (judge_id, programme_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_comments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_type ENUM('profile','legacy','thread','nominee') NOT NULL,
  target_id BIGINT UNSIGNED NOT NULL,
  parent_id BIGINT UNSIGNED DEFAULT NULL,
  author_name VARCHAR(200) NOT NULL,
  author_email VARCHAR(191) DEFAULT NULL,
  author_email_hash VARCHAR(64) DEFAULT NULL,
  body TEXT NOT NULL,
  status ENUM('approved','quarantined','rejected','deleted') NOT NULL DEFAULT 'approved',
  ai_score DECIMAL(4,3) DEFAULT NULL,
  ai_reason VARCHAR(500) DEFAULT NULL,
  ip_hash VARCHAR(64) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_comments_target (target_type, target_id),
  KEY idx_comments_status (status),
  KEY idx_comments_created (created_at),
  CONSTRAINT fk_comment_parent FOREIGN KEY (parent_id) REFERENCES gates_comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_cheers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_type ENUM('profile','nominee','comment','thread') NOT NULL,
  target_id BIGINT UNSIGNED NOT NULL,
  fp VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cheer (target_type, target_id, fp),
  KEY idx_cheers_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_activity (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind ENUM('vote','nomination','register','comment','cheer','winner','legacy','opportunity') NOT NULL,
  actor_label VARCHAR(200) DEFAULT NULL,
  target_type VARCHAR(50) DEFAULT NULL,
  target_id BIGINT UNSIGNED DEFAULT NULL,
  target_label VARCHAR(250) DEFAULT NULL,
  meta TEXT,
  is_public TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_activity_created (created_at),
  KEY idx_activity_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_threads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  programme_id TINYINT UNSIGNED DEFAULT NULL,
  slug VARCHAR(191) NOT NULL,
  title VARCHAR(250) NOT NULL,
  body TEXT,
  author_name VARCHAR(200) NOT NULL,
  author_email_hash VARCHAR(64) NOT NULL,
  status ENUM('approved','quarantined','rejected','deleted','locked') NOT NULL DEFAULT 'approved',
  ai_score DECIMAL(4,3) DEFAULT NULL,
  reply_count INT UNSIGNED NOT NULL DEFAULT 0,
  cheer_count INT UNSIGNED NOT NULL DEFAULT 0,
  repost_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_activity TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_thread_slug (slug),
  KEY idx_threads_programme (programme_id),
  KEY idx_threads_activity (last_activity),
  KEY idx_threads_status (status),
  CONSTRAINT fk_thread_prog FOREIGN KEY (programme_id) REFERENCES gates_award_programmes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_moderation_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_type VARCHAR(50) NOT NULL,
  target_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(30) NOT NULL DEFAULT 'heuristic',
  decision ENUM('allow','quarantine','reject') NOT NULL,
  score DECIMAL(4,3) DEFAULT NULL,
  reason VARCHAR(500) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_modlog_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_polls (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_type VARCHAR(16) NOT NULL DEFAULT 'thread',
  target_id BIGINT UNSIGNED NOT NULL,
  question VARCHAR(255) NOT NULL,
  options TEXT NOT NULL,
  multi TINYINT(1) NOT NULL DEFAULT 0,
  is_closed TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_poll_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_poll_votes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  poll_id BIGINT UNSIGNED NOT NULL,
  option_index INT NOT NULL,
  fp VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pollvote (poll_id, fp, option_index),
  KEY idx_pollvotes_poll (poll_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_follows (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  target_type VARCHAR(20) NOT NULL,
  target_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_follow (user_id, target_type, target_id),
  KEY idx_follow_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_bookmarks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  thread_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bookmark (user_id, thread_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_reposts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  thread_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_repost (user_id, thread_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- (supplemental table not in base schema files)
CREATE TABLE IF NOT EXISTS `gates_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference` TEXT NOT NULL,
  `email` TEXT NOT NULL,
  `name` TEXT NOT NULL,
  `phone` TEXT,
  `address` TEXT,
  `items_json` TEXT NOT NULL,
  `subtotal_naira` BIGINT DEFAULT 0,
  `status` VARCHAR(255) DEFAULT 'pending',
  `provider` TEXT,
  `provider_ref` TEXT,
  `ip_hash` TEXT,
  `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_at` TEXT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- (supplemental table not in base schema files)
CREATE TABLE IF NOT EXISTS `gates_products` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` TEXT NOT NULL,
  `name` TEXT NOT NULL,
  `category` VARCHAR(255) DEFAULT 'Apparel',
  `description` TEXT,
  `price_naira` BIGINT DEFAULT 0,
  `cover_path` TEXT,
  `tag` TEXT,
  `stock` BIGINT DEFAULT NULL,
  `is_active` BIGINT DEFAULT 1,
  `sort_order` BIGINT DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `delivery_regions` TEXT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 2. Columns (added only if absent — idempotent, no DELIMITER) ----------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'slug');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `slug` VARCHAR(191) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'display_name');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `display_name` VARCHAR(200) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'profile_type');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `profile_type` ENUM(''individual'',''business'',''organisation'') NOT NULL DEFAULT ''individual''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'category');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `category` VARCHAR(100) NOT NULL DEFAULT ''''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'bio');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `bio` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'email');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `email` VARCHAR(191) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'phone');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `phone` VARCHAR(30) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'website');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `website` VARCHAR(300) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'instagram_handle');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `instagram_handle` VARCHAR(120) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'twitter_handle');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `twitter_handle` VARCHAR(120) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'country_code');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `country_code` CHAR(2) NOT NULL DEFAULT ''NG''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'region');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `region` ENUM(''west'',''east'',''north'',''south'',''central'') NOT NULL DEFAULT ''west''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'location_city');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `location_city` VARCHAR(100) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'latitude');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `latitude` DECIMAL(10,7) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'longitude');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `longitude` DECIMAL(10,7) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'avatar_path');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `avatar_path` VARCHAR(400) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'cover_path');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `cover_path` VARCHAR(400) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'gallery_paths');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `gallery_paths` JSON DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'achievements');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `achievements` JSON DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'tags');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `tags` JSON DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'cpi_score');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `cpi_score` SMALLINT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'cpi_tier');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `cpi_tier` ENUM(''diamond'',''platinum'',''gold'',''silver'',''bronze'',''unranked'') NOT NULL DEFAULT ''unranked''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'cpi_last_computed');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `cpi_last_computed` TIMESTAMP DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'verification_tier');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `verification_tier` ENUM(''none'',''basic'',''verified'',''premium'') NOT NULL DEFAULT ''none''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'status');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `status` ENUM(''pending'',''approved'',''suspended'',''rejected'') NOT NULL DEFAULT ''pending''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'completeness_pct');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `completeness_pct` TINYINT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'view_count');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `view_count` INT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'registered_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `registered_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_profiles' AND COLUMN_NAME = 'updated_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_profiles` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_programmes' AND COLUMN_NAME = 'slug');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_programmes` ADD COLUMN `slug` VARCHAR(100) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_programmes' AND COLUMN_NAME = 'title');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_programmes` ADD COLUMN `title` VARCHAR(200) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_programmes' AND COLUMN_NAME = 'subtitle');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_programmes` ADD COLUMN `subtitle` VARCHAR(300) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_programmes' AND COLUMN_NAME = 'description');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_programmes` ADD COLUMN `description` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_programmes' AND COLUMN_NAME = 'scope');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_programmes` ADD COLUMN `scope` ENUM(''continental'',''regional'',''national'') NOT NULL DEFAULT ''continental''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_programmes' AND COLUMN_NAME = 'cover_path');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_programmes` ADD COLUMN `cover_path` VARCHAR(400) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_programmes' AND COLUMN_NAME = 'icon_emoji');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_programmes` ADD COLUMN `icon_emoji` VARCHAR(20) DEFAULT ''🏆''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_programmes' AND COLUMN_NAME = 'sort_order');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_programmes` ADD COLUMN `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_programmes' AND COLUMN_NAME = 'is_active');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_programmes` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_programmes' AND COLUMN_NAME = 'terms');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_programmes` ADD COLUMN `terms` MEDIUMTEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_programmes' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_programmes` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_cycles' AND COLUMN_NAME = 'programme_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_cycles` ADD COLUMN `programme_id` TINYINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_cycles' AND COLUMN_NAME = 'year');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_cycles` ADD COLUMN `year` YEAR NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_cycles' AND COLUMN_NAME = 'edition_label');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_cycles` ADD COLUMN `edition_label` VARCHAR(100) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_cycles' AND COLUMN_NAME = 'status');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_cycles` ADD COLUMN `status` ENUM(''upcoming'',''nominations'',''voting'',''judging'',''results'',''archived'') NOT NULL DEFAULT ''upcoming''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_cycles' AND COLUMN_NAME = 'nominations_open');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_cycles` ADD COLUMN `nominations_open` DATETIME DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_cycles' AND COLUMN_NAME = 'nominations_close');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_cycles` ADD COLUMN `nominations_close` DATETIME DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_cycles' AND COLUMN_NAME = 'voting_open');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_cycles` ADD COLUMN `voting_open` DATETIME DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_cycles' AND COLUMN_NAME = 'voting_close');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_cycles` ADD COLUMN `voting_close` DATETIME DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_cycles' AND COLUMN_NAME = 'results_date');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_cycles` ADD COLUMN `results_date` DATETIME DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_cycles' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_cycles` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_categories' AND COLUMN_NAME = 'cycle_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_categories` ADD COLUMN `cycle_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_categories' AND COLUMN_NAME = 'slug');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_categories` ADD COLUMN `slug` VARCHAR(191) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_categories' AND COLUMN_NAME = 'title');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_categories` ADD COLUMN `title` VARCHAR(200) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_categories' AND COLUMN_NAME = 'description');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_categories` ADD COLUMN `description` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_award_categories' AND COLUMN_NAME = 'sort_order');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_award_categories` ADD COLUMN `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominees' AND COLUMN_NAME = 'category_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominees` ADD COLUMN `category_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominees' AND COLUMN_NAME = 'profile_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominees` ADD COLUMN `profile_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominees' AND COLUMN_NAME = 'name');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominees` ADD COLUMN `name` VARCHAR(200) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominees' AND COLUMN_NAME = 'tagline');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominees` ADD COLUMN `tagline` VARCHAR(300) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominees' AND COLUMN_NAME = 'photo_path');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominees` ADD COLUMN `photo_path` VARCHAR(400) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominees' AND COLUMN_NAME = 'country_code');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominees` ADD COLUMN `country_code` CHAR(2) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominees' AND COLUMN_NAME = 'vote_count');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominees` ADD COLUMN `vote_count` INT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominees' AND COLUMN_NAME = 'organic_vote_count');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominees` ADD COLUMN `organic_vote_count` INT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominees' AND COLUMN_NAME = 'status');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominees` ADD COLUMN `status` ENUM(''pending'',''approved'',''winner'',''runner_up'') NOT NULL DEFAULT ''pending''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominees' AND COLUMN_NAME = 'nominated_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominees` ADD COLUMN `nominated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_votes' AND COLUMN_NAME = 'nominee_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_votes` ADD COLUMN `nominee_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_votes' AND COLUMN_NAME = 'category_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_votes` ADD COLUMN `category_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_votes' AND COLUMN_NAME = 'voter_email_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_votes` ADD COLUMN `voter_email_hash` VARCHAR(64) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_votes' AND COLUMN_NAME = 'otp_token_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_votes` ADD COLUMN `otp_token_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_votes' AND COLUMN_NAME = 'nominee_country');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_votes` ADD COLUMN `nominee_country` CHAR(2) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_votes' AND COLUMN_NAME = 'ip_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_votes` ADD COLUMN `ip_hash` VARCHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_votes' AND COLUMN_NAME = 'device_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_votes` ADD COLUMN `device_hash` VARCHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_votes' AND COLUMN_NAME = 'idempotency_key');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_votes` ADD COLUMN `idempotency_key` VARCHAR(80) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_votes' AND COLUMN_NAME = 'voter_name');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_votes` ADD COLUMN `voter_name` VARCHAR(120) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_votes' AND COLUMN_NAME = 'voter_phone');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_votes` ADD COLUMN `voter_phone` VARCHAR(40) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_votes' AND COLUMN_NAME = 'vote_type');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_votes` ADD COLUMN `vote_type` ENUM(''standard'',''bonus'',''paid'') NOT NULL DEFAULT ''standard''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_votes' AND COLUMN_NAME = 'weight');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_votes` ADD COLUMN `weight` SMALLINT UNSIGNED NOT NULL DEFAULT 1');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_votes' AND COLUMN_NAME = 'donation_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_votes` ADD COLUMN `donation_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_votes' AND COLUMN_NAME = 'risk_score');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_votes` ADD COLUMN `risk_score` TINYINT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_votes' AND COLUMN_NAME = 'fraud_flag');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_votes` ADD COLUMN `fraud_flag` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_votes' AND COLUMN_NAME = 'voted_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_votes` ADD COLUMN `voted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_vote_snapshots' AND COLUMN_NAME = 'cycle_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_vote_snapshots` ADD COLUMN `cycle_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_vote_snapshots' AND COLUMN_NAME = 'nominee_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_vote_snapshots` ADD COLUMN `nominee_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_vote_snapshots' AND COLUMN_NAME = 'vote_count');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_vote_snapshots` ADD COLUMN `vote_count` INT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_vote_snapshots' AND COLUMN_NAME = 'judge_score');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_vote_snapshots` ADD COLUMN `judge_score` DECIMAL(5,2) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_vote_snapshots' AND COLUMN_NAME = 'cpi_score');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_vote_snapshots` ADD COLUMN `cpi_score` INT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_vote_snapshots' AND COLUMN_NAME = 'snapshot_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_vote_snapshots` ADD COLUMN `snapshot_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_vote_snapshots' AND COLUMN_NAME = 'prev_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_vote_snapshots` ADD COLUMN `prev_hash` VARCHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_vote_snapshots' AND COLUMN_NAME = 'hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_vote_snapshots` ADD COLUMN `hash` VARCHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cycle_transitions' AND COLUMN_NAME = 'cycle_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cycle_transitions` ADD COLUMN `cycle_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cycle_transitions' AND COLUMN_NAME = 'from_status');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cycle_transitions` ADD COLUMN `from_status` VARCHAR(20) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cycle_transitions' AND COLUMN_NAME = 'to_status');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cycle_transitions` ADD COLUMN `to_status` VARCHAR(20) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cycle_transitions' AND COLUMN_NAME = 'reason');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cycle_transitions` ADD COLUMN `reason` VARCHAR(200) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cycle_transitions' AND COLUMN_NAME = 'actor');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cycle_transitions` ADD COLUMN `actor` VARCHAR(80) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cycle_transitions' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cycle_transitions` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_jobs' AND COLUMN_NAME = 'type');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_jobs` ADD COLUMN `type` VARCHAR(80) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_jobs' AND COLUMN_NAME = 'payload');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_jobs` ADD COLUMN `payload` JSON DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_jobs' AND COLUMN_NAME = 'status');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_jobs` ADD COLUMN `status` ENUM(''pending'',''done'',''failed'') NOT NULL DEFAULT ''pending''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_jobs' AND COLUMN_NAME = 'attempts');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_jobs` ADD COLUMN `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_jobs' AND COLUMN_NAME = 'run_after');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_jobs` ADD COLUMN `run_after` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_jobs' AND COLUMN_NAME = 'locked_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_jobs` ADD COLUMN `locked_at` TIMESTAMP NULL DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_jobs' AND COLUMN_NAME = 'last_error');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_jobs` ADD COLUMN `last_error` VARCHAR(500) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_jobs' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_jobs` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_jobs' AND COLUMN_NAME = 'updated_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_jobs` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_rule_sets' AND COLUMN_NAME = 'scope');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_rule_sets` ADD COLUMN `scope` VARCHAR(20) NOT NULL DEFAULT ''global''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_rule_sets' AND COLUMN_NAME = 'scope_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_rule_sets` ADD COLUMN `scope_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_rule_sets' AND COLUMN_NAME = 'rules');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_rule_sets` ADD COLUMN `rules` JSON NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_rule_sets' AND COLUMN_NAME = 'updated_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_rule_sets` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_collusion_findings' AND COLUMN_NAME = 'kind');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_collusion_findings` ADD COLUMN `kind` ENUM(''shared_device'',''shared_ip'',''timing_burst'') NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_collusion_findings' AND COLUMN_NAME = 'category_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_collusion_findings` ADD COLUMN `category_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_collusion_findings' AND COLUMN_NAME = 'nominee_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_collusion_findings` ADD COLUMN `nominee_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_collusion_findings' AND COLUMN_NAME = 'shared_key');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_collusion_findings` ADD COLUMN `shared_key` VARCHAR(120) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_collusion_findings' AND COLUMN_NAME = 'vote_count');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_collusion_findings` ADD COLUMN `vote_count` INT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_collusion_findings' AND COLUMN_NAME = 'distinct_voters');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_collusion_findings` ADD COLUMN `distinct_voters` INT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_collusion_findings' AND COLUMN_NAME = 'risk_score');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_collusion_findings` ADD COLUMN `risk_score` TINYINT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_collusion_findings' AND COLUMN_NAME = 'explanation');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_collusion_findings` ADD COLUMN `explanation` VARCHAR(255) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_collusion_findings' AND COLUMN_NAME = 'status');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_collusion_findings` ADD COLUMN `status` ENUM(''open'',''reviewed'',''dismissed'',''actioned'') NOT NULL DEFAULT ''open''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_collusion_findings' AND COLUMN_NAME = 'first_seen');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_collusion_findings` ADD COLUMN `first_seen` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_collusion_findings' AND COLUMN_NAME = 'last_seen');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_collusion_findings` ADD COLUMN `last_seen` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_fraud_scores' AND COLUMN_NAME = 'vote_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_fraud_scores` ADD COLUMN `vote_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_fraud_scores' AND COLUMN_NAME = 'email_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_fraud_scores` ADD COLUMN `email_hash` VARCHAR(64) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_fraud_scores' AND COLUMN_NAME = 'ip_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_fraud_scores` ADD COLUMN `ip_hash` VARCHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_fraud_scores' AND COLUMN_NAME = 'device_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_fraud_scores` ADD COLUMN `device_hash` VARCHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_fraud_scores' AND COLUMN_NAME = 'risk_score');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_fraud_scores` ADD COLUMN `risk_score` TINYINT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_fraud_scores' AND COLUMN_NAME = 'signals');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_fraud_scores` ADD COLUMN `signals` JSON DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_fraud_scores' AND COLUMN_NAME = 'decision');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_fraud_scores` ADD COLUMN `decision` ENUM(''allow'',''monitor'',''flag'',''block'') NOT NULL DEFAULT ''allow''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_fraud_scores' AND COLUMN_NAME = 'reviewed');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_fraud_scores` ADD COLUMN `reviewed` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_fraud_scores' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_fraud_scores` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_events' AND COLUMN_NAME = 'name');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_events` ADD COLUMN `name` VARCHAR(80) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_events' AND COLUMN_NAME = 'actor_type');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_events` ADD COLUMN `actor_type` ENUM(''voter'',''nominator'',''admin'',''judge'',''system'') NOT NULL DEFAULT ''system''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_events' AND COLUMN_NAME = 'actor_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_events` ADD COLUMN `actor_hash` VARCHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_events' AND COLUMN_NAME = 'subject_type');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_events` ADD COLUMN `subject_type` VARCHAR(40) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_events' AND COLUMN_NAME = 'subject_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_events` ADD COLUMN `subject_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_events' AND COLUMN_NAME = 'payload');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_events` ADD COLUMN `payload` JSON DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_events' AND COLUMN_NAME = 'ip_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_events` ADD COLUMN `ip_hash` VARCHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_events' AND COLUMN_NAME = 'device_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_events` ADD COLUMN `device_hash` VARCHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_events' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_events` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_funnel_events' AND COLUMN_NAME = 'session_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_funnel_events` ADD COLUMN `session_id` VARCHAR(128) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_funnel_events' AND COLUMN_NAME = 'step');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_funnel_events` ADD COLUMN `step` VARCHAR(40) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_funnel_events' AND COLUMN_NAME = 'nominee_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_funnel_events` ADD COLUMN `nominee_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_funnel_events' AND COLUMN_NAME = 'award_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_funnel_events` ADD COLUMN `award_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_funnel_events' AND COLUMN_NAME = 'device_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_funnel_events` ADD COLUMN `device_hash` VARCHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_funnel_events' AND COLUMN_NAME = 'ip_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_funnel_events` ADD COLUMN `ip_hash` VARCHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_funnel_events' AND COLUMN_NAME = 'meta');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_funnel_events` ADD COLUMN `meta` JSON DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_funnel_events' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_funnel_events` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_vote_milestones' AND COLUMN_NAME = 'nominee_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_vote_milestones` ADD COLUMN `nominee_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_vote_milestones' AND COLUMN_NAME = 'milestone');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_vote_milestones` ADD COLUMN `milestone` INT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_vote_milestones' AND COLUMN_NAME = 'notified');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_vote_milestones` ADD COLUMN `notified` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_vote_milestones' AND COLUMN_NAME = 'achieved_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_vote_milestones` ADD COLUMN `achieved_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nomination_drafts' AND COLUMN_NAME = 'session_key');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nomination_drafts` ADD COLUMN `session_key` VARCHAR(128) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nomination_drafts' AND COLUMN_NAME = 'payload');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nomination_drafts` ADD COLUMN `payload` TEXT NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nomination_drafts' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nomination_drafts` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nomination_drafts' AND COLUMN_NAME = 'updated_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nomination_drafts` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_otp_tokens' AND COLUMN_NAME = 'email_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_otp_tokens` ADD COLUMN `email_hash` VARCHAR(64) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_otp_tokens' AND COLUMN_NAME = 'token_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_otp_tokens` ADD COLUMN `token_hash` VARCHAR(64) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_otp_tokens' AND COLUMN_NAME = 'purpose');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_otp_tokens` ADD COLUMN `purpose` VARCHAR(30) NOT NULL DEFAULT ''vote''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_otp_tokens' AND COLUMN_NAME = 'nominee_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_otp_tokens` ADD COLUMN `nominee_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_otp_tokens' AND COLUMN_NAME = 'award_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_otp_tokens` ADD COLUMN `award_id` TINYINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_otp_tokens' AND COLUMN_NAME = 'attempts');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_otp_tokens` ADD COLUMN `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_otp_tokens' AND COLUMN_NAME = 'is_used');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_otp_tokens` ADD COLUMN `is_used` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_otp_tokens' AND COLUMN_NAME = 'expires_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_otp_tokens` ADD COLUMN `expires_at` DATETIME NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_otp_tokens' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_otp_tokens` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'cycle_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `cycle_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'category_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `category_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'nominee_name');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `nominee_name` VARCHAR(200) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'nominee_email');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `nominee_email` VARCHAR(191) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'country_code');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `country_code` CHAR(2) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'nominee_state');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `nominee_state` VARCHAR(100) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'nominee_lga');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `nominee_lga` VARCHAR(100) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'reason');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `reason` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'reference_url');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `reference_url` VARCHAR(400) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'reference_url_2');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `reference_url_2` VARCHAR(400) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'reference_url_3');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `reference_url_3` VARCHAR(400) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'nominator_name');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `nominator_name` VARCHAR(200) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'nominator_email');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `nominator_email` VARCHAR(191) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'nominator_phone');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `nominator_phone` VARCHAR(30) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'nominator_location');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `nominator_location` VARCHAR(200) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'nominator_country');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `nominator_country` CHAR(2) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'nominator_state');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `nominator_state` VARCHAR(100) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'nominator_lga');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `nominator_lga` VARCHAR(100) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'status');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `status` ENUM(''pending'',''approved'',''rejected'') NOT NULL DEFAULT ''pending''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'ip_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `ip_hash` VARCHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'device_fp');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `device_fp` VARCHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'nominee_photo_path');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `nominee_photo_path` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'nominee_org');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `nominee_org` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'nominee_phone');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `nominee_phone` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_nominations' AND COLUMN_NAME = 'nominator_age_range');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_nominations` ADD COLUMN `nominator_age_range` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_legacy_events' AND COLUMN_NAME = 'slug');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_legacy_events` ADD COLUMN `slug` VARCHAR(191) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_legacy_events' AND COLUMN_NAME = 'title');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_legacy_events` ADD COLUMN `title` VARCHAR(200) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_legacy_events' AND COLUMN_NAME = 'tagline');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_legacy_events` ADD COLUMN `tagline` VARCHAR(300) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_legacy_events' AND COLUMN_NAME = 'event_date');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_legacy_events` ADD COLUMN `event_date` DATE NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_legacy_events' AND COLUMN_NAME = 'location');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_legacy_events` ADD COLUMN `location` VARCHAR(200) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_legacy_events' AND COLUMN_NAME = 'cover_path');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_legacy_events` ADD COLUMN `cover_path` VARCHAR(400) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_legacy_events' AND COLUMN_NAME = 'gallery_paths');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_legacy_events` ADD COLUMN `gallery_paths` JSON DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_legacy_events' AND COLUMN_NAME = 'video_url');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_legacy_events` ADD COLUMN `video_url` VARCHAR(400) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_legacy_events' AND COLUMN_NAME = 'excerpt');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_legacy_events` ADD COLUMN `excerpt` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_legacy_events' AND COLUMN_NAME = 'full_content');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_legacy_events` ADD COLUMN `full_content` LONGTEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_legacy_events' AND COLUMN_NAME = 'attendee_count');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_legacy_events` ADD COLUMN `attendee_count` INT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_legacy_events' AND COLUMN_NAME = 'award_count');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_legacy_events` ADD COLUMN `award_count` INT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_legacy_events' AND COLUMN_NAME = 'highlight_reel');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_legacy_events` ADD COLUMN `highlight_reel` JSON DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_legacy_events' AND COLUMN_NAME = 'icon');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_legacy_events` ADD COLUMN `icon` VARCHAR(10) DEFAULT ''🏆''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_legacy_events' AND COLUMN_NAME = 'is_published');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_legacy_events` ADD COLUMN `is_published` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_legacy_events' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_legacy_events` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_opportunities' AND COLUMN_NAME = 'slug');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_opportunities` ADD COLUMN `slug` VARCHAR(191) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_opportunities' AND COLUMN_NAME = 'title');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_opportunities` ADD COLUMN `title` VARCHAR(200) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_opportunities' AND COLUMN_NAME = 'opportunity_type');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_opportunities` ADD COLUMN `opportunity_type` ENUM(''grant'',''mentorship'',''training'',''job'',''fellowship'',''competition'') NOT NULL DEFAULT ''grant''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_opportunities' AND COLUMN_NAME = 'scope');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_opportunities` ADD COLUMN `scope` VARCHAR(100) DEFAULT ''Pan-African''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_opportunities' AND COLUMN_NAME = 'provider');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_opportunities` ADD COLUMN `provider` VARCHAR(200) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_opportunities' AND COLUMN_NAME = 'description');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_opportunities` ADD COLUMN `description` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_opportunities' AND COLUMN_NAME = 'eligibility');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_opportunities` ADD COLUMN `eligibility` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_opportunities' AND COLUMN_NAME = 'value');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_opportunities` ADD COLUMN `value` VARCHAR(200) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_opportunities' AND COLUMN_NAME = 'deadline');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_opportunities` ADD COLUMN `deadline` DATE DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_opportunities' AND COLUMN_NAME = 'apply_url');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_opportunities` ADD COLUMN `apply_url` VARCHAR(400) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_opportunities' AND COLUMN_NAME = 'min_cpi_tier');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_opportunities` ADD COLUMN `min_cpi_tier` ENUM(''bronze'',''silver'',''gold'',''platinum'',''diamond'') DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_opportunities' AND COLUMN_NAME = 'status');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_opportunities` ADD COLUMN `status` ENUM(''active'',''closed'',''draft'') NOT NULL DEFAULT ''active''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_opportunities' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_opportunities` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_rate_limits' AND COLUMN_NAME = 'fingerprint');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_rate_limits` ADD COLUMN `fingerprint` VARCHAR(64) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_rate_limits' AND COLUMN_NAME = 'action');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_rate_limits` ADD COLUMN `action` VARCHAR(50) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_rate_limits' AND COLUMN_NAME = 'hit_count');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_rate_limits` ADD COLUMN `hit_count` SMALLINT UNSIGNED NOT NULL DEFAULT 1');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_rate_limits' AND COLUMN_NAME = 'window_start');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_rate_limits` ADD COLUMN `window_start` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cache' AND COLUMN_NAME = 'cache_key');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cache` ADD COLUMN `cache_key` VARCHAR(191) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cache' AND COLUMN_NAME = 'payload');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cache` ADD COLUMN `payload` LONGTEXT NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cache' AND COLUMN_NAME = 'tags');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cache` ADD COLUMN `tags` VARCHAR(500) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cache' AND COLUMN_NAME = 'expires_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cache` ADD COLUMN `expires_at` TIMESTAMP NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cache' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cache` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cpi_history' AND COLUMN_NAME = 'profile_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cpi_history` ADD COLUMN `profile_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cpi_history' AND COLUMN_NAME = 'cpi_score');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cpi_history` ADD COLUMN `cpi_score` SMALLINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cpi_history' AND COLUMN_NAME = 'cpi_tier');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cpi_history` ADD COLUMN `cpi_tier` VARCHAR(20) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cpi_history' AND COLUMN_NAME = 'computed_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cpi_history` ADD COLUMN `computed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_partner_enquiries' AND COLUMN_NAME = 'org_name');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_partner_enquiries` ADD COLUMN `org_name` VARCHAR(200) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_partner_enquiries' AND COLUMN_NAME = 'contact_name');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_partner_enquiries` ADD COLUMN `contact_name` VARCHAR(200) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_partner_enquiries' AND COLUMN_NAME = 'contact_email');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_partner_enquiries` ADD COLUMN `contact_email` VARCHAR(191) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_partner_enquiries' AND COLUMN_NAME = 'contact_phone');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_partner_enquiries` ADD COLUMN `contact_phone` VARCHAR(30) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_partner_enquiries' AND COLUMN_NAME = 'partnership_type');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_partner_enquiries` ADD COLUMN `partnership_type` VARCHAR(100) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_partner_enquiries' AND COLUMN_NAME = 'message');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_partner_enquiries` ADD COLUMN `message` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_partner_enquiries' AND COLUMN_NAME = 'status');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_partner_enquiries` ADD COLUMN `status` ENUM(''new'',''in_review'',''converted'',''closed'') NOT NULL DEFAULT ''new''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_partner_enquiries' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_partner_enquiries` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cron_log' AND COLUMN_NAME = 'job_name');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cron_log` ADD COLUMN `job_name` VARCHAR(100) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cron_log' AND COLUMN_NAME = 'status');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cron_log` ADD COLUMN `status` ENUM(''success'',''error'') NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cron_log' AND COLUMN_NAME = 'message');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cron_log` ADD COLUMN `message` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cron_log' AND COLUMN_NAME = 'runtime_ms');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cron_log` ADD COLUMN `runtime_ms` INT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cron_log' AND COLUMN_NAME = 'ran_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cron_log` ADD COLUMN `ran_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_newsletter' AND COLUMN_NAME = 'email_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_newsletter` ADD COLUMN `email_hash` CHAR(64) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_newsletter' AND COLUMN_NAME = 'email');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_newsletter` ADD COLUMN `email` VARCHAR(255) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_newsletter' AND COLUMN_NAME = 'ip_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_newsletter` ADD COLUMN `ip_hash` CHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_newsletter' AND COLUMN_NAME = 'source');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_newsletter` ADD COLUMN `source` VARCHAR(50) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_newsletter' AND COLUMN_NAME = 'subscribed_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_newsletter` ADD COLUMN `subscribed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_newsletter' AND COLUMN_NAME = 'unsubscribed_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_newsletter` ADD COLUMN `unsubscribed_at` TIMESTAMP NULL DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_admin_settings' AND COLUMN_NAME = 'setting_key');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_admin_settings` ADD COLUMN `setting_key` VARCHAR(100) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_admin_settings' AND COLUMN_NAME = 'setting_value');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_admin_settings` ADD COLUMN `setting_value` TEXT NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_admin_settings' AND COLUMN_NAME = 'description');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_admin_settings` ADD COLUMN `description` VARCHAR(300) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_admin_settings' AND COLUMN_NAME = 'updated_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_admin_settings` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_donations' AND COLUMN_NAME = 'donor_name');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_donations` ADD COLUMN `donor_name` VARCHAR(200) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_donations' AND COLUMN_NAME = 'donor_email');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_donations` ADD COLUMN `donor_email` VARCHAR(191) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_donations' AND COLUMN_NAME = 'donor_phone');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_donations` ADD COLUMN `donor_phone` VARCHAR(30) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_donations' AND COLUMN_NAME = 'donor_location');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_donations` ADD COLUMN `donor_location` VARCHAR(200) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_donations' AND COLUMN_NAME = 'amount_naira');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_donations` ADD COLUMN `amount_naira` INT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_donations' AND COLUMN_NAME = 'tier');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_donations` ADD COLUMN `tier` VARCHAR(50) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_donations' AND COLUMN_NAME = 'bonus_votes');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_donations` ADD COLUMN `bonus_votes` INT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_donations' AND COLUMN_NAME = 'votes_used');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_donations` ADD COLUMN `votes_used` INT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_donations' AND COLUMN_NAME = 'payment_ref');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_donations` ADD COLUMN `payment_ref` VARCHAR(200) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_donations' AND COLUMN_NAME = 'status');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_donations` ADD COLUMN `status` ENUM(''pending'',''confirmed'',''failed'') NOT NULL DEFAULT ''pending''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_donations' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_donations` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_site_events' AND COLUMN_NAME = 'slug');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_site_events` ADD COLUMN `slug` VARCHAR(160) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_site_events' AND COLUMN_NAME = 'title');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_site_events` ADD COLUMN `title` VARCHAR(200) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_site_events' AND COLUMN_NAME = 'tagline');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_site_events` ADD COLUMN `tagline` VARCHAR(255) NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_site_events' AND COLUMN_NAME = 'description');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_site_events` ADD COLUMN `description` TEXT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_site_events' AND COLUMN_NAME = 'location');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_site_events` ADD COLUMN `location` VARCHAR(160) NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_site_events' AND COLUMN_NAME = 'venue');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_site_events` ADD COLUMN `venue` VARCHAR(200) NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_site_events' AND COLUMN_NAME = 'event_date');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_site_events` ADD COLUMN `event_date` DATETIME NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_site_events' AND COLUMN_NAME = 'end_date');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_site_events` ADD COLUMN `end_date` DATETIME NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_site_events' AND COLUMN_NAME = 'cover_image');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_site_events` ADD COLUMN `cover_image` VARCHAR(500) NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_site_events' AND COLUMN_NAME = 'rsvp_url');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_site_events` ADD COLUMN `rsvp_url` VARCHAR(500) NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_site_events' AND COLUMN_NAME = 'status');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_site_events` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT ''published''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_site_events' AND COLUMN_NAME = 'capacity');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_site_events` ADD COLUMN `capacity` INT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_site_events' AND COLUMN_NAME = 'price_naira');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_site_events` ADD COLUMN `price_naira` INT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_site_events' AND COLUMN_NAME = 'schedule');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_site_events` ADD COLUMN `schedule` TEXT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_site_events' AND COLUMN_NAME = 'map_embed');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_site_events` ADD COLUMN `map_embed` VARCHAR(500) NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_site_events' AND COLUMN_NAME = 'ticket_tiers');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_site_events` ADD COLUMN `ticket_tiers` TEXT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_site_events' AND COLUMN_NAME = 'early_bird_text');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_site_events` ADD COLUMN `early_bird_text` VARCHAR(255) NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_site_events' AND COLUMN_NAME = 'early_bird_deadline');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_site_events` ADD COLUMN `early_bird_deadline` DATETIME NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_site_events' AND COLUMN_NAME = 'early_bird_url');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_site_events` ADD COLUMN `early_bird_url` VARCHAR(500) NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_site_events' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_site_events` ADD COLUMN `created_at` DATETIME NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_event_registrations' AND COLUMN_NAME = 'event_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_event_registrations` ADD COLUMN `event_id` INT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_event_registrations' AND COLUMN_NAME = 'name');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_event_registrations` ADD COLUMN `name` VARCHAR(160) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_event_registrations' AND COLUMN_NAME = 'email');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_event_registrations` ADD COLUMN `email` VARCHAR(190) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_event_registrations' AND COLUMN_NAME = 'phone');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_event_registrations` ADD COLUMN `phone` VARCHAR(40) NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_event_registrations' AND COLUMN_NAME = 'ip_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_event_registrations` ADD COLUMN `ip_hash` VARCHAR(64) NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_event_registrations' AND COLUMN_NAME = 'amount_naira');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_event_registrations` ADD COLUMN `amount_naira` INT DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_event_registrations' AND COLUMN_NAME = 'reference');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_event_registrations` ADD COLUMN `reference` VARCHAR(80) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_event_registrations' AND COLUMN_NAME = 'tier');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_event_registrations` ADD COLUMN `tier` VARCHAR(80) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_event_registrations' AND COLUMN_NAME = 'user_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_event_registrations` ADD COLUMN `user_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_event_registrations' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_event_registrations` ADD COLUMN `created_at` DATETIME NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_form_tokens' AND COLUMN_NAME = 'purpose');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_form_tokens` ADD COLUMN `purpose` VARCHAR(16) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_form_tokens' AND COLUMN_NAME = 'subject_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_form_tokens` ADD COLUMN `subject_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_form_tokens' AND COLUMN_NAME = 'email_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_form_tokens` ADD COLUMN `email_hash` VARCHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_form_tokens' AND COLUMN_NAME = 'token_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_form_tokens` ADD COLUMN `token_hash` VARCHAR(64) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_form_tokens' AND COLUMN_NAME = 'payload');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_form_tokens` ADD COLUMN `payload` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_form_tokens' AND COLUMN_NAME = 'is_used');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_form_tokens` ADD COLUMN `is_used` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_form_tokens' AND COLUMN_NAME = 'used_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_form_tokens` ADD COLUMN `used_at` TIMESTAMP NULL DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_form_tokens' AND COLUMN_NAME = 'expires_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_form_tokens` ADD COLUMN `expires_at` TIMESTAMP NULL DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_form_tokens' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_form_tokens` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_forms' AND COLUMN_NAME = 'form_key');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_forms` ADD COLUMN `form_key` VARCHAR(80) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_forms' AND COLUMN_NAME = 'title');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_forms` ADD COLUMN `title` VARCHAR(200) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_forms' AND COLUMN_NAME = 'description');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_forms` ADD COLUMN `description` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_forms' AND COLUMN_NAME = 'schema_json');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_forms` ADD COLUMN `schema_json` MEDIUMTEXT NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_forms' AND COLUMN_NAME = 'submit_message');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_forms` ADD COLUMN `submit_message` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_forms' AND COLUMN_NAME = 'status');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_forms` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT ''draft''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_forms' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_forms` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_forms' AND COLUMN_NAME = 'updated_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_forms` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_form_submissions' AND COLUMN_NAME = 'form_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_form_submissions` ADD COLUMN `form_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_form_submissions' AND COLUMN_NAME = 'form_key');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_form_submissions` ADD COLUMN `form_key` VARCHAR(80) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_form_submissions' AND COLUMN_NAME = 'data_json');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_form_submissions` ADD COLUMN `data_json` MEDIUMTEXT NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_form_submissions' AND COLUMN_NAME = 'ip_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_form_submissions` ADD COLUMN `ip_hash` VARCHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_form_submissions' AND COLUMN_NAME = 'user_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_form_submissions` ADD COLUMN `user_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_form_submissions' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_form_submissions` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_users' AND COLUMN_NAME = 'name');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_users` ADD COLUMN `name` VARCHAR(160) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_users' AND COLUMN_NAME = 'email');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_users` ADD COLUMN `email` VARCHAR(191) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_users' AND COLUMN_NAME = 'phone');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_users` ADD COLUMN `phone` VARCHAR(40) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_users' AND COLUMN_NAME = 'password_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_users` ADD COLUMN `password_hash` VARCHAR(255) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_users' AND COLUMN_NAME = 'points');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_users` ADD COLUMN `points` INT NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_users' AND COLUMN_NAME = 'status');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_users` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT ''active''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_users' AND COLUMN_NAME = 'email_verified');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_users` ADD COLUMN `email_verified` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_users' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_users` ADD COLUMN `created_at` TIMESTAMP NULL DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_users' AND COLUMN_NAME = 'last_login_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_users` ADD COLUMN `last_login_at` TIMESTAMP NULL DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_users' AND COLUMN_NAME = 'last_login_ip');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_users` ADD COLUMN `last_login_ip` VARCHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_points_ledger' AND COLUMN_NAME = 'user_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_points_ledger` ADD COLUMN `user_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_points_ledger' AND COLUMN_NAME = 'delta');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_points_ledger` ADD COLUMN `delta` INT NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_points_ledger' AND COLUMN_NAME = 'reason');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_points_ledger` ADD COLUMN `reason` VARCHAR(40) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_points_ledger' AND COLUMN_NAME = 'ref_type');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_points_ledger` ADD COLUMN `ref_type` VARCHAR(40) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_points_ledger' AND COLUMN_NAME = 'ref_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_points_ledger` ADD COLUMN `ref_id` VARCHAR(80) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_points_ledger' AND COLUMN_NAME = 'balance_after');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_points_ledger` ADD COLUMN `balance_after` INT NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_points_ledger' AND COLUMN_NAME = 'note');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_points_ledger` ADD COLUMN `note` VARCHAR(200) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_points_ledger' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_points_ledger` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_posts' AND COLUMN_NAME = 'slug');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_posts` ADD COLUMN `slug` VARCHAR(160) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_posts' AND COLUMN_NAME = 'title');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_posts` ADD COLUMN `title` VARCHAR(220) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_posts' AND COLUMN_NAME = 'excerpt');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_posts` ADD COLUMN `excerpt` VARCHAR(400) NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_posts' AND COLUMN_NAME = 'body');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_posts` ADD COLUMN `body` MEDIUMTEXT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_posts' AND COLUMN_NAME = 'cover_image');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_posts` ADD COLUMN `cover_image` VARCHAR(500) NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_posts' AND COLUMN_NAME = 'audio_path');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_posts` ADD COLUMN `audio_path` VARCHAR(500) NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_posts' AND COLUMN_NAME = 'author');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_posts` ADD COLUMN `author` VARCHAR(120) NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_posts' AND COLUMN_NAME = 'tag');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_posts` ADD COLUMN `tag` VARCHAR(60) NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_posts' AND COLUMN_NAME = 'status');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_posts` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT ''published''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_posts' AND COLUMN_NAME = 'published_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_posts` ADD COLUMN `published_at` DATETIME NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_posts' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_posts` ADD COLUMN `created_at` DATETIME NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_admins' AND COLUMN_NAME = 'email');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_admins` ADD COLUMN `email` VARCHAR(191) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_admins' AND COLUMN_NAME = 'password_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_admins` ADD COLUMN `password_hash` VARCHAR(255) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_admins' AND COLUMN_NAME = 'name');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_admins` ADD COLUMN `name` VARCHAR(200) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_admins' AND COLUMN_NAME = 'role');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_admins` ADD COLUMN `role` ENUM(''superadmin'',''admin'',''editor'',''moderator'',''judge'',''viewer'') NOT NULL DEFAULT ''editor''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_admins' AND COLUMN_NAME = 'avatar_path');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_admins` ADD COLUMN `avatar_path` VARCHAR(400) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_admins' AND COLUMN_NAME = 'is_active');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_admins` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_admins' AND COLUMN_NAME = 'last_login_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_admins` ADD COLUMN `last_login_at` TIMESTAMP NULL DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_admins' AND COLUMN_NAME = 'last_login_ip');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_admins` ADD COLUMN `last_login_ip` VARCHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_admins' AND COLUMN_NAME = 'failed_attempts');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_admins` ADD COLUMN `failed_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_admins' AND COLUMN_NAME = 'locked_until');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_admins` ADD COLUMN `locked_until` DATETIME DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_admins' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_admins` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_admins' AND COLUMN_NAME = 'updated_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_admins` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_magic_links' AND COLUMN_NAME = 'email');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_magic_links` ADD COLUMN `email` VARCHAR(191) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_magic_links' AND COLUMN_NAME = 'token_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_magic_links` ADD COLUMN `token_hash` VARCHAR(128) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_magic_links' AND COLUMN_NAME = 'purpose');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_magic_links` ADD COLUMN `purpose` ENUM(''admin_login'',''password_reset'') NOT NULL DEFAULT ''admin_login''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_magic_links' AND COLUMN_NAME = 'expires_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_magic_links` ADD COLUMN `expires_at` DATETIME NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_magic_links' AND COLUMN_NAME = 'used_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_magic_links` ADD COLUMN `used_at` TIMESTAMP NULL DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_magic_links' AND COLUMN_NAME = 'ip_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_magic_links` ADD COLUMN `ip_hash` VARCHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_magic_links' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_magic_links` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_audit_log' AND COLUMN_NAME = 'admin_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_audit_log` ADD COLUMN `admin_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_audit_log' AND COLUMN_NAME = 'action');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_audit_log` ADD COLUMN `action` VARCHAR(100) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_audit_log' AND COLUMN_NAME = 'target_type');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_audit_log` ADD COLUMN `target_type` VARCHAR(50) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_audit_log' AND COLUMN_NAME = 'target_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_audit_log` ADD COLUMN `target_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_audit_log' AND COLUMN_NAME = 'meta');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_audit_log` ADD COLUMN `meta` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_audit_log' AND COLUMN_NAME = 'ip_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_audit_log` ADD COLUMN `ip_hash` VARCHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_audit_log' AND COLUMN_NAME = 'ua');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_audit_log` ADD COLUMN `ua` VARCHAR(250) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_audit_log' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_audit_log` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judges' AND COLUMN_NAME = 'admin_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judges` ADD COLUMN `admin_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judges' AND COLUMN_NAME = 'name');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judges` ADD COLUMN `name` VARCHAR(200) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judges' AND COLUMN_NAME = 'email');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judges` ADD COLUMN `email` VARCHAR(191) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judges' AND COLUMN_NAME = 'title');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judges` ADD COLUMN `title` VARCHAR(200) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judges' AND COLUMN_NAME = 'organisation');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judges` ADD COLUMN `organisation` VARCHAR(200) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judges' AND COLUMN_NAME = 'bio');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judges` ADD COLUMN `bio` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judges' AND COLUMN_NAME = 'avatar_path');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judges` ADD COLUMN `avatar_path` VARCHAR(400) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judges' AND COLUMN_NAME = 'country_code');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judges` ADD COLUMN `country_code` CHAR(2) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judges' AND COLUMN_NAME = 'programme_ids');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judges` ADD COLUMN `programme_ids` VARCHAR(500) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judges' AND COLUMN_NAME = 'is_active');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judges` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judges' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judges` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_scores' AND COLUMN_NAME = 'judge_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_scores` ADD COLUMN `judge_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_scores' AND COLUMN_NAME = 'nominee_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_scores` ADD COLUMN `nominee_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_scores' AND COLUMN_NAME = 'category_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_scores` ADD COLUMN `category_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_scores' AND COLUMN_NAME = 'score');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_scores` ADD COLUMN `score` TINYINT NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_scores' AND COLUMN_NAME = 'notes');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_scores` ADD COLUMN `notes` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_scores' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_scores` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_settings' AND COLUMN_NAME = 'key_name');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_settings` ADD COLUMN `key_name` VARCHAR(100) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_settings' AND COLUMN_NAME = 'value');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_settings` ADD COLUMN `value` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_settings' AND COLUMN_NAME = 'updated_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_settings` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_settings' AND COLUMN_NAME = 'updated_by');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_settings` ADD COLUMN `updated_by` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_uploads' AND COLUMN_NAME = 'uploader_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_uploads` ADD COLUMN `uploader_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_uploads' AND COLUMN_NAME = 'uploader_type');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_uploads` ADD COLUMN `uploader_type` ENUM(''admin'',''public'',''system'') NOT NULL DEFAULT ''admin''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_uploads' AND COLUMN_NAME = 'path');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_uploads` ADD COLUMN `path` VARCHAR(500) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_uploads' AND COLUMN_NAME = 'mime');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_uploads` ADD COLUMN `mime` VARCHAR(60) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_uploads' AND COLUMN_NAME = 'size_bytes');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_uploads` ADD COLUMN `size_bytes` INT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_uploads' AND COLUMN_NAME = 'width');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_uploads` ADD COLUMN `width` INT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_uploads' AND COLUMN_NAME = 'height');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_uploads` ADD COLUMN `height` INT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_uploads' AND COLUMN_NAME = 'alt');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_uploads` ADD COLUMN `alt` VARCHAR(250) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_uploads' AND COLUMN_NAME = 'attached_to_type');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_uploads` ADD COLUMN `attached_to_type` VARCHAR(50) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_uploads' AND COLUMN_NAME = 'attached_to_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_uploads` ADD COLUMN `attached_to_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_uploads' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_uploads` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_webhooks' AND COLUMN_NAME = 'url');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_webhooks` ADD COLUMN `url` VARCHAR(500) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_webhooks' AND COLUMN_NAME = 'secret');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_webhooks` ADD COLUMN `secret` VARCHAR(120) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_webhooks' AND COLUMN_NAME = 'events');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_webhooks` ADD COLUMN `events` TEXT NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_webhooks' AND COLUMN_NAME = 'description');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_webhooks` ADD COLUMN `description` VARCHAR(200) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_webhooks' AND COLUMN_NAME = 'is_active');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_webhooks` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_webhooks' AND COLUMN_NAME = 'last_status');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_webhooks` ADD COLUMN `last_status` INT DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_webhooks' AND COLUMN_NAME = 'last_event_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_webhooks` ADD COLUMN `last_event_at` TIMESTAMP NULL DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_webhooks' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_webhooks` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_webhook_deliveries' AND COLUMN_NAME = 'webhook_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_webhook_deliveries` ADD COLUMN `webhook_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_webhook_deliveries' AND COLUMN_NAME = 'event');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_webhook_deliveries` ADD COLUMN `event` VARCHAR(60) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_webhook_deliveries' AND COLUMN_NAME = 'status_code');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_webhook_deliveries` ADD COLUMN `status_code` INT DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_webhook_deliveries' AND COLUMN_NAME = 'ok');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_webhook_deliveries` ADD COLUMN `ok` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_webhook_deliveries' AND COLUMN_NAME = 'error');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_webhook_deliveries` ADD COLUMN `error` VARCHAR(300) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_webhook_deliveries' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_webhook_deliveries` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_criteria' AND COLUMN_NAME = 'programme_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_criteria` ADD COLUMN `programme_id` TINYINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_criteria' AND COLUMN_NAME = 'slug');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_criteria` ADD COLUMN `slug` VARCHAR(60) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_criteria' AND COLUMN_NAME = 'label');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_criteria` ADD COLUMN `label` VARCHAR(120) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_criteria' AND COLUMN_NAME = 'description');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_criteria` ADD COLUMN `description` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_criteria' AND COLUMN_NAME = 'weight');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_criteria` ADD COLUMN `weight` TINYINT UNSIGNED NOT NULL DEFAULT 25');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_criteria' AND COLUMN_NAME = 'sort_order');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_criteria` ADD COLUMN `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_criteria' AND COLUMN_NAME = 'is_active');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_criteria` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_criteria_scores' AND COLUMN_NAME = 'judge_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_criteria_scores` ADD COLUMN `judge_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_criteria_scores' AND COLUMN_NAME = 'nominee_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_criteria_scores` ADD COLUMN `nominee_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_criteria_scores' AND COLUMN_NAME = 'category_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_criteria_scores` ADD COLUMN `category_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_criteria_scores' AND COLUMN_NAME = 'criterion_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_criteria_scores` ADD COLUMN `criterion_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_criteria_scores' AND COLUMN_NAME = 'score');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_criteria_scores` ADD COLUMN `score` TINYINT NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_criteria_scores' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_criteria_scores` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_criteria_scores' AND COLUMN_NAME = 'updated_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_criteria_scores` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_notes' AND COLUMN_NAME = 'judge_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_notes` ADD COLUMN `judge_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_notes' AND COLUMN_NAME = 'nominee_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_notes` ADD COLUMN `nominee_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_notes' AND COLUMN_NAME = 'notes');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_notes` ADD COLUMN `notes` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_notes' AND COLUMN_NAME = 'submitted_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_notes` ADD COLUMN `submitted_at` TIMESTAMP NULL DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_notes' AND COLUMN_NAME = 'updated_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_notes` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_coi' AND COLUMN_NAME = 'judge_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_coi` ADD COLUMN `judge_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_coi' AND COLUMN_NAME = 'programme_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_coi` ADD COLUMN `programme_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_coi' AND COLUMN_NAME = 'reason');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_coi` ADD COLUMN `reason` VARCHAR(500) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_judge_coi' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_judge_coi` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_comments' AND COLUMN_NAME = 'target_type');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_comments` ADD COLUMN `target_type` ENUM(''profile'',''legacy'',''thread'',''nominee'') NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_comments' AND COLUMN_NAME = 'target_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_comments` ADD COLUMN `target_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_comments' AND COLUMN_NAME = 'parent_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_comments` ADD COLUMN `parent_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_comments' AND COLUMN_NAME = 'author_name');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_comments` ADD COLUMN `author_name` VARCHAR(200) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_comments' AND COLUMN_NAME = 'author_email');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_comments` ADD COLUMN `author_email` VARCHAR(191) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_comments' AND COLUMN_NAME = 'author_email_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_comments` ADD COLUMN `author_email_hash` VARCHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_comments' AND COLUMN_NAME = 'body');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_comments` ADD COLUMN `body` TEXT NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_comments' AND COLUMN_NAME = 'status');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_comments` ADD COLUMN `status` ENUM(''approved'',''quarantined'',''rejected'',''deleted'') NOT NULL DEFAULT ''approved''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_comments' AND COLUMN_NAME = 'ai_score');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_comments` ADD COLUMN `ai_score` DECIMAL(4,3) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_comments' AND COLUMN_NAME = 'ai_reason');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_comments` ADD COLUMN `ai_reason` VARCHAR(500) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_comments' AND COLUMN_NAME = 'ip_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_comments` ADD COLUMN `ip_hash` VARCHAR(64) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_comments' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_comments` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cheers' AND COLUMN_NAME = 'target_type');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cheers` ADD COLUMN `target_type` ENUM(''profile'',''nominee'',''comment'',''thread'') NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cheers' AND COLUMN_NAME = 'target_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cheers` ADD COLUMN `target_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cheers' AND COLUMN_NAME = 'fp');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cheers` ADD COLUMN `fp` VARCHAR(64) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_cheers' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_cheers` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_activity' AND COLUMN_NAME = 'kind');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_activity` ADD COLUMN `kind` ENUM(''vote'',''nomination'',''register'',''comment'',''cheer'',''winner'',''legacy'',''opportunity'') NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_activity' AND COLUMN_NAME = 'actor_label');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_activity` ADD COLUMN `actor_label` VARCHAR(200) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_activity' AND COLUMN_NAME = 'target_type');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_activity` ADD COLUMN `target_type` VARCHAR(50) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_activity' AND COLUMN_NAME = 'target_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_activity` ADD COLUMN `target_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_activity' AND COLUMN_NAME = 'target_label');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_activity` ADD COLUMN `target_label` VARCHAR(250) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_activity' AND COLUMN_NAME = 'meta');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_activity` ADD COLUMN `meta` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_activity' AND COLUMN_NAME = 'is_public');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_activity` ADD COLUMN `is_public` TINYINT(1) NOT NULL DEFAULT 1');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_activity' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_activity` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_threads' AND COLUMN_NAME = 'programme_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_threads` ADD COLUMN `programme_id` TINYINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_threads' AND COLUMN_NAME = 'slug');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_threads` ADD COLUMN `slug` VARCHAR(191) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_threads' AND COLUMN_NAME = 'title');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_threads` ADD COLUMN `title` VARCHAR(250) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_threads' AND COLUMN_NAME = 'body');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_threads` ADD COLUMN `body` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_threads' AND COLUMN_NAME = 'author_name');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_threads` ADD COLUMN `author_name` VARCHAR(200) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_threads' AND COLUMN_NAME = 'author_email_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_threads` ADD COLUMN `author_email_hash` VARCHAR(64) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_threads' AND COLUMN_NAME = 'status');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_threads` ADD COLUMN `status` ENUM(''approved'',''quarantined'',''rejected'',''deleted'',''locked'') NOT NULL DEFAULT ''approved''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_threads' AND COLUMN_NAME = 'ai_score');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_threads` ADD COLUMN `ai_score` DECIMAL(4,3) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_threads' AND COLUMN_NAME = 'reply_count');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_threads` ADD COLUMN `reply_count` INT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_threads' AND COLUMN_NAME = 'cheer_count');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_threads` ADD COLUMN `cheer_count` INT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_threads' AND COLUMN_NAME = 'repost_count');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_threads` ADD COLUMN `repost_count` INT UNSIGNED NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_threads' AND COLUMN_NAME = 'last_activity');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_threads` ADD COLUMN `last_activity` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_threads' AND COLUMN_NAME = 'is_pinned');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_threads` ADD COLUMN `is_pinned` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_threads' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_threads` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_moderation_log' AND COLUMN_NAME = 'target_type');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_moderation_log` ADD COLUMN `target_type` VARCHAR(50) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_moderation_log' AND COLUMN_NAME = 'target_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_moderation_log` ADD COLUMN `target_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_moderation_log' AND COLUMN_NAME = 'provider');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_moderation_log` ADD COLUMN `provider` VARCHAR(30) NOT NULL DEFAULT ''heuristic''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_moderation_log' AND COLUMN_NAME = 'decision');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_moderation_log` ADD COLUMN `decision` ENUM(''allow'',''quarantine'',''reject'') NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_moderation_log' AND COLUMN_NAME = 'score');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_moderation_log` ADD COLUMN `score` DECIMAL(4,3) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_moderation_log' AND COLUMN_NAME = 'reason');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_moderation_log` ADD COLUMN `reason` VARCHAR(500) DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_moderation_log' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_moderation_log` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_polls' AND COLUMN_NAME = 'target_type');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_polls` ADD COLUMN `target_type` VARCHAR(16) NOT NULL DEFAULT ''thread''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_polls' AND COLUMN_NAME = 'target_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_polls` ADD COLUMN `target_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_polls' AND COLUMN_NAME = 'question');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_polls` ADD COLUMN `question` VARCHAR(255) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_polls' AND COLUMN_NAME = 'options');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_polls` ADD COLUMN `options` TEXT NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_polls' AND COLUMN_NAME = 'multi');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_polls` ADD COLUMN `multi` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_polls' AND COLUMN_NAME = 'is_closed');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_polls` ADD COLUMN `is_closed` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_polls' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_polls` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_poll_votes' AND COLUMN_NAME = 'poll_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_poll_votes` ADD COLUMN `poll_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_poll_votes' AND COLUMN_NAME = 'option_index');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_poll_votes` ADD COLUMN `option_index` INT NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_poll_votes' AND COLUMN_NAME = 'fp');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_poll_votes` ADD COLUMN `fp` VARCHAR(64) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_poll_votes' AND COLUMN_NAME = 'user_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_poll_votes` ADD COLUMN `user_id` BIGINT UNSIGNED DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_poll_votes' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_poll_votes` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_follows' AND COLUMN_NAME = 'user_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_follows` ADD COLUMN `user_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_follows' AND COLUMN_NAME = 'target_type');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_follows` ADD COLUMN `target_type` VARCHAR(20) NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_follows' AND COLUMN_NAME = 'target_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_follows` ADD COLUMN `target_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_follows' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_follows` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_bookmarks' AND COLUMN_NAME = 'user_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_bookmarks` ADD COLUMN `user_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_bookmarks' AND COLUMN_NAME = 'thread_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_bookmarks` ADD COLUMN `thread_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_bookmarks' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_bookmarks` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_reposts' AND COLUMN_NAME = 'user_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_reposts` ADD COLUMN `user_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_reposts' AND COLUMN_NAME = 'thread_id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_reposts` ADD COLUMN `thread_id` BIGINT UNSIGNED NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_reposts' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_reposts` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_orders' AND COLUMN_NAME = 'id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_orders` ADD COLUMN `id` BIGINT DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_orders' AND COLUMN_NAME = 'reference');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_orders` ADD COLUMN `reference` TEXT NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_orders' AND COLUMN_NAME = 'email');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_orders` ADD COLUMN `email` TEXT NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_orders' AND COLUMN_NAME = 'name');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_orders` ADD COLUMN `name` TEXT NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_orders' AND COLUMN_NAME = 'phone');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_orders` ADD COLUMN `phone` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_orders' AND COLUMN_NAME = 'address');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_orders` ADD COLUMN `address` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_orders' AND COLUMN_NAME = 'items_json');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_orders` ADD COLUMN `items_json` TEXT NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_orders' AND COLUMN_NAME = 'subtotal_naira');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_orders` ADD COLUMN `subtotal_naira` BIGINT DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_orders' AND COLUMN_NAME = 'status');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_orders` ADD COLUMN `status` VARCHAR(255) DEFAULT ''pending''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_orders' AND COLUMN_NAME = 'provider');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_orders` ADD COLUMN `provider` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_orders' AND COLUMN_NAME = 'provider_ref');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_orders` ADD COLUMN `provider_ref` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_orders' AND COLUMN_NAME = 'ip_hash');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_orders` ADD COLUMN `ip_hash` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_orders' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_orders` ADD COLUMN `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_orders' AND COLUMN_NAME = 'paid_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_orders` ADD COLUMN `paid_at` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_products' AND COLUMN_NAME = 'id');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_products` ADD COLUMN `id` BIGINT DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_products' AND COLUMN_NAME = 'slug');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_products` ADD COLUMN `slug` TEXT NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_products' AND COLUMN_NAME = 'name');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_products` ADD COLUMN `name` TEXT NOT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_products' AND COLUMN_NAME = 'category');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_products` ADD COLUMN `category` VARCHAR(255) DEFAULT ''Apparel''');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_products' AND COLUMN_NAME = 'description');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_products` ADD COLUMN `description` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_products' AND COLUMN_NAME = 'price_naira');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_products` ADD COLUMN `price_naira` BIGINT DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_products' AND COLUMN_NAME = 'cover_path');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_products` ADD COLUMN `cover_path` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_products' AND COLUMN_NAME = 'tag');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_products` ADD COLUMN `tag` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_products' AND COLUMN_NAME = 'stock');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_products` ADD COLUMN `stock` BIGINT DEFAULT NULL');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_products' AND COLUMN_NAME = 'is_active');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_products` ADD COLUMN `is_active` BIGINT DEFAULT 1');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_products' AND COLUMN_NAME = 'sort_order');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_products` ADD COLUMN `sort_order` BIGINT DEFAULT 0');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_products' AND COLUMN_NAME = 'created_at');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_products` ADD COLUMN `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_products' AND COLUMN_NAME = 'delivery_regions');
SET @s := IF(@c > 0, 'SELECT 1', 'ALTER TABLE `gates_products` ADD COLUMN `delivery_regions` TEXT');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;

-- ---------- 3. Widen the admin role list to include 'moderator'/'editor' (MySQL ENUM) ----------
SET @eng := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_admins');
-- Only attempt the MODIFY when the table exists; harmless re-statement of the full enum.
SET @s := IF(@eng > 0, "ALTER TABLE `gates_admins` MODIFY `role` ENUM('superadmin','admin','editor','moderator','judge','viewer') NOT NULL DEFAULT 'editor'", 'SELECT 1');
PREPARE _st FROM @s; EXECUTE _st; DEALLOCATE PREPARE _st;

SET FOREIGN_KEY_CHECKS = 1;
-- Done. 59 base tables, 2 supplemental, 536 column checks.
