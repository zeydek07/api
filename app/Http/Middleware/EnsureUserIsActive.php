<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pasife alınan personelin elindeki token anında geçersiz olur.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            $user?->currentAccessToken()?->delete();

            return response()->json(['message' => 'Hesabınız pasif. Yöneticinizle iletişime geçin.'], 403);
        }

        return $next($request);
    }
}
