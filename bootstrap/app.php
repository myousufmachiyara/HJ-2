<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'check.permission' => \App\Http\Middleware\CheckModulePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // "Page Expired" (form token too old): go back to the form with what was typed
        // instead of the blank 419 page; the keep-alive normally prevents this.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e, \Illuminate\Http\Request $request) {
            if ($e->getStatusCode() !== 419) return null;
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Your session was refreshed — please try again.'], 419);
            }
            if (!auth()->check()) {
                return redirect()->route('login')->with('error', 'Please sign in again.');
            }
            return redirect()->back()
                ->withInput($request->except(['_token', 'password', 'password_confirmation']))
                ->with('error', 'The page was open for a long time — please check and click Save again.');
        });
    })->create();
