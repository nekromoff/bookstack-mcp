<?php

use BookStack\Facades\Theme;
use BookStack\Http\Middleware\CheckEmailConfirmed;
use BookStack\Http\Middleware\EncryptCookies;
use BookStack\Http\Middleware\StartSessionExtended;
use BookStack\Http\Middleware\VerifyCsrfToken;
use BookStack\Theming\ThemeEvents;
use BookStackMcp\Commands\InstallCommand;
use BookStackMcp\Commands\PruneCommand;
use BookStackMcp\Controllers\ConnectionsController;
use BookStackMcp\Controllers\McpController;
use BookStackMcp\Controllers\OAuthController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Routing\Router;
use Illuminate\View\Middleware\ShareErrorsFromSession;

spl_autoload_register(function (string $class) {
    $prefix = 'BookStackMcp\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

// Machine-to-machine endpoints. These authenticate via bearer tokens or PKCE,
// never via cookies, so session & CSRF handling is stripped from them.
Theme::listen(ThemeEvents::ROUTES_REGISTER_WEB, function (Router $router) {
    $stateless = [
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSessionExtended::class,
        ShareErrorsFromSession::class,
        VerifyCsrfToken::class,
        CheckEmailConfirmed::class,
    ];

    $router->group(['excluded_middleware' => $stateless], function (Router $router) {
        $router->get('/.well-known/oauth-protected-resource', [OAuthController::class, 'protectedResourceMetadata']);
        $router->get('/.well-known/oauth-protected-resource/mcp', [OAuthController::class, 'protectedResourceMetadata']);
        $router->get('/.well-known/oauth-authorization-server', [OAuthController::class, 'authorizationServerMetadata']);
        $router->get('/.well-known/openid-configuration', [OAuthController::class, 'authorizationServerMetadata']);

        $router->post('/mcp/oauth/register', [OAuthController::class, 'register']);
        $router->post('/mcp/oauth/token', [OAuthController::class, 'token']);
        $router->post('/mcp/oauth/revoke', [OAuthController::class, 'revoke']);

        $router->post('/mcp', [McpController::class, 'handle']);
        $router->match(['GET', 'DELETE'], '/mcp', [McpController::class, 'methodNotAllowed']);
    });
});

// Browser endpoints. These use the normal BookStack login (any configured
// auth method, including MFA) and are CSRF protected.
Theme::listen(ThemeEvents::ROUTES_REGISTER_WEB_AUTH, function (Router $router) {
    $router->get('/mcp/oauth/authorize', [OAuthController::class, 'showAuthorize']);
    $router->post('/mcp/oauth/authorize', [OAuthController::class, 'submitAuthorize']);

    $router->get('/mcp/connections', [ConnectionsController::class, 'index']);
    $router->delete('/mcp/connections/{clientId}', [ConnectionsController::class, 'revoke']);
});

Theme::registerCommand(new InstallCommand());
Theme::registerCommand(new PruneCommand());
