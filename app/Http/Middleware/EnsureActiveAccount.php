<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Runs on every signed-in page:
 * 1. A deactivated account is logged out immediately (also when it has an old session).
 * 2. An account with a temporary password must choose a new password first.
 */
class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')->withErrors(['email' => 'This account is deactivated. Please contact the CGSO.']);
        }

        if ($user && $user->must_change_password && ! $request->routeIs('account.*') && ! $request->routeIs('logout')) {
            return redirect()->route('account.edit')->with('success', 'Please choose a new password before you continue.');
        }

        return $next($request);
    }
}
