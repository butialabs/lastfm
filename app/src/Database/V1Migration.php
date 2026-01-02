<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class V1Migration
{
    private PDO $sqlite;
    private LoggerInterface $logger;

    public function __construct(
        private ConnectionFactory $connectionFactory,
        LoggerInterface $logger
    ) {
        $this->sqlite = $connectionFactory->pdo();
        $this->logger = $logger;
    }

    public function migrateFromFile(string $sqlFile): array
    {
        if (!file_exists($sqlFile)) {
            throw new RuntimeException("SQL file not found: {$sqlFile}");
        }

        $sqlContent = file_get_contents($sqlFile);
        if ($sqlContent === false) {
            throw new RuntimeException("Failed to read SQL file: {$sqlFile}");
        }

        $this->logger->info('Starting v1 migration', ['file' => $sqlFile]);

        $stats = [
            'users' => 0,
            'mastodon_apps' => 0,
            'errors' => 0,
            'warnings' => []
        ];

        $this->sqlite->beginTransaction();

        try {
            $users = $this->parseUsers($sqlContent);
            $stats['users'] = $this->migrateUsers($users);

            $apps = $this->parseMastodonApps($sqlContent);
            $stats['mastodon_apps'] = $this->migrateMastodonApps($apps);

            $this->sqlite->commit();
            $this->logger->info('Migration completed successfully', $stats);

        } catch (\Throwable $e) {
            $this->sqlite->rollBack();
            $this->logger->error('Migration failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        return $stats;
    }

    private function parseUsers(string $sqlContent): array
    {
        $users = [];
        
        preg_match_all(
            '/INSERT INTO `users` VALUES \((.*?)\);/is',
            $sqlContent,
            $matches
        );

        foreach ($matches[1] as $values) {
            $values = str_replace("'NULL'", 'NULL', $values);
            $row = str_getcsv($values, ',', "'", '\\');
            
            if (count($row) >= 16) {
                $users[] = [
                    'id' => (int)$row[0],
                    'username' => $row[1],
                    'password' => $row[2] === 'NULL' ? null : $row[2],
                    'lastfm_username' => $row[3] === 'NULL' ? null : $row[3],
                    'day_of_week' => $row[4] === 'NULL' ? null : (int)$row[4],
                    'time' => $row[5] === 'NULL' ? null : $row[5],
                    'timezone' => $row[6] === 'NULL' ? null : $row[6],
                    'time_cron' => $row[7] === 'NULL' ? null : $row[7],
                    'day_of_week_cron' => $row[8] === 'NULL' ? null : (int)$row[8],
                    'status' => $row[9] === 'NULL' ? null : $row[9],
                    'instance' => $row[10] === 'NULL' ? null : $row[10],
                    'protocol' => $row[11] === 'NULL' ? null : $row[11],
                    'token' => $row[12] === 'NULL' ? null : $row[12],
                    'social_message' => $row[13] === 'NULL' ? null : $row[13],
                    'social_montage' => $row[14] === 'NULL' ? null : $row[14],
                    'callback' => $row[15] === 'NULL' ? null : $row[15],
                    'at_did' => isset($row[16]) && $row[16] !== 'NULL' ? $row[16] : null,
                ];
            }
        }

        return $users;
    }

    private function parseMastodonApps(string $sqlContent): array
    {
        $apps = [];
        
        preg_match_all(
            '/INSERT INTO `mastodon_apps` VALUES \((.*?)\);/is',
            $sqlContent,
            $matches
        );

        foreach ($matches[1] as $values) {
            $values = str_replace("'NULL'", 'NULL', $values);
            $row = str_getcsv($values, ',', "'", '\\');
            
            if (count($row) >= 7) {
                $apps[] = [
                    'id' => (int)$row[0],
                    'instance' => $row[1],
                    'hostname' => $row[2],
                    'client_id' => $row[3],
                    'client_secret' => $row[4],
                    'scopes' => $row[5],
                    'created_at' => $row[6],
                    'updated_at' => $row[7],
                ];
            }
        }

        return $apps;
    }

    private function migrateUsers(array $users): int
    {
        $sql = <<<'SQL'
            INSERT INTO users (
                protocol, instance, username, did, password, token,
                lastfm_username, day_of_week, time, timezone, language,
                status, callback, social_message, social_montage,
                error_count, created_at, updated_at
            ) VALUES (
                :protocol, :instance, :username, :did, :password, :token,
                :lastfm_username, :day_of_week, :time, :timezone, :language,
                :status, :callback, :social_message, :social_montage,
                :error_count, :created_at, :updated_at
            )
        SQL;

        $count = 0;
        foreach ($users as $user) {
            try {
                $protocol = $this->mapProtocol($user['protocol']);
                $instance = $this->cleanInstance($user['instance']);
                
                $did = null;
                if ($protocol === 'at' && !empty($user['at_did'])) {
                    $did = $user['at_did'];
                }

                $stmt = $this->sqlite->prepare($sql);
                $stmt->execute([
                    ':protocol' => $protocol,
                    ':instance' => $instance,
                    ':username' => $user['username'],
                    ':did' => $did,
                    ':password' => $user['password'],
                    ':token' => $user['token'],
                    ':lastfm_username' => $user['lastfm_username'],
                    ':day_of_week' => $user['day_of_week'],
                    ':time' => $user['time'],
                    ':timezone' => $user['timezone'] ?? 'UTC',
                    ':language' => 'en',
                    ':status' => $this->mapStatus($user['status']),
                    ':callback' => $user['callback'],
                    ':social_message' => $user['social_message'],
                    ':social_montage' => $user['social_montage'],
                    ':error_count' => 0,
                    ':created_at' => date('Y-m-d H:i:s'),
                    ':updated_at' => date('Y-m-d H:i:s'),
                ]);
                $stmt->closeCursor();
                $count++;

            } catch (\Throwable $e) {
                $this->logger->warning('Failed to migrate user', [
                    'username' => $user['username'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $count;
    }

    private function migrateMastodonApps(array $apps): int
    {
        $this->logger->info('Found mastodon apps in v1 export', ['count' => count($apps)]);
        
        return count($apps);
    }

    private function mapProtocol(?string $v1Protocol): string
    {
        return match ($v1Protocol) {
            'mastodon' => 'mastodon',
            'bluesky', 'at' => 'at',
            default => 'mastodon',
        };
    }

    private function cleanInstance(?string $instance): string
    {
        if (empty($instance)) {
            return 'https://mastodon.social';
        }

        if (!preg_match('#^https?://#', $instance)) {
            $instance = 'https://' . $instance;
        }
        
        return $instance;
    }

    private function mapStatus(?string $v1Status): string
    {
        return match ($v1Status) {
            'active', 'schedule' => 'ACTIVE',
            'error' => 'ERROR',
            'paused' => 'PAUSED',
            default => 'ACTIVE',
        };
    }
}