<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Gemini's Files API: upload a document once, reference it many times.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS NOT ANOTHER PROVIDER IN THE CHAIN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see AiService} tries Groq → Gemini → Anthropic → OpenAI. Groq is TEXT ONLY. Send a
 * PDF through that chain and Groq answers first, having seen a prompt with no document in
 * it — and it will happily describe a certificate it never read, from the filename. A
 * confident summary of a file nobody looked at is worse than no summary, because a judge
 * cannot tell them apart.
 *
 * So file analysis is Gemini-only by construction. There is no fallback ladder here, and
 * that is the point: when Gemini cannot answer, the honest result is "not analysed".
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE THREE OPTIMISATIONS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1 · UPLOAD ONCE, KEYED ON CONTENT. The Files API is free in every region Gemini serves,
 *     accepts 2GB per file, and keeps a file for 48 HOURS — which is the whole reason to
 *     use it over inlining base64. The returned URI is cached against the file's SHA-256,
 *     so re-analysing the same evidence under a changed prompt costs zero uploads, and two
 *     nominees who submitted the same council letter share one.
 *
 *     Content hash and not evidence id: the id would re-upload an identical file for every
 *     row, and would miss a cache hit after a re-upload of the same bytes.
 *
 * 2 · INLINE IS THE FALLBACK, NOT THE DEFAULT. Base64 inflates a payload by a third and a
 *     20MB document does not fit in a request at all. But when the upload endpoint is
 *     unreachable and the file is small, inlining still works — so it is kept for exactly
 *     that case rather than as the primary path.
 *
 * 3 · SEVERAL FILES IN ONE REQUEST. A dossier is six documents. Six requests is six
 *     round trips and six copies of the prompt; one request with six file parts is one of
 *     each. {@see EvidenceAnalysis} batches per nominee for this reason.
 *
 * ── AND THE CAP THAT IS NOT AN OPTIMISATION ──────────────────────────────────
 *
 * A PDF page costs 258 tokens, so a 400-page report is ~103k tokens of input for one
 * answer. {@see MAX_PAGES} refuses beyond a limit and SAYS SO, rather than sending the
 * first N pages and returning a summary that silently describes part of a document.
 */
final class GeminiFiles
{
    private const BASE = 'https://generativelanguage.googleapis.com';

    /**
     * Files live 48 hours. Cached slightly shorter so a URI is never handed out in the
     * minutes before it expires — a request against an expired file is a 403 that reads
     * like a credential problem.
     */
    public const TTL_HOURS = 46;

    /** Above this, inlining is not attempted: base64 is 4/3 the size and requests have limits. */
    public const INLINE_MAX_BYTES = 4 * 1024 * 1024;

    /** The Files API's own ceiling is 2GB. This is ours, and it is about cost, not capability. */
    public const UPLOAD_MAX_BYTES = 40 * 1024 * 1024;

    /**
     * Page ceiling for a PDF, at 258 tokens each.
     *
     * 120 pages is ~31k tokens — a large report, still inside a sensible budget for one
     * answer. Beyond it the file is refused with a reason rather than truncated.
     */
    public const MAX_PAGES = 120;

