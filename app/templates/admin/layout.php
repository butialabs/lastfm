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
	<link href="//cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
	<link href="//cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
	<script src="//cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js"></script>
	<link rel="shortcut icon" href="/dist/images/meta/favicon.ico">
</head>

<body>
	<?= $this->section('content') ?>
</body>

</html>