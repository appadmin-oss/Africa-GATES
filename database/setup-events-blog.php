<?php
declare(strict_types=1);
/**
 * One-off provisioner: events + blog tables and starter content.
 * Idempotent (CREATE IF NOT EXISTS; seeds skipped when rows exist).
 * Run: php database/setup-events-blog.php
 */
require __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();

use Illuminate\Database\Capsule\Manager as DB;

$capsule = new DB();
$capsule->addConnection(require __DIR__ . '/../config/database.php');
$capsule->setAsGlobal();
$capsule->bootEloquent();

$schema = DB::schema();

if (!$schema->hasTable('gates_site_events')) {
    $schema->create('gates_site_events', function ($t) {
        $t->increments('id');
        $t->string('slug', 160)->unique();
        $t->string('title', 200);
        $t->string('tagline', 255)->nullable();
        $t->text('description')->nullable();
        $t->string('location', 160)->nullable();
        $t->string('venue', 200)->nullable();
        $t->dateTime('event_date');
        $t->dateTime('end_date')->nullable();
        $t->string('cover_image', 500)->nullable();
        $t->string('rsvp_url', 500)->nullable();
        $t->string('status', 20)->default('published'); // published|draft
        $t->dateTime('created_at')->nullable();
    });
    echo "✓ created gates_site_events\n";
}

if (!$schema->hasTable('gates_posts')) {
    $schema->create('gates_posts', function ($t) {
        $t->increments('id');
        $t->string('slug', 160)->unique();
        $t->string('title', 220);
        $t->string('excerpt', 400)->nullable();
        $t->text('body')->nullable();
        $t->string('cover_image', 500)->nullable();
        $t->string('author', 120)->nullable();
        $t->string('tag', 60)->nullable();
        $t->string('status', 20)->default('published'); // published|draft
        $t->dateTime('published_at')->nullable();
        $t->dateTime('created_at')->nullable();
    });
    echo "✓ created gates_posts\n";
}

$now = date('Y-m-d H:i:s');

if (DB::table('gates_site_events')->count() === 0) {
    DB::table('gates_site_events')->insert([
        [
            'slug' => 'africa-gates-2026-award-ceremony',
            'title' => 'Africa GATES 2026 Award Ceremony',
            'tagline' => 'The inaugural recognition night — winners announced live.',
            'description' => "The first Africa GATES award ceremony brings together nominees, judges, partners and the community for a night of recognition. Category winners across education, integrity, choral and business excellence are announced live, and every result enters the permanent record.",
            'location' => 'Lagos · Nigeria',
            'venue' => 'Alimosho, Lagos (venue announced to ticket holders)',
            'event_date' => date('Y-m-d 16:00:00', strtotime('+78 days')),
            'cover_image' => 'https://images.unsplash.com/photo-1492684223066-81342ee5ff30?auto=format&fit=crop&w=1200&q=70',
            'rsvp_url' => '/partner',
            'status' => 'published',
            'created_at' => $now,
        ],
        [
            'slug' => 'nominations-masterclass-webinar',
            'title' => 'Nominations Masterclass (Online)',
            'tagline' => 'How to write a nomination that judges remember.',
            'description' => "A free one-hour session for the community: what makes a strong nomination, how the CPI score is computed, and how the judging rubric weighs impact, originality, reach and integrity. Live Q&A with a programme director.",
            'location' => 'Online · Google Meet',
            'venue' => 'Link sent after registration',
            'event_date' => date('Y-m-d 18:00:00', strtotime('+21 days')),
            'cover_image' => 'https://images.unsplash.com/photo-1587825140708-dfaf72ae4b04?auto=format&fit=crop&w=1200&q=70',
            'rsvp_url' => '/community',
            'status' => 'published',
            'created_at' => $now,
        ],
        [
            'slug' => 'judges-orientation-2026',
            'title' => 'Judges Orientation — 2026 Cycle',
            'tagline' => 'Closed session for the appointed judging panel.',
            'description' => "Orientation for the 2026 judging panel: rubric calibration, conflict-of-interest rules, and the scoring console walkthrough. Attendance is required before ballots open.",
            'location' => 'Lagos · Nigeria',
            'venue' => 'Hybrid — in person & online',
            'event_date' => date('Y-m-d 10:00:00', strtotime('+45 days')),
            'cover_image' => 'https://images.unsplash.com/photo-1431540015161-0bf868a2d407?auto=format&fit=crop&w=1200&q=70',
            'rsvp_url' => '/judge/login',
            'status' => 'published',
            'created_at' => $now,
        ],
    ]);
    echo "✓ seeded 3 events\n";
}