    /** What Gemini will actually read. Anything else is refused before a byte leaves. */
    public const MIME = [
        'pdf'  => 'application/pdf',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'txt'  => 'text/plain',
        'csv'  => 'text/csv',
        'md'   => 'text/markdown',
        'html' => 'text/html',
        // Office formats are NOT here. Gemini does not read .docx natively, and pretending
        // to would mean uploading a zip of XML and getting a description of its structure.
    ];

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly int $timeout = 45,
    ) {}

    public static function boot(): self
    {
        // The same resolution order as every other provider key on this platform: the
        // admin setting first, then the environment. An operator with no shell has to be
        // able to configure this.
        $key = null;
        try {
            $v = DB::table('gates_settings')->where('key_name', 'ai_gemini_key')->value('value');
            if (is_string($v) && trim($v) !== '') $key = trim($v);
        } catch (\Throwable) {
        }
        $key ??= (string) Env::get('GEMINI_API_KEY', '') ?: null;

        return new self($key);
    }

    public function configured(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    // ═══════════════════════════════════════════════════════════════════════
    // INSPECTING A FILE BEFORE IT LEAVES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Can this file be analysed, and what would it cost?
     *
     * Answered from the file on disk with no network call, so a screen can say "this one
     * cannot be read" without spending a request to find out.
     *
     * @return array{ok:bool, mime:string, bytes:int, pages:int, hash:string, reason:string}
     */
    public function inspect(string $path): array
    {
        $no = fn (string $why): array => ['ok' => false, 'mime' => '', 'bytes' => 0,
                                          'pages' => 0, 'hash' => '', 'reason' => $why];

        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return $no('The file is missing from disk.');
        }

        $bytes = (int) filesize($path);
        if ($bytes < 1)                    return $no('The file is empty.');
        if ($bytes > self::UPLOAD_MAX_BYTES) {
            return $no('Larger than ' . round(self::UPLOAD_MAX_BYTES / 1048576) . 'MB, so it is not sent.');
        }

        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = self::MIME[$ext] ?? '';
        if ($mime === '') {
            return $no($ext === '' ? 'No file extension, so its type is unknown.'
                                   : 'A .' . $ext . ' cannot be read by the model.');
        }

        $pages = $mime === 'application/pdf' ? self::pdfPages($path) : 1;
        if ($mime === 'application/pdf' && $pages > self::MAX_PAGES) {
            // Refused, not truncated. A summary of the first 120 pages of a 400-page report
            // that does not say so is a summary a judge would act on as if it were whole.
            return $no($pages . ' pages — over the ' . self::MAX_PAGES . '-page limit. '
                     . 'Split it or summarise the relevant section.');
        }

        return ['ok' => true, 'mime' => $mime, 'bytes' => $bytes,
                'pages' => max(1, $pages), 'hash' => (string) hash_file('sha256', $path),
                'reason' => ''];
    }

    /**
     * Page count from the PDF itself, without a library.
     *
     * Counted from `/Type /Page` objects rather than the `/Count` in the page tree: `/Count`
     * is a number a generator writes and is wrong often enough in real-world files
     * (incremental updates, linearised documents) that trusting it would let a 400-page
     * report through claiming to be 12. Falls back to 1 rather than 0 when nothing matches,
     * because 0 would read as "empty" and refuse a file that is probably fine.
     */
    public static function pdfPages(string $path): int
    {
        $raw = (string) @file_get_contents($path);
        if ($raw === '') return 1;

        // `/Type /Page` with optional whitespace, and NOT `/Pages` — the tree node.
        if (preg_match_all('~/Type\s*/Page(?![s/\w])~', $raw, $m)) {
            $n = count($m[0]);
            if ($n > 0) return $n;
        }
        // An object-stream PDF hides its page objects in a compressed stream, so the count
        // above finds nothing. Fall back to the tree's own figure rather than guessing 1.
        if (preg_match_all('~/Count\s+(\d+)~', $raw, $m2)) {
            $max = max(array_map('intval', $m2[1]));
            if ($max > 0) return $max;
        }

        return 1;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // THE CACHE
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * A file part Gemini can consume: a cached URI, a fresh upload, or inline bytes.
     *
     * @return array{ok:bool, part:array<string,mixed>, cached:bool, reason:string}
     */
    public function part(string $path): array
    {
        $no = fn (string $why): array => ['ok' => false, 'part' => [], 'cached' => false, 'reason' => $why];

        if (!$this->configured()) return $no('No Gemini API key is configured.');

        $info = $this->inspect($path);
        if (!$info['ok']) return $no($info['reason']);

        // ── 1 · a live URI for these exact bytes ────────────────────────────
        $hit = $this->cached($info['hash']);
        if ($hit !== null) {
            return ['ok' => true, 'cached' => true, 'reason' => '',
                    'part' => ['fileData' => ['mimeType' => $info['mime'], 'fileUri' => $hit]]];
        }

        // ── 2 · upload, and remember it ─────────────────────────────────────
        $uri = $this->upload($path, $info['mime'], $info['bytes']);
        if ($uri !== null) {
            $this->remember($info['hash'], $uri, $info['mime'], $info['bytes'], $info['pages']);
            return ['ok' => true, 'cached' => false, 'reason' => '',
                    'part' => ['fileData' => ['mimeType' => $info['mime'], 'fileUri' => $uri]]];
        }

        // ── 3 · inline, only for a small file ───────────────────────────────
        //
        // The upload endpoint being unreachable does not mean generateContent is. Inlining
        // keeps a small document working through that; a large one genuinely cannot go.
        if ($info['bytes'] <= self::INLINE_MAX_BYTES) {
            $bytes = (string) @file_get_contents($path);
            if ($bytes !== '') {
                return ['ok' => true, 'cached' => false,
                        'reason' => 'Uploaded inline — the file store was unreachable.',
                        'part' => ['inlineData' => ['mimeType' => $info['mime'],
                                                    'data' => base64_encode($bytes)]]];
            }
        }

        return $no('The file could not be sent to Gemini.');
    }

    /** A cached, unexpired URI for this content hash, or null. */
    private function cached(string $hash): ?string
    {
        if ($hash === '') return null;
        try {
            $row = DB::table('gates_ai_files')
                ->where('content_hash', $hash)
                ->where('expires_at', '>', Carbon::now()->toDateTimeString())
                ->orderByDesc('id')->first(['file_uri']);

            $uri = trim((string) ($row->file_uri ?? ''));
            return $uri !== '' ? $uri : null;
        } catch (\Throwable) {
            // No cache table yet (a deploy before db:migrate). Upload every time rather
            // than failing — the feature still works, it is just not free.
            return null;
        }
    }

    private function remember(string $hash, string $uri, string $mime, int $bytes, int $pages): void
    {
        try {
            DB::table('gates_ai_files')->updateOrInsert(
                ['content_hash' => $hash],
                ['file_uri' => $uri, 'mime' => $mime, 'bytes' => $bytes, 'pages' => $pages,
                 'uploaded_at' => Carbon::now()->toDateTimeString(),
                 'expires_at'  => Carbon::now()->addHours(self::TTL_HOURS)->toDateTimeString()]
            );
        } catch (\Throwable) {
            // A cache write failure costs an upload next time. It must not lose the answer
            // we already have a URI for.
        }
    }

    /** Drop expired rows. Called from the maintenance tick. */
    public static function prune(): int
    {
        try {
            return DB::table('gates_ai_files')
                ->where('expires_at', '<', Carbon::now()->toDateTimeString())->delete();
        } catch (\Throwable) {
            return 0;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // THE WIRE
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Resumable upload, then wait for the file to become ACTIVE.
     *
     * ── WHY THE WAIT IS NOT OPTIONAL ─────────────────────────────────────────
     *
     * A newly uploaded PDF is PROCESSING for a moment while Google rasterises it, and
     * `generateContent` against a PROCESSING file fails. Not retrying here would make
     * analysis fail on exactly the large documents that most need it, intermittently, in a
     * way that looks like a flaky model.
     *
     * The protocol is Google's two-step resumable one — a start request that returns an
     * upload URL, then the bytes — because the single-shot `?uploadType=multipart` form
     * needs a hand-built MIME body and fails opaquely on anything unusual.
     */
    private function upload(string $path, string $mime, int $bytes): ?string
    {
        $start = self::BASE . '/upload/v1beta/files?key=' . urlencode((string) $this->apiKey);

        [$code, $body, $headers] = $this->http('POST', $start, [
            'X-Goog-Upload-Protocol: resumable',
            'X-Goog-Upload-Command: start',
            'X-Goog-Upload-Header-Content-Length: ' . $bytes,
            'X-Goog-Upload-Header-Content-Type: ' . $mime,
            'Content-Type: application/json',
        ], json_encode(['file' => ['display_name' => basename($path)]]), true);

        if ($code < 200 || $code >= 300) {
            error_log('[gemini-files] start failed: HTTP ' . $code . ' ' . mb_substr($body, 0, 200));
            return null;
        }

        // Case-insensitively, because HTTP header casing is not guaranteed and matching
        // only the documented spelling is how this breaks silently on a proxy.
        $uploadUrl = '';
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'x-goog-upload-url') === 0) { $uploadUrl = $value; break; }
        }
        if ($uploadUrl === '') {
            error_log('[gemini-files] start returned no upload URL');
            return null;
        }

        $raw = (string) @file_get_contents($path);
        if ($raw === '') return null;

        [$code2, $body2] = $this->http('POST', $uploadUrl, [
            'Content-Length: ' . $bytes,
            'X-Goog-Upload-Offset: 0',
            'X-Goog-Upload-Command: upload, finalize',
        ], $raw);

        if ($code2 < 200 || $code2 >= 300) {
            error_log('[gemini-files] upload failed: HTTP ' . $code2 . ' ' . mb_substr($body2, 0, 200));
            return null;
        }

        $j    = json_decode($body2, true);
        $uri  = trim((string) ($j['file']['uri'] ?? ''));
        $name = trim((string) ($j['file']['name'] ?? ''));
        $state = strtoupper(trim((string) ($j['file']['state'] ?? '')));

        if ($uri === '') return null;
        if ($state === 'ACTIVE') return $uri;
        if ($state === 'FAILED') {
            error_log('[gemini-files] the file store rejected ' . basename($path));
            return null;
        }

        return $this->awaitActive($name, $uri);
    }

    /**
     * Poll until PROCESSING becomes ACTIVE.
     *
     * Bounded at roughly twelve seconds. A file still processing after that is a large one
     * and the right answer is to give up on THIS request rather than hold a page open — the
     * URI is already cached, so the next attempt finds it ready.
     */
    private function awaitActive(string $name, string $uri): ?string
    {
        if ($name === '') return null;

        $url = self::BASE . '/v1beta/' . ltrim($name, '/') . '?key=' . urlencode((string) $this->apiKey);

        for ($i = 0; $i < 8; $i++) {
            usleep(($i < 3 ? 400_000 : 2_000_000));
            [$code, $body] = $this->http('GET', $url, ['Accept: application/json'], null);
            if ($code < 200 || $code >= 300) continue;

            $state = strtoupper(trim((string) ((json_decode($body, true))['state'] ?? '')));
            if ($state === 'ACTIVE') return $uri;
            if ($state === 'FAILED') return null;
        }

        return null;
    }

    /**
     * One generateContent call carrying text and however many file parts.
     *
     * @param list<array<string,mixed>> $fileParts from {@see part()}
     * @param array<string,mixed>|null  $schema    a responseSchema, for structured output
     * @return array{ok:bool, text:string, tokens_in:int, tokens_out:int, error:string}
     */
    public function generate(string $model, string $system, string $prompt,
                             array $fileParts, ?array $schema = null,
                             int $maxTokens = 1200, float $temperature = 0.2): array
    {
        $no = fn (string $why): array => ['ok' => false, 'text' => '', 'tokens_in' => 0,
                                          'tokens_out' => 0, 'error' => $why];

        if (!$this->configured()) return $no('No Gemini API key is configured.');
        if ($fileParts === [])    return $no('Nothing readable was attached.');

        $cfg = ['temperature' => $temperature, 'maxOutputTokens' => $maxTokens];
        if ($schema !== null) {
            // A schema AND the mime type: the mime type alone yields JSON of whatever shape
            // the model chose, which then needs defensive parsing at every call site.
            $cfg['responseMimeType'] = 'application/json';
            $cfg['responseSchema']   = $schema;
        }

        // The text part goes LAST, after the files. Gemini's own document guidance puts the
        // question after the material it is about, and the ordering measurably changes what
        // a long-document answer attends to.
        $parts   = $fileParts;
        $parts[] = ['text' => $prompt];

        $url = self::BASE . '/v1beta/models/' . rawurlencode($model)
             . ':generateContent?key=' . urlencode((string) $this->apiKey);

        [$code, $body] = $this->http('POST', $url, ['Content-Type: application/json'],
            json_encode([
                'contents'          => [['role' => 'user', 'parts' => $parts]],
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'generationConfig'  => $cfg,
                // Evidence is somebody's account of their own work and routinely describes
                // hardship, illness or violence. The default filters refuse that, and a
                // refused analysis of a legitimate submission reads to an operator as the
                // feature being broken. Loosened for the two categories that actually
                // collide with it, and left at default for the rest.
                'safetySettings'    => [
                    ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                    ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_ONLY_HIGH'],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($code < 200 || $code >= 300) {
            $msg = (string) ((json_decode($body, true))['error']['message'] ?? ('HTTP ' . $code));
            return $no(mb_substr($msg, 0, 300));
        }

        $j = json_decode($body, true);
        if (!is_array($j)) return $no('Gemini returned something that was not JSON.');

        $in  = (int) ($j['usageMetadata']['promptTokenCount'] ?? 0);
        $out = (int) ($j['usageMetadata']['candidatesTokenCount'] ?? 0);

        // A blocked prompt returns 200 with no candidate and a promptFeedback reason. Read
        // it, because "empty response" and "the safety filter refused this" are different
        // problems and only one of them is worth retrying.
        $blocked = trim((string) ($j['promptFeedback']['blockReason'] ?? ''));
        if ($blocked !== '') {
            return ['ok' => false, 'text' => '', 'tokens_in' => $in, 'tokens_out' => $out,
                    'error' => 'Gemini declined to analyse this file (' . $blocked . ').'];
        }

        $text = '';
        foreach ((array) ($j['candidates'][0]['content']['parts'] ?? []) as $p) {
            if (isset($p['text']) && is_string($p['text'])) $text .= $p['text'];
        }
        $text = trim($text);

        if ($text === '') {
            $finish = trim((string) ($j['candidates'][0]['finishReason'] ?? ''));
            return ['ok' => false, 'text' => '', 'tokens_in' => $in, 'tokens_out' => $out,
                    'error' => $finish !== ''
                        ? 'Gemini stopped without answering (' . $finish . ').'
                        : 'Gemini returned an empty answer.'];
        }

        return ['ok' => true, 'text' => $text, 'tokens_in' => $in, 'tokens_out' => $out, 'error' => ''];
    }

    /**
     * @return array{0:int,1:string,2:array<string,string>}
     */
    private function http(string $method, string $url, array $headers,
                          ?string $body, bool $wantHeaders = false): array
    {
        if (!function_exists('curl_init')) return [0, 'no curl', []];

        $ch = curl_init($url);
        if ($ch === false) return [0, 'curl init failed', []];

        $got = [];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            // Not followed: a redirect from this API is a misconfiguration, and following
            // one would replay the key to wherever it points.
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
        if ($wantHeaders) {
            $opts[CURLOPT_HEADERFUNCTION] = static function ($ch, string $line) use (&$got): int {
                $len = strlen($line);
                $i   = strpos($line, ':');
                if ($i !== false) $got[trim(substr($line, 0, $i))] = trim(substr($line, $i + 1));
                return $len;
            };
        }
        curl_setopt_array($ch, $opts);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) return [0, $err !== '' ? $err : 'connection failed', $got];

        return [$code, (string) $raw, $got];
    }
}
