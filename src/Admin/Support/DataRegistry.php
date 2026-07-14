<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Support;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Registry of collected datasets exposed in the admin "Data" explorer.
 *
 * One definition per dataset drives the generic browse/detail/export views, so
 * every dataset gets a consistent, paginated, searchable table + a full-detail
 * page without bespoke controllers. List columns are explicit (no SELECT *);
 * the detail page shows every column EXCEPT the globally-hidden secrets.
 *
 * `cols` entries are [column, label, format?] where format ∈ datetime|naira|chip|bool.
 */
final class DataRegistry
{
    /** Columns never rendered anywhere (credentials / raw secrets). */
    public const HIDDEN = ['password_hash', 'token_hash', 'otp', 'otp_hash', 'secret', 'magic_token'];

    /** Roles that may view EVERY dataset (the see-all tier). */
    private const BASE_ROLES = ['superadmin', 'admin', 'viewer'];

    /** Extra roles, beyond BASE_ROLES, allowed per dataset (role-scoped least-privilege access). */
    private const EXTRA_ROLES = [
        // Moderation + integrity datasets a moderator needs.
        'votes' => ['moderator'], 'comments' => ['moderator'], 'cheers' => ['moderator'],
        'activity' => ['moderator', 'editor'], 'moderation-log' => ['moderator'],
        'vote-milestones' => ['moderator'], 'collusion' => ['moderator'], 'fraud-scores' => ['moderator'],
        // Analytics / content datasets an editor needs.
        'funnel' => ['editor'], 'cpi-history' => ['editor'], 'nomination-drafts' => ['editor'], 'newsletter' => ['editor'],
    ];

