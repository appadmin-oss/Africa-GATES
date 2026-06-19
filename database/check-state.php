<?php
require __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;
$c = new DB();
$c->addConnection(require __DIR__ . '/../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();
$schema = DB::schema();

echo "=== TABLES ===\n";
// Canonical table set — names match the real schema (see sqlite-schema.sql).
// NOTE: judging uses gates_judge_criteria/_criteria_scores/_notes/_scores (NOT
// "assignments/scores/rubric_criteria"); community uses gates_threads/_comments/
// _cheers/_activity; partners use gates_partner_enquiries.
$expected = ['gates_admins','gates_admin_settings','gates_magic_links','gates_audit_log','gates_uploads','gates_settings',
  'gates_profiles','gates_cpi_history','gates_award_programmes','gates_award_cycles','gates_award_categories',
  'gates_nominations','gates_nomination_drafts','gates_nominees','gates_votes','gates_vote_milestones','gates_vote_snapshots','gates_cycle_transitions',
  'gates_otp_tokens','gates_fraud_scores','gates_collusion_findings','gates_events','gates_funnel_events',
  'gates_judges','gates_judge_criteria','gates_judge_criteria_scores','gates_judge_notes','gates_judge_coi',
  'gates_threads','gates_comments','gates_cheers','gates_activity','gates_moderation_log',
  'gates_legacy_events','gates_opportunities','gates_partner_enquiries','gates_donations','gates_newsletter',
  'gates_cache','gates_rate_limits','gates_cron_log','gates_site_events','gates_posts','gates_jobs','gates_rule_sets'];
foreach ($expected as $t) {
    printf("  %-26s %s\n", $t, $schema->hasTable($t) ? 'EXISTS' : '*** MISSING ***');
}

function cnt($t){ try { return \Illuminate\Database\Capsule\Manager::table($t)->count(); } catch(\Throwable $e){ return 'ERR'; } }
echo "\n=== ROW COUNTS ===\n";
foreach (['gates_admins','gates_judges','gates_profiles','gates_award_programmes','gates_award_cycles','gates_award_categories','gates_nominations','gates_nominees','gates_votes','gates_legacy_events','gates_opportunities','gates_partner_enquiries','gates_threads','gates_site_events','gates_posts'] as $t) {
    printf("  %-26s %s\n", $t, cnt($t));
}

echo "\n=== AWARD PROGRAMMES ===\n";
try { foreach (DB::table('gates_award_programmes')->get() as $p) {
    printf("  #%d %-32s status=%s\n", $p->id, $p->title ?? '?', $p->status ?? '?');
}} catch(\Throwable $e){ echo "  ERR: ".$e->getMessage()."\n"; }

echo "\n=== AWARD CYCLES (status drives nominate/vote visibility) ===\n";
try { foreach (DB::table('gates_award_cycles')->get() as $cy) {
    printf("  cycle#%d prog#%d status=%-12s nom=%s..%s vote=%s..%s\n",
      $cy->id, $cy->programme_id ?? 0, $cy->status ?? '?',
      $cy->nominations_open ?? '-', $cy->nominations_close ?? '-',
      $cy->voting_open ?? '-', $cy->voting_close ?? '-');
}} catch(\Throwable $e){ echo "  ERR: ".$e->getMessage()."\n"; }

echo "\n=== CATEGORIES PER PROGRAMME ===\n";
try { foreach (DB::table('gates_award_categories')->get() as $cat) {
    printf("  cat#%d prog#%d %s\n", $cat->id, $cat->programme_id ?? 0, $cat->title ?? '?');
}} catch(\Throwable $e){ echo "  ERR: ".$e->getMessage()."\n"; }

echo "\n=== PROFILES BY STATUS ===\n";
try { foreach (DB::table('gates_profiles')->select('status')->get()->groupBy('status') as $s=>$g) {
    printf("  %-12s %d\n", $s, count($g));
}} catch(\Throwable $e){ echo "  ERR\n"; }

echo "\n=== NOMINEES BY STATUS ===\n";
try { foreach (DB::table('gates_nominees')->get()->groupBy('status') as $s=>$g) {
    printf("  %-12s %d\n", $s ?: '(null)', count($g));
}} catch(\Throwable $e){ echo "  ERR\n"; }

echo "\n=== ADMINS ===\n";
try { foreach (DB::table('gates_admins')->get() as $a) {
    printf("  #%d %-34s role=%s active=%s\n", $a->id, $a->email ?? '?', $a->role ?? '?', $a->is_active ?? '?');
}} catch(\Throwable $e){ echo "  ERR: ".$e->getMessage()."\n"; }
