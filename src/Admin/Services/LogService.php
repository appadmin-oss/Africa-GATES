<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

/**
 * Thin facade around a Monolog logger with sensible defaults.
 * Logs to var/logs/africa-gates.log with daily rotation (14 days).
 */
class LogService
{
    private readonly Logger $logger;

    public function __construct(?string $logDir = null, string $channel = 'africa-gates')
    {
        $logDir ??= dirname(__DIR__, 3) . '/var/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $this->logger = new Logger($channel);
        $handler = new RotatingFileHandler($logDir . '/africa-gates.log', 14, Logger::DEBUG);
        $handler->setFormatter(new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s',
            true,
            true
        ));
        $this->logger->pushHandler($handler);
    }

    public function info(string $msg, array $ctx = []): void  { $this->logger->info($msg, $ctx); }
    public function warn(string $msg, array $ctx = []): void  { $this->logger->warning($msg, $ctx); }
    public function error(string $msg, array $ctx = []): void { $this->logger->error($msg, $ctx); }
    public function debug(string $msg, array $ctx = []): void { $this->logger->debug($msg, $ctx); }
    public function logger(): Logger { return $this->logger; }
}
