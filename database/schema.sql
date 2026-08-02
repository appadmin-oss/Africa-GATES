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
  merged_into BIGINT UNSIGNED DEFAULT NULL,
  merged_at TIMESTAMP NULL DEFAULT NULL,
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
  status ENUM('upcoming','nominations','shortlisting','voting','judging','results','archived') NOT NULL DEFAULT 'upcoming',
  -- The next declared boundary this cycle is waiting on. A computed phase
  -- cannot be indexed (NOW() is non-deterministic and rejected in generated
  -- columns, and MySQL builds functional indexes as hidden generated columns),
  -- so this materialises the one question an operator needs indexed: which
  -- cycles need attention right now.
  next_boundary_at DATETIME NULL DEFAULT NULL,
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
  country_code CHAR(2) DEFAULT NULL,
  -- School / organisation, carried across from the nomination on approval.
  organisation VARCHAR(200) DEFAULT NULL,
  vote_count INT UNSIGNED NOT NULL DEFAULT 0,
  organic_vote_count INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('pending','approved','winner','runner_up') NOT NULL DEFAULT 'pending',
  merged_into BIGINT UNSIGNED DEFAULT NULL,
  merged_at TIMESTAMP NULL DEFAULT NULL,
  nominated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_category(category_id), KEY idx_votes(vote_count DESC), KEY idx_merged_into(merged_into),
  CONSTRAINT fk_nominee_cat FOREIGN KEY(category_id) REFERENCES gates_award_categories(id) ON DELETE CASCADE,
  CONSTRAINT fk_nominee_profile FOREIGN KEY(profile_id) REFERENCES gates_profiles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-row undo journal for nominee merges: every reassigned row (old value) and
