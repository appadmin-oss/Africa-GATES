<?php
declare(strict_types=1);
namespace AfricaGates\Services\EventPlan;

/**
 * HOW BIG A CHANGE IS, AND SO WHO APPROVES IT (owner, 5 Nov 2026, "Event and Project
 * Management: Design Plan", Approval tiers): "small changes by the task owner, medium by the
 * project lead, large by the top of the hierarchy." One table, so the event console, the
 * proposals queue and the tests cannot disagree about what a change is.
 *
 *   SMALL   rewording your own work, adding detail to it. Applies itself after LAPSE_HOURS
 *           unless a lead objects — the only tier that may (plan: "Only small changes may
 *           approve themselves").
 *   MEDIUM  new work, moving a due date, giving work to somebody, a team, putting somebody in
 *           an existing role, a role whose ticks are all low or medium risk. A lead decides;
 *           a lead's own medium change is the decision (owner, 5 Nov 2026: "Event lead
 *           should be able to assign roles and tasks").
 *   LARGE   a goal or its target, a gate or its date, an assumption a target rests on, the
 *           budget, and any role that can move money or message many people. An OWNER decides
 *           — never the person who proposed it — or the platform's staff where the event has
 *           no other owner. Money, deadlines, the goal and authority always need approval.
 */
final class Tiers
{
    public const LABELS = ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'];
    public const APPROVER = ['small' => 'Applies itself unless a lead objects', 'medium' => 'A lead decides', 'large' => 'An owner decides'];
    /** Plan: "Approves itself after a set time if nobody objects." */
    public const LAPSE_HOURS = 24;

    /** The tier of a change, by its kind. Unknown kinds are large: fail towards more scrutiny. */
    public static function of(string $kind): string
    {
        return match ($kind) {
            'reword', 'detail' => 'small',
            'work_new', 'due_shift', 'owner_move', 'team', 'member' => 'medium',
            default => 'large',   // goal, target, gate, gate_date, assumption, budget, risky role
        };
    }

    /** A role is large when any tick it carries is high risk (money, mass messages, the door, settings). @param list<string> $perms */
    public static function forRole(array $perms): string
    {
        foreach ($perms as $p) if ((EventPermissions::MENU[$p]['risk'] ?? 'high') === 'high') return 'large';
        return 'medium';
    }
}
