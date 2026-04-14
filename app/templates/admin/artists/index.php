<?php $this->layout('admin/layout') ?>


    <div class="card mb-4">
        <div class="card-body">
            <form method="get" action="/admin/artists" class="row g-3">
                <div class="col-md-5">
                    <label for="filter_search" class="form-label"><?= htmlspecialchars(__('admin.filter.search'), ENT_QUOTES) ?></label>
                    <input type="text" class="form-control" id="filter_search" name="search" value="<?= htmlspecialchars($filters['search'] ?? '', ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars(__('admin.artists.search_placeholder'), ENT_QUOTES) ?>">
                </div>
                <div class="col-md-2">
                    <label for="filter_no_image" class="form-label"><?= htmlspecialchars(__('admin.filter.image'), ENT_QUOTES) ?></label>
                    <select class="form-select" id="filter_no_image" name="no_image">
                        <option value=""><?= htmlspecialchars(__('admin.filter.all'), ENT_QUOTES) ?></option>
                        <option value="1" <?= ($filters['no_image'] ?? '') == '1' ? 'selected' : '' ?>><?= htmlspecialchars(__('admin.filter.no_image'), ENT_QUOTES) ?></option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="filter_limit" class="form-label"><?= htmlspecialchars(__('admin.filter.per_page'), ENT_QUOTES) ?></label>
                    <select class="form-select" id="filter_limit" name="limit">
                        <option value="25" <?= ($filters['limit'] ?? 25) == 25 ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= ($filters['limit'] ?? 25) == 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= ($filters['limit'] ?? 25) == 100 ? 'selected' : '' ?>>100</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary"><?= htmlspecialchars(__('admin.filter.button'), ENT_QUOTES) ?></button>
                    <a href="/admin/artists" class="btn btn-outline-secondary"><?= htmlspecialchars(__('admin.filter.clear'), ENT_QUOTES) ?></a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body overflow-auto p-0">
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

    <?php if ($totalPages > 1): ?>
        <nav aria-label="Artists pagination" class="pb-2 pt-3">
            <ul class="pagination justify-content-center m-0">
                <?php
                $buildUrl = function ($page) use ($filters) {
                    $params = $filters;
                    $params['page'] = $page;
                    return '/admin/artists?' . http_build_query($params);
                };
                ?>

                <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $buildUrl(1) ?>" aria-label="First">
                        <span aria-hidden="true">&laquo;&laquo;</span>
                    </a>
                </li>
                <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $buildUrl($currentPage - 1) ?>" aria-label="Previous">
                        <span aria-hidden="true">&laquo;</span>
                    </a>
                </li>

                <?php
                $start = max(1, $currentPage - 2);
                $end = min($totalPages, $currentPage + 2);
                for ($i = $start; $i <= $end; $i++):
                    ?>
                    <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                        <a class="page-link" href="<?= $buildUrl($i) ?>">
                            <?= $i ?>
                        </a>
                    </li>
                <?php endfor; ?>

                <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $buildUrl($currentPage + 1) ?>" aria-label="Next">
                        <span aria-hidden="true">&raquo;</span>
                    </a>
                </li>
                <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $buildUrl($totalPages) ?>" aria-label="Last">
                        <span aria-hidden="true">&raquo;&raquo;</span>
                    </a>
                </li>
            </ul>
        </nav>
        <p class="text-center text-muted small pb-2 m-0">
            <?= htmlspecialchars(__('admin.pagination.page_of', [$currentPage, $totalPages, $totalArtists]), ENT_QUOTES) ?>
        </p>
    <?php endif; ?>
</div>