<?php $this->layout('admin/layout') ?>

    <style>
        .artist-card-image {
            height: 126px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #f8f9fa;
        }
        
        .artist-card-image img {
            object-fit: contain;
            max-width: 100%;
            max-height: 100%;
            transition: transform 0.3s ease;
        }
    </style>

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
        <div class="card-body">
            <?php if (empty($artists)): ?>
                <div class="text-center text-muted py-4"><?= htmlspecialchars(__('admin.artists.no_artists'), ENT_QUOTES) ?></div>
            <?php else: ?>
                <div class="row row-cols-2 row-cols-md-6 g-4">
                    <?php foreach ($artists as $artist): ?>
                        <div class="col">
                            <div class="card h-100">
                                <a href="/admin/artists/<?= (int) $artist['id'] ?>/image" target="_blank" class="card-img-top artist-card-image">
                                    <img src="/admin/artists/<?= (int) $artist['id'] ?>/image" class="img-fluid" alt="<?= htmlspecialchars($artist['name'], ENT_QUOTES) ?>">
                                </a>
                                <div class="card-body">
                                    <h5 class="card-title fs-6 fw-normal mb-0">
                                        <a href="/admin/artists/<?= (int) $artist['id'] ?>" class="text-decoration-none">
                                            <?= htmlspecialchars($artist['name'], ENT_QUOTES) ?>
                                            <span class="text-muted fs-6">(<?= (int) $artist['id'] ?>)</span>
                                        </a>
                                    </h5>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
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
