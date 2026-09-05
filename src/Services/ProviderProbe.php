<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * ASK EVERY PROVIDER, RIGHT NOW, WHETHER IT WILL ANSWER US.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every integration on this platform could tell you whether a KEY WAS PRESENT and none
 * of them could tell you whether it WORKED. Those are different questions and the gap
 * between them has cost this codebase more than any bug in it:
 *
 *   · The door voice was reported broken for days. The key was present and correct, the
 *     provider was correct, the API request was correct. The one screen built to test it
 *     asked a different provider, and nothing anywhere would have said so.
 *   · A Permissions-Policy header switched the greeting, the camera and the microphone
 *     off site-wide for months. Every check upstream read healthy.
 *   · `openai_voice_last_error` was written from the first commit and read by nothing.
 *
 * The shape is always the same: a configured-looking system that does not work, with no
 * screen willing to say which. One click that asks every provider a real question closes
 * the whole class.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * NOTHING HERE SENDS, CHARGES OR COSTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This is a diagnostic an operator will press repeatedly, on a live system, possibly with
 * an event running. Every probe is a READ — "who am I", "what is my balance", "list your
 * models" — chosen for exactly that reason. No SMS is sent, no payment is initialised, no
 * audio is synthesised, no mail leaves.
 *
 * That rule is the reason the endpoints look arbitrary: Africa's Talking is asked for its
 * balance rather than to deliver a message, Paystack for its bank list rather than to
 * start a transaction. `ProviderProbeTest` asserts it, because the tempting change later
 * — "let me actually send one to be sure" — turns a diagnostic into an outbound message
 * to a real phone every time somebody opens the page.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND IT SEPARATES THE THREE ANSWERS THAT MATTER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   not set   — no credential. Not a fault; most deployments use a handful of these.
 *   OK        — it answered us. The credential is live and reaches the vendor.
 *   FAILING   — configured and refusing, with the vendor's own words.
 *
 * The middle and last are what no screen here could distinguish, and "configured and
 * refusing" is the state every expensive fault in this codebase was sitting in.
 */
final class ProviderProbe
{
    /** Kept short: an operator is watching a page, and fourteen probes run in series. */
    private const TIMEOUT = 8;

    /** Grouped as an operator thinks about them, not as the code is organised. */
    public const GROUPS = [
        'messaging' => 'Messaging',
        'money'     => 'Money',
        'voice'     => 'Voice',
        'ai'        => 'AI',
        'platform'  => 'Platform',
    ];

    /**
     * Every probe, in the order the page shows them.
     *
     * @return list<array{id:string, label:string, group:string}>
     */
    public static function catalogue(): array
    {
        return [
            ['id' => 'africastalking', 'label' => "Africa's Talking", 'group' => 'messaging'],
            ['id' => 'termii',         'label' => 'Termii',           'group' => 'messaging'],
            ['id' => 'twilio',         'label' => 'Twilio',           'group' => 'messaging'],
            ['id' => 'smtp',           'label' => 'Email (SMTP)',     'group' => 'messaging'],
            ['id' => 'paystack',       'label' => 'Paystack',         'group' => 'money'],
            ['id' => 'flutterwave',    'label' => 'Flutterwave',      'group' => 'money'],
            ['id' => 'openai_voice',   'label' => 'OpenAI (voice)',   'group' => 'voice'],
            ['id' => 'elevenlabs',     'label' => 'ElevenLabs',       'group' => 'voice'],
            ['id' => 'azure_speech',   'label' => 'Azure Speech',     'group' => 'voice'],
            ['id' => 'openai',         'label' => 'OpenAI',           'group' => 'ai'],
            ['id' => 'groq',           'label' => 'Groq',             'group' => 'ai'],
            ['id' => 'gemini',         'label' => 'Google Gemini',    'group' => 'ai'],
            ['id' => 'anthropic',      'label' => 'Anthropic',        'group' => 'ai'],
            ['id' => 'cloudinary',     'label' => 'Cloudinary',       'group' => 'platform'],
            ['id' => 'gas',            'label' => 'Google Calendar / Meet', 'group' => 'platform'],
            ['id' => 'cron',           'label' => 'Scheduled tasks',  'group' => 'platform'],
        ];
    }

