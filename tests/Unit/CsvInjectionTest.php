<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Csv;
use PHPUnit\Framework\TestCase;

/**
 * No export lets a spreadsheet run what a stranger typed (Support\Csv).
 */
final class CsvInjectionTest extends TestCase
{
    public function test_a_formula_is_shown_not_run(): void
    {
        foreach (['=HYPERLINK("https://x.example")', '+1+1', '-1+1', '@SUM(A1)', "\t=1", "\r=1"] as $evil) {
            $this->assertSame("'" . $evil, Csv::cell($evil), $evil);
        }
    }

    public function test_numbers_and_ordinary_text_are_left_alone(): void
    {
        $this->assertSame(-5, Csv::cell(-5));
        $this->assertSame('-2000.50', Csv::cell('-2000.50'));
        $this->assertSame('Ada Obi', Csv::cell('Ada Obi'));
        $this->assertSame('', Csv::cell(''));
        $this->assertNull(Csv::cell(null));
    }

    public function test_put_writes_the_neutralised_row(): void
    {
        $fh = fopen('php://memory', 'w+');
        Csv::put($fh, ['=cmd()', 'Ada', -3]);
        rewind($fh);
        $this->assertSame("'=cmd(),Ada,-3\n", stream_get_contents($fh));
    }

    /** The sweep: every CSV this platform writes goes through Csv. */
    public function test_no_csv_is_written_around_the_helper(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $bad = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!str_ends_with($f->getFilename(), '.php') || str_ends_with($f->getPathname(), '/Support/Csv.php')) continue;
            $src = (string) file_get_contents($f->getPathname());
            if (preg_match('/(?<![\w:>])\\\\?fputcsv\s*\(/', $src)) $bad[] = substr($f->getPathname(), strlen($root) + 1) . ': fputcsv()';
            if (preg_match_all('/->insertOne\(([^;]*);/', $src, $m)) {
                foreach ($m[1] as $call) {
                    // A header row of string literals is not user data; anything else must be mapped.
                    if (!str_contains($call, 'Csv::class') && preg_match('/\$/', $call)) $bad[] = substr($f->getPathname(), strlen($root) + 1) . ': insertOne()';
                }
            }
        }
        $this->assertSame([], $bad, "CSV written without Support\\Csv:\n" . implode("\n", $bad));
    }
}
