<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureManager
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isManager()) {
            return response()->json([
                'code' => 'forbidden',
                'message' => 'Manager role required.',
            ], 403);
        }

        return $next($request);
    }
}
