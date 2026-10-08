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
  results_date DATETIME DEFAULT NULL,
  -- Why a result is late, in the operator's own words, on the page people are waiting on.
  -- The DELAY itself is derived (a results date that has passed, a cycle not yet
  -- announced) so the site admits it with or without this; the note is the part only a
  -- person can write, and it stops being shown the moment the cycle is announced.
  results_delay_note TEXT NULL DEFAULT NULL,
  -- Which edition this is ("11th Edition"), stored because a programme may have run for
  -- years before it came here; migrations/2027_03_05_edition_number.php, Support\EditionName.
  edition_number SMALLINT UNSIGNED NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
  -- The nominator's full case for this person. `tagline` is the SHORT line a
  -- leaderboard row, card or flier needs; this is what the ballot prints. Approval
  -- used to keep only the first 200 characters of the story in `tagline` and drop
  -- the rest, so every ballot showed a sentence cut mid-word with no way to read on.
  story TEXT NULL,
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
  -- Which recovery batch put this vote here, if any. NULL on every vote cast the
  -- normal way, so "which votes did we place ourselves" is a one-column question.
  -- A column rather than a new vote_type: a recovered vote IS an ordinary organic
  -- vote, and inventing a type would make every existing query learn about it.
  recovery_batch_id BIGINT UNSIGNED DEFAULT NULL,
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
  PRIMARY KEY (id), KEY idx_snap_cycle (cycle_id), KEY idx_snap_nominee (nominee_id),
  -- A link may be extended exactly once. Two concurrent captures reading the same
  -- tail would otherwise fork the chain, and a forked chain reports itself as
  -- tampered with, permanently and unclearably. NULLs are distinct in a unique
  -- index, so rows predating prev_hash are unaffected.
  UNIQUE KEY uq_snap_prev (prev_hash)
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
  -- Did the code actually leave the building? 'failed' is the platform's own record
  -- that it let this person down, and the only basis on which their dropped vote may
  -- later be recovered. 'unknown' predates the column and is never recoverable.
  delivery_state ENUM('unknown','sent','failed') NOT NULL DEFAULT 'unknown',
  delivery_error VARCHAR(300) NULL,
  delivery_at TIMESTAMP NULL DEFAULT NULL,
  expires_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_email_purpose(email_hash,purpose), KEY idx_expires(expires_at),
  KEY idx_otp_delivery(purpose, delivery_state, is_used)
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
  -- "How do you know them?" (NominationFlow.dc.html step 5): one key of
  -- NominationRules::RELATIONS. For the review desk, in the operator brief.
  nominator_relation VARCHAR(40) DEFAULT NULL,
  -- "Keep my name private from the nominee": the nominee's confirmation names nobody.
  nominator_private TINYINT(1) NOT NULL DEFAULT 0,
  decision_reason TEXT,
  nominator_ack_at TIMESTAMP NULL DEFAULT NULL,
  -- WIDENED FOR CHALLENGES, NEVER REPLACED. The handoff specifies
  -- (draft,submitted,checking,verified,needs_details,rejected); 176 places in src/
  -- read the literal 'approved'. Dropping it would make every one of them match zero
  -- rows, silently, on MySQL. So this is the union, the three live words keep their
  -- original positions (an ENUM reorder rewrites every stored row), and
  -- `Support\NominationStatus` is the one place that knows the two vocabularies
  -- overlap: `approved` is the historic spelling of a moderator pass, and a prize
  -- also needs the nominee's own confirmation.
  status ENUM('pending','approved','rejected','draft','submitted','checking','verified','needs_details') NOT NULL DEFAULT 'pending',
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
  -- The released cycle this night was, and where (2027_03_06_legacy_edition_link.php).
  cycle_id BIGINT UNSIGNED NULL DEFAULT NULL, country_code CHAR(2) NULL DEFAULT NULL,
  PRIMARY KEY(id), UNIQUE KEY uq_slug(slug), KEY idx_published(is_published), KEY idx_date(event_date DESC), KEY idx_legacy_cycle(cycle_id)
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
  -- sent | failed | logged_dev | refused | deferred — Mail\MailLog. VARCHAR, not ENUM:
  -- see 2027_02_20_mail_send_rules.php, which repairs databases built with the ENUM.
  status VARCHAR(16) NOT NULL,
  error VARCHAR(300) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  to_hash CHAR(64) DEFAULT NULL,
  bulk TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY(id), KEY idx_mail_created(created_at), KEY idx_mail_status(status),
  KEY idx_mail_to(to_hash, created_at)
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
  -- The GATEWAY's own identifiers for this payment, captured at confirmation.
  -- `payment_ref`/`reference` above is the reference WE mint and hand to the gateway;
  -- these two are what Paystack's receipt, dashboard and SMS actually show the buyer, so
  -- without them the number in a supporter's hand matches nothing on the platform.
  -- Deliberately NOT unique: a transaction id is unique per gateway, not across them, and
  -- some providers reuse a reference on a retried charge.
  -- See database/migrations/2026_08_26_gateway_reference.php.
  gateway_txn_id VARCHAR(64) DEFAULT NULL,
  gateway_ref VARCHAR(80) DEFAULT NULL,
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
  -- Refusal pacing. A refused refund releases its claim (no money moved, so a
  -- retry is safe) but must NOT be retried on the next 14-minute tick; these
  -- two turn that loop into 1h -> 6h -> 24h and then a stop.
  refund_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  refund_retry_after TIMESTAMP NULL DEFAULT NULL,
  -- The gateway's own answer behind a refund decision. `unreachable` is a
  -- distinct verdict: a confident refusal made out of a network timeout is the
  -- worst thing this column could be used to justify. See RefundDecision.
  gateway_checked_at TIMESTAMP NULL DEFAULT NULL,
  gateway_verdict VARCHAR(24) DEFAULT NULL,
  gateway_evidence TEXT DEFAULT NULL,
  -- WHEN the money arrived, as distinct from when checkout started. The refund
  -- grace window measures this; before the column it measured created_at.
  confirmed_at TIMESTAMP NULL DEFAULT NULL,
  -- WHICH gateway took the money. Without it the reconciler asks every gateway
  -- about every reference, and a refund has to guess where to send cash back to.
  provider VARCHAR(24) DEFAULT NULL,
  -- Stamped when the reconciler gives up on a checkout nobody ever completed.
  -- `status` also becomes 'failed'; this records that TIME decided, not a bank.
  expired_at TIMESTAMP NULL DEFAULT NULL,
  -- Send-exactly-once claim stamps. Both emails have more than one caller racing
  -- to send them (callback vs webhook; every maintenance tick), so the claim is a
  -- guarded UPDATE on a NULL column. See CheckoutMailer.
  receipt_sent_at TIMESTAMP NULL DEFAULT NULL,
  abandoned_mail_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_donation_email(donor_email),
  KEY idx_dona_gwtxn (gateway_txn_id),
  KEY idx_dona_gwref (gateway_ref),
  KEY idx_donation_status(status),
  KEY idx_donations_pending_age(status, created_at),
  KEY idx_donation_refund_retry(refund_state, refund_retry_after),
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
  -- Phase 7: a link out to the broadcast and the recording, and the access provisions
  -- (one per line). See migrations/2027_03_04_event_page_links.php.
  livestream_url VARCHAR(500) NULL DEFAULT NULL,
  recording_url VARCHAR(500) NULL DEFAULT NULL,
  cover_kind VARCHAR(24) NULL DEFAULT NULL,
  spotlight_rank TINYINT UNSIGNED NULL DEFAULT NULL,
  access_notes TEXT NULL,
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

