<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * A vendor applying for a stand, and the two-stage gate that decides it.
 *
 * ── ELIGIBILITY IS NOT SELECTION, AND THEY ARE SEPARATE COLUMNS ──────────────
 *
 * Eligibility is objective and machine-checkable: the required documents are present and in
 * date, and the organisation is not suspended. A failure here is explainable in one sentence
 * and carries no judgement.
 *
 * Selection is judgement — quota, quality, mix — and is the only stage that needs a recorded
 * rationale.
 *
 * They are two columns because collapsing them into a single status is what makes rejections
 * feel arbitrary: an applicant told "unsuccessful" cannot tell whether they failed a rule or
 * a taste, and the second is the one that becomes a story about favouritism.
 *
 * ── AND THE TIEBREAK IS COMPLETENESS, NOT SPEED ──────────────────────────────
 *
 * §5.4: ties break on the earliest COMPLETE application, which is why `completed_at` exists
 * separately from `submitted_at`. Breaking on submission rewards whoever submits an empty
 * form fastest and then fills it in, which is precisely backwards.
 */
final class StandApplication
{
    public const ELIGIBILITY_UNCHECKED = 'unchecked';
    public const ELIGIBILITY_PASS      = 'pass';
    public const ELIGIBILITY_FAIL      = 'fail';

    public const DECISION_PENDING   = 'pending';
    public const DECISION_OFFERED   = 'offered';
    public const DECISION_ACCEPTED  = 'accepted';
    public const DECISION_DECLINED  = 'declined';
    public const DECISION_WAITLIST  = 'waitlisted';
    public const DECISION_REJECTED  = 'rejected';

    public const DECISIONS = [
        self::DECISION_PENDING  => 'Not yet decided',
        self::DECISION_OFFERED  => 'Offered a stand',
        self::DECISION_ACCEPTED => 'Accepted',
        self::DECISION_DECLINED => 'Declined the offer',
        self::DECISION_WAITLIST => 'On the waiting list',
        self::DECISION_REJECTED => 'Not selected',
    ];

    /** How long a vendor has to accept before the place goes to the waiting list. */
    public const OFFER_HOURS = 72;

    public static function find(int $id): ?object
    {
        if ($id < 1) return null;
        return DB::table('gates_stand_applications')->where('id', $id)->first();
    }

