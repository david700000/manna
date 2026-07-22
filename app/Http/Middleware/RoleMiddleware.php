<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        if (!$request->user() || !in_array($request->user()->role, $roles)) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        // Allow password change and backup/restore endpoints even with temp password
        $allowedPaths = ['auth/change-password', 'root/backup/export', 'root/backup/import'];
        $isAllowed = collect($allowedPaths)->some(fn($path) => str_contains($request->path(), $path));

        if ($request->user()->must_change_password && !$isAllowed) {
            return response()->json(['message' => 'You must change your temporary password before accessing these endpoints.'], 403);
        }

        return $next($request);
    }
}
