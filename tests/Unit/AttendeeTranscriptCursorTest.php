<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AttendeeBot;
use Tests\TestCase;

/**
 * The transcript cursor, and the reordering that made counting positions unsafe.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS FILE EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `docs/INTERVIEW-BOT-HANDOFF.md` filed the ordinal cursor as the highest live risk in
 * the integration, conditional on something that has to be observed:
 *
 *     "bot_cursor is an ordinal position in the response array, not a provider ID. If
 *      /transcript ever paginates or reorders, the cursor silently skips or repeats."
 *
 * It does not paginate. It reorders, and it does so by design rather than by accident.
 * `TranscriptView` in the provider source at 77e990ed builds its list as
 *
 *     Utterance.objects.filter(recording=…, transcription__isnull=False)
 *                      .order_by("timestamp_ms")
 *
 * — ordered by when the words were SPOKEN, filtered to those already transcribed.
 * Transcription is asynchronous, so an utterance whose text lands late appears at its
 * own offset, in the MIDDLE of the list, shifting every ordinal after it.
 *
 * The tests below are written against fixtures shaped like that response, because the
 * suite deliberately does not mock cURL and a live instance has never existed. That is
 * the same reason {@see AttendeeBot::buildCreateBody()} was split out: logic reachable
 * only over a network is logic nothing can hold.
 */
final class AttendeeTranscriptCursorTest extends TestCase
{
    /** One utterance, shaped as TranscriptUtteranceSerializer emits it. */
    private static function utterance(int $ms, string $speaker, string $uuid, string $text): array
    {
        return [
            'speaker_name'      => $speaker,
            'speaker_uuid'      => $uuid,
            'speaker_is_host'   => false,
            'timestamp_ms'      => $ms,
            'duration_ms'       => 2000,
            'transcription'     => ['transcript' => $text],
        ];
    }

    /**
     * The failure the ordinal scheme produced, stated as the property that must hold:
     * an utterance keeps its identity when another is inserted ahead of it.
     */
    public function test_an_utterance_keeps_its_id_when_a_late_transcription_inserts_ahead_of_it(): void
    {
        $panel   = 'p-uuid-panel';
        $nominee = 'p-uuid-nominee';

        // First poll. The utterance at 8000ms has not been transcribed yet, so the
        // provider omits it — `transcription__isnull=False`.
        $first = AttendeeBot::parseTranscript([
            self::utterance(4000,  'Panel',    $panel,   'Tell us about the club.'),
            self::utterance(12000, 'Nominee',  $nominee, 'We started with six students.'),
        ]);

        // Second poll, moments later. The 8000ms line has now been transcribed and takes
        // its place BY TIMESTAMP — second in the list, not last.
        $second = AttendeeBot::parseTranscript([
            self::utterance(4000,  'Panel',    $panel,   'Tell us about the club.'),
            self::utterance(8000,  'Nominee',  $nominee, 'Gladly.'),
            self::utterance(12000, 'Nominee',  $nominee, 'We started with six students.'),
        ]);

        $idFor = static function (array $rows, string $text): string {
            foreach ($rows as $r) if ($r['text'] === $text) return $r['uid'];
            return '';
        };

        $before = $idFor($first,  'We started with six students.');
        $after  = $idFor($second, 'We started with six students.');

        $this->assertNotSame('', $before);
        $this->assertSame(
            $before,
            $after,
            'the id moved when a line was inserted ahead of it — append() would compare '
            . 'this sentence against an unrelated one and keep both'
        );

        // And the ordinal genuinely did shift, so the assertion above is not vacuous.
        $ord = static function (array $rows, string $text): int {
            foreach ($rows as $r) if ($r['text'] === $text) return $r['index'];
            return 0;
        };
        $this->assertSame(2, $ord($first,  'We started with six students.'));
        $this->assertSame(3, $ord($second, 'We started with six students.'));
    }

