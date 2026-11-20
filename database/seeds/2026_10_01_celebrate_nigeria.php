<?php
declare(strict_types=1);

/**
 * Seed: the "Celebrate Nigeria" Independence Day challenge, on the Alimosho Awards.
 *
 * Shipped from `handoff-oct-2026/challenge/seed/2026_10_01_celebrate_nigeria.php`. The
 * LOGIC is the handoff's and is not to be edited here. The COPY AND THE NUMBERS are the
 * handoff's DEFAULTS: they live in `data/2026_10_01_celebrate_nigeria.php`, and an
 * operator checks — and may correct — them on /admin/challenges before this runs, because
 * once it has run they are the published terms and locked. Only the table and column
 * names were adapted to what this repo actually has, which is what that handoff asked
 * for. The adaptations, each one a real difference:
 *
 *   gates_awards              → gates_award_programmes   (and `name` → `title`)
 *   gates_award_cycles.award_id          → programme_id
 *   gates_award_cycles.nominations_open_at  → nominations_open
 *   gates_award_cycles.nominations_close_at → nominations_close
 *   gates_award_cycles.edition_number       → year
 *   gates_challenge_events: has its own `challenge_id` column and a `once_key`, so the
 *   audit row is written against the challenge and keyed, rather than hung off `ref_id`.
 *
 * Plus two things the handoff asks for in its own prose and the original file had no
 * column for: `art_alt` (the alt text is specified — "Àlímọ̀ṣọ́ celebrates Nigeria") and
 * the programme's host, so "Hosted by Okun Alimosho" has a source.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * IDEMPOTENT, AND WHAT THAT MEANS HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Upserts by slug. Running it twice changes no row count and — the part that matters —
 * never resets a live challenge: on a second run it rewrites the words and the art and
 * TOUCHES NEITHER the status, the cap, the dates, nor anybody's entry. A seed that
 * reopened a closed challenge or re-randomised a draw would be a seed that can take an
 * award back off somebody.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * IT REFUSES RATHER THAN INVENTING, AND THAT IS THE POINT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * No Alimosho Awards programme, or no edition open for nominations, and it throws. The
 * handoff's rule is "create the edition through admin, never inside the seed", and the
 * reason is this codebase's own: an edition minted by a script has no operator behind it,
 * no dates anybody chose and no categories anybody wrote, and the first thing it does is
 * accept real nominations from real people into an award nobody announced.
 *
 * Datetimes are stored as UTC with a SPACE separator. A `T` is normalised by MySQL in a
 * TIMESTAMP column and stored verbatim by SQLite, so the two databases compare differently
 * on a string nothing here would ever notice was wrong.
 *
 * @param PDO $db an open connection, inside no transaction of its own
 */
