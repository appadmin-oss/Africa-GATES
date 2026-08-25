<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * A map of one nominee's dossier, for a judge about to read it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IT IS FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A judge opens a ballot with forty nominees on it and a morning. Each dossier is the
 * nominator's case, the nominee's own answers, a handful of documents and sometimes an
 * interview transcript. The first ten minutes of every one of them goes on working out what
 * is actually in there before any judging can start.
 *
 * This does that part: what the case rests on, which claims have something behind them and
 * which are assertions, what a judge might reasonably want and cannot see, and what is worth
 * checking. It is a finding aid — the same job {@see EvidenceAnalysis} does for a single
 * document, one level up.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * IT CANNOT RANK, AND THAT IS A PROPERTY OF THE CALL RATHER THAN THE PROMPT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This is the whole design and everything else follows from it.
 *
 * A model that is shown a category can order it. Not because it was asked to — because
 * comparison is what a language model does with a list, and once a comparison exists in the
 * output a judge has been given a ranking whether or not either of them intended it. Telling
 * it "do not compare" in a prompt is a request; the prompt is also the one part of this an
 * administrator can now edit.
 *
 * So the call is built from ONE nominee. Not one nominee plus context, not the category with
 * one highlighted. There is no second nominee in the payload, so there is nothing to rank
 * against and no wording — shipped, edited or injected — can produce one.
 *
 * It also never sees, and is never told:
 *
 *  · The scores anybody has given, including this judge's own. A dossier map that knows the
 *    panel is at 8/10 is a nudge with extra steps.
 *  · The vote counts. Public support is a different signal deliberately kept off the ballot.
 *  · The criteria and their weights. A summary organised around the scoring rubric is a
 *    filled-in scorecard, and a judge reading one is reading somebody else's judgement.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * CACHED PER NOMINEE, NOT PER JUDGE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Ten judges on the same category is ten identical requests otherwise. It is also the right
 * answer for fairness: every judge on a panel sees the SAME map, so the orientation is not
 * a variable that differs between them. Invalidated on the dossier's own content hash, so a
 * nominee who adds evidence gets a fresh one and nobody else pays for it.
 */
final class JudgeAssist
{
    public const CAPABILITY = 'judge.orientation';

    /** Bumped when {@see system()} or {@see schema()} changes in a way that invalidates cached output. */
    public const PROMPT_VERSION = 'v1';

    /**
     * How much dossier text is sent.
     *
     * A long interview transcript can run to thirty thousand characters, which is most of a
     * context window spent on one nominee and a bill nobody sees coming from a ballot page.
     * Truncation is SIGNALLED to the model and to the judge, never silent — a map of half a
     * dossier that does not say so is worse than no map.
     */
    public const MAX_CHARS = 14000;

    // ═══════════════════════════════════════════════════════════════════════
    // THE SHAPE
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @return array<string,mixed> a JSON-schema-ish description for the prompt
     */
    public static function schema(): array
    {
        return [
            'rests_on'  => 'One sentence: what this entry\'s case is built on.',
            'evidenced' => 'Claims with a document, transcript or verified source behind them.',
            'asserted'  => 'Claims made with nothing attached. Not a criticism — a judge needs '
                         . 'to know which is which.',
            'gaps'      => 'What a judge might reasonably expect and cannot see here.',
            'check'     => 'Specific things worth looking at in the dossier itself.',
        ];
    }