    /**
     * Run one probe.
     *
     * @return array{id:string, label:string, group:string, configured:bool, ok:bool,
     *                detail:string, ms:int}
     */
    public static function one(string $id): array
    {
        $meta = null;
        foreach (self::catalogue() as $c) if ($c['id'] === $id) { $meta = $c; break; }
        if ($meta === null) {
            return ['id' => $id, 'label' => $id, 'group' => 'platform',
                    'configured' => false, 'ok' => false, 'detail' => 'Unknown check.', 'ms' => 0];
        }

        $started = microtime(true);
        try {
            $r = self::run($id);
        } catch (\Throwable $e) {
            // A probe must never be the thing that breaks the page it is diagnosing.
            $r = ['configured' => true, 'ok' => false,
                  'detail' => 'The check itself failed: ' . $e->getMessage()];
        }

        return $meta + $r + ['ms' => (int) round((microtime(true) - $started) * 1000)];
    }

    /** @return list<array<string,mixed>> every probe, in catalogue order */
    public static function all(): array
    {
        $out = [];
        foreach (self::catalogue() as $c) $out[] = self::one($c['id']);

        return $out;
    }

    // ══ the probes ═══════════════════════════════════════════════════════════

    /** @return array{configured:bool, ok:bool, detail:string} */
    private static function run(string $id): array
    {
        return match ($id) {
            'africastalking' => self::africasTalking(),
            'termii'         => self::termii(),
            'twilio'         => self::twilio(),
            'smtp'           => self::smtp(),
            'paystack'       => self::paystack(),
            'flutterwave'    => self::flutterwave(),
            'openai_voice'   => self::openAiVoice(),
            'elevenlabs'     => self::elevenLabs(),
            'azure_speech'   => self::azure(),
            'openai'         => self::bearerJson('OpenAI', self::setting('ai_openai_key', 'OPENAI_API_KEY'),
                                                 'https://api.openai.com/v1/models'),
            'groq'           => self::bearerJson('Groq', self::setting('ai_groq_key', 'GROQ_API_KEY'),
                                                 'https://api.groq.com/openai/v1/models'),
            'gemini'         => self::gemini(),
            'anthropic'      => self::anthropic(),
            'cloudinary'     => self::cloudinary(),
            'gas'            => self::gas(),
            'cron'           => self::cron(),
            default          => self::off('Unknown check.'),
        };
    }

    /**
     * AFRICA'S TALKING — asked for its BALANCE, never to send.
     *
     * `GET /version1/user?username=…` with an `apiKey` header is the whole credential
     * check: it proves the key, proves the username, and proves which environment the
     * pair routes to — because a sandbox account IS the literal username `sandbox`, which
     * is how {@see SmsService::atEndpoint()} decides. Sending a message to test would put
     * a real SMS on a real phone every time somebody opened this page.
     */
    private static function africasTalking(): array
    {
        $user = self::setting('sms_at_username', 'AT_USERNAME');
        $key  = self::setting('sms_at_api_key', 'AT_API_KEY');
        if ($user === '' || $key === '') return self::off('No username or API key set.');

        $base = SmsService::atEndpoint($user) === SmsService::AT_SANDBOX
            ? 'https://api.sandbox.africastalking.com' : 'https://api.africastalking.com';

        [$code, $body] = self::http('GET', $base . '/version1/user?username=' . rawurlencode($user),
                                    ['apiKey: ' . $key, 'Accept: application/json']);

        $where = $base === 'https://api.africastalking.com' ? 'live' : 'sandbox';
        if ($code === 200) {
            $j   = json_decode($body, true);
            $bal = (string) ($j['UserData']['balance'] ?? '');
            return self::ok('Answered on ' . $where
                . ($bal !== '' ? ' — balance ' . $bal : '') . '.');
        }
        if ($code === 401) return self::bad('Rejected the API key (401) on ' . $where . '.');

        return self::bad(self::httpWhy($code, $body, $where));
    }

    private static function termii(): array
    {
        $key = self::setting('sms_termii_api_key', 'TERMII_API_KEY');
        if ($key === '') return self::off('No API key set.');

        [$code, $body] = self::http('GET',
            'https://api.ng.termii.com/api/get-balance?api_key=' . rawurlencode($key), []);

        if ($code === 200) {
            $j = json_decode($body, true);
            return self::ok('Answered' . (isset($j['balance'])
                ? ' — balance ' . (string) $j['balance'] . ' ' . (string) ($j['currency'] ?? '') : '') . '.');
        }
        return self::bad(self::httpWhy($code, $body));
    }

