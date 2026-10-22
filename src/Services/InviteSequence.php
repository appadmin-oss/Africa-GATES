<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Support\DisplayTime;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The countdown letters a nominee receives in the last days before a ceremony.
 *
 * ── WHAT THIS IS AND WHY IT IS NOT THE REMINDER ──────────────────────────────
 *
 * {@see InviteReminders} sends a NUDGE: the day is close, here is your pass. One short
 * body, the same one at every mark, and that is the right message for a judge and the
 * right message at thirty days out.
 *
 * This is something else. It is an ARC — five letters that build on each other over the
 * final week, and each one only works in its position:
 *
 *   WHY → VALUES → RESPONSIBILITY → MESSAGE → ACTION
 *
 * Day five asks a nominee why they were chosen. Day four asks what they stand for. Day
 * three narrows it to their own street, classroom or business. Day two asks them not to
 * come empty-handed. Day one tells them the red carpet is a platform and asks what they
 * will say when they reach it. Sent in the wrong order, or with one missing from the
 * middle, it is five unrelated emails.
 *
 * ── AND WHY A MISSED DAY IS DROPPED RATHER THAN SENT LATE ────────────────────
 *
 * Each letter is ABOUT its distance — "we are officially five days away", "only two days
 * to go", "tomorrow is the day". Scheduled work here runs on a shared host with no shell,
 * and if the tick that would have sent day five never happens, the honest thing is to
 * lose that letter rather than post it on day two saying five. {@see InviteReminders} is
 * built the same way for the same reason: the mark decides when, and the copy tells the
 * truth about where we are.
 *
 * ── NOMINEES, NOT JUDGES ─────────────────────────────────────────────────────
 *
 * Every letter here speaks to somebody who was nominated — their work, their example,
 * what they will say when asked about it. A judge is honoured for how they judged, and
 * "your nomination represents trust" is simply not addressed to them. Judges keep the
 * ordinary reminder, which has its own sentence written for them. A judge sequence would
 * need copy written for judges; inventing it here would put words in an organiser's mouth.
 *
 * ── PLACEHOLDERS ARE TOKENS, AND THE EVENT FILLS MOST OF THEM ────────────────
 *
 * The letters are written once and reused for any ceremony. Everything the platform
 * already knows — the name, the date, the time, the venue, how many days are left — is
 * filled from the event row, so an operator running a second gala types nothing. Only the
 * things no database can know are settings: the theme, the outcome, the values, the
 * closing call, and who signs it. See {@see TOKENS}.
 */
final class InviteSequence
{
    /**
     * What an operator may write in a letter, and where each one comes from.
     *
     * Rendered on the settings screen, so this array is the documentation rather than a
     * copy of it — a token list that lives in a Twig comment is a token list that goes out
     * of date the first time one is added.
     *
     * @var array<string,string>
     */
    public const TOKENS = [
        'name'      => "the nominee's own name",
        'event'     => 'the name of the ceremony',
        'theme'     => "this year's theme",
        'date'      => 'the date, written out',
        'time'      => 'the time, with the zone named',
        'venue'     => 'the venue and city',
        'days'      => 'whole days remaining, as a number',
        'countdown' => '"in 5 days", "tomorrow", "today"',
        'outcome'   => 'the change this ceremony is asking for',
        'values'    => 'the values it celebrates, as a list',
        'action'    => 'the closing call to action',
        'team'      => 'who the letters are signed by',
    ];

    /** The marks these letters are written for: the last five days, one a day. */
    public const DAYS = [5, 4, 3, 2, 1];

