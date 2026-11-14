<?php
declare(strict_types=1);

namespace AfricaGates\Services\Mail;

use AfricaGates\Support\Env;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * WHAT AN ANNOUNCEMENT ACHIEVED: WHO PRESSED A LINK, AND WHAT THEY DID NEXT.
 *
 * Owner, 5 Nov 2026: emails that are "promotional and also purposeful, generating traction".
 * Nothing here could say whether a campaign worked — the log knew it was SENT and nothing
 * else. So an announcement's links go through {@see wrap()}: each becomes a signed address on
 * this site that records the press and forwards at once (`/m/c/{token}`), and the visit that
 * follows is remembered in the SESSION (no new cookie; the session cookie is already there
 * and essential) so that a vote, a nomination or a gift started within {@see WINDOW_DAYS}
 * is credited to the campaign that brought them ({@see converted()}).
 *
 * The boundaries, each deliberate:
 *   · ANNOUNCEMENTS ONLY. A sign-in code or a receipt is never rewritten: it is not ours to
 *     measure, and a sign-in link must go exactly where it says.
 *   · No open pixel (see MailEngagement for why "opened" is noise and a pixel is a promise
 *     broken). A click is a person choosing to come back.
 *   · The unsubscribe link is NEVER wrapped. Stopping must not pass through a counter.
 *   · Addresses are kept only as the hash the opt-out list already uses.
 *   · A press within SCANNER_SECONDS of the send is not counted: corporate mail scanners
 *     (Outlook Safe Links and the like) fetch every link on arrival, and counting them would
 *     report a campaign "clicked" by machines.
 *   · The token is signed with APP_KEY, so the redirect cannot be turned into an open
 *     redirect by editing the address, and only http(s) targets are honoured.
 */
final class MailTraction
{
    public const WINDOW_DAYS     = 7;
    public const SCANNER_SECONDS = 60;
    public const SESSION         = 'mail_ref';

    /**
     * What a campaign can be FOR, and the action that counts as it happening. Each has a hook
     * where the action completes ({@see converted()} callers); `read` is the click itself.
     * A goal with no hook would report nought for ever and read as the campaign failing.
     */
    public const GOALS = [
        'vote'     => 'Votes',
        'nominate' => 'Nominations',
        'give'     => 'Gifts started',
        'read'     => 'Readers who came to the site',
    ];

