<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $isActive = $user->status === 'active' && $user->employee?->status === 'active';
        if ($isActive) {
            return $next($request);
        }

        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = match ($user->status) {
            'pending' => 'Your account is pending admin approval.',
            'rejected' => 'Your account registration was rejected.',
            'blocked' => 'Your account is blocked. Contact an administrator.',
            default => 'Your account is inactive.',
        };

        return redirect()->route('login')->withErrors(['login' => $message]);
    }
}
