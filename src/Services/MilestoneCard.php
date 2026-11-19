<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * A MILESTONE, AS AN IMAGE THE NOMINEE POSTS (owner, 16 Nov 2026: "more features for voters and
 * nominees"). When a nominee passes 100 supporters, makes the shortlist or takes the lead, their
 * own page offers the moment as a card to share — so the campaign keeps being seen without
 * anybody designing anything.
 *
 * It is the NOMINEE's voice ("Chiamaka Eze · Top 3 · Thank you for backing me"), drawn in BackedCard's layout so the
 * supporter's card and the nominee's look like one family. Only a milestone actually recorded
 * (NomineeMilestones) is drawn; any other URL is a 404, so nobody can mint "Leading" for a
 * nominee who is not.
 */
final class MilestoneCard
{
    /** @return array{photo:string, initials_of:string, kicker:string, lead:string, title:string, sub:string, foot:string}|null */
    public static function spec(int $nomineeId, string $kind, int $value): ?array
    {
        if (!in_array($kind, ['people', 'top3', 'lead', 'shortlist'], true) || !NomineeMilestones::exists($nomineeId, $kind, $value)) return null;
        $f = BackedCard::facts($nomineeId);
        if ($f === null) return null;
        // Short enough to stay at display size; the category is named on the line under it.
        $title = match ($kind) {
            'people'    => number_format($value) . ' supporters',
            'top3'      => 'Top 3',
            'lead'      => 'In the lead',
            default     => 'On the shortlist',
        };
        return ['photo' => $f['photo'], 'initials_of' => $f['name'],
                'kicker' => $f['place'] !== '' ? $f['place'] . ' is celebrating its own' : $f['award'],
                'lead' => $f['name'], 'title' => $title, 'sub' => $f['category'] . ' · ' . $f['award'],
                'foot' => 'Thank you for backing me'];
    }

    public static function png(int $nomineeId, string $kind, int $value): ?string
    {
        $s = self::spec($nomineeId, $kind, $value);
        return $s === null ? null : (new BackedCard())->draw($s);
    }
}
