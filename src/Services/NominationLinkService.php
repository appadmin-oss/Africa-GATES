<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Shareable prefill nomination links.
 *
 * A nominator can mint a link for their nominee; whoever opens it gets the
 * nomination wizard prefilled with the nominee-side details (all editable —
 * the new nominator still submits their own entry through the full pipeline:
 * validation, spam gate, rate limits, device dedupe).
 *
 * Privacy model: the payload lives server-side behind an opaque 32-byte hex
 * token (the URL carries no PII), only WHITELISTED nominee-side fields are
 * stored (never the nominator's identity or their nomination story), links
 * expire (default 30 days), and every open is counted.
 */
class NominationLinkService
{
    /** Nominee-side fields that may ride on a share link. */
    private const FIELDS = [
        'nominee_name'  => 200,
        'nominee_email' => 191,
        'nominee_phone' => 40,
        'country_code'  => 2,
        'nominee_state' => 100,
        'nominee_lga'   => 100,
        'nominee_org'   => 200,
        'programme_id'  => 0,   // int
        'category_id'   => 0,   // int
    ];

    public const DEFAULT_TTL_DAYS = 30;

    /** Mint a link token for a nominee payload. Throws when unusable. */
    public function create(array $data, ?string $ip, int $ttlDays = self::DEFAULT_TTL_DAYS): string
    {
        $payload = [];
        foreach (self::FIELDS as $field => $max) {
            if (!isset($data[$field])) continue;
            if ($max === 0) {
                $v = (int) $data[$field];
                if ($v > 0) $payload[$field] = $v;
            } else {
                $v = mb_substr(trim((string) $data[$field]), 0, $max);
                if ($v !== '') $payload[$field] = $field === 'country_code' ? strtoupper($v) : $v;
            }
        }
        if (($payload['nominee_name'] ?? '') === '') {
            throw new \RuntimeException('A nominee name is required to create a share link.');
        }

        $token = bin2hex(random_bytes(32));
        $row = [
            'token'           => $token,
            'payload'         => (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
            'created_ip_hash' => ($ip !== null && $ip !== '') ? hash('sha256', $ip) : null,
            'hits'            => 0,
            'expires_at'      => date('Y-m-d H:i:s', time() + max(1, $ttlDays) * 86400),
            'created_at'      => date('Y-m-d H:i:s'),
        ];
        // Attribute to the signed-in member (guests stay NULL) — powers the
        // dashboard "your share links" card. Column-guarded for older schemas.
        try {
            $memberId = (int) ($_SESSION['user_id'] ?? 0);
            if ($memberId > 0 && DB::getSchemaBuilder()->hasColumn('gates_nomination_links', 'created_by')) {
                $row['created_by'] = $memberId;
            }
        } catch (\Throwable) {}
        DB::table('gates_nomination_links')->insert($row);

        WebhookService::dispatch('share_link.created', [
            'nominee'      => (string) $payload['nominee_name'],
            'programme_id' => (int) ($payload['programme_id'] ?? 0),
            'expires_days' => $ttlDays,
        ]);
        return $token;
    }

    /** Resolve a token to its prefill payload (or null), counting the open. */
    public function resolve(string $token): ?array
    {
        $token = strtolower(trim($token));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;

        $row = DB::table('gates_nomination_links')->where('token', $token)->first();
        if (!$row) return null;
        if ($row->expires_at !== null && strtotime((string) $row->expires_at) < time()) return null;

        $payload = json_decode((string) $row->payload, true);
        if (!is_array($payload)) return null;

        try {
            DB::table('gates_nomination_links')->where('id', $row->id)->increment('hits');
        } catch (\Throwable) {}
        WebhookService::dispatch('share_link.used', [
            'nominee' => (string) ($payload['nominee_name'] ?? ''),
            'hits'    => (int) $row->hits + 1,
        ]);
        return $payload;
    }
}
