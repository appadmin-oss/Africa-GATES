<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Read-only activity aggregation for the member dashboard: votes cast,
 * nominations made, share links minted, community participation, profile
 * completeness and the onboarding checklist.
 *
 * Identity convention: votes and community content store sha256 of the
 * lowercased trimmed email (never the raw address), so a member's history is
 * resolved by hashing THEIR OWN email from their session. Everything here is
 * SELECT-only — the audited vote path is not touched.
 */
class MemberActivityService
{
    private static function emailHash(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    /**
     * Votes this member has cast (standard votes only — bonus/redeemed votes
     * use synthetic hashes and show in the points ledger instead).
     *
     * @return list<array{nominee:string,category:string,voted_at:string,nominee_id:int}>
     */
    public static function votesFor(string $email, int $limit = 20): array
    {
        try {
            $rows = DB::table('gates_votes as v')
                ->leftJoin('gates_nominees as n', 'n.id', '=', 'v.nominee_id')
                ->leftJoin('gates_award_categories as c', 'c.id', '=', 'v.category_id')
                ->where('v.voter_email_hash', self::emailHash($email))
                ->where('v.vote_type', 'standard')
                ->orderByDesc('v.voted_at')
                ->limit(max(1, $limit))
                ->get(['v.nominee_id', 'v.voted_at', 'n.name as nominee', 'c.title as category']);
        } catch (\Throwable) {
            return [];
        }
        return array_map(static fn($r) => [
            'nominee_id' => (int) $r->nominee_id,
            'nominee'    => (string) ($r->nominee ?? 'Nominee #' . $r->nominee_id),
            'category'   => (string) ($r->category ?? ''),
            'voted_at'   => (string) $r->voted_at,
        ], $rows->all());
    }

    /**
     * Nominations this member has submitted, newest first.
     *
     * @return list<array{nominee:string,status:string,reference:string,created_at:string}>
     */
    public static function nominationsFor(string $email, int $limit = 20): array
    {
        try {
            $rows = DB::table('gates_nominations')
                ->whereRaw('LOWER(nominator_email) = ?', [strtolower(trim($email))])
                ->orderByDesc('created_at')
                ->limit(max(1, $limit))
                ->get(['id', 'nominee_name', 'status', 'reference', 'created_at']);
        } catch (\Throwable) {
            return [];
        }
        return array_map(static fn($r) => [
            'nominee'    => (string) $r->nominee_name,
            'status'     => (string) $r->status,
            'reference'  => (string) ($r->reference ?? ('NOM-' . $r->id)),
            'created_at' => (string) $r->created_at,
        ], $rows->all());
    }

    /**
     * Shop orders this member has placed.
     *
     * ── WHY THIS WAS MISSING, AND WHY IT MATTERS NOW ─────────────────────────
     *
     * The dashboard showed votes, nominations, points and community activity — an accurate
     * picture of everything a member had CONTRIBUTED, and nothing at all about what they had
     * BOUGHT. That was defensible when the shop took a name and an address and emailed a
     * receipt. It is not defensible now that an order has a delivery state: the only route to
     * "has it shipped" was the reference link in an email, so a member who lost that email had
     * no way in and a support inbox answered it by hand.
     *
     * ── MATCHED BY EMAIL, WHICH IS THE HONEST JOIN ───────────────────────────
     *
     * `gates_orders` has no `user_id`, because checkout does not require an account and adding
     * one would put a registration between somebody and a t-shirt. The email on the order is
     * the only link and it is the right one: it is the address the receipt went to, so it is
     * the address the buyer proved they could read.
     *
     * Compared case-insensitively — an order typed `Ada@Example.com` at a checkout and an
     * account registered `ada@example.com` are the same person, and an exact match would
     * silently show them nothing.
     *
     * @return list<array<string,mixed>>
     */
    public static function ordersFor(string $email, int $limit = 20): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') return [];

        try {
            $rows = DB::table('gates_orders')
                ->whereRaw('LOWER(email) = ?', [$email])
                // `pending` is shown: it is the order a member is most likely to be asking
                // about, and hiding it leaves somebody who was charged looking at an empty
                // list. `failed` is hidden — nothing was taken and nothing is coming.
                ->whereIn('status', ['paid', 'pending'])
                ->orderByDesc('id')->limit($limit)->get();
        } catch (\Throwable) {
            return [];
        }

        return $rows->map(static function ($o): array {
            $lines = json_decode((string) ($o->items_json ?? '[]'), true) ?: [];
            return [
                'reference' => (string) $o->reference,
                'status'    => (string) $o->status,
                'total'     => (int) ($o->subtotal_naira ?? 0),
                'items'     => (int) array_sum(array_column($lines, 'qty')),
                // Named, not just referenced. "Order AFG-SHP-9f2c…" tells a member nothing
                // about which of their three orders they are looking at.
                'what'      => (string) ($lines[0]['name'] ?? 'Your order')
                             . (count($lines) > 1 ? ' + ' . (count($lines) - 1) . ' more' : ''),
                'fulfilment'=> ((string) ($o->fulfilment ?? '')) ?: 'unfulfilled',
                'tracking'  => (string) ($o->tracking_note ?? ''),
                'placed'    => (string) ($o->created_at ?? ''),
                'url'       => '/shop/order/' . rawurlencode((string) $o->reference),
            ];
        })->all();
    }