    /**
     * The instruction.
     *
     * Editable by a superadmin via {@see AiPrompt}. Everything that MATTERS about the
     * safety of this feature is outside it — the single-nominee payload, the absent scores,
     * the absent criteria — so an edit can change how the map reads and cannot turn it into
     * a scorecard.
     */
    public static function system(): string
    {
        return <<<'TXT'
        You are helping a judge find their way around one entry to an awards programme before
        they read it properly. You are a MAP, not a judge.

        You are given the case made for one nominee: what the person nominating them wrote,
        what the nominee said about their own work, and what was attached to it.

        Rules you must not break:
        - Never score, rate, rank or recommend. Do not say the entry is strong, weak,
          promising or competitive. Do not suggest what a judge should conclude.
        - Never compare this entry to any other. You have not been shown any others.
        - Separate what is EVIDENCED from what is ASSERTED, and be even-handed about it.
          An assertion is not a fault; a judge simply needs to know which is which.
        - Do not invent. If something is not in the text, it belongs in `gaps` as absent,
          not in `evidenced` as present.
        - Do not name private individuals other than the nominee. Organisations are fine.
        - `gaps` is about what is MISSING FROM THE PAPERWORK, never about the nominee. "No
          third-party confirmation of the figures" is a gap. "Lacks ambition" is a judgement
          and is forbidden.
        - Quote figures and dates exactly as written. Do not convert, round or estimate.

        Answer in JSON with exactly these keys: rests_on (string), evidenced (array of
        strings), asserted (array of strings), gaps (array of strings), check (array of
        strings). At most five items in each array.
        TXT;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // READING WHAT IS ALREADY KNOWN
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * The cached map for a nominee, or null.
     *
     * Matched on the dossier's content hash as well as the id: a nominee who adds evidence
     * has a different dossier, and serving the old map would describe an entry that no
     * longer exists.
     *
     * @return array<string,mixed>|null
     */
    public static function cached(int $nomineeId, string $hash = ''): ?array
    {
        try {
            $q = DB::table('gates_judge_orientation')
                ->where('nominee_id', $nomineeId)
                ->where('status', 'ok')
                ->where('prompt_version', self::PROMPT_VERSION);

            if ($hash !== '') $q->where('content_hash', $hash);

            $row = $q->orderByDesc('id')->first();
        } catch (\Throwable) {
            return null;
        }

        return $row ? self::hydrate($row) : null;
    }

    /**
     * How long a dossier that failed is left alone before it is tried again.
     *
     * `store()` has written a `failed` row since the day this shipped and NOTHING READ ONE.
     * So a dossier the model reliably chokes on — prose instead of JSON, a transcript that
     * blows the token ceiling — cost a fresh call on every button press, from each of ten
     * judges on the panel, and would cost another on every cron sweep for the rest of the
     * round. The dossier itself is right there on the same screen and the feature is
     * advisory, so nothing is lost by waiting; a bill and a rate limit are lost by not.
     *
     * Short enough that "try again shortly" is honest, long enough that a page refresh and a
     * second judge arriving are not two more attempts.
     */
    public const RETRY_AFTER_MIN = 10;

    /**
     * Was this exact dossier attempted and failed recently?
     *
     * On the CONTENT HASH, not on the nominee: a nominee who has just added the evidence the
     * last attempt choked on is a different dossier and deserves an immediate attempt.
     */
    private static function recentlyFailed(int $nomineeId, string $hash): bool
    {
        if ($hash === '') return false;

        try {
            return DB::table('gates_judge_orientation')
                ->where('nominee_id', $nomineeId)
                ->where('content_hash', $hash)
                ->where('status', 'failed')
                ->where('created_at', '>=',
                        Carbon::now()->subMinutes(self::RETRY_AFTER_MIN)->toDateTimeString())
                ->exists();
        } catch (\Throwable) {
            // No table, or an unreadable one. "I do not know" must mean TRY: failing closed
            // here would make one database hiccup look like a feature that has been switched
            // off for everybody.
            return false;
        }
    }

    /**
     * The map for a nominee, generating it if there is not one.
     *
     * @return array{ok:bool, map:array<string,mixed>|null, message:string}
     */
    public static function forNominee(int $nomineeId, bool $force = false): array
    {
        if ($nomineeId < 1) {
            return ['ok' => false, 'map' => null, 'message' => 'No nominee.'];
        }

        $dossier = self::dossier($nomineeId);
        if (trim($dossier['text']) === '') {
            return ['ok' => false, 'map' => null,
                    'message' => 'There is nothing written about this nominee yet, so there '
                               . 'is nothing to map.'];
        }

        if (!$force) {
            $hit = self::cached($nomineeId, $dossier['hash']);
            if ($hit !== null) return ['ok' => true, 'map' => $hit, 'message' => ''];
        }

        if (!$force && self::recentlyFailed($nomineeId, $dossier['hash'])) {
            return ['ok' => false, 'map' => null,
                    'message' => 'This one was tried a few minutes ago and could not be '
                               . 'mapped. It will be tried again shortly. The dossier below '
                               . 'is unaffected — read it as you would have anyway.'];
        }

        if (!AiGateway::available(self::CAPABILITY)) {
            return ['ok' => false, 'map' => null, 'message' => self::whyUnavailable()];
        }

        $r = (new AiGateway())->run(self::CAPABILITY, [
            'system' => self::system(),
            // ── THE PAYLOAD IS ONE NOMINEE ──────────────────────────────────
            //
            // There is no `trusted` block naming the category, no criteria, no scores and
            // no second nominee. That is what makes ranking impossible rather than merely
            // forbidden: the model has nothing to rank against. The gateway fences this as
            // untrusted, which it is — most of it is text a nominator typed.
            'user'         => $dossier['text'],
            'subject_type' => 'nominee',
            'subject_id'   => $nomineeId,
            'json'         => true,
            'temperature'  => 0.2,
            'schema'       => [self::class, 'parse'],
        ]);

        if (!$r->ok) {
            self::store($nomineeId, $dossier['hash'], [], 'failed', (string) $r->message);
            return ['ok' => false, 'map' => null,
                    'message' => 'The summary could not be produced just now. The dossier '
                               . 'below is unaffected — read it as you would have anyway.'];
        }

        self::store($nomineeId, $dossier['hash'], (array) $r->value, 'ok', '');

        return ['ok' => true, 'map' => self::cached($nomineeId, $dossier['hash']), 'message' => ''];
    }

    /**
     * Maps for a whole ballot, WITHOUT generating any.
     *
     * Read-only on render, deliberately. A judge opening a ballot of forty must not start
     * forty model calls by scrolling, and a page that spends money on render spends it again
     * on every refresh. The ballot shows what is already there and offers a button for the
     * rest — and the cron sweep fills most of them in before anybody arrives.
     * {@see sweep()}
     *
     * ══════════════════════════════════════════════════════════════════════════
     * IT NOW SAYS WHETHER EACH MAP STILL DESCRIBES THE ENTRY
     * ══════════════════════════════════════════════════════════════════════════
     *
     * {@see forNominee()} has always matched on the dossier's content hash, so the button
     * never served a map of a superseded dossier. This method did not, and it is the one the
     * ballot renders from — so once a nominee added evidence, ten judges went on reading a
     * map of the entry as it stood BEFORE it, with nothing on the screen to say so. A map
     * whose `gaps` said "no third-party confirmation of the figures" outlived the letter that
     * confirmed them.
     *
     * That is the same fault as a silently truncated dossier, which this class already
     * refuses to ship: the map is not wrong about what it read, it is wrong about what it is
     * a map OF, and only the reader can be harmed by the difference. So `stale` is computed
     * against the CURRENT hash and the ballot marks it, rather than quietly serving it or —
     * worse — hiding it, which would throw away a map that is still mostly true and start a
     * fresh model call on render.
     *
     * @param list<int> $nomineeIds
     * @return array<int,array<string,mixed>>
     */
    public static function forBallot(array $nomineeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $nomineeIds))));
        if ($ids === []) return [];

        try {
            $rows = DB::table('gates_judge_orientation')
                ->whereIn('nominee_id', $ids)
                ->where('status', 'ok')
                ->where('prompt_version', self::PROMPT_VERSION)
                ->orderBy('id')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) $out[(int) $r->nominee_id] = self::hydrate($r);

        if ($out === []) return [];

        // Four queries for the whole ballot, and only for the nominees that HAVE a map —
        // there is nothing to date-check for the rest, and the sweep will get to them.
        $current = self::dossiers(array_keys($out));

        foreach ($out as $id => $map) {
            $hash = (string) ($current[$id]['hash'] ?? '');
            // An unknown current hash (the dossier query failed) is NOT reported as stale.
            // Marking every map on the ballot out of date because one query hiccuped would
            // discard the panel's whole orientation over a transient fault.
            $out[$id]['stale'] = $hash !== '' && $hash !== (string) ($map['hash'] ?? '');
        }

        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // THE DOSSIER
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Everything written about one nominee, as one block of text plus its hash.
     *
     * A thin wrapper on {@see dossiers()} so there is ONE assembly and one hash formula. It
     * used to be the other way round and there was no batch at all, which is why the ballot
     * could only ever show maps it happened to have rather than say whether they were current.
     *
     * @return array{text:string, hash:string, truncated:bool}
     */
    public static function dossier(int $nomineeId): array
    {
        return self::dossiers([$nomineeId])[$nomineeId]
            ?? ['text' => '', 'hash' => '', 'truncated' => false];
    }

    /**
     * The same thing for a whole ballot at once, keyed by nominee id.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THE BATCH EXISTS
     * ══════════════════════════════════════════════════════════════════════════
     *
     * Assembled here rather than reusing {@see EvidenceService::forJudge()} because that
     * shapes data for a SCREEN — file urls, provenance chips, colours — and what a model
     * needs is prose. Passing the screen's structure would spend tokens on `file_kind` and
     * `provenance_label` and teach the model that the platform's internals are part of the
     * entry.
     *
     * Batched, four queries for any number of nominees, because two callers need every
     * dossier on a ballot at once and neither could afford four queries per nominee: the
     * cron sweep that fills the maps in before the judges arrive, and {@see forBallot()},
     * which has to know each stored map's hash is still the CURRENT one. Looping the
     * single-nominee version would have made both of those a query storm, which is how the
     * ballot ended up serving maps without checking they still described the entry.
     *
     * @param  list<int> $nomineeIds
     * @return array<int,array{text:string, hash:string, truncated:bool}>
     */
    public static function dossiers(array $nomineeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $nomineeIds))));
        if ($ids === []) return [];

        try {
            $nominees = DB::table('gates_nominees')->whereIn('id', $ids)
                ->get(['id', 'name', 'tagline', 'story', 'organisation'])
                ->keyBy('id');
        } catch (\Throwable) {
            $nominees = collect([]);
        }

        // `visible_to_judges` is honoured — an item withheld from the panel must not reach
        // the panel through a summary of it.
        try {
            $evidence = DB::table('gates_nominee_evidence')
                ->whereIn('nominee_id', $ids)->where('visible_to_judges', 1)
                ->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'nominee_id', 'title', 'body', 'provenance', 'verified'])
                ->groupBy('nominee_id');
        } catch (\Throwable) {
            $evidence = collect([]);
        }

        // Published transcripts only. A draft is an unreviewed machine output and a
        // withdrawn one had consent taken back.
        try {
            $interviews = DB::table('gates_nominee_interviews')
                ->whereIn('nominee_id', $ids)->where('status', 'published')
                ->orderBy('id')->get(['nominee_id', 'transcript'])
                ->groupBy('nominee_id');
        } catch (\Throwable) {
            $interviews = collect([]);
        }

        $analyses = EvidenceAnalysis::forNomineesMap($ids);

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = self::assemble(
                $nominees[$id] ?? null,
                $evidence[$id] ?? [],
                $interviews[$id] ?? [],
                $analyses[$id] ?? [],
            );
        }

        return $out;
    }

    /**
     * One nominee's rows turned into the prose a model is given.
     *
     * @param  iterable<object> $evidence
     * @param  iterable<object> $interviews
     * @param  array<int,array<string,mixed>> $analyses
     * @return array{text:string, hash:string, truncated:bool}
     */
    private static function assemble(?object $n, iterable $evidence,
                                     iterable $interviews, array $analyses): array
    {
        $parts = [];

        if ($n) {
            $parts[] = 'NOMINEE: ' . (string) $n->name
                . (trim((string) ($n->organisation ?? '')) !== ''
                    ? ' (' . (string) $n->organisation . ')' : '');
            if (trim((string) ($n->tagline ?? '')) !== '') $parts[] = 'IN ONE LINE: ' . $n->tagline;
            if (trim((string) ($n->story ?? '')) !== '')   $parts[] = "THE CASE MADE FOR THEM:\n" . $n->story;
        }

        foreach ($evidence as $e) {
            $line = 'ATTACHED — ' . (string) $e->title
                  . ' [' . (string) $e->provenance . ((int) $e->verified === 1 ? ', verified' : '') . ']';
            if (trim((string) ($e->body ?? '')) !== '') $line .= "\n" . $e->body;

            // What a model made of the file itself, folded in. It is the only way the text
            // of a scanned certificate reaches this call at all — and it is labelled as a
            // machine reading so the map does not treat it as the platform's own finding.
            $a = $analyses[(int) $e->id] ?? null;
            if ($a !== null && ($a['status'] ?? '') === 'ok' && trim((string) $a['summary']) !== '') {
                $line .= "\n(machine reading of the file: " . $a['summary'] . ')';
            }

            $parts[] = $line;
        }

        foreach ($interviews as $r) {
            if (trim((string) $r->transcript) !== '') {
                $parts[] = "INTERVIEW — THE NOMINEE'S OWN WORDS:\n" . $r->transcript;
            }
        }

        $text  = trim(implode("\n\n", $parts));
        $full  = $text;
        $trunc = false;

        if (mb_strlen($text) > self::MAX_CHARS) {
            // Signalled, not silent. A map of half a dossier that does not say so is worse
            // than no map — a judge would read "no third-party confirmation" as a fact about
            // the entry rather than about the part we sent.
            $text  = mb_substr($text, 0, self::MAX_CHARS)
                   . "\n\n[This dossier is longer than could be sent. Anything after this "
                   . 'point was not shown to you, so say so in `gaps`.]';
            $trunc = true;
        }

        return [
            'text'      => $text,
            // Hashed on the FULL text, so adding evidence past the cut still invalidates the
            // cache. Hashing the truncated copy would freeze the map at the first 14,000
            // characters for ever.
            'hash'      => $full === '' ? '' : hash('sha256', $full),
            'truncated' => $trunc,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FILLING THEM IN BEFORE THE PANEL ARRIVES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * How many maps one sweep will make.
     *
     * Bounded because this runs on a cPanel host with a `max_execution_time`, and each map is
     * a 30-second budget against a 14,000-character dossier. Six is roughly three minutes at
     * the worst case and finishes a category of forty inside a few hours of cron — long
     * before a round opens, which is the only deadline that matters.
     */
    public const SWEEP_LIMIT = 6;

    /**
     * Make the maps for ballots that are open, ahead of the judges.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THIS IS THE FEATURE AND THE BUTTON IS THE FALLBACK
     * ══════════════════════════════════════════════════════════════════════════
     *
     * A judge opens a shortlist of forty. Every map is generated on demand, so the whole
     * panel's orientation is forty button presses and forty waits of up to thirty seconds
     * each — during which the judge has nothing to do but start reading, which is the moment
     * the map existed to come before. The feature was built to save the first ten minutes of
     * every dossier and, arriving late, saved none of them.
     *
     * Nothing about the design objects to doing this early. The map is cached PER NOMINEE and
     * shared by the whole panel — deliberately, so orientation is not a variable between
     * judges — so a map made at 04:00 by cron is byte-identical to the one the first judge
     * would have paid to wait for, and it is made once instead of once per panel member who
     * gets there first. The cost is identical; only the waiting moves.
     *
     * ── WHAT IT WILL NOT DO ──────────────────────────────────────────────────
     *
     *  · It never touches the sandbox. `DemoSeeder` builds real rows with real flags, and a
     *    rehearsal category would otherwise consume a genuine round's budget every night.
     *  · It only maps the SHORTLIST. That is what a ballot renders, and a map for a nominee
     *    no panel will see is money spent on a page nobody opens.
     *  · It stops the moment the capability is unavailable — budget spent, switch off, no
     *    provider — rather than logging one refusal per nominee. A sweep that writes forty
     *    BUDGET_CALLS rows makes the audit log unreadable on the day it matters.
     *  · It does not retry a dossier that just failed. {@see RETRY_AFTER_MIN}
     *
     * @return int maps made; 0 means there was nothing to do
     */
    public static function sweep(int $limit = self::SWEEP_LIMIT): int
    {
        if (!AiGateway::available(self::CAPABILITY)) return 0;

        $made = 0;
        foreach (self::pending($limit) as $id) {
            // `forNominee()` reads the dossier again rather than being handed {@see
            // pending()}'s copy. Four extra queries per map on a background task is nothing,
            // and the alternative — a parameter only the sweep passes — is how the stored
            // hash and the text it was computed from drift apart. It also means a dossier
            // that changed since the selection is mapped as it stands NOW, with the
            // matching hash.
            $r = self::forNominee($id);
            if ($r['ok']) { $made++; continue; }

            // A failure that is not about THIS dossier stops the sweep rather than walking
            // the rest of the shortlist into the same wall. `available()` was true when we
            // started; if it is false now, the budget ran out mid-sweep and every remaining
            // nominee would only add a refusal row.
            if (!AiGateway::available(self::CAPABILITY)) break;
        }

        return $made;
    }

    /**
     * Which nominees need a map right now, in the order the sweep should make them.
     *
     * ── SEPARATE FROM sweep() BECAUSE THIS IS THE PART THAT COSTS MONEY ──────
     *
     * `sweep()` is a loop around one model call; every decision about WHETHER to make that
     * call is here. Keeping them together would have made the interesting half testable only
     * by configuring a provider key and letting a test suite spend real tokens — so the
     * selection would have gone untested, which for the one scheduled task on this platform
     * that spends money is the wrong thing to leave uncovered.
     *
     * Four rules, each of which is a bill if it goes wrong:
     *
     *  · Only cycles genuinely IN the judging phase, read through {@see CyclePolicy} rather
     *    than from `cached_status` — the platform documents that column as possibly stale,
     *    and a sweep driven by a stale phase spends a round's budget early or misses the
     *    round entirely.
     *  · Only the published SHORTLIST, which is what a ballot renders. A map for a nominee
     *    no panel will see is money spent on a page nobody opens.
     *  · Never the sandbox. `DemoSeeder` builds real rows with real flags, so a rehearsal
     *    category would otherwise consume a genuine round's budget every night.
     *  · Never a dossier whose CURRENT hash already has a map, and never one that failed in
     *    the last {@see RETRY_AFTER_MIN} minutes.
     *
     * @return list<int>
     */
    public static function pending(int $limit = self::SWEEP_LIMIT): array
    {
        $ids = self::awaitingJudgement();
        if ($ids === []) return [];

        // The hashes for every candidate in four queries, so deciding WHICH need a map costs
        // the same whether the shortlist is six or six hundred.
        $dossiers = self::dossiers($ids);

        try {
            // Every map already held for these nominees — one query. Compared BY HASH, so a
            // superseded map counts as absent: a nominee who added evidence yesterday needs
            // a new one, and the ballot is already marking the old one out of date.
            // {@see forBallot()}
            $held = DB::table('gates_judge_orientation')
                ->whereIn('nominee_id', $ids)
                ->where('status', 'ok')
                ->where('prompt_version', self::PROMPT_VERSION)
                // A nominee re-read after a change has SEVERAL ok rows, and `pluck` keeps
                // whichever arrives last. Without an order that is undefined, and picking
                // the older row would report a current map as superseded — so the sweep
                // would regenerate it, at cost, on every single tick for the rest of the
                // round. `forBallot()` orders for the same reason.
                ->orderBy('id')
                ->pluck('content_hash', 'nominee_id');
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($ids as $id) {
            if (count($out) >= max(1, $limit)) break;

            $hash = (string) ($dossiers[$id]['hash'] ?? '');
            if ($hash === '') continue;                              // nothing written yet
            if ((string) ($held[$id] ?? '') === $hash) continue;     // current map in hand
            if (self::recentlyFailed($id, $hash)) continue;

            $out[] = $id;
        }

        return $out;
    }

    /**
     * Nominees on a ballot that is open for judging right now.
     *
     * Phase is read through {@see CyclePolicy::phaseFor()} rather than from
     * `cached_status`, for the reason `JudgeService::cycleToJudge()` gives: the platform
     * itself documents that column as possibly stale, and a sweep driven by a stale phase
     * either spends a round's budget early or misses the round entirely.
     *
     * @return list<int>
     */
    private static function awaitingJudgement(): array
    {
        try {
            // The sandbox programme by slug, so its cycles can be dropped before anything
            // else is asked about them.
            $demo = DB::table('gates_award_programmes')
                ->where('slug', DemoSeeder::PROGRAMME_SLUG)->value('id');

            $cycles = DB::table('gates_award_cycles')
                ->when($demo !== null, fn ($q) => $q->where('programme_id', '!=', (int) $demo))
                ->orderByDesc('year')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($cycles as $c) {
            try {
                if (CyclePolicy::phaseFor($c) !== CyclePhase::Judging) continue;
            } catch (\Throwable) {
                // A row with unreadable windows is not a reason to sweep the wrong cycle.
                continue;
            }

            try {
                $catIds = DB::table('gates_award_categories')
                    ->where('cycle_id', $c->id)->pluck('id')->all();
                if ($catIds === []) continue;

                $q = DB::table('gates_nominees')
                    ->whereIn('category_id', $catIds)
                    ->whereIn('status', ['approved', 'winner', 'runner_up']);
                MergeService::notMerged($q);                 // tombstones are not on a ballot

                $nominees = $q->pluck('id')->all();

                // The published shortlist is what a ballot actually shows. An unpublished or
                // absent one means no panel is reading anything yet, so there is nothing to
                // prepare — and mapping the whole field on the chance a cut lands later is
                // the money this bound exists to protect.
                $shortlisted = ShortlistService::shortlistedIn((int) $c->id);
                if ($shortlisted === []) continue;

                foreach ($nominees as $id) {
                    if (isset($shortlisted[(int) $id])) $out[] = (int) $id;
                }
            } catch (\Throwable $e) {
                error_log('[judge-assist] sweep could not read cycle ' . $c->id . ': '
                        . $e->getMessage());
            }
        }

        return array_values(array_unique($out));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PARSING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Validate the model's JSON, or return null so the gateway discards it.
     *
     * Discarded and never coerced — the discipline the rest of the platform applies. A map
     * with a missing key rendered as an empty section reads to a judge as "there is no
     * evidence", which is a claim about the nominee that nobody made.
     *
     * @return array<string,mixed>|null
     */
    public static function parse(string $raw): ?array
    {
        $d = json_decode(trim($raw), true);
        if (!is_array($d)) {
            // Some providers wrap JSON in prose despite being asked not to. One salvage
            // attempt on the outermost braces, then give up.
            if (preg_match('/\{.*\}/s', $raw, $m)) $d = json_decode($m[0], true);
        }
        if (!is_array($d)) return null;

        $rests = trim((string) ($d['rests_on'] ?? ''));
        if ($rests === '') return null;

        $list = static function (mixed $v): array {
            if (!is_array($v)) return [];
            $out = [];
            foreach ($v as $x) {
                if (!is_scalar($x)) continue;
                $s = trim((string) $x);
                if ($s !== '') $out[] = mb_substr($s, 0, 300);
                if (count($out) >= 5) break;
            }
            return $out;
        };

        return [
            'rests_on'  => mb_substr($rests, 0, 400),
            'evidenced' => $list($d['evidenced'] ?? []),
            'asserted'  => $list($d['asserted'] ?? []),
            'gaps'      => $list($d['gaps'] ?? []),
            'check'     => $list($d['check'] ?? []),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // STORAGE
    // ═══════════════════════════════════════════════════════════════════════

    /** @param array<string,mixed> $map */
    private static function store(int $nomineeId, string $hash, array $map,
                                  string $status, string $error): void
    {
        try {
            DB::table('gates_judge_orientation')->insert([
                'nominee_id'     => $nomineeId,
                'content_hash'   => $hash !== '' ? $hash : null,
                'rests_on'       => isset($map['rests_on']) ? mb_substr((string) $map['rests_on'], 0, 400) : null,
                'evidenced_json' => json_encode($map['evidenced'] ?? []),
                'asserted_json'  => json_encode($map['asserted'] ?? []),
                'gaps_json'      => json_encode($map['gaps'] ?? []),
                'check_json'     => json_encode($map['check'] ?? []),
                'prompt_version' => self::PROMPT_VERSION,
                'status'         => $status,
                'error'          => $error !== '' ? mb_substr($error, 0, 300) : null,
                'created_at'     => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            error_log('[judge-assist] could not store map for nominee ' . $nomineeId . ': '
                    . $e->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private static function hydrate(object $r): array
    {
        $list = static fn (?string $j): array => is_array($v = json_decode((string) $j, true)) ? $v : [];

        return [
            'nominee_id' => (int) $r->nominee_id,
            'rests_on'   => (string) ($r->rests_on ?? ''),
            'evidenced'  => $list($r->evidenced_json ?? null),
            'asserted'   => $list($r->asserted_json ?? null),
            'gaps'       => $list($r->gaps_json ?? null),
            'check'      => $list($r->check_json ?? null),
            'at'         => (string) ($r->created_at ?? ''),
            // The dossier this was a map OF. Carried so {@see forBallot()} can say whether
            // it still is one; the single-nominee path matches on it in the query instead.
            'hash'       => (string) ($r->content_hash ?? ''),
            // Only ever true where it has actually been checked against the current dossier.
            // Absent would render as false in Twig anyway, so it is stated: a judge reading
            // "up to date" must not be reading an unset key.
            'stale'      => false,
        ];
    }

    /** Which gate closed, in words a judge can act on — or at least understand. */
    private static function whyUnavailable(): string
    {
        if (!AiGateway::globallyEnabled())                   return 'AI is switched off for this platform.';
        if (!AiGateway::capabilityEnabled(self::CAPABILITY)) return 'Dossier summaries are switched off.';

        $cap = AiCapability::find(self::CAPABILITY);
        if ($cap !== null) {
            $spent = AiGateway::spentToday(self::CAPABILITY);
            if ($spent['calls'] >= $cap->callsPerDay || $spent['tokens'] >= $cap->tokensPerDay) {
                return 'Today\'s budget for dossier summaries is spent. It resets at midnight, '
                     . 'and the dossiers themselves are unaffected.';
            }
        }

        return 'Dossier summaries are not available right now.';
    }
}
