<?php
declare(strict_types=1);
/**
 * Seed: "Celebrate Nigeria" Independence Day challenge (Alimosho Awards).
 * Path in repo: database/seeds/2026_10_01_celebrate_nigeria.php
 * Run: php bin/seed.php 2026_10_01_celebrate_nigeria   (adapt to the repo's seed runner)
 *
 * IDEMPOTENT: upserts by slug; re-running never duplicates rows or resets entries.
 * Works on SQLite (dev) and MySQL (prod): no ENUM/JSON functions, ISO datetimes with a space (no "T").
 * Requires the Challenges migrations (PROMPT-CHALLENGES.md §2) to have run first.
 */

return static function (PDO $db): void {
    $slug  = 'celebrate-nigeria-2026';
    $now   = gmdate('Y-m-d H:i:s');
    // Africa/Lagos (WAT, UTC+1) stored as UTC
    $start = '2026-09-30 23:00:00';   // 1 Oct 2026 00:00 WAT
    $end   = '2026-10-15 22:59:59';   // 15 Oct 2026 23:59:59 WAT

    // ── 1. Resolve scope: Alimosho Awards, the edition whose nominations are open ──
    $award = $db->prepare("SELECT id FROM gates_awards WHERE slug = :s OR name = :n LIMIT 1");
    $award->execute([':s' => 'alimosho-awards', ':n' => 'Alimosho Awards']);
    $awardId = $award->fetchColumn();
    if (!$awardId) {
        throw new RuntimeException('Alimosho Awards not found. Create the award + its current edition first; do not invent one here.');
    }
    $cycle = $db->prepare(
        "SELECT id FROM gates_award_cycles
          WHERE award_id = :a AND nominations_open_at <= :n AND (nominations_close_at IS NULL OR nominations_close_at >= :n)
          ORDER BY edition_number DESC LIMIT 1"
    );
    $cycle->execute([':a' => $awardId, ':n' => $now]);
    $cycleId = $cycle->fetchColumn();
    if (!$cycleId) {
        throw new RuntimeException('No Alimosho Awards edition is open for nominations. Open the edition, then re-run.');
    }

    $db->beginTransaction();
    try {
        // ── 2. Challenge (upsert by slug) ──
        $row = [
            ':slug'        => $slug,
            ':title'       => 'Celebrate Nigeria',
            ':kicker'      => 'Independence Day challenge',
            ':summary'     => 'Nominate 10 different people for the Alimosho Awards. The first 11 people to get 10 nominees verified win ₦6,000 each.',
            ':action'      => 'nominate',
            ':target'      => 10,
            ':mode'        => 'first',
            ':cap'         => 11,
            ':ptype'       => 'cash_each',
            ':pamount'     => 6000,          // whole naira, NOT kobo; match the column's unit
            ':pcur'        => 'NGN',
            ':plabel'      => '₦6,000 each',
            ':theme'       => 'green',
            ':art'         => '/assets/img/challenges/alimosho-celebrates-nigeria.png',
            ':icon'        => null,
            ':flag'        => 1,             // shows the Nigerian flag chip
            ':elig'        => json_encode(['country' => ['NG'], 'state' => [], 'lga' => [], 'new_members_only' => false, 'min_age' => 18]),
            ':extra'       => json_encode([
                'Nominees must live or work in Alimosho, Lagos.',
                'You cannot nominate yourself or the same person twice.',
            ], JSON_UNESCAPED_UNICODE),
            ':starts'      => $start,
            ':ends'        => $end,
            ':tz'          => 'Africa/Lagos',
            ':terms'       => 'challenges-v1',
            ':status'      => ($now < $start) ? 'upcoming' : (($now > $end) ? 'ended' : 'open'),
            ':pub'         => $now,
            ':now'         => $now,
        ];
        $id = $db->prepare("SELECT id FROM gates_challenges WHERE slug = :slug");
        $id->execute([':slug' => $slug]);
        $challengeId = $id->fetchColumn();

        if ($challengeId) {
            // Never touch status/entries of a live challenge except to fill blanks.
            $db->prepare("UPDATE gates_challenges SET title=:title,kicker=:kicker,summary=:summary,art_url=:art,extra_rules=:extra,updated_at=:now WHERE id=:id")
               ->execute([':title'=>$row[':title'],':kicker'=>$row[':kicker'],':summary'=>$row[':summary'],':art'=>$row[':art'],':extra'=>$row[':extra'],':now'=>$now,':id'=>$challengeId]);
        } else {
            $db->prepare(
                "INSERT INTO gates_challenges
                 (slug,title,kicker,summary,action,target,mode,cap,prize_type,prize_amount,prize_currency,prize_label,
                  theme,art_url,icon,flag,eligibility,extra_rules,starts_at,ends_at,timezone,terms_version,status,
                  created_by,published_at,created_at,updated_at)
                 VALUES (:slug,:title,:kicker,:summary,:action,:target,:mode,:cap,:ptype,:pamount,:pcur,:plabel,
                  :theme,:art,:icon,:flag,:elig,:extra,:starts,:ends,:tz,:terms,:status,
                  NULL,:pub,:now,:now)"
            )->execute($row);
            $challengeId = (int)$db->lastInsertId();
        }

        // ── 3. Scope: the open Alimosho edition (all its categories: Choral, Business, Impact) ──
        $db->prepare("DELETE FROM gates_challenge_scopes WHERE challenge_id = :c")->execute([':c' => $challengeId]);
        $db->prepare("INSERT INTO gates_challenge_scopes (challenge_id,scope_type,scope_id) VALUES (:c,'award_cycle',:s)")
           ->execute([':c' => $challengeId, ':s' => $cycleId]);

        // ── 4. Promos (banner carousel). One per placement, upsert by (placement, challenge_id) ──
        $promos = [
            // placement, audience, priority, cta
            ['nominate', 'all',        100, 'Join the challenge'],
            ['home',     'all',         90, 'See how to win'],
            ['account',  'signed_in',  100, 'Track your progress'],
            ['vote',     'all',         60, 'See the challenge'],
            ['award',    'all',         80, 'Join the challenge'],   // shows only on scoped award pages
        ];
        $del = $db->prepare("DELETE FROM gates_promos WHERE challenge_id = :c");
        $del->execute([':c' => $challengeId]);
        $ins = $db->prepare(
            "INSERT INTO gates_promos (placement,kicker,title,sub,cta,href,theme,art_url,challenge_id,priority,audience,starts_at,ends_at,active)
             VALUES (:p,:k,:t,:s,:cta,:h,'green',:art,:c,:pri,:aud,:st,:en,1)"
        );
        foreach ($promos as [$p, $aud, $pri, $cta]) {
            $ins->execute([
                ':p' => $p, ':k' => 'Independence Day challenge', ':t' => 'Celebrate Nigeria: win ₦6,000',
                ':s' => 'First 11 people to get 10 nominees verified. Ends 15 Oct.',
                ':cta' => $cta, ':h' => '/challenges/' . $slug, ':art' => $row[':art'],
                ':c' => $challengeId, ':pri' => $pri, ':aud' => $aud, ':st' => $start, ':en' => $end,
            ]);
        }

        // ── 5. Audit ──
        $db->prepare("INSERT INTO gates_challenge_events (entry_id,kind,ref_type,ref_id,meta,created_at) VALUES (NULL,'seeded','challenge',:c,:m,:now)")
           ->execute([':c' => $challengeId, ':m' => json_encode(['seed' => basename(__FILE__)]), ':now' => $now]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
};
