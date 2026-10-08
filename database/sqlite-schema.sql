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
  -- Why a result is late, in the operator's own words, on the page people are waiting on.
  -- The DELAY itself is derived (a results date that has passed, a cycle not yet
  -- announced) so the site admits it with or without this; the note is the part only a
  -- person can write, and it stops being shown the moment the cycle is announced.
  results_delay_note TEXT,
  -- Which edition this is ("11th Edition"); migrations/2027_03_05_edition_number.php.
  edition_number INTEGER NULL,
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
  -- The nominator's full case for this person; `tagline` stays the short line.
  -- See schema.sql and database/migrations/2026_08_24_nominee_story.php.
  story TEXT,
  photo_path TEXT,
  country_code TEXT,
  organisation TEXT,                              -- school / organisation, carried from the nomination
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
  -- Which recovery batch put this vote here, if any. NULL on every vote cast the
  -- normal way, so "which votes did we place ourselves" is a one-column question.
  -- A column rather than a new vote_type: a recovered vote IS an ordinary organic
  -- vote, and inventing a type would make every existing query learn about it.
  recovery_batch_id INTEGER NULL,
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
  -- Did the code actually leave the building? 'failed' is the platform's own record
  -- that it let this person down, and the only basis on which their dropped vote may
  -- later be recovered. 'unknown' predates the column and is never recoverable.
  delivery_state TEXT NOT NULL DEFAULT 'unknown',
  delivery_error TEXT NULL,
  delivery_at TEXT NULL,
  expires_at TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_otp_email ON gates_otp_tokens(email_hash, purpose);
CREATE INDEX IF NOT EXISTS idx_otp_expires ON gates_otp_tokens(expires_at);
CREATE INDEX IF NOT EXISTS idx_otp_delivery ON gates_otp_tokens(purpose, delivery_state, is_used);

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
  -- "How do you know them?" — a NominationRules::RELATIONS key (Phase 8).
  nominator_relation TEXT,
  -- "Keep my name private from the nominee" (Phase 8).
  nominator_private INTEGER NOT NULL DEFAULT 0,
  decision_reason TEXT,
  nominator_ack_at TEXT,
  -- The union of the live vocabulary and the challenge handoff's. SQLite enforces
  -- CHECK, and PRAGMA foreign_keys = OFF does not touch it, so a word missing here
  -- is a word no developer and no test can ever write while production accepts it.
  status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected','draft','submitted','checking','verified','needs_details')),
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
  cycle_id INTEGER NULL,
  country_code TEXT NULL,
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
  -- sent | failed | logged_dev | refused | deferred — Mail\MailLog. No CHECK: see
  -- 2027_02_20_mail_send_rules.php, which repairs databases built with one.
  status TEXT NOT NULL,
  error TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  to_hash TEXT NULL,
  bulk INTEGER NOT NULL DEFAULT 0
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
  -- The GATEWAY's own identifiers for this payment, captured at confirmation.
  -- `payment_ref`/`reference` above is the reference WE mint and hand to the gateway;
  -- these two are what Paystack's receipt, dashboard and SMS actually show the buyer, so
  -- without them the number in a supporter's hand matches nothing on the platform.
  -- Deliberately NOT unique: a transaction id is unique per gateway, not across them, and
  -- some providers reuse a reference on a retried charge.
  -- See database/migrations/2026_08_26_gateway_reference.php.
  gateway_txn_id TEXT DEFAULT NULL,
  gateway_ref TEXT DEFAULT NULL,
  status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','confirmed','failed')),
  -- The buyer's answer to "show my name publicly", carried through the gateway
  -- round-trip and copied onto the vote at mint. Default 0 = private.
  show_name INTEGER NOT NULL DEFAULT 0,
  refunded_at TEXT DEFAULT NULL,
  -- Automatic-refund bookkeeping. `refund_requested_at` is the CLAIM stamp,
  -- written before the gateway is called so two workers can never both refund
  -- the same order — see AfricaGates\Services\RefundService.
  refund_state TEXT DEFAULT NULL,
  refund_ref TEXT DEFAULT NULL,
  refund_reason TEXT DEFAULT NULL,
  refund_requested_at TEXT DEFAULT NULL,
  -- Refusal pacing. A refused refund releases its claim (no money moved, so a
  -- retry is safe) but must NOT be retried on the next 14-minute tick; these
  -- two turn that loop into 1h -> 6h -> 24h and then a stop.
  refund_attempts INTEGER NOT NULL DEFAULT 0,
  refund_retry_after TEXT DEFAULT NULL,
  -- The gateway's own answer behind a refund decision. `unreachable` is a
  -- distinct verdict: a confident refusal made out of a network timeout is the
  -- worst thing this column could be used to justify. See RefundDecision.
  gateway_checked_at TEXT DEFAULT NULL,
  gateway_verdict TEXT DEFAULT NULL,
  gateway_evidence TEXT DEFAULT NULL,
  -- WHEN the money arrived, as distinct from when checkout started. The refund
  -- grace window measures this; before the column it measured created_at.
  confirmed_at TEXT DEFAULT NULL,
  -- WHICH gateway took the money. Without it the reconciler asks every gateway
  -- about every reference, and a refund has to guess where to send cash back to.
  provider TEXT DEFAULT NULL,
  -- Stamped when the reconciler gives up on a checkout nobody ever completed.
  -- `status` also becomes 'failed'; this records that TIME decided, not a bank.
  expired_at TEXT DEFAULT NULL,
  -- Send-exactly-once claim stamps; see CheckoutMailer.
  receipt_sent_at TEXT DEFAULT NULL,
  abandoned_mail_at TEXT DEFAULT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_donation_email ON gates_donations(donor_email);
