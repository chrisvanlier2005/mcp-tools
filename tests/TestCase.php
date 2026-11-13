<?php

namespace Lier\McpTools\Tests;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;
use Lier\McpTools\DispatchToRoute;
use Lier\McpTools\McpToolsServiceProvider;
use Lier\McpTools\Tests\Fixtures\WidgetController;
use Orchestra\Testbench\TestCase as Orchestra;
use Override;
use RuntimeException;

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
        $router->get('facade', fn () => ['marked' => RequestFacade::instance()->attributes->get(DispatchToRoute::REQUEST_ATTRIBUTE)]);
        $router->get('closure', fn () => 'OK');
        $router->get('phases/{project_phase}/tasks/{task_id?}', fn (string $project_phase, ?string $task_id = null) => [
            'phase' => $project_phase,
            'task' => $task_id,
        ]);
        $router->get('optional/{page?}', fn (?string $page = null) => ['page' => $page]);
        $router->get('echo', fn (Request $request) => $request->query());
        $router->post('echo', fn (Request $request) => $request->json()->all());
        $router->get('boom', fn () => throw new RuntimeException('Boom.'))->middleware(Authenticate::class);
    }
}
