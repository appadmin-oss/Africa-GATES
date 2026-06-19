<?php
/**
 * Africa GATES — Local SQLite setup script
 *
 * Creates the SQLite DB, applies the schema, and seeds rich sample data
 * (profiles, votes, legacy events, opportunities, nominees) so the site
 * is fully populated for local dev / smoke-testing.
 *
 * Usage:   php database/setup-sqlite.php
 */
declare(strict_types=1);

$root   = dirname(__DIR__);
$dbPath = $root . '/var/data/africa_gates.sqlite';
$schema = $root . '/database/sqlite-schema.sql';

if (!is_dir(dirname($dbPath))) {
    mkdir(dirname($dbPath), 0775, true);
}
if (file_exists($dbPath)) {
    unlink($dbPath);
    echo "Removed existing DB: $dbPath\n";
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

echo "Applying schema...\n";
$pdo->exec(file_get_contents($schema));
$pdo->exec(file_get_contents($root . '/database/sqlite-admin-schema.sql'));
$pdo->exec(file_get_contents($root . '/database/sqlite-community-schema.sql'));

// ─── Award programmes + cycles + categories ─────────────────────────
echo "Seeding award programmes...\n";
$programmes = [
    [1, 'principals',    'Incredible Principal Awards',         'Honouring Educational Leadership Across Africa',          'Recognising school principals who have demonstrated exceptional commitment to academic excellence, leadership, and community transformation.', '🎓', 1],
    [2, 'incorruptible', 'Incorruptible Awards',                'Raising a Generation Defined by Integrity',               'Flagship recognition dedicated to celebrating young individuals defined by integrity, discipline, and values-driven living.',                    '🛡️', 2],
    [3, 'carol',         'Carol Awards',                        'Celebrating Excellence in Choral Music',                  'Dedicated to celebrating excellence in choral music and spiritual expression, promoting unity, faith, and cultural identity across Africa.',     '🎶', 3],
    [4, 'business',      'African Business Excellence Awards',  'Recognising Enterprise & Entrepreneurship',               'Recognising outstanding businesses and entrepreneurs contributing to economic growth, innovation, and community development across Africa.',     '🏢', 4],
];
$insP = $pdo->prepare("INSERT INTO gates_award_programmes(id,slug,title,subtitle,description,scope,icon_emoji,sort_order,is_active) VALUES (?,?,?,?,?, 'continental',?,?,1)");
foreach ($programmes as $p) {
    $insP->execute([$p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6]]);
}

$year = (int)date('Y');
$cycles = [
    [1, 1, $year, '2025 Edition', 'nominations', "$year-05-01 00:00:00", "$year-08-31 23:59:59", "$year-09-10 00:00:00", "$year-09-30 23:59:59", "$year-10-15 18:00:00"],
    [2, 2, $year, '2025 Edition', 'nominations', "$year-05-01 00:00:00", "$year-09-15 23:59:59", "$year-09-20 00:00:00", "$year-10-10 23:59:59", "$year-10-25 18:00:00"],
    [3, 3, $year, '2025 Edition', 'voting',      "$year-04-01 00:00:00", "$year-07-31 23:59:59", "$year-08-01 00:00:00", "$year-09-20 23:59:59", "$year-10-05 18:00:00"],
    [4, 4, $year, '2025 Edition', 'voting',      "$year-04-01 00:00:00", "$year-07-31 23:59:59", "$year-08-01 00:00:00", "$year-09-15 23:59:59", "$year-10-01 18:00:00"],
];
$insC = $pdo->prepare("INSERT INTO gates_award_cycles(id,programme_id,year,edition_label,status,nominations_open,nominations_close,voting_open,voting_close,results_date) VALUES (?,?,?,?,?,?,?,?,?,?)");
foreach ($cycles as $c) {
    $insC->execute($c);
}

