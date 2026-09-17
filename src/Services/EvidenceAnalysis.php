<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\LocalMedia;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Reading a nominee's uploaded documents, so a judge knows what is in them.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS IS FOR, AND WHAT IT MUST NEVER BECOME
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A dossier arrives as six PDFs called `scan_0043.pdf`. A judge with forty nominees to get
 * through opens two of them. Everything the nominee actually gathered — the council letter,
 * the water board certificate, the press cutting — is invisible, and the nominee is judged
 * on the two files whose names happened to look promising.
 *
 * This reads each file and says what it is: a type, a summary, the claims it makes, the
 * dates and names in it, and whether it is legible. That is a FINDING AID. It is not a
 * score, it is not a verdict, and it is declared `advisory` in {@see AiCapability}, which
 * {@see AiGateway} enforces rather than merely documents.
 *
 * Three things are therefore deliberately absent, and they are absent by design rather than
 * by omission:
 *
 *   · No number that ranks a nominee against another. `legibility` scores the SCAN — is
 *     this readable — and the schema and the prompt both say so, because a field called
 *     "quality" on a nominee's evidence becomes a score somebody sorts by within a week.
 *   · No verification claim. The model cannot tell a real council letter from a good
 *     forgery, and `concerns` is phrased as "worth a human look", never "this is fake".
 *   · No writing into `gates_nominee_evidence`. The analysis lives in its own table so the
 *     nominee's own words and a machine's description of them can never be confused —
 *     the whole provenance argument the evidence table rests on.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * GEMINI ONLY, AND WHY THAT IS NOT A LIMITATION TO FIX
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The platform's generic ladder is Groq → Gemini → Anthropic → OpenAI, and Groq is text
 * only. A PDF sent through that chain reaches a model that cannot see it, from a prompt
 * that mentions a filename — and it will describe the document confidently anyway. A
 * fabricated summary of a file nobody read is worse than no summary, because a judge cannot
 * tell them apart. So this bypasses the chain entirely via {@see GeminiFiles}, and when
 * Gemini cannot answer the recorded result is "not analysed".
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE COST WORK
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Uploads are cached on content hash and reused for 48 hours (see {@see GeminiFiles}), so
 * a second pass over a dossier sends no bytes. Analyses are cached on the same hash plus
 * the prompt version, so re-opening a judge's screen costs nothing at all — and changing
 * the prompt re-analyses, which is the behaviour somebody tuning a prompt needs.
 *
 * Batching is per NOMINEE and not per file: six documents in one request is one round trip
 * and one copy of the instructions instead of six of each. That is the single biggest saving
 * available here, and it is why {@see forNominee()} exists rather than only {@see one()}.
 */
final class EvidenceAnalysis
{
    public const CAPABILITY = 'evidence.analyse';

    /**
     * Bumped when the prompt or the schema changes in a way that makes an old answer
     * incomparable to a new one.
     *
     * The cache key includes it, so a bump re-analyses rather than serving an answer
     * produced by instructions nobody can see any more. Stored on the row too, so two
     * analyses of one file can be told apart.
     */
    public const PROMPT_VERSION = 'v1';

    /**
     * Files per request.
     *
     * Not unlimited: at 258 tokens a PDF page, eight ten-page documents is already ~21k
     * tokens of input, and a request that large is slow enough to time out on a page an
     * operator is waiting on. Beyond this the batch is split, which still beats one request
     * per file.
     */
    public const BATCH = 6;

