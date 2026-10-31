<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Name;
use AfricaGates\Support\Phone;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Everything that must happen after a nomination is stored.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A SERVICE AND NOT A CONTROLLER METHOD
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * There are TWO doors a nomination comes through, and only one of them did any of
 * this. {@see \AfricaGates\Controllers\NominationController} — the web form — sent
 * the operator brief, the nominator's confirmation, the nominee's notification, the
 * SMS and WhatsApp, queued the AI triage and fired the webhook, all inline. Its own
 * comment described itself as "the only door nominations come through".
 *
 * It is not. {@see \AfricaGates\Controllers\ApiController::submitNomination()} is a
 * live public endpoint at POST /api/nominations, and it inserted the row and
 * returned `ok`. Nothing else. So a nomination arriving through the API was
 * invisible: no operator was told it existed, the nominator got no confirmation and
 * no reference, the nominee never learned they had been nominated, no triage was
 * queued for the review desk, and no webhook fired. It sat in the table waiting for
 * somebody to notice it by chance.
 *
 * Duplicating ninety lines into the second controller would have fixed today's
 * symptom and guaranteed the next divergence, so the work moved here instead. A
 * third door — an import, a partner integration — now gets it by calling one method.
 *
 * ── AND IT READS THE STORED REFERENCE RATHER THAN RECOMPUTING IT ─────────────
 *
 * A bug found while extracting this, invisible in the current calendar year.
 * {@see \AfricaGates\Support\Reference::nomination()} takes an optional year and
 * defaults it to `date('Y')`. AwardService persists the reference using the CYCLE's
 * year; the controller recomputed it with no year at all. Those agree only while the
 * cycle year happens to equal the current year — so a cycle labelled for next year,
 * or a nomination submitted on the 31st of December, told the nominator one
 * reference and stored another.
 *
 * Every surface here quotes that reference: the operator brief, the confirmation
 * email, both SMS messages, the webhook and the success page. A reference that does
 * not match the record is worse than no reference, because support looks it up,
 * finds nothing, and tells a real nominator their entry does not exist.
 *
 * The row already holds the right answer. This reads it.
 */
