<?php

namespace App\Http\Middleware;

use Closure;

class BasicAuth
{
    public function handle($request, Closure $next)
    {
        $user = $request->getUser();
        $pass = $request->getPassword();

        if ($user !== 'admin' || $pass !== 'secret') {
            return response('Unauthorized', 401, ['WWW-Authenticate' => 'Basic']);
        }

        return $next($request);
    }
}

