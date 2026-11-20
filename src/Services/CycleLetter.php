<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The nominee voting letter's words, per edition (owner, 28 Oct 2026: "more enticing, promote
 * communal spirit, editable and viewable per cycle").
 *
 * ── WHAT IS EDITABLE, AND WHAT IS NOT ───────────────────────────────────────
 *
 * The headline and the paragraphs are the operator's, with {fields} filled in per nominee.
 * Everything that is a FACT stays the code's: the reference, the dates, the voting page and
 * its QR, the steps (read from the voting rules), the signature block. And one paragraph is
 * never editable: the notice that Africa GATES never asks a nominee to pay (plan D34). A
 * letter like this is exactly what a "pay to be honoured" scheme forges, so the genuine
 * one must always say what the forgery asks for; letting an edit delete that sentence would
 * let the platform's own letter stop protecting the person it is addressed to.
 *
 * ── VERSIONS, NOT OVERWRITES ─────────────────────────────────────────────────
 *
 * The editor autosaves into one draft row; publishing adds a new published row, which is
 * what every letter sent from then on reads. So the history is the table, a restore is a
 * copy into the draft, and a letter already sent can always be matched to the words it
 * carried. With nothing published, the shipped default below is the letter.
 */
final class CycleLetter
{
    public const KIND = 'voting';
    public const MAX_HEADLINE = 120;
    public const MAX_BODY = 2400;
    public const MAX_PARAGRAPHS = 8;

    /**
     * The fields an operator may place in the words, with what each becomes. The sample is
     * what the editor shows in its preview chips.
     *
     * @var array<string, array{0:string, 1:string}> field => [label, sample]
     */
    public const FIELDS = [
        'first_name' => ['First name', 'Ngozi'],
        'name'       => ['Full name', 'Ngozi Adaeze Eze'],
        'category'   => ['Category', 'Community Health'],
        'award'      => ['Award and edition', 'Teaching Award 2026'],
        'opens'      => ['Voting opens', 'Monday 2 November 2026'],
        'closes'     => ['Voting closes', 'Friday 20 November 2026 at 18:00 WAT'],
        'vote_url'   => ['Voting page', 'africagates.org/vote/teaching/42-ngozi'],
        'rules_url'  => ['Rules page', 'africagates.org/integrity'],
    ];

    /**
     * The default, written to be read aloud in a staff room: warm, communal, plain, and true.
     * "Every verified supporter counts" is the rule (the people term is 70% of the community
     * half), so it is a promise this platform keeps.
     */
    public const DEFAULT_HEADLINE = 'Your community has put you forward';

    public const DEFAULT_BODY = "Congratulations. Your nomination for {category} has been verified, and you are on the public ballot. Someone saw your work and wanted everyone to see it too.\n\n"
        . "From {opens} until {closes}, the people who know your work can stand with you. Share your voting page with family, colleagues and the people you serve: every verified supporter counts.\n\n"
        . "An independent judging panel and that public support decide the result, under rules at {rules_url}.\n\n"
        . "Whatever happens next, this recognition belongs to you and everyone who stood with you.";

    /** The paragraph no edition may remove (plan D34). */
    public const LOCKED = 'Africa GATES never asks a nominee to pay to be nominated, to stay on the ballot or to receive an award. If anyone asks, it is not us. Please report it.';

    public static function ready(): bool
    {
        return SchemaHas::table('gates_cycle_letters');
    }

    /**
     * The words in use for an edition: the newest published version, or the default.
     *
     * @return array{headline:string, body:string, custom:bool, published_at:?string, version:?int}
     */
    public static function current(int $cycleId): array
    {
        $row = self::latest($cycleId, 'published');
        return $row
            ? ['headline' => (string) $row->headline, 'body' => (string) $row->body, 'custom' => true,
               'published_at' => (string) $row->created_at, 'version' => (int) $row->id]
            : ['headline' => self::DEFAULT_HEADLINE, 'body' => self::DEFAULT_BODY, 'custom' => false,
               'published_at' => null, 'version' => null];
    }

