<?php
/**
 * Batch 3 (admin productivity) — editable legal/policy docs.
 * Creates gates_legal_docs and seeds the three policies with the copy that was
 * previously hardcoded in templates/pages/legal.twig, so nothing changes for
 * visitors until an operator edits them. Idempotent + driver-aware.
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_legal_docs')) {
    if ($sqlite) {
        DB::statement("CREATE TABLE gates_legal_docs (
          slug TEXT PRIMARY KEY, title TEXT NOT NULL, body_html TEXT, updated_label TEXT,
          is_published INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0,
          updated_by INTEGER, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        DB::statement('CREATE INDEX IF NOT EXISTS idx_legal_pub ON gates_legal_docs(is_published, sort_order)');
    } else {
        DB::statement("CREATE TABLE gates_legal_docs (
          slug VARCHAR(60) NOT NULL, title VARCHAR(160) NOT NULL, body_html MEDIUMTEXT, updated_label VARCHAR(60) DEFAULT NULL,
          is_published TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0,
          updated_by BIGINT UNSIGNED DEFAULT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY(slug), KEY idx_legal_pub(is_published, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    echo "created gates_legal_docs\n";
}

// Seed defaults ONLY when the doc is absent — never clobber operator edits.
$sec = static fn(string $h, string $b): string => '<h2>' . $h . '</h2>' . "\n" . '<p>' . $b . '</p>';
$defaults = [
    'privacy' => ['Privacy Policy', 0, implode("\n", [
        $sec('What we collect', 'When you nominate, vote, or register a profile, we store your display name, country, and a SHA-256 hash of your email. We do not retain plaintext emails after the verification code is delivered.'),
        $sec('Why we collect it', 'Hashed emails let us guarantee one vote per category per person without ever holding identifying data. Profile details are used to compute and display your Cultural Power Index score and to surface you in cycle shortlists.'),
        $sec('Cookies', 'We set a session cookie for CSRF protection, a remembered-locale cookie if you choose one, and a fingerprint hash used only for rate-limiting. No third-party advertising trackers. See our <a href="/cookies">Cookie Policy</a> for detail.'),
        $sec('Your rights', 'You can request the deletion of any profile or comment you submitted by emailing <a href="mailto:privacy@afrovanguard.org.ng">privacy@afrovanguard.org.ng</a>. We respond within 14 days. Because emails are stored only as hashes, deletion of identifiers is permanent.'),
        $sec('Age &amp; eligibility', 'Voting is open to anyone, anywhere in the world. Voters aged 16 or older qualify automatically; voters under 16 may take part provided they are in JSS&nbsp;1 or above. Nominating, registering a profile, and commenting are open to verified users aged 16 and older.'),
    ])],
    'terms' => ['Terms of Service', 1, implode("\n", [
        $sec('Participation', '<strong>Voting is open to anyone, anywhere in the world</strong>, free of charge — all you need is a valid email to verify. Voters aged 16 or older qualify automatically; voters under 16 may take part provided they are in JSS&nbsp;1 or above (i.e. enrolled in junior secondary school or higher). Nominating, registering a profile, commenting, and applying for opportunities remain open to people located in — or attributable to — one of the African nations covered by GATES.'),
        $sec('One verified voice', 'Each verified email address may cast one vote per category, per cycle. Submitting multiple emails, automated submissions, or coordinated brigading invalidates the affected votes and may suspend the profile.'),
        $sec('Moderation', 'Nominations, comments, and threads are moderated for accuracy and respect. We remove submissions that defame, target individuals abusively, or contain unverified claims presented as fact.'),
        $sec('Content ownership', 'You retain copyright on anything you submit. By submitting, you grant Africa GATES a non-exclusive licence to display, archive, and surface that content on the platform and in cycle communications.'),
        $sec('The legacy vault', 'Once a cycle closes, the resulting winners, jury rationales, and final rankings become permanent records in the legacy vault. We do not retroactively edit historical attribution.'),
        $sec('Disputes', 'Questions, takedowns, or jury appeals can be sent to <a href="mailto:appeals@afrovanguard.org.ng">appeals@afrovanguard.org.ng</a>. We respond within 14 days. See <a href="/support">Support &amp; Appeals</a> for the full process.'),
    ])],
    'cookies' => ['Cookie Policy', 2, implode("\n", [
        $sec('What cookies we use', 'We use a small set of strictly-necessary cookies to keep you signed in and to protect against fraud (CSRF and rate-limiting). These cannot be switched off, as voting and sign-in will not function without them.'),
        $sec('Analytics', 'With your consent, we use privacy-respecting analytics to understand which pages are useful. These are aggregated and never used to identify you personally.'),
        $sec('No advertising trackers', 'We do not use third-party advertising or cross-site tracking cookies. Your activity on Africa GATES is not sold to advertisers.'),
        $sec('Managing cookies', 'You can clear or block cookies in your browser settings. Blocking necessary cookies may prevent voting and sign-in from working.'),
    ])],
];

$now = date('Y-m-d H:i:s');
foreach ($defaults as $slug => [$title, $order, $body]) {
    $exists = DB::table('gates_legal_docs')->where('slug', $slug)->exists();
    if (!$exists) {
        DB::table('gates_legal_docs')->insert([
            'slug' => $slug, 'title' => $title, 'body_html' => $body,
            'updated_label' => '20 June 2026', 'is_published' => 1, 'sort_order' => $order, 'updated_at' => $now,
        ]);
        echo "seeded legal doc: {$slug}\n";
    }
}

echo "legal docs migration OK\n";
