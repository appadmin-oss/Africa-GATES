<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Controller→Twig render smoke tests. These catch the class of bug that hid on
 * the registry — a controller passing a variable under a name the template
 * doesn't read, which Twig's non-strict mode silently renders as an EMPTY state
 * (no error, green suite, blank page). We boot the REAL DI container + Twig and
 * render each data page against seeded data, asserting the content shows and the
 * empty-state copy does NOT.
 */
class PageRenderSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_profiles')->insert([
            ['slug' => 'ada-obi',  'display_name' => 'Ada Obi',  'email' => 'ada@example.com',  'category' => 'Music', 'profile_type' => 'individual', 'country_code' => 'NG', 'region' => 'west', 'cpi_score' => 812, 'cpi_tier' => 'platinum', 'status' => 'approved', 'completeness_pct' => 90],
            ['slug' => 'bola-ade',  'display_name' => 'Bola Ade', 'email' => 'bola@example.com', 'category' => 'Film',  'profile_type' => 'individual', 'country_code' => 'GH', 'region' => 'west', 'cpi_score' => 640, 'cpi_tier' => 'gold',     'status' => 'approved', 'completeness_pct' => 80],
        ]);
    }

    private function render(string $class, string $method, string $path = '/'): array
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $container = $builder->build();

        $ctrl = $container->get($class);
        $req  = (new ServerRequestFactory())->createServerRequest('GET', $path);
        $out  = $ctrl->$method($req, new Response());
        return [$out->getStatusCode(), (string) $out->getBody()];
    }

    public function test_registry_renders_profiles_not_empty_state(): void
    {
        [$status, $body] = $this->render(\AfricaGates\Controllers\RegistryController::class, 'index', '/registry');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Ada Obi', $body, 'seeded profile must appear in the grid');
        $this->assertStringNotContainsString('just getting started', $body, 'must NOT fall back to the empty state when profiles exist');
    }

    public function test_leaderboard_renders_entries_not_empty_state(): void
    {
        [$status, $body] = $this->render(\AfricaGates\Controllers\LeaderboardController::class, 'index', '/leaderboard');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Ada Obi', $body, 'top profile must appear in the ranking');
        $this->assertStringNotContainsString("hasn’t been ranked yet", $body, 'must NOT show the pre-cycle empty state');
    }
}
