<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Force the testing profile. The harness uses an in-memory SQLite database
// (see Tests\TestCase) so no external DB or .env is required.
$_ENV['APP_ENV']   = 'testing';
$_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_DRIVER'] = 'sqlite';
