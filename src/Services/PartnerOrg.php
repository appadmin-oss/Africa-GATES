<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Partner organisations that collect donations through this platform.
 *
 * ── THE MONEY NEVER BELONGS TO US ────────────────────────────────────────────
 *
 * A partner donation is ONE payment split at the gateway: the organisation's share settles
 * into the organisation's own Paystack subaccount — tied to a bank account in the
 * organisation's own registered name — and the platform's share splits off at source. There
 * is no platform balance holding partner money, and therefore no moment at which Africa
 * GATES is a custodian of somebody else's charitable funds.
 *
 * That is why this class stores a `subaccount_code` and not a balance, and why
 * {@see receivableStatuses()} is checked on the PUBLIC path rather than only in admin: a
 * suspended organisation must stop being able to receive money the moment somebody presses
 * the button, not the next time a cache expires.
 *
 * ── THE NAME MATCH IS THE FRAUD CONTROL ──────────────────────────────────────
 *
 * Before a subaccount is created, `/bank/resolve` is asked who owns the account number. The
 * answer is compared against the organisation's registered name and the verdict is STORED.
 * A weak match does not block creation — a genuine "Bright Futures Initiative" may bank as
 * "BRIGHT FUTURES INIT LTD", and refusing that would just push people to lie — but it does
 * block the organisation going live, because the difference between a trading name and a
 * stranger's personal account is a judgement a human has to make once, cheaply, before any
 * donor is involved.
 *
 * Deliberately NOT stored: the account number. It is sent to Paystack once and never written
 * down. After that the subaccount code is the only handle anything needs, and a table of NGO
 * account numbers is a liability with no matching use.
 */
final class PartnerOrg
{
    /** draft → pending → approved, with suspended and rejected reachable from anywhere sensible. */
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REJECTED  = 'rejected';

    public const STATUSES = [
        self::STATUS_DRAFT     => 'Draft',
        self::STATUS_PENDING   => 'Awaiting review',
        self::STATUS_APPROVED  => 'Approved',
        self::STATUS_SUSPENDED => 'Suspended',
        self::STATUS_REJECTED  => 'Rejected',
    ];

    /** How close a resolved account name must be to count as "the same organisation". */
    private const STRONG_MATCH = 0.86;

    /** Only these may appear publicly or take money. */
    public static function receivableStatuses(): array
    {
        return [self::STATUS_APPROVED];
    }

    public static function find(int $id): ?object
    {
        if ($id < 1) return null;
        return DB::table('gates_partner_orgs')->where('id', $id)->first();
    }

    public static function bySlug(string $slug): ?object
    {
        $slug = trim($slug);
        if ($slug === '') return null;
        return DB::table('gates_partner_orgs')->where('slug', $slug)->first();
    }

    /**
     * May this organisation be shown a donate button right now?
     *
     * Three conditions, all required: approved, not suspended, and actually able to receive
     * a split. An approved organisation with no subaccount is a configuration mistake, and
     * showing it a donate button would take money with nowhere to send it.
     */
    public static function canReceive(?object $org): bool
    {
        if (!$org) return false;
        if (!in_array((string) ($org->status ?? ''), self::receivableStatuses(), true)) return false;
        return trim((string) ($org->subaccount_code ?? '')) !== '';
    }

