<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * FlierRaster::fetch() speaks http and https and nothing else.
 *
 * A photo URL is a stored value, and both transports behind fetch() speak far more than
 * the web — cURL does `file://`, the stream wrapper does `file://` and `php://filter`. So a
 * `file://` "photo" was read off this disk before failing to decode as an image.
 */
final class FlierRasterFetchTest extends TestCase
{
    public function test_a_non_web_scheme_is_never_read(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ag-fetch-');
        file_put_contents($tmp, 'SECRET=do-not-read');

        $r = new class { use \AfricaGates\Services\FlierRaster;
            public function get(string $u): ?string { return $this->fetch($u); }
        };

        try {
            foreach (['file://' . $tmp, 'php://filter/resource=' . $tmp, 'FILE://' . $tmp] as $u) {
                $this->assertNull(@$r->get($u), "fetch() read a local file through {$u}");
            }
        } finally {
            @unlink($tmp);
        }
    }
}