    /**
     * What the editor opens: the draft if one is newer than what is published, else the
     * letter in use.
     *
     * @return array{headline:string, body:string, draft:bool, saved_at:?string}
     */
    public static function editing(int $cycleId): array
    {
        $d = self::latest($cycleId, 'draft');
        $p = self::current($cycleId);
        if ($d && ($p['version'] === null || (int) $d->id > (int) $p['version'] || (string) ($d->updated_at ?? '') > (string) $p['published_at'])) {
            return ['headline' => (string) $d->headline, 'body' => (string) $d->body, 'draft' => true,
                    'saved_at' => (string) ($d->updated_at ?? $d->created_at)];
        }
        return ['headline' => $p['headline'], 'body' => $p['body'], 'draft' => false, 'saved_at' => null];
    }

    /**
     * Check words before they are kept: limits, and every {field} one we know.
     *
     * @return array{headline:string, body:string, errors:list<string>}
     */
    public static function clean(string $headline, string $body): array
    {
        $headline = trim((string) preg_replace('/\s+/u', ' ', $headline));
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $paras = self::paragraphs($body);
        $body = implode("\n\n", $paras);
        $e = [];
        if ($headline === '') $e[] = 'The letter needs a headline.';
        if (mb_strlen($headline) > self::MAX_HEADLINE) $e[] = sprintf('Keep the headline under %d characters.', self::MAX_HEADLINE);
        if ($paras === []) $e[] = 'The letter needs at least one paragraph.';
        if (mb_strlen($body) > self::MAX_BODY) $e[] = sprintf('Keep the letter under %d characters, so it fits on one page.', self::MAX_BODY);
        if (count($paras) > self::MAX_PARAGRAPHS) $e[] = sprintf('Use at most %d paragraphs.', self::MAX_PARAGRAPHS);
        foreach (self::unknownFields($headline . ' ' . $body) as $f) $e[] = sprintf('{%s} is not a field the letter knows.', $f);
        return ['headline' => $headline, 'body' => $body, 'errors' => $e];
    }

