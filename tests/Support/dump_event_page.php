<?php
/**
 * Render the event page to a file, for measuring in a real browser.
 *
 * Not a test: it exists because the geometry claims in the tier-selection effect ("no
 * layer reaches past the card", "nothing overflows at 390px") are claims about layout, and
 * a regex over a stylesheet cannot settle one. The suite's in-memory database is the only
 * place on this machine with an event to render, hence the harness.
 */
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';

final class Dump extends \Tests\TestCase
{
    public function run_(string $out): void
    {
        $this->setUp();
        $slug = 'fx-demo';
        $ev = (int) \Illuminate\Database\Capsule\Manager::table('gates_site_events')->insertGetId([
            'title' => 'Africa GATES Gala', 'slug' => $slug,
            'event_date' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'status' => 'published', 'ticket_accent' => '#2a6fdb',
            
        ]);
        foreach ([['General', 5000, 'cool', 1], ['Supporter', 25000, 'warm', 2], ['Patron', 380000, 'bold', 3]] as [$n, $p, $c, $o]) {
            \Illuminate\Database\Capsule\Manager::table('gates_event_tiers')->insert([
                'event_id' => $ev, 'slug' => strtolower($n), 'name' => $n, 'price_naira' => $p,
                'capacity' => 100, 'colour' => $c, 'is_active' => 1, 'sort_order' => $o,
                'min_per_order' => 1, 'max_per_order' => 10, 'description' => $n . ' admission',
            ]);
        }
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(require __DIR__ . '/../../config/container.php');
        $ctrl = $b->build()->get(\AfricaGates\Controllers\EventsController::class);
        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/events/' . $slug);
        $res = $ctrl->show($req, (new \Slim\Psr7\Factory\ResponseFactory())->createResponse(), ['slug' => $slug]);
        file_put_contents($out, (string) $res->getBody());
        echo "wrote $out (" . $res->getStatusCode() . ")\n";
    }
}
(new Dump('run_'))->run_($argv[1] ?? 'event.html');