CREATE INDEX IF NOT EXISTS idx_dona_gwtxn ON gates_donations(gateway_txn_id);
CREATE INDEX IF NOT EXISTS idx_dona_gwref ON gates_donations(gateway_ref);
CREATE INDEX IF NOT EXISTS idx_donation_status ON gates_donations(status);
CREATE INDEX IF NOT EXISTS idx_donations_pending_age ON gates_donations(status, created_at);
CREATE INDEX IF NOT EXISTS idx_donation_refund_retry ON gates_donations(refund_state, refund_retry_after);
CREATE INDEX IF NOT EXISTS idx_donations_abandon ON gates_donations(status, abandoned_mail_at, created_at);
CREATE INDEX IF NOT EXISTS idx_donation_refundable ON gates_donations(status, tier, votes_used, refund_requested_at);

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
  livestream_url TEXT NULL DEFAULT NULL,
  recording_url TEXT NULL DEFAULT NULL,
  access_notes TEXT NULL,
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

-- ─── Award terms, versioned, and who accepted which version (migrations/2027_03_05_award_terms.php) ───
CREATE TABLE IF NOT EXISTS gates_award_terms (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  programme_id INTEGER NOT NULL,
  version INTEGER NOT NULL,
  body TEXT NOT NULL,
  changelog TEXT NULL,
  effective_at TEXT NOT NULL,
  created_at TEXT NULL,
  created_by INTEGER NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_terms_version ON gates_award_terms(programme_id, version);
CREATE INDEX IF NOT EXISTS idx_terms_effective ON gates_award_terms(programme_id, effective_at);
CREATE TABLE IF NOT EXISTS gates_award_terms_acceptance (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  terms_id INTEGER NOT NULL,
  programme_id INTEGER NOT NULL,
  kind TEXT NOT NULL,
  email_hash TEXT NOT NULL,
  subject_id INTEGER NULL,
  accepted_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_terms_accept ON gates_award_terms_acceptance(terms_id, email_hash, kind, subject_id);

-- ─── "Notify me" on a coming-soon award (double opt-in; migrations/2027_03_05_award_alerts.php) ───
CREATE TABLE IF NOT EXISTS gates_award_alerts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  programme_id INTEGER NOT NULL,
  email TEXT NOT NULL,
  email_hash TEXT NOT NULL,
  token TEXT NOT NULL,
  ip_hash TEXT NULL,
  created_at TEXT NULL,
  confirm_sent_at TEXT NULL,
  confirmed_at TEXT NULL,
  notified_at TEXT NULL,
  cancelled_at TEXT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_awa_who ON gates_award_alerts(programme_id, email_hash);
CREATE UNIQUE INDEX IF NOT EXISTS uq_awa_token ON gates_award_alerts(token);
CREATE INDEX IF NOT EXISTS idx_awa_due ON gates_award_alerts(programme_id, confirmed_at, notified_at);

-- ─── "Email me when tickets go on sale" (double opt-in; migrations/2027_03_04_event_sale_alerts.php) ───
CREATE TABLE IF NOT EXISTS gates_event_sale_alerts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INTEGER NOT NULL,
  email TEXT NOT NULL,
  email_hash TEXT NOT NULL,
  token TEXT NOT NULL,
  ip_hash TEXT NULL,
  created_at TEXT NULL,
  confirm_sent_at TEXT NULL,
  confirmed_at TEXT NULL,
  notified_at TEXT NULL,
  cancelled_at TEXT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_esa_who ON gates_event_sale_alerts(event_id, email_hash);
CREATE UNIQUE INDEX IF NOT EXISTS uq_esa_token ON gates_event_sale_alerts(token);
CREATE INDEX IF NOT EXISTS idx_esa_due ON gates_event_sale_alerts(event_id, confirmed_at, notified_at);

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
  last_login_ip TEXT,
  -- How far this member has read their alerts. The ONLY state the alerts
  -- feature stores — see AfricaGates\Services\AlertService.
  alerts_read_at TEXT NULL DEFAULT NULL,
  -- Display & reading, saved to the member (REFERENCE §7.5). NULL = never saved.
  display_json TEXT NULL DEFAULT NULL,
  -- The Menu's most-used tiles (MenuShortcuts). NULL = no history.
  menu_use_json TEXT NULL DEFAULT NULL,
  -- Passwordless sign-in by phone (Phase 8), E.164 via Support\Phone. Not unique.
  phone_e164 TEXT NULL DEFAULT NULL,
  -- Joining, steps 3 and 4: what you do, where you are based, interests (JSON keys).
  headline TEXT NULL DEFAULT NULL,
  based_in TEXT NULL DEFAULT NULL,
  interests_json TEXT NULL DEFAULT NULL
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
-- A link may be extended exactly once. Two concurrent captures reading the same
-- tail would otherwise fork the chain, and a forked chain reports itself as
-- tampered with, permanently and unclearably. See the 2026_08_16 migration.
CREATE UNIQUE INDEX IF NOT EXISTS uq_snap_prev ON gates_vote_snapshots(prev_hash);

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
CREATE INDEX IF NOT EXISTS idx_users_phone_e164 ON gates_users(phone_e164);
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

-- The reconciliation audit trail. One row per RUN of the payment reconciler:
-- who ran it, in which mode, and what the gateway said at the time. A finance
-- correction with no trail is indistinguishable from tampering, and this became
-- load-bearing the moment an admin (not just cron) could press the button.
CREATE TABLE IF NOT EXISTS gates_reconciliation_runs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ran_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor TEXT NOT NULL DEFAULT 'system',
  mode TEXT NOT NULL DEFAULT 'check',
  checked INTEGER NOT NULL DEFAULT 0,
  confirmed INTEGER NOT NULL DEFAULT 0,
  failed INTEGER NOT NULL DEFAULT 0,
  mismatch INTEGER NOT NULL DEFAULT 0,
  unverifiable INTEGER NOT NULL DEFAULT 0,
  naira INTEGER NOT NULL DEFAULT 0,
  detail_json TEXT
);
CREATE INDEX IF NOT EXISTS idx_recon_ran ON gates_reconciliation_runs(ran_at);

-- Shop orders. Lived only in the 2026_06_22_shop migration, so a database built
-- from this file alone had no shop table at all — and the test harness, which loads
-- these files WITHOUT applying migrations, could not exercise any shop or
-- reconciliation path. Kept byte-compatible with that migration, which is idempotent
-- and therefore still safe to run on an existing install.
CREATE TABLE IF NOT EXISTS gates_orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  reference TEXT NOT NULL UNIQUE,
  email TEXT NOT NULL,
  name TEXT NOT NULL,
  phone TEXT,
  address TEXT,
  items_json TEXT NOT NULL,
  subtotal_naira INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'pending',
  provider TEXT,
  provider_ref TEXT,
  -- The GATEWAY's own identifiers for this payment, captured at confirmation.
  -- `payment_ref`/`reference` above is the reference WE mint and hand to the gateway;
  -- these two are what Paystack's receipt, dashboard and SMS actually show the buyer, so
  -- without them the number in a supporter's hand matches nothing on the platform.
  -- Deliberately NOT unique: a transaction id is unique per gateway, not across them, and
  -- some providers reuse a reference on a retried charge.
  -- See database/migrations/2026_08_26_gateway_reference.php.
  gateway_txn_id TEXT DEFAULT NULL,
  gateway_ref TEXT DEFAULT NULL,
  ip_hash TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_order_status ON gates_orders(status);
CREATE INDEX IF NOT EXISTS idx_orde_gwtxn ON gates_orders(gateway_txn_id);
CREATE INDEX IF NOT EXISTS idx_orde_gwref ON gates_orders(gateway_ref);

-- Shop catalogue. Same gap gates_orders had: it lived only in the 2026_06_22_shop
-- migration, so a database built from this file had orders with no products for them
-- to reference. Found by diffing a fresh schema build against a migrated one.
CREATE TABLE IF NOT EXISTS gates_products (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT 'Apparel',
  description TEXT,
  price_naira INTEGER NOT NULL DEFAULT 0,
  cover_path TEXT,
  tag TEXT,
  stock INTEGER,
  is_active INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0,
  delivery_regions TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_product_active ON gates_products(is_active, sort_order);

-- ── SUPPORT DESK ────────────────────────────────────────────────────────────
-- Same gap gates_orders and gates_products each had before them: these lived
-- only in the 2026_08 migrations, so a database built from this file had a
-- /support/tickets page with no tables behind it — and the whole feature was
-- untestable on the default (SQLite) suite, because the tables never existed
-- there to write to. A migration is how an EXISTING database catches up; this
-- file is what a NEW one is, and both have to describe the same platform.
CREATE TABLE IF NOT EXISTS gates_support_tickets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  reference TEXT NOT NULL,
  user_id INTEGER,
  email TEXT,
  name TEXT,
  subject TEXT NOT NULL,
  transcript TEXT,
  tools_used TEXT,
  severity TEXT NOT NULL DEFAULT 'normal',
  status TEXT NOT NULL DEFAULT 'open',
  emailed INTEGER NOT NULL DEFAULT 0,
  webhooked INTEGER NOT NULL DEFAULT 0,
  page_url TEXT,
  user_agent TEXT,
  ip_hash TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_activity TEXT,
  resolved_at TEXT
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_ticket_ref ON gates_support_tickets(reference);
CREATE INDEX IF NOT EXISTS idx_ticket_status ON gates_support_tickets(status);
CREATE INDEX IF NOT EXISTS idx_ticket_created ON gates_support_tickets(created_at DESC);

-- Replies. A row each, not an appended blob: an author, a time, a delivery flag
-- and an internal/visible distinction are all things a concatenated transcript
-- destroys. `is_internal` exists so a staff note ("refunded manually, chased
-- Paystack") has somewhere safe to live instead of being emailed to the customer.
CREATE TABLE IF NOT EXISTS gates_support_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_id INTEGER NOT NULL,
  author_type TEXT NOT NULL DEFAULT 'member',
  author_id INTEGER,
  author_name TEXT,
  body TEXT NOT NULL,
  is_internal INTEGER NOT NULL DEFAULT 0,
  emailed INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_smsg_ticket ON gates_support_messages(ticket_id, id);

-- ── VOTE MESSAGES ──────────────────────────────────────────────────────────
-- A voter's message of support for a nominee. A separate table rather than a
-- column on gates_votes: a message needs a moderation lifecycle, and putting that
-- on the integrity table would conflate "is this vote real" with "is this
-- sentence publishable". See database/migrations/2026_08_22_vote_messages.php.
CREATE TABLE IF NOT EXISTS gates_vote_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nominee_id INTEGER NOT NULL,
  category_id INTEGER NULL,
  -- NULL for a paid contribution: its votes are minted after the gateway
  -- confirms, so the message exists before the vote row does.
  vote_id INTEGER NULL,
  donation_id INTEGER NULL,
  voter_email_hash TEXT NOT NULL,
  display_name TEXT NULL,
  -- Shown only when 1, exactly like the supporters list.
  show_name INTEGER NOT NULL DEFAULT 0,
  body TEXT NOT NULL,
  source TEXT NOT NULL DEFAULT 'free' CHECK(source IN ('free','paid')),
  status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected','quarantined')),
  mod_score REAL NULL,
  mod_reason TEXT NULL,
  moderated_by INTEGER NULL,
  moderated_at TEXT NULL,
  cheers INTEGER NOT NULL DEFAULT 0,
  -- Reader reports. Counted on the row rather than in gates_reports because that
  -- table requires a member id and constrains target_type to thread|comment: a
  -- reader who arrives from a WhatsApp link and sees something about a child is not
  -- going to register in order to say so.
  reports INTEGER NOT NULL DEFAULT 0,
  reported_at TEXT NULL,
  share_token TEXT NULL,
  created_at TEXT NULL,
  deleted_at TEXT NULL,
  FOREIGN KEY(nominee_id) REFERENCES gates_nominees(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_vmsg_wall ON gates_vote_messages(nominee_id, status, created_at);
CREATE UNIQUE INDEX IF NOT EXISTS uq_vmsg_voter ON gates_vote_messages(nominee_id, voter_email_hash);
CREATE UNIQUE INDEX IF NOT EXISTS uq_vmsg_token ON gates_vote_messages(share_token);
CREATE INDEX IF NOT EXISTS idx_vmsg_queue ON gates_vote_messages(status, created_at);
-- For a FRESH database. On an existing one this statement cannot run: db:migrate
-- applies the schema files BEFORE the dated migrations, `CREATE TABLE IF NOT EXISTS`
-- skips the table, and `reports` is added two steps later by
-- database/migrations/2026_08_23_vote_message_reports.php — which also creates this
-- index. So on an upgrade this line prints a WARN and the migration does the work.
--
-- It used to take the whole upgrade down with it: the SQLite applier was a single
-- exec() of the entire file, so one unrunnable statement aborted the run two steps
-- before the migration that would have fixed it. See MigrationRunner::applySchemaFile.
CREATE INDEX IF NOT EXISTS idx_vmsg_reported ON gates_vote_messages(reports, reported_at);

-- ══════════════════════════════════════════════════════════════════════════════
-- CHALLENGES (SQLite)
-- The same five tables as `schema.sql`. SQLite has no ENUM and ignores integer
-- widths, so every column that is an ENUM there is TEXT here — which is exactly why
-- `ChallengeSchemaWordsTest` reads the words out of `Support\ChallengeEnum` and the
-- MySQL schema rather than trusting this file: a sixth action added in PHP and not
-- in the column passes every test on this harness and lands as an empty string on
-- the only database that matters.
-- ══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS gates_challenges (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL, title TEXT NOT NULL, kicker TEXT NOT NULL, summary TEXT NULL,
  action TEXT NOT NULL DEFAULT 'nominate',
  target INTEGER NOT NULL DEFAULT 1,
  mode TEXT NOT NULL DEFAULT 'first',
  cap INTEGER NULL, draw_count INTEGER NULL, draw_at TEXT NULL, draw_seed TEXT NULL,
  prize_type TEXT NOT NULL DEFAULT 'cash_each',
  prize_amount INTEGER NOT NULL DEFAULT 0,
  prize_currency TEXT NULL, prize_label TEXT NULL,
  theme TEXT NOT NULL DEFAULT 'green',
  art_url TEXT NULL, art_alt TEXT NULL, icon TEXT NULL, flag INTEGER NOT NULL DEFAULT 0,
  eligibility TEXT NULL, extra_rules TEXT NULL,
  starts_at TEXT NULL, ends_at TEXT NULL,
  timezone TEXT NOT NULL DEFAULT 'Africa/Lagos',
  terms_version TEXT NOT NULL DEFAULT '1.0',
  status TEXT NOT NULL DEFAULT 'draft', cancel_reason TEXT NULL,
  created_by INTEGER NULL, published_at TEXT NULL,
  created_at TEXT NULL, updated_at TEXT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_challenge_slug ON gates_challenges(slug);
CREATE INDEX IF NOT EXISTS idx_challenge_status ON gates_challenges(status);

CREATE TABLE IF NOT EXISTS gates_challenge_scopes (
  challenge_id INTEGER NOT NULL,
  scope_type TEXT NOT NULL,
  scope_id INTEGER NOT NULL,
  PRIMARY KEY (challenge_id, scope_type, scope_id)
);
CREATE INDEX IF NOT EXISTS idx_scope_lookup ON gates_challenge_scopes(scope_type, scope_id);

CREATE TABLE IF NOT EXISTS gates_challenge_entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  challenge_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
  phone_hash TEXT NULL, joined_at TEXT NULL,
  progress INTEGER NOT NULL DEFAULT 0, verified INTEGER NOT NULL DEFAULT 0,
  checking INTEGER NOT NULL DEFAULT 0, needs_details INTEGER NOT NULL DEFAULT 0,
  qualified_at TEXT NULL, standing INTEGER NULL,
  status TEXT NOT NULL DEFAULT 'active', disqualify_reason TEXT NULL,
  payout_status TEXT NOT NULL DEFAULT 'none',
  payout_ref TEXT NULL, payout_at TEXT NULL,
  created_at TEXT NULL, updated_at TEXT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_entry_user ON gates_challenge_entries(challenge_id, user_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_entry_phone ON gates_challenge_entries(challenge_id, phone_hash);
CREATE INDEX IF NOT EXISTS idx_entry_rank ON gates_challenge_entries(challenge_id, status, qualified_at);

CREATE TABLE IF NOT EXISTS gates_challenge_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  challenge_id INTEGER NULL, entry_id INTEGER NULL,
  kind TEXT NOT NULL, ref_type TEXT NULL, ref_id INTEGER NULL,
  actor_id INTEGER NULL, meta TEXT NULL, created_at TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_chev_entry ON gates_challenge_events(entry_id, id);
CREATE INDEX IF NOT EXISTS idx_chev_challenge ON gates_challenge_events(challenge_id, kind);

CREATE TABLE IF NOT EXISTS gates_promos (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  placement TEXT NOT NULL, kicker TEXT NULL, title TEXT NOT NULL,
  sub TEXT NULL, cta TEXT NULL, href TEXT NULL,
  theme TEXT NOT NULL DEFAULT 'green', art_url TEXT NULL,
  challenge_id INTEGER NULL, priority INTEGER NOT NULL DEFAULT 0,
  audience TEXT NOT NULL DEFAULT 'all',
  starts_at TEXT NULL, ends_at TEXT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NULL, updated_at TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_promo_slot ON gates_promos(placement, active, priority);

-- ─── Recognitions from verified issuers (REFERENCE §11; 2027_03_06_recognitions.php) ───
CREATE TABLE IF NOT EXISTS gates_recognition_issuers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  issuer_type TEXT NOT NULL DEFAULT 'organisation',
  name TEXT NOT NULL,
  programme_id INTEGER NULL, partner_org_id INTEGER NULL,
  url TEXT NULL,
  verified_at TEXT NULL, verified_basis TEXT NULL,
  created_at TEXT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_rci_programme ON gates_recognition_issuers(programme_id);
CREATE TABLE IF NOT EXISTS gates_recognitions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  issuer_id INTEGER NOT NULL,
  recipient_profile_id INTEGER NULL, recipient_nominee_id INTEGER NULL,
  recipient_name TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'award', standing TEXT NULL,
  title TEXT NOT NULL, citation TEXT NULL,
  issued_at TEXT NULL,
  reference TEXT NOT NULL,
  visibility TEXT NOT NULL DEFAULT 'public',
  evidence_ids TEXT NULL,
  cycle_id INTEGER NULL, category_id INTEGER NULL,
  withdrawn_at TEXT NULL, withdrawn_reason TEXT NULL,
  created_at TEXT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_rec_reference ON gates_recognitions(reference);
CREATE INDEX IF NOT EXISTS idx_rec_profile ON gates_recognitions(recipient_profile_id);
CREATE INDEX IF NOT EXISTS idx_rec_nominee ON gates_recognitions(recipient_nominee_id);
CREATE INDEX IF NOT EXISTS idx_rec_issued ON gates_recognitions(issued_at);
CREATE TABLE IF NOT EXISTS gates_recognition_withdrawals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  recognition_id INTEGER NOT NULL,
  reason TEXT NOT NULL,
  withdrawn_at TEXT NOT NULL,
  actor_admin_id INTEGER NULL
);
CREATE INDEX IF NOT EXISTS idx_rcw_rec ON gates_recognition_withdrawals(recognition_id);

-- ─── "Get status updates" on /status (double opt-in; migrations/2027_03_07_status_alerts.php) ───
CREATE TABLE IF NOT EXISTS gates_status_alerts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL,
  email_hash TEXT NOT NULL,
  token TEXT NOT NULL,
  ip_hash TEXT NULL,
  created_at TEXT NULL,
  confirm_sent_at TEXT NULL,
  confirmed_at TEXT NULL,
  cancelled_at TEXT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_sta_who ON gates_status_alerts(email_hash);
CREATE UNIQUE INDEX IF NOT EXISTS uq_sta_token ON gates_status_alerts(token);
CREATE INDEX IF NOT EXISTS idx_sta_live ON gates_status_alerts(confirmed_at, cancelled_at);