    /**
     * Event tickets this member holds.
     *
     * Same join and the same reason as {@see ordersFor()}: registration does not require an
     * account either. A `pending` registration is included and labelled, because an offer from
     * a waiting list IS pending — it is a seat held for a fixed number of hours, and it is the
     * most time-critical thing this platform can put in front of anybody.
     *
     * @return list<array<string,mixed>>
     */
    public static function ticketsFor(string $email, int $limit = 20): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') return [];

        try {
            $rows = DB::table('gates_event_registrations as r')
                ->leftJoin('gates_site_events as e', 'e.id', '=', 'r.event_id')
                ->whereRaw('LOWER(r.email) = ?', [$email])
                ->whereIn('r.status', ['confirmed', 'pending', 'waitlisted'])
                ->orderByDesc('r.id')->limit($limit)
                ->select('r.*', 'e.title as event_title', 'e.slug as event_slug',
                         'e.event_date', 'e.location')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        return $rows->map(static function ($r): array {
            $offered = ($r->offered_at ?? null) !== null;
            $status  = (string) $r->status;
            return [
                'event'     => (string) ($r->event_title ?? 'An event'),
                'slug'      => (string) ($r->event_slug ?? ''),
                'when'      => (string) ($r->event_date ?? ''),
                'where'     => (string) ($r->location ?? ''),
                'tier'      => (string) ($r->tier ?? ''),
                'seats'     => (int) ($r->quantity ?? 1),
                'code'      => (string) ($r->ticket_code ?? ''),
                'status'    => $status,
                // Four states a reader must be able to tell apart, and one of them has a clock
                // on it: a held offer expires and the seat passes to the next person waiting.
                'state'     => $status === 'confirmed' ? 'confirmed'
                             : ($status === 'waitlisted' ? 'waiting'
                             : ($offered ? 'offered' : 'unpaid')),
                'expires'   => (string) ($r->offer_expires_at ?? ''),
                'reference' => (string) ($r->reference ?? ''),
                // ── THE FLIER, FROM THE ACCOUNT AREA ────────────────────────
                //
                // The handoff's third entry point: regenerate, switch format, re-share. It
                // links at the event page's own generator rather than a second copy of one —
                // "switch format" and "re-share" are exactly what that component does, and
                // building a smaller version of it here would be two generators to keep in
                // step.
                //
                // Confirmed only. A held or waitlisted seat is not a ticket, and a flier
                // reading "Ticket confirmed" over a payment that has not landed is a claim
                // the door would refuse.
                //
                // The token rides in the FRAGMENT, not the query string. A fragment is never
                // sent to a server, so it stays out of access logs, out of the Referer header
                // and out of anything an intermediary keeps — which for a credential that
                // renders somebody's name onto an image is worth the one line of JavaScript
                // it costs to read.
                'flier'     => $status === 'confirmed' && trim((string) ($r->event_slug ?? '')) !== ''
                    ? '/events/' . rawurlencode((string) $r->event_slug) . '#flier='
                      . rawurlencode(\AfricaGates\Services\EventFlierToken::mint(
                          (int) ($r->event_id ?? 0),
                          (string) ($r->name ?? ''),
                          (int) ($r->id ?? 0)
                      ))
                    : '',
                // A waitlisted row has no reference to link to, so it points at the event.
                'url'       => trim((string) ($r->reference ?? '')) !== ''
                    ? '/events/ticket/' . rawurlencode((string) $r->reference)
                    : (trim((string) ($r->event_slug ?? '')) !== ''
                        ? '/events/' . rawurlencode((string) $r->event_slug) : '/events'),
            ];
        })->all();
    }

