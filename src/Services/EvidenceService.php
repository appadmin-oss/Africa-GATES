<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * The dossier a judge scores against.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT A JUDGE HAD BEFORE THIS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A name, a country flag, four sliders and a notes box. Impact, Originality, Reach and
 * Integrity out of ten — 55% of a nominee's CPI — produced from almost nothing, which in
 * practice means produced from whatever the judge already knew, or from the single
 * paragraph a nominator wrote to persuade somebody.
 *
 * This assembles what the platform actually holds about a nominee and hands it over in
 * one place, with the provenance attached to every line.
 *
 * ── THE RULE THAT OUTRANKS EVERYTHING ELSE HERE ──────────────────────────────
 *
 * **A dossier never carries popularity.** No vote count, no rank, no "leading their
 * category", no other judge's score or note. The whole justification for weighting an
 * expert panel at 55% against a public vote at 45% is that the two are independent
 * measurements; a dossier that whispered "this one is winning" would make them one
 * measurement counted twice, and the weighting a fiction.
 *
 * It is enforced HERE, in {@see forJudge()}, rather than trusted to templates. The
 * ballot already tells judges "judge on documented impact, not popularity" and then —
 * until this change — walked them through the nominees in vote order. Instructions do
 * not survive contact with a convenient variable; a filter does.
 *
 * ── PROVENANCE IS THE PRODUCT ────────────────────────────────────────────────
 *
 * Every item says who is asserting it, and `nominator_claim` is stated rather than
 * implied. docs/CLAIM-FAIRNESS-AND-FRAUD.md §1 makes the general point: the contact
 * details on a nomination were typed by the NOMINATOR, so they are a claim about the
 * nominee and not proof from them. A nomination's reason paragraph is the same thing and
 * is usually the best-written text in the dossier. Rendered indistinguishably from a
 * verified award record, it invites a panel to score the advocacy.
 *
 * ── AND A THIN DOSSIER IS NOT A WEAK NOMINEE ─────────────────────────────────
 *
 * The failure mode of any evidence system is that whoever is easiest to document scores
 * best. A nominee with a press archive and a filmed interview looks substantial next to
 * a weaver whose nominator wrote four sentences — and the weaver may be the better
 * candidate. So {@see forJudge()} always reports `coverage`, naming what is MISSING as
 * plainly as what is present, and the ballot is expected to show it. A judge who can see
 * "no interview on file" can discount for it. A judge who just sees less cannot.
 */
final class EvidenceService
{
    /**
     * Fields that must never reach a judge, whatever else changes around this class.
     *
     * Checked by name against every item a dossier carries, so an added join or a
     * `select *` cannot quietly reintroduce one.
     *
     * @var list<string>
     */
    public const FORBIDDEN_FIELDS = [
        'vote_count', 'organic_vote_count', 'votes', 'rank', 'position',
        'cpi_score', 'cpi_tier', 'judge_score', 'avg_score', 'panel_avg',
    ];

    /**
     * The dossier for one nominee, safe to put in front of a judge.
     *
     * @return array{items:list<array<string,mixed>>, interviews:list<array<string,mixed>>,
     *               coverage:array{has_interview:bool, has_verified:bool, has_nominee:bool, items:int,
     *                              missing:list<string>, note:string}}
     */
    public function forJudge(int $nomineeId): array
    {
        $items      = $this->items($nomineeId);
        $interviews = $this->interviews($nomineeId);

        return [
            'items'      => $items,
            'interviews' => $interviews,
            'coverage'   => $this->coverage($items, $interviews),
        ];
    }

