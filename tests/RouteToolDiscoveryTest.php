<?php

namespace Lier\McpTools\Tests;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Lier\McpTools\Exceptions\InvalidApiDocumentationException;
use Lier\McpTools\Exceptions\McpToolCacheException;
use Lier\McpTools\RouteTool;
use Lier\McpTools\RouteToolCache;
use Lier\McpTools\RouteToolDiscovery;
use Lier\McpTools\Tests\Fixtures\RestrictedRouteTool;
use Lier\McpTools\Tests\Fixtures\WidgetController;

final class RouteToolDiscoveryTest extends TestCase
{
    private const string DOCUMENT = __DIR__ . '/Fixtures/openapi.json';

    public function testItMakesToolsForAnnotatedControllerActionsOnly(): void
    {
        Route::get('undocumented', [WidgetController::class, 'undocumented']);

        Exceptions::fake();

        $discovery = new RouteToolDiscovery(
            app(Router::class),
            new RouteToolCache(sys_get_temp_dir() . '/missing-mcp-tools.php'),
            self::DOCUMENT,
        );

        $names = array_map(fn (RouteTool $tool) => $tool->name(), $discovery->tools());

        // Neither the closure route, the action without the attribute, nor the undocumented route becomes a tool.
        $this->assertSame(['get-widgets', 'create-widget', 'show-widget', 'delete-widget', 'get-me'], $names);

        Exceptions::assertReported(InvalidApiDocumentationException::class);
    }

    public function testItMakesToolsOfTheGivenClass(): void
    {
        $discovery = new RouteToolDiscovery(
            app(Router::class),
            new RouteToolCache(sys_get_temp_dir() . '/missing-mcp-tools.php'),
            self::DOCUMENT,
            toolClass: RestrictedRouteTool::class,
        );

        $this->assertContainsOnlyInstancesOf(RestrictedRouteTool::class, $discovery->tools());
    }

    public function testItCachesTheToolDefinitions(): void
    {
        $path = sys_get_temp_dir() . '/mcp-tools-' . bin2hex(random_bytes(4)) . '.php';
        $cache = new RouteToolCache($path);
        $discovery = new RouteToolDiscovery(app(Router::class), $cache, self::DOCUMENT);

        try {
            $live = array_map(fn (RouteTool $tool) => $tool->toArray(), $discovery->tools());

            $this->assertSame(5, $discovery->cache());
            $this->assertFileExists($path);

            $cached = array_map(fn (RouteTool $tool) => $tool->toArray(), $discovery->tools());

            $this->assertSame(json_encode($live), json_encode($cached));
        } finally {
            $cache->clear();
        }

        $this->assertFileDoesNotExist($path);
    }

    public function testItSkipsCachedToolsThatNoLongerMatchTheirRoute(): void
    {
        $path = sys_get_temp_dir() . '/mcp-tools-' . bin2hex(random_bytes(4)) . '.php';

        file_put_contents($path, '<?php return ' . var_export([[
            'action' => WidgetController::class . '@index',
            'uri' => 'old-widgets',
            'method' => 'GET',
            'attribute' => ['name' => 'get-widgets'],
            'title' => 'List widgets',
            'description' => 'Retrieve the widgets.',
            'inputSchema' => ['type' => 'object'],
            'outputSchema' => null,
        ],
        ], true) . ';');

        Exceptions::fake();

        try {
            $discovery = new RouteToolDiscovery(app(Router::class), new RouteToolCache($path), self::DOCUMENT);

            $this->assertSame([[], []], [$discovery->tools(), $discovery->tools()]);
        } finally {
            unlink($path);
        }

        Exceptions::assertReportedCount(2);
        Exceptions::assertReported(McpToolCacheException::class);
    }

    public function testItFindsCachedToolsOfActionsOnSeveralRoutes(): void
    {
        // getByAction() returns the last route registered for the action, not the one the tool was cached for.
        Route::get('widgets-copy', [WidgetController::class, 'index']);

        $path = sys_get_temp_dir() . '/mcp-tools-' . bin2hex(random_bytes(4)) . '.php';

        file_put_contents($path, '<?php return ' . var_export([[
            'action' => WidgetController::class . '@index',
            'uri' => 'widgets',
            'method' => 'GET',
            'attribute' => ['name' => 'get-widgets'],
            'title' => 'List widgets',
            'description' => 'Retrieve the widgets.',
            'inputSchema' => ['type' => 'object'],
            'outputSchema' => null,
        ],
        ], true) . ';');

        Exceptions::fake();

        try {
            $tools = new RouteToolDiscovery(app(Router::class), new RouteToolCache($path), self::DOCUMENT)->tools();
        } finally {
            unlink($path);
        }

        $this->assertSame(['get-widgets'], array_map(fn (RouteTool $tool) => $tool->name(), $tools));

        Exceptions::assertNothingReported();
    }

    public function testItKeepsThePreviousCacheWhenAToolCantBeMade(): void
    {
        $path = sys_get_temp_dir() . '/mcp-tools-' . bin2hex(random_bytes(4)) . '.php';

        file_put_contents($path, '<?php return [];');

        Route::get('undocumented', [WidgetController::class, 'undocumented']);

        try {
            new RouteToolDiscovery(app(Router::class), new RouteToolCache($path), self::DOCUMENT)->cache();

            $this->fail('Expected an exception.');
        } catch (McpToolCacheException $exception) {
            $this->assertStringContainsString('undocumented', $exception->getMessage());
            $this->assertStringEqualsFile($path, '<?php return [];');
        } finally {
            unlink($path);
        }
    }

    public function testItHasNoRouteToolsWhenTheDocumentationCantBeRead(): void
    {
        Exceptions::fake();

        $discovery = new RouteToolDiscovery(
            app(Router::class),
            new RouteToolCache(sys_get_temp_dir() . '/missing-mcp-tools.php'),
            __DIR__ . '/Fixtures/missing.json',
        );

        // Each call reports again, rather than failing silently after the first.
        $this->assertSame([[], []], [$discovery->tools(), $discovery->tools()]);

        Exceptions::assertReportedCount(2);
        Exceptions::assertReported(InvalidApiDocumentationException::class);
    }

    public function testTheBoundDiscoveryIgnoresTheCacheInTests(): void
    {
        $path = app()->bootstrapPath('cache/mcp-tools.php');

        file_put_contents($path, '<?php return [];');

        try {
            $this->assertCount(5, app(RouteToolDiscovery::class)->tools());
        } finally {
            unlink($path);
        }
    }

    public function testTheBoundDiscoveryUsesTheConfiguredToolClass(): void
    {
        config(['mcp-tools.tool' => RestrictedRouteTool::class]);

        $this->assertContainsOnlyInstancesOf(RestrictedRouteTool::class, app(RouteToolDiscovery::class)->tools());
    }
}
