<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Key/value runtime settings stored in gates_settings.
 * Cached in-memory per request.
 */
class SettingsService
{
    private array $cache = [];
    private bool  $loaded = false;

    public function all(): array
    {
        if ($this->loaded) return $this->cache;
        try {
            $rows = DB::table('gates_settings')->get();
            foreach ($rows as $r) {
                $this->cache[$r->key_name] = $r->value;
            }
        } catch (\Throwable $e) {}
        $this->loaded = true;
        return $this->cache;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $all = $this->all();
        return $all[$key] ?? $default;
    }

    public function set(string $key, ?string $value, ?int $byAdminId = null): void
    {
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => $key],
            ['value' => $value, 'updated_at' => Carbon::now()->toDateTimeString(), 'updated_by' => $byAdminId]
        );
        $this->cache[$key] = $value;
    }
}
