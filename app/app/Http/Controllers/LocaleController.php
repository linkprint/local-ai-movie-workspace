<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(SetLocale::SUPPORTED)],
        ]);
        $locale = $validated['locale'];
        $request->session()->put('locale', $locale);
        app()->setLocale($locale);

        return back()->withCookie(cookie(
            name: 'locale',
            value: $locale,
            minutes: 60 * 24 * 365,
            secure: true,
            httpOnly: true,
            sameSite: 'lax',
        ));
    }
}
