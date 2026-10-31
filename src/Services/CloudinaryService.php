<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use GuzzleHttp\Client;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Log\LoggerInterface;

/**
 * Cloudinary image hosting — upload, delete, and the delivery URL builder.
 *
 * ── WHY NOT THE OFFICIAL SDK ─────────────────────────────────────────────────
 *
 * `cloudinary/cloudinary_php` pulls in its own HTTP stack and a monolog/guzzle
 * constraint set on top of the eight this project already pins, and it is deployed by
 * uploading a vendor tree to shared cPanel hosting where `composer update` is a
 * gamble. The API surface actually needed here is two signed POSTs and a URL format,
 * all three of which are stable, documented and about ninety lines. Guzzle is already
 * a direct dependency. So this talks to the REST API directly and the dependency graph
 * does not move.
 *
 * ── THE SIGNATURE ────────────────────────────────────────────────────────────
 *
 * Cloudinary authenticates a server-side upload with `sha1(sorted_params + api_secret)`
 * where `sorted_params` is the alphabetically-sorted `k=v&k=v` of everything being
 * sent EXCEPT `file`, `api_key`, `resource_type` and `cloud_name`. Getting the
 * exclusion list or the sort wrong yields a 401 that reads like bad credentials, so
 * the set is pinned in {@see EXCLUDED_FROM_SIGNATURE} rather than spelled out at each
 * call site.
 *
 * ── OFF BY DEFAULT, AND SILENT ABOUT IT ──────────────────────────────────────
 *
 * {@see enabled()} is false until an operator sets the credentials, and every caller
 * is written so that false means "keep storing locally" rather than "fail". A CDN is
 * an optimisation; a nomination form that rejects a photo because a CDN key is missing
 * is an outage. {@see \AfricaGates\Admin\Services\UploadService} and
 * {@see MediaMigrationService} both take that path.
 */
final class CloudinaryService
{
    /** Sent with the request but never part of the signature payload. */
    private const EXCLUDED_FROM_SIGNATURE = ['file', 'api_key', 'resource_type', 'cloud_name', 'signature'];

    /** Cloudinary's delivery host. Also how {@see isRemote()} recognises its own URLs. */
    public const DELIVERY_HOST = 'res.cloudinary.com';

    /** Upload timeout. Generous — an admin is waiting — but never unbounded. */
    private const TIMEOUT = 25;

    public function __construct(
        private readonly ?LoggerInterface $log = null,
        private readonly ?Client          $http = null,
    ) {}

    // ── Configuration ────────────────────────────────────────────────────────

    /**
     * Credentials, from either spelling.
     *
     * `CLOUDINARY_URL` (`cloudinary://key:secret@cloud`) is what Cloudinary's own
     * dashboard hands you, so it is supported verbatim — an operator should be able to
     * paste what they were given. The three discrete names are supported because a
     * PaaS secret manager stores values, not URLs, and because a URL-encoded secret
     * containing `@` or `/` is a well-known way to lose an afternoon.
     *
     * @return array{cloud:string, key:string, secret:string}|null
     */
    public static function config(): ?array
    {
        $pick   = self::resolver();
        $cloud  = $pick('cloudinary_cloud_name', 'CLOUDINARY_CLOUD_NAME');
        $key    = $pick('cloudinary_api_key',    'CLOUDINARY_API_KEY');
        $secret = $pick('cloudinary_api_secret', 'CLOUDINARY_API_SECRET');

        if ($cloud === '' || $key === '' || $secret === '') {
            $url = $pick('cloudinary_url', 'CLOUDINARY_URL');
            if ($url !== '' && preg_match('~^cloudinary://([^:]+):([^@]+)@(.+)$~', $url, $m) === 1) {
                $key    = rawurldecode($m[1]);
                $secret = rawurldecode($m[2]);
                $cloud  = trim($m[3], '/');
            }
        }

        if ($cloud === '' || $key === '' || $secret === '') return null;
        return ['cloud' => $cloud, 'key' => $key, 'secret' => $secret];
    }

    /**
     * `gates_settings` first, `.env` as the fallback — for every value above.
     *
     * ── WHY THIS IS NOT ENV-ONLY ANY MORE ────────────────────────────────────
     *
     * It was, and the Media screen's remedy for it read "add
     * CLOUDINARY_URL=cloudinary://key:secret@cloud to .env". There is no SSH on
     * production. So the panel could tell an operator their image hosting was
     * unconfigured and hand them an instruction they had no way to carry out — the
     * GAS_URL failure exactly, on a different screen. The panel's own "configured"
     * light reads through this resolver, so it now goes green from the settings form
     * that sets it.
     *
     * @return \Closure(string, string): string
     */
    private static function resolver(): \Closure
    {
        $s = [];
        try { $s = DB::table('gates_settings')->pluck('value', 'key_name')->all(); }
        catch (\Throwable) {}

        return static fn (string $key, string $env): string
            => trim((string) ($s[$key] ?? '')) ?: (string) Env::get($env, '');
    }

