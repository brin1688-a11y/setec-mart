<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep staff out of the shopping flow.
 *
 * An admin account is for running the shop, not buying from it: an order
 * placed on one would move real stock, land in the shop's own takings and
 * muddle the figures it exists to report. Browsing the storefront stays open,
 * so staff can still see the shop the way a customer does.
 */
class DenyShoppingToStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isAdmin()) {
            $message = 'Admin accounts cannot place orders. Use a customer account to shop.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 403);
            }

            return redirect()->route('admin.dashboard')->with('error', $message);
        }

        return $next($request);
    }
}
