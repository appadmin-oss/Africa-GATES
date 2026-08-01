-- Africa GATES — Community + Judging extensions (SQLite)

PRAGMA foreign_keys = ON;

-- Judge scoring criteria (per programme — admins can adjust the rubric)
CREATE TABLE IF NOT EXISTS gates_judge_criteria (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  programme_id INTEGER,
  slug TEXT NOT NULL,
  label TEXT NOT NULL,
  description TEXT,
  weight INTEGER NOT NULL DEFAULT 25,
  sort_order INTEGER NOT NULL DEFAULT 0,
  is_active INTEGER NOT NULL DEFAULT 1,
  FOREIGN KEY(programme_id) REFERENCES gates_award_programmes(id) ON DELETE CASCADE
);

-- Per-criterion judge scores (replaces the simple single-score table)
CREATE TABLE IF NOT EXISTS gates_judge_criteria_scores (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  judge_id INTEGER NOT NULL,
  nominee_id INTEGER NOT NULL,
  category_id INTEGER NOT NULL,
  criterion_id INTEGER NOT NULL,
  score INTEGER NOT NULL CHECK(score BETWEEN 0 AND 10),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(judge_id, nominee_id, criterion_id),
  FOREIGN KEY(judge_id) REFERENCES gates_judges(id) ON DELETE CASCADE,
  FOREIGN KEY(nominee_id) REFERENCES gates_nominees(id) ON DELETE CASCADE,
  FOREIGN KEY(category_id) REFERENCES gates_award_categories(id) ON DELETE CASCADE,
  FOREIGN KEY(criterion_id) REFERENCES gates_judge_criteria(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_jcrit_nominee ON gates_judge_criteria_scores(nominee_id);
CREATE INDEX IF NOT EXISTS idx_jcrit_judge ON gates_judge_criteria_scores(judge_id);

-- Notes (per-judge, per-nominee)
CREATE TABLE IF NOT EXISTS gates_judge_notes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  judge_id INTEGER NOT NULL,
  nominee_id INTEGER NOT NULL,
  notes TEXT,
  submitted_at TEXT,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(judge_id, nominee_id),
  FOREIGN KEY(judge_id) REFERENCES gates_judges(id) ON DELETE CASCADE,
  FOREIGN KEY(nominee_id) REFERENCES gates_nominees(id) ON DELETE CASCADE
);

-- Conflict-of-interest recusals (per judge, per programme). A declared row
-- removes that judge's ability to score any nominee in the programme.
CREATE TABLE IF NOT EXISTS gates_judge_coi (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  judge_id INTEGER NOT NULL,
  programme_id INTEGER NOT NULL,
  reason TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(judge_id, programme_id),
  FOREIGN KEY(judge_id) REFERENCES gates_judges(id) ON DELETE CASCADE
);

-- Comments (on profiles + legacy events + threads)
CREATE TABLE IF NOT EXISTS gates_comments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  target_type TEXT NOT NULL CHECK(target_type IN ('profile','legacy','thread','nominee')),
  target_id INTEGER NOT NULL,
  parent_id INTEGER,
  author_name TEXT NOT NULL,
  author_email TEXT,
  author_email_hash TEXT,
  author_user_id INTEGER,
  body TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'approved' CHECK(status IN ('approved','quarantined','rejected','deleted')),
  ai_score REAL,
  ai_reason TEXT,
  ip_hash TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(parent_id) REFERENCES gates_comments(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_comments_target ON gates_comments(target_type, target_id);
CREATE INDEX IF NOT EXISTS idx_comments_status ON gates_comments(status);
CREATE INDEX IF NOT EXISTS idx_comments_created ON gates_comments(created_at DESC);

-- Cheers (like-style reactions)
CREATE TABLE IF NOT EXISTS gates_cheers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  target_type TEXT NOT NULL CHECK(target_type IN ('profile','nominee','comment','thread')),
  target_id INTEGER NOT NULL,
  fp TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(target_type, target_id, fp)
);
CREATE INDEX IF NOT EXISTS idx_cheers_target ON gates_cheers(target_type, target_id);

-- Public activity feed (Pulse)
CREATE TABLE IF NOT EXISTS gates_activity (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind TEXT NOT NULL CHECK(kind IN ('vote','nomination','register','comment','cheer','winner','legacy','opportunity')),
  actor_label TEXT,
  target_type TEXT,
  target_id INTEGER,
  target_label TEXT,
  meta TEXT,
  is_public INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_activity_created ON gates_activity(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_activity_kind ON gates_activity(kind);

-- Forum threads (per programme)
CREATE TABLE IF NOT EXISTS gates_threads (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  programme_id INTEGER,
  slug TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  body TEXT,
  author_name TEXT NOT NULL,
  author_email_hash TEXT NOT NULL,
  author_user_id INTEGER,
  status TEXT NOT NULL DEFAULT 'approved' CHECK(status IN ('approved','quarantined','rejected','deleted','locked')),
  ai_score REAL,
  reply_count INTEGER NOT NULL DEFAULT 0,
  cheer_count INTEGER NOT NULL DEFAULT 0,
  repost_count INTEGER NOT NULL DEFAULT 0,
  last_activity TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_pinned INTEGER NOT NULL DEFAULT 0,
  -- Photo/video on a post — see database/migrations/2026_08_01_thread_media.php.
  media_path TEXT NULL DEFAULT NULL,
  media_type TEXT NULL DEFAULT NULL,
  media_w INTEGER NULL DEFAULT NULL,
  media_h INTEGER NULL DEFAULT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(programme_id) REFERENCES gates_award_programmes(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_threads_programme ON gates_threads(programme_id);
CREATE INDEX IF NOT EXISTS idx_threads_activity ON gates_threads(last_activity DESC);
CREATE INDEX IF NOT EXISTS idx_threads_status ON gates_threads(status);

-- AI moderation log (audit trail)
CREATE TABLE IF NOT EXISTS gates_moderation_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  target_type TEXT NOT NULL,
  target_id INTEGER NOT NULL,
  provider TEXT NOT NULL DEFAULT 'heuristic',
  decision TEXT NOT NULL CHECK(decision IN ('allow','quarantine','reject')),
  score REAL,
  reason TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_modlog_target ON gates_moderation_log(target_type, target_id);

-- Polls (one per target — thread or blog post; options as a JSON array; multi = WhatsApp-style multiple answers)
CREATE TABLE IF NOT EXISTS gates_polls (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  target_type TEXT NOT NULL DEFAULT 'thread',
  target_id INTEGER NOT NULL,
  question TEXT NOT NULL,
  options TEXT NOT NULL,
  multi INTEGER NOT NULL DEFAULT 0,
  is_closed INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(target_type, target_id)
);
CREATE TABLE IF NOT EXISTS gates_poll_votes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  poll_id INTEGER NOT NULL,
  option_index INTEGER NOT NULL,
  fp TEXT NOT NULL,
  user_id INTEGER,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(poll_id, fp, option_index)
);
CREATE INDEX IF NOT EXISTS idx_pollvotes_poll ON gates_poll_votes(poll_id);

-- Member follows (programme / thread / member / nominee), bookmarks + reposts
CREATE TABLE IF NOT EXISTS gates_follows (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  target_type TEXT NOT NULL,
  target_id INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(user_id, target_type, target_id)
);
CREATE INDEX IF NOT EXISTS idx_follows_target ON gates_follows(target_type, target_id);
CREATE TABLE IF NOT EXISTS gates_bookmarks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  thread_id INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(user_id, thread_id)
);
CREATE TABLE IF NOT EXISTS gates_reposts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  thread_id INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(user_id, thread_id)
);

-- Member reports: one report per member per target; content quarantines at the
-- threshold (see CommunityService::report), operators review in /admin/moderation.
CREATE TABLE IF NOT EXISTS gates_reports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  target_type TEXT NOT NULL CHECK(target_type IN ('thread','comment')),
  target_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  reason TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(target_type, target_id, user_id)
);
CREATE INDEX IF NOT EXISTS idx_reports_target ON gates_reports(target_type, target_id);
