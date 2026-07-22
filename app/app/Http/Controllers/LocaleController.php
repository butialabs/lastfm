<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

class LocaleController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', 'in:'.implode(',', (array) config('lastfm.locales', ['en']))],
        ]);

        $locale = $validated['locale'];

        // Same cookie contract as the legacy app: 1 year, Path=/, SameSite=Lax.
        Cookie::queue(Cookie::make('locale', $locale, 60 * 24 * 365, '/', null, null, false, false, 'Lax'));

        $user = Auth::user();
        if ($user !== null) {
            $user->forceFill(['language' => $locale])->save();
        }

        return redirect()->back();
    }
}
