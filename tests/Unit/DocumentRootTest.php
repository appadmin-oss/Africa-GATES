<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The .htaccess set, and the two outages it has caused.
 *
 * ── WHAT HAPPENED ────────────────────────────────────────────────────────────
 *
 * The DocumentRoot on the production host points at the PROJECT ROOT, not ./public.
 * That single fact produced both reported symptoms and neither pointed at it:
 *
 *   1. The whole site returned 403. The project-root .htaccess was
 *      `Require all denied` — intended as defence-in-depth for exactly this
 *      misconfiguration. On a host where the misconfiguration is REAL, a deny-all at
 *      the DocumentRoot is not a safety net, it is the site being switched off.
 *
 *   2. Replacing that file with a copy of public/.htaccess returned 500, because
 *      `RewriteRule ^ index.php` at the project root names a file that is not there
 *      (index.php is in public/), so Apache looped to the internal-redirect limit.
 *
 * Apache only reads .htaccess from the DocumentRoot downward, so the project-root
 * file having ANY effect is itself the proof of where the DocumentRoot is.
 *
 * ── WHAT THESE TESTS DEFEND ──────────────────────────────────────────────────
 *
 * The root file is now a forwarder into public/ with layered denies, verified
 * against a real Apache 2.4 under BOTH DocumentRoot configurations. These tests
 * cannot run Apache, so they pin the properties whose loss caused an outage:
 * no deny-all at the root, no front-controller rule that assumes index.php is
 * beside it, a deny inside every non-public directory, and — the recurring one —
 * not a single directive that a LiteSpeed/cPanel host will reject.
 */
class DocumentRootTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    private function read(string $rel): string
    {
        $path = $this->root . '/' . $rel;
        $this->assertFileExists($path, $rel . ' is missing');
        return (string) file_get_contents($path);
    }

    /** Directives stripped of comments, so prose about a hazard is never read as the hazard. */
    private function directives(string $rel): string
    {
        return (string) preg_replace('~^\s*#.*$~m', '', $this->read($rel));
    }

    /**
     * Directives at FILE scope — everything inside a `<FilesMatch>`/`<Files>` removed.
     *
     * The distinction is the whole point of the deny tests below. `Require all denied`
     * scoped to `^\.env` is the protection we want; the same directive at file scope is
     * the site returning 403 for every URL. A guard that cannot tell them apart either
     * fails on correct code (it did, first try) or has to be weakened until it stops
     * catching the outage.
     */
    private function fileScope(string $rel): string
    {
        return (string) preg_replace(
            '~<(FilesMatch|Files|Directory|Location)\b.*?</\1>~is',
            '',
            $this->directives($rel)
        );
    }

    // ── Symptom 1: the site-wide 403 ─────────────────────────────────────────

    /**
     * A blanket deny at the project root is the site returning 403 for every URL on
     * any host whose DocumentRoot is the project root. The protection it was reaching
     * for is now per-directory (see below), where it cannot take the site with it.
     */
    public function test_the_project_root_does_not_deny_everything(): void
    {
        $d = $this->fileScope('.htaccess');

        $this->assertDoesNotMatchRegularExpression('~Require\s+all\s+denied~i', $d,
            'an UNSCOPED `Require all denied` returned 403 for the entire site — the '
            . 'DocumentRoot on this host IS the project root. Inside a <FilesMatch> it is '
            . 'fine and expected; at file scope it is an outage.');
        $this->assertDoesNotMatchRegularExpression('~Deny\s+from\s+all~i', $d,
            'same, in 2.2 syntax');
    }

    /** And it must actually forward, or the root serves nothing at all. */
    public function test_the_project_root_forwards_into_public(): void
    {
        $d = $this->directives('.htaccess');

        $this->assertMatchesRegularExpression('~RewriteEngine\s+On~i', $d);
        $this->assertMatchesRegularExpression('~RewriteRule\s+\^\(\.\*\)\$\s+public/\$1~', $d,
            'every request must be forwarded one level down into public/');
        $this->assertMatchesRegularExpression('~RewriteRule\s+\^public\(\?:/\|\$\)\s+-\s+\[L\]~', $d,
            'and the rewrite must TERMINATE: without this the forward re-enters the file '
            . 'and Apache loops to the internal-redirect limit');
    }

    // ── Symptom 2: the 500 ───────────────────────────────────────────────────

    /**
     * THE EXACT 500. `RewriteRule ^ index.php` is correct in public/ and fatal at the
     * project root, where index.php does not exist: the rule rewrites to itself until
     * Apache gives up. It is the one line that must never be copied upward.
     */
    public function test_the_project_root_never_routes_to_a_bare_index_php(): void
    {
        $d = $this->directives('.htaccess');

        $this->assertDoesNotMatchRegularExpression('~RewriteRule\s+\S+\s+index\.php~', $d,
            'index.php is in public/, so this rewrites to a path that does not exist and '
            . 'loops — the 500 that followed the 403');
    }

    // ── The layered denies ───────────────────────────────────────────────────

    /**
     * Layer 3: a deny INSIDE each non-public directory.
     *
     * The only layer that survives someone emptying the root .htaccess — which is what
     * an operator does when the site returns 403 for every URL, i.e. precisely the
     * situation in which the source tree must stay unreadable.
     */
    public function test_every_non_public_directory_denies_access_on_its_own(): void
    {
        $missing = [];
        foreach (['bin', 'config', 'cron', 'database', 'docs', 'resources', 'src', 'templates', 'tests', 'var', 'vendor'] as $dir) {
            $path = $this->root . '/' . $dir . '/.htaccess';
            if (!is_file($path)) { $missing[] = $dir . '/.htaccess'; continue; }
            $d = (string) preg_replace('~^\s*#.*$~m', '', (string) file_get_contents($path));
            if (!preg_match('~Require\s+all\s+denied~i', $d)) $missing[] = $dir . '/.htaccess (no deny)';
        }
        $this->assertSame([], $missing,
            "these directories are readable over HTTP when the DocumentRoot is the project "
            . "root:\n  " . implode("\n  ", $missing));
    }

    /**
     * vendor/.htaccess has to be COMMITTABLE, and once was not.
     *
     * `/vendor/` in .gitignore excludes the directory, and git cannot re-include a file
     * whose parent directory is excluded — the same defect that kept
     * public/uploads/.htaccess out of every deployment. The pattern must be
     * `/vendor/*` with a negation.
     */
    public function test_the_vendor_deny_is_not_gitignored(): void
    {
        $ignore = $this->read('.gitignore');

        $this->assertDoesNotMatchRegularExpression('~^/vendor/$~m', $ignore,
            'the directory form makes the negation below impossible, so vendor/.htaccess '
            . 'could never be committed and shipped');
        $this->assertMatchesRegularExpression('~^!/vendor/\.htaccess$~m', $ignore);
    }

    /** Layer 1: the sensitive trees are refused by path, without needing mod_rewrite. */
    public function test_the_sensitive_trees_are_denied_without_mod_rewrite(): void
    {
        $d = $this->directives('.htaccess');

        $this->assertStringContainsString('RedirectMatch', $d,
            'mod_alias, so a request for /.env is refused even if mod_rewrite is off');
        foreach (['src', 'config', 'vendor', 'database', 'var'] as $tree) {
            $this->assertMatchesRegularExpression('~RedirectMatch[^\n]*\b' . $tree . '\b~', $d,
                $tree . ' must be named in the path denylist');
        }
        $this->assertMatchesRegularExpression('~\\\\\.env~', $d, '.env must be denied by name');
    }

    /**
     * The denylist must not name anything served from public/.
     *
     * A `RedirectMatch 404 ^/+assets` would 404 every stylesheet on the site — the whole
     * front end, from a rule that reads as a security improvement.
     */
    public function test_the_denylist_cannot_match_a_public_asset_path(): void
    {
        $d = $this->directives('.htaccess');

        foreach (['assets', 'uploads', 'robots', 'sitemap', 'favicon'] as $public) {
            $this->assertDoesNotMatchRegularExpression(
                '~RedirectMatch[^\n]*\(\?:[^\n]*\b' . $public . '\b~', $d,
                "/{$public} is served out of public/ — denying it takes the front end down"
            );
        }
    }

    // ── The recurring failure: an unsupported directive is a site-wide 500 ───

    /**
     * NO DIRECTIVE THAT A LITESPEED / cPANEL HOST CAN REJECT.
     *
     * This has taken the site down before: `Header always setifempty` is Apache 2.4.7+
     * and is NOT implemented by LiteSpeed's mod_headers emulation, which is what a large
     * share of cPanel hosts run, and an unknown directive in .htaccess is a 500 for
     * everything below it. `<IfVersion>` does not help — it is itself a directive the
     * server must understand. `Options` and `php_flag` need an AllowOverride this host
     * may not grant.
     *
     * Matched by SHAPE against the stripped directives, so a comment explaining the
     * hazard is not mistaken for the hazard — a guard in this repo has cried wolf over
     * its own documentation four times.
     */
    public function test_no_htaccess_uses_a_directive_a_shared_host_may_reject(): void
    {
        $offenders = [];
        foreach ($this->htaccessFiles() as $rel) {
            $d = (string) preg_replace('~^\s*#.*$~m', '', (string) file_get_contents($this->root . '/' . $rel));
            foreach ([
                '~^\s*Header\s+\S*\s*setifempty~mi' => 'Header setifempty (Apache 2.4.7+, absent on LiteSpeed)',
                '~^\s*<IfVersion~mi'                => '<IfVersion (needs mod_version, which may not be loaded)',
                '~^\s*php_(flag|value|admin)~mi'    => 'php_flag/php_value (needs AllowOverride Options, and fails under php-fpm)',
                '~^\s*Options\s+[-+]?ExecCGI~mi'    => 'Options ExecCGI (needs AllowOverride Options)',
            ] as $pattern => $why) {
                if (preg_match($pattern, $d)) $offenders[] = $rel . ': ' . $why;
            }
        }
        $this->assertSame([], $offenders,
            "an unsupported directive in .htaccess is a 500 for every URL below it:\n  "
            . implode("\n  ", $offenders));
    }

    /**
     * `Order`/`Deny`/`Allow` are mod_access_compat, which stock Apache 2.4 does NOT
     * load. Unguarded, "Invalid command 'Order'" is a site-wide 500 — the same class of
     * outage as `setifempty`, and public/.htaccess shipped with exactly that until this
     * was verified against a real 2.4 with the module disabled.
     */
    public function test_every_apache_2_2_directive_is_module_gated(): void
    {
        $offenders = [];
        foreach ($this->htaccessFiles() as $rel) {
            $src = (string) preg_replace('~^\s*#.*$~m', '', (string) file_get_contents($this->root . '/' . $rel));
            // Walk <IfModule> nesting so a directive appended AFTER a guarded block is
            // still caught — checking only "is there an IfModule somewhere in the file"
            // was verified NOT to catch that.
            $depth = 0; $guarded = 0;
            foreach (explode("\n", $src) as $line) {
                if (preg_match('~^\s*<IfModule\s+!?(\S+?)>~i', $line, $m)) {
                    $depth++;
                    if (stripos($m[1], 'access_compat') !== false || stripos($m[1], 'authz_core') !== false) $guarded = $depth;
                    continue;
                }
                if (preg_match('~^\s*</IfModule>~i', $line)) {
                    if ($guarded === $depth) $guarded = 0;
                    $depth = max(0, $depth - 1);
                    continue;
                }
                if (preg_match('~^\s*(Order|Deny\s+from|Allow\s+from)\b~i', $line, $m) && $guarded === 0) {
                    $offenders[] = $rel . ': ' . trim($m[0]) . ' outside an <IfModule> guard';
                }
            }
        }
        $this->assertSame([], $offenders,
            "mod_access_compat is not loaded by default on Apache 2.4, so these are "
            . "\"Invalid command\" — a 500 for every URL:\n  " . implode("\n  ", $offenders));
    }

    // ── public/.htaccess must work at either depth ───────────────────────────

    /**
     * `RewriteBase /` is only true when the DocumentRoot IS public/.
     *
     * With the DocumentRoot at the project root this directory is served at /public/, so
     * `RewriteBase /` sent every pretty URL to `/index.php` — a path that does not exist
     * there. It worked anyway, by accident, because the root forwarder caught the stray
     * /index.php and prefixed it: correctness resting on a three-hop coincidence. With
     * no RewriteBase, mod_rewrite derives the prefix from the directory's own URL path,
     * which is right at either depth and takes one hop.
     */
    public function test_public_htaccess_does_not_hardcode_a_rewrite_base(): void
    {
        $d = $this->directives('public/.htaccess');

        $this->assertDoesNotMatchRegularExpression('~^\s*RewriteBase~mi', $d,
            'a hardcoded RewriteBase assumes which directory is the DocumentRoot');
        $this->assertMatchesRegularExpression('~RewriteRule\s+\^\s+index\.php~', $d,
            'the front-controller rule must stay RELATIVE so the prefix is inferred');
    }

    /**
     * The /public/… → /… canonical redirect must live in public/.htaccess.
     *
     * mod_rewrite rules are NOT inherited — a subdirectory's rules REPLACE the parent's —
     * so the same redirect written in the project-root file is dead code for any request
     * that already names public/. That was found by rewrite trace after the first attempt
     * silently did nothing.
     */
    public function test_the_public_prefix_redirect_is_where_it_can_actually_run(): void
    {
        $inPublic = $this->directives('public/.htaccess');
        $inRoot   = $this->directives('.htaccess');

        $this->assertMatchesRegularExpression('~THE_REQUEST[^\n]*public~i', $inPublic,
            'the canonical redirect has to be in public/.htaccess to be reached at all');
        $this->assertDoesNotMatchRegularExpression('~THE_REQUEST[^\n]*public~i', $inRoot,
            'in the root file this rule can never fire — mod_rewrite rules are not inherited');
    }

    /** public/ must re-grant access, so an inherited deny can never switch the site off again. */
    public function test_public_regrants_access_for_itself(): void
    {
        $this->assertMatchesRegularExpression('~Require\s+all\s+granted~i', $this->directives('public/.htaccess'),
            'this is the one directory whose whole contents are meant to be public, and the '
            . 'only safe place to re-grant after an inherited deny');
    }

    /** @return list<string> every .htaccess this repository ships. */
    private function htaccessFiles(): array
    {
        $found = [];
        foreach (['', 'public/', 'public/assets/', 'public/uploads/', 'bin/', 'config/', 'cron/',
                  'database/', 'docs/', 'resources/', 'src/', 'templates/', 'tests/', 'var/', 'vendor/'] as $dir) {
            if (is_file($this->root . '/' . $dir . '.htaccess')) $found[] = $dir . '.htaccess';
        }
        $this->assertGreaterThan(10, count($found), 'the .htaccess set is incomplete');
        return $found;
    }
}