if (DB::table('gates_posts')->count() === 0) {
    DB::table('gates_posts')->insert([
        [
            'slug' => 'introducing-africa-gates',
            'title' => 'Introducing Africa GATES: a permanent record of African excellence',
            'excerpt' => 'Why we built a continental Cultural Power Index — and how community votes, expert panels and a public archive fit together.',
            'body' => "<p>Africa GATES exists to answer a simple question: when Africans do exceptional work, where does the recognition live?</p><p>Trophies gather dust. Press releases expire. Africa GATES is built differently — a continental Cultural Power Index where the community nominates, expert panels score, and every cycle becomes part of a permanent, public record.</p><h2>How it works</h2><p>Anyone can nominate a profile in under 90 seconds. Nominations are OTP-verified to keep the process honest. Expert judging panels score each shortlisted nominee on impact, originality, reach and integrity, and the CPI score combines community votes with panel scores — recomputed every six hours, publicly.</p><h2>Live in Nigeria, building toward 54</h2><p>We are starting where we live — Alimosho, Lagos — and building the rails to recognise excellence across all 54 nations. The inaugural cycle is open now: nominate someone whose work deserves to be remembered.</p>",
            'cover_image' => 'https://images.unsplash.com/photo-1577368211130-4bbd0181ddf0?auto=format&fit=crop&w=1200&q=70',
            'author' => 'Africa GATES Editorial',
            'tag' => 'Announcements',
            'status' => 'published',
            'published_at' => date('Y-m-d H:i:s', strtotime('-9 days')),
            'created_at' => $now,
        ],
        [
            'slug' => 'how-the-cpi-score-works',
            'title' => 'How the CPI score works — and why it is transparent by design',
            'excerpt' => 'Community votes, judge panels, verification and completeness: the components behind every Cultural Power Index score.',
            'body' => "<p>Every score on the leaderboard traces back to inputs you can inspect. No black boxes.</p><h2>The components</h2><p>The Cultural Power Index blends community votes with expert panel scores, weighted by profile verification and completeness. Votes are OTP-verified — one verified voter, one vote per category — and every vote is fraud-scored before it counts.</p><h2>Recomputed every six hours</h2><p>The index is not a one-off ranking. Scores recompute on a six-hour cycle while voting is open, and the methodology is published in full in our Integrity Center.</p><p>Recognition you can audit is recognition you can trust.</p>",
            'cover_image' => 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=1200&q=70',
            'author' => 'Africa GATES Editorial',
            'tag' => 'Methodology',
            'status' => 'published',
            'published_at' => date('Y-m-d H:i:s', strtotime('-5 days')),
            'created_at' => $now,
        ],
        [
            'slug' => 'partner-with-the-2026-cycle',
            'title' => 'Five ways to back the 2026 cycle',
            'excerpt' => 'Voting packs, event tickets, child champion donations, corporate sponsorship — and what each one funds.',
            'body' => "<p>Africa GATES is community-funded by design. Here is where every naira goes.</p><h2>1. Voting packs</h2><p>Buy votes, cast them across any open category. Funds verification infrastructure.</p><h2>2. Event tickets</h2><p>Attend the ceremony live, with voting credits included.</p><h2>3. Child Champion donations</h2><p>Separate from voting — these fund leadership and character development programmes for children in Alimosho.</p><h2>4. Corporate sponsorship</h2><p>Category exclusivity, stage presence and verified community reach, from Community Partner to Title Sponsor.</p><h2>5. Exhibitions and adverts</h2><p>Booths, magazine pages and livestream access for organisations that want to show up in person.</p><p>Every tier is listed on the partner page — and every contribution is acknowledged publicly.</p>",
            'cover_image' => 'https://images.unsplash.com/photo-1559027615-cd4628902d4a?auto=format&fit=crop&w=1200&q=70',
            'author' => 'Africa GATES Editorial',
            'tag' => 'Partnership',
            'status' => 'published',
            'published_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
            'created_at' => $now,
        ],
    ]);
    echo "✓ seeded 3 posts\n";
}

echo "Done.\n";
