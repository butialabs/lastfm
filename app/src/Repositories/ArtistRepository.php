<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ArtistRepository
{
    private PDO $pdo;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Find an artist by name
     * 
     * @param string $name The artist name
     * @return array|null The artist data or null if not found
     */
    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artists WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);
        $artist = $stmt->fetch();
        
        return $artist ?: null;
    }

    /**
     * Find an artist by image hash
     * 
     * @param string $hash The image hash (MD5 of artist name)
     * @return array|null The artist data or null if not found
     */
    public function findByImageHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artists WHERE image_hash = :hash LIMIT 1');
        $stmt->execute([':hash' => $hash]);
        $artist = $stmt->fetch();
        
        return $artist ?: null;
    }

    /**
     * Create a new artist record
     * 
     * @param array $data The artist data
     * @return int The ID of the created artist
     */
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        
        $stmt = $this->pdo->prepare('
            INSERT INTO artists (name, lastfm_url, musicbrainz_id, image_hash, created_at, updated_at)
            VALUES (:name, :lastfm_url, :musicbrainz_id, :image_hash, :created_at, :updated_at)
        ');
        
        $stmt->execute([
            ':name' => $data['name'],
            ':lastfm_url' => $data['lastfm_url'],
            ':musicbrainz_id' => $data['musicbrainz_id'] ?? null,
            ':image_hash' => $data['image_hash'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update an existing artist record
     * 
     * @param int $id The artist ID
     * @param array $data The data to update
     * @return bool Whether the update was successful
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id];
        
        foreach ($data as $key => $value) {
            if (in_array($key, ['name', 'lastfm_url', 'musicbrainz_id', 'image_hash'])) {
                $fields[] = "$key = :$key";
                $params[":$key"] = $value;
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $fields[] = "updated_at = :updated_at";
        $params[':updated_at'] = date('Y-m-d H:i:s');
        
        $sql = 'UPDATE artists SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        
        return $stmt->execute($params);
    }

    /**
     * Find an artist by ID
     * 
     * @param int $id The artist ID
     * @return array|null The artist data or null if not found
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artists WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $artist = $stmt->fetch();
        
        return $artist ?: null;
    }

    /**
     * Find all artists with optional filtering
     * 
     * @param array $filters Filters to apply
     * @param int $limit Maximum number of results
     * @param int $offset Pagination offset
     * @return array List of artists
     */
    public function findAll(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $where = [];
        $params = [];
        
        if (!empty($filters['search'])) {
            $where[] = 'name LIKE :search';
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        if (isset($filters['no_image']) && $filters['no_image'] === '1') {
            $where[] = '(image_hash IS NULL OR image_hash = "")';
        }
        
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $sql = "SELECT * FROM artists $whereClause ORDER BY name ASC LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Count all artists with optional filtering
     * 
     * @param array $filters Filters to apply
     * @return int Total count
     */
    public function countAll(array $filters = []): int
    {
        $where = [];
        $params = [];
        
        if (!empty($filters['search'])) {
            $where[] = 'name LIKE :search';
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        if (isset($filters['no_image']) && $filters['no_image'] === '1') {
            $where[] = '(image_hash IS NULL OR image_hash = "")';
        }
        
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $sql = "SELECT COUNT(*) FROM artists $whereClause";
        $stmt = $this->pdo->prepare($sql);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Record artist statistics
     * 
     * @param int $artistId The artist ID
     * @param int $userId The user ID
     * @param int $position The position in the chart (1-5)
     * @param int $playCount Number of plays
     * @return void
     */
    public function recordStats(int $artistId, int $userId, int $position, int $playCount): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO artist_stats (artist_id, user_id, position, play_count, recorded_at)
            VALUES (:artist_id, :user_id, :position, :play_count, :recorded_at)
        ');
        
        $stmt->execute([
            ':artist_id' => $artistId,
            ':user_id' => $userId,
            ':position' => $position,
            ':play_count' => $playCount,
            ':recorded_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Get statistics for an artist
     * 
     * @param int $artistId The artist ID
     * @param int|null $limit Maximum number of records to return
     * @return array Statistics data
     */
    public function getArtistStats(int $artistId, ?int $limit = null): array
    {
        $limitClause = $limit !== null ? 'LIMIT ' . (int) $limit : '';
        
        $sql = "
            SELECT 
                s.*, 
                u.username, 
                u.lastfm_username
            FROM 
                artist_stats s
            JOIN 
                users u ON s.user_id = u.id
            WHERE 
                s.artist_id = :artist_id
            ORDER BY 
                s.recorded_at DESC
            $limitClause
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':artist_id' => $artistId]);
        
        return $stmt->fetchAll();
    }

    /**
     * Get artist appearance statistics
     * 
     * @param array $filters Optional filters
     * @param int $limit Maximum number of results
     * @param int $offset Pagination offset
     * @return array Statistics data
     */
    public function getArtistAppearanceStats(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $where = ['1=1'];
        $params = [];
        
        if (!empty($filters['from_date'])) {
            $where[] = 's.recorded_at >= :from_date';
            $params[':from_date'] = $filters['from_date'];
        }
        
        if (!empty($filters['to_date'])) {
            $where[] = 's.recorded_at <= :to_date';
            $params[':to_date'] = $filters['to_date'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = 'a.name LIKE :search';
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        $whereClause = implode(' AND ', $where);
        
        $validSortColumns = [
            'name' => 'a.name',
            'appearance_count' => 'appearance_count',
            'average_position' => 'average_position',
            'total_plays' => 'total_plays'
        ];
        
        $sortColumn = $validSortColumns[$filters['sort'] ?? 'appearance_count'] ?? 'appearance_count';
        $sortOrder = strtoupper($filters['order'] ?? 'desc') === 'ASC' ? 'ASC' : 'DESC';
        
        $sql = "
            SELECT
                a.id,
                a.name,
                COUNT(s.id) as appearance_count,
                AVG(s.position) as average_position,
                SUM(s.play_count) as total_plays
            FROM
                artists a
            JOIN
                artist_stats s ON a.id = s.artist_id
            WHERE
                $whereClause
            GROUP BY
                a.id, a.name
            ORDER BY
                $sortColumn $sortOrder
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $this->pdo->prepare($sql);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Count total artist appearances for statistics
     * 
     * @param array $filters Optional filters
     * @return int Total count
     */
    public function countArtistAppearanceStats(array $filters = []): int
    {
        $where = ['1=1'];
        $params = [];
        
        if (!empty($filters['from_date'])) {
            $where[] = 's.recorded_at >= :from_date';
            $params[':from_date'] = $filters['from_date'];
        }
        
        if (!empty($filters['to_date'])) {
            $where[] = 's.recorded_at <= :to_date';
            $params[':to_date'] = $filters['to_date'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = 'a.name LIKE :search';
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        $whereClause = implode(' AND ', $where);
        
        $sql = "
            SELECT 
                COUNT(DISTINCT a.id) as total
            FROM 
                artists a
            JOIN 
                artist_stats s ON a.id = s.artist_id
            WHERE 
                $whereClause
        ";
        
        $stmt = $this->pdo->prepare($sql);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }
}