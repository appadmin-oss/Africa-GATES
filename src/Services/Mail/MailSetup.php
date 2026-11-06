<?php
declare(strict_types=1);

namespace AfricaGates\Services\Mail;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Saving how mail is sent — and refusing to save a login that does not log in.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * A STORED VALUE OUTRANKS THE ONE THAT WORKED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Mail worked from the server's `.env` on every branch before the settings page grew
 * SMTP fields. After that, `MailConfig` read the settings first, the settings page
 * re-posted those fields on every save, and a value that reached the table — a typo, a
 * key pasted into the wrong box, an admin login a browser autofilled into "SMTP
 * username" — silently replaced the working one. Every later fix to the SMTP code changed
 * nothing, because the input was wrong rather than the code.
 *
 * So the transport is saved HERE, by itself, and:
 *
 *   · an SMTP login is stored once `MailDiagnosis` has walked the real conversation with
 *     it — never a message, `RSET` at the end. The gate is on the PROVIDER'S VERDICT, not
 *     on the run completing: only a refusal that reached the provider and judged what was
 *     typed (auth, sender, quota) keeps the old value. A run that stopped earlier — DNS,
 *     the port, the handshake — never presented the login, so it has no opinion of it, and
 *     the settings are stored unverified with the fault stated.
 *
 *     That last clause is a REPAIR, not a relaxation. Gating on the whole run made a
 *     deadlock out of the situation this screen exists for: the host blocks 587, the
 *     diagnosis fails on the road, the operator moves to 465 — the one change that would
 *     fix it — and the save is refused BECAUSE the road is broken. The only fields that
 *     can route around a blocked road were the only fields a blocked road prevented
 *     changing, and the refusal read "what was working before still is" to somebody for
 *     whom nothing had worked in days. Nothing here knows that, so it no longer says it;
 *   · an API key is stored only after Brevo's account endpoint (a READ) accepts it;
 *   · `useEnv()` removes every stored SMTP value, which puts the server's `.env` back in
 *     charge — the configuration that is known to have worked here.
 *
 * The checks are injected so the suite can say yes and no without a network.
 */
final class MailSetup
{
    /** The rows this class owns. Nothing else writes them. */
    public const SMTP_KEYS = ['mail_smtp_host', 'mail_smtp_port', 'mail_smtp_secure', 'mail_smtp_user', 'mail_smtp_pass'];
    public const TRANSPORT_KEY = 'mail_transport';
    public const API_KEY = 'mail_brevo_api_key';

    /** @var \Closure(MailConfig):array{ok:bool,title:string,fix:string,steps:array} */
    private \Closure $smtpCheck;
    /** @var \Closure(string):array{ok:bool,detail:string} */
    private \Closure $apiCheck;

    public function __construct(?\Closure $smtpCheck = null, ?\Closure $apiCheck = null)
    {
        $this->smtpCheck = $smtpCheck ?? static fn (MailConfig $c): array => (new MailDiagnosis($c))->run();
        $this->apiCheck  = $apiCheck  ?? static fn (string $key): array => (new BrevoApi($key))->check();
    }