-- every collision-dropped row (full snapshot) so an unmerge restores exactly.
CREATE TABLE IF NOT EXISTS gates_merge_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch VARCHAR(40) NOT NULL,
  keep_id BIGINT UNSIGNED NOT NULL,
  merged_id BIGINT UNSIGNED NOT NULL,
  op ENUM('reassign','delete') NOT NULL,
  tbl VARCHAR(64) NOT NULL,
  row_pk BIGINT UNSIGNED DEFAULT NULL,
  col VARCHAR(64) DEFAULT NULL,
  old_val VARCHAR(64) DEFAULT NULL,
  snapshot TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_merge_log_merged(merged_id), KEY idx_merge_log_batch(batch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Same undo journal, for registry-profile merges (see gates_merge_log).
CREATE TABLE IF NOT EXISTS gates_profile_merge_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch VARCHAR(40) NOT NULL,
  keep_id BIGINT UNSIGNED NOT NULL,
  merged_id BIGINT UNSIGNED NOT NULL,
  op ENUM('reassign','delete') NOT NULL,
  tbl VARCHAR(64) NOT NULL,
  row_pk BIGINT UNSIGNED DEFAULT NULL,
  col VARCHAR(64) DEFAULT NULL,
  old_val VARCHAR(64) DEFAULT NULL,
  snapshot TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_pmerge_log_merged(merged_id), KEY idx_pmerge_log_batch(batch)
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
  -- Consent to appear on the PUBLIC supporters list. 0 unless the voter ticked the
  -- box, so a name collected for a receipt is never published by default.
  show_name TINYINT(1) NOT NULL DEFAULT 0,
  vote_type ENUM('standard','bonus','paid') NOT NULL DEFAULT 'standard',
  -- INT, not SMALLINT. A paid-vote order mints ONE row with weight = quantity, so
  -- SMALLINT's 65,535 was the real (and unmeasured) ceiling on a bulk purchase — and
  -- on a host that overrides sql_mode away from strict, MySQL would have CLAMPED to it
  -- and reported success, crediting 65,535 votes for an order of 100,000. Matches
  -- gates_nominees.vote_count and gates_donations.bonus_votes, which were already INT.
  weight INT UNSIGNED NOT NULL DEFAULT 1,
  donation_id BIGINT UNSIGNED DEFAULT NULL,
  risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  fraud_flag TINYINT(1) NOT NULL DEFAULT 0,
  voted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), UNIQUE KEY uq_one_vote(voter_email_hash,category_id),
  UNIQUE KEY uq_votes_idem(voter_email_hash,idempotency_key),
  KEY idx_nominee(nominee_id), KEY idx_voted_at(voted_at), KEY idx_votes_device(device_hash),
  -- Read on every paid-vote clawback, which scans by donation_id. It was only
  -- ever created by a catch-up migration whose CREATE INDEX IF NOT EXISTS is
  -- MySQL-invalid, so it existed on no MySQL install at all until now.
  KEY idx_votes_donation(donation_id),
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
  boundary_at DATETIME NULL DEFAULT NULL, observed_at DATETIME NULL DEFAULT NULL,
  notify TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_cyctrans_cycle (cycle_id),
  -- The INSERT is the claim: exactly one caller records a phase entry and
  -- fires its side effects, even when two schedulers run concurrently.
  UNIQUE KEY uq_cyctrans_phase (cycle_id, to_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gates_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, type VARCHAR(80) NOT NULL,
  payload JSON DEFAULT NULL, status ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  run_after TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, locked_at TIMESTAMP NULL DEFAULT NULL,
  last_error VARCHAR(500) DEFAULT NULL,
  dedupe_key VARCHAR(191) NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_jobs_due (status, run_after),
  -- The outbox delivers at-least-once, so anything with a user-visible effect
  -- needs a way to refuse a duplicate enqueue.
  UNIQUE KEY uq_jobs_dedupe (dedupe_key)
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
  nominee_org VARCHAR(200) DEFAULT NULL,
  nominee_phone VARCHAR(40) DEFAULT NULL,
  nominee_photo_path VARCHAR(400) DEFAULT NULL,
  reference VARCHAR(24) DEFAULT NULL,
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
  nominator_age_range VARCHAR(20) DEFAULT NULL,
  decision_reason TEXT,
  nominator_ack_at TIMESTAMP NULL DEFAULT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  ip_hash VARCHAR(64) DEFAULT NULL,
  device_fp VARCHAR(64) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_cycle(cycle_id), KEY idx_status(status), KEY idx_nominations_device(device_fp),
  UNIQUE KEY uq_nom_reference(reference),
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

-- Shareable prefill nomination links: an opaque high-entropy token maps to a
-- nominee-side payload (JSON) that prefills the wizard for whoever opens it.
-- PII stays server-side behind the token; links expire and count their hits.
CREATE TABLE IF NOT EXISTS gates_nomination_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token VARCHAR(64) NOT NULL,
  payload TEXT NOT NULL,
  created_ip_hash VARCHAR(64) DEFAULT NULL,
  created_by BIGINT UNSIGNED DEFAULT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  expires_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), UNIQUE KEY uq_nomlink_token(token), KEY idx_nomlink_expires(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Editable legal / policy documents (privacy, terms, cookies, + custom).
-- Replaces the hardcoded copy that used to live in templates/pages/legal.twig
-- so operators can edit policies from the admin without a deploy.
CREATE TABLE IF NOT EXISTS gates_legal_docs (
  slug VARCHAR(60) NOT NULL,
  title VARCHAR(160) NOT NULL,
  body_html MEDIUMTEXT,
  updated_label VARCHAR(60) DEFAULT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  updated_by BIGINT UNSIGNED DEFAULT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(slug), KEY idx_legal_pub(is_published, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI triage for nomination review at scale: one advisory row per nomination
-- (quality score, summary, duplicate hints). NEVER auto-approves/rejects —
-- operators decide; this only helps them decide faster and more accurately.
CREATE TABLE IF NOT EXISTS gates_nomination_insights (
  nomination_id BIGINT UNSIGNED NOT NULL,
  quality_score TINYINT UNSIGNED DEFAULT NULL, -- 0-100 advisory
  summary TEXT,
  duplicates_json TEXT,
  model VARCHAR(40) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(nomination_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email delivery audit: one row per attempted send (recipient masked).
-- Powers the admin Email-health card so "emails are not arriving" is
-- diagnosable in one glance instead of silent.
CREATE TABLE IF NOT EXISTS gates_mail_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  to_masked VARCHAR(120) NOT NULL,
  subject VARCHAR(200) NOT NULL,
  category VARCHAR(40) DEFAULT NULL,
  status ENUM('sent','failed','logged_dev') NOT NULL,
  error VARCHAR(300) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_mail_created(created_at), KEY idx_mail_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outbound SMS / WhatsApp delivery audit (recipients stored hashed + masked, never raw).
CREATE TABLE IF NOT EXISTS gates_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  channel ENUM('sms','whatsapp') NOT NULL,
  to_hash VARCHAR(64) NOT NULL,
  to_masked VARCHAR(24) NOT NULL,
  template VARCHAR(60) NOT NULL DEFAULT 'generic',
  status ENUM('sent','failed','queued') NOT NULL,
  provider VARCHAR(20) DEFAULT NULL,
  provider_ref VARCHAR(80) DEFAULT NULL,
  error VARCHAR(300) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_messages_created(created_at), KEY idx_messages_status(status)
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
  intent_nominee_id BIGINT UNSIGNED DEFAULT NULL, -- paid-vote orders: auto-mint target on confirm
  payment_ref VARCHAR(200) DEFAULT NULL,
  status ENUM('pending','confirmed','failed') NOT NULL DEFAULT 'pending',
  -- The buyer's answer to "show my name publicly", carried through the gateway
  -- round-trip and copied onto the vote at mint. Default 0 = private.
  show_name TINYINT(1) NOT NULL DEFAULT 0,
  refunded_at TIMESTAMP NULL DEFAULT NULL,
  -- Automatic-refund bookkeeping. `refund_requested_at` is the CLAIM stamp,
  -- written before the gateway is called so two workers can never both refund
  -- the same order — see AfricaGates\Services\RefundService.
  refund_state VARCHAR(16) DEFAULT NULL,
  refund_ref VARCHAR(120) DEFAULT NULL,
  refund_reason VARCHAR(255) DEFAULT NULL,
  refund_requested_at TIMESTAMP NULL DEFAULT NULL,
  -- Send-exactly-once claim stamps. Both emails have more than one caller racing
  -- to send them (callback vs webhook; every maintenance tick), so the claim is a
  -- guarded UPDATE on a NULL column. See CheckoutMailer.
  receipt_sent_at TIMESTAMP NULL DEFAULT NULL,
  abandoned_mail_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_donation_email(donor_email),
  KEY idx_donation_status(status),
  KEY idx_donations_abandon(status, abandoned_mail_at, created_at),
  KEY idx_donation_refundable(status, tier, votes_used, refund_requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE: the gates_nominations location + reference columns (nominee_state,
-- nominee_lga, reference_url[_2/_3], nominator_phone/location/country/state/lga)
-- are added by the idempotent, driver-aware migration
--   database/migrations/2026_06_30_nomination_location_columns.php
-- A raw `ALTER TABLE … ADD COLUMN IF NOT EXISTS …` was REMOVED from here because
-- that syntax is MariaDB-only and a hard error on Oracle MySQL — it aborted the
-- entire schema apply (and every later migration) on MySQL hosts.

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

-- ─── Event registrations (on-platform RSVP for site events) ───
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

-- ─── Gated single-use form links (verified nominees + judge invites) ───
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

-- ─── Form builder (admin-designed forms + submissions) ───
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

-- ─── User accounts + voting-points ledger ───
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
  -- How far this member has read their alerts. The ONLY state the alerts
  -- feature stores: everything else is derived from the tables that already
  -- record the events. NULL = never opened = everything unread.
  alerts_read_at DATETIME NULL DEFAULT NULL,
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

-- ─── Blog posts ───
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

-- Shadow-mode ledger for the COMPUTED cycle phase (see AfricaGates\Services\BallotGuard).
-- One row whenever the computed phase and the stored gates_award_cycles.status
-- disagree about whether a vote/nomination may proceed, so a mis-configured
-- live cycle surfaces to an operator instead of silently mis-gating traffic.
CREATE TABLE IF NOT EXISTS gates_phase_drift (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cycle_id BIGINT UNSIGNED NOT NULL,
  action ENUM('vote','nominate') NOT NULL DEFAULT 'vote',
  computed_phase VARCHAR(20) NOT NULL,
  stored_status VARCHAR(20) NOT NULL,
  would_allow TINYINT(1) NOT NULL DEFAULT 0,
  phase_allows TINYINT(1) NOT NULL DEFAULT 0,
  mode VARCHAR(10) NOT NULL DEFAULT 'strict',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_drift_cycle (cycle_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI audit log. Every model call, whatever the outcome: which capability ran,
-- which provider answered, tokens spent, and what happened. The prompt itself is
-- NOT stored — only a hash — so the log does not become a second copy of every
-- nominator's free text. Budgets are enforced against this table, so the spend
-- figure and the record can never disagree.
CREATE TABLE IF NOT EXISTS gates_ai_calls (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  capability VARCHAR(60) NOT NULL,
  purpose VARCHAR(20) DEFAULT NULL,
  provider VARCHAR(20) DEFAULT NULL,
  model VARCHAR(80) DEFAULT NULL,
  subject_type VARCHAR(40) DEFAULT NULL,
  subject_id BIGINT UNSIGNED DEFAULT NULL,
  input_hash CHAR(64) DEFAULT NULL,
  output_summary VARCHAR(300) DEFAULT NULL,
  tokens_in INT UNSIGNED NOT NULL DEFAULT 0,
  tokens_out INT UNSIGNED NOT NULL DEFAULT 0,
  latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
  outcome VARCHAR(24) NOT NULL DEFAULT 'OK',
  error VARCHAR(300) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ai_cap_day (capability, created_at),
  KEY idx_ai_subject (subject_type, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What the AI suggested vs what the human decided. gates_ai_calls records that a
-- call happened; this records whether it was any use — the only thing that
-- justifies keeping an advisory AI, and the accountability trail for a decision
-- made with a machine score in front of the reviewer.
CREATE TABLE IF NOT EXISTS gates_ai_decisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  capability VARCHAR(60) NOT NULL,
  subject_type VARCHAR(40) NOT NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  suggested VARCHAR(120) DEFAULT NULL,
  decided VARCHAR(120) NOT NULL,
  agreed TINYINT(1) DEFAULT NULL,
  actor_id BIGINT UNSIGNED DEFAULT NULL,
  note VARCHAR(300) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_aidec_cap_day (capability, created_at),
  KEY idx_aidec_subject (subject_type, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The reconciliation audit trail. One row per RUN of the payment reconciler:
-- who ran it, in which mode, and what the gateway said at the time. A finance
-- correction with no trail is indistinguishable from tampering, and this became
-- load-bearing the moment an admin (not just cron) could press the button.
CREATE TABLE IF NOT EXISTS gates_reconciliation_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ran_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor VARCHAR(120) NOT NULL DEFAULT 'system',
  mode VARCHAR(10) NOT NULL DEFAULT 'check',
  checked INT UNSIGNED NOT NULL DEFAULT 0,
  confirmed INT UNSIGNED NOT NULL DEFAULT 0,
  failed INT UNSIGNED NOT NULL DEFAULT 0,
  mismatch INT UNSIGNED NOT NULL DEFAULT 0,
  unverifiable INT UNSIGNED NOT NULL DEFAULT 0,
  naira BIGINT UNSIGNED NOT NULL DEFAULT 0,
  detail_json LONGTEXT,
  PRIMARY KEY(id),
  KEY idx_recon_ran (ran_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shop orders. Lived only in the 2026_06_22_shop migration, so a database built from
-- this file alone had no shop table at all. Byte-compatible with that migration,
-- which is idempotent and still safe to run on an existing install.
CREATE TABLE IF NOT EXISTS gates_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference VARCHAR(64) NOT NULL,
  email VARCHAR(190) NOT NULL,
  name VARCHAR(160) NOT NULL,
  phone VARCHAR(40) DEFAULT NULL,
  address TEXT,
  items_json TEXT NOT NULL,
  subtotal_naira INT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  provider VARCHAR(30) DEFAULT NULL,
  provider_ref VARCHAR(120) DEFAULT NULL,
  ip_hash VARCHAR(64) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_ref (reference),
  KEY idx_order_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shop catalogue. Same gap gates_orders had: it lived only in the 2026_06_22_shop
-- migration, so a database built from this file had orders with no products for them
-- to reference. Found by diffing a fresh schema build against a migrated one.
CREATE TABLE IF NOT EXISTS gates_products (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(160) NOT NULL,
  name VARCHAR(200) NOT NULL,
  category VARCHAR(80) NOT NULL DEFAULT 'Apparel',
  description TEXT,
  price_naira INT UNSIGNED NOT NULL DEFAULT 0,
  cover_path VARCHAR(400) DEFAULT NULL,
  tag VARCHAR(40) DEFAULT NULL,
  stock INT DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  delivery_regions TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_slug (slug),
  KEY idx_product_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SUPPORT DESK ────────────────────────────────────────────────────────────
-- See the note in sqlite-schema.sql: these tables shipped only as migrations, so
-- a database built from this file had a support desk with nothing behind it.
CREATE TABLE IF NOT EXISTS gates_support_tickets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference VARCHAR(24) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  email VARCHAR(255) NULL,
  name VARCHAR(160) NULL,
  subject VARCHAR(255) NOT NULL,
  transcript MEDIUMTEXT NULL,
  tools_used VARCHAR(255) NULL,
  severity VARCHAR(16) NOT NULL DEFAULT 'normal',
  status VARCHAR(16) NOT NULL DEFAULT 'open',
  emailed TINYINT(1) NOT NULL DEFAULT 0,
  webhooked TINYINT(1) NOT NULL DEFAULT 0,
  page_url VARCHAR(500) NULL,
  user_agent VARCHAR(255) NULL,
  ip_hash CHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_activity TIMESTAMP NULL,
  resolved_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_ref (reference),
  KEY idx_ticket_status (status),
  KEY idx_ticket_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_support_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id BIGINT UNSIGNED NOT NULL,
  author_type VARCHAR(12) NOT NULL DEFAULT 'member',
  author_id BIGINT UNSIGNED NULL,
  author_name VARCHAR(160) NULL,
  body MEDIUMTEXT NOT NULL,
  is_internal TINYINT(1) NOT NULL DEFAULT 0,
  emailed TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_smsg_ticket (ticket_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