    /**
     * Dossiers for a whole ballot, in one pass.
     *
     * The ballot renders every nominee in a programme, so a per-nominee call would be a
     * query per nominee per page load on the one screen a judge keeps open for hours.
     *
     * @param list<int> $nomineeIds
     * @return array<int, array{items:list<array<string,mixed>>, interviews:list<array<string,mixed>>, coverage:array<string,mixed>}>
     */
    /**
     * Is this `source_url` actually an uploaded file, and if so how should it be shown?
     *
     * A stored path has no scheme; a real source is an absolute http(s) URL. Cloudinary
     * images are absolute and stay links — they are already served from a delivery URL and
     * nothing is gained by proxying them — but a LOCAL path becomes the judge-gated stream.
     *
     * @return array{0:string, 1:string} [url, kind] — kind is 'pdf', 'image' or ''
     */
    public static function fileFor(string $sourceUrl, int $evidenceId): array
    {
        $v = trim($sourceUrl);
        if ($v === '' || $evidenceId < 1) return ['', ''];

        // An absolute URL is a source, not a file on this disk.
        if (preg_match('~^https?://~i', $v)) return ['', ''];
        // Anything else that is not inside the uploads tree is not ours to stream.
        if (!str_starts_with(ltrim($v, '/'), 'uploads/')) return ['', ''];

        $ext  = strtolower(pathinfo($v, PATHINFO_EXTENSION));
        $kind = $ext === 'pdf' ? 'pdf' : (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? 'image' : '');

        return ['/judge/evidence/' . $evidenceId, $kind];
    }

    public function forBallot(array $nomineeIds): array
    {
        $nomineeIds = array_values(array_unique(array_map('intval', $nomineeIds)));
        if ($nomineeIds === []) return [];

        $itemsBy      = $this->items(null, $nomineeIds);
        $interviewsBy = $this->interviews(null, $nomineeIds);

        $out = [];
        foreach ($nomineeIds as $id) {
            $items = $itemsBy[$id] ?? [];
            $ints  = $interviewsBy[$id] ?? [];
            $out[$id] = [
                'items'      => $items,
                'interviews' => $ints,
                'coverage'   => $this->coverage($items, $ints),
            ];
        }
        return $out;
    }

    // ── the pieces ──────────────────────────────────────────────────────────

    /**
     * Curated evidence rows, plus the nomination's own case for the nominee.
     *
     * When $ids is given the return is grouped by nominee id; otherwise it is a flat
     * list for the single $nomineeId.
     *
     * @param list<int>|null $ids
     */
    private function items(?int $nomineeId, ?array $ids = null): array
    {
        $many = $ids !== null;
        $want = $many ? $ids : [$nomineeId];
        if ($want === [] || $want === [0]) return $many ? [] : [];

        $grouped = array_fill_keys($want, []);

        try {
            $rows = DB::table('gates_nominee_evidence')
                ->whereIn('nominee_id', $want)
                ->where('visible_to_judges', 1)
                ->orderBy('sort_order')->orderBy('id')
                ->get();
            foreach ($rows as $r) {
                // ── A FILE IS NOT A LINK, AND TREATING IT AS ONE BROKE IT ────
                //
                // `source_url` holds one of two very different things: a real external URL,
                // or the STORED PATH of a file the nominee uploaded — see
                // QuestionnaireService::publishEvidence(), which writes `works[].file`
                // straight into this column.
                //
                // The ballot rendered it as `<a :href="it.source_url">` either way. For a
                // path like `uploads/nominee-evidence/2026/08/<uuid>.pdf` — no leading
                // slash — the browser resolved it against the current page and asked for
                // `/judge/uploads/...`, which is a 404. Images survived only because they
                // go to Cloudinary and come back as an absolute https URL; every locally
                // stored PDF was a dead link. That is the reported "they save as images and
                // cannot be previewed in the judges portal": the images were the ones that
                // happened to work.
                //
                // So a file is emitted as a file, behind a route that authorises the judge
                // against THIS nominee — never as a bare path, which would also put private
                // moderation material on a guessable public URL.
                $item = [
                    'kind'         => (string) $r->kind,
                    'title'        => (string) $r->title,
                    'body'         => (string) ($r->body ?? ''),
                    'source_label' => (string) ($r->source_label ?? ''),
                    'source_url'   => (string) ($r->source_url ?? ''),
                    'provenance'   => (string) $r->provenance,
                    'verified'     => (bool) $r->verified,
                ];

                [$fileUrl, $fileKind] = self::fileFor((string) ($r->source_url ?? ''), (int) $r->id);
                if ($fileUrl !== '') {
                    $item['file_url']  = $fileUrl;
                    $item['file_kind'] = $fileKind;
                    // Not a link. Leaving it set would render the broken anchor as well.
                    $item['source_url'] = '';
                }

                $grouped[(int) $r->nominee_id][] = $this->shape($item);
            }
        } catch (\Throwable $e) {
            error_log('[evidence] could not read evidence: ' . $e->getMessage());
        }

        // The nomination itself, folded in rather than left for the judge to go and find.
        // Labelled `nominator_claim` without exception: it is an interested party's
        // written case, which is exactly what makes it useful AND what makes it unsafe to
        // render as a finding.
        foreach ($this->nominationCases($want) as $nid => $cases) {
            foreach ($cases as $c) $grouped[$nid][] = $c;
        }

        return $many ? $grouped : ($grouped[$nomineeId] ?? []);
    }

