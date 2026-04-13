<?php $this->layout('admin/layout') ?>

    <div class="card mb-4">
        <div class="card-body">
            <form method="get" action="/admin/artists" class="row g-3">
                <div class="col-md-6">
                    <label for="filter_search" class="form-label"><?= htmlspecialchars(__('admin.filter.search'), ENT_QUOTES) ?></label>
                    <input type="text" class="form-control" id="filter_search" name="search" value="<?= htmlspecialchars($filters['search'] ?? '', ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars(__('admin.artists.search_placeholder'), ENT_QUOTES) ?>">
                </div>
                <div class="col-md-2">
                    <label for="filter_limit" class="form-label"><?= htmlspecialchars(__('admin.filter.per_page'), ENT_QUOTES) ?></label>
                    <select class="form-select" id="filter_limit" name="limit">
                        <option value="25" <?= ($filters['limit'] ?? 25) == 25 ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= ($filters['limit'] ?? 25) == 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= ($filters['limit'] ?? 25) == 100 ? 'selected' : '' ?>>100</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary"><?= htmlspecialchars(__('admin.filter.button'), ENT_QUOTES) ?></button>
                    <a href="/admin/artists" class="btn btn-outline-secondary"><?= htmlspecialchars(__('admin.filter.clear'), ENT_QUOTES) ?></a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th><?= htmlspecialchars(__('admin.artists.table.image'), ENT_QUOTES) ?></th>
                        <th><?= htmlspecialchars(__('admin.artists.table.name'), ENT_QUOTES) ?></th>
                        <th><?= htmlspecialchars(__('admin.artists.table.lastfm'), ENT_QUOTES) ?></th>
                        <th><?= htmlspecialchars(__('admin.artists.table.musicbrainz'), ENT_QUOTES) ?></th>
                        <th><?= htmlspecialchars(__('admin.table.dates'), ENT_QUOTES) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($artists)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4"><?= htmlspecialchars(__('admin.artists.no_artists'), ENT_QUOTES) ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($artists as $artist): ?>
                            <tr>
                                <td>
                                    <div class="artist-image">
                                        <a href="/admin/artists/<?= (int) $artist['id'] ?>/image" target="_blank"><img src="/admin/artists/<?= (int) $artist['id'] ?>/image" class="img-fluid rounded" width="50"></a>
                                    </div>
                                </td>
                                <td>
                                    <a href="/admin/artists/<?= (int) $artist['id'] ?>"><?= htmlspecialchars($artist['name'], ENT_QUOTES) ?></a> (<?= (int) $artist['id'] ?>)
                                </td>
                                <td>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($artist['lastfm_url'], ENT_QUOTES) ?>" target="_blank" rel="noopener">
                                        <i class="bi bi-box-arrow-up-right"></i>
                                    </a>
                                </td>
                                <td>
                                    <?php if (!empty($artist['musicbrainz_id'])): ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="https://musicbrainz.org/artist/<?= htmlspecialchars($artist['musicbrainz_id'], ENT_QUOTES) ?>" target="_blank" rel="noopener">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?= htmlspecialchars(__('admin.created'), ENT_QUOTES) ?>: <?= htmlspecialchars($artist['created_at'], ENT_QUOTES) ?><br>
                                        <?= htmlspecialchars(__('admin.updated'), ENT_QUOTES) ?>: <?= htmlspecialchars($artist['updated_at'], ENT_QUOTES) ?>
                                    </small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!empty($artists) && $totalPages > 1): ?>
        <div class="d-flex justify-content-between align-items-center mt-4">
            <p class="text-muted mb-0">
                <?= sprintf(
                    htmlspecialchars(__('admin.pagination.page_of'), ENT_QUOTES),
                    $currentPage,
                    $totalPages,
                    $totalArtists
                ) ?>
            </p>
            <nav aria-label="<?= htmlspecialchars(__('admin.pagination.navigation'), ENT_QUOTES) ?>">
                <ul class="pagination mb-0">
                    <?php if ($currentPage > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $currentPage - 1 ?>&limit=<?= $filters['limit'] ?>&search=<?= urlencode($filters['search'] ?? '') ?>">
                                &laquo; <?= htmlspecialchars(__('admin.pagination.previous'), ENT_QUOTES) ?>
                            </a>
                        </li>
                    <?php endif; ?>
                    
                    <?php if ($currentPage < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $currentPage + 1 ?>&limit=<?= $filters['limit'] ?>&search=<?= urlencode($filters['search'] ?? '') ?>">
                                <?= htmlspecialchars(__('admin.pagination.next'), ENT_QUOTES) ?> &raquo;
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>