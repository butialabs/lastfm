<?php $this->layout('admin/layout') ?>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <div class="artist-image">
                        <a href="/admin/artists/<?= (int) $artist['id'] ?>/image" target="_blank"><img src="/admin/artists/<?= (int) $artist['id'] ?>/image" class="img-fluid rounded"></a>
                    </div>
                    <code class="d-block p-2 text-center"><?= htmlspecialchars($artist['image_hash'], ENT_QUOTES) ?></code>
                    <button type="button" class="btn btn-primary regenerate-image" data-artist-id="<?= (int) $artist['id'] ?>">
                        <i class="bi bi-image"></i> <?= htmlspecialchars(__('admin.artists.regenerate_image'), ENT_QUOTES) ?>
                    </button>

                    <h5 class="card-title mt-2"><?= htmlspecialchars($artist['name'], ENT_QUOTES) ?></h5>
                    
                    <div>
                        <strong class="d-block"><?= htmlspecialchars(__('admin.artists.lastfm_url'), ENT_QUOTES) ?>:</strong>
                        <a href="<?= htmlspecialchars($artist['lastfm_url'], ENT_QUOTES) ?>" target="_blank" rel="noopener" class="d-block text-truncate">
                            <?= htmlspecialchars($artist['lastfm_url'], ENT_QUOTES) ?>
                        </a>
                    </div>
                    
                    <div>
                        <strong class="d-block"><?= htmlspecialchars(__('admin.artists.musicbrainz_id'), ENT_QUOTES) ?>:</strong>
                        <?php if (!empty($artist['musicbrainz_id'])): ?>
                            <a href="https://musicbrainz.org/artist/<?= htmlspecialchars($artist['musicbrainz_id'], ENT_QUOTES) ?>" target="_blank" rel="noopener">
                                <?= htmlspecialchars($artist['musicbrainz_id'], ENT_QUOTES) ?>
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
                <div class="card-body p-0">
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
                                    <td colspan="4" class="text-center text-muted py-4"><?= htmlspecialchars(__('admin.artists.no_stats'), ENT_QUOTES) ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($stats as $stat): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($stat['recorded_at'], ENT_QUOTES) ?></td>
                                        <td>
                                            <?php if (!empty($stat['lastfm_username'])): ?>
                                                <a href="https://last.fm/user/<?= htmlspecialchars($stat['lastfm_username'], ENT_QUOTES) ?>" target="_blank" rel="noopener">
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
</div>

<!-- Regenerate Image Modal -->
<div class="modal fade" id="regenerateImageModal" tabindex="-1" aria-labelledby="regenerateImageModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="regenerateImageModalLabel"><?= htmlspecialchars(__('admin.artists.regenerate_image_title'), ENT_QUOTES) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(__('admin.modal.close'), ENT_QUOTES) ?>"></button>
            </div>
            <div class="modal-body">
                <p><?= htmlspecialchars(__('admin.artists.regenerate_image_text'), ENT_QUOTES) ?></p>
                <form id="regenerateImageForm">
                    <input type="hidden" id="artistId" name="artistId" value="<?= (int) $artist['id'] ?>">
                    <div class="mb-3">
                        <label for="imageSource" class="form-label"><?= htmlspecialchars(__('admin.artists.image_source'), ENT_QUOTES) ?></label>
                        <select class="form-select" id="imageSource" name="source">
                            <option value="lastfm"><?= htmlspecialchars(__('admin.artists.source.lastfm'), ENT_QUOTES) ?></option>
                            <option value="archive"><?= htmlspecialchars(__('admin.artists.source.archive'), ENT_QUOTES) ?></option>
                            <option value="musicbrainz"><?= htmlspecialchars(__('admin.artists.source.musicbrainz'), ENT_QUOTES) ?></option>
                        </select>
                    </div>
                </form>
                <div id="regenerateResult" class="alert d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= htmlspecialchars(__('admin.modal.close'), ENT_QUOTES) ?></button>
                <button type="button" class="btn btn-primary" id="regenerateButton"><?= htmlspecialchars(__('admin.artists.regenerate_button'), ENT_QUOTES) ?></button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = new bootstrap.Modal(document.getElementById('regenerateImageModal'));
    const regenerateButtons = document.querySelectorAll('.regenerate-image');
    const regenerateForm = document.getElementById('regenerateImageForm');
    const artistIdInput = document.getElementById('artistId');
    const regenerateButton = document.getElementById('regenerateButton');
    const resultDiv = document.getElementById('regenerateResult');
    
    regenerateButtons.forEach(button => {
        button.addEventListener('click', function() {
            resultDiv.classList.add('d-none');
            modal.show();
        });
    });
    
    regenerateButton.addEventListener('click', function() {
        const artistId = artistIdInput.value;
        const source = document.getElementById('imageSource').value;
        
        regenerateButton.disabled = true;
        regenerateButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <?= htmlspecialchars(__('admin.processing'), ENT_QUOTES) ?>';
        
        fetch(`/admin/artists/${artistId}/regenerate-image`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ source }),
        })
        .then(response => response.json())
        .then(data => {
            regenerateButton.disabled = false;
            regenerateButton.innerHTML = '<?= htmlspecialchars(__('admin.artists.regenerate_button'), ENT_QUOTES) ?>';
            
            resultDiv.classList.remove('d-none', 'alert-success', 'alert-danger');
            
            if (data.success) {
                resultDiv.classList.add('alert-success');
                resultDiv.textContent = '<?= htmlspecialchars(__('admin.artists.regenerate_success'), ENT_QUOTES) ?>';
                
                // Wait 2 seconds and reload the page
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            } else {
                resultDiv.classList.add('alert-danger');
                resultDiv.textContent = data.error || '<?= htmlspecialchars(__('admin.artists.regenerate_error'), ENT_QUOTES) ?>';
            }
        })
        .catch(error => {
            regenerateButton.disabled = false;
            regenerateButton.innerHTML = '<?= htmlspecialchars(__('admin.artists.regenerate_button'), ENT_QUOTES) ?>';
            
            resultDiv.classList.remove('d-none');
            resultDiv.classList.add('alert-danger');
            resultDiv.textContent = '<?= htmlspecialchars(__('admin.artists.regenerate_error'), ENT_QUOTES) ?>';
            console.error('Error:', error);
        });
    });
});
</script>