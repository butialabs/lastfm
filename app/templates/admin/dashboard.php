<?php $this->layout('admin/layout') ?>

<div class="container py-4">
	<div class="d-flex justify-content-between align-items-center mb-4">
		<h1 class="h4">
			<span class="text-muted">d-_-b</span> <?= htmlspecialchars(__('admin.dashboard'), ENT_QUOTES) ?>
		</h1>
		<form method="post" action="/admin/logout">
			<button type="submit" class="btn btn-outline-secondary btn-sm"><?= htmlspecialchars(__('admin.logout'), ENT_QUOTES) ?></button>
		</form>
	</div>

	<div class="row mb-4">
		<div class="col-md-3">
			<div class="card bg-primary text-white">
				<div class="card-body d-flex align-items-center">
					<i class="bi bi-people-fill" style="font-size: 2.5rem; opacity: 0.7;"></i>
					<div class="ms-3">
						<h5 class="card-title mb-0"><?= htmlspecialchars(__('admin.total_users'), ENT_QUOTES) ?></h5>
						<p class="card-text display-6 mb-0"><?= $totalUsers ?? 0 ?></p>
					</div>
				</div>
			</div>
		</div>
		<div class="col-md-3">
			<div class="card bg-success text-white">
				<div class="card-body d-flex align-items-center">
					<i class="bi bi-check-circle-fill" style="font-size: 2.5rem; opacity: 0.7;"></i>
					<div class="ms-3">
						<h5 class="card-title mb-0"><?= htmlspecialchars(__('admin.active'), ENT_QUOTES) ?></h5>
						<p class="card-text display-6 mb-0"><?= $activeUsers ?? 0 ?></p>
					</div>
				</div>
			</div>
		</div>
		<div class="col-md-3">
			<div class="card bg-info text-white">
				<div class="card-body d-flex align-items-center">
					<i class="bi bi-cloud-fill" style="font-size: 2.5rem; opacity: 0.7;"></i>
					<div class="ms-3">
						<h5 class="card-title mb-0"><?= htmlspecialchars(__('admin.bluesky'), ENT_QUOTES) ?></h5>
						<p class="card-text display-6 mb-0"><?= $blueskyUsers ?? 0 ?></p>
					</div>
				</div>
			</div>
		</div>
		<div class="col-md-3">
			<div class="card bg-warning text-dark">
				<div class="card-body d-flex align-items-center">
					<i class="bi bi-mastodon" style="font-size: 2.5rem; opacity: 0.7;"></i>
					<div class="ms-3">
						<h5 class="card-title mb-0"><?= htmlspecialchars(__('admin.mastodon'), ENT_QUOTES) ?></h5>
						<p class="card-text display-6 mb-0"><?= $mastodonUsers ?? 0 ?></p>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="card mb-4">
		<div class="card-body">
			<form method="get" action="/admin" class="row g-3">
				<div class="col-md-2">
					<label for="filter_protocol" class="form-label"><?= htmlspecialchars(__('admin.filter.protocol'), ENT_QUOTES) ?></label>
					<select class="form-select" id="filter_protocol" name="protocol">
						<option value=""><?= htmlspecialchars(__('admin.filter.all'), ENT_QUOTES) ?></option>
						<option value="at" <?= ($filters['protocol'] ?? '') === 'at' ? 'selected' : '' ?>><?= htmlspecialchars(__('admin.bluesky'), ENT_QUOTES) ?></option>
						<option value="mastodon" <?= ($filters['protocol'] ?? '') === 'mastodon' ? 'selected' : '' ?>><?= htmlspecialchars(__('admin.mastodon'), ENT_QUOTES) ?></option>
					</select>
				</div>
				<div class="col-md-2">
					<label for="filter_status" class="form-label"><?= htmlspecialchars(__('admin.filter.status'), ENT_QUOTES) ?></label>
					<select class="form-select" id="filter_status" name="status">
						<option value=""><?= htmlspecialchars(__('admin.filter.all'), ENT_QUOTES) ?></option>
						<option value="ACTIVE" <?= ($filters['status'] ?? '') === 'ACTIVE' ? 'selected' : '' ?>><?= htmlspecialchars(__('admin.status.active'), ENT_QUOTES) ?></option>
						<option value="SCHEDULE" <?= ($filters['status'] ?? '') === 'SCHEDULE' ? 'selected' : '' ?>><?= htmlspecialchars(__('admin.status.schedule'), ENT_QUOTES) ?></option>
						<option value="QUEUED" <?= ($filters['status'] ?? '') === 'QUEUED' ? 'selected' : '' ?>><?= htmlspecialchars(__('admin.status.queued'), ENT_QUOTES) ?></option>
						<option value="SENDING" <?= ($filters['status'] ?? '') === 'SENDING' ? 'selected' : '' ?>><?= htmlspecialchars(__('admin.status.sending'), ENT_QUOTES) ?></option>
						<option value="ERROR" <?= ($filters['status'] ?? '') === 'ERROR' ? 'selected' : '' ?>><?= htmlspecialchars(__('admin.status.error'), ENT_QUOTES) ?></option>
					</select>
				</div>
				<div class="col-md-3">
					<label for="filter_search" class="form-label"><?= htmlspecialchars(__('admin.filter.search'), ENT_QUOTES) ?></label>
					<input type="text" class="form-control" id="filter_search" name="search" value="<?= htmlspecialchars($filters['search'] ?? '', ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars(__('admin.filter.search_placeholder'), ENT_QUOTES) ?>">
				</div>
				<div class="col-md-2">
					<label for="filter_language" class="form-label"><?= htmlspecialchars(__('admin.filter.language'), ENT_QUOTES) ?></label>
					<select class="form-select" id="filter_language" name="language">
						<option value=""><?= htmlspecialchars(__('admin.filter.all'), ENT_QUOTES) ?></option>
						<option value="en" <?= ($filters['language'] ?? '') === 'en' ? 'selected' : '' ?>><?= htmlspecialchars(__('admin.language.english'), ENT_QUOTES) ?></option>
						<option value="pt-BR" <?= ($filters['language'] ?? '') === 'pt-BR' ? 'selected' : '' ?>><?= htmlspecialchars(__('admin.language.portuguese'), ENT_QUOTES) ?></option>
					</select>
				</div>
				<div class="col-md-1">
					<label for="filter_limit" class="form-label"><?= htmlspecialchars(__('admin.filter.per_page'), ENT_QUOTES) ?></label>
					<select class="form-select" id="filter_limit" name="limit">
						<option value="25" <?= ($filters['limit'] ?? 25) == 25 ? 'selected' : '' ?>>25</option>
						<option value="50" <?= ($filters['limit'] ?? 25) == 50 ? 'selected' : '' ?>>50</option>
						<option value="100" <?= ($filters['limit'] ?? 25) == 100 ? 'selected' : '' ?>>100</option>
					</select>
				</div>
				<div class="col-md-2 d-flex align-items-end gap-2">
					<button type="submit" class="btn btn-primary"><?= htmlspecialchars(__('admin.filter.button'), ENT_QUOTES) ?></button>
					<a href="/admin" class="btn btn-outline-secondary"><?= htmlspecialchars(__('admin.filter.clear'), ENT_QUOTES) ?></a>
				</div>
			</form>
		</div>
	</div>

	<div class="card">
		<div class="card-body p-0">
			<table class="table table-striped table-hover mb-0">
				<thead class="table-dark">
					<tr>
						<th><?= htmlspecialchars(__('admin.table.id'), ENT_QUOTES) ?></th>
						<th class="text-center"><i class="bi bi-globe"></i></th>
						<th><?= htmlspecialchars(__('admin.table.username'), ENT_QUOTES) ?></th>
						<th><?= htmlspecialchars(__('admin.table.lastfm'), ENT_QUOTES) ?></th>
						<th><?= htmlspecialchars(__('admin.table.language'), ENT_QUOTES) ?></th>
						<th><?= htmlspecialchars(__('admin.table.status'), ENT_QUOTES) ?></th>
						<th><?= htmlspecialchars(__('admin.table.schedule'), ENT_QUOTES) ?></th>
						<th><?= htmlspecialchars(__('admin.table.timezone'), ENT_QUOTES) ?></th>
						<th><?= htmlspecialchars(__('admin.table.errors'), ENT_QUOTES) ?></th>
						<th><?= htmlspecialchars(__('admin.table.dates'), ENT_QUOTES) ?></th>
						<th class="text-center"><?= htmlspecialchars(__('admin.table.actions'), ENT_QUOTES) ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($users)): ?>
						<tr>
							<td colspan="11" class="text-center text-muted py-4"><?= htmlspecialchars(__('admin.table.no_users'), ENT_QUOTES) ?></td>
						</tr>
					<?php else: ?>
						<?php foreach ($users as $user): ?>
							<tr>
								<td><?= (int) $user['id'] ?></td>
								<td class="text-center">
									<?php
									$instanceUrl = htmlspecialchars($user['instance'] ?? '', ENT_QUOTES);
									$iconClass = $user['protocol'] === 'at' ? 'bi-cloud-fill text-info' : 'bi-mastodon text-warning';
									$protocolTitle = $user['protocol'] === 'at' ? 'Bluesky' : 'Mastodon';
									?>
									<a href="<?= $instanceUrl ?>" target="_blank" rel="noopener" title="<?= $protocolTitle ?>: <?= htmlspecialchars($user['instance'] ?? '', ENT_QUOTES) ?>">
										<i class="bi <?= $iconClass ?>" style="font-size: 1.2rem;"></i>
									</a>
								</td>
								<td class="text-truncate" style="max-width: 150px;" title="<?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES) ?>">
									<?php
									$profileUrl = null;
									if ($user['protocol'] === 'at') {
										$handle = !empty($user['did']) ? $user['did'] : $user['username'];
										$profileUrl = 'https://bsky.app/profile/' . $handle;
									} elseif ($user['protocol'] === 'mastodon') {
										$profileUrl = 'https://' . $user['instance'] . '/@' . $user['username'];
									}
									?>
									<?php if ($profileUrl): ?>
										<a href="<?= htmlspecialchars($profileUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener" title="<?= htmlspecialchars(__('admin.table.view_profile'), ENT_QUOTES) ?>">
											<?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES) ?>
										</a>
									<?php else: ?>
										<?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES) ?>
									<?php endif; ?>
								</td>
								<td>
									<?php if (!empty($user['lastfm_username'])): ?>
										<a href="https://last.fm/user/<?= htmlspecialchars($user['lastfm_username'], ENT_QUOTES) ?>" target="_blank" rel="noopener">
											<?= htmlspecialchars($user['lastfm_username'], ENT_QUOTES) ?>
										</a>
									<?php else: ?>
										<span class="text-muted">-</span>
									<?php endif; ?>
								</td>
								<td><?= htmlspecialchars($user['language'] ?? 'en', ENT_QUOTES) ?></td>
								<td>
									<?php
									$statusClass = match ($user['status'] ?? '') {
										'ACTIVE' => 'bg-secondary',
										'SCHEDULE' => 'bg-success',
										'QUEUED' => 'bg-primary',
										'SENDING' => 'bg-info',
										'ERROR' => 'bg-danger',
										default => 'bg-secondary',
									};
									?>
									<span class="badge <?= $statusClass ?>"><?= htmlspecialchars($user['status'] ?? '', ENT_QUOTES) ?></span>
								</td>
								<td>
									<?php if (!empty($user['day_of_week']) && !empty($user['time'])): ?>
										<?php
										$days = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
										$day = $days[(int) $user['day_of_week']] ?? '';
										?>
										<?= $day ?> <?= substr($user['time'], 0, 5) ?> UTC
									<?php else: ?>
										<span class="text-muted">-</span>
									<?php endif; ?>
								</td>
								<td class="text-truncate" style="max-width: 100px;" title="<?= htmlspecialchars($user['timezone'] ?? '', ENT_QUOTES) ?>">
									<?= htmlspecialchars($user['timezone'] ?? '-', ENT_QUOTES) ?>
								</td>
								<td>
									<?php if ((int) $user['error_count'] > 0): ?>
										<span class="badge bg-danger" title="<?= htmlspecialchars($user['callback'] ?? '', ENT_QUOTES) ?>">
											<?= (int) $user['error_count'] ?>
										</span>
									<?php else: ?>
										<span class="text-muted">0</span>
									<?php endif; ?>
								</td>
								<td>
									<?= htmlspecialchars(substr($user['created_at'] ?? '', 0, 10), ENT_QUOTES) ?> / <?= htmlspecialchars(substr($user['updated_at'] ?? '', 0, 10), ENT_QUOTES) ?>
								</td>
								<td class="text-center">
									<button type="button" class="btn btn-sm btn-outline-primary view-user-btn" data-user-id="<?= (int) $user['id'] ?>" title="<?= htmlspecialchars(__('admin.table.view_user'), ENT_QUOTES) ?>">
										<i class="bi bi-eye"></i>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<?php if ($totalPages > 1): ?>
			<nav aria-label="Users pagination" class="pb-2 pt-3">
				<ul class="pagination justify-content-center m-0">
					<?php
					$buildUrl = function ($page) use ($filters) {
						$params = $filters;
						$params['page'] = $page;
						return '/admin?' . http_build_query($params);
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
							<a class="page-link" href="<?= $buildUrl($i) ?>"><?= $i ?></a>
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
				<?= htmlspecialchars(__('admin.pagination.page_of', [$currentPage, $totalPages, $totalFiltered]), ENT_QUOTES) ?>
			</p>
		<?php endif; ?>
	</div>
</div>

<div class="modal fade" id="userDetailsModal" tabindex="-1" aria-labelledby="userDetailsModalLabel" aria-hidden="true">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="userDetailsModalLabel"><?= htmlspecialchars(__('admin.modal.user_details'), ENT_QUOTES) ?></h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(__('admin.modal.close'), ENT_QUOTES) ?>"></button>
			</div>
			<div class="modal-body" id="userDetailsModalBody">
				<div class="text-center py-4">
					<div class="spinner-border text-primary" role="status">
						<span class="visually-hidden"><?= htmlspecialchars(__('admin.modal.loading'), ENT_QUOTES) ?></span>
					</div>
					<p class="mt-2"><?= htmlspecialchars(__('admin.modal.loading'), ENT_QUOTES) ?></p>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= htmlspecialchars(__('admin.modal.close'), ENT_QUOTES) ?></button>
			</div>
		</div>
	</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
	const fieldLabels = {
		'id': <?= json_encode(__('admin.field.id')) ?>,
		'protocol': <?= json_encode(__('admin.field.protocol')) ?>,
		'instance': <?= json_encode(__('admin.field.instance')) ?>,
		'username': <?= json_encode(__('admin.field.username')) ?>,
		'did': <?= json_encode(__('admin.field.did')) ?>,
		'lastfm_username': <?= json_encode(__('admin.field.lastfm_username')) ?>,
		'day_of_week': <?= json_encode(__('admin.field.day_of_week')) ?>,
		'time': <?= json_encode(__('admin.field.time')) ?>,
		'timezone': <?= json_encode(__('admin.field.timezone')) ?>,
		'language': <?= json_encode(__('admin.field.language')) ?>,
		'status': <?= json_encode(__('admin.field.status')) ?>,
		'callback': <?= json_encode(__('admin.field.callback')) ?>,
		'social_message': <?= json_encode(__('admin.field.social_message')) ?>,
		'social_montage': <?= json_encode(__('admin.field.social_montage')) ?>,
		'error_count': <?= json_encode(__('admin.field.error_count')) ?>,
		'created_at': <?= json_encode(__('admin.field.created_at')) ?>,
		'updated_at': <?= json_encode(__('admin.field.updated_at')) ?>
	};
	const noValue = <?= json_encode(__('admin.field.no_value')) ?>;
	const errorLoading = <?= json_encode(__('admin.modal.error_loading')) ?>;
	const loadingHtml = <?= json_encode('<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">' . __('admin.modal.loading') . '</span></div><p class="mt-2">' . __('admin.modal.loading') . '</p></div>') ?>;

	const dayNames = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

	const displayOrder = [
		'id', 'protocol', 'instance', 'username', 'did', 'lastfm_username',
		'day_of_week', 'time', 'timezone', 'language', 'status',
		'callback', 'social_message', 'social_montage', 'error_count',
		'created_at', 'updated_at'
	];

	function formatValue(key, value) {
		if (value === null || value === '' || value === undefined) {
			return '<span class="text-muted">' + noValue + '</span>';
		}
		if (key === 'day_of_week') {
			return dayNames[parseInt(value)] || value;
		}
		if (key === 'social_montage' && value) {
			return '<a href="' + value + '" target="_blank">' + value + '</a>';
		}
		if (key === 'status') {
			const statusClasses = {
				'ACTIVE': 'bg-secondary',
				'SCHEDULE': 'bg-success',
				'QUEUED': 'bg-primary',
				'SENDING': 'bg-info',
				'ERROR': 'bg-danger'
			};
			const cls = statusClasses[value] || 'bg-secondary';
			return '<span class="badge ' + cls + '">' + value + '</span>';
		}
		if (key === 'callback' && value) {
			return '<code class="text-wrap" style="word-break: break-all;">' + escapeHtml(value) + '</code>';
		}
		return escapeHtml(String(value));
	}

	function escapeHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	function renderUserDetails(user) {
		let html = '<table class="table table-bordered mb-0">';
		html += '<tbody>';
		
		displayOrder.forEach(function(key) {
			if (user.hasOwnProperty(key)) {
				const label = fieldLabels[key] || key;
				const value = formatValue(key, user[key]);
				html += '<tr><th style="width: 200px;" class="bg-light">' + escapeHtml(label) + '</th><td>' + value + '</td></tr>';
			}
		});

		html += '</tbody></table>';
		return html;
	}

	document.querySelectorAll('.view-user-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			const userId = this.getAttribute('data-user-id');
			const modalBody = document.getElementById('userDetailsModalBody');
			const modal = new bootstrap.Modal(document.getElementById('userDetailsModal'));
			
			modalBody.innerHTML = loadingHtml;
			modal.show();

			fetch('/admin/user/' + userId)
				.then(function(response) {
					if (!response.ok) {
						throw new Error('HTTP ' + response.status);
					}
					return response.json();
				})
				.then(function(user) {
					modalBody.innerHTML = renderUserDetails(user);
				})
				.catch(function(error) {
					modalBody.innerHTML = '<div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle me-2"></i>' + errorLoading + ': ' + escapeHtml(error.message) + '</div>';
				});
		});
	});
});
</script>