    /** Twilio: the account resource, which is the cheapest authenticated read there is. */
    private static function twilio(): array
    {
        $sid   = self::setting('sms_twilio_sid', 'TWILIO_ACCOUNT_SID');
        $token = self::setting('sms_twilio_token', 'TWILIO_AUTH_TOKEN');
        if ($sid === '' || $token === '') return self::off('No account SID or auth token set.');

        [$code, $body] = self::http('GET',
            'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '.json',
            ['Accept: application/json'], null, $sid . ':' . $token);

        if ($code === 200) {
            $j = json_decode($body, true);
            return self::ok('Answered — account ' . (string) ($j['status'] ?? 'active') . '.');
        }
        if ($code === 401) return self::bad('Rejected the SID/token pair (401).');

        return self::bad(self::httpWhy($code, $body));
    }

    /**
     * SMTP — CONNECTED AND GREETED, never sent.
     *
     * Opens the socket and reads the banner. That proves the host, the port and that
     * something is listening; it deliberately stops before AUTH and before any message,
     * because a diagnostic that emails somebody is a diagnostic nobody presses twice.
     */
    private static function smtp(): array
    {
        $host = self::setting('mail_smtp_host', 'SMTP_HOST');
        $port = (int) (self::setting('mail_smtp_port', 'SMTP_PORT') ?: '587');
        if ($host === '') return self::off('No SMTP host set.');

        $errNo = 0; $errStr = '';
        $sock = @fsockopen(
            (str_contains($host, '://') ? $host : 'tcp://' . $host), $port, $errNo, $errStr, self::TIMEOUT);
        if (!$sock) return self::bad('Could not connect to ' . $host . ':' . $port
                                     . ($errStr !== '' ? ' — ' . $errStr : '') . '.');

        stream_set_timeout($sock, self::TIMEOUT);
        $banner = (string) fgets($sock, 512);
        @fclose($sock);

        return str_starts_with(trim($banner), '220')
            ? self::ok('Connected to ' . $host . ':' . $port . ' — ' . trim(mb_substr($banner, 0, 80)))
            : self::bad('Connected but the server did not greet us: ' . trim(mb_substr($banner, 0, 120)));
    }

    /** Paystack: the bank list — authenticated, read-only, and no transaction opened. */
    private static function paystack(): array
    {
        $key = self::setting('paystack_secret_key', 'PAYSTACK_SECRET_KEY');
        if ($key === '') return self::off('No secret key set.');

        [$code, $body] = self::http('GET', 'https://api.paystack.co/bank?country=nigeria&perPage=1',
                                    ['Authorization: Bearer ' . $key, 'Accept: application/json']);

        if ($code === 200) {
            $mode = str_starts_with($key, 'sk_live') ? 'live' : 'test';
            return self::ok('Answered on the ' . $mode . ' key.');
        }
        if ($code === 401) return self::bad('Rejected the secret key (401).');

        return self::bad(self::httpWhy($code, $body));
    }

    private static function flutterwave(): array
    {
        $key = self::setting('flutterwave_secret_key', 'FLW_SECRET_KEY');
        if ($key === '') return self::off('No secret key set.');

        [$code, $body] = self::http('GET', 'https://api.flutterwave.com/v3/banks/NG',
                                    ['Authorization: Bearer ' . $key, 'Accept: application/json']);

        if ($code === 200) {
            $mode = str_contains($key, 'TEST') ? 'test' : 'live';
            return self::ok('Answered on the ' . $mode . ' key.');
        }
        if ($code === 401) return self::bad('Rejected the secret key (401).');

        return self::bad(self::httpWhy($code, $body));
    }

    /**
     * OPENAI, AS THE DOOR USES IT.
     *
     * Listed separately from the AI check even though both read the same key, because they
     * fail independently and an operator chasing a silent door must not have to reason
     * about which row covers it. `/v1/models` also proves the account can REACH the TTS
     * model, which a bare key check does not.
     */
    private static function openAiVoice(): array
    {
        $key = self::setting('ai_openai_key', 'OPENAI_API_KEY');
        if ($key === '') return self::off('No OpenAI key set.');

        [$code, $body] = self::http('GET', 'https://api.openai.com/v1/models/' . OpenAiVoice::MODEL,
                                    ['Authorization: Bearer ' . $key, 'Accept: application/json']);

        if ($code === 200) return self::ok('Answered — ' . OpenAiVoice::MODEL . ' is reachable.');
        if ($code === 401) return self::bad('Rejected the key (401).');
        if ($code === 404) return self::bad('The key works, but this account cannot reach '
                                            . OpenAiVoice::MODEL . '.');

        return self::bad(self::httpWhy($code, $body));
    }

