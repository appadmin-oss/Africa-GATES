<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\ChallengeEnum as E;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Creating and changing a challenge, and the one rule that outranks convenience.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AFTER PUBLICATION THE RULES ARE LOCKED. ALL OF THEM.
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * §5: "After publish, only copy, art, an end-date extension and placements can be
 * edited; the rules are locked."
 *
 * This is not a nicety. The page tells a named person "the first 11 to get 10 verified
 * each win ₦6,000", and somebody then spends an evening on it. Lowering the cap to 5
 * afterwards takes a prize from a person who earned it under the published terms, and
 * every figure on the page would go on looking internally consistent while doing it.
 * This codebase already has the rule in its bones — a published result is the one that
 * was ANNOUNCED, not the one today's arithmetic gives.
 *
 * So {@see LOCKED} is the list, enforced in {@see update()}, and an attempt to change
 * one is refused with the field named rather than silently dropped. An end date may be
 * EXTENDED and never shortened: extending gives everyone more of what they were
 * promised; shortening takes it away.
 *
 * ── AND THE AI DECIDES NOTHING HERE ─────────────────────────────────────────
 *
 * {@see draft()} asks a model for copy and for scope suggestions. Its answer lands in
 * a FORM for an operator to read, change and submit. It never writes a row, never
 * picks a prize, never sets a cap, and nothing it returns reaches a public page except
 * through a human pressing save. `ChallengeCopy` still generates every published
 * sentence from the stored numbers, so even an operator who accepts a drafted title
 * cannot end up with a promise that disagrees with the rules behind it.
 */
final class ChallengeAdmin
{
    /**
     * What may never change once people can see it.
     *
     * Everything that decides who wins or what they get. The permitted edits are the
     * complement: title, kicker, summary, art, icon, theme, extra rules, and the end
     * date upwards only.
     *
     * @var list<string>
     */
    public const LOCKED = [
        'action', 'target', 'mode', 'cap', 'draw_count', 'draw_at',
        'prize_type', 'prize_amount', 'prize_currency', 'prize_label',
        'starts_at', 'terms_version', 'slug',
    ];

    /** The flier's own lines and portrait — {@see ChallengeCopy::flier()}. */
    public const FLIER = ['headline', 'standfirst', 'tagline', 'portrait_url', 'portrait_alt'];

    /**
     * What a BLANK box means in a NOT NULL column: the column's own default. A draft is
     * allowed to be half-filled — `publish()` is the gate, and it asks for each of these
     * by name — so a blank must save, and NULL is the one value these refuse.
     *
     * @var array<string,string|int>
     */
    private const BLANK_MEANS = [
        'kicker'        => '',
        'target'        => 1,
        'prize_amount'  => 0,
        'timezone'      => 'Africa/Lagos',
        'terms_version' => '1.0',
        'title'         => '',
    ];

