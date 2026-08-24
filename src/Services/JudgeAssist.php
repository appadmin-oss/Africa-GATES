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
     * rest.
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

        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // THE DOSSIER
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Everything written about one nominee, as one block of text plus its hash.
     *
     * Assembled here rather than reusing {@see EvidenceService::forJudge()} because that
     * shapes data for a SCREEN — file urls, provenance chips, colours — and what a model
     * needs is prose. Passing the screen's structure would spend tokens on `file_kind` and
     * `provenance_label` and teach the model that the platform's internals are part of the
     * entry.
     *
     * @return array{text:string, hash:string, truncated:bool}
     */
    public static function dossier(int $nomineeId): array
    {
        $parts = [];

        try {
            $n = DB::table('gates_nominees')->where('id', $nomineeId)
                ->first(['name', 'tagline', 'story', 'organisation']);
        } catch (\Throwable) {
            $n = null;
        }

        if ($n) {
            $parts[] = 'NOMINEE: ' . (string) $n->name
                . (trim((string) ($n->organisation ?? '')) !== ''
                    ? ' (' . (string) $n->organisation . ')' : '');
            if (trim((string) ($n->tagline ?? '')) !== '') $parts[] = 'IN ONE LINE: ' . $n->tagline;
            if (trim((string) ($n->story ?? '')) !== '')   $parts[] = "THE CASE MADE FOR THEM:\n" . $n->story;
        }

        // Evidence rows: title, body and the model's own reading of the file where one
        // exists. `visible_to_judges` is honoured — an item withheld from the panel must not
        // reach the panel through a summary of it.
        try {
            $ev = DB::table('gates_nominee_evidence')
                ->where('nominee_id', $nomineeId)->where('visible_to_judges', 1)
                ->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'title', 'body', 'provenance', 'verified']);
        } catch (\Throwable) {
            $ev = collect([]);
        }

        $analyses = EvidenceAnalysis::forNomineeMap($nomineeId);

        foreach ($ev as $e) {
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

        // Published interview transcripts only. A draft is an unreviewed machine output and
        // a withdrawn one had consent taken back.
        try {
            $iv = DB::table('gates_nominee_interviews')
                ->where('nominee_id', $nomineeId)->where('status', 'published')
                ->orderBy('id')->get(['transcript']);
            foreach ($iv as $r) {
                if (trim((string) $r->transcript) !== '') {
                    $parts[] = "INTERVIEW — THE NOMINEE'S OWN WORDS:\n" . $r->transcript;
                }
            }
        } catch (\Throwable) {
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