    public function test_two_speakers_starting_on_the_same_millisecond_get_different_ids(): void
    {
        $rows = AttendeeBot::parseTranscript([
            self::utterance(9000, 'Panel',   'p-uuid-panel',   'Go ahead.'),
            self::utterance(9000, 'Nominee', 'p-uuid-nominee', 'Thank you.'),
        ]);

        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]['uid'], $rows[1]['uid']);
    }

    public function test_the_same_utterance_fetched_twice_carries_one_id(): void
    {
        $one = AttendeeBot::parseTranscript([self::utterance(4000, 'Panel', 'p-uuid-panel', 'Tell us about the club.')]);
        $two = AttendeeBot::parseTranscript([self::utterance(4000, 'Panel', 'p-uuid-panel', 'Tell us about the club.')]);

        $this->assertSame($one[0]['uid'], $two[0]['uid']);
    }

    /**
     * A recogniser revising an utterance keeps its offset, so the revision must arrive
     * under the SAME id — that is what lets append() replace it in place instead of
     * leaving a truncated sentence beside its finished form.
     */
    public function test_a_revised_utterance_keeps_its_id(): void
    {
        $partial = AttendeeBot::parseTranscript([self::utterance(6000, 'Nominee', 'p-uuid-nominee', 'We started with')]);
        $full    = AttendeeBot::parseTranscript([self::utterance(6000, 'Nominee', 'p-uuid-nominee', 'We started with six students.')]);

        $this->assertSame($partial[0]['uid'], $full[0]['uid']);
        $this->assertNotSame($partial[0]['text'], $full[0]['text']);
    }

    // ══ the watermark ════════════════════════════════════════════════════════

    public function test_the_watermark_reads_behind_itself_so_a_late_insert_is_still_seen(): void
    {
        $late = 900000;                                  // 15:00 into the call
        $rows = AttendeeBot::parseTranscript([
            self::utterance($late - 60000, 'Nominee', 'p-uuid-nominee', 'Transcribed out of order.'),
            self::utterance($late,         'Panel',   'p-uuid-panel',   'And after that?'),
        ], $late);

        $texts = array_column($rows, 'text');
        $this->assertContains(
            'Transcribed out of order.',
            $texts,
            'a line behind the watermark but inside the overlap window was dropped'
        );
    }

    public function test_the_watermark_still_drops_what_is_far_behind_it(): void
    {
        $now  = 3600000;                                 // an hour in
        $rows = AttendeeBot::parseTranscript([
            self::utterance(1000, 'Panel',   'p-uuid-panel',   'Opening question.'),
            self::utterance($now, 'Nominee', 'p-uuid-nominee', 'Closing answer.'),
        ], $now);

        $this->assertSame(['Closing answer.'], array_column($rows, 'text'));
    }

    public function test_a_cursor_left_over_from_the_ordinal_scheme_loses_nothing(): void
    {
        // An old row holds a small count, which reads as a few milliseconds. Everything
        // must come back, so the next write corrects it to a real offset.
        $rows = AttendeeBot::parseTranscript([
            self::utterance(4000,  'Panel',   'p-uuid-panel',   'One.'),
            self::utterance(12000, 'Nominee', 'p-uuid-nominee', 'Two.'),
        ], 7);

        $this->assertCount(2, $rows);
    }

    // ══ shapes ═══════════════════════════════════════════════════════════════

    public function test_a_paginated_envelope_is_accepted_as_well_as_a_bare_list(): void
    {
        $rows = AttendeeBot::parseTranscript([
            'results' => [self::utterance(4000, 'Panel', 'p-uuid-panel', 'Tell us about the club.')],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('Tell us about the club.', $rows[0]['text']);
    }

    public function test_an_utterance_with_no_offset_is_kept_rather_than_filtered_away(): void
    {
        // It cannot be placed against the watermark, so it must not be silently dropped
        // by one. The ordinal id still de-duplicates it.
        $rows = AttendeeBot::parseTranscript([
            ['speaker_name' => 'Panel', 'transcription' => ['transcript' => 'No offset on this one.']],
        ], 500000);

        $this->assertCount(1, $rows);
        $this->assertSame('att-i1', $rows[0]['uid']);
    }

    public function test_empty_and_malformed_rows_are_skipped(): void
    {
        $rows = AttendeeBot::parseTranscript([
            self::utterance(1000, 'Panel', 'p-uuid-panel', ''),
            'not an array',
            self::utterance(2000, 'Panel', 'p-uuid-panel', 'Kept.'),
        ]);

        $this->assertSame(['Kept.'], array_column($rows, 'text'));
    }

    public function test_a_non_list_payload_yields_nothing(): void
    {
        $this->assertSame([], AttendeeBot::parseTranscript('a string'));
        $this->assertSame([], AttendeeBot::parseTranscript(null));
    }

    /**
     * `InterviewLive::append()` truncates a block id to 40 characters, so an id that
     * overruns collides with its neighbour — the same bug in a different coat.
     */
    public function test_an_id_fits_inside_the_length_append_keeps(): void
    {
        $rows = AttendeeBot::parseTranscript([
            self::utterance(359999999, 'A nominee with a very long display name indeed',
                'participant-uuid-that-is-really-quite-long-0123456789', 'Words.'),
        ]);

        $this->assertLessThanOrEqual(40, strlen($rows[0]['uid']));
    }
}