    public const SETS = [
        'votes' => [
            'label' => 'Votes', 'table' => 'gates_votes', 'group' => 'Engagement',
            'order' => ['voted_at', 'desc'], 'search' => ['voter_name', 'voter_phone'],
            'cols' => [['id', 'ID'], ['nominee_id', 'Nominee #'], ['voter_name', 'Voter'], ['vote_type', 'Type', 'chip'], ['weight', 'Weight'], ['voted_at', 'When', 'datetime']],
        ],
        'donations' => [
            'label' => 'Donations', 'table' => 'gates_donations', 'group' => 'Commerce',
            'order' => ['created_at', 'desc'], 'search' => ['donor_name', 'donor_email', 'payment_ref'],
            'cols' => [['id', 'ID'], ['donor_name', 'Donor'], ['donor_email', 'Email'], ['amount_naira', 'Amount', 'naira'], ['tier', 'Tier'], ['status', 'Status', 'chip'], ['created_at', 'When', 'datetime']],
        ],
        'orders' => [
            'label' => 'Shop orders', 'table' => 'gates_orders', 'group' => 'Commerce',
            'order' => ['created_at', 'desc'], 'search' => ['reference', 'email', 'name'],
            'cols' => [['id', 'ID'], ['reference', 'Ref'], ['name', 'Name'], ['email', 'Email'], ['subtotal_naira', 'Subtotal', 'naira'], ['status', 'Status', 'chip'], ['provider', 'Provider'], ['created_at', 'When', 'datetime']],
        ],
        'users' => [
            'label' => 'User accounts', 'table' => 'gates_users', 'group' => 'People',
            'order' => ['created_at', 'desc'], 'search' => ['name', 'email'],
            'cols' => [['id', 'ID'], ['name', 'Name'], ['email', 'Email'], ['points', 'Points'], ['status', 'Status', 'chip'], ['created_at', 'Joined', 'datetime']],
        ],
        'points' => [
            'label' => 'Points ledger', 'table' => 'gates_points_ledger', 'group' => 'People',
            'order' => ['created_at', 'desc'], 'search' => ['reason'],
            'cols' => [['id', 'ID'], ['user_id', 'User #'], ['delta', 'Change'], ['reason', 'Reason', 'chip'], ['balance_after', 'Balance'], ['created_at', 'When', 'datetime']],
        ],
        'newsletter' => [
            'label' => 'Newsletter', 'table' => 'gates_newsletter', 'group' => 'People',
            'order' => ['subscribed_at', 'desc'], 'search' => ['email'],
            'cols' => [['id', 'ID'], ['email', 'Email'], ['source', 'Source'], ['subscribed_at', 'Subscribed', 'datetime'], ['unsubscribed_at', 'Unsubscribed', 'datetime']],
        ],
        'comments' => [
            'label' => 'Comments', 'table' => 'gates_comments', 'group' => 'Community',
            'order' => ['created_at', 'desc'], 'search' => ['author_name', 'body'],
            'cols' => [['id', 'ID'], ['author_name', 'Author'], ['target_type', 'On', 'chip'], ['status', 'Status', 'chip'], ['ai_score', 'AI'], ['created_at', 'When', 'datetime']],
        ],
        'cheers' => [
            'label' => 'Cheers (likes)', 'table' => 'gates_cheers', 'group' => 'Community',
            'order' => ['created_at', 'desc'], 'search' => [],
            'cols' => [['id', 'ID'], ['target_type', 'On', 'chip'], ['target_id', 'Target #'], ['created_at', 'When', 'datetime']],
        ],
        'activity' => [
            'label' => 'Activity feed', 'table' => 'gates_activity', 'group' => 'Community',
            'order' => ['created_at', 'desc'], 'search' => ['actor_label', 'target_label'],
            'cols' => [['id', 'ID'], ['kind', 'Kind', 'chip'], ['actor_label', 'Actor'], ['target_label', 'Target'], ['is_public', 'Public', 'bool'], ['created_at', 'When', 'datetime']],
        ],
        'funnel' => [
            'label' => 'Funnel events', 'table' => 'gates_funnel_events', 'group' => 'Analytics',
            'order' => ['created_at', 'desc'], 'search' => ['step', 'session_id'],
            'cols' => [['id', 'ID'], ['step', 'Step', 'chip'], ['session_id', 'Session'], ['nominee_id', 'Nominee #'], ['created_at', 'When', 'datetime']],
        ],
        'moderation-log' => [
            'label' => 'Moderation log', 'table' => 'gates_moderation_log', 'group' => 'Integrity',
            'order' => ['created_at', 'desc'], 'search' => ['reason', 'provider'],
            'cols' => [['id', 'ID'], ['target_type', 'On', 'chip'], ['target_id', 'Target #'], ['provider', 'Provider'], ['decision', 'Decision', 'chip'], ['score', 'Score'], ['created_at', 'When', 'datetime']],
        ],
        'audit-log' => [
            'label' => 'Admin audit log', 'table' => 'gates_audit_log', 'group' => 'Integrity',
            'order' => ['created_at', 'desc'], 'search' => ['action'],
            'cols' => [['id', 'ID'], ['admin_id', 'Admin #'], ['action', 'Action', 'chip'], ['target_type', 'On'], ['target_id', 'Target #'], ['created_at', 'When', 'datetime']],
        ],
        'judge-scores' => [
            'label' => 'Judge scores', 'table' => 'gates_judge_criteria_scores', 'group' => 'Judging',
            'order' => ['created_at', 'desc'], 'search' => [],
            'cols' => [['id', 'ID'], ['judge_id', 'Judge #'], ['nominee_id', 'Nominee #'], ['criterion_id', 'Criterion #'], ['score', 'Score'], ['created_at', 'When', 'datetime']],
        ],
        'judge-notes' => [
            'label' => 'Judge notes', 'table' => 'gates_judge_notes', 'group' => 'Judging',
            'order' => ['updated_at', 'desc'], 'search' => ['notes'],
            'cols' => [['id', 'ID'], ['judge_id', 'Judge #'], ['nominee_id', 'Nominee #'], ['submitted_at', 'Submitted', 'datetime'], ['updated_at', 'Updated', 'datetime']],
        ],
        'fraud-scores' => [
            'label' => 'Fraud scores', 'table' => 'gates_fraud_scores', 'group' => 'Integrity',
            'order' => ['created_at', 'desc'], 'search' => ['decision'],
            'cols' => [['id', 'ID'], ['vote_id', 'Vote #'], ['risk_score', 'Risk'], ['decision', 'Decision', 'chip'], ['reviewed', 'Reviewed', 'bool'], ['created_at', 'When', 'datetime']],
        ],
        'collusion' => [
            'label' => 'Collusion findings', 'table' => 'gates_collusion_findings', 'group' => 'Integrity',
            'order' => ['last_seen', 'desc'], 'search' => ['explanation', 'kind'],
            'cols' => [['id', 'ID'], ['kind', 'Kind', 'chip'], ['nominee_id', 'Nominee #'], ['vote_count', 'Votes'], ['distinct_voters', 'Voters'], ['risk_score', 'Risk'], ['status', 'Status', 'chip'], ['last_seen', 'Last seen', 'datetime']],
        ],
        'cycle-transitions' => [
            'label' => 'Cycle transitions', 'table' => 'gates_cycle_transitions', 'group' => 'Integrity',
            'order' => ['created_at', 'desc'], 'search' => ['reason', 'actor'],
            'cols' => [['id', 'ID'], ['cycle_id', 'Cycle #'], ['from_status', 'From'], ['to_status', 'To', 'chip'], ['actor', 'Actor'], ['created_at', 'When', 'datetime']],
        ],
        'webhook-deliveries' => [
            'label' => 'Webhook deliveries', 'table' => 'gates_webhook_deliveries', 'group' => 'Integrity',
            'order' => ['created_at', 'desc'], 'search' => ['event'],
            'cols' => [['id', 'ID'], ['webhook_id', 'Hook #'], ['event', 'Event', 'chip'], ['status_code', 'HTTP'], ['ok', 'OK', 'bool'], ['created_at', 'When', 'datetime']],
        ],
        'cpi-history' => [
            'label' => 'CPI history', 'table' => 'gates_cpi_history', 'group' => 'Analytics',
            'order' => ['computed_at', 'desc'], 'search' => [],
            'cols' => [['id', 'ID'], ['profile_id', 'Profile #'], ['cpi_score', 'CPI'], ['cpi_tier', 'Tier', 'chip'], ['computed_at', 'Computed', 'datetime']],
        ],
        'vote-milestones' => [
            'label' => 'Vote milestones', 'table' => 'gates_vote_milestones', 'group' => 'Engagement',
            'order' => ['achieved_at', 'desc'], 'search' => ['milestone'],
            'cols' => [['id', 'ID'], ['nominee_id', 'Nominee #'], ['milestone', 'Milestone', 'chip'], ['notified', 'Notified', 'bool'], ['achieved_at', 'When', 'datetime']],
        ],
        'nomination-drafts' => [
            'label' => 'Nomination drafts', 'table' => 'gates_nomination_drafts', 'group' => 'Analytics',
            'order' => ['updated_at', 'desc'], 'search' => ['session_key'],
            'cols' => [['id', 'ID'], ['session_key', 'Session'], ['created_at', 'Started', 'datetime'], ['updated_at', 'Updated', 'datetime']],
        ],
        'form-submissions' => [
            'label' => 'Form submissions', 'table' => 'gates_form_submissions', 'group' => 'People',
            'order' => ['created_at', 'desc'], 'search' => ['form_key'],
            'cols' => [['id', 'ID'], ['form_key', 'Form', 'chip'], ['created_at', 'When', 'datetime']],
        ],
    ];

