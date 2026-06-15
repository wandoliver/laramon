<?php

namespace App\Http\Middleware;

use App\Models\Instance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateInstance
{
    public function handle(Request $request, Closure $next): Response
    {
        $instance = Instance::authenticateToken($request->bearerToken());

        if ($instance === null) {
            // Opaque on purpose: never reveal whether the instance id existed.
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->attributes->set('instance', $instance);

        return $next($request);
    }
}