final class NominationAftercare
{
    /**
     * @param array<string,mixed> $data the submitted form fields
     * @param array{evidence?:string, photo?:string} $files already-stored upload URLs
     * @return array{reference:string, nominee:string, category:string, nominee_email:string}
     *         the facts a caller needs for its own response — the web form flashes
     *         them onto the success page, the API returns them as JSON.
     */
    public static function run(
        array $data,
        int $nominationId,
        string $baseUrl,
        ?OtpService $mailer = null,
        array $files = [],
        ?GoogleSheetsService $sheets = null,
    ): array {
        // Normalised HERE, at the one place all doors pass through, so the reviewer
        // sees the tidy version and every surface downstream — ballot, registry,
        // flier, OG card, the receipt email — inherits it without a display filter
        // that the next new template forgets to apply. Forms get filled in on phones
        // with caps lock on: the same person arrived as ADA OKONKWO, ada okonkwo and
        // Ada Okonkwo, and the ballot rendered all three. See Support\Name.
        $nomName  = Name::title((string) ($data['nominee_name'] ?? ''));
        $nomEmail = strtolower(trim((string) ($data['nominee_email'] ?? '')));
        $byName   = Name::title((string) ($data['nominator_name'] ?? ''));
        $byEmail  = strtolower(trim((string) ($data['nominator_email'] ?? '')));
        // Each category's title, so a reason in the brief and the Sheets row says which
        // category it argues for (NominationRules::reasonText).
        $catTitles = [];
        $pickedIds = array_keys(\AfricaGates\Services\NominationRules::categories($data));
        if ($pickedIds !== []) {
            try {
                $catTitles = array_map('strval', \Illuminate\Database\Capsule\Manager::table('gates_award_categories')
                    ->whereIn('id', $pickedIds)->pluck('title', 'id')->all());
            } catch (\Throwable) {}
        }
        /* ── THE AWARD'S REAL NAME, LOOKED UP WHEN IT IS NOT HANDED OVER ───────
           This fell back to `'Programme #' . $id`, and that string is not a label —
           it goes into the confirmation EMAIL, the SMS and the success page. A
           nominator who had just written about somebody was told their nomination was
           filed under "Programme #1". The fallback reads like data, which is what
           made it survive: nothing looks broken, it just says the wrong thing.

           The id is in hand either way, so the title is a lookup rather than a guess.
           The numbered form stays as the last resort for a programme row that is
           genuinely gone — an import, a deleted award — because an empty sentence is
           worse than an honest placeholder. */
        $progName = trim((string) ($data['programme_title'] ?? ''));
        if ($progName === '' && !empty($data['programme_id'])) {
            try {
                $progName = trim((string) (DB::table('gates_award_programmes')
                    ->where('id', (int) $data['programme_id'])->value('title') ?? ''));
            } catch (\Throwable) {}
        }
        if ($progName === '') $progName = 'Programme #' . (int) ($data['programme_id'] ?? 0);
        $watchUrl = rtrim($baseUrl, '/') . '/leaderboard';

        $reference = self::reference($nominationId);

        /* ── EVERY CATEGORY, NOT THE FIRST ────────────────────────────────────
           A nomination names two or three categories now, and they live in
           `gates_nomination_categories`. This read `category_id` off the nomination
           row — the denormalised copy of the FIRST one — so the email, the SMS and
           the success page each named one of the three and said nothing about the
           others. The nominator chose them deliberately and is told their nomination
           went somewhere narrower than it did.

           The row's own `category_id` is the fallback, for a nomination taken before
           the table existed. */
        $catNames = [];
        try {
            $catNames = DB::table('gates_nomination_categories as nc')
                ->join('gates_award_categories as c', 'c.id', '=', 'nc.category_id')
                ->where('nc.nomination_id', $nominationId)
                ->orderBy('nc.sort_order')->orderBy('nc.id')
                ->pluck('c.title')->all();
        } catch (\Throwable) { $catNames = []; }

        if ($catNames === [] && !empty($data['category_id'])) {
            try {
                $one = (string) (DB::table('gates_award_categories')
                    ->where('id', (int) $data['category_id'])->value('title') ?? '');
                if ($one !== '') $catNames = [$one];
            } catch (\Throwable) {}
        }

        $catName = self::listOf($catNames);
        $catLine = $catName !== '' ? ($progName . ' · ' . $catName) : $progName;

        self::email($data, $mailer, $files, $baseUrl, $reference,
                    $nomName, $nomEmail, $byName, $byEmail, $catLine, $watchUrl, $catTitles);
        self::text($data, $reference, $nomName, $nomEmail, $catLine, $watchUrl);

        // Advisory AI triage (score / summary / duplicates) for the review desk.
        try { NominationTriageService::enqueue($nominationId); } catch (\Throwable) {}

        // And which category the work actually belongs in, for the review desk and the
        // judging panel. QUEUED HERE rather than in either controller, for the reason
        // this whole class exists: there are two nomination doors, and the last thing
        // queued from only one of them was the triage itself — a nomination arriving at
        // the API sat in the table with no score, no summary and no duplicate check
        // until somebody happened to look at it.
        try { NominationCategoryFit::enqueue($nominationId); } catch (\Throwable) {}

        // ── the operator's own spreadsheet ──────────────────────────────────
        //
        // GoogleSheetsService has had a pushNomination() since it was written and
        // nothing ever called it, while pushRegistration() IS called from the registry
        // form. So an operator who followed the setup note in that class — deploy the
        // Apps Script, put its /exec URL in GAS_URL — watched registrations arrive and
        // a `nominations` tab stay empty forever, with no error to explain it. The tab
        // is declared in config/AfricaGATES_AppScript.gs; only the writer was missing.
        //
        // Six fields, not the whole submission. That is exactly what the deployed
        // script reads for this sheet, so sending more would put the nominator's phone
        // and address on the wire to be discarded on arrival. `reference` is included
        // even though the current script ignores unknown keys and has no column for
        // it — harmless today, and there if the operator ever adds the column.
        //
        // Nothing here is a new disclosure: the same facts already go to the operator's
        // inbox in the brief above. This is the same audience, in the place they asked
        // for it.
        try {
            $sheets?->pushNomination([
                'programme_id'    => (int) ($data['programme_id'] ?? 0),
                'nominee_name'    => $nomName,
                'country_code'    => strtoupper((string) ($data['country_code'] ?? '')),
                'reason'          => \AfricaGates\Services\NominationRules::reasonText($data, $catTitles),
                'nominator_name'  => $byName,
                'nominator_email' => $byEmail,
                'reference'       => $reference,
            ]);
        } catch (\Throwable) {}

        // ids + labels only, never raw contact details.
        try {
            WebhookService::dispatch('nomination.submitted', [
                'nomination_id' => $nominationId,
                'reference'     => $reference,
                'nominee'       => $nomName,
                'programme'     => $progName,
                'category_id'   => (int) ($data['category_id'] ?? 0),
                'category'      => $catName,
                'country'       => strtoupper((string) ($data['country_code'] ?? '')),
                'has_email'     => $nomEmail !== '',
                'has_phone'     => trim((string) ($data['nominee_phone'] ?? '')) !== '',
            ]);
        } catch (\Throwable) {}

        return ['reference' => $reference, 'nominee' => $nomName,
                'category' => $catLine, 'nominee_email' => $nomEmail];
    }

