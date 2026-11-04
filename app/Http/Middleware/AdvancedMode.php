<?php

namespace App\Http\Middleware;

use App\Support\Sales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** v2 sales screens exist only in Advanced mode (Settings → Mode). In Simple mode they 404 like they aren't there. */
class AdvancedMode
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Sales::advanced(), 404);

        return $next($request);
    }
}
