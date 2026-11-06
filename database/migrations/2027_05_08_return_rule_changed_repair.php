<?php
/**
 * Date the community-return rule change on editions whose rule was saved BEFORE the change
 * was recorded, so the letters sent under the old rule can be found and corrected.
 *
 * 4 Nov 2026: an edition meant as a target pool was saved as Threshold, every voting letter
 * promised live nominees "50% once you have 250 votes", and the operator then put the rule
 * right — on code that did not yet record when an edition's rule changes
 * (CommunityReturnMilestone::CHANGED_AT). NomineeVotingNotice::correctionPlan() finds the
 * nominees owed a correction as "sent the letter before the rule last changed"; with no date,
 * it finds nobody, and the correction button never appears on the one edition that needs it.
 *
 * The evidence used is the cycle's own rule row: its `updated_at` is when its rules were last
 * saved. An edition qualifies only where it HAS a community-return rule of its own, has no
 * change date yet, and has letters SENT before that save. Nothing is sent from here: the
 * console then shows the count and a person decides, with a reason, whether to send the
 * correction — so an edition whose last save changed something else entirely is shown, not
 * mailed. The rule row's `updated_at` is written back unchanged, because it is the evidence.
 *
 * Idempotent: a row that already carries the key is skipped. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use AfricaGates\Services\CommunityReturnMilestone;
use AfricaGates\Services\NomineeVotingNotice;
use Illuminate\Database\Capsule\Manager as DB;

if (!DB::schema()->hasTable('gates_rule_sets') || !DB::schema()->hasTable('gates_broadcast_log')) {
    echo "  · return rule change dates: nothing to repair\n";
    return;
}

$dated = 0;
foreach (DB::table('gates_rule_sets')->where('scope', 'cycle')->get(['id', 'scope_id', 'rules', 'updated_at']) as $row) {
    $rules = json_decode((string) $row->rules, true);
    if (!is_array($rules) || !array_key_exists('community_return_model', $rules)) continue;
    if (array_key_exists(CommunityReturnMilestone::CHANGED_AT, $rules)) continue;
    $saved = (string) ($row->updated_at ?? '');
    if ($saved === '') continue;

    $before = DB::table('gates_broadcast_log')
        ->where('campaign', NomineeVotingNotice::campaign((int) $row->scope_id))
        ->where('status', 'sent')
        ->where('sent_at', '<', $saved)
        ->exists();
    if (!$before) continue;

    $rules[CommunityReturnMilestone::CHANGED_AT] = $saved;
    DB::table('gates_rule_sets')->where('id', $row->id)
        ->update(['rules' => json_encode($rules), 'updated_at' => $saved]);
    $dated++;
    echo '  ~ cycle ' . (int) $row->scope_id . ": letters sent before its rule was saved on $saved can now be corrected from the console\n";
}
echo "  · return rule change dates: $dated edition(s) dated\n";