    /**
     * Share links this member created (live ones first), with open counts.
     *
     * @return list<array{nominee:string,url:string,hits:int,expires_at:string,expired:bool}>
     */
    public static function shareLinksFor(int $userId, int $limit = 10): array
    {
        if ($userId < 1) return [];
        try {
            $rows = DB::table('gates_nomination_links')
                ->where('created_by', $userId)
                ->orderByDesc('created_at')
                ->limit(max(1, $limit))
                ->get(['token', 'payload', 'hits', 'expires_at']);
        } catch (\Throwable) {
            return [];
        }
        $base = rtrim((string) Env::get('APP_URL', ''), '/');
        return array_map(static function ($r) use ($base) {
            $p = json_decode((string) $r->payload, true);
            return [
                'nominee'    => (string) (is_array($p) ? ($p['nominee_name'] ?? '') : ''),
                'url'        => $base . '/nominate?share=' . $r->token,
                'hits'       => (int) $r->hits,
                'expires_at' => (string) ($r->expires_at ?? ''),
                'expired'    => $r->expires_at !== null && strtotime((string) $r->expires_at) < time(),
            ];
        }, $rows->all());
    }

    /** @return array{threads:int,comments:int} */
    public static function communityCountsFor(string $email): array
    {
        $h = self::emailHash($email);
        try {
            return [
                'threads'  => (int) DB::table('gates_threads')->where('author_email_hash', $h)->whereIn('status', ['approved', 'quarantined', 'locked'])->count(),
                'comments' => (int) DB::table('gates_comments')->where('author_email_hash', $h)->whereIn('status', ['approved', 'quarantined'])->count(),
            ];
        } catch (\Throwable) {
            return ['threads' => 0, 'comments' => 0];
        }
    }

    /**
     * Profile completeness: verified email 40%, phone 30%, password 30%.
     *
     * @return array{pct:int,missing:list<string>}
     */
    public static function completeness(object|array $user): array
    {
        $u = (object) $user;
        $pct = 0;
        $missing = [];
        if ((int) ($u->email_verified ?? 0) === 1) $pct += 40; else $missing[] = 'verify';
        if (trim((string) ($u->phone ?? '')) !== '') $pct += 30; else $missing[] = 'phone';
        if (trim((string) ($u->password_hash ?? '')) !== '') $pct += 30; else $missing[] = 'password';
        return ['pct' => $pct, 'missing' => $missing];
    }

    /**
     * Onboarding checklist for the dashboard — each step links somewhere
     * actionable so a fresh account always has something to DO next.
     *
     * @return list<array{key:string,label:string,done:bool,href:string}>
     */
    public static function checklist(object|array $user, array $votes, array $nominations, array $communityCounts): array
    {
        $u = (object) $user;
        $comp = self::completeness($u);
        return [
            ['key' => 'verify',   'label' => 'Verify your email',                 'done' => (int) ($u->email_verified ?? 0) === 1, 'href' => '/account/verify'],
            ['key' => 'profile',  'label' => 'Complete your profile',             'done' => $comp['pct'] === 100,                   'href' => '/account#profile'],
            ['key' => 'vote',     'label' => 'Cast your first verified vote',     'done' => $votes !== [],                          'href' => '/vote'],
            ['key' => 'nominate', 'label' => 'Nominate someone extraordinary',    'done' => $nominations !== [],                    'href' => '/nominate'],
            ['key' => 'community','label' => 'Join a community conversation',     'done' => ($communityCounts['threads'] ?? 0) + ($communityCounts['comments'] ?? 0) > 0, 'href' => '/community'],
        ];
    }
}