    /**
     * Published interview transcripts.
     *
     * `draft` is invisible because an unreviewed transcript is an unchecked machine
     * output, and `withdrawn` because consent can be taken back — at which point the row
     * survives for the record and stops being evidence.
     *
     * @param list<int>|null $ids
     */
    private function interviews(?int $nomineeId, ?array $ids = null): array
    {
        $many = $ids !== null;
        $want = $many ? $ids : [$nomineeId];
        if ($want === [] || $want === [0]) return $many ? [] : [];

        $grouped = array_fill_keys($want, []);

        try {
            $rows = DB::table('gates_nominee_interviews')
                ->whereIn('nominee_id', $want)
                ->where('status', 'published')
                // Consent is a precondition, not a footnote. A transcript shown to a
                // panel without it is the nominee's words used against their wishes.
                ->where('consent_given', 1)
                ->orderBy('interviewed_at')->orderBy('id')
                ->get();

            foreach ($rows as $r) {
                $translated = trim((string) ($r->translated_from ?? '')) !== '';
                $machine    = (string) $r->transcript_source !== 'human';

                $grouped[(int) $r->nominee_id][] = [
                    'interviewed_at'    => (string) ($r->interviewed_at ?? ''),
                    'interviewer'       => (string) ($r->interviewer ?? ''),
                    'medium'            => (string) $r->medium,
                    'language'          => (string) $r->language,
                    'translated_from'   => (string) ($r->translated_from ?? ''),
                    'transcript'        => (string) $r->transcript,
                    'transcript_source' => (string) $r->transcript_source,
                    'transcriber'       => (string) ($r->transcriber ?? ''),
                    'provenance'        => 'nominee_supplied',
                    // A single sentence the ballot can print above the text. Both caveats
                    // matter and neither is obvious from the transcript itself: a
                    // translation is somebody's reading of what was said, and a machine
                    // transcript mishears exactly the proper nouns and numbers an impact
                    // claim rests on.
                    'caveat'            => $this->transcriptCaveat($translated, $machine,
                                                                   (string) ($r->translated_from ?? '')),
                ];
            }
        } catch (\Throwable $e) {
            error_log('[evidence] could not read interviews: ' . $e->getMessage());
        }

        return $many ? $grouped : ($grouped[$nomineeId] ?? []);
    }