$categories = [
    // ── Principal Awards (cycle 1) ─────────────────────────────────
    [1, 'academic-excellence',   'Academic Excellence',
        'Recognising principals who have shown outstanding dedication to improving academic standards and performance in their schools.', 1],
    [1, 'community-engagement',  'Community Engagement',
        'Honoring principals who have made significant contributions to the community by actively engaging and collaborating with local residents.', 2],
    [1, 'innovation-education',  'Innovation in Education',
        'Celebrating those who have pioneered innovative teaching methods and educational practices.', 3],
    [1, 'leadership-mentorship', 'Leadership and Mentorship',
        'Acknowledging principals who have displayed exceptional leadership skills and acted as mentors for both students and staff.', 4],
    [1, 'social-development',    'Social Development and Impact',
        'Highlighting the impact of principals on the social and overall development of the Alimosho community.', 5],

    // ── Incorruptible Child Awards (cycle 2) ──────────────────────
    [2, 'teachers-choice',        "Teachers' Choice Award",
        'Selected by educators based on character, honesty, discipline, and leadership.', 1],
    [2, 'creative-change-maker',  'Creative Change-Maker Award',
        'For using arts, music, writing, innovation, or creativity to promote positive values and social impact.', 2],
    [2, 'volunteer-service',      'Volunteer Service Award',
        'For outstanding participation in community service, charity, and humanitarian activities.', 3],
    [2, 'young-peacemaker',       'Young Peacemaker Award',
        'For promoting peace, unity, conflict resolution, and harmony among peers.', 4],
    [2, 'parents-pride',          "Parent's Pride Award",
        'For children whose conduct and achievements reflect exceptional upbringing and family values.', 5],
    [2, 'most-improved-character','Most Improved Character Award',
        'For remarkable growth and transformation in behavior, discipline, and personal values.', 6],

    // ── Choral (Carol) Awards (cycle 3) ───────────────────────────
    [3, 'most-influential-choir', 'Most Influential Choir',
        'Awarded to the choir whose music, ministry, and presence has had the greatest impact on audiences and communities.', 1],
    [3, 'best-contemporary-choir','Best Contemporary Choir',
        'Celebrating a choir that excels in modern choral styles, blending tradition with fresh, current expression.', 2],
    [3, 'most-artistic-choir',    'Most Artistic Choir',
        'Recognising outstanding creativity, originality, and artistic presentation in choral performance.', 3],
    [3, 'most-creative-choir',    'Most Creative Choir',
        'Honouring the choir that brings the most inventive arrangements, concepts, and stage presence to their performances.', 4],
    [3, 'best-dressed-choir',     'Best Dressed Choir',
        'Celebrating the choir whose attire, coordination, and visual presentation best complements their performance.', 5],

    // ── Business Excellence Awards (cycle 4) ──────────────────────
    [4, 'entrepreneur-of-year',  'Entrepreneur of the Year',
        'Recognizing an individual who has shown exceptional vision, leadership, and innovation in their business.', 1],
    [4, 'small-business-champion','Small Business Champion',
        'Highlighting a small business that has made a significant impact in its industry and community.', 2],
    [4, 'innovative-startup',    'Innovative Start-up',
        'Celebrating a start-up that has shown creativity, adaptability, and a pioneering spirit.', 3],
    [4, 'csr-advocate',          'Corporate Social Responsibility (CSR) Advocate',
        'Honoring a business that has demonstrated a strong commitment to social responsibility and community development.', 4],
    [4, 'tech-pioneer',          'Tech Pioneer',
        'Recognizing an individual or business at the forefront of technological innovation in the community.', 5],
    [4, 'sustainable-business',  'Sustainable Business Leader',
        'Celebrating a business in Alimosho that prioritizes environmental sustainability and ethical practices.', 6],
    [4, 'customer-service',      'Customer Service Excellence',
        'Highlighting a business that consistently goes above and beyond in serving its customers.', 7],
];
$insCat = $pdo->prepare("INSERT INTO gates_award_categories(cycle_id,slug,title,description,sort_order) VALUES (?,?,?,?,?)");
foreach ($categories as $cat) {
    $insCat->execute($cat);
}

// ─── Admin + judges ────────────────────────────────────────────────
echo "Seeding admin accounts and judges...\n";
$adminEmail = getenv('SEED_ADMIN_EMAIL') ?: 'admin@afrovanguard.org.ng';
// No known-default password ships. Use SEED_ADMIN_PASSWORD if set, otherwise
// generate a strong random one for this local DB and print it once below.
$adminPass     = getenv('SEED_ADMIN_PASSWORD') ?: '';
$generatedPass = false;
if ($adminPass === '') {
    $adminPass     = bin2hex(random_bytes(9)); // 18 random hex chars
    $generatedPass = true;
}
// Each password_hash() call gets its own bcrypt salt — no hash is reused.
$insA = $pdo->prepare("INSERT INTO gates_admins(email,password_hash,name,role,is_active) VALUES (?,?,?,?,1)");
$insA->execute([$adminEmail, password_hash($adminPass, PASSWORD_BCRYPT), 'Afrovanguard Admin', 'superadmin']);
$insA->execute(['app.admin@afrovanguard.org.ng', password_hash($adminPass, PASSWORD_BCRYPT), 'App Admin', 'superadmin']);
$insA->execute(['editor@afrovanguard.org.ng', password_hash($adminPass, PASSWORD_BCRYPT), 'Content Editor', 'editor']);
$insA->execute(['judge1@afrovanguard.org.ng', password_hash($adminPass, PASSWORD_BCRYPT), 'Dr. Adaobi Nwosu', 'judge']);

