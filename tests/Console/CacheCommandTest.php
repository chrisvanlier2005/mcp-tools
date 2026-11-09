<?php

namespace Lier\McpTools\Tests\Console;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\PendingCommand;
use Lier\McpTools\RouteToolCache;
use Lier\McpTools\RouteToolDiscovery;
use Lier\McpTools\Tests\Fixtures\WidgetController;
use Lier\McpTools\Tests\TestCase;

final class CacheCommandTest extends TestCase
{
    public function testItCachesAndClearsTheRouteTools(): void
    {
        $path = sys_get_temp_dir() . '/mcp-tools-' . bin2hex(random_bytes(4)) . '.php';
        $cache = app()->instance(RouteToolCache::class, new RouteToolCache($path));

        app()->instance(RouteToolDiscovery::class, new RouteToolDiscovery(
            app(Router::class),
            $cache,
            __DIR__ . '/../Fixtures/openapi.json',
        ));

        $this->assertInstanceOf(PendingCommand::class, $command = $this->artisan('mcp-tools:cache'));

        $command->expectsOutputToContain('Cached 5 MCP route tools.')->assertSuccessful()->run();

        $this->assertFileExists($path);

        $this->assertInstanceOf(PendingCommand::class, $command = $this->artisan('mcp-tools:clear'));

        $command->expectsOutputToContain('Cleared the cached MCP route tools.')->assertSuccessful()->run();

        $this->assertFileDoesNotExist($path);
    }

    public function testItFailsWithoutReplacingTheCacheWhenAToolCantBeMade(): void
    {
        $path = sys_get_temp_dir() . '/mcp-tools-' . bin2hex(random_bytes(4)) . '.php';

        file_put_contents($path, '<?php return [];');

        $cache = app()->instance(RouteToolCache::class, new RouteToolCache($path));

        app()->instance(RouteToolDiscovery::class, new RouteToolDiscovery(
            app(Router::class),
            $cache,
            __DIR__ . '/../Fixtures/openapi.json',
        ));

        Route::get('undocumented', [WidgetController::class, 'undocumented']);

        try {
            $this->assertInstanceOf(PendingCommand::class, $command = $this->artisan('mcp-tools:cache'));

            $command
                ->expectsOutputToContain('Could not cache the MCP route tools; the previous cache is kept.')
                ->assertFailed()
                ->run();

            $this->assertStringEqualsFile($path, '<?php return [];');
        } finally {
            unlink($path);
        }
    }
}
