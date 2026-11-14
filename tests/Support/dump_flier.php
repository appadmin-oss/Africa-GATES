<?php
/**
 * Render the three flier formats in both states to PNG files, for looking at.
 *
 * Not a test: the tests assert geometry, the QR's contents and the strings. Whether the thing
 * is worth posting is a question only a person looking at it can answer, and this is how they
 * get one to look at without a database on the host.
 */
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';

final class DumpFlier extends \Tests\TestCase
{
    public function run_(string $dir): void
    {
        $this->setUp();
        $ev = (int) \Illuminate\Database\Capsule\Manager::table('gates_site_events')->insertGetId([
            'title' => 'Africa GATES Gala 2026', 'slug' => 'gala',
            'event_date' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'status' => 'published', 'location' => 'Eko Hotel, Lagos',
        ]);
        $reg = (int) \Illuminate\Database\Capsule\Manager::table('gates_event_registrations')->insertGetId([
            'event_id' => $ev, 'tier' => 'Patron', 'reference' => 'AFG-EVT-DEMO',
            'name' => 'Ada Nwosu', 'email' => 'ada@example.test',
            'status' => 'confirmed', 'amount_naira' => 380000,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // A real photo, so the photo formats draw their photo layout rather than falling
        // back to `plain` — which is what they correctly do without one.
        $photo = sys_get_temp_dir() . '/ag-dump-photo.jpg';
        $pim = imagecreatetruecolor(900, 1200);
        imagefilledrectangle($pim, 0, 0, 899, 699, (int) imagecolorallocate($pim, 176, 122, 84));
        imagefilledrectangle($pim, 0, 700, 899, 1199, (int) imagecolorallocate($pim, 92, 74, 58));
        imagejpeg($pim, $photo, 92);
        imagedestroy($pim);

        foreach (['confirmed' => $reg, 'open' => 0] as $state => $r) {
            $f = \AfricaGates\Services\EventFlier::forToken(
                \AfricaGates\Services\EventFlierToken::mint($ev, 'Ada Nwosu', $r),
                'https://afg.afrovanguard.org.ng'
            );
            foreach (\AfricaGates\Services\EventFlierLayout::FORMATS as $fmt) {
                $png = (new \AfricaGates\Services\EventFlier())->png($f, $fmt, $photo);
                $out = $dir . '/flier-' . $state . '-' . $fmt . '.png';
                file_put_contents($out, (string) $png);
                echo $out . ' (' . strlen((string) $png) . " bytes)\n";
            }
        }
    }
}
(new DumpFlier('run_'))->run_($argv[1] ?? '.');
