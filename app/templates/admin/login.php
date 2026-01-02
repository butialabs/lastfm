<?php $this->layout('admin/layout') ?>

<div class="container">
	<div class="row justify-content-center align-items-center min-vh-100">
		<div class="col-md-4">
			<div class="card shadow">
				<div class="card-body p-4">
					<h1 class="h4 text-center mb-4">
						<span class="text-muted">d-_-b</span>
					</h1>
					
					<?php if (!empty($error)): ?>
					<div class="alert alert-danger" role="alert">
						<?= htmlspecialchars($error, ENT_QUOTES) ?>
					</div>
					<?php endif; ?>
					
					<form method="post" action="/admin/login">
						<div class="mb-3">
							<label for="username" class="form-label"><?= htmlspecialchars(__('admin.username'), ENT_QUOTES) ?></label>
							<input type="text" class="form-control" id="username" name="username" required autofocus>
						</div>
						<div class="mb-3">
							<label for="password" class="form-label"><?= htmlspecialchars(__('admin.password'), ENT_QUOTES) ?></label>
							<input type="password" class="form-control" id="password" name="password" required>
						</div>
						<div class="d-grid">
							<button type="submit" class="btn btn-primary"><?= htmlspecialchars(__('admin.login'), ENT_QUOTES) ?></button>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>
</div>