    /** @return array<int,object> */
    public static function forCall(int $callId): array
    {
        try {
            return DB::table('gates_stand_applications')->where('call_id', $callId)
                ->orderBy('stand_type_id')->orderBy('completed_at')->orderBy('id')
                ->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int,object> */
    public static function forOrg(int $orgId): array
    {
        try {
            return DB::table('gates_stand_applications')->where('org_id', $orgId)
                ->orderByDesc('id')->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    // ──────────────────────────────── applying ──────────────────────────────

    /**
     * Submit an application.
     *
     * @return array{ok:bool,message:string,id:int}
     */
    public static function submit(int $orgId, int $standTypeId, array $in): array
    {
        $fail = ['ok' => false, 'id' => 0];

        $org = PartnerOrg::find($orgId);
        if (!$org) return $fail + ['message' => 'That organisation does not exist.'];

        // A suspended vendor cannot apply, for the same reason it cannot collect: the state
        // exists to stop them trading, and an application is the first step of trading.
        if ((string) $org->status === PartnerOrg::STATUS_SUSPENDED) {
            return $fail + ['message' => 'This organisation is suspended and cannot apply.'];
        }

        $type = StandType::find($standTypeId);
        if (!$type) return $fail + ['message' => 'That stand type does not exist.'];

        $call = StandCall::forEvent((int) $type->event_id);
        if (!StandCall::isAccepting($call)) {
            return $fail + ['message' => 'Applications for this event are closed.'];
        }

        // One application per organisation per stand type. Applying for two DIFFERENT types is
        // legitimate — a vendor may take either a food or a craft pitch — but applying twice
        // for the same one is either a double-click or an attempt at two places in one queue.
        $already = DB::table('gates_stand_applications')
            ->where('call_id', $call->id)->where('org_id', $orgId)
            ->where('stand_type_id', $standTypeId)->first();
        if ($already) {
            // NOT `$fail + [...]`. The union operator keeps the LEFT operand's keys, so
            // `$fail['id'] = 0` would silently win over the real id and the caller would be
            // pointed at nothing. Spelled out rather than clever.
            return ['ok' => false, 'id' => (int) $already->id,
                    'message' => 'You have already applied for this stand type.'];
        }

        $sells = trim((string) ($in['what_they_sell'] ?? ''));
        if ($sells === '') {
            return $fail + ['message' => 'Say what you intend to sell — it is what the panel scores '
                                       . 'and what decides which certificates you need.'];
        }

        $now = date('Y-m-d H:i:s');
        $id  = (int) DB::table('gates_stand_applications')->insertGetId([
            'call_id'         => (int) $call->id,
            'event_id'        => (int) $type->event_id,
            'org_id'          => $orgId,
            'stand_type_id'   => $standTypeId,
            'what_they_sell'  => mb_substr($sells, 0, 2000),
            'needs_power'     => !empty($in['needs_power'])     ? 1 : 0,
            'needs_step_free' => !empty($in['needs_step_free']) ? 1 : 0,
            'submitted_at'    => $now,
            'created_at'      => $now,
        ]);

        // Completeness is evaluated immediately, because an application that already has its
        // documents is complete on submission and should carry that timestamp.
        self::refreshCompleteness($id);

        return ['ok' => true, 'id' => $id, 'message' => 'Application received.'];
    }

    /**
     * Re-evaluate whether an application is COMPLETE, and stamp the moment it became so.
     *
     * Stamped once and never moved. The tiebreak in §5.4 is the moment an application became
     * complete, so a later document upload must not reset it and a vendor must not be able to
     * improve their queue position by touching the form.
     */
    public static function refreshCompleteness(int $appId): bool
    {
        $app = self::find($appId);
        if (!$app) return false;
        if (trim((string) ($app->completed_at ?? '')) !== '') return true;  // already stamped

        $missing = self::missingDocuments((int) $app->org_id);
        if ($missing !== []) return false;

        DB::table('gates_stand_applications')->where('id', $appId)
            ->update(['completed_at' => date('Y-m-d H:i:s')]);
        return true;
    }

    /**
     * Which required documents this organisation has not supplied, or has let lapse.
     *
     * An EXPIRED document counts as missing. That is the whole point of storing expiries: a
     * hygiene certificate that ran out three weeks before the event is not a document, and
     * discovering it on the morning makes it the organiser's crisis rather than the vendor's
     * (§10.4).
     *
     * @return array<string,string> slug => human label
     */
    public static function missingDocuments(int $orgId): array
    {
        $required = PartnerOrg::requiredDocuments($orgId);
        if ($required === []) return [];

        try {
            $held = DB::table('gates_org_documents')->where('org_id', $orgId)
                ->get(['kind', 'expires_on'])->all();
        } catch (\Throwable) {
            return $required;   // cannot read them; treat as absent rather than as present
        }

        $today = date('Y-m-d');
        $valid = [];
        foreach ($held as $d) {
            $exp = trim((string) ($d->expires_on ?? ''));
            if ($exp !== '' && substr($exp, 0, 10) < $today) continue;   // lapsed
            $valid[(string) $d->kind] = true;
        }

        return array_diff_key($required, $valid);
    }

    // ─────────────────────────────── eligibility ────────────────────────────

    /**
     * The objective gate. No judgement, and no scoring.
     *
     * @return array{ok:bool,message:string,missing:array<string,string>}
     */
    public static function checkEligibility(int $appId): array
    {
        $app = self::find($appId);
        if (!$app) return ['ok' => false, 'message' => 'Unknown application.', 'missing' => []];

        $org     = PartnerOrg::find((int) $app->org_id);
        $missing = self::missingDocuments((int) $app->org_id);

        $reasons = [];
        if (!$org) {
            $reasons[] = 'the organisation record is missing';
        } elseif ((string) $org->status === PartnerOrg::STATUS_SUSPENDED) {
            $reasons[] = 'the organisation is suspended';
        }
        if ($missing !== []) {
            $reasons[] = 'these are missing or out of date: ' . implode(', ', $missing);
        }

        $pass = $reasons === [];
        $note = $pass ? '' : ucfirst(implode('; ', $reasons)) . '.';

        DB::table('gates_stand_applications')->where('id', $appId)->update([
            'eligibility'      => $pass ? self::ELIGIBILITY_PASS : self::ELIGIBILITY_FAIL,
            'eligibility_note' => $note !== '' ? mb_substr($note, 0, 400) : null,
        ]);

        return ['ok' => $pass, 'missing' => $missing,
                'message' => $pass ? 'Eligible.' : $note];
    }

    // ──────────────────────────────── decisions ─────────────────────────────

    /**
     * Offer a stand.
     *
     * Two refusals here matter. An INELIGIBLE application cannot be offered — that is what
     * makes eligibility a gate rather than a note. And an offer beyond the published quota is
     * refused, because the quota was published before anybody applied and offering an
     * eleventh place in a category of ten is the exact failure §5.2 exists to prevent.
     *
     * @return array{ok:bool,message:string}
     */
    public static function offer(int $appId, int $adminId, string $reason = ''): array
    {
        $app = self::find($appId);
        if (!$app) return ['ok' => false, 'message' => 'Unknown application.'];

        if ((string) $app->eligibility !== self::ELIGIBILITY_PASS) {
            return ['ok' => false, 'message' => 'This application has not passed the eligibility '
                                              . 'check, so it cannot be offered a stand. Run the '
                                              . 'check, or tell them what is missing.'];
        }
        if (in_array((string) $app->decision, [self::DECISION_OFFERED, self::DECISION_ACCEPTED], true)) {
            return ['ok' => false, 'message' => 'This application already holds a place.'];
        }

        foreach (StandCall::capacity((int) $app->event_id) as $c) {
            if ((int) $c['type']->id !== (int) $app->stand_type_id) continue;
            if ($c['left'] < 1) {
                return ['ok' => false, 'message' => 'All ' . $c['quota'] . ' places in “'
                    . $c['type']->name . '” are taken. The quota was published with the call and '
                    . 'cannot be exceeded — put this application on the waiting list instead.'];
            }
        }

        DB::table('gates_stand_applications')->where('id', $appId)->update([
            'decision'         => self::DECISION_OFFERED,
            'decision_reason'  => $reason !== '' ? mb_substr($reason, 0, 400) : null,
            'decided_by'       => $adminId,
            'decided_at'       => date('Y-m-d H:i:s'),
            'offer_expires_at' => date('Y-m-d H:i:s', time() + self::OFFER_HOURS * 3600),
        ]);

        return ['ok' => true, 'message' => 'Offered. They have ' . self::OFFER_HOURS
                                         . ' hours to accept before the place returns to the pool.'];
    }

    /**
     * Decline, reject or waitlist.
     *
     * A rejection REQUIRES a reason. §5.7 gives every applicant an outcome with a reason drawn
     * from the published criteria, and the cheapest way to guarantee that is to make the
     * record impossible to write without one.
     */
    public static function decide(int $appId, string $decision, int $adminId, string $reason = ''): array
    {
        if (!isset(self::DECISIONS[$decision])) {
            return ['ok' => false, 'message' => 'Unknown decision.'];
        }
        if (!self::find($appId)) return ['ok' => false, 'message' => 'Unknown application.'];

        if ($decision === self::DECISION_REJECTED && trim($reason) === '') {
            return ['ok' => false, 'message' => 'A rejection needs a reason. Every applicant gets '
                                              . 'an outcome they can understand — that is the '
                                              . 'difference between a disappointment and a story '
                                              . 'about favouritism.'];
        }

        DB::table('gates_stand_applications')->where('id', $appId)->update([
            'decision'        => $decision,
            'decision_reason' => trim($reason) !== '' ? mb_substr(trim($reason), 0, 400) : null,
            'decided_by'      => $adminId,
            'decided_at'      => date('Y-m-d H:i:s'),
        ]);

        return ['ok' => true, 'message' => self::DECISIONS[$decision] . ' recorded.'];
    }

    /**
     * The vendor accepts their offer.
     *
     * Refused once the hold window has passed, because the window is one of the published
     * terms and an offer that never expires is a place held indefinitely against a waiting
     * list that was told it would move.
     */
    public static function accept(int $appId, int $orgId): array
    {
        $app = self::find($appId);
        if (!$app || (int) $app->org_id !== $orgId) {
            return ['ok' => false, 'message' => 'That application does not belong to your organisation.'];
        }
        if ((string) $app->decision !== self::DECISION_OFFERED) {
            return ['ok' => false, 'message' => 'There is no open offer on this application.'];
        }

        $expires = trim((string) ($app->offer_expires_at ?? ''));
        if ($expires !== '' && $expires < date('Y-m-d H:i:s')) {
            return ['ok' => false, 'message' => 'This offer expired on ' . substr($expires, 0, 16)
                                              . '. Contact the organiser — the place may have gone '
                                              . 'to somebody on the waiting list.'];
        }

        DB::table('gates_stand_applications')->where('id', $appId)->update([
            'decision'   => self::DECISION_ACCEPTED,
            'decided_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'message' => 'Accepted. You will be invoiced for the stand fee.'];
    }

    /**
     * Release offers nobody accepted in time.
     *
     * Run from maintenance. Without it a place is held by somebody who stopped replying, and
     * the waiting list that was promised movement does not move.
     *
     * @return int how many were released
     */
    public static function expireStaleOffers(): int
    {
        try {
            $rows = DB::table('gates_stand_applications')
                ->where('decision', self::DECISION_OFFERED)
                ->whereNotNull('offer_expires_at')
                ->where('offer_expires_at', '<', date('Y-m-d H:i:s'))
                ->get(['id'])->all();
        } catch (\Throwable) {
            return 0;
        }

        $n = 0;
        foreach ($rows as $r) {
            DB::table('gates_stand_applications')->where('id', $r->id)->update([
                'decision'        => self::DECISION_WAITLIST,
                'decision_reason' => 'The offer was not accepted before it expired.',
                'decided_at'      => date('Y-m-d H:i:s'),
            ]);
            $n++;
        }
        return $n;
    }
}