    private static function elevenLabs(): array
    {
        $key = self::setting('ai_elevenlabs_key', 'ELEVENLABS_API_KEY');
        if ($key === '') return self::off('No API key set.');

        [$code, $body] = self::http('GET', 'https://api.elevenlabs.io/v1/user',
                                    ['xi-api-key: ' . $key, 'Accept: application/json']);

        if ($code === 200) return self::ok('Answered.');
        if ($code === 401) return self::bad('Rejected the API key (401).');

        return self::bad(self::httpWhy($code, $body));
    }

    /**
     * AZURE SPEECH — a TOKEN, which is the same handshake the voice makes.
     *
     * Region matters as much as the key here and a key-only check would pass with the
     * wrong one, which is precisely the failure an operator cannot see from a settings
     * screen. The token endpoint is per-region, so this proves the pair.
     */
    private static function azure(): array
    {
        $key    = self::setting('azure_speech_key', 'AZURE_SPEECH_KEY');
        $region = self::setting('azure_speech_region', 'AZURE_SPEECH_REGION');
        if ($key === '')    return self::off('No Azure Speech key set.');
        if ($region === '') return self::bad('A key is set but no region — Azure needs both.');

        [$code, $body] = self::http('POST',
            'https://' . rawurlencode($region) . '.api.cognitive.microsoft.com/sts/v1.0/issueToken',
            ['Ocp-Apim-Subscription-Key: ' . $key, 'Content-Length: 0'], '');

        if ($code === 200) return self::ok('Issued a token for ' . $region . '.');
        if ($code === 401) return self::bad('Rejected the key (401) — or it belongs to a different region.');
        if ($code === 404) return self::bad('No Speech resource at region "' . $region . '".');

        return self::bad(self::httpWhy($code, $body));
    }

