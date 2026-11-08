<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Usage: ->middleware('role:cgso')  or  'role:resident,official' */
class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }
        abort_unless($user->isRole(...$roles), 403, 'You are not allowed to access this page.');
        return $next($request);
    }
}
