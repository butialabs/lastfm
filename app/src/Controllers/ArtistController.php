<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ArtistRepository;
use App\Services\LastFmService;
use League\Plates\Engine;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ArtistController
{
    private ArtistRepository $artistRepository;
    private LastFmService $lastFmService;
    private Engine $views;

    public function __construct(
        ArtistRepository $artistRepository,
        LastFmService $lastFmService,
        Engine $views
    ) {
        $this->artistRepository = $artistRepository;
        $this->lastFmService = $lastFmService;
        $this->views = $views;
    }

    /**
     * List all artists with search and pagination
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        session_start_safe();
        if (!$this->isAuthenticated()) {
            return redirect('/admin/login');
        }

        $query = $request->getQueryParams();
        
        $filters = [
            'search' => trim((string) ($query['search'] ?? '')),
            'no_image' => trim((string) ($query['no_image'] ?? '')),
            'limit' => (int) ($query['limit'] ?? 25),
            'page' => max(1, (int) ($query['page'] ?? 1)),
        ];

        if (!in_array($filters['limit'], [25, 50, 100], true)) {
            $filters['limit'] = 25;
        }

        $offset = ($filters['page'] - 1) * $filters['limit'];
        
        $artists = $this->artistRepository->findAll(
            [
                'search' => $filters['search'],
                'no_image' => $filters['no_image']
            ],
            $filters['limit'],
            $offset
        );
        
        $totalArtists = $this->artistRepository->countAll([
            'search' => $filters['search'],
            'no_image' => $filters['no_image']
        ]);
        $totalPages = (int) ceil($totalArtists / $filters['limit']);

        $html = $this->views->render('admin/artists/index', [
            'artists' => $artists,
            'filters' => $filters,
            'currentPage' => $filters['page'],
            'totalPages' => $totalPages,
            'totalArtists' => $totalArtists,
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    /**
     * Show artist details
     */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        session_start_safe();
        if (!$this->isAuthenticated()) {
            return redirect('/admin/login');
        }

        $artistId = (int) ($args['id'] ?? 0);
        if ($artistId <= 0) {
            return redirect('/admin/artists');
        }

        $artist = $this->artistRepository->findById($artistId);
        if (!$artist) {
            return redirect('/admin/artists');
        }

        $stats = $this->artistRepository->getArtistStats($artistId, 50);

        $html = $this->views->render('admin/artists/show', [
            'artist' => $artist,
            'stats' => $stats,
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    /**
     * Show artist statistics
     */
    public function statistics(ServerRequestInterface $request): ResponseInterface
    {
        session_start_safe();
        if (!$this->isAuthenticated()) {
            return redirect('/admin/login');
        }

        $query = $request->getQueryParams();
        
        $filters = [
            'search' => trim((string) ($query['search'] ?? '')),
            'from_date' => trim((string) ($query['from_date'] ?? '')),
            'to_date' => trim((string) ($query['to_date'] ?? '')),
            'limit' => (int) ($query['limit'] ?? 25),
            'page' => max(1, (int) ($query['page'] ?? 1)),
            'sort' => trim((string) ($query['sort'] ?? 'appearance_count')),
            'order' => trim((string) ($query['order'] ?? 'desc')),
        ];

        if (!in_array($filters['limit'], [25, 50, 100], true)) {
            $filters['limit'] = 25;
        }

        $offset = ($filters['page'] - 1) * $filters['limit'];
        
        $stats = $this->artistRepository->getArtistAppearanceStats(
            $filters, 
            $filters['limit'], 
            $offset
        );
        
        $totalStats = $this->artistRepository->countArtistAppearanceStats($filters);
        $totalPages = (int) ceil($totalStats / $filters['limit']);

        $html = $this->views->render('admin/artists/statistics', [
            'stats' => $stats,
            'filters' => $filters,
            'currentPage' => $filters['page'],
            'totalPages' => $totalPages,
            'totalStats' => $totalStats,
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    /**
     * Get artist image - serves the actual image file
     */
    public function getImage(ServerRequestInterface $request, array $args): ResponseInterface
    {
        session_start_safe();
        if (!$this->isAuthenticated()) {
            return new Response(401, ['Content-Type' => 'application/json'], json_encode(['error' => 'Unauthorized']));
        }

        $artistId = (int) ($args['id'] ?? 0);
        if ($artistId <= 0) {
            return new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => 'Invalid artist ID']));
        }

        $artist = $this->artistRepository->findById($artistId);
        if (!$artist) {
            return new Response(404, ['Content-Type' => 'application/json'], json_encode(['error' => 'Artist not found']));
        }

        $imagePath = $this->lastFmService->getCachedImagePath($artist);

        if ($imagePath === null) {
            return new Response(404, ['Content-Type' => 'application/json'], json_encode(['error' => 'Image not found']));
        }

        $imageData = file_get_contents($imagePath);
        $mimeType = 'image/jpeg';
        
        return new Response(200, [
            'Content-Type' => $mimeType,
            'Content-Length' => strlen($imageData),
            'Cache-Control' => 'public, max-age=86400',
        ], $imageData);
    }

    /**
     * Save artist image from URL
     */
    public function saveImage(ServerRequestInterface $request, array $args): ResponseInterface
    {
        session_start_safe();
        if (!$this->isAuthenticated()) {
            return new Response(401, ['Content-Type' => 'application/json'], json_encode(['error' => 'Unauthorized']));
        }

        $artistId = (int) ($args['id'] ?? 0);
        if ($artistId <= 0) {
            return new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => 'Invalid artist ID']));
        }

        $artist = $this->artistRepository->findById($artistId);
        if (!$artist) {
            return new Response(404, ['Content-Type' => 'application/json'], json_encode(['error' => 'Artist not found']));
        }

        $body = $request->getParsedBody();
        
        if (empty($body) && $request->getHeaderLine('Content-Type') === 'application/json') {
            $body = json_decode((string) $request->getBody(), true) ?? [];
        }
        
        $imageUrl = trim((string) ($body['imageUrl'] ?? ''));
        if (empty($imageUrl)) {
            return new Response(400, ['Content-Type' => 'application/json'], json_encode([
                'success' => false,
                'message' => 'Image URL is required'
            ]));
        }

        $success = $this->lastFmService->downloadArtistImageFromUrl($artistId, $imageUrl);
        
        if ($success) {
            return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'message' => 'Image saved successfully'
            ]));
        }

        return new Response(500, ['Content-Type' => 'application/json'], json_encode([
            'success' => false,
            'message' => 'Failed to download image from URL'
        ]));
    }

    /**
     * Force re-download of artist image from the default sources
     */
    public function regenerateImage(ServerRequestInterface $request, array $args): ResponseInterface
    {
        session_start_safe();
        if (!$this->isAuthenticated()) {
            return new Response(401, ['Content-Type' => 'application/json'], json_encode(['error' => 'Unauthorized']));
        }

        $artistId = (int) ($args['id'] ?? 0);
        if ($artistId <= 0) {
            return new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => 'Invalid artist ID']));
        }

        $artist = $this->artistRepository->findById($artistId);
        if (!$artist) {
            return new Response(404, ['Content-Type' => 'application/json'], json_encode(['error' => 'Artist not found']));
        }

        $success = $this->lastFmService->regenerateArtistImage($artistId);

        if ($success) {
            return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'message' => 'Image regenerated successfully'
            ]));
        }

        return new Response(500, ['Content-Type' => 'application/json'], json_encode([
            'success' => false,
            'message' => 'Failed to regenerate image from default sources'
        ]));
    }

    private function isAuthenticated(): bool
    {
        return session_get('admin_authenticated') === true;
    }
}