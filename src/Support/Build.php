<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * Which code is actually serving this request.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────
 *
 * Because the answer has twice been "not the code you are reading", and there was no
 * way to find that out from a browser.
 *
 * `docs/VOTING-NOMINATIONS-STATE-AUDIT.md` records the proof: `Csp::policy()` was
 * edited ON THE SERVER with a deliberate syntax error, and the site carried on
 * returning HTTP 200 with the old header. A syntax error in a file PHP loads is a
 * fatal, not a no-op — so the file was not being loaded. The deployed tree is not this
 * tree. Every CSP refusal reported from production since then has been the same
 * unresolved deployment problem wearing a different hat: CDN stylesheets blocked, CDN
 * scripts blocked, and every paid vote refused by `form-action 'self'` — all of which
 * this repository fixed, and none of which the running code contains.
 *
 * That is an expensive thing to re-derive. It took several rounds the first time
 * precisely because "is the deploy live?" was unanswerable without shell access, and
 * the symptoms all look like application bugs.
 *
 * ── THE DESIGN: IT MUST BE ANSWERABLE FROM OUTSIDE ───────────────────────────
 *
 * {@see \AfricaGates\Console\Commands\DoctorCommand} can compare the policy this code
 * emits against the one the live URL returns, but it needs a shell. So the same facts
 * are exposed on `/ping`, unauthenticated, carrying no secrets:
 *
 *   • `rev` — bumped by hand when something deployment-critical changes. Its ABSENCE
 *     from /ping is itself the diagnosis: a tree old enough not to have this class.
 *   • `csp_nonce` — whether the running policy is nonce-based. False means the code
 *     predates the CSP rewrite, which is exactly the reported production state.
 *   • `root` — the directory actually serving the request, hashed. The most likely
 *     cause is a DocumentRoot still pointing at an older copy (a `public_html/` beside
 *     the deploy), and a changed hash is how you confirm the switch landed.
 *
 * Nothing here is a secret: a revision string, a boolean, and a hash of a path. The
 * path is hashed rather than printed because an absolute filesystem path is free
 * reconnaissance for no diagnostic gain — you only ever need to know whether it
 * CHANGED.
 */
final class Build
{
    /**
     * Bump this when a change must be verifiable as deployed.
     *
     * Deliberately manual. Deriving it from git would report the checked-out tree,
     * which on a broken deploy is precisely the tree that is NOT running — the
     * question is what the web server loaded, so the value has to travel inside the
     * PHP file the web server loads.
     */
    public const REV = '2026-07-30.4-csp-nonce-assets';

    /**
     * The deployment facts, safe to expose publicly.
     *
     * @return array{rev:string, csp:string, csp_nonce:bool, root:string, php:string}
     */
    public static function fingerprint(): array
    {
        $policy = class_exists(Csp::class) ? Csp::policy() : '';

        return [
            'rev' => self::REV,
            // A short hash of the policy, so two deployments can be compared without
            // publishing the whole allowlist.
            'csp' => $policy === '' ? 'absent' : substr(hash('sha256', $policy), 0, 12),
            // The single most diagnostic bit on the whole endpoint.
            'csp_nonce' => str_contains($policy, "'nonce-"),
            'root' => substr(hash('sha256', dirname(__DIR__, 2)), 0, 12),
            'php'  => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        ];
    }
}