    // ══════════════════════════════════════════════════════════════════════════
    // Writing
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Create a draft. Nothing here is published, so nothing here is validated hard.
     *
     * A builder that refuses to save until every field is right is a builder nobody can
     * step away from. The gate is {@see publish()}.
     *
     * @return array{ok:bool,code:string,id:int}
     */
    public static function create(array $in, ?int $adminId = null): array
    {
        // A BLANK slug falls back to the title, not only a missing one. The form always
        // posts the field and it is usually empty, so `$in['slug'] ?? $in['title']` took
        // the empty string, slugged it to nothing, and refused every challenge with
        // "give it a title first" beside a title box that was filled in.
        $slug = self::slug(trim((string) ($in['slug'] ?? '')) ?: (string) ($in['title'] ?? ''));
        if ($slug === '' || trim((string) ($in['title'] ?? '')) === '') {
            return ['ok' => false, 'code' => 'NO_TITLE', 'id' => 0];
        }

        if (DB::table('gates_challenges')->where('slug', $slug)->exists()) {
            // A slug is a published URL the moment it goes live, so a collision is
            // refused rather than silently suffixed — two challenges one character
            // apart is a support call nobody can answer.
            return ['ok' => false, 'code' => 'SLUG_TAKEN', 'id' => 0];
        }

        $now    = date('Y-m-d H:i:s');
        // `kicker` is NOT NULL with NO database default, so it is supplied even when the
        // caller sent no such key at all; every other NOT NULL column has a default of
        // its own and is simply left out.
        $fields = self::clean($in) + ['kicker' => ''];

        $id = (int) DB::table('gates_challenges')->insertGetId($fields + [
            'slug' => $slug, 'status' => E::ST_DRAFT,
            'created_by' => $adminId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return ['ok' => true, 'code' => 'CREATED', 'id' => $id];
    }

    /**
     * Change one. Refuses a locked field on anything that has been published.
     *
     * @return array{ok:bool,code:string,locked:list<string>}
     */
    public static function update(int $id, array $in): array
    {
        $c = ChallengeService::find($id);
        if (!$c) return ['ok' => false, 'code' => 'NO_CHALLENGE', 'locked' => []];

        $fields = self::clean($in);
        $live   = $c->status !== E::ST_DRAFT;

        if ($live) {
            $refused = [];

            foreach (self::LOCKED as $k) {
                if (!array_key_exists($k, $fields)) continue;
                // Compared loosely on purpose: a form re-posts every field, so the
                // common case is a value that has not actually changed and must not be
                // reported as an attempt to change it.
                if ((string) $fields[$k] === (string) ($c->$k ?? '')) { unset($fields[$k]); continue; }
                $refused[] = $k;
            }

            if ($refused !== []) {
                return ['ok' => false, 'code' => 'LOCKED', 'locked' => $refused];
            }

            // An end date may only move OUTWARDS. Extending gives everybody more of
            // what they were promised; shortening takes it away from whoever was
            // part-way through.
            if (!empty($fields['ends_at']) && !empty($c->ends_at)
                && strtotime((string) $fields['ends_at']) < strtotime((string) $c->ends_at)) {
                return ['ok' => false, 'code' => 'ENDS_EARLIER', 'locked' => ['ends_at']];
            }
        }

        // A cleared title box keeps the title: a challenge with no name is a row nobody
        // can find again in the list, and `publish()` would refuse it anyway.
        if (array_key_exists('title', $fields) && $fields['title'] === '') unset($fields['title']);

        if ($fields !== []) {
            $fields['updated_at'] = date('Y-m-d H:i:s');
            DB::table('gates_challenges')->where('id', $id)->update($fields);
        }

        return ['ok' => true, 'code' => 'SAVED', 'locked' => []];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Scopes
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Replace the scopes. An action counts ONLY inside one.
     *
     * Rewritten whole rather than diffed: a scope list is small, and a partial update
     * is how a category gets left behind pointing at an edition nobody meant to
     * include. Refused outright once published — the scope decides what counts, so it
     * is as locked as the target.
     */
    public static function setScopes(int $id, array $scopes): array
    {
        $c = ChallengeService::find($id);
        if (!$c) return ['ok' => false, 'code' => 'NO_CHALLENGE'];
        if ($c->status !== E::ST_DRAFT) return ['ok' => false, 'code' => 'LOCKED'];

        $rows = [];
        foreach ($scopes as $s) {
            $type = (string) ($s['scope_type'] ?? '');
            $sid  = (int) ($s['scope_id'] ?? 0);
            if (!in_array($type, E::SCOPE_TYPES, true) || $sid < 1) continue;
            $rows[] = ['challenge_id' => $id, 'scope_type' => $type, 'scope_id' => $sid];
        }

        DB::transaction(static function () use ($id, $rows): void {
            DB::table('gates_challenge_scopes')->where('challenge_id', $id)->delete();
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('gates_challenge_scopes')->insert($chunk);
            }
        });

        return ['ok' => true, 'code' => 'SAVED', 'count' => count($rows)];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Publishing
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * The gate. Every reason it cannot go out, in one answer.
     *
     * Returned as a LIST rather than the first failure: an operator fixing a challenge
     * one refusal at a time, with a round trip each, is an operator who gives up and
     * publishes something half-right.
     *
     * @return list<string>
     */
    public static function blockers(int $id): array
    {
        $c = ChallengeService::find($id);
        if (!$c) return ['That challenge does not exist.'];

        $out = [];

        if (trim((string) $c->title) === '')  $out[] = 'It needs a title.';
        if (trim((string) $c->kicker) === '') $out[] = 'It needs a kicker — the line above the title.';

        // §5: at least one scope. Without one the challenge counts everything
        // everywhere, which is never what anybody meant and is unbounded.
        $scopes = (int) DB::table('gates_challenge_scopes')->where('challenge_id', $id)->count();
        if ($scopes < 1) $out[] = 'Add at least one award, category or event for it to count inside.';

        if ((int) $c->target < 1) $out[] = 'The target has to be at least 1.';

        if ((string) $c->mode === E::MODE_DRAW) {
            if ((int) ($c->draw_count ?? 0) < 1) $out[] = 'A draw needs a number of winners.';
        } elseif ((int) ($c->cap ?? 0) < 1) {
            $out[] = 'It needs a cap — how many people can win.';
        }

        if ((int) $c->prize_amount < 1) $out[] = 'It needs a prize amount.';

        if ((string) $c->prize_type === E::PRIZE_TICKETS && trim((string) $c->prize_label) === '') {
            $out[] = 'A ticket prize needs a label, so the page can say what the tickets are for.';
        }
        if (in_array((string) $c->prize_type, [E::PRIZE_CASH_EACH, E::PRIZE_CASH_POOL], true)
            && trim((string) $c->prize_currency) === '') {
            $out[] = 'A cash prize needs a currency symbol.';
        }

        if (empty($c->ends_at)) $out[] = 'It needs a closing date.';
        if (!empty($c->starts_at) && !empty($c->ends_at)
            && strtotime((string) $c->ends_at) <= strtotime((string) $c->starts_at)) {
            $out[] = 'It closes before it opens.';
        }

        if (trim((string) $c->terms_version) === '') $out[] = 'It needs a terms version.';

        // ── THE ACTIONS WITH NO DESIGNED COPY ───────────────────────────────
        // `ChallengeCopy::steps()` writes nothing for `vote`, `give` or `attend` — the
        // design comp defines neither steps nor per-action rules for them. Publishing
        // one would put an empty "How to take part" on the page that exists to say how
        // to take part, and terms with no per-action clause at all. Refused until the
        // copy is written, which is the point at which this check can go.
        if (in_array((string) $c->action, [E::ACTION_VOTE, E::ACTION_GIVE, E::ACTION_ATTEND], true)) {
            $out[] = 'There is no approved wording for a "' . $c->action . '" challenge yet, so it '
                . 'would publish an empty "How to take part". Use nominate or refer for now.';
        }

        return $out;
    }

    /**
     * Put it live, or say why not.
     *
     * `upcoming` when it has not started, `open` when it has. The status is what the
     * public page gates on, so this is the only place that decides it.
     */
    public static function publish(int $id, ?int $adminId = null): array
    {
        $blockers = self::blockers($id);
        if ($blockers !== []) return ['ok' => false, 'code' => 'BLOCKED', 'blockers' => $blockers];

        $c   = ChallengeService::find($id);
        $now = date('Y-m-d H:i:s');

        $status = (!empty($c->starts_at) && strtotime((string) $c->starts_at) > time())
            ? E::ST_UPCOMING : E::ST_OPEN;

        DB::table('gates_challenges')->where('id', $id)->update([
            'status' => $status, 'published_at' => $now, 'updated_at' => $now,
        ]);

        return ['ok' => true, 'code' => 'PUBLISHED', 'status' => $status, 'blockers' => []];
    }

    /**
     * Stop one.
     *
     * `cancelled` rather than deleted, and the reason is required. A challenge people
     * have entered is a promise that was made; the row is the record that it was, and
     * entries point at it. Deleting it would orphan them and erase the evidence of
     * what was offered — the rule this codebase states as "a record that has been used
     * is retired, never deleted".
     */
    public static function cancel(int $id, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') return ['ok' => false, 'code' => 'NO_REASON'];

        DB::table('gates_challenges')->where('id', $id)->update([
            'status' => E::ST_CANCELLED, 'cancel_reason' => mb_substr($reason, 0, 300),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return ['ok' => true, 'code' => 'CANCELLED'];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The assistant, which decides nothing
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Ask a model to draft the words, for an operator to read and change.
     *
     * ── WHAT IT MAY AND MAY NOT TOUCH ───────────────────────────────────────
     *
     * It drafts a TITLE, a KICKER, a SUMMARY and extra RULES. It is given the numbers
     * and never asked for them: no prize, no cap, no target, no dates. Those decide who
     * gets money, and a model that could nudge a cap from 11 to 15 is a model deciding
     * an award.
     *
     * The promise sentence is not drafted either, ever. `ChallengeCopy` generates it
     * from the stored figures, so an operator who accepts every word of this draft
     * still cannot end up with a page promising something the rules do not do.
     *
     * Returns `[]` when AI is off, over budget or unreachable. The screen says so and
     * the form still works — the builder has never needed it.
     */
    public static function draft(array $fields, ?AiGateway $gw = null): array
    {
        if (!AiGateway::available('challenge.draft')) return [];

        $action = (string) ($fields['action'] ?? E::ACTION_NOMINATE);
        $target = max(1, (int) ($fields['target'] ?? 1));
        $mode   = (string) ($fields['mode'] ?? E::MODE_FIRST);
        $scopes = array_slice(array_values(array_filter(array_map(
            static fn($x) => trim((string) $x), $fields['scope_names'] ?? [],
        ))), 0, 12);

        $system = <<<'TXT'
            You write short, plain campaign copy for an African awards platform.

            Return JSON only, with these keys and nothing else:
              title    a name for the challenge, under 60 characters, no quotes
              kicker   the small line ABOVE the title, under 40 characters
              summary  one sentence saying why somebody would bother, under 160 characters
              rules    0 to 3 EXTRA eligibility lines, each under 140 characters

            Hard limits:
            - Never state a prize, an amount, a cap, a number of winners or a date. You
              have not been told them and the page writes those itself from the record.
            - Never promise anything about who wins or how it is decided.
            - `rules` may only ADD restrictions (a region, an age, a staff exclusion).
              The platform's own rules about verification, one entry per person, and how
              places are ordered are written elsewhere and must not be repeated.
            - Plain British English. No exclamation marks, no "amazing", no emoji.
            TXT;

        $shape = "Action: {$action}\nTarget: {$target}\nMode: {$mode}\n"
            . 'Counts inside: ' . ($scopes === [] ? '(not chosen yet)' : implode('; ', $scopes));

        try {
            $r = ($gw ?? new AiGateway())->run('challenge.draft', [
                'system'      => $system,
                // The shape is OURS, so it is trusted. The operator's hint is theirs and
                // is fenced as untrusted — it is free text typed into an admin box, and
                // the one place an instruction could be smuggled in.
                'trusted'     => $shape,
                'user'        => mb_substr(trim((string) ($fields['hint'] ?? '')), 0, 400),
                'json'        => true,
                'temperature' => 0.4,
                'schema'      => static function (string $raw): ?array {
                    $j = json_decode($raw, true);
                    if (!is_array($j)) return null;

                    $title = mb_substr(trim((string) ($j['title'] ?? '')), 0, 200);
                    if ($title === '') return null;   // a draft with no title is no draft

                    $rules = [];
                    foreach (is_array($j['rules'] ?? null) ? $j['rules'] : [] as $x) {
                        $line = mb_substr(trim((string) $x), 0, 200);
                        if ($line !== '') $rules[] = $line;
                    }

                    return [
                        'title'   => $title,
                        'kicker'  => mb_substr(trim((string) ($j['kicker'] ?? '')), 0, 160),
                        'summary' => mb_substr(trim((string) ($j['summary'] ?? '')), 0, 300),
                        'rules'   => array_slice($rules, 0, 3),
                    ];
                },
            ]);

            return $r->ok && is_array($r->value) ? $r->value : [];
        } catch (\Throwable $e) {
            // A drafting failure must never stop somebody building a challenge by hand.
            return [];
        }
    }

    // ══════════════════════════════════════════════════════════════════════════

    /** Only the columns a form may set, cast to what the column holds. */
    private static function clean(array $in): array
    {
        $out = [];

        foreach (['title', 'kicker', 'summary', 'prize_currency', 'prize_label',
                  'art_url', 'art_alt', 'icon', 'eligibility', 'timezone', 'terms_version'] as $k) {
            if (array_key_exists($k, $in)) $out[$k] = trim((string) $in[$k]) ?: null;
        }

        // The flier's campaign lines and portrait — copy, never a rule, so they stay
        // editable after publication like the title and the art. Written only where the
        // columns exist: `2027_02_22_challenge_flier_copy.php` adds them, migrations here
        // are applied by an operator opening a URL, and a builder that names a column the
        // database does not have yet is a builder that 500s on every save until then.
        foreach (self::FLIER as $k) {
            if (array_key_exists($k, $in) && \AfricaGates\Support\SchemaHas::column('gates_challenges', $k)) {
                $out[$k] = trim((string) $in[$k]) ?: null;
            }
        }

        foreach (['target', 'cap', 'draw_count', 'prize_amount'] as $k) {
            if (array_key_exists($k, $in)) $out[$k] = max(0, (int) $in[$k]) ?: null;
        }

        // An unknown word in an ENUM column is `Data truncated` on MySQL and silence on
        // SQLite, so a value that is not in the list is dropped rather than written.
        $enums = ['action' => E::ACTIONS, 'mode' => E::MODES,
                  'prize_type' => E::PRIZE_TYPES, 'theme' => E::THEMES];
        foreach ($enums as $k => $allowed) {
            if (array_key_exists($k, $in) && in_array((string) $in[$k], $allowed, true)) {
                $out[$k] = (string) $in[$k];
            }
        }

        foreach (['starts_at', 'ends_at', 'draw_at'] as $k) {
            if (!array_key_exists($k, $in)) continue;
            $out[$k] = self::stamp((string) $in[$k]);
        }

        if (array_key_exists('flag', $in)) $out['flag'] = !empty($in['flag']) ? 1 : 0;

        // Appended rules travel as JSON; `ChallengeCopy` reads either shape.
        if (array_key_exists('extra_rules', $in)) {
            $list = is_array($in['extra_rules'])
                ? $in['extra_rules']
                : (preg_split('/\r?\n/', (string) $in['extra_rules']) ?: []);
            $list = array_values(array_filter(array_map('trim', $list)));
            $out['extra_rules'] = $list === [] ? null : json_encode($list, JSON_UNESCAPED_UNICODE);
        }

        // ── A BLANK BOX IS NOT A NULL IN A NOT NULL COLUMN ──────────────────
        // Every blank above became null, and five of these columns refuse one. The
        // prize box starts EMPTY on a new challenge, so saving a draft before deciding
        // the prize — the ordinary way to use a builder — threw on `prize_amount` and
        // answered 500. One table of what a blank means, rather than a patch per column
        // as each one is found: these are the columns' own defaults.
        foreach (self::BLANK_MEANS as $k => $default) {
            if (array_key_exists($k, $out) && $out[$k] === null) $out[$k] = $default;
        }

        return $out;
    }

    /**
     * A datetime from a form, stored as UTC.
     *
     * `datetime-local` posts "2026-11-30T23:59" with no zone, and the form drew it with
     * `|when_input` — the stored UTC time in the DISPLAY zone. So it is read back in that
     * zone, through {@see \AfricaGates\Support\DisplayTime::toStored()}, the inverse of
     * what drew it. This read it as UTC, which moved every date an hour LATER each time
     * somebody opened the form and pressed save — and on a published challenge the moved
     * `starts_at` is a locked field, so the save was refused for a change nobody made.
     * Null rather than a throw on nonsense.
     */
    private static function stamp(string $raw): ?string
    {
        return \AfricaGates\Support\DisplayTime::toStored($raw);
    }

    /**
     * The slug, through the one builder — NOT a second regex.
     *
     * The hand-rolled version here lowercased and then stripped anything outside
     * `[a-z0-9]`, which DELETES an accented letter rather than folding it. "Àlímọ̀ṣọ́
     * Celebrates" came out as `l-celebrates`, and most African names lose letters the
     * same way. `Slug::make()` folds first — it is the reason that class exists, and
     * `SlugTest` sweeps for exactly this second implementation.
     */
    public static function slug(string $raw): string
    {
        return \AfricaGates\Support\Slug::make($raw, 160);
    }
}