    /**
     * The reference actually on the record.
     *
     * Read, not recomputed — see the class note. Falls back to computing one only
     * when the column does not exist on an unmigrated database, which is the single
     * case where there is nothing stored to disagree with.
     */
    /**
     * "A", "A and B", or "A, B and C" — the way a person would say a short list.
     *
     * One place, because this line is read in three: the confirmation email, the SMS
     * and the success page. Three joins is three chances for one of them to come out
     * as "Teaching,Leadership".
     *
     * @param list<string> $items
     */
    private static function listOf(array $items): string
    {
        $items = array_values(array_filter(array_map('trim', $items), static fn ($v) => $v !== ''));
        $n = count($items);

        if ($n === 0) return '';
        if ($n === 1) return $items[0];

        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }

    private static function reference(int $nominationId): string
    {
        try {
            $stored = DB::table('gates_nominations')->where('id', $nominationId)->value('reference');
            if (is_string($stored) && trim($stored) !== '') return trim($stored);
        } catch (\Throwable) {}

        try {
            return \AfricaGates\Support\Reference::nomination($nominationId);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Three emails: the operator brief, the nominator's confirmation, the nominee's
     * notification. Each wrapped, because a mail failure must never fail a
     * nomination that is already stored.
     */
    private static function email(
        array $data, ?OtpService $mailer, array $files, string $baseUrl, string $reference,
        string $nomName, string $nomEmail, string $byName, string $byEmail,
        string $catLine, string $watchUrl, array $catTitles = [],
    ): void {
        if ($mailer === null) return;

        $esc      = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $base     = rtrim($baseUrl, '/');
        $evidence = trim((string) ($files['evidence'] ?? ''));
        $photo    = trim((string) ($files['photo'] ?? ''));

        // ── 1 · operators ────────────────────────────────────────────────────
        $rows = [
            'Nominee'             => $nomName,
            'Category'            => $catLine,
            'Country'             => strtoupper((string) ($data['country_code'] ?? '')),
            'State / LGA'         => trim((string) ($data['nominee_state'] ?? '')) . ' / ' . trim((string) ($data['nominee_lga'] ?? '')),
            'Organisation'        => trim((string) ($data['nominee_org'] ?? '')) ?: '—',
            'Nominee email'       => $nomEmail ?: '—',
            'Nominee phone'       => trim((string) ($data['nominee_phone'] ?? '')) ?: '—',
            'Nominator'           => $byName . ' <' . $byEmail . '>',
            'Nominator phone'     => trim((string) ($data['nominator_phone'] ?? '')),
            'Nominator age range' => trim((string) ($data['nominator_age_range'] ?? '')) ?: '—',
            // How they know the nominee, and whether the nominee may be told who they are.
            'Knows them as'       => \AfricaGates\Services\NominationRules::RELATIONS[\AfricaGates\Services\NominationRules::relation($data['nominator_relation'] ?? null) ?? ''] ?? '—',
            'Name kept private'   => !empty($data['nominator_private']) ? 'Yes — the nominee is not told who nominated them' : 'No',
            'Nominator location'  => trim((string) ($data['nominator_state'] ?? '')) . ', '
                                   . trim((string) ($data['nominator_lga'] ?? '')) . ', '
                                   . strtoupper((string) ($data['nominator_country'] ?? '')),
            'Reference'           => $reference,
        ];
        $tbl = '';
        foreach ($rows as $k => $v) {
            $tbl .= '<tr><td style="padding:6px 16px 6px 0;color:#6b7674;font-size:13px;white-space:nowrap;vertical-align:top">' . $esc($k)
                  . '</td><td style="padding:6px 0;color:#10292c;font-size:14px;font-weight:600">' . $esc($v) . '</td></tr>';
        }
        $adminHtml = '<p>A new nomination has been submitted and is awaiting review.</p>'
            . '<table style="border-collapse:collapse;margin:6px 0 16px">' . $tbl . '</table>'
            . '<p style="margin:0 0 5px;color:#6b7674;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.04em">Reason</p>'
            . '<div style="background:#f6f7f6;border-radius:10px;padding:12px 14px;font-size:14px;line-height:1.6;color:#10292c;white-space:pre-wrap">'
            . $esc(\AfricaGates\Services\NominationRules::reasonText($data, $catTitles)) . '</div>'
            . ($evidence ? '<p style="font-size:13px;margin-top:12px">Supporting document: ' . $esc($evidence) . '</p>' : '')
            . ($photo ? '<p style="font-size:13px">Photo: ' . $esc($photo) . '</p>' : '')
            . '<p style="margin-top:16px"><a href="' . $esc($base . '/admin/nominations') . '" style="color:#237b22;font-weight:600">Review in the admin console &rarr;</a></p>';
        if (Notifier::adminEmail() !== '') try {
            $mailer->sendBranded(Notifier::adminEmail(), 'New nomination · ' . $nomName,
                                 $adminHtml, strip_tags($adminHtml), 'Nominations');
        } catch (\Throwable) {}

        // ── 2 and 3 · the nominator and the nominee: the handoff's emails ─────
        //
        // What they promise is read from what makes it true: the review time is the
        // setting the acknowledgement mail is sent against (NominationFeedbackService::
        // slaHours()), the voting date is the cycle's own, and a nominator who asked to
        // stay private is never named to the nominee (nominator_private).
        $cycle = null;
        try {
            if (!empty($data['cycle_id'])) {
                $cycle = DB::table('gates_award_cycles')->where('id', (int) $data['cycle_id'])->first(['voting_open']);
            }
        } catch (\Throwable) {}
        $votingOpens = '';
        if ($cycle && !empty($cycle->voting_open)) {
            try { $votingOpens = \Illuminate\Support\Carbon::parse((string) $cycle->voting_open)->format('j F Y'); } catch (\Throwable) {}
        }
        $progOnly = trim((string) ($data['programme_title'] ?? '')) ?: (string) explode(' · ', $catLine)[0];
        $nomFirst = \AfricaGates\Services\DoorWelcome::firstName($nomName) ?: $nomName;
        $nomPhone = trim((string) ($data['nominee_phone'] ?? ''));

        if ($byEmail !== '' && filter_var($byEmail, FILTER_VALIDATE_EMAIL)) {
            try {
                \AfricaGates\Services\Mail\Emails::send($mailer, $byEmail, 'nom-in', [
                    'programme'          => $progOnly,
                    'nominee'            => $nomName,
                    'nominee_first_name' => $nomFirst,
                    'category'           => (string) (explode(' · ', $catLine, 2)[1] ?? $catLine),
                    'reference'          => $reference,
                    'decide_by'          => \Illuminate\Support\Carbon::now()
                        ->addHours(\AfricaGates\Services\NominationFeedbackService::slaHours())->format('l j F'),
                    'nominee_contacted'  => $nomEmail !== '' || $nomPhone !== '',
                    'voting_opens'       => $votingOpens,
                    'watch_url'          => $watchUrl,
                    'site_url'           => $base,
                ]);
            } catch (\Throwable) {}
        }

        if ($nomEmail !== '' && filter_var($nomEmail, FILTER_VALIDATE_EMAIL)) {
            // ── NOTHING THE NOMINATOR TYPED, UNTIL A PERSON HAS READ IT ──────────
            //
            // This goes out the moment the public form is submitted, to whatever address the
            // nominator typed, under the Africa GATES masthead and signature. Quoting their
            // reason (or naming them) here relays a stranger's text through our brand before
            // anyone has looked at it: "pay the ₦20,000 processing fee at … to receive your
            // award" would arrive looking like ours, which is exactly the forgery the voting
            // letter tells nominees to report. Nothing they wrote is sent to the nominee
            // from here; NominationAftercareTest holds that.
            try {
                \AfricaGates\Services\Mail\Emails::send($mailer, $nomEmail, 'nominated', [
                    'programme'  => $progOnly,
                    'first_name' => $nomFirst,
                    'quote'      => '',
                    'quote_by'   => '',
                    'confirm_url' => '',
                    'watch_url'  => $watchUrl,
                    'help_url'   => $base . '/support',
                    'site_url'   => $base,
                ]);
            } catch (\Throwable) {}
        }
    }

    /**
     * SMS / WhatsApp — best-effort, admin-configured, off by default.
     *
     * Spec: email → email flow; phone → SMS then WhatsApp; both contacts → email +
     * SMS; WhatsApp always sends when configured and a phone exists. Failures audit
     * and re-queue inside SmsService, and never block the nomination.
     */
    private static function text(
        array $data, string $reference, string $nomName, string $nomEmail,
        string $catLine, string $watchUrl,
    ): void {
        try {
            $sms = SmsService::boot();
            if (!$sms->configured()) return;

            $nomPhone = Phone::normalize((string) ($data['nominee_phone'] ?? ''),
                                         (string) ($data['country_code'] ?? ''));
            if ($nomPhone !== null) {
                $plan = SmsService::channelPlan($nomEmail ?: null, $nomPhone, $sms);
                $msg  = 'Africa GATES: ' . $nomName . ', you have been nominated for ' . $catLine
                      . ' (ref ' . $reference . '). Our panel reviews every profile before it goes live. ' . $watchUrl;
                if (in_array('sms', $plan, true))      $sms->sendSms($nomPhone, $msg, 'nomination_nominee');
                if (in_array('whatsapp', $plan, true)) $sms->sendWhatsApp($nomPhone, $msg, 'nomination_nominee');
            }

            $byPhone = Phone::normalize((string) ($data['nominator_phone'] ?? ''),
                                        (string) ($data['nominator_country'] ?? ''));
            if ($byPhone !== null) {
                $msg = 'Africa GATES: your nomination of ' . $nomName . ' is in (ref ' . $reference
                     . '). We will notify you the moment the profile goes live. ' . $watchUrl;
                if ($sms->smsConfigured())      $sms->sendSms($byPhone, $msg, 'nomination_nominator');
                if ($sms->whatsappConfigured()) $sms->sendWhatsApp($byPhone, $msg, 'nomination_nominator');
            }
        } catch (\Throwable) {}
    }
}
