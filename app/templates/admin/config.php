<?php $this->layout('admin/layout') ?>

<div class="card">
	<div class="card-header">
		<h5 class="mb-0"><i class="bi bi-gear-fill me-2"></i><?= htmlspecialchars(__('admin.config.title'), ENT_QUOTES) ?></h5>
	</div>
	<div class="card-body">
		<?php if ($success): ?>
			<div class="alert alert-success alert-dismissible fade show" role="alert">
				<?= htmlspecialchars(__('admin.config.saved'), ENT_QUOTES) ?>
				<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= htmlspecialchars(__('admin.modal.close'), ENT_QUOTES) ?>"></button>
			</div>
		<?php endif; ?>

		<form method="post" action="/admin/config">
			<?= csrf_field() ?>

			<div class="mb-3">
				<label for="analytics_script" class="form-label fw-bold"><?= htmlspecialchars(__('admin.config.analytics_script'), ENT_QUOTES) ?></label>
				<p class="text-muted small mb-2"><?= __('admin.config.analytics_script_help') ?></p>
				<textarea
					class="form-control font-monospace"
					id="analytics_script"
					name="analytics_script"
					rows="10"
					placeholder="<?= htmlspecialchars(__('admin.config.placeholder'), ENT_QUOTES) ?>"><?= htmlspecialchars($config['analytics_script'] ?? '', ENT_QUOTES) ?></textarea>
			</div>

			<button type="submit" class="btn btn-primary">
				<i class="bi bi-floppy me-1"></i><?= htmlspecialchars(__('admin.config.save'), ENT_QUOTES) ?>
			</button>
		</form>
	</div>
</div>
