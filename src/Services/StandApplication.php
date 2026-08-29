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

    /**
     * How much of "what you will sell" is kept.
     *
     * A constant rather than a literal in two places, because the form's `maxlength` and the
     * server's limit disagreeing is exactly how a silent truncation happens: the browser
     * lets 3,000 through and the database keeps 2,000.
     */
    public const SELLS_MAX = 2000;

    public static function find(int $id): ?object
    {
        if ($id < 1) return null;
        return DB::table('gates_stand_applications')->where('id', $id)->first();
    }

    /**
     * Every application in a call, in the order the published tiebreak defines.
     *
     * ── THE `IS NULL` IS THE WHOLE POINT ────────────────────────────────────
     *
     * §5.4 ranks equal applications by WHO BECAME COMPLETE FIRST, and the public form says
     * so twice. This used to be `orderBy('completed_at')` alone — and in both MySQL and
     * SQLite, NULL sorts FIRST in an ascending order. So every INCOMPLETE application, the
     * ones that cannot be offered anything, floated to the top of the list, above the
     * vendors who had actually got their certificates in.
     *
     * An organiser working down a two-hundred-row page in the order it was handed to them
     * was therefore reading it in almost exactly the inverse of the rule they had published.
     * Nothing in the code was wrong about the rule; the SQL just quietly disagreed with it.
     *
     * `(completed_at IS NULL)` yields 0 for complete and 1 for not, in both engines, so
     * complete rows come first and are then ordered among themselves by when. `id` last, so
     * two applications completed in the same second are still deterministic — the same
     * reasoning as the shortlist cut.
     *
     * @return array<int,object>
     */
    public static function forCall(int $callId): array
    {
        try {
            return DB::table('gates_stand_applications')->where('call_id', $callId)
                ->orderBy('stand_type_id')
                ->orderByRaw('(completed_at IS NULL)')
                ->orderBy('completed_at')
                ->orderBy('id')
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
            // `field`, so the form can mark THIS input rather than showing a banner at the
            // top of a page the person then has to re-read. See PartnerOrg::registerVendor.
            return $fail + ['field' => 'what_they_sell',
                            'message' => 'Say what you intend to sell — it is what the panel scores '
                                       . 'and what decides which certificates you need.'];
        }
        // ── REFUSED, NOT TRUNCATED ──────────────────────────────────────────
        //
        // The column takes 2,000 characters and this used to mb_substr() silently. Somebody
        // who wrote 2,400 words about their business had 400 of them deleted without being
        // told, in the one field the panel actually reads. The form now caps the textarea at
        // the same number and this is the server saying the same thing, so a paste that
        // exceeds it is a message rather than a quiet edit.
        if (mb_strlen($sells) > self::SELLS_MAX) {
            return $fail + ['field' => 'what_they_sell',
                            'message' => 'That is ' . number_format(mb_strlen($sells) - self::SELLS_MAX)
                                       . ' characters longer than the '
                                       . number_format(self::SELLS_MAX) . ' this field holds. '
                                       . 'Shorten it rather than letting us cut it — the panel '
                                       . 'reads what is here.'];
        }

        // ── THE TRADE THEY SAY THEY ARE IN ──────────────────────────────────
        //
        // Validated against the ORGANISER'S list rather than accepted as typed. The slug
        // reaches a screen that groups applications by trade and a quota conversation that
        // assumes two people who sell food are filed together, and a value not on the list
        // is a row that silently belongs to no group — which looks like a missing
        // application rather than a bad field.
        //
        // Required, because the form now asks for it. An older application has NULL here
        // and that is a different thing: not asked, rather than not answered.
        $category = trim((string) ($in['category'] ?? ''));
        $offered  = VendorPolicy::categories();
        if (!isset($offered[$category])) {
            return $fail + ['field' => 'category',
                            'message' => $category === ''
                                ? 'Choose the trade that best describes what you sell.'
                                : 'That is not one of the trades this event publishes. '
                                . 'Choose one from the list.'];
        }

        $now = date('Y-m-d H:i:s');
        $id  = (int) DB::table('gates_stand_applications')->insertGetId([
            'call_id'         => (int) $call->id,
            'event_id'        => (int) $type->event_id,
            'org_id'          => $orgId,
            'stand_type_id'   => $standTypeId,
            'category'        => $category,
            'what_they_sell'  => mb_substr($sells, 0, self::SELLS_MAX),
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

        if (self::missingForCompleteness($appId) !== []) return false;

        DB::table('gates_stand_applications')->where('id', $appId)
            ->update(['completed_at' => date('Y-m-d H:i:s')]);
        return true;
    }

    /**
     * Everything an application is still waiting on before it counts as complete.
     *
     * ── WHY THIS IS NOT missingDocuments() WITH AN EXTRA ITEM ────────────────
     *
     * Because {@see checkEligibility()} reads missingDocuments(), and eligibility is a
     * RULE — a gate with no judgement in it, which either passes or refuses the
     * application outright. Photographs are deliberately not that. Putting them in that
     * list would fail applications from exactly the people this platform exists for:
     * somebody photographing their goods on a borrowed phone, from a market with no
     * signal, the evening before the deadline.
     *
     * So they sit here instead, on the completeness shelf, which is the §5.4 tiebreak. An
     * application without them is submitted, read, and can win. It is simply not complete
     * until three are on file, and where two are otherwise equal the complete one goes
     * first.
     *
     * The wording matches a missing certificate on purpose. To a vendor reading their
     * dashboard these are the same kind of fact — a thing still to do before the
     * application is finished — and giving one of them a softer voice would teach them it
     * mattered less.
     *
     * @return array<string,string> slug => human label
     */
    public static function missingForCompleteness(int $appId): array
    {
        $app = self::find($appId);
        if (!$app) return [];

        $missing = self::missingDocuments((int) $app->org_id);

        $photos = StandPhotos::count($appId);
        if ($photos < StandPhotos::MIN) {
            $missing['stand_photos'] = 'Photographs of what you sell ('
                . $photos . ' of ' . StandPhotos::MIN . ')';
        }

        return $missing;
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

        // ── WHAT ACCEPTING COSTS, AND THE LINK THAT REACHES IT ──────────────
        //
        // Stamped HERE and not on acceptance, because the offer email needs both: somebody
        // deciding inside a two-day clock needs to know the price before they decide, and
        // needs a way in that does not require remembering a password set six weeks ago on
        // a phone. {@see StandFee::stamp()} copies the price off the stand type so a later
        // change to it cannot alter what this vendor agreed to.
        StandFee::stamp($appId);

        // ── AND TELL THEM, WHICH NOTHING DID ────────────────────────────────
        //
        // The clock above starts now. Before this line existed, the only way a vendor could
        // learn that it had started was to log in unprompted — and when it ran out,
        // Maintenance::expireStandOffers() released the pitch, silently. So the most likely
        // outcome of a successful application was losing the place without ever knowing it
        // had been won. The public application page promises "You hear either way, with a
        // reason"; this is the half of that promise with a deadline attached.
        //
        // Queued, so an SMTP hiccup cannot fail or slow the decision itself.
        StandNotice::queue($appId, self::DECISION_OFFERED);

        return ['ok' => true, 'message' => 'Offered, and they have been emailed. They have '
                                         . self::OFFER_HOURS
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

        // A rejection is refused above without a reason, precisely so the applicant is owed
        // an explanation — and that reason was then stored and never sent. `declined` is the
        // vendor's own act and needs no message back; the other outcomes are ours to report.
        StandNotice::queue($appId, $decision);

        return ['ok' => true, 'message' => self::DECISIONS[$decision]
            . (isset(StandNotice::KINDS[$decision]) ? ' recorded, and they have been emailed.'
                                                    : ' recorded.')];
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

        // ── AND WHAT HAPPENS TO THE MONEY ───────────────────────────────────
        //
        // This used to say "You will be invoiced for the stand fee." Nothing invoiced
        // anybody: there was no amount on the row, nothing the vendor could see, no way to
        // pay, and no way for an organiser to tell a paid pitch from an unpaid one on the
        // morning of the market. A published price beside a published quota is only
        // defensible if the transaction happens where both parties can see it.
        $owing = StandFee::owing(self::find($appId));

        return ['ok' => true, 'message' => $owing['settled']
            ? 'Accepted. ' . $owing['label']
            : 'Accepted. ' . $owing['label'] . ' You can pay it now from this page.'];
    }

    /**
     * Put a settled application back in front of the panel.
     *
     * ── WHY THIS IS NEEDED ──────────────────────────────────────────────────
     *
     * Every decision on this table was one-way. An offer that ran out while the trader was
     * at a funeral, a rejection recorded against the wrong row, a waitlisted applicant an
     * organiser now has room for — none of them had a route back. The only workaround was
     * to ask the vendor to apply again, which loses their place in the completeness
     * tiebreak (§5.4 ranks by when an application became COMPLETE) and rewrites their own
     * record as though the first application never happened.
     *
     * ── AND WHY AN ACCEPTED PITCH CANNOT BE REOPENED ────────────────────────
     *
     * Because it may have been paid for. Flipping an accepted row back to pending would
     * leave money credited against an application nobody holds, and would take a place off
     * somebody who was told it was theirs. Withdrawing an accepted stand is a different act
     * with a refund attached; it is not this button, and pretending otherwise here would
     * hide the refund.
     *
     * An OFFERED application is refused for a smaller reason: it is already live. Its clock
     * is running and reopening it would silently cancel an offer the vendor may be reading
     * right now.
     *
     * @return array{ok:bool,message:string}
     */
    public static function reopen(int $appId, int $adminId, string $note = ''): array
    {
        $app = self::find($appId);
        if (!$app) return ['ok' => false, 'message' => 'Unknown application.'];

        $decision = (string) $app->decision;

        if ($decision === self::DECISION_PENDING) {
            return ['ok' => false, 'message' => 'This application has not been decided, so there '
                                              . 'is nothing to reopen.'];
        }
        if ($decision === self::DECISION_OFFERED) {
            return ['ok' => false, 'message' => 'This application holds a live offer. Let it run, '
                                              . 'or close it out first — reopening now would '
                                              . 'cancel an offer the vendor may be reading.'];
        }
        if ($decision === self::DECISION_ACCEPTED) {
            return ['ok' => false, 'message' => 'This stand has been accepted and may have been '
                                              . 'paid for. Reopening it would leave money against '
                                              . 'a pitch nobody holds and take a place off '
                                              . 'somebody who was told it was theirs. Withdraw it '
                                              . 'instead, which handles the refund.'];
        }

        // The capacity check is NOT run here on purpose. Reopening returns an application to
        // the pool; it does not hand out a place. {@see offer()} is the gate that counts the
        // published quota, and it will refuse if the type is now full — which is the right
        // moment to find that out, and the right person to tell.
        DB::table('gates_stand_applications')->where('id', $appId)->update([
            'decision'         => self::DECISION_PENDING,
            // The old reason goes. Leaving "Two other food vendors scored higher" attached to
            // an application that is once again undecided would put a verdict on a row that
            // has not been judged, and it is the text a rejection notice quotes.
            'decision_reason'  => trim($note) !== '' ? mb_substr(trim($note), 0, 400) : null,
            'decided_by'       => $adminId,
            'decided_at'       => date('Y-m-d H:i:s'),
            // The clock and the token belong to the offer that is being undone.
            'offer_expires_at' => null,
        ]);

        return ['ok' => true, 'message' => 'Reopened. It is back in the undecided pile and can '
                                         . 'be offered, waitlisted or rejected again — the '
                                         . 'quota is checked when you offer it, not now. '
                                         . 'Nothing has been sent to the vendor.'];
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
            // `expired` and not `waitlisted`: the stored decision is the same, but the
            // message a person needs is completely different. "You are on the waiting list"
            // to somebody who was offered a pitch and lost it reads as though we never
            // offered it at all.
            StandNotice::queue((int) $r->id, 'expired');
            $n++;
        }
        return $n;
    }
}
