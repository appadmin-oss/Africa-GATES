<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * THE VOLUNTEER PORTAL MUST SAY WHAT EACH CONTROL DOES, AND HOW TO SEND A REPORT.
 *
 * A coordinator's voice note to the owner (31 Oct 2026): "a lot of people are asking how they
 * can send their report on the dashboard, and how to do this and that… even I don't know how
 * to go about it." The people were the volunteers. Two things were wrong and this file holds
 * both:
 *
 *   · THE REPORT HAD NO NAME. What a volunteer calls "my report" is the hours log by hand —
 *     a tab called "Log hours", two clicks deep, under a page whose purpose line told them
 *     not to use it. Nothing on Today, nothing in Find, nothing that said where it went.
 *   · CONTROLS SAID A VERB AND NOTHING ELSE. "Answer", "Retake", "Drop", "Start", "Open",
 *     "Check", "Confirm" — each correct beside the row it sat on and meaningless on its own,
 *     which is how a screen reader's list of links reads it and how a hurried phone user
 *     reads it too (WCAG 2.4.4 and 2.4.6).
 *
 * And a guide, the Volunteer academy, that anybody can open without signing in.
 */
final class VolunteerAcademyTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * A word that, alone on a button, does not say what it acts on or what happens.
     * Lower case; compared against the whole visible label.
     */
    public const VAGUE = ['open', 'view', 'review', 'details', 'more', 'manage', 'submit', 'continue', 'go', 'save',
        'answer', 'reply', 'start', 'confirm', 'decline', 'approve', 'withdraw', 'check', 'drop', 'retake', 'download',
        'leave', 'write', 'find', 'clear', 'call', 'send', 'done', 'ok', 'edit', 'delete', 'remove', 'cancel', 'next',
        'back', 'update', 'go back', 'see more', 'click here', 'learn more'];

    /** Every file that draws a control in the volunteer workspace or on its application. */
    private const SOURCES = [
        'src/Services/Volunteer/WorkspacePages.php',
        'src/Services/Volunteer/Workspace.php',
        'src/Controllers/VolunteerController.php',
        'templates/shell/sections.twig',
        'templates/shell/layout.twig',
        'templates/volunteer/apply.twig',
        'templates/volunteer/academy.twig',
    ];

    /**
     * The visible label of every control a source can draw.
     *
     * PHP: a `label` in an array that also carries `href` or `post` (a row's or a header's
     * action), every form's `submit`, and the request decision options `['key', 'Label', bool]`.
     * Twig: the text of every <button>, <summary> and button-styled <a>, with Twig expressions
     * and screen-reader-only spans removed — what is left is what a sighted person reads.
     *
     * The lead console shares the shell; its Ask dialog (`sh.ask`) is never set for a
     * volunteer, so those blocks are left out rather than renamed under somebody else's page.
     *
     * @return list<array{0:string,1:string}> [file, label]
     */
    public static function labels(string $rel, string $src): array
    {
        $out = [];
        if (str_ends_with($rel, '.php')) {
            preg_match_all("~\\['label' => '((?:[^'\\\\]|\\\\.)*)', '(?:href|post)' =>~", $src, $m);
            foreach ($m[1] as $l) $out[] = [$rel, stripslashes($l)];
            preg_match_all("~'submit' => '((?:[^'\\\\]|\\\\.)*)'~", $src, $m);
            foreach ($m[1] as $l) $out[] = [$rel, stripslashes($l)];
            preg_match_all("~\\['[a-z_]+', '((?:[^'\\\\]|\\\\.)*)', (?:true|false)\\]~", $src, $m);
            foreach ($m[1] as $l) $out[] = [$rel, stripslashes($l)];
            return $out;
        }
        $src = (string) preg_replace('~\{#.*?#\}~s', '', $src);
        $src = (string) preg_replace('~\{% if sh\.ask is defined and sh\.ask %\}.*?\{% endif %\}~s', '', $src);
        preg_match_all('~<(button|summary)\b[^>]*>(.*?)</\1>|<a\b[^>]*class="[^"]*btn[^"]*"[^>]*>(.*?)</a>~s', $src, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            $inner = (string) (($x[2] ?? '') !== '' ? $x[2] : ($x[3] ?? ''));
            $inner = (string) preg_replace('~<span class="(?:sh7|ap|ac)-sr">.*?</span>~s', '', $inner);
            $inner = (string) preg_replace('~\{\{.*?\}\}|\{%.*?%\}~s', ' ', $inner);
            $inner = trim((string) preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($inner))));
            if ($inner !== '') $out[] = [$rel, $inner];
        }
        return $out;
    }

    /** @return list<string> "file: label" for every control whose whole label is vague */
    public static function vague(string $rel, string $src): array
    {
        $bad = [];
        foreach (self::labels($rel, $src) as [$f, $l]) {
            if (in_array(mb_strtolower(trim($l, " .…")), self::VAGUE, true)) $bad[] = $f . ': “' . $l . '”';
        }
        return $bad;
    }

    public function test_no_volunteer_control_is_a_bare_vague_verb(): void
    {
        $bad = [];
        foreach (self::SOURCES as $rel) {
            $abs = self::ROOT . '/' . $rel;
            if (!is_file($abs)) continue;
            $bad = array_merge($bad, self::vague($rel, (string) file_get_contents($abs)));
        }
        $this->assertSame([], $bad, "A control must name what it acts on or what happens. These say only a verb:\n  " . implode("\n  ", $bad));
    }

    /** A sweep is evidence only once it has been seen to name a break (CLAUDE.md). */
    public function test_the_sweep_names_every_kind_of_control_it_exists_to_stop(): void
    {
        $php = "['label' => 'Open', 'href' => '/x'] ['label' => 'Answer', 'post' => ['do' => 'x']] 'submit' => 'Save', [['approve', 'Approve', true]]"
             . " ['label' => 'Answer the invite', 'href' => '/x'] ['name' => 'what', 'label' => 'Check', 'type' => 'text']";
        $this->assertSame(['f.php: “Open”', 'f.php: “Answer”', 'f.php: “Save”', 'f.php: “Approve”'], self::vague('f.php', $php),
            'a row action, a posted action, a submit and a decision option are all read; a field label is not a control');
        $twig = '<button class="sh7-btn" type="submit">Confirm<span class="sh7-sr">, {{ ctx }}</span></button>'
              . '<a class="sh7-btn" href="/x">{{ a.label }}</a><summary class="sh7-phonetab">{{ S.icon("more") }}<span>More</span></summary>'
              . '<a class="sh7-btn" href="/y">Confirm hours</a>{# <button>Drop</button> #}'
              . '{% if sh.ask is defined and sh.ask %}<button>Close</button>{% endif %}';
        $this->assertSame(['f.twig: “Confirm”', 'f.twig: “More”'], self::vague('f.twig', $twig),
            'the screen-reader context does not rescue a visible "Confirm"; a computed label and a comment are not read; the Ask dialog is not the volunteer’s');
    }

    private function ctrl(): \AfricaGates\Controllers\VolunteerController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(\AfricaGates\Controllers\VolunteerController::class);
    }

    private function academyHtml(): array
    {
        unset($_SESSION['user_id']);
        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/volunteer/academy');
        $res = $this->ctrl()->academy($req, new \Slim\Psr7\Response());
        return [$res->getStatusCode(), (string) $res->getBody()];
    }

    public function test_the_academy_opens_signed_out_and_every_screenshot_it_shows_is_on_disk(): void
    {
        [$st, $html] = $this->academyHtml();
        $this->assertSame(200, $st);
        $this->assertStringContainsString('Reporting your contacts with nominees', $html);
        preg_match_all('~<img src="([^"?]+)[^"]*" alt="([^"]*)"~', $html, $m, PREG_SET_ORDER);
        $this->assertGreaterThanOrEqual(8, count($m), 'the academy shows its screenshots');
        foreach ($m as [, $src, $alt]) {
            $this->assertFileExists(self::ROOT . '/public' . $src, 'a screenshot the academy shows must be shipped');
            $this->assertGreaterThan(20, mb_strlen($alt), "$src needs alt text that says what it shows");
        }
        // Outside the member group: nothing between a shared link and the page.
        // Booted as public/index.php does (RouteTableIntegrityTest's way): the route file reads
        // the container, so a bare AppFactory::create() fails before a route is registered.
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require self::ROOT . '/config/container.php');
        \Slim\Factory\AppFactory::setContainer($builder->build());
        $app = \Slim\Factory\AppFactory::create();
        (require self::ROOT . '/src/routes.php')($app);
        $found = false;
        foreach ($app->getRouteCollector()->getRoutes() as $r) {
            if ($r->getPattern() !== '/volunteer/academy') continue;
            $found = true;
            $this->assertSame([], $r->getGroups(), 'the academy is not behind the member sign-in');
        }
        $this->assertTrue($found);
    }

    public function test_every_button_the_academy_names_is_one_the_workspace_draws(): void
    {
        [, $html] = $this->academyHtml();
        preg_match_all('~<b class="ac-ui">(.*?)</b>~', $html, $m);
        $this->assertNotEmpty($m[1]);
        $all = [];
        foreach (self::SOURCES as $rel) {
            if ($rel === 'templates/volunteer/academy.twig') continue;
            foreach (self::labels($rel, (string) file_get_contents(self::ROOT . '/' . $rel)) as [, $l]) $all[] = $l;
        }
        // Labels a section builds from data (the outreach quick-log rows, the WhatsApp link) are read from the source as text.
        $text = implode("\n", array_map(static fn ($r) => (string) file_get_contents(self::ROOT . '/' . $r), self::SOURCES));
        foreach ($m[1] as $name) {
            $name = html_entity_decode($name, ENT_QUOTES);
            $this->assertTrue(in_array($name, $all, true) || str_contains($text, "'" . $name . "'"),
                "The academy tells people to press “{$name}”, which the volunteer workspace does not draw.");
        }
    }

    public function test_today_offers_the_contact_report_and_find_answers_report(): void
    {
        $uid = (int) \Illuminate\Database\Capsule\Manager::table('gates_users')->insertGetId(['name' => 'Amaka Obi', 'email' => 'amaka.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $r = \AfricaGates\Services\Volunteer\Volunteers::apply($uid, ['phone' => '0803 555 0101', 'state' => 'Lagos', 'emergency_name' => 'Ada Okafor', 'emergency_phone' => '0803 555 0199',
            'area' => 'Ikeja', 'dob' => '1995-05-01', 'interests' => ['drive'], 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1']);
        $this->assertTrue($r['ok']);
        \AfricaGates\Services\Volunteer\Volunteers::approve((int) $r['id'], 1);
        $prog = (int) \Illuminate\Database\Capsule\Manager::table('gates_award_programmes')->insertGetId(['title' => 'Lagos Awards', 'slug' => 'la-' . bin2hex(random_bytes(3)), 'is_active' => 1]);
        $cycle = (int) \Illuminate\Database\Capsule\Manager::table('gates_award_cycles')->insertGetId(['programme_id' => $prog, 'year' => 2026, 'status' => 'voting']);
        $this->assertTrue(\AfricaGates\Services\Grants::grant('member', $uid, \AfricaGates\Services\Volunteer\Outreach::CAPABILITY, 'cycle', $cycle, date('Y-m-d', strtotime('+20 days')), 1)['ok']);
        \AfricaGates\Services\Volunteer\Outreach::reset();
        $_SESSION['user_id'] = $uid;
        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/volunteer');
        $html = (string) $this->ctrl()->root($req, new \Slim\Psr7\Response(), [])->getBody();
        $this->assertMatchesRegularExpression('~<a class="sh7-btn sh7-btn--primary" href="/volunteer/cycle/' . $cycle . '/nominees">Log a contact with a nominee</a>~', $html,
            'Today’s first, filled button opens the award’s nominees, where a contact is logged');
        $this->assertStringContainsString('href="/volunteer/academy"', $html, 'the guide is one press away');
        $v = \AfricaGates\Services\Volunteer\Volunteers::of($uid);
        $hits = \AfricaGates\Services\Shell\Find::match(\AfricaGates\Services\Volunteer\Workspace::findItems($v, 'active'), 'report');
        $hrefs = array_column($hits, 'href');
        $this->assertContains('/volunteer/cycle/' . $cycle . '/nominees', $hrefs, 'typing “report” finds where a contact is logged');
        $this->assertContains('/volunteer/cycle/' . $cycle . '/nominees/due', $hrefs);
        unset($_SESSION['user_id']);
    }
}
