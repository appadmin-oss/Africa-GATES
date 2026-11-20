-- AFRICA GATES — Community + Judging-rubric extensions (MySQL 8+)
-- Idempotent.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

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
  score TINYINT NOT NULL CHECK (score BETWEEN 0 AND 10),
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

-- Conflict-of-interest recusals (per judge, per programme). Server-side gate that
-- removes a recused judge's ability to score any nominee in the programme.
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
  author_user_id BIGINT UNSIGNED DEFAULT NULL,
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
  -- Which of the four reactions this person holds. Deliberately NOT part of
  -- uq_cheer: one reaction per person per thing, changeable. Inside the key it
  -- would let one person hold all four at once, and the counts would stop
  -- summing to the number of people.
  kind VARCHAR(12) NOT NULL DEFAULT 'cheer',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cheer (target_type, target_id, fp),
  KEY idx_cheers_target (target_type, target_id),
  KEY idx_cheers_kind (target_type, target_id, kind)
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
  author_user_id BIGINT UNSIGNED DEFAULT NULL,
  status ENUM('approved','quarantined','rejected','deleted','locked') NOT NULL DEFAULT 'approved',
  ai_score DECIMAL(4,3) DEFAULT NULL,
  reply_count INT UNSIGNED NOT NULL DEFAULT 0,
  cheer_count INT UNSIGNED NOT NULL DEFAULT 0,
  repost_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_activity TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  -- Photo/video on a post. Type is stored rather than sniffed from the extension,
  -- and the dimensions let the feed reserve the box so it does not reflow as
  -- media loads. See database/migrations/2026_08_01_thread_media.php.
  media_path VARCHAR(500) NULL DEFAULT NULL,
  media_type VARCHAR(10) NULL DEFAULT NULL,
  media_w INT NULL DEFAULT NULL,
  media_h INT NULL DEFAULT NULL,
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

-- Polls (one per target — thread or blog post; options as a JSON array; multi = WhatsApp-style multiple answers)
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

-- Member follows / bookmarks / reposts
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
  -- A line of your own. A repost without one is a bookmark with extra steps.
  comment VARCHAR(500) NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_repost (user_id, thread_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Member reports: one report per member per target; content quarantines at the
-- threshold (see CommunityService::report), operators review in /admin/moderation.
CREATE TABLE IF NOT EXISTS gates_reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_type ENUM('thread','comment') NOT NULL,
  target_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  reason VARCHAR(300) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), UNIQUE KEY uq_report(target_type, target_id, user_id),
  KEY idx_reports_target(target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
