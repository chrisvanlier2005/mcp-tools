<?php

namespace Lier\McpTools\Tests;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Config\Repository;
use Lier\McpTools\McpToolsServiceProvider;
use Lier\McpTools\Tests\Fixtures\WidgetController;
use Orchestra\Testbench\TestCase as Orchestra;
use Override;

abstract class TestCase extends Orchestra
{
    /**
     * @param \Illuminate\Foundation\Application $app
     * @return list<class-string<\Illuminate\Support\ServiceProvider>>
     */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [McpToolsServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    #[Override]
    protected function defineEnvironment($app): void
    {
        $app->make(Repository::class)->set('mcp-tools.openapi_path', __DIR__ . '/Fixtures/openapi.json');
    }

    /**
     * @param \Illuminate\Routing\Router $router
     * @return void
     */
    #[Override]
    protected function defineRoutes($router): void
    {
        $router->get('widgets', [WidgetController::class, 'index']);
        $router->post('widgets', [WidgetController::class, 'store']);
        $router->get('widgets/{widget}', [WidgetController::class, 'show']);
        $router->delete('widgets/purge', fn () => 'purged');
        $router->delete('widgets/{widget}', [WidgetController::class, 'destroy']);
        $router->get('me', [WidgetController::class, 'me'])->middleware(Authenticate::class);
        $router->get('marker', [WidgetController::class, 'marker']);
        $router->get('plain', [WidgetController::class, 'plain']);
        $router->get('closure', fn () => 'OK');
    }
}
