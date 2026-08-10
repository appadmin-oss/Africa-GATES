<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Support;

/**
 * Central admin RBAC matrix — the single source of truth for "who can see what".
 *
 * Five console roles, six sections. {@see canAccess()} drives both the
 * SectionGuardMiddleware (server-side enforcement) and the sidebar (UI), so the
 * two can never drift apart. Writes are additionally gated by the writer
 * allowlist in AdminAuthMiddleware, and a handful of trust-sensitive routes keep
 * their own RoleMiddleware('superadmin') — belt and suspenders.
 *
 * Judges are NOT a console role here: AdminAuthMiddleware blocks role='judge'
 * from /admin entirely (they evaluate in the /judge portal).
 */
final class Permissions
{
    /** Console roles, most → least privileged, with display labels + blurbs. */
    public const ROLES = [
        'superadmin' => ['label' => 'Superadmin', 'blurb' => 'Full access, including configuration and other admin accounts.'],
        'admin'      => ['label' => 'Admin',      'blurb' => 'Everything except configuration (admins, settings, webhooks, judges).'],
        'editor'     => ['label' => 'Editor',     'blurb' => 'Content & programmes — create and edit public-facing material.'],
        'moderator'  => ['label' => 'Moderator',  'blurb' => 'Moderation only — review profiles, nominations and community posts.'],
        'viewer'     => ['label' => 'Viewer',     'blurb' => 'Read-only across the console — no configuration, no changes.'],
    ];

    /** section => roles allowed to VIEW it. (Writing is further gated by the writer allowlist.) */
    public const MATRIX = [
        'overview'      => ['superadmin', 'admin', 'editor', 'moderator', 'viewer'],
        'moderation'    => ['superadmin', 'admin', 'moderator', 'viewer'],
        'programmes'    => ['superadmin', 'admin', 'editor', 'viewer'],
        'content'       => ['superadmin', 'admin', 'editor', 'viewer'],
        // All roles can reach the Data section; the DataRegistry then filters WHICH
        // datasets each role sees (superadmin/admin/viewer see all; editor + moderator
        // see the subset their role needs).
        'data'          => ['superadmin', 'admin', 'editor', 'moderator', 'viewer'],
        // Finance is its OWN section rather than part of `data`, and it is narrower than
        // `data` on purpose. The data explorer lets a viewer read gates_donations row by
        // row, which is an operational lookup; a page that totals every naira the
        // organisation has taken and ranks its largest donors is a different kind of
        // disclosure. Editors and moderators have no reason to see either.
        'finance'       => ['superadmin', 'admin'],
        'configuration' => ['superadmin'],
    ];

    /** First path segment under /admin → section. (Drives sectionForPath().) */
    private const PATH_SECTIONS = [
        // moderation
        'profiles' => 'moderation', 'nominations' => 'moderation', 'nominees' => 'moderation', 'moderation' => 'moderation',
        // Judging interviews are `moderation` rather than `configuration`, where /judges
        // sits. Appointing a judge is a governance act; running an interview is programme
        // work a moderator does. Mapping it also keeps the sidebar and the guard on one
        // answer — an unmapped path fails closed to superadmin while the sidebar would
        // still offer the link to an admin, which is the exact disagreement documented
        // against the finance paths below. A viewer holds this section and may READ the
        // schedule; InterviewsController separately refuses them every action that writes.
        'interviews' => 'moderation',
        // programmes
        'programmes' => 'programmes', 'categories' => 'programmes', 'awards-page' => 'programmes',
        // content
        'events' => 'content', 'posts' => 'content', 'legacy' => 'content', 'opportunities' => 'content',
        'partners' => 'content', 'media' => 'content', 'products' => 'content', 'forms' => 'content',
        'legal' => 'content', 'ai' => 'content',
        // data / reports (operational + financial datasets — surfaced in the admin overhaul)
        'data' => 'data', 'votes' => 'data', 'donations' => 'data', 'orders' => 'data', 'users' => 'data',
        'registrations' => 'data', 'points' => 'data', 'comments' => 'data', 'activity' => 'data', 'reports' => 'data',
        // Analytics is `data` and not `finance` on purpose: it carries no naira
        // figure at all. Gating the growth of the platform behind the revenue
        // section would hide it from the editors and moderators whose work it is
        // meant to direct. See the note on AnalyticsController.
        'analytics' => 'data',
        // finance (superadmin + admin)
        //
        // `refunds`, `vote-delivery` and `payments` were missing here, and the
        // consequence was not that they were open — unmapped fails CLOSED, to
        // superadmin — but that the SIDEBAR and the GUARD disagreed. The Finance
        // group renders for anyone holding the `finance` section, which includes
        // `admin`, so an admin was shown "Refunds" and "Vote Delivery", clicked
        // them, and was bounced to the dashboard told their role has no access to
        // a section their sidebar had just offered. Mapping them puts both sides
        // back on one answer: these are finance, and finance is superadmin+admin.
        //
        // `payments` covers the triage screen and the gateway ledger under it.
        'finance' => 'finance', 'refunds' => 'finance', 'vote-delivery' => 'finance',
        'payments' => 'finance',
        // configuration (superadmin)
        'admins' => 'configuration', 'settings' => 'configuration', 'webhooks' => 'configuration', 'judges' => 'configuration',
        // overview — the dashboard and the AI console copilot (every role may use
        // the assistant; the controller enforces the per-role usage budget).
        'dashboard' => 'overview', 'assistant' => 'overview',
    ];

    /** Auth-exempt / utility admin paths that carry no section (login, logout, magic, admin-api). */
    public static function isUtilityPath(string $path): bool
    {
        $path = '/' . trim($path, '/');
        if (!str_starts_with($path, '/admin/')) return false;
        $seg = strtok(substr($path, strlen('/admin/')), '/') ?: '';
        return in_array($seg, ['login', 'logout', 'magic', 'api'], true);
    }

    /** Map a request path to its guarded section, or null when it carries none. */
    public static function sectionForPath(string $path): ?string
    {
        $path = '/' . trim($path, '/');
        if ($path === '/admin' || $path === '/') return 'overview';
        if (!str_starts_with($path, '/admin/')) return null;
        $seg = strtok(substr($path, strlen('/admin/')), '/') ?: '';
        // Auth-exempt + utility routes carry no section (they pass through the guard).
        if (in_array($seg, ['login', 'logout', 'magic', 'api', ''], true)) return null;
        return self::PATH_SECTIONS[$seg] ?? null;
    }

    /** True when $role may view $section. */
    public static function canAccess(string $role, string $section): bool
    {
        return in_array($role, self::MATRIX[$section] ?? [], true);
    }

    /**
     * Integrity & award decisions — CPI score/tier, verification tier,
     * completeness, and setting winners/runner-ups — are reserved for admin and
     * superadmin. A moderator moderates (approve/reject/suspend) but must not
     * rewrite the numbers that decide rank or crown winners.
     */
    public static function canManageIntegrity(string $role): bool
    {
        return in_array($role, ['superadmin', 'admin'], true);
    }

    /** The sections $role may view — drives the sidebar. */
    public static function allowedSections(string $role): array
    {
        $out = [];
        foreach (self::MATRIX as $section => $roles) {
            if (in_array($role, $roles, true)) $out[] = $section;
        }
        return $out;
    }

    /** A human label for a role (falls back to the raw value). */
    public static function label(string $role): string
    {
        return self::ROLES[$role]['label'] ?? ucfirst($role);
    }

    public static function isRole(string $role): bool
    {
        return array_key_exists($role, self::ROLES);
    }
}