    /** Rewrite every link in an announcement's HTML to a counted one. */
    public static function wrap(string $html, string $site, string $campaign, string $emailHash, ?string $variant = null): string
    {
        if ($html === '' || $campaign === '' || $emailHash === '') return $html;
        $site = rtrim($site, '/');
        return (string) preg_replace_callback('~(<a\b[^>]*?\bhref\s*=\s*)(["\'])(https?://[^"\']+)\2~i',
            static function (array $m) use ($site, $campaign, $emailHash, $variant): string {
                $url = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5);
                if (str_contains($url, '/email/unsubscribe') || str_contains($url, '/m/c/')) return $m[0];
                $token = self::token(['k' => $campaign, 'h' => $emailHash, 'v' => $variant, 'u' => $url]);
                return $m[1] . $m[2] . htmlspecialchars($site . '/m/c/' . $token, ENT_QUOTES) . $m[2];
            }, $html);
    }

    /** @param array<string,mixed> $data */
    public static function token(array $data): string
    {
        $p = rtrim(strtr(base64_encode((string) json_encode($data, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        return $p . '.' . self::sig($p);
    }

    /** @return array{k:string,h:string,v:?string,u:string}|null */
    public static function read(string $token): ?array
    {
        $dot = strrpos($token, '.');
        if ($dot === false) return null;
        $p = substr($token, 0, $dot);
        if (!hash_equals(self::sig($p), substr($token, $dot + 1))) return null;
        $d = json_decode((string) base64_decode(strtr($p, '-_', '+/')), true);
        if (!is_array($d) || !is_string($d['u'] ?? null) || !preg_match('~^https?://~i', $d['u'])
            || !is_string($d['k'] ?? null) || !is_string($d['h'] ?? null)) return null;
        return ['k' => $d['k'], 'h' => $d['h'], 'v' => isset($d['v']) && is_string($d['v']) ? $d['v'] : null, 'u' => $d['u']];
    }

    private static function sig(string $payload): string
    {
        $key = (string) Env::get('APP_KEY', '');
        if ($key === '') $key = 'ag-fallback|' . (string) Env::get('DB_NAME', 'africa_gates');
        return substr(hash_hmac('sha256', 'mail-click|' . $payload, $key), 0, 24);
    }

    /**
     * A press arrived. Record it (unless it is a scanner), remember the campaign for this
     * visit, and return where to send them. Null when the token is not one we signed.
     */
    public static function click(string $token, ?Carbon $now = null): ?string
    {
        $d = self::read($token);
        if ($d === null) return null;
        $now ??= Carbon::now();
        try {
            if (SchemaHas::table('gates_mail_clicks') && !self::scanner($d['k'], $d['h'], $now)) {
                DB::table('gates_mail_clicks')->insert(['campaign_key' => mb_substr($d['k'], 0, 64), 'email_hash' => mb_substr($d['h'], 0, 64),
                    'variant' => $d['v'], 'url' => mb_substr($d['u'], 0, 500), 'clicked_at' => $now->toDateTimeString()]);
            }
        } catch (\Throwable) {
            // A count can never cost somebody the page they asked for.
        }
        if (isset($_SESSION) && is_array($_SESSION)) {
            $_SESSION[self::SESSION] = ['k' => $d['k'], 'h' => $d['h'], 'at' => $now->getTimestamp()];
        }
        return $d['u'];
    }

    /** True when this press came too soon after the send to be a person. */
    private static function scanner(string $campaign, string $hash, Carbon $now): bool
    {
        if (!SchemaHas::table('gates_mail_outbox')) return false;
        $sent = DB::table('gates_mail_outbox')->where('campaign_key', $campaign)->where('email_hash', $hash)->value('sent_at');
        return $sent !== null && $now->getTimestamp() - Carbon::parse((string) $sent)->getTimestamp() < self::SCANNER_SECONDS;
    }

    /**
     * Somebody did something worth crediting. If they arrived from an announcement in this
     * session within the window, the campaign gets it — once per kind and reference.
     */
    public static function converted(string $kind, string $ref = '', int $valueMinor = 0, ?Carbon $now = null): bool
    {
        $m = (isset($_SESSION) && is_array($_SESSION)) ? ($_SESSION[self::SESSION] ?? null) : null;
        if (!is_array($m) || empty($m['k'])) return false;
        $now ??= Carbon::now();
        if ($now->getTimestamp() - (int) ($m['at'] ?? 0) > self::WINDOW_DAYS * 86400) {
            unset($_SESSION[self::SESSION]);
            return false;
        }
        try {
            if (!SchemaHas::table('gates_mail_conversions')) return false;
            $q = DB::table('gates_mail_conversions')->where('campaign_key', $m['k'])->where('kind', $kind);
            if ($ref !== '' && $q->where('ref', $ref)->exists()) return false;
            DB::table('gates_mail_conversions')->insert(['campaign_key' => mb_substr((string) $m['k'], 0, 64),
                'email_hash' => mb_substr((string) $m['h'], 0, 64), 'kind' => mb_substr($kind, 0, 24),
                'ref' => $ref !== '' ? mb_substr($ref, 0, 64) : null, 'value_minor' => max(0, $valueMinor),
                'created_at' => $now->toDateTimeString()]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * One campaign's results, by variant where there were two.
     *
     * @return array{sent:int, clickers:int, clicks:int, rate:float, conversions:array<string,int>,
     *               variants:array<string,array{sent:int, clickers:int, rate:float}>}
     */
    public static function report(string $campaign): array
    {
        $out = ['sent' => 0, 'clickers' => 0, 'clicks' => 0, 'rate' => 0.0, 'conversions' => [], 'variants' => []];
        if (!SchemaHas::table('gates_mail_clicks')) return $out;
        $sentBy = SchemaHas::table('gates_mail_outbox')
            ? DB::table('gates_mail_outbox')->where('campaign_key', $campaign)->where('status', 'sent')
                ->groupBy('variant')->get([DB::raw('variant as v'), DB::raw('COUNT(*) as n')])->all() : [];
        foreach ($sentBy as $r) {
            $out['sent'] += (int) $r->n;
            if ($r->v) $out['variants'][(string) $r->v] = ['sent' => (int) $r->n, 'clickers' => 0, 'rate' => 0.0];
        }
        if ($out['sent'] === 0) {
            $out['sent'] = (int) DB::table('gates_broadcast_log')->where('campaign', $campaign)->where('status', 'sent')->count();
        }
        $out['clicks']   = (int) DB::table('gates_mail_clicks')->where('campaign_key', $campaign)->count();
        $out['clickers'] = (int) DB::table('gates_mail_clicks')->where('campaign_key', $campaign)->distinct()->count('email_hash');
        foreach (DB::table('gates_mail_clicks')->where('campaign_key', $campaign)->whereNotNull('variant')->groupBy('variant')
                     ->get([DB::raw('variant as v'), DB::raw('COUNT(DISTINCT email_hash) as n')]) as $r) {
            if (isset($out['variants'][(string) $r->v])) $out['variants'][(string) $r->v]['clickers'] = (int) $r->n;
        }
        foreach ($out['variants'] as &$vv) $vv['rate'] = $vv['sent'] > 0 ? round($vv['clickers'] / $vv['sent'] * 100, 1) : 0.0;
        unset($vv);
        $out['rate'] = $out['sent'] > 0 ? round($out['clickers'] / $out['sent'] * 100, 1) : 0.0;
        if (SchemaHas::table('gates_mail_conversions')) {
            foreach (DB::table('gates_mail_conversions')->where('campaign_key', $campaign)->groupBy('kind')
                         ->get([DB::raw('kind as k'), DB::raw('COUNT(*) as n')]) as $r) {
                $out['conversions'][(string) $r->k] = (int) $r->n;
            }
        }
        return $out;
    }
}
