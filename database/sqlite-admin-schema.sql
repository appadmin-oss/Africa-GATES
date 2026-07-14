-- AFRICA GATES — Admin / auth / audit / judges schema (SQLite)
-- For MySQL: same DDL with ENUMs/TEXT mapped accordingly.

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS gates_admins (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE,
  password_hash TEXT,
  name TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'editor' CHECK(role IN ('superadmin','admin','editor','moderator','judge','viewer')),
  avatar_path TEXT,
  is_active INTEGER NOT NULL DEFAULT 1,
  last_login_at TEXT,
  last_login_ip TEXT,
  failed_attempts INTEGER NOT NULL DEFAULT 0,
  locked_until TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_admins_role ON gates_admins(role);
CREATE INDEX IF NOT EXISTS idx_admins_active ON gates_admins(is_active);

CREATE TABLE IF NOT EXISTS gates_magic_links (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL,
  token_hash TEXT NOT NULL UNIQUE,
  purpose TEXT NOT NULL DEFAULT 'admin_login' CHECK(purpose IN ('admin_login','password_reset')),
  expires_at TEXT NOT NULL,
  used_at TEXT,
  ip_hash TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_magic_email ON gates_magic_links(email);
CREATE INDEX IF NOT EXISTS idx_magic_exp ON gates_magic_links(expires_at);

CREATE TABLE IF NOT EXISTS gates_audit_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  admin_id INTEGER,
  action TEXT NOT NULL,
  target_type TEXT,
  target_id INTEGER,
  meta TEXT,
  ip_hash TEXT,
  ua TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(admin_id) REFERENCES gates_admins(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_audit_admin ON gates_audit_log(admin_id);
CREATE INDEX IF NOT EXISTS idx_audit_action ON gates_audit_log(action);
CREATE INDEX IF NOT EXISTS idx_audit_created ON gates_audit_log(created_at);

CREATE TABLE IF NOT EXISTS gates_judges (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  admin_id INTEGER,
  name TEXT NOT NULL,
  email TEXT NOT NULL UNIQUE,
  title TEXT,
  organisation TEXT,
  bio TEXT,
  avatar_path TEXT,
  country_code TEXT,
  programme_ids TEXT,
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(admin_id) REFERENCES gates_admins(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_judges_active ON gates_judges(is_active);

-- NOTE: the legacy single-score `gates_judge_scores` table was removed — the
-- platform scores per criterion via gates_judge_criteria + _criteria_scores +
-- _notes (see sqlite-community-schema.sql). It had zero code references.

CREATE TABLE IF NOT EXISTS gates_settings (
  key_name TEXT PRIMARY KEY,
  value TEXT,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INTEGER,
  FOREIGN KEY(updated_by) REFERENCES gates_admins(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS gates_uploads (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  uploader_id INTEGER,
  uploader_type TEXT NOT NULL DEFAULT 'admin' CHECK(uploader_type IN ('admin','public','system')),
  path TEXT NOT NULL,
  mime TEXT,
  size_bytes INTEGER,
  width INTEGER,
  height INTEGER,
  alt TEXT,
  attached_to_type TEXT,
  attached_to_id INTEGER,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_uploads_attached ON gates_uploads(attached_to_type, attached_to_id);

-- Outbound webhooks (admin-managed integration endpoints) + delivery log.
CREATE TABLE IF NOT EXISTS gates_webhooks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  url TEXT NOT NULL,
  secret TEXT NOT NULL,
  events TEXT NOT NULL DEFAULT '*',
  description TEXT,
  is_active INTEGER NOT NULL DEFAULT 1,
  last_status INTEGER,
  last_event_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS gates_webhook_deliveries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  webhook_id INTEGER NOT NULL,
  event TEXT NOT NULL,
  status_code INTEGER,
  ok INTEGER NOT NULL DEFAULT 0,
  error TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_delivery_hook ON gates_webhook_deliveries(webhook_id, created_at);