    /**
     * Save the "Sending" form.
     *
     * @param array<string,mixed> $in transport, host, port, secure, username, password, api_key
     * @return array{ok:bool, saved:list<string>, messages:list<string>, report:?array}
     */
    public function save(array $in, ?int $adminId = null): array
    {
        $saved = []; $messages = []; $report = null; $ok = true;

        $current = self::settings();
        $transport = strtolower(trim((string) ($in['transport'] ?? MailConfig::TRANSPORT_AUTO)));
        if (!in_array($transport, MailConfig::TRANSPORTS, true)) $transport = MailConfig::TRANSPORT_AUTO;

        // ── SMTP: checked as a whole, stored as a whole ─────────────────────────
        // Blank boxes keep what is there (the password is never drawn back), so the
        // candidate is "what would be in force if this were saved".
        $smtp = [
            'mail_smtp_host'   => trim((string) ($in['host'] ?? '')),
            'mail_smtp_port'   => trim((string) ($in['port'] ?? '')),
            'mail_smtp_secure' => strtolower(trim((string) ($in['secure'] ?? ''))),
            'mail_smtp_user'   => trim((string) ($in['username'] ?? '')),
            'mail_smtp_pass'   => trim((string) ($in['password'] ?? '')),
        ];
        $changed = [];
        foreach ($smtp as $k => $v) {
            if ($k === 'mail_smtp_pass' && $v === '') continue;
            if ($v !== trim((string) ($current[$k] ?? ''))) $changed[$k] = $v;
        }

        if ($changed !== []) {
            $candidate = MailConfig::load(array_merge($current, $changed));
            if (!$candidate->hasCredentials()) {
                $ok = false;
                $messages[] = 'The SMTP login was not saved: it needs both a username and a password.';
            } else {
                $report = ($this->smtpCheck)($candidate);
                $cause  = (string) ($report['cause'] ?? '');
                if (!empty($report['ok'])) {
                    foreach ($changed as $k => $v) self::put($k, $v, $adminId);
                    $saved = array_merge($saved, array_keys($changed));
                    $messages[] = 'SMTP saved — the provider accepted the login and the From address.';
                } elseif (self::judgedTheLogin($cause)) {
                    $ok = false;
                    $messages[] = 'The SMTP login was not saved — the provider answered and refused it, so the '
                        . 'stored settings are unchanged. '
                        . trim((string) ($report['title'] ?? '')) . '. ' . trim((string) ($report['fix'] ?? ''));
                } else {
                    // ── THE GUARD WAS BLOCKING THE FIX ──────────────────────────────────
                    // Saved anyway, deliberately. The check never reached the login: it
                    // stopped at DNS, at the port, or at the handshake, so it has learned
                    // NOTHING about what was typed and has no standing to refuse it.
                    //
                    // Refusing everything made a deadlock out of exactly the situation
                    // this screen exists for. The host blocks 587, so the diagnosis fails
                    // on the road; the operator moves to 465 or 2525 — the one change that
                    // would fix it — and the save is refused because the road is broken.
                    // The only fields that can route around a broken road are the only
                    // fields a broken road prevents changing, and the refusal said "what
                    // was working before still is" to somebody for whom nothing had worked
                    // in days. Stored, with the fault stated: it is no worse than what is
                    // there, which does not work either, and it can now be iterated.
                    foreach ($changed as $k => $v) self::put($k, $v, $adminId);
                    $saved = array_merge($saved, array_keys($changed));
                    $messages[] = 'Saved, but mail still cannot be sent by SMTP: the check stopped before the '
                        . 'provider was asked about the login, so these settings are stored unverified. '
                        . trim((string) ($report['title'] ?? '')) . '. ' . trim((string) ($report['fix'] ?? ''));
                }
            }
        }

        // ── The API key ─────────────────────────────────────────────────────────
        $key = trim((string) ($in['api_key'] ?? ''));
        if (!empty($in['api_key_clear'])) {
            self::put(self::API_KEY, '', $adminId);
            $saved[] = self::API_KEY;
            $messages[] = 'The Brevo API key was removed.';
        } elseif ($key !== '') {
            $r = ($this->apiCheck)($key);
            if (!empty($r['ok'])) {
                self::put(self::API_KEY, $key, $adminId);
                $saved[] = self::API_KEY;
                $messages[] = 'API key saved. ' . $r['detail'];
            } else {
                $ok = false;
                $messages[] = 'The API key was NOT saved: ' . $r['detail'];
            }
        }

        // ── Which road — always saved, it is a choice rather than a credential ──
        if ($transport !== trim((string) ($current[self::TRANSPORT_KEY] ?? ''))) {
            self::put(self::TRANSPORT_KEY, $transport, $adminId);
            $saved[] = self::TRANSPORT_KEY;
        }
        $after = MailConfig::load();
        if ($transport === MailConfig::TRANSPORT_API && !$after->hasApiKey()) {
            $ok = false;
            $messages[] = 'Sending is set to the Brevo API, but no working API key is saved — nothing can be sent until one is.';
        }

        return ['ok' => $ok, 'saved' => $saved, 'messages' => $messages, 'report' => $report];
    }

