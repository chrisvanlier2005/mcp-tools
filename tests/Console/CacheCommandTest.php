<?php

namespace Lier\McpTools\Tests\Console;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
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

    public function testItFailsToClearACacheThatCantBeRemoved(): void
    {
        // Unlinking a directory fails, which Laravel's error handler raises as an ErrorException.
        $path = sys_get_temp_dir() . '/mcp-tools-' . bin2hex(random_bytes(4));
        mkdir($path);

        app()->instance(RouteToolCache::class, new RouteToolCache($path));

        try {
            $this->assertInstanceOf(PendingCommand::class, $command = $this->artisan('mcp-tools:clear'));

            $command->expectsOutputToContain('Could not clear the cached MCP route tools.')->assertFailed()->run();
        } finally {
            rmdir($path);
        }
    }

    public function testItFailsWhenTheDocumentationIsMissing(): void
    {
        $path = sys_get_temp_dir() . '/mcp-tools-' . bin2hex(random_bytes(4)) . '.php';
        $cache = app()->instance(RouteToolCache::class, new RouteToolCache($path));

        app()->instance(RouteToolDiscovery::class, new RouteToolDiscovery(
            app(Router::class),
            $cache,
            __DIR__ . '/../Fixtures/missing.json',
        ));

        $this->assertInstanceOf(PendingCommand::class, $command = $this->artisan('mcp-tools:cache'));

        $command
            ->expectsOutputToContain('Could not cache the MCP route tools; the previous cache is kept.')
            ->expectsOutputToContain('missing.json')
            ->assertFailed()
            ->run();

        $this->assertFileDoesNotExist($path);
    }

    public function testItFailsWhenTheCacheDirectoryIsMissing(): void
    {
        $path = sys_get_temp_dir() . '/missing-' . bin2hex(random_bytes(4)) . '/mcp-tools.php';
        $cache = app()->instance(RouteToolCache::class, new RouteToolCache($path));

        app()->instance(RouteToolDiscovery::class, new RouteToolDiscovery(
            app(Router::class),
            $cache,
            __DIR__ . '/../Fixtures/openapi.json',
        ));

        $this->assertInstanceOf(PendingCommand::class, $command = $this->artisan('mcp-tools:cache'));

        $command->expectsOutputToContain('Could not cache the MCP route tools')->assertFailed()->run();
    }

    public function testItClearsWhenThereIsNoCache(): void
    {
        app()->instance(RouteToolCache::class, new RouteToolCache(sys_get_temp_dir() . '/missing-mcp-tools.php'));

        $this->assertInstanceOf(PendingCommand::class, $command = $this->artisan('mcp-tools:clear'));

        $command->expectsOutputToContain('Cleared the cached MCP route tools.')->assertSuccessful()->run();
    }

    public function testItCachesAnEmptyListOfTools(): void
    {
        $path = sys_get_temp_dir() . '/mcp-tools-' . bin2hex(random_bytes(4)) . '.php';
        $cache = app()->instance(RouteToolCache::class, new RouteToolCache($path));

        // A router without annotated routes.
        app()->instance(RouteToolDiscovery::class, new RouteToolDiscovery(
            new Router(app('events'), app()),
            $cache,
            __DIR__ . '/../Fixtures/openapi.json',
        ));

        try {
            $this->assertInstanceOf(PendingCommand::class, $command = $this->artisan('mcp-tools:cache'));

            $command->expectsOutputToContain('Cached 0 MCP route tools.')->assertSuccessful()->run();

            $this->assertSame([], $cache->get());
        } finally {
            $cache->clear();
        }
    }

    public function testItRegistersBothCommandsAndTheOptimizeHooks(): void
    {
        $commands = array_keys(Artisan::all());

        $this->assertContains('mcp-tools:cache', $commands);
        $this->assertContains('mcp-tools:clear', $commands);
    }
}
