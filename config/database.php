<?php
declare(strict_types=1);

$driver = $_ENV['DB_DRIVER'] ?? 'mysql';

if ($driver === 'sqlite') {
    $dbPath = $_ENV['DB_PATH'] ?? __DIR__ . '/../var/data/africa_gates.sqlite';
    if (!is_dir(dirname($dbPath))) {
        @mkdir(dirname($dbPath), 0775, true);
    }
    return [
        'driver'                  => 'sqlite',
        'database'                => $dbPath,
        'prefix'                  => '',
        'foreign_key_constraints' => true,
        'options'                 => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ],
    ];
}

return [
    'driver'    => 'mysql',
    'host'      => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'port'      => (int)($_ENV['DB_PORT'] ?? 3306),
    'database'  => $_ENV['DB_NAME'] ?? 'africa_gates',
    'username'  => $_ENV['DB_USER'] ?? 'root',
    'password'  => $_ENV['DB_PASS'] ?? '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
    'strict'    => true,
    'options'   => [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ],
];
