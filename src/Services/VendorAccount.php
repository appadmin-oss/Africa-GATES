<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * A vendor, seen from the member account rather than from the partner console.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * TWO DASHBOARDS, AND ONLY ONE OF THEM IS FOR A TRADER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `/org` was built for a DONATION PARTNER: totals from confirmed gifts, appeals, payout
 * schedules, settlement accounts. A stand vendor has none of that and never will — they PAY
 * for a pitch rather than receiving anything through it — so roughly sixty per cent of that
 * screen is another product's interface, and the dashboard already suppresses the money
 * sections for them.
 *
 * What is left is a console a market trader has to remember a second password for. Meanwhile
 * they already have an account on this site: they registered, they vote, they buy tickets,
 * and `/account` is the page they actually open.
 *
 * So the vendor lives on the member page. `/org` is not removed — a partner organisation
 * with several staff logins still needs it, and a vendor who prefers it can still sign in
 * there — but a trader should never have to.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * HOW A MEMBER IS LINKED TO A VENDOR, AND WHY IT IS THE EMAIL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * By verified email address, matched against `gates_org_users.email`.
 *
 * That is the same join this platform already uses to resolve a member's votes, their
 * nominations, their orders and their tickets — see MemberActivityService, all four of which
 * key on `$user->email`. Adding a foreign key instead would create a second source of truth
 * for "is this the same person", and the two would disagree the first time somebody changed
 * an address on one side.
 *
 * ── THE EMAIL MUST BE VERIFIED, AND THAT IS LOAD-BEARING ────────────────────
 *
 * {@see forMember()} takes the member row and refuses unless the account is verified. An
 * unverified address is an address somebody typed, and a vendor account carries a settlement
 * account, uploaded registration certificates, and the ability to accept a pitch and be
 * charged for it. Matching on a typed string would be an account takeover with a sign-up
 * form as the exploit.
 */
