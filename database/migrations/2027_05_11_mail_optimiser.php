<?php
/**
 * The email optimiser (owner, 5 Nov 2026: "prioritises emails, optimises sends, is fast and
 * reliable, dynamic, aware of limits … generating traction"; "improve the campaigns and
 * promotions adaptability"). See Services\Mail\Outbox, MailEngagement and MailTraction.
 *
 *   gates_mail_outbox      one row per (campaign, address): what is still to send, in what
 *                          order (priority), from when (not_before — the send-time choice and
 *                          the retry backoff), with which subject (variant), and what happened.
 *                          UNIQUE(campaign_key, email_hash): queuing a campaign twice queues
 *                          nobody twice.
 *   gates_mail_clicks      a press on a link in an announcement: which campaign, which
 *                          (hashed) address, which link, when, and the variant they had.
 *   gates_mail_conversions what somebody did after arriving from one: voted, nominated, gave.
 *   gates_email_campaigns  + goal (what the campaign is FOR), subject_b (the A/B variant),
 *                          ab_winner (decided from clicks), sending_at (queued and draining).
 *
 * Every status is VARCHAR (CLAUDE.md: a word added later is `Data truncated` on MySQL).
 * Addresses are stored for the outbox because it has to send to them; clicks and conversions
 * keep only the hash, which is all a report needs. Indexes through SchemaIndex. Idempotent.
 */
require_once __DIR__ . '/../bootstrap.php';
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$id   = $sqlite ? 'id INTEGER PRIMARY KEY AUTOINCREMENT' : 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT';
$int  = $sqlite ? 'INTEGER' : 'INT';
$big  = $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED';
$v    = static fn (int $n): string => $sqlite ? 'TEXT' : "VARCHAR($n)";
$text = $sqlite ? 'TEXT' : 'MEDIUMTEXT';
$ts   = $sqlite ? 'TEXT' : 'DATETIME';
$tail = $sqlite ? '' : ', PRIMARY KEY (id)';
$eng  = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

if (!DB::schema()->hasTable('gates_mail_outbox')) {
    DB::statement("CREATE TABLE gates_mail_outbox ($id,
        campaign_key {$v(64)} NOT NULL,
        email {$v(191)} NOT NULL,
        email_hash {$v(64)} NOT NULL,
        payload $text NULL,
        priority $int NOT NULL DEFAULT 50,
        tier {$v(16)} NULL,
        variant {$v(1)} NULL,
        not_before $ts NOT NULL,
        status {$v(16)} NOT NULL DEFAULT 'queued',
        attempts $int NOT NULL DEFAULT 0,
        last_error {$v(300)} NULL,
        sent_at $ts NULL,
        created_at $ts NOT NULL,
        updated_at $ts NULL$tail)$eng");
    echo "  + gates_mail_outbox\n";
}
if (!DB::schema()->hasTable('gates_mail_clicks')) {
    DB::statement("CREATE TABLE gates_mail_clicks ($id,
        campaign_key {$v(64)} NOT NULL,
        email_hash {$v(64)} NOT NULL,
        variant {$v(1)} NULL,
        url {$v(500)} NOT NULL,
        clicked_at $ts NOT NULL$tail)$eng");
    echo "  + gates_mail_clicks\n";
}
if (!DB::schema()->hasTable('gates_mail_conversions')) {
    DB::statement("CREATE TABLE gates_mail_conversions ($id,
        campaign_key {$v(64)} NOT NULL,
        email_hash {$v(64)} NULL,
        kind {$v(24)} NOT NULL,
        ref {$v(64)} NULL,
        value_minor $big NOT NULL DEFAULT 0,
        created_at $ts NOT NULL$tail)$eng");
    echo "  + gates_mail_conversions\n";
}
foreach (['goal' => $v(16), 'subject_b' => $v(200), 'ab_winner' => $v(1), 'sending_at' => $ts] as $col => $type) {
    if (DB::schema()->hasTable('gates_email_campaigns') && !SchemaHas::column('gates_email_campaigns', $col)) {
        DB::statement("ALTER TABLE gates_email_campaigns ADD COLUMN $col $type NULL");
        echo "  + gates_email_campaigns.$col\n";
    }
}
SchemaHas::forget();

echo '  ' . SchemaIndex::ensure('gates_mail_outbox', 'uq_outbox_campaign_email', ['campaign_key', 'email_hash'], true) . "\n";
echo '  ' . SchemaIndex::ensure('gates_mail_outbox', 'idx_outbox_due', ['status', 'not_before', 'priority']) . "\n";
echo '  ' . SchemaIndex::ensure('gates_mail_clicks', 'idx_clicks_campaign', ['campaign_key', 'email_hash']) . "\n";
echo '  ' . SchemaIndex::ensure('gates_mail_clicks', 'idx_clicks_hash', ['email_hash', 'clicked_at']) . "\n";
echo '  ' . SchemaIndex::ensure('gates_mail_conversions', 'idx_conv_campaign', ['campaign_key', 'kind']) . "\n";
echo "mail optimiser OK\n";
