<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $password = (string) config('ironforge.admin_password');
        if ($password === '') {
            return $next($request);
        }

        if ($request->session()->get('ironforge.admin') === true) {
            return $next($request);
        }

        return redirect()->guest(route('admin.login'));
    }
}
