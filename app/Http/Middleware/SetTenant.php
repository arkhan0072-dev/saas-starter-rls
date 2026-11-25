<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Tenancy\Tenancy;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Scopes the request to the signed-in user's tenant and clears it afterwards. Runs after "auth". */
class SetTenant
{
    public function __construct(private Tenancy $tenancy) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Fail closed if a route uses this middleware without "auth".
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $this->tenancy->run($user->tenant_id, fn () => $next($request));
    }
}
