<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Cloudflare R2 — object storage for Pulse media.
 *
 * ── WHY R2 AND NOT THE LOCAL DISK ────────────────────────────────────────────
 *
 * The target host is shared cPanel with a fixed, small disk quota. A feed that
 * accepts 25MB videos fills that quota, and the failure mode when it does is not
 * "uploads stop" — it is the whole site failing to write sessions, logs and the
 * SQLite cache, because everything shares one allowance. R2 also serves media
 * from Cloudflare's edge rather than from one origin in one region, which for an
 * audience spread across the continent is the difference between a feed that
 * scrolls and one that stalls.
 *
 * ── WHY SIGV4 BY HAND AND NOT THE AWS SDK ────────────────────────────────────
 *
 * aws/aws-sdk-php is ~15MB of vendor for the four operations used here, on a
 * host where the whole application is deployed by unzipping an archive over FTP.
 * The signing algorithm is a page of code and is pinned by tests. Guzzle is
 * already a dependency and does the transport.
 *
 * ── AND WHY IT IS ALWAYS OPTIONAL ────────────────────────────────────────────
 *
 * `configured()` is false until all four settings exist, and every caller keeps
 * its local path for that case. An operator who has not signed up for R2 gets a
 * working feed on local disk; nothing here is load-bearing for correctness, only
 * for capacity.
 */
final class R2Service
{
    private const REGION  = 'auto';        // R2 ignores region but SigV4 requires one
    private const SERVICE = 's3';
    private const TIMEOUT = 25;

    public function __construct(
        private readonly ?ClientInterface $http = null,
        private readonly ?LoggerInterface $log = null,
    ) {}

    /**
     * @return array{account:string, bucket:string, key:string, secret:string, public:string}|null
     */
    public static function config(): ?array
    {
        $account = trim((string) Env::get('R2_ACCOUNT_ID', ''));
        $bucket  = trim((string) Env::get('R2_BUCKET', ''));
        $key     = trim((string) Env::get('R2_ACCESS_KEY_ID', ''));
        $secret  = trim((string) Env::get('R2_SECRET_ACCESS_KEY', ''));
        if ($account === '' || $bucket === '' || $key === '' || $secret === '') return null;

        return [
            'account' => $account,
            'bucket'  => $bucket,
            'key'     => $key,
            'secret'  => $secret,
            // The public base. R2 buckets are private by default; this is either a
            // custom domain or the r2.dev subdomain the operator enabled. Without
            // it we would store objects nobody can read, so it falls back to the
            // S3 endpoint, which at least fails visibly rather than silently.
            'public'  => rtrim((string) (Env::get('R2_PUBLIC_URL', '') ?: "https://{$account}.r2.cloudflarestorage.com/{$bucket}"), '/'),
        ];
    }

    public static function configured(): bool
    {
        return self::config() !== null;
    }