    private static function gemini(): array
    {
        $key = self::setting('ai_gemini_key', 'GEMINI_API_KEY');
        if ($key === '') return self::off('No API key set.');

        [$code, $body] = self::http('GET',
            'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode($key), []);

        return $code === 200 ? self::ok('Answered.') : self::bad(self::httpWhy($code, $body));
    }

    private static function anthropic(): array
    {
        $key = self::setting('ai_anthropic_key', 'ANTHROPIC_API_KEY');
        if ($key === '') return self::off('No API key set.');

        [$code, $body] = self::http('GET', 'https://api.anthropic.com/v1/models',
            ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01', 'Accept: application/json']);

        if ($code === 200) return self::ok('Answered.');
        if ($code === 401) return self::bad('Rejected the API key (401).');

        return self::bad(self::httpWhy($code, $body));
    }

    private static function cloudinary(): array
    {
        $cloud  = self::setting('cloudinary_cloud_name', 'CLOUDINARY_CLOUD_NAME');
        $key    = self::setting('cloudinary_api_key', 'CLOUDINARY_API_KEY');
        $secret = self::setting('cloudinary_api_secret', 'CLOUDINARY_API_SECRET');
        if ($cloud === '' || $key === '' || $secret === '') return self::off('Not fully configured.');

        [$code, $body] = self::http('GET',
            'https://api.cloudinary.com/v1_1/' . rawurlencode($cloud) . '/usage',
            ['Accept: application/json'], null, $key . ':' . $secret);

        if ($code === 200) return self::ok('Answered for cloud "' . $cloud . '".');
        if ($code === 401) return self::bad('Rejected the key/secret pair (401).');

        return self::bad(self::httpWhy($code, $body));
    }

    /** The Apps Script behind Calendar and Meet. A GET, which its doGet answers. */
    private static function gas(): array
    {
        $url = GoogleMeetService::gasUrl();
        if ($url === '') return self::off('No Apps Script URL set.');

        [$code, $body] = self::http('GET', $url, ['Accept: application/json']);

        // Apps Script answers 302 to a googleusercontent URL on success; curl follows it.
        if ($code >= 200 && $code < 300) return self::ok('Answered.');
        if ($code === 401 || $code === 403) {
            return self::bad('Refused (' . $code . ') — the deployment is probably not set to '
                           . '"Anyone with the link".');
        }
        return self::bad(self::httpWhy($code, $body));
    }

    /**
     * SCHEDULED TASKS — the one that is not an HTTP call, and the one most often wrong.
     *
     * There is no shell on this host, so maintenance runs only if somebody pointed a
     * webcron at `/__cron/run`. Where that was never done, everything it drives is
     * silently dead — the door's greetings among them, which is exactly how a working
     * voice came to look like a broken one. Read from the log rather than asked.
     */
    private static function cron(): array
    {
        try {
            $row = DB::table('gates_cron_log')->where('job_name', 'maintenance')
                ->orderByDesc('id')->first();
        } catch (\Throwable) {
            return self::bad('The cron log could not be read.');
        }

        if (!$row) {
            return self::bad('Maintenance has never run. Nothing scheduled is happening — '
                           . 'point a webcron at /__cron/run. See Settings → Automation.');
        }

        // `ran_at`, which is what the column is called. Reading `created_at` gave null on
        // every row, strtotime('') is false, and the check reported "scheduled work has
        // stopped" on a platform whose cron was running perfectly — a diagnostic lying
        // about the one thing it exists to report. Caught by its own test.
        $at   = (string) ($row->ran_at ?? '');
        $mins = $at !== '' ? (int) round((time() - strtotime($at)) / 60) : PHP_INT_MAX;

        if ($mins > 180) {
            return self::bad('Last ran ' . self::ago($mins) . ' ago (' . $at . '). '
                           . 'Scheduled work has stopped.');
        }
        return self::ok('Last ran ' . self::ago($mins) . ' ago.');
    }

    // ══ plumbing ═════════════════════════════════════════════════════════════

    private static function ago(int $mins): string
    {
        if ($mins < 1)   return 'under a minute';
        if ($mins < 60)  return $mins . ' minute' . ($mins === 1 ? '' : 's');
        $h = (int) round($mins / 60);
        if ($h < 48)     return $h . ' hour' . ($h === 1 ? '' : 's');

        return (int) round($h / 24) . ' days';
    }

    /** @return array{configured:bool, ok:bool, detail:string} */
    private static function ok(string $d): array  { return ['configured' => true, 'ok' => true, 'detail' => $d]; }
    private static function bad(string $d): array { return ['configured' => true, 'ok' => false, 'detail' => $d]; }
    private static function off(string $d): array { return ['configured' => false, 'ok' => false, 'detail' => $d]; }

    /** A shared bearer-token JSON GET, for the providers whose check is exactly that. */
    private static function bearerJson(string $name, string $key, string $url): array
    {
        if ($key === '') return self::off('No API key set.');

        [$code, $body] = self::http('GET', $url,
            ['Authorization: Bearer ' . $key, 'Accept: application/json']);

        if ($code === 200) return self::ok('Answered.');
        if ($code === 401) return self::bad('Rejected the API key (401).');

        return self::bad(self::httpWhy($code, $body));
    }

    /**
     * The vendor's own words, trimmed — never a guess.
     *
     * "Check the key and the region" sent an operator to the wrong box for every failure
     * that was not the key. What a provider actually said is shorter and always right.
     */
    private static function httpWhy(int $code, string $body, string $where = ''): string
    {
        $j   = json_decode($body, true);
        $msg = '';
        if (is_array($j)) {
            foreach (['message', 'error', 'detail', 'error_description', 'description'] as $k) {
                if (isset($j[$k])) {
                    $msg = is_array($j[$k]) ? (string) ($j[$k]['message'] ?? '') : (string) $j[$k];
                    if ($msg !== '') break;
                }
            }
        }
        if ($msg === '') $msg = trim(strip_tags(mb_substr($body, 0, 160)));

        if ($code === 0) return 'No answer — the request did not get through' . ($msg !== '' ? ': ' . $msg : '.');

        return 'HTTP ' . $code . ($where !== '' ? ' on ' . $where : '')
             . ($msg !== '' ? ' — ' . $msg : '.');
    }

    /**
     * @param  string|null $body    null for GET
     * @param  string      $userpwd basic-auth pair, or '' for none
     * @return array{0:int, 1:string} status (0 on a transport failure) and the body
     */
    private static function http(string $method, string $url, array $headers,
                                 ?string $body = null, string $userpwd = ''): array
    {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($method !== 'GET')  $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($body !== null)     $opts[CURLOPT_POSTFIELDS] = $body;
        if ($userpwd !== '')    $opts[CURLOPT_USERPWD] = $userpwd;
        curl_setopt_array($ch, $opts);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        return [$code, is_string($raw) && $raw !== '' ? $raw : $err];
    }

    /** Settings first, `.env` as the fallback — the platform rule, no shell on production. */
    private static function setting(string $key, string $env): string
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', $key)->value('value');
            if (is_string($v) && trim($v) !== '') return trim($v);
        } catch (\Throwable) {}

        return trim((string) Env::get($env, ''));
    }
}