return static function (PDO $db): void {
    $slug  = 'celebrate-nigeria-2026';
    $now   = gmdate('Y-m-d H:i:s');

    // ── THE VALUES ARE THE OPERATOR'S TO CHECK FIRST ─────────────────────────
    //
    // Everything a person may want to correct — the words, the numbers, the window, the
    // art and the flier's lines — is in `data/2026_10_01_celebrate_nigeria.php`, with the
    // handoff's value as each default, and an operator's edits from
    // /admin/challenges/seeds/2026_10_01_celebrate_nigeria laid over it. The sweep does
    // not run this seed until that form has been approved: see SeedReview.
    $seedName = basename(__FILE__, '.php');
    $v = \AfricaGates\Support\SeedReview::values($seedName);
    $fill = static fn (string $t): string => \AfricaGates\Support\SeedReview::fill($t, $v);

    // Typed as Lagos wall-clock time and stored as UTC with a SPACE separator. WAT has
    // no daylight saving, so the handoff's 1 Oct 00:00 and 15 Oct 23:59:59 are exactly
    // 30 Sep 23:00 and 15 Oct 22:59:59 UTC.
    $start = \AfricaGates\Support\SeedReview::utc($seedName, (string) $v['starts_at']);
    $end   = \AfricaGates\Support\SeedReview::utc($seedName, (string) $v['ends_at']);

    $artUrl = (string) $v['art_url'];
    $artAlt = (string) $v['art_alt'];

    // The flier's lines keep their placeholders: ChallengeCopy::flier() fills them from
    // the row at render time, so they follow the numbers if the row is ever corrected.
    $flier = [
        ':headline'     => (string) $v['headline'],
        ':standfirst'   => (string) $v['standfirst'],
        ':tagline'      => (string) $v['tagline'],
        ':portrait'     => (string) $v['portrait_url'],
        ':portrait_alt' => (string) $v['portrait_alt'],
    ];

    // ── 1. Resolve the scope: Alimosho Awards, the edition open for nominations ──
    $award = $db->prepare(
        'SELECT id FROM gates_award_programmes WHERE slug = :s OR title = :n LIMIT 1');
    $award->execute([':s' => 'alimosho-awards', ':n' => 'Alimosho Awards']);
    $programmeId = $award->fetchColumn();

    // ── BOTH REFUSALS NAME WHAT THEY LOOKED FOR ──────────────────────────────
    //
    // They are shown verbatim on /admin/challenges, which is the only place an operator
    // on this host can read them. "Alimosho Awards not found" sends somebody to check an
    // award they can see in the list — named "Alimosho Awards 2026", say, which is not
    // what this matches. Saying the exact slug and title turns the sentence into the fix.
    if (!$programmeId) {
        throw new RuntimeException(
            'No award programme has the slug "alimosho-awards" or the title "Alimosho Awards". '
            . 'Create it (or correct its slug) in Programmes, open an edition for nominations, '
            . 'then press Run now. This seed will not invent an award.');
    }

    // Open for nominations by the DATES, which is the question this seed is asking —
    // "may a nomination be made into this edition right now". `status` is a cache of a
    // computed phase (see CycleService) and an edition can sit on the wrong word for a
    // day; the boundary columns are what the nomination door itself honours.
    $cycle = $db->prepare(
        'SELECT id FROM gates_award_cycles
          WHERE programme_id = :a
            AND nominations_open IS NOT NULL AND nominations_open <= :n
            AND (nominations_close IS NULL OR nominations_close >= :n2)
          ORDER BY year DESC, id DESC LIMIT 1');
    $cycle->execute([':a' => $programmeId, ':n' => $now, ':n2' => $now]);
    $cycleId = $cycle->fetchColumn();

    if (!$cycleId) {
        // List what IS there, with its window: the commonest cause is an edition whose
        // dates are a day off, and that is invisible from a sentence saying "none open".
        $seen = $db->prepare(
            'SELECT year, nominations_open, nominations_close FROM gates_award_cycles
              WHERE programme_id = :a ORDER BY year DESC, id DESC LIMIT 3');
        $seen->execute([':a' => $programmeId]);
        $editions = array_map(
            static fn (array $e): string => $e['year'] . ' (nominations '
                . ($e['nominations_open'] ?: 'not set') . ' → '
                . ($e['nominations_close'] ?: 'no close') . ')',
            $seen->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
        throw new RuntimeException(
            'Alimosho Awards has no edition open for nominations at ' . $now . ' UTC. '
            . ($editions ? 'Editions found: ' . implode('; ', $editions) . '. ' : 'It has no editions. ')
            . 'Set the nominations window to include today, then press Run now.');
    }

    // ── IT OWNS THE TRANSACTION ONLY IF THERE IS NOT ONE ALREADY ───────────
    //
    // PDO's `beginTransaction()` is a THROW inside an open transaction, not a no-op, so a
    // seed that opens one unconditionally cannot run under any caller that has one — and
    // the test harness wraps every case in exactly that. Found by the MySQL parity run:
    // eleven cases red on the only database that matters and all eleven green on SQLite,
    // because that harness opens no transaction there. The failure was total — the seed
    // simply did not run — and the SQLite suite reported a healthy feature.
    //
    // So the atomicity is kept where it is this file's to keep (a standalone run, which
    // is how it reaches production through `SeedRunner`) and deferred to the caller where
    // it is not. Rolling back somebody else's transaction would be the worse bug.
    $ownTransaction = !$db->inTransaction();

    if ($ownTransaction) {
        $db->beginTransaction();
    }

    try {
        // ── 2. The challenge (upsert by slug) ──
        $row = [
            ':slug'    => $slug,
            ':title'   => (string) $v['title'],
            ':kicker'  => (string) $v['kicker'],
            ':summary' => $fill((string) $v['summary']),
            ':action'  => 'nominate',
            ':target'  => (int) $v['target'],
            ':mode'    => 'first',
            ':cap'     => (int) $v['cap'],
            ':ptype'   => 'cash_each',
            // WHOLE NAIRA, not kobo: `prize_amount` is an INT of the challenge's own
            // currency unit and `ChallengeCopy` prints it with a thousands separator. Six
            // thousand kobo here would read as "₦6,000" on the page and pay sixty naira.
            ':pamount' => (int) $v['prize_amount'],
            ':pcur'    => 'NGN',
            ':plabel'  => '₦' . number_format((int) $v['prize_amount']) . ' each',
            ':theme'   => 'green',
            ':art'     => $artUrl,
            ':artalt'  => $artAlt,
            ':icon'    => null,
            ':headline'     => $flier[':headline'],
            ':standfirst'   => $flier[':standfirst'],
            ':tagline'      => $flier[':tagline'],
            ':portrait'     => $flier[':portrait'],
            ':portrait_alt' => $flier[':portrait_alt'],
            ':flag'    => 1,
            ':elig'    => json_encode([
                'country' => ['NG'], 'state' => [], 'lga' => [],
                'new_members_only' => false, 'min_age' => 18,
            ]),
            ':extra'   => json_encode(array_map($fill, (array) $v['extra_rules']), JSON_UNESCAPED_UNICODE),
            ':starts'  => $start,
            ':ends'    => $end,
            ':tz'      => 'Africa/Lagos',
            ':terms'   => 'challenges-v1',
            ':status'  => ($now < $start) ? 'upcoming' : (($now > $end) ? 'ended' : 'open'),
            ':pub'     => $now,
            // ── ONE PLACEHOLDER PER POSITION, AND THAT IS A MySQL RULE ──────────
            //
            // `created_at` and `updated_at` both wanted `$now` and both named `:now`.
            // SQLite's driver binds a repeated named placeholder quite happily; MySQL
            // with native prepares answers `SQLSTATE[HY093]: Invalid parameter number`
            // and the whole seed fails. Found by the parity run — on SQLite this file
            // was green and on the database production uses it had never run at all.
            ':created' => $now,
            ':updated' => $now,
            // And no `:now` here: the INSERT below no longer names one, and an EXTRA
            // bound parameter is the same `HY093` on MySQL as a repeated one. The UPDATE
            // branch builds its own array.
        ];

        $id = $db->prepare('SELECT id FROM gates_challenges WHERE slug = :slug');
        $id->execute([':slug' => $slug]);
        $challengeId = $id->fetchColumn();

        if ($challengeId) {
            // The words and the picture only. Status, cap, dates, mode and prize are the
            // terms somebody is already competing under — see the note at the top. The
            // flier's lines are FILLED, never overwritten: they are campaign copy the
            // builder lets an operator edit, and a re-run must not take that edit back.
            $db->prepare(
                'UPDATE gates_challenges
                    SET title = :title, kicker = :kicker, summary = :summary,
                        art_url = :art, art_alt = :artalt, extra_rules = :extra,
                        headline     = COALESCE(NULLIF(headline, \'\'), :headline),
                        standfirst   = COALESCE(NULLIF(standfirst, \'\'), :standfirst),
                        tagline      = COALESCE(NULLIF(tagline, \'\'), :tagline),
                        portrait_url = COALESCE(NULLIF(portrait_url, \'\'), :portrait),
                        portrait_alt = COALESCE(NULLIF(portrait_alt, \'\'), :portrait_alt),
                        updated_at = :now
                  WHERE id = :id')
               ->execute([
                   ':title' => $row[':title'], ':kicker' => $row[':kicker'],
                   ':summary' => $row[':summary'], ':art' => $row[':art'],
                   ':artalt' => $row[':artalt'], ':extra' => $row[':extra'],
                   ':now' => $now, ':id' => $challengeId,
               ] + $flier);
        } else {
            $db->prepare(
                'INSERT INTO gates_challenges
                 (slug, title, kicker, summary, action, target, mode, cap,
                  prize_type, prize_amount, prize_currency, prize_label,
                  theme, art_url, art_alt, icon, headline, standfirst, tagline, portrait_url, portrait_alt,
                  flag, eligibility, extra_rules,
                  starts_at, ends_at, timezone, terms_version, status,
                  created_by, published_at, created_at, updated_at)
                 VALUES
                 (:slug, :title, :kicker, :summary, :action, :target, :mode, :cap,
                  :ptype, :pamount, :pcur, :plabel,
                  :theme, :art, :artalt, :icon, :headline, :standfirst, :tagline, :portrait, :portrait_alt,
                  :flag, :elig, :extra,
                  :starts, :ends, :tz, :terms, :status,
                  NULL, :pub, :created, :updated)')
               ->execute($row);

            $challengeId = (int) $db->lastInsertId();
        }

        // ── 3. Scope: the open Alimosho edition, so all of its categories count ──
        $db->prepare('DELETE FROM gates_challenge_scopes WHERE challenge_id = :c')
           ->execute([':c' => $challengeId]);
        $db->prepare(
            "INSERT INTO gates_challenge_scopes (challenge_id, scope_type, scope_id)
             VALUES (:c, 'award_cycle', :s)")
           ->execute([':c' => $challengeId, ':s' => $cycleId]);

        // ── 4. The host, filling a blank and never overwriting ──
        //
        // An operator who has already named a host meant it; a seed that corrects them
        // on every deploy is a settings screen that does not work. Written to the
        // PROGRAMME, which is where the fact lives — see 2027_02_14_programme_host.php
        // for why this is not a sponsor row.
        $db->prepare(
            "UPDATE gates_award_programmes
                SET host_name = :n, host_logo_path = :l
              WHERE id = :id AND (host_name IS NULL OR host_name = '')")
           ->execute([
               ':n'  => 'Okun Alimosho',
               ':l'  => '/assets/img/hosts/okun-alimosho-logo.png',
               ':id' => $programmeId,
           ]);

        // ── 5. Promos, one per placement ──
        $promos = [
            // placement, audience, priority, call to action
            ['nominate', 'all',       100, 'Join the challenge'],
            ['home',     'all',        90, 'See how to win'],
            ['account',  'signed_in', 100, 'Track your progress'],
            ['vote',     'all',        60, 'See the challenge'],
            ['award',    'all',        80, 'Join the challenge'],
        ];

        $db->prepare('DELETE FROM gates_promos WHERE challenge_id = :c')
           ->execute([':c' => $challengeId]);

        $ins = $db->prepare(
            "INSERT INTO gates_promos
             (placement, kicker, title, sub, cta, href, theme, art_url, challenge_id,
              priority, audience, starts_at, ends_at, active, created_at, updated_at)
             VALUES
             (:p, :k, :t, :s, :cta, :h, 'green', :art, :c, :pri, :aud, :st, :en, 1, :created, :updated)");

        foreach ($promos as [$placement, $audience, $priority, $cta]) {
            $ins->execute([
                ':p'   => $placement,
                ':k'   => 'Independence Day challenge',
                ':t'   => 'Celebrate Nigeria: win ₦6,000',
                ':s'   => 'First 11 people to get 10 nominees verified. Ends 15 Oct.',
                ':cta' => $cta,
                ':h'   => '/challenges/' . $slug,
                ':art' => $artUrl,
                ':c'   => $challengeId,
                ':pri' => $priority,
                ':aud' => $audience,
                ':st'  => $start,
                ':en'  => $end,
                ':created' => $now,
                ':updated' => $now,
            ]);
        }

        // ── 6. The audit row ──
        //
        // `once_key` carries the idempotency rather than a SELECT-then-INSERT: the unique
        // index refuses the second write itself, so two deploys racing each other cannot
        // both decide the row is absent. `INSERT OR IGNORE` / `INSERT IGNORE` spell the
        // same thing differently on the two drivers, so the refusal is caught instead.
        try {
            $db->prepare(
                "INSERT INTO gates_challenge_events
                 (challenge_id, entry_id, kind, ref_type, ref_id, meta, once_key, created_at)
                 VALUES (:c, NULL, 'seeded', 'challenge', :c2, :m, :once, :now)")
               ->execute([
                   ':c'    => $challengeId,
                   ':c2'   => $challengeId,
                   ':m'    => json_encode(['seed' => basename(__FILE__)]),
                   ':once' => 'seed:' . $slug,
                   ':now'  => $now,
               ]);
        } catch (PDOException $e) {
            // 23000 is the integrity-constraint class on both drivers. Anything else is
            // a real failure and must still take the transaction down with it.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }

        if ($ownTransaction) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction) {
            $db->rollBack();
        }

        throw $e;
    }
};