    /**
     * Every organisation a donor may currently give to.
     *
     * @return array<int,object>
     */
    public static function listReceivable(): array
    {
        try {
            return DB::table('gates_partner_orgs')
                ->whereIn('status', self::receivableStatuses())
                ->whereNotNull('subaccount_code')
                ->where('subaccount_code', '!=', '')
                ->orderBy('name')
                ->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    // ─────────────────────────────── onboarding ─────────────────────────────

    /**
     * Resolve the account, check the name, create the subaccount, store the code.
     *
     * Ordered so that nothing is written until the gateway has agreed. A half-onboarded
     * organisation holding a bank account we could not verify is exactly the row somebody
     * approves by accident six weeks later.
     *
     * @return array{ok:bool,message:string,match:float,resolved_name:string,needs_review:bool}
     */
    public static function attachSubaccount(
        PaymentService $payments,
        int            $orgId,
        string         $accountNumber,
        string         $bankCode,
        float          $platformFeePercent = 0.0,
        string         $settlementSchedule = 'auto'
    ): array {
        $fail = ['ok' => false, 'match' => 0.0, 'resolved_name' => '', 'needs_review' => true];

        $org = self::find($orgId);
        if (!$org) return $fail + ['message' => 'That organisation does not exist.'];

        if (trim((string) ($org->subaccount_code ?? '')) !== '') {
            return $fail + ['message' => 'That organisation already has a settlement account. '
                                       . 'Detach it before attaching another, so there is never '
                                       . 'ambiguity about where its money went.'];
        }

        // 1 · Who does the bank say owns this account?
        $resolved = $payments->resolveAccount($accountNumber, $bankCode);
        if (!$resolved['ok']) {
            // An outage is not a refusal, and the message says which it was.
            return $fail + ['message' => $resolved['message']];
        }

        // 2 · Is that plausibly this organisation?
        $registered = (string) ($org->legal_name ?? '') !== ''
            ? (string) $org->legal_name
            : (string) ($org->name ?? '');
        $score = self::nameSimilarity($registered, $resolved['name']);

        // 3 · Create it. A weak match is recorded, not refused — see the class docblock.
        $created = $payments->createSubaccount(
            businessName:       (string) $org->name,
            bankCode:           $bankCode,
            accountNumber:      $accountNumber,
            percentageCharge:   $platformFeePercent,
            settlementSchedule: $settlementSchedule,
            contact: [
                'email' => (string) ($org->contact_email ?? ''),
                'name'  => (string) ($org->contact_name ?? ''),
                'phone' => (string) ($org->contact_phone ?? ''),
            ],
        );
        if (!$created['ok']) {
            return $fail + ['message' => $created['message'], 'resolved_name' => $resolved['name'], 'match' => $score];
        }

        $digits = preg_replace('/\D+/', '', $accountNumber) ?? '';

        // ── THE TRANSFER RECIPIENT IS CREATED HERE, NOT AT PAYOUT TIME ───────
        //
        // A transfer recipient needs the bank account number, and this is the only moment
        // the platform legitimately has it — after this request it is deliberately never
        // stored. Creating the recipient now and keeping only its code means a payout later
        // needs nothing but that code, and the account number still never touches a table.
        //
        // Best-effort: a partner that cannot be paid out yet is a partner that can still
        // COLLECT, which is the more valuable half. The admin screen shows when this is
        // missing and offers to retry rather than failing the whole onboarding over it.
        $recipient = $payments->createTransferRecipient(
            $resolved['name'] !== '' ? $resolved['name'] : (string) $org->name,
            $accountNumber,
            $bankCode
        );

        DB::table('gates_partner_orgs')->where('id', $orgId)->update([
            'payout_recipient_code' => $recipient['ok'] ? $recipient['code'] : null,
            'subaccount_code'       => $created['code'],
            'settlement_bank'       => $bankCode,
            // Last four only. Enough for a human to recognise which account they chose,
            // useless to anybody who steals the table.
            'account_last4'         => $digits === '' ? null : substr($digits, -4),
            'account_name_resolved' => $resolved['name'],
            'settlement_schedule'   => in_array($settlementSchedule, ['auto','weekly','monthly','manual'], true)
                                        ? $settlementSchedule : 'auto',
            'platform_fee_bps'      => (int) round($platformFeePercent * 100),
            // Attaching an account never approves anybody. Approval is a human act with a
            // recorded reason, and it happens after somebody has read the name comparison.
            'status'                => (string) $org->status === self::STATUS_DRAFT
                                        ? self::STATUS_PENDING
                                        : (string) $org->status,
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);

        $strong = $score >= self::STRONG_MATCH;
        return [
            'ok'            => true,
            'match'         => $score,
            'resolved_name' => $resolved['name'],
            'needs_review'  => !$strong,
            'message'       => $strong
                ? 'Settlement account attached. The bank confirms it belongs to “' . $resolved['name'] . '”.'
                : 'Settlement account attached, but the bank says it belongs to “' . $resolved['name']
                  . '”, which does not closely match “' . $registered . '”. Someone should confirm that '
                  . 'is the organisation’s own account before this partner goes live.',
        ];
    }

    /**
     * How alike are two organisation names, 0..1?
     *
     * Normalised first, because the differences that matter are never punctuation: case,
     * ampersands, and the legal-form suffixes an account name carries and a registered name
     * often does not. What survives normalisation is compared with `similar_text`, which is
     * good enough for a signal whose only job is to decide whether a human should look.
     *
     * It is deliberately NOT a decision. Two unrelated charities working on the same thing
     * can score highly, and a legitimate trading name can score low.
     */
    public static function nameSimilarity(string $a, string $b): float
    {
        $a = self::normaliseName($a);
        $b = self::normaliseName($b);
        if ($a === '' || $b === '') return 0.0;
        if ($a === $b) return 1.0;

        // One containing the other covers "BRIGHT FUTURES" vs "BRIGHT FUTURES INITIATIVE",
        // which similar_text scores lower than it deserves.
        if (str_contains($a, $b) || str_contains($b, $a)) return 0.95;

        $pct = 0.0;
        similar_text($a, $b, $pct);
        return round($pct / 100, 4);
    }

    private static function normaliseName(string $s): string
    {
        $s = strtoupper(trim($s));
        $s = str_replace(['&', '-', '.', ',', '\'', '"', '/'], [' AND ', ' ', ' ', ' ', '', '', ' '], $s);
        // Legal forms and the words every third NGO shares. Removing them stops two
        // unrelated "…FOUNDATION"s scoring highly on the shared half of their names.
        $s = preg_replace('/\b(LTD|LIMITED|PLC|GTE|LBG|RC|IT|NIG|NIGERIA|INTL|INTERNATIONAL)\b/', ' ', $s) ?? $s;
        $s = preg_replace('/[^A-Z0-9 ]+/', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim((string) $s);
    }

    // ──────────────────────────────── vetting ───────────────────────────────

    /**
     * Approve, and record who said so.
     *
     * Refuses to approve an organisation with no settlement account, because an approved
     * organisation is one a donor can be shown — and a donate button with nowhere to settle
     * takes money it cannot deliver.
     *
     * @return array{ok:bool,message:string}
     */
    public static function approve(int $orgId, int $adminId, string $note = ''): array
    {
        $org = self::find($orgId);
        if (!$org) return ['ok' => false, 'message' => 'That organisation does not exist.'];

        if (trim((string) ($org->subaccount_code ?? '')) === '') {
            return ['ok' => false, 'message' => 'Attach a verified settlement account first — '
                                              . 'otherwise this partner gets a donate button with '
                                              . 'nowhere to send the money.'];
        }
        foreach (['cac_number' => 'a CAC registration number', 'scuml_number' => 'a SCUML number'] as $col => $what) {
            if (trim((string) ($org->{$col} ?? '')) === '') {
                return ['ok' => false, 'message' => 'This partner has no ' . $what . ' on file. '
                                                  . 'Both are legal requirements for a Nigerian '
                                                  . 'non-profit collecting donations, not paperwork.'];
            }
        }

        DB::table('gates_partner_orgs')->where('id', $orgId)->update([
            'status'           => self::STATUS_APPROVED,
            'vetted_by'        => $adminId,
            'vetted_at'        => date('Y-m-d H:i:s'),
            'vetting_note'     => $note !== '' ? $note : null,
            'suspended_reason' => null,
            'suspended_at'     => null,
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'message' => 'Approved. This partner can now receive donations.'];
    }

    /**
     * Stop an organisation collecting, today, without erasing its history.
     *
     * Separate from `rejected` because the CAC can restrict an incorporated trustee's
     * financial transactions between one week and the next, and a partner may need to stop
     * taking money while everything already given to them stays attributed and auditable.
     */
    public static function suspend(int $orgId, string $reason): array
    {
        if (!self::find($orgId)) return ['ok' => false, 'message' => 'That organisation does not exist.'];
        DB::table('gates_partner_orgs')->where('id', $orgId)->update([
            'status'           => self::STATUS_SUSPENDED,
            'suspended_reason' => $reason !== '' ? $reason : 'Suspended by an administrator.',
            'suspended_at'     => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'message' => 'Suspended. This partner can no longer receive donations.'];
    }

    // ──────────────────────────────── figures ───────────────────────────────

    /**
     * What an organisation has actually received, from confirmed rows only.
     *
     * `gross` is what donors gave; `platform_fee` is what split off to us; `net` is the
     * organisation's own share. Pending and failed rows are excluded — a dashboard that
     * counts money that has not arrived is a dashboard that causes an argument.
     *
     * @return array{gross:int,platform_fee:int,net:int,count:int}
     */
    public static function totals(int $orgId): array
    {
        $zero = ['gross' => 0, 'platform_fee' => 0, 'net' => 0, 'count' => 0];
        try {
            $row = DB::table('gates_donations')
                ->where('recipient_org_id', $orgId)
                ->where('status', 'confirmed')
                ->selectRaw('COALESCE(SUM(amount_naira),0) g, COALESCE(SUM(platform_fee_naira),0) f, COUNT(*) c')
                ->first();
        } catch (\Throwable) {
            return $zero;
        }
        if (!$row) return $zero;

        $g = (int) ($row->g ?? 0);
        $f = (int) ($row->f ?? 0);
        return ['gross' => $g, 'platform_fee' => $f, 'net' => max(0, $g - $f), 'count' => (int) ($row->c ?? 0)];
    }
}