    /** True when uploads should go to Cloudinary. False means "store locally", never "fail". */
    public static function enabled(): bool
    {
        return self::config() !== null;
    }

    /** The cloud name, or '' — needed to build a delivery URL without credentials in hand. */
    public static function cloudName(): string
    {
        return (string) (self::config()['cloud'] ?? '');
    }

    /**
     * Root folder for everything this application uploads.
     *
     * Namespaced so one Cloudinary account can host several sites without a
     * migration sweep colliding with somebody else's `nominees/` folder.
     */
    public static function rootFolder(): string
    {
        $folder = (self::resolver())('cloudinary_folder', 'CLOUDINARY_FOLDER');

        return trim($folder !== '' ? $folder : 'africa-gates', '/');
    }

    // ── Upload ───────────────────────────────────────────────────────────────

    /**
     * Upload a local file.
     *
     * `$publicId` is optional and, when given, DETERMINISTIC — the migration sweep
     * derives it from the source path so that re-running after a partial failure
     * overwrites the same asset instead of accumulating a second copy of every photo.
     * That is also why `overwrite` is true: idempotence is worth more here than
     * protection against an accidental replace, because the only writer is this
     * codebase and the only thing it ever writes is the same bytes again.
     *
     * @return array{ok:bool, public_id?:string, url?:string, width?:int, height?:int,
     *               bytes?:int, format?:string, version?:int, error?:string}
     */
    public function upload(string $absPath, string $folder = '', ?string $publicId = null): array
    {
        $cfg = self::config();
        if ($cfg === null)       return ['ok' => false, 'error' => 'Cloudinary is not configured.'];
        if (!is_file($absPath))  return ['ok' => false, 'error' => 'File not found: ' . $absPath];
        if (!is_readable($absPath)) return ['ok' => false, 'error' => 'File not readable: ' . $absPath];

        $params = ['timestamp' => (string) time()];
        $dir = trim(self::rootFolder() . '/' . trim($folder, '/'), '/');
        if ($dir !== '')       $params['folder'] = $dir;
        if ($publicId !== null && $publicId !== '') {
            $params['public_id'] = $publicId;
            $params['overwrite'] = 'true';
            // Required alongside overwrite, or Cloudinary appends a random suffix and
            // the deterministic id we just asked for is not the one we get back.
            $params['unique_filename'] = 'false';
        }

        $params['signature'] = self::sign($params, $cfg['secret']);
        $params['api_key']   = $cfg['key'];

        $multipart = [];
        foreach ($params as $name => $value) {
            $multipart[] = ['name' => $name, 'contents' => $value];
        }
        $handle = fopen($absPath, 'rb');
        if ($handle === false) return ['ok' => false, 'error' => 'Could not open ' . $absPath];
        $multipart[] = ['name' => 'file', 'contents' => $handle, 'filename' => basename($absPath)];

        try {
            $client = $this->http ?? new Client(['timeout' => self::TIMEOUT]);
            $res = $client->post(
                'https://api.cloudinary.com/v1_1/' . rawurlencode($cfg['cloud']) . '/image/upload',
                ['multipart' => $multipart, 'timeout' => self::TIMEOUT, 'http_errors' => false]
            );
            $body = (string) $res->getBody();
            $json = json_decode($body, true);
            if (!is_array($json)) {
                return ['ok' => false, 'error' => 'Unreadable response (HTTP ' . $res->getStatusCode() . ')'];
            }
            if ($res->getStatusCode() >= 300 || !isset($json['public_id'], $json['secure_url'])) {
                $msg = (string) ($json['error']['message'] ?? 'HTTP ' . $res->getStatusCode());
                $this->log?->warning('[cloudinary] upload rejected', ['file' => basename($absPath), 'err' => $msg]);
                return ['ok' => false, 'error' => $msg];
            }
            return [
                'ok'        => true,
                'public_id' => (string) $json['public_id'],
                'url'       => (string) $json['secure_url'],
                'width'     => (int) ($json['width'] ?? 0),
                'height'    => (int) ($json['height'] ?? 0),
                'bytes'     => (int) ($json['bytes'] ?? 0),
                'format'    => (string) ($json['format'] ?? ''),
                'version'   => (int) ($json['version'] ?? 0),
            ];
        } catch (\Throwable $e) {
            $this->log?->error('[cloudinary] upload failed', ['file' => basename($absPath), 'err' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            if (is_resource($handle)) @fclose($handle);
        }
    }

    /**
     * Delete an asset. True only when Cloudinary confirms it is gone.
     *
     * `not found` counts as success: the caller's intent is "this asset must not
     * exist", and reporting failure for an asset that was already deleted would make
     * the admin media delete un-retryable.
     */
    public function destroy(string $publicId): bool
    {
        $cfg = self::config();
        if ($cfg === null || $publicId === '') return false;

        $params = ['public_id' => $publicId, 'timestamp' => (string) time()];
        $params['signature'] = self::sign($params, $cfg['secret']);
        $params['api_key']   = $cfg['key'];

        try {
            $client = $this->http ?? new Client(['timeout' => self::TIMEOUT]);
            $res = $client->post(
                'https://api.cloudinary.com/v1_1/' . rawurlencode($cfg['cloud']) . '/image/destroy',
                ['form_params' => $params, 'timeout' => self::TIMEOUT, 'http_errors' => false]
            );
            $json = json_decode((string) $res->getBody(), true);
            $result = is_array($json) ? (string) ($json['result'] ?? '') : '';
            return $result === 'ok' || $result === 'not found';
        } catch (\Throwable $e) {
            $this->log?->warning('[cloudinary] destroy failed', ['public_id' => $publicId, 'err' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * `sha1(sorted k=v&k=v + api_secret)`.
     *
     * Kept static and side-effect-free so the unit test can pin it against
     * Cloudinary's documented example without any network or credentials.
     */
    public static function sign(array $params, string $secret): string
    {
        $signable = [];
        foreach ($params as $k => $v) {
            if (in_array($k, self::EXCLUDED_FROM_SIGNATURE, true)) continue;
            if ($v === null || $v === '') continue;
            $signable[(string) $k] = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        }
        ksort($signable);
        $parts = [];
        foreach ($signable as $k => $v) $parts[] = $k . '=' . $v;
        return sha1(implode('&', $parts) . $secret);
    }

    // ── Delivery ─────────────────────────────────────────────────────────────

    /** Is this stored value a Cloudinary delivery URL (rather than a local path)? */
    public static function isRemote(?string $stored): bool
    {
        if ($stored === null || $stored === '') return false;
        $host = parse_url($stored, PHP_URL_HOST);
        return is_string($host) && (
            $host === self::DELIVERY_HOST || str_ends_with($host, '.' . self::DELIVERY_HOST)
        );
    }

    /**
     * Apply a transformation to a Cloudinary delivery URL.
     *
     * Cloudinary URLs are `…/image/upload/[transformation/][vNNN/]public_id.ext`, so a
     * transformation is inserted by splitting on `/upload/`. Two things make that
     * non-trivial, and both are real cases here:
     *
     *  • The stored URL may ALREADY carry a transformation — the flier asks for a
     *    face-cropped derivative of a value some other caller already sized. Appending
     *    would chain them (the face crop applied to an already-square thumbnail), so
     *    an existing leading transformation segment is REPLACED, not stacked.
     *  • A version segment (`v1730…`) must stay in front of the public id. It is what
     *    makes the URL immutable-cacheable, and moving or dropping it turns every
     *    delivery into a cache miss.
     *
     * A non-Cloudinary value is returned untouched, so this is safe to call on any
     * stored path at all — which is the point: {@see \AfricaGates\Support\Media} calls
     * it for every image on the site, local ones included.
     */
    public static function transformed(?string $url, string $transformation): ?string
    {
        if ($url === null || $url === '') return $url;
        if ($transformation === '' || !self::isRemote($url)) return $url;

        $marker = '/upload/';
        $at = strpos($url, $marker);
        if ($at === false) return $url;

        $head = substr($url, 0, $at + strlen($marker));
        $tail = substr($url, $at + strlen($marker));

        // Drop an existing transformation segment, if the first segment is one. A
        // transformation segment carries `k_v` parameters; a version segment is
        // `v` + digits; a bare public id has neither an underscore-parameter shape
        // nor a slash before its extension.
        $slash = strpos($tail, '/');
        if ($slash !== false) {
            $first = substr($tail, 0, $slash);
            if (preg_match('/^v\d+$/', $first) !== 1 && preg_match('/(^|,)[a-z]{1,3}_[^,\/]+/', $first) === 1) {
                $tail = substr($tail, $slash + 1);
            }
        }

        return $head . trim($transformation, '/') . '/' . $tail;
    }

    /**
     * The public id inside a delivery URL, or null.
     *
     * Used by the admin media delete so removing an item removes the asset too, and by
     * the migration ledger to recognise an already-migrated row.
     */
    public static function publicIdFromUrl(?string $url): ?string
    {
        if (!self::isRemote($url)) return null;
        $path = (string) parse_url((string) $url, PHP_URL_PATH);
        $at = strpos($path, '/upload/');
        if ($at === false) return null;

        $segments = array_values(array_filter(explode('/', substr($path, $at + strlen('/upload/'))), fn($s) => $s !== ''));
        // Everything before the version is transformation; everything after it is the
        // folder-qualified public id. With no version segment, only a leading
        // transformation may need dropping.
        $start = 0;
        foreach ($segments as $i => $seg) {
            if (preg_match('/^v\d+$/', $seg) === 1) { $start = $i + 1; break; }
            if ($i === 0 && preg_match('/(^|,)[a-z]{1,3}_[^,\/]+/', $seg) === 1) $start = 1;
        }
        $id = implode('/', array_slice($segments, $start));
        if ($id === '') return null;

        // The extension is a delivery format, not part of the id.
        $dot = strrpos($id, '.');
        return $dot !== false ? substr($id, 0, $dot) : $id;
    }
}
