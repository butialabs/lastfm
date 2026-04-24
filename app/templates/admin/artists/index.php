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

        .artist-card-wrapper {
            position: relative;
        }

        .artist-card-wrapper .bulk-check {
            position: absolute;
            top: 6px;
            left: 10px;
            z-index: 2;
            transform: scale(1.3);
            cursor: pointer;
        }

        .artist-card-wrapper.is-selected .card {
            outline: 2px solid #0d6efd;
        }

        .artist-card-wrapper.is-processing .card {
            opacity: 0.5;
        }

        .artist-card-wrapper.is-success .card {
            outline: 2px solid #198754;
        }

        .artist-card-wrapper.is-failed .card {
            outline: 2px solid #dc3545;
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

    <?php $isNoImageFilter = ($filters['no_image'] ?? '') == '1'; ?>

    <?php if ($isNoImageFilter && !empty($artists)): ?>
        <div class="card mb-4" id="bulkActionBar">
            <div class="card-body d-flex flex-wrap align-items-center gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="bulkSelectAll">
                    <i class="bi bi-check2-square"></i> <?= htmlspecialchars(__('admin.artists.bulk_select_all'), ENT_QUOTES) ?>
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="bulkClear">
                    <i class="bi bi-x-square"></i> <?= htmlspecialchars(__('admin.artists.bulk_clear'), ENT_QUOTES) ?>
                </button>
                <button type="button" class="btn btn-primary btn-sm" id="bulkForceDownload" disabled>
                    <i class="bi bi-arrow-clockwise"></i> <?= htmlspecialchars(__('admin.artists.bulk_force_download'), ENT_QUOTES) ?>
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm d-none" id="bulkCancel">
                    <i class="bi bi-stop-circle"></i> <?= htmlspecialchars(__('admin.artists.bulk_cancel'), ENT_QUOTES) ?>
                </button>
                <span class="ms-auto text-muted small" id="bulkStatus"></span>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <?php if (empty($artists)): ?>
                <div class="text-center text-muted py-4"><?= htmlspecialchars(__('admin.artists.no_artists'), ENT_QUOTES) ?></div>
            <?php else: ?>
                <div class="row row-cols-2 row-cols-md-6 g-4">
                    <?php foreach ($artists as $artist): ?>
                        <div class="col">
                            <div class="artist-card-wrapper" data-artist-id="<?= (int) $artist['id'] ?>">
                                <?php if ($isNoImageFilter): ?>
                                    <input type="checkbox" class="form-check-input bulk-check" data-artist-id="<?= (int) $artist['id'] ?>" aria-label="Select">
                                <?php endif; ?>
                                <div class="card h-100">
                                    <a href="/admin/artists/<?= (int) $artist['id'] ?>" target="_blank" class="card-img-top artist-card-image">
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

<?php if ($isNoImageFilter && !empty($artists)): ?>
<script>
    (function () {
        const selectAllBtn = document.getElementById('bulkSelectAll');
        const clearBtn = document.getElementById('bulkClear');
        const forceBtn = document.getElementById('bulkForceDownload');
        const cancelBtn = document.getElementById('bulkCancel');
        const statusEl = document.getElementById('bulkStatus');
        const checkboxes = Array.from(document.querySelectorAll('.bulk-check'));

        const i18n = {
            selected: <?= json_encode(__('admin.artists.bulk_selected')) ?>,
            progress: <?= json_encode(__('admin.artists.bulk_progress')) ?>,
            done: <?= json_encode(__('admin.artists.bulk_done')) ?>,
            noneSelected: <?= json_encode(__('admin.artists.bulk_none_selected')) ?>,
        };

        const fmt = (tpl, ...args) => {
            let i = 0;
            return tpl.replace(/%d/g, () => args[i++]);
        };

        const wrapperFor = (id) => document.querySelector('.artist-card-wrapper[data-artist-id="' + id + '"]');

        let cancelRequested = false;

        const updateSelectedCount = () => {
            const count = checkboxes.filter(cb => cb.checked).length;
            forceBtn.disabled = count === 0;
            statusEl.textContent = count > 0 ? fmt(i18n.selected, count) : '';
        };

        checkboxes.forEach(cb => {
            cb.addEventListener('change', () => {
                const w = wrapperFor(cb.dataset.artistId);
                if (w) w.classList.toggle('is-selected', cb.checked);
                updateSelectedCount();
            });
        });

        selectAllBtn.addEventListener('click', () => {
            checkboxes.forEach(cb => {
                cb.checked = true;
                const w = wrapperFor(cb.dataset.artistId);
                if (w) w.classList.add('is-selected');
            });
            updateSelectedCount();
        });

        clearBtn.addEventListener('click', () => {
            checkboxes.forEach(cb => {
                cb.checked = false;
                const w = wrapperFor(cb.dataset.artistId);
                if (w) {
                    w.classList.remove('is-selected', 'is-success', 'is-failed', 'is-processing');
                }
            });
            updateSelectedCount();
        });

        const sleep = (ms) => new Promise(r => setTimeout(r, ms));
        const randomDelay = () => 2000 + Math.floor(Math.random() * 3001); // 2000..5000ms

        const regenerate = async (artistId) => {
            const response = await fetch('/admin/artists/' + artistId + '/regenerate-image', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const data = await response.json().catch(() => ({}));
            return response.ok && data && data.success === true;
        };

        const setProcessingUi = (running) => {
            selectAllBtn.disabled = running;
            clearBtn.disabled = running;
            forceBtn.disabled = running;
            cancelBtn.classList.toggle('d-none', !running);
            checkboxes.forEach(cb => { cb.disabled = running; });
        };

        forceBtn.addEventListener('click', async () => {
            const selected = checkboxes.filter(cb => cb.checked).map(cb => cb.dataset.artistId);
            if (selected.length === 0) {
                statusEl.textContent = i18n.noneSelected;
                return;
            }

            cancelRequested = false;
            setProcessingUi(true);

            let success = 0;
            let failed = 0;

            for (let i = 0; i < selected.length; i++) {
                if (cancelRequested) break;

                const artistId = selected[i];
                const wrapper = wrapperFor(artistId);
                if (wrapper) {
                    wrapper.classList.remove('is-success', 'is-failed');
                    wrapper.classList.add('is-processing');
                }
                statusEl.textContent = fmt(i18n.progress, i + 1, selected.length);

                try {
                    const ok = await regenerate(artistId);
                    if (wrapper) {
                        wrapper.classList.remove('is-processing');
                        wrapper.classList.add(ok ? 'is-success' : 'is-failed');
                    }
                    if (ok) {
                        success++;
                        const img = wrapper ? wrapper.querySelector('img') : null;
                        if (img) {
                            const src = img.getAttribute('src').split('?')[0];
                            img.setAttribute('src', src + '?t=' + Date.now());
                        }
                    } else {
                        failed++;
                    }
                } catch (e) {
                    failed++;
                    if (wrapper) {
                        wrapper.classList.remove('is-processing');
                        wrapper.classList.add('is-failed');
                    }
                }

                if (i < selected.length - 1 && !cancelRequested) {
                    await sleep(randomDelay());
                }
            }

            statusEl.textContent = fmt(i18n.done, success, failed);
            setProcessingUi(false);
            forceBtn.disabled = checkboxes.filter(cb => cb.checked).length === 0;
        });

        cancelBtn.addEventListener('click', () => {
            cancelRequested = true;
        });
    })();
</script>
<?php endif; ?>
