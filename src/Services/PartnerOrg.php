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

    /**
     * The two kinds of organisation this table holds.
     *
     * One table, because a donation partner and a vendor need the same five things: an
     * account in their own registered name, a subaccount created without storing that
     * account number, a dashboard scoped to their own rows, documents with expiries, and a
     * vetting state machine where suspension is distinct from rejection. A parallel
     * `gates_vendors` would mean writing all five twice and maintaining two of each.
     *
     * What differs is which documents are required, and that is a rule rather than a schema.
     */
    public const KIND_PARTNER = 'partner';
    public const KIND_VENDOR  = 'vendor';

    public const KINDS = [
        self::KIND_PARTNER => 'Donation partner',
        self::KIND_VENDOR  => 'Vendor',
    ];

    /**
     * And a vendor can be a person.
     *
     * ── WHY THIS MATTERS MORE THAN IT LOOKS ──────────────────────────────────
     *
     * Most of the people who will actually sell at an Africa GATES market are not companies.
     * They are one woman with a jollof stall, one man who prints t-shirts, a pair who make
     * sandals. Demanding a CAC registration before any of them can hold a pitch would not
     * raise the standard of the market — it would hand every place to whoever already has a
     * lawyer, and push everybody else to borrow somebody else's registration number, which
     * is strictly worse than having none: it puts the wrong name on the paperwork at exactly
     * the moment the paperwork matters.
     *
     * So the requirements branch on this, and only the requirements. The settlement account,
     * the subaccount, the dashboard, the documents, the vetting states are all identical.
     *
     * An organisation is always a business in this sense — an Incorporated Trustee is a
     * registered body, never a natural person — so `business` is the default and every row
     * written before this existed still means what it meant.
     */
    public const ENTITY_BUSINESS   = 'business';
    public const ENTITY_INDIVIDUAL = 'individual';

    public const ENTITIES = [
        self::ENTITY_BUSINESS   => 'Registered business',
        self::ENTITY_INDIVIDUAL => 'Individual or sole trader',
    ];

    /**
     * Every kind of document this platform files, in one place.
     *
     * Shared rather than redeclared per screen, because the admin upload form and
     * {@see requiredDocuments()} disagreeing about a slug means a vendor uploads the right
     * certificate and is still told it is missing.
     */
    public const DOCUMENT_KINDS = [
        'cac'       => 'CAC certificate',
        'scuml'     => 'SCUML certificate',
        'id'        => 'Government photo ID',
        'insurance' => 'Public liability insurance',
        'food'      => 'Food handling / hygiene certificate',
        'other'     => 'Other supporting document',
    ];

    public static function find(int $id): ?object
    {
        if ($id < 1) return null;
        return DB::table('gates_partner_orgs')->where('id', $id)->first();
    }

    public static function kindOf(?object $org): string
    {
        $k = (string) ($org->kind ?? self::KIND_PARTNER);
        return isset(self::KINDS[$k]) ? $k : self::KIND_PARTNER;
    }

    public static function entityOf(?object $org): string
    {
        $e = (string) ($org->entity_type ?? self::ENTITY_BUSINESS);
        return isset(self::ENTITIES[$e]) ? $e : self::ENTITY_BUSINESS;
    }

    /** A natural person rather than a registered body. */
    public static function isIndividual(?object $org): bool
    {
        return self::entityOf($org) === self::ENTITY_INDIVIDUAL;
    }

    /**
     * The name this party is legally known by — what the bank account should be in.
     *
     * For a business that is the CAC registered name; for a person it is their own full name.
     * Falling back to the display name covers the row where only one was ever given.
     */
    public static function legalNameOf(?object $org): string
    {
        $legal = trim((string) ($org->legal_name ?? ''));
        return $legal !== '' ? $legal : trim((string) ($org->name ?? ''));
    }

    /**
     * The documents this organisation must hold, by kind.
     *
     * ── WHY THE TWO LISTS DIFFER ─────────────────────────────────────────────
     *
     * SCUML registration is mandatory for an NGO collecting donations under the Money
     * Laundering (Prevention and Prohibition) Act 2022, and irrelevant to somebody selling
     * jewellery at a market. Public liability insurance is the reverse: essential for anybody
     * trading from a stand where the public walks past, and not something a donation partner
     * needs in order to receive a bank transfer.
     *
     * Demanding the union of both lists would look rigorous and would mostly teach applicants
     * that the requirements are not serious, because a third of them would not apply.
     *
     * ── AND WHY A PERSON IS NOT ASKED FOR A CAC CERTIFICATE ──────────────────
     *
     * A sole trader does not have one, and asking anyway has exactly two outcomes: the honest
     * ones do not apply, and the rest borrow a number. What replaces it is a photo ID, for an
     * operational reason rather than a regulatory one — on the morning of the market somebody
     * has to check that the person at the pitch is the person it was allocated to, and a
     * company registration number does not help with that.
     *
     * @return array<string,string> slug => human label
     */
    public static function requiredDocuments(int $orgId): array
    {
        $org = self::find($orgId);
        if (!$org) return [];

        if (self::kindOf($org) === self::KIND_VENDOR) {
            return self::isIndividual($org)
                ? [
                    'id'        => 'Government photo ID',
                    'insurance' => 'Public liability insurance',
                  ]
                : [
                    'cac'       => 'CAC registration',
                    'insurance' => 'Public liability insurance',
                  ];
        }

        return [
            'cac'   => 'CAC certificate (Incorporated Trustees)',
            'scuml' => 'SCUML certificate',
        ];
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

    // ────────────────────────── self-registration ───────────────────────────

    /**
     * A vendor signs themselves up from the public application form.
     *
     * ── WHY THIS EXISTS AT ALL ───────────────────────────────────────────────
     *
     * The alternative is that every vendor is typed in by an administrator, which sounds
     * careful and is actually the least fair option available: it means the only people who
     * can apply are the ones who already know somebody. A published form that anybody can
     * fill in is the thing that makes the allocation rules in §5 of the specification worth
     * having, because a fair rule applied to a hand-picked pool is still a hand-picked pool.
     *
     * ── AND WHY IT CREATES ALMOST NOTHING ────────────────────────────────────
     *
     * The row lands as a DRAFT with `self_registered` set. Nobody has met these people. They
     * can sign in, upload their certificates and see their own application — and that is the
     * entire extent of it. They cannot collect money, cannot appear publicly, and cannot be
     * offered a stand until an administrator has read the record and approved it. Registering
     * buys an applicant a place in the queue and nothing else, which is exactly what it
     * should buy.
     *
     * The password is chosen by the applicant here rather than generated, unlike the admin
     * path: a person who has just typed their details into a public form and been handed a
     * random string will not have it tomorrow, and a vendor locked out of their own
     * application generates a support ticket instead of a stall.
     *
     * @return array{ok:bool,message:string,org_id:int,user:?object}
     */
    public static function registerVendor(array $in): array
    {
        $fail = ['ok' => false, 'org_id' => 0, 'user' => null];

        $name  = trim((string) ($in['name'] ?? ''));
        $legal = trim((string) ($in['legal_name'] ?? ''));
        $email = strtolower(trim((string) ($in['contact_email'] ?? '')));
        $pass  = (string) ($in['password'] ?? '');

        $entity = (string) ($in['entity_type'] ?? self::ENTITY_BUSINESS);
        if (!isset(self::ENTITIES[$entity])) $entity = self::ENTITY_BUSINESS;

        if ($name === '') {
            return $fail + ['message' => $entity === self::ENTITY_INDIVIDUAL
                ? 'Give the name you trade under — it can be your own name.'
                : 'Give the name of the business.'];
        }
        if ($legal === '') {
            return $fail + ['message' => $entity === self::ENTITY_INDIVIDUAL
                ? 'Give your full name as it appears on your bank account and your ID.'
                : 'Give the registered name of the business exactly as it appears at the CAC.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $fail + ['message' => 'That is not a valid email address.'];
        }
        if (\AfricaGates\Services\OrgAuth::findByEmail($email)) {
            // Deliberately explicit, unlike the sign-in screen. This is a registration form,
            // where "that address is taken" is information the person needs in order to
            // proceed, and where they can discover it in one attempt anyway.
            return $fail + ['message' => 'That email address already has a sign-in. '
                                       . 'Sign in and apply from your dashboard instead.'];
        }
        if (strlen($pass) < 12) {
            return $fail + ['message' => 'Use a password of at least 12 characters — this sign-in '
                                       . 'can later request payouts.'];
        }
        // A registered business must say what it is registered as. An individual is not
        // asked, because they have nothing to give and asking anyway invites a borrowed number.
        $cac = trim((string) ($in['cac_number'] ?? ''));
        if ($entity === self::ENTITY_BUSINESS && $cac === '') {
            return $fail + ['message' => 'Give the CAC registration number of the business, or '
                                       . 'apply as an individual if you are not registered.'];
        }

        $slug = self::uniqueSlug($name);
        if ($slug === '') return $fail + ['message' => 'That name does not make a usable web address.'];

        $orgId = (int) DB::table('gates_partner_orgs')->insertGetId([
            'slug'            => $slug,
            'name'            => mb_substr($name, 0, 200),
            'legal_name'      => mb_substr($legal, 0, 200),
            'kind'            => self::KIND_VENDOR,
            'entity_type'     => $entity,
            'self_registered' => 1,
            'cac_number'      => $entity === self::ENTITY_BUSINESS ? mb_substr($cac, 0, 60) : null,
            'contact_name'    => mb_substr(trim((string) ($in['contact_name'] ?? '')), 0, 160) ?: null,
            'contact_email'   => mb_substr($email, 0, 190),
            'contact_phone'   => mb_substr(trim((string) ($in['contact_phone'] ?? '')), 0, 40) ?: null,
            'description'     => mb_substr(trim((string) ($in['description'] ?? '')), 0, 2000) ?: null,
            'status'          => self::STATUS_DRAFT,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        $u = \AfricaGates\Services\OrgAuth::createUser(
            $orgId, $email, $pass, trim((string) ($in['contact_name'] ?? '')), 'owner'
        );
        if (!$u['ok']) {
            // Nothing half-built is left behind. An organisation nobody can sign into is a
            // row an administrator will eventually approve by accident.
            DB::table('gates_partner_orgs')->where('id', $orgId)->delete();
            return $fail + ['message' => $u['message']];
        }

        return [
            'ok'      => true,
            'org_id'  => $orgId,
            'user'    => \AfricaGates\Services\OrgAuth::findByEmail($email),
            'message' => 'Account created.',
        ];
    }

    /**
     * An organisation applies to collect gifts through Africa GATES.
     *
     * ── WHY THE GIFT PAGE NEEDED AN APPLICATION FORM ─────────────────────────
     *
     * Every organisation on the platform was typed in by an administrator, which meant the
     * only bodies that could ever raise money here were the ones that already knew somebody.
     * A published form does not lower the bar — the vetting in {@see approve()} is unchanged,
     * and CAC and SCUML are still both mandatory — it changes WHO GETS TO REACH the bar.
     *
     * ── AND WHY IT BUYS ALMOST NOTHING ───────────────────────────────────────
     *
     * The row lands as a DRAFT with `self_registered` set. They can sign in, upload their
     * certificates and read their own decision. They cannot collect, cannot appear publicly,
     * and cannot be approved until somebody has read the record and attached a settlement
     * account. Applying buys a place in a queue, which is exactly what it should buy.
     *
     * @return array{ok:bool,message:string,org_id:int,user:?object}
     */
    public static function registerPartner(array $in): array
    {
        $fail = ['ok' => false, 'org_id' => 0, 'user' => null];

        $name  = trim((string) ($in['name'] ?? ''));
        $legal = trim((string) ($in['legal_name'] ?? ''));
        $email = strtolower(trim((string) ($in['contact_email'] ?? '')));
        $pass  = (string) ($in['password'] ?? '');
        $cac   = trim((string) ($in['cac_number'] ?? ''));

        if ($name === '')  return $fail + ['message' => 'Give the name supporters will recognise you by.'];
        if ($legal === '') return $fail + ['message' => 'Give the registered name exactly as it appears at the CAC.'];
        if ($cac === '') {
            // Not negotiable, and not the same rule as a vendor's. A body collecting
            // charitable gifts in Nigeria has to be incorporated — an unregistered group
            // asking the public for money is the thing this platform must never launder.
            return $fail + ['message' => 'A CAC registration number is required. Only an '
                                       . 'incorporated body may collect charitable gifts in Nigeria.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $fail + ['message' => 'That is not a valid email address.'];
        }
        if (\AfricaGates\Services\OrgAuth::findByEmail($email)) {
            return $fail + ['message' => 'That email address already has a sign-in. '
                                       . 'Sign in and continue from your dashboard.'];
        }
        if (strlen($pass) < 12) {
            return $fail + ['message' => 'Use a password of at least 12 characters — this sign-in '
                                       . 'can later request payouts.'];
        }

        $slug = self::uniqueSlug($name);
        if ($slug === '') return $fail + ['message' => 'That name does not make a usable web address.'];

        $orgId = (int) DB::table('gates_partner_orgs')->insertGetId([
            'slug'            => $slug,
            'name'            => mb_substr($name, 0, 200),
            'legal_name'      => mb_substr($legal, 0, 200),
            'kind'            => self::KIND_PARTNER,
            // A donation partner is always a body, never a person. Enforced here so nothing
            // downstream has to check the pair.
            'entity_type'     => self::ENTITY_BUSINESS,
            'self_registered' => 1,
            'cac_number'      => mb_substr($cac, 0, 60),
            'scuml_number'    => mb_substr(trim((string) ($in['scuml_number'] ?? '')), 0, 60) ?: null,
            'contact_name'    => mb_substr(trim((string) ($in['contact_name'] ?? '')), 0, 160) ?: null,
            'contact_email'   => mb_substr($email, 0, 190),
            'contact_phone'   => mb_substr(trim((string) ($in['contact_phone'] ?? '')), 0, 40) ?: null,
            'description'     => mb_substr(trim((string) ($in['description'] ?? '')), 0, 2000) ?: null,
            'status'          => self::STATUS_DRAFT,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        $u = \AfricaGates\Services\OrgAuth::createUser(
            $orgId, $email, $pass, trim((string) ($in['contact_name'] ?? '')), 'owner'
        );
        if (!$u['ok']) {
            DB::table('gates_partner_orgs')->where('id', $orgId)->delete();
            return $fail + ['message' => $u['message']];
        }

        return ['ok' => true, 'org_id' => $orgId,
                'user' => \AfricaGates\Services\OrgAuth::findByEmail($email),
                'message' => 'Application received.'];
    }

    /**
     * What the platform has done for organisations, in two numbers.
     *
     * ── WHY THESE TWO AND NOT A DASHBOARD ────────────────────────────────────
     *
     * A stranger deciding whether to apply is asking one question — has anybody actually got
     * money out of this? — and a stranger deciding whether to give is asking the same one
     * from the other side. "Twelve organisations, ₦4.2m raised" answers it. A grid of six
     * metrics does not; it reads as marketing.
     *
     * Counted from confirmed rows every time and never cached. A cached figure on a page
     * about other people's money is a number that is eventually wrong in public, and the
     * query is two aggregates over an indexed column.
     *
     * @return array{orgs:int,approved:int,raised:int,gifts:int}
     */
    public static function platformTotals(): array
    {
        $out = ['orgs' => 0, 'approved' => 0, 'raised' => 0, 'gifts' => 0];

        try {
            // Every organisation that has APPLIED, not only the ones that got through. The
            // honest denominator: a page that counts only successes is reporting a rate of
            // 100% for a process that refuses people.
            $out['orgs'] = (int) DB::table('gates_partner_orgs')
                ->where('kind', self::KIND_PARTNER)->count();
            $out['approved'] = (int) DB::table('gates_partner_orgs')
                ->where('kind', self::KIND_PARTNER)
                ->where('status', self::STATUS_APPROVED)->count();
        } catch (\Throwable) {
            // A missing column on a database that has not been migrated yet is a zero, not
            // a five-hundred on the public page that asks people for money.
        }

        try {
            $row = DB::table('gates_donations')
                ->whereNotNull('recipient_org_id')
                ->where('status', 'confirmed')
                ->selectRaw('COALESCE(SUM(amount_naira),0) g, COUNT(*) c')
                ->first();
            $out['raised'] = (int) ($row->g ?? 0);
            $out['gifts']  = (int) ($row->c ?? 0);
        } catch (\Throwable) {
        }

        return $out;
    }

    /** A slug nobody else holds. Suffixed rather than refused — two "Mama's Kitchen"s exist. */
    private static function uniqueSlug(string $name): string
    {
        $base = \AfricaGates\Support\Slug::make($name, 110);
        if ($base === '') return '';

        $slug = $base;
        for ($i = 2; $i < 60; $i++) {
            if (!DB::table('gates_partner_orgs')->where('slug', $slug)->exists()) return $slug;
            $slug = $base . '-' . $i;
        }
        return $base . '-' . bin2hex(random_bytes(3));
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

        // 2 · Is that plausibly this party?
        //
        // Routed through matchScore rather than nameSimilarity directly, because a person's
        // account is compared by a different rule — see personNameSimilarity(). Getting this
        // wrong would score every legitimate sole trader as the fraud case.
        $registered = self::legalNameOf($org);
        $score      = self::matchScore($org, $resolved['name']);

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
     * The right name comparison for this party, 0..1.
     *
     * One entry point so the onboarding path and the admin screen can never disagree about
     * how a score was reached — a screen that recomputes a match with the other rule would
     * show a number nobody could reproduce.
     */
    public static function matchScore(?object $org, string $resolvedName): float
    {
        $registered = self::legalNameOf($org);
        return self::isIndividual($org)
            ? self::personNameSimilarity($registered, $resolvedName)
            : self::nameSimilarity($registered, $resolvedName);
    }

    /**
     * How alike are two PEOPLE's names, 0..1?
     *
     * ── WHY THIS CANNOT BE THE ORGANISATION RULE ─────────────────────────────
     *
     * A Nigerian bank returns "OKAFOR NGOZI CHIOMA". The same woman writes "Ngozi Okafor".
     * Character-similarity scores that pair around 0.5 — squarely in the range this platform
     * treats as "someone is collecting into a stranger's account". Run over the whole vendor
     * list, an organisation rule applied to people would flag nearly every honest sole trader
     * and teach reviewers to click past the warning, which is how the warning stops working
     * for the one case it exists for.
     *
     * So names are compared as a SET of parts, not a string:
     *
     *   · order is irrelevant — surname first is the bank's convention, not a discrepancy;
     *   · an extra part on the bank's side is expected — middle names live on bank records
     *     and rarely on forms, so containment scores high;
     *   · a single initial matches the part it abbreviates, because "OKAFOR N C" is a real
     *     thing a bank returns;
     *   · titles are stripped — MRS is not a name, and half of Nigerian bank records carry one.
     *
     * The score weights recall over precision: every part the person claimed must be on the
     * account, while parts the account carries that they did not mention cost comparatively
     * little. Claiming a name that is not on the account is the suspicious direction.
     *
     * Like its sibling it is a SIGNAL, not a decision. Two brothers share a surname and a
     * married couple share an account; only a human can settle those.
     */
    public static function personNameSimilarity(string $stated, string $resolved): float
    {
        $want = self::nameParts($stated);
        $got  = self::nameParts($resolved);
        if ($want === [] || $got === []) return 0.0;

        $hits      = 0;
        $remaining = $got;
        foreach ($want as $part) {
            foreach ($remaining as $i => $candidate) {
                if (self::partsMatch($part, $candidate)) {
                    $hits++;
                    // Consumed, so "OKAFOR OKAFOR" cannot score twice against one "OKAFOR".
                    unset($remaining[$i]);
                    break;
                }
            }
        }
        if ($hits === 0) return 0.0;

        $recall    = $hits / count($want);
        $precision = $hits / count($got);
        return round($recall * 0.75 + $precision * 0.25, 4);
    }

    /**
     * A person's name split into comparable parts, titles removed.
     *
     * @return array<int,string>
     */
    private static function nameParts(string $s): array
    {
        $s = strtoupper(trim($s));
        $s = preg_replace('/[^A-Z ]+/', ' ', $s) ?? $s;

        // Titles, honorifics and the religious and traditional prefixes that appear on
        // Nigerian bank records constantly. None of them identify anybody.
        static $titles = [
            'MR', 'MRS', 'MS', 'MISS', 'DR', 'PROF', 'ENGR', 'BARR', 'REV', 'PASTOR',
            'IMAM', 'ALHAJI', 'ALHAJA', 'MALLAM', 'CHIEF', 'OTUNBA', 'OLORI', 'HRH', 'SIR',
        ];

        $out = [];
        foreach (preg_split('/\s+/', (string) $s) ?: [] as $part) {
            $part = trim($part);
            if ($part === '' || in_array($part, $titles, true)) continue;
            $out[] = $part;
        }
        return $out;
    }

    /** Two name parts, where a single letter is an initial standing for a longer part. */
    private static function partsMatch(string $a, string $b): bool
    {
        if ($a === $b) return true;
        if (strlen($a) === 1) return str_starts_with($b, $a);
        if (strlen($b) === 1) return str_starts_with($a, $b);
        return false;
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
        // ── WHAT MUST BE ON FILE DEPENDS ON WHAT THIS PARTY IS ──────────────
        //
        // A vendor selling jewellery has no reason to hold a SCUML certificate, and a sole
        // trader has no CAC number to give. Demanding either anyway teaches applicants that
        // the requirements are decorative, which is how a real requirement gets ignored too.
        if (self::isIndividual($org) && self::kindOf($org) === self::KIND_VENDOR) {
            // A person is identified by their name and by a bank having already verified it.
            // There is no NIN column to check because there is deliberately no NIN column:
            // opening a Nigerian account requires a BVN, so /bank/resolve answering with this
            // person's name is a regulated institution's identity check, fresher and better
            // evidenced than a number typed into a form — and it leaves no register of
            // Nigerians' identifiers here to be stolen.
            if (self::legalNameOf($org) === '') {
                return ['ok' => false, 'message' => 'This vendor has no full legal name on file. '
                    . 'For an individual that is the name the settlement account is checked '
                    . 'against, so approving without it checks nothing.'];
            }
            if (trim((string) ($org->account_name_resolved ?? '')) === '') {
                return ['ok' => false, 'message' => 'The bank has not confirmed who owns this '
                    . 'vendor’s settlement account. For an individual that confirmation is the '
                    . 'identity check — re-attach the account so it can be resolved.'];
            }
        } else {
            $needed = self::kindOf($org) === self::KIND_VENDOR
                ? ['cac_number' => 'a CAC registration number']
                : ['cac_number' => 'a CAC registration number', 'scuml_number' => 'a SCUML number'];

            foreach ($needed as $col => $what) {
                if (trim((string) ($org->{$col} ?? '')) === '') {
                    return ['ok' => false, 'message' => 'This organisation has no ' . $what . ' on file. '
                        . (self::kindOf($org) === self::KIND_VENDOR
                            ? 'A registered business trading at an event must give its registration '
                            . 'number — or be recorded as an individual, which has its own requirements.'
                            : 'CAC and SCUML are both legal requirements for a Nigerian non-profit '
                            . 'collecting donations, not paperwork.')];
                }
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