    public static function get(string $key): ?array
    {
        return self::SETS[$key] ?? null;
    }

    /** Whether a column must never be shown/exported — explicit list OR a sensitive suffix/prefix (future-proof). */
    public static function isHidden(string $col): bool
    {
        $c = strtolower($col);
        if (in_array($c, self::HIDDEN, true)) return true;
        foreach (['_hash', '_token', '_secret'] as $suf) {
            if (str_ends_with($c, $suf)) return true;
        }
        return str_starts_with($c, 'password');
    }

    /** Datasets whose table actually exists — resilient to partially-migrated schemas. */
    public static function available(): array
    {
        $out = [];
        foreach (self::SETS as $k => $d) {
            try { if (DB::schema()->hasTable($d['table'])) $out[$k] = $d; } catch (\Throwable $e) {}
        }
        return $out;
    }

    /** The roles allowed to view a dataset (see-all tier + any extra role-scoped grants). */
    public static function rolesFor(string $key): array
    {
        return array_values(array_unique(array_merge(self::BASE_ROLES, self::EXTRA_ROLES[$key] ?? [])));
    }

    /** True when $role may view dataset $key. */
    public static function canRole(string $key, string $role): bool
    {
        return isset(self::SETS[$key]) && in_array($role, self::rolesFor($key), true);
    }

    /** Existing datasets the given role may view — drives the hub + access checks. */
    public static function availableForRole(string $role): array
    {
        $out = [];
        foreach (self::available() as $k => $d) {
            if (in_array($role, self::rolesFor($k), true)) $out[$k] = $d;
        }
        return $out;
    }
}