    /** @return list<string> */
    public static function unknownFields(string $text): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $text, $m);
        return array_values(array_unique(array_filter($m[1], static fn ($f) => !isset(self::FIELDS[$f]))));
    }

    /** @return list<string> */
    public static function paragraphs(string $body): array
    {
        $out = [];
        foreach (preg_split('/\n\s*\n/', str_replace(["\r\n", "\r"], "\n", $body)) ?: [] as $p) {
            $p = trim((string) preg_replace('/[ \t]*\n[ \t]*/', ' ', $p));
            if ($p !== '') $out[] = $p;
        }
        return $out;
    }

    /**
     * The paragraphs as one nominee receives them, the locked notice last but one.
     *
     * @param array<string,mixed> $v NomineeVotingNotice::view()
     * @return list<string>
     */
    public static function render(string $body, array $v): array
    {
        $paras = array_map(static fn (string $p): string => self::fill($p, $v), self::paragraphs($body));
        // The notice before the closing paragraph, so the letter still ends on its warmth.
        $last = count($paras) > 1 ? array_pop($paras) : null;
        $paras[] = self::LOCKED;
        if ($last !== null) $paras[] = $last;
        return $paras;
    }

    /** @param array<string,mixed> $v */
    public static function fill(string $text, array $v): string
    {
        $first = DoorWelcome::firstName((string) ($v['name'] ?? '')) ?: (string) ($v['name'] ?? '');
        $map = ['{first_name}' => $first];
        foreach (array_keys(self::FIELDS) as $f) {
            if ($f === 'first_name') continue;
            $val = (string) ($v[$f] ?? '');
            if ($f === 'vote_url') $val = (string) preg_replace('~^https?://~', '', $val);
            $map['{' . $f . '}'] = $val;
        }
        return strtr($text, $map);
    }

    /** The specimen the editor previews with, built from the fields' samples. */
    public static function sample(): array
    {
        $v = [];
        foreach (self::FIELDS as $f => [, $s]) $v[$f] = $s;
        return $v;
    }

    /** Autosave: the one draft row for the edition, created or updated. */
    public static function saveDraft(int $cycleId, string $headline, string $body, int $adminId): array
    {
        $c = self::clean($headline, $body);
        if (!self::ready()) return ['ok' => false, 'errors' => ['The database needs its latest update before this works.']];
        // A draft may be unfinished: it is kept even with errors, so nothing typed is lost.
        $now = date('Y-m-d H:i:s');
        $d = self::latest($cycleId, 'draft');
        $row = ['headline' => mb_substr($c['headline'], 0, 160), 'body' => mb_substr($c['body'] !== '' ? $c['body'] : $body, 0, 8000),
                'saved_by' => $adminId ?: null, 'updated_at' => $now];
        if ($d) {
            DB::table('gates_cycle_letters')->where('id', $d->id)->update($row);
        } else {
            DB::table('gates_cycle_letters')->insert($row + ['cycle_id' => $cycleId, 'kind' => self::KIND, 'state' => 'draft', 'created_at' => $now]);
        }
        return ['ok' => true, 'errors' => $c['errors'], 'saved_at' => $now];
    }

    /** Publish: a new version every letter from now on reads. */
    public static function publish(int $cycleId, string $headline, string $body, int $adminId): array
    {
        $c = self::clean($headline, $body);
        if ($c['errors'] !== []) return ['ok' => false, 'errors' => $c['errors']];
        if (!self::ready()) return ['ok' => false, 'errors' => ['The database needs its latest update before this works.']];
        $now = date('Y-m-d H:i:s');
        $id = (int) DB::table('gates_cycle_letters')->insertGetId([
            'cycle_id' => $cycleId, 'kind' => self::KIND, 'state' => 'published', 'headline' => $c['headline'],
            'body' => $c['body'], 'saved_by' => $adminId ?: null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        // The draft has become the letter; keeping it would open the editor on a stale copy.
        DB::table('gates_cycle_letters')->where('cycle_id', $cycleId)->where('kind', self::KIND)->where('state', 'draft')->delete();
        return ['ok' => true, 'errors' => [], 'version' => $id];
    }

    /** Every published version, newest first, with who saved it. @return list<array<string,mixed>> */
    public static function history(int $cycleId, int $limit = 12): array
    {
        if (!self::ready()) return [];
        $rows = DB::table('gates_cycle_letters as l')->leftJoin('gates_admins as a', 'a.id', '=', 'l.saved_by')
            ->where('l.cycle_id', $cycleId)->where('l.kind', self::KIND)->where('l.state', 'published')
            ->orderByDesc('l.id')->limit($limit)->get(['l.id', 'l.headline', 'l.body', 'l.created_at', 'a.name as by'])->all();
        return array_map(static fn ($r) => ['id' => (int) $r->id, 'headline' => (string) $r->headline,
            'words' => str_word_count(strip_tags((string) $r->body)), 'at' => (string) $r->created_at,
            'by' => (string) ($r->by ?? ''), 'body' => (string) $r->body], $rows);
    }

    /** A published version back into the draft, to look at and publish again. */
    public static function restore(int $cycleId, int $versionId, int $adminId): bool
    {
        if (!self::ready()) return false;
        $v = DB::table('gates_cycle_letters')->where('id', $versionId)->where('cycle_id', $cycleId)->where('state', 'published')->first();
        if (!$v) return false;
        self::saveDraft($cycleId, (string) $v->headline, (string) $v->body, $adminId);
        return true;
    }

    /** Back to the shipped words, as a draft (publishing it is still a deliberate act). */
    public static function resetDraft(int $cycleId, int $adminId): void
    {
        self::saveDraft($cycleId, self::DEFAULT_HEADLINE, self::DEFAULT_BODY, $adminId);
    }

    private static function latest(int $cycleId, string $state): ?object
    {
        if ($cycleId <= 0 || !self::ready()) return null;
        try {
            return DB::table('gates_cycle_letters')->where('cycle_id', $cycleId)->where('kind', self::KIND)
                ->where('state', $state)->orderByDesc('id')->first() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
