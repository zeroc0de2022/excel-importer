<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP Basic auth against a single user from config (no users table needed).
 */
class BasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = (string) config('auth.basic.user');
        $password = (string) config('auth.basic.password');

        // hash_equals() compares in constant time, so response timing doesn't leak the secret
        $valid = $user !== ''
            && hash_equals($user, (string) $request->getUser())
            && hash_equals($password, (string) $request->getPassword());

        if (! $valid) {
            return response('Unauthorized', 401, ['WWW-Authenticate' => 'Basic realm="Excel Importer"']);
        }

        return $next($request);
    }
}
