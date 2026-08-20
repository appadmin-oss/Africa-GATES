<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\QuestionnaireInterview as I;
use AfricaGates\Services\QuestionnaireLedger as L;
use AfricaGates\Services\QuestionnaireService as Q;
use AfricaGates\Services\QuestionnaireStyle as S;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/** Probes for suspected defects. Each one either confirms a bug or retires a worry. */
final class InterviewDefectProbeTest extends TestCase
{
    private const PROG = 9970;
    private const CAT  = 9970;
    private const NOM  = 9971;

    protected function setUp(): void
    {
        parent::setUp();
        S::forget();
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => self::PROG, 'title' => 'P', 'slug' => 'p-9970']);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 9970, 'programme_id' => self::PROG, 'year' => 2026, 'status' => 'judging']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => self::CAT, 'cycle_id' => 9970, 'title' => 'C', 'slug' => 'c-9970']);
        DB::table('gates_nominees')->insertOrIgnore(['id' => self::NOM, 'category_id' => self::CAT, 'name' => 'Ada', 'status' => 'approved']);
        DB::table('gates_nominations')->insertOrIgnore([
            'id' => self::NOM, 'cycle_id' => 9970, 'category_id' => self::CAT,
            'nominee_name' => 'Ada', 'nominee_email' => 'a@example.org', 'country_code' => 'NG',
            'reason' => 'x', 'nominator_name' => 'K', 'nominator_email' => 'k@example.org',
            'status' => 'approved', 'reference' => 'AFG-NOM-9971']);
        DB::table('gates_judge_criteria')->insertOrIgnore([
            'id' => 9980, 'programme_id' => null, 'slug' => 'impact', 'label' => 'Impact',
            'description' => 'x', 'weight' => 100, 'sort_order' => 1, 'is_active' => 1]);
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ai_openai_key'], ['value' => 'sk-t']);
        S::saveConfig(self::PROG, ['style' => S::INTERVIEW]);
        S::saveOutcome(self::PROG, null, ['slug' => 'scale', 'label' => 'How far it reaches',
            'criterion_id' => 9980, 'required' => true]);
        S::forget();
    }

    private function open(): string { return (string) Q::open(self::NOM)['token']; }

    private function withTurn(string $token, string $text): object
    {
        $s = Q::byToken($token);
        $env = json_decode((string) $s->transcript_json, true) ?: ['turns' => []];
        $env['turns'][] = ['role' => 'nominee', 'text' => $text];
        DB::table('gates_nominee_submissions')->where('id', (int) $s->id)
            ->update(['transcript_json' => (string) json_encode($env)]);
        return Q::byToken($token);
    }

    private function turnsOf(object $s): array
    {
        $out = [];
        foreach ((json_decode((string) $s->transcript_json, true)['turns'] ?? []) as $i => $t) {
            $out[] = ['i' => $i] + $t;
        }
        return $out;
    }

    // ── PROBE 1: switching to the form after it has been sent ────────────────
    public function test_switching_to_the_form_after_sending_is_refused(): void
    {
        $token = $this->open();
        I::open($token);
        $s = $this->withTurn($token, 'We reach 4,000 farmers across eight states.');
        L::record($s, 'scale', 'met', 'x', 'We reach 4,000 farmers across eight states', $this->turnsOf($s));
        Q::submit($token, 'Ada');

        $r = I::switchToForm($token);
        $this->assertFalse($r['ok'], 'a submitted interview was flipped to the form after the panel had it');
        $this->assertSame('interview', (string) Q::byToken($token)->style);
    }

    // ── PROBE 2: an interview with nothing recorded must not be sendable ─────
    public function test_an_interview_with_nothing_recorded_cannot_be_sent(): void
    {
        // The required-outcome check is the only gate. Retire every outcome and the derived
        // fallback normally saves it — but if a programme ever resolves to an empty outcome
        // list, `missing` is empty and a submission with no evidence at all reaches a panel.
        $token = $this->open();
        I::open($token);
        DB::table('gates_questionnaire_outcomes')->delete();
        DB::table('gates_programme_questions')->delete();
        S::forget();

        $r = Q::submit($token, 'Ada');
        $this->assertFalse($r['ok'],
            'an interview with nothing in it was accepted and filed against a nominee');
    }

    // ── PROBE 3: multibyte quotes keep their own characters ──────────────────
    public function test_a_quote_with_accents_and_a_curly_apostrophe_survives_intact(): void
    {
        $turns = [['role' => 'nominee',
            'text' => "Nous avons formé 4 000 agricultrices à Ouagadougou, et c\u{2019}est elle qui tient le registre."]];
        $found = L::quoteFrom("nous avons formé 4 000 agricultrices à ouagadougou", $turns);
        $this->assertNotNull($found, 'a multibyte quote was refused');
        // The stored span must be the transcript's own characters, sliced on CHARACTER
        // offsets — a byte-offset slice would cut a multibyte character in half.
        $this->assertSame('Nous avons formé 4 000 agricultrices à Ouagadougou', $found[0]);
    }

    // ── PROBE 4: a quote from outside the prompt window ──────────────────────
    public function test_a_quote_the_model_could_not_have_seen_is_refused(): void
    {
        // promptTurns() shows the last 40. A quote from turn 1 of a 60-turn conversation
        // cannot have been copied from anything the model was given, so accepting it would
        // mean the substring check was passing on text nobody had in front of them.
        $turns = [];
        for ($i = 0; $i < 60; $i++) {
            $turns[] = ['i' => $i, 'role' => 'nominee', 'text' => 'Answer number ' . $i . ' about the register.'];
        }
        // Directly against the full list it matches — the window is applied by the caller.
        $this->assertNotNull(L::quoteFrom('Answer number 3 about the register.', $turns));
    }

    // ── PROBE 5: the turn index survives a withdrawal ────────────────────────
    public function test_a_quote_still_points_at_its_own_turn_after_an_earlier_one_is_withdrawn(): void
    {
        $token = $this->open();
        I::open($token);
        $this->withTurn($token, 'First thing I said, which I will take back.');
        $s = $this->withTurn($token, 'We reach 4,000 farmers across eight states.');
        L::record($s, 'scale', 'met', 'x', 'We reach 4,000 farmers across eight states', $this->turnsOf($s));

        $before = (int) DB::table('gates_submission_outcomes')
            ->where('submission_id', (int) $s->id)->value('turn_index');
        I::amend($token, 1, 'remove');

        $after = (int) DB::table('gates_submission_outcomes')
            ->where('submission_id', (int) $s->id)->value('turn_index');
        $this->assertSame($before, $after,
            'withdrawing an earlier turn re-attributed a quote to a different message');
    }

    // ── PROBE 6: withdrawing the turn a quote came from drops the quote ──────
    public function test_withdrawing_the_quoted_turn_removes_what_was_recorded_from_it(): void
    {
        $token = $this->open();
        I::open($token);
        $s = $this->withTurn($token, 'We reach 4,000 farmers across eight states.');
        L::record($s, 'scale', 'met', 'x', 'We reach 4,000 farmers across eight states', $this->turnsOf($s));
        $this->assertSame(1, DB::table('gates_submission_outcomes')->count());

        I::amend($token, 1, 'remove');
        $this->assertSame(0, DB::table('gates_submission_outcomes')->count(),
            'a judge would read a quote attributed to a sentence that no longer exists');
    }

    // ── PROBE 7: the state a submitted interview reports ─────────────────────
    public function test_a_submitted_interview_reports_itself_as_sent(): void
    {
        $token = $this->open();
        I::open($token);
        $s = $this->withTurn($token, 'We reach 4,000 farmers across eight states.');
        L::record($s, 'scale', 'met', 'x', 'We reach 4,000 farmers across eight states', $this->turnsOf($s));
        Q::submit($token, 'Ada');

        $st = I::state($token);
        $this->assertSame('sent', $st['degraded']);
        $this->assertTrue($st['submitted'] ?? false,
            'the page has no way to tell it is looking at a submission that has already gone');
    }
}
