-- AFRICA GATES — SQLite-compatible schema (local dev / fallback)
-- For production MySQL deployment use database/schema.sql instead.

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS gates_profiles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  display_name TEXT NOT NULL,
  profile_type TEXT NOT NULL DEFAULT 'individual' CHECK(profile_type IN ('individual','business','organisation')),
  category TEXT NOT NULL DEFAULT '',
  bio TEXT,
  email TEXT NOT NULL UNIQUE,
  phone TEXT,
  website TEXT,
  instagram_handle TEXT,
  twitter_handle TEXT,
  country_code TEXT NOT NULL DEFAULT 'NG',
  region TEXT NOT NULL DEFAULT 'west' CHECK(region IN ('west','east','north','south','central')),
  location_city TEXT,
  latitude REAL,
  longitude REAL,
  avatar_path TEXT,
  cover_path TEXT,
  gallery_paths TEXT,
  achievements TEXT,
  tags TEXT,
  cpi_score INTEGER NOT NULL DEFAULT 0,
  cpi_tier TEXT NOT NULL DEFAULT 'unranked' CHECK(cpi_tier IN ('diamond','platinum','gold','silver','bronze','unranked')),
  cpi_last_computed TEXT,
  verification_tier TEXT NOT NULL DEFAULT 'none' CHECK(verification_tier IN ('none','basic','verified','premium')),
  status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','suspended','rejected')),
  completeness_pct INTEGER NOT NULL DEFAULT 0,
  view_count INTEGER NOT NULL DEFAULT 0,
  merged_into INTEGER DEFAULT NULL,   -- survivor id when this profile is a merge tombstone (NULL = live)
  merged_at TEXT DEFAULT NULL,
  registered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_profiles_status ON gates_profiles(status);
CREATE INDEX IF NOT EXISTS idx_profiles_country ON gates_profiles(country_code);
CREATE INDEX IF NOT EXISTS idx_profiles_region ON gates_profiles(region);
CREATE INDEX IF NOT EXISTS idx_profiles_cpi ON gates_profiles(cpi_score DESC);
CREATE INDEX IF NOT EXISTS idx_profiles_tier ON gates_profiles(cpi_tier);

