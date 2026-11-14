<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Advanced form builder engine. Forms are admin-designed field schemas stored as
 * JSON; this service normalizes a schema, evaluates conditional visibility, runs
 * server-side validation (the authoritative pass), and persists submissions.
 */
class FormService
{
    public const TYPES = ['text', 'email', 'tel', 'number', 'textarea', 'select', 'radio', 'checkbox', 'date', 'file'];

    public static function byKey(string $key): ?array
    {
        $f = DB::table('gates_forms')->where('form_key', $key)->first();
        return $f ? self::shape($f) : null;
    }

    public static function byId(int $id): ?array
    {
        $f = DB::table('gates_forms')->where('id', $id)->first();
        return $f ? self::shape($f) : null;
    }

    private static function shape(object $f): array
    {
        $schema = json_decode((string) $f->schema_json, true);
        if (!is_array($schema)) $schema = ['fields' => []];
        $fields = self::normalizeFields($schema['fields'] ?? []);
        return [
            'id' => (int) $f->id, 'form_key' => (string) $f->form_key, 'title' => (string) $f->title,
            'description' => (string) ($f->description ?? ''), 'submit_message' => (string) ($f->submit_message ?? ''),
            'status' => (string) $f->status, 'fields' => $fields,
        ];
    }

    /** Clean + canonicalize a fields array (from the builder POST or stored JSON). */
    public static function normalizeFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            if (!is_array($f)) continue;
            $label = trim((string) ($f['label'] ?? ''));
            if ($label === '') continue;
            $type = in_array(($f['type'] ?? ''), self::TYPES, true) ? $f['type'] : 'text';
            $name = trim((string) ($f['name'] ?? ''));
            $name = preg_replace('/[^a-z0-9_]+/', '_', strtolower($name !== '' ? $name : $label));
            $name = trim((string) $name, '_') ?: 'field';
            $opts = [];
            foreach ((array) ($f['options'] ?? []) as $o) { $o = trim((string) $o); if ($o !== '') $opts[] = mb_substr($o, 0, 120); }
            // Validate the regex at save time; drop it if invalid so it can never
            // silently disable a field's validation (a bad pattern → preg_match false).
            $pattern = mb_substr(trim((string) ($f['pattern'] ?? '')), 0, 200);
            if ($pattern !== '' && @preg_match('/' . str_replace('/', '\/', $pattern) . '/', '') === false) $pattern = '';
            $out[] = [
                'type' => $type, 'name' => $name, 'label' => mb_substr($label, 0, 200),
                'required' => !empty($f['required']),
                'placeholder' => mb_substr(trim((string) ($f['placeholder'] ?? '')), 0, 160),
                'help' => mb_substr(trim((string) ($f['help'] ?? '')), 0, 300),
                'pattern' => $pattern,
                'maxlength' => max(0, (int) ($f['maxlength'] ?? 0)),
                'options' => array_slice($opts, 0, 30),
                'showIfField' => preg_replace('/[^a-z0-9_]+/', '', strtolower((string) ($f['showIfField'] ?? ''))),
                'showIfValue' => mb_substr(trim((string) ($f['showIfValue'] ?? '')), 0, 120),
            ];
        }
        // De-duplicate field names so submission keys never collide.
        $seen = [];
        foreach ($out as &$f) {
            $base = $f['name']; $name = $base; $n = 1;
            while (in_array($name, $seen, true)) { $name = $base . '_' . (++$n); }
            $f['name'] = $name; $seen[] = $name;
        }
        unset($f);
        return $out;
    }

    /** Whether a field is visible given submitted data + its (optional) showIf condition. */
    public static function visible(array $field, array $data): bool
    {
        $cond = (string) ($field['showIfField'] ?? '');
        if ($cond === '') return true;
        $val = $data[$cond] ?? '';
        if (is_array($val)) return in_array((string) ($field['showIfValue'] ?? ''), array_map('strval', $val), true);
        return (string) $val === (string) ($field['showIfValue'] ?? '');
    }

    /**
     * Authoritative server-side validation. Returns a list of human error strings
     * (empty = valid). Only VISIBLE fields are validated (conditional logic).
     */
    public static function validate(array $form, array $data): array
    {
        $errors = [];
        foreach ($form['fields'] as $f) {
            if (!self::visible($f, $data)) continue;
            $name = $f['name'];
            $v = $data[$name] ?? ($f['type'] === 'checkbox' ? [] : '');
            $isEmpty = is_array($v) ? count($v) === 0 : trim((string) $v) === '';
            if (!empty($f['required']) && $isEmpty) { $errors[] = $f['label'] . ' is required.'; continue; }
            if ($isEmpty) continue;
            $sv = is_array($v) ? '' : (string) $v;
            if ($f['type'] === 'email' && !filter_var($sv, FILTER_VALIDATE_EMAIL)) $errors[] = $f['label'] . ' must be a valid email address.';
            if ($f['type'] === 'number' && !is_numeric($sv)) $errors[] = $f['label'] . ' must be a number.';
            if (!empty($f['maxlength']) && mb_strlen($sv) > (int) $f['maxlength']) $errors[] = $f['label'] . ' is too long.';
            if (!empty($f['pattern']) && $sv !== '') {
                $re = '/' . str_replace('/', '\/', $f['pattern']) . '/';
                if (@preg_match($re, $sv) === 0) $errors[] = $f['label'] . ' is not in the expected format.';
            }
            if (in_array($f['type'], ['select', 'radio'], true) && $f['options'] && !in_array($sv, $f['options'], true)) {
                $errors[] = $f['label'] . ' has an invalid choice.';
            }
            // Checkbox-with-options arrives as an array — every chosen value must be in the allowlist.
            if ($f['type'] === 'checkbox' && $f['options']) {
                foreach ((array) $v as $cv) {
                    if (!in_array((string) $cv, $f['options'], true)) { $errors[] = $f['label'] . ' has an invalid choice.'; break; }
                }
            }
        }
        return $errors;
    }

    /** Persist a submission, keeping only declared field values. Returns the submission id. */
    public static function storeSubmission(array $form, array $data, string $ip = '', ?int $userId = null): int
    {
        $clean = [];
        foreach ($form['fields'] as $f) {
            $n = $f['name'];
            if (array_key_exists($n, $data)) $clean[$n] = is_array($data[$n]) ? array_map('strval', $data[$n]) : (string) $data[$n];
        }
        return (int) DB::table('gates_form_submissions')->insertGetId([
            'form_id'   => (int) $form['id'],
            'form_key'  => (string) $form['form_key'],
            'data_json' => json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip_hash'   => $ip !== '' ? hash('sha256', $ip) : null,
            'user_id'   => $userId,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }
}
