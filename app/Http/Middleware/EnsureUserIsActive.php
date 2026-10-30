<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends an already open session as soon as the account is deactivated.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->active) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                abort(401);
            }

            return redirect()->route('login')
                ->with('status', 'Учётная запись отключена. Обратитесь к администратору.');
        }

        return $next($request);
    }
}
