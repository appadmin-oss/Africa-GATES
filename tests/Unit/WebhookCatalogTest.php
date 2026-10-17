<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\WebhookService;
use PHPUnit\Framework\TestCase;

/**
 * Catalog integrity: every event name dispatched anywhere in the codebase must
 * be documented in WebhookService::EVENTS (that constant drives the admin
 * subscription UI), and the catalog must not advertise events nothing fires.
 * Scans the actual source tree so a new dispatch site can never ship
 * undocumented.
 */
final class WebhookCatalogTest extends TestCase
{
    /** @return array<string, true> event => true, from real dispatch call sites */
    private function dispatchedEvents(): array
    {
        $roots = [__DIR__ . '/../../src', __DIR__ . '/../../cron'];
        $found = [];
        foreach ($roots as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') continue;
                $src = (string) file_get_contents($file->getPathname());
                // dispatch() and dispatchLater() both count as a call site: the second
                // is the same delivery queued rather than sent inline, which the payment
                // webhook must do to stay inside Paystack's ~30-second budget. Without
                // `Later` here, moving a caller onto the queue would read as the event
                // having been withdrawn from the catalogue.
                if (preg_match_all("/WebhookService::dispatch(?:Later)?\\(\\s*'([a-z0-9_.]+)'/", $src, $m)) {
                    foreach ($m[1] as $event) $found[$event] = true;
                }
            }
        }
        return $found;
    }

    public function test_every_dispatched_event_is_documented(): void
    {
        $dispatched = $this->dispatchedEvents();
        $this->assertNotEmpty($dispatched, 'expected dispatch call sites in src/');
        foreach (array_keys($dispatched) as $event) {
            $this->assertArrayHasKey($event, WebhookService::EVENTS, "dispatched event '{$event}' missing from WebhookService::EVENTS");
        }
    }

    public function test_catalog_advertises_only_real_events(): void
    {
        $dispatched = $this->dispatchedEvents();
        $dispatched['ping'] = true; // fired via ping(), not dispatch()
        foreach (array_keys(WebhookService::EVENTS) as $event) {
            $this->assertArrayHasKey($event, $dispatched, "catalog event '{$event}' has no dispatch call site");
        }
    }
}
