-- AFRICA GATES — Admin / auth / audit / judges / settings schema (MySQL 8+)
-- Idempotent: safe to run on an existing production DB that's missing these tables.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

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

-- (Removed) gates_judge_scores — the legacy single-score table is dead (zero code
-- references; scoring uses gates_judge_criteria_scores). Kept out of fresh installs
-- to match the SQLite schema, which already omits it.

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
  -- Where the bytes actually live. `path` holds whichever URL is serveable (a
  -- Cloudinary secure_url, or a local /uploads/... path), so these three exist to
  -- answer the questions `path` alone cannot: which host owns it, what to call to
  -- delete it, and where the original landed on disk.
  provider ENUM('local','cloudinary') NOT NULL DEFAULT 'local',
  public_id VARCHAR(255) DEFAULT NULL,
  local_path VARCHAR(500) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_uploads_attached (attached_to_type, attached_to_id),
  KEY idx_uploads_provider (provider),
  KEY idx_uploads_public_id (public_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ledger for the local → Cloudinary sweep (AfricaGates\Services\MediaMigrationService).
--
-- WHY A LEDGER AND NOT JUST THE REWRITTEN COLUMNS. The sweep rewrites image paths in
-- eleven columns across nine tables. Without a record of what it did, three ordinary
-- situations become unrecoverable: a batch interrupted halfway leaves no way to know
-- which rows were done, a re-run cannot tell "already migrated" from "never had a
-- photo", and an operator who needs to point the site back at local files has nothing
-- to reverse. `source_path` is UNIQUE so the same local file is uploaded exactly once
-- however many times the sweep is run across however many rows referenced it.
CREATE TABLE IF NOT EXISTS gates_media_migrations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_path VARCHAR(500) NOT NULL,
  public_id VARCHAR(255) DEFAULT NULL,
  remote_url VARCHAR(500) DEFAULT NULL,
  target_table VARCHAR(64) DEFAULT NULL,
  target_column VARCHAR(64) DEFAULT NULL,
  target_id BIGINT UNSIGNED DEFAULT NULL,
  status ENUM('migrated','missing','failed','skipped') NOT NULL DEFAULT 'migrated',
  error VARCHAR(300) DEFAULT NULL,
  bytes INT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_media_source (source_path),
  KEY idx_media_status (status)
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

SET FOREIGN_KEY_CHECKS = 1;
