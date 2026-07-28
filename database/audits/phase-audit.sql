-- ============================================================================
-- Africa GATES — phase audit, as plain SQL.
--
-- WHAT THIS IS FOR. `bin/console cycles:audit` is the real tool and reports more.
-- This file exists for the case where you want the numbers BEFORE deploying the
-- branch that contains that command: it needs nothing but a SQL client and a
-- read-only connection, so it can be run against a replica today.
--
-- STRICTLY READ-ONLY. Every statement is a SELECT. Nothing here writes, and it is
-- safe to run against production. Run it on a replica if you have one, purely to
-- keep the load off the primary.
--
-- WHAT IT DELIBERATELY DOES NOT DO: decide a cycle's phase. That logic lives in
-- CyclePolicy::phaseFor() and must have exactly ONE implementation — the entire
-- point of the restructure was that the phase stopped being computed in several
-- places that disagreed, and re-deriving it here in CASE expressions would
-- recreate that defect in a file nobody would remember to update. For
-- stored-status-vs-computed-phase drift, run the console command.
--
-- Every window test below is HALF-OPEN, [open, close): a vote AT the closing
-- instant is late. That matches CyclePolicy, so this reports the same population
-- the BallotGuard now refuses. Only DECLARED boundaries are used — a cycle with a
-- NULL boundary is reported by §1 as unjudgeable rather than assigned an inferred
-- window, because inventing a deadline inside an audit manufactures offences no
-- operator ever announced.
--
-- Portable between MySQL and SQLite (no backticks, no vendor-only functions), so
-- the same file can be checked against a test database before it is pointed at
-- production.
-- ============================================================================


-- ── §0. Clock ───────────────────────────────────────────────────────────────
-- READ THIS FIRST. Every finding below compares a stored timestamp against a
-- stored boundary. Rows written by a DB-side CURRENT_TIMESTAMP default are only
-- comparable to boundaries written by PHP if these agree. SQLite's
-- CURRENT_TIMESTAMP is always UTC; MySQL's follows the session time_zone, and
-- this schema mixes DATETIME and TIMESTAMP columns. A whole-hour difference from
-- the wall clock you expect means some findings below are timezone artefacts
-- rather than real offences.
SELECT 'clock' AS section, CURRENT_TIMESTAMP AS db_now;


-- ── §1. Cycles with an undeclared boundary ──────────────────────────────────
-- Not clean — UNCHECKABLE. With no closing date there is no instant at which
-- anything became late, so no offence can be attributed to these cycles. Equally,
-- nothing was ever going to close on its own.
-- Two columns rather than one concatenated string: `||` is string concatenation
-- in SQLite but boolean OR in MySQL (absent PIPES_AS_CONCAT), so a concatenated
-- "missing" column would return 0 on production instead of failing loudly.
SELECT 'undated' AS section,
       c.id AS cycle_id, c.year, c.status,
       CASE WHEN c.nominations_close IS NULL THEN 'MISSING' ELSE 'ok' END AS nominations_close,
       CASE WHEN c.voting_close      IS NULL THEN 'MISSING' ELSE 'ok' END AS voting_close
FROM gates_award_cycles c
WHERE (c.nominations_close IS NULL OR c.voting_close IS NULL)
  AND c.status <> 'archived'
ORDER BY c.year DESC;


-- ── §2. Votes cast AFTER voting closed ──────────────────────────────────────
-- Count = how many offences. Weight = how far the standings actually moved; one
-- paid row can carry hundreds, so the two numbers answer different questions.
SELECT 'votes_after_close' AS section,
       cy.id AS cycle_id, cy.year, v.vote_type,
       COUNT(*) AS votes, COALESCE(SUM(v.weight), 0) AS weight,
       MAX(cy.voting_close) AS closed_at,
       MIN(v.voted_at) AS first_late, MAX(v.voted_at) AS last_late
FROM gates_votes v
JOIN gates_award_categories cat ON cat.id = v.category_id
JOIN gates_award_cycles     cy  ON cy.id  = cat.cycle_id
WHERE cy.voting_close IS NOT NULL
  AND v.voted_at >= cy.voting_close
GROUP BY cy.id, cy.year, v.vote_type
ORDER BY cy.year DESC, weight DESC;


-- ── §3. Votes cast BEFORE voting opened ─────────────────────────────────────
-- A different bug from a late vote: a window edited backwards after voting had
-- already begun.
SELECT 'votes_before_open' AS section,
       cy.id AS cycle_id, cy.year, v.vote_type,
       COUNT(*) AS votes, COALESCE(SUM(v.weight), 0) AS weight,
       MAX(cy.voting_open) AS opened_at,
       MIN(v.voted_at) AS first_at, MAX(v.voted_at) AS last_at
FROM gates_votes v
JOIN gates_award_categories cat ON cat.id = v.category_id
JOIN gates_award_cycles     cy  ON cy.id  = cat.cycle_id
WHERE cy.voting_open IS NOT NULL
  AND v.voted_at < cy.voting_open
GROUP BY cy.id, cy.year, v.vote_type
ORDER BY cy.year DESC;