    /**
     * Save the Google Apps Script address and secret from Email health — tried first.
     *
     * The same two rows Settings → Google Calendar and Meet writes, and the same resolver
     * reads them ({@see \AfricaGates\Services\GoogleMeetService::gasUrl()}), so the
     * calendar and the mail cannot disagree. They are on this page too because this is
     * the page an operator is on when mail is failing, and sending them to another
     * screen to paste two values was a step that lost people.
     *
     * Which raises the stakes on a typo: the calendar uses this secret too. So the
     * candidate is asked first, and the one answer that JUDGES the secret — the script's
     * own "Bad token" — keeps the stored values. An address that cannot be reached, or a
     * deployment older than the mail action, has said nothing against what was typed, and
     * it is stored with the fault stated (the deadlock rule in save()).
     *
     * @param \Closure(string,string):array{ok:bool,detail:string}|null $check
     * @return array{ok:bool, message:string}
     */
    public static function saveAppsScript(string $url, string $secret, ?int $adminId = null, ?\Closure $check = null): array
    {
        $current = self::settings();
        // A blank box keeps what is in force (the secret is never drawn back), and only a
        // value somebody TYPED is written — otherwise pressing the button would copy the
        // server's .env into the table, where it would outrank the file from then on.
        $typedUrl = trim($url);
        $typedSecret = trim($secret);
        $url = $typedUrl !== '' ? $typedUrl : \AfricaGates\Services\GoogleMeetService::gasUrl();
        $secret = $typedSecret !== '' ? $typedSecret : \AfricaGates\Services\GoogleMeetService::gasSecret();

        if ($url === '' || $secret === '') {
            return ['ok' => false, 'message' => 'Nothing was saved: Apps Script needs both the web-app address and the secret.'];
        }
        $parts = parse_url($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return ['ok' => false, 'message' => 'Nothing was saved: “' . mb_substr($url, 0, 80) . '” is not a web address. '
                . 'Copy the whole Web app URL, starting https://script.google.com/.'];
        }
        // `/dev` is the test address: it answers only the script's owner, signed in, in a
        // browser — so it works when they try it and never from this server.
        if (!str_ends_with(rtrim((string) ($parts['path'] ?? ''), '/'), '/exec')) {
            return ['ok' => false, 'message' => 'Nothing was saved: the address must end in /exec. '
                . 'In Apps Script, Deploy → Manage deployments shows it as “Web app” — not the editor’s address, '
                . 'and not the one ending /dev, which only works for you.'];
        }

        $check ??= static fn (string $u, string $s): array => (new AppsScriptMail($u, $s))->check();
        $r = $check($url, $secret);
        $detail = trim((string) ($r['detail'] ?? ''));

        if (empty($r['ok']) && stripos($detail, 'Bad token') !== false) {
            return ['ok' => false, 'message' => 'Nothing was saved: the script refused the secret. It must be exactly the text '
                . 'between the quotes in const SECRET = \'…\'; at the top of the script — and if you changed it there, '
                . 'deploy a New version so Google is running it.'];
        }
        if (empty($r['ok']) && stripos($detail, 'no SECRET set') !== false) {
            return ['ok' => false, 'message' => 'Nothing was saved: the deployed script has no secret of its own yet. Put a long '
                . 'random text between the quotes in const SECRET = \'\'; at the top, save, deploy a New version, and paste '
                . 'the same text here.'];
        }

        if ($typedUrl !== '' && $typedUrl !== trim((string) ($current['gas_url'] ?? ''))) self::put('gas_url', $typedUrl, $adminId);
        if ($typedSecret !== '' && $typedSecret !== trim((string) ($current['gas_secret'] ?? ''))) self::put('gas_secret', $typedSecret, $adminId);

        return !empty($r['ok'])
            ? ['ok' => true, 'message' => 'Saved. ' . $detail . ' When Google SMTP fails, codes, receipts and confirmations go out through it.']
            : ['ok' => false, 'message' => 'Saved, but mail cannot go through Apps Script yet: ' . $detail];
    }

    /**
     * Forget every stored SMTP value, so the server's `.env` decides again.
     *
     * @return list<string> the rows that were removed
     */
    public static function useEnv(): array
    {
        $had = DB::table('gates_settings')->whereIn('key_name', self::SMTP_KEYS)
            ->where('value', '!=', '')->pluck('key_name')->all();
        DB::table('gates_settings')->whereIn('key_name', self::SMTP_KEYS)->delete();
        // A rested SMTP road would otherwise go on being skipped for half an hour.
        DB::table('gates_settings')->where('key_name', \AfricaGates\Services\OtpService::SMTP_REST_KEY)->delete();
        return array_values(array_map('strval', $had));
    }

    /**
     * Did the provider actually answer and judge what was typed?
     *
     * Only an AUTH or SENDER refusal is the provider saying no to a VALUE: it answered,
     * read the login or the From address, and rejected it — which is the fault this
     * class's whole gate exists to keep out of the table. Everything earlier in the
     * conversation (config, DNS, the port, the handshake) is a fault of the ROAD, and the
     * check formed no opinion of the credentials because it never got to present them.
     *
     * The distinction is the difference between a guard and a deadlock. See save().
     */
    private static function judgedTheLogin(string $cause): bool
    {
        return in_array($cause, [MailFailure::AUTH, MailFailure::SENDER, MailFailure::QUOTA], true);
    }

    /** @return array<string,string> */
    private static function settings(): array
    {
        try {
            return array_map('strval', DB::table('gates_settings')->pluck('value', 'key_name')->all());
        } catch (\Throwable) {
            return [];
        }
    }

    private static function put(string $key, string $value, ?int $adminId): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => $key], [
            'value' => $value, 'updated_at' => Carbon::now()->toDateTimeString(),
            // A foreign key to gates_admins; there is no admin 0.
            'updated_by' => ($adminId ?? 0) > 0 ? $adminId : null,
        ]);
    }
}