-- ─── Award terms, versioned, and who accepted which version (migrations/2027_03_05_award_terms.php) ───
CREATE TABLE IF NOT EXISTS gates_award_terms (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  programme_id INT UNSIGNED NOT NULL,
  version SMALLINT UNSIGNED NOT NULL,
  body MEDIUMTEXT NOT NULL,
  changelog VARCHAR(500) NULL DEFAULT NULL,
  effective_at DATETIME NOT NULL,
  created_at TIMESTAMP NULL DEFAULT NULL,
  created_by INT UNSIGNED NULL DEFAULT NULL,
  UNIQUE KEY uq_terms_version (programme_id, version),
  KEY idx_terms_effective (programme_id, effective_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS gates_award_terms_acceptance (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  terms_id BIGINT UNSIGNED NOT NULL,
  programme_id INT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL,
  email_hash CHAR(64) NOT NULL,
  subject_id BIGINT UNSIGNED NULL DEFAULT NULL,
  accepted_at DATETIME NOT NULL,
  UNIQUE KEY uq_terms_accept (terms_id, email_hash, kind, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── "Notify me" on a coming-soon award (double opt-in; migrations/2027_03_05_award_alerts.php) ───
CREATE TABLE IF NOT EXISTS gates_award_alerts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  programme_id INT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  email_hash CHAR(64) NOT NULL,
  token CHAR(32) NOT NULL,
  ip_hash CHAR(64) NULL,
  created_at TIMESTAMP NULL DEFAULT NULL,
  confirm_sent_at TIMESTAMP NULL DEFAULT NULL,
  confirmed_at TIMESTAMP NULL DEFAULT NULL,
  notified_at TIMESTAMP NULL DEFAULT NULL,
  cancelled_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uq_awa_who (programme_id, email_hash),
  UNIQUE KEY uq_awa_token (token),
  KEY idx_awa_due (programme_id, confirmed_at, notified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── "Email me when tickets go on sale" (double opt-in; migrations/2027_03_04_event_sale_alerts.php) ───
CREATE TABLE IF NOT EXISTS gates_event_sale_alerts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id INT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  email_hash CHAR(64) NOT NULL,
  token CHAR(32) NOT NULL,
  ip_hash CHAR(64) NULL,
  created_at TIMESTAMP NULL DEFAULT NULL,
  confirm_sent_at TIMESTAMP NULL DEFAULT NULL,
  confirmed_at TIMESTAMP NULL DEFAULT NULL,
  notified_at TIMESTAMP NULL DEFAULT NULL,
  cancelled_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_esa_who (event_id, email_hash),
  UNIQUE KEY uq_esa_token (token),
  KEY idx_esa_due (event_id, confirmed_at, notified_at)
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
  -- Display & reading, saved to the member so it follows them between devices
  -- (REFERENCE §7.5). NULL = never saved. Written only by DisplayReadingPrefs.
  display_json VARCHAR(255) NULL DEFAULT NULL,
  -- The Menu's most-used tiles: a decayed open count per menu destination (MenuShortcuts).
  -- NULL = no history. Bounded by the catalogue, so 1024 ASCII bytes always hold it.
  menu_use_json VARCHAR(1024) NULL DEFAULT NULL,
  -- Passwordless sign-in by phone (Phase 8): the number a code is sent to, normalised to
  -- E.164 by Support\Phone. NOT unique — a family or an office may share a line; see
  -- UserAccountService::byPhone(). `phone` beside it stays as the member typed it.
  phone_e164 VARCHAR(20) NULL DEFAULT NULL,
  -- Joining, steps 3 and 4 (SignIn.dc.html): "What you do", "Where you're based", and the
  -- areas they care about (MemberInterests::FIELDS keys, JSON). Read by the account page
  -- and by the nomination hub's ordering.
  headline VARCHAR(120) NULL DEFAULT NULL,
  based_in VARCHAR(120) NULL DEFAULT NULL,
  interests_json VARCHAR(255) NULL DEFAULT NULL,
  seasonal_greetings TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_users_phone_e164 (phone_e164),
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
  -- The GATEWAY's own identifiers for this payment, captured at confirmation.
  -- `payment_ref`/`reference` above is the reference WE mint and hand to the gateway;
  -- these two are what Paystack's receipt, dashboard and SMS actually show the buyer, so
  -- without them the number in a supporter's hand matches nothing on the platform.
  -- Deliberately NOT unique: a transaction id is unique per gateway, not across them, and
  -- some providers reuse a reference on a retried charge.
  -- See database/migrations/2026_08_26_gateway_reference.php.
  gateway_txn_id VARCHAR(64) DEFAULT NULL,
  gateway_ref VARCHAR(80) DEFAULT NULL,
  ip_hash VARCHAR(64) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_ref (reference),
  KEY idx_order_status (status),
  KEY idx_orde_gwtxn (gateway_txn_id),
  KEY idx_orde_gwref (gateway_ref)
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

-- ── VOTE MESSAGES ──────────────────────────────────────────────────────────
-- A voter's message of support for a nominee. A separate table rather than a
-- column on gates_votes: a message needs a moderation lifecycle, and putting that
-- on the integrity table would conflate "is this vote real" with "is this
-- sentence publishable". See database/migrations/2026_08_22_vote_messages.php.
CREATE TABLE IF NOT EXISTS gates_vote_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nominee_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  -- NULL for a paid contribution: its votes are minted after the gateway
  -- confirms, so the message exists before the vote row does.
  vote_id BIGINT UNSIGNED NULL,
  donation_id BIGINT UNSIGNED NULL,
  voter_email_hash VARCHAR(64) NOT NULL,
  display_name VARCHAR(120) NULL,
  -- Shown only when 1, exactly like the supporters list.
  show_name TINYINT(1) NOT NULL DEFAULT 0,
  body TEXT NOT NULL,
  source ENUM('free','paid') NOT NULL DEFAULT 'free',
  status ENUM('pending','approved','rejected','quarantined') NOT NULL DEFAULT 'pending',
  mod_score DECIMAL(4,3) NULL,
  mod_reason VARCHAR(190) NULL,
  moderated_by BIGINT UNSIGNED NULL,
  moderated_at TIMESTAMP NULL DEFAULT NULL,
  cheers INT UNSIGNED NOT NULL DEFAULT 0,
  -- Reader reports. Counted on the row rather than in gates_reports because that
  -- table requires a member id and constrains target_type to thread|comment: a
  -- reader who arrives from a WhatsApp link and sees something about a child is not
  -- going to register in order to say so.
  reports INT UNSIGNED NOT NULL DEFAULT 0,
  reported_at TIMESTAMP NULL DEFAULT NULL,
  share_token CHAR(22) NULL,
  created_at TIMESTAMP NULL DEFAULT NULL,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  KEY idx_vmsg_wall (nominee_id, status, created_at),
  UNIQUE KEY uq_vmsg_voter (nominee_id, voter_email_hash),
  UNIQUE KEY uq_vmsg_token (share_token),
  KEY idx_vmsg_queue (status, created_at),
  KEY idx_vmsg_reported (reports, reported_at),
  CONSTRAINT fk_vmsg_nominee FOREIGN KEY (nominee_id) REFERENCES gates_nominees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════════════════════════════════════════════
-- CHALLENGES
-- A time-boxed campaign that pays people for real actions. Every campaign is ONE
-- ROW here: the launch one (first 11 to get 10 nominees verified, ₦6,000 each,
-- inside Alimosho Awards 2026) differs from "20 gala tickets drawn among everyone
-- who thanks 5 teachers" only by these values. If a second campaign ever needs a
-- migration, this shape is wrong.
--
-- Kept in step with `database/migrations/2027_02_10_challenges.php` and with
-- `Support\ChallengeEnum`, which is where every word below is declared.
-- ══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS gates_challenges (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(160) NOT NULL,
  title VARCHAR(200) NOT NULL,
  kicker VARCHAR(160) NOT NULL,
  summary TEXT,
  action ENUM('nominate','vote','refer','give','attend') NOT NULL DEFAULT 'nominate',
  target SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  mode ENUM('first','top','draw') NOT NULL DEFAULT 'first',
  -- SMALLINT and not TINYINT: a cap of 300 is an ordinary campaign, and a TINYINT
  -- stores it as 255 with nothing to see. This codebase has paid for that twice.
  cap SMALLINT UNSIGNED DEFAULT NULL,
  draw_count SMALLINT UNSIGNED DEFAULT NULL,
  draw_at DATETIME DEFAULT NULL,
  draw_seed VARCHAR(64) DEFAULT NULL,
  prize_type ENUM('cash_each','cash_pool','points','tickets') NOT NULL DEFAULT 'cash_each',
  -- INT: a shared pool in Naira passes a SMALLINT at ₦65,536.
  prize_amount INT UNSIGNED NOT NULL DEFAULT 0,
  prize_currency VARCHAR(8) DEFAULT NULL,
  prize_label VARCHAR(80) DEFAULT NULL,
  theme ENUM('green','blue','gold','rose') NOT NULL DEFAULT 'green',
  art_url VARCHAR(400) DEFAULT NULL,
  art_alt VARCHAR(200) DEFAULT NULL,
  icon VARCHAR(400) DEFAULT NULL,
  flag TINYINT(1) NOT NULL DEFAULT 0,
  eligibility TEXT,
  extra_rules TEXT,
  starts_at DATETIME DEFAULT NULL,
  ends_at DATETIME DEFAULT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Lagos',
  terms_version VARCHAR(16) NOT NULL DEFAULT '1.0',
  status ENUM('draft','upcoming','open','full','ended','cancelled') NOT NULL DEFAULT 'draft',
  cancel_reason VARCHAR(300) DEFAULT NULL,
  created_by BIGINT UNSIGNED DEFAULT NULL,
  published_at DATETIME DEFAULT NULL,
  created_at DATETIME DEFAULT NULL,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY(id),
  UNIQUE KEY uq_challenge_slug(slug),
  KEY idx_challenge_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An action counts ONLY inside a scope. This table is the whole difference between
-- "nominate anybody" and "nominate for Alimosho 2026, in three named categories".
CREATE TABLE IF NOT EXISTS gates_challenge_scopes (
  challenge_id INT UNSIGNED NOT NULL,
  scope_type ENUM('award_cycle','category','event') NOT NULL,
  -- BIGINT because a category id is BIGINT, even though an event id is INT: of two
  -- joined keys it is the narrower one that silently truncates.
  scope_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (challenge_id, scope_type, scope_id),
  KEY idx_scope_lookup(scope_type, scope_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_challenge_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  challenge_id INT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  -- Hashed, never stored: one entry per PERSON is enforced on a number we must not
  -- keep beside a prize.
  phone_hash CHAR(64) DEFAULT NULL,
  joined_at DATETIME DEFAULT NULL,
  progress SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  verified SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  checking SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  needs_details SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  qualified_at DATETIME DEFAULT NULL,
  -- `standing`, never `rank`: RANK is reserved in MySQL 8 and bare-legal in SQLite,
  -- which is how a column name passes dev and fails production. The release seal hit
  -- this already and settled on the same word.
  standing SMALLINT UNSIGNED DEFAULT NULL,
  status ENUM('active','qualified','won','disqualified','withdrawn') NOT NULL DEFAULT 'active',
  disqualify_reason VARCHAR(300) DEFAULT NULL,
  payout_status ENUM('none','pending','paid','failed') NOT NULL DEFAULT 'none',
  payout_ref VARCHAR(120) DEFAULT NULL,
  payout_at DATETIME DEFAULT NULL,
  created_at DATETIME DEFAULT NULL,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY(id),
  -- The two ways one human arrives twice.
  UNIQUE KEY uq_entry_user(challenge_id, user_id),
  UNIQUE KEY uq_entry_phone(challenge_id, phone_hash),
  KEY idx_entry_rank(challenge_id, status, qualified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- APPEND-ONLY. A disqualification, a payout and a draw are decisions somebody may
-- have to answer for months later. `gates_audit_log` already taught this codebase
-- that a record nothing can QUERY is not a record, so this one is indexed for the
-- two questions it exists to answer: what happened to this entry, and what has been
-- done on this challenge.
CREATE TABLE IF NOT EXISTS gates_challenge_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  challenge_id INT UNSIGNED DEFAULT NULL,
  entry_id BIGINT UNSIGNED DEFAULT NULL,
  kind VARCHAR(60) NOT NULL,
  ref_type VARCHAR(40) DEFAULT NULL,
  ref_id BIGINT UNSIGNED DEFAULT NULL,
  actor_id BIGINT UNSIGNED DEFAULT NULL,
  meta TEXT,
  created_at DATETIME DEFAULT NULL,
  PRIMARY KEY(id),
  KEY idx_chev_entry(entry_id, id),
  KEY idx_chev_challenge(challenge_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The rows `partials/promo-carousel.twig` reads. `challenge_id` is nullable because a
-- promo may advertise something that is not a challenge; where it is set, the state
-- chip is computed live rather than copied, so a full challenge cannot go on
-- advertising spare places.
CREATE TABLE IF NOT EXISTS gates_promos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  placement ENUM('account','nominate','home','vote','events','award','event') NOT NULL,
  kicker VARCHAR(120) DEFAULT NULL,
  title VARCHAR(200) NOT NULL,
  sub VARCHAR(300) DEFAULT NULL,
  cta VARCHAR(80) DEFAULT NULL,
  href VARCHAR(400) DEFAULT NULL,
  theme ENUM('green','blue','gold','rose') NOT NULL DEFAULT 'green',
  art_url VARCHAR(400) DEFAULT NULL,
  challenge_id INT UNSIGNED DEFAULT NULL,
  priority SMALLINT NOT NULL DEFAULT 0,
  audience ENUM('all','signed_in','signed_out') NOT NULL DEFAULT 'all',
  starts_at DATETIME DEFAULT NULL,
  ends_at DATETIME DEFAULT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT NULL,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY(id),
  KEY idx_promo_slot(placement, active, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Recognitions from verified issuers (REFERENCE §11; 2027_03_06_recognitions.php) ───
-- Seeded from sealed, announced releases only (Services\Recognitions). Immutable apart
-- from the withdrawal fields; every withdrawal is a row in the public log below.
CREATE TABLE IF NOT EXISTS gates_recognition_issuers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  issuer_type VARCHAR(20) NOT NULL DEFAULT 'organisation',
  name VARCHAR(200) NOT NULL,
  programme_id BIGINT UNSIGNED NULL, partner_org_id BIGINT UNSIGNED NULL,
  url VARCHAR(400) NULL,
  verified_at TIMESTAMP NULL DEFAULT NULL, verified_basis VARCHAR(40) NULL,
  created_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY(id), UNIQUE KEY uq_rci_programme(programme_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_recognitions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  issuer_id BIGINT UNSIGNED NOT NULL,
  recipient_profile_id BIGINT UNSIGNED NULL, recipient_nominee_id BIGINT UNSIGNED NULL,
  recipient_name VARCHAR(200) NOT NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'award', standing VARCHAR(20) NULL,
  title VARCHAR(300) NOT NULL, citation TEXT NULL,
  issued_at TIMESTAMP NULL DEFAULT NULL,
  reference VARCHAR(64) NOT NULL,
  visibility VARCHAR(20) NOT NULL DEFAULT 'public',
  evidence_ids TEXT NULL,
  cycle_id BIGINT UNSIGNED NULL, category_id BIGINT UNSIGNED NULL,
  withdrawn_at TIMESTAMP NULL DEFAULT NULL, withdrawn_reason TEXT NULL,
  created_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY(id), UNIQUE KEY uq_rec_reference(reference),
  KEY idx_rec_profile(recipient_profile_id), KEY idx_rec_nominee(recipient_nominee_id),
  KEY idx_rec_issued(issued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates_recognition_withdrawals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recognition_id BIGINT UNSIGNED NOT NULL,
  reason TEXT NOT NULL,
  withdrawn_at TIMESTAMP NOT NULL,
  actor_admin_id BIGINT UNSIGNED NULL,
  PRIMARY KEY(id), KEY idx_rcw_rec(recognition_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
