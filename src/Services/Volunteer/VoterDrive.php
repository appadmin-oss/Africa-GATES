<?php
declare(strict_types=1);

namespace AfricaGates\Services\Volunteer;

use AfricaGates\Services\Grants;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The voter drive: a volunteer brings people to vote on an award (consoles v7 §6.2, G6).
 *
 * The same idea as a voter's own link (VoteRecruit), with the one difference the role guide
 * turns on: "You bring voters, not votes." A voter's link is for the nominee they chose; a
 * volunteer's link opens the award's ballot and names nobody, so it can never be a campaign
 * for one nominee wearing a volunteer's bib.
 *
 * What counts is a PERSON whose vote counted after arriving through the link: the ledger's own
 * voter hash, once per link, credited only after the vote is recorded and never able to touch
 * it. Paid votes are not credited — a drive brings people, and money is not a person. Nothing
 * here moves a tally, a score or a point; it is a count for the volunteer's own screen, and it
 * never says who came or whom they voted for.
 */
final class VoterDrive
{
    public const CAPABILITY = 'vol.voter_drive';
    public const SESSION_KEY = 'vol_drive';

    /** A code a person can read aloud: no 0/O, 1/I/L — VoteRecruit's alphabet. */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public static function ready(): bool
    {
        return SchemaHas::table('gates_vol_drive_links') && SchemaHas::table('gates_vol_drive_credits');
    }

    public static function allowed(object $v, int $cycleId): bool
    {
        return Grants::allows('member', (int) $v->user_id, self::CAPABILITY, 'cycle', $cycleId);
    }

    /** The volunteer's link for this edition: made once, the same one after. Null without the grant. */
    public static function link(object $v, int $cycleId): ?array
    {
        if (!self::ready() || !self::allowed($v, $cycleId)) return null;
        $row = DB::table('gates_vol_drive_links')->where('volunteer_id', $v->id)->where('cycle_id', $cycleId)->first();
        for ($i = 0; $i < 5 && !$row; $i++) {
            DB::table('gates_vol_drive_links')->insertOrIgnore(['volunteer_id' => (int) $v->id, 'cycle_id' => $cycleId,
                'code' => self::mint(), 'created_at' => date('Y-m-d H:i:s')]);
            $row = DB::table('gates_vol_drive_links')->where('volunteer_id', $v->id)->where('cycle_id', $cycleId)->first();
        }
        if (!$row) return null;
        $slug = (string) (DB::table('gates_award_cycles as c')->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
            ->where('c.id', $cycleId)->value('p.slug') ?? '');
        return ['code' => (string) $row->code, 'path' => ($slug !== '' ? '/vote/' . $slug : '/vote') . '?drive=' . $row->code,
                'people' => (int) DB::table('gates_vol_drive_credits')->where('link_id', $row->id)->count()];
    }

    /** `?drive=` on a ballot page: kept for this browser until a vote spends it. */
    public static function capture(string $raw): void
    {
        $code = self::normalise($raw);
        if ($code === '' || !isset($_SESSION) || !is_array($_SESSION)) return;
        $_SESSION[self::SESSION_KEY] = $code;
    }

    public static function fromSession(): string
    {
        return isset($_SESSION[self::SESSION_KEY]) && is_string($_SESSION[self::SESSION_KEY]) ? self::normalise($_SESSION[self::SESSION_KEY]) : '';
    }

    public static function normalise(string $raw): string
    {
        $c = strtolower(trim($raw));
        return preg_match('/^[a-z0-9]{6,16}$/', $c) === 1 ? $c : '';
    }

    /**
     * A counted vote for a nominee arrived through a drive link. True when it brought somebody
     * new. Refused, quietly, for an unknown code and for a vote on ANOTHER award — the link is
     * for its own edition — and the volunteer's own vote brings nobody.
     */
    public static function credit(string $code, int $nomineeId, string $voterHash): bool
    {
        $code = self::normalise($code);
        if ($code === '' || !self::ready() || !preg_match('/^[a-f0-9]{64}$/', $voterHash)) return false;
        try {
            $l = DB::table('gates_vol_drive_links')->where('code', $code)->first();
            if (!$l) return false;
            $cycle = (int) (DB::table('gates_nominees as n')->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->where('n.id', $nomineeId)->value('c.cycle_id') ?? 0);
            if ($cycle !== (int) $l->cycle_id) return false;
            $email = (string) (DB::table('gates_volunteers as v')->join('gates_users as u', 'u.id', '=', 'v.user_id')->where('v.id', $l->volunteer_id)->value('u.email') ?? '');
            if ($email !== '' && hash_equals(\AfricaGates\Services\VoteService::voterHash($email), $voterHash)) return false;
            return DB::table('gates_vol_drive_credits')->insertOrIgnore([
                'link_id' => (int) $l->id, 'voter_hash' => $voterHash, 'created_at' => date('Y-m-d H:i:s'),
            ]) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function mint(): string
    {
        $s = '';
        for ($i = 0; $i < 8; $i++) $s .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        return $s;
    }
}
