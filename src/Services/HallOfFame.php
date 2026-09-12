<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Accent;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Everybody this platform has ever crowned, as people rather than as rows.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A VIEW OVER `PublicResults::index()` AND NOT A QUERY OF ITS OWN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The obvious implementation is `WHERE gates_nominees.status = 'winner'`, and it would be
 * wrong in a way nothing on the page could show. A published result is the one that was
 * ANNOUNCED, not the one today's rules give: `PublicResults::category()` lays a cycle's
 * SEALED standing back over the live computation, and a nominee has already moved 693 →
 * 885 across a week of scoring changes with no record edited. A hall reading the status
 * column directly would print an index nobody was ever given, beside a name from the same
 * database, on a page whose whole claim is that these are the awards that were made.
 *
 * It would also have to re-learn the four things that path already knows: the sandbox is
 * excluded by reaching only for live programmes, a withheld award has no winner to name,
 * an unsealed release must say its figures are a recomputation, and a cycle's scale is the
 * whole edition's field.
 *
 * So this adds no query at all. It regroups what `/results` already drew — from
 * edition → awards into person → awards — which is the only difference between a ledger
 * and a hall, and the difference worth having: one is organised by WHEN and the other by
 * WHO.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * ONE SCORER, AND THE REASON THE CAP IS NOT REMOVED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `index()` shares a single {@see NomineeScoringService} across the whole page because the
 * community denominator is the edition's own field, so drawing one award reads its whole
 * cycle. That is memoised per cycle ON THE SCORER; the cap is what stops a hall that grows
 * for ten years from reading ten years of editions on every view.
 *
 * A cap on a HALL OF FAME is a real cost and is stated rather than hidden: the page says
 * how far back it reaches, and the edition archive at `/results` is where the rest lives.
 * Silently truncating a list of people whose whole point is that they are remembered is
 * the one failure this page must not have.
 */
final class HallOfFame
{
    /**
     * How many editions deep the hall reaches by default.
     *
     * Generous, because the alternative — a hall that forgets — defeats the page. Bounded,
     * because each edition costs a full scoring pass and this is a public page anybody can
     * open. `PublicResults::index()` clamps it again at sixty.
     */
    public const EDITIONS = 40;

    /** How many of this person's wins took a whole edition. */
    private static function overallWins(array $person): int
    {
        $n = 0;
        foreach ((array) ($person['wins'] ?? []) as $w) {
            if (!empty($w['overall'])) $n++;
        }

        return $n;
    }

    /** A category's title, whether the row arrived as an object or as an array. */
    private static function title(mixed $cat): string
    {
        if (is_object($cat)) return trim((string) ($cat->title ?? ''));
        if (is_array($cat))  return trim((string) ($cat['title'] ?? ''));

        return '';
    }

