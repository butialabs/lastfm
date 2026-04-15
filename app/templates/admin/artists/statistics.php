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
        <div class="card-body overflow-auto p-0">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <?php
                        $sortLink = function($column, $label) use ($filters) {
                            $currentSort = $filters['sort'] ?? 'appearance_count';
                            $currentOrder = $filters['order'] ?? 'desc';
                            
                            $newOrder = ($currentSort === $column && $currentOrder === 'desc') ? 'asc' : 'desc';
                            $sortParams = array_merge($filters, ['sort' => $column, 'order' => $newOrder, 'page' => 1]);
                            $url = '/admin/artists/statistics?' . http_build_query($sortParams);
                            
                            $sortIcon = '';
                            if ($currentSort === $column) {
                                $sortIcon = $currentOrder === 'asc' ? ' ↑' : ' ↓';
                            }
                            
                            return '<a href="' . $url . '" class="text-white text-decoration-none">' .
                                   htmlspecialchars($label, ENT_QUOTES) . $sortIcon . '</a>';
                        };
                        ?>
                        <th><?= $sortLink('name', __('admin.artists.table.name')) ?></th>
                        <th class="text-center"><?= $sortLink('appearance_count', __('admin.artists.stats.appearances')) ?></th>
                        <th class="text-center"><?= $sortLink('average_position', __('admin.artists.stats.avg_position')) ?></th>
                        <th class="text-center"><?= $sortLink('total_plays', __('admin.artists.stats.total_plays')) ?></th>
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

    <?php if ($totalPages > 1): ?>
        <nav aria-label="Statistics pagination" class="pb-2 pt-3">
            <ul class="pagination justify-content-center m-0">
                <?php
                $buildUrl = function ($page) use ($filters) {
                    $params = $filters;
                    $params['page'] = $page;
                    return '/admin/artists/statistics?' . http_build_query($params);
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
            <?= htmlspecialchars(__('admin.pagination.page_of', [$currentPage, $totalPages, $totalStats]), ENT_QUOTES) ?>
        </p>
    <?php endif; ?>
</div>