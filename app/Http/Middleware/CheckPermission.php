<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kullanım: ->middleware('permission:products.view')
 * Birden fazla yetkiden biri yeterliyse: 'permission:movements.view_team|movements.view_all'
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();

        foreach (explode('|', $permissions) as $permission) {
            if ($user?->hasPermission($permission)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Bu işlem için yetkiniz yok.',
            'required_permission' => $permissions,
        ], 403);
    }
}
