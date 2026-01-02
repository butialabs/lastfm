<?php

declare(strict_types=1);

namespace App\Services;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;

final class LoggerFactory
{
    public function __construct(private readonly string $dataPath)
    {
    }

    public function make(string $channel): Logger
    {
        $logDir = $this->dataPath . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $logger = new Logger($channel);

        if (PHP_SAPI === 'cli') {
            $logger->pushHandler(new StreamHandler('php://stderr', Logger::INFO));
        }

        $logger->pushHandler(new StreamHandler($logDir . '/' . $channel . '.log', Logger::DEBUG));
        return $logger;
    }
}

