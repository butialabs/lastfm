<?php $this->layout('admin/layout') ?>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <?php if ($artist['image_hash']) { ?>
                    <div class="artist-image">
                        <a href="/admin/artists/<?= (int) $artist['id'] ?>/image" target="_blank"><img
                                src="/admin/artists/<?= (int) $artist['id'] ?>/image" class="img-fluid rounded"></a>
                    </div>
                    <code class="d-block p-2 text-center"><?= htmlspecialchars($artist['image_hash'], ENT_QUOTES) ?></code>
                <?php } ?>
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-primary fetch-image" data-artist-id="<?= (int) $artist['id'] ?>">
                        <i class="bi bi-image"></i> <?= htmlspecialchars(__('admin.artists.change_image'), ENT_QUOTES) ?>
                    </button>
                    <button type="button" class="btn btn-outline-primary regenerate-image" data-artist-id="<?= (int) $artist['id'] ?>">
                        <i class="bi bi-arrow-clockwise"></i> <?= htmlspecialchars(__('admin.artists.force_download'), ENT_QUOTES) ?>
                    </button>
                </div>

                <h5 class="card-title mt-2"><?= htmlspecialchars($artist['name'], ENT_QUOTES) ?></h5>

                <div class="mb-2">
                    <strong
                        class="d-block"><?= htmlspecialchars(__('admin.artists.lastfm_url'), ENT_QUOTES) ?>:</strong>
                    <div class="d-flex gap-1">
                    <a href="<?= htmlspecialchars($artist['lastfm_url'], ENT_QUOTES) ?>" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener"
                        class="d-block text-truncate">
                        <i class="bi bi-box-arrow-up-right"></i>
                    </a>
                    <a href="<?= htmlspecialchars($artist['lastfm_url'], ENT_QUOTES) ?>/+images/" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener"
                        class="d-block text-truncate">
                        <i class="bi bi-image"></i>
                    </a>
                    </div>

                </div>

                <div class="mb-2">
                    <strong
                        class="d-block"><?= htmlspecialchars(__('admin.artists.musicbrainz_url'), ENT_QUOTES) ?>:</strong>
                    <?php if (!empty($artist['musicbrainz_id'])): ?>
                        <a href="https://musicbrainz.org/artist/<?= htmlspecialchars($artist['musicbrainz_id'], ENT_QUOTES) ?>"
                            class="btn btn-outline-secondary btn-sm"  target="_blank" rel="noopener">
                            <i class="bi bi-box-arrow-up-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="text-muted"><?= htmlspecialchars(__('admin.field.no_value'), ENT_QUOTES) ?></span>
                    <?php endif; ?>
                </div>

                <div>
                    <strong><?= htmlspecialchars(__('admin.created'), ENT_QUOTES) ?>:</strong>
                    <span><?= htmlspecialchars($artist['created_at'], ENT_QUOTES) ?></span>
                </div>

                <div>
                    <strong><?= htmlspecialchars(__('admin.updated'), ENT_QUOTES) ?>:</strong>
                    <span><?= htmlspecialchars($artist['updated_at'], ENT_QUOTES) ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><?= htmlspecialchars(__('admin.artists.recent_stats'), ENT_QUOTES) ?></h5>
            </div>
            <div class="card-body overflow-auto p-0">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th><?= htmlspecialchars(__('admin.artists.stats.date'), ENT_QUOTES) ?></th>
                            <th><?= htmlspecialchars(__('admin.artists.stats.user'), ENT_QUOTES) ?></th>
                            <th><?= htmlspecialchars(__('admin.artists.stats.position'), ENT_QUOTES) ?></th>
                            <th><?= htmlspecialchars(__('admin.artists.stats.plays'), ENT_QUOTES) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stats)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    <?= htmlspecialchars(__('admin.artists.no_stats'), ENT_QUOTES) ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($stats as $stat): ?>
                                <tr>
                                    <td><?= htmlspecialchars($stat['recorded_at'], ENT_QUOTES) ?></td>
                                    <td>
                                        <?php if (!empty($stat['lastfm_username'])): ?>
                                            <a href="https://last.fm/user/<?= htmlspecialchars($stat['lastfm_username'], ENT_QUOTES) ?>"
                                                target="_blank" rel="noopener">
                                                <?= htmlspecialchars($stat['username'], ENT_QUOTES) ?>
                                            </a>
                                        <?php else: ?>
                                            <?= htmlspecialchars($stat['username'], ENT_QUOTES) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $stat['position'] <= 3 ? 'success' : 'secondary' ?>">
                                            #<?= (int) $stat['position'] ?>
                                        </span>
                                    </td>
                                    <td><?= (int) $stat['play_count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="fetchImageModal" tabindex="-1" aria-labelledby="fetchImageModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="fetchImageModalLabel">
                    <?= htmlspecialchars(__('admin.artists.artist_image'), ENT_QUOTES) ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                    aria-label="<?= htmlspecialchars(__('admin.modal.close'), ENT_QUOTES) ?>"></button>
            </div>
            <div class="modal-body">
                <p><?= htmlspecialchars(__('admin.artists.change_image_text'), ENT_QUOTES) ?></p>
                <form id="fetchImageForm">
                    <input type="hidden" id="artistId" name="artistId" value="<?= (int) $artist['id'] ?>">
                    <div class="mb-3">
                        <label for="imageUrl"
                            class="form-label"><?= htmlspecialchars(__('admin.artists.image_url'), ENT_QUOTES) ?></label>
                        <input type="url" class="form-control" id="imageUrl" name="imageUrl"
                            placeholder="https://example.com/image.jpg">
                    </div>
                </form>
                <div id="fetchResult" class="alert d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary"
                    data-bs-dismiss="modal"><?= htmlspecialchars(__('admin.modal.close'), ENT_QUOTES) ?></button>
                <button type="button" class="btn btn-primary"
                    id="fetchButton"><?= htmlspecialchars(__('admin.artists.save_button'), ENT_QUOTES) ?></button>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const modal = new bootstrap.Modal(document.getElementById('fetchImageModal'));
        const fetchForm = document.getElementById('fetchImageForm');
        const artistIdInput = document.getElementById('artistId');
        const imageUrlInput = document.getElementById('imageUrl');
        const resultDiv = document.getElementById('fetchResult');
        const fetchImageButtons = document.querySelectorAll('.fetch-image');
        
        fetchImageButtons.forEach(button => {
            button.addEventListener('click', function() {
                modal.show();
            });
        });

        const regenerateButtons = document.querySelectorAll('.regenerate-image');
        regenerateButtons.forEach(button => {
            button.addEventListener('click', function() {
                const artistId = button.dataset.artistId;
                const originalHtml = button.innerHTML;
                button.disabled = true;
                button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';

                fetch(`/admin/artists/${artistId}/regenerate-image`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    if (ok && data.success) {
                        window.location.reload();
                    } else {
                        alert('<?= htmlspecialchars(__('admin.artists.regenerate_error'), ENT_QUOTES) ?>: ' + (data.message || ''));
                        button.disabled = false;
                        button.innerHTML = originalHtml;
                    }
                })
                .catch(error => {
                    alert('<?= htmlspecialchars(__('admin.artists.regenerate_error'), ENT_QUOTES) ?>: ' + error.message);
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                });
            });
        });

        const fetchButton = document.getElementById('fetchButton');
        fetchButton.addEventListener('click', function() {
            const artistId = artistIdInput.value;
            const imageUrl = imageUrlInput.value;
            
            if (!imageUrl) {
                resultDiv.classList.remove('d-none', 'alert-success');
                resultDiv.classList.add('alert-danger', 'd-block');
                resultDiv.textContent = '<?= htmlspecialchars(__('admin.artists.image_url_required'), ENT_QUOTES) ?>';
                return;
            }
            
            fetchButton.disabled = true;
            fetchButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
            
            fetch(`/admin/artists/${artistId}/image`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ imageUrl: imageUrl })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP error ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    resultDiv.classList.remove('d-none', 'alert-danger');
                    resultDiv.classList.add('alert-success', 'd-block');
                    resultDiv.textContent = '<?= htmlspecialchars(__('admin.artists.image_saved'), ENT_QUOTES) ?>';
                    
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    throw new Error(data.message || 'Unknown error');
                }
            })
            .catch(error => {
                resultDiv.classList.remove('d-none', 'alert-success');
                resultDiv.classList.add('alert-danger', 'd-block');
                resultDiv.textContent = '<?= htmlspecialchars(__('admin.artists.save_error'), ENT_QUOTES) ?>: ' + error.message;
            })
            .finally(() => {
                fetchButton.disabled = false;
                fetchButton.innerHTML = '<?= htmlspecialchars(__('admin.artists.save_button'), ENT_QUOTES) ?>';
            });
        });
    });
</script>