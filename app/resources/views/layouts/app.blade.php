@php
    $logo = 'd-_-b';
    $title = __('messages.app.title');
    $description = __('messages.app.description');
    $appUrl = rtrim((string) config('app.url'), '/');
    $currentLocale = app()->getLocale();

    $descriptionHtml = str_replace(
        ['Last.fm', 'Bluesky', 'Mastodon'],
        [
            '<a href="https://last.fm" target="_blank" rel="noopener">Last.fm</a>',
            '<a href="https://bsky.app" target="_blank" rel="noopener">Bluesky</a>',
            '<a href="https://joinmastodon.org" target="_blank" rel="noopener">Mastodon</a>',
        ],
        e($description)
    );
@endphp
<!DOCTYPE html>
<html lang="{{ $currentLocale }}">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>@hasSection('page-title')@yield('page-title') · @endif{{ $title }} · LastFM.blue</title>
	<meta name="description" content="{{ $description }}">

	<meta property="og:type" content="website">
	<meta property="og:url" content="{{ $appUrl }}">
	<meta property="og:title" content="{{ $logo }} {{ $title }}">
	<meta property="og:description" content="{{ $description }}">
	<meta property="og:image" content="{{ $appUrl }}/images/meta/ogimage.png">

	<meta property="twitter:card" content="summary_large_image">
	<meta property="twitter:url" content="{{ $appUrl }}">
	<meta property="twitter:title" content="{{ $logo }} {{ $title }}">
	<meta property="twitter:description" content="{{ $description }}">
	<meta property="twitter:image" content="{{ $appUrl }}/images/meta/ogimage.png">

	<link rel="apple-touch-icon" sizes="180x180" href="/images/meta/apple-touch-icon.png">
	<link rel="icon" type="image/png" sizes="32x32" href="/images/meta/favicon-32x32.png">
	<link rel="icon" type="image/png" sizes="16x16" href="/images/meta/favicon-16x16.png">
	<link rel="manifest" href="/images/meta/site.webmanifest">
	<link rel="mask-icon" href="/images/meta/safari-pinned-tab.svg" color="#5bbad5">
	<link rel="shortcut icon" href="/images/meta/favicon.ico">
	<meta name="msapplication-TileColor" content="#94bbe9">
	<meta name="msapplication-config" content="/images/meta/browserconfig.xml">
	<meta name="theme-color" content="#94bbe9">

	<link href="/css/style.css" rel="stylesheet">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300&family=Red+Hat+Display&family=Syne:wght@700&display=swap" rel="stylesheet">
	@livewireStyles
</head>

<body class="@yield('body-class')">
	<div class="middle">
		<div class="container">
			<header>
				<div class="logo" aria-hidden="true">{{ $logo }}</div>
				<h1>{{ $title }}</h1>
				<p>{!! $descriptionHtml !!}</p>
			</header>

			<main>
				@yield('content')
			</main>

			<footer>
				<div>{{ __('messages.footer.made_with_love') }} <a href="https://butialabs.com" target="_blank" rel="noopener"><strong>Butiá Labs</strong></a> · <a href="https://github.com/butialabs/lastfm" target="_blank" rel="noopener"><strong>GitHub</strong></a></div>

				@unless (in_array(trim($__env->yieldContent('body-class')), ['page-login', 'page-settings'], true))
					<div class="users">{{ sprintf(trans_choice('messages.footer.total_users', $totalUsers ?? 0), $totalUsers ?? 0) }}</div>
				@endunless

				<form method="post" action="{{ route('locale') }}" class="locale">
					@csrf
					<label for="locale">{{ __('messages.app.language') }}</label>
					<select id="locale" name="locale" onchange="this.form.submit()">
						<option value="en" @selected($currentLocale === 'en')>English</option>
						<option value="pt-BR" @selected($currentLocale === 'pt-BR')>Português (Brasil)</option>
						<option value="fr-FR" @selected($currentLocale === 'fr-FR')>Français (France)</option>
					</select>
					<noscript><button type="submit">OK</button></noscript>
				</form>
			</footer>
		</div>
	</div>

	@livewireScripts
</body>

</html>