    /**
     * The hall.
     *
     * @return array{
     *   people:list<array<string,mixed>>, editions:int, programmes:int,
     *   awards:int, repeat:int, reach:array{from:int,to:int}, held:int, truncated:bool
     * }
     */
    public static function build(int $maxEditions = self::EDITIONS): array
    {
        $empty = ['people' => [], 'editions' => 0, 'programmes' => 0, 'awards' => 0,
                  'repeat' => 0, 'reach' => ['from' => 0, 'to' => 0], 'held' => 0,
                  'truncated' => false];

        $index = PublicResults::index($maxEditions);
        $eds   = (array) ($index['editions'] ?? []);
        if ($eds === []) return $empty;

        // Keyed by nominee, because the single most interesting thing a hall of fame knows
        // is who is in it TWICE, and that fact does not exist anywhere in a ledger
        // organised by edition. A merged-away nominee simply never appears: they hold no
        // winner row on any sealed standing.
        $people     = [];
        $rows       = [];      // [nominee id, winner row, the win] in edition order
        $ids        = [];
        $programmes = [];
        $awards     = 0;
        $years      = [];

        foreach ($eds as $ed) {
            $year = (int) ($ed['year'] ?? 0);
            if ($year > 0) $years[] = $year;
            $programmes[(string) ($ed['programme'] ?? '')] = true;

            // ── WHO TOPPED THE WHOLE EDITION ─────────────────────────────────
            //
            // A hall that lists a category winner and the person who led the entire
            // edition at the same weight is a hall that has thrown away the only ranking
            // this platform actually makes. `PublicResults::overallFor()` computes it from
            // the SEALED figures — see its docblock for why that matters and for the two
            // caveats (provisional while an award is withheld, order reconstructed because
            // no overall rank is sealed) which travel on the win rather than being dropped.
            $ov    = is_array($ed['overall'] ?? null) ? $ed['overall'] : null;
            $ovId  = (int) ($ov['winner']['nominee_id'] ?? 0);

            foreach ((array) ($ed['awards'] ?? []) as $a) {
                $w = $a['winner'] ?? null;
                // A withheld award has no winner to name, and `index()` has already kept
                // it out of `awards`. Belt and braces: a null here would render a card
                // with an empty face and no name, which reads as a person nobody knows.
                if (!is_array($w) || trim((string) ($w['name'] ?? '')) === '') continue;

                $awards++;
                $id = (int) ($w['nominee_id'] ?? 0);

                // `category` is an OBJECT here and an array almost everywhere else in this
                // file, because ResultRelease carries the row Eloquent handed it rather
                // than casting. Read through a helper instead of assuming either shape: a
                // subscript on a stdClass is a fatal, not a null, so this is the one field
                // on the row that can take the page down rather than draw it wrong.
                $ids[$id]  = true;
                $rows[]    = [$id, $w, [
                    // Did this win take the whole edition, or one category of it? Carried
                    // per WIN: somebody can top one edition outright and take a single
                    // category in another, and flattening that onto the person loses the
                    // distinction on the card that needs it.
                    'overall'         => $ovId > 0 && $id === $ovId,
                    'overall_provisional'   => $ovId > 0 && $id === $ovId && (bool) ($ov['provisional'] ?? false),
                    'overall_reconstructed' => $ovId > 0 && $id === $ovId && (bool) ($ov['reconstructed'] ?? false),
                    'overall_margin'        => $ovId > 0 && $id === $ovId ? ($ov['margin'] ?? null) : null,
                    'overall_dead_heat'     => $ovId > 0 && $id === $ovId && (bool) ($ov['dead_heat'] ?? false),
                    'awards_in_edition'     => count((array) ($ed['awards'] ?? [])),
                    // The programme's identity colour, carried on the win rather than
                    // looked up by the template: one person can hold awards from two
                    // programmes, so the colour belongs to the AWARD and not to them.
                    'programme_id'    => (int) ($ed['programme_id'] ?? 0),
                    'programme_style' => Accent::programmeStyle((int) ($ed['programme_id'] ?? 0)),
                    'category'  => self::title($a['category'] ?? null),
                    'url'       => (string) ($a['url'] ?? ''),
                    'programme' => (string) ($ed['programme'] ?? ''),
                    'edition'   => (string) ($ed['edition'] ?? ''),
                    'edition_url' => (string) ($ed['url'] ?? ''),
                    'year'      => $year,
                    'cpi'       => (int) round((float) ($w['cpi'] ?? 0)),
                    // An unsealed release is a live recomputation and the page has to say
                    // so — carried per win, because one person can hold a sealed award
                    // from one year and an unsealed one from another.
                    'sealed'    => trim((string) ($a['sealed_at'] ?? '')) !== '',
                ]];
            }
        }

        // ── WHO IS THE SAME PERSON ACROSS TWO EDITIONS ───────────────────────
        //
        // A nominee row belongs to ONE category, so somebody entered in two editions is two
        // rows — and `gates_nominees.profile_id` is the only thing on this platform that
        // says they are one human being. Resolved in a single query for the whole wall
        // rather than per card: a hall of two hundred people looking up its own identities
        // one at a time is two hundred round trips on a public page.
        //
        // NEVER BY NAME. Two different people share a name, and a hall of fame that merged
        // them would attribute somebody's award to a stranger and print it under their
        // face. Where there is no profile the two rows stay two entries — the same person
        // listed twice is a thinner claim than the wrong person honoured once.
        $profiles = [];
        if ($ids !== []) {
            try {
                foreach (DB::table('gates_nominees')
                            ->whereIn('id', array_keys($ids))
                            ->whereNotNull('profile_id')
                            ->get(['id', 'profile_id']) as $n) {
                    $profiles[(int) $n->id] = (int) $n->profile_id;
                }
            } catch (\Throwable) {
                // A hall that cannot join is a hall of individual awards, which is still
                // true — just less informative. Never a blank page.
                $profiles = [];
            }
        }

        $people = [];
        foreach ($rows as [$id, $w, $win]) {
            $key = isset($profiles[$id]) ? 'p' . $profiles[$id] : 'n' . $id;

            if (!isset($people[$key])) {
                $people[$key] = [
                    'nominee_id' => $id,
                    'name'       => (string) $w['name'],
                    'photo'      => (string) ($w['photo'] ?? ''),
                    'wins'       => [],
                ];
            }

            // The first portrait found wins, and the rows arrive newest-edition first — so
            // this is the most recent one, which is the face a reader recognises and the
            // one an earlier edition may predate.
            if (($people[$key]['photo'] ?? '') === '' && ($w['photo'] ?? '') !== '') {
                $people[$key]['photo'] = (string) $w['photo'];
            }

            $people[$key]['wins'][] = $win;
        }

        // ── AN EDITION WIN OUTRANKS A CATEGORY WIN ──────────────────────────
        //
        // Topping a whole edition is the largest thing this platform decides, and the hall
        // used to sort by recency alone — so the person who led an entire edition sat in
        // the same row as somebody who won one category of it, with nothing saying which
        // was which. That is not a layout problem; it is the hall discarding the only
        // ranking the platform makes.
        //
        // Then most recent, then the bigger index, then the name — so the order is TOTAL
        // and a reload never reshuffles two people who tie. The name last rather than the
        // id, because an import that renumbers rows must not reorder the wall.
        $people = array_values($people);
        usort($people, static function (array $a, array $b): int {
            $ao = self::overallWins($a);
            $bo = self::overallWins($b);
            if ($ao !== $bo) return $bo <=> $ao;

            $ay = (int) ($a['wins'][0]['year'] ?? 0);
            $by = (int) ($b['wins'][0]['year'] ?? 0);
            if ($ay !== $by) return $by <=> $ay;

            $ac = (int) ($a['wins'][0]['cpi'] ?? 0);
            $bc = (int) ($b['wins'][0]['cpi'] ?? 0);
            if ($ac !== $bc) return $bc <=> $ac;

            return strcmp((string) $a['name'], (string) $b['name']);
        });

        $repeat  = 0;
        $overall = 0;
        foreach ($people as &$p) {
            $p['count'] = count($p['wins']);
            // An edition win is put FIRST among a person's own wins too, so a card drawing
            // `wins|first` shows the biggest thing they did rather than the most recent.
            usort($p['wins'], static fn (array $x, array $y): int
                => [(int) !empty($y['overall']), (int) $y['year'], (int) $y['cpi']]
                <=> [(int) !empty($x['overall']), (int) $x['year'], (int) $x['cpi']]);

            $p['overall_count'] = self::overallWins($p);
            if ($p['count'] > 1) $repeat++;
            if ($p['overall_count'] > 0) $overall++;
        }
        unset($p);

        return [
            'people'     => $people,
            'editions'   => count($eds),
            'programmes' => count(array_filter(array_keys($programmes), static fn ($s) => $s !== '')),
            'awards'     => $awards,
            'repeat'     => $repeat,
            // How many people in the hall topped an edition outright. Counted here so a
            // screen never has to walk the wall to say it.
            'overall'    => $overall,
            'reach'      => ['from' => $years === [] ? 0 : min($years),
                             'to'   => $years === [] ? 0 : max($years)],
            // Named on the page rather than swallowed: a withheld award is a real event and
            // a hall that silently omits it is a hall claiming a tidier history than the one
            // that happened.
            'held'       => (int) ($index['held'] ?? 0),
            // Whether the cap actually bit. A hall that quietly forgets is the one failure
            // this page must not have, so the page says so and points at the archive.
            'truncated'  => count($eds) >= $maxEditions,
        ];
    }
}
