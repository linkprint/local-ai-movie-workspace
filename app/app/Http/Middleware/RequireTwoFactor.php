<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('movie.require_totp') && $request->user()?->two_factor_confirmed_at === null) {
            return redirect()->route('profile')->with('status', __('ui.errors.totp_before_portal'));
        }

        return $next($request);
    }
}
