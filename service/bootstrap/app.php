<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        \NewSolari\Core\CoreServiceProvider::class,
        ...(class_exists(\NewSolari\Events\EventsServiceProvider::class) ? [\NewSolari\Events\EventsServiceProvider::class] : []),
        \NewSolari\EventPlanning\EventPlanningServiceProvider::class,
    ])
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Content filtering (append = runs last)
        $middleware->append([
            \NewSolari\Core\Security\NaughtyWordsFilter::class,
        ]);
        // CORS must be global — module routes aren't in the api middleware group
        $middleware->prepend([
            \NewSolari\Core\Security\CorsMiddleware::class,
            \NewSolari\Core\Security\SecurityMiddleware::class,
            \NewSolari\Core\Security\MaintenanceMode::class,
            \NewSolari\Core\Security\RequestIdMiddleware::class,
            \NewSolari\Core\Security\RateLimitingMiddleware::class,
            \NewSolari\Core\Security\SecurityHeadersMiddleware::class,
            \NewSolari\Core\Security\VerifyCsrfToken::class,
            \NewSolari\Core\Security\TestAuthenticationMiddleware::class,
        ]);

        $middleware->alias([
            'auth.api' => \NewSolari\Core\Security\AuthenticationMiddleware::class,
            'module.enabled' => \NewSolari\Core\Module\Middleware\EnsureModuleEnabled::class,
            'partition.app' => \NewSolari\Core\Module\Middleware\CheckPartitionAppEnabled::class,
            'permission' => \NewSolari\Core\Security\CheckPermission::class,
            'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
            'idempotent' => \NewSolari\Core\Security\IdempotencyMiddleware::class,
            'test.auth' => \NewSolari\Core\Security\TestAuthenticationMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
