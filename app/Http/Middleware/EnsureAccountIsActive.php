<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of a member who has been suspended since they signed
 * in, so a suspension takes effect on their next request rather than when
 * their session happens to expire.
 */
final class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isSuspended()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // In the URL rather than flashed: a Livewire request's fetch
            // follows this redirect (using up a flash) before the browser
            // loads the login page itself.
            return redirect()->route('login', ['suspended' => 1]);
        }

        return $next($request);
    }
}
