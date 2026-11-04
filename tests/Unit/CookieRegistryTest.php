<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CookiePrefs;
use AfricaGates\Support\CookieRegistry;
use Tests\TestCase;

/**
 * Does the published list say exactly what the code stores on a visitor's device — no less,
 * and no more?
 *
 * Rebuilt on 3 Oct 2026 with the registry (GAPS Q11, C14; DESTROYED.md "Stale declarations
 * on `/cookies`"). The old sweep asked ONE direction and had ONE blind spot, and both had
 * already cost something:
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * 1. DECLARED BUT NOT WRITTEN IS THE SAME STALE LEGAL PAGE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * It asked whether every key WRITTEN was declared, never whether every key DECLARED was
 * written — so when the old pages were destroyed, seven storage rows and two cookies went
 * on being published for writers that no longer existed. Over-disclosure is still a page
 * describing a platform that is not running. Both directions are held now.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * 2. A KEY HELD IN A VARIABLE WAS INVISIBLE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The storage sweep read only a string LITERAL as the first argument, so
 * `setItem(SESSION_KEY, '1')` — `ag_community_prompted`, written for months and never
 * declared — passed. The sweep now resolves the argument: a literal; a variable assigned in
 * the same file (its leading literal is the prefix — `'ag-door-q:' + TOKEN`); an option
 * with a literal fallback (`opts.storageKey || ''`, plus every `storageKey:'…'` a caller
 * passes); a method that returns one (`this.key()` → `'afStep:' + …`). And ANYTHING IT
 * CANNOT RESOLVE FAILS, by file and expression — so the blind spot is closed by refusal
 * rather than by cleverness: a key computed in a way this cannot read must be rewritten so
 * it can, or the build is red. It reads every `.setItem(` whatever the receiver, so a store
 * held in a variable (`var st = localStorage; st.setItem(…)`) is read too.
 *
 * What it still cannot see, and says so: a write through `Storage.prototype` or bracket
 * syntax (`localStorage['k'] = …`), IndexedDB, and a script no template loads (out of scope
 * by definition — a file nothing executes stores nothing; `gee.js` is such a file until
 * Phase 3 mounts it, and the day a template loads it, it is swept).
 */