-- ── §4. Nominations taken after nominations closed ──────────────────────────
-- Broken down by status on purpose: 40 pending rows and 40 approved finalists
-- are entirely different decisions.
SELECT 'nominations_late' AS section,
       cy.id AS cycle_id, cy.year, n.status,
       COUNT(*) AS nominations,
       MAX(cy.nominations_close) AS closed_at,
       MIN(n.created_at) AS first_late, MAX(n.created_at) AS last_late
FROM gates_nominations n
JOIN gates_award_cycles cy ON cy.id = n.cycle_id
WHERE cy.nominations_close IS NOT NULL
  AND n.created_at >= cy.nominations_close
GROUP BY cy.id, cy.year, n.status
ORDER BY cy.year DESC, nominations DESC;


-- ── §5. Paid-vote orders confirmed but NEVER minted ─────────────────────────
-- Money taken, nothing delivered. PaidVoteService::mint() refuses to push
-- weighted votes into a closed cycle and leaves votes_used = 0 on purpose, so a
-- CONFIRMED 'paid-vote' row with votes_used = 0 IS the "refund owed" signal.
-- Already-refunded orders are excluded: those are settled.
--
-- The console command additionally tags each row re-mint / refund / investigate
-- by asking whether the target category is votable again. That needs the phase
-- policy, so it is not reproduced here — check voting_close below by eye.
SELECT 'paid_unminted' AS section,
       d.id AS donation_id, d.payment_ref, d.amount_naira, d.bonus_votes AS votes,
       d.created_at AS paid_at,
       nm.name AS nominee, cy.year, cy.voting_close
FROM gates_donations d
LEFT JOIN gates_nominees          nm  ON nm.id  = d.intent_nominee_id
LEFT JOIN gates_award_categories  cat ON cat.id = nm.category_id
LEFT JOIN gates_award_cycles      cy  ON cy.id  = cat.cycle_id
WHERE d.tier = 'paid-vote'
  AND d.status = 'confirmed'
  AND d.votes_used = 0
  AND d.refunded_at IS NULL
ORDER BY d.id;

-- The bill, in one row.
SELECT 'paid_unminted_total' AS section,
       COUNT(*) AS orders,
       COALESCE(SUM(d.amount_naira), 0) AS naira,
       COALESCE(SUM(d.bonus_votes), 0)  AS votes_owed
FROM gates_donations d
WHERE d.tier = 'paid-vote' AND d.status = 'confirmed'
  AND d.votes_used = 0 AND d.refunded_at IS NULL;


-- ── §6. Paid votes that DID mint after voting closed ────────────────────────
-- The mirror image, and the worse one: the money was kept AND a closed public
-- tally moved. No clean remedy exists — voiding changes a published standing,
-- keeping it means a closed result was bought after the fact.
--
-- Keyed on the VOTE's voted_at, not the order date: the order may legitimately
-- predate the deadline. It is the MINT that was late.
SELECT 'paid_minted_late' AS section,
       v.id AS vote_id, v.donation_id, d.payment_ref,
       cy.id AS cycle_id, cy.year, v.nominee_id, v.weight,
       d.amount_naira, cy.voting_close AS closed_at, v.voted_at,
       CASE WHEN d.refunded_at IS NULL THEN 'no' ELSE 'yes' END AS refunded
FROM gates_votes v
JOIN gates_award_categories cat ON cat.id = v.category_id
JOIN gates_award_cycles     cy  ON cy.id  = cat.cycle_id
LEFT JOIN gates_donations   d   ON d.id   = v.donation_id
WHERE v.vote_type = 'paid'
  AND cy.voting_close IS NOT NULL
  AND v.voted_at >= cy.voting_close
ORDER BY v.id;


-- ── §7. Finished categories with no winner ──────────────────────────────────
-- The historic 'results' backlog. Winner promotion only happens when the
-- materialiser CLAIMS the results transition, and for every cycle that finished
-- while nothing was closing, that claim never happened.
--
-- An empty category is not a finding — nobody was ever going to win it. A merged
-- away duplicate (merged_into IS NOT NULL) is not a candidate either: counting it
-- would report a category as awaiting a winner when its only entrant no longer
-- exists.
--
-- APPROXIMATION: "finished" here means voting_close has passed. The console
-- command uses the real phase policy, which also accounts for results_date and a
-- manually-archived cycle, so treat any difference between the two as the command
-- being right.
SELECT 'uncrowned' AS section,
       cy.id AS cycle_id, cy.year, cat.id AS category_id, cat.title AS category,
       SUM(CASE WHEN n.status IN ('approved','winner','runner_up') THEN 1 ELSE 0 END) AS eligible,
       cy.voting_close AS closed_at, cy.results_date
FROM gates_award_categories cat
JOIN gates_award_cycles cy ON cy.id = cat.cycle_id
LEFT JOIN gates_nominees n ON n.category_id = cat.id AND n.merged_into IS NULL
WHERE cy.voting_close IS NOT NULL
  AND cy.voting_close < CURRENT_TIMESTAMP
GROUP BY cy.id, cy.year, cat.id, cat.title, cy.voting_close, cy.results_date
HAVING SUM(CASE WHEN n.status IN ('approved','winner','runner_up') THEN 1 ELSE 0 END) > 0
   AND SUM(CASE WHEN n.status = 'winner' THEN 1 ELSE 0 END) = 0
ORDER BY cy.year DESC, cat.id;