CREATE TABLE IF NOT EXISTS gates_award_programmes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  subtitle TEXT,
  description TEXT,
  scope TEXT NOT NULL DEFAULT 'continental',
  cover_path TEXT,
  icon_emoji TEXT DEFAULT '🏆',
  sort_order INTEGER NOT NULL DEFAULT 0,
  is_active INTEGER NOT NULL DEFAULT 1,
  terms TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS gates_award_cycles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  programme_id INTEGER NOT NULL,
  year INTEGER NOT NULL,
  edition_label TEXT,
  status TEXT NOT NULL DEFAULT 'upcoming' CHECK(status IN ('upcoming','nominations','shortlisting','voting','judging','results','archived')),
  nominations_open TEXT,
  nominations_close TEXT,
  voting_open TEXT,
  voting_close TEXT,
  results_date TEXT,
  -- The next declared boundary this cycle is waiting on. A computed phase
  -- cannot be indexed (NOW() is non-deterministic and rejected in generated
  -- columns), so this materialises the one question an operator needs indexed:
  -- which cycles need attention right now.
  next_boundary_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(programme_id) REFERENCES gates_award_programmes(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_cycles_prog_year ON gates_award_cycles(programme_id, year);
CREATE INDEX IF NOT EXISTS idx_cycles_next_boundary ON gates_award_cycles(next_boundary_at);

CREATE TABLE IF NOT EXISTS gates_award_categories (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  cycle_id INTEGER NOT NULL,
  slug TEXT NOT NULL,
  title TEXT NOT NULL,
  description TEXT,
  sort_order INTEGER NOT NULL DEFAULT 0,
  UNIQUE(cycle_id, slug),
  FOREIGN KEY(cycle_id) REFERENCES gates_award_cycles(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS gates_nominees (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  category_id INTEGER NOT NULL,
  profile_id INTEGER,
  name TEXT NOT NULL,
  tagline TEXT,
  photo_path TEXT,
  country_code TEXT,
  vote_count INTEGER NOT NULL DEFAULT 0,          -- total display support (organic + paid boost)
  organic_vote_count INTEGER NOT NULL DEFAULT 0,  -- organic OTP votes only; the CPI community signal
  status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','winner','runner_up')),
  merged_into INTEGER DEFAULT NULL,   -- survivor id when this row is a merge tombstone (NULL = live)
  merged_at TEXT DEFAULT NULL,
  nominated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(category_id) REFERENCES gates_award_categories(id) ON DELETE CASCADE,
  FOREIGN KEY(profile_id) REFERENCES gates_profiles(id) ON DELETE SET NULL
);

-- Per-row undo journal for nominee merges (see schema.sql for the rationale).
CREATE TABLE IF NOT EXISTS gates_merge_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  batch TEXT NOT NULL,
  keep_id INTEGER NOT NULL,
  merged_id INTEGER NOT NULL,
  op TEXT NOT NULL,             -- 'reassign' | 'delete'
  tbl TEXT NOT NULL,
  row_pk INTEGER DEFAULT NULL,  -- pk of the affected row (reassign)
  col TEXT DEFAULT NULL,        -- column moved (reassign)
  old_val TEXT DEFAULT NULL,    -- prior value = merged_id (reassign)
  snapshot TEXT DEFAULT NULL,   -- JSON of the dropped row (delete)
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_merge_log_merged ON gates_merge_log(merged_id);
CREATE INDEX IF NOT EXISTS idx_merge_log_batch ON gates_merge_log(batch);

-- Same undo journal, for registry-profile merges.
CREATE TABLE IF NOT EXISTS gates_profile_merge_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  batch TEXT NOT NULL, keep_id INTEGER NOT NULL, merged_id INTEGER NOT NULL,
  op TEXT NOT NULL, tbl TEXT NOT NULL, row_pk INTEGER DEFAULT NULL,
  col TEXT DEFAULT NULL, old_val TEXT DEFAULT NULL, snapshot TEXT DEFAULT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_pmerge_log_merged ON gates_profile_merge_log(merged_id);
CREATE INDEX IF NOT EXISTS idx_pmerge_log_batch ON gates_profile_merge_log(batch);
CREATE INDEX IF NOT EXISTS idx_nominees_cat ON gates_nominees(category_id);
CREATE INDEX IF NOT EXISTS idx_nominees_votes ON gates_nominees(vote_count DESC);

CREATE TABLE IF NOT EXISTS gates_votes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nominee_id INTEGER NOT NULL,
  category_id INTEGER NOT NULL,
  voter_email_hash TEXT NOT NULL,
  otp_token_id INTEGER,
  nominee_country TEXT,
  ip_hash TEXT,
  device_hash TEXT,
  idempotency_key TEXT,
  voter_name TEXT,
  voter_phone TEXT,
  -- Consent to appear on the PUBLIC supporters list. 0 unless the voter ticked the
  -- box, so a name collected for a receipt is never published by default.
  show_name INTEGER NOT NULL DEFAULT 0,
  vote_type TEXT NOT NULL DEFAULT 'standard' CHECK(vote_type IN ('standard','bonus','paid')),
  weight INTEGER NOT NULL DEFAULT 1,
  donation_id INTEGER,
  risk_score INTEGER NOT NULL DEFAULT 0,
  fraud_flag INTEGER NOT NULL DEFAULT 0,
  voted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(voter_email_hash, category_id),
  FOREIGN KEY(nominee_id) REFERENCES gates_nominees(id) ON DELETE CASCADE,
  FOREIGN KEY(category_id) REFERENCES gates_award_categories(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_votes_nominee ON gates_votes(nominee_id);
CREATE INDEX IF NOT EXISTS idx_votes_voted ON gates_votes(voted_at);
CREATE INDEX IF NOT EXISTS idx_votes_device ON gates_votes(device_hash);
-- Read on every paid-vote clawback, which scans by donation_id.
CREATE INDEX IF NOT EXISTS idx_votes_donation ON gates_votes(donation_id);
-- Idempotency is scoped per-voter: a shared/buggy client key must not let one
-- voter block another. (Multiple NULL keys remain allowed for key-less votes.)
CREATE UNIQUE INDEX IF NOT EXISTS idx_votes_idem ON gates_votes(voter_email_hash, idempotency_key);

CREATE TABLE IF NOT EXISTS gates_otp_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email_hash TEXT NOT NULL,
  token_hash TEXT NOT NULL,
  purpose TEXT NOT NULL DEFAULT 'vote',
  nominee_id INTEGER,
  award_id INTEGER,
  attempts INTEGER NOT NULL DEFAULT 0,
  is_used INTEGER NOT NULL DEFAULT 0,
  expires_at TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_otp_email ON gates_otp_tokens(email_hash, purpose);
CREATE INDEX IF NOT EXISTS idx_otp_expires ON gates_otp_tokens(expires_at);

CREATE TABLE IF NOT EXISTS gates_nominations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  cycle_id INTEGER NOT NULL,
  category_id INTEGER,
  nominee_name TEXT NOT NULL,
  nominee_email TEXT,
  country_code TEXT,
  nominee_state TEXT,
  nominee_lga TEXT,
  nominee_org TEXT,
  nominee_phone TEXT,
  nominee_photo_path TEXT,
  reference TEXT,
  reason TEXT,
  reference_url TEXT,
  reference_url_2 TEXT,
  reference_url_3 TEXT,
  nominator_name TEXT NOT NULL,
  nominator_email TEXT NOT NULL,
  nominator_phone TEXT,
  nominator_location TEXT,
  nominator_country TEXT,
  nominator_state TEXT,
  nominator_lga TEXT,
  nominator_age_range TEXT,
  decision_reason TEXT,
  nominator_ack_at TEXT,
  status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),
  ip_hash TEXT,
  device_fp TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(cycle_id) REFERENCES gates_award_cycles(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_nominations_cycle ON gates_nominations(cycle_id);
CREATE INDEX IF NOT EXISTS idx_nominations_status ON gates_nominations(status);
CREATE UNIQUE INDEX IF NOT EXISTS uq_nom_reference ON gates_nominations(reference);

CREATE TABLE IF NOT EXISTS gates_legacy_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  tagline TEXT,
  event_date TEXT NOT NULL,
  location TEXT,
  cover_path TEXT,
  gallery_paths TEXT,
  video_url TEXT,
  excerpt TEXT,
  full_content TEXT,
  attendee_count INTEGER NOT NULL DEFAULT 0,
  award_count INTEGER NOT NULL DEFAULT 0,
  highlight_reel TEXT,
  icon TEXT DEFAULT '🏆',
  is_published INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_legacy_published ON gates_legacy_events(is_published);
CREATE INDEX IF NOT EXISTS idx_legacy_date ON gates_legacy_events(event_date DESC);

CREATE TABLE IF NOT EXISTS gates_opportunities (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  opportunity_type TEXT NOT NULL DEFAULT 'grant' CHECK(opportunity_type IN ('grant','mentorship','training','job','fellowship','competition')),
  scope TEXT DEFAULT 'Pan-African',
  provider TEXT NOT NULL,
  description TEXT,
  eligibility TEXT,
  value TEXT,
  deadline TEXT,
  apply_url TEXT,
  min_cpi_tier TEXT CHECK(min_cpi_tier IN ('bronze','silver','gold','platinum','diamond')),
  status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','closed','draft')),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_opps_status ON gates_opportunities(status);
CREATE INDEX IF NOT EXISTS idx_opps_deadline ON gates_opportunities(deadline);

CREATE TABLE IF NOT EXISTS gates_rate_limits (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  fingerprint TEXT NOT NULL,
  action TEXT NOT NULL,
  hit_count INTEGER NOT NULL DEFAULT 1,
  window_start TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(fingerprint, action)
);
CREATE INDEX IF NOT EXISTS idx_rate_window ON gates_rate_limits(window_start);

-- Shareable prefill nomination links (opaque token → nominee-side JSON payload).
CREATE TABLE IF NOT EXISTS gates_nomination_links (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  token TEXT NOT NULL UNIQUE,
  payload TEXT NOT NULL,
  created_ip_hash TEXT,
  created_by INTEGER,
  hits INTEGER NOT NULL DEFAULT 0,
  expires_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_nomlink_expires ON gates_nomination_links(expires_at);

-- Editable legal / policy documents (privacy, terms, cookies, + custom).
CREATE TABLE IF NOT EXISTS gates_legal_docs (
  slug TEXT PRIMARY KEY,
  title TEXT NOT NULL,
  body_html TEXT,
  updated_label TEXT,
  is_published INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0,
  updated_by INTEGER,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_legal_pub ON gates_legal_docs(is_published, sort_order);

-- AI triage for nomination review at scale (advisory only — never auto-decides).
CREATE TABLE IF NOT EXISTS gates_nomination_insights (
  nomination_id INTEGER PRIMARY KEY,
  quality_score INTEGER,
  summary TEXT,
  duplicates_json TEXT,
  model TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Email delivery audit (recipient masked) — powers the admin Email-health card.
CREATE TABLE IF NOT EXISTS gates_mail_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  to_masked TEXT NOT NULL,
  subject TEXT NOT NULL,
  category TEXT,
  status TEXT NOT NULL CHECK(status IN ('sent','failed','logged_dev')),
  error TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_mail_created ON gates_mail_log(created_at);
CREATE INDEX IF NOT EXISTS idx_mail_status ON gates_mail_log(status);

-- Outbound SMS / WhatsApp delivery audit (recipients stored hashed + masked, never raw).
CREATE TABLE IF NOT EXISTS gates_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  channel TEXT NOT NULL CHECK(channel IN ('sms','whatsapp')),
  to_hash TEXT NOT NULL,
  to_masked TEXT NOT NULL,
  template TEXT NOT NULL DEFAULT 'generic',
  status TEXT NOT NULL CHECK(status IN ('sent','failed','queued')),
  provider TEXT,
  provider_ref TEXT,
  error TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_messages_created ON gates_messages(created_at);
CREATE INDEX IF NOT EXISTS idx_messages_status ON gates_messages(status);

CREATE TABLE IF NOT EXISTS gates_cache (
  cache_key TEXT PRIMARY KEY,
  payload TEXT NOT NULL,
  tags TEXT,
  expires_at TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_cache_expires ON gates_cache(expires_at);

CREATE TABLE IF NOT EXISTS gates_cpi_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  profile_id INTEGER NOT NULL,
  cpi_score INTEGER NOT NULL,
  cpi_tier TEXT NOT NULL,
  computed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(profile_id) REFERENCES gates_profiles(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_cpihist_profile ON gates_cpi_history(profile_id);
CREATE INDEX IF NOT EXISTS idx_cpihist_computed ON gates_cpi_history(computed_at);

CREATE TABLE IF NOT EXISTS gates_partner_enquiries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  org_name TEXT NOT NULL,
  contact_name TEXT NOT NULL,
  contact_email TEXT NOT NULL,
  contact_phone TEXT,
  partnership_type TEXT,
  message TEXT,
  status TEXT NOT NULL DEFAULT 'new' CHECK(status IN ('new','in_review','converted','closed')),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_partner_status ON gates_partner_enquiries(status);

CREATE TABLE IF NOT EXISTS gates_cron_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  job_name TEXT NOT NULL,
  status TEXT NOT NULL CHECK(status IN ('success','error')),
  message TEXT,
  runtime_ms INTEGER,
  ran_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_cron_job ON gates_cron_log(job_name);
CREATE INDEX IF NOT EXISTS idx_cron_ran ON gates_cron_log(ran_at);

-- Newsletter signups (Phase 0 / Task D4). email_hash is the dedup key
-- (case-insensitive, privacy-safe); email is kept raw so we can actually send.
CREATE TABLE IF NOT EXISTS gates_newsletter (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email_hash TEXT NOT NULL UNIQUE,
  email TEXT NOT NULL,
  ip_hash TEXT,
  source TEXT,
  subscribed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unsubscribed_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_newsletter_subscribed ON gates_newsletter(subscribed_at);

-- Admin-configurable key/value settings
CREATE TABLE IF NOT EXISTS gates_admin_settings (
  setting_key TEXT NOT NULL PRIMARY KEY,
  setting_value TEXT NOT NULL,
  description TEXT,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Donation records + bonus-vote tracking
CREATE TABLE IF NOT EXISTS gates_donations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  donor_name TEXT NOT NULL,
  donor_email TEXT NOT NULL,
  donor_phone TEXT,
  donor_location TEXT,
  amount_naira INTEGER NOT NULL,
  tier TEXT,
  bonus_votes INTEGER NOT NULL DEFAULT 0,
  votes_used INTEGER NOT NULL DEFAULT 0,
  intent_nominee_id INTEGER, -- paid-vote orders: auto-mint target on confirm
  payment_ref TEXT,
  status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','confirmed','failed')),
  -- The buyer's answer to "show my name publicly", carried through the gateway
  -- round-trip and copied onto the vote at mint. Default 0 = private.
  show_name INTEGER NOT NULL DEFAULT 0,
  refunded_at TEXT DEFAULT NULL,
  -- Send-exactly-once claim stamps; see CheckoutMailer.
  receipt_sent_at TEXT DEFAULT NULL,
  abandoned_mail_at TEXT DEFAULT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_donation_email ON gates_donations(donor_email);
CREATE INDEX IF NOT EXISTS idx_donation_status ON gates_donations(status);
CREATE INDEX IF NOT EXISTS idx_donations_abandon ON gates_donations(status, abandoned_mail_at, created_at);

-- ─── Site events (public calendar; distinct from gates_events analytics log) ───
CREATE TABLE IF NOT EXISTS gates_site_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  tagline TEXT,
  description TEXT,
  location TEXT,
  venue TEXT,
  event_date TEXT NOT NULL,
  end_date TEXT,
  cover_image TEXT,
  rsvp_url TEXT,
  status TEXT NOT NULL DEFAULT 'published',
  capacity INTEGER,
  price_naira INTEGER,
  schedule TEXT,
  map_embed TEXT,
  ticket_tiers TEXT,
  early_bird_text TEXT,
  early_bird_deadline TEXT,
  early_bird_url TEXT,
  created_at TEXT
);

-- ─── Event registrations (on-platform RSVP for site events) ───
CREATE TABLE IF NOT EXISTS gates_event_registrations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  email TEXT NOT NULL,
  phone TEXT,
  ip_hash TEXT,
  amount_naira INTEGER DEFAULT 0,
  reference TEXT,
  tier TEXT,
  user_id INTEGER,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(event_id, email)
);
CREATE INDEX IF NOT EXISTS idx_evreg_event ON gates_event_registrations(event_id);

-- ─── Gated single-use form links (verified nominees + judge invites) ───
CREATE TABLE IF NOT EXISTS gates_form_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  purpose TEXT NOT NULL,
  subject_id INTEGER NOT NULL,
  email_hash TEXT,
  token_hash TEXT NOT NULL UNIQUE,
  payload TEXT,
  is_used INTEGER NOT NULL DEFAULT 0,
  used_at TEXT,
  expires_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_formtok_subject ON gates_form_tokens(purpose, subject_id);

-- ─── Form builder (admin-designed forms + submissions) ───
CREATE TABLE IF NOT EXISTS gates_forms (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  form_key TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  description TEXT,
  schema_json TEXT NOT NULL DEFAULT '{"fields":[]}',
  submit_message TEXT,
  status TEXT NOT NULL DEFAULT 'draft',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS gates_form_submissions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  form_id INTEGER NOT NULL,
  form_key TEXT NOT NULL,
  data_json TEXT NOT NULL,
  ip_hash TEXT,
  user_id INTEGER,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_formsub_form ON gates_form_submissions(form_key);

-- ─── User accounts + voting-points ledger ───
CREATE TABLE IF NOT EXISTS gates_users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  email TEXT NOT NULL UNIQUE,
  phone TEXT,
  password_hash TEXT,
  points INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'active',
  email_verified INTEGER NOT NULL DEFAULT 0,
  created_at TEXT,
  last_login_at TEXT,
  last_login_ip TEXT
);
CREATE TABLE IF NOT EXISTS gates_points_ledger (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  delta INTEGER NOT NULL,
  reason TEXT NOT NULL,
  ref_type TEXT,
  ref_id TEXT,
  balance_after INTEGER NOT NULL DEFAULT 0,
  note TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ledger_user ON gates_points_ledger(user_id, created_at);

-- ─── Blog posts ───
CREATE TABLE IF NOT EXISTS gates_posts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  excerpt TEXT,
  body TEXT,
  cover_image TEXT,
  audio_path TEXT,
  author TEXT,
  tag TEXT,
  status TEXT NOT NULL DEFAULT 'published',
  published_at TEXT,
  created_at TEXT
);

-- ─── Integrity, analytics & engagement (parity with MySQL INSTALL.sql) ───
-- Per-vote fraud scoring (written by FraudService alongside each vote).
CREATE TABLE IF NOT EXISTS gates_fraud_scores (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  vote_id INTEGER,
  email_hash TEXT NOT NULL,
  ip_hash TEXT,
  device_hash TEXT,
  risk_score INTEGER NOT NULL DEFAULT 0,
  signals TEXT,
  decision TEXT NOT NULL DEFAULT 'allow' CHECK(decision IN ('allow','monitor','flag','block')),
  reviewed INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_fraud_email    ON gates_fraud_scores(email_hash);
CREATE INDEX IF NOT EXISTS idx_fraud_score    ON gates_fraud_scores(risk_score);
CREATE INDEX IF NOT EXISTS idx_fraud_decision ON gates_fraud_scores(decision);

-- Cluster-level collusion findings (a nightly graph pass writes these; admins
-- review and void rings in bulk). Distinct from per-vote gates_fraud_scores.
CREATE TABLE IF NOT EXISTS gates_collusion_findings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind TEXT NOT NULL CHECK(kind IN ('shared_device','shared_ip','timing_burst')),
  category_id INTEGER,
  nominee_id INTEGER NOT NULL,
  shared_key TEXT NOT NULL,            -- device/ip hash, or burst-window start
  vote_count INTEGER NOT NULL DEFAULT 0,
  distinct_voters INTEGER NOT NULL DEFAULT 0,
  risk_score INTEGER NOT NULL DEFAULT 0,
  explanation TEXT,
  status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','reviewed','dismissed','actioned')),
  first_seen TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(kind, nominee_id, shared_key)
);
CREATE INDEX IF NOT EXISTS idx_collusion_status  ON gates_collusion_findings(status);
CREATE INDEX IF NOT EXISTS idx_collusion_nominee ON gates_collusion_findings(nominee_id);

-- Generic analytics event log (distinct from gates_site_events public calendar).
CREATE TABLE IF NOT EXISTS gates_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  actor_type TEXT NOT NULL DEFAULT 'system' CHECK(actor_type IN ('voter','nominator','admin','judge','system')),
  actor_hash TEXT,
  subject_type TEXT,
  subject_id INTEGER,
  payload TEXT,
  ip_hash TEXT,
  device_hash TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_event_name    ON gates_events(name);
CREATE INDEX IF NOT EXISTS idx_event_created ON gates_events(created_at);
CREATE INDEX IF NOT EXISTS idx_event_subject ON gates_events(subject_type, subject_id);

-- Conversion-funnel step tracking.
CREATE TABLE IF NOT EXISTS gates_funnel_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id TEXT NOT NULL,
  step TEXT NOT NULL,
  nominee_id INTEGER,
  award_id INTEGER,
  device_hash TEXT,
  ip_hash TEXT,
  meta TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_funnel_session ON gates_funnel_events(session_id);
CREATE INDEX IF NOT EXISTS idx_funnel_step    ON gates_funnel_events(step);
CREATE INDEX IF NOT EXISTS idx_funnel_created ON gates_funnel_events(created_at);

-- Vote-count milestones reached by nominees (drives celebratory notifications).
CREATE TABLE IF NOT EXISTS gates_vote_milestones (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nominee_id INTEGER NOT NULL,
  milestone INTEGER NOT NULL,
  notified INTEGER NOT NULL DEFAULT 0,
  achieved_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(nominee_id, milestone),
  FOREIGN KEY(nominee_id) REFERENCES gates_nominees(id) ON DELETE CASCADE
);

-- Server-side autosave of in-progress nomination forms.
CREATE TABLE IF NOT EXISTS gates_nomination_drafts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_key TEXT NOT NULL UNIQUE,
  payload TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_draft_updated ON gates_nomination_drafts(updated_at);

-- Tamper-evident periodic snapshots of the vote/CPI standings.
CREATE TABLE IF NOT EXISTS gates_vote_snapshots (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  cycle_id INTEGER NOT NULL,
  nominee_id INTEGER NOT NULL,
  vote_count INTEGER NOT NULL,
  judge_score REAL,
  cpi_score INTEGER NOT NULL DEFAULT 0,
  snapshot_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  prev_hash TEXT,
  hash TEXT
);
CREATE INDEX IF NOT EXISTS idx_snap_cycle   ON gates_vote_snapshots(cycle_id);
CREATE INDEX IF NOT EXISTS idx_snap_nominee ON gates_vote_snapshots(nominee_id);

-- Auditable cycle lifecycle transitions (who/when moved a cycle between phases).
CREATE TABLE IF NOT EXISTS gates_cycle_transitions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  cycle_id INTEGER NOT NULL,
  from_status TEXT,
  to_status TEXT NOT NULL,
  reason TEXT,
  actor TEXT,
  boundary_at TEXT,
  observed_at TEXT,
  notify INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(cycle_id) REFERENCES gates_award_cycles(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_cyctrans_cycle ON gates_cycle_transitions(cycle_id);
-- The INSERT is the claim: exactly one caller records a phase entry and fires
-- its side effects, even when two schedulers run concurrently.
CREATE UNIQUE INDEX IF NOT EXISTS uq_cyctrans_phase ON gates_cycle_transitions(cycle_id, to_status);

-- Background job queue (async side-effects off the request hot path).
CREATE TABLE IF NOT EXISTS gates_jobs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT NOT NULL,
  payload TEXT,
  status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','done','failed')),
  attempts INTEGER NOT NULL DEFAULT 0,
  run_after TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at TEXT,
  last_error TEXT,
  -- Optional idempotency key: the outbox delivers at-least-once, so anything
  -- with a user-visible effect needs a way to refuse a duplicate enqueue.
  dedupe_key TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_jobs_dedupe ON gates_jobs(dedupe_key);
CREATE INDEX IF NOT EXISTS idx_jobs_due ON gates_jobs(status, run_after);

-- Per-scope rule overrides (CPI weights, tiers, thresholds…) layered over code
-- defaults: global → programme → cycle. JSON so new keys need no migration.
CREATE TABLE IF NOT EXISTS gates_rule_sets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  scope TEXT NOT NULL DEFAULT 'global' CHECK(scope IN ('global','programme','cycle')),
  scope_id INTEGER,
  rules TEXT NOT NULL DEFAULT '{}',
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(scope, scope_id)
);

-- Performance indexes on hot columns (mirrors 2026_06_30_perf_indexes.php + nomination_device_fp).
-- Placed at the very end so every referenced table is already defined.
CREATE INDEX IF NOT EXISTS idx_nominations_device ON gates_nominations(device_fp);
CREATE INDEX IF NOT EXISTS idx_votes_nominee ON gates_votes(nominee_id);
CREATE INDEX IF NOT EXISTS idx_votes_voted_at ON gates_votes(voted_at);
CREATE INDEX IF NOT EXISTS idx_points_user ON gates_points_ledger(user_id);
CREATE INDEX IF NOT EXISTS idx_donations_created ON gates_donations(created_at);
CREATE INDEX IF NOT EXISTS idx_users_created ON gates_users(created_at);
CREATE INDEX IF NOT EXISTS idx_formsub_formid ON gates_form_submissions(form_id);

-- Shadow-mode ledger for the COMPUTED cycle phase (see AfricaGates\Services\BallotGuard).
-- One row whenever the computed phase and the stored gates_award_cycles.status
-- disagree about whether a vote/nomination may proceed, so a mis-configured
-- live cycle surfaces to an operator instead of silently mis-gating traffic.
CREATE TABLE IF NOT EXISTS gates_phase_drift (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  cycle_id INTEGER NOT NULL,
  action TEXT NOT NULL DEFAULT 'vote' CHECK(action IN ('vote','nominate')),
  computed_phase TEXT NOT NULL,
  stored_status TEXT NOT NULL,
  would_allow INTEGER NOT NULL DEFAULT 0,
  phase_allows INTEGER NOT NULL DEFAULT 0,
  mode TEXT NOT NULL DEFAULT 'strict',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_drift_cycle ON gates_phase_drift(cycle_id, created_at);

-- AI audit log. Every model call, whatever the outcome: which capability ran,
-- which provider answered, tokens spent, and what happened. The prompt itself is
-- NOT stored — only a hash — so the log does not become a second copy of every
-- nominator's free text. Budgets are enforced against this table, so the spend
-- figure and the record can never disagree.
CREATE TABLE IF NOT EXISTS gates_ai_calls (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  capability TEXT NOT NULL,
  purpose TEXT,
  provider TEXT,
  model TEXT,
  subject_type TEXT,
  subject_id INTEGER,
  input_hash TEXT,
  output_summary TEXT,
  tokens_in INTEGER NOT NULL DEFAULT 0,
  tokens_out INTEGER NOT NULL DEFAULT 0,
  latency_ms INTEGER NOT NULL DEFAULT 0,
  outcome TEXT NOT NULL DEFAULT 'OK',
  error TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ai_cap_day ON gates_ai_calls(capability, created_at);
CREATE INDEX IF NOT EXISTS idx_ai_subject ON gates_ai_calls(subject_type, subject_id);

-- What the AI suggested vs what the human decided. gates_ai_calls records that a
-- call happened; this records whether it was any use — the only thing that
-- justifies keeping an advisory AI, and the accountability trail for a decision
-- made with a machine score in front of the reviewer.
CREATE TABLE IF NOT EXISTS gates_ai_decisions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  capability TEXT NOT NULL,
  subject_type TEXT NOT NULL,
  subject_id INTEGER NOT NULL,
  suggested TEXT,
  decided TEXT NOT NULL,
  -- NULL when there was no suggestion, so those rows are excluded from the
  -- agreement rate rather than counted as disagreement.
  agreed INTEGER,
  actor_id INTEGER,
  note TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_aidec_cap_day ON gates_ai_decisions(capability, created_at);
CREATE INDEX IF NOT EXISTS idx_aidec_subject ON gates_ai_decisions(subject_type, subject_id);
