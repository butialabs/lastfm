<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\ConnectionFactory;
use League\Plates\Engine;
use Nyholm\Psr7\Response;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AdminController
{
    private PDO $pdo;

    public function __construct(
        ConnectionFactory $db,
        private readonly Engine $views,
    ) {
        $this->pdo = $db->pdo();
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        session_start_safe();
        if (!$this->isAuthenticated()) {
            return redirect('/admin/login');
        }

        $query = $request->getQueryParams();
        
        $filters = [
            'protocol' => trim((string) ($query['protocol'] ?? '')),
            'status' => trim((string) ($query['status'] ?? '')),
            'search' => trim((string) ($query['search'] ?? '')),
            'language' => trim((string) ($query['language'] ?? '')),
            'limit' => (int) ($query['limit'] ?? 25),
            'page' => max(1, (int) ($query['page'] ?? 1)),
        ];

        if (!in_array($filters['limit'], [25, 50, 100], true)) {
            $filters['limit'] = 25;
        }

        $offset = ($filters['page'] - 1) * $filters['limit'];

        $where = [];
        $params = [];

        if ($filters['protocol'] !== '') {
            $where[] = 'protocol = :protocol';
            $params[':protocol'] = $filters['protocol'];
        }

        if ($filters['status'] !== '') {
            $where[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }

        if ($filters['language'] !== '') {
            $where[] = 'language = :language';
            $params[':language'] = $filters['language'];
        }

        if ($filters['search'] !== '') {
            $where[] = '(username LIKE :search OR instance LIKE :search OR lastfm_username LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countSql = "SELECT COUNT(*) FROM users $whereClause";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalFiltered = (int) $countStmt->fetchColumn();

        $sql = "SELECT id, protocol, instance, username, did, lastfm_username, day_of_week, time, timezone, language, status, callback, social_message, social_montage, error_count, created_at, updated_at
                FROM users $whereClause ORDER BY id DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $filters['limit'], PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll();

        $totalUsers = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $activeUsers = (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE status IN ('ACTIVE', 'SCHEDULE')")->fetchColumn();
        $blueskyUsers = (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE protocol = 'at'")->fetchColumn();
        $mastodonUsers = (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE protocol = 'mastodon'")->fetchColumn();

        $totalPages = (int) ceil($totalFiltered / $filters['limit']);

        $html = $this->views->render('admin/dashboard', [
            'users' => $users,
            'totalUsers' => $totalUsers,
            'activeUsers' => $activeUsers,
            'blueskyUsers' => $blueskyUsers,
            'mastodonUsers' => $mastodonUsers,
            'filters' => $filters,
            'currentPage' => $filters['page'],
            'totalPages' => $totalPages,
            'totalFiltered' => $totalFiltered,
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    public function showUser(ServerRequestInterface $request, array $args): ResponseInterface
    {
        session_start_safe();
        if (!$this->isAuthenticated()) {
            return new Response(401, ['Content-Type' => 'application/json'], json_encode(['error' => 'Unauthorized']));
        }

        $userId = (int) ($args['id'] ?? 0);
        if ($userId <= 0) {
            return new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => 'Invalid user ID']));
        }

        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return new Response(404, ['Content-Type' => 'application/json'], json_encode(['error' => 'User not found']));
        }

        unset($user['password']);
        unset($user['token']);

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($user, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function loginForm(ServerRequestInterface $request): ResponseInterface
    {
        session_start_safe();
        if ($this->isAuthenticated()) {
            return redirect('/admin');
        }

        $html = $this->views->render('admin/login', [
            'error' => null,
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        session_start_safe();
        $body = (array) ($request->getParsedBody() ?? []);

        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        $adminUser = $_ENV['ADMIN_USER'] ?? '';
        $adminPassword = $_ENV['ADMIN_PASSWORD'] ?? '';

        if ($adminUser === '' || $adminPassword === '') {
            $html = $this->views->render('admin/login', [
                'error' => 'Admin credentials not configured in .env',
            ]);
            return new Response(500, ['Content-Type' => 'text/html; charset=utf-8'], $html);
        }

        if ($username === $adminUser && $password === $adminPassword) {
            session_set('admin_authenticated', true);
            return redirect('/admin');
        }

        $html = $this->views->render('admin/login', [
            'error' => 'Invalid username or password',
        ]);

        return new Response(401, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        session_start_safe();
        session_remove('admin_authenticated');
        return redirect('/admin/login');
    }

    private function isAuthenticated(): bool
    {
        return session_get('admin_authenticated') === true;
    }
}