    /**
     * The nominator's written case, from the approved nomination.
     *
     * `reason_status` is respected: a reason that has not been through moderation is an
     * unreviewed written character assessment of a named third party, which
     * 2026_08_12_nominee_claims already refuses to publish and which has no business in
     * front of a panel either.
     *
     * @param list<int> $ids
     * @return array<int, list<array<string,mixed>>>
     */
    private function nominationCases(array $ids): array
    {
        $out = [];
        try {
            $nominees = DB::table('gates_nominees')->whereIn('id', $ids)
                ->get(['id', 'name', 'category_id']);

            foreach ($nominees as $n) {
                $q = DB::table('gates_nominations')
                    ->where('status', 'approved')
                    ->whereRaw('LOWER(TRIM(nominee_name)) = ?', [mb_strtolower(trim((string) $n->name))]);
                if (($n->category_id ?? null) !== null) $q->where('category_id', (int) $n->category_id);

                if (\AfricaGates\Support\OptionalColumn::on('gates_nominations', 'reason_status')) {
                    $q->where('reason_status', 'approved');
                }

                foreach ($q->get() as $nom) {
                    $reason = trim((string) ($nom->reason ?? ''));
                    if ($reason === '') continue;
                    $out[(int) $n->id][] = $this->shape([
                        'kind'         => 'nomination',
                        'title'        => 'Why they were nominated',
                        'body'         => $reason,
                        'source_label' => trim((string) ($nom->nominee_org ?? '')) !== ''
                            ? 'Nomination · ' . (string) $nom->nominee_org
                            : 'Nomination',
                        'source_url'   => '',
                        'provenance'   => 'nominator_claim',
                        'verified'     => false,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log('[evidence] could not read nomination cases: ' . $e->getMessage());
        }
        return $out;
    }

    // ── the guards ──────────────────────────────────────────────────────────

    /**
     * Strip anything a judge must not see, and attach the label for what is left.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function shape(array $item): array
    {
        foreach (self::FORBIDDEN_FIELDS as $banned) {
            unset($item[$banned]);
        }
        $item['provenance_label'] = self::provenanceLabel((string) ($item['provenance'] ?? ''));
        return $item;
    }

    /**
     * How each provenance should read to a judge.
     *
     * Written as plain statements of who is speaking rather than as confidence grades. A
     * judge asked to weigh "low confidence" has been handed the platform's opinion; a
     * judge told "the person who nominated them says this" has been handed the fact, and
     * weighing it is their job.
     */
    public static function provenanceLabel(string $provenance): string
    {
        return match ($provenance) {
            'nominator_claim'   => 'Said by the person who nominated them — not independently checked',
            'nominee_supplied'  => 'Supplied by the nominee',
            'platform_verified' => 'Checked by Africa GATES',
            'third_party'       => 'From a source outside the nomination',
            'staff_note'        => 'Note from the programme team',
            default             => 'Source not recorded — treat with caution',
        };
    }

    /** One sentence naming what a judge is actually reading. */
    private function transcriptCaveat(bool $translated, bool $machine, string $from): string
    {
        $bits = [];
        if ($translated) {
            $bits[] = 'translated' . ($from !== '' ? ' from ' . $from : '')
                    . ', so the wording is the translator\'s and the meaning may have shifted';
        }
        if ($machine) {
            $bits[] = 'transcribed automatically, and machine transcripts most often get '
                    . 'names, places and numbers wrong';
        }
        if ($bits === []) return 'A human transcript in the language it was spoken in.';
        return 'This transcript was ' . implode('; it was also ', $bits) . '.';
    }

    /**
     * What is here, and — the part that matters — what is not.
     *
     * @param list<array<string,mixed>> $items
     * @param list<array<string,mixed>> $interviews
     * @return array{has_interview:bool, has_verified:bool, has_nominee:bool, items:int,
     *               missing:list<string>, note:string}
     */
    private function coverage(array $items, array $interviews): array
    {
        $hasInterview = $interviews !== [];
        $hasVerified  = false;
        $hasIndep     = false;
        $hasNominee   = false;
        foreach ($items as $i) {
            if (!empty($i['verified'])) $hasVerified = true;
            if (in_array($i['provenance'] ?? '', ['third_party', 'platform_verified'], true)) $hasIndep = true;
            if (($i['provenance'] ?? '') === 'nominee_supplied') $hasNominee = true;
        }

        $missing = [];
        if (!$hasInterview) $missing[] = 'no interview on file';
        // WORDED CAREFULLY, and re-worded once. This used to read "nothing from a source
        // outside the nomination", which became misleading the moment nominees could submit
        // their own evidence: a dossier holding six things the NOMINEE sent is plainly not
        // "nothing outside the nomination", and a judge reading that line would conclude we
        // had gathered nothing. What is actually being tested is INDEPENDENCE — the nominee
        // is a second interested party, not a neutral one — so that is what it now says.
        if (!$hasIndep)     $missing[] = 'nothing from an independent source';
        if (!$hasVerified)  $missing[] = 'nothing independently checked';
        // A separate fact, and a new one: whether the nominee has spoken for themselves at
        // all. A dossier where they were never asked, or never answered, is a different
        // thing from one where they did — and only one of those is about their work.
        if (!$hasNominee)   $missing[] = 'the nominee has not sent anything themselves';

        return [
            'has_interview' => $hasInterview,
            'has_verified'  => $hasVerified,
            'has_nominee'   => $hasNominee,
            'items'         => count($items),
            'missing'       => $missing,
            // Said out loud, because the alternative is a judge reading a short dossier
            // as a weak nominee. How well somebody is documented is a fact about our
            // reach and their access to a camera, not about their work.
            'note'          => $missing === []
                ? 'This dossier is reasonably complete.'
                : 'Thin dossier — ' . implode(', ', $missing) . '. That reflects what we '
                  . 'were able to gather, not the quality of the work. Score what is here.',
        ];
    }
}
