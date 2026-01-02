<?php
$logo = 'd-_-b';
$title = htmlspecialchars(__('app.title'), ENT_QUOTES);
$description = htmlspecialchars(__('app.description'), ENT_QUOTES);
$appUrl = htmlspecialchars($_ENV['APP_URL'] ?? '', ENT_QUOTES);
$currentLocale = $_COOKIE['locale'] ?? 'en';

$distPath = dirname(__DIR__) . '/public/dist';
$cssFiles = glob($distPath . '/css/style-*.css');
$jsFiles = glob($distPath . '/js/scripts-*.js');
$styleFile = !empty($cssFiles) ? basename($cssFiles[0]) : 'style.css';
$scriptFile = !empty($jsFiles) ? basename($jsFiles[0]) : 'scripts.js';

$descriptionHtml = str_replace(
	['Last.fm', 'Bluesky', 'Mastodon'],
	[
		'<a href="https://last.fm" target="_blank" rel="noopener">Last.fm</a>',
		'<a href="https://bsky.app" target="_blank" rel="noopener">Bluesky</a>',
		'<a href="https://joinmastodon.org" target="_blank" rel="noopener">Mastodon</a>',
	],
	$description
);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLocale, ENT_QUOTES) ?>">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?= $title ?></title>
	<meta name="title" content="<?= $logo ?> <?= $title ?>">
	<meta name="description" content="<?= $description ?>">

	<meta property="og:type" content="website">
	<meta property="og:url" content="<?= $appUrl ?>">
	<meta property="og:title" content="<?= $logo ?> <?= $title ?>">
	<meta property="og:description" content="<?= $description ?>">
	<meta property="og:image" content="<?= $appUrl ?>/dist/images/meta/ogimage.png">

	<meta property="twitter:card" content="summary_large_image">
	<meta property="twitter:url" content="<?= $appUrl ?>">
	<meta property="twitter:title" content="<?= $logo ?> <?= $title ?>">
	<meta property="twitter:description" content="<?= $description ?>">
	<meta property="twitter:image" content="<?= $appUrl ?>/dist/images/meta/ogimage.png">

	<link rel="apple-touch-icon" sizes="180x180" href="/dist/images/meta/apple-touch-icon.png">
	<link rel="icon" type="image/png" sizes="32x32" href="/dist/images/meta/favicon-32x32.png">
	<link rel="icon" type="image/png" sizes="16x16" href="/dist/images/meta/favicon-16x16.png">
	<link rel="manifest" href="/dist/images/meta/site.webmanifest">
	<link rel="mask-icon" href="/dist/images/meta/safari-pinned-tab.svg" color="#5bbad5">
	<link rel="shortcut icon" href="/dist/images/meta/favicon.ico">
	<meta name="msapplication-TileColor" content="#eaa0a4">
	<meta name="msapplication-config" content="/dist/images/meta/browserconfig.xml">
	<meta name="theme-color" content="#ffffff">

	<link href="/dist/css/<?= $styleFile ?>" rel="stylesheet">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300&family=Red+Hat+Display&family=Syne:wght@700&display=swap" rel="stylesheet">
</head>

<body class="<?= htmlspecialchars($bodyClass ?? '', ENT_QUOTES) ?>">
	<div class="middle">
		<div class="container">
			<header>
				<div class="logo"><?= $logo ?></div>
				<h1><?= $title ?></h1>
				<p><?= $descriptionHtml ?></p>
			</header>

			<main>
				<?= $this->section('content') ?>
			</main>

			<footer>
				<div><?= htmlspecialchars(__('footer.made_with_love'), ENT_QUOTES) ?> <a href="https://butialabs.com" target="_blank" rel="noopener"><strong>Butiá Labs</strong></a> ● <a href="https://github.com/butialabds/lastfm" target="_blank"><strong>Github</strong></a></div>
				
				<div><?= htmlspecialchars(trans_choice('footer.total_users', $totalUsers ?? 0), ENT_QUOTES) ?></div>

				<form method="post" action="/locale">
					<select name="locale" onchange="this.form.submit()" aria-label="<?= htmlspecialchars(__('app.language'), ENT_QUOTES) ?>">
						<option disabled><?= htmlspecialchars(__('app.language'), ENT_QUOTES) ?></option>
						<option value="en" <?= $currentLocale === 'en' ? 'selected' : '' ?>>English</option>
						<option value="pt-BR" <?= $currentLocale === 'pt-BR' ? 'selected' : '' ?>>Português (Brasil)</option>
					</select>
				</form>
			</footer>
		</div>
	</div>

	<script src="/dist/js/<?= $scriptFile ?>"></script>
</body>

</html>