<?php $this->layout('admin/layout') ?>

    <div class="card mb-4">
        <div class="card-body">
            <form method="get" action="/admin/artists/statistics" class="row g-3">
                <div class="col-md-3">
                    <label for="filter_search" class="form-label"><?= htmlspecialchars(__('admin.filter.search'), ENT_QUOTES) ?></label>
                    <input type="text" class="form-control" id="filter_search" name="search" value="<?= htmlspecialchars($filters['search'] ?? '', ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars(__('admin.artists.search_placeholder'), ENT_QUOTES) ?>">
                </div>
                <div class="col-md-2">
                    <label for="filter_from_date" class="form-label"><?= htmlspecialchars(__('admin.filter.from_date'), ENT_QUOTES) ?></label>
                    <input type="date" class="form-control" id="filter_from_date" name="from_date" value="<?= htmlspecialchars($filters['from_date'] ?? '', ENT_QUOTES) ?>">
                </div>
                <div class="col-md-2">
                    <label for="filter_to_date" class="form-label"><?= htmlspecialchars(__('admin.filter.to_date'), ENT_QUOTES) ?></label>
                    <input type="date" class="form-control" id="filter_to_date" name="to_date" value="<?= htmlspecialchars($filters['to_date'] ?? '', ENT_QUOTES) ?>">
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
                    <a href="/admin/artists/statistics" class="btn btn-outline-secondary"><?= htmlspecialchars(__('admin.filter.clear'), ENT_QUOTES) ?></a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th><?= htmlspecialchars(__('admin.artists.table.name'), ENT_QUOTES) ?></th>
                        <th class="text-center"><?= htmlspecialchars(__('admin.artists.stats.appearances'), ENT_QUOTES) ?></th>
                        <th class="text-center"><?= htmlspecialchars(__('admin.artists.stats.avg_position'), ENT_QUOTES) ?></th>
                        <th class="text-center"><?= htmlspecialchars(__('admin.artists.stats.total_plays'), ENT_QUOTES) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stats)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4"><?= htmlspecialchars(__('admin.artists.no_stats'), ENT_QUOTES) ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($stats as $stat): ?>
                            <tr>
                                <td>
                                    <a href="/admin/artists/<?= (int) $stat['id'] ?>">
                                        <?= htmlspecialchars($stat['name'], ENT_QUOTES) ?>
                                    </a>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-primary rounded-pill">
                                        <?= (int) $stat['appearance_count'] ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php 
                                    $avgPosition = round((float) $stat['average_position'], 1);
                                    $positionClass = $avgPosition <= 2 ? 'success' : ($avgPosition <= 3.5 ? 'info' : 'secondary');
                                    ?>
                                    <span class="badge bg-<?= $positionClass ?> rounded-pill">
                                        <?= number_format($avgPosition, 1) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?= number_format((int) $stat['total_plays']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!empty($stats) && $totalPages > 1): ?>
        <div class="d-flex justify-content-between align-items-center mt-4">
            <p class="text-muted mb-0">
                <?= sprintf(
                    htmlspecialchars(__('admin.pagination.page_of'), ENT_QUOTES),
                    $currentPage,
                    $totalPages,
                    $totalStats
                ) ?>
            </p>
            <nav aria-label="<?= htmlspecialchars(__('admin.pagination.navigation'), ENT_QUOTES) ?>">
                <ul class="pagination mb-0">
                    <?php if ($currentPage > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $currentPage - 1 ?>&limit=<?= $filters['limit'] ?>&search=<?= urlencode($filters['search'] ?? '') ?>&from_date=<?= urlencode($filters['from_date'] ?? '') ?>&to_date=<?= urlencode($filters['to_date'] ?? '') ?>">
                                &laquo; <?= htmlspecialchars(__('admin.pagination.previous'), ENT_QUOTES) ?>
                            </a>
                        </li>
                    <?php endif; ?>
                    
                    <?php if ($currentPage < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $currentPage + 1 ?>&limit=<?= $filters['limit'] ?>&search=<?= urlencode($filters['search'] ?? '') ?>&from_date=<?= urlencode($filters['from_date'] ?? '') ?>&to_date=<?= urlencode($filters['to_date'] ?? '') ?>">
                                <?= htmlspecialchars(__('admin.pagination.next'), ENT_QUOTES) ?> &raquo;
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>