    /**
     * Upload a local file.
     *
     * @return array{ok:bool, url?:string, key?:string, error?:string}
     */
    public function put(string $absPath, string $objectKey, string $contentType): array
    {
        $cfg = self::config();
        if ($cfg === null)          return ['ok' => false, 'error' => 'R2 is not configured.'];
        if (!is_file($absPath))     return ['ok' => false, 'error' => 'File not found: ' . basename($absPath)];
        if (!is_readable($absPath)) return ['ok' => false, 'error' => 'File not readable.'];

        $body = @file_get_contents($absPath);
        if ($body === false) return ['ok' => false, 'error' => 'Could not read the file.'];

        $objectKey = ltrim($objectKey, '/');
        $host = $cfg['account'] . '.r2.cloudflarestorage.com';
        $path = '/' . $cfg['bucket'] . '/' . $objectKey;

        $headers = $this->sign('PUT', $host, $path, $body, $cfg, [
            'content-type' => $contentType,
            // Media is immutable — the key contains random bytes, so a given URL
            // never changes content and can be cached for as long as anyone likes.
            'cache-control' => 'public, max-age=31536000, immutable',
        ]);

        try {
            $client = $this->http ?? new Client(['timeout' => self::TIMEOUT]);
            $res = $client->request('PUT', 'https://' . $host . $path, [
                'headers' => $headers, 'body' => $body,
                'timeout' => self::TIMEOUT, 'http_errors' => false,
            ]);
            $code = $res->getStatusCode();
            if ($code >= 200 && $code < 300) {
                return ['ok' => true, 'key' => $objectKey, 'url' => $cfg['public'] . '/' . $objectKey];
            }
            $msg = 'HTTP ' . $code . ' ' . mb_substr((string) $res->getBody(), 0, 200);
            $this->log?->warning('[r2] upload rejected', ['key' => $objectKey, 'err' => $msg]);
            return ['ok' => false, 'error' => $msg];
        } catch (\Throwable $e) {
            $this->log?->warning('[r2] upload failed', ['key' => $objectKey, 'err' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Remove an object. Used when moderation rejects something already uploaded. */
    public function delete(string $objectKey): bool
    {
        $cfg = self::config();
        if ($cfg === null) return false;

        $objectKey = ltrim($objectKey, '/');
        $host = $cfg['account'] . '.r2.cloudflarestorage.com';
        $path = '/' . $cfg['bucket'] . '/' . $objectKey;
        $headers = $this->sign('DELETE', $host, $path, '', $cfg);

        try {
            $client = $this->http ?? new Client(['timeout' => self::TIMEOUT]);
            $res = $client->request('DELETE', 'https://' . $host . $path, [
                'headers' => $headers, 'timeout' => self::TIMEOUT, 'http_errors' => false,
            ]);
            return $res->getStatusCode() < 300;
        } catch (\Throwable $e) {
            $this->log?->warning('[r2] delete failed', ['key' => $objectKey, 'err' => $e->getMessage()]);
            return false;
        }
    }

    // ── AWS Signature Version 4 ──────────────────────────────────────────────

    /**
     * Sign a request.
     *
     * The canonical request must match, byte for byte, what the server rebuilds
     * from the wire — which is why the payload hash is over the exact bytes being
     * sent, the header list is sorted, and the values are trimmed. A mismatch
     * anywhere produces a 403 with no hint as to which of the four parts was
     * wrong, so the ordering here is load-bearing rather than stylistic.
     *
     * @param array{account:string,bucket:string,key:string,secret:string,public:string} $cfg
     * @return array<string,string>
     */
    private function sign(string $method, string $host, string $path, string $body, array $cfg, array $extra = []): array
    {
        $now       = gmdate('Ymd\THis\Z');
        $shortDate = substr($now, 0, 8);
        $payload   = hash('sha256', $body);

        $headers = array_merge([
            'host'                 => $host,
            'x-amz-content-sha256' => $payload,
            'x-amz-date'           => $now,
        ], $extra);

        // Canonical headers: lowercase names, trimmed values, sorted by name.
        $canonNames = array_keys($headers);
        sort($canonNames);
        $canonHeaders = '';
        foreach ($canonNames as $n) $canonHeaders .= strtolower($n) . ':' . trim((string) $headers[$n]) . "\n";
        $signedHeaders = implode(';', array_map('strtolower', $canonNames));

        $canonicalRequest = implode("\n", [
            $method,
            // The path is already the escaped object key; R2 keys here are
            // hex+slash+dot only, so no further encoding is needed or wanted —
            // double-encoding the slashes is the classic way to get a 403.
            $path,
            '',                    // no query string on these operations
            $canonHeaders,
            $signedHeaders,
            $payload,
        ]);

        $scope     = $shortDate . '/' . self::REGION . '/' . self::SERVICE . '/aws4_request';
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256', $now, $scope, hash('sha256', $canonicalRequest),
        ]);

        $kDate    = hash_hmac('sha256', $shortDate,     'AWS4' . $cfg['secret'], true);
        $kRegion  = hash_hmac('sha256', self::REGION,   $kDate,   true);
        $kService = hash_hmac('sha256', self::SERVICE,  $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $headers['Authorization'] = 'AWS4-HMAC-SHA256 '
            . 'Credential=' . $cfg['key'] . '/' . $scope . ', '
            . 'SignedHeaders=' . $signedHeaders . ', '
            . 'Signature=' . $signature;

        return $headers;
    }

    /** A collision-proof object key: bucket path + random name + real extension. */
    public static function keyFor(string $bucket, string $ext): string
    {
        return sprintf('%s/%s/%s/%s.%s', trim($bucket, '/'), date('Y'), date('m'),
                       bin2hex(random_bytes(16)), ltrim($ext, '.'));
    }
}
