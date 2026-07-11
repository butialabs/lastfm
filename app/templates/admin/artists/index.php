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
                        <option value="placeholder" <?= ($filters['no_image'] ?? '') == 'placeholder' ? 'selected' : '' ?>><?= htmlspecialchars(__('admin.filter.placeholder'), ENT_QUOTES) ?></option>
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
    <?php $isPlaceholderFilter = ($filters['no_image'] ?? '') == 'placeholder'; ?>
    <?php $showBulkBar = ($isNoImageFilter || $isPlaceholderFilter) && !empty($artists); ?>

    <?php if ($showBulkBar): ?>
        <div class="card mb-4" id="bulkActionBar">
            <div class="card-body d-flex flex-wrap align-items-center gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="bulkSelectAll">
                    <i class="bi bi-check2-square"></i> <?= htmlspecialchars(__('admin.artists.bulk_select_all'), ENT_QUOTES) ?>
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="bulkClear">
                    <i class="bi bi-x-square"></i> <?= htmlspecialchars(__('admin.artists.bulk_clear'), ENT_QUOTES) ?>
                </button>
                <?php if ($isNoImageFilter): ?>
                <button type="button" class="btn btn-primary btn-sm" id="bulkForceDownload" disabled>
                    <i class="bi bi-arrow-clockwise"></i> <?= htmlspecialchars(__('admin.artists.bulk_force_download'), ENT_QUOTES) ?>
                </button>
                <?php endif; ?>
                <button type="button" class="btn btn-outline-success btn-sm" id="bulkFindImages" disabled>
                    <i class="bi bi-cloud-download"></i> <?= htmlspecialchars(__('admin.artists.bulk_find_images'), ENT_QUOTES) ?>
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
                                <?php if ($isNoImageFilter || $isPlaceholderFilter): ?>
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

<?php if ($showBulkBar): ?>
<div class="modal fade" id="findImagesWizard" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="wizardTitle"><?= htmlspecialchars(__('admin.artists.find_providers'), ENT_QUOTES) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="wizardBody" style="min-height:300px;"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="wizardPrev">
                    <i class="bi bi-chevron-left"></i>
                </button>
                <button type="button" class="btn btn-outline-warning btn-sm" id="wizardSkip">
                    <?= htmlspecialchars(__('admin.artists.wizard_skip'), ENT_QUOTES) ?>
                </button>
                <button type="button" class="btn btn-primary d-none" id="wizardNext">Next</button>
                <button type="button" class="btn btn-success d-none" id="wizardClose">
                    <?= htmlspecialchars(__('admin.artists.wizard_close'), ENT_QUOTES) ?>
                </button>
            </div>
        </div>
    </div>
