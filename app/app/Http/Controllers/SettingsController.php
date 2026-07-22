<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return redirect('/');
        }

        // Reset error count and re-enable ERROR users (legacy behavior).
        $user->resetOnAccess();

        // The settings page forces the user's preferred language.
        $locale = (string) ($user->language ?? 'en');
        if (! in_array($locale, (array) config('lastfm.locales', ['en']), true)) {
            $locale = 'en';
        }

        app()->setLocale($locale);
        Cookie::queue(Cookie::make('locale', $locale, 60 * 24 * 365, '/', null, null, false, false, 'Lax'));

        return view('pages.settings', ['user' => $user->fresh()]);
    }
}
