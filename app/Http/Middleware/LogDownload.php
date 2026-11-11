<?php

namespace App\Http\Middleware;

use App\Audit\Audit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Adds every PDF / Excel / receipt download to the audit trail. */
class LogDownload
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->isSuccessful()) {
            $what = Str::headline(Str::after((string) $request->route()?->getName(), 'exports.'));
            Audit::record('downloaded', 'Downloaded '.Str::lower($what).' ('.basename($request->path()).')');
        }

        return $response;
    }
}