</div>
<script>
    (function () {
        const selectAllBtn = document.getElementById('bulkSelectAll');
        const clearBtn = document.getElementById('bulkClear');
        const forceBtn = document.getElementById('bulkForceDownload');
        const findImagesBtn = document.getElementById('bulkFindImages');
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
            if (forceBtn) forceBtn.disabled = count === 0;
            findImagesBtn.disabled = count === 0;
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
            if (forceBtn) forceBtn.disabled = running;
            findImagesBtn.disabled = running;
            cancelBtn.classList.toggle('d-none', !running);
            checkboxes.forEach(cb => { cb.disabled = running; });
        };

        if (forceBtn) forceBtn.addEventListener('click', async () => {
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
            if (forceBtn) forceBtn.disabled = checkboxes.filter(cb => cb.checked).length === 0;
        });

        cancelBtn.addEventListener('click', () => {
            cancelRequested = true;
        });

        const wizardI18n = {
            fetching: <?= json_encode(__('admin.artists.wizard_fetching')) ?>,
            noSources: <?= json_encode(__('admin.artists.wizard_no_sources')) ?>,
            reviewTitle: <?= json_encode(__('admin.artists.wizard_review')) ?>,
            skip: <?= json_encode(__('admin.artists.wizard_skip')) ?>,
            close: <?= json_encode(__('admin.artists.wizard_close')) ?>,
            summary: <?= json_encode(__('admin.artists.wizard_summary')) ?>,
            downloaded: <?= json_encode(__('admin.artists.wizard_downloaded')) ?>,
            skipped: <?= json_encode(__('admin.artists.wizard_skipped')) ?>,
            noResults: <?= json_encode(__('admin.artists.wizard_no_results')) ?>,
        };

        const wizardEl = document.getElementById('findImagesWizard');
        const wizardModal = wizardEl ? new bootstrap.Modal(wizardEl) : null;
        const wizardBody = document.getElementById('wizardBody');
        const wizardTitle = document.getElementById('wizardTitle');
        const wizardPrev = document.getElementById('wizardPrev');
        const wizardNext = document.getElementById('wizardNext');
        const wizardSkipBtn = document.getElementById('wizardSkip');
        const wizardCloseBtn = document.getElementById('wizardClose');

        let wizardQueue = [];
        let wizardIndex = 0;
        let wizardStats = { downloaded: 0, skipped: 0 };

        const downloadFromUrl = async (artistId, imageUrl) => {
            const response = await fetch('/admin/artists/' + artistId + '/image', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ imageUrl: imageUrl })
            });
            const data = await response.json().catch(() => ({}));
            return response.ok && data && data.success === true;
        };

        const renderWizardArtist = () => {
            if (wizardIndex >= wizardQueue.length) {
                renderWizardSummary();
                return;
            }

            const item = wizardQueue[wizardIndex];
            wizardTitle.textContent = fmt(wizardI18n.reviewTitle, wizardIndex + 1, wizardQueue.length) + ' — ' + item.name;

            wizardPrev.disabled = wizardIndex === 0;
            wizardSkipBtn.classList.remove('d-none');
            wizardNext.classList.add('d-none');

            let html = '<div class="row g-2">';
            item.sources.forEach(function(source, idx) {
                html += '<div class="col-6 col-md-4">';
                html += '  <div class="card h-100 wizard-pick" data-source-idx="' + idx + '" style="cursor:pointer;">';
                html += '    <img src="' + source.url + '" class="card-img-top p-2" style="height:160px;object-fit:contain;" loading="lazy" alt="' + source.source + '">';
                html += '    <div class="card-body p-2 text-center">';
                html += '      <span class="badge bg-secondary">' + source.source + '</span>';
                html += '      <span class="badge bg-info ms-1">' + source.type + '</span>';
                html += '    </div>';
                html += '  </div>';
                html += '</div>';
            });
            html += '</div>';

            wizardBody.innerHTML = html;

            wizardBody.querySelectorAll('.wizard-pick').forEach(card => {
                card.addEventListener('click', async function() {
                    const sourceIdx = parseInt(this.dataset.sourceIdx, 10);
                    const source = item.sources[sourceIdx];
                    card.style.opacity = '0.5';
                    card.style.pointerEvents = 'none';

                    try {
                        const ok = await downloadFromUrl(item.artistId, source.url);
                        if (ok) {
                            wizardStats.downloaded++;
                            const wrapper = wrapperFor(String(item.artistId));
                            if (wrapper) {
                                wrapper.classList.remove('is-processing', 'is-failed');
                                wrapper.classList.add('is-success');
                                const img = wrapper.querySelector('img');
                                if (img) {
                                    const src = img.getAttribute('src').split('?')[0];
                                    img.setAttribute('src', src + '?t=' + Date.now());
                                }
                            }
                            wizardIndex++;
                            renderWizardArtist();
                        } else {
                            card.style.opacity = '1';
                            card.style.pointerEvents = 'auto';
                            alert('Download failed');
                        }
                    } catch(e) {
                        card.style.opacity = '1';
                        card.style.pointerEvents = 'auto';
                        alert(e.message);
                    }
                });
            });
        };

        const renderWizardSummary = () => {
            wizardTitle.textContent = wizardI18n.summary;
            wizardPrev.classList.add('d-none');
            wizardSkipBtn.classList.add('d-none');
            wizardNext.classList.add('d-none');
            wizardCloseBtn.classList.remove('d-none');

            const noResults = wizardStats.downloaded === 0 && wizardStats.skipped === 0;
            wizardBody.innerHTML = '<div class="text-center py-4">' +
                (noResults
                    ? '<p class="text-muted">' + wizardI18n.noResults + '</p>'
                    : '<p><i class="bi bi-check-circle text-success fs-1"></i></p>' +
                      '<p>' + wizardI18n.downloaded + ': <strong>' + wizardStats.downloaded + '</strong></p>' +
                      '<p>' + wizardI18n.skipped + ': <strong>' + wizardStats.skipped + '</strong></p>'
                ) +
                '</div>';
        };

        wizardSkipBtn.addEventListener('click', function() {
            wizardStats.skipped++;
            wizardIndex++;
            renderWizardArtist();
        });

        wizardPrev.addEventListener('click', function() {
            if (wizardIndex > 0) {
                wizardIndex--;
                renderWizardArtist();
            }
        });

        wizardCloseBtn.addEventListener('click', function() {
            wizardModal.hide();
        });

        if (findImagesBtn) {
            findImagesBtn.addEventListener('click', async function() {
                const selected = checkboxes.filter(cb => cb.checked).map(cb => cb.dataset.artistId);
                if (selected.length === 0) {
                    statusEl.textContent = i18n.noneSelected;
                    return;
                }

                findImagesBtn.disabled = true;
                findImagesBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

                const queue = [];
                for (let i = 0; i < selected.length; i++) {
                    const artistId = selected[i];
                    const wrapper = wrapperFor(artistId);
                    if (wrapper) {
                        wrapper.classList.remove('is-success', 'is-failed');
                        wrapper.classList.add('is-processing');
                    }
                    statusEl.textContent = fmt(wizardI18n.fetching, i + 1, selected.length);

                    try {
                        const response = await fetch('/admin/artists/' + artistId + '/image-sources', {
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        });
                        const data = await response.json();
                        if (data.success && data.sources && data.sources.length > 0) {
                            queue.push({
                                artistId: parseInt(artistId, 10),
                                name: data.artist ? data.artist.name : ('#' + artistId),
                                sources: data.sources
                            });
                        }
                    } catch(e) { /* skip */ }

                    if (wrapper) {
                        wrapper.classList.remove('is-processing');
                    }
                }

                findImagesBtn.innerHTML = '<i class="bi bi-cloud-download"></i> ' + <?= json_encode(__('admin.artists.bulk_find_images')) ?>;
                findImagesBtn.disabled = false;

                if (queue.length === 0) {
                    statusEl.textContent = wizardI18n.noSources;
                    checkboxes.forEach(cb => {
                        const w = wrapperFor(cb.dataset.artistId);
                        if (w) w.classList.remove('is-processing');
                    });
                    return;
                }

                wizardQueue = queue;
                wizardIndex = 0;
                wizardStats = { downloaded: 0, skipped: 0 };
                wizardPrev.classList.remove('d-none');
                wizardSkipBtn.classList.remove('d-none');
                wizardCloseBtn.classList.add('d-none');
                wizardNext.classList.add('d-none');
                wizardModal.show();
                renderWizardArtist();
            });
        }
    })();
</script>
<?php endif; ?>
