<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * An appeal for a stated purpose, run by a partner organisation.
 *
 * ── THE DIFFERENCE BETWEEN A CAMPAIGN AND A GENERAL FUND ─────────────────────
 *
 * Money given to an organisation is theirs to allocate. Money given to a CAMPAIGN was given
 * for the thing the campaign describes, and that is a restriction the organisation accepted
 * by publishing the page. Everything unusual in this class follows from that one fact:
 *
 *   • A target has a stated SHORTFALL POLICY, shown before anybody gives. "What happens if
 *     you don't reach it" is the first question a thoughtful donor asks and the last thing
 *     most platforms answer.
 *   • A closed campaign refuses money at the gateway call, not merely on the page.
 *   • The raised total is summed from confirmed rows and never cached, because a fundraising
 *     figure that drifts from the rows underneath it is the one number nobody forgives.
 *
 * ── AND A CAMPAIGN IS REVIEWED BEFORE IT IS PUBLIC ───────────────────────────
 *
 * The organisation writes it; Africa GATES publishes it. Listing an appeal puts the
 * platform's name beside somebody else's claim about what they will do with money, so it is
 * read first. Same doctrine as approving the organisation, applied to the thing donors
 * actually read.
 */
final class OrgCampaign
{
    public const STATUS_DRAFT  = 'draft';
    public const STATUS_REVIEW = 'review';
    public const STATUS_LIVE   = 'live';
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_DRAFT  => 'Draft',
        self::STATUS_REVIEW => 'Awaiting review',
        self::STATUS_LIVE   => 'Live',
        self::STATUS_CLOSED => 'Closed',
    ];

    /**
     * What the organisation commits to doing if the target is missed.
     *
     * Three options and no "we will decide later", because deciding later means deciding in
     * whatever way is most convenient once the money is in hand.
     */
    public const SHORTFALL = [
        'same_purpose' => 'Spend what was raised on the same purpose, at a smaller scale',
        'general'      => 'Move what was raised into the organisation’s general funds',
        'refund'       => 'Return every donation',
    ];

    public static function find(int $id): ?object
    {
        if ($id < 1) return null;
        return DB::table('gates_org_campaigns')->where('id', $id)->first();
    }

    /** Resolved by ORG and slug together — a slug is only unique within its organisation. */
    public static function bySlug(int $orgId, string $slug): ?object
    {
        $slug = trim($slug);
        if ($orgId < 1 || $slug === '') return null;
        return DB::table('gates_org_campaigns')->where('org_id', $orgId)->where('slug', $slug)->first();
    }

    /**
     * May this campaign take money right now?
     *
     * Four conditions, and the last two are the ones a page alone would miss: a campaign
     * whose window has not opened, and one whose closing date has passed. Both are checked
     * against the clock rather than against a status somebody has to remember to change.
     */
    public static function isOpen(?object $c): bool
    {
        if (!$c || (string) ($c->status ?? '') !== self::STATUS_LIVE) return false;

        $today = date('Y-m-d');
        $opens = trim((string) ($c->opens_on  ?? ''));
        $close = trim((string) ($c->closes_on ?? ''));

        if ($opens !== '' && substr($opens, 0, 10) > $today) return false;
        if ($close !== '' && substr($close, 0, 10) < $today) return false;
        return true;
    }

    /**
     * Everything a donor may currently give to, for one organisation.
     *
     * @return array<int,object>
     */
    public static function openFor(int $orgId): array
    {
        try {
            $rows = DB::table('gates_org_campaigns')
                ->where('org_id', $orgId)
                ->where('status', self::STATUS_LIVE)
                ->orderBy('closes_on')
                ->get()->all();
        } catch (\Throwable) {
            return [];
        }
        return array_values(array_filter($rows, static fn($c) => self::isOpen($c)));
    }

    /** @return array<int,object> */
    public static function allFor(int $orgId): array
    {
        try {
            return DB::table('gates_org_campaigns')->where('org_id', $orgId)
                ->orderByDesc('id')->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * What a campaign has actually raised.
     *
     * Summed from confirmed rows on every read. `pct` is capped at 100 for the bar's width
     * and `raised` is not, because an appeal that beat its target should say so and a bar
     * that overflows its container is a rendering bug rather than good news.
     *
     * @return array{raised:int,net:int,count:int,target:int,pct:int,met:bool}
     */
    public static function progress(int $campaignId): array
    {
        $zero = ['raised' => 0, 'net' => 0, 'count' => 0, 'target' => 0, 'pct' => 0, 'met' => false];

        $c = self::find($campaignId);
        if (!$c) return $zero;

        try {
            $row = DB::table('gates_donations')
                ->where('campaign_id', $campaignId)
                ->where('status', 'confirmed')
                ->selectRaw('COALESCE(SUM(amount_naira),0) g, COALESCE(SUM(platform_fee_naira),0) f, COUNT(*) n')
                ->first();
        } catch (\Throwable) {
            return $zero;
        }

        $gross  = (int) ($row->g ?? 0);
        $fee    = (int) ($row->f ?? 0);
        $target = (int) ($c->target_naira ?? 0);

        return [
            'raised' => $gross,
            'net'    => max(0, $gross - $fee),
            'count'  => (int) ($row->n ?? 0),
            'target' => $target,
            'pct'    => $target > 0 ? min(100, (int) floor($gross * 100 / $target)) : 0,
            'met'    => $target > 0 && $gross >= $target,
        ];
    }

    /**
     * The event this appeal names, or null.
     *
     * @param array<string,mixed> $in
     */
    private static function eventIdFrom(array $in): ?int
    {
        $id = (int) ($in['event_id'] ?? 0);
        if ($id < 1) return null;

        try {
            return DB::table('gates_site_events')->where('id', $id)->exists() ? $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The live appeals for an event, with their progress.
     *
     * Open ones only: a draft is somebody's unfinished writing and a closed one asking for
     * money is worse than nothing. Ordered by target so the main appeal leads when an event
     * carries more than one.
     *
     * @return list<array<string,mixed>>
     */
    public static function forEvent(int $eventId): array
    {
        if ($eventId < 1) return [];

        try {
            $rows = DB::table('gates_org_campaigns as c')
                ->join('gates_partner_orgs as o', 'o.id', '=', 'c.org_id')
                ->where('c.event_id', $eventId)
                ->where('c.status', self::STATUS_LIVE)
                ->orderByDesc('c.target_naira')->orderBy('c.id')
                ->get(['c.*', 'o.slug as org_slug', 'o.name as org_name']);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            // isOpen() reads the dates as well as the status, so an appeal whose closing
            // date has passed drops off the event page without anybody having to close it.
            if (!self::isOpen($r)) continue;

            $out[] = [
                'campaign' => $r,
                'progress' => self::progress((int) $r->id),
                'days_left'=> self::daysLeft($r),
                'url'      => '/donate/' . (string) $r->org_slug . '/' . (string) $r->slug,
                'org'      => (string) $r->org_name,
            ];
        }

        return $out;
    }

    /** How many days are left, or null when the appeal has no closing date. */
    public static function daysLeft(?object $c): ?int
    {
        $close = trim((string) ($c->closes_on ?? ''));
        if ($close === '') return null;
        $days = (int) floor((strtotime(substr($close, 0, 10) . ' 23:59:59') - time()) / 86400);
        return max(0, $days);
    }

    // ──────────────────────────────── writing ───────────────────────────────

    /**
     * Create or update. The organisation writes; publishing is somebody else's decision.
     *
     * A campaign that is already LIVE drops back to `review` when its story or target
     * changes, because a donor gave against the words that were on the page — quietly
     * rewriting an appeal that is collecting money is the thing this refuses to allow.
     *
     * @return array{ok:bool,message:string,id:int}
     */
    public static function save(int $orgId, array $in, int $campaignId = 0): array
    {
        $fail = ['ok' => false, 'id' => $campaignId];

        $org = PartnerOrg::find($orgId);
        if (!$org) return $fail + ['message' => 'That organisation does not exist.'];

        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') return $fail + ['message' => 'An appeal needs a title.'];

        // Slug::make, not a local expression. The naive `[^a-z0-9]+` DELETES accented
        // letters rather than folding them, which turns "Ẹ̀kọ́ Reads" into "-reads" and
        // mangles most African names — SlugTest exists to stop that copy being made a sixth
        // time, and caught this one.
        $slug = \AfricaGates\Support\Slug::make((string) ($in['slug'] ?? '') ?: $title, 120);
        if ($slug === '') return $fail + ['message' => 'That title does not make a usable web address.'];

        $target = (int) preg_replace('/[^0-9]/', '', (string) ($in['target_naira'] ?? '0'));
        $policy = (string) ($in['shortfall_policy'] ?? 'same_purpose');
        if (!isset(self::SHORTFALL[$policy])) $policy = 'same_purpose';

        $closes = trim((string) ($in['closes_on'] ?? ''));
        $opens  = trim((string) ($in['opens_on'] ?? ''));
        foreach (['opens' => $opens, 'closes' => $closes] as $k => $d) {
            if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                return $fail + ['message' => 'The ' . $k . ' date is not a valid date.'];
            }
        }
        if ($opens !== '' && $closes !== '' && $closes < $opens) {
            return $fail + ['message' => 'The closing date is before the opening date.'];
        }

        // A target with no deadline is an appeal that never ends. Allowed, but a target of
        // zero means "no target" rather than "we want nothing", and the two must not look
        // the same on the page.
        $row = [
            'org_id'           => $orgId,
            'slug'             => $slug,
            'title'            => mb_substr($title, 0, 200),
            'summary'          => mb_substr(trim((string) ($in['summary'] ?? '')), 0, 400) ?: null,
            'story'            => trim((string) ($in['story'] ?? '')) ?: null,
            // ── THE EVENT THIS IS RAISING FOR, IF ANY ────────────────────────
            //
            // Optional, and null is the normal case. An appeal that names an event appears
            // on that event's page with its target and running total, which is how a
            // fundraising dinner stops being a ticket page and an appeal page that do not
            // know about each other.
            //
            // Validated as a real event rather than trusted: this arrives from a form, and
            // an id pointing at nothing would render an appeal on no page at all while
            // looking correctly configured on the organisation's own screen.
            'event_id'         => self::eventIdFrom($in),
            'target_naira'     => $target > 0 ? $target : null,
            'shortfall_policy' => $policy,
            'opens_on'         => $opens !== '' ? $opens : null,
            'closes_on'        => $closes !== '' ? $closes : null,
            'updated_at'       => date('Y-m-d H:i:s'),
        ];

        $clash = DB::table('gates_org_campaigns')->where('org_id', $orgId)->where('slug', $slug);
        if ($campaignId > 0) $clash->where('id', '!=', $campaignId);
        if ($clash->exists()) {
            return $fail + ['message' => 'This organisation already has an appeal at that web address.'];
        }

        if ($campaignId > 0) {
            $existing = self::find($campaignId);
            if (!$existing || (int) $existing->org_id !== $orgId) {
                return $fail + ['message' => 'That appeal does not belong to this organisation.'];
            }

            // Editing a live appeal sends it back for review. A donor gave against the words
            // that were on the page; changing them while it collects is not an edit.
            $material = (string) ($existing->story ?? '') !== (string) ($row['story'] ?? '')
                     || (int) ($existing->target_naira ?? 0) !== (int) ($row['target_naira'] ?? 0)
                     || (string) ($existing->shortfall_policy ?? '') !== $policy;

            if ((string) $existing->status === self::STATUS_LIVE && $material) {
                $row['status']      = self::STATUS_REVIEW;
                $row['reviewed_by'] = null;
                $row['reviewed_at'] = null;
            }

            DB::table('gates_org_campaigns')->where('id', $campaignId)->update($row);
            return ['ok' => true, 'id' => $campaignId, 'message' => isset($row['status'])
                ? 'Saved. Because the appeal is live and its story or target changed, it has gone '
                . 'back for review before the new version is public.'
                : 'Saved.'];
        }

        $row['status']     = self::STATUS_DRAFT;
        $row['created_at'] = date('Y-m-d H:i:s');
        $id = (int) DB::table('gates_org_campaigns')->insertGetId($row);

        return ['ok' => true, 'id' => $id, 'message' => 'Appeal created as a draft. Send it for '
                                                      . 'review when you are happy with it.'];
    }

    /** The organisation asks for it to be published. */
    public static function submit(int $orgId, int $campaignId): array
    {
        $c = self::find($campaignId);
        if (!$c || (int) $c->org_id !== $orgId) return ['ok' => false, 'message' => 'Unknown appeal.'];
        if ((string) $c->status === self::STATUS_LIVE) return ['ok' => false, 'message' => 'That appeal is already live.'];

        DB::table('gates_org_campaigns')->where('id', $campaignId)->update([
            'status' => self::STATUS_REVIEW, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'message' => 'Sent for review. We will be in touch.'];
    }

    /**
     * Publish it. Refused unless the organisation itself may receive money — a live appeal
     * for a suspended charity is a donate button with nowhere to send the money.
     */
    public static function publish(int $campaignId, int $adminId, string $note = ''): array
    {
        $c = self::find($campaignId);
        if (!$c) return ['ok' => false, 'message' => 'Unknown appeal.'];

        if (!PartnerOrg::canReceive(PartnerOrg::find((int) $c->org_id))) {
            return ['ok' => false, 'message' => 'That organisation cannot currently receive donations, '
                                              . 'so its appeal cannot go live.'];
        }

        DB::table('gates_org_campaigns')->where('id', $campaignId)->update([
            'status'      => self::STATUS_LIVE,
            'reviewed_by' => $adminId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'review_note' => $note !== '' ? mb_substr($note, 0, 400) : null,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'message' => 'Published. It is now collecting.'];
    }

    public static function close(int $campaignId, int $adminId = 0): array
    {
        if (!self::find($campaignId)) return ['ok' => false, 'message' => 'Unknown appeal.'];
        DB::table('gates_org_campaigns')->where('id', $campaignId)->update([
            'status' => self::STATUS_CLOSED, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'message' => 'Closed. It no longer accepts donations.'];
    }
}