final class CookieRegistryTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @return array<string,string> path → body, for a directory and an extension pattern */
    private function files(string $dir, string $ext): array
    {
        $out = [];
        if (!is_dir($dir)) return $out;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $f) {
            if (!$f->isFile() || !preg_match($ext, $f->getFilename())) continue;
            if (str_contains($f->getPathname(), '/vendor/')) continue;
            $out[$f->getPathname()] = (string) file_get_contents($f->getPathname());
        }
        return $out;
    }

    /**
     * What a visitor's browser actually executes: every template, every script a template
     * loads, and the server code. A script nothing loads stores nothing.
     *
     * @param array<string,string> $templates
     * @param array<string,string> $scripts  path under public/assets/js → body
     * @return array<string,string>
     */
    private function scope(array $templates, array $scripts, array $php = []): array
    {
        $loaded = [];
        foreach ($templates as $body) {
            if (preg_match_all('~/assets/js/([A-Za-z0-9_./-]+\.js)~', $body, $m)) {
                foreach ($m[1] as $rel) $loaded[$rel] = true;
            }
        }
        $out = $templates + $php;
        foreach ($scripts as $rel => $body) {
            if (isset($loaded[$rel])) $out['public/assets/js/' . $rel] = $body;
        }
        return $out;
    }

    /** @return array<string,string> */
    private function shipped(): array
    {
        $norm = [];
        foreach ($this->files(self::ROOT . '/public/assets/js', '/\.js$/') as $p => $b) {
            $norm[(string) preg_replace('~^.*/public/assets/js/~', '', $p)] = $b;
        }

        return $this->scope(
            $this->files(self::ROOT . '/templates', '/\.twig$/'),
            $norm,
            $this->files(self::ROOT . '/src', '/\.php$/'));
    }

    // ══ cookies ══════════════════════════════════════════════════════════════

    /**
     * Every cookie name the code writes, with whether each write is an expiry.
     *
     * @param array<string,string> $files
     * @return array<string, array{path:string, expiry_only:bool}>
     */
    private function cookieWrites(array $files): array
    {
        $found = [];
        $add = static function (string $name, string $path, bool $expiry) use (&$found): void {
            $name = strtolower($name);
            $prev = $found[$name]['expiry_only'] ?? true;
            $found[$name] = ['path' => $path, 'expiry_only' => $prev && $expiry];
        };

        foreach ($files as $path => $body) {
            if (preg_match_all('/document\.cookie\s*=\s*[\'"]([A-Za-z0-9_.-]+)=([^\'"]*)/', $body, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) $add($x[1], $path, str_contains($x[2], 'max-age=0'));
            }
            // The delegated form: the name in a `data-cookie` attribute, the writer one
            // generic listener. It is how ag_region/ag_currency went unpublished for so long.
            if (preg_match_all('/data-cookie\s*=\s*"([A-Za-z0-9_.-]+)"/', $body, $m)) {
                foreach ($m[1] as $n) $add($n, $path, false);
            }
            if (preg_match_all('/setcookie\s*\(\s*[\'"]([A-Za-z0-9_.-]+)[\'"]/', $body, $m)) {
                foreach ($m[1] as $n) $add($n, $path, false);
            }
            // PSR-7: `withAddedHeader('Set-Cookie', …)` over parts beginning `self::CONST . '='`,
            // the name resolved from the same file's own constant.
            if (preg_match('/with(?:Added)?Header\(\s*[\'"]Set-Cookie[\'"]/', $body)
                && preg_match_all('/self::([A-Z_]+)\s*\.\s*[\'"]=([^\'"]*)[\'"]/', $body, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    if (preg_match('/const\s+' . $x[1] . '\s*=\s*[\'"]([A-Za-z0-9_.-]+)[\'"]/', $body, $c)) {
                        $add($c[1], $path, str_contains($x[2], 'Max-Age=0'));
                    }
                }
            }
        }

        return $found;
    }

    public function test_every_cookie_the_code_writes_is_published_and_a_retired_one_is_only_ever_expired(): void
    {
        $declared = array_map('strtolower', CookieRegistry::names());
        $retired  = array_map(static fn (array $r): string => strtolower($r['name']), CookieRegistry::retired());
        $found    = $this->cookieWrites($this->shipped());

        $this->assertNotSame([], $found,
            'the sweep found no cookie writer at all — the patterns have stopped matching');

        foreach ($found as $name => $w) {
            if (in_array($name, $retired, true)) {
                $this->assertTrue($w['expiry_only'],
                    "'{$name}' is retired and " . basename($w['path']) . ' writes it with a value — '
                    . 'a retired cookie may only be expired');
                continue;
            }
            $this->assertContains($name, $declared,
                "'{$name}' is set in " . basename($w['path']) . ' and is not in CookieRegistry, so '
                . 'the published cookie policy does not mention it');
        }
    }

    public function test_every_cookie_the_policy_publishes_is_actually_written(): void
    {
        // The other direction. The session cookie is written by PHP's session machinery,
        // not by a line anybody can sweep for — exempt by what it IS, and only that one.
        $found = $this->cookieWrites($this->shipped());
        foreach (CookieRegistry::names() as $name) {
            if ($name === CookieRegistry::sessionName()) continue;
            $this->assertArrayHasKey(strtolower($name), $found,
                "the policy publishes '{$name}' and nothing in the shipped code writes it — "
                . 'a row for a writer that no longer exists is the same stale legal page');
        }
    }

    // ══ browser storage ══════════════════════════════════════════════════════

    /**
     * Every storage key written, resolved; and every write it could NOT resolve.
     *
     * @param array<string,string> $files
     * @return array{found: array<string,string>, unresolved: list<string>}
     */
    private function storageWrites(array $files): array
    {
        $found = [];
        $unresolved = [];
        $lit = '(?:\'([^\']*)\'|"([^"]*)")';

        // Option names a caller passes a literal for, across the whole scope —
        // `agChat({storageKey:'ag-copilot'})` is how `opts.storageKey` gets its value.
        $options = [];
        foreach ($files as $body) {
            if (preg_match_all('/([A-Za-z_]\w*)\s*:\s*' . $lit . '/', $body, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) $options[$x[1]][] = ($x[2] ?? '') !== '' ? $x[2] : ($x[3] ?? '');
            }
        }

        foreach ($files as $path => $body) {
            if (!preg_match_all('/\.setItem\s*\(\s*([^,]+?)\s*,/', $body, $m)) continue;

            foreach ($m[1] as $arg) {
                $keys = $this->resolve(trim($arg), $body, $options, 0);
                if ($keys === null) {
                    $unresolved[] = basename($path) . ': setItem(' . trim($arg) . ', …)';
                    continue;
                }
                foreach ($keys as $k) {
                    // `coi_declared_{{ programme.id }}` — the Twig half is not part of the name.
                    $k = strtolower((string) preg_replace('/\{\{.*$/', '', $k));
                    if (trim($k) !== '') $found[$k] = $path;
                }
            }
        }

        return ['found' => $found, 'unresolved' => $unresolved];
    }

    /**
     * The literal key, or prefix, an argument expression writes; null if it cannot be read.
     *
     * @param array<string,list<string>> $options
     * @return list<string>|null
     */
    private function resolve(string $expr, string $body, array $options, int $depth): ?array
    {
        if ($depth > 3) return null;
        $expr = trim($expr);

        // A literal, or a literal followed by `+ something` — the literal is the prefix.
        if (preg_match('/^(?:\'([^\']*)\'|"([^"]*)")/', $expr, $m)) {
            return [($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '')];
        }

        // `a || 'fallback'` — every value either side can take.
        if (str_contains($expr, '||')) {
            $out = [];
            foreach (explode('||', $expr) as $part) {
                $r = $this->resolve($part, $body, $options, $depth + 1);
                if ($r === null) return null;
                $out = array_merge($out, $r);
            }
            return $out;
        }

        // `opts.storageKey` — whatever literal any caller passes for that option.
        if (preg_match('/^\w+\.(\w+)$/', $expr, $m) && !preg_match('/^this\./', $expr)) {
            return $options[$m[1]] ?? [];
        }

        // `this.key()` / `key()` — the method's return expression, in this file.
        if (preg_match('/^(?:this\.)?(\w+)\(\)$/', $expr, $m)) {
            if (preg_match('/\b' . preg_quote($m[1], '/') . '\s*\(\)\s*\{\s*return\s+([^;}]+)/', $body, $r)) {
                return $this->resolve($r[1], $body, $options, $depth + 1);
            }
            return null;
        }

        // A variable: its assignment in this file.
        if (preg_match('/^[A-Za-z_$][\w$]*$/', $expr)) {
            if (preg_match('/(?:var|let|const)\s+' . preg_quote($expr, '/') . '\s*=\s*([^;,\n]+)/', $body, $a)) {
                return $this->resolve($a[1], $body, $options, $depth + 1);
            }
            return null;
        }

        return null;
    }

    public function test_every_storage_key_the_code_writes_is_published_and_nothing_hides_from_the_sweep(): void
    {
        $prefixes = array_map(static fn (array $s): string => strtolower($s['key']), CookieRegistry::storage());
        ['found' => $found, 'unresolved' => $unresolved] = $this->storageWrites($this->shipped());

        $this->assertNotSame([], $found, 'the storage sweep matched nothing');
        $this->assertSame([], $unresolved,
            "a storage write whose key this sweep cannot read — rewrite it so the key is a literal, "
            . "a variable, an option or a method returning one:\n" . implode("\n", $unresolved));

        foreach ($found as $key => $path) {
            $ok = false;
            foreach ($prefixes as $p) if (str_starts_with($key, $p)) { $ok = true; break; }
            $this->assertTrue($ok, "browser storage key '{$key}' is written in " . basename($path)
                . ' and no CookieRegistry::storage() entry covers it');
        }
    }

    public function test_every_storage_key_the_policy_publishes_is_actually_written(): void
    {
        $found = array_keys($this->storageWrites($this->shipped())['found']);
        foreach (CookieRegistry::storage() as $s) {
            $p = strtolower($s['key']);
            $hit = array_filter($found, static fn (string $k): bool => str_starts_with($k, $p));
            $this->assertNotSame([], $hit,
                "the policy publishes storage key '{$s['key']}' and nothing in the shipped code "
                . 'writes it — the seven stale rows DESTROYED.md recorded were exactly this');
        }
    }

    // ══ proving the sweeps fail ══════════════════════════════════════════════

    public function test_the_sweeps_name_what_they_were_built_to_catch(): void
    {
        // A sweep is evidence only once it has been seen to name a break.
        $plant = [
            'templates/x.twig' => "<script src=\"{{ asset('/assets/js/planted.js') }}\"></script>",
        ];
        $scripts = [
            // The exact shape that got past the old sweep (`ag_community_prompted`).
            'planted.js'  => "var SESSION_KEY = 'ag_community_prompted'; sessionStorage.setItem(SESSION_KEY, '1');"
                           . " var st = localStorage; st.setItem('ag_aliased', 1);"
                           . " function k(){ return 'afStep:' + 1 } sessionStorage.setItem(k(), 1);"
                           . " localStorage.setItem(window.computeKey(), 1);",
            // Loaded by nothing, so out of scope — it stores nothing.
            'orphan.js'   => "localStorage.setItem('ag_orphan', 1);",
        ];
        $r = $this->storageWrites($this->scope($plant, $scripts));

        $this->assertArrayHasKey('ag_community_prompted', $r['found'], 'a key held in a variable is invisible again');
        $this->assertArrayHasKey('ag_aliased', $r['found'], 'a store held in a variable is invisible again');
        $this->assertArrayHasKey('afstep:', $r['found'], 'a key returned by a method is invisible again');
        $this->assertSame(['planted.js: setItem(window.computeKey(), …)'], $r['unresolved'],
            'an unreadable key must be reported, never skipped');
        $this->assertArrayNotHasKey('ag_orphan', $r['found'], 'a script no template loads was swept');

        // And the two cookie writers that got past everybody.
        $c = $this->cookieWrites([
            'a.js'  => "document.cookie = 'ag_experiment=' + v + ';path=/';",
            'b.twig' => '<select data-ag-do="set-cookie-reload" data-cookie="ag_theme">',
            'C.php' => "const OLD = 'ag_old'; \$r->withAddedHeader('Set-Cookie', self::OLD . '=v; Path=/');",
        ]);
        $this->assertSame(['ag_experiment', 'ag_theme', 'ag_old'], array_keys($c));
        $this->assertFalse($c['ag_old']['expiry_only'], 'a retired cookie written with a value must be caught');
    }

    // ══ the declarations themselves ══════════════════════════════════════════

    public function test_every_entry_is_in_one_of_the_four_categories_and_explains_itself(): void
    {
        $cats = array_map(static fn (array $c): string => $c['key'], CookieRegistry::categories());
        $this->assertSame(CookiePrefs::CATEGORIES, $cats, 'the policy and the resolver disagree about the categories');

        foreach (CookieRegistry::cookies() as $c) {
            $this->assertContains($c['category'], $cats, "'{$c['name']}' has a category no part of the page knows how to draw");
            $this->assertNotSame('', trim($c['purpose']), "'{$c['name']}' is published with no explanation");
        }
        foreach (CookieRegistry::storage() as $s) {
            $this->assertContains($s['category'], $cats, "'{$s['key']}' has no category");
            $this->assertContains($s['where'], ['local', 'session', 'local-or-session']);
            $this->assertNotSame('', trim($s['purpose']));
        }

        // The record of a refusal is essential and itself declared; the old record is
        // retired, not declared.
        $this->assertContains(CookiePrefs::COOKIE, CookieRegistry::names());
        $this->assertNotContains(CookiePrefs::LEGACY, CookieRegistry::names());
        $this->assertContains(CookiePrefs::LEGACY, array_column(CookieRegistry::retired(), 'name'));
    }

    public function test_the_session_cookie_is_described_from_the_live_configuration(): void
    {
        $this->assertSame(session_name(), CookieRegistry::sessionName());

        $params = session_get_cookie_params();
        if ((int) ($params['lifetime'] ?? 0) > 0) {
            $days = (int) floor(((int) $params['lifetime']) / 86400);
            $this->assertStringContainsString($days === 7 ? 'Seven days' : (string) $days, CookieRegistry::sessionLifetime());
        } else {
            $this->assertStringContainsString('close your browser', CookieRegistry::sessionLifetime());
        }
    }
}