final class VendorAccount
{
    /**
     * The organisations this member is an org-user of.
     *
     * @return list<array{org:object, role:string, is_owner:bool}>
     */
    public static function forMember(?object $member): array
    {
        if (!$member) return [];

        $email = strtolower(trim((string) ($member->email ?? '')));
        if ($email === '') return [];

        // ── VERIFIED ONLY ────────────────────────────────────────────────────
        //
        // A vendor account holds a settlement account, registration certificates, and the
        // power to accept a pitch and be charged for it. Linking on an address somebody
        // merely typed into a sign-up form would make that form the exploit.
        if (!self::verified($member)) return [];

        try {
            $links = DB::table('gates_org_users')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->where('is_active', 1)
                ->get()->all();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($links as $l) {
            $org = PartnerOrg::find((int) $l->org_id);
            if (!$org) continue;

            $role = (string) ($l->role ?? 'viewer');
            $out[] = ['org' => $org, 'role' => $role, 'is_owner' => $role === 'owner'];
        }

        return $out;
    }

    /**
     * Whether this member's address has been confirmed.
     *
     * Read defensively: the column has been called two things across this codebase's life
     * and an absent one must mean NOT verified, never "assume yes". Failing open here would
     * turn the whole guard above into decoration.
     */
    private static function verified(object $member): bool
    {
        // `gates_users.email_verified` — an integer flag, which is what this table actually
        // has. The two timestamp forms are read as well because they are the shapes the rest
        // of this codebase uses elsewhere and a migration could bring one here.
        if (property_exists($member, 'email_verified')) {
            return (int) ($member->email_verified ?? 0) === 1;
        }
        foreach (['email_verified_at', 'verified_at'] as $col) {
            if (property_exists($member, $col)) {
                return trim((string) ($member->{$col} ?? '')) !== '';
            }
        }

        // NOT verified. Failing open here would turn the guard above into decoration, and
        // the thing it guards is a settlement account and the power to accept a charge.
        return false;
    }

    /**
     * The one organisation to show, when a member has exactly one.
     *
     * Most traders have one. The rare multi-org case is a partner with staff on several
     * accounts, and those people are the ones /org exists for.
     */
    public static function primary(?object $member): ?object
    {
        $all = self::forMember($member);
        return $all === [] ? null : $all[0]['org'];
    }

    public static function isOwner(?object $member, int $orgId): bool
    {
        foreach (self::forMember($member) as $l) {
            if ((int) $l['org']->id === $orgId) return (bool) $l['is_owner'];
        }
        return false;
    }

    /**
     * Everything a trader's section of the account page needs, in one call.
     *
     * @return array<string,mixed>|null null when this member is not a vendor at all
     */
    public static function panel(?object $member): ?array
    {
        $org = self::primary($member);
        if (!$org) return null;

        $orgId = (int) $org->id;
        $apps  = self::applications($orgId);

        // The one thing with a deadline on it. Surfaced separately because everything else
        // on this page can wait and this cannot: an unaccepted offer is released, and before
        // the offer email carried its own link the only way to find out was to notice the
        // pitch had gone.
        $liveOffers = array_values(array_filter($apps, static fn (array $a): bool => $a['live_offer']));

        $owed = 0;
        foreach ($apps as $a) {
            if ((string) $a['app']->decision !== StandApplication::DECISION_ACCEPTED) continue;
            $owed += (int) $a['owing']['due'];
        }

        return [
            'org'          => $org,
            'is_owner'     => self::isOwner($member, $orgId),
            'is_vendor'    => (string) ($org->kind ?? '') === PartnerOrg::KIND_VENDOR,
            'applications' => $apps,
            'live_offers'  => $liveOffers,
            'owed'         => $owed,
            // The eligibility gap, in the vendor's own words rather than as a status code.
            // This is the reason an application sits unread, and it was only ever visible on
            // the other dashboard.
            'missing_docs' => StandApplication::missingDocuments($orgId),
            'documents'    => self::documents($orgId),
            'items'        => VendorCatalogue::forOrg($orgId),
            'lead_cat'     => VendorCatalogue::leadingCategory($orgId),
            'brand'        => OrgBrand::of($org),
        ];
    }

    /**
     * This vendor's applications, each with what it owes and whether its clock is running.
     *
     * @return list<array{app:object, event:?object, type:?object, owing:array<string,mixed>,
     *                    live_offer:bool, expires_at:string}>
     */
    public static function applications(int $orgId): array
    {
        try {
            $rows = DB::table('gates_stand_applications')
                ->where('org_id', $orgId)
                ->orderByDesc('id')
                ->get()->all();
        } catch (\Throwable) {
            return [];
        }

        $now = date('Y-m-d H:i:s');
        $out = [];

        foreach ($rows as $r) {
            $expires = trim((string) ($r->offer_expires_at ?? ''));

            $out[] = [
                'app'   => $r,
                'event' => self::event((int) $r->event_id),
                'type'  => StandType::find((int) $r->stand_type_id),
                'owing' => StandFee::owing($r),
                // An offer that is OPEN — not one whose clock has already run out, which is
                // a different sentence and a different set of buttons.
                'live_offer' => (string) $r->decision === StandApplication::DECISION_OFFERED
                             && ($expires === '' || $expires >= $now),
                'expires_at' => $expires,
                // The vendor's own link to it. Empty for a decision recorded before the
                // token existed, and the template falls back to the dashboard.
                'token' => trim((string) ($r->access_token ?? '')),
            ];
        }

        return $out;
    }

    /** @return array<int,object> */
    public static function documents(int $orgId): array
    {
        try {
            return DB::table('gates_org_documents')
                ->where('org_id', $orgId)->orderByDesc('id')->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private static function event(int $id): ?object
    {
        if ($id < 1) return null;
        try {
            return DB::table('gates_site_events')->where('id', $id)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // WRITING FROM THE MEMBER SESSION
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Which organisation this request is allowed to change, from either session.
     *
     * A vendor may arrive with an ORG session (they signed in at /org/login) or with a
     * MEMBER session (they signed in at /account/login and their verified address matches
     * an org user). Both are the same person and both must reach the same controls —
     * otherwise the account page would show a catalogue it could not edit.
     *
     * Returns 0 when neither session authorises anything. `requireOwner` is the write gate:
     * a `viewer` may read the dashboard and change nothing, on either route.
     */
    public static function writableOrgId(?object $orgUser, ?object $member, bool $requireOwner = true): int
    {
        if ($orgUser !== null) {
            $ok = !$requireOwner || OrgAuth::canRequestPayout($orgUser);
            return $ok ? (int) $orgUser->org_id : 0;
        }

        $org = self::primary($member);
        if (!$org) return 0;

        $orgId = (int) $org->id;
        if ($requireOwner && !self::isOwner($member, $orgId)) return 0;

        return $orgId;
    }
}