    /**
     * The shape the model must return, per file.
     *
     * A `responseSchema` and not just "reply in JSON": the mime type alone yields JSON of
     * whatever shape the model felt like, which means defensive parsing at the call site
     * and a field quietly vanishing when the model changes its mind.
     *
     * @return array<string,mixed>
     */
    public static function schema(): array
    {
        return [
            'type'  => 'ARRAY',
            'items' => [
                'type'       => 'OBJECT',
                'properties' => [
                    'ref'        => ['type' => 'STRING',
                                     'description' => 'The document reference given in the prompt, exactly.'],
                    'doc_type'   => ['type' => 'STRING',
                                     'description' => 'What kind of document this is, in three words or fewer '
                                                    . '(e.g. "council letter", "test certificate", "press cutting", '
                                                    . '"photograph", "unclear").'],
                    'summary'    => ['type' => 'STRING',
                                     'description' => 'What the document shows, in two or three sentences, '
                                                    . 'written for somebody who has not opened it.'],
                    'claims'     => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'],
                                     'description' => 'Specific factual claims the document makes. Quote figures '
                                                    . 'and dates as written. At most six.'],
                    'dates'      => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'],
                                     'description' => 'Dates that appear in the document, as written.'],
                    'names'      => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'],
                                     'description' => 'Organisations and official bodies named. Not private '
                                                    . 'individuals.'],
                    'legibility' => ['type' => 'INTEGER',
                                     'description' => 'How READABLE the scan is, 0-100. This describes the image '
                                                    . 'quality only and is never a judgement of the work.'],
                    'concerns'   => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'],
                                     'description' => 'Things a human reviewer should look at themselves: pages '
                                                    . 'missing, text cut off, a date that does not match the '
                                                    . 'claim, no letterhead where one would be expected. Never '
                                                    . 'assert that a document is forged.'],
                ],
                'required' => ['ref', 'doc_type', 'summary', 'legibility'],
            ],
        ];
    }

    /**
     * The instruction. Kept here and versioned rather than in a settings row, for now:
     * {@see \AfricaGates\Services\AiCapability} owns the model and the budget, and an
     * editable prompt is its own piece of work with its own audit requirements.
     */
    public static function system(): string
    {
        return <<<'TXT'
        You are helping a judging panel find their way around documents a nominee submitted
        about their own work. You are a FINDING AID, not a judge.

        For each document you are given, describe what it is and what it shows, so that
        somebody who has not opened it knows whether they need to.

        Rules you must not break:
        - Describe only what is actually in the document. If a page is blank, illegible or
          missing, say that rather than inferring what it probably said.
        - Never score, rank or evaluate the nominee or their work. `legibility` describes
          how readable the SCAN is and nothing else.
        - Never assert that a document is forged, altered or false. If something does not
          look right — a date that contradicts the letter, a missing letterhead, a cropped
          signature — put it in `concerns` as something for a person to check.
        - Do not name private individuals. Organisations and official bodies are fine.
        - Quote figures and dates exactly as they are written. Do not convert or round them.
        - Answer for every document reference you were given, in the same order, even when
          the answer is that you could not read it.
        TXT;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // READING WHAT IS ALREADY KNOWN
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * The current analysis for one evidence row, or null.
     *
     * Matched on the CONTENT HASH as well as the id: a nominee who replaces a file keeps
     * the same evidence row, and serving the old analysis for new bytes would describe a
     * document that is no longer there.
     *
     * @return array<string,mixed>|null
     */
    public static function forEvidence(int $evidenceId, string $contentHash = ''): ?array
    {
        try {
            $q = DB::table('gates_evidence_analysis')
                ->where('evidence_id', $evidenceId)
                ->where('status', 'ok')
                ->where('prompt_version', self::PROMPT_VERSION);

            if ($contentHash !== '') $q->where('content_hash', $contentHash);

            $row = $q->orderByDesc('id')->first();

            return $row ? self::hydrate($row) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Every analysis for a nominee, keyed by evidence id.
     *
     * One query for a whole dossier, because the judge's screen renders a dozen rows and a
     * query per row is how a page that felt fine with three nominees stops being usable
     * with forty.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forNomineeMap(int $nomineeId): array
    {
        try {
            $rows = DB::table('gates_evidence_analysis')
                ->where('nominee_id', $nomineeId)
                ->where('prompt_version', self::PROMPT_VERSION)
                ->orderBy('id')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            // Later rows overwrite earlier ones, so the newest analysis per evidence id
            // wins — which is what `orderBy('id')` above is for.
            $out[(int) $r->evidence_id] = self::hydrate($r);
        }

        return $out;
    }

    /**
     * The same thing for a whole ballot at once, keyed nominee → evidence id.
     *
     * Separate from {@see forNomineeMap()} rather than a loop around it for the reason
     * that method exists at all: a judge's ballot carries every nominee in a category, and
     * calling the single-nominee version per nominee reintroduces exactly the query storm
     * it was written to avoid, one level up.
     *
     * @param list<int> $nomineeIds
     * @return array<int,array<int,array<string,mixed>>>
     */
    public static function forNomineesMap(array $nomineeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $nomineeIds))));
        if ($ids === []) return [];

        try {
            $rows = DB::table('gates_evidence_analysis')
                ->whereIn('nominee_id', $ids)
                ->where('prompt_version', self::PROMPT_VERSION)
                ->orderBy('id')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $out = array_fill_keys($ids, []);
        foreach ($rows as $r) {
            $out[(int) $r->nominee_id][(int) $r->evidence_id] = self::hydrate($r);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function hydrate(object $r): array
    {
        $list = static fn (?string $j): array => is_array($v = json_decode((string) $j, true)) ? $v : [];

        return [
            'id'         => (int) $r->id,
            'evidence_id'=> (int) $r->evidence_id,
            'status'     => (string) $r->status,
            'doc_type'   => (string) ($r->doc_type ?? ''),
            'summary'    => (string) ($r->summary ?? ''),
            'claims'     => $list($r->claims_json ?? null),
            'dates'      => $list($r->dates_json ?? null),
            'names'      => $list($r->names_json ?? null),
            'concerns'   => $list($r->concerns_json ?? null),
            'legibility' => $r->legibility === null ? null : (int) $r->legibility,
            'model'      => (string) ($r->model ?? ''),
            'pages'      => (int) ($r->pages ?? 0),
            'error'      => (string) ($r->error ?? ''),
            'at'         => (string) ($r->created_at ?? ''),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // RUNNING IT
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Analyse every unanalysed file in a nominee's dossier.
     *
     * @return array{ok:bool, analysed:int, skipped:int, cached:int, failed:int,
     *               message:string, items:array<int,array<string,mixed>>}
     */
    public static function forNominee(int $nomineeId, int $adminId = 0, bool $force = false): array
    {
        $none = ['ok' => false, 'analysed' => 0, 'skipped' => 0, 'cached' => 0, 'failed' => 0,
                 'items' => []];

        if (!AiGateway::available(self::CAPABILITY)) {
            // Says WHICH gate closed, because "AI is off", "this feature is off" and "the
            // budget is spent" are three different things to do about it.
            return $none + ['message' => self::whyUnavailable()];
        }

        $files = self::filesFor($nomineeId);
        if ($files === []) {
            return $none + ['message' => 'This nominee has no readable files attached.'];
        }

        $gemini = GeminiFiles::boot();
        if (!$gemini->configured()) {
            return $none + ['message' => 'File reading needs a Gemini API key. Add one in '
                                       . 'Settings — the other providers cannot read documents.'];
        }

        $cap   = AiCapability::find(self::CAPABILITY);
        $model = self::modelName($cap);

        $todo    = [];
        $cached  = 0;
        $skipped = 0;

        foreach ($files as $f) {
            if (!$force && self::forEvidence((int) $f['evidence_id'], (string) $f['hash']) !== null) {
                $cached++;
                continue;
            }
            if (!$f['ok']) {
                // Recorded as a skip rather than dropped, so the screen can say "this one
                // is a .docx" instead of leaving a row mysteriously blank forever.
                self::store((int) $f['evidence_id'], $nomineeId, (string) $f['hash'], [],
                            $model, 0, 0, (int) $f['pages'], 'skipped', (string) $f['reason'], $adminId);
                $skipped++;
                continue;
            }
            $todo[] = $f;
        }

        if ($todo === []) {
            return ['ok' => true, 'analysed' => 0, 'skipped' => $skipped, 'cached' => $cached,
                    'failed' => 0, 'items' => self::forNomineeMap($nomineeId),
                    'message' => $cached > 0
                        ? $cached . ' file(s) already read — nothing new to do.'
                        : 'Nothing here can be read by the model.'];
        }

        $analysed = 0;
        $failed   = 0;
        $lastErr  = '';

        foreach (array_chunk($todo, self::BATCH) as $batch) {
            $r = self::runBatch($gemini, $model, $nomineeId, $batch, $adminId);
            $analysed += $r['analysed'];
            $failed   += $r['failed'];
            if ($r['error'] !== '') $lastErr = $r['error'];
        }

        $msg = $analysed . ' file(s) read.';
        if ($cached > 0)  $msg .= ' ' . $cached . ' were already done.';
        if ($skipped > 0) $msg .= ' ' . $skipped . ' could not be sent (wrong type, too big, or missing).';
        if ($failed > 0)  $msg .= ' ' . $failed . ' failed' . ($lastErr !== '' ? ': ' . $lastErr : '.');

        return ['ok' => $analysed > 0, 'analysed' => $analysed, 'skipped' => $skipped,
                'cached' => $cached, 'failed' => $failed,
                'items' => self::forNomineeMap($nomineeId), 'message' => $msg];
    }

    /** One file, for a "read this one" button. Goes through the same batch path. */
    public static function one(int $evidenceId, int $adminId = 0, bool $force = false): array
    {
        $row = null;
        try {
            $row = DB::table('gates_nominee_evidence')->where('id', $evidenceId)
                ->first(['id', 'nominee_id']);
        } catch (\Throwable) {
        }
        if (!$row) return ['ok' => false, 'message' => 'That evidence row does not exist.',
                           'analysed' => 0, 'skipped' => 0, 'cached' => 0, 'failed' => 0, 'items' => []];

        $all = self::filesFor((int) $row->nominee_id);
        $mine = array_values(array_filter($all, fn ($f) => (int) $f['evidence_id'] === $evidenceId));
        if ($mine === []) {
            return ['ok' => false, 'message' => 'That row has no file attached to read.',
                    'analysed' => 0, 'skipped' => 0, 'cached' => 0, 'failed' => 0, 'items' => []];
        }

        if (!AiGateway::available(self::CAPABILITY)) {
            return ['ok' => false, 'message' => self::whyUnavailable(),
                    'analysed' => 0, 'skipped' => 0, 'cached' => 0, 'failed' => 0, 'items' => []];
        }

        $gemini = GeminiFiles::boot();
        if (!$gemini->configured()) {
            return ['ok' => false, 'message' => 'File reading needs a Gemini API key.',
                    'analysed' => 0, 'skipped' => 0, 'cached' => 0, 'failed' => 0, 'items' => []];
        }

        if (!$force && self::forEvidence($evidenceId, (string) $mine[0]['hash']) !== null) {
            return ['ok' => true, 'message' => 'Already read.', 'analysed' => 0, 'skipped' => 0,
                    'cached' => 1, 'failed' => 0, 'items' => self::forNomineeMap((int) $row->nominee_id)];
        }
        if (!$mine[0]['ok']) {
            self::store($evidenceId, (int) $row->nominee_id, (string) $mine[0]['hash'], [],
                        self::modelName(AiCapability::find(self::CAPABILITY)), 0, 0,
                        (int) $mine[0]['pages'], 'skipped', (string) $mine[0]['reason'], $adminId);

            return ['ok' => false, 'message' => (string) $mine[0]['reason'], 'analysed' => 0,
                    'skipped' => 1, 'cached' => 0, 'failed' => 0,
                    'items' => self::forNomineeMap((int) $row->nominee_id)];
        }

        $model = self::modelName(AiCapability::find(self::CAPABILITY));
        $r = self::runBatch($gemini, $model, (int) $row->nominee_id, $mine, $adminId);

        return ['ok' => $r['analysed'] > 0, 'analysed' => $r['analysed'], 'skipped' => 0,
                'cached' => 0, 'failed' => $r['failed'],
                'items' => self::forNomineeMap((int) $row->nominee_id),
                'message' => $r['analysed'] > 0 ? 'Read.' : ($r['error'] ?: 'Could not read that file.')];
    }

    /**
     * One request, however many files.
     *
     * @param list<array<string,mixed>> $batch
     * @return array{analysed:int, failed:int, error:string}
     */
    private static function runBatch(GeminiFiles $gemini, string $model, int $nomineeId,
                                     array $batch, int $adminId): array
    {
        $parts = [];
        $refs  = [];
        $lines = [];

        foreach ($batch as $i => $f) {
            $p = $gemini->part((string) $f['path']);
            if (!$p['ok']) {
                self::store((int) $f['evidence_id'], $nomineeId, (string) $f['hash'], [],
                            $model, 0, 0, (int) $f['pages'], 'skipped', (string) $p['reason'], $adminId);
                continue;
            }
            // A stable, prompt-visible reference. Not the evidence id: an id in a prompt is
            // an invitation to reason about it, and the model has no business knowing our
            // primary keys. Not the filename either — `scan_0043.pdf` is noise, and a
            // filename is attacker-controlled text in a prompt.
            $ref = 'DOC' . ($i + 1);
            $refs[$ref] = $f;
            $parts[]    = $p['part'];

            // The nominee's own title, FENCED. It is the most useful context available —
            // "Borehole handover letter" tells the model what it is looking at — and it is
            // also untrusted text a nominee typed, so it is labelled as a claim rather than
            // presented as fact.
            //
            // Minimised by hand rather than by the gateway, because this call does NOT go
            // through {@see AiGateway::complete()} — it goes straight to the Files API,
            // since no other provider can see an attachment. The capability declares
            // `minimise: true`, and a declaration nothing enforces is a lie, so it is
            // enforced here at the only place the text is assembled.
            $title = AiPrivacy::minimise(trim((string) ($f['title'] ?? '')))['text'];
            $lines[] = $ref . ': ' . ($f['pages'] > 1 ? $f['pages'] . ' pages' : 'one page')
                     . ($title !== '' ? ', which the nominee titled "' . mb_substr($title, 0, 120) . '"' : '');
        }

        if ($parts === []) return ['analysed' => 0, 'failed' => 0, 'error' => ''];

        $prompt = "The documents attached, in order, are:\n" . implode("\n", $lines)
                . "\n\nDescribe each one. Use the reference exactly as given above in the `ref` field. "
                . 'The titles are what the nominee called them and may not be accurate — describe what '
                . 'the document actually is.';

        $cap = AiCapability::find(self::CAPABILITY);

        // ── THE ADMIN'S WORDING HERE TOO ────────────────────────────────────
        //
        // This is the one capability that does NOT go through AiGateway — it talks to the
        // Files API directly, because no other provider can receive an attachment — so it
        // would otherwise be the one feature missing from the prompt editor, silently. A
        // screen listing every AI feature and quietly not controlling one of them is worse
        // than not listing it: somebody edits it, nothing changes, and they conclude the
        // whole editor does nothing.
        $sys = AiPrompt::effective(self::CAPABILITY, self::system());

        $out = $gemini->generate(
            $model, $sys['body'], $prompt, $parts, self::schema(),
            $cap?->maxTokens ?? 1600
        );

        // Recorded whichever way it went, so the admin spend panel and the daily budget
        // both see file analysis. Without this the budget in the capability declaration
        // would be a number nothing counted against.
        AiGateway::record(self::CAPABILITY, $out['ok'] ? 'OK' : 'FAILED', [
            'tokens_in' => $out['tokens_in'], 'tokens_out' => $out['tokens_out'],
            'model' => $model, 'files' => count($parts),
            // Which wording produced this reading, so a summary somebody disputes can be
            // traced to the instruction behind it. 0 is the shipped one.
            'prompt_version' => $sys['version'],
        ]);

        if (!$out['ok']) {
            foreach ($refs as $f) {
                self::store((int) $f['evidence_id'], $nomineeId, (string) $f['hash'], [],
                            $model, $out['tokens_in'], $out['tokens_out'], (int) $f['pages'],
                            'failed', $out['error'], $adminId);
            }
            return ['analysed' => 0, 'failed' => count($refs), 'error' => $out['error']];
        }

        return self::applyAnswers($out['text'], $refs, $model, $nomineeId,
                                  $out['tokens_in'], $out['tokens_out'], $adminId);
    }

    /**
     * Write one request's answer onto the rows it was about.
     *
     * SEPARATE from the request that produced it, so the part with all the ways to be
     * wrong can be tested without a network and without a key: what happens when the model
     * answers for three of four documents, answers for one twice, answers in the wrong
     * order, invents a reference, or returns something that is not a list at all. Every one
     * of those attaches a description to the wrong file if it is got wrong, which is the
     * single worst output this feature has.
     *
     * @param array<string,array<string,mixed>> $refs DOC1 → the file row it stands for
     * @return array{analysed:int, failed:int, error:string}
     */
    public static function applyAnswers(string $text, array $refs, string $model, int $nomineeId,
                                        int $tokensIn, int $tokensOut, int $adminId = 0): array
    {
        if ($refs === []) return ['analysed' => 0, 'failed' => 0, 'error' => ''];

        // Tokens are per REQUEST, so attributing the full count to each row would multiply
        // the recorded spend by the batch size. Divided evenly; the rounding loss is
        // deliberate and is at most one token per row.
        $n     = count($refs);
        $inEa  = intdiv($tokensIn, $n);
        $outEa = intdiv($tokensOut, $n);

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            foreach ($refs as $f) {
                self::store((int) $f['evidence_id'], $nomineeId, (string) $f['hash'], [],
                            $model, $inEa, $outEa, (int) $f['pages'],
                            'failed', 'The answer was not the expected shape.', $adminId);
            }
            return ['analysed' => 0, 'failed' => count($refs),
                    'error' => 'Gemini answered in an unexpected shape.'];
        }

        // Matched on `ref` and NOT on array position. A model that returns three answers
        // for four documents would otherwise shift every description onto the wrong file,
        // and a summary attached to the wrong document is the worst possible output here.
        $byRef = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) continue;
            $ref = strtoupper(trim((string) ($item['ref'] ?? '')));
            if ($ref !== '' && isset($refs[$ref])) $byRef[$ref] = $item;
        }

        $analysed = 0;
        $failed   = 0;

        foreach ($refs as $ref => $f) {
            $item = $byRef[$ref] ?? null;
            if ($item === null) {
                self::store((int) $f['evidence_id'], $nomineeId, (string) $f['hash'], [],
                            $model, $inEa, $outEa, (int) $f['pages'], 'failed',
                            'Gemini did not answer for this document.', $adminId);
                $failed++;
                continue;
            }
            self::store((int) $f['evidence_id'], $nomineeId, (string) $f['hash'], $item,
                        $model, $inEa, $outEa, (int) $f['pages'], 'ok', '', $adminId);
            $analysed++;
        }

        return ['analysed' => $analysed, 'failed' => $failed, 'error' => ''];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // WHAT THERE IS TO READ
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Every evidence row for a nominee that has a file behind it, with the file inspected.
     *
     * `ok` false is a real answer and is carried through rather than filtered out: an
     * operator needs to see "this one is a .docx" instead of a row that silently never
     * gets analysed.
     *
     * @return list<array<string,mixed>>
     */
    public static function filesFor(int $nomineeId): array
    {
        try {
            $rows = DB::table('gates_nominee_evidence')
                ->where('nominee_id', $nomineeId)
                ->whereNotNull('source_url')
                ->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'title', 'source_url']);
        } catch (\Throwable) {
            return [];
        }

        $gemini = GeminiFiles::boot();
        $out    = [];

        foreach ($rows as $r) {
            $stored = trim((string) $r->source_url);
            if ($stored === '' || preg_match('~^https?://~i', $stored)) {
                // An absolute URL is a reference somebody gave, not a file on this disk.
                // Fetching it would put a third party in the path of an admin button and
                // would send the platform out to an arbitrary host on a nominee's say-so.
                continue;
            }

            $path = LocalMedia::file($stored);
            if ($path === '') {
                // Recorded, not skipped silently: a file the database remembers and the
                // disk does not is exactly the state somebody needs telling about.
                $out[] = ['evidence_id' => (int) $r->id, 'title' => (string) $r->title,
                          'path' => '', 'hash' => '', 'pages' => 0, 'ok' => false,
                          'reason' => 'The file is not on this server\'s disk.'];
                continue;
            }

            $info = $gemini->inspect($path);
            $out[] = ['evidence_id' => (int) $r->id, 'title' => (string) $r->title,
                      'path' => $path, 'hash' => (string) $info['hash'],
                      'pages' => (int) $info['pages'], 'ok' => (bool) $info['ok'],
                      'reason' => (string) $info['reason'], 'bytes' => (int) $info['bytes']];
        }

        return $out;
    }

    /** How many files in a dossier are readable, for a button that should say a number. */
    public static function countable(int $nomineeId): array
    {
        $files = self::filesFor($nomineeId);
        $done  = self::forNomineeMap($nomineeId);

        $ready = 0;
        $todo  = 0;
        foreach ($files as $f) {
            if (!$f['ok']) continue;
            $ready++;
            $a = $done[(int) $f['evidence_id']] ?? null;
            if ($a === null || $a['status'] !== 'ok') $todo++;
        }

        return ['files' => count($files), 'readable' => $ready, 'unread' => $todo];
    }

    /**
     * Everything one admin screen needs to render the button and the results, in one call.
     *
     * Assembled here rather than in a controller because the interesting part is the
     * REASON: "read the files" being greyed out is useless on its own, and the four things
     * that grey it out — AI off entirely, this feature off, today's budget spent, no
     * Gemini key — need four different actions from whoever is looking at it.
     *
     * @return array{available:bool, reason:string, model:string, files:int, readable:int,
     *               unread:int, items:array<int,array<string,mixed>>}
     */
    public static function status(int $nomineeId): array
    {
        $cap   = AiCapability::find(self::CAPABILITY);
        $model = self::modelName($cap);

        if (!AiGateway::available(self::CAPABILITY)) {
            return ['available' => false, 'reason' => self::whyUnavailable(), 'model' => $model,
                    'files' => 0, 'readable' => 0, 'unread' => 0,
                    'items' => self::forNomineeMap($nomineeId)];
        }

        if (!GeminiFiles::boot()->configured()) {
            return ['available' => false, 'model' => $model,
                    'reason' => 'This needs a Gemini API key — no other configured provider can '
                              . 'receive a file at all. Add one in Settings.',
                    'files' => 0, 'readable' => 0, 'unread' => 0,
                    'items' => self::forNomineeMap($nomineeId)];
        }

        $c = self::countable($nomineeId);

        return ['available' => true, 'reason' => '', 'model' => $model,
                'files' => $c['files'], 'readable' => $c['readable'], 'unread' => $c['unread'],
                'items' => self::forNomineeMap($nomineeId)];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // STORAGE
    // ═══════════════════════════════════════════════════════════════════════

    /** @param array<string,mixed> $item */
    private static function store(int $evidenceId, int $nomineeId, string $hash, array $item,
                                  string $model, int $tokensIn, int $tokensOut, int $pages,
                                  string $status, string $error, int $adminId): void
    {
        $strings = static function (mixed $v, int $cap = 6): array {
            if (!is_array($v)) return [];
            $out = [];
            foreach ($v as $x) {
                if (!is_scalar($x)) continue;
                $s = trim((string) $x);
                if ($s !== '') $out[] = mb_substr($s, 0, 300);
                if (count($out) >= $cap) break;
            }
            return $out;
        };

        $legibility = $item['legibility'] ?? null;
        $legibility = is_numeric($legibility) ? max(0, min(100, (int) $legibility)) : null;

        try {
            // Superseded rather than updated: an older analysis is what somebody compares
            // against when they ask whether a prompt change was an improvement.
            DB::table('gates_evidence_analysis')->insert([
                'evidence_id'    => $evidenceId,
                'nominee_id'     => $nomineeId,
                'content_hash'   => $hash !== '' ? $hash : null,
                'summary'        => isset($item['summary']) ? mb_substr(trim((string) $item['summary']), 0, 2000) : null,
                'doc_type'       => isset($item['doc_type']) ? mb_substr(trim((string) $item['doc_type']), 0, 60) : null,
                'claims_json'    => json_encode($strings($item['claims'] ?? [])),
                'dates_json'     => json_encode($strings($item['dates'] ?? [], 12)),
                'names_json'     => json_encode($strings($item['names'] ?? [], 12)),
                'concerns_json'  => json_encode($strings($item['concerns'] ?? [])),
                'legibility'     => $legibility,
                'model'          => mb_substr($model, 0, 80),
                'prompt_version' => self::PROMPT_VERSION,
                'tokens_in'      => max(0, $tokensIn),
                'tokens_out'     => max(0, $tokensOut),
                'pages'          => max(0, $pages),
                'status'         => $status,
                'error'          => $error !== '' ? mb_substr($error, 0, 400) : null,
                'created_at'     => Carbon::now()->toDateTimeString(),
                'created_by'     => $adminId ?: null,
            ]);
        } catch (\Throwable $e) {
            error_log('[evidence-analysis] could not store row for evidence ' . $evidenceId
                    . ': ' . $e->getMessage());
        }
    }

    /** `provider:model` → the model name Gemini's endpoint wants. */
    private static function modelName(?AiCapability $cap): string
    {
        $pin = (string) ($cap->model ?? 'gemini:gemini-3.6-flash');
        $bits = explode(':', $pin, 2);

        // A capability repinned to a non-Gemini model would otherwise send a Groq model id
        // to Gemini's endpoint and get a 404 that reads like a key problem.
        return ($bits[0] === 'gemini' && isset($bits[1]) && $bits[1] !== '')
            ? $bits[1]
            : 'gemini-3.6-flash';
    }

    /** Which gate closed, in words an operator can act on. */
    private static function whyUnavailable(): string
    {
        if (!AiGateway::globallyEnabled())                    return 'AI is switched off for this platform.';
        if (!AiGateway::capabilityEnabled(self::CAPABILITY))  return 'Reading uploaded files is switched off.';

        $cap = AiCapability::find(self::CAPABILITY);
        if ($cap !== null) {
            $spent = AiGateway::spentToday(self::CAPABILITY);
            if ($spent['calls'] >= $cap->callsPerDay) {
                return 'Today\'s budget for reading files is spent (' . $cap->callsPerDay . ' calls). '
                     . 'It resets at midnight.';
            }
            if ($spent['tokens'] >= $cap->tokensPerDay) {
                return 'Today\'s token budget for reading files is spent. It resets at midnight.';
            }
        }

        return 'Reading uploaded files is not available right now.';
    }
}
