<?php
$current_locale = $_COOKIE['locale'] ?? 'en';
$title = htmlspecialchars(__('app.title'), ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($current_locale, ENT_QUOTES) ?>">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?= $title ?></title>
	<link href="//cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
	<link href="//cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
	<script src="//cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
	<script src="//cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js"></script>
	<link rel="shortcut icon" href="/dist/images/meta/favicon.ico">
	<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
</head>

<body>
	<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
		<div class="container">
			<a class="navbar-brand" href="/admin">
				<span>d-_-b</span> <?= htmlspecialchars(__('admin.dashboard'), ENT_QUOTES) ?>
			</a>
			<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar"
				aria-controls="adminNavbar" aria-expanded="false" aria-label="Toggle navigation">
				<span class="navbar-toggler-icon"></span>
			</button>
			<div class="collapse navbar-collapse" id="adminNavbar">
				<ul class="navbar-nav me-auto mb-2 mb-lg-0">
					<li class="nav-item">
						<a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/admin') === 0 && strpos($_SERVER['REQUEST_URI'], '/admin/artists') === false ? 'active' : '' ?>"
							href="/admin">
							<i class="bi bi-speedometer2"></i>
							<?= htmlspecialchars(__('admin.dashboard'), ENT_QUOTES) ?>
						</a>
					</li>
					<li class="nav-item">
						<a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/admin/artists') === 0 && strpos($_SERVER['REQUEST_URI'], '/admin/artists/statistics') === false ? 'active' : '' ?>"
							href="/admin/artists">
							<i class="bi bi-music-note-list"></i>
							<?= htmlspecialchars(__('admin.artists.title'), ENT_QUOTES) ?>
						</a>
					</li>
					<li class="nav-item">
						<a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/admin/artists/statistics') === 0 ? 'active' : '' ?>"
							href="/admin/artists/statistics">
							<i class="bi bi-bar-chart-fill"></i>
							<?= htmlspecialchars(__('admin.artists.statistics_title'), ENT_QUOTES) ?>
						</a>
					</li>
					<li class="nav-item">
						<a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/admin/config') === 0 ? 'active' : '' ?>"
							href="/admin/config">
							<i class="bi bi-gear-fill"></i>
							<?= htmlspecialchars(__('admin.config.title'), ENT_QUOTES) ?>
						</a>
					</li>
				</ul>
				<form method="post" action="/admin/logout" class="d-flex">
					<?= csrf_field() ?>
					<button type="submit"
						class="btn btn-outline-light btn-sm"><?= htmlspecialchars(__('admin.logout'), ENT_QUOTES) ?></button>
				</form>
			</div>
		</div>
	</nav>

	<div class="container py-4">
		<?= $this->section('content') ?>
	</div>
</body>

</html>