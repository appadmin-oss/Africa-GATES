<?php
declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case: boots a fresh in-memory SQLite database for every test and
 * loads the project's SQLite schema files. No external DB, no .env required.
 */
abstract class TestCase extends BaseTestCase
{
    protected Capsule $db;

    /** Schema files (under database/) loaded into every fresh test DB. */
    private const SCHEMA_FILES = [
        'sqlite-schema.sql',
        'sqlite-admin-schema.sql',
        'sqlite-community-schema.sql',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            // FK enforcement OFF for unit tests: seeds stay minimal (no need to
            // create whole parent chains). UNIQUE indexes (e.g. one-vote-per-
            // category) are still enforced, so integrity tests remain valid.
            'foreign_key_constraints' => false,
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $this->db = $capsule;

        $this->loadSchema();

        // Static per-process caches must never leak between tests.
        \AfricaGates\Services\SpamService::resetThresholdCache();
    }

    protected function tearDown(): void
    {
        // Drop the in-memory connection so the next test starts clean.
        Capsule::connection()->disconnect();
        parent::tearDown();
    }

    private function loadSchema(): void
    {
        $pdo = Capsule::connection()->getPdo();
        foreach (self::SCHEMA_FILES as $file) {
            $path = __DIR__ . '/../database/' . $file;
            $sql  = file_get_contents($path);
            if ($sql === false) {
                throw new \RuntimeException("Missing schema file: {$path}");
            }
            try {
                // SQLite's PDO can execute multi-statement scripts in one exec().
                $pdo->exec($sql);
            } catch (\PDOException $e) {
                // Fallback: run statements one at a time (handles drivers/scripts
                // that reject multi-statement exec).
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                    if ($stmt !== '') {
                        $pdo->exec($stmt);
                    }
                }
            }
        }

        // The schema files each issue `PRAGMA foreign_keys = ON`. Force it back
        // OFF *after* loading so unit seeds can stay minimal. Done outside any
        // transaction so it persists for the test body.
        $pdo->exec('PRAGMA foreign_keys = OFF');
    }
}
