<?php
declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;
use Tests\TestCase;

/**
 * The submit path, posted through the controller — not around it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS: A 500 THAT EVERY GREEN SUITE WALKED PAST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `NominationController::submit()` looped over `self::uploadedEvidence($req)` and
 * **that method did not exist**. Every nomination carrying a file died with
 * `Call to undefined method` — after the row was written, so the nomination was saved
 * and the nominator got a 500 page. The only thing they could reasonably conclude was
 * that it had not gone through, and the honest cost of that is a second nomination or
 * none.
 *
 * The suite was green throughout. It exercised `AwardService::recordEvidenceFile()`
 * directly, which is the half that worked, and nothing ever posted a file at this
 * controller. **A unit test of the piece below a fault is not a test of the path**, and
 * a path nothing walks is a path that can stop existing without anybody hearing.
 *
 * So this test posts. It builds a real `UploadedFile`, hands it to the real controller
 * and asks what came back and what landed in the database.
 */
final class NominationSubmitPathTest extends TestCase
{
    private int $prog = 0;
    private int $cycle = 0;
    private int $catA = 0;
    private int $catB = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // AUTO_INCREMENT, never a literal: `gates_award_programmes.id` is TINYINT.
        $this->prog = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'submit-path', 'title' => 'Submit Path Awards', 'is_active' => 1,
        ]);
        $this->cycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $this->prog, 'year' => 2026, 'status' => 'nominations',
            'nominations_open'  => date('Y-m-d H:i:s', strtotime('-1 day')),
            'nominations_close' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);
        $this->catA = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycle, 'slug' => 'sp-a', 'title' => 'Teaching', 'sort_order' => 1,
        ]);
        $this->catB = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycle, 'slug' => 'sp-b', 'title' => 'Leadership', 'sort_order' => 2,
        ]);
    }

    private function controller(): \AfricaGates\Controllers\NominationController
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        return $builder->build()->get(\AfricaGates\Controllers\NominationController::class);
    }

    /** @var list<string> temp files to remove when the test ends */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) { if (is_file($f)) @unlink($f); }
        $this->tmp = [];
        parent::tearDown();
    }

    /**
     * A real upload, backed by a real file on disk.
     *
     * NOT an in-memory stream: `UploadedFile::moveTo()` calls `rename()`, and a PHP
     * memory wrapper cannot be renamed — the upload then warns and the store never
     * happens, so the test would pass over a path it had not actually walked. A temp
     * file makes the move real, which is the whole point of posting at the controller.
     */
    private function file(string $name, string $body, string $type = 'application/pdf'): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'agev');
        file_put_contents($path, $body);
        $this->tmp[] = $path;

        return new UploadedFile($path, $name, $type, strlen($body), UPLOAD_ERR_OK);
    }

    private function post(array $body, array $files = []): Response
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/nominate', ['REMOTE_ADDR' => '203.0.113.9'])
            ->withParsedBody($body + [
                'programme_id'        => $this->prog,
                'nominee_name'        => 'Ada Lovelace Nwosu',
                'nominee_kind'        => 'person',
                'nominee_email'       => 'ada@example.com',
                'country_code'        => 'NG',
                'nominee_state'       => 'Lagos',
                'nominee_lga'         => 'Ikeja',
                'nominator_name'      => 'Grace Hopper',
                'nominator_email'     => 'grace@example.com',
                'nominator_phone'     => '+2348031234567',
                'nominator_country'   => 'NG',
                'nominator_state'     => 'Lagos',
                'nominator_lga'       => 'Ikeja',
                'nominator_age_range' => '25-34',
                'consent'             => '1',
                'categories'          => [
                    $this->catA => 'She rebuilt the roll from four hundred to nine hundred pupils.',
                    $this->catB => 'And she did it while carrying the whole senior timetable herself.',
                ],
            ])
            ->withUploadedFiles($files);

        /** @var Response $r */
        $r = $this->controller()->submit($req, new Response());
        return $r;
    }

    // ══════════════════════════════════════════════════════════════════════════

    public function test_a_nomination_carrying_a_file_does_not_500(): void
    {
        $res = $this->post([], ['evidence' => [$this->file('proof.pdf', '%PDF-1.4 proof')]]);

        // The fault was a 500 AFTER the row was written, so both halves are asserted:
        // the response, and that the thing it was supposed to do happened.
        $this->assertLessThan(500, $res->getStatusCode(),
            'posting a nomination with a file is a server error again');

        $this->assertSame(1, (int) DB::table('gates_nominations')
            ->where('nominee_name', 'Ada Lovelace Nwosu')->count());
    }

    public function test_a_nomination_with_no_file_still_works(): void
    {
        // The empty case has to keep working: `evidence` is simply absent from the
        // request, which is what a form with nothing attached posts.
        $res = $this->post([]);

        $this->assertLessThan(500, $res->getStatusCode());
        $this->assertSame(1, (int) DB::table('gates_nominations')
            ->where('nominee_name', 'Ada Lovelace Nwosu')->count());
    }

    public function test_an_empty_file_input_is_not_an_error(): void
    {
        // A file input nobody touched posts with UPLOAD_ERR_NO_FILE. Treating that as
        // a failure would refuse every nomination from somebody who had no document.
        // An errored upload is never moved, so it needs no file behind it.
        $empty = new UploadedFile(
            (new StreamFactory())->createStream(''), '', null, 0, UPLOAD_ERR_NO_FILE);

        $res = $this->post([], ['evidence' => [$empty]]);

        $this->assertLessThan(500, $res->getStatusCode());
        $this->assertSame(1, (int) DB::table('gates_nominations')
            ->where('nominee_name', 'Ada Lovelace Nwosu')->count());
    }

    public function test_the_confirmation_names_the_award_and_every_category(): void
    {
        // ── WHAT THIS LINE IS ───────────────────────────────────────────────
        // It is not decoration on a success page: the same string goes into the
        // confirmation EMAIL and the SMS. Two faults lived in it.
        //
        // The award's name fell back to `'Programme #' . $id` whenever the title was
        // not handed over, and the rebuilt form stopped handing it over — so somebody
        // who had just written about a person they admire was told their nomination
        // was filed under "Programme #1". A placeholder that reads like data is how a
        // wrong answer survives review.
        //
        // And it named ONE category, read off the nomination row's denormalised
        // `category_id`, while a nomination now names two or three. The nominator
        // chose them deliberately and was told their nomination went somewhere
        // narrower than it did.
        // Asked of `NominationAftercare::run()` rather than of the session the
        // controller flashes it through: the resolver is where the rule lives, the
        // session is a transport, and a CLI test has no live session to read.
        $this->post([]);

        $id = (int) DB::table('gates_nominations')
            ->where('nominee_name', 'Ada Lovelace Nwosu')->value('id');

        $line = (string) \AfricaGates\Services\NominationAftercare::run(
            ['programme_id' => $this->prog, 'nominee_name' => 'Ada Lovelace Nwosu',
             'nominator_email' => 'grace@example.com'],
            $id, 'https://example.test'
        )['category'];

        $this->assertStringContainsString('Submit Path Awards', $line,
            'the award fell back to its id again');
        $this->assertStringNotContainsString('Programme #', $line);

        $this->assertStringContainsString('Teaching', $line);
        $this->assertStringContainsString('Leadership', $line,
            'only the first category is named, so two thirds of the choice is lost');
        // Said the way a person would say it, in one place, because three readers
        // print this line and three joins is three chances for "Teaching,Leadership".
        $this->assertStringContainsString('Teaching and Leadership', $line);
    }

    public function test_both_categories_reach_the_table(): void
    {
        $this->post([]);

        $id = (int) DB::table('gates_nominations')
            ->where('nominee_name', 'Ada Lovelace Nwosu')->value('id');

        $this->assertSame([$this->catA, $this->catB],
            array_map('intval', DB::table('gates_nomination_categories')
                ->where('nomination_id', $id)->orderBy('sort_order')
                ->pluck('category_id')->all()));
    }

    // ══ the fields the redesign dropped (owner, 13 Oct 2026) ═════════════════

    /**
     * "Backed from N different locations" counts distinct nominator country + state, so a
     * nomination without them was a nomination from nowhere. Each one is refused by name,
     * and a state is stored as the region list spells it, so one place is one location.
     */
    public function test_the_nominator_location_and_age_are_required_and_canonical(): void
    {
        foreach ([
            ['nominator_age_range' => ''],
            ['nominator_age_range' => '30 to 40'],
            ['nominator_country' => ''],
            ['nominator_state' => ''],
            ['nominator_state' => 'Atlantis'],
            ['nominee_state' => ''],
        ] as $bad) {
            $res = $this->post($bad);
            $this->assertSame(422, $res->getStatusCode(), 'accepted ' . json_encode($bad));
        }
        $this->assertSame(0, (int) DB::table('gates_nominations')->count(), 'a refused nomination was saved');

        $this->assertLessThan(400, $this->post(['nominator_state' => 'lagos', 'nominee_state' => 'LAGOS'])->getStatusCode());
        $row = DB::table('gates_nominations')->where('nominee_name', 'Ada Lovelace Nwosu')->first();
        $this->assertSame('Lagos', $row->nominator_state, 'two spellings of one state would count as two locations');
        $this->assertSame('Lagos', $row->nominee_state);
        $this->assertSame('25–34', $row->nominator_age_range, 'the hyphenated range was not read as the listed one');
    }

    /** An award with one category could be open and impossible to nominate for. */
    public function test_an_award_with_one_category_takes_one(): void
    {
        DB::table('gates_award_categories')->where('id', $this->catB)->delete();
        $res = $this->post(['categories' => [$this->catA => 'She rebuilt the roll from four hundred to nine hundred pupils.']]);
        $this->assertLessThan(400, $res->getStatusCode(), 'one category on a one-category award was refused');
        $this->assertSame(1, \AfricaGates\Services\NominationRules::minCategories(1));
        $this->assertSame(2, \AfricaGates\Services\NominationRules::minCategories(7), 'a full award no longer asks for two');
        $this->assertSame(3, \AfricaGates\Services\NominationRules::maxCategories(7));
    }

    /** A refused post spends nothing; only a nomination that is accepted counts. */
    public function test_a_refused_nomination_does_not_spend_the_daily_limit(): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'nomination_daily_limit'], ['value' => '1']);
        foreach ([1, 2, 3] as $_) $this->assertSame(422, $this->post(['nominator_age_range' => ''])->getStatusCode());

        $this->assertLessThan(400, $this->post([])->getStatusCode(), 'three typos used up the day');
        $this->assertSame(429, $this->post(['nominee_name' => 'Chidi Okafor Eze'])->getStatusCode(), 'the cap no longer holds');
        $this->assertSame(1, (int) DB::table('gates_nominations')->count());
    }
}
