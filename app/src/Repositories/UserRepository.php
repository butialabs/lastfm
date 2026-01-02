<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PDO;
use Psr\Log\LoggerInterface;
use Throwable;

final class UserRepository
{
    private PDO $pdo;

    public function __construct(
        ConnectionFactory $db,
        private readonly LoggerInterface $logger,
    ) {
        $this->pdo = $db->pdo();
    }

    public function absolutePath(string $relative): string
    {
        $relative = ltrim($relative, '/');
        return dirname(__DIR__, 2) . '/' . $relative;
    }

    public function deleteMontageFile(string $relative): void
    {
        $path = $this->absolutePath($relative);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function deleteById(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function updateLanguage(int $userId, string $language): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET language = :lang, updated_at = :u WHERE id = :id');
        $stmt->execute([
            ':lang' => $language,
            ':u' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            ':id' => $userId,
        ]);
    }

    public function upsertAtUser(string $instance, string $username, string $did, string $encryptedPassword, string $preferredLanguage): int
    {
        $existing = $this->findByProtocolAndUsername('at', $instance, $username);
        if ($existing !== null) {
            $stmt = $this->pdo->prepare('UPDATE users SET did = :did, password = :pass, language = :lang, updated_at = :u WHERE id = :id');
            $stmt->execute([
                ':did' => $did,
                ':pass' => $encryptedPassword,
                ':lang' => $preferredLanguage,
                ':u' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
                ':id' => (int) $existing['id'],
            ]);
            return (int) $existing['id'];
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
        $stmt = $this->pdo->prepare('INSERT INTO users (protocol, instance, username, did, password, token, language, status, error_count, created_at, updated_at) VALUES (:p,:i,:u,:d,:pw,NULL,:lang,:s,0,:c,:c)');
        $stmt->execute([
            ':p' => 'at',
            ':i' => $instance,
            ':u' => $username,
            ':d' => $did,
            ':pw' => $encryptedPassword,
            ':lang' => $preferredLanguage,
            ':s' => 'ACTIVE',
            ':c' => $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function upsertMastodonUser(string $instance, string $username, string $encryptedToken, string $preferredLanguage): int
    {
        $existing = $this->findByProtocolAndUsername('mastodon', $instance, $username);
        if ($existing !== null) {
            $stmt = $this->pdo->prepare('UPDATE users SET token = :t, language = :lang, updated_at = :u WHERE id = :id');
            $stmt->execute([
                ':t' => $encryptedToken,
                ':lang' => $preferredLanguage,
                ':u' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
                ':id' => (int) $existing['id'],
            ]);
            return (int) $existing['id'];
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
        $stmt = $this->pdo->prepare('INSERT INTO users (protocol, instance, username, did, password, token, language, status, error_count, created_at, updated_at) VALUES (:p,:i,:u,NULL,NULL,:t,:lang,:s,0,:c,:c)');
        $stmt->execute([
            ':p' => 'mastodon',
            ':i' => $instance,
            ':u' => $username,
            ':t' => $encryptedToken,
            ':lang' => $preferredLanguage,
            ':s' => 'ACTIVE',
            ':c' => $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    private function findByProtocolAndUsername(string $protocol, string $instance, string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE protocol = :p AND instance = :i AND username = :u LIMIT 1');
        $stmt->execute([':p' => $protocol, ':i' => $instance, ':u' => $username]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function saveSettings(int $userId, string $lastfmUsername, int $dayOfWeekUtc, string $timeUtc, string $timezone, string $status): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
        $stmt = $this->pdo->prepare('UPDATE users SET lastfm_username = :l, day_of_week = :d, time = :t, timezone = :z, status = :s, error_count = 0, updated_at = :u WHERE id = :id');
        $stmt->execute([
            ':l' => $lastfmUsername,
            ':d' => $dayOfWeekUtc,
            ':t' => $timeUtc,
            ':z' => $timezone,
            ':s' => $status,
            ':u' => $now,
            ':id' => $userId,
        ]);
    }

    public function setCallback(int $userId, string $message): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET callback = :c, updated_at = :u WHERE id = :id');
        $stmt->execute([
            ':c' => $message,
            ':u' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            ':id' => $userId,
        ]);
    }

    /**
     * @return array{0:int,1:string} [dayOfWeekUtc(1..7, Mon=1 .. Sun=7), timeUtc(HH:MM:SS)]
     */
    public function convertLocalScheduleToUtc(int $dayOfWeek, string $hour, DateTimeZone $timezone): array
    {
        $nowLocal = new DateTimeImmutable('now', $timezone);

        $todayDow = (int) $nowLocal->format('N');
        $daysAhead = ($dayOfWeek - $todayDow + 7) % 7;
        $targetDate = $nowLocal->modify('+' . $daysAhead . ' days')->setTime(0, 0, 0);

        [$hh, $mm] = array_map('intval', explode(':', $hour));
        $targetLocal = $targetDate->setTime($hh, $mm, 0);
        $targetUtc = $targetLocal->setTimezone(new DateTimeZone('UTC'));

        return [(int) $targetUtc->format('N'), $targetUtc->format('H:i:s')];
    }

    /** @param array<string,mixed> $user */
    public function formatScheduleHourForTimezone(array $user, string $timezone): string
    {
        $dow = (int) ($user['day_of_week'] ?? 7);
        $time = (string) ($user['time'] ?? '09:00:00');
        try {
            $tz = new DateTimeZone($timezone);
        } catch (Throwable) {
            $tz = new DateTimeZone('UTC');
        }

        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $todayDowUtc = (int) $nowUtc->format('N');
        $daysAhead = ($dow - $todayDowUtc + 7) % 7;
        $next = $nowUtc->modify('+' . $daysAhead . ' days');
        $timeParts = array_map('intval', explode(':', $time));
        $hh = $timeParts[0] ?? 0;
        $mm = $timeParts[1] ?? 0;
        $ss = $timeParts[2] ?? 0;
        $next = $next->setTime($hh, $mm, $ss);
        $local = $next->setTimezone($tz);
        return $local->format('H:i');
    }

    /** @return list<array<string,mixed>> */
    public function findUsersDueForSchedule(DateTimeInterface $nowUtc): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE status = 'SCHEDULE' AND day_of_week IS NOT NULL AND time IS NOT NULL AND timezone IS NOT NULL AND lastfm_username IS NOT NULL AND lastfm_username != ''");
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $users = is_array($rows) ? $rows : [];

        $dueUsers = [];
        
        foreach ($users as $user) {
            try {
                $userTimezone = new DateTimeZone($user['timezone']);
                $userLocalTime = new DateTimeImmutable($nowUtc->format('Y-m-d H:i:s'), $userTimezone);
                
                $localDayOfWeek = (int) $userLocalTime->format('N');
                $localTime = $userLocalTime->format('H:i');
                $scheduledTime = substr($user['time'], 0, 5);
                $scheduledDay = (int) $user['day_of_week'];
                
                if ($localDayOfWeek === $scheduledDay && $localTime === $scheduledTime) {
                    $dueUsers[] = $user;
                }
            } catch (Throwable $e) {
                $this->logger->warning('Invalid timezone for user', [
                    'user_id' => $user['id'],
                    'timezone' => $user['timezone'],
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }
        
        return $dueUsers;
    }

    /** @return list<array<string,mixed>> */
    public function findQueuedUsers(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM users WHERE status = 'QUEUED'");
        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    public function markQueued(int $userId, string $montagePath): void
    {
        $stmt = $this->pdo->prepare("UPDATE users SET status = 'QUEUED', social_montage = :m, updated_at = :u WHERE id = :id");
        $stmt->execute([
            ':m' => $montagePath,
            ':u' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            ':id' => $userId,
        ]);
    }

    public function markSending(int $userId): void
    {
        $stmt = $this->pdo->prepare("UPDATE users SET status = 'SENDING', updated_at = :u WHERE id = :id");
        $stmt->execute([
            ':u' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            ':id' => $userId,
        ]);
    }

    public function markScheduledAfterSend(int $userId, string $socialMessage): void
    {
        $stmt = $this->pdo->prepare("UPDATE users SET status = 'SCHEDULE', social_message = :m, error_count = 0, updated_at = :u WHERE id = :id");
        $stmt->execute([
            ':m' => $socialMessage,
            ':u' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            ':id' => $userId,
        ]);
    }

    public function markScheduledAfterGiveUp(int $userId, string $reason): void
    {
        $stmt = $this->pdo->prepare("UPDATE users SET status = 'SCHEDULE', callback = :c, updated_at = :u WHERE id = :id");
        $stmt->execute([
            ':c' => 'Giving up until next week: ' . $reason,
            ':u' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            ':id' => $userId,
        ]);
    }

    public function incrementError(int $userId, string $message, bool $temporary): int
    {
        $user = $this->findById($userId);
        $count = (int) ($user['error_count'] ?? 0);
        $count++;
        $status = $temporary ? 'QUEUED' : 'ERROR';

        $stmt = $this->pdo->prepare('UPDATE users SET error_count = :e, status = :s, callback = :c, updated_at = :u WHERE id = :id');
        $stmt->execute([
            ':e' => $count,
            ':s' => $status,
            ':c' => $message,
            ':u' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            ':id' => $userId,
        ]);
        return $count;
    }

    public function countActiveUsers(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM users WHERE status IN ('ACTIVE', 'SCHEDULE')");
        return (int) $stmt->fetchColumn();
    }

    public function countTotalUsers(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM users");
        return (int) $stmt->fetchColumn();
    }
}