    /**
     * The subject and body for one day of the arc.
     *
     * Subjects are NOT settable while bodies are, and that is a deliberate asymmetry. A
     * subject here is a hinge in the arc — "begin with your WHY", "find your message",
     * "own your message", "prepare to speak" — and it is also the line an inbox truncates
     * at sixty characters, so it carries the name and the countdown first by construction.
     * A body is where an organiser's own voice belongs.
     *
     * @return array{day:int, subject:string, body:string, body_setting:string, body_default:string}
     */
    public static function day(int $day, string $audience = InviteAudience::NOMINEE): array
    {
        $audience = InviteAudience::isValid($audience) ? $audience : InviteAudience::NOMINEE;

        $d = self::defaults()[$audience][$day] ?? null;
        if ($d === null) {
            throw new \InvalidArgumentException('No ' . $audience . ' letter for day ' . $day);
        }

        $set = self::settings();
        $key = self::bodyKey($day, $audience);
        $val = trim((string) ($set[$key] ?? ''));

        // ── THE LEGACY KEY ───────────────────────────────────────────────────
        //
        // The nominee letters shipped before there were judge letters, under a key with no
        // audience in it. Renaming without a fallback would silently revert every letter an
        // organiser had already rewritten — a save that appears to work, and a send that
        // goes out in the shipped wording weeks later. One resolver with one documented
        // legacy read; the form only ever writes the new key, so the next save migrates it.
        if ($val === '' && $audience === InviteAudience::NOMINEE) {
            $val = trim((string) ($set['invite_seq_body_' . $day] ?? ''));
        }

        return [
            'day'          => $day,
            'audience'     => $audience,
            'subject'      => $d['subject'],
            'body'         => $val !== '' ? $val : $d['body'],
            'body_setting' => $key,
            'body_default' => $d['body'],
        ];
    }

    /** The settings key holding one audience's letter for one day. */
    public static function bodyKey(int $day, string $audience): string
    {
        return 'invite_seq_body_' . $audience . '_' . $day;
    }

    /**
     * The one word that names a letter's place in the arc.
     *
     * For the schedule table on the invitations screen, where "day 3" says when and
     * nothing says what — and an operator deciding whether to rewrite one needs to know
     * that day three is the one about their own street before they open it.
     */
    public static function label(int $day): string
    {
        return [5 => 'why', 4 => 'values', 3 => 'responsibility',
                2 => 'message', 1 => 'action'][$day] ?? '';
    }

    /** Whether a mark has a letter written for this audience. */
    public static function has(int $day, string $audience = InviteAudience::NOMINEE): bool
    {
        return isset(self::defaults()[$audience][$day]);
    }

    /**
     * Resolve the tokens in one letter against a real ceremony and a real person.
     *
     * ── ESCAPED NOWHERE HERE, ON PURPOSE ─────────────────────────────────────
     *
     * This returns TEXT, and the two things that render it escape it themselves: the Twig
     * template autoescapes, and the plain-text part is plain text. Escaping here would
     * double-encode an apostrophe in a nominee's name into `&amp;#039;` in the HTML half
     * and leave `&#039;` sitting in the plain-text half, which is exactly the shape of bug
     * that reaches an inbox and nothing else.
     *
     * An unknown token is left standing rather than blanked. `{jury}` in a letter is a
     * typo an operator can see and fix; an empty gap where it was is one they cannot.
     */
    public static function fill(string $text, array $values): string
    {
        foreach ($values as $token => $value) {
            $text = str_replace('{' . $token . '}', (string) $value, $text);
        }

        return $text;
    }

    /**
     * Everything a token can stand for, for one invite at one distance.
     *
     * @return array<string,string>
     */
    public static function tokens(object $invite, object $event, int $daysUntil): array
    {
        $set = self::settings();

        $where = trim(implode(', ', array_filter([
            trim((string) ($event->venue ?? '')),
            trim((string) ($event->location ?? '')),
        ])));

        return [
            'name'  => trim((string) ($invite->name ?? '')),
            'event' => trim((string) ($event->title ?? '')),
            // The event's own tagline first. A theme is a fact about ONE ceremony, and a
            // platform that runs several would otherwise put last year's theme in this
            // year's letters — so the per-event field wins and the setting is the fallback
            // for a ceremony whose tagline was never filled in.
            'theme' => trim((string) ($event->tagline ?? '')) !== ''
                ? trim((string) $event->tagline)
                : trim((string) ($set['invite_seq_theme'] ?? '')),

            'date'  => DisplayTime::show((string) $event->event_date, 'l j F Y'),
            'time'  => trim(DisplayTime::showZoned((string) $event->event_date, 'H:i')
                          . ' ' . DisplayTime::abbr()),
            'venue' => $where,

            'days'      => (string) max(0, $daysUntil),
            'countdown' => InviteReminders::countdown($daysUntil),

            'outcome' => self::setting($set, 'invite_seq_outcome', self::DEFAULT_OUTCOME),
            'values'  => self::values(),
            'action'  => self::setting($set, 'invite_seq_action', self::DEFAULT_ACTION),
            'team'    => self::setting($set, 'invite_seq_team', self::DEFAULT_TEAM),
        ];
    }

    public const DEFAULT_OUTCOME = 'raise a generation that values character as much as success';
    public const DEFAULT_ACTION  = 'It begins with us.';
    public const DEFAULT_TEAM    = 'Africa GATES';

    /**
     * The values the ceremony celebrates, as the letters read them out.
     *
     * Stored one per line because that is how somebody writes a list, and rendered with
     * the line breaks intact because day two sets them as a block — five short lines, each
     * its own beat. Joining them into a sentence would flatten the one place in the arc
     * where the typography is the argument.
     */
    public static function values(): string
    {
        $raw = trim((string) (self::settings()['invite_seq_values'] ?? ''));
        if ($raw === '') return implode("\n", self::DEFAULT_VALUES);

        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            // Six, because day two sets them as a block of short lines and a list of forty
            // is not a block — it is the rest of the letter.
            if ($line !== '' && count($out) < 6) $out[] = $line;
        }

        return $out === [] ? implode("\n", self::DEFAULT_VALUES) : implode("\n", $out);
    }

    /** @var list<string> */
    public const DEFAULT_VALUES = [
        'Integrity over advantage.',
        'Responsibility alongside rights.',
        'Community above competition.',
        'Character before success.',
        'The example we set, not the one we describe.',
    ];

    /** @param array<string,string> $set */
    private static function setting(array $set, string $key, string $fallback): string
    {
        $v = trim((string) ($set[$key] ?? ''));

        return $v !== '' ? $v : $fallback;
    }

    /**
     * The arc, as shipped.
     *
     * Written without a greeting on purpose. The template opens every one of these with
     * "Dear {their name}," from the invite row — this platform KNOWS who it is writing to,
     * the invitation they already received used their name, and a countdown letter that
     * falls back to "Dear Esteemed Nominee" after that reads as a mail-merge that lost the
     * merge. The greeting is the template's job; the argument is this file's.
     *
     * @return array<int,array{subject:string,body:string}>
     */
    private static function defaults(): array
    {
        return [InviteAudience::NOMINEE => [
            // ── DAY 5 · WHY ──────────────────────────────────────────────────
            5 => [
                'subject' => '{name}, {countdown}: begin with your WHY',
                'body' => <<<'TXT'
We are officially {days} days away from {event}, and as we begin the countdown, we want to remind you that your nomination represents more than recognition.

It represents trust.

You have been identified as someone whose influence, work, character, or contribution can help shape the community we desire.

This year's conversation goes beyond celebration. It is about {theme}.

As a parent, educator, professional, entrepreneur, community leader, or citizen, what does accountability and responsibility mean in your space?

Over the next few days, begin preparing your thoughts.

We would love you to be ready to share:

"What must we do differently in our homes, schools, and communities to {outcome}?"

You may be asked this on the red carpet, during interviews, or in conversations at the event.

Don't prepare a speech simply to impress.

Prepare a conviction worth sharing.

Your voice matters.

Your experience matters.

Your example matters.

And together, our voices can help shape the community we want our children and future generations to inherit.

{days} days to go. Begin with your WHY.
TXT,
            ],

            // ── DAY 4 · VALUES ───────────────────────────────────────────────
            4 => [
                'subject' => '{name}, {countdown}: find your message',
                'body' => <<<'TXT'
{days} days to {event}.

Today, we invite you to look beyond the event and look into the future.

Every parent, teacher, educator, community leader, and responsible adult is participating in the formation of the next generation — whether consciously or unconsciously.

The question is:

What kind of person are we raising?

A child who knows how to succeed at all costs? Or one who understands that character must never be sacrificed for success?

A child who knows how to demand rights? Or one who also understands responsibility?

A child who knows how to compete? Or one who understands community and communal responsibility?

As you prepare for {date}, think about your own space.

What is one value you believe we must deliberately restore?

What is one behaviour adults must stop normalising?

What is one practical thing parents, teachers, and community members can begin doing differently?

Prepare your answer.

Because when you step onto that red carpet, we don't just want to know who you are.

We want to know what you stand for.

{days} days to go. Find your message.
TXT,
            ],

            // ── DAY 3 · RESPONSIBILITY ───────────────────────────────────────
            3 => [
                'subject' => '{name}, {countdown}: own your message',
                'body' => <<<'TXT'
{days} days to {event}.

Today, we want you to think about something simple:

What is your responsibility within your own space?

You don't have to change an entire community at once. You can begin with your home. Your classroom. Your business. Your organisation. Your street. Your profession. Your community. Your influence.

Meaningful change is rarely created by one institution alone. It is built collectively.

So, before {date}, prepare to share one practical idea from your own experience:

"How can we become more accountable and responsible in creating the future we want?"

Keep it simple.

Keep it authentic.

Speak from experience.

On the red carpet, you may have only a few moments — but a few sincere words can become a powerful message when they come from conviction.

So don't merely prepare to attend {event}.

Prepare to represent an idea.

Prepare to inspire someone.

Prepare to contribute to the conversation.

{days} days to go. Own your message.
TXT,
            ],

            // ── DAY 2 · MESSAGE ──────────────────────────────────────────────
            2 => [
                'subject' => '{name}, {countdown}: prepare to speak',
                'body' => <<<'TXT'
Only {days} days to go.

By now, we hope you have begun thinking deeply about the message you want to carry into {event}.

We want to challenge you today: don't come empty-handed.

Come with a story.

Come with an idea.

Come with a conviction.

Come with a solution.

Come with a word that can challenge a parent, encourage a teacher, awaken a community leader, or inspire a young person.

The red carpet will celebrate you, but your words can celebrate something bigger than yourself.

They can celebrate:

{values}

Think about this:

"If every adult in our community became more accountable for the example they set, what kind of generation would we raise?"

Prepare your answer. You may have the opportunity to share it.

And when you do, speak boldly — not because you have all the answers, but because you are willing to take responsibility for being part of the solution.

{days} days to go. Prepare to speak.
TXT,
            ],

            // ── DAY 1 · ACTION ───────────────────────────────────────────────
            1 => [
                'subject' => '{name}, tomorrow is {event}',
                'body' => <<<'TXT'
Tomorrow is {event}.

The day we have been preparing for is finally here.

But before you step onto that red carpet, we want you to remember one thing: you are not coming merely to receive recognition. You are coming to contribute to a movement.

Come ready.

Come thoughtful.

Come proud of your journey.

Come prepared to tell the story of your work and influence.

And most importantly, come with a message for the generation coming behind us.

If you are asked, "What does {theme} mean to you?" — what will you say?

If you are asked, "What role should parents, educators, leaders, or community members play?" — what will you say?

If you are asked, "What must our community do differently?" — what will you say?

Your response does not need to be perfect. It needs to be true.

Speak from your experience.

Speak from your convictions.

Speak from your responsibility.

And let your voice join hundreds of others saying:

{action}

Tomorrow, the red carpet becomes more than a pathway. It becomes a platform for ideas, values, stories, and hope.

We cannot wait to welcome you to {event}.

Tomorrow, let's celebrate the people shaping today — and commit ourselves to the generation shaping tomorrow.
TXT,
            ],
        ],

        // ══ THE PANEL ════════════════════════════════════════════════════════
        //
        // The same arc, and deliberately the same beats — an organiser reading both sets
        // should recognise one voice, and a judge and a nominee standing on the same red
        // carpet should have been asked the same question.
        //
        // What changes is WHAT WAS TRUSTED. A nominee is honoured for work they did; a
        // judge for a decision they made about somebody else's. So "your nomination
        // represents trust" becomes "your seat on that panel represented trust", the
        // day-four values letter can point at a scoresheet they have just filled in, and
        // day one asks them to stand behind a result rather than receive one. Every
        // sentence that would have been a small lie to a judge is rewritten; the rest is
        // held identical on purpose.
        InviteAudience::JUDGE => [
            // ── DAY 5 · WHY ──────────────────────────────────────────────────
            5 => [
                'subject' => '{name}, {countdown}: begin with your WHY',
                'body' => <<<'TXT'
We are officially {days} days away from {event}, and as we begin the countdown, we want to remind you that your seat on that panel represented more than a task.

It represented trust.

You were asked to weigh other people's work — their years, their evidence, their reputations — and to do it fairly when nobody was watching.

This year's conversation goes beyond celebration. It is about {theme}.

As a judge, a parent, an educator, a professional or a citizen, what does accountability and responsibility mean in the room where decisions are made?

Over the next few days, begin preparing your thoughts.

We would love you to be ready to share:

"What must we do differently in our homes, schools, and communities to {outcome}?"

You may be asked this on the red carpet, during interviews, or in conversations at the event.

Don't prepare a speech simply to impress.

Prepare a conviction worth sharing.

Your judgement matters.

Your experience matters.

Your example matters.

And together, our voices can help shape the community we want our children and future generations to inherit.

{days} days to go. Begin with your WHY.
TXT,
            ],

            // ── DAY 4 · VALUES ───────────────────────────────────────────────
            4 => [
                'subject' => '{name}, {countdown}: find your message',
                'body' => <<<'TXT'
{days} days to {event}.

Today, we invite you to look beyond the event and look into the future.

Every judge, parent, teacher, educator, community leader, and responsible adult is participating in the formation of the next generation — whether consciously or unconsciously.

The question is:

What kind of person are we raising?

A child who knows how to succeed at all costs? Or one who understands that character must never be sacrificed for success?

A child who knows how to demand rights? Or one who also understands responsibility?

A child who knows how to compete? Or one who understands community and communal responsibility?

You have just spent weeks answering a version of that question with a scoresheet in front of you.

As you prepare for {date}, think about your own space.

What is one value you believe we must deliberately restore?

What is one behaviour adults must stop normalising?

What is one practical thing parents, teachers, and community members can begin doing differently?

Prepare your answer.

Because when you step onto that red carpet, we don't just want to know who you are.

We want to know what you stand for.

{days} days to go. Find your message.
TXT,
            ],

            // ── DAY 3 · RESPONSIBILITY ───────────────────────────────────────
            3 => [
                'subject' => '{name}, {countdown}: own your message',
                'body' => <<<'TXT'
{days} days to {event}.

Today, we want you to think about something simple:

What is your responsibility within your own space?

You don't have to change an entire community at once. You can begin with your home. Your classroom. Your practice. Your organisation. Your street. Your profession. Your community. Your influence.

Meaningful change is rarely created by one institution alone. It is built collectively.

So, before {date}, prepare to share one practical idea from your own experience:

"How can we become more accountable and responsible in creating the future we want?"

Keep it simple.

Keep it authentic.

Speak from experience.

On the red carpet, you may have only a few moments — but a few sincere words can become a powerful message when they come from conviction, and yours carries the weight of a panel that had to be sure.

So don't merely prepare to attend {event}.

Prepare to represent an idea.

Prepare to inspire someone.

Prepare to contribute to the conversation.

{days} days to go. Own your message.
TXT,
            ],

            // ── DAY 2 · MESSAGE ──────────────────────────────────────────────
            2 => [
                'subject' => '{name}, {countdown}: prepare to speak',
                'body' => <<<'TXT'
Only {days} days to go.

By now, we hope you have begun thinking deeply about the message you want to carry into {event}.

We want to challenge you today: don't come empty-handed.

Come with a story.

Come with an idea.

Come with a conviction.

Come with a solution.

Come with a word that can challenge a parent, encourage a teacher, awaken a community leader, or inspire a young person.

The red carpet will celebrate the work you helped decide, but your words can celebrate something bigger than any one result.

They can celebrate:

{values}

Think about this:

"If every adult in our community became more accountable for the example they set, what kind of generation would we raise?"

Prepare your answer. You may have the opportunity to share it.

And when you do, speak boldly — not because you have all the answers, but because you were willing to take responsibility for a decision that mattered to somebody.

{days} days to go. Prepare to speak.
TXT,
            ],

            // ── DAY 1 · ACTION ───────────────────────────────────────────────
            1 => [
                'subject' => '{name}, tomorrow is {event}',
                'body' => <<<'TXT'
Tomorrow is {event}.

The day we have been preparing for is finally here.

But before you step onto that red carpet, we want you to remember one thing: you are not coming merely to watch a result announced. You are coming to stand behind it.

Come ready.

Come thoughtful.

Come proud of the care you took.

Come prepared to speak for the standard this panel held.

And most importantly, come with a message for the generation coming behind us.

If you are asked, "What does {theme} mean to you?" — what will you say?

If you are asked, "What role should parents, educators, leaders, or community members play?" — what will you say?

If you are asked, "What must our community do differently?" — what will you say?

Your response does not need to be perfect. It needs to be true.

Speak from your experience.

Speak from your convictions.

Speak from your responsibility.

And let your voice join hundreds of others saying:

{action}

Tomorrow, the names you weighed will be read aloud, and the legacies behind them honoured in front of the people they belong to.

We cannot wait to welcome you to {event}.

Tomorrow, let's celebrate the people shaping today — and commit ourselves to the generation shaping tomorrow.
TXT,
            ],
        ]];
    }

    /** @return array<string,string> */
    private static function settings(): array
    {
        try {
            return DB::table('gates_settings')->pluck('value', 'key_name')->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
