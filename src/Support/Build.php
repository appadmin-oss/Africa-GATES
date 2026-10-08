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
    public const REV = '2026-07-31.1-docroot-forwarder-webcron';

    /**
     * The deployment facts, safe to expose publicly.
     *
     * @return array{rev:string, csp:string, csp_nonce:bool, root:string, docroot:string, php:string}
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
            'docroot' => self::documentRoot(),
            'php'  => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        ];
    }

    /**
     * Is the DocumentRoot ./public, or the project root?
     *
     * ── WHY THIS IS ON A PUBLIC ENDPOINT ─────────────────────────────────────────
     *
     * Because it is the single fact behind two production outages and it was
     * unanswerable without shell access, so it was diagnosed by elimination twice.
     *
     * With the DocumentRoot at the project root, the project-root .htaccess is in
     * scope — and while it was `Require all denied` (defence-in-depth for exactly this
     * misconfiguration) the ENTIRE SITE returned 403. Replacing that file with a copy of
     * public/.htaccess then returned 500, because its front-controller rule names an
     * index.php that is not beside it. One cause, two symptoms, neither naming it.
     *
     * The project root is now a forwarder, so the site WORKS either way — which means
     * the misconfiguration is no longer visible from the outside at all, and would
     * silently persist. It is a real weakness worth fixing rather than living with: the
     * whole tree (.env, the database, logs, vendor/) sits inside the web root, protected
     * only by .htaccess instead of by being unreachable.
     *
     * Returned as a WORD, not a path. "project-root" is the actionable answer; the
     * absolute path would be free reconnaissance for no diagnostic gain.
     */
    public static function documentRoot(): string
    {
        $declared = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($declared === '') return 'unknown (no DOCUMENT_ROOT — CLI or a SAPI that omits it)';

        $declared = rtrim((string) (realpath($declared) ?: $declared), '/');
        $project  = rtrim((string) (realpath(dirname(__DIR__, 2)) ?: ''), '/');
        $public   = $project . '/public';

        if ($declared === $public)  return 'public (correct)';
        if ($declared === $project) return 'project-root (WRONG — see docs/DOCUMENT-ROOT.md)';
        // A third copy of the app, or a symlinked docroot that resolves elsewhere. Worth
        // distinguishing: this is the shape of "the deploy is not the tree you edited".
        return 'elsewhere (WRONG — DOCUMENT_ROOT is not this deployment: ' . substr(hash('sha256', $declared), 0, 12) . ')';
    }
}
