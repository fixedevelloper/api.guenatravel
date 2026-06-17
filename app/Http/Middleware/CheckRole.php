<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param string ...$roles
     * @return Response
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // 1. Vérifier si l'utilisateur est authentifié
        if (!$request->user()) {
            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        }

        // 2. Vérifier si le rôle de l'utilisateur fait partie des rôles requis
        // (En supposant que votre table 'users' possède une colonne 'role')
        if (!in_array($request->user()->role, $roles)) {
            return response()->json([
                'message' => 'Forbidden. You do not have the required role to access this resource.',
                'required_roles' => $roles,
                'your_role' => $request->user()->role
            ], 403);
        }

        return $next($request);
    }
}