$insJ = $pdo->prepare("INSERT INTO gates_judges(admin_id,name,email,title,organisation,bio,country_code,programme_ids,is_active) VALUES (?,?,?,?,?,?,?,?,1)");
$judges = [
    [3, 'Dr. Adaobi Nwosu',  'judge1@afrovanguard.org.ng', 'Professor of Education',          'University of Lagos',         'Three decades championing access to quality education across West Africa.', 'NG', '[1,4]'],
    [null, 'Wangari Mwangi', 'wangari@afrovanguard.org.ng','Founder & Choral Director',       'Voices of Mombasa',           'Multi-award-winning choral director and pan-African cultural producer.',    'KE', '[3]'],
    [null, 'Kofi Mensah',    'kofi@afrovanguard.org.ng',   'Managing Partner',                'AccraVentures Capital',       'Backs early-stage African businesses with operational rigour.',             'GH', '[4]'],
    [null, 'Amal El-Sayed',  'amal@afrovanguard.org.ng',   'Director, Youth Policy',          'Cairo Civic Lab',             'Designs youth leadership programmes adopted across North Africa.',           'EG', '[2]'],
    [null, 'Thandi Khumalo', 'thandi@afrovanguard.org.ng', 'Head, Education Innovation',      'Cape Town Schools Network',   'Brings digital learning to 200+ schools across Southern Africa.',           'ZA', '[1]'],
];
foreach ($judges as $j) {
    $insJ->execute($j);
}

// ─── Settings ──────────────────────────────────────────────────────
$insS = $pdo->prepare("INSERT INTO gates_settings(key_name,value) VALUES (?,?)");
$insS->execute(['announce_text', 'Nominations open — 2026 Cycle · live in Nigeria, building toward 54']);
$insS->execute(['announce_url',  '/nominate']);
$insS->execute(['announce_cta',  'Nominate now →']);

// ─── Admin configurable settings (donation votes, etc.) ────────────
echo "Seeding admin settings...\n";
try {
    $insAS = $pdo->prepare("INSERT OR IGNORE INTO gates_admin_settings(setting_key,setting_value,description) VALUES (?,?,?)");
    $insAS->execute(['donation_votes_per_1000', '5',    'Bonus votes awarded per ₦1,000 donated (admin configurable)']);
    $insAS->execute(['donation_vote_enabled',   '1',    'Enable donation-based bonus votes — 1=yes, 0=no']);
    $insAS->execute(['donation_vote_min_amount','1000', 'Minimum donation amount (₦) to qualify for bonus votes']);
} catch (Exception $e) {
    echo "Admin settings table may not exist yet — skipping: " . $e->getMessage() . "\n";
}

// ─── Judge scoring rubric (4 criteria, equal-weighted) ─────────────
echo "Seeding judge rubric...\n";
$insR = $pdo->prepare("INSERT INTO gates_judge_criteria(programme_id,slug,label,description,weight,sort_order,is_active) VALUES (NULL,?,?,?,?,?,1)");
$criteria = [
    ['impact',     'Impact',     'Measurable difference made for the community or industry.',           25, 1],
    ['originality','Originality','Inventiveness, creativity, novelty of approach.',                      25, 2],
    ['reach',      'Reach',      'Breadth of influence — local, regional, continental, global.',       25, 3],
    ['integrity',  'Integrity',  'Consistency of values, ethics, and accountability.',                  25, 4],
];
foreach ($criteria as $c) { $insR->execute($c); }

echo "\n  ✓ DB ready: $dbPath\n";
echo "  ✓ Seeded: programmes, cycles, categories, admin accounts, judges, rubric criteria, settings.\n";
echo "  ✓ No demo data — all profiles, nominees, votes, legacy events and sample comments omitted.\n";
echo "\n  → Admin login:  $adminEmail\n";
if ($generatedPass) {
    echo "  → Password (DEV ONLY, generated — shown once):  $adminPass\n";
    echo "    Same password for all seeded accounts. Set SEED_ADMIN_PASSWORD to choose your own.\n";
} else {
    echo "  → Password:      (the SEED_ADMIN_PASSWORD you provided)\n";
}
echo "  → Run:           cd public && php -S 127.0.0.1:8000\n";
echo "  → Admin URL:     http://127.0.0.1:8000/admin/login\n\n